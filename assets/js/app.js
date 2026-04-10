// ============================================
// MOLAY - Main Application JavaScript
// ============================================

// --- Globals ---
let serviceTypes = [];
let currentClient = null;
let searchTimeout = null;

const MONTHS_AR = ['', 'يناير', 'فبراير', 'مارس', 'أبريل', 'ماي', 'يونيو', 'يوليوز', 'غشت', 'شتنبر', 'أكتوبر', 'نونبر', 'دجنبر'];

// --- Init ---
document.addEventListener('DOMContentLoaded', async () => {
    initPeriodSelectors();
    await loadServiceTypes();
    loadSummary();
    loadClientsTable();
    initNavigation();
    initSearch();

    // Mobile menu
    document.getElementById('menuToggle').addEventListener('click', () => {
        document.getElementById('sidebar').classList.toggle('open');
    });
});

// =====================
// NAVIGATION
// =====================
function initNavigation() {
    const navItems = document.querySelectorAll('.nav-item');
    const titles = {
        'invoices': 'إدخال الفواتير',
        'clients': 'إدارة العملاء',
        'reports': 'التقارير والإحصائيات',
        'settings': 'الإعدادات'
    };
    navItems.forEach(item => {
        item.addEventListener('click', (e) => {
            e.preventDefault();
            const page = item.dataset.page;
            navItems.forEach(n => n.classList.remove('active'));
            item.classList.add('active');
            document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
            document.getElementById('page-' + page).classList.add('active');
            document.getElementById('pageTitle').textContent = titles[page] || '';
            // Reload data for pages
            if (page === 'clients') loadClientsTable();
            if (page === 'reports') loadReportServiceFilter();
            if (page === 'settings') { loadUsersManager(); loadServicesManager(); loadWaTemplate(); loadRiadCredentials(); loadRiadConfigManager(); }
            // Close mobile menu
            document.getElementById('sidebar').classList.remove('open');
        });
    });
}

// =====================
// PERIOD SELECTORS
// =====================
function initPeriodSelectors() {
    const now = new Date();
    const currentMonth = now.getMonth() + 1;
    const currentYear = now.getFullYear();

    // Fill all month/year selectors
    ['globalMonth', 'reportMonth'].forEach(id => {
        const sel = document.getElementById(id);
        if (!sel) return;
        for (let m = 1; m <= 12; m++) {
            const opt = document.createElement('option');
            opt.value = m;
            opt.textContent = MONTHS_AR[m];
            if (m === currentMonth) opt.selected = true;
            sel.appendChild(opt);
        }
    });
    ['globalYear', 'reportYear'].forEach(id => {
        const sel = document.getElementById(id);
        if (!sel) return;
        for (let y = currentYear - 2; y <= currentYear + 1; y++) {
            const opt = document.createElement('option');
            opt.value = y;
            opt.textContent = y;
            if (y === currentYear) opt.selected = true;
            sel.appendChild(opt);
        }
    });

    // On period change reload data
    document.getElementById('globalMonth').addEventListener('change', () => {
        loadSummary();
        if (currentClient) loadClientInvoices(currentClient.id);
    });
    document.getElementById('globalYear').addEventListener('change', () => {
        loadSummary();
        if (currentClient) loadClientInvoices(currentClient.id);
    });
}

function getSelectedMonth() { return parseInt(document.getElementById('globalMonth').value); }
function getSelectedYear() { return parseInt(document.getElementById('globalYear').value); }

// =====================
// API HELPERS
// =====================
async function api(url, options = {}) {
    try {
        const resp = await fetch(url, {
            headers: { 'Content-Type': 'application/json' },
            ...options
        });
        const data = await resp.json();
        if (!resp.ok) throw new Error(data.error || 'خطأ في الخادم');
        return data;
    } catch (e) {
        showToast(e.message, 'error');
        throw e;
    }
}

// =====================
// SEARCH
// =====================
function initSearch() {
    const input = document.getElementById('clientSearch');
    const results = document.getElementById('searchResults');

    input.addEventListener('input', () => {
        clearTimeout(searchTimeout);
        const q = input.value.trim();
        if (q.length < 2) { results.classList.remove('show'); return; }
        searchTimeout = setTimeout(async () => {
            const clients = await api(`api/clients.php?action=search&q=${encodeURIComponent(q)}`);
            results.innerHTML = '';
            if (clients.length === 0) {
                results.innerHTML = '<div style="padding:15px;text-align:center;color:#919294;">لا توجد نتائج</div>';
            } else {
                clients.forEach(c => {
                    results.innerHTML += `
                        <div class="search-result-item" onclick="selectClient(${c.id})">
                            <i class="fas fa-user-circle" style="font-size:28px;color:#F38E21;"></i>
                            <div>
                                <div class="name">${esc(c.full_name)}</div>
                                <div class="info"><i class="fas fa-phone"></i> ${esc(c.phone || '-')} &nbsp;|&nbsp; <i class="fas fa-map-marker-alt"></i> ${esc(c.address || '-')}</div>
                            </div>
                        </div>`;
                });
            }
            results.classList.add('show');
        }, 300);
    });

    // Close search on click outside
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.search-box')) results.classList.remove('show');
    });
}

async function selectClient(id) {
    // Switch to invoices page first
    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
    document.querySelector('[data-page="invoices"]').classList.add('active');
    document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
    document.getElementById('page-invoices').classList.add('active');
    document.getElementById('pageTitle').textContent = 'إدخال الفواتير';

    document.getElementById('searchResults').classList.remove('show');
    const client = await api(`api/clients.php?action=get&id=${id}`);
    currentClient = client;
    document.getElementById('clientSearch').value = client.full_name;
    renderClientWorkspace(client);
    loadClientInvoices(id);

    // Scroll to workspace
    document.getElementById('invoiceWorkspace').scrollIntoView({ behavior: 'smooth' });
}

