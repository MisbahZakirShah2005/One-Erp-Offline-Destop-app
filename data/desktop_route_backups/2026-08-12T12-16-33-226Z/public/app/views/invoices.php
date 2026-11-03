<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ================= AUTH =================
if (empty($_SESSION['user_id'])) {
    header("Location: /onerp/app/public/app/views/login_view.php");
    exit;
}
/* ================= AJAX ================= */
if(isset($_POST['ajax'])){

    // -------- SAVE INVOICE --------
    if($_POST['ajax'] === 'save_invoice'){

        $invoice_id = (int)($_POST['invoice_id'] ?? 0);
        $client_id = (int)$_POST['client_id'];
        $subscription_id = (int)$_POST['subscription_id'];
        $amount = (float)$_POST['amount'];   // ❗ float only
        $due_date = $_POST['due_date'];
        $status = $_POST['status'];
        $invoice_date = date('Y-m-d');

        if($invoice_id > 0){
            $stmt = $conn->prepare("
                UPDATE invoices 
                SET client_id=?, subscription_id=?, due_date=?, amount=?, status=?
                WHERE id=?
            ");
            $stmt->bind_param(
                "iisdsi",
                $client_id,
                $subscription_id,
                $due_date,
                $amount,
                $status,
                $invoice_id
            );
        } else {
            $stmt = $conn->prepare("
                INSERT INTO invoices
                (client_id, subscription_id, invoice_date, due_date, amount, status)
                VALUES (?,?,?,?,?,?)
            ");
            $stmt->bind_param(
                "iissds",
                $client_id,
                $subscription_id,
                $invoice_date,
                $due_date,
                $amount,
                $status
            );
        }

        $stmt->execute();
        $stmt->close();
        echo "ok";
        exit;
    }

    // -------- DELETE --------
    if($_POST['ajax'] === 'delete_invoice'){
        $id = (int)$_POST['id'];
        $conn->query("DELETE FROM invoices WHERE id=$id");
        echo "deleted";
        exit;
    }
}

/* ================= DATA ================= */

// Clients + subscriptions
$clients = $conn->query("
    SELECT s.id AS subscription_id, c.id AS client_id, c.business_name, p.name AS plan_name, p.price
    FROM subscriptions s
    JOIN clients c ON c.id=s.client_id
    JOIN plans p ON p.id=s.plan_id
    WHERE s.status='active'
");

// Invoices
$invoices = $conn->query("
    SELECT i.*, c.business_name, p.name AS plan_name
    FROM invoices i
    JOIN clients c ON c.id=i.client_id
    JOIN subscriptions s ON s.id=i.subscription_id
    JOIN plans p ON p.id=s.plan_id
    ORDER BY i.id DESC
");
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Invoices</title>
    <?php include('partials/head.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
html,
body.admin-dashboard-page {
    margin: 0 !important;
    padding: 0 !important;
    width: 100%;
    min-height: 100%;
    overflow-x: hidden !important;
    background: #f4f7fb !important;
}

/* =====================================================
   ADMIN INVOICES PAGE FIX
   Same fixed sidebar/topbar as admin dashboard
===================================================== */

body.admin-dashboard-page {
    --admin-sidebar-open-width: 280px;
    --admin-sidebar-close-width: 88px;
    --admin-current-sidebar-width: var(--admin-sidebar-open-width);
}

body.admin-dashboard-page.sidebar-collapsed,
body.admin-dashboard-page.dashboard-sidebar-collapsed {
    --admin-current-sidebar-width: var(--admin-sidebar-close-width);
}

body.admin-dashboard-page .container-scroll {
    display: flex !important;
    width: 100% !important;
    min-height: 100vh !important;
    align-items: stretch !important;
    margin: 0 !important;
    padding: 0 !important;
    background: #f4f7fb !important;
}

body.admin-dashboard-page .sidebar,
body.admin-dashboard-page #dashboardSidebar {
    width: var(--admin-current-sidebar-width) !important;
    min-width: var(--admin-current-sidebar-width) !important;
    max-width: var(--admin-current-sidebar-width) !important;
    flex-shrink: 0 !important;
    display: flex !important;
    align-self: stretch !important;
    background: transparent !important;
    overflow: visible !important;
    position: fixed !important;
    left: 0 !important;
    top: 0 !important;
    height: 100vh !important;
    min-height: 100vh !important;
    z-index: 1000 !important;
    transition: width .25s ease, min-width .25s ease, max-width .25s ease !important;
}

body.admin-dashboard-page .sidebar > div,
body.admin-dashboard-page #dashboardSidebar > div {
    width: 100% !important;
    min-height: 100vh !important;
    height: 100vh !important;
}

body.admin-dashboard-page #dashboardSidebar .sidebar-wrap {
    width: var(--admin-current-sidebar-width) !important;
    min-width: var(--admin-current-sidebar-width) !important;
    max-width: var(--admin-current-sidebar-width) !important;
    height: 100vh !important;
    min-height: 100vh !important;
    position: fixed !important;
    left: 0 !important;
    top: 0 !important;
    z-index: 1000 !important;
    overflow: hidden !important;
    border-radius: 0 !important;
    transition: width .25s ease, min-width .25s ease, max-width .25s ease !important;
}

body.admin-dashboard-page #dashboardSidebar .sidebar-inner {
    width: 100% !important;
    height: 100vh !important;
    min-height: 100vh !important;
}

body.admin-dashboard-page #dashboardSidebar .sidebar-menu {
    overflow-y: auto !important;
    overflow-x: hidden !important;
}

