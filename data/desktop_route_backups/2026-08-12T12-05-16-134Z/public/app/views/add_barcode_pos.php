<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

// if (!isset($_SESSION['user_id'])) {
//     die("Login required");
// }

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$client_id = (int)($_SESSION['client_id'] ?? 0);
if ($client_id <= 0) {
    die("Invalid client session");
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* ================= BARCODE GENERATOR ================= */
class BarcodeGenerator
{
    public static function generatePNG($text, $filePath)
    {
        $barWidth = 2;
        $height = 60;
        $padding = 22;

        $patterns = [
            '0'=>'11011001100','1'=>'11001101100','2'=>'11001100110','3'=>'10010011000',
            '4'=>'10010001100','5'=>'10001001100','6'=>'10011001000','7'=>'10011000100',
            '8'=>'10001100100','9'=>'11001001000'
        ];

        $digits = str_split(preg_replace('/[^0-9]/', '', $text));
        if (empty($digits)) {
            $digits = str_split('1234567890');
        }

        $singlePatternWidth = strlen('11011001100') * $barWidth;
        $imgWidth = max(count($digits) * $singlePatternWidth, 260);

        $im = imagecreate($imgWidth, $height + $padding);
        $white = imagecolorallocate($im, 255, 255, 255);
        $black = imagecolorallocate($im, 0, 0, 0);

        imagefill($im, 0, 0, $white);

        $x = 10;
        foreach ($digits as $d) {
            $bits = $patterns[$d] ?? '11011001100';
            foreach (str_split($bits) as $b) {
                if ($b === '1') {
                    imagefilledrectangle($im, $x, 0, $x + $barWidth - 1, $height, $black);
                }
                $x += $barWidth;
            }
        }

        $font = 5;
        $textWidth = imagefontwidth($font) * strlen($text);
        imagestring($im, $font, max(($imgWidth - $textWidth) / 2, 5), $height + 2, $text, $black);

        imagepng($im, $filePath);
        imagedestroy($im);
    }
}

function barcode_dir(): string
{
    $dir = $_SERVER['DOCUMENT_ROOT'] . '/SaasPos/barcodes/';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

function barcode_url(string $image): string
{
    return '/SaasPos/barcodes/' . $image;
}

$message = '';
$messageType = 'success';

/* ================= HANDLE ACTIONS ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'generate') {
        $item_id = (int)($_POST['item_id'] ?? 0);
        $variant_id = (int)($_POST['variant_id'] ?? 0);
        $qty = max((int)($_POST['qty'] ?? 1), 1);
        $barcode_type = trim($_POST['barcode_type'] ?? 'Code128');
        $label_slug = trim($_POST['label_slug'] ?? '');

        if ($item_id <= 0) {
            $message = "Please select product.";
            $messageType = 'danger';
        } else {
            $stmt = $conn->prepare("
                SELECT i.name, v.size, v.color
                FROM items i
                LEFT JOIN item_variants v ON v.id = ?
                WHERE i.id = ? AND i.client_id = ?
                LIMIT 1
            ");
            $stmt->bind_param("iii", $variant_id, $item_id, $client_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            $stmt->close();

            if (!$row) {
                $message = "Item not found.";
                $messageType = 'danger';
            } else {
                $barcode_text = $client_id . date('His') . rand(1000, 9999);
                $barcode_image = 'barcode_' . $barcode_text . '.png';

                BarcodeGenerator::generatePNG($barcode_text, barcode_dir() . $barcode_image);

                $stmt = $conn->prepare("
                    INSERT INTO item_barcodes
                    (client_id, item_id, variant_id, barcode, barcode_image, qty, barcode_type, label_slug)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param(
                    "iiississ",
                    $client_id,
                    $item_id,
                    $variant_id,
                    $barcode_text,
                    $barcode_image,
                    $qty,
                    $barcode_type,
                    $label_slug
                );

                if ($stmt->execute()) {
                    $variant_text = $row['name'];
                    if (!empty($row['size']) || !empty($row['color'])) {
                        $variant_text .= ' - ' . trim(($row['size'] ?? '') . ' ' . ($row['color'] ?? ''));
                    }
                    $message = "Barcode generated successfully for " . $variant_text;
                    $messageType = 'success';
                } else {
                    $message = "Error generating barcode.";
                    $messageType = 'danger';
                }
                $stmt->close();
            }
        }
    }

    if ($action === 'delete_single') {
        $id = (int)($_POST['id'] ?? 0);

        $stmt = $conn->prepare("SELECT barcode_image FROM item_barcodes WHERE id = ? AND client_id = ?");
        $stmt->bind_param("ii", $id, $client_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        if ($row) {
            $file = barcode_dir() . $row['barcode_image'];
            if (file_exists($file)) {
                @unlink($file);
            }

            $stmt = $conn->prepare("DELETE FROM item_barcodes WHERE id = ? AND client_id = ?");
            $stmt->bind_param("ii", $id, $client_id);
            $stmt->execute();
            $stmt->close();

            $message = "Barcode deleted successfully.";
            $messageType = 'success';
        }
    }

    if ($action === 'delete_all') {
        $stmt = $conn->prepare("SELECT barcode_image FROM item_barcodes WHERE client_id = ?");
        $stmt->bind_param("i", $client_id);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $file = barcode_dir() . $row['barcode_image'];
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM item_barcodes WHERE client_id = ?");
        $stmt->bind_param("i", $client_id);
        $stmt->execute();
        $stmt->close();

        $message = "All barcodes deleted successfully.";
        $messageType = 'success';
    }
}

/* ================= FETCH ITEMS ================= */
$items = [];
$stmt = $conn->prepare("SELECT id, name FROM items WHERE client_id = ? ORDER BY name ASC");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $items[] = $r;
}
$stmt->close();

/* ================= FETCH VARIANTS ================= */
$variants = [];
$itemVariantsExists = $conn->query("SHOW TABLES LIKE 'item_variants'");
if ($itemVariantsExists && $itemVariantsExists->num_rows > 0) {
    $res = $conn->query("SELECT id, item_id, size, color FROM item_variants ORDER BY item_id ASC");
    while ($r = $res->fetch_assoc()) {
        $variants[$r['item_id']][] = $r;
    }
}

/* ================= PAGINATION VARIABLES ================= */
$perPage     = 6;
$currentPage = max(1, (int)($_GET['page'] ?? 1));

/* Total rows for this client */
$countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM item_barcodes WHERE client_id = ?");
$countStmt->bind_param("i", $client_id);
$countStmt->execute();
$countRes  = $countStmt->get_result();
$totalRows = (int)($countRes->fetch_assoc()['total'] ?? 0);
$countStmt->close();

$totalPages = $totalRows > 0 ? (int)ceil($totalRows / $perPage) : 1;

/* If after a delete the current page no longer exists, go to last valid page */
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}

$offset      = ($currentPage - 1) * $perPage;
$showingFrom = $totalRows > 0 ? $offset + 1 : 0;
$showingTo   = min($offset + $perPage, $totalRows);

/* ================= FETCH GENERATED BARCODES (paginated) ================= */
$barcodes = [];
$stmt = $conn->prepare("
    SELECT 
        b.id,
        b.barcode,
        b.barcode_image,
        b.qty,
        b.barcode_type,
        b.label_slug,
        b.created_at,
        i.name AS item_name,
        v.size,
        v.color
    FROM item_barcodes b
    INNER JOIN items i ON i.id = b.item_id
    LEFT JOIN item_variants v ON v.id = b.variant_id
    WHERE b.client_id = ?
    ORDER BY b.id DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param("iii", $client_id, $perPage, $offset);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $barcodes[] = $r;
}
$stmt->close();