// =====================
// CLIENT WORKSPACE
// =====================
function renderClientWorkspace(client) {
    const ws = document.getElementById('invoiceWorkspace');
    ws.style.display = 'block';

    // Header
    document.getElementById('clientHeader').innerHTML = `
        <div class="client-info">
            <h3><i class="fas fa-user"></i> ${esc(client.full_name)}</h3>
            <span><i class="fas fa-phone"></i> ${esc(client.phone || '-')} &nbsp;|&nbsp; <i class="fas fa-map-marker-alt"></i> ${esc(client.address || '-')}</span>
        </div>
        <div class="client-actions">
            <button class="btn btn-whatsapp btn-sm" onclick="openWhatsAppModal(${client.id})">
                <i class="fab fa-whatsapp"></i> واتساب
            </button>
            ${APP_ROLE === 'admin' ? `
                <button class="btn btn-sm" style="background:rgba(255,255,255,0.15);color:#fff;" onclick="editClient(${client.id})">
                    <i class="fas fa-edit"></i>
                </button>` : ''}
        </div>`;

    // Meters grid
    const grid = document.getElementById('metersGrid');
    if (client.meters.length === 0) {
        grid.innerHTML = '<div style="padding:30px;text-align:center;color:#919294;">لا توجد عدادات مسجلة لهذا العميل</div>';
        return;
    }

    grid.innerHTML = client.meters.map(m => `
        <div class="meter-card" id="meter-card-${m.id}">
            <div class="meter-icon" style="background:${m.service_color};">
                <i class="fas ${m.service_icon}"></i>
            </div>
            <div class="meter-info">
                <div class="meter-label">${esc(m.label)}</div>
                <div class="meter-details">
                    ${esc(m.service_name)} ${m.meter_number ? '| رقم: ' + esc(m.meter_number) : ''}
                    ${m.nopolice ? '<br><span style="color:var(--info);font-size:11px;"><i class="fas fa-cloud"></i> N.Police: ' + esc(m.nopolice) + '</span>' : ''}
                </div>
            </div>
            <div class="meter-input">
                ${m.nopolice ? `<button class="btn btn-sm riad-fetch-btn" style="background:var(--dark);color:#fff;padding:8px 10px;" onclick="fetchRiadForMeter(${m.id},'${esc(m.nopolice)}',${m.service_type_id})" title="استخراج من RIAD"><i class="fas fa-cloud-arrow-down"></i></button>` : ''}
                <input type="number" id="amount-${m.id}" placeholder="0.00" step="0.01" min="0"
                       onchange="updateTotal()" data-meter-id="${m.id}">
                <span class="currency">DH</span>
            </div>
        </div>
    `).join('');
}

async function loadClientInvoices(clientId) {
    const month = getSelectedMonth();
    const year = getSelectedYear();
    const invoices = await api(`api/invoices.php?action=get_client&client_id=${clientId}&month=${month}&year=${year}`);

    // Fill amounts
    if (currentClient && currentClient.meters) {
        currentClient.meters.forEach(m => {
            const input = document.getElementById(`amount-${m.id}`);
            if (input) {
                const inv = invoices.find(i => i.meter_id == m.id);
                input.value = inv ? inv.amount : '';
            }
        });
    }
    updateTotal();
    renderInvoiceActions();
}

function updateTotal() {
    const inputs = document.querySelectorAll('.meter-input input');
    let total = 0;
    inputs.forEach(inp => { total += parseFloat(inp.value) || 0; });
    document.getElementById('invoiceTotals').innerHTML = `
        <div class="total-label"><i class="fas fa-calculator"></i> المجموع الكلي</div>
        <div class="total-amount">${total.toFixed(2)} DH</div>`;
}

async function saveAllInvoices() {
    if (!currentClient) return;
    const month = getSelectedMonth();
    const year = getSelectedYear();
    const invoices = [];

    currentClient.meters.forEach(m => {
        const input = document.getElementById(`amount-${m.id}`);
        if (input) {
            invoices.push({
                meter_id: m.id,
                month: month,
                year: year,
                amount: parseFloat(input.value) || 0,
                notes: ''
            });
        }
    });

    await api('api/invoices.php?action=save_batch', {
        method: 'POST',
        body: JSON.stringify({ invoices })
    });
    showToast('تم حفظ الفواتير بنجاح', 'success');
    loadSummary();
}

// =====================
// SUMMARY
// =====================
async function loadSummary() {
    const month = getSelectedMonth();
    const year = getSelectedYear();
    const data = await api(`api/invoices.php?action=summary&month=${month}&year=${year}`);

    // Summary cards
    let cardsHtml = `
        <div class="summary-card" style="border-top-color: var(--primary);">
            <div class="card-icon" style="background: var(--primary);"><i class="fas fa-coins"></i></div>
            <div class="card-value">${parseFloat(data.grand_total).toFixed(2)}</div>
            <div class="card-label">المجموع الكلي (DH)</div>
        </div>
        <div class="summary-card" style="border-top-color: var(--info);">
            <div class="card-icon" style="background: var(--info);"><i class="fas fa-file-invoice"></i></div>
            <div class="card-value">${data.total_invoices}</div>
            <div class="card-label">عدد الفواتير</div>
        </div>`;

    data.by_service.forEach(s => {
        if (parseFloat(s.total) > 0) {
            cardsHtml += `
                <div class="summary-card" style="border-top-color: ${s.color};">
                    <div class="card-icon" style="background: ${s.color};"><i class="fas ${s.icon}"></i></div>
                    <div class="card-value">${parseFloat(s.total).toFixed(2)}</div>
                    <div class="card-label">${esc(s.name)}</div>
                </div>`;
        }
    });
    document.getElementById('summaryCards').innerHTML = cardsHtml;

    // Recent entries
    let recentHtml = '';
    if (data.recent && data.recent.length > 0) {
        data.recent.forEach(r => {
            recentHtml += `
                <div class="entry-item">
                    <div class="entry-icon" style="background: ${r.service_icon ? '#F38E21' : '#919294'};">
                        <i class="fas ${r.service_icon || 'fa-file'}"></i>
                    </div>
                    <div class="entry-info">
                        <div class="entry-name">${esc(r.full_name)}</div>
                        <div class="entry-detail">${esc(r.service_name)} - ${esc(r.meter_label)}</div>
                    </div>
                    <div class="entry-amount" style="color: var(--primary);">${parseFloat(r.amount).toFixed(2)} DH</div>
                </div>`;
        });
    } else {
        recentHtml = '<div style="padding:30px;text-align:center;color:#919294;">لا توجد فواتير لهذا الشهر</div>';
    }
    document.getElementById('recentEntries').innerHTML = recentHtml;
}

