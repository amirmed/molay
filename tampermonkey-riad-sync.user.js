// ==UserScript==
// @name         MOLAY - RIAD Auto Sync
// @namespace    molay-riad-sync
// @version      1.0
// @description  يستخرج بيانات الاتصال من riad.m2t.ma ويُرسلها تلقائياً لـ MOLAY
// @author       MOLAY
// @match        https://riad.m2t.ma/*
// @grant        GM_xmlhttpRequest
// @grant        GM_notification
// @grant        GM_getValue
// @grant        GM_setValue
// @connect      *
// @run-at       document-start
// ==/UserScript==

(function() {
    'use strict';

    // ========================================
    // ⚙️ إعدادات — غيّر الرابط حسب الاستضافة
    // ========================================
    const MOLAY_URL = GM_getValue('molay_url', '');
    const MOLAY_SYNC_ENDPOINT = MOLAY_URL ? MOLAY_URL.replace(/\/$/, '') + '/api/riad-proxy.php?action=auto_sync' : '';

    // إذا لم يتم تعيين الرابط بعد، اطلبه من المستخدم
    if (!MOLAY_URL) {
        setTimeout(() => {
            const url = prompt(
                'MOLAY Sync: أدخل رابط تطبيق MOLAY\n' +
                'مثال: https://molay.up.railway.app\n' +
                'أو: http://localhost:8080'
            );
            if (url) {
                GM_setValue('molay_url', url.trim());
                alert('✅ تم الحفظ! أعد تحميل الصفحة.');
                location.reload();
            }
        }, 2000);
        return;
    }

    console.log('[MOLAY Sync] 🟢 Active — URL:', MOLAY_URL);

    // ========================================
    // 🔍 اعتراض طلبات fetch
    // ========================================
    const originalFetch = window.fetch;
    let lastSyncTime = 0;
    const SYNC_COOLDOWN = 30000; // 30 ثانية بين كل مزامنة

    window.fetch = async function(...args) {
        const response = await originalFetch.apply(this, args);

        try {
            const url = typeof args[0] === 'string' ? args[0] : (args[0]?.url || '');
            const options = args[1] || {};

            // اعترض طلبات billings/unpaid لاستخراج المعرّفات
            if (url.includes('/billings/unpaid') && url.includes('operatorServiceId=')) {
                handleBillingRequest(url, options);
            }

            // اعترض أي طلب لـ riad-api لاستخراج الكوكيز
            if (url.includes('riad-api.m2t.ma')) {
                extractAndSync(url, options);
            }
        } catch (e) {
            console.warn('[MOLAY Sync] Error intercepting fetch:', e);
        }

        return response;
    };

    // ========================================
    // 🔍 اعتراض XMLHttpRequest أيضاً
    // ========================================
    const originalXHR = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function(method, url, ...rest) {
        this._molayUrl = url;
        this._molayHeaders = {};
        const originalSetHeader = this.setRequestHeader.bind(this);
        this.setRequestHeader = function(name, value) {
            this._molayHeaders[name] = value;
            return originalSetHeader(name, value);
        };
        return originalXHR.call(this, method, url, ...rest);
    };

    const originalSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send = function(body) {
        try {
            if (this._molayUrl && this._molayUrl.includes('riad-api.m2t.ma')) {
                extractAndSync(this._molayUrl, { headers: this._molayHeaders });
            }
        } catch (e) {}
        return originalSend.call(this, body);
    };

    // ========================================
    // 📤 استخراج البيانات وإرسالها
    // ========================================
    function extractAndSync(url, options) {
        const now = Date.now();
        if (now - lastSyncTime < SYNC_COOLDOWN) return;

        const data = {};

        // استخراج x-code-es من الهيدر
        if (options?.headers) {
            const headers = options.headers;
            if (headers instanceof Headers) {
                if (headers.has('x-code-es')) data.code_es = headers.get('x-code-es');
            } else if (typeof headers === 'object') {
                for (const [key, val] of Object.entries(headers)) {
                    if (key.toLowerCase() === 'x-code-es') data.code_es = val;
                }
            }
        }

        // استخراج الكوكيز من document.cookie (riad.m2t.ma)
        // ملاحظة: الكوكيز على riad-api.m2t.ma قد لا تكون مرئية من هنا
        // لكن نحاول + نستخرج من performance entries
        extractCookiesFromPage(data);

        // استخراج operatorServiceId من URL
        const opMatch = url.match(/operatorServiceId=([^&]+)/);
        if (opMatch) data.operator_service_id = opMatch[1];

        if (Object.keys(data).length > 0) {
            syncToMolay(data);
            lastSyncTime = now;
        }
    }

    function handleBillingRequest(url, options) {
        const data = {};

        // operatorServiceId
        const opMatch = url.match(/operatorServiceId=([^&]+)/);
        if (opMatch) data.operator_service_id = opMatch[1];

        // x-code-es
        if (options?.headers) {
            const headers = options.headers;
            if (headers instanceof Headers) {
                if (headers.has('x-code-es')) data.code_es = headers.get('x-code-es');
            } else if (typeof headers === 'object') {
                for (const [key, val] of Object.entries(headers)) {
                    if (key.toLowerCase() === 'x-code-es') data.code_es = val;
                }
            }
        }

        // searchCriteria from body
        if (options?.body) {
            try {
                const body = JSON.parse(options.body);
                if (body.searchCriteria) data.search_criteria = body.searchCriteria;
            } catch(e) {}
        }

        extractCookiesFromPage(data);

        if (Object.keys(data).length > 0) {
            syncToMolay(data);
            lastSyncTime = Date.now();
        }
    }

    function extractCookiesFromPage(data) {
        // محاولة قراءة الكوكيز عبر performance API (URLs)
        // الكوكيز على riad-api.m2t.ma قد لا تكون قابلة للقراءة مباشرة
        // لذلك نستعمل طريقة بديلة: نقرأ من localStorage/sessionStorage إذا كان الموقع يخزن شيئاً

        // قراءة من document.cookie
        const cookies = document.cookie.split(';').reduce((acc, c) => {
            const [k, ...v] = c.trim().split('=');
            if (k) acc[k.trim()] = v.join('=');
            return acc;
        }, {});

        // JWT token
        if (cookies['token']) {
            data.jwt_token = cookies['token'];
        }

        // X-SESSIONID
        if (cookies['X-SESSIONID']) {
            data.session_id = cookies['X-SESSIONID'];
        }

        // Device cookie (starts with device_)
        for (const [name, val] of Object.entries(cookies)) {
            if (name.startsWith('device_')) {
                data.device_cookie_name = name;
                data.device_cookie_value = val;
                break;
            }
        }

        // Fallback: try localStorage
        try {
            const token = localStorage.getItem('token') || localStorage.getItem('jwt') || localStorage.getItem('access_token');
            if (token && !data.jwt_token) data.jwt_token = token;
        } catch(e) {}

        // Fallback: try sessionStorage
        try {
            const token = sessionStorage.getItem('token') || sessionStorage.getItem('jwt');
            if (token && !data.jwt_token) data.jwt_token = token;
        } catch(e) {}
    }

    // ========================================
    // 📡 إرسال لـ MOLAY
    // ========================================
    function syncToMolay(data) {
        if (!MOLAY_SYNC_ENDPOINT) return;

        // Don't sync if no useful data
        const hasUseful = data.jwt_token || data.session_id || data.code_es || data.operator_service_id;
        if (!hasUseful) return;

        console.log('[MOLAY Sync] 📤 Syncing:', Object.keys(data));

        GM_xmlhttpRequest({
            method: 'POST',
            url: MOLAY_SYNC_ENDPOINT,
            headers: {
                'Content-Type': 'application/json',
            },
            data: JSON.stringify(data),
            withCredentials: true, // إرسال كوكيز MOLAY للمصادقة
            onload: function(response) {
                try {
                    const result = JSON.parse(response.responseText);
                    if (result.success) {
                        console.log('[MOLAY Sync] ✅', result.message);
                        showSyncBadge('✅ تمت المزامنة', '#10b981');

                        // إشعار عند أول مزامنة ناجحة
                        if (result.synced && result.synced.length > 2) {
                            GM_notification({
                                title: 'MOLAY Sync',
                                text: result.message,
                                timeout: 3000,
                            });
                        }
                    } else {
                        console.warn('[MOLAY Sync] ❌', result.error || 'Unknown error');
                        showSyncBadge('❌ خطأ في المزامنة', '#ef4444');
                    }
                } catch(e) {
                    console.warn('[MOLAY Sync] Parse error:', e);
                }
            },
            onerror: function(err) {
                console.warn('[MOLAY Sync] ❌ Connection error:', err);
                showSyncBadge('❌ لا يمكن الاتصال بـ MOLAY', '#ef4444');
            }
        });
    }

    // ========================================
    // 🎨 شارة المزامنة في الصفحة
    // ========================================
    function showSyncBadge(text, color) {
        let badge = document.getElementById('molay-sync-badge');
        if (!badge) {
            badge = document.createElement('div');
            badge.id = 'molay-sync-badge';
            badge.style.cssText = `
                position: fixed;
                bottom: 20px;
                left: 20px;
                z-index: 99999;
                padding: 10px 18px;
                border-radius: 25px;
                font-family: 'Cairo', Arial, sans-serif;
                font-size: 13px;
                font-weight: 700;
                color: #fff;
                box-shadow: 0 4px 15px rgba(0,0,0,0.2);
                transition: all 0.3s ease;
                cursor: pointer;
            `;
            badge.addEventListener('click', () => {
                const newUrl = prompt('MOLAY URL:', GM_getValue('molay_url', ''));
                if (newUrl !== null) {
                    GM_setValue('molay_url', newUrl.trim());
                    alert('✅ تم التحديث! أعد تحميل الصفحة.');
                    location.reload();
                }
            });
            document.body.appendChild(badge);
        }

        badge.style.background = color;
        badge.textContent = text;

        // اختفاء بعد 5 ثوان
        clearTimeout(badge._timeout);
        badge._timeout = setTimeout(() => {
            badge.style.opacity = '0.3';
        }, 5000);
        badge.style.opacity = '1';
    }

    // شارة أولية
    window.addEventListener('load', () => {
        setTimeout(() => {
            showSyncBadge('🔄 MOLAY Sync نشط', '#3b82f6');
        }, 1000);
    });

})();
