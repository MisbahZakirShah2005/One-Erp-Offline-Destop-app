    <?php
    session_start();
    include('partials/performance.php');
    require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');
    
if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}
    $logged_client_id = (int)$_SESSION['client_id'];
    
    $feature_labels = [
        'dashboard'=>'Dashboard',
        'invoices'=>'Invoices / Billing',
        'pos_sales'=>'POS Sales',
        'inventory'=>'Products / Inventory',
        'inventory_adjustments'=>'Inventory Adjustments',
        'customers'=>'Customers',
        'suppliers'=>'Suppliers / Purchase Orders',
        'users'=>'Users / Staff',
        'reports'=>'Reports / Analytics',
        'wholesale_module'=>'Wholesale Module',
        'fbr_integration'=>'FBR Integration',
        'tables'=>'Restaurant Tables',
        'reservations'=>'Table Reservations',
        'recipes'=>'Recipes Management',
        'memberships'=>'Customer Memberships',
        'appointments'=>'Appointments',
        'attendance'=>'Staff Attendance',
        'commissions'=>'Commissions',
        'supplier_management'=>'Supplier Management',
        'supplier_ledger'=>'Supplier Ledger',
        'customer_ledger'=>'Customer Ledger',
        'profit_loss'=>'Profit & Loss',
        'daily_sales'=>'Daily Sales',
        'cashier_shift'=>'Cashier Shift',
        'low_stock'=>'Low Stock',
        'tax_summary'=>'Tax Summary',
        'discounts'=>'Discounts / Coupons',
        'taxes'=>'Taxes / VAT',
        'multi_branch'=>'Multi-Branch',
        'membership'=>'Membership / Subscription',
        'payments'=>'Payments / Gateways',
        'expenses'=>'Expenses / Costs',
        'returns'=>'Returns / Refunds',
        'notifications'=>'Notifications',
        'settings'=>'Settings / Configurations',
        'integrations'=>'Integrations',
        'barcode'=>'Barcode / QR',
        'mobile_access'=>'Mobile / Web Access',
        'products'=>'Products / Items',
        'item_variants'=>'Item Variants'
    ];
    
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
    
    $search = trim($_GET['search'] ?? '');
    
    $staff = [];
    $sql = "SELECT * FROM users WHERE client_id=?";
    $params = [$logged_client_id];
    $types = "i";
    
    if ($search !== '') {
        $sql .= " AND (name LIKE ? OR email LIKE ? OR phone LIKE ?)";
        $like = "%{$search}%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= "sss";
    }
    
    $sql .= " ORDER BY id DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $staff[] = $r;
    }
    $stmt->close();
    
    $user_permissions_map = [];
    foreach ($staff as $s) {
        $user_permissions_map[$s['id']] = [];
        $stmt = $conn->prepare("SELECT feature_key FROM users_permissions WHERE user_id=?");
        $stmt->bind_param("i", $s['id']);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r2 = $res->fetch_assoc()) {
            if (!empty($r2['feature_key'])) {
                $user_permissions_map[$s['id']][] = $r2['feature_key'];
            }
        }
        $user_permissions_map[$s['id']] = array_values(array_unique($user_permissions_map[$s['id']]));
        $stmt->close();
    }
    
    $totalStaff = count($staff);
    $admins = 0;
    $managers = 0;
    $activeUsers = count($staff);
    
    foreach ($staff as $s) {
        $permissions = $user_permissions_map[$s['id']] ?? [];
        if (in_array('users', $permissions, true) || in_array('settings', $permissions, true)) {
            $admins++;
        }
        if (in_array('reports', $permissions, true) || in_array('supplier_management', $permissions, true)) {
            $managers++;
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <title>Staff Management</title>
    
    <script>
(function () {
    const storageKey = 'oneerp_pos_theme';
    let selectedTheme = 'light';

    try {
        const savedTheme = localStorage.getItem(storageKey);

        if (savedTheme === 'light' || savedTheme === 'dark') {
            selectedTheme = savedTheme;
        }
    } catch (error) {
        selectedTheme = 'light';
    }

    document.documentElement.setAttribute(
        'data-pos-theme',
        selectedTheme
    );
})();
</script>
    <?php include('partials/head.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.css" rel="stylesheet">
    
    <style>
    *{box-sizing:border-box}
    body{margin:0;background:#eef2f7;font-family:Inter,Arial,sans-serif;color:#1f2736}
  .container-scroll{
        display:flex;
        min-height:100vh;
           align-items:stretch;
    }

    .sidebar{
        width:250px;
        flex-shrink:0;
          display:flex;*/
  align-self:stretch;
  background:transparent;
    overflow:hidden;
    }


.sidebar > div{
    width:100%;
    min-height:100%;
    height:100%;
}

#dashboardSidebar .sidebar-wrap{
    min-height:100% !important;
    height:100% !important;
    border-radius:0;
}

#dashboardSidebar .sidebar-inner{
    min-height:100% !important;
}
    .main-panel{flex:1;min-width:0;display:flex;flex-direction:column}
    .topbar{height:64px;background:#05070d;color:#fff;display:flex;align-items:center;padding:0 20px}
    .content-wrapper{padding:18px;flex:1;overflow:auto}
    
    .page-header{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px}
    .page-title{font-size:24px;font-weight:800;margin:0;color:#243046}
    .btn-gold{border:none;border-radius:12px;background:linear-gradient(180deg,#ffd25f,#f0aa00);color:#2a2100;font-weight:800;padding:12px 18px;min-width:170px}
    .btn-remove-staff{
    border:none;
    border-radius:12px;
    background:linear-gradient(180deg,#ff6b6b,#e53935);
    color:#fff;
    font-weight:800;
    padding:12px 18px;
    min-width:170px;
}
    
    .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px}
    .stat-card{border-radius:14px;padding:18px;min-height:92px;color:#fff;position:relative;overflow:hidden;box-shadow:0 14px 28px rgba(0,0,0,.12)}
    .stat-card::after{content:'';position:absolute;width:110px;height:110px;border-radius:50%;right:-18px;top:-18px;background:rgba(255,255,255,.12)}
    .stat-blue{background:linear-gradient(135deg,#5e84ff,#7fa9ff)}
    .stat-green{background:linear-gradient(135deg,#4ea98d,#66c09f)}
    .stat-cyan{background:linear-gradient(135deg,#56b4da,#7fd4f5)}
    .stat-gold{background:linear-gradient(135deg,#8e7f73,#b9a38b)}
    .stat-title{font-size:14px;font-weight:600;line-height:1.3}
    .stat-value{position:absolute;right:18px;bottom:14px;font-size:30px;font-weight:800;line-height:1}
    
    .toolbar-card,.table-card{background:#fff;border:1px solid #e6ebf3;border-radius:16px;box-shadow:0 8px 22px rgba(18,38,63,.05)}
    .toolbar-card{padding:14px;margin-bottom:16px}
    .toolbar-row{display:grid;grid-template-columns:1fr 140px 120px;gap:10px}
    .toolbar-input,.toolbar-btn{height:44px;border:1px solid #dbe2ed;border-radius:10px;background:#fff;padding:0 14px;outline:none}
    .toolbar-btn{display:flex;align-items:center;justify-content:center;text-decoration:none;font-weight:700;background:#f2f4fa;color:#4b566d}
    .filter-btn{background:#1f2642;color:#fff;border:none}
    
    .table-card{padding:0;overflow:hidden}
    .table-wrap{overflow:auto}
    .erp-table{width:100%;min-width:1100px;border-collapse:separate;border-spacing:0}
    .erp-table thead th{background:#1a1f2e;color:#fff;padding:14px 12px;font-size:14px;font-weight:600;white-space:nowrap}
    .erp-table tbody td{background:#fff;padding:13px 12px;border-bottom:1px solid #edf1f6;vertical-align:middle;white-space:nowrap}
    .erp-table tbody tr:hover td{background:#fafcff}
    .erp-table thead th:first-child{border-top-left-radius:12px}
    .erp-table thead th:last-child{border-top-right-radius:12px}
    
    .name-cell{font-weight:700;color:#1d2738}
    .sub-line{display:block;font-size:12px;color:#7a8598;margin-top:4px}
    .permission-badges{display:flex;gap:6px;flex-wrap:wrap}
    .perm-badge{background:#eef3ff;color:#4966d1;border-radius:999px;padding:5px 10px;font-size:12px;font-weight:700}
 .staff-actions{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:10px;
}

.staff-action-btn{
    width:38px;
    height:38px;
    border:none;
    border-radius:50%;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:0;
    cursor:pointer;
    transition:all .18s ease;
    box-shadow:0 4px 10px rgba(15,23,42,.06);
}

.staff-action-btn:hover{
    transform:translateY(-2px);
}

.staff-action-btn i{
    font-size:15px;
    line-height:1;
}

.staff-edit{
    background:#fff4d6;
    color:#e0a100;
}

.staff-edit:hover{
    background:#e0a100;
    color:#fff;
}

.staff-delete{
    background:#ffe3e3;
    color:#ef4444;
}

.staff-delete:hover{
    background:#ef4444;
    color:#fff;
}
    /* Staff Modal */
    .staff-modal-content{
        background:linear-gradient(135deg,#22273d 0%, #1d2238 100%);
        color:#fff;
        border:none;
        border-radius:14px;
        box-shadow:0 20px 60px rgba(0,0,0,0.35);
        width:100%;
        max-height:calc(100vh - 3.5rem);
        overflow:hidden;
        display:flex;
        flex-direction:column;
    }
    
    .staff-modal-header{
        background:transparent;
        border-bottom:1px solid rgba(255,255,255,0.08);
        padding:18px 22px;
        display:flex;
        justify-content:space-between;
        align-items:center;
        flex:0 0 auto;
    }
    
    .staff-modal-title{
        font-size:18px;
        font-weight:700;
        color:#fff;
        margin:0;
    }
    
    .staff-close{
        background:none;
        border:none;
        color:#fff;
        font-size:28px;
        line-height:1;
    }
    
    .staff-form-side{
        padding:20px 22px 10px;
        flex:1 1 auto;
        min-height:0;
        overflow-y:auto;
        overflow-x:hidden;
    }
    
    .dark-label{
        display:block;
        font-size:15px;
        margin-bottom:7px;
        color:#e4e8f8;
        font-weight:500;
    }
    
    .req{
        color:#f4b21c;
    }
    
    .dark-input,
    .dark-textarea{
        width:100%;
        border:1px solid rgba(255,255,255,0.10);
        background:#e9eaf3;
        color:#222;
        border-radius:8px;
        min-height:44px;
        padding:0 14px;
        outline:none;
        font-size:14px;
        box-shadow:none;
    }
    
    .dark-input:focus,
    .dark-textarea:focus{
        border-color:#f0ae19;
        box-shadow:0 0 0 2px rgba(240,174,25,0.20);
        background:#f4f5fb;
    }
    
    .dark-textarea{
        min-height:90px;
        resize:vertical;
        padding:12px 14px;
    }
    
    .password-strength-wrap{
        margin-top:8px;
        padding:12px;
        border:1px solid rgba(255,255,255,0.10);
        border-radius:12px;
        background:rgba(255,255,255,0.03);
    }
    
    .strength-bar{
        height:10px;
        border-radius:999px;
        background:rgba(255,255,255,.10);
        overflow:hidden;
    }
    
    .strength-fill{
        height:100%;
        width:25%;
        background:#f0aa00;
        border-radius:999px;
    }
    
    .strength-text{
        font-size:13px;
        color:#d8def1;
        margin-top:6px;
    }
    
    .permissions-box{
        border:1px solid rgba(255,255,255,.12);
        border-radius:14px;
        overflow:visible;
        margin-top:10px;
        background:rgba(255,255,255,.03);
    }
    
    .permissions-head{
        padding:14px 16px;
        border-bottom:1px solid rgba(255,255,255,.10);
        display:flex;
        justify-content:space-between;
        align-items:center;
        font-size:16px;
        font-weight:700;
        color:#fff;
    }
    
    .permissions-body{
        padding:14px 16px;
    }
    
    .modal-footer-custom{
        padding:16px 22px 18px;
        border-top:1px solid rgba(255,255,255,.08);
        display:flex;
        justify-content:space-between;
        align-items:center;
        flex-wrap:wrap;
        gap:14px;
        flex:0 0 auto;
    }
    
    .staff-footer-note{
        color:#dbe0f4;
        font-size:14px;
        font-weight:500;
    }
    
    .staff-footer-btns{
        display:flex;
        align-items:center;
        gap:10px;
    }
    
    .cancel-btn{
        height:42px;
        min-width:110px;
        border:none;
        border-radius:8px;
        background:linear-gradient(180deg,#59627f 0%, #4b5570 100%);
        color:#fff;
        font-weight:600;
    }
    
    .save-btn{
        height:42px;
        min-width:140px;
        border:none;
        border-radius:8px;
        background:linear-gradient(180deg,#f0ae19 0%, #d89505 100%);
        color:#fff;
        font-weight:700;
    }
    
    /* Tom Select */
    .ts-wrapper{font-size:14px}
    .ts-control{
        background:#e9eaf3 !important;
        border:1px solid rgba(255,255,255,.10) !important;
        border-radius:10px !important;
        min-height:52px !important;
        padding:8px 12px !important;
        box-shadow:none !important;
        display:flex !important;
        flex-wrap:wrap !important;
        align-items:center !important;
    }
    .ts-control input{color:#222 !important}
    .ts-control .item{
        background:#dfe5f2 !important;
        color:#222 !important;
        border:1px solid #cfd6e4 !important;
        border-radius:999px !important;
        padding:5px 10px !important;
        margin:3px !important;
        font-size:13px !important;
    }
    .ts-wrapper.multi .ts-control > div{
        background:#dfe5f2 !important;
        color:#222 !important;
        border:1px solid #cfd6e4 !important;
        border-radius:999px !important;
        padding:5px 10px !important;
    }
    .ts-dropdown{
        background:#0f1834 !important;
        border:1px solid rgba(255,255,255,.12) !important;
        color:#fff !important;
        border-radius:12px !important;
        margin-top:6px !important;
        overflow:hidden !important;
        z-index:99999 !important;
    }
    .ts-dropdown .ts-dropdown-content{
        max-height:260px !important;
        overflow-y:auto !important;
        overflow-x:hidden !important;
    }
    .ts-dropdown .ts-dropdown-content::-webkit-scrollbar{width:10px}
    .ts-dropdown .ts-dropdown-content::-webkit-scrollbar-track{background:rgba(255,255,255,.06);border-radius:10px}
    .ts-dropdown .ts-dropdown-content::-webkit-scrollbar-thumb{background:rgba(255,255,255,.22);border-radius:10px}
    .ts-dropdown .ts-dropdown-content::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,.35)}
    .ts-dropdown .option{
        color:#fff !important;
        padding:12px 14px !important;
        font-size:14px !important;
        line-height:1.4 !important;
        white-space:normal !important;
        border-bottom:1px solid rgba(255,255,255,.05);
    }
    .ts-dropdown .option:last-child{border-bottom:none}
    .ts-dropdown .option.active{background:rgba(255,255,255,.08) !important}
    .ts-dropdown .option.selected{background:rgba(255,210,95,.14) !important;color:#ffd25f !important}
    .ts-control .remove{color:#222 !important;margin-left:6px !important}
    
    /* FINAL MODAL SCROLL FIX */
    #staffModal{
        overflow-y:auto !important;
        overscroll-behavior:contain;
    }
    
    #staffModal .modal-dialog{
        margin:1.75rem auto;
        max-height:calc(100vh - 3.5rem);
        display:flex;
        align-items:center;
    }
    
    #staffModal .staff-form-side::-webkit-scrollbar{
        width:8px;
    }
    
    #staffModal .staff-form-side::-webkit-scrollbar-track{
        background:rgba(255,255,255,.06);
        border-radius:10px;
    }
    
    #staffModal .staff-form-side::-webkit-scrollbar-thumb{
        background:rgba(255,255,255,.22);
        border-radius:10px;
    }
    
    #staffModal .staff-form-side::-webkit-scrollbar-thumb:hover{
        background:rgba(255,255,255,.35);
    }
    
    body.modal-open{
        overflow:hidden !important;
        padding-right:0 !important;
    }
    
    @media(max-width:1200px){
        .stats-grid{grid-template-columns:repeat(2,1fr)}
    }
    @media(max-width:768px){
        .stats-grid{grid-template-columns:1fr}
        .page-header{flex-direction:column;align-items:stretch}
        .modal-footer-custom{justify-content:flex-end}
        .staff-footer-note{width:100%}
        .staff-footer-btns{width:100%;flex-direction:column}
        .cancel-btn,.save-btn{width:100%}
    }
    
/* =====================================================
   STAFF.PHP - DARK PURPLE BLACK GLASS THEME
   Paste at VERY END of <style> before
   Backend / PHP / JS logic untouched
===================================================== */

html, body{
    min-height:100% !important;
}

:root{
    --sidebar-open-width:270px;
    --sidebar-close-width:76px;

    --oms-bg:#070b14;
    --oms-panel:#0f172a;
    --oms-text:#f8fafc;
    --oms-muted:#94a3b8;

    --oms-purple:#6d4cff;
    --oms-purple-2:#7c3aed;
    --oms-blue:#3b82f6;
    --oms-green:#22c55e;
    --oms-yellow:#f59e0b;
    --oms-red:#ef4444;
    --oms-cyan:#06b6d4;

    --oms-border:rgba(148,163,184,.16);
}

/* ================= BASE ================= */
body{
    margin:0 !important;
    font-family:"Inter","Segoe UI",Tahoma,Geneva,Verdana,sans-serif !important;
    color:var(--oms-text) !important;
    background:
        radial-gradient(circle at 20% 0%, rgba(109,76,255,.16), transparent 28%),
        radial-gradient(circle at 80% 10%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg, #070b14 0%, #0a1020 55%, #070b14 100%) !important;
    overflow-x:hidden !important;
}

body{
    --current-sidebar-width:var(--sidebar-open-width);
}

body.dashboard-sidebar-collapsed{
    --current-sidebar-width:var(--sidebar-close-width);
}

/* ================= LAYOUT ================= */
.container-scroll{
    display:flex !important;
    min-height:100vh !important;
    align-items:stretch !important;
    width:100% !important;
}

.sidebar{
    width:var(--current-sidebar-width) !important;
    min-width:var(--current-sidebar-width) !important;
    max-width:var(--current-sidebar-width) !important;
    flex-shrink:0 !important;
    display:flex !important;
    align-self:stretch !important;
    background:transparent !important;
    overflow:visible !important;
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    height:100vh !important;
    z-index:1000 !important;
    transition:width .25s ease, min-width .25s ease, max-width .25s ease !important;
}

.sidebar > div{
    width:100% !important;
    min-height:100vh !important;
    height:100vh !important;
}

.sidebar .sidebar-wrap,
#dashboardSidebar .sidebar-wrap,
#sidebar .sidebar-wrap{
    width:var(--current-sidebar-width) !important;
    min-width:var(--current-sidebar-width) !important;
    max-width:var(--current-sidebar-width) !important;
    height:100vh !important;
    min-height:100vh !important;
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    z-index:1000 !important;
    overflow:hidden !important;
    border-radius:0 !important;
    transition:width .25s ease, min-width .25s ease, max-width .25s ease !important;
}

.sidebar .sidebar-inner,
#dashboardSidebar .sidebar-inner,
#sidebar .sidebar-inner{
    width:100% !important;
    height:100vh !important;
    min-height:100vh !important;
}

.sidebar .sidebar-menu,
#dashboardSidebar .sidebar-menu,
#sidebar .sidebar-menu{
    overflow-y:auto !important;
    overflow-x:hidden !important;
}

.main-panel{
    margin-left:var(--current-sidebar-width) !important;
    flex:1 !important;
    min-width:0 !important;
    min-height:100vh !important;
    display:flex !important;
    flex-direction:column !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(109,76,255,.16), transparent 26%),
        radial-gradient(circle at 90% 12%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg, #070b14 0%, #0a1020 60%, #070b14 100%) !important;
    transition:margin-left .25s ease !important;
}

.topbar{
    min-height:70px !important;
    height:70px !important;
    background:#070b14 !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    display:flex !important;
    align-items:center !important;
    padding:0 !important;
    position:sticky !important;
    top:0 !important;
    z-index:1030 !important;
    box-shadow:0 10px 30px rgba(0,0,0,.25) !important;
}

.content-wrapper{
    position:relative !important;
    padding:24px 26px !important;
    flex:1 !important;
    min-height:calc(100vh - 70px) !important;
    overflow:visible !important;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.34), transparent 28%),
        radial-gradient(circle at 78% 2%, rgba(59,130,246,.16), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(245,158,11,.10), transparent 26%),
        linear-gradient(135deg, #050914 0%, #0b1024 38%, #160f2d 68%, #090d18 100%) !important;
}

.content-wrapper::before{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    z-index:0;
    background:
        linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px),
        linear-gradient(180deg, rgba(255,255,255,.03) 1px, transparent 1px);
    background-size:44px 44px;
    mask-image:radial-gradient(circle at 40% 20%, black 0%, transparent 70%);
    opacity:.42;
}

.content-wrapper::after{
    content:"";
    position:absolute;
    width:520px;
    height:520px;
    right:-190px;
    top:-210px;
    pointer-events:none;
    z-index:0;
    background:radial-gradient(circle, rgba(109,76,255,.30), transparent 68%);
    filter:blur(8px);
    opacity:.85;
}

.content-wrapper > *{
    position:relative !important;
    z-index:1 !important;
}

/* ================= PAGE HEADER ================= */
.page-header{
    display:flex !important;
    justify-content:space-between !important;
    align-items:center !important;
    gap:14px !important;
    flex-wrap:wrap !important;
    margin-bottom:18px !important;
}

.page-title{
    font-size:30px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    margin:0 !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.30) !important;
}

.btn-gold,
.btn-remove-staff{
    min-height:50px !important;
    border:none !important;
    border-radius:14px !important;
    color:#fff !important;
    font-weight:900 !important;
    padding:12px 18px !important;
    transition:.22s ease !important;
}

.btn-gold{
    background:linear-gradient(135deg,#047857,#22c55e) !important;
    box-shadow:
        0 16px 34px rgba(34,197,94,.22),
        0 0 28px rgba(34,197,94,.12) !important;
}

.btn-remove-staff{
    background:linear-gradient(135deg,#be123c,#ef4444) !important;
    box-shadow:
        0 16px 34px rgba(239,68,68,.22),
        0 0 28px rgba(239,68,68,.12) !important;
}

.btn-gold:hover,
.btn-remove-staff:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

/* ================= STAT CARDS ================= */
.stats-grid{
    display:grid !important;
    grid-template-columns:repeat(4,1fr) !important;
    gap:16px !important;
    margin-bottom:18px !important;
}

.stat-card{
    min-height:112px !important;
    color:#fff !important;
    border-radius:18px !important;
    padding:20px 22px !important;
    display:flex !important;
    justify-content:space-between !important;
    align-items:center !important;
    position:relative !important;
    overflow:hidden !important;
    border:1px solid rgba(255,255,255,.10) !important;
    box-shadow:0 18px 40px rgba(0,0,0,.28) !important;
    isolation:isolate !important;
    transition:transform .25s ease, box-shadow .25s ease, border-color .25s ease, filter .25s ease !important;
}

.stat-card::before{
    content:"";
    position:absolute;
    inset:0;
    background:
        linear-gradient(135deg, rgba(255,255,255,.10), transparent 42%),
        radial-gradient(circle at 86% 10%, rgba(255,255,255,.16), transparent 28%);
    z-index:-1;
}

.stat-card::after{
    content:"";
    position:absolute;
    right:14px;
    top:14px;
    width:76px;
    height:76px;
    border-radius:20px;
    background:rgba(255,255,255,.10);
    z-index:-1;
}

.stat-card:hover{
    transform:translateY(-4px) !important;
    border-color:rgba(255,255,255,.22) !important;
    filter:saturate(1.08) !important;
    box-shadow:
        0 26px 65px rgba(0,0,0,.46),
        0 0 48px rgba(124,58,237,.18) !important;
}

.stat-title{
    font-size:15px !important;
    font-weight:900 !important;
    color:rgba(255,255,255,.94) !important;
}

.stat-value{
    position:absolute !important;
    right:18px !important;
    bottom:14px !important;
    font-size:30px !important;
    font-weight:900 !important;
    color:#fff !important;
}

.stat-blue{ background:linear-gradient(135deg,#2563eb,#6d4cff) !important; }
.stat-green{ background:linear-gradient(135deg,#047857,#16a34a) !important; }
.stat-cyan{ background:linear-gradient(135deg,#0891b2,#06b6d4) !important; }
.stat-gold{ background:linear-gradient(135deg,#8e7f73,#b9a38b) !important; }

/* ================= TOOLBAR GLASS ================= */
.toolbar-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.18), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.70), rgba(17,24,39,.54)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:18px !important;
    padding:14px 16px !important;
    margin-bottom:18px !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.30),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
}

.toolbar-row{
    display:grid !important;
    grid-template-columns:1fr 120px 120px !important;
    gap:10px !important;
    align-items:center !important;
}

.toolbar-input,
.toolbar-btn{
    height:46px !important;
    width:100% !important;
    border:1px solid rgba(148,163,184,.20) !important;
    border-radius:13px !important;
    background:rgba(15,23,42,.76) !important;
    color:#e5e7eb !important;
    padding:0 14px !important;
    outline:none !important;
    font-weight:800 !important;
    box-shadow:
        0 8px 22px rgba(0,0,0,.18),
        inset 0 1px 0 rgba(255,255,255,.04) !important;
    text-decoration:none !important;
}

.toolbar-input::placeholder{
    color:#94a3b8 !important;
}

.toolbar-input:focus{
    background:rgba(17,24,39,.92) !important;
    color:#fff !important;
    border-color:rgba(124,58,237,.62) !important;
    box-shadow:
        0 0 0 3px rgba(124,58,237,.16),
        0 12px 26px rgba(0,0,0,.24) !important;
}

.toolbar-btn{
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:6px !important;
    transition:.22s ease !important;
}

.filter-btn{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    border:none !important;
}

.toolbar-row a.toolbar-btn{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

.toolbar-btn:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
    border-color:rgba(139,92,246,.45) !important;
    box-shadow:
        0 18px 40px rgba(0,0,0,.36),
        0 0 34px rgba(124,58,237,.18) !important;
}

/* ================= TABLE GLASS ================= */
.table-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.72), rgba(17,24,39,.56)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.30),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
    transition:.25s ease !important;
}

.table-card:hover{
    border-color:rgba(139,92,246,.45) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 0 1px rgba(124,58,237,.16),
        0 0 42px rgba(124,58,237,.18),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.table-wrap{
    overflow:auto !important;
}

.erp-table{
    width:100% !important;
    min-width:1100px !important;
    border-collapse:collapse !important;
    background:transparent !important;
}

.erp-table thead th{
    background:rgba(7,11,20,.76) !important;
    color:#cbd5e1 !important;
    font-weight:900 !important;
    font-size:13px !important;
    padding:15px 14px !important;
    border-right:1px solid rgba(148,163,184,.08) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    white-space:nowrap !important;
    text-transform:uppercase !important;
    letter-spacing:.04em !important;
}

.erp-table tbody td{
    padding:15px 14px !important;
    border-top:1px solid rgba(148,163,184,.10) !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
    vertical-align:middle !important;
    font-size:14px !important;
    color:#e5e7eb !important;
    font-weight:700 !important;
    background:rgba(15,23,42,.30) !important;
}

.erp-table tbody tr:hover td{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.18), transparent 38%),
        linear-gradient(135deg, rgba(30,20,58,.42), rgba(15,23,42,.34)) !important;
    color:#fff !important;
    box-shadow:none !important;
}

.erp-table input[type="checkbox"]{
    width:16px !important;
    height:16px !important;
    accent-color:#7c3aed !important;
}

.name-cell{
    color:#f8fafc !important;
    font-weight:900 !important;
}

.sub-line{
    display:block !important;
    color:#94a3b8 !important;
    font-size:12px !important;
    margin-top:4px !important;
    font-weight:700 !important;
}

.permission-badges{
    display:flex !important;
    gap:6px !important;
    flex-wrap:wrap !important;
}

.perm-badge{
    background:rgba(124,58,237,.18) !important;
    color:#c4b5fd !important;
    border:1px solid rgba(124,58,237,.28) !important;
    border-radius:999px !important;
    padding:6px 10px !important;
    font-size:12px !important;
    font-weight:900 !important;
}

/* ================= ACTION ICONS ================= */
.staff-actions{
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:10px !important;
}

.staff-action-btn{
    width:40px !important;
    height:40px !important;
    border:none !important;
    border-radius:14px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    padding:0 !important;
    cursor:pointer !important;
    transition:all .20s ease !important;
    box-shadow:0 12px 24px rgba(0,0,0,.18) !important;
}

.staff-action-btn:hover{
    transform:translateY(-2px) !important;
}

.staff-edit{
    background:rgba(245,158,11,.15) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.24) !important;
}

.staff-delete{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.24) !important;
}

.staff-edit:hover{
    background:rgba(245,158,11,.28) !important;
    color:#fff !important;
}

.staff-delete:hover{
    background:rgba(239,68,68,.28) !important;
    color:#fff !important;
}

/* ================= STAFF MODAL PURPLE GLASS ================= */
body.modal-open .modal-backdrop.show,
.modal-backdrop.show{
    opacity:.52 !important;
    background:#000 !important;
}

#staffModal .modal-dialog{
    max-width:820px !important;
    width:820px !important;
    margin:18px auto !important;
    max-height:calc(100vh - 36px) !important;
    display:flex !important;
    align-items:center !important;
}

#staffModal .staff-modal-content,
#staffModal .modal-content{
    max-height:82vh !important;
    overflow:hidden !important;
    display:flex !important;
    flex-direction:column !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.24), transparent 38%),
        linear-gradient(135deg, rgba(15,23,42,.88), rgba(17,24,39,.72)) !important;
    backdrop-filter:blur(22px) saturate(145%) !important;
    -webkit-backdrop-filter:blur(22px) saturate(145%) !important;
    color:#f8fafc !important;
    border:1px solid rgba(139,92,246,.32) !important;
    border-radius:18px !important;
    box-shadow:
        0 24px 70px rgba(0,0,0,.58),
        0 0 45px rgba(124,58,237,.16),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.staff-modal-header{
    min-height:56px !important;
    padding:12px 18px !important;
    background:rgba(7,11,20,.30) !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
}

.staff-modal-title{
    color:#f8fafc !important;
    font-size:18px !important;
    font-weight:900 !important;
    margin:0 !important;
}

.staff-close{
    width:36px !important;
    height:36px !important;
    border-radius:10px !important;
    background:rgba(148,163,184,.16) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    color:#e5e7eb !important;
    font-size:24px !important;
    line-height:1 !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    opacity:1 !important;
}

.staff-close:hover{
    background:rgba(239,68,68,.24) !important;
}

.staff-form-side{
    padding:14px 18px !important;
    max-height:62vh !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    background:rgba(15,23,42,.22) !important;
}

#staffModal .row{
    --bs-gutter-x:14px !important;
    --bs-gutter-y:10px !important;
}

.dark-label,
#staffModal label{
    color:#e5e7eb !important;
    font-size:12px !important;
    font-weight:900 !important;
    margin-bottom:5px !important;
}

.req{
    color:#fbbf24 !important;
}

.dark-input,
.dark-textarea,
#staffModal input,
#staffModal textarea{
    background:rgba(255,255,255,.92) !important;
    color:#111827 !important;
    border:1px solid rgba(203,213,225,.82) !important;
    border-radius:10px !important;
    font-size:13px !important;
    font-weight:700 !important;
    box-shadow:0 8px 18px rgba(0,0,0,.13) !important;
}

.dark-input,
#staffModal input{
    height:38px !important;
    min-height:38px !important;
    padding:5px 10px !important;
}

.dark-textarea,
#staffModal textarea{
    min-height:60px !important;
    height:60px !important;
    padding:8px 10px !important;
    resize:vertical !important;
}

.dark-input:focus,
.dark-textarea:focus,
#staffModal input:focus,
#staffModal textarea:focus{
    border-color:#7c3aed !important;
    box-shadow:
        0 0 0 3px rgba(124,58,237,.20),
        0 8px 18px rgba(0,0,0,.18) !important;
    outline:none !important;
}

.password-strength-wrap,
.permissions-box{
    background:
        radial-gradient(circle at 0% 0%, rgba(124,58,237,.18), transparent 38%),
        linear-gradient(135deg, rgba(15,23,42,.62), rgba(17,24,39,.44)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:14px !important;
    box-shadow:
        0 12px 28px rgba(0,0,0,.24),
        inset 0 1px 0 rgba(255,255,255,.04) !important;
}

.password-strength-wrap{
    padding:12px !important;
}

.strength-bar{
    height:9px !important;
    border-radius:999px !important;
    background:rgba(255,255,255,.10) !important;
    overflow:hidden !important;
}

.strength-fill{
    height:100% !important;
    border-radius:999px !important;
    background:linear-gradient(135deg,#f59e0b,#22c55e) !important;
}

.strength-text{
    color:#cbd5e1 !important;
    font-size:12px !important;
    font-weight:800 !important;
}

.permissions-head{
    padding:12px 14px !important;
    background:rgba(7,11,20,.30) !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    color:#f8fafc !important;
    font-size:15px !important;
    font-weight:900 !important;
}

.permissions-body{
    padding:12px 14px !important;
}

/* Tom Select dark glass */
#staffModal .ts-wrapper{
    font-size:13px !important;
}

#staffModal .ts-control{
    min-height:40px !important;
    padding:5px 10px !important;
    background:rgba(255,255,255,.92) !important;
    color:#111827 !important;
    border:1px solid rgba(203,213,225,.82) !important;
    border-radius:10px !important;
    box-shadow:0 8px 18px rgba(0,0,0,.13) !important;
}

#staffModal .ts-control input{
    color:#111827 !important;
    font-weight:700 !important;
}

#staffModal .ts-control .item,
#staffModal .ts-wrapper.multi .ts-control > div{
    background:rgba(124,58,237,.16) !important;
    color:#4c1d95 !important;
    border:1px solid rgba(124,58,237,.24) !important;
    border-radius:999px !important;
    padding:5px 10px !important;
    margin:3px !important;
    font-size:12px !important;
    font-weight:900 !important;
}

.ts-dropdown{
    background:#0f172a !important;
    border:1px solid rgba(139,92,246,.32) !important;
    color:#fff !important;
    border-radius:12px !important;
    margin-top:6px !important;
    overflow:hidden !important;
    z-index:99999 !important;
    box-shadow:0 18px 48px rgba(0,0,0,.45) !important;
}

.ts-dropdown .option{
    color:#e5e7eb !important;
    padding:11px 14px !important;
    font-size:13px !important;
    font-weight:700 !important;
    line-height:1.4 !important;
    white-space:normal !important;
    border-bottom:1px solid rgba(255,255,255,.05) !important;
}

.ts-dropdown .option.active{
    background:rgba(124,58,237,.24) !important;
    color:#fff !important;
}

.ts-dropdown .option.selected{
    background:rgba(34,197,94,.16) !important;
    color:#86efac !important;
}

.modal-footer-custom{
    min-height:54px !important;
    padding:10px 18px !important;
    background:rgba(7,11,20,.30) !important;
    border-top:1px solid rgba(148,163,184,.14) !important;
}

.staff-footer-note{
    color:#cbd5e1 !important;
    font-size:13px !important;
    font-weight:800 !important;
}

.cancel-btn,
.save-btn{
    height:38px !important;
    min-width:105px !important;
    border:none !important;
    border-radius:10px !important;
    font-size:13px !important;
    font-weight:900 !important;
    transition:.22s ease !important;
}

.cancel-btn{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

.save-btn{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    box-shadow:0 12px 24px rgba(124,58,237,.24) !important;
}

.cancel-btn:hover,
.save-btn:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

#staffModal .staff-form-side::-webkit-scrollbar,
.ts-dropdown .ts-dropdown-content::-webkit-scrollbar{
    width:8px !important;
}

#staffModal .staff-form-side::-webkit-scrollbar-track,
.ts-dropdown .ts-dropdown-content::-webkit-scrollbar-track{
    background:rgba(255,255,255,.06) !important;
    border-radius:10px !important;
}

#staffModal .staff-form-side::-webkit-scrollbar-thumb,
.ts-dropdown .ts-dropdown-content::-webkit-scrollbar-thumb{
    background:rgba(139,92,246,.38) !important;
    border-radius:10px !important;
}

body.modal-open{
    overflow:hidden !important;
    padding-right:0 !important;
}

/* ================= RESPONSIVE ================= */
@media(max-width:1200px){
    .stats-grid{
        grid-template-columns:repeat(2,1fr) !important;
    }
}

@media(max-width:991px){
    .container-scroll{
        flex-direction:column !important;
    }

    .sidebar{
        width:100% !important;
        min-width:100% !important;
        max-width:100% !important;
        position:relative !important;
        height:auto !important;
    }

    .sidebar .sidebar-wrap,
    #dashboardSidebar .sidebar-wrap,
    #sidebar .sidebar-wrap{
        position:relative !important;
        width:100% !important;
        min-width:100% !important;
        max-width:100% !important;
        height:auto !important;
        min-height:auto !important;
    }

    .main-panel{
        margin-left:0 !important;
    }

    .topbar{
        position:relative !important;
    }

    .content-wrapper{
        padding:18px !important;
    }

    #staffModal .modal-dialog{
        width:calc(100% - 24px) !important;
        max-width:calc(100% - 24px) !important;
        margin:12px auto !important;
    }
}

@media(max-width:768px){
    .stats-grid{
        grid-template-columns:1fr !important;
    }

    .page-header{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .page-header > div{
        width:100% !important;
        flex-direction:column !important;
    }

    .btn-gold,
    .btn-remove-staff{
        width:100% !important;
    }

    .toolbar-row{
        grid-template-columns:1fr !important;
    }

    .toolbar-input,
    .toolbar-btn{
        width:100% !important;
    }

    .modal-footer-custom{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .staff-footer-btns{
        width:100% !important;
        flex-direction:column !important;
    }

    .cancel-btn,
    .save-btn{
        width:100% !important;
    }
}

/* =========================================================
   STAFF / USERS PAGE - FINAL LIGHT MODE
   Must remain at the very end before 
========================================================= */

html[data-pos-theme="light"]{
    color-scheme:light;
}

/* =========================================================
   PAGE AND LAYOUT
========================================================= */

html[data-pos-theme="light"] body{
    background:#f3f6fb !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .container-scroll{
    background:#f3f6fb !important;
}

html[data-pos-theme="light"] body .main-panel{
    background:
        linear-gradient(
            135deg,
            #f8fafc 0%,
            #f1f5f9 52%,
            #faf7ff 100%
        ) !important;
}

html[data-pos-theme="light"] body .topbar{
    background:#ffffff !important;
    color:#0f172a !important;
    border-bottom:1px solid #dce4ef !important;
    box-shadow:0 5px 18px rgba(15,23,42,.06) !important;
}

html[data-pos-theme="light"] body .content-wrapper{
    background:
        radial-gradient(
            circle at 12% 8%,
            rgba(124,58,237,.08),
            transparent 28%
        ),
        radial-gradient(
            circle at 80% 4%,
            rgba(59,130,246,.05),
            transparent 30%
        ),
        linear-gradient(
            135deg,
            #f8fafc 0%,
            #f1f5f9 48%,
            #faf7ff 76%,
            #f8fafc 100%
        ) !important;

    color:#0f172a !important;
}

html[data-pos-theme="light"] body .content-wrapper::before{
    background:
        linear-gradient(
            90deg,
            rgba(15,23,42,.03) 1px,
            transparent 1px
        ),
        linear-gradient(
            180deg,
            rgba(15,23,42,.025) 1px,
            transparent 1px
        ) !important;

    opacity:.5 !important;
}

html[data-pos-theme="light"] body .content-wrapper::after{
    background:
        radial-gradient(
            circle,
            rgba(124,58,237,.08),
            transparent 68%
        ) !important;

    opacity:.42 !important;
}

/* =========================================================
   PAGE HEADER
========================================================= */

html[data-pos-theme="light"] body .page-title{
    color:#0f172a !important;
    text-shadow:none !important;
}

/* Add Staff */
html[data-pos-theme="light"] body .btn-gold{
    background:
        linear-gradient(
            135deg,
            #059669,
            #16a34a
        ) !important;

    color:#ffffff !important;

    box-shadow:
        0 12px 26px rgba(16,185,129,.20) !important;
}

/* Remove Staff */
html[data-pos-theme="light"] body .btn-remove-staff{
    background:
        linear-gradient(
            135deg,
            #e11d48,
            #ef4444
        ) !important;

    color:#ffffff !important;

    box-shadow:
        0 12px 26px rgba(239,68,68,.20) !important;
}

/* =========================================================
   STAT CARDS
========================================================= */

html[data-pos-theme="light"] body .stat-card{
    color:#ffffff !important;
    border-color:rgba(255,255,255,.25) !important;

    box-shadow:
        0 14px 32px rgba(15,23,42,.14) !important;
}

html[data-pos-theme="light"] body .stat-card .stat-title,
html[data-pos-theme="light"] body .stat-card .stat-value{
    color:#ffffff !important;
    opacity:1 !important;
}

/* =========================================================
   SEARCH TOOLBAR
========================================================= */

html[data-pos-theme="light"] body .toolbar-card{
    background:#ffffff !important;
    background-image:none !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 12px 28px rgba(15,23,42,.07) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .toolbar-input{
    background:#ffffff !important;
    color:#0f172a !important;

    border:1px solid #cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body .toolbar-input::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body .toolbar-input:focus{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#7c3aed !important;

    box-shadow:
        0 0 0 3px rgba(124,58,237,.13) !important;
}

/* Filter */
html[data-pos-theme="light"] body .toolbar-card .filter-btn{
    background:
        linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        ) !important;

    color:#ffffff !important;
    border-color:#7c3aed !important;
}

/* Reset */
html[data-pos-theme="light"] body .toolbar-row a.toolbar-btn{
    background:#ffffff !important;
    color:#334155 !important;

    border:1px solid #cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body .toolbar-row a.toolbar-btn:hover{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;
}

/* =========================================================
   TABLE CARD
========================================================= */

html[data-pos-theme="light"] body .table-card{
    background:#ffffff !important;
    background-image:none !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 12px 32px rgba(15,23,42,.07) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] body .table-card:hover{
    border-color:#c4b5fd !important;

    box-shadow:
        0 16px 36px rgba(15,23,42,.09) !important;
}

/* Bootstrap table variables reset */
html[data-pos-theme="light"] body .erp-table,
html[data-pos-theme="light"] body .erp-table thead,
html[data-pos-theme="light"] body .erp-table tbody,
html[data-pos-theme="light"] body .erp-table tr,
html[data-pos-theme="light"] body .erp-table th,
html[data-pos-theme="light"] body .erp-table td{
    --bs-table-bg:transparent !important;
    --bs-table-color:#1e293b !important;
    --bs-table-hover-bg:#f6f2ff !important;
    --bs-table-hover-color:#0f172a !important;
    --bs-table-accent-bg:transparent !important;
}

/* Table heading */
html[data-pos-theme="light"] body .erp-table thead th{
    background:#f1f5f9 !important;
    background-color:#f1f5f9 !important;

    color:#334155 !important;

    border-right:1px solid #e2e8f0 !important;
    border-bottom:1px solid #cbd5e1 !important;

    box-shadow:none !important;
}

/* Table rows */
html[data-pos-theme="light"] body .erp-table tbody tr,
html[data-pos-theme="light"] body .erp-table tbody tr td{
    background:#ffffff !important;
    background-color:#ffffff !important;
    background-image:none !important;

    color:#1e293b !important;

    border-top-color:#e8edf4 !important;
    border-bottom-color:#e8edf4 !important;

    box-shadow:none !important;
}

/* Row hover */
html[data-pos-theme="light"] body .erp-table tbody tr:hover td{
    background:#f6f2ff !important;
    background-color:#f6f2ff !important;
    background-image:none !important;

    color:#0f172a !important;
    box-shadow:none !important;
}

/* Empty row */
html[data-pos-theme="light"] body .erp-table td[colspan]{
    background:#ffffff !important;
    color:#64748b !important;
}

/* Staff name */
html[data-pos-theme="light"] body .name-cell{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body .sub-line{
    color:#64748b !important;
}

/* Checkbox */
html[data-pos-theme="light"] body .erp-table input[type="checkbox"]{
    accent-color:#7c3aed !important;
}

/* =========================================================
   PERMISSION BADGES
========================================================= */

html[data-pos-theme="light"] body .perm-badge{
    background:#f3efff !important;
    color:#5b21b6 !important;

    border:1px solid #c4b5fd !important;

    box-shadow:none !important;
}

/* =========================================================
   TABLE ACTION BUTTONS
========================================================= */

html[data-pos-theme="light"] body .staff-action-btn{
    box-shadow:
        0 5px 12px rgba(15,23,42,.07) !important;
}

/* Edit */
html[data-pos-theme="light"] body .staff-edit{
    background:#fffbeb !important;
    color:#d97706 !important;
    border-color:#fde68a !important;
}

html[data-pos-theme="light"] body .staff-edit:hover{
    background:#f59e0b !important;
    color:#ffffff !important;
}

/* Delete */
html[data-pos-theme="light"] body .staff-delete{
    background:#fff1f2 !important;
    color:#e11d48 !important;
    border-color:#fecdd3 !important;
}

html[data-pos-theme="light"] body .staff-delete:hover{
    background:#ef4444 !important;
    color:#ffffff !important;
}

/* =========================================================
   ADD / EDIT STAFF MODAL
========================================================= */

html[data-pos-theme="light"] body
#staffModal .staff-modal-content,

html[data-pos-theme="light"] body
#staffModal .modal-content{
    background:#ffffff !important;
    background-image:none !important;

    color:#0f172a !important;

    border:1px solid #d8e0eb !important;

    box-shadow:
        0 28px 80px rgba(15,23,42,.20) !important;

    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

/* Modal header */
html[data-pos-theme="light"] body
#staffModal .staff-modal-header{
    background:#f8fafc !important;
    border-bottom:1px solid #e2e8f0 !important;
}

html[data-pos-theme="light"] body
#staffModal .staff-modal-title{
    color:#0f172a !important;
}

/* Close button */
html[data-pos-theme="light"] body
#staffModal .staff-close{
    background:#ffffff !important;
    color:#334155 !important;

    border:1px solid #cbd5e1 !important;
}

html[data-pos-theme="light"] body
#staffModal .staff-close:hover{
    background:#fff1f2 !important;
    color:#e11d48 !important;
    border-color:#fecdd3 !important;
}

/* Modal body */
html[data-pos-theme="light"] body
#staffModal .staff-form-side{
    background:#ffffff !important;
    color:#0f172a !important;
}

/* Labels */
html[data-pos-theme="light"] body
#staffModal .dark-label,

html[data-pos-theme="light"] body
#staffModal label{
    color:#334155 !important;
}

html[data-pos-theme="light"] body
#staffModal .req{
    color:#e11d48 !important;
}

/* Inputs */
html[data-pos-theme="light"] body
#staffModal .dark-input,

html[data-pos-theme="light"] body
#staffModal .dark-textarea,

html[data-pos-theme="light"] body
#staffModal input,

html[data-pos-theme="light"] body
#staffModal textarea{
    background:#ffffff !important;
    color:#0f172a !important;

    border:1px solid #cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body
#staffModal input::placeholder,

html[data-pos-theme="light"] body
#staffModal textarea::placeholder{
    color:#64748b !important;
    opacity:1 !important;
}

html[data-pos-theme="light"] body
#staffModal .dark-input:focus,

html[data-pos-theme="light"] body
#staffModal .dark-textarea:focus,

html[data-pos-theme="light"] body
#staffModal input:focus,

html[data-pos-theme="light"] body
#staffModal textarea:focus{
    background:#ffffff !important;
    color:#0f172a !important;

    border-color:#7c3aed !important;

    box-shadow:
        0 0 0 3px rgba(124,58,237,.13) !important;
}