// =====================
// CLIENTS PAGE
// =====================
async function loadClientsTable() {
    const clients = await api('api/clients.php?action=list');
    const tbody = document.getElementById('clientsTableBody');
    if (!clients.length) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:30px;color:#919294;">لا يوجد عملاء بعد</td></tr>';
        return;
    }
    tbody.innerHTML = clients.map((c, i) => `
        <tr>
            <td>${i + 1}</td>
            <td><strong>${esc(c.full_name)}</strong></td>
            <td>${esc(c.phone || '-')}</td>
            <td>${esc(c.address || '-')}</td>
            <td><span class="badge badge-paid">${c.meter_count} عداد</span></td>
            <td>
                <div style="display:flex;gap:5px;">
                    <button class="btn btn-sm btn-primary" onclick="selectClient(${c.id})" title="فواتير">
                        <i class="fas fa-file-invoice"></i>
                    </button>
                    ${APP_ROLE === 'admin' ? `
                        <button class="btn btn-sm btn-secondary" onclick="editClient(${c.id})" title="تعديل">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="deleteClient(${c.id})" title="حذف">
                            <i class="fas fa-trash"></i>
                        </button>
                    ` : ''}
                    ${c.phone ? `
                        <button class="btn btn-sm btn-whatsapp" onclick="openWhatsAppModal(${c.id})" title="واتساب">
                            <i class="fab fa-whatsapp"></i>
                        </button>` : ''}
                </div>
            </td>
        </tr>
    `).join('');
}

// =====================
// CLIENT MODAL
// =====================
function openClientModal(clientData = null) {
    document.getElementById('clientModalTitle').textContent = clientData ? 'تعديل العميل' : 'إضافة عميل';
    document.getElementById('clientId').value = clientData ? clientData.id : '';
    document.getElementById('clientName').value = clientData ? clientData.full_name : '';
    document.getElementById('clientPhone').value = clientData ? clientData.phone : '';
    document.getElementById('clientAddress').value = clientData ? clientData.address : '';
    document.getElementById('clientNotes').value = clientData ? clientData.notes : '';

    const container = document.getElementById('metersContainer');
    container.innerHTML = '';

    if (clientData && clientData.meters) {
        clientData.meters.forEach(m => addMeterRow(m));
    }

    openModal('clientModal');
}

async function editClient(id) {
    const client = await api(`api/clients.php?action=get&id=${id}`);
    openClientModal(client);
}

function addMeterRow(meterData = null) {
    const container = document.getElementById('metersContainer');
    const row = document.createElement('div');
    row.className = 'meter-row';

    const serviceOptions = serviceTypes.map(s =>
        `<option value="${s.id}" ${meterData && meterData.service_type_id == s.id ? 'selected' : ''}>${esc(s.name)}</option>`
    ).join('');

    row.innerHTML = `
        <input type="hidden" class="meter-id" value="${meterData ? meterData.id : ''}">
        <div class="form-group">
            <label>الخدمة</label>
            <select class="meter-service">${serviceOptions}</select>
        </div>
        <div class="form-group">
            <label>الوصف</label>
            <input type="text" class="meter-label" value="${meterData ? esc(meterData.label) : ''}" placeholder="مثال: عداد المنزل">
        </div>
        <div class="form-group">
            <label>رقم العداد</label>
            <input type="text" class="meter-number" value="${meterData ? esc(meterData.meter_number) : ''}" placeholder="اختياري" style="direction:ltr;">
        </div>
        <div class="form-group">
            <label>N Police</label>
            <input type="text" class="meter-nopolice" value="${meterData ? esc(meterData.nopolice || '') : ''}" placeholder="رقم البوليس" style="direction:ltr;">
        </div>
        <button type="button" class="btn-remove" onclick="this.closest('.meter-row').remove()">
            <i class="fas fa-times"></i>
        </button>`;
    container.appendChild(row);
}

async function saveClient() {
    const id = document.getElementById('clientId').value;
    const name = document.getElementById('clientName').value.trim();
    if (!name) { showToast('يرجى إدخال اسم العميل', 'error'); return; }

    const meters = [];
    document.querySelectorAll('.meter-row').forEach(row => {
        const label = row.querySelector('.meter-label').value.trim();
        if (label) {
            meters.push({
                id: row.querySelector('.meter-id').value || 0,
                service_type_id: row.querySelector('.meter-service').value,
                label: label,
                meter_number: row.querySelector('.meter-number').value.trim(),
                nopolice: row.querySelector('.meter-nopolice').value.trim(),
                is_active: 1
            });
        }
    });

    await api('api/clients.php?action=save', {
        method: 'POST',
        body: JSON.stringify({
            id: id || 0,
            full_name: name,
            phone: document.getElementById('clientPhone').value.trim(),
            address: document.getElementById('clientAddress').value.trim(),
            notes: document.getElementById('clientNotes').value.trim(),
            meters: meters
        })
    });

    showToast(id ? 'تم تحديث العميل بنجاح' : 'تم إضافة العميل بنجاح', 'success');
    closeModal('clientModal');
    loadClientsTable();
}

async function deleteClient(id) {
    const ok = await confirmAction('حذف العميل', 'هل أنت متأكد من حذف هذا العميل وجميع بياناته (العدادات والفواتير)؟ لا يمكن التراجع عن هذا الإجراء.');
    if (!ok) return;
    await api(`api/clients.php?action=delete&id=${id}`);
    showToast('تم حذف العميل', 'success');
    loadClientsTable();
}

// =====================
// WHATSAPP INTEGRATION
// =====================
let waClientId = null;
let waOriginalMessage = '';
let waCurrentMessage = '';

// Default template parts
const WA_DEFAULT_HEADER = `السلام عليكم ورحمة الله 🙏\nالأخ(ة): {client_name}\n\n📋 *كشف فواتير الخدمات*\n━━━━━━━━━━━━━━━━━━`;
const WA_DEFAULT_FOOTER = `📌 يرجى تسديد المبلغ في أقرب وقت.\nشكراً لثقتكم 🙏`;
let waTemplateHeader = '';
let waTemplateFooter = '';

async function openWhatsAppModal(clientId) {
    waClientId = clientId;
    const client = await api(`api/clients.php?action=get&id=${clientId}`);
    currentClient = client;

    if (!client.phone) {
        showToast('هذا العميل ليس لديه رقم هاتف مسجل', 'error');
        return;
    }

    // Load saved template
    try {
        const tpl = await api('api/wa-template.php?action=get');
        waTemplateHeader = tpl.header || '';
        waTemplateFooter = tpl.footer || '';
    } catch(e) { /* use defaults */ }

    // Build months selector - last 6 months
    const now = new Date();
    let monthsHtml = '';
    for (let i = 0; i < 6; i++) {
        const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
        const m = d.getMonth() + 1;
        const y = d.getFullYear();
        const selected = i === 0 ? 'selected' : '';
        monthsHtml += `<div class="wa-month-chip ${selected}" data-month="${m}" data-year="${y}" onclick="toggleWaMonth(this)">${MONTHS_AR[m]} ${y}</div>`;
    }
    document.getElementById('waMonthsSelector').innerHTML = monthsHtml;

    // Reset to preview mode
    switchWaTab('preview');
    await regenerateWaMessage();
    openModal('whatsappModal');
}