body.admin-dashboard-page .main-panel {
    margin-left: var(--admin-current-sidebar-width) !important;
    width: calc(100% - var(--admin-current-sidebar-width)) !important;
    min-width: 0 !important;
    min-height: 100vh !important;
    padding: 70px 0 0 0 !important;
    display: block !important;
    background: #f4f7fb !important;
    transition: margin-left .25s ease, width .25s ease !important;
}

body.admin-dashboard-page .navbar.my-navbar.fixed-top {
    left: var(--admin-current-sidebar-width) !important;
    width: calc(100% - var(--admin-current-sidebar-width)) !important;
    height: 70px !important;
    min-height: 70px !important;
    margin: 0 !important;
    z-index: 999 !important;
    transition: left .25s ease, width .25s ease !important;
}

body.admin-dashboard-page .content-wrapper {
    width: 100% !important;
    margin: 0 !important;
    padding: 24px !important;
    min-height: calc(100vh - 70px) !important;
    overflow: visible !important;
    position: relative !important;
    background: #f4f7fb !important;
}

body.admin-dashboard-page .table-responsive {
    width: 100%;
    overflow-x: auto;
    background: #fff;
    border-radius: 12px;
}

body.admin-dashboard-page table.table {
    background: #fff;
    margin-bottom: 0;
}

body.admin-dashboard-page .table th,
body.admin-dashboard-page .table td {
    vertical-align: middle;
    white-space: nowrap;
}

body.admin-dashboard-page .modal-header {
    background: linear-gradient(135deg, #000, #e4ac00);
    color: #fff;
}

body.admin-dashboard-page .modal {
    z-index: 5000 !important;
}

body.admin-dashboard-page .modal-backdrop {
    z-index: 4990 !important;
}

/* Footer */
body.admin-dashboard-page .footer,
body.admin-dashboard-page footer {
    margin: 0 !important;
    padding: 14px 24px !important;
    background: #f4f7fb !important;
}

/* Collapsed sidebar text hide */
body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .sidebar-brand-text,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .sidebar-brand-text,
body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .search-box,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .search-box,
body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .menu-title,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .menu-title,
body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .menu-arrow,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .menu-arrow {
    display: none !important;
}

body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .submenu-collapse,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .submenu-collapse {
    display: none !important;
}

body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .menu-link,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .menu-link {
    justify-content: center !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
}

body.admin-dashboard-page.sidebar-collapsed #dashboardSidebar .menu-main,
body.admin-dashboard-page.dashboard-sidebar-collapsed #dashboardSidebar .menu-main {
    justify-content: center !important;
    gap: 0 !important;
}

