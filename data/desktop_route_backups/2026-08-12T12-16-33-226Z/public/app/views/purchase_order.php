    <?php
    session_start();
    require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');
    
    if (empty($_SESSION['user_id'])) {
        header("Location: /onerp/app/public/app/views/login_view.php");
        exit;
    }
    
    $client_id = (int)$_SESSION['client_id'];
    
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
    
    function poStatusFromValues(float $qty, float $amount): string
    {
        if ($qty > 0 && $amount > 0) return 'Completed';
        if ($amount > 0) return 'Pending';
        return 'Pending Approval';
    }
    
    function poStatusClass(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === 'completed') return 'status-completed';
        if ($status === 'pending') return 'status-pending';
        return 'status-approval';
    }
    
    /* ================= SUPPLIERS ================= */
    $suppliers = [];
    $stmt = $conn->prepare("
        SELECT id, company_name, name
        FROM suppliers
        WHERE client_id = ?
        ORDER BY company_name ASC, name ASC
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $suppliers[] = $row;
    }
    $stmt->close();
    
    /* ================= ITEMS ================= */
    $items = [];
    $itemsTableExists = false;
    $checkItems = $conn->query("SHOW TABLES LIKE 'items'");
    if ($checkItems && $checkItems->num_rows > 0) {
        $itemsTableExists = true;
    }
    
    if ($itemsTableExists) {
        $itemColumns = [];
        $colRes = $conn->query("SHOW COLUMNS FROM items");
        while ($c = $colRes->fetch_assoc()) {
            $itemColumns[] = $c['Field'];
        }
    
        $nameField = in_array('name', $itemColumns, true)
            ? 'name'
            : (in_array('item_name', $itemColumns, true) ? 'item_name' : 'id');
    
        $priceField = in_array('price', $itemColumns, true)
            ? 'price'
            : (in_array('sale_price', $itemColumns, true)
                ? 'sale_price'
                : (in_array('purchase_price', $itemColumns, true) ? 'purchase_price' : '0'));
    
        $sql = "SELECT id, {$nameField} AS item_name, {$priceField} AS item_price FROM items WHERE client_id = ? ORDER BY {$nameField} ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $client_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $items[] = [
                'id' => (int)$row['id'],
                'name' => $row['item_name'],
                'price' => (float)$row['item_price'],
            ];
        }
        $stmt->close();
    }
    
    /* ================= FILTERS ================= */
    $search = trim($_GET['search'] ?? '');
    $statusFilter = trim($_GET['status'] ?? '');
    $supplierFilter = trim($_GET['supplier_id'] ?? '');
    
    /* ================= FETCH ORDERS ================= */
    $sql = "
        SELECT 
            po.*,
            COALESCE(s.company_name, s.name, 'Unknown Supplier') AS supplier_name,
            COALESCE(SUM(poi.qty), 0) AS total_qty,
            COUNT(poi.id) AS total_items
        FROM purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.id
        LEFT JOIN purchase_order_items poi ON po.id = poi.po_id
        WHERE po.client_id = ?
    ";
    $params = [$client_id];
    $types = "i";
    
    if ($search !== '') {
        $sql .= " AND (
            CAST(po.id AS CHAR) LIKE ?
            OR s.company_name LIKE ?
            OR s.name LIKE ?
            OR CAST(po.total_amount AS CHAR) LIKE ?
        )";
        $like = "%{$search}%";
        array_push($params, $like, $like, $like, $like);
        $types .= "ssss";
    }
    
    if ($supplierFilter !== '') {
        $sql .= " AND po.supplier_id = ?";
        $params[] = (int)$supplierFilter;
        $types .= "i";
    }
    
    /* status filter directly query pe apply karo */
    if ($statusFilter === 'Completed') {
        $sql .= " GROUP BY po.id HAVING COALESCE(SUM(poi.qty), 0) > 0 AND po.total_amount > 0";
    } elseif ($statusFilter === 'Pending') {
        $sql .= " GROUP BY po.id HAVING COALESCE(SUM(poi.qty), 0) = 0 AND po.total_amount > 0";
    } elseif ($statusFilter === 'Pending Approval') {
        $sql .= " GROUP BY po.id HAVING po.total_amount <= 0";
    } else {
        $sql .= " GROUP BY po.id";
    }
    
    $sql .= " ORDER BY po.id DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $orders = [];
    while ($row = $res->fetch_assoc()) {
        $row['computed_status'] = poStatusFromValues((float)$row['total_qty'], (float)$row['total_amount']);
        $orders[] = $row;
    }
    $stmt->close();
    
    /* ================= STATS ================= */
    $totalOrders = count($orders);
    $pendingOrders = 0;
    $completedOrders = 0;
    $totalAmount = 0;
    
    foreach ($orders as $order) {
        $totalAmount += (float)$order['total_amount'];
        if ($order['computed_status'] === 'Completed') {
            $completedOrders++;
        } else {
            $pendingOrders++;
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Purchase Orders - ONE ERP</title>
        <?php include('partials/head.php'); ?>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    
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
            .page-title{font-size:22px;font-weight:800;margin:0;color:#243046}
            .btn-green{border:none;border-radius:12px;background:linear-gradient(180deg,#53ae6e,#3e8f58);color:#fff;font-weight:700;padding:12px 18px;min-width:190px}
            .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px}
            .stat-card{border-radius:14px;padding:18px;min-height:92px;color:#fff;position:relative;overflow:hidden;box-shadow:0 14px 28px rgba(0,0,0,.12)}
            .stat-card::after{content:'';position:absolute;width:110px;height:110px;border-radius:50%;right:-18px;top:-18px;background:rgba(255,255,255,.12)}
            .stat-blue{background:linear-gradient(135deg,#5e84ff,#7fa9ff)}
            .stat-green{background:linear-gradient(135deg,#4ea98d,#66c09f)}
            .stat-cyan{background:linear-gradient(135deg,#56b4da,#7fd4f5)}
            .stat-gold{background:linear-gradient(135deg,#8e7f73,#b9a38b)}
            .stat-title{font-size:14px;font-weight:600;line-height:1.3}
            .stat-value{position:absolute;right:18px;bottom:14px;font-size:30px;font-weight:800;line-height:1}
            .stat-sub{margin-top:6px;font-size:13px;opacity:.95}
            .filter-card,.table-card,.approval-card{background:#fff;border:1px solid #e6ebf3;border-radius:16px;box-shadow:0 8px 22px rgba(18,38,63,.05)}
            .filter-card{padding:14px;margin-bottom:16px}
            .filter-row{display:grid;grid-template-columns:1fr 1fr 1fr 1fr 120px 120px;gap:10px;margin-bottom:10px}
            .search-row{display:grid;grid-template-columns:1fr;gap:10px}
            .erp-select,.erp-input{height:44px;border:1px solid #dbe2ed;border-radius:10px;background:#fff;padding:0 14px;outline:none;width:100%}
            .filter-btn{height:44px;border:none;border-radius:10px;background:#1f2642;color:#fff;font-weight:700}
            .reset-btn{height:44px;border:none;border-radius:10px;background:#f2f4fa;color:#4b566d;font-weight:700}
            .table-card{padding:0;overflow:hidden}
            .table-wrap{overflow:auto}
            .erp-table{width:100%;min-width:1180px;border-collapse:separate;border-spacing:0}
            .erp-table thead th{background:#1a1f2e;color:#fff;padding:14px 12px;font-size:14px;font-weight:600;white-space:nowrap}
            .erp-table tbody td{background:#fff;padding:13px 12px;border-bottom:1px solid #edf1f6;vertical-align:middle;white-space:nowrap}
            .erp-table tbody tr:hover td{background:#fafcff}
            .erp-table thead th:first-child{border-top-left-radius:12px}
            .erp-table thead th:last-child{border-top-right-radius:12px}
            .supplier-cell{display:flex;align-items:center;gap:10px}
            .supplier-icon{width:28px;height:28px;border-radius:8px;background:#4f8cff;color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px}
            .status-pill{display:inline-flex;align-items:center;justify-content:center;padding:6px 12px;border-radius:8px;font-size:12px;font-weight:800}
            .status-approval{background:#f5e2aa;color:#7f5d00}
            .status-pending{background:#f7e4a8;color:#916800}
            .status-completed{background:#dcefdc;color:#2c6b38}
            .work-pill{display:inline-flex;align-items:center;gap:6px;background:#f1f3f7;color:#4c596f;border-radius:999px;padding:7px 12px;font-size:13px;font-weight:600}
           .actions{
        display:flex;
        gap:10px;
        align-items:center;
        justify-content:center;
    }
    
    /* COMMON ICON BUTTON */
    .icon-btn{
        width:38px;
        height:38px;
        border:none;
        border-radius:50%;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        font-size:15px;
        cursor:pointer;
        transition:all .18s ease;
        box-shadow:0 4px 10px rgba(15,23,42,.06);
    }
    
    .icon-btn:hover{
        transform:translateY(-2px);
    }
    
    .icon-btn i{
        font-size:15px;
    }
    
    /* EDIT */
    .icon-edit{
        background:#fff4d6;
        color:#e0a100;
    }
    .icon-edit:hover{
        background:#e0a100;
        color:#fff;
    }
    
    /* DELETE */
    .icon-delete{
        background:#ffe3e3;
        color:#ef4444;
    }
    .icon-delete:hover{
        background:#ef4444;
        color:#fff;
    }
    
    .icon-delete::before,
    .icon-delete::after{
        content:none !important;
        display:none !important;
    }
    
    /* VIEW / MORE */
    .icon-view{
        background:#eef2ff;
        color:#6366f1;
    }
    .icon-view:hover{
        background:#6366f1;
        color:#fff;
    }
           
            .act-dot{background:#f3f5fa;color:#444}
            .approval-card{margin-top:16px;padding:0;overflow:hidden}
            .approval-head{padding:16px 18px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #edf1f6}
            .approval-title{font-size:16px;font-weight:800;color:#253046;display:flex;align-items:center;gap:10px}
            .approval-body{padding:14px 18px}
            .approval-legend{display:flex;gap:24px;flex-wrap:wrap;margin-bottom:20px;color:#4c566d;font-size:14px}
            .legend-dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:6px}
            .empty-state{text-align:center;color:#6a7488;font-size:18px;padding:24px 0 28px}
            .modal-xl-custom{max-width:980px}
            .po-modal-content{background:radial-gradient(circle at top left, rgba(25,43,120,.40), transparent 32%),radial-gradient(circle at bottom right, rgba(255,185,0,.12), transparent 28%),linear-gradient(180deg,#060916,#0b1031);border:none;border-radius:20px;overflow:hidden;color:#fff;box-shadow:0 30px 80px rgba(0,0,0,.35)}
            .po-modal-header{padding:18px 24px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;justify-content:space-between;align-items:center}
            .po-modal-title{font-size:18px;font-weight:700;margin:0;color:#fff}
            .po-close{background:none;border:none;color:#d8def2;font-size:28px;line-height:1}
            .po-modal-body{padding:14px 24px 0}
            .tab-bar{display:flex;gap:0;border-bottom:1px solid rgba(255,255,255,.10);margin-bottom:18px}
            .tab-btn{border:none;padding:12px 18px;background:transparent;color:#d5d9ea;font-weight:600;border-radius:8px 8px 0 0}
            .tab-btn.active{background:#fff;color:#1d2434}
            .tab-pane-custom{display:none}
            .tab-pane-custom.active{display:block}
            .dark-label{font-size:13px;font-weight:600;color:#f4f6fd;margin-bottom:6px}
            .req{color:#ffbf43}
            .dark-input,.dark-select,.dark-textarea{width:100%;border:none;outline:none;border-radius:8px;background:#f3f5fa;color:#1d2434;padding:12px 14px;font-size:14px}
            .dark-textarea{min-height:84px;resize:vertical}
            .qty-wrap{background:#f3f5fa;border-radius:8px;height:54px;display:grid;grid-template-columns:56px 1fr 56px;overflow:hidden;align-items:center;border:1px solid rgba(255,255,255,.1)}
            .qty-btn{border:none;background:transparent;font-size:28px;color:#1d2434;height:100%;font-weight:700}
            .qty-input{border:none;background:transparent;text-align:center;font-size:30px;color:#111827;outline:none;width:100%;font-weight:800}
            .qty-meter{height:10px;border-radius:999px;background:linear-gradient(90deg,#6e7bff,#d3a23d,#9dcc67,#62d1c5);margin-top:10px}
            .qty-stats{display:flex;justify-content:space-between;color:#d4d9ea;font-size:13px;margin-top:8px}
            .switch-row{display:flex;align-items:center;gap:10px;margin-top:8px;font-weight:600;color:#fff}
            .form-check-input{width:44px;height:24px;cursor:pointer;border:none}
            .item-toolbar{display:grid;grid-template-columns:1fr 175px;gap:12px;align-items:end;margin-bottom:16px}
            .item-add-btn{height:44px;border:none;border-radius:8px;background:linear-gradient(180deg,#f2bc24,#d99d00);color:#fff;font-weight:700}
            .items-box{border:1px solid rgba(255,255,255,.12);border-radius:12px;overflow:hidden;margin-bottom:14px}
            .items-head,.items-row{display:grid;grid-template-columns:1.6fr .8fr .9fr .9fr 48px;align-items:center}
            .items-head{background:rgba(255,255,255,.06);color:#f2f4ff;font-size:14px;font-weight:600}
            .items-head div,.items-row div{padding:12px 14px;border-right:1px solid rgba(255,255,255,.10)}
            .items-head div:last-child,.items-row div:last-child{border-right:none}
            .items-row{border-top:1px solid rgba(255,255,255,.08)}
            .items-empty{text-align:center;color:#d0d6ea;padding:22px 10px;font-size:16px}
            .item-remove-btn{border:none;background:transparent;color:#f4f6ff;font-size:22px}
            .item-cell-input{width:100%;height:42px;border:1px solid rgba(255,255,255,.18);outline:none;border-radius:8px;background:rgba(255,255,255,.06);color:#fff;padding:0 12px;font-size:18px;font-weight:800}
            .item-cell-input::placeholder{color:rgba(255,255,255,.65)}
            .items-row .item-name-text{color:#fff;font-weight:700}
            .items-row .item-subtotal-text{color:#fff;font-weight:800;font-size:16px}
            .total-line{display:flex;justify-content:flex-end;gap:18px;align-items:center;color:#f5f6fb;border-top:1px solid rgba(255,255,255,.14);padding:14px 4px 0;margin-top:12px;font-size:16px}
            .total-amount{font-size:18px;font-weight:800}
            .po-modal-footer{padding:16px 24px;border-top:1px solid rgba(255,255,255,.08);display:flex;justify-content:flex-end;gap:12px;margin-top:16px}
            .cancel-btn{min-width:130px;height:44px;border-radius:10px;border:none;background:#5d617a;color:#fff;font-weight:700}
            .save-btn{min-width:140px;height:44px;border-radius:10px;border:none;background:linear-gradient(180deg,#f2bc24,#d99d00);color:#fff;font-weight:800}
            @media(max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr)}}
            @media(max-width:991px){.filter-row{grid-template-columns:1fr 1fr}}
            @media(max-width:768px){.page-header{flex-direction:column;align-items:stretch}.stats-grid{grid-template-columns:1fr}.filter-row{grid-template-columns:1fr}.item-toolbar{grid-template-columns:1fr}}
    /* =====================================================
       PURCHASE_ORDER.PHP - DARK PURPLE BLACK GLASS THEME
       Paste at VERY END before
       Backend / PHP / JS logic untouched
    ===================================================== */
    
    html, body{
        min-height:100%;
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
        --oms-orange:#f97316;
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
    .page-shell,
    .page-card,
    .po-shell,
    .purchase-shell{
        background:transparent !important;
        border:none !important;
        box-shadow:none !important;
    }
    
    .page-header,
    .header-row,
    .po-header{
        display:flex !important;
        justify-content:space-between !important;
        align-items:center !important;
        gap:14px !important;
        flex-wrap:wrap !important;
        margin-bottom:18px !important;
    }
    
    .page-title,
    h4.page-title,
    .po-title{
        font-size:28px !important;
        font-weight:900 !important;
        color:#f8fafc !important;
        margin:0 0 18px !important;
        letter-spacing:-.03em !important;
        text-shadow:0 0 28px rgba(124,58,237,.30) !important;
    }
    
    /* ================= STAT CARDS ================= */
    .stats-row,
    .stats-grid,
    .po-stats{
        display:grid !important;
        grid-template-columns:repeat(4,1fr) !important;
        gap:16px !important;
        margin-bottom:18px !important;
    }
    
    .stat-card,
    .dashboard-stat,
    .summary-card{
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
    
    .stat-card::before,
    .dashboard-stat::before,
    .summary-card::before{
        content:"";
        position:absolute;
        inset:0;
        background:
            linear-gradient(135deg, rgba(255,255,255,.10), transparent 42%),
            radial-gradient(circle at 86% 10%, rgba(255,255,255,.16), transparent 28%);
        z-index:-1;
    }
    
    .stat-card::after,
    .dashboard-stat::after,
    .summary-card::after{
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
    
    .stat-card:hover,
    .dashboard-stat:hover,
    .summary-card:hover{
        transform:translateY(-4px) !important;
        border-color:rgba(255,255,255,.22) !important;
        filter:saturate(1.08) !important;
        box-shadow:
            0 26px 65px rgba(0,0,0,.46),
            0 0 48px rgba(124,58,237,.18) !important;
    }
    
    .stat-title,
    .stat-card .title,
    .summary-card .title{
        font-size:15px !important;
        font-weight:900 !important;
        color:rgba(255,255,255,.94) !important;
    }
    
    .stat-value,
    .stat-card .count,
    .summary-card .value{
        font-size:28px !important;
        font-weight:900 !important;
        color:#fff !important;
    }
    
    .card-blue,
    .stat-blue{ background:linear-gradient(135deg,#2563eb,#6d4cff) !important; }
    
    .card-green,
    .stat-green{ background:linear-gradient(135deg,#047857,#16a34a) !important; }
    
    .card-cyan,
    .stat-cyan{ background:linear-gradient(135deg,#0891b2,#06b6d4) !important; }
    
    .card-yellow,
    .card-gold,
    .stat-yellow{ background:linear-gradient(135deg,#f59e0b,#fb923c) !important; }
    
    .card-red,
    .stat-red{ background:linear-gradient(135deg,#be123c,#ef4444) !important; }
    
    /* ================= FILTER / ACTION BAR ================= */
    .filter-bar,
    .filter-row,
    .toolbar,
    .po-filter-bar,
    .action-bar{
        background:
            radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
            linear-gradient(135deg, rgba(15,23,42,.62), rgba(17,24,39,.46)) !important;
        border:1px solid rgba(148,163,184,.16) !important;
        backdrop-filter:blur(18px) saturate(135%) !important;
        -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
        box-shadow:
            0 18px 48px rgba(0,0,0,.26),
            inset 0 1px 0 rgba(255,255,255,.045) !important;
        border-radius:18px !important;
        padding:14px !important;
        display:flex !important;
        gap:10px !important;
        flex-wrap:wrap !important;
        align-items:center !important;
        margin-bottom:18px !important;
    }
    
    .search-wrap,
    .filter-field{
        position:relative !important;
        flex:1 !important;
        min-width:220px !important;
    }
    
    .search-wrap i,
    .filter-field i{
        position:absolute !important;
        left:14px !important;
        top:50% !important;
        transform:translateY(-50%) !important;
        color:#a78bfa !important;
        z-index:2 !important;
        pointer-events:none !important;
    }
    
    .search-wrap input,
    .filter-field input,
    .filter-field select,
    .filter-control,
    .form-control,
    .form-select{
        height:44px !important;
        border:1px solid rgba(148,163,184,.18) !important;
        border-radius:13px !important;
        padding:0 14px !important;
        outline:none !important;
        background:rgba(15,23,42,.72) !important;
        color:#e5e7eb !important;
        font-weight:800 !important;
        box-shadow:none !important;
    }
    
    .search-wrap input,
    .filter-field input,
    .filter-field select{
        width:100% !important;
        padding-left:42px !important;
    }
    
    .search-wrap input::placeholder,
    .filter-field input::placeholder{
        color:#94a3b8 !important;
    }
    
    .form-select option,
    .filter-control option,
    .filter-field select option{
        background:#0f172a !important;
        color:#e5e7eb !important;
    }
    
    .search-wrap input:focus,
    .filter-field input:focus,
    .filter-field select:focus,
    .filter-control:focus,
    .form-control:focus,
    .form-select:focus{
        background:#111827 !important;
        color:#fff !important;
        border-color:rgba(109,76,255,.55) !important;
        box-shadow:0 0 0 3px rgba(109,76,255,.14) !important;
    }
    
    /* Buttons */
    .btn,
    .action-btn,
    .filter-btn,
    .filter-btn-custom,
    .reset-btn,
    .add-btn,
    .btn-add,
    .btn-import,
    .btn-export,
    .btn-report,
    .btn-primary,
    .btn-success{
        min-height:42px !important;
        border-radius:12px !important;
        font-weight:900 !important;
        box-shadow:0 12px 24px rgba(0,0,0,.18) !important;
        transition:.22s ease !important;
    }
    
    .btn:hover,
    .action-btn:hover,
    .filter-btn:hover,
    .filter-btn-custom:hover,
    .reset-btn:hover,
    .add-btn:hover,
    .btn-add:hover,
    .btn-import:hover,
    .btn-export:hover,
    .btn-report:hover{
        transform:translateY(-2px) !important;
    }
    
    .btn-add,
    .add-btn,
    .btn-success{
        background:linear-gradient(135deg,#047857,#22c55e) !important;
        color:#fff !important;
        border:none !important;
    }
    
    .btn-primary,
    .filter-btn,
    .filter-btn-custom{
        background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
        color:#fff !important;
        border:none !important;
    }
    
    .reset-btn,
    .btn-secondary,
    .btn-import,
    .btn-export{
        background:rgba(15,23,42,.62) !important;
        color:#e5e7eb !important;
        border:1px solid rgba(148,163,184,.18) !important;
    }
    
    .btn-danger,
    .btn-delete,
    .delete-btn,
    .btn-delete-selected{
        background:linear-gradient(135deg,#be123c,#ef4444) !important;
        color:#fff !important;
        border:none !important;
    }
    
    /* ================= TABLE CARD ================= */
    .table-card,
    .table-shell,
    .po-table-card,
    .purchase-table-card{
        background:
            radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
            linear-gradient(135deg, rgba(15,23,42,.68), rgba(17,24,39,.52)) !important;
        border:1px solid rgba(148,163,184,.16) !important;
        backdrop-filter:blur(18px) saturate(135%) !important;
        -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
        border-radius:18px !important;
        overflow:hidden !important;
        box-shadow:
            0 18px 48px rgba(0,0,0,.26),
            inset 0 1px 0 rgba(255,255,255,.045) !important;
        transition:.25s ease !important;
    }
    
    .table-card:hover,
    .table-shell:hover,
    .po-table-card:hover,
    .purchase-table-card:hover{
        border-color:rgba(139,92,246,.45) !important;
        box-shadow:
            0 24px 65px rgba(0,0,0,.45),
            0 0 0 1px rgba(124,58,237,.16),
            0 0 42px rgba(124,58,237,.18),
            inset 0 1px 0 rgba(255,255,255,.06) !important;
    }
    
    .table-responsive{
        overflow:auto !important;
    }
    
    table,
    .purchase-table,
    .purchase-order-table,
    .purchase-orders-table,
    .po-table{
        width:100% !important;
        border-collapse:collapse !important;
        background:transparent !important;
    }
    
    table thead th,
    .purchase-table thead th,
    .purchase-order-table thead th,
    .purchase-orders-table thead th,
    .po-table thead th{
        background:rgba(7,11,20,.72) !important;
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
    
    table tbody td,
    .purchase-table tbody td,
    .purchase-order-table tbody td,
    .purchase-orders-table tbody td,
    .po-table tbody td{
        padding:15px 14px !important;
        border-top:1px solid rgba(148,163,184,.10) !important;
        vertical-align:middle !important;
        font-size:14px !important;
        color:#e5e7eb !important;
        font-weight:700 !important;
        background:rgba(15,23,42,.24) !important;
    }
    
    /* White hover remove */
    table tbody tr:hover,
    table tbody tr:hover td,
    table tbody td:hover{
        background:
            radial-gradient(circle at 18% 0%, rgba(124,58,237,.18), transparent 38%),
            linear-gradient(135deg, rgba(30,20,58,.42), rgba(15,23,42,.34)) !important;
        color:#ffffff !important;
        box-shadow:none !important;
    }
    
    table input[type="checkbox"]{
        width:16px !important;
        height:16px !important;
        accent-color:#7c3aed !important;
    }
    
    /* ================= STATUS BADGES ================= */
    .status-badge,
    .badge-status,
    .badge,
    .po-status{
        padding:7px 12px !important;
        border-radius:999px !important;
        font-size:12px !important;
        font-weight:900 !important;
        display:inline-flex !important;
        align-items:center !important;
        justify-content:center !important;
    }
    
    .status-active,
    .status-approved,
    .badge-approved{
        background:rgba(34,197,94,.16) !important;
        color:#4ade80 !important;
        border:1px solid rgba(34,197,94,.25) !important;
    }
    
    .status-pending,
    .badge-pending{
        background:rgba(245,158,11,.16) !important;
        color:#fbbf24 !important;
        border:1px solid rgba(245,158,11,.25) !important;
    }
    
    .status-cancelled,
    .status-rejected,
    .badge-cancelled,
    .badge-rejected{
        background:rgba(239,68,68,.16) !important;
        color:#f87171 !important;
        border:1px solid rgba(239,68,68,.25) !important;
    }
    
    .status-draft,
    .badge-draft{
        background:rgba(148,163,184,.14) !important;
        color:#cbd5e1 !important;
        border:1px solid rgba(148,163,184,.22) !important;
    }
    
    /* ================= ACTION ICONS ================= */
    .actions,
    .table-actions,
    .po-actions{
        display:flex !important;
        gap:10px !important;
        align-items:center !important;
        justify-content:center !important;
    }
    
    .icon-btn,
    .action-icon,
    .edit-btn,
    .delete-btn-single,
    .view-btn{
        width:40px !important;
        height:40px !important;
        border:none !important;
        border-radius:14px !important;
        display:inline-flex !important;
        align-items:center !important;
        justify-content:center !important;
        cursor:pointer !important;
        transition:all .20s ease !important;
        box-shadow:0 12px 24px rgba(0,0,0,.18) !important;
        text-decoration:none !important;
    }
    
    .icon-btn:hover,
    .action-icon:hover,
    .edit-btn:hover,
    .delete-btn-single:hover,
    .view-btn:hover{
        transform:translateY(-2px) !important;
    }
    
    .icon-view,
    .view-btn{
        background:rgba(124,58,237,.18) !important;
        color:#a78bfa !important;
        border:1px solid rgba(124,58,237,.28) !important;
    }
    
    .icon-edit,
    .edit-btn{
        background:rgba(245,158,11,.15) !important;
        color:#fbbf24 !important;
        border:1px solid rgba(245,158,11,.24) !important;
    }
    
    .icon-delete,
    .delete-btn-single{
        background:rgba(239,68,68,.14) !important;
        color:#f87171 !important;
        border:1px solid rgba(239,68,68,.24) !important;
    }
    
    /* ================= FOOTER ================= */
    .table-footer,
    .bottom-actions,
    .footer-bar{
        padding:14px 16px !important;
        border-top:1px solid rgba(148,163,184,.13) !important;
        background:rgba(7,11,20,.30) !important;
        display:flex !important;
        justify-content:space-between !important;
        align-items:center !important;
        gap:12px !important;
        flex-wrap:wrap !important;
        color:#94a3b8 !important;
        font-weight:800 !important;
    }
    
    /* ================= MODAL PURPLE GLASS ================= */
    body.modal-open .modal-backdrop.show,
    .modal-backdrop.show{
        opacity:.52 !important;
        background:#000 !important;
    }
    
    #purchaseOrderModal .modal-dialog,
    #poModal .modal-dialog,
    .modal-dialog.purchase-order-modal{
        max-width:920px !important;
        width:920px !important;
        margin:1rem auto !important;
    }
    
    #purchaseOrderModal .modal-content,
    #poModal .modal-content,
    .purchase-order-modal .modal-content,
    .modal-content{
        background:
            radial-gradient(circle at 18% 0%, rgba(124,58,237,.24), transparent 38%),
            linear-gradient(135deg, rgba(15,23,42,.86), rgba(17,24,39,.70)) !important;
        backdrop-filter:blur(22px) saturate(145%) !important;
        -webkit-backdrop-filter:blur(22px) saturate(145%) !important;
        color:#f8fafc !important;
        border:1px solid rgba(139,92,246,.32) !important;
        border-radius:18px !important;
        overflow:hidden !important;
        box-shadow:
            0 24px 70px rgba(0,0,0,.58),
            0 0 45px rgba(124,58,237,.16),
            inset 0 1px 0 rgba(255,255,255,.06) !important;
    }
    
    .modal-header{
        min-height:56px !important;
        padding:12px 16px !important;
        background:rgba(7,11,20,.30) !important;
        border-bottom:1px solid rgba(148,163,184,.14) !important;
    }
    
    .modal-title{
        color:#f8fafc !important;
        font-size:18px !important;
        font-weight:900 !important;
        margin:0 !important;
    }
    
    .modal-body{
        background:rgba(15,23,42,.22) !important;
        color:#f8fafc !important;
        padding:14px 16px !important;
        max-height:64vh !important;
        overflow-y:auto !important;
        overflow-x:hidden !important;
    }
    
    .modal-footer{
        background:rgba(7,11,20,.30) !important;
        border-top:1px solid rgba(148,163,184,.14) !important;
        padding:10px 16px !important;
        min-height:54px !important;
    }
    
    .modal label,
    .modal .form-label{
        color:#e5e7eb !important;
        font-size:12px !important;
        font-weight:900 !important;
        margin-bottom:5px !important;
    }
    
    .modal input,
    .modal select,
    .modal textarea{
        background:rgba(255,255,255,.92) !important;
        color:#111827 !important;
        border:1px solid rgba(203,213,225,.82) !important;
        border-radius:10px !important;
        font-size:13px !important;
        font-weight:700 !important;
        box-shadow:0 8px 18px rgba(0,0,0,.13) !important;
    }
    
    .modal input,
    .modal select{
        height:36px !important;
        min-height:36px !important;
        padding:5px 10px !important;
    }
    
    .modal textarea{
        min-height:60px !important;
        height:60px !important;
        padding:8px 10px !important;
        resize:vertical !important;
    }
    
    .modal input:focus,
    .modal select:focus,
    .modal textarea:focus{
        border-color:#7c3aed !important;
        box-shadow:
            0 0 0 3px rgba(124,58,237,.20),
            0 8px 18px rgba(0,0,0,.18) !important;
        outline:none !important;
    }
    
    /* Close button */
    .modal .btn-close{
        width:34px !important;
        height:34px !important;
        border-radius:10px !important;
        background:rgba(148,163,184,.16) !important;
        border:1px solid rgba(148,163,184,.18) !important;
        opacity:1 !important;
        filter:none !important;
        background-image:none !important;
        position:relative !important;
        padding:0 !important;
    }
    
    .modal .btn-close::before,
    .modal .btn-close::after{
        content:"" !important;
        position:absolute !important;
        left:50% !important;
        top:50% !important;
        width:16px !important;
        height:2px !important;
        background:#e5e7eb !important;
        border-radius:999px !important;
    }
    
    .modal .btn-close::before{
        transform:translate(-50%, -50%) rotate(45deg) !important;
    }
    
    .modal .btn-close::after{
        transform:translate(-50%, -50%) rotate(-45deg) !important;
    }
    
    .modal .btn-close:hover{
        background:rgba(239,68,68,.24) !important;
    }
    
    /* ================= RESPONSIVE ================= */
    @media(max-width:1200px){
        .stats-row,
        .stats-grid,
        .po-stats{
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
    
        #purchaseOrderModal .modal-dialog,
        #poModal .modal-dialog,
        .modal-dialog.purchase-order-modal{
            width:calc(100% - 24px) !important;
            max-width:calc(100% - 24px) !important;
        }
    }
    
    @media(max-width:768px){
        .stats-row,
        .stats-grid,
        .po-stats{
            grid-template-columns:1fr !important;
        }
    
        .filter-bar,
        .filter-row,
        .toolbar,
        .po-filter-bar,
        .action-bar{
            flex-direction:column !important;
            align-items:stretch !important;
        }
    
        .search-wrap,
        .filter-field,
        .filter-control,
        .filter-btn,
        .add-btn,
        .btn{
            width:100% !important;
            min-width:100% !important;
        }
    
        .page-title{
            font-size:24px !important;
        }
    
        .table-footer,
        .bottom-actions,
        .footer-bar{
            flex-direction:column !important;
            align-items:stretch !important;
        }
    
        .modal-footer{
            flex-direction:column !important;
            align-items:stretch !important;
        }
    
        .modal-footer .btn{
            width:100% !important;
        }
    }
    
    /* =====================================================
       PURCHASE_ORDER.PHP FINAL FIX
       Filter card one-line + white background remove
       Paste at VERY END before 
    ===================================================== */
    
    /* Full page white gap remove */
    html,
    body{
        min-height:100% !important;
        background:
            radial-gradient(circle at 12% 8%, rgba(124,58,237,.34), transparent 28%),
            radial-gradient(circle at 78% 2%, rgba(59,130,246,.16), transparent 30%),
            linear-gradient(135deg, #050914 0%, #0b1024 38%, #160f2d 68%, #090d18 100%) !important;
    }
    
    .content-wrapper{
        min-height:calc(100vh - 70px) !important;
        overflow:visible !important;
        background:
            radial-gradient(circle at 12% 8%, rgba(124,58,237,.34), transparent 28%),
            radial-gradient(circle at 78% 2%, rgba(59,130,246,.16), transparent 30%),
            radial-gradient(circle at 92% 86%, rgba(245,158,11,.10), transparent 26%),
            linear-gradient(135deg, #050914 0%, #0b1024 38%, #160f2d 68%, #090d18 100%) !important;
    }
    
    /* Filter card glass look */
    .filter-card{
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
    
    /* Form ko clean one-line layout do */
    .filter-card form{
        display:grid !important;
        grid-template-columns:1fr !important;
        gap:10px !important;
    }
    
    /* Main filters one line */
    .filter-row{
        display:grid !important;
        grid-template-columns:
            minmax(150px, 1fr)
            minmax(160px, 1fr)
            minmax(150px, 1fr)
            minmax(150px, 1fr)
            100px
            100px !important;
        gap:10px !important;
        align-items:center !important;
        margin-bottom:0 !important;
    }
    
    /* Search input full width below filters */
    .search-row{
        display:grid !important;
        grid-template-columns:1fr !important;
        gap:10px !important;
        margin-top:0 !important;
    }
    
    /* Inputs/select dark glass */
    .erp-select,
    .erp-input{
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
    }
    
    .erp-select option{
        background:#0f172a !important;
        color:#e5e7eb !important;
    }
    
    .erp-select:focus,
    .erp-input:focus{
        background:rgba(17,24,39,.92) !important;
        color:#fff !important;
        border-color:rgba(124,58,237,.62) !important;
        box-shadow:
            0 0 0 3px rgba(124,58,237,.16),
            0 12px 26px rgba(0,0,0,.24) !important;
    }
    
    .erp-input::placeholder{
        color:#94a3b8 !important;
    }
    
    /* Disabled select white na ho */
    .erp-select:disabled{
        background:rgba(15,23,42,.50) !important;
        color:#94a3b8 !important;
        opacity:1 !important;
        cursor:not-allowed !important;
    }
    
    /* Filter / Reset buttons compact */
    .filter-btn,
    .reset-btn{
        height:46px !important;
        width:100% !important;
        border-radius:13px !important;
        border:none !important;
        font-weight:900 !important;
        display:inline-flex !important;
        align-items:center !important;
        justify-content:center !important;
        gap:6px !important;
        transition:.22s ease !important;
        box-shadow:0 12px 24px rgba(0,0,0,.20) !important;
    }
    
    .filter-btn{
        background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
        color:#fff !important;
    }
    
    .reset-btn{
        background:rgba(15,23,42,.72) !important;
        color:#e5e7eb !important;
        border:1px solid rgba(148,163,184,.18) !important;
    }
    
    .filter-btn:hover,
    .reset-btn:hover{
        transform:translateY(-2px) !important;
        color:#fff !important;
        border-color:rgba(139,92,246,.45) !important;
        box-shadow:
            0 18px 40px rgba(0,0,0,.36),
            0 0 34px rgba(124,58,237,.18) !important;
    }
    
    .reset-btn:hover{
        background:rgba(124,58,237,.20) !important;
    }
    
    /* Pending approval white card fix */
    .approval-card{
        background:
            radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
            linear-gradient(135deg, rgba(15,23,42,.70), rgba(17,24,39,.54)) !important;
        border:1px solid rgba(148,163,184,.18) !important;
        border-radius:18px !important;
        overflow:hidden !important;
        box-shadow:
            0 18px 48px rgba(0,0,0,.30),
            inset 0 1px 0 rgba(255,255,255,.05) !important;
        backdrop-filter:blur(18px) saturate(135%) !important;
        -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
    }
    
    .approval-head{
        background:rgba(7,11,20,.36) !important;
        border-bottom:1px solid rgba(148,163,184,.14) !important;
    }
    
    .approval-title{
        color:#f8fafc !important;
        font-weight:900 !important;
    }
    
    .approval-body{
        background:rgba(15,23,42,.16) !important;
    }
    
    .approval-legend{
        color:#cbd5e1 !important;
        font-weight:800 !important;
    }
    
    .empty-state{
        color:#94a3b8 !important;
        font-weight:800 !important;
        background:transparent !important;
    }
    
    /* Table white row fix */
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
    }
    
    .erp-table tbody td{
        background:rgba(15,23,42,.30) !important;
        color:#e5e7eb !important;
        border-bottom:1px solid rgba(148,163,184,.10) !important;
    }
    
    .erp-table tbody tr:hover td{
        background:
            radial-gradient(circle at 18% 0%, rgba(124,58,237,.18), transparent 38%),
            linear-gradient(135deg, rgba(30,20,58,.42), rgba(15,23,42,.34)) !important;
        color:#fff !important;
    }
    
    .erp-table thead th{
        background:rgba(7,11,20,.76) !important;
        color:#cbd5e1 !important;
        border-bottom:1px solid rgba(148,163,184,.16) !important;
    }
    
    /* Supplier / work pill dark readable */
    .work-pill{
        background:rgba(34,197,94,.14) !important;
        color:#4ade80 !important;
        border:1px solid rgba(34,197,94,.24) !important;
    }
    
    .supplier-icon{
        background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    }
    
    /* Page title and button polish */
    .page-title{
        color:#f8fafc !important;
        font-size:30px !important;
        font-weight:900 !important;
        text-shadow:0 0 28px rgba(124,58,237,.30) !important;
    }
    
    .btn-green{
        background:linear-gradient(135deg,#047857,#22c55e) !important;
        border:none !important;
        border-radius:14px !important;
        min-height:50px !important;
        color:#fff !important;
        font-weight:900 !important;
        box-shadow:
            0 16px 34px rgba(34,197,94,.22),
            0 0 28px rgba(34,197,94,.12) !important;
        transition:.22s ease !important;
    }
    
    .btn-green:hover{
        transform:translateY(-2px) !important;
    }
    
    /* Responsive: medium screens par 3 + 3 */
    @media(max-width:1200px){
        .filter-row{
            grid-template-columns:repeat(3, 1fr) !important;
        }
    }
    
    /* Mobile/tablet par one column */
    @media(max-width:768px){
        .filter-row{
            grid-template-columns:1fr !important;
        }
    
        .filter-btn,
        .reset-btn,
        .erp-select,
        .erp-input{
            width:100% !important;
        }
    }
    /* =====================================================
       PURCHASE ORDER POPUP SIZE SMALL FIX
       Paste at VERY END before 
    ===================================================== */
    
    /* Popup width small */
    #poModal .modal-dialog,
    #poModal .modal-dialog.modal-xl-custom{
        max-width:820px !important;
        width:820px !important;
        margin:18px auto !important;
    }
    
    /* Popup height controlled */
    #poModal .modal-content,
    #poModal .po-modal-content{
        max-height:82vh !important;
        border-radius:18px !important;
        overflow:hidden !important;
    }
    
    /* Header compact */
    #poModal .po-modal-header,
    #poModal .modal-header{
        min-height:54px !important;
        padding:12px 18px !important;
    }
    
    #poModal .po-modal-title,
    #poModal .modal-title{
        font-size:18px !important;
    }
    
    /* Close button small */
    #poModal .po-close{
        width:36px !important;
        height:36px !important;
        font-size:22px !important;
    }
    
    /* Body compact + scroll inside popup */
    #poModal .po-modal-body,
    #poModal .modal-body{
        padding:12px 18px !important;
        max-height:62vh !important;
        overflow-y:auto !important;
        overflow-x:hidden !important;
    }
    
    /* Tabs compact */
    #poModal .tab-bar{
        margin-bottom:12px !important;
    }
    
    #poModal .tab-btn{
        padding:9px 14px !important;
        font-size:14px !important;
    }
    
    /* Labels compact */
    #poModal .dark-label,
    #poModal label{
        font-size:12px !important;
        margin-bottom:5px !important;
    }
    
    /* Bootstrap row gap compact */
    #poModal .row{
        --bs-gutter-x:14px !important;
        --bs-gutter-y:8px !important;
    }
    
    #poModal .mb-3{
        margin-bottom:10px !important;
    }
    
    #poModal .mb-2{
        margin-bottom:8px !important;
    }
    
    /* Inputs/selects small */
    #poModal .dark-input,
    #poModal .dark-select,
    #poModal input[type="text"],
    #poModal input[type="number"],
    #poModal input[type="date"],
    #poModal select{
        height:38px !important;
        min-height:38px !important;
        padding:6px 12px !important;
        font-size:13px !important;
        border-radius:9px !important;
    }
    
    /* Textarea small */
    #poModal .dark-textarea,
    #poModal textarea{
        min-height:58px !important;
        height:58px !important;
        padding:8px 12px !important;
        font-size:13px !important;
        border-radius:9px !important;
    }
    
    /* Quantity area compact */
    #poModal .qty-wrap{
        height:42px !important;
        grid-template-columns:48px 1fr 48px !important;
        border-radius:9px !important;
    }
    
    #poModal .qty-btn{
        font-size:22px !important;
    }
    
    #poModal .qty-input{
        height:42px !important;
        min-height:42px !important;
        font-size:18px !important;
    }
    
    #poModal .qty-meter{
        height:7px !important;
        margin-top:7px !important;
    }
    
    #poModal .qty-stats{
        font-size:12px !important;
        margin-top:5px !important;
    }
    
    /* Approval switch compact */
    #poModal .switch-row{
        padding:8px 12px !important;
        margin-top:4px !important;
        font-size:13px !important;
    }
    
    #poModal .form-check-input{
        width:38px !important;
        height:20px !important;
    }
    
    /* Items tab compact */
    #poModal .item-toolbar{
        grid-template-columns:1fr 140px !important;
        gap:10px !important;
        margin-bottom:10px !important;
    }
    
    #poModal .item-add-btn{
        height:38px !important;
        font-size:13px !important;
        border-radius:9px !important;
    }
    
    #poModal .items-box{
        border-radius:12px !important;
        margin-bottom:10px !important;
    }
    
    #poModal .items-head,
    #poModal .items-row{
        grid-template-columns:1.5fr .75fr .85fr .85fr 44px !important;
    }
    
    #poModal .items-head div,
    #poModal .items-row div{
        padding:9px 10px !important;
        font-size:12px !important;
    }
    
    #poModal .item-cell-input{
        height:34px !important;
        font-size:13px !important;
        padding:0 10px !important;
    }
    
    #poModal .items-empty{
        padding:14px 10px !important;
        font-size:14px !important;
    }
    
    /* Total line compact */
    #poModal .total-line{
        padding-top:9px !important;
        margin-top:8px !important;
        font-size:14px !important;
    }
    
    #poModal .total-amount{
        font-size:15px !important;
    }
    
    /* Is huge gap ko remove karo */
    #poModal .total-line[style*="margin-top:180px"]{
        margin-top:18px !important;
    }
    
    /* Footer compact */
    #poModal .po-modal-footer,
    #poModal .modal-footer{
        padding:10px 18px !important;
        min-height:52px !important;
        margin-top:0 !important;
    }
    
    #poModal .cancel-btn,
    #poModal .save-btn{
        height:38px !important;
        min-width:105px !important;
        font-size:13px !important;
        border-radius:9px !important;
    }
    
    /* Responsive */
    @media(max-width:900px){
        #poModal .modal-dialog,
        #poModal .modal-dialog.modal-xl-custom{
            width:calc(100% - 24px) !important;
            max-width:calc(100% - 24px) !important;
            margin:12px auto !important;
        }
    
        #poModal .po-modal-body,
        #poModal .modal-body{
            max-height:66vh !important;
        }
    }
    
    @media(max-width:768px){
        #poModal .item-toolbar{
            grid-template-columns:1fr !important;
        }
    
        #poModal .po-modal-footer,
        #poModal .modal-footer{
            flex-direction:column !important;
            align-items:stretch !important;
        }
    
        #poModal .cancel-btn,
        #poModal .save-btn{
            width:100% !important;
        }
    }
    
    
    /* =====================================================
   PURCHASE ORDER - ENABLE APPROVAL SWITCH FIX
   Paste at VERY END before 
===================================================== */

/* Enable Approval row ko proper glass card look do */
#poModal .switch-row{
    display:inline-flex !important;
    align-items:center !important;
    gap:12px !important;
    padding:10px 14px !important;
    margin-top:12px !important;
    border-radius:14px !important;
    background:
        radial-gradient(circle at 0% 0%, rgba(124,58,237,.18), transparent 38%),
        linear-gradient(135deg, rgba(15,23,42,.62), rgba(17,24,39,.44)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    color:#f8fafc !important;
    font-size:14px !important;
    font-weight:900 !important;
    box-shadow:
        0 12px 28px rgba(0,0,0,.24),
        inset 0 1px 0 rgba(255,255,255,.04) !important;
}

/* Bootstrap switch wrapper spacing */
#poModal .switch-row .form-check{
    margin:0 !important;
    padding-left:0 !important;
    min-height:auto !important;
    display:flex !important;
    align-items:center !important;
}

/* IMPORTANT: generic modal input CSS ko override karo */
#poModal .switch-row .form-check-input,
#poModal input#enable_approval.form-check-input{
    width:48px !important;
    height:26px !important;
    min-width:48px !important;
    min-height:26px !important;
    margin:0 !important;
    padding:0 !important;
    cursor:pointer !important;
    border-radius:999px !important;

    background-color:rgba(51,65,85,.95) !important;
    border:1px solid rgba(148,163,184,.32) !important;
    box-shadow:
        inset 0 2px 5px rgba(0,0,0,.32),
        0 0 0 0 rgba(124,58,237,0) !important;

    appearance:auto !important;
    -webkit-appearance:none !important;
    background-image:none !important;
    transition:all .22s ease !important;
    position:relative !important;
}

/* Switch ka white circle */
#poModal .switch-row .form-check-input::before,
#poModal input#enable_approval.form-check-input::before{
    content:"" !important;
    position:absolute !important;
    top:3px !important;
    left:3px !important;
    width:18px !important;
    height:18px !important;
    border-radius:50% !important;
    background:#ffffff !important;
    box-shadow:0 3px 8px rgba(0,0,0,.30) !important;
    transition:all .22s ease !important;
}

/* Checked state purple-green gradient */
#poModal .switch-row .form-check-input:checked,
#poModal input#enable_approval.form-check-input:checked{
    background:
        linear-gradient(135deg,#6d4cff,#22c55e) !important;
    border-color:rgba(34,197,94,.55) !important;
    box-shadow:
        0 0 0 3px rgba(124,58,237,.18),
        0 0 22px rgba(34,197,94,.22),
        inset 0 1px 0 rgba(255,255,255,.12) !important;
}

/* Checked par circle right side */
#poModal .switch-row .form-check-input:checked::before,
#poModal input#enable_approval.form-check-input:checked::before{
    left:25px !important;
}

/* Focus outline */
#poModal .switch-row .form-check-input:focus,
#poModal input#enable_approval.form-check-input:focus{
    outline:none !important;
    box-shadow:
        0 0 0 3px rgba(124,58,237,.22),
        0 0 22px rgba(124,58,237,.16) !important;
}

/* Text clean */
#poModal .switch-row span,
#poModal .switch-row label{
    color:#f8fafc !important;
    font-weight:900 !important;
    line-height:1 !important;
    margin:0 !important;
}

/* Switch row ko left aligned aur compact rakho */
#poModal .approval-switch-wrap,
#poModal .switch-row-wrap{
    display:flex !important;
    align-items:center !important;
    justify-content:flex-start !important;
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
                    <h1 class="page-title">Purchase Orders</h1>
                    <button type="button" class="btn-green" onclick="openPOModal()">
                        <i class="bi bi-plus-lg me-1"></i> New Purchase Order
                    </button>
                </div>
    
                <div class="stats-grid">
                    <div class="stat-card stat-blue"><div class="stat-title">Total Purchase Orders</div><div class="stat-value"><?= $totalOrders ?></div></div>
                    <div class="stat-card stat-green"><div class="stat-title">Pending Orders</div><div class="stat-value"><?= $pendingOrders ?></div></div>
                    <div class="stat-card stat-cyan"><div class="stat-title">Completed Orders</div><div class="stat-value"><?= $completedOrders ?></div></div>
                    <div class="stat-card stat-gold"><div class="stat-title">PKR</div><div class="stat-sub"><?= number_format($totalAmount) ?></div><div class="stat-value" style="font-size:22px;">PKR <?= number_format($totalAmount) ?></div></div>
                </div>
    
                <div class="filter-card">
                    <form method="GET">
                        <div class="filter-row">
                            <select class="erp-select" name="status">
                                <option value="">All Sending</option>
                                <option value="Pending Approval" <?= $statusFilter === 'Pending Approval' ? 'selected' : '' ?>>Pending Approval</option>
                                <option value="Pending" <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="Completed" <?= $statusFilter === 'Completed' ? 'selected' : '' ?>>Completed</option>
                            </select>
    
                            <select class="erp-select" name="supplier_id">
                                <option value="">All Suppliers</option>
                                <?php foreach ($suppliers as $s): ?>
                                    <option value="<?= (int)$s['id'] ?>" <?= $supplierFilter == $s['id'] ? 'selected' : '' ?>>
                                        <?= e(!empty($s['company_name']) ? $s['company_name'] : $s['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
    
                            <select class="erp-select" disabled><option>All Suppliers</option></select>
                            <select class="erp-select" disabled><option>Last 30 Days</option></select>
    
                            <button type="submit" class="filter-btn"><i class="bi bi-search me-1"></i> Filter</button>
                            <a href="purchase_order.php" class="reset-btn d-inline-flex align-items-center justify-content-center text-decoration-none"><i class="bi bi-arrow-clockwise me-1"></i> Reset</a>
                        </div>
                        <div class="search-row">
                            <input type="text" class="erp-input" name="search" placeholder="Search orders..." value="<?= e($search) ?>">
                        </div>
                    </form>
                </div>
    
                <div class="table-card">
                    <div class="table-wrap">
                        <table class="erp-table">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="checkAll"></th>
                                    <th>Supplier</th>
                                    <th>Status</th>
                                    <th>Total Amount</th>
                                    <th>Date</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($orders)): ?>
                                    <tr><td colspan="7" class="text-center py-4">No purchase orders found</td></tr>
                                <?php else: ?>
                                    <?php foreach ($orders as $o): ?>
                                        <tr>
                                            <td><input type="checkbox" class="row-check" value="<?= (int)$o['id'] ?>"></td>
                                            <td>
                                                <div class="supplier-cell">
                                                    <span class="supplier-icon"><i class="bi bi-card-list"></i></span>
                                                    <span><?= e($o['supplier_name']) ?></span>
                                                </div>
                                            </td>
                                            <td><span class="status-pill <?= poStatusClass($o['computed_status']) ?>"><?= e($o['computed_status']) ?></span></td>
                                            <td>PKR <?= number_format((float)$o['total_amount']) ?></td>
                                            <td><?= date('d/m/Y', strtotime($o['created_at'])) ?></td>
                                            <td><span class="work-pill"><i class="bi bi-check-lg text-success"></i> Work</span></td>
                                  <td>
        <div class="actions">
    
            <button type="button"
                class="icon-btn icon-edit editPO"
                data-id="<?= (int)$o['id'] ?>"
                title="Edit">
                <i class="bi bi-pencil-fill"></i>
            </button>
    
            <button type="button"
                class="icon-btn icon-delete deletePO"
                data-id="<?= (int)$o['id'] ?>"
                title="Delete">
                <i class="bi bi-trash-fill"></i>
            </button>
    
            <button type="button"
                class="icon-btn icon-view viewPO"
                data-id="<?= (int)$o['id'] ?>"
                title="View">
                <i class="bi bi-three-dots-vertical"></i>
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
    
                <div class="approval-card">
                    <div class="approval-head">
                        <div class="approval-title">
                            <i class="bi bi-hourglass-split text-warning"></i>
                            Pending Approvals
                        </div>
                        <button type="button" class="reset-btn" style="height:40px;">View All</button>
                    </div>
                    <div class="approval-body">
                        <div class="approval-legend">
                            <span><span class="legend-dot" style="background:#4c8dff;"></span>Needs Review</span>
                            <span><span class="legend-dot" style="background:#d9aa25;"></span>Pending</span>
                            <span><span class="legend-dot" style="background:#69b46a;"></span>Approved</span>
                            <span><span class="legend-dot" style="background:#e06b6b;"></span>Rejected</span>
                        </div>
                        <div class="empty-state">No pending approvals</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="poModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl-custom">
            <div class="modal-content po-modal-content">
                <form id="poForm">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="po_id" id="po_id">
    
                    <div class="po-modal-header">
                        <h5 class="po-modal-title" id="poModalTitle">New Purchase Order</h5>
                        <button type="button" class="po-close" data-bs-dismiss="modal">&times;</button>
                    </div>
    
                    <div class="po-modal-body">
                        <div class="tab-bar">
                            <button type="button" class="tab-btn active" data-tab="detailsTab">Order Details</button>
                            <button type="button" class="tab-btn" data-tab="itemsTab">Order Items</button>
                        </div>
    
                        <div id="detailsTab" class="tab-pane-custom active">
                            <div class="row">
                                <div class="col-12 mb-3">
                                    <label class="dark-label">Product <span class="req">*</span></label>
                                    <select class="dark-select" id="detail_product_select">
                                        <option value="">- Select Product...</option>
                                        <?php foreach ($items as $item): ?>
                                            <option value="<?= (int)$item['id'] ?>" data-price="<?= (float)$item['price'] ?>">
                                                <?= e($item['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
    
                                <div class="col-12 mb-3">
                                    <label class="dark-label">Quantity</label>
                                    <div class="qty-wrap">
                                        <button class="qty-btn" type="button" id="qtyMinus">−</button>
                                        <input type="number" class="qty-input" id="detail_qty" value="1" min="1">
                                        <button class="qty-btn" type="button" id="qtyPlus">+</button>
                                    </div>
                                    <div class="qty-meter"></div>
                                    <div class="qty-stats">
                                        <span>0 Reserved</span>
                                        <span>0 Sold</span>
                                        <span>0 Available</span>
                                    </div>
                                </div>
    
                                <div class="col-md-6 mb-3">
                                    <label class="dark-label">Supplier <span class="req">*</span></label>
                                    <select name="supplier_id" id="supplier_id" class="dark-select" required>
                                        <option value="">- Select Supplier...</option>
                                        <?php foreach ($suppliers as $s): ?>
                                            <option value="<?= (int)$s['id'] ?>">
                                                <?= e(!empty($s['company_name']) ? $s['company_name'] : $s['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
    
                                <div class="col-md-6 mb-3">
                                    <label class="dark-label">Order Date <span class="req">*</span></label>
                                    <input type="date" class="dark-input" id="order_date" value="<?= date('Y-m-d') ?>">
                                </div>
    
                                <div class="col-12 mb-3">
                                    <label class="dark-label">Reference Number <span class="req">*</span></label>
                                    <input type="text" class="dark-input" id="reference_number" placeholder="Enter reference number">
                                </div>
    
                                <div class="col-12 mb-2">
                                    <label class="dark-label">Notes</label>
                                    <textarea class="dark-textarea" id="po_notes" placeholder="Enter internal notes..."></textarea>
                                </div>
    
                                <div class="col-12">
                                    <div class="switch-row">
                                        <div class="form-check form-switch m-0">
                                            <input class="form-check-input" type="checkbox" id="enable_approval" checked>
                                        </div>
                                        Enable Approval
                                    </div>
                                </div>
                            </div>
                        </div>
    
                        <div id="itemsTab" class="tab-pane-custom">
                            <div class="item-toolbar">
                                <div>
                                    <label class="dark-label">Product <span class="req">*</span></label>
                                    <select class="dark-select" id="item_product_select">
                                        <option value="">- Select Product...</option>
                                        <?php foreach ($items as $item): ?>
                                            <option value="<?= (int)$item['id'] ?>" data-price="<?= (float)$item['price'] ?>">
                                                <?= e($item['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <button type="button" class="item-add-btn" id="addItemBtn">
                                        <i class="bi bi-plus-lg me-1"></i> Add Item
                                    </button>
                                </div>
                            </div>
    
                            <div class="items-box">
                                <div class="items-head">
                                    <div>Item</div>
                                    <div>Quantity</div>
                                    <div>Unit Cost (PKR)</div>
                                    <div>Subtotal (PKR)</div>
                                    <div></div>
                                </div>
                                <div id="poItemsContainer">
                                    <div class="items-empty">No items added</div>
                                </div>
                            </div>
    
                            <div class="total-line">
                                <span>Total Amount (PKR)</span>
                                <span class="total-amount" id="topTotalText">PKR 0</span>
                            </div>
    
                            <div class="total-line" style="margin-top:180px;">
                                <span>Total Amount (PKR)</span>
                                <span class="total-amount" id="bottomTotalText">PKR 0</span>
                            </div>
                        </div>
                    </div>
    
                    <div class="po-modal-footer">
                        <button type="button" class="cancel-btn" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="save-btn" id="savePoBtn">Add Order</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="viewPOModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content" style="border-radius:16px;overflow:hidden;">
                <div class="modal-header">
                    <h5 class="modal-title">Purchase Order Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="viewPOModalBody">Loading...</div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        
    function handleAjaxSession(response) {
        if (response.status === 401) {
            window.location.href = '/onerp/app/onerp/app/public/app/views/login_view.php';
            return null;
        }
    
        return response.text();
    }
    const poModal = new bootstrap.Modal(document.getElementById('poModal'));
    const viewPOModal = new bootstrap.Modal(document.getElementById('viewPOModal'));
    let poItems = [];
    
    function formatPKR(value) {
        return 'PKR ' + Number(value || 0).toLocaleString();
    }
    
    function activateTab(tabId) {
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.tab === tabId);
        });
        document.querySelectorAll('.tab-pane-custom').forEach(tab => {
            tab.classList.toggle('active', tab.id === tabId);
        });
    }
    
    function resetPOForm() {
        document.getElementById('poForm').reset();
        document.getElementById('po_id').value = '';
        document.getElementById('poModalTitle').innerText = 'New Purchase Order';
        document.getElementById('savePoBtn').innerText = 'Add Order';
        document.getElementById('order_date').value = new Date().toISOString().slice(0,10);
        document.getElementById('detail_qty').value = 1;
        poItems = [];
        renderItems();
        activateTab('detailsTab');
    }
    
    function openPOModal() {
        resetPOForm();
        poModal.show();
    }
    
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            activateTab(this.dataset.tab);
        });
    });
    
    document.getElementById('qtyMinus').addEventListener('click', () => {
        const input = document.getElementById('detail_qty');
        let v = parseInt(input.value || 1, 10);
        if (v > 1) v--;
        input.value = v;
    });
    
    document.getElementById('qtyPlus').addEventListener('click', () => {
        const input = document.getElementById('detail_qty');
        let v = parseInt(input.value || 1, 10);
        input.value = v + 1;
    });
    
    document.getElementById('detail_product_select').addEventListener('change', function () {
        document.getElementById('item_product_select').value = this.value;
    });
    
    function renderItems() {
        const container = document.getElementById('poItemsContainer');
    
        if (!poItems.length) {
            container.innerHTML = '<div class="items-empty">No items added</div>';
            document.getElementById('topTotalText').innerText = 'PKR 0';
            document.getElementById('bottomTotalText').innerText = 'PKR 0';
            return;
        }
    
        let html = '';
        let total = 0;
    
        poItems.forEach((item, index) => {
            const subtotal = Number(item.qty) * Number(item.price);
            total += subtotal;
    
            html += `
                <div class="items-row">
                    <div class="item-name-text">${item.name}</div>
                    <div>
                        <input type="number" class="item-cell-input" value="${item.qty}" min="1"
                            onchange="updateItemQty(${index}, this.value)">
                    </div>
                    <div>
                        <input type="number" class="item-cell-input" value="${item.price}" step="0.01" min="0"
                            onchange="updateItemPrice(${index}, this.value)">
                    </div>
                    <div class="item-subtotal-text">${subtotal.toLocaleString()}</div>
                    <div>
                        <button type="button" class="item-remove-btn" onclick="removeItem(${index})">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            `;
        });
    
        container.innerHTML = html;
        document.getElementById('topTotalText').innerText = formatPKR(total);
        document.getElementById('bottomTotalText').innerText = formatPKR(total);
    }
    
    function addSelectedItem() {
        const select = document.getElementById('item_product_select');
        const option = select.options[select.selectedIndex];
        const detailQty = Math.max(1, Number(document.getElementById('detail_qty').value || 1));
    
        if (!select.value) {
            alert('Please select product');
            return;
        }
    
        const existing = poItems.findIndex(i => String(i.item_id) === String(select.value));
        if (existing !== -1) {
            poItems[existing].qty = Number(poItems[existing].qty) + detailQty;
            renderItems();
            return;
        }
    
        poItems.push({
            item_id: select.value,
            name: option.text,
            qty: detailQty,
            price: Number(option.dataset.price || 0)
        });
    
        renderItems();
        document.getElementById('detail_qty').value = 1;
        activateTab('itemsTab');
    }
    
    function maybeAutoAddFromDetails() {
        const detailSelect = document.getElementById('detail_product_select');
        const detailQty = Math.max(1, Number(document.getElementById('detail_qty').value || 1));
    
        if (!poItems.length && detailSelect.value) {
            const option = detailSelect.options[detailSelect.selectedIndex];
            poItems.push({
                item_id: detailSelect.value,
                name: option.text,
                qty: detailQty,
                price: Number(option.dataset.price || 0)
            });
            renderItems();
        }
    }
    
    function updateItemQty(index, value) {
        poItems[index].qty = Math.max(1, Number(value || 1));
        renderItems();
    }
    
    function updateItemPrice(index, value) {
        poItems[index].price = Math.max(0, Number(value || 0));
        renderItems();
    }
    
    function removeItem(index) {
        poItems.splice(index, 1);
        renderItems();
    }
    
    window.updateItemQty = updateItemQty;
    window.updateItemPrice = updateItemPrice;
    window.removeItem = removeItem;
    
    document.getElementById('addItemBtn').addEventListener('click', addSelectedItem);
    
    document.getElementById('poForm').addEventListener('submit', async function(e) {
        e.preventDefault();
    
        maybeAutoAddFromDetails();
    
        const supplierId = document.getElementById('supplier_id').value;
        if (!supplierId) {
            alert('Please select supplier');
            activateTab('detailsTab');
            return;
        }
    
        if (!poItems.length) {
            alert('Please add at least one item');
            activateTab('itemsTab');
            return;
        }
    
        const formData = new FormData();
        formData.append('action', 'save');
        formData.append('po_id', document.getElementById('po_id').value);
        formData.append('supplier_id', supplierId);
    
        poItems.forEach(item => {
            formData.append('item_id[]', item.item_id);
            formData.append('qty[]', item.qty);
            formData.append('price[]', item.price);
        });
    
        const saveBtn = document.getElementById('savePoBtn');
        const oldText = saveBtn.innerText;
        saveBtn.disabled = true;
        saveBtn.innerText = 'Saving...';
    
        try {
            const response = await fetch('ajax_purchase_order.php', {
                method: 'POST',
                body: formData
            });
    
            const res = await response.text();
            if (res.trim() === 'saved') {
                location.reload();
            } else {
                alert(res);
                saveBtn.disabled = false;
                saveBtn.innerText = oldText;
            }
        } catch (e) {
            alert('Save request failed');
            saveBtn.disabled = false;
            saveBtn.innerText = oldText;
        }
    });
    
    async function fetchPOData(id) {
        const response = await fetch('ajax_purchase_order.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=get&id=' + encodeURIComponent(id)
        });
        return await response.json();
    }
    
    document.querySelectorAll('.editPO').forEach(btn => {
        btn.addEventListener('click', async function() {
            const id = this.dataset.id;
            const data = await fetchPOData(id);
    
            if (!data.success) {
                alert(data.message || 'Order not found');
                return;
            }
    
            resetPOForm();
            document.getElementById('po_id').value = data.order.id;
            document.getElementById('supplier_id').value = data.order.supplier_id;
            document.getElementById('poModalTitle').innerText = 'Edit Purchase Order';
            document.getElementById('savePoBtn').innerText = 'Update Order';
    
            poItems = data.items.map(item => ({
                item_id: item.item_id,
                name: item.item_name,
                qty: Number(item.qty),
                price: Number(item.price)
            }));
    
            renderItems();
            poModal.show();
        });
    });
    
    document.querySelectorAll('.viewPO').forEach(btn => {
        btn.addEventListener('click', async function() {
            const id = this.dataset.id;
            try {
                const response = await fetch('ajax_view_po.php?po_id=' + encodeURIComponent(id));
                const html = await response.text();
                document.getElementById('viewPOModalBody').innerHTML = html;
                viewPOModal.show();
            } catch (e) {
                alert('Failed to load PO details');
            }
        });
    });
    
    document.querySelectorAll('.deletePO').forEach(btn => {
        btn.addEventListener('click', async function() {
            if (!confirm('Delete this purchase order?')) return;
    
            const response = await fetch('ajax_purchase_order.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=delete&id=' + encodeURIComponent(this.dataset.id)
            });
    
            const res = await response.text();
            if (res.trim() === 'deleted') {
                location.reload();
            } else {
                alert(res);
            }
        });
    });
    
    const checkAll = document.getElementById('checkAll');
    checkAll?.addEventListener('change', function() {
        document.querySelectorAll('.row-check').forEach(ch => ch.checked = this.checked);
    });
    </script>
    </body>
    </html>