function toggleWaMonth(chip) {
    chip.classList.toggle('selected');
    regenerateWaMessage();
}

async function regenerateWaMessage() {
    if (!currentClient) return;
    const selectedMonths = [];
    document.querySelectorAll('.wa-month-chip.selected').forEach(chip => {
        selectedMonths.push(`${chip.dataset.month}-${chip.dataset.year}`);
    });

    if (selectedMonths.length === 0) {
        waOriginalMessage = '';
        waCurrentMessage = '';
        document.getElementById('waPreview').innerHTML = '<div style="text-align:center;padding:30px;color:#919294;">اختر شهر واحد على الأقل</div>';
        document.getElementById('waEditArea').value = '';
        return;
    }

    const invoices = await api(`api/invoices.php?action=get_client_range&client_id=${currentClient.id}&months=${selectedMonths.join(',')}`);

    waOriginalMessage = buildWhatsAppMessage(currentClient, invoices, selectedMonths);
    waCurrentMessage = waOriginalMessage;
    renderWaPreview();
    document.getElementById('waEditArea').value = waCurrentMessage;
}

function renderWaPreview() {
    document.getElementById('waPreview').innerHTML = `<div class="wa-bubble">${esc(waCurrentMessage).replace(/\n/g, '<br>')}</div>`;
}

function switchWaTab(tab) {
    const previewSection = document.getElementById('waPreview');
    const editSection = document.getElementById('waEditSection');
    const tabPreview = document.getElementById('waTabPreview');
    const tabEdit = document.getElementById('waTabEdit');

    if (tab === 'edit') {
        previewSection.style.display = 'none';
        editSection.style.display = 'block';
        tabPreview.classList.remove('active');
        tabEdit.classList.add('active');
        document.getElementById('waEditArea').value = waCurrentMessage;
    } else {
        // Save edits from textarea
        const editArea = document.getElementById('waEditArea');
        if (editArea.value.trim()) {
            waCurrentMessage = editArea.value;
        }
        previewSection.style.display = 'block';
        editSection.style.display = 'none';
        tabPreview.classList.add('active');
        tabEdit.classList.remove('active');
        renderWaPreview();
    }
}

function resetWaMessage() {
    if (!waOriginalMessage) { showToast('لا توجد رسالة أصلية', 'error'); return; }
    waCurrentMessage = waOriginalMessage;
    document.getElementById('waEditArea').value = waCurrentMessage;
    renderWaPreview();
    switchWaTab('preview');
    showToast('تم إرجاع الرسالة الأصلية', 'success');
}

function buildWhatsAppMessage(client, invoices, periods) {
    // --- HEADER (from template or default) ---
    const headerTpl = waTemplateHeader || WA_DEFAULT_HEADER;
    let header = headerTpl.replace(/\{client_name\}/g, client.full_name);

    // --- BODY (auto-generated, always) ---
    let body = '';
    let grandTotal = 0;

    periods.forEach(period => {
        const [m, y] = period.split('-');
        const monthInvs = invoices.filter(i => i.month == m && i.year == y);
        if (monthInvs.length === 0) return;

        body += `\n📅 *${MONTHS_AR[parseInt(m)]} ${y}*\n`;
        let monthTotal = 0;
        monthInvs.forEach(inv => {
            const amount = parseFloat(inv.amount);
            monthTotal += amount;
            body += `  ${getServiceEmoji(inv.service_name)} ${inv.service_name} - ${inv.meter_label}: *${amount.toFixed(2)} DH*\n`;
        });
        body += `  ➤ مجموع الشهر: *${monthTotal.toFixed(2)} DH*\n`;
        grandTotal += monthTotal;
    });

    body += `\n━━━━━━━━━━━━━━━━━━\n`;
    body += `💰 *المجموع الكلي: ${grandTotal.toFixed(2)} DH*\n`;

    // --- FOOTER (from template or default) ---
    const footerTpl = waTemplateFooter || WA_DEFAULT_FOOTER;
    let footer = footerTpl
        .replace(/\{client_name\}/g, client.full_name)
        .replace(/\{grand_total\}/g, grandTotal.toFixed(2));

    return header + '\n' + body + '\n' + footer;
}

function getServiceEmoji(name) {
    const map = { 'Electricite': '⚡', 'Eau': '💧', 'Internet': '🌐', 'Telephone Mobile': '📱', 'Telephone Fixe': '📞' };
    return map[name] || '📄';
}

function sendWhatsApp() {
    if (!currentClient || !currentClient.phone) return;

    // If in edit mode, save edits first
    const editArea = document.getElementById('waEditArea');
    if (editArea && editArea.value.trim() && document.getElementById('waEditSection').style.display !== 'none') {
        waCurrentMessage = editArea.value;
    }

    if (!waCurrentMessage) { showToast('اختر شهر واحد على الأقل', 'error'); return; }

    const text = waCurrentMessage;

    // Format phone
    let phone = currentClient.phone.replace(/\s+/g, '').replace(/^0/, '212');
    if (!phone.startsWith('+') && !phone.startsWith('212')) phone = '212' + phone;

    const url = `https://wa.me/${phone}?text=${encodeURIComponent(text)}`;
    window.open(url, '_blank');
    closeModal('whatsappModal');
    showToast('تم فتح واتساب', 'success');
}

// =====================
// REPORTS
// =====================
async function loadReportServiceFilter() {
    const sel = document.getElementById('reportType');
    sel.innerHTML = '<option value="all">جميع الخدمات</option>';
    serviceTypes.forEach(s => {
        sel.innerHTML += `<option value="${s.id}">${esc(s.name)}</option>`;
    });
}