/* Mobile */
@media (max-width: 991.98px) {
    body.admin-dashboard-page {
        --admin-current-sidebar-width: 0px !important;
    }

    body.admin-dashboard-page .container-scroll {
        display: block !important;
        width: 100% !important;
        min-height: 100vh !important;
    }

    body.admin-dashboard-page .sidebar,
    body.admin-dashboard-page #dashboardSidebar {
        position: fixed !important;
        top: 0 !important;
        left: -280px !important;
        width: 280px !important;
        min-width: 280px !important;
        max-width: 280px !important;
        height: 100dvh !important;
        min-height: 100dvh !important;
        margin: 0 !important;
        padding: 0 !important;
        z-index: 3000 !important;
        overflow: hidden !important;
        transition: left .25s ease !important;
    }

    body.admin-dashboard-page .sidebar > div,
    body.admin-dashboard-page #dashboardSidebar > div,
    body.admin-dashboard-page #dashboardSidebar .sidebar-wrap,
    body.admin-dashboard-page #dashboardSidebar .sidebar-inner {
        position: relative !important;
        width: 280px !important;
        min-width: 280px !important;
        max-width: 280px !important;
        height: 100dvh !important;
        min-height: 100dvh !important;
        max-height: 100dvh !important;
        margin: 0 !important;
        overflow: hidden !important;
        border-radius: 0 !important;
    }

    body.admin-dashboard-page #dashboardSidebar .sidebar-menu {
        height: calc(100dvh - 120px) !important;
        max-height: calc(100dvh - 120px) !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
    }

    body.admin-dashboard-page.sidebar-open #dashboardSidebar,
    body.admin-dashboard-page.dashboard-mobile-sidebar-open #dashboardSidebar {
        left: 0 !important;
    }

    body.admin-dashboard-page .main-panel {
        margin-left: 0 !important;
        width: 100% !important;
        min-width: 0 !important;
        padding-top: 70px !important;
    }

    body.admin-dashboard-page .navbar.my-navbar.fixed-top {
        left: 0 !important;
        width: 100% !important;
        height: 70px !important;
        min-height: 70px !important;
    }

    body.admin-dashboard-page .content-wrapper {
        padding: 16px !important;
        min-height: calc(100vh - 70px) !important;
    }

    body.admin-dashboard-page .d-flex.justify-content-between.mb-3 {
        gap: 12px;
        flex-wrap: wrap;
        align-items: center;
    }

    body.admin-dashboard-page .footer,
    body.admin-dashboard-page footer {
        padding: 12px 16px !important;
    }
}

/* =====================================================
   INVOICES.PHP - DARK PURPLE GLASS THEME
   Paste at END before 
===================================================== */

html,
body.admin-invoices-page{
    margin:0 !important;
    padding:0 !important;
    min-height:100% !important;
    overflow-x:hidden !important;
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.32), transparent 28%),
        radial-gradient(circle at 82% 4%, rgba(59,130,246,.14), transparent 30%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 70%,#070b14 100%) !important;
    color:#f8fafc !important;
}

/* Main background */
body.admin-invoices-page .container-scroll,
body.admin-invoices-page .main-panel,
body.admin-invoices-page .content-wrapper{
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.30), transparent 28%),
        radial-gradient(circle at 82% 4%, rgba(59,130,246,.14), transparent 30%),
        radial-gradient(circle at 92% 86%, rgba(34,197,94,.07), transparent 26%),
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 70%,#070b14 100%) !important;
    color:#f8fafc !important;
}

body.admin-invoices-page .content-wrapper{
    padding:26px 28px !important;
    min-height:calc(100vh - 70px) !important;
    position:relative !important;
}