/* =========================================================
   PASSWORD STRENGTH AND PERMISSIONS BOX
========================================================= */

html[data-pos-theme="light"] body
#staffModal .password-strength-wrap,

html[data-pos-theme="light"] body
#staffModal .permissions-box{
    background:#f8fafc !important;
    background-image:none !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 6px 18px rgba(15,23,42,.05) !important;
}

html[data-pos-theme="light"] body
#staffModal .strength-bar{
    background:#e2e8f0 !important;
}

html[data-pos-theme="light"] body
#staffModal .strength-text{
    color:#64748b !important;
}

html[data-pos-theme="light"] body
#staffModal .permissions-head{
    background:#f1f5f9 !important;
    color:#0f172a !important;

    border-bottom:1px solid #dce4ef !important;
}

html[data-pos-theme="light"] body
#staffModal .permissions-body{
    background:#ffffff !important;
}

/* =========================================================
   TOM SELECT - LIGHT MODE
========================================================= */

html[data-pos-theme="light"] body
#staffModal .ts-control{
    background:#ffffff !important;
    color:#0f172a !important;

    border:1px solid #cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] body
#staffModal .ts-control input{
    color:#0f172a !important;
}

html[data-pos-theme="light"] body
#staffModal .ts-control .item,

html[data-pos-theme="light"] body
#staffModal .ts-wrapper.multi .ts-control > div{
    background:#f3efff !important;
    color:#5b21b6 !important;

    border:1px solid #c4b5fd !important;
}