async function generateReport() {
    const month = parseInt(document.getElementById('reportMonth').value);
    const year = parseInt(document.getElementById('reportYear').value);
    const serviceId = document.getElementById('reportType').value;

    const data = await api(`api/invoices.php?action=report&month=${month}&year=${year}&service_id=${serviceId}`);

    // Summary
    let total = 0;
    data.forEach(r => total += parseFloat(r.amount));
    document.getElementById('reportSummary').innerHTML = `
        <div class="summary-card" style="border-top-color:var(--primary);">
            <div class="card-value">${total.toFixed(2)} DH</div>
            <div class="card-label">المجموع الكلي</div>
        </div>
        <div class="summary-card" style="border-top-color:var(--info);">
            <div class="card-value">${data.length}</div>
            <div class="card-label">عدد الفواتير</div>
        </div>
        <div class="summary-card" style="border-top-color:var(--success);">
            <div class="card-value">${data.filter(r => r.is_paid).length}</div>
            <div class="card-label">مدفوعة</div>
        </div>`;

    // Table
    document.getElementById('reportTableHead').innerHTML = `
        <tr><th>العميل</th><th>الخدمة</th><th>العداد</th><th>المبلغ</th><th>الحالة</th></tr>`;

    document.getElementById('reportTableBody').innerHTML = data.map(r => `
        <tr>
            <td><strong>${esc(r.full_name)}</strong></td>
            <td><i class="fas ${r.service_icon}" style="color:${r.service_color};"></i> ${esc(r.service_name)}</td>
            <td>${esc(r.meter_label)} ${r.meter_number ? '<br><small style="color:#919294;">'+esc(r.meter_number)+'</small>' : ''}</td>
            <td style="font-weight:900;direction:ltr;">${parseFloat(r.amount).toFixed(2)} DH</td>
            <td><span class="badge ${r.is_paid ? 'badge-paid' : 'badge-unpaid'}">${r.is_paid ? 'مدفوعة' : 'غير مدفوعة'}</span></td>
        </tr>
    `).join('');
}