body.admin-invoices-page .content-wrapper::before{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    background:
        linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px),
        linear-gradient(180deg, rgba(255,255,255,.03) 1px, transparent 1px);
    background-size:44px 44px;
    opacity:.25;
    z-index:0;
}

body.admin-invoices-page .content-wrapper > *{
    position:relative !important;
    z-index:1 !important;
}

/* Topbar */
body.admin-invoices-page .navbar.my-navbar.fixed-top,
body.admin-invoices-page .topbar{
    background:#070b14 !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    box-shadow:0 10px 30px rgba(0,0,0,.28) !important;
}

/* Title */
body.admin-invoices-page h1,
body.admin-invoices-page h2,
body.admin-invoices-page h3,
body.admin-invoices-page h4,
body.admin-invoices-page .page-title{
    color:#ffffff !important;
    font-weight:900 !important;
    letter-spacing:-.03em !important;
    text-shadow:0 0 28px rgba(124,58,237,.36) !important;
}

body.admin-invoices-page p,
body.admin-invoices-page small,
body.admin-invoices-page .text-muted{
    color:#cbd5e1 !important;
}

/* Cards / panels */
body.admin-invoices-page .card,
body.admin-invoices-page .page-card,
body.admin-invoices-page .filter-card,
body.admin-invoices-page .table-card,
body.admin-invoices-page .invoice-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.16), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.76), rgba(17,24,39,.56)) !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:18px !important;
    color:#f8fafc !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.30),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
    overflow:hidden !important;
}

/* Header card */
body.admin-invoices-page .page-card-head,
body.admin-invoices-page .card-header{
    background:rgba(7,11,20,.45) !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    color:#f8fafc !important;
}

/* Form controls */
body.admin-invoices-page .form-control,
body.admin-invoices-page .form-select,
body.admin-invoices-page input,
body.admin-invoices-page select,
body.admin-invoices-page textarea{
    min-height:46px !important;
    border-radius:13px !important;
    background:rgba(15,23,42,.78) !important;
    color:#f8fafc !important;
    border:1px solid rgba(148,163,184,.22) !important;
    box-shadow:
        0 8px 22px rgba(0,0,0,.18),
        inset 0 1px 0 rgba(255,255,255,.04) !important;
    outline:none !important;
    font-weight:800 !important;
}

body.admin-invoices-page input::placeholder,
body.admin-invoices-page textarea::placeholder{
    color:#94a3b8 !important;
}

body.admin-invoices-page .form-control:focus,
body.admin-invoices-page .form-select:focus,
body.admin-invoices-page input:focus,
body.admin-invoices-page select:focus,
body.admin-invoices-page textarea:focus{
    background:rgba(17,24,39,.94) !important;
    color:#fff !important;
    border-color:rgba(124,58,237,.62) !important;
    box-shadow:
        0 0 0 3px rgba(124,58,237,.16),
        0 12px 26px rgba(0,0,0,.24) !important;
}

body.admin-invoices-page select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

body.admin-invoices-page label,
body.admin-invoices-page .form-label{
    color:#e5e7eb !important;
    font-weight:900 !important;
}

/* Buttons */
body.admin-invoices-page .btn-primary,
body.admin-invoices-page .btn-success,
body.admin-invoices-page .btn-warning,
body.admin-invoices-page .add-invoice-btn,
body.admin-invoices-page button[type="submit"]{
    min-height:44px !important;
    border:none !important;
    border-radius:13px !important;
    padding:0 18px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    font-weight:900 !important;
    box-shadow:
        0 14px 28px rgba(109,76,255,.30),
        0 0 24px rgba(124,58,237,.18) !important;
    transition:.22s ease !important;
}

body.admin-invoices-page .btn-secondary,
body.admin-invoices-page .btn-dark,
body.admin-invoices-page .btn-light{
    min-height:42px !important;
    border-radius:12px !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    font-weight:900 !important;
}