html[data-pos-theme="light"] body
#staffModal .ts-control .remove{
    color:#5b21b6 !important;
}

/* Dropdown body mein attach hota hai, isliye body selector */
html[data-pos-theme="light"] body .ts-dropdown{
    background:#ffffff !important;
    color:#0f172a !important;

    border:1px solid #dce4ef !important;

    box-shadow:
        0 18px 42px rgba(15,23,42,.16) !important;
}

html[data-pos-theme="light"] body
.ts-dropdown .option{
    background:#ffffff !important;
    color:#334155 !important;

    border-bottom-color:#e2e8f0 !important;
}

html[data-pos-theme="light"] body
.ts-dropdown .option.active{
    background:#f3efff !important;
    color:#5b21b6 !important;
}

html[data-pos-theme="light"] body
.ts-dropdown .option.selected{
    background:#ecfdf5 !important;
    color:#047857 !important;
}

/* =========================================================
   MODAL FOOTER
========================================================= */

html[data-pos-theme="light"] body
#staffModal .modal-footer-custom{
    background:#f8fafc !important;
    border-top:1px solid #e2e8f0 !important;
}

html[data-pos-theme="light"] body
#staffModal .staff-footer-note{
    color:#64748b !important;
}

/* Cancel */
html[data-pos-theme="light"] body
#staffModal .cancel-btn{
    background:#e2e8f0 !important;
    color:#334155 !important;

    border:1px solid #cbd5e1 !important;
}