function exportReport() {
    // Simple CSV export
    const table = document.getElementById('reportTable');
    if (!table) return;
    let csv = '\uFEFF'; // BOM for Excel Arabic
    const rows = table.querySelectorAll('tr');
    rows.forEach(row => {
        const cells = row.querySelectorAll('th, td');
        const rowData = [];
        cells.forEach(cell => rowData.push('"' + cell.innerText.replace(/"/g, '""') + '"'));
        csv += rowData.join(',') + '\n';
    });
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `report_${document.getElementById('reportMonth').value}_${document.getElementById('reportYear').value}.csv`;
    link.click();
    showToast('تم تصدير التقرير', 'success');
}

// =====================
// SETTINGS - USERS
// =====================
async function loadUsersManager() {
    if (APP_ROLE !== 'admin') return;
    const users = await api('api/users.php?action=list');
    const container = document.getElementById('usersManager');
    const adminCount = users.filter(u => u.role === 'admin').length;
    container.innerHTML = users.map(u => {
        const isSelf = u.username === APP_USERNAME;
        const isLastAdmin = u.role === 'admin' && adminCount <= 1;
        const canDelete = !isSelf && !isLastAdmin;
        return `
        <div class="settings-item">
            <div class="item-info">
                <i class="fas fa-user-circle" style="font-size:24px;color:${u.role === 'admin' ? 'var(--primary)' : 'var(--secondary)'}"></i>
                <div>
                    <div class="item-label">${esc(u.full_name)} ${isSelf ? '<span style="font-size:11px;color:var(--primary);">(أنت)</span>' : ''}</div>
                    <div class="item-sub">${esc(u.username)} - ${u.role === 'admin' ? 'مدير' : 'مساعد'}</div>
                </div>
            </div>
            <div class="item-actions">
                <button class="btn btn-sm btn-secondary" onclick="editUser(${u.id})"><i class="fas fa-edit"></i></button>
                ${canDelete ? `<button class="btn btn-sm btn-danger" onclick="deleteUser(${u.id})"><i class="fas fa-trash"></i></button>` : ''}
            </div>
        </div>`;
    }).join('');
}

function openUserModal(userData = null) {
    document.getElementById('userModalTitle').textContent = userData ? 'تعديل المستخدم' : 'إضافة مستخدم';
    document.getElementById('userId').value = userData ? userData.id : '';
    document.getElementById('userFullName').value = userData ? userData.full_name : '';
    document.getElementById('userUsername').value = userData ? userData.username : '';
    document.getElementById('userPassword').value = '';
    document.getElementById('userRole').value = userData ? userData.role : 'assistant';
    openModal('userModal');
}

async function editUser(id) {
    const users = await api('api/users.php?action=list');
    const user = users.find(u => u.id == id);
    if (user) openUserModal(user);
}

async function saveUser() {
    const id = document.getElementById('userId').value;
    const data = {
        id: id || 0,
        full_name: document.getElementById('userFullName').value.trim(),
        username: document.getElementById('userUsername').value.trim(),
        password: document.getElementById('userPassword').value,
        role: document.getElementById('userRole').value
    };
    if (!data.full_name || !data.username) { showToast('يرجى ملء جميع الحقول', 'error'); return; }
    if (!id && !data.password) { showToast('يرجى إدخال كلمة المرور', 'error'); return; }

    await api('api/users.php?action=save', { method: 'POST', body: JSON.stringify(data) });
    showToast('تم الحفظ بنجاح', 'success');
    closeModal('userModal');
    loadUsersManager();
}

async function deleteUser(id) {
    const ok = await confirmAction('حذف المستخدم', 'هل أنت متأكد من حذف هذا المستخدم؟');
    if (!ok) return;
    await api(`api/users.php?action=delete&id=${id}`);
    showToast('تم الحذف', 'success');
    loadUsersManager();
}

// =====================
// SETTINGS - SERVICES
// =====================
async function loadServicesManager() {
    if (APP_ROLE !== 'admin') return;
    const container = document.getElementById('servicesManager');
    container.innerHTML = serviceTypes.map(s => `
        <div class="settings-item">
            <div class="item-info">
                <div class="meter-icon" style="background:${s.color};width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;">
                    <i class="fas ${s.icon}" style="font-size:16px;color:#fff;"></i>
                </div>
                <div>
                    <div class="item-label">${esc(s.name)}</div>
                    <div class="item-sub" style="direction:ltr;font-size:11px;color:#919294;">${esc(s.icon)}</div>
                </div>
            </div>
            <div class="item-actions">
                <button class="btn btn-sm btn-secondary" onclick="editService(${s.id})" title="تعديل"><i class="fas fa-edit"></i></button>
                <button class="btn btn-sm btn-danger" onclick="deleteService(${s.id})" title="حذف"><i class="fas fa-trash"></i></button>
            </div>
        </div>
    `).join('');
}

function openServiceModal(serviceData = null) {
    document.getElementById('serviceModalTitle').textContent = serviceData ? 'تعديل الخدمة' : 'إضافة خدمة';
    document.getElementById('serviceId').value = serviceData ? serviceData.id : '';
    document.getElementById('serviceName').value = serviceData ? serviceData.name : '';
    document.getElementById('serviceIcon').value = serviceData ? serviceData.icon : 'fa-file-invoice';
    document.getElementById('serviceColor').value = serviceData ? serviceData.color : '#F38E21';

    // Update icon preview
    updateIconPreview();

    // Highlight active icon chip
    document.querySelectorAll('.icon-chip').forEach(chip => {
        chip.classList.toggle('active', chip.dataset.icon === (serviceData ? serviceData.icon : 'fa-file-invoice'));
    });

    // Highlight active color chip
    document.querySelectorAll('.color-chip').forEach(chip => {
        chip.classList.toggle('active', chip.dataset.color === (serviceData ? serviceData.color : '#F38E21'));
    });

    openModal('serviceModal');

    // Attach icon chip click handlers
    document.querySelectorAll('.icon-chip').forEach(chip => {
        chip.onclick = () => {
            document.getElementById('serviceIcon').value = chip.dataset.icon;
            document.querySelectorAll('.icon-chip').forEach(c => c.classList.remove('active'));
            chip.classList.add('active');
            updateIconPreview();
        };
    });

    // Attach color chip click handlers
    document.querySelectorAll('.color-chip').forEach(chip => {
        chip.onclick = () => {
            document.getElementById('serviceColor').value = chip.dataset.color;
            document.querySelectorAll('.color-chip').forEach(c => c.classList.remove('active'));
            chip.classList.add('active');
            updateIconPreview();
        };
    });

    // Live icon preview on input change
    document.getElementById('serviceIcon').oninput = updateIconPreview;
    document.getElementById('serviceColor').oninput = updateIconPreview;
}

function updateIconPreview() {
    const icon = document.getElementById('serviceIcon').value || 'fa-file-invoice';
    const color = document.getElementById('serviceColor').value || '#F38E21';
    const preview = document.getElementById('iconPreview');
    preview.innerHTML = `<i class="fas ${esc(icon)}"></i>`;
    preview.style.background = color;
}

function editService(id) {
    const svc = serviceTypes.find(s => s.id == id);
    if (svc) openServiceModal(svc);
}

async function saveServiceFromModal() {
    const name = document.getElementById('serviceName').value.trim();
    if (!name) { showToast('يرجى إدخال اسم الخدمة', 'error'); return; }

    const data = {
        id: document.getElementById('serviceId').value || 0,
        name: name,
        icon: document.getElementById('serviceIcon').value.trim() || 'fa-file-invoice',
        color: document.getElementById('serviceColor').value || '#F38E21'
    };

    await api('api/services.php?action=save', { method: 'POST', body: JSON.stringify(data) });
    showToast(data.id ? 'تم تحديث الخدمة بنجاح' : 'تم إضافة الخدمة بنجاح', 'success');
    closeModal('serviceModal');
    await loadServiceTypes();
    loadServicesManager();
}

async function deleteService(id) {
    const ok = await confirmAction('حذف الخدمة', 'هل أنت متأكد من حذف هذه الخدمة؟');
    if (!ok) return;
    await api(`api/services.php?action=delete&id=${id}`);
    showToast('تم الحذف', 'success');
    await loadServiceTypes();
    loadServicesManager();
}

// =====================
// RIAD M2T INTEGRATION
// =====================
let riadFetchedData = []; // Store fetched results for applying

// -- Fetch button in client workspace --
function renderInvoiceActions() {
    const hasNopolice = currentClient && currentClient.meters && currentClient.meters.some(m => m.nopolice);
    document.getElementById('invoiceActions').innerHTML = `
        ${hasNopolice ? `
            <button class="btn" style="background:linear-gradient(135deg,#0f3460,#1a1a2e);color:#fff;" onclick="fetchRiadForClient(${currentClient.id})">
                <i class="fas fa-cloud-arrow-down"></i> استخراج تلقائي من RIAD
            </button>` : ''}
        <button class="btn btn-primary" onclick="saveAllInvoices()">
            <i class="fas fa-save"></i> حفظ الفواتير
        </button>
        <button class="btn btn-whatsapp" onclick="openWhatsAppModal(${currentClient.id})">
            <i class="fab fa-whatsapp"></i> إرسال عبر واتساب
        </button>`;
}

// -- Fetch all meters for a client --
async function fetchRiadForClient(clientId) {
    openModal('riadResultsModal');
    document.getElementById('riadResultsBody').innerHTML = `
        <div style="text-align:center;padding:40px;">
            <i class="fas fa-spinner fa-spin" style="font-size:36px;color:#F38E21;"></i>
            <p style="margin-top:15px;font-size:16px;font-weight:600;">جاري الاستخراج من RIAD M2T...</p>
            <p style="color:#919294;font-size:13px;">يتم البحث عن الفواتير غير المدفوعة لكل عداد</p>
        </div>`;
    document.getElementById('riadResultsFooter').style.display = 'none';

    try {
        const data = await api(`api/riad-proxy.php?action=fetch_client&client_id=${clientId}`);
        riadFetchedData = data.results || [];
        renderRiadResults(riadFetchedData);
    } catch (e) {
        document.getElementById('riadResultsBody').innerHTML = `
            <div style="text-align:center;padding:40px;color:var(--danger);">
                <i class="fas fa-exclamation-triangle" style="font-size:36px;"></i>
                <p style="margin-top:15px;">فشل الاتصال بالخادم</p>
                <p style="font-size:13px;">${esc(e.message)}</p>
            </div>`;
    }
}

// -- Fetch single meter --
async function fetchRiadForMeter(meterId, nopolice, serviceTypeId) {
    const meterCard = document.getElementById(`meter-card-${meterId}`);
    if (!meterCard) return;

    // Add loading indicator
    const btn = meterCard.querySelector('.riad-fetch-btn');
    if (btn) { btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; btn.disabled = true; }

    try {
        const data = await api(`api/riad-proxy.php?action=fetch&nopolice=${encodeURIComponent(nopolice)}&service_type_id=${serviceTypeId}`);
        if (data.success && data.invoices && data.invoices.length > 0) {
            // Take the first unpaid invoice and fill the amount
            const inv = data.invoices[0];
            const input = document.getElementById(`amount-${meterId}`);
            if (input) {
                input.value = inv.amount_ttc.toFixed(2);
                input.style.borderColor = '#10b981';
                input.style.background = '#f0fdf4';
                updateTotal();
            }
            showToast(`${data.client_name}: ${inv.amount_ttc.toFixed(2)} DH (${inv.period})`, 'success');
        } else {
            showToast(`لا توجد فواتير غير مدفوعة لـ ${nopolice}`, 'info');
        }
    } catch (e) {
        showToast(`خطأ في استخراج ${nopolice}`, 'error');
    }

    if (btn) { btn.innerHTML = '<i class="fas fa-cloud-arrow-down"></i>'; btn.disabled = false; }
}

// -- Render results modal --
function renderRiadResults(results) {
    if (!results || results.length === 0) {
        document.getElementById('riadResultsBody').innerHTML = `
            <div style="text-align:center;padding:40px;color:#919294;">
                <i class="fas fa-info-circle" style="font-size:36px;"></i>
                <p style="margin-top:15px;">لا توجد عدادات مرتبطة بـ N Police أو لا توجد فواتير غير مدفوعة</p>
            </div>`;
        return;
    }

    let html = '';
    let hasInvoices = false;

    results.forEach(r => {
        const resp = r.api_response;
        const isSuccess = resp && resp.success;
        const invoices = isSuccess ? resp.invoices : [];
        if (invoices.length > 0) hasInvoices = true;

        html += `<div class="riad-result-card" style="border:2px solid ${isSuccess ? '#10b981' : '#ef4444'};border-radius:12px;padding:18px;margin-bottom:12px;">`;
        html += `<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">`;
        html += `<div>
            <strong style="font-size:16px;">${esc(r.meter_label)}</strong>
            <span style="color:#919294;font-size:13px;margin-right:10px;">${esc(r.service_name)} | N: ${esc(r.nopolice)}</span>
        </div>`;
        html += `<span class="badge ${isSuccess ? 'badge-paid' : 'badge-unpaid'}">${isSuccess ? 'متصل' : 'خطأ'}</span>`;
        html += `</div>`;

        if (isSuccess && invoices.length > 0) {
            html += `<div style="font-size:13px;color:#919294;margin-bottom:8px;">العميل: ${esc(resp.client_name)} | العنوان: ${esc(resp.raw_address)}</div>`;
            html += `<table style="width:100%;font-size:14px;border-collapse:collapse;">`;
            html += `<tr style="background:#f8f9fa;"><th style="padding:8px;text-align:right;">الفترة</th><th style="padding:8px;text-align:right;">المبلغ TTC</th><th style="padding:8px;text-align:right;">الطابع</th><th style="padding:8px;text-align:right;">الوصف</th></tr>`;
            invoices.forEach(inv => {
                html += `<tr style="border-bottom:1px solid #f0f0f0;">
                    <td style="padding:8px;font-weight:700;">${esc(inv.period)}</td>
                    <td style="padding:8px;font-weight:900;color:var(--primary);direction:ltr;">${inv.amount_ttc.toFixed(2)} DH</td>
                    <td style="padding:8px;direction:ltr;">${inv.timbre.toFixed(2)}</td>
                    <td style="padding:8px;font-size:12px;color:#919294;">${esc(inv.label)}</td>
                </tr>`;
            });
            html += `</table>`;
            html += `<div style="text-align:left;margin-top:8px;font-weight:900;font-size:18px;color:var(--primary);direction:ltr;">المجموع: ${resp.total_ttc.toFixed(2)} DH</div>`;
        } else if (isSuccess) {
            html += `<div style="color:#919294;font-size:13px;">لا توجد فواتير غير مدفوعة</div>`;
        } else {
            html += `<div style="color:var(--danger);font-size:13px;">${esc(resp.error || 'خطأ غير معروف')}</div>`;
        }

        html += `</div>`;
    });

    document.getElementById('riadResultsBody').innerHTML = html;
    if (hasInvoices) {
        document.getElementById('riadResultsFooter').style.display = 'flex';
    }
}

// -- Apply fetched amounts to invoice inputs --
function applyRiadResults() {
    if (!riadFetchedData || !currentClient) return;
    let applied = 0;

    riadFetchedData.forEach(r => {
        if (r.api_response && r.api_response.success && r.api_response.invoices) {
            // Sum all unpaid invoices for this meter
            let total = 0;
            r.api_response.invoices.forEach(inv => { total += inv.amount_ttc; });

            const input = document.getElementById(`amount-${r.meter_id}`);
            if (input && total > 0) {
                input.value = total.toFixed(2);
                input.style.borderColor = '#10b981';
                input.style.background = '#f0fdf4';
                applied++;
            }
        }
    });

    updateTotal();
    closeModal('riadResultsModal');
    showToast(`تم تطبيق ${applied} مبلغ(مبالغ) من RIAD`, 'success');
}

// -- Settings: Riad Credentials --
// =====================
// SETTINGS - WA TEMPLATE
// =====================
async function loadWaTemplate() {
    if (APP_ROLE !== 'admin') return;
    try {
        const tpl = await api('api/wa-template.php?action=get');
        const h = document.getElementById('waTemplateHeader');
        const f = document.getElementById('waTemplateFooter');
        if (h) h.value = tpl.header || '';
        if (f) f.value = tpl.footer || '';
        // Show placeholder if empty
        if (h && !tpl.header) h.placeholder = WA_DEFAULT_HEADER;
        if (f && !tpl.footer) f.placeholder = WA_DEFAULT_FOOTER;
    } catch(e) { /* ignore */ }
}

async function saveWaTemplate() {
    const header = document.getElementById('waTemplateHeader').value;
    const footer = document.getElementById('waTemplateFooter').value;
    await api('api/wa-template.php?action=save', {
        method: 'POST',
        body: JSON.stringify({ header, footer })
    });
    showToast('تم حفظ قالب الرسالة بنجاح', 'success');
}

async function resetWaTemplate() {
    const ok = await confirmAction('إرجاع القالب الافتراضي', 'هل تريد إرجاع قالب رسالة الواتساب للنص الافتراضي؟');
    if (!ok) return;
    await api('api/wa-template.php?action=reset');
    document.getElementById('waTemplateHeader').value = '';
    document.getElementById('waTemplateFooter').value = '';
    showToast('تم إرجاع القالب الافتراضي', 'success');
}

// =====================
// SETTINGS - RIAD CREDENTIALS
// =====================
async function loadRiadCredentials() {
    if (APP_ROLE !== 'admin') return;
    try {
        const data = await api('api/riad-proxy.php?action=get_credentials');
        const codeInput = document.getElementById('riadCodeEs');
        const userInput = document.getElementById('riadUsername');
        const passInput = document.getElementById('riadPassword');
        if (codeInput && data.code_es) codeInput.value = data.code_es;
        if (userInput && data.username) userInput.value = data.username;
        if (passInput && data.has_password) passInput.placeholder = '••••••• (محفوظة)';
    } catch (e) { /* ignore */ }
}

async function saveRiadCredentials() {
    const code = document.getElementById('riadCodeEs').value.trim();
    const username = document.getElementById('riadUsername').value.trim();
    const password = document.getElementById('riadPassword').value;

    if (!code || !username) {
        showToast('أدخل كود المحل واسم المستخدم على الأقل', 'error');
        return;
    }

    await api('api/riad-proxy.php?action=save_credentials', {
        method: 'POST',
        body: JSON.stringify({ code_es: code, username: username, password: password })
    });
    showToast('تم حفظ بيانات الاتصال بنجاح', 'success');
    document.getElementById('riadPassword').value = '';
    document.getElementById('riadPassword').placeholder = '••••••• (محفوظة)';
}

async function testRiadConnection() {
    const btn = document.getElementById('riadTestBtn');
    const status = document.getElementById('riadConnectionStatus');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الاختبار...';
    status.innerHTML = '';

    try {
        // Save first
        const code = document.getElementById('riadCodeEs').value.trim();
        const username = document.getElementById('riadUsername').value.trim();
        const password = document.getElementById('riadPassword').value;

        if (!code || !username) {
            status.innerHTML = '<div style="color:var(--danger);font-weight:600;padding:10px;background:#fef2f2;border-radius:8px;"><i class="fas fa-times-circle"></i> أكمل كود المحل واسم المستخدم أولاً</div>';
            return;
        }

        await api('api/riad-proxy.php?action=save_credentials', {
            method: 'POST',
            body: JSON.stringify({ code_es: code, username: username, password: password })
        });

        // Then test login
        const result = await api('api/riad-proxy.php?action=test');
        if (result.success) {
            status.innerHTML = `<div style="color:var(--success);font-weight:600;padding:12px;background:#f0fdf4;border-radius:8px;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-check-circle" style="font-size:20px;"></i>
                <div>${esc(result.message)}</div>
            </div>`;
        } else {
            status.innerHTML = `<div style="color:var(--danger);font-weight:600;padding:12px;background:#fef2f2;border-radius:8px;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-times-circle" style="font-size:20px;"></i>
                <div>${esc(result.error)}</div>
            </div>`;
        }
    } catch (e) {
        status.innerHTML = `<div style="color:var(--danger);font-weight:600;padding:10px;background:#fef2f2;border-radius:8px;"><i class="fas fa-times-circle"></i> ${esc(e.message)}</div>`;
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-plug"></i> اختبار الاتصال';
    }
}

// -- Settings: Riad Config Management --
async function loadRiadConfigManager() {
    if (APP_ROLE !== 'admin') return;
    try {
        const configs = await api('api/riad-proxy.php?action=config_list');
        const container = document.getElementById('riadConfigManager');
        if (!container) return;

        if (!configs || configs.length === 0) {
            container.innerHTML = '<div style="padding:10px;color:#919294;font-size:13px;">لا توجد خدمات مربوطة بعد</div>';
            return;
        }

        container.innerHTML = configs.map(c => `
            <div class="settings-item">
                <div class="item-info">
                    <i class="fas fa-cloud-arrow-down" style="font-size:18px;color:var(--primary);"></i>
                    <div>
                        <div class="item-label">${esc(c.service_name)} ${c.label ? '(' + esc(c.label) + ')' : ''}</div>
                        <div class="item-sub" style="direction:ltr;font-size:11px;">${esc(c.operator_service_id.substring(0, 20))}... | criteria: ${esc(c.search_criteria)}</div>
                    </div>
                </div>
                <div class="item-actions">
                    <button class="btn btn-sm btn-danger" onclick="deleteRiadConfig(${c.id})"><i class="fas fa-trash"></i></button>
                </div>
            </div>
        `).join('');
    } catch (e) {
        // Table might not exist yet
        const container = document.getElementById('riadConfigManager');
        if (container) container.innerHTML = '<div style="padding:10px;color:#919294;font-size:13px;">سيتم إنشاء الإعدادات عند أول استعمال</div>';
    }
}

function openRiadConfigModal() {
    const sel = document.getElementById('riadServiceType');
    sel.innerHTML = serviceTypes.map(s => `<option value="${s.id}">${esc(s.name)}</option>`).join('');
    document.getElementById('riadOperatorId').value = 'fe559a53-e921-4677-a8c4-a6d5f55e82db';
    document.getElementById('riadSearchCriteria').value = '6';
    document.getElementById('riadLabel').value = '';
    openModal('riadConfigModal');
}

async function saveRiadConfig() {
    const operatorId = document.getElementById('riadOperatorId').value.trim();
    if (!operatorId) { showToast('يرجى إدخال Operator Service ID', 'error'); return; }

    await api('api/riad-proxy.php?action=config_save', {
        method: 'POST',
        body: JSON.stringify({
            service_type_id: document.getElementById('riadServiceType').value,
            operator_service_id: operatorId,
            search_criteria: document.getElementById('riadSearchCriteria').value,
            label: document.getElementById('riadLabel').value.trim()
        })
    });
    showToast('تم حفظ إعدادات RIAD', 'success');
    closeModal('riadConfigModal');
    loadRiadConfigManager();
}

async function deleteRiadConfig(id) {
    const ok = await confirmAction('حذف الربط', 'هل أنت متأكد من حذف هذا الربط؟');
    if (!ok) return;
    await api(`api/riad-proxy.php?action=config_delete&id=${id}`);
    showToast('تم الحذف', 'success');
    loadRiadConfigManager();
}

// =====================
// LOAD SERVICE TYPES
// =====================
async function loadServiceTypes() {
    serviceTypes = await api('api/services.php?action=list');
}

// =====================
// UTILITIES
// =====================
function openModal(id) { document.getElementById(id).classList.add('show'); }
function closeModal(id) { document.getElementById(id).classList.remove('show'); }

// Custom confirm dialog (replaces browser confirm())
function confirmAction(title, message) {
    return new Promise((resolve) => {
        document.getElementById('confirmTitle').textContent = title;
        document.getElementById('confirmMessage').textContent = message;
        const btn = document.getElementById('confirmBtn');
        // Remove old listeners
        const newBtn = btn.cloneNode(true);
        btn.parentNode.replaceChild(newBtn, btn);
        newBtn.addEventListener('click', () => { closeModal('confirmModal'); resolve(true); });
        // Cancel = resolve false
        const overlay = document.getElementById('confirmModal');
        const cancelBtns = overlay.querySelectorAll('.btn-secondary, .modal-close');
        cancelBtns.forEach(b => {
            const nb = b.cloneNode(true);
            b.parentNode.replaceChild(nb, b);
            nb.addEventListener('click', () => { closeModal('confirmModal'); resolve(false); });
        });
        openModal('confirmModal');
    });
}

function esc(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function showToast(message, type = 'info') {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    const icons = { success: 'fa-check-circle', error: 'fa-exclamation-circle', info: 'fa-info-circle' };
    toast.innerHTML = `<i class="fas ${icons[type] || icons.info}"></i> ${message}`;
    container.appendChild(toast);
    setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 300); }, 3000);
}