body.admin-invoices-page .btn-danger{
    min-height:42px !important;
    border:none !important;
    border-radius:12px !important;
    background:linear-gradient(135deg,#dc2626,#ef4444) !important;
    color:#fff !important;
    font-weight:900 !important;
}

body.admin-invoices-page .btn:hover,
body.admin-invoices-page button:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

/* Table wrapper */
body.admin-invoices-page .table-responsive,
body.admin-invoices-page .table-wrap{
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.18), transparent 35%),
        linear-gradient(135deg, rgba(12,18,35,.88), rgba(20,12,40,.78)) !important;
    border:1px solid rgba(139,92,246,.22) !important;
    border-radius:18px !important;
    overflow:auto !important;
    box-shadow:
        0 18px 45px rgba(0,0,0,.28),
        inset 0 1px 0 rgba(255,255,255,.05) !important;
    backdrop-filter:blur(16px) saturate(140%) !important;
    -webkit-backdrop-filter:blur(16px) saturate(140%) !important;
}

/* Table */
body.admin-invoices-page table,
body.admin-invoices-page .table,
body.admin-invoices-page .erp-table{
    width:100% !important;
    margin:0 !important;
    border-collapse:separate !important;
    border-spacing:0 !important;
    background:transparent !important;
    color:#e5e7eb !important;

    --bs-table-bg:transparent !important;
    --bs-table-striped-bg:rgba(124,58,237,.06) !important;
    --bs-table-hover-bg:rgba(124,58,237,.12) !important;
    --bs-table-color:#e5e7eb !important;
    --bs-table-border-color:rgba(148,163,184,.10) !important;
}

body.admin-invoices-page table > :not(caption) > * > *,
body.admin-invoices-page .table > :not(caption) > * > *{
    background:transparent !important;
    color:#e5e7eb !important;
    border-color:rgba(148,163,184,.10) !important;
    box-shadow:none !important;
}

body.admin-invoices-page table thead th,
body.admin-invoices-page .table thead th,
body.admin-invoices-page table thead.table-dark th{
    background:rgba(6,10,20,.96) !important;
    color:#ffffff !important;
    border:none !important;
    border-bottom:1px solid rgba(139,92,246,.28) !important;
    padding:16px 18px !important;
    font-size:13px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
    letter-spacing:.05em !important;
    white-space:nowrap !important;
}

body.admin-invoices-page table tbody td,
body.admin-invoices-page .table tbody td{
    background:
        linear-gradient(135deg, rgba(15,23,42,.52), rgba(30,20,58,.28)) !important;
    color:#e5e7eb !important;
    border:none !important;
    border-bottom:1px solid rgba(148,163,184,.08) !important;
    padding:16px 18px !important;
    font-size:14px !important;
    font-weight:800 !important;
    vertical-align:middle !important;
    white-space:nowrap !important;
}

body.admin-invoices-page table tbody tr:nth-child(even) td{
    background:
        linear-gradient(135deg, rgba(30,20,58,.48), rgba(15,23,42,.32)) !important;
}

/* White hover remove */
body.admin-invoices-page table tbody tr:hover td,
body.admin-invoices-page .table-hover tbody tr:hover td,
body.admin-invoices-page table tbody tr:focus-within td{
    background:
        radial-gradient(circle at 15% 0%, rgba(124,58,237,.22), transparent 35%),
        linear-gradient(135deg, rgba(40,25,75,.72), rgba(15,23,42,.52)) !important;
    color:#ffffff !important;
}

/* Empty row */
body.admin-invoices-page table tbody td[colspan]{
    text-align:center !important;
    color:#cbd5e1 !important;
    background:
        linear-gradient(135deg, rgba(15,23,42,.40), rgba(24,15,45,.24)) !important;
}

/* Status badges */
body.admin-invoices-page .badge,
body.admin-invoices-page .status-badge{
    border-radius:999px !important;
    padding:7px 14px !important;
    font-size:12px !important;
    font-weight:900 !important;
}