/* Helper: build pagination URL preserving existing GET params except page */
function pagUrl(int $page): string
{
    $params = $_GET;
    $params['page'] = $page;
    unset($params['export']); // don't carry export flag into pagination links
    return '?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Generate Barcode</title>

<script>
(function () {
    const storageKey = 'oneerp_pos_theme';
    let selectedTheme = 'light';

    try {
        const savedTheme = localStorage.getItem(storageKey);
        if (savedTheme === 'light' || savedTheme === 'dark') {
            selectedTheme = savedTheme;
        }
    } catch (e) {
        selectedTheme = 'light';
    }

    document.documentElement.setAttribute('data-pos-theme', selectedTheme);
})();
</script>
<?php include('partials/head.php'); ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<style>
body{margin:0;background:#f3f4fb;font-family:"Segoe UI",Tahoma,Geneva,Verdana,sans-serif;}
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

.sidebar > .sidebar-wrap{
    width:100%;
    min-height:100%;
    height:100%;
    border-radius:0;
}

#dashboardSidebar .sidebar-inner{
    min-height:100%;
}

.main-panel{flex-grow:1;display:flex;flex-direction:column;}
.topbar{height:60px;border-bottom:1px solid #ddd;display:flex;align-items:center;padding:0 20px;background:#fff;}
.content-wrapper{padding:20px;flex-grow:1;overflow-y:auto;}
.page-card{background:#fff;border:1px solid #e6e8ef;border-radius:14px;padding:20px;margin-bottom:18px;box-shadow:0 3px 12px rgba(0,0,0,0.03);}
.page-title{font-size:24px;font-weight:700;color:#1f2937;margin-bottom:18px;}
.grid-top{display:grid;grid-template-columns:2fr 1fr 1fr 1.2fr;gap:12px;align-items:start;}
.input-box{position:relative;}
.input-box i{position:absolute;left:12px;top:13px;color:#6b7280;}
.input-box input,.input-box select{width:100%;height:44px;border:1px solid #dcdfea;border-radius:10px;padding:0 12px 0 38px;outline:none;background:#fff;}
.product-preview{margin-top:10px;height:54px;border:1px solid #dcdfea;border-radius:10px;display:flex;align-items:center;padding:0 14px;background:#f9fafc;font-weight:500;color:#374151;}
.quick-actions-title{font-size:18px;font-weight:700;margin-bottom:12px;color:#1f2937;}
.quick-actions-row{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;}
.quick-btns-left,.quick-btns-right{display:flex;gap:10px;flex-wrap:wrap;}
.quick-btn{height:42px;border:1px solid #dcdfea;border-radius:10px;background:#fff;padding:0 16px;font-weight:600;color:#374151;display:inline-flex;align-items:center;gap:8px;text-decoration:none;}
.main-generate-btn{height:50px;border:none;border-radius:12px;background:linear-gradient(180deg,#f5c21a 0%,#e3a80a 100%);padding:0 22px;font-size:15px;font-weight:700;color:#1f2937;display:inline-flex;align-items:center;gap:10px;}
.section-title{font-size:22px;font-weight:700;color:#1f2937;margin-bottom:14px;}
.generated-toolbar{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;border:1px solid #e6e8ef;border-radius:12px;padding:12px 14px;background:#fff;margin-bottom:14px;}
.generated-toolbar .toolbar-left,.generated-toolbar .toolbar-right{display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
.generated-toolbar a,.generated-toolbar button{border:none;background:none;font-weight:600;color:#374151;text-decoration:none;display:inline-flex;align-items:center;gap:8px;}
.generated-panel{background:#fff;border:1px solid #e6e8ef;border-radius:14px;overflow:hidden;}
.generated-panel-head{padding:14px 18px;border-bottom:1px solid #eceff5;font-size:18px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:10px;}
.barcode-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;padding:18px;}
.barcode-card{border:1px solid #e6e8ef;border-radius:12px;padding:14px;background:#fff;}
.barcode-name{font-size:16px;font-weight:700;color:#1f2937;line-height:1.3;margin-bottom:12px;}
.barcode-image-wrap{text-align:center;border-bottom:1px solid #edf0f5;padding-bottom:10px;margin-bottom:12px;}
.barcode-image{max-width:100%;height:78px;object-fit:contain;}
.barcode-number{font-size:14px;font-weight:700;color:#374151;margin-top:6px;}
.barcode-actions{display:flex;gap:10px;}
.barcode-btn{flex:1;height:44px;border-radius:10px;border:1px solid #dcdfea;background:#fff;font-weight:700;display:inline-flex;align-items:center;justify-content:center;gap:8px;text-decoration:none;color:#374151;cursor:pointer;}
.barcode-btn.primary{background:#3b82f6;color:#fff;border-color:#3b82f6;}
.alert-box{margin-bottom:15px;}

/* ---- Pagination ---- */
.pagination-footer{
    display:flex;
    flex-direction:column;
    align-items:center;
    gap:10px;
    padding:14px 18px 18px;
    border-top:1px solid #eceff5;
}
.pagination-summary{
    font-size:13px;
    color:#6b7280;
}
.pagination-bar{
    display:flex;
    gap:6px;
    flex-wrap:wrap;
    justify-content:center;
}
.pag-btn{
    min-width:36px;
    height:36px;
    border:1px solid #dcdfea;
    border-radius:8px;
    background:#fff;
    color:#374151;
    font-weight:600;
    font-size:13px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    text-decoration:none;
    padding:0 10px;
    transition:background .15s,color .15s,border-color .15s;
}
.pag-btn:hover{
    background:#f3f4fb;
    color:#1f2937;
    border-color:#b0b7c7;
}
.pag-btn.active{
    background:linear-gradient(180deg,#f5c21a 0%,#e3a80a 100%);
    color:#1f2937;
    border-color:#e3a80a;
}
.pag-btn.disabled{
    opacity:.45;
    pointer-events:none;
}

@media(max-width:1100px){
  .grid-top{grid-template-columns:1fr 1fr;}
  .barcode-grid{grid-template-columns:1fr 1fr;}
}
@media(max-width:768px){
  .grid-top{grid-template-columns:1fr;}
  .barcode-grid{grid-template-columns:1fr;}
}

/* =====================================================
   ADD_BARCODE_POS.PHP - DARK PURPLE BLACK GLASS THEME
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
    --oms-bg-2:#0a1020;
    --oms-panel:#0f172a;
    --oms-border:rgba(148,163,184,.16);
    --oms-border-2:rgba(148,163,184,.24);
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

/* ================= SIDEBAR COLLAPSE ================= */
body.dashboard-sidebar-collapsed .sidebar .sidebar-brand-text,
body.dashboard-sidebar-collapsed .sidebar .search-box,
body.dashboard-sidebar-collapsed .sidebar .menu-title,
body.dashboard-sidebar-collapsed .sidebar .menu-arrow,
body.dashboard-sidebar-collapsed .sidebar .submenu-collapse{
    display:none !important;
}

body.dashboard-sidebar-collapsed .sidebar .menu-link{
    justify-content:center !important;
    padding:11px 0 !important;
}

body.dashboard-sidebar-collapsed .sidebar .menu-main{
    justify-content:center !important;
    gap:0 !important;
}

body.dashboard-sidebar-collapsed .sidebar .menu-icon{
    margin:0 !important;
}

body.dashboard-sidebar-collapsed .sidebar .sidebar-inner{
    padding-left:8px !important;
    padding-right:8px !important;
}

body.dashboard-sidebar-collapsed .sidebar .new-customer-btn{
    width:52px !important;
    height:42px !important;
    margin:0 auto !important;
    padding:0 !important;
    border-radius:12px !important;
    gap:0 !important;
}

body.dashboard-sidebar-collapsed .sidebar .new-customer-btn span{
    display:none !important;
}

/* ================= GLASS CARDS ================= */
.page-card,
.generated-panel{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.68), rgba(17,24,39,.52)) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    backdrop-filter:blur(18px) saturate(135%) !important;
    -webkit-backdrop-filter:blur(18px) saturate(135%) !important;
    border-radius:18px !important;
    box-shadow:
        0 18px 48px rgba(0,0,0,.26),
        inset 0 1px 0 rgba(255,255,255,.045) !important;
    transition:.25s ease !important;
}

.page-card:hover,
.generated-panel:hover{
    border-color:rgba(139,92,246,.45) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 0 1px rgba(124,58,237,.16),
        0 0 42px rgba(124,58,237,.18),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.page-title,
.quick-actions-title,
.section-title,
.generated-panel-head{
    color:#f8fafc !important;
    font-weight:900 !important;
    letter-spacing:-.02em !important;
}

.page-title{
    font-size:26px !important;
    margin-bottom:18px !important;
}

.quick-actions-title{
    font-size:18px !important;
}

.generated-panel-head{
    background:rgba(7,11,20,.36) !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
    padding:16px 20px !important;
}

/* ================= FORM ================= */
.grid-top{
    display:grid !important;
    grid-template-columns:2fr 1fr 1fr 1.2fr !important;
    gap:12px !important;
    align-items:start !important;
}

.input-box{
    position:relative !important;
}

.input-box i{
    position:absolute !important;
    left:14px !important;
    top:50% !important;
    transform:translateY(-50%) !important;
    color:#a78bfa !important;
    z-index:2 !important;
}

.input-box input,
.input-box select{
    width:100% !important;
    height:46px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:13px !important;
    padding:0 14px 0 42px !important;
    outline:none !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    font-weight:800 !important;
    box-shadow:none !important;
    transition:.2s !important;
}

.input-box input::placeholder{
    color:#94a3b8 !important;
}

.input-box select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.input-box input:focus,
.input-box select:focus{
    background:#111827 !important;
    color:#fff !important;
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14) !important;
}

.product-preview{
    margin-top:10px !important;
    height:54px !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:13px !important;
    display:flex !important;
    align-items:center !important;
    padding:0 14px !important;
    background:rgba(15,23,42,.46) !important;
    font-weight:800 !important;
    color:#cbd5e1 !important;
}

/* ================= BUTTONS ================= */
.main-generate-btn{
    height:50px !important;
    border:none !important;
    border-radius:13px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    padding:0 22px !important;
    font-size:15px !important;
    font-weight:900 !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:10px !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
    transition:.22s ease !important;
}

.main-generate-btn:hover{
    transform:translateY(-2px) !important;
    background:linear-gradient(135deg,#7c3aed,#6d4cff) !important;
    color:#fff !important;
}

.quick-btn{
    height:42px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    background:rgba(15,23,42,.62) !important;
    padding:0 16px !important;
    font-weight:900 !important;
    color:#e5e7eb !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:8px !important;
    text-decoration:none !important;
    box-shadow:0 12px 24px rgba(0,0,0,.18) !important;
    transition:.2s ease !important;
}

.quick-btn:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
    background:rgba(124,58,237,.20) !important;
    border-color:rgba(139,92,246,.42) !important;
}

.quick-btns-left form{
    margin:0 !important;
}

/* ================= GENERATED BARCODES ================= */
.barcode-grid{
    display:grid !important;
    grid-template-columns:repeat(3,1fr) !important;
    gap:16px !important;
    padding:18px !important;
}

.barcode-card{
    background:
        radial-gradient(circle at 0% 0%, rgba(124,58,237,.12), transparent 36%),
        linear-gradient(135deg, rgba(15,23,42,.68), rgba(17,24,39,.48)) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:16px !important;
    padding:14px !important;
    box-shadow:0 14px 32px rgba(0,0,0,.22) !important;
    transition:.22s ease !important;
}

.barcode-card:hover{
    transform:translateY(-3px) !important;
    border-color:rgba(139,92,246,.42) !important;
    box-shadow:
        0 22px 55px rgba(0,0,0,.38),
        0 0 36px rgba(124,58,237,.14) !important;
}

.barcode-name{
    font-size:16px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    line-height:1.3 !important;
    margin-bottom:12px !important;
}

.barcode-image-wrap{
    text-align:center !important;
    border-bottom:1px solid rgba(148,163,184,.12) !important;
    padding:12px !important;
    margin-bottom:12px !important;
    background:rgba(255,255,255,.94) !important;
    border-radius:12px !important;
}

.barcode-image{
    max-width:100% !important;
    height:78px !important;
    object-fit:contain !important;
}

.barcode-number{
    font-size:14px !important;
    font-weight:900 !important;
    color:#111827 !important;
    margin-top:6px !important;
}

.barcode-actions{
    display:flex !important;
    gap:10px !important;
}

.barcode-btn{
    flex:1 !important;
    height:42px !important;
    border-radius:12px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    background:rgba(15,23,42,.62) !important;
    font-weight:900 !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:8px !important;
    text-decoration:none !important;
    color:#e5e7eb !important;
    cursor:pointer !important;
    transition:.2s ease !important;
}

.barcode-btn.primary{
    background:linear-gradient(135deg,#2563eb,#6d4cff) !important;
    color:#fff !important;
    border-color:rgba(139,92,246,.32) !important;
}

.barcode-btn:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
    border-color:rgba(139,92,246,.42) !important;
}

.barcode-qty{
    color:#94a3b8 !important;
    font-weight:800 !important;
}

/* Empty state */
.barcode-grid > div[style*="padding:20px"],
.generated-panel div[style*="No barcodes"]{
    color:#94a3b8 !important;
    font-weight:800 !important;
}

/* ================= ALERTS ================= */
.alert{
    border-radius:14px !important;
    font-weight:900 !important;
    backdrop-filter:blur(14px) !important;
    border:1px solid rgba(148,163,184,.18) !important;
}

.alert-success{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border-color:rgba(34,197,94,.26) !important;
}

.alert-danger{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.26) !important;
}

/* ================= PAGINATION ================= */
.pagination-footer{
    background:rgba(7,11,20,.30) !important;
    border-top:1px solid rgba(148,163,184,.14) !important;
}

.pagination-summary{
    color:#94a3b8 !important;
    font-weight:800 !important;
}

.pag-btn{
    background:rgba(15,23,42,.62) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    font-weight:900 !important;
}

.pag-btn:hover{
    background:rgba(124,58,237,.18) !important;
    color:#fff !important;
    border-color:rgba(139,92,246,.38) !important;
}

.pag-btn.active{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    border-color:rgba(139,92,246,.42) !important;
}

/* ================= RESPONSIVE ================= */
@media(max-width:1100px){
    .grid-top{
        grid-template-columns:1fr 1fr !important;
    }

    .barcode-grid{
        grid-template-columns:1fr 1fr !important;
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
}

@media(max-width:768px){
    .grid-top{
        grid-template-columns:1fr !important;
    }

    .barcode-grid{
        grid-template-columns:1fr !important;
    }

    .quick-actions-row,
    .quick-btns-left,
    .quick-btns-right{
        flex-direction:column !important;
        align-items:stretch !important;
        width:100% !important;
    }

    .quick-btn,
    .main-generate-btn{
        width:100% !important;
    }
}


/* =========================================================
   FINAL ADD BARCODE POS - CLEAN PROFESSIONAL UI OVERRIDE
   Backend / PHP / JS logic untouched
   Paste at the very END before 
========================================================= */

:root{
    --bc-bg:#070b14;
    --bc-panel:rgba(15,23,42,.72);
    --bc-panel-strong:rgba(15,23,42,.92);
    --bc-card:rgba(9,14,28,.82);
    --bc-border:rgba(148,163,184,.16);
    --bc-border-strong:rgba(139,92,246,.36);
    --bc-text:#f8fafc;
    --bc-muted:#9aa7bd;
    --bc-purple:#7c3aed;
    --bc-purple-2:#8b5cf6;
    --bc-purple-3:#5b21b6;
    --bc-green:#22c55e;
    --bc-red:#ef4444;
}

body{
    background:
        radial-gradient(circle at 16% 0%, rgba(124,58,237,.26), transparent 30%),
        radial-gradient(circle at 86% 10%, rgba(59,130,246,.13), transparent 28%),
        linear-gradient(180deg,#060a14 0%,#090f1f 58%,#070b14 100%) !important;
}

.content-wrapper{
    padding:24px 26px 32px !important;
}

/* Main cards: softer glass, less boxy */
.content-wrapper > .page-card,
.content-wrapper > .generated-panel{
    background:
        linear-gradient(135deg, rgba(15,23,42,.78), rgba(11,16,32,.64)) !important;
    border:1px solid rgba(148,163,184,.15) !important;
    border-radius:22px !important;
    box-shadow:0 22px 70px rgba(0,0,0,.30), inset 0 1px 0 rgba(255,255,255,.045) !important;
    backdrop-filter:blur(18px) !important;
    -webkit-backdrop-filter:blur(18px) !important;
}

.page-title{
    margin:0 0 20px !important;
    display:flex !important;
    align-items:center !important;
    gap:12px !important;
    color:#fff !important;
    font-size:28px !important;
    font-weight:950 !important;
    letter-spacing:-.03em !important;
}

.page-title::before{
    content:'▦' !important;
    width:38px !important;
    height:38px !important;
    border-radius:13px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    color:#c4b5fd !important;
    background:rgba(124,58,237,.16) !important;
    border:1px solid rgba(124,58,237,.32) !important;
    font-size:18px !important;
}

/* Generate form: clean command bar */
.grid-top{
    display:grid !important;
    grid-template-columns:minmax(280px,1.45fr) minmax(210px,.82fr) minmax(210px,.82fr) 230px !important;
    gap:12px !important;
    align-items:stretch !important;
}

.grid-top > div{
    min-width:0 !important;
}

.input-box{
    position:relative !important;
}

.input-box i{
    width:22px !important;
    height:22px !important;
    left:14px !important;
    top:50% !important;
    transform:translateY(-50%) !important;
    color:#a78bfa !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    z-index:4 !important;
}

.input-box input,
.input-box select{
    height:50px !important;
    width:100% !important;
    border-radius:15px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    background:rgba(7,13,28,.82) !important;
    color:#edf2ff !important;
    padding:0 14px 0 46px !important;
    font-size:14px !important;
    font-weight:850 !important;
    outline:none !important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.04) !important;
    transition:border-color .18s ease, box-shadow .18s ease, background .18s ease !important;
}

.input-box input::placeholder{
    color:#91a0b7 !important;
}

.input-box input:focus,
.input-box select:focus{
    background:rgba(12,19,38,.96) !important;
    border-color:rgba(139,92,246,.70) !important;
    box-shadow:0 0 0 4px rgba(124,58,237,.16), inset 0 1px 0 rgba(255,255,255,.05) !important;
}

.input-box select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.product-preview{
    height:58px !important;
    margin-top:10px !important;
    border-radius:15px !important;
    border:1px solid rgba(148,163,184,.14) !important;
    background:
        linear-gradient(135deg, rgba(124,58,237,.10), rgba(15,23,42,.62)) !important;
    color:#dbeafe !important;
    padding:0 16px !important;
    font-size:14px !important;
    font-weight:900 !important;
    display:flex !important;
    align-items:center !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
    white-space:nowrap !important;
}

.product-preview::before{
    content:'Selected:' !important;
    color:#a78bfa !important;
    font-size:12px !important;
    font-weight:950 !important;
    margin-right:8px !important;
    text-transform:uppercase !important;
    letter-spacing:.04em !important;
}

.grid-top > div:last-child{
    display:flex !important;
    align-items:flex-end !important;
    justify-content:stretch !important;
}

.grid-top > div:last-child .main-generate-btn,
button.main-generate-btn[style]{
    width:100% !important;
    height:58px !important;
    margin-top:0 !important;
}

.main-generate-btn{
    border:0 !important;
    border-radius:16px !important;
    background:linear-gradient(135deg,#9f67ff 0%,#7c3aed 58%,#5b21b6 100%) !important;
    color:#fff !important;
    font-weight:950 !important;
    letter-spacing:.01em !important;
    box-shadow:0 18px 34px rgba(124,58,237,.32), inset 0 1px 0 rgba(255,255,255,.14) !important;
}

.main-generate-btn:hover{
    transform:translateY(-2px) !important;
    box-shadow:0 22px 44px rgba(124,58,237,.42), inset 0 1px 0 rgba(255,255,255,.18) !important;
}

/* Quick actions: cleaner and compact */
.quick-actions-title{
    color:#f8fafc !important;
    font-size:18px !important;
    font-weight:950 !important;
    margin-bottom:14px !important;
}

.quick-actions-row{
    padding:2px 0 !important;
}

.quick-btn{
    height:44px !important;
    border-radius:14px !important;
    border:1px solid rgba(148,163,184,.16) !important;
    background:rgba(7,13,28,.70) !important;
    color:#dbe5f5 !important;
    padding:0 17px !important;
    font-weight:900 !important;
    box-shadow:none !important;
}

.quick-btn:hover{
    background:rgba(124,58,237,.18) !important;
    border-color:rgba(139,92,246,.42) !important;
    color:#fff !important;
}

.quick-btn i{
    color:#c4b5fd !important;
}

/* Generated section header */
.generated-panel{
    overflow:hidden !important;
}

.generated-panel-head{
    height:62px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:12px !important;
    padding:0 20px !important;
    background:rgba(7,11,20,.46) !important;
    border-bottom:1px solid rgba(148,163,184,.12) !important;
    color:#f8fafc !important;
    font-size:18px !important;
    font-weight:950 !important;
}

.generated-panel-head::after{
    content:'Preview labels before printing' !important;
    color:#94a3b8 !important;
    font-size:12px !important;
    font-weight:800 !important;
}

/* Barcode list: label preview cards, not heavy boxes */
.barcode-grid{
    display:grid !important;
    grid-template-columns:repeat(auto-fill,minmax(310px,1fr)) !important;
    gap:18px !important;
    padding:20px !important;
}

.barcode-card{
    position:relative !important;
    display:grid !important;
    grid-template-rows:auto auto auto auto !important;
    gap:12px !important;
    min-height:260px !important;
    padding:16px !important;
    border-radius:20px !important;
    background:
        radial-gradient(circle at 0% 0%, rgba(124,58,237,.14), transparent 38%),
        rgba(8,13,28,.86) !important;
    border:1px solid rgba(148,163,184,.14) !important;
    box-shadow:0 18px 42px rgba(0,0,0,.26), inset 0 1px 0 rgba(255,255,255,.035) !important;
    overflow:hidden !important;
}

.barcode-card::before{
    content:'Barcode Label' !important;
    position:absolute !important;
    top:14px !important;
    right:14px !important;
    height:24px !important;
    padding:0 10px !important;
    border-radius:999px !important;
    display:flex !important;
    align-items:center !important;
    background:rgba(124,58,237,.15) !important;
    color:#c4b5fd !important;
    border:1px solid rgba(124,58,237,.30) !important;
    font-size:10px !important;
    font-weight:950 !important;
    text-transform:uppercase !important;
    letter-spacing:.04em !important;
}

.barcode-card:hover{
    transform:translateY(-3px) !important;
    border-color:rgba(139,92,246,.48) !important;
    box-shadow:0 26px 60px rgba(0,0,0,.40), 0 0 34px rgba(124,58,237,.16) !important;
}

.barcode-name{
    padding-right:105px !important;
    color:#f8fafc !important;
    font-size:16px !important;
    font-weight:950 !important;
    line-height:1.25 !important;
    margin:0 !important;
    min-height:40px !important;
    display:-webkit-box !important;
    -webkit-line-clamp:2 !important;
    -webkit-box-orient:vertical !important;
    overflow:hidden !important;
}

/* White label preview */
.barcode-image-wrap{
    position:relative !important;
    min-height:118px !important;
    margin:0 !important;
    padding:14px 16px 12px !important;
    border-radius:16px !important;
    border:1px solid rgba(255,255,255,.90) !important;
    background:#ffffff !important;
    display:flex !important;
    flex-direction:column !important;
    align-items:center !important;
    justify-content:center !important;
    box-shadow:inset 0 0 0 1px rgba(15,23,42,.06), 0 14px 28px rgba(0,0,0,.22) !important;
}

.barcode-image-wrap::before,
.barcode-image-wrap::after{
    content:'' !important;
    position:absolute !important;
    top:50% !important;
    width:12px !important;
    height:26px !important;
    transform:translateY(-50%) !important;
    background:rgba(15,23,42,.08) !important;
    border-radius:999px !important;
}

.barcode-image-wrap::before{ left:-6px !important; }
.barcode-image-wrap::after{ right:-6px !important; }

.barcode-image{
    width:100% !important;
    max-width:260px !important;
    height:66px !important;
    object-fit:contain !important;
    display:block !important;
    filter:contrast(1.08) !important;
}

.barcode-number{
    margin-top:6px !important;
    color:#111827 !important;
    font-size:13px !important;
    font-weight:950 !important;
    letter-spacing:.06em !important;
    font-family:"Courier New", monospace !important;
    word-break:break-word !important;
}

.barcode-qty{
    margin:0 !important;
    display:inline-flex !important;
    width:max-content !important;
    max-width:100% !important;
    align-items:center !important;
    gap:6px !important;
    height:30px !important;
    padding:0 10px !important;
    border-radius:999px !important;
    background:rgba(15,23,42,.66) !important;
    border:1px solid rgba(148,163,184,.14) !important;
    color:#aab4c8 !important;
    font-size:12px !important;
    font-weight:900 !important;
}

.barcode-actions{
    display:grid !important;
    grid-template-columns:1fr 1fr !important;
    gap:10px !important;
}

.barcode-btn{
    height:42px !important;
    border-radius:13px !important;
    border:1px solid rgba(148,163,184,.16) !important;
    background:rgba(15,23,42,.64) !important;
    color:#e5edf8 !important;
    font-size:13px !important;
    font-weight:950 !important;
    box-shadow:none !important;
}

.barcode-btn.primary{
    background:linear-gradient(135deg,#3b82f6,#7c3aed) !important;
    border-color:rgba(139,92,246,.46) !important;
    color:#fff !important;
}

.barcode-btn:hover{
    transform:translateY(-2px) !important;
    color:#fff !important;
    border-color:rgba(139,92,246,.42) !important;
}

.barcode-card > div:last-child{
    margin-top:-2px !important;
}

.barcode-card > div:last-child .barcode-btn{
    width:100% !important;
    background:rgba(239,68,68,.08) !important;
    border-color:rgba(239,68,68,.22) !important;
    color:#fb7185 !important;
}

.barcode-card > div:last-child .barcode-btn:hover{
    background:rgba(239,68,68,.16) !important;
    border-color:rgba(239,68,68,.42) !important;
}

/* Empty state */
.barcode-grid > div[style*="padding:20px"]{
    grid-column:1/-1 !important;
    min-height:170px !important;
    padding:28px !important;
    border:1px dashed rgba(148,163,184,.22) !important;
    border-radius:18px !important;
    background:rgba(7,13,28,.50) !important;
    color:#94a3b8 !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    font-weight:900 !important;
}

.pagination-footer{
    background:rgba(7,11,20,.34) !important;
    border-top:1px solid rgba(148,163,184,.12) !important;
}

.pagination-summary{
    color:#9aa7bd !important;
    font-weight:850 !important;
}

.pag-btn{
    border-radius:11px !important;
    background:rgba(15,23,42,.72) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    color:#e5edf8 !important;
    font-weight:950 !important;
}

.pag-btn.active{
    background:linear-gradient(135deg,#8b5cf6,#7c3aed) !important;
    color:#fff !important;
    border-color:rgba(139,92,246,.60) !important;
}

/* Responsive */
@media(max-width:1280px){
    .grid-top{
        grid-template-columns:1.2fr 1fr 1fr !important;
    }
    .grid-top > div:last-child{
        grid-column:1/-1 !important;
    }
    .grid-top > div:last-child .main-generate-btn{
        max-width:260px !important;
        margin-left:auto !important;
    }
}

@media(max-width:900px){
    .content-wrapper{
        padding:16px !important;
    }
    .grid-top{
        grid-template-columns:1fr !important;
    }
    .grid-top > div:last-child .main-generate-btn{
        max-width:none !important;
        margin-left:0 !important;
    }
    .generated-panel-head{
        height:auto !important;
        min-height:62px !important;
        flex-direction:column !important;
        align-items:flex-start !important;
        justify-content:center !important;
        padding:14px 16px !important;
    }
    .barcode-grid{
        grid-template-columns:1fr !important;
        padding:14px !important;
    }
    .quick-actions-row,
    .quick-btns-left,
    .quick-btns-right{
        width:100% !important;
        align-items:stretch !important;
    }
    .quick-btn,
    .quick-btns-right .main-generate-btn{
        width:100% !important;
    }
}



/* =========================================================
   FINAL SMALL BARCODE PREVIEW CARDS
   Barcode preview chota, decent aur compact
   Backend / generate / print / download / delete logic untouched
========================================================= */
.generated-panel{
    overflow:hidden !important;
}

.generated-panel-head{
    height:52px !important;
    min-height:52px !important;
    padding:0 18px !important;
    font-size:16px !important;
}

.barcode-grid{
    grid-template-columns:repeat(auto-fill, minmax(240px, 1fr)) !important;
    gap:12px !important;
    padding:14px !important;
}

.barcode-card{
    min-height:unset !important;
    height:auto !important;
    padding:12px !important;
    border-radius:16px !important;
    gap:8px !important;
}

.barcode-card::before{
    height:20px !important;
    top:10px !important;
    right:10px !important;
    padding:0 8px !important;
    font-size:8px !important;
}

.barcode-name{
    min-height:28px !important;
    padding-right:82px !important;
    font-size:13px !important;
    line-height:1.15 !important;
    margin:0 !important;
    -webkit-line-clamp:2 !important;
}

.barcode-image-wrap{
    min-height:78px !important;
    height:78px !important;
    padding:8px 12px 7px !important;
    border-radius:12px !important;
    margin:0 !important;
}

.barcode-image-wrap::before,
.barcode-image-wrap::after{
    width:8px !important;
    height:18px !important;
}

.barcode-image-wrap::before{ left:-4px !important; }
.barcode-image-wrap::after{ right:-4px !important; }

.barcode-image{
    max-width:205px !important;
    width:100% !important;
    height:42px !important;
    object-fit:contain !important;
}

.barcode-number{
    margin-top:3px !important;
    font-size:10px !important;
    line-height:1 !important;
    letter-spacing:.04em !important;
}

.barcode-actions{
    gap:7px !important;
}

.barcode-btn{
    height:34px !important;
    min-height:34px !important;
    border-radius:10px !important;
    font-size:11px !important;
    padding:0 10px !important;
}

.barcode-qty{
    height:24px !important;
    min-height:24px !important;
    padding:0 8px !important;
    font-size:10px !important;
    margin-top:0 !important;
}

.barcode-card > div:last-child{
    margin-top:0 !important;
}

.barcode-card > div:last-child .barcode-btn{
    height:32px !important;
    min-height:32px !important;
}

@media(max-width:1280px){
    .barcode-grid{
        grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)) !important;
    }

    .barcode-image{
        max-width:190px !important;
        height:40px !important;
    }
}

@media(max-width:768px){
    .barcode-grid{
        grid-template-columns:1fr !important;
        gap:10px !important;
        padding:12px !important;
    }
}


/* =========================================================
   GENERATE BARCODE PAGE - LIGHT MODE
   Paste at the VERY END before
========================================================= */

html[data-pos-theme="light"]{
    color-scheme: light;
}

html[data-pos-theme="light"] body{
    background:#f4f7fb !important;
    color:#0f172a !important;
}

/* Layout */
html[data-pos-theme="light"] .main-panel{
    background:#f4f7fb !important;
}

html[data-pos-theme="light"] .topbar{
    background:#ffffff !important;
    border-bottom:1px solid #dbe3ef !important;
    box-shadow:0 6px 18px rgba(15,23,42,.06) !important;
}

html[data-pos-theme="light"] .content-wrapper{
    background:
        radial-gradient(circle at 12% 8%, rgba(124,58,237,.08), transparent 26%),
        radial-gradient(circle at 78% 2%, rgba(59,130,246,.05), transparent 28%),
        linear-gradient(135deg,#f8fafc 0%,#f1f5f9 52%,#f8fafc 100%) !important;
}

html[data-pos-theme="light"] .content-wrapper::before{
    background:
        linear-gradient(90deg, rgba(15,23,42,.025) 1px, transparent 1px),
        linear-gradient(180deg, rgba(15,23,42,.02) 1px, transparent 1px) !important;
    opacity:.55 !important;
}

html[data-pos-theme="light"] .content-wrapper::after{
    background:radial-gradient(circle, rgba(124,58,237,.08), transparent 68%) !important;
    opacity:.50 !important;
}

/* Main cards */
html[data-pos-theme="light"] .page-card,
html[data-pos-theme="light"] .generated-panel{
    background:#ffffff !important;
    border:1px solid #dce4ef !important;
    box-shadow:0 12px 30px rgba(15,23,42,.07) !important;
    backdrop-filter:none !important;
    -webkit-backdrop-filter:none !important;
}

html[data-pos-theme="light"] .page-card:hover,
html[data-pos-theme="light"] .generated-panel:hover{
    border-color:#c4b5fd !important;
    box-shadow:0 16px 36px rgba(15,23,42,.08) !important;
}

/* Titles */
html[data-pos-theme="light"] .page-title,
html[data-pos-theme="light"] .quick-actions-title,
html[data-pos-theme="light"] .section-title,
html[data-pos-theme="light"] .generated-panel-head{
    color:#0f172a !important;
    text-shadow:none !important;
}

html[data-pos-theme="light"] .page-title::before{
    background:#f3efff !important;
    color:#7c3aed !important;
    border:1px solid #c4b5fd !important;
}

/* Generated section heading */
html[data-pos-theme="light"] .generated-panel-head{
    background:#f8fafc !important;
    border-bottom:1px solid #e2e8f0 !important;
}

html[data-pos-theme="light"] .generated-panel-head::after{
    color:#64748b !important;
}

/* Inputs */
html[data-pos-theme="light"] .input-box i{
    color:#7c3aed !important;
}

html[data-pos-theme="light"] .input-box input,
html[data-pos-theme="light"] .input-box select{
    background:#ffffff !important;
    color:#0f172a !important;
    border:1px solid #cbd5e1 !important;
    box-shadow:none !important;
}

html[data-pos-theme="light"] .input-box input::placeholder{
    color:#64748b !important;
}

html[data-pos-theme="light"] .input-box select option{
    background:#ffffff !important;
    color:#0f172a !important;
}

html[data-pos-theme="light"] .input-box input:focus,
html[data-pos-theme="light"] .input-box select:focus{
    background:#ffffff !important;
    color:#0f172a !important;
    border-color:#7c3aed !important;
    box-shadow:0 0 0 3px rgba(124,58,237,.12) !important;
}

/* Selected product preview */
html[data-pos-theme="light"] .product-preview{
    background:#f8fafc !important;
    color:#334155 !important;
    border:1px solid #dce4ef !important;
}

html[data-pos-theme="light"] .product-preview::before{
    color:#7c3aed !important;
}

/* Buttons */
html[data-pos-theme="light"] .main-generate-btn{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    color:#ffffff !important;
    box-shadow:0 12px 26px rgba(124,58,237,.18) !important;
}

html[data-pos-theme="light"] .main-generate-btn:hover{
    color:#ffffff !important;
}

html[data-pos-theme="light"] .quick-btn{
    background:#ffffff !important;
    color:#334155 !important;
    border:1px solid #cbd5e1 !important;
    box-shadow:0 6px 16px rgba(15,23,42,.05) !important;
}

html[data-pos-theme="light"] .quick-btn i{
    color:#7c3aed !important;
}

html[data-pos-theme="light"] .quick-btn:hover{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;
}

/* Alert */
html[data-pos-theme="light"] .alert{
    border-radius:14px !important;
    font-weight:800 !important;
    backdrop-filter:none !important;
}

html[data-pos-theme="light"] .alert-success{
    background:#ecfdf5 !important;
    color:#047857 !important;
    border:1px solid #a7f3d0 !important;
}

html[data-pos-theme="light"] .alert-danger{
    background:#fff1f2 !important;
    color:#be123c !important;
    border:1px solid #fecdd3 !important;
}

/* Barcode cards */
html[data-pos-theme="light"] .barcode-card{
    background:#ffffff !important;
    border:1px solid #dce4ef !important;
    box-shadow:0 10px 24px rgba(15,23,42,.06) !important;
}

html[data-pos-theme="light"] .barcode-card::before{
    background:#f3efff !important;
    color:#7c3aed !important;
    border:1px solid #c4b5fd !important;
}

html[data-pos-theme="light"] .barcode-card:hover{
    border-color:#c4b5fd !important;
    box-shadow:0 14px 30px rgba(15,23,42,.08) !important;
}

html[data-pos-theme="light"] .barcode-name{
    color:#0f172a !important;
}

html[data-pos-theme="light"] .barcode-image-wrap{
    background:#ffffff !important;
    border:1px solid #e2e8f0 !important;
    box-shadow:0 8px 18px rgba(15,23,42,.06) !important;
}

html[data-pos-theme="light"] .barcode-image-wrap::before,
html[data-pos-theme="light"] .barcode-image-wrap::after{
    background:rgba(15,23,42,.08) !important;
}

html[data-pos-theme="light"] .barcode-number{
    color:#111827 !important;
}

html[data-pos-theme="light"] .barcode-qty{
    background:#f8fafc !important;
    color:#475569 !important;
    border:1px solid #dce4ef !important;
}

/* Barcode action buttons */
html[data-pos-theme="light"] .barcode-btn{
    background:#ffffff !important;
    color:#334155 !important;
    border:1px solid #cbd5e1 !important;
}

html[data-pos-theme="light"] .barcode-btn.primary{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    color:#ffffff !important;
    border-color:#7c3aed !important;
}

html[data-pos-theme="light"] .barcode-btn:hover{
    border-color:#c4b5fd !important;
    color:#5b21b6 !important;
}

html[data-pos-theme="light"] .barcode-btn.primary:hover{
    color:#ffffff !important;
}

/* Delete button row */
html[data-pos-theme="light"] .barcode-card > div:last-child .barcode-btn{
    background:#fff1f2 !important;
    color:#e11d48 !important;
    border-color:#fecdd3 !important;
}

html[data-pos-theme="light"] .barcode-card > div:last-child .barcode-btn:hover{
    background:#ffe4e6 !important;
    color:#be123c !important;
    border-color:#fda4af !important;
}

/* Empty state */
html[data-pos-theme="light"] .barcode-grid > div[style*="padding:20px"]{
    background:#ffffff !important;
    color:#64748b !important;
    border:1px dashed #cbd5e1 !important;
}

/* Pagination */
html[data-pos-theme="light"] .pagination-footer{
    background:#f8fafc !important;
    border-top:1px solid #e2e8f0 !important;
}

html[data-pos-theme="light"] .pagination-summary{
    color:#64748b !important;
}

html[data-pos-theme="light"] .pag-btn{
    background:#ffffff !important;
    color:#334155 !important;
    border:1px solid #cbd5e1 !important;
}

html[data-pos-theme="light"] .pag-btn:hover{
    background:#f3efff !important;
    color:#5b21b6 !important;
    border-color:#c4b5fd !important;
}

html[data-pos-theme="light"] .pag-btn.active{
    background:linear-gradient(135deg,#8b5cf6,#6d28d9) !important;
    color:#ffffff !important;
    border-color:#7c3aed !important;
}

</style>
<script>
function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
}

function buildPrintWindow(title, labelsHtml) {
    var w = window.open('', '_blank');
    if (!w) return;

    var html = `
    <html>
    <head>
        <title>${title}</title>
        <style>
            @page{
                size:A4 portrait;
                margin:8mm;
            }

            *{
                box-sizing:border-box;
            }

            html,body{
                margin:0;
                padding:0;
                background:#fff;
                font-family:Arial,sans-serif;
            }

            body{
                padding:0;
            }

            .sheet{
                width:100%;
                display:grid;
                grid-template-columns:repeat(3, 63mm);
                grid-auto-rows:32mm;
                column-gap:4mm;
                row-gap:4mm;
                justify-content:start;
                align-content:start;
            }

            .label{
    width:63mm;
    height:32mm;
    padding:2mm;
    overflow:hidden;
    display:flex;
    flex-direction:column;
    justify-content:flex-start;
    align-items:center;
}
            .item-title{
    width:100%;
    font-size:9px;
    font-weight:bold;
    line-height:1.2;
    margin-bottom:1mm;
    color:#000;
    text-align:center;
}

            .label img{
                width:100%;
                height:19mm;
                object-fit:contain;
                display:block;
                margin:0;
            }

            .barcode-text{
                width:100%;
                margin-top:1mm;
                font-size:10px;
                line-height:1.2;
                text-align:center;
                color:#000;
                word-break:break-word;
            }

            @media print{
                html,body{
                    width:210mm;
                }

                .sheet{
                    justify-content:start;
                    align-content:start;
                }
            }
            
            
        </style>
    </head>
    <body>
        <div class="sheet">${labelsHtml}</div>
    </body>
    </html>
    `;

    w.document.open();
    w.document.write(html);
    w.document.close();

    w.onload = function(){
        w.focus();
        w.print();
    };
}

function printBarcode(imgSrc, barcodeText, itemTitle, qty) {
    qty = parseInt(qty || 1, 10);
    if (!qty || qty < 1) qty = 1;

    var labelsHtml = '';

    for (var i = 0; i < qty; i++) {
        labelsHtml += `
            <div class="label">
                <div class="item-title">${escapeHtml(itemTitle || '')}</div>
                <img src="${imgSrc}" alt="barcode">
                <div class="barcode-text">${escapeHtml(barcodeText || '')}</div>
            </div>
        `;
    }

    buildPrintWindow('Print Barcode', labelsHtml);
}

function printAllBarcodes() {
    var cards = document.querySelectorAll('.barcode-card');
    if (!cards.length) return;

    var labelsHtml = '';

    cards.forEach(function(card){
        var img = card.querySelector('.barcode-image');
        var barcodeTextEl = card.querySelector('.barcode-number');
        var titleEl = card.querySelector('.barcode-name');
        var qtyEl = card.querySelector('.barcode-qty');

        var imgSrc = img ? img.src : '';
        var barcodeText = barcodeTextEl ? barcodeTextEl.textContent.trim() : '';
        var itemTitle = titleEl ? titleEl.textContent.trim() : '';
        var qty = qtyEl ? parseInt(qtyEl.getAttribute('data-qty'), 10) : 1;

        if (!qty || qty < 1) qty = 1;

        for (var i = 0; i < qty; i++) {
            labelsHtml += `
                <div class="label">
                    <div class="item-title">${escapeHtml(itemTitle)}</div>
                    <img src="${imgSrc}" alt="barcode">
                    <div class="barcode-text">${escapeHtml(barcodeText)}</div>
                </div>
            `;
        }
    });

    buildPrintWindow('Print All Barcodes', labelsHtml);
}

function loadVariants(itemId){
    var variants = <?php echo json_encode($variants); ?>;
    var select = document.getElementById('variantSelect');
    var preview = document.getElementById('productPreview');
    var productText = '';

    var productSelect = document.getElementById('itemSelect');
    if (productSelect.selectedIndex > 0) {
        productText = productSelect.options[productSelect.selectedIndex].text;
    }

    preview.textContent = productText || 'No product selected';

    select.innerHTML = '<option value="0">Quantity / Variant</option>';
    if(variants[itemId]){
        variants[itemId].forEach(function(v){
            var txt = ((v.size || '') + ' ' + (v.color || '')).trim();
            if (!txt) txt = 'Variant #' + v.id;
            select.innerHTML += '<option value="'+v.id+'">'+txt+'</option>';
        });
    }
}

function confirmDeleteAll() {
    return confirm('Delete all generated barcodes?');
}

function deleteSingle(id) {
    if (!confirm('Delete this barcode?')) return;
    var form = document.getElementById('deleteSingleForm');
    document.getElementById('deleteSingleId').value = id;
    form.submit();
}
</script>
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

            <?php if($message): ?>
                <div class="alert alert-<?= e($messageType) ?> alert-box"><?= e($message) ?></div>
            <?php endif; ?>

            <div class="page-card">
                <div class="page-title">Generate Barcode</div>

                <form method="post">
                    <input type="hidden" name="action" value="generate">

                    <div class="grid-top">
                        <div>
                            <div class="input-box">
                                <i class="bi bi-credit-card-2-front"></i>
                                <select name="item_id" id="itemSelect" required onchange="loadVariants(this.value)">
                                    <option value="">Select Product</option>
                                    <?php foreach($items as $item): ?>
                                        <option value="<?= (int)$item['id'] ?>"><?= e($item['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="product-preview" id="productPreview">No product selected</div>
                        </div>

                        <div>
                            <div class="input-box">
                                <i class="bi bi-person"></i>
                                <select name="variant_id" id="variantSelect">
                                    <option value="0">Quantity / Variant</option>
                                </select>
                            </div>
                            <div class="input-box" style="margin-top:10px;">
                                <i class="bi bi-hash"></i>
                                <select name="qty">
                                    <option value="1">1</option>
                                    <?php for($i=2; $i<=100; $i++): ?>
                                        <option value="<?= $i ?>"><?= $i ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>

                        <div>
                            <div class="input-box">
                                <i class="bi bi-upc"></i>
                                <select name="barcode_type">
                                    <option value="Code128">Code128</option>
                                    <option value="EAN13">EAN13</option>
                                    <option value="UPC">UPC</option>
                                </select>
                            </div>
                            <div class="input-box" style="margin-top:10px;">
                                <i class="bi bi-text-paragraph"></i>
                                <input type="text" name="label_slug" placeholder="Choose Slug / Label">
                            </div>
                        </div>

                        <div style="display:flex;align-items:flex-start;">
                            <button type="submit" class="main-generate-btn" style="margin-top:64px;">
                                <i class="bi bi-upc-scan"></i> Generate Barcode
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="page-card">
                <div class="quick-actions-title"><i class="bi bi-file-earmark"></i> Quick Actions</div>
                <div class="quick-actions-row">
                    <div class="quick-btns-left">
                        <button type="button" class="quick-btn" onclick="printAllBarcodes()"><i class="bi bi-printer"></i> Print All</button>
                        <a href="<?= e($_SERVER['PHP_SELF']) ?>?export=pdf" class="quick-btn"><i class="bi bi-file-earmark-pdf"></i> Download PDF</a>
                        <a href="<?= e($_SERVER['PHP_SELF']) ?>?export=png" class="quick-btn"><i class="bi bi-download"></i> Export PNG</a>
                        <form method="post" onsubmit="return confirmDeleteAll()" style="display:inline;">
                            <input type="hidden" name="action" value="delete_all">
                            <button type="submit" class="quick-btn"><i class="bi bi-trash"></i> Delete All</button>
                        </form>
                    </div>

                    <div class="quick-btns-right">
                        <button type="button" class="main-generate-btn" onclick="document.getElementById('itemSelect').focus()">
                            <i class="bi bi-upc-scan"></i> Generate Barcode
                        </button>
                    </div>
                </div>
            </div>

            <div class="generated-panel">
                <div class="generated-panel-head">
                    <i class="bi bi-upc-scan"></i> Generated Barcodes
                </div>

                <div class="barcode-grid">
                    <?php if(!empty($barcodes)): ?>
                        <?php foreach($barcodes as $b): ?>
                            <?php
                                $variantText = trim(($b['size'] ?? '') . ' ' . ($b['color'] ?? ''));
                                $title = $b['item_name'];
                                if ($variantText !== '') {
                                    $title .= ' (' . $variantText . ')';
                                }
                            ?>
                            <div class="barcode-card">
                                <div class="barcode-name"><?= e($title) ?></div>
                                <div class="barcode-image-wrap">
                                    <img src="<?= e(barcode_url($b['barcode_image'])) ?>" class="barcode-image" alt="barcode">
                                    <div class="barcode-number"><?= e($b['barcode']) ?></div>
                                </div>
                                <div class="barcode-actions">
                                    <button
                                        type="button"
                                        class="barcode-btn primary"
                                        onclick="printBarcode(
                                            '<?= e(barcode_url($b['barcode_image'])) ?>',
                                            '<?= e($b['barcode']) ?>',
                                            '<?= e($title) ?>',
                                            '<?= (int)$b['qty'] ?>'
                                        )"
                                    >
                                        <i class="bi bi-printer"></i> Print
                                    </button>
                                    <a href="<?= e(barcode_url($b['barcode_image'])) ?>" download class="barcode-btn">
                                        <i class="bi bi-download"></i> Download
                                    </a>
                                </div>
                                <div class="barcode-qty" data-qty="<?= (int)$b['qty'] ?>" style="margin-top:8px;font-size:13px;color:#6b7280;">
                                    Qty: <?= (int)$b['qty'] ?> | Type: <?= e($b['barcode_type']) ?>
                                </div>
                                <div style="margin-top:8px;">
                                    <button type="button" class="barcode-btn" onclick="deleteSingle(<?= (int)$b['id'] ?>)">
                                        <i class="bi bi-trash"></i> Delete
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="padding:20px;color:#6b7280;">No barcodes generated</div>
                    <?php endif; ?>
                </div>

                <!-- Pagination Footer -->
                <div class="pagination-footer">
                    <div class="pagination-summary">
                        <?php if($totalRows > 0): ?>
                            Showing <?= $showingFrom ?> to <?= $showingTo ?> of <?= $totalRows ?> generated barcodes
                        <?php else: ?>
                            No barcodes to display
                        <?php endif; ?>
                    </div>

                    <?php if($totalPages > 1): ?>
                    <div class="pagination-bar">

                        <!-- Previous -->
                        <?php if($currentPage <= 1): ?>
                            <span class="pag-btn disabled"><i class="bi bi-chevron-left"></i></span>
                        <?php else: ?>
                            <a href="<?= e(pagUrl($currentPage - 1)) ?>" class="pag-btn"><i class="bi bi-chevron-left"></i></a>
                        <?php endif; ?>

                        <?php
                        /* Show max 7 page buttons with ellipsis */
                        $window   = 2; // pages around current
                        $start    = max(1, $currentPage - $window);
                        $end      = min($totalPages, $currentPage + $window);

                        if ($start > 1): ?>
                            <a href="<?= e(pagUrl(1)) ?>" class="pag-btn">1</a>
                            <?php if($start > 2): ?>
                                <span class="pag-btn disabled" style="border:none;background:none;">…</span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for($p = $start; $p <= $end; $p++): ?>
                            <a href="<?= e(pagUrl($p)) ?>" class="pag-btn <?= $p === $currentPage ? 'active' : '' ?>"><?= $p ?></a>
                        <?php endfor; ?>

                        <?php if($end < $totalPages): ?>
                            <?php if($end < $totalPages - 1): ?>
                                <span class="pag-btn disabled" style="border:none;background:none;">…</span>
                            <?php endif; ?>
                            <a href="<?= e(pagUrl($totalPages)) ?>" class="pag-btn"><?= $totalPages ?></a>
                        <?php endif; ?>

                        <!-- Next -->
                        <?php if($currentPage >= $totalPages): ?>
                            <span class="pag-btn disabled"><i class="bi bi-chevron-right"></i></span>
                        <?php else: ?>
                            <a href="<?= e(pagUrl($currentPage + 1)) ?>" class="pag-btn"><i class="bi bi-chevron-right"></i></a>
                        <?php endif; ?>

                    </div>
                    <?php endif; ?>
                </div>
                <!-- /Pagination Footer -->

            </div>

        </div>
    </div>
</div>

<form method="post" id="deleteSingleForm" style="display:none;">
    <input type="hidden" name="action" value="delete_single">
    <input type="hidden" name="id" id="deleteSingleId">
</form>
</body>
</html>