/* Save */
html[data-pos-theme="light"] body
#staffModal .save-btn{
    background:
        linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        ) !important;

    color:#ffffff !important;

    box-shadow:
        0 10px 22px rgba(124,58,237,.20) !important;
}

/* =========================================================
   MODAL AND DROPDOWN SCROLLBARS
========================================================= */

html[data-pos-theme="light"] body
#staffModal .staff-form-side::-webkit-scrollbar-track,

html[data-pos-theme="light"] body
.ts-dropdown .ts-dropdown-content::-webkit-scrollbar-track{
    background:#f1f5f9 !important;
}

html[data-pos-theme="light"] body
#staffModal .staff-form-side::-webkit-scrollbar-thumb,

html[data-pos-theme="light"] body
.ts-dropdown .ts-dropdown-content::-webkit-scrollbar-thumb{
    background:#a78bfa !important;
}

/* Modal backdrop */
html[data-pos-theme="light"] body.modal-open
.modal-backdrop.show{
    background:#0f172a !important;
    opacity:.45 !important;
}

</style>
    </head>
    <body>
    <div class="container-scroll">
 <div class="sidebar" id="dashboardSidebar">
         <div class="sidebar" id="sidebar">
        <?php include('partials/sidebar.php'); ?>
    </div>
    </div>
        <div class="main-panel">
            <div class="topbar"><?php include('partials/topbar.php'); ?></div>
    
            <div class="content-wrapper">
                <div class="page-header">
    <h1 class="page-title">Staff / Users</h1>

    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <button type="button" class="btn-gold" onclick="openStaffModal()">
            <i class="bi bi-plus-lg me-1"></i> Add Staff
        </button>

        <button type="button" class="btn-remove-staff" onclick="removeSelectedStaff()">
            <i class="bi bi-trash me-1"></i> Remove Staff
        </button>
    </div>