body.admin-invoices-page .bg-success,
body.admin-invoices-page .badge-success,
body.admin-invoices-page .paid{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border:1px solid rgba(34,197,94,.28) !important;
}

body.admin-invoices-page .bg-warning,
body.admin-invoices-page .badge-warning,
body.admin-invoices-page .pending{
    background:rgba(245,158,11,.16) !important;
    color:#fbbf24 !important;
    border:1px solid rgba(245,158,11,.28) !important;
}

body.admin-invoices-page .bg-danger,
body.admin-invoices-page .badge-danger,
body.admin-invoices-page .overdue{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border:1px solid rgba(239,68,68,.28) !important;
}

/* Action icons */
body.admin-invoices-page .editInvoice,
body.admin-invoices-page .deleteInvoice,
body.admin-invoices-page .printInvoice,
body.admin-invoices-page .viewInvoice,
body.admin-invoices-page .action-btn{
    width:38px !important;
    height:38px !important;
    border-radius:12px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    border:1px solid rgba(148,163,184,.18) !important;
    background:rgba(15,23,42,.64) !important;
    color:#e5e7eb !important;
    transition:.22s ease !important;
    box-shadow:0 10px 20px rgba(0,0,0,.20) !important;
    margin:0 3px !important;
}

body.admin-invoices-page .editInvoice{
    background:rgba(245,158,11,.16) !important;
    color:#fbbf24 !important;
    border-color:rgba(245,158,11,.26) !important;
}

body.admin-invoices-page .deleteInvoice{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.26) !important;
}

body.admin-invoices-page .printInvoice,
body.admin-invoices-page .viewInvoice{
    background:rgba(124,58,237,.16) !important;
    color:#c4b5fd !important;
    border-color:rgba(124,58,237,.26) !important;
}

body.admin-invoices-page .editInvoice:hover,
body.admin-invoices-page .deleteInvoice:hover,
body.admin-invoices-page .printInvoice:hover,
body.admin-invoices-page .viewInvoice:hover,
body.admin-invoices-page .action-btn:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
}

/* Modal dark glass */
body.admin-invoices-page .modal{
    z-index:5000 !important;
}

body.admin-invoices-page .modal-backdrop.show{
    opacity:.80 !important;
    background:#000 !important;
    backdrop-filter:blur(8px) !important;
    -webkit-backdrop-filter:blur(8px) !important;
}

body.admin-invoices-page .modal-dialog{
    max-width:760px !important;
    width:calc(100% - 34px) !important;
    margin:18px auto !important;
}

body.admin-invoices-page .modal-content{
    max-height:calc(100vh - 36px) !important;
    display:flex !important;
    flex-direction:column !important;
    overflow:hidden !important;
    background:
        radial-gradient(circle at 18% 0%, rgba(124,58,237,.28), transparent 38%),
        radial-gradient(circle at 90% 0%, rgba(59,130,246,.10), transparent 32%),
        linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.86)) !important;
    border:1px solid rgba(139,92,246,.42) !important;
    border-radius:20px !important;
    color:#f8fafc !important;
    box-shadow:
        0 28px 80px rgba(0,0,0,.68),
        0 0 55px rgba(124,58,237,.22),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
    backdrop-filter:blur(24px) saturate(150%) !important;
    -webkit-backdrop-filter:blur(24px) saturate(150%) !important;
}

body.admin-invoices-page .modal-header{
    flex:0 0 auto !important;
    padding:15px 20px !important;
    background:
        linear-gradient(135deg, rgba(30,20,58,.78), rgba(7,11,20,.55)) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
}

body.admin-invoices-page .modal-title{
    color:#ffffff !important;
    font-size:22px !important;
    font-weight:950 !important;
    letter-spacing:-.02em !important;
    text-shadow:0 0 24px rgba(124,58,237,.35) !important;
}

