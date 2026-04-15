<?php
require_once __DIR__ . '/includes/auth.php';
if (!isLoggedIn()) { header('Location: index.php'); exit; }
$role = $_SESSION['role'];
$fullName = $_SESSION['full_name'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MOLAY - لوحة التحكم</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo-icon"><img src="logo.png" alt="Moulay Chaabi" style="width:100%;height:100%;object-fit:contain;border-radius:15px;"></div>
            <h2>MOULAY CHAABI</h2>
            <span class="version">v1.0</span>
        </div>
        <nav class="sidebar-nav">
            <a href="#" class="nav-item active" data-page="invoices">
                <i class="fas fa-file-invoice"></i><span>إدخال الفواتير</span>
            </a>
            <a href="#" class="nav-item" data-page="clients">
                <i class="fas fa-users"></i><span>العملاء</span>
            </a>
            <a href="#" class="nav-item" data-page="reports">
                <i class="fas fa-chart-bar"></i><span>التقارير</span>
            </a>
            <?php if ($role === 'admin'): ?>
            <a href="#" class="nav-item" data-page="settings">
                <i class="fas fa-cog"></i><span>الإعدادات</span>
            </a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-footer">
            <div class="user-info">
                <i class="fas fa-user-circle"></i>
                <div>
                    <strong><?= htmlspecialchars($fullName) ?></strong>
                    <small><?= $role === 'admin' ? 'مدير' : 'مساعد' ?></small>
                </div>
            </div>
            <a href="api/auth.php?action=logout" class="btn-logout"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </aside>

    <!-- RIAD Session Expiry Banner -->
    <div id="riadExpiryBanner" style="display:none;position:fixed;top:0;left:0;right:0;z-index:9999;background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;padding:12px 20px;text-align:center;font-family:'Cairo',sans-serif;font-size:14px;font-weight:700;box-shadow:0 4px 20px rgba(0,0,0,0.3);">
        <i class="fas fa-exclamation-triangle" style="margin-left:8px;"></i>
        جلسة RIAD M2T منتهية أو غير صالحة — يرجى تحديث كوكي الجلسة في الإعدادات
        <button onclick="goToRiadSettings()" style="margin-right:15px;background:rgba(255,255,255,0.2);border:2px solid rgba(255,255,255,0.5);color:#fff;padding:4px 14px;border-radius:20px;cursor:pointer;font-family:'Cairo',sans-serif;font-weight:700;font-size:13px;">
            <i class="fas fa-cog"></i> الإعدادات
        </button>
        <button onclick="document.getElementById('riadExpiryBanner').style.display='none'" style="background:transparent;border:none;color:rgba(255,255,255,0.7);cursor:pointer;font-size:18px;vertical-align:middle;">×</button>
    </div>

    <!-- Main Content -->
    <main class="main-content" id="mainContent">
        <!-- Top Bar -->
        <header class="topbar">
            <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
            <div class="topbar-title" id="pageTitle">إدخال الفواتير</div>
            <div class="topbar-actions">
                <div class="period-selector">
                    <select id="globalMonth"></select>
                    <select id="globalYear"></select>
                </div>
            </div>
        </header>

        <!-- Page: Invoices -->
        <section class="page active" id="page-invoices">
            <div class="search-section">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="clientSearch" placeholder="ابحث عن عميل بالاسم أو رقم العداد...">
                    <div class="search-results" id="searchResults"></div>
                </div>
            </div>

            <div id="invoiceWorkspace" class="invoice-workspace" style="display:none;">
                <div class="client-header" id="clientHeader"></div>
                <div class="meters-grid" id="metersGrid"></div>
                <div class="invoice-totals" id="invoiceTotals"></div>
                <div class="invoice-actions" id="invoiceActions"></div>
            </div>

            <!-- Summary for all clients -->
            <div class="summary-section" id="globalSummary">
                <h3><i class="fas fa-calculator"></i> ملخص الشهر</h3>
                <div class="summary-cards" id="summaryCards"></div>
                <div class="recent-entries" id="recentEntries"></div>
            </div>
        </section>

        <!-- Page: Clients -->
        <section class="page" id="page-clients">
            <div class="page-header">
                <h2>إدارة العملاء</h2>
                <?php if ($role === 'admin'): ?>
                <button class="btn btn-primary" onclick="openClientModal()">
                    <i class="fas fa-plus"></i> إضافة عميل
                </button>
                <?php endif; ?>
            </div>
            <div class="clients-table-wrapper">
                <table class="data-table" id="clientsTable">
                    <thead>
                        <tr>
                            <th>#</th><th>الاسم</th><th>الهاتف</th><th>العنوان</th>
                            <th>العدادات</th><th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody id="clientsTableBody"></tbody>
                </table>
            </div>
        </section>

        <!-- Page: Reports -->
        <section class="page" id="page-reports">
            <div class="page-header">
                <h2>التقارير والإحصائيات</h2>
            </div>
            <div class="report-filters">
                <select id="reportMonth"></select>
                <select id="reportYear"></select>
                <select id="reportType">
                    <option value="all">جميع الخدمات</option>
                </select>
                <button class="btn btn-primary" onclick="generateReport()">
                    <i class="fas fa-sync"></i> تحديث
                </button>
                <button class="btn btn-success" onclick="exportReport()">
                    <i class="fas fa-file-excel"></i> تصدير
                </button>
            </div>
            <div class="report-summary" id="reportSummary"></div>
            <?php if ($role === 'admin'): ?>
            <div id="bulkActionsBar" style="display:none;background:#f0fdf4;border:2px solid #10b981;border-radius:12px;padding:12px 18px;margin-bottom:15px;align-items:center;gap:12px;flex-wrap:wrap;">
                <span id="bulkSelectionCount" style="font-weight:700;color:#065f46;"></span>
                <button class="btn btn-success btn-sm" onclick="bulkMarkPaid(1)">
                    <i class="fas fa-check-circle"></i> تحديد كمدفوعة
                </button>
                <button class="btn btn-sm" style="background:#fee2e2;color:#991b1b;" onclick="bulkMarkPaid(0)">
                    <i class="fas fa-times-circle"></i> تحديد كغير مدفوعة
                </button>
                <button class="btn btn-sm btn-secondary" onclick="clearBulkSelection()">
                    <i class="fas fa-times"></i> إلغاء التحديد
                </button>
            </div>
            <?php endif; ?>
            <div class="report-table-wrapper">
                <table class="data-table" id="reportTable">
                    <thead id="reportTableHead"></thead>
                    <tbody id="reportTableBody"></tbody>
                </table>
            </div>
        </section>

        <!-- Page: Settings (Admin only) -->
        <?php if ($role === 'admin'): ?>
        <section class="page" id="page-settings">
            <div class="page-header"><h2>الإعدادات</h2></div>
            <div class="settings-grid">
                <div class="settings-card">
                    <h3><i class="fas fa-users-cog"></i> المستخدمون</h3>
                    <div id="usersManager"></div>
                    <button class="btn btn-primary" onclick="openUserModal()">
                        <i class="fas fa-plus"></i> إضافة مستخدم
                    </button>
                </div>
                <div class="settings-card">
                    <h3><i class="fas fa-list"></i> أنواع الخدمات</h3>
                    <div id="servicesManager"></div>
                    <button class="btn btn-primary" onclick="openServiceModal()">
                        <i class="fas fa-plus"></i> إضافة خدمة
                    </button>
                </div>
                <div class="settings-card" style="grid-column: 1 / -1;">
                    <h3><i class="fab fa-whatsapp" style="color:#25d366;"></i> قالب رسالة الواتساب</h3>
                    <p style="color:#919294;font-size:13px;margin-bottom:15px;">
                        عدّل مقدمة وخاتمة الرسالة. الجزء الأوسط (تفاصيل الفواتير) يُولّد تلقائياً.
                    </p>

                    <div class="form-group">
                        <label>🔝 مقدمة الرسالة (قبل تفاصيل الفواتير)</label>
                        <textarea id="waTemplateHeader" rows="3" dir="rtl" style="line-height:2;white-space:pre-wrap;" placeholder="السلام عليكم ورحمة الله 🙏&#10;الأخ(ة): {client_name}&#10;&#10;📋 *كشف فواتير الخدمات*"></textarea>
                        <p style="color:#919294;font-size:11px;margin-top:4px;">
                            استعمل <code>{client_name}</code> لاسم العميل
                        </p>
                    </div>

                    <div style="background:#f0f0f1;border-radius:8px;padding:12px;margin-bottom:15px;text-align:center;color:#919294;font-size:13px;">
                        <i class="fas fa-cogs"></i> ← هنا تفاصيل الفواتير (تُولّد تلقائياً حسب العدادات والأشهر المختارة) →
                    </div>

                    <div class="form-group">
                        <label>🔚 خاتمة الرسالة (بعد تفاصيل الفواتير)</label>
                        <textarea id="waTemplateFooter" rows="3" dir="rtl" style="line-height:2;white-space:pre-wrap;" placeholder="📌 يرجى تسديد المبلغ في أقرب وقت.&#10;شكراً لثقتكم 🙏"></textarea>
                    </div>

                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <button class="btn btn-primary" onclick="saveWaTemplate()">
                            <i class="fas fa-save"></i> حفظ القالب
                        </button>
                        <button class="btn" style="background:#fee2e2;color:#991b1b;" onclick="resetWaTemplate()">
                            <i class="fas fa-rotate-left"></i> إرجاع القالب الافتراضي
                        </button>
                    </div>
                </div>

                <div class="settings-card" style="grid-column: 1 / -1;">
                    <h3><i class="fas fa-cloud-arrow-down"></i> ربط RIAD M2T (استخراج تلقائي)</h3>

                    <!-- Sync Status Banner -->
                    <div id="riadSyncStatus" style="display:none;border-radius:12px;padding:15px 20px;margin-bottom:20px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;"></div>

                    <!-- Step 1: Connection -->
                    <div class="riad-setup-section" style="background:#f8f9fa;border-radius:12px;padding:20px;margin-bottom:20px;">
                        <h4 style="margin-bottom:12px;display:flex;align-items:center;gap:8px;">
                            <span style="background:var(--primary);color:#fff;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;">1</span>
                            بيانات الاتصال بـ RIAD
                        </h4>

                        <!-- Auto-Sync Info -->
                        <div style="background:#d1fae5;border:2px solid #10b981;border-radius:10px;padding:14px;margin-bottom:18px;font-size:13px;color:#065f46;line-height:1.8;">
                            <strong><i class="fas fa-magic"></i> مزامنة تلقائية:</strong>
                            ثبّت سكريبت Tampermonkey ← ابحث عن أي عميل في riad.m2t.ma ← كل البيانات تُحفظ هنا تلقائياً!
                            <br><span style="font-size:11px;color:#047857;">آخر مزامنة: <span id="lastSyncTime">—</span></span>
                        </div>

                        <div class="form-row" style="margin-bottom:10px;">
                            <div class="form-group" style="margin-bottom:0;">
                                <label>كود المحل (x-code-es) *</label>
                                <input type="text" id="riadCodeEs" placeholder="مثال: 006581" style="direction:ltr;text-align:left;font-weight:700;font-size:16px;">
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label>X-SESSIONID</label>
                                <input type="text" id="riadSessionId" placeholder="X-63-1" style="direction:ltr;text-align:left;">
                            </div>
                        </div>
                        <div class="form-row" style="margin-bottom:10px;">
                            <div class="form-group" style="margin-bottom:0;">
                                <label>JWT Token</label>
                                <input type="text" id="riadJwtToken" placeholder="eyJhbGciOi..." style="direction:ltr;text-align:left;font-size:11px;">
                            </div>
                        </div>

                        <!-- JWT Expiry Display -->
                        <div id="jwtExpiryDisplay" style="display:none;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px;"></div>

                        <details style="margin-bottom:12px;">
                            <summary style="font-size:12px;color:#919294;cursor:pointer;"><i class="fas fa-cog"></i> إعدادات متقدمة (Device Cookie)</summary>
                            <div style="padding:10px 0;">
                                <div class="form-row">
                                    <div class="form-group" style="margin-bottom:0;">
                                        <label>اسم كوكي الجهاز</label>
                                        <input type="text" id="riadDeviceName" placeholder="device_HE7669" style="direction:ltr;text-align:left;font-size:12px;">
                                    </div>
                                    <div class="form-group" style="margin-bottom:0;">
                                        <label>قيمة كوكي الجهاز</label>
                                        <input type="text" id="riadDeviceValue" placeholder="UUID..." style="direction:ltr;text-align:left;font-size:12px;">
                                    </div>
                                </div>
                            </div>
                        </details>

                        <div style="display:flex;gap:8px;margin-bottom:15px;flex-wrap:wrap;">
                            <button class="btn btn-primary" onclick="saveRiadSession()" style="height:44px;">
                                <i class="fas fa-save"></i> حفظ
                            </button>
                            <button class="btn btn-success" onclick="testRiadConnection()" style="height:44px;" id="riadTestBtn">
                                <i class="fas fa-plug"></i> اختبار الاتصال
                            </button>
                        </div>
                        <div id="riadConnectionStatus"></div>
                    </div>

                    <!-- Step 2: Services Mapping -->
                    <div class="riad-setup-section" style="background:#f8f9fa;border-radius:12px;padding:20px;">
                        <h4 style="margin-bottom:12px;display:flex;align-items:center;gap:8px;">
                            <span style="background:var(--primary);color:#fff;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;">2</span>
                            ربط الخدمات
                        </h4>
                        <p style="color:#919294;font-size:13px;margin-bottom:15px;">
                            كل خدمة في RIAD لها معرّف خاص (Operator Service ID). اربط كل نوع خدمة عندك بالمعرّف المقابل.
                            <br>المعرّف الافتراضي للكهرباء ONEE هو: <code style="background:#fff;padding:2px 8px;border-radius:4px;font-size:12px;direction:ltr;">fe559a53-e921-4677-a8c4-a6d5f55e82db</code>
                        </p>
                        <div id="riadConfigManager"></div>
                        <button class="btn btn-primary" onclick="openRiadConfigModal()" style="margin-top:10px;">
                            <i class="fas fa-plus"></i> ربط خدمة جديدة
                        </button>
                    </div>

                    <!-- RIAD Session Status -->
                    <div id="riadSessionStatusCard" style="margin-top:15px;display:none;padding:12px 16px;border-radius:10px;font-size:13px;font-weight:600;"></div>

                    <!-- Help -->
                    <details style="margin-top:15px;cursor:pointer;">
                        <summary style="font-weight:700;color:var(--primary);font-size:14px;">
                            <i class="fas fa-question-circle"></i> كيف أحصل على هذه المعلومات؟
                        </summary>
                        <div style="padding:15px;background:#fffbeb;border-radius:8px;margin-top:10px;font-size:13px;line-height:2;">
                            <strong style="color:var(--success);"><i class="fas fa-magic"></i> الطريقة التلقائية (مُوصى بها):</strong><br>
                            &nbsp;&nbsp;1. ثبّت إضافة <a href="https://www.tampermonkey.net/" target="_blank" style="color:var(--primary);">Tampermonkey</a> في المتصفح<br>
                            &nbsp;&nbsp;2. أضف سكريبت MOLAY Sync (متوفر في ملفات المشروع)<br>
                            &nbsp;&nbsp;3. سجّل الدخول في riad.m2t.ma وابحث عن أي عميل<br>
                            &nbsp;&nbsp;4. كل البيانات تُحفظ تلقائياً هنا ✅<br>
                            <br>
                            <strong>الطريقة اليدوية:</strong><br>
                            &nbsp;&nbsp;• سجّل الدخول في <a href="https://riad.m2t.ma" target="_blank" style="color:var(--primary);">riad.m2t.ma</a><br>
                            &nbsp;&nbsp;• اضغط F12 ← تبويب <strong>Application</strong> ← <strong>Cookies</strong> ← riad-api.m2t.ma<br>
                            &nbsp;&nbsp;• انسخ: <code>token</code> (JWT) + <code>X-SESSIONID</code> + <code>device_XXXXX</code><br>
                            &nbsp;&nbsp;• ⚠️ الـ JWT ينتهي كل <strong>ساعتين</strong>. أعد النسخ عند الانتهاء.<br>
                            <br>
                            <strong>المعرّفات:</strong><br>
                            &nbsp;&nbsp;• <strong>x-code-es:</strong> في Network ← Headers ← ابحث عن <code>x-code-es</code><br>
                            &nbsp;&nbsp;• <strong>Operator Service ID:</strong> في URL بعد <code>operatorServiceId=</code><br>
                            &nbsp;&nbsp;• <strong>Search Criteria:</strong> 6 = كهرباء ONEE | 1 = ماء/أخرى
                        </div>
                    </details>
                </div>
                <div class="settings-card" style="grid-column: 1 / -1;">
                    <h3><i class="fas fa-database" style="color:var(--primary);"></i> النسخ الاحتياطي</h3>
                    <p style="color:#919294;font-size:13px;margin-bottom:15px;">
                        قم بتنزيل نسخة احتياطية كاملة من قاعدة البيانات. يُنصح بعمل نسخة احتياطية أسبوعياً.
                    </p>
                    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                        <button class="btn btn-primary" onclick="downloadBackup()">
                            <i class="fas fa-download"></i> تنزيل نسخة احتياطية
                        </button>
                        <span id="backupStatus" style="font-size:13px;color:#919294;"></span>
                    </div>
                </div>

                <div class="settings-card" style="grid-column: 1 / -1;">
                    <h3><i class="fas fa-history" style="color:#6b7280;"></i> سجل النشاطات (Audit Log)</h3>
                    <p style="color:#919294;font-size:13px;margin-bottom:15px;">
                        سجل بجميع العمليات التي تمت على الفواتير والبيانات.
                    </p>
                    <div style="display:flex;gap:10px;margin-bottom:15px;flex-wrap:wrap;">
                        <button class="btn btn-primary btn-sm" onclick="loadAuditLog()">
                            <i class="fas fa-sync"></i> تحديث السجل
                        </button>
                    </div>
                    <div id="auditLogContainer" style="max-height:380px;overflow-y:auto;border:1px solid #e8e8e8;border-radius:10px;">
                        <div style="padding:30px;text-align:center;color:#919294;">اضغط "تحديث السجل" للعرض</div>
                    </div>
                </div>
            </div>
        </section>
        <?php endif; ?>
    </main>

    <!-- Modal: Riad Config -->
    <div class="modal-overlay" id="riadConfigModal">
        <div class="modal">
            <div class="modal-header">
                <h3 id="riadConfigModalTitle"><i class="fas fa-cloud-arrow-down"></i> ربط خدمة بـ RIAD</h3>
                <button class="modal-close" onclick="closeModal('riadConfigModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>نوع الخدمة *</label>
                    <select id="riadServiceType"></select>
                </div>
                <div class="form-group">
                    <label>Operator Service ID *</label>
                    <input type="text" id="riadOperatorId" placeholder="fe559a53-e921-4677-a8c4-a6d5f55e82db" style="direction:ltr;text-align:left;">
                </div>
                <div class="form-group">
                    <label>Search Criteria</label>
                    <select id="riadSearchCriteria">
                        <option value="6">6 - كهرباء (ONEE)</option>
                        <option value="1">1 - ماء / أخرى</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>وصف (اختياري)</label>
                    <input type="text" id="riadLabel" placeholder="مثال: ONEE Electricite">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('riadConfigModal')">إلغاء</button>
                <button class="btn btn-primary" onclick="saveRiadConfig()">حفظ</button>
            </div>
        </div>
    </div>

    <!-- Modal: Riad Fetch Results -->
    <div class="modal-overlay" id="riadResultsModal">
        <div class="modal modal-lg">
            <div class="modal-header" style="background:linear-gradient(135deg,#0f3460,#1a1a2e);">
                <h3 style="color:#fff;"><i class="fas fa-cloud-arrow-down"></i> نتائج الاستخراج التلقائي</h3>
                <button class="modal-close" onclick="closeModal('riadResultsModal')" style="color:#fff;">&times;</button>
            </div>
            <div class="modal-body" id="riadResultsBody">
                <div style="text-align:center;padding:30px;">
                    <i class="fas fa-spinner fa-spin" style="font-size:30px;color:#F38E21;"></i>
                    <p style="margin-top:10px;">جاري الاستخراج...</p>
                </div>
            </div>
            <div class="modal-footer" id="riadResultsFooter" style="display:none;">
                <button class="btn btn-secondary" onclick="closeModal('riadResultsModal')">إلغاء</button>
                <button class="btn btn-primary" onclick="applyRiadResults()">
                    <i class="fas fa-check"></i> تطبيق المبالغ
                </button>
            </div>
        </div>
    </div>

    <!-- Modal: Client -->
    <div class="modal-overlay" id="clientModal">
        <div class="modal">
            <div class="modal-header">
                <h3 id="clientModalTitle">إضافة عميل</h3>
                <button class="modal-close" onclick="closeModal('clientModal')">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="clientId">
                <div class="form-row">
                    <div class="form-group">
                        <label>الاسم الكامل *</label>
                        <input type="text" id="clientName" required>
                    </div>
                    <div class="form-group">
                        <label>الهاتف</label>
                        <input type="text" id="clientPhone" placeholder="06XXXXXXXX">
                    </div>
                </div>
                <div class="form-group">
                    <label>العنوان</label>
                    <input type="text" id="clientAddress">
                </div>
                <div class="form-group">
                    <label>ملاحظات</label>
                    <textarea id="clientNotes" rows="2"></textarea>
                </div>
                <hr>
                <h4 style="margin: 15px 0;">العدادات والحسابات</h4>
                <div id="metersContainer"></div>
                <button type="button" class="btn btn-outline" onclick="addMeterRow()">
                    <i class="fas fa-plus"></i> إضافة عداد
                </button>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('clientModal')">إلغاء</button>
                <button class="btn btn-primary" onclick="saveClient()">حفظ</button>
            </div>
        </div>
    </div>

    <!-- Modal: User -->
    <div class="modal-overlay" id="userModal">
        <div class="modal">
            <div class="modal-header">
                <h3 id="userModalTitle">إضافة مستخدم</h3>
                <button class="modal-close" onclick="closeModal('userModal')">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="userId">
                <div class="form-group">
                    <label>الاسم الكامل</label>
                    <input type="text" id="userFullName">
                </div>
                <div class="form-group">
                    <label>اسم المستخدم</label>
                    <input type="text" id="userUsername">
                </div>
                <div class="form-group">
                    <label>كلمة المرور</label>
                    <input type="password" id="userPassword">
                </div>
                <div class="form-group">
                    <label>الصلاحية</label>
                    <select id="userRole">
                        <option value="assistant">مساعد</option>
                        <option value="admin">مدير</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('userModal')">إلغاء</button>
                <button class="btn btn-primary" onclick="saveUser()">حفظ</button>
            </div>
        </div>
    </div>

    <!-- Modal: Service -->
    <div class="modal-overlay" id="serviceModal">
        <div class="modal">
            <div class="modal-header">
                <h3 id="serviceModalTitle">إضافة خدمة</h3>
                <button class="modal-close" onclick="closeModal('serviceModal')">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="serviceId">
                <div class="form-group">
                    <label>اسم الخدمة *</label>
                    <input type="text" id="serviceName" placeholder="مثال: كهرباء، ماء، إنترنت...">
                </div>
                <div class="form-group">
                    <label>الأيقونة (FontAwesome)</label>
                    <div class="icon-picker-wrapper">
                        <input type="text" id="serviceIcon" value="fa-file-invoice" placeholder="fa-bolt" style="direction:ltr;">
                        <div class="icon-preview" id="iconPreview"><i class="fas fa-file-invoice"></i></div>
                    </div>
                    <div class="icon-suggestions" id="iconSuggestions">
                        <span class="icon-chip" data-icon="fa-bolt" title="كهرباء"><i class="fas fa-bolt"></i></span>
                        <span class="icon-chip" data-icon="fa-droplet" title="ماء"><i class="fas fa-droplet"></i></span>
                        <span class="icon-chip" data-icon="fa-wifi" title="إنترنت"><i class="fas fa-wifi"></i></span>
                        <span class="icon-chip" data-icon="fa-mobile-screen" title="هاتف نقال"><i class="fas fa-mobile-screen"></i></span>
                        <span class="icon-chip" data-icon="fa-phone" title="هاتف ثابت"><i class="fas fa-phone"></i></span>
                        <span class="icon-chip" data-icon="fa-tv" title="تلفاز"><i class="fas fa-tv"></i></span>
                        <span class="icon-chip" data-icon="fa-fire-flame-simple" title="غاز"><i class="fas fa-fire-flame-simple"></i></span>
                        <span class="icon-chip" data-icon="fa-satellite-dish" title="قمر صناعي"><i class="fas fa-satellite-dish"></i></span>
                        <span class="icon-chip" data-icon="fa-building" title="عقار"><i class="fas fa-building"></i></span>
                        <span class="icon-chip" data-icon="fa-file-invoice" title="فاتورة"><i class="fas fa-file-invoice"></i></span>
                    </div>
                </div>
                <div class="form-group">
                    <label>اللون</label>
                    <div class="color-picker-wrapper">
                        <input type="color" id="serviceColor" value="#F38E21">
                        <div class="color-presets">
                            <span class="color-chip" data-color="#f59e0b" style="background:#f59e0b;" title="برتقالي"></span>
                            <span class="color-chip" data-color="#3b82f6" style="background:#3b82f6;" title="أزرق"></span>
                            <span class="color-chip" data-color="#8b5cf6" style="background:#8b5cf6;" title="بنفسجي"></span>
                            <span class="color-chip" data-color="#10b981" style="background:#10b981;" title="أخضر"></span>
                            <span class="color-chip" data-color="#6b7280" style="background:#6b7280;" title="رمادي"></span>
                            <span class="color-chip" data-color="#ef4444" style="background:#ef4444;" title="أحمر"></span>
                            <span class="color-chip" data-color="#F38E21" style="background:#F38E21;" title="برتقالي غامق"></span>
                            <span class="color-chip" data-color="#06b6d4" style="background:#06b6d4;" title="سماوي"></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('serviceModal')">إلغاء</button>
                <button class="btn btn-primary" onclick="saveServiceFromModal()">حفظ</button>
            </div>
        </div>
    </div>

    <!-- Modal: WhatsApp Preview -->
    <div class="modal-overlay" id="whatsappModal">
        <div class="modal modal-lg">
            <div class="modal-header whatsapp-header">
                <h3><i class="fab fa-whatsapp"></i> إرسال عبر واتساب</h3>
                <button class="modal-close" onclick="closeModal('whatsappModal')" style="color:#fff;">&times;</button>
            </div>
            <div class="modal-body">
                <div class="wa-months-selector" id="waMonthsSelector"></div>

                <!-- Tab switch: Preview / Edit -->
                <div style="display:flex;gap:8px;margin:12px 0;">
                    <button class="btn btn-sm wa-tab active" id="waTabPreview" onclick="switchWaTab('preview')">
                        <i class="fas fa-eye"></i> معاينة
                    </button>
                    <button class="btn btn-sm wa-tab" id="waTabEdit" onclick="switchWaTab('edit')">
                        <i class="fas fa-edit"></i> تعديل الرسالة
                    </button>
                    <button class="btn btn-sm" style="margin-right:auto;background:#fee2e2;color:#991b1b;" onclick="resetWaMessage()" title="إرجاع للرسالة الأصلية">
                        <i class="fas fa-rotate-left"></i> إرجاع للأصل
                    </button>
                </div>

                <!-- Preview mode -->
                <div class="wa-preview" id="waPreview"></div>

                <!-- Edit mode -->
                <div id="waEditSection" style="display:none;">
                    <textarea id="waEditArea" dir="rtl" style="width:100%;min-height:280px;padding:15px;border:2px solid #e8e8e8;border-radius:12px;font-family:'Cairo',sans-serif;font-size:14px;line-height:2;resize:vertical;white-space:pre-wrap;" placeholder="اكتب رسالة الواتساب هنا..."></textarea>
                    <p style="color:#919294;font-size:11px;margin-top:6px;">
                        <i class="fas fa-info-circle"></i>
                        يمكنك تعديل النص كما تشاء. اضغط "معاينة" لرؤية الشكل النهائي.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('whatsappModal')">إلغاء</button>
                <button class="btn btn-whatsapp" onclick="sendWhatsApp()">
                    <i class="fab fa-whatsapp"></i> إرسال
                </button>
            </div>
        </div>
    </div>

    <!-- Modal: Confirm Delete -->
    <div class="modal-overlay" id="confirmModal">
        <div class="modal" style="width:420px;">
            <div class="modal-body" style="text-align:center;padding:35px 25px;">
                <div style="width:65px;height:65px;border-radius:50%;background:#fef2f2;display:flex;align-items:center;justify-content:center;margin:0 auto 18px;">
                    <i class="fas fa-exclamation-triangle" style="font-size:30px;color:var(--danger);"></i>
                </div>
                <h3 style="margin-bottom:10px;color:var(--dark);" id="confirmTitle">تأكيد الحذف</h3>
                <p style="color:#919294;font-size:14px;line-height:1.8;" id="confirmMessage">هل أنت متأكد؟</p>
            </div>
            <div class="modal-footer" style="justify-content:center;gap:12px;">
                <button class="btn btn-secondary" onclick="closeModal('confirmModal')" style="min-width:100px;">إلغاء</button>
                <button class="btn btn-danger" id="confirmBtn" style="min-width:100px;">
                    <i class="fas fa-trash"></i> حذف
                </button>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div class="toast-container" id="toastContainer"></div>

    <script>
        const APP_ROLE = '<?= $role ?>';
        const APP_USER = '<?= htmlspecialchars($fullName) ?>';
        const APP_USERNAME = '<?= htmlspecialchars($_SESSION['username']) ?>';
    </script>
    <script src="assets/js/app.js"></script>
</body>
</html>