</div>
    
                <div class="stats-grid">
                    <div class="stat-card stat-blue"><div class="stat-title">Total Staff</div><div class="stat-value"><?= $totalStaff ?></div></div>
                    <div class="stat-card stat-green"><div class="stat-title">Active Users</div><div class="stat-value"><?= $activeUsers ?></div></div>
                    <div class="stat-card stat-cyan"><div class="stat-title">Admins</div><div class="stat-value"><?= $admins ?></div></div>
                    <div class="stat-card stat-gold"><div class="stat-title">Managers</div><div class="stat-value"><?= $managers ?></div></div>
                </div>
    
                <div class="toolbar-card">
                    <form method="GET" class="toolbar-row">
                        <input type="text" class="toolbar-input" name="search" placeholder="Search staff..." value="<?= e($search) ?>">
                        <button type="submit" class="toolbar-btn filter-btn"><i class="bi bi-search me-1"></i> Filter</button>
                        <a href="staff.php" class="toolbar-btn"><i class="bi bi-arrow-clockwise me-1"></i> Reset</a>
                    </form>
                </div>
    
                <div class="table-card">
                    <div class="table-wrap">
                        <table class="erp-table" id="staffTable">
                            <thead>
                                <tr>
    <th style="width:50px;">
        <input type="checkbox" id="checkAllStaff">
    </th>
    <th>ID</th>
    <th>Staff</th>
    <th>Phone</th>
    <th>Permissions</th>
    <th>Actions</th>