body.admin-invoices-page .modal-body{
    flex:1 1 auto !important;
    min-height:0 !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    padding:16px 20px 12px !important;
    background:rgba(15,23,42,.12) !important;
}

body.admin-invoices-page .modal-footer{
    flex:0 0 auto !important;
    position:sticky !important;
    bottom:0 !important;
    z-index:20 !important;
    padding:12px 20px !important;
    background:
        linear-gradient(180deg, rgba(7,11,20,.88), rgba(7,11,20,.98)) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    box-shadow:0 -18px 35px rgba(0,0,0,.35) !important;
}

body.admin-invoices-page .btn-close{
    width:40px !important;
    height:40px !important;
    border-radius:13px !important;
    background-color:rgba(148,163,184,.16) !important;
    filter:invert(1) grayscale(1) !important;
    opacity:1 !important;
    box-shadow:0 10px 24px rgba(0,0,0,.25) !important;
}

/* Scrollbar */
body.admin-invoices-page .modal-body::-webkit-scrollbar,
body.admin-invoices-page .table-responsive::-webkit-scrollbar,
body.admin-invoices-page .table-wrap::-webkit-scrollbar{
    width:10px !important;
    height:10px !important;
}

body.admin-invoices-page .modal-body::-webkit-scrollbar-track,
body.admin-invoices-page .table-responsive::-webkit-scrollbar-track,
body.admin-invoices-page .table-wrap::-webkit-scrollbar-track{
    background:rgba(15,23,42,.75) !important;
    border-radius:999px !important;
}

body.admin-invoices-page .modal-body::-webkit-scrollbar-thumb,
body.admin-invoices-page .table-responsive::-webkit-scrollbar-thumb,
body.admin-invoices-page .table-wrap::-webkit-scrollbar-thumb{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    border-radius:999px !important;
}

/* Mobile */
@media(max-width:768px){
    body.admin-invoices-page .content-wrapper{
        padding:16px !important;
    }

    body.admin-invoices-page table{
        min-width:900px !important;
    }

    body.admin-invoices-page .modal-dialog{
        width:calc(100% - 18px) !important;
        margin:9px auto !important;
    }

    body.admin-invoices-page .modal-footer{
        flex-direction:column !important;
    }

    body.admin-invoices-page .modal-footer .btn,
    body.admin-invoices-page .modal-footer button{
        width:100% !important;
    }
}
    </style>
</head>

