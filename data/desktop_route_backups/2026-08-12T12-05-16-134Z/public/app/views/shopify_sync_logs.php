<?php
session_start();

require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/app/helpers/includes/integration_helper.php');

if (empty($_SESSION['user_id'])) {
    header('Location: /public/app/views/login_view.php');
    exit;
}

$clientId = (int)($_SESSION['client_id'] ?? 0);

requirePlanFeature(
    $conn,
    $clientId,
    'shopify_sync_logs'
);

$logs = [];

if (oneerpTableExists($conn, 'shopify_sync_logs')) {
    $stmt = $conn->prepare("
        SELECT
            id,
            item_id,
            action,
            status,
            message,
            created_at
        FROM shopify_sync_logs
        WHERE client_id = ?
        ORDER BY id DESC
        LIMIT 250
    ");

    if ($stmt) {
        $stmt->bind_param(
            'i',
            $clientId
        );

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $logs[] = $row;
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| UI summary only
|--------------------------------------------------------------------------
| Existing database query and Shopify workflow remain unchanged.
|--------------------------------------------------------------------------
*/
$totalLogs = count($logs);
$successLogs = 0;
$failedLogs = 0;
$pendingLogs = 0;
$latestLogAt = null;

foreach ($logs as $log) {
    $status = strtolower(
        trim(
            (string)($log['status'] ?? '')
        )
    );

    if (in_array(
        $status,
        [
            'success',
            'successful',
            'completed',
            'synced',
            'processed',
            'done'
        ],
        true
    )) {
        $successLogs++;
    } elseif (in_array(
        $status,
        [
            'failed',
            'failure',
            'error',
            'rejected'
        ],
        true
    )) {
        $failedLogs++;
    } else {
        $pendingLogs++;
    }

    $createdAt = trim(
        (string)($log['created_at'] ?? '')
    );

    if (
        $createdAt !== '' &&
        (
            $latestLogAt === null ||
            strtotime($createdAt) > strtotime($latestLogAt)
        )
    ) {
        $latestLogAt = $createdAt;
    }
}

function shopifyLogStatusMeta($status): array
{
    $status = strtolower(
        trim(
            (string)$status
        )
    );

    if (in_array(
        $status,
        [
            'success',
            'successful',
            'completed',
            'synced',
            'processed',
            'done'
        ],
        true
    )) {
        return [
            'label' => $status !== ''
                ? ucwords(
                    str_replace(
                        ['_', '-'],
                        ' ',
                        $status
                    )
                )
                : 'Success',
            'class' => 'log-success',
            'icon' => 'bi-check-circle-fill',
            'group' => 'success',
        ];
    }

    if (in_array(
        $status,
        [
            'failed',
            'failure',
            'error',
            'rejected'
        ],
        true
    )) {
        return [
            'label' => $status !== ''
                ? ucwords(
                    str_replace(
                        ['_', '-'],
                        ' ',
                        $status
                    )
                )
                : 'Failed',
            'class' => 'log-failed',
            'icon' => 'bi-exclamation-triangle-fill',
            'group' => 'failed',
        ];
    }

    if (in_array(
        $status,
        [
            'processing',
            'syncing',
            'running'
        ],
        true
    )) {
        return [
            'label' => ucwords(
                str_replace(
                    ['_', '-'],
                    ' ',
                    $status
                )
            ),
            'class' => 'log-processing',
            'icon' => 'bi-arrow-repeat',
            'group' => 'pending',
        ];
    }

    if (in_array(
        $status,
        [
            'pending',
            'queued',
            'waiting'
        ],
        true
    )) {
        return [
            'label' => ucwords(
                str_replace(
                    ['_', '-'],
                    ' ',
                    $status
                )
            ),
            'class' => 'log-pending',
            'icon' => 'bi-clock-fill',
            'group' => 'pending',
        ];
    }

    return [
        'label' => $status !== ''
            ? ucwords(
                str_replace(
                    ['_', '-'],
                    ' ',
                    $status
                )
            )
            : 'Unknown',
        'class' => 'log-neutral',
        'icon' => 'bi-info-circle-fill',
        'group' => 'pending',
    ];
}

function shopifyLogActionLabel($action): string
{
    $action = trim(
        (string)$action
    );

    if ($action === '') {
        return 'Unknown Action';
    }

    return ucwords(
        str_replace(
            ['_', '-'],
            ' ',
            $action
        )
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Shopify Logs - ONEERP</title>

    <?php include('partials/head.php'); ?>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <style>
        /* =====================================================
           SHOPIFY SYNC LOGS PAGE
           UI only. Existing backend/query remains unchanged.
        ===================================================== */

        body.shopify-logs-page{
            min-height:100vh;
            overflow-x:hidden;
            background:
                radial-gradient(
                    circle at 10% 5%,
                    rgba(124,58,237,.24),
                    transparent 30%
                ),
                radial-gradient(
                    circle at 88% 4%,
                    rgba(34,197,94,.10),
                    transparent 28%
                ),
                linear-gradient(
                    135deg,
                    #050914 0%,
                    #0b1024 46%,
                    #160f2d 100%
                ) !important;
            color:#e5e7eb;
        }

        body.shopify-logs-page .main-panel{
            min-height:100vh;
            background:transparent !important;
        }

        body.shopify-logs-page .content-wrapper{
            position:relative;
            min-height:100vh;
            padding:
                94px
                24px
                28px !important;
            background:
                radial-gradient(
                    circle at 14% 4%,
                    rgba(124,58,237,.18),
                    transparent 30%
                ),
                radial-gradient(
                    circle at 85% 8%,
                    rgba(34,197,94,.08),
                    transparent 28%
                ),
                linear-gradient(
                    135deg,
                    #050914 0%,
                    #0b1024 48%,
                    #160f2d 100%
                ) !important;
        }

        body.shopify-logs-page .content-wrapper::before{
            content:"";
            position:absolute;
            inset:0;
            pointer-events:none;
            background:
                linear-gradient(
                    90deg,
                    rgba(255,255,255,.025) 1px,
                    transparent 1px
                ),
                linear-gradient(
                    180deg,
                    rgba(255,255,255,.02) 1px,
                    transparent 1px
                );
            background-size:44px 44px;
            opacity:.28;
        }

        body.shopify-logs-page .content-wrapper > *{
            position:relative;
            z-index:1;
        }

        /* =====================================================
           PAGE HEADER
        ===================================================== */

        .shopify-log-header{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:18px;
            flex-wrap:wrap;
            margin-bottom:20px;
            padding:19px 20px;
            border:1px solid rgba(139,92,246,.24);
            border-radius:18px;
            background:
                radial-gradient(
                    circle at 12% 0%,
                    rgba(124,58,237,.24),
                    transparent 38%
                ),
                linear-gradient(
                    135deg,
                    rgba(15,23,42,.82),
                    rgba(17,24,39,.62)
                );
            box-shadow:
                0 18px 48px rgba(0,0,0,.28),
                inset 0 1px 0 rgba(255,255,255,.05);
            backdrop-filter:blur(18px);
            -webkit-backdrop-filter:blur(18px);
        }

        .shopify-log-title-group{
            display:flex;
            align-items:center;
            gap:14px;
            min-width:0;
        }

        .shopify-log-title-icon{
            width:52px;
            min-width:52px;
            height:52px;
            display:flex;
            align-items:center;
            justify-content:center;
            border-radius:15px;
            color:#ffffff;
            font-size:24px;
            background:
                linear-gradient(
                    135deg,
                    #008060,
                    #00a47c
                );
            box-shadow:
                0 14px 28px rgba(0,128,96,.28),
                inset 0 1px 0 rgba(255,255,255,.18);
        }

        .shopify-log-header h3{
            margin:0;
            color:#f8fafc;
            font-size:25px;
            font-weight:900;
            letter-spacing:-.03em;
        }

        .shopify-log-subtitle{
            margin-top:4px;
            color:#94a3b8;
            font-size:13px;
            font-weight:700;
        }

        .latest-log-badge{
            display:inline-flex;
            align-items:center;
            gap:8px;
            min-height:38px;
            padding:0 13px;
            border-radius:999px;
            color:#c4b5fd;
            background:rgba(124,58,237,.12);
            border:1px solid rgba(124,58,237,.26);
            font-size:12px;
            font-weight:900;
            white-space:nowrap;
        }

        /* =====================================================
           SUMMARY CARDS
        ===================================================== */

        .shopify-log-summary{
            display:grid;
            grid-template-columns:
                repeat(4, minmax(0,1fr));
            gap:14px;
            margin-bottom:18px;
        }

        .shopify-log-summary-card{
            position:relative;
            overflow:hidden;
            min-height:126px;
            padding:18px;
            border-radius:18px;
            border:1px solid rgba(148,163,184,.16);
            background:
                linear-gradient(
                    135deg,
                    rgba(15,23,42,.82),
                    rgba(30,20,58,.64)
                );
            box-shadow:
                0 16px 38px rgba(0,0,0,.25),
                inset 0 1px 0 rgba(255,255,255,.05);
            backdrop-filter:blur(16px);
            -webkit-backdrop-filter:blur(16px);
        }

        .shopify-log-summary-card::after{
            content:"";
            position:absolute;
            right:-25px;
            top:-25px;
            width:100px;
            height:100px;
            border-radius:50%;
            background:rgba(255,255,255,.07);
        }

        .log-summary-top{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            margin-bottom:14px;
        }

        .log-summary-label{
            color:rgba(255,255,255,.88);
            font-size:12px;
            font-weight:900;
            text-transform:uppercase;
            letter-spacing:.05em;
        }

        .log-summary-icon{
            width:38px;
            height:38px;
            display:flex;
            align-items:center;
            justify-content:center;
            border-radius:12px;
            font-size:17px;
            background:rgba(255,255,255,.09);
        }

        .log-summary-value{
            color:#ffffff;
            font-size:29px;
            line-height:1;
            font-weight:950;
            letter-spacing:-.03em;
        }

        .log-summary-note{
            margin-top:8px;
            color:rgba(255,255,255,.82);
            font-size:11px;
            font-weight:700;
        }

        .log-summary-total{
            background:
                linear-gradient(
                    135deg,
                    #3b82f6,
                    #6366f1
                );
        }

        .log-summary-success{
            background:
                linear-gradient(
                    135deg,
                    #059669,
                    #10b981
                );
        }

        .log-summary-pending{
            background:
                linear-gradient(
                    135deg,
                    #7c3aed,
                    #8b5cf6
                );
        }

        .log-summary-failed{
            background:
                linear-gradient(
                    135deg,
                    #be185d,
                    #ef4444
                );
        }

        /* =====================================================
           FILTER TOOLBAR
        ===================================================== */

        .shopify-log-toolbar{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            flex-wrap:wrap;
            margin-bottom:14px;
            padding:13px;
            border-radius:16px;
            border:1px solid rgba(148,163,184,.16);
            background:
                linear-gradient(
                    135deg,
                    rgba(15,23,42,.80),
                    rgba(17,24,39,.58)
                );
            box-shadow:
                0 14px 34px rgba(0,0,0,.22),
                inset 0 1px 0 rgba(255,255,255,.04);
            backdrop-filter:blur(16px);
            -webkit-backdrop-filter:blur(16px);
        }

        .shopify-log-search-wrap{
            position:relative;
            flex:1 1 360px;
            min-width:220px;
        }

        .shopify-log-search-wrap i{
            position:absolute;
            left:14px;
            top:50%;
            transform:translateY(-50%);
            color:#94a3b8;
            pointer-events:none;
        }

        .shopify-log-search,
        .shopify-log-filter{
            min-height:44px;
            border-radius:12px !important;
            background:rgba(15,23,42,.78) !important;
            color:#f8fafc !important;
            border:1px solid rgba(148,163,184,.18) !important;
            box-shadow:
                inset 0 1px 0 rgba(255,255,255,.03) !important;
            font-size:13px;
            font-weight:800;
        }

        .shopify-log-search{
            padding-left:41px !important;
        }

        .shopify-log-search::placeholder{
            color:#64748b;
        }

        .shopify-log-search:focus,
        .shopify-log-filter:focus{
            border-color:rgba(124,58,237,.62) !important;
            box-shadow:
                0 0 0 3px rgba(124,58,237,.15) !important;
        }

        .shopify-log-filter{
            width:190px;
            max-width:100%;
        }

        .shopify-log-filter option{
            background:#0f172a;
            color:#f8fafc;
        }

        .shopify-log-visible-count{
            display:flex;
            align-items:center;
            gap:7px;
            min-height:38px;
            padding:0 11px;
            border-radius:10px;
            color:#cbd5e1;
            background:rgba(124,58,237,.10);
            border:1px solid rgba(124,58,237,.18);
            font-size:12px;
            font-weight:900;
            white-space:nowrap;
        }

        /* =====================================================
           LOG TABLE PANEL
        ===================================================== */

        .shopify-log-panel{
            overflow:hidden;
            border-radius:19px;
            border:1px solid rgba(139,92,246,.22);
            background:
                radial-gradient(
                    circle at 10% 0%,
                    rgba(124,58,237,.12),
                    transparent 34%
                ),
                linear-gradient(
                    135deg,
                    rgba(12,18,35,.90),
                    rgba(20,12,40,.78)
                );
            box-shadow:
                0 22px 58px rgba(0,0,0,.32),
                inset 0 1px 0 rgba(255,255,255,.05);
            backdrop-filter:blur(18px);
            -webkit-backdrop-filter:blur(18px);
        }

        .shopify-log-panel-head{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            flex-wrap:wrap;
            padding:16px 18px;
            border-bottom:1px solid rgba(148,163,184,.12);
            background:rgba(7,11,20,.38);
        }

        .shopify-log-panel-title{
            display:flex;
            align-items:center;
            gap:10px;
            color:#f8fafc;
            font-size:15px;
            font-weight:900;
        }

        .shopify-log-panel-note{
            color:#94a3b8;
            font-size:11px;
            font-weight:800;
        }

        .shopify-log-table-wrap{
            width:100%;
            overflow:auto;
        }

        .shopify-log-table{
            width:100%;
            min-width:1050px;
            margin:0 !important;
            border-collapse:separate;
            border-spacing:0;
            color:#e5e7eb;
            --bs-table-bg:transparent;
            --bs-table-color:#e5e7eb;
            --bs-table-border-color:rgba(148,163,184,.10);
        }

        .shopify-log-table thead th{
            position:sticky;
            top:0;
            z-index:2;
            padding:14px 16px;
            background:rgba(6,10,20,.96) !important;
            color:#cbd5e1 !important;
            border:none !important;
            border-bottom:1px solid rgba(139,92,246,.22) !important;
            font-size:11px;
            font-weight:950;
            text-transform:uppercase;
            letter-spacing:.06em;
            white-space:nowrap;
        }

        .shopify-log-table tbody td{
            padding:13px 16px;
            color:#dbe4f0 !important;
            background:rgba(15,23,42,.26) !important;
            border:none !important;
            border-bottom:1px solid rgba(148,163,184,.08) !important;
            font-size:13px;
            font-weight:700;
            vertical-align:middle;
        }

        .shopify-log-table tbody tr{
            transition:.18s ease;
        }

        .shopify-log-table tbody tr:hover td{
            background:rgba(124,58,237,.10) !important;
            color:#ffffff !important;
        }

        .log-id-badge{
            display:inline-flex;
            align-items:center;
            min-height:28px;
            padding:0 9px;
            border-radius:9px;
            color:#c4b5fd;
            background:rgba(124,58,237,.12);
            border:1px solid rgba(124,58,237,.20);
            font-size:11px;
            font-weight:900;
        }

        .log-item-badge{
            display:inline-flex;
            align-items:center;
            gap:6px;
            min-height:29px;
            padding:0 9px;
            border-radius:9px;
            color:#bae6fd;
            background:rgba(14,165,233,.10);
            border:1px solid rgba(14,165,233,.18);
            font-size:11px;
            font-weight:900;
            white-space:nowrap;
        }

        .log-item-empty{
            color:#64748b;
            font-size:11px;
            font-weight:800;
        }

        .log-action-badge{
            display:inline-flex;
            align-items:center;
            gap:7px;
            min-height:31px;
            padding:0 10px;
            border-radius:10px;
            color:#d8b4fe;
            background:rgba(168,85,247,.11);
            border:1px solid rgba(168,85,247,.19);
            font-size:11px;
            font-weight:900;
            white-space:nowrap;
        }

        .log-status-badge{
            display:inline-flex;
            align-items:center;
            gap:7px;
            min-height:31px;
            padding:0 10px;
            border-radius:999px;
            font-size:11px;
            font-weight:950;
            white-space:nowrap;
        }

        .log-success{
            color:#86efac;
            background:rgba(34,197,94,.13);
            border:1px solid rgba(34,197,94,.28);
        }

        .log-pending{
            color:#fcd34d;
            background:rgba(245,158,11,.12);
            border:1px solid rgba(245,158,11,.27);
        }

        .log-processing{
            color:#c4b5fd;
            background:rgba(124,58,237,.14);
            border:1px solid rgba(124,58,237,.28);
        }

        .log-processing i{
            animation:shopifyLogSpin 1.1s linear infinite;
        }

        .log-failed{
            color:#fca5a5;
            background:rgba(239,68,68,.13);
            border:1px solid rgba(239,68,68,.28);
        }

        .log-neutral{
            color:#cbd5e1;
            background:rgba(100,116,139,.12);
            border:1px solid rgba(100,116,139,.22);
        }

        @keyframes shopifyLogSpin{
            to{
                transform:rotate(360deg);
            }
        }

        .log-message{
            display:block;
            max-width:420px;
            overflow:hidden;
            text-overflow:ellipsis;
            white-space:nowrap;
            color:#cbd5e1;
            font-size:12px;
            font-weight:700;
        }

        .log-message-empty{
            color:#64748b;
            font-style:italic;
        }

        .log-date{
            display:flex;
            flex-direction:column;
            gap:3px;
            white-space:nowrap;
        }

        .log-date-main{
            color:#f8fafc;
            font-size:12px;
            font-weight:900;
        }

        .log-date-time{
            color:#64748b;
            font-size:10px;
            font-weight:800;
        }

        /* =====================================================
           EMPTY STATE
        ===================================================== */

        .shopify-log-empty-state{
            padding:58px 22px !important;
            text-align:center;
        }

        .shopify-log-empty-icon{
            width:68px;
            height:68px;
            display:flex;
            align-items:center;
            justify-content:center;
            margin:0 auto 14px;
            border-radius:19px;
            color:#c4b5fd;
            background:rgba(124,58,237,.12);
            border:1px solid rgba(124,58,237,.22);
            font-size:29px;
        }

        .shopify-log-empty-title{
            color:#f8fafc;
            font-size:15px;
            font-weight:900;
        }

        .shopify-log-empty-text{
            margin-top:5px;
            color:#94a3b8;
            font-size:12px;
            font-weight:700;
        }

        .shopify-log-no-results{
            display:none;
            padding:38px 22px;
            text-align:center;
            color:#94a3b8;
            font-size:13px;
            font-weight:800;
        }

        /* =====================================================
           SCROLLBAR
        ===================================================== */

        .shopify-log-table-wrap::-webkit-scrollbar{
            width:9px;
            height:9px;
        }

        .shopify-log-table-wrap::-webkit-scrollbar-track{
            background:rgba(15,23,42,.72);
        }

        .shopify-log-table-wrap::-webkit-scrollbar-thumb{
            background:
                linear-gradient(
                    135deg,
                    #6d4cff,
                    #7c3aed
                );
            border-radius:999px;
        }

        /* =====================================================
           LIGHT MODE
        ===================================================== */

        html[data-pos-theme="light"]
        body.shopify-logs-page{
            background:
                radial-gradient(
                    circle at 12% 4%,
                    rgba(124,58,237,.09),
                    transparent 30%
                ),
                radial-gradient(
                    circle at 86% 6%,
                    rgba(34,197,94,.06),
                    transparent 28%
                ),
                linear-gradient(
                    135deg,
                    #f7f8fd,
                    #eef2f8
                ) !important;
            color:#0f172a;
        }

        html[data-pos-theme="light"]
        body.shopify-logs-page .content-wrapper{
            background:
                radial-gradient(
                    circle at 12% 4%,
                    rgba(124,58,237,.08),
                    transparent 30%
                ),
                radial-gradient(
                    circle at 86% 6%,
                    rgba(34,197,94,.05),
                    transparent 28%
                ),
                linear-gradient(
                    135deg,
                    #f7f8fd,
                    #eef2f8
                ) !important;
        }

        html[data-pos-theme="light"]
        body.shopify-logs-page .content-wrapper::before{
            background:
                linear-gradient(
                    90deg,
                    rgba(15,23,42,.025) 1px,
                    transparent 1px
                ),
                linear-gradient(
                    180deg,
                    rgba(15,23,42,.02) 1px,
                    transparent 1px
                );
        }

        html[data-pos-theme="light"] .shopify-log-header,
        html[data-pos-theme="light"] .shopify-log-toolbar,
        html[data-pos-theme="light"] .shopify-log-panel{
            background:
                linear-gradient(
                    135deg,
                    rgba(255,255,255,.98),
                    rgba(248,250,252,.94)
                ) !important;
            border-color:#dde3ee !important;
            box-shadow:
                0 16px 38px rgba(15,23,42,.08),
                inset 0 1px 0 #ffffff !important;
        }

        html[data-pos-theme="light"] .shopify-log-header h3,
        html[data-pos-theme="light"] .shopify-log-panel-title,
        html[data-pos-theme="light"] .shopify-log-empty-title,
        html[data-pos-theme="light"] .log-date-main{
            color:#0f172a !important;
        }

        html[data-pos-theme="light"] .shopify-log-subtitle,
        html[data-pos-theme="light"] .shopify-log-panel-note{
            color:#64748b;
        }

        html[data-pos-theme="light"] .shopify-log-search,
        html[data-pos-theme="light"] .shopify-log-filter{
            background:#ffffff !important;
            color:#0f172a !important;
            border-color:#dbe2ec !important;
        }

        html[data-pos-theme="light"] .shopify-log-search::placeholder{
            color:#94a3b8;
        }

        html[data-pos-theme="light"] .shopify-log-filter option{
            background:#ffffff;
            color:#0f172a;
        }

        html[data-pos-theme="light"] .shopify-log-visible-count{
            color:#5b21b6;
            background:#f3efff;
            border-color:#ddd6fe;
        }

        html[data-pos-theme="light"] .shopify-log-panel-head{
            background:#fbfcff;
            border-color:#e3e8f0;
        }

        html[data-pos-theme="light"] .shopify-log-table{
            color:#1e293b;
            --bs-table-color:#1e293b;
            --bs-table-border-color:#e6eaf0;
        }

        html[data-pos-theme="light"]
        .shopify-log-table thead th{
            background:#f1f4f9 !important;
            color:#475569 !important;
            border-bottom-color:#dce3ee !important;
        }

        html[data-pos-theme="light"]
        .shopify-log-table tbody td{
            background:#ffffff !important;
            color:#334155 !important;
            border-bottom-color:#e7ebf1 !important;
        }

        html[data-pos-theme="light"]
        .shopify-log-table tbody tr:hover td{
            background:#f5f3ff !important;
            color:#0f172a !important;
        }

        html[data-pos-theme="light"] .log-id-badge{
            color:#6d28d9;
            background:#f3efff;
            border-color:#ddd6fe;
        }

        html[data-pos-theme="light"] .log-item-badge{
            color:#0369a1;
            background:#f0f9ff;
            border-color:#bae6fd;
        }

        html[data-pos-theme="light"] .log-action-badge{
            color:#7e22ce;
            background:#faf5ff;
            border-color:#e9d5ff;
        }

        html[data-pos-theme="light"] .log-message{
            color:#475569;
        }

        html[data-pos-theme="light"] .log-date-time{
            color:#94a3b8;
        }

        html[data-pos-theme="light"]
        .shopify-log-table-wrap::-webkit-scrollbar-track{
            background:#eef2f7;
        }

        /* =====================================================
           RESPONSIVE
        ===================================================== */

        @media(max-width:1199.98px){
            .shopify-log-summary{
                grid-template-columns:
                    repeat(2, minmax(0,1fr));
            }
        }

        @media(max-width:767.98px){
            body.shopify-logs-page .content-wrapper{
                padding:
                    86px
                    14px
                    20px !important;
            }

            .shopify-log-header{
                padding:16px;
            }

            .shopify-log-title-icon{
                width:46px;
                min-width:46px;
                height:46px;
                font-size:21px;
            }

            .shopify-log-header h3{
                font-size:21px;
            }

            .shopify-log-summary{
                grid-template-columns:1fr;
            }

            .shopify-log-summary-card{
                min-height:112px;
            }

            .shopify-log-toolbar{
                align-items:stretch;
            }

            .shopify-log-search-wrap,
            .shopify-log-filter,
            .shopify-log-visible-count{
                width:100%;
                flex-basis:100%;
            }

            .shopify-log-visible-count{
                justify-content:center;
            }
        }
    </style>
</head>

<body class="shopify-logs-page">

<div class="container-scroll">

    <div
        class="sidebar"
        id="dashboardSidebar"
    >
        <?php include('partials/sidebar.php'); ?>
    </div>

    <div class="main-panel">

        <?php include('partials/topbar.php'); ?>

        <div class="content-wrapper">

            <div class="shopify-log-header">

                <div class="shopify-log-title-group">

                    <div class="shopify-log-title-icon">
                        <i class="bi bi-journal-check"></i>
                    </div>

                    <div>
                        <h3>
                            Shopify Sync Logs
                        </h3>

                        <div class="shopify-log-subtitle">
                            Review Shopify product, inventory and integration activity.
                        </div>
                    </div>

                </div>

                <div class="latest-log-badge">
                    <i class="bi bi-clock-history"></i>

                    Latest:
                    <?php if ($latestLogAt): ?>
                        <?= htmlspecialchars(
                            date(
                                'd M Y, h:i A',
                                strtotime($latestLogAt)
                            )
                        ) ?>
                    <?php else: ?>
                        No activity
                    <?php endif; ?>
                </div>

            </div>

            <div class="shopify-log-summary">

                <div class="shopify-log-summary-card log-summary-total">

                    <div class="log-summary-top">
                        <span class="log-summary-label">
                            Total Logs
                        </span>

                        <span class="log-summary-icon">
                            <i class="bi bi-list-ul"></i>
                        </span>
                    </div>

                    <div class="log-summary-value">
                        <?= number_format($totalLogs) ?>
                    </div>

                    <div class="log-summary-note">
                        Latest 250 records
                    </div>

                </div>

                <div class="shopify-log-summary-card log-summary-success">

                    <div class="log-summary-top">
                        <span class="log-summary-label">
                            Successful
                        </span>

                        <span class="log-summary-icon">
                            <i class="bi bi-check-circle"></i>
                        </span>
                    </div>

                    <div class="log-summary-value">
                        <?= number_format($successLogs) ?>
                    </div>

                    <div class="log-summary-note">
                        Completed operations
                    </div>

                </div>

                <div class="shopify-log-summary-card log-summary-pending">

                    <div class="log-summary-top">
                        <span class="log-summary-label">
                            Pending
                        </span>

                        <span class="log-summary-icon">
                            <i class="bi bi-clock-history"></i>
                        </span>
                    </div>

                    <div class="log-summary-value">
                        <?= number_format($pendingLogs) ?>
                    </div>

                    <div class="log-summary-note">
                        Waiting or processing
                    </div>

                </div>

                <div class="shopify-log-summary-card log-summary-failed">

                    <div class="log-summary-top">
                        <span class="log-summary-label">
                            Failed
                        </span>

                        <span class="log-summary-icon">
                            <i class="bi bi-exclamation-triangle"></i>
                        </span>
                    </div>

                    <div class="log-summary-value">
                        <?= number_format($failedLogs) ?>
                    </div>

                    <div class="log-summary-note">
                        Needs attention
                    </div>

                </div>

            </div>

            <div class="shopify-log-toolbar">

                <div class="shopify-log-search-wrap">
                    <i class="bi bi-search"></i>

                    <input
                        type="search"
                        id="shopifyLogSearch"
                        class="form-control shopify-log-search"
                        placeholder="Search item, action, status or message..."
                        autocomplete="off"
                    >
                </div>

                <select
                    id="shopifyLogStatusFilter"
                    class="form-select shopify-log-filter"
                >
                    <option value="">
                        All statuses
                    </option>

                    <option value="success">
                        Successful
                    </option>

                    <option value="pending">
                        Pending / Processing
                    </option>

                    <option value="failed">
                        Failed
                    </option>
                </select>

                <div class="shopify-log-visible-count">
                    <i class="bi bi-list-check"></i>

                    <span id="shopifyVisibleLogCount">
                        <?= number_format($totalLogs) ?>
                    </span>

                    logs visible
                </div>

            </div>

            <div class="shopify-log-panel">

                <div class="shopify-log-panel-head">

                    <div class="shopify-log-panel-title">
                        <i class="bi bi-activity"></i>
                        Integration Activity
                    </div>

                    <div class="shopify-log-panel-note">
                        Most recent records are shown first.
                    </div>

                </div>

                <div class="shopify-log-table-wrap">

                    <table
                        class="table align-middle shopify-log-table"
                        id="shopifyLogTable"
                    >

                        <thead>
                            <tr>
                                <th style="width:90px;">
                                    ID
                                </th>

                                <th style="width:130px;">
                                    Item
                                </th>

                                <th style="width:190px;">
                                    Action
                                </th>

                                <th style="width:160px;">
                                    Status
                                </th>

                                <th>
                                    Message
                                </th>

                                <th style="width:190px;">
                                    Date
                                </th>
                            </tr>
                        </thead>

                        <tbody id="shopifyLogTableBody">

                        <?php if (!$logs): ?>

                            <tr>
                                <td
                                    colspan="6"
                                    class="shopify-log-empty-state"
                                >
                                    <div class="shopify-log-empty-icon">
                                        <i class="bi bi-journal-x"></i>
                                    </div>

                                    <div class="shopify-log-empty-title">
                                        No Shopify sync logs yet
                                    </div>

                                    <div class="shopify-log-empty-text">
                                        Logs will appear here after Shopify product or inventory synchronization starts.
                                    </div>
                                </td>
                            </tr>

                        <?php endif; ?>

                        <?php foreach ($logs as $log): ?>

                            <?php
                                $statusMeta =
                                    shopifyLogStatusMeta(
                                        $log['status']
                                            ?? ''
                                    );

                                $logId =
                                    (int)($log['id'] ?? 0);

                                $itemId =
                                    trim(
                                        (string)(
                                            $log['item_id']
                                                ?? ''
                                        )
                                    );

                                $actionRaw =
                                    trim(
                                        (string)(
                                            $log['action']
                                                ?? ''
                                        )
                                    );

                                $actionLabel =
                                    shopifyLogActionLabel(
                                        $actionRaw
                                    );

                                $message =
                                    trim(
                                        (string)(
                                            $log['message']
                                                ?? ''
                                        )
                                    );

                                $createdAt =
                                    trim(
                                        (string)(
                                            $log['created_at']
                                                ?? ''
                                        )
                                    );

                                $searchText = strtolower(
                                    implode(
                                        ' ',
                                        [
                                            $logId,
                                            $itemId,
                                            $actionRaw,
                                            $actionLabel,
                                            $statusMeta['label'],
                                            $message,
                                            $createdAt,
                                        ]
                                    )
                                );
                            ?>

                            <tr
                                class="shopify-log-row"
                                data-search="<?= htmlspecialchars(
                                    $searchText,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                data-status="<?= htmlspecialchars(
                                    $statusMeta['group'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                            >

                                <td>
                                    <span class="log-id-badge">
                                        #<?= $logId ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if (
                                        $itemId !== '' &&
                                        $itemId !== '0'
                                    ): ?>

                                        <span class="log-item-badge">
                                            <i class="bi bi-box-seam"></i>
                                            #<?= htmlspecialchars($itemId) ?>
                                        </span>

                                    <?php else: ?>

                                        <span class="log-item-empty">
                                            No item
                                        </span>

                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="log-action-badge">
                                        <i class="bi bi-arrow-left-right"></i>

                                        <?= htmlspecialchars(
                                            $actionLabel
                                        ) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="log-status-badge <?= htmlspecialchars(
                                        $statusMeta['class']
                                    ) ?>">
                                        <i class="bi <?= htmlspecialchars(
                                            $statusMeta['icon']
                                        ) ?>"></i>

                                        <?= htmlspecialchars(
                                            $statusMeta['label']
                                        ) ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if ($message !== ''): ?>

                                        <span
                                            class="log-message"
                                            title="<?= htmlspecialchars(
                                                $message,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>"
                                        >
                                            <?= htmlspecialchars(
                                                $message
                                            ) ?>
                                        </span>

                                    <?php else: ?>

                                        <span class="log-message log-message-empty">
                                            No message available
                                        </span>

                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="log-date">

                                        <?php if ($createdAt !== ''): ?>

                                            <span class="log-date-main">
                                                <?= htmlspecialchars(
                                                    date(
                                                        'd M Y',
                                                        strtotime($createdAt)
                                                    )
                                                ) ?>
                                            </span>

                                            <span class="log-date-time">
                                                <?= htmlspecialchars(
                                                    date(
                                                        'h:i:s A',
                                                        strtotime($createdAt)
                                                    )
                                                ) ?>
                                            </span>

                                        <?php else: ?>

                                            <span class="log-date-main">
                                                -
                                            </span>

                                        <?php endif; ?>

                                    </div>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                    <?php if ($logs): ?>

                        <div
                            id="shopifyLogNoResults"
                            class="shopify-log-no-results"
                        >
                            <i class="bi bi-search me-2"></i>
                            No logs match the selected search or status.
                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
></script>

<script>
document.addEventListener(
    'DOMContentLoaded',
    function () {
        const searchInput =
            document.getElementById(
                'shopifyLogSearch'
            );

        const statusFilter =
            document.getElementById(
                'shopifyLogStatusFilter'
            );

        const rows = Array.from(
            document.querySelectorAll(
                '.shopify-log-row'
            )
        );

        const visibleCount =
            document.getElementById(
                'shopifyVisibleLogCount'
            );

        const noResults =
            document.getElementById(
                'shopifyLogNoResults'
            );

        function applyLogFilters() {
            const searchValue = String(
                searchInput?.value || ''
            )
                .trim()
                .toLowerCase();

            const statusValue = String(
                statusFilter?.value || ''
            )
                .trim()
                .toLowerCase();

            let visibleRows = 0;

            rows.forEach(function (row) {
                const rowSearch = String(
                    row.dataset.search || ''
                ).toLowerCase();

                const rowStatus = String(
                    row.dataset.status || ''
                ).toLowerCase();

                const matchesSearch =
                    searchValue === '' ||
                    rowSearch.includes(
                        searchValue
                    );

                const matchesStatus =
                    statusValue === '' ||
                    rowStatus === statusValue;

                const shouldShow =
                    matchesSearch &&
                    matchesStatus;

                row.hidden = !shouldShow;

                if (shouldShow) {
                    visibleRows++;
                }
            });

            if (visibleCount) {
                visibleCount.textContent =
                    visibleRows.toLocaleString();
            }

            if (noResults) {
                noResults.style.display =
                    visibleRows === 0
                        ? 'block'
                        : 'none';
            }
        }

        searchInput?.addEventListener(
            'input',
            applyLogFilters
        );

        statusFilter?.addEventListener(
            'change',
            applyLogFilters
        );
    }
);
</script>

</body>
</html>