</tr>
                            </thead>
                            <tbody>
                                <?php if (empty($staff)): ?>
                                   <tr><td colspan="6" class="text-center py-4">No staff found</td></tr>
                                <?php else: ?>
                                    <?php foreach ($staff as $s): ?>
                                        <?php $perms = $user_permissions_map[$s['id']] ?? []; ?>
                                       <tr data-id="<?= (int)$s['id'] ?>">
    <td>
        <input type="checkbox" class="staffRowCheck" value="<?= (int)$s['id'] ?>">
    </td>
    <td><?= (int)$s['id'] ?></td>
                                            <td>
                                                <span class="name-cell"><?= e($s['name']) ?></span>
                                                <span class="sub-line"><?= e($s['email']) ?></span>
                                            </td>
                                            <td><?= e($s['phone']) ?></td>
                                            <td>
                                                <div class="permission-badges">
                                                    <?php if (empty($perms)): ?>
                                                        <span class="perm-badge">No Permissions</span>
                                                    <?php else: ?>
                                                        <?php foreach (array_slice($perms, 0, 4) as $p): ?>
                                                            <span class="perm-badge"><?= e($feature_labels[$p] ?? $p) ?></span>
                                                        <?php endforeach; ?>
                                                        <?php if (count($perms) > 4): ?>
                                                            <span class="perm-badge">+<?= count($perms) - 4 ?> more</span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
    <div class="staff-actions">
        <button type="button"
            class="staff-action-btn staff-edit editStaff"
            data-id="<?= (int)$s['id'] ?>"
            data-name="<?= e($s['name']) ?>"
            data-email="<?= e($s['email']) ?>"
            data-phone="<?= e($s['phone']) ?>"
            data-permissions='<?= e(json_encode($perms)) ?>'
            title="Edit Staff">
            <i class="bi bi-pencil-fill"></i>
        </button>

        <button type="button"
            class="staff-action-btn staff-delete deleteStaff"
            data-id="<?= (int)$s['id'] ?>"
            title="Delete Staff">
            <i class="bi bi-trash-fill"></i>
        </button>
    </div>