<body class="admin-dashboard-page admin-invoices-page">
  <div class="container-scroll">

    <div class="sidebar" id="dashboardSidebar">
        <?php include('partials/sidebar.php'); ?>
    </div>

    <div class="main-panel">
        <?php include('partials/topbar.php'); ?>

        <div class="content-wrapper">

                <div class="d-flex justify-content-between mb-3">
                    <h4>Client Invoices</h4>
                    <i class="bi bi-plus-circle text-primary" style="cursor:pointer;font-size:22px"
                        onclick="openAdd()"></i>
                </div>

                <table class="table table-bordered table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Client</th>
                            <th>Plan</th>
                            <th>Amount</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($invoices->num_rows==0): ?>
                        <tr>
                            <td colspan="7" class="text-center">No invoices</td>
                        </tr>
                        <?php endif; ?>

                        <?php while($row=$invoices->fetch_assoc()): ?>
                        <tr>
                            <td><?= $row['id'] ?></td>
                            <td><?= htmlspecialchars($row['business_name']) ?></td>
                            <td><?= htmlspecialchars($row['plan_name']) ?></td>
                            <td><?= number_format($row['amount'],2) ?></td>
                            <td><?= $row['due_date'] ?></td>
                            <td>
                                <span
                                    class="badge bg-<?= $row['status']=='paid'?'success':($row['status']=='unpaid'?'secondary':'danger') ?>">
                                    <?= ucfirst($row['status']) ?>
                                </span>
                            </td>
                            <td>
                                <i class="bi bi-eye text-primary me-2 viewInvoice" data-id="<?= $row['id'] ?>"></i>
                                <i class="bi bi-pencil-square text-warning edit" data-id="<?= $row['id'] ?>"
                                    data-client="<?= $row['client_id'] ?>" data-sub="<?= $row['subscription_id'] ?>"
                                    data-amount="<?= $row['amount'] ?>" data-due="<?= $row['due_date'] ?>"
                                    data-status="<?= $row['status'] ?>"></i>

                                <i class="bi bi-trash text-danger ms-2 delete" data-id="<?= $row['id'] ?>"></i>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>

            </div>

            <?php include('partials/footer.php'); ?>
        </div>
    </div>

    <!-- MODAL -->
    <div class="modal fade" id="invoiceModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="invoiceForm">
                    <input type="hidden" name="invoice_id" id="invoice_id">

                    <div class="modal-header">
                        <h5 id="modalTitle">Add Invoice</h5>
                        <button class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">

                        <select name="client_id" id="client_id" class="form-control mb-2" onchange="updateSub()"
                            required>
                            <option value="">Select Client</option>
                            <?php $clients->data_seek(0); while($c=$clients->fetch_assoc()): ?>
                            <option value="<?= $c['client_id'] ?>" data-sub="<?= $c['subscription_id'] ?>"
                                data-amount="<?= $c['price'] ?>">
                                <?= htmlspecialchars($c['business_name']) ?> (<?= htmlspecialchars($c['plan_name']) ?>)
                            </option>
                            <?php endwhile; ?>
                        </select>

                        <input type="text" name="subscription_id" id="subscription_id" class="form-control mb-2"
                            readonly>
                        <input type="number" step="0.01" name="amount" id="amount" class="form-control mb-2" required>
                        <input type="date" name="due_date" id="due_date" class="form-control mb-2" required>

                        <select name="status" id="status" class="form-control">
                            <option value="unpaid">Unpaid</option>
                            <option value="paid">Paid</option>
                            <option value="overdue">Overdue</option>
                        </select>

                    </div>

                    <div class="modal-footer">
                        <button class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                        <button class="btn btn-warning">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        
function handleAjaxSession(response) {
    if (response.status === 401) {
        window.location.href = '/onerp/app/onerp/app/public/app/views/login_view.php';
        return null;
    }

    return response.text();
}
    const modal = new bootstrap.Modal(document.getElementById('invoiceModal'));

    function openAdd() {
        invoiceForm.reset();
        invoice_id.value = "";
        subscription_id.value = "";
        modal.show();
    }

    function updateSub() {
        let o = client_id.options[client_id.selectedIndex];
        subscription_id.value = o.dataset.sub || "";
        amount.value = o.dataset.amount || "";
    }

    document.querySelectorAll('.edit').forEach(e => {
        e.onclick = () => {
            invoice_id.value = e.dataset.id;
            client_id.value = e.dataset.client;
            updateSub();
            amount.value = e.dataset.amount;
            due_date.value = e.dataset.due;
            status.value = e.dataset.status;
            modal.show();
        }
    });

    document.querySelectorAll('.delete').forEach(d => {
        d.onclick = () => {
            if (confirm("Delete invoice?")) {
                fetch("invoices.php", {
                    method: "POST",
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: "ajax=delete_invoice&id=" + d.dataset.id
                }).then(() => location.reload());
            }
        }
    });

    invoiceForm.onsubmit = e => {
        e.preventDefault();
        let fd = new FormData(invoiceForm);
        fd.append("ajax", "save_invoice");
        fetch("invoices.php", {
                method: "POST",
                body: fd
            })
            .then(r => r.text())
            .then(r => {
                if (r.trim() == "ok") location.reload();
                else alert(r);
            });
    };
    document.querySelectorAll('.viewInvoice').forEach(v => {
    v.onclick = () => {
        window.open(
            "invoice-view.php?id=" + v.dataset.id,
            "_blank",
            "width=900,height=700"
        );
    }
});

    </script>

</body>

</html>