</td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="staffModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content staff-modal-content">
                <form id="staffForm">
                    <input type="hidden" name="user_id" id="user_id">
    
                    <div class="staff-modal-header">
                        <h5 id="modalTitle" class="staff-modal-title">Add Staff</h5>
                        <button type="button" class="staff-close" data-bs-dismiss="modal">&times;</button>
                    </div>
    
                    <div class="staff-form-side">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="dark-label">Full Name <span class="req">*</span></label>
                                <input type="text" name="name" id="name" class="dark-input" required placeholder="Enter full name">
                            </div>
    
                            <div class="col-md-6">
                                <label class="dark-label">Email Address <span class="req">*</span></label>
                                <input type="email" name="email" id="email" class="dark-input" placeholder="example@email.com" required>
                            </div>
    
                            <div class="col-md-6">
                                <label class="dark-label">Phone Number <span class="req">*</span></label>
                                <input type="text" name="phone" id="phone" class="dark-input" placeholder="+92 3XX XXXXXXX" required>
                            </div>
    
                            <div class="col-md-6">
                                <label class="dark-label">Password <span class="req">*</span></label>
                                <input type="password" name="password" id="password" class="dark-input" placeholder="Enter password">
                            </div>
    
                            <div class="col-12">
                                <div class="password-strength-wrap">
                                    <div class="d-flex justify-content-between">
                                        <span class="dark-label mb-1">Password Strength</span>
                                        <span class="strength-text" id="strengthText">Weak</span>
                                    </div>
                                    <div class="strength-bar">
                                        <div class="strength-fill" id="strengthFill"></div>
                                    </div>
                                    <div class="strength-text">
                                        Use 8 characters or more. Combine upper & lower case letters, at least 1 number and 1 symbol.
                                    </div>
                                </div>
                            </div>
    
                            <div class="col-12">
                                <div class="permissions-box">
                                    <div class="permissions-head">
                                        <span><i class="bi bi-shield-check me-2"></i>Permissions <span class="req">*</span></span>
                                        <i class="bi bi-chevron-down"></i>
                                    </div>
    
                                    <div class="permissions-body">
                                        <label class="dark-label">Select Permissions</label>
                                        <select name="permissions[]" id="permissions_select" multiple placeholder="Select permissions...">
                                            <?php foreach ($feature_labels as $key => $label): ?>
                                                <option value="<?= e($key) ?>"><?= e($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
    
                    <div class="modal-footer-custom">
                        <div class="staff-footer-note">Create or update staff details and permissions.</div>
    
                        <div class="staff-footer-btns">
                            <button type="button" class="cancel-btn" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="save-btn" id="saveStaffBtn">Save Staff</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
    <script>
        
function handleAjaxSession(response) {
    if (response.status === 401) {
        window.location.href = '/onerp/app/public/app/views/login_view.php';
        return null;
    }

    return response.text();
}
    const modal = new bootstrap.Modal(document.getElementById('staffModal'));
    let permissionSelect = null;
    
    document.addEventListener('DOMContentLoaded', function () {
        permissionSelect = new TomSelect('#permissions_select', {
            plugins: ['remove_button'],
            create: false,
            persist: false,
            maxItems: null,
            hidePlaceholder: false,
            closeAfterSelect: false,
            dropdownParent: 'body',
            placeholder: 'Select permissions...',
            render: {
                option: function(data, escape) {
                    return '<div>' + escape(data.text) + '</div>';
                },
                item: function(data, escape) {
                    return '<div>' + escape(data.text) + '</div>';
                }
            }
        });
    });
    
    function updateStrengthMeter() {
        const val = document.getElementById('password').value;
        const fill = document.getElementById('strengthFill');
        const text = document.getElementById('strengthText');
    
        let score = 0;
        if (val.length >= 8) score++;
        if (/[A-Z]/.test(val) && /[a-z]/.test(val)) score++;
        if (/\d/.test(val)) score++;
        if (/[^A-Za-z0-9]/.test(val)) score++;
    
        if (score <= 1) {
            fill.style.width = '25%';
            fill.style.background = '#f0aa00';
            text.innerText = 'Weak';
        } else if (score === 2) {
            fill.style.width = '50%';
            fill.style.background = '#d8b441';
            text.innerText = 'Normal';
        } else if (score === 3) {
            fill.style.width = '75%';
            fill.style.background = '#6dbb5d';
            text.innerText = 'Good';
        } else {
            fill.style.width = '100%';
            fill.style.background = '#2fb36d';
            text.innerText = 'Strong';
        }
    }
    
    document.getElementById('password').addEventListener('input', updateStrengthMeter);
    
    function resetPermissionDropdown() {
        if (permissionSelect) {
            permissionSelect.clear(true);
        }
    }
    
    function openStaffModal() {
        document.getElementById('staffForm').reset();
        document.getElementById('user_id').value = '';
        resetPermissionDropdown();
        document.getElementById('modalTitle').innerText = 'Add Staff';
        document.getElementById('saveStaffBtn').innerText = 'Save Staff';
        updateStrengthMeter();
        modal.show();
    }
    
    document.querySelectorAll('.editStaff').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('staffForm').reset();
            resetPermissionDropdown();
    
            document.getElementById('user_id').value = this.dataset.id || '';
            document.getElementById('name').value = this.dataset.name || '';
            document.getElementById('email').value = this.dataset.email || '';
            document.getElementById('phone').value = this.dataset.phone || '';
            document.getElementById('password').value = '';
    
            let perms = [];
            try {
                perms = JSON.parse(this.dataset.permissions || '[]');
            } catch (e) {
                perms = [];
            }
    
            if (permissionSelect && perms.length) {
                permissionSelect.setValue(perms);
            }
    
            document.getElementById('modalTitle').innerText = 'Edit Staff';
            document.getElementById('saveStaffBtn').innerText = 'Update Staff';
            updateStrengthMeter();
            modal.show();
        });
    });
    
    document.querySelectorAll('.deleteStaff').forEach(btn => {
        btn.addEventListener('click', async function () {
            const userId = this.dataset.id;
            if (!confirm('Delete this user?')) return;
    
            try {
                const response = await fetch('ajax_staff.php', {
                    method:'POST',
                    headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body:'action=delete&user_id=' + encodeURIComponent(userId)
                });
    
                const resp = await response.text();
                if (resp.trim() === 'ok') {
                    document.querySelector('tr[data-id="' + userId + '"]')?.remove();
                } else {
                    alert('Error deleting user: ' + resp);
                }
            } catch (e) {
                alert('Delete request failed');
            }
        });
    });
    
    document.getElementById('staffForm').addEventListener('submit', async function (e) {
        e.preventDefault();
    
        const formData = new FormData(this);
        formData.append('action', 'save');
    
        const btn = document.getElementById('saveStaffBtn');
        const oldText = btn.innerText;
        btn.disabled = true;
        btn.innerText = 'Saving...';
    
        try {
            const response = await fetch('ajax_staff.php', {
                method:'POST',
                body: formData,
                credentials:'same-origin'
            });
    
            const raw = await response.text();
            let staff = null;
    
            try {
                staff = JSON.parse(raw);
            } catch (err) {
                alert('Invalid AJAX response: ' + raw);
                btn.disabled = false;
                btn.innerText = oldText;
                return;
            }
    
            if (staff && staff.id) {
                location.reload();
                return;
            }
    
            alert(staff.message || 'Error saving staff');
            btn.disabled = false;
            btn.innerText = oldText;
        } catch (e) {
            alert('Save request failed: ' + e.message);
            btn.disabled = false;
            btn.innerText = oldText;
        }
    });
    document.addEventListener('DOMContentLoaded', function () {
    const checkAll = document.getElementById('checkAllStaff');

    if (checkAll) {
        checkAll.addEventListener('change', function () {
            document.querySelectorAll('.staffRowCheck').forEach(chk => {
                chk.checked = this.checked;
            });
        });
    }

    document.querySelectorAll('.staffRowCheck').forEach(chk => {
        chk.addEventListener('change', function () {
            const all = document.querySelectorAll('.staffRowCheck');
            const checked = document.querySelectorAll('.staffRowCheck:checked');

            if (checkAll) {
                checkAll.checked = all.length > 0 && all.length === checked.length;
            }
        });
    });
});

async function removeSelectedStaff() {
    const selected = Array.from(document.querySelectorAll('.staffRowCheck:checked')).map(chk => chk.value);

    if (selected.length === 0) {
        alert('Please select at least one staff member.');
        return;
    }

    if (!confirm('Are you sure you want to remove selected staff?')) {
        return;
    }

    try {
        for (const userId of selected) {
            const response = await fetch('ajax_staff.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=delete&user_id=' + encodeURIComponent(userId)
            });

            const resp = await response.text();

            if (resp.trim() === 'ok') {
                document.querySelector('tr[data-id="' + userId + '"]')?.remove();
            } else {
                alert('Error deleting user ID ' + userId + ': ' + resp);
                return;
            }
        }

        const checkAll = document.getElementById('checkAllStaff');
        if (checkAll) checkAll.checked = false;

        alert('Selected staff removed successfully.');
        location.reload();
    } catch (e) {
        alert('Bulk delete request failed');
    }
}
    </script>
    </body>
    </html>