<?php
/*
|--------------------------------------------------------------------------
| deals.php
| State Agency / Real Estate Deals / Conversions
|--------------------------------------------------------------------------
| Path:
| C:\xampp\htdocs\pos\public\app\views\deals.php
|
| Workflow:
| Lead final ho jaye => Deal create hogi
| Deal create hone ke baad:
| - Lead status = Converted
| - Property status = Sold / Booked
| - Commission record = Created
*/

session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/onerp/app/config/db.php');

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (empty($_SESSION['user_id'])) {
    header("Location: /public/app/views/login_view.php");
    exit;
}

$user_id   = (int)($_SESSION['user_id'] ?? 0);
$client_id = (int)($_SESSION['client_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? ($_SESSION['current_branch_id'] ?? 0));

if ($client_id <= 0) {
    die("Invalid client session");
}

/* ===================== HELPERS ===================== */
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return ((int)$count) > 0;
}

function columnExists(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return ((int)$count) > 0;
}

function redirect_with_message(string $type, string $message): void {
    header("Location: deals.php?msg_type=" . urlencode($type) . "&msg=" . urlencode($message));
    exit;
}

function normalize_option($value, array $allowed, string $default) {
    $value = trim((string)$value);
    return in_array($value, $allowed, true) ? $value : $default;
}

function moneyValue($value): float {
    $value = str_replace([',', 'Rs.', 'PKR', ' '], '', (string)$value);
    return (float)$value;
}

function moneyFmt($amount): string {
    return 'PKR ' . number_format((float)$amount, 0);
}

function dealNo(mysqli $conn, int $client_id): string {
    $prefix = 'DEAL-' . date('Ymd') . '-';
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM re_deals
        WHERE client_id = ?
          AND DATE(created_at) = CURDATE()
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    return $prefix . str_pad((string)((int)$count + 1), 4, '0', STR_PAD_LEFT);
}

function badgeClass($status) {
    $map = [
        'Token Paid'        => 'badge-yellow',
        'Booking Confirmed' => 'badge-blue',
        'Agreement'         => 'badge-purple',
        'Completed'         => 'badge-green',
        'On Going'          => 'badge-teal',
        'Follow Up'         => 'badge-orange',
        'Lost'              => 'badge-red',
        'Cancelled'         => 'badge-red',
    ];
    return $map[$status] ?? 'badge-blue';
}

function updateOrCreateCommission(mysqli $conn, int $client_id, ?int $branch_id, int $deal_id, ?int $agent_id, float $deal_amount, float $commission_percent, float $commission_amount, int $user_id): void {
    /*
     * IMPORTANT FIX:
     * Pehle code old table `re_commissions` me `paid_amount` column read kar raha tha.
     * Aapki DB me old `re_commissions` table me `paid_amount` missing hai,
     * isliye error aaya: Unknown column 'paid_amount' in field list.
     *
     * Ab logic pehle new professional table `re_agent_commissions` use karega.
     * Agar wo table na ho to old `re_commissions` ko safely handle karega.
     */

    $branch_id_db = ($branch_id && $branch_id > 0) ? $branch_id : null;
    $agent_id_db  = ($agent_id && $agent_id > 0) ? $agent_id : null;

    /* ===================== NEW AGENT COMMISSION TABLE ===================== */
    if (tableExists($conn, 're_agent_commissions')) {
        $property_id = null;
        $deal_no = '';
        $property_title = '';

        if (tableExists($conn, 're_deals')) {
            $hasProperties = tableExists($conn, 're_properties');
            $joinProperty = $hasProperties ? "LEFT JOIN re_properties p ON p.id = d.property_id AND p.client_id = d.client_id" : "";
            $propertySelect = $hasProperties ? "COALESCE(p.title, CONCAT('Property #', d.property_id))" : "CONCAT('Property #', d.property_id)";

            $stmt = $conn->prepare("
                SELECT d.property_id, d.deal_no, $propertySelect AS property_title
                FROM re_deals d
                $joinProperty
                WHERE d.id = ? AND d.client_id = ?
                LIMIT 1
            ");
            $stmt->bind_param("ii", $deal_id, $client_id);
            $stmt->execute();
            $stmt->bind_result($property_id_tmp, $deal_no_tmp, $property_title_tmp);
            if ($stmt->fetch()) {
                $property_id = $property_id_tmp ? (int)$property_id_tmp : null;
                $deal_no = (string)$deal_no_tmp;
                $property_title = (string)$property_title_tmp;
            }
            $stmt->close();
        }

        $stmt = $conn->prepare("
            SELECT id, paid_amount
            FROM re_agent_commissions
            WHERE client_id = ? AND deal_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $client_id, $deal_id);
        $stmt->execute();
        $stmt->bind_result($commission_id, $old_paid_amount);
        $exists = $stmt->fetch();
        $stmt->close();

        $paid_amount = $exists ? (float)$old_paid_amount : 0.00;
        $balance_amount = max(0, $commission_amount - $paid_amount);

        if ($paid_amount <= 0) {
            $payment_status = 'Unpaid';
        } elseif ($paid_amount >= $commission_amount) {
            $payment_status = 'Paid';
        } else {
            $payment_status = 'Partial';
        }

        $commission_status = $payment_status === 'Paid'
            ? 'Released'
            : ($payment_status === 'Partial' ? 'Approved' : 'Pending');

        if ($exists) {
            $stmt = $conn->prepare("
                UPDATE re_agent_commissions
                SET
                    branch_id = ?,
                    agent_id = ?,
                    property_id = ?,
                    deal_no = ?,
                    property_title = ?,
                    deal_amount = ?,
                    commission_percent = ?,
                    commission_amount = ?,
                    paid_amount = ?,
                    balance_amount = ?,
                    payment_status = ?,
                    commission_status = ?
                WHERE id = ? AND client_id = ?
            ");
            $stmt->bind_param(
                "iiissddddsssii",
                $branch_id_db,
                $agent_id_db,
                $property_id,
                $deal_no,
                $property_title,
                $deal_amount,
                $commission_percent,
                $commission_amount,
                $paid_amount,
                $balance_amount,
                $payment_status,
                $commission_status,
                $commission_id,
                $client_id
            );
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare("
                INSERT INTO re_agent_commissions
                (
                    client_id, branch_id, deal_id, agent_id, property_id, deal_no, property_title,
                    deal_amount, commission_percent, commission_amount, paid_amount, balance_amount,
                    payment_status, commission_status, created_by
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "iiiiissdddddssi",
                $client_id,
                $branch_id_db,
                $deal_id,
                $agent_id_db,
                $property_id,
                $deal_no,
                $property_title,
                $deal_amount,
                $commission_percent,
                $commission_amount,
                $paid_amount,
                $balance_amount,
                $payment_status,
                $commission_status,
                $user_id
            );
            $stmt->execute();
            $stmt->close();
        }

        return;
    }

    /* ===================== OLD COMMISSION TABLE FALLBACK ===================== */
    if (!tableExists($conn, 're_commissions')) {
        return;
    }

    $paid_amount = 0.00;

    if (columnExists($conn, 're_commissions', 'paid_amount')) {
        $stmt = $conn->prepare("
            SELECT id, paid_amount
            FROM re_commissions
            WHERE client_id = ? AND deal_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $client_id, $deal_id);
        $stmt->execute();
        $stmt->bind_result($commission_id, $old_paid_amount);
        $exists = $stmt->fetch();
        $stmt->close();
        $paid_amount = $exists ? (float)$old_paid_amount : 0.00;
    } else {
        $stmt = $conn->prepare("
            SELECT id
            FROM re_commissions
            WHERE client_id = ? AND deal_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $client_id, $deal_id);
        $stmt->execute();
        $stmt->bind_result($commission_id);
        $exists = $stmt->fetch();
        $stmt->close();
    }

    $balance_amount = max(0, $commission_amount - $paid_amount);
    $commission_status = $balance_amount <= 0 && $commission_amount > 0 ? 'Paid' : ($paid_amount > 0 ? 'Partial' : 'Pending');

    if ($exists) {
        $set = [
            "agent_id = ?",
            "deal_amount = ?",
            "commission_percent = ?",
            "commission_amount = ?",
            "balance_amount = ?",
            "status = ?"
        ];
        $types = "iddddsi";
        $params = [$agent_id_db, $deal_amount, $commission_percent, $commission_amount, $balance_amount, $commission_status, $commission_id];

        if (columnExists($conn, 're_commissions', 'branch_id')) {
            array_unshift($set, "branch_id = ?");
            $types = "i" . $types;
            array_unshift($params, $branch_id_db);
        }

        $types .= "i";
        $params[] = $client_id;

        $sql = "UPDATE re_commissions SET " . implode(", ", $set) . " WHERE id = ? AND client_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
    } else {
        // Old table ka schema different ho sakta hai, isliye simple insert sirf tab jab required columns exist hon.
        $requiredCols = ['client_id','deal_id','agent_id','deal_amount','commission_percent','commission_amount','balance_amount','status','created_by'];
        foreach ($requiredCols as $col) {
            if (!columnExists($conn, 're_commissions', $col)) {
                return;
            }
        }

        $columns = ['client_id','deal_id','agent_id','deal_amount','commission_percent','commission_amount','balance_amount','status','created_by'];
        $placeholders = ['?','?','?','?','?','?','?','?','?'];
        $types = "iiiddddsi";
        $params = [$client_id, $deal_id, $agent_id_db, $deal_amount, $commission_percent, $commission_amount, $balance_amount, $commission_status, $user_id];

        if (columnExists($conn, 're_commissions', 'branch_id')) {
            array_splice($columns, 1, 0, 'branch_id');
            array_splice($placeholders, 1, 0, '?');
            $types = "i" . $types;
            array_splice($params, 1, 0, [$branch_id_db]);
        }

        if (columnExists($conn, 're_commissions', 'paid_amount')) {
            array_splice($columns, -2, 0, 'paid_amount');
            array_splice($placeholders, -2, 0, '?');
            $types = substr($types, 0, -2) . "d" . substr($types, -2);
            array_splice($params, -2, 0, [$paid_amount]);
        }

        $sql = "INSERT INTO re_commissions (" . implode(",", $columns) . ") VALUES (" . implode(",", $placeholders) . ")";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
    }
}

/* ===================== REQUIRED TABLE CHECK ===================== */
if (!tableExists($conn, 're_deals')) {
    die("
        <div style='font-family:Arial;padding:25px;background:#fff3cd;color:#664d03;border:1px solid #ffecb5;border-radius:10px;margin:25px;'>
            <h3>re_deals table missing</h3>
            <p>Pehle <b>re_deals_schema.sql</b> phpMyAdmin me run karo.</p>
        </div>
    ");
}

if (!tableExists($conn, 're_leads')) {
    die("
        <div style='font-family:Arial;padding:25px;background:#fff3cd;color:#664d03;border:1px solid #ffecb5;border-radius:10px;margin:25px;'>
            <h3>re_leads table missing</h3>
            <p>Pehle Agency Leads table create karo.</p>
        </div>
    ");
}

if (!tableExists($conn, 're_properties')) {
    die("
        <div style='font-family:Arial;padding:25px;background:#fff3cd;color:#664d03;border:1px solid #ffecb5;border-radius:10px;margin:25px;'>
            <h3>re_properties table missing</h3>
            <p>Pehle Properties table create karo.</p>
        </div>
    ");
}

/* ===================== OPTIONS ===================== */
$dealStatuses = ['Token Paid','Booking Confirmed','Agreement','Completed','On Going','Follow Up','Lost','Cancelled'];

/* ===================== AGENTS ===================== */
$agents = [];
if (tableExists($conn, 'roles')) {
    $stmt = $conn->prepare("
        SELECT
            u.id,
            u.name,
            u.email,
            u.phone,
            u.role_id,
            COALESCE(r.name, '') AS role_name
        FROM users u
        LEFT JOIN roles r ON r.id = u.role_id
        WHERE u.client_id = ?
          AND u.status = 'active'
          AND (
                u.role_id = 2
                OR LOWER(COALESCE(r.name, '')) IN (
                    'agent','staff','employee','sales agent','real estate agent','property agent'
                )
          )
        ORDER BY u.name ASC
    ");
    $stmt->bind_param("i", $client_id);
} else {
    $stmt = $conn->prepare("
        SELECT id, name, email, phone, role_id, '' AS role_name
        FROM users
        WHERE client_id = ?
          AND status = 'active'
          AND role_id = 2
        ORDER BY name ASC
    ");
    $stmt->bind_param("i", $client_id);
}
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $agents[] = $row;
}
$stmt->close();

/* ===================== LEADS ===================== */
$leadAgentExpr = columnExists($conn, 're_leads', 'assigned_agent_id')
    ? 'assigned_agent_id'
    : (columnExists($conn, 're_leads', 'agent_id') ? 'agent_id' : 'NULL');

$leads = [];
$stmt = $conn->prepare("
    SELECT
        id,
        lead_name,
        phone,
        email,
        status,
        $leadAgentExpr AS assigned_agent_id
    FROM re_leads
    WHERE client_id = ?
    ORDER BY id DESC
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $leads[] = $row;
}
$stmt->close();

/* ===================== PROPERTIES ===================== */
$hasProjects = tableExists($conn, 're_projects');
$projectJoin = $hasProjects ? "LEFT JOIN re_projects pr ON pr.id = p.project_id AND pr.client_id = p.client_id" : "";
$projectNameSelect = $hasProjects ? "COALESCE(pr.project_name, pr.name, '') AS project_name," : "'' AS project_name,";

$properties = [];
$stmt = $conn->prepare("
    SELECT
        p.id,
        p.title,
        p.property_code,
        p.city,
        p.area_location,
        p.status,
        p.demand_price,
        p.assigned_agent_id,
        $projectNameSelect
        CONCAT(COALESCE(p.title,''), ' - ', COALESCE(p.city,''), ' ', COALESCE(p.area_location,'')) AS display_title
    FROM re_properties p
    $projectJoin
    WHERE p.client_id = ?
    ORDER BY p.id DESC
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $properties[] = $row;
}
$stmt->close();

/* ===================== POST ACTIONS ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'save_deal') {
        $id                    = (int)($_POST['id'] ?? 0);
        $lead_id               = (int)($_POST['lead_id'] ?? 0);
        $property_id           = (int)($_POST['property_id'] ?? 0);
        $agent_id              = (int)($_POST['agent_id'] ?? 0);
        $deal_no               = trim($_POST['deal_no'] ?? '');
        $deal_amount           = moneyValue($_POST['deal_amount'] ?? 0);
        $token_amount          = moneyValue($_POST['token_amount'] ?? 0);
        $paid_amount           = moneyValue($_POST['paid_amount'] ?? 0);
        $commission_percent    = (float)($_POST['commission_percent'] ?? 0);
        $status                = normalize_option($_POST['status'] ?? 'Token Paid', $dealStatuses, 'Token Paid');
        $deal_date             = trim($_POST['deal_date'] ?? '');
        $expected_closing_date = trim($_POST['expected_closing_date'] ?? '');
        $notes                 = trim($_POST['notes'] ?? '');

        if ($lead_id <= 0) {
            redirect_with_message('danger', 'Lead / customer is required.');
        }
        if ($property_id <= 0) {
            redirect_with_message('danger', 'Property is required.');
        }
        if ($agent_id <= 0) {
            redirect_with_message('danger', 'Agent is required.');
        }
        if ($deal_amount <= 0) {
            redirect_with_message('danger', 'Deal amount is required.');
        }
        if ($paid_amount < 0 || $token_amount < 0) {
            redirect_with_message('danger', 'Paid amount and token amount cannot be negative.');
        }
        if ($paid_amount > $deal_amount) {
            redirect_with_message('danger', 'Paid amount cannot be greater than deal amount.');
        }
        if ($deal_date === '') {
            $deal_date = date('Y-m-d');
        }
        if ($expected_closing_date === '') {
            $expected_closing_date = null;
        }

        $balance_amount = max(0, $deal_amount - $paid_amount);
        $commission_amount = ($deal_amount * $commission_percent) / 100;
        $branch_id_db = $branch_id > 0 ? $branch_id : null;

        if ($deal_no === '') {
            $deal_no = dealNo($conn, $client_id);
        }

        if ($id > 0) {
            $stmt = $conn->prepare("
                UPDATE re_deals
                SET
                    lead_id = ?,
                    property_id = ?,
                    agent_id = ?,
                    deal_no = ?,
                    deal_amount = ?,
                    token_amount = ?,
                    paid_amount = ?,
                    balance_amount = ?,
                    commission_percent = ?,
                    commission_amount = ?,
                    status = ?,
                    deal_date = ?,
                    expected_closing_date = ?,
                    notes = ?
                WHERE id = ? AND client_id = ?
            ");
            $stmt->bind_param(
                "iiisddddddssssii",
                $lead_id,
                $property_id,
                $agent_id,
                $deal_no,
                $deal_amount,
                $token_amount,
                $paid_amount,
                $balance_amount,
                $commission_percent,
                $commission_amount,
                $status,
                $deal_date,
                $expected_closing_date,
                $notes,
                $id,
                $client_id
            );
            $stmt->execute();
            $stmt->close();

            updateOrCreateCommission($conn, $client_id, $branch_id_db, $id, $agent_id, $deal_amount, $commission_percent, $commission_amount, $user_id);
            $message = 'Deal updated successfully.';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO re_deals
                (
                    client_id, branch_id, lead_id, property_id, agent_id, deal_no,
                    deal_amount, token_amount, paid_amount, balance_amount,
                    commission_percent, commission_amount, status, deal_date,
                    expected_closing_date, notes, created_by
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "iiiiisddddddssssi",
                $client_id,
                $branch_id_db,
                $lead_id,
                $property_id,
                $agent_id,
                $deal_no,
                $deal_amount,
                $token_amount,
                $paid_amount,
                $balance_amount,
                $commission_percent,
                $commission_amount,
                $status,
                $deal_date,
                $expected_closing_date,
                $notes,
                $user_id
            );
            $stmt->execute();
            $deal_id = (int)$stmt->insert_id;
            $stmt->close();

            updateOrCreateCommission($conn, $client_id, $branch_id_db, $deal_id, $agent_id, $deal_amount, $commission_percent, $commission_amount, $user_id);
            $id = $deal_id;
            $message = 'Deal created successfully.';
        }

        /*
         * Deal workflow:
         * - Deal save par lead Converted ho jayegi
         * - Property status status ke mutabiq Booked/Sold/Available ho jayegi
         */
        if (columnExists($conn, 're_leads', 'status')) {
            $leadStatus = in_array($status, ['Lost','Cancelled'], true) ? $status : 'Converted';
            $stmt = $conn->prepare("UPDATE re_leads SET status = ? WHERE id = ? AND client_id = ?");
            $stmt->bind_param("sii", $leadStatus, $lead_id, $client_id);
            $stmt->execute();
            $stmt->close();
        }

        if (columnExists($conn, 're_properties', 'status')) {
            $propertyStatus = 'Booked';
            if ($status === 'Completed') {
                $propertyStatus = 'Sold';
            } elseif (in_array($status, ['Lost','Cancelled'], true)) {
                $propertyStatus = 'Available';
            } elseif (in_array($status, ['Token Paid','Booking Confirmed','Agreement','On Going'], true)) {
                $propertyStatus = 'Booked';
            }

            $stmt = $conn->prepare("UPDATE re_properties SET status = ? WHERE id = ? AND client_id = ?");
            $stmt->bind_param("sii", $propertyStatus, $property_id, $client_id);
            $stmt->execute();
            $stmt->close();
        }

        redirect_with_message('success', $message);
    }

    if ($action === 'delete_deal') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            redirect_with_message('danger', 'Invalid deal id.');
        }

        if (tableExists($conn, 're_agent_commissions')) {
            if (tableExists($conn, 're_commission_payments')) {
                $stmt = $conn->prepare("
                    DELETE cp
                    FROM re_commission_payments cp
                    JOIN re_agent_commissions ac ON ac.id = cp.commission_id AND ac.client_id = cp.client_id
                    WHERE ac.deal_id = ? AND ac.client_id = ?
                ");
                $stmt->bind_param("ii", $id, $client_id);
                $stmt->execute();
                $stmt->close();
            }

            $stmt = $conn->prepare("DELETE FROM re_agent_commissions WHERE deal_id = ? AND client_id = ?");
            $stmt->bind_param("ii", $id, $client_id);
            $stmt->execute();
            $stmt->close();
        }

        if (tableExists($conn, 're_commissions')) {
            $stmt = $conn->prepare("DELETE FROM re_commissions WHERE deal_id = ? AND client_id = ?");
            $stmt->bind_param("ii", $id, $client_id);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $conn->prepare("DELETE FROM re_deals WHERE id = ? AND client_id = ?");
        $stmt->bind_param("ii", $id, $client_id);
        $stmt->execute();
        $stmt->close();

        redirect_with_message('success', 'Deal deleted successfully.');
    }
}

/* ===================== FILTERS ===================== */
$search       = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$agentFilter  = (int)($_GET['agent_id'] ?? 0);
$projectFilter = (int)($_GET['project_id'] ?? 0);
$dateFrom     = trim($_GET['date_from'] ?? '');
$dateTo       = trim($_GET['date_to'] ?? '');
$perPage      = (int)($_GET['per_page'] ?? 10);
$perPage      = in_array($perPage, [10,20,50,100], true) ? $perPage : 10;
$page         = max(1, (int)($_GET['page'] ?? 1));
$offset       = ($page - 1) * $perPage;

/* ===================== CSV EXPORT ===================== */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $where = " WHERE d.client_id = ? ";
    $params = [$client_id];
    $types = 'i';

    if ($search !== '') {
        $where .= " AND (d.deal_no LIKE ? OR l.lead_name LIKE ? OR p.title LIKE ? OR u.name LIKE ?)";
        $like = "%{$search}%";
        array_push($params, $like, $like, $like, $like);
        $types .= 'ssss';
    }
    if ($statusFilter !== '') {
        $where .= " AND d.status = ?";
        $params[] = $statusFilter;
        $types .= 's';
    }
    if ($agentFilter > 0) {
        $where .= " AND d.agent_id = ?";
        $params[] = $agentFilter;
        $types .= 'i';
    }
    if ($projectFilter > 0) {
        $where .= " AND p.project_id = ?";
        $params[] = $projectFilter;
        $types .= 'i';
    }
    if ($dateFrom !== '') {
        $where .= " AND d.deal_date >= ?";
        $params[] = $dateFrom;
        $types .= 's';
    }
    if ($dateTo !== '') {
        $where .= " AND d.deal_date <= ?";
        $params[] = $dateTo;
        $types .= 's';
    }

    $sql = "
        SELECT
            d.*,
            l.lead_name,
            l.phone AS lead_phone,
            p.title AS property_title,
            u.name AS agent_name
        FROM re_deals d
        LEFT JOIN re_leads l ON l.id = d.lead_id AND l.client_id = d.client_id
        LEFT JOIN re_properties p ON p.id = d.property_id AND p.client_id = d.client_id
        LEFT JOIN users u ON u.id = d.agent_id
        $where
        ORDER BY d.deal_date DESC, d.id DESC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=deals_' . date('Ymd_His') . '.csv');

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Deal No','Lead','Phone','Property','Deal Amount','Token Amount','Paid Amount',
        'Balance Amount','Commission %','Commission Amount','Status','Agent','Deal Date','Expected Closing','Notes'
    ]);

    while ($row = $res->fetch_assoc()) {
        fputcsv($out, [
            $row['deal_no'],
            $row['lead_name'],
            $row['lead_phone'],
            $row['property_title'],
            $row['deal_amount'],
            $row['token_amount'],
            $row['paid_amount'],
            $row['balance_amount'],
            $row['commission_percent'],
            $row['commission_amount'],
            $row['status'],
            $row['agent_name'],
            $row['deal_date'],
            $row['expected_closing_date'],
            $row['notes'],
        ]);
    }

    fclose($out);
    $stmt->close();
    exit;
}

/* ===================== FLASH ===================== */
$msg = trim($_GET['msg'] ?? '');
$msgType = trim($_GET['msg_type'] ?? 'success');

/* ===================== STATS ===================== */
function scalarValue(mysqli $conn, string $sql, string $types = '', array $params = []) {
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $stmt->bind_result($value);
    $stmt->fetch();
    $stmt->close();
    return $value ?? 0;
}

$totalDeals = (int)scalarValue($conn, "SELECT COUNT(*) FROM re_deals WHERE client_id = ?", "i", [$client_id]);
$totalDealValue = (float)scalarValue($conn, "SELECT COALESCE(SUM(deal_amount),0) FROM re_deals WHERE client_id = ?", "i", [$client_id]);
$totalPaidValue = (float)scalarValue($conn, "SELECT COALESCE(SUM(paid_amount),0) FROM re_deals WHERE client_id = ?", "i", [$client_id]);
$totalPendingValue = (float)scalarValue($conn, "SELECT COALESCE(SUM(balance_amount),0) FROM re_deals WHERE client_id = ?", "i", [$client_id]);
$totalCommissionValue = (float)scalarValue($conn, "SELECT COALESCE(SUM(commission_amount),0) FROM re_deals WHERE client_id = ?", "i", [$client_id]);
$thisMonthDeals = (int)scalarValue($conn, "SELECT COUNT(*) FROM re_deals WHERE client_id = ? AND YEAR(deal_date)=YEAR(CURDATE()) AND MONTH(deal_date)=MONTH(CURDATE())", "i", [$client_id]);
$convertedLeads = (int)scalarValue($conn, "SELECT COUNT(*) FROM re_leads WHERE client_id = ? AND status = 'Converted'", "i", [$client_id]);

/* ===================== PROJECTS FILTER ===================== */
$projects = [];
if ($hasProjects) {
    $stmt = $conn->prepare("
        SELECT id, COALESCE(project_name, name) AS project_name
        FROM re_projects
        WHERE client_id = ?
        ORDER BY COALESCE(project_name, name) ASC
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $projects[] = $row;
    }
    $stmt->close();
}

/* ===================== FETCH DEALS ===================== */
$where = " WHERE d.client_id = ? ";
$params = [$client_id];
$types = 'i';

if ($search !== '') {
    $where .= " AND (d.deal_no LIKE ? OR l.lead_name LIKE ? OR p.title LIKE ? OR u.name LIKE ?)";
    $like = "%{$search}%";
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}
if ($statusFilter !== '') {
    $where .= " AND d.status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}
if ($agentFilter > 0) {
    $where .= " AND d.agent_id = ?";
    $params[] = $agentFilter;
    $types .= 'i';
}
if ($projectFilter > 0) {
    $where .= " AND p.project_id = ?";
    $params[] = $projectFilter;
    $types .= 'i';
}
if ($dateFrom !== '') {
    $where .= " AND d.deal_date >= ?";
    $params[] = $dateFrom;
    $types .= 's';
}
if ($dateTo !== '') {
    $where .= " AND d.deal_date <= ?";
    $params[] = $dateTo;
    $types .= 's';
}

$countSql = "
    SELECT COUNT(*)
    FROM re_deals d
    LEFT JOIN re_leads l ON l.id = d.lead_id AND l.client_id = d.client_id
    LEFT JOIN re_properties p ON p.id = d.property_id AND p.client_id = d.client_id
    LEFT JOIN users u ON u.id = d.agent_id
    $where
";
$countStmt = $conn->prepare($countSql);
$countStmt->bind_param($types, ...$params);
$countStmt->execute();
$countStmt->bind_result($totalFiltered);
$countStmt->fetch();
$countStmt->close();

$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$sql = "
    SELECT
        d.*,
        l.lead_name,
        l.phone AS lead_phone,
        p.title AS property_title,
        p.property_code,
        p.project_id,
        p.city AS property_city,
        p.area_location,
        u.name AS agent_name,
        " . ($hasProjects ? "COALESCE(pr.project_name, pr.name, '')" : "''") . " AS project_name
    FROM re_deals d
    LEFT JOIN re_leads l ON l.id = d.lead_id AND l.client_id = d.client_id
    LEFT JOIN re_properties p ON p.id = d.property_id AND p.client_id = d.client_id
    " . ($hasProjects ? "LEFT JOIN re_projects pr ON pr.id = p.project_id AND pr.client_id = p.client_id" : "") . "
    LEFT JOIN users u ON u.id = d.agent_id
    $where
    ORDER BY d.deal_date DESC, d.id DESC
    LIMIT ? OFFSET ?
";

$paramsPage = array_merge($params, [$perPage, $offset]);
$typesPage = $types . 'ii';

$stmt = $conn->prepare($sql);
$stmt->bind_param($typesPage, ...$paramsPage);
$stmt->execute();
$res = $stmt->get_result();

$deals = [];
while ($row = $res->fetch_assoc()) {
    $deals[] = $row;
}
$stmt->close();

$dealsJson = json_encode($deals, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$leadsJson = json_encode($leads, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$propertiesJson = json_encode($properties, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

$queryBase = $_GET;
unset($queryBase['page']);
function pageUrl($page, $queryBase) {
    $queryBase['page'] = $page;
    return 'deals.php?' . http_build_query($queryBase);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Deals / Conversions – Real Estate | ONE ERP</title>

    <?php include('partials/head.php'); ?>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap"
        rel="stylesheet" />
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"
        rel="stylesheet" />

    <style>
    :root {
        --bg: #eef2f7;
        --white: #fff;
        --border: #e0e5f2;
        --text: #1f2736;
        --muted: #64748b;
        --gold: #c9a84c;
        --gold2: #d8a500;
        --red: #c94b57;
        --green: #3e7a3a;
        --blue: #1d4ed8;
        --shadow: 0 4px 12px rgba(0, 0, 0, .05);
    }

    * {
        box-sizing: border-box;
        margin: 0;
        padding: 0
    }

    body {
        font-family: 'Inter', sans-serif;
        background: var(--bg);
        color: var(--text);
        font-size: 13px;
    }

    a {
        text-decoration: none;
    }

    .container-scroll {
        display: flex;
        min-height: 100vh;
        background: var(--bg);
    }

    .sidebar {
        width: 270px;
        flex-shrink: 0;
    }

    .main-panel {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        background: var(--bg);
    }

    .topbar {
        min-height: 70px;
        background: #fff;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        align-items: center;
    }

    .content-wrapper {
        padding: 24px;
        flex: 1;
    }

    .page-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 14px;
        flex-wrap: wrap;
        margin-bottom: 22px;
    }

    .page-title {
        font-size: 24px;
        font-weight: 900;
        color: #243046;
        letter-spacing: -.35px;
    }

    .breadcrumb-custom {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-top: 5px;
        color: #9ca3af;
        font-size: 12px;
    }

    .breadcrumb-custom a {
        color: #9ca3af
    }

    .breadcrumb-custom .active {
        color: var(--gold);
        font-weight: 800
    }

    .page-actions {
        display: flex;
        gap: 9px;
        align-items: center;
        flex-wrap: wrap;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        height: 42px;
        padding: 0 16px;
        border-radius: 10px;
        border: none;
        font-weight: 800;
        font-size: 13px;
        cursor: pointer;
        font-family: 'Inter', sans-serif;
        transition: .18s;
        white-space: nowrap;
    }

    .btn .material-symbols-outlined {
        font-size: 18px
    }

    .btn-primary {
        background: linear-gradient(180deg, #f5c742, #d8a500);
        color: #111;
        box-shadow: 0 4px 12px rgba(216, 165, 0, .25);
    }

    .btn-primary:hover {
        transform: translateY(-1px)
    }

    .btn-outline {
        background: #fff;
        color: #2b3674;
        border: 1px solid var(--border);
    }

    .btn-outline:hover {
        background: #f4f7fe;
        border-color: var(--gold);
        color: #8a6a20;
    }

    .btn-danger {
        background: linear-gradient(180deg, #c94b57, #9f2430);
        color: #fff;
    }

    .btn-success {
        background: linear-gradient(180deg, #4f9e4a, #3e7a3a);
        color: #fff;
    }

    .alert {
        margin-bottom: 18px;
        padding: 12px 14px;
        border-radius: 12px;
        font-weight: 700;
    }

    .alert-success {
        background: #dcfce7;
        color: #14532d;
        border: 1px solid #bbf7d0
    }

    .alert-danger {
        background: #fee2e2;
        color: #7f1d1d;
        border: 1px solid #fecaca
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 14px;
        margin-bottom: 22px;
    }

    .stat-card {
        border: none;
        border-radius: 14px;
        min-height: 112px;
        padding: 18px 16px;
        color: #fff;
        position: relative;
        overflow: hidden;
        box-shadow: 0 14px 28px rgba(0, 0, 0, .12);
    }

    .stat-card::after {
        content: '';
        position: absolute;
        width: 110px;
        height: 110px;
        border-radius: 50%;
        right: -18px;
        top: -18px;
        background: rgba(255, 255, 255, .12);
    }

    .stat-card:nth-child(1) {
        background: linear-gradient(135deg, #9f2430, #c94b57);
    }

    .stat-card:nth-child(2) {
        background: linear-gradient(135deg, #b27b16, #d8a33a);
    }

    .stat-card:nth-child(3) {
        background: linear-gradient(135deg, #b87a16, #dc9b2d);
    }

    .stat-card:nth-child(4) {
        background: linear-gradient(135deg, #386c58, #4c9075);
    }

    .stat-card:nth-child(5) {
        background: linear-gradient(135deg, #111c44, #20346d);
    }

    .stat-label {
        font-size: 12px;
        font-weight: 850;
        color: rgba(255, 255, 255, .88);
        position: relative;
        z-index: 1
    }

    .stat-value {
        font-size: 26px;
        font-weight: 900;
        margin-top: 8px;
        position: relative;
        z-index: 1
    }

    .stat-value.small {
        font-size: 15px;
        line-height: 1.35
    }

    .stat-sub {
        font-size: 11px;
        margin-top: 7px;
        color: rgba(255, 255, 255, .82);
        font-weight: 700;
        position: relative;
        z-index: 1
    }

    .card {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 12px;
        box-shadow: var(--shadow);
    }

    .filters-card {
        padding: 16px 20px;
        margin-bottom: 22px;
    }

    .filters-row {
        display: flex;
        gap: 10px;
        align-items: flex-end;
        flex-wrap: wrap;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
        min-width: 140px;
        flex: 1;
    }

    .filter-group.search {
        min-width: 220px;
        flex: 1.5
    }

    .filter-group label {
        font-size: 11px;
        color: #64748b;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .45px;
    }

    .filter-input,
    .filter-select {
        height: 42px;
        border: 1px solid var(--border);
        border-radius: 10px;
        background: #f4f7fe;
        color: #2b3674;
        font-family: 'Inter', sans-serif;
        font-size: 12.5px;
        font-weight: 700;
        padding: 0 11px;
        outline: none;
        width: 100%;
    }

    .filter-input:focus,
    .filter-select:focus {
        border-color: var(--gold);
        background: #fff;
        box-shadow: 0 0 0 3px rgba(201, 168, 76, .12);
    }

    .filter-actions {
        display: flex;
        gap: 8px;
    }

    .table-card {
        overflow: hidden;
        border-radius: 16px;
        border: 1px solid #eef2f7;
        box-shadow: 0 4px 20px rgba(0, 0, 0, .05);
        margin-bottom: 18px;
    }

    .table-header {
        padding: 16px 20px;
        border-bottom: 1px solid #edf2f7;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
    }

    .table-title {
        font-size: 18px;
        font-weight: 900;
        color: #1a202c;
    }

    .table-wrap {
        overflow-x: auto;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1150px;
    }

    thead th {
        background: #f8fafc;
        color: #64748b;
        font-size: 12px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: .4px;
        padding: 14px 12px;
        border-bottom: 2px solid #edf2f7;
        text-align: left;
        white-space: nowrap;
    }

    tbody td {
        padding: 14px 12px;
        border-bottom: 1px solid #f1f5f9;
        font-size: 13px;
        color: #334155;
        vertical-align: middle;
        white-space: nowrap;
    }

    tbody tr:hover td {
        background: #fbfcfe;
    }

    .deal-id {
        color: #8a6a20;
        font-weight: 900;
    }

    .client-name {
        font-weight: 850;
        color: #1e293b;
    }

    .property-name {
        color: #64748b;
        font-size: 12px;
        max-width: 210px;
        overflow: hidden;
        text-overflow: ellipsis;
        display: block;
    }

    .amount {
        font-weight: 900;
        color: #1f2736;
    }

    .paid {
        color: var(--green);
        font-weight: 900;
    }

    .pending {
        color: #dc9b2d;
        font-weight: 900;
    }

    .agent-name {
        font-weight: 750;
        color: #334155;
    }

    .badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 900;
    }

    .badge::before {
        content: '';
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: currentColor;
    }

    .badge-green {
        background: #dcfce7;
        color: #14532d;
    }

    .badge-blue {
        background: #dbeafe;
        color: #1e40af;
    }

    .badge-teal {
        background: #ccfbf1;
        color: #0f766e;
    }

    .badge-orange {
        background: #ffedd5;
        color: #9a3412;
    }

    .badge-yellow {
        background: #fef9c3;
        color: #854d0e;
    }

    .badge-red {
        background: #fee2e2;
        color: #7f1d1d;
    }

    .badge-purple {
        background: #f3e8ff;
        color: #6b21a8;
    }

    .actions {
        display: flex;
        gap: 4px;
        align-items: center;
    }

    .icon-btn {
        width: 31px;
        height: 31px;
        border-radius: 8px;
        border: none;
        background: transparent;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
    }

    .icon-btn .material-symbols-outlined {
        font-size: 18px
    }

    .icon-view {
        color: #3b6cf4
    }

    .icon-edit {
        color: #d8a500
    }

    .icon-del {
        color: #c94b57
    }

    .icon-view:hover {
        background: #eef2ff
    }

    .icon-edit:hover {
        background: #fef3c7
    }

    .icon-del:hover {
        background: #fee2e2
    }

    .pagination-wrap {
        padding: 13px 20px;
        border-top: 1px solid #edf2f7;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
        background: #fff;
    }

    .pagination-info {
        font-size: 12.5px;
        color: #64748b;
        font-weight: 700;
    }

    .pagination-btns {
        display: flex;
        gap: 5px;
        align-items: center;
    }

    .page-btn {
        min-width: 32px;
        height: 32px;
        border-radius: 7px;
        border: 1px solid var(--border);
        background: #fff;
        color: #64748b;
        font-weight: 800;
        font-size: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0 9px;
        text-decoration: none;
    }

    .page-btn.active {
        background: var(--gold2);
        border-color: var(--gold2);
        color: #fff
    }

    .page-btn.disabled {
        opacity: .45;
        pointer-events: none
    }

    .summary-grid {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 14px;
    }

    .summary-card {
        background: #fff;
        border: 1px solid #eef2f7;
        border-radius: 14px;
        box-shadow: var(--shadow);
        padding: 14px 16px;
        text-align: center;
    }

    .summary-label {
        font-size: 11px;
        font-weight: 900;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: .4px;
        margin-bottom: 7px;
    }

    .summary-value {
        font-size: 16px;
        font-weight: 900;
        color: #1f2736;
    }

    .summary-value.green {
        color: var(--green)
    }

    .summary-value.orange {
        color: #dc9b2d
    }

    .summary-value.purple {
        color: #7a4cb0
    }

    .summary-value.blue {
        color: #8a6a20
    }

    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 9999;
        background: rgba(0, 0, 0, .58);
        backdrop-filter: blur(5px);
        padding: 28px 16px;
        overflow-y: auto;
        align-items: flex-start;
        justify-content: center;
    }

    .modal-overlay.open {
        display: flex
    }

    .erp-modal {
        background: #fff;
        border-radius: 18px;
        box-shadow: 0 30px 80px rgba(0, 0, 0, .25);
        width: 100%;
        max-width: 680px;
        overflow: hidden;
        margin: auto 0;
    }

    .modal-header {
        height: 70px;
        background: linear-gradient(135deg, #111 0%, #1a1a1a 100%);
        padding: 0 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .modal-header h2 {
        font-size: 17px;
        font-weight: 900;
        color: #fff;
    }

    .modal-close {
        width: 34px;
        height: 34px;
        border-radius: 9px;
        background: rgba(255, 255, 255, .08);
        border: 1px solid rgba(255, 255, 255, .12);
        color: #aaa;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
    }

    .modal-close:hover {
        background: rgba(239, 68, 68, .2);
        color: #ef4444;
    }

    .modal-body {
        padding: 22px 24px;
        max-height: calc(100vh - 220px);
        overflow-y: auto;
    }

    .modal-footer {
        padding: 15px 24px;
        border-top: 1px solid #e5e7eb;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        background: #fff;
    }

    .form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px 14px;
    }

    .form-group {
        display: flex;
        flex-direction: column;
        gap: 7px;
    }

    .form-group.full {
        grid-column: 1/-1
    }

    .form-label {
        font-size: 11.5px;
        font-weight: 900;
        color: #374151;
        text-transform: uppercase;
        letter-spacing: .35px;
    }

    .req {
        color: #ef4444
    }

    .form-control {
        height: 40px;
        border: 1.5px solid #e5e7eb;
        border-radius: 9px;
        background: #fafafa;
        color: #111827;
        font-family: 'Inter', sans-serif;
        font-size: 13px;
        padding: 0 12px;
        outline: none;
    }

    .form-control:focus {
        border-color: var(--gold);
        background: #fff;
        box-shadow: 0 0 0 3px rgba(201, 168, 76, .12);
    }

    textarea.form-control {
        height: 78px;
        padding: 10px 12px;
        resize: vertical
    }

    .commission-row {
        display: flex;
        gap: 8px;
    }

    .commission-row .form-control {
        flex: 1
    }

    .commission-amount {
        height: 40px;
        background: #fff7df;
        border: 1px solid #f5d77c;
        color: var(--green);
        border-radius: 9px;
        padding: 0 12px;
        display: flex;
        align-items: center;
        font-size: 12.5px;
        font-weight: 900;
        white-space: nowrap;
    }

    .detail-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }

    .detail-item {
        background: #f8fafc;
        border: 1px solid #edf2f7;
        border-radius: 10px;
        padding: 12px 14px;
    }

    .detail-item.full {
        grid-column: 1/-1
    }

    .detail-label {
        font-size: 10.5px;
        font-weight: 900;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: .45px;
        margin-bottom: 4px;
    }

    .detail-value {
        font-size: 13.5px;
        font-weight: 800;
        color: #1e293b;
    }

    .empty-state {
        padding: 45px 20px;
        text-align: center;
        color: #94a3b8;
    }

    .empty-state .material-symbols-outlined {
        font-size: 48px;
        color: #d1d5db;
        margin-bottom: 8px;
    }


    /* ===== POPUP VISIBILITY FIX: avoid Bootstrap .modal conflict ===== */
    .modal-overlay {
        z-index: 999999 !important;
    }

    .modal-overlay.open {
        display: flex !important;
    }

    .erp-modal {
        display: block !important;
        visibility: visible !important;
        opacity: 1 !important;
        position: relative !important;
        height: auto !important;
        max-height: none !important;
        overflow: hidden !important;
    }

    .modal-overlay.open>.erp-modal {
        display: block !important;
    }

    body.deal-modal-open {
        overflow: hidden;
    }

    @media(max-width:1280px) {
        .stats-grid {
            grid-template-columns: repeat(3, 1fr)
        }

        .summary-grid {
            grid-template-columns: repeat(3, 1fr)
        }
    }

    @media(max-width:768px) {

        .stats-grid,
        .summary-grid {
            grid-template-columns: 1fr
        }

        .form-grid,
        .detail-grid {
            grid-template-columns: 1fr
        }

        .filter-group,
        .filter-group.search {
            min-width: 100%;
        }

        .filter-actions {
            width: 100%
        }

        .filter-actions .btn {
            flex: 1;
            justify-content: center
        }
    }
    
/* =====================================================
   DEALS.PHP DASHBOARD DARK PURPLE / BLACK GLASS THEME
   Paste before 
===================================================== */

html, body{
    height:100%;
}

:root{
    --sidebar-open-width:270px;
    --sidebar-close-width:76px;
    --oms-bg:#070b14;
    --oms-bg-2:#0a1020;
    --oms-border:rgba(148,163,184,.14);
    --oms-text:#f8fafc;
    --oms-muted:#94a3b8;
    --oms-purple:#6d4cff;
    --oms-purple-2:#7c3aed;
}

body{
    --current-sidebar-width:var(--sidebar-open-width);
    margin:0 !important;
    font-family:"Inter","Segoe UI",Tahoma,Geneva,Verdana,sans-serif !important;
    color:var(--oms-text) !important;
    overflow-x:hidden;
    background:
        radial-gradient(circle at 20% 0%, rgba(109,76,255,.16), transparent 28%),
        radial-gradient(circle at 80% 10%, rgba(59,130,246,.10), transparent 30%),
        linear-gradient(180deg,#070b14 0%,#0a1020 55%,#070b14 100%) !important;
}

body.dashboard-sidebar-collapsed{
    --current-sidebar-width:var(--sidebar-close-width);
}

/* Layout / Sidebar */
.container-scroll{
    display:flex !important;
    min-height:100vh !important;
    align-items:stretch !important;
    background:transparent !important;
}

.sidebar{
    width:var(--current-sidebar-width) !important;
    min-width:var(--current-sidebar-width) !important;
    max-width:var(--current-sidebar-width) !important;
    flex-shrink:0 !important;
    display:flex !important;
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    height:100vh !important;
    z-index:1000 !important;
    background:transparent !important;
    overflow:visible !important;
    transition:width .25s ease,min-width .25s ease,max-width .25s ease;
}

.sidebar > div{
    width:100% !important;
    min-height:100vh !important;
    height:100vh !important;
}

#dashboardSidebar .sidebar-wrap{
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
}

#dashboardSidebar .sidebar-inner{
    width:100% !important;
    height:100vh !important;
    min-height:100vh !important;
}

#dashboardSidebar .sidebar-menu{
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
        linear-gradient(180deg,#070b14 0%,#0a1020 60%,#070b14 100%) !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .sidebar-brand-text,
body.dashboard-sidebar-collapsed #dashboardSidebar .search-box,
body.dashboard-sidebar-collapsed #dashboardSidebar .menu-title,
body.dashboard-sidebar-collapsed #dashboardSidebar .menu-arrow,
body.dashboard-sidebar-collapsed #dashboardSidebar .submenu-collapse{
    display:none !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .menu-link{
    justify-content:center !important;
    padding:11px 0 !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .menu-main{
    justify-content:center !important;
    gap:0 !important;
}

body.dashboard-sidebar-collapsed #dashboardSidebar .menu-icon{
    margin:0 !important;
}

/* Topbar / Content Background */
.topbar{
    min-height:70px !important;
    height:70px !important;
    background:#070b14 !important;
    border-bottom:1px solid var(--oms-border) !important;
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
        linear-gradient(135deg,#050914 0%,#0b1024 38%,#160f2d 68%,#090d18 100%) !important;
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
    position:relative;
    z-index:1;
}

/* Header */
.page-title{
    font-size:28px !important;
    font-weight:900 !important;
    color:#f8fafc !important;
    margin:0 !important;
    letter-spacing:-.02em;
}

.breadcrumb-custom,
.breadcrumb-custom a{
    color:#94a3b8 !important;
    font-weight:700 !important;
}

.breadcrumb-custom .active{
    color:#c4b5fd !important;
    font-weight:900 !important;
}

/* Buttons */
.btn{
    min-height:42px !important;
    border-radius:12px !important;
    font-weight:900 !important;
    transition:.22s ease !important;
}

.btn-primary,
.btn-success{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    border:none !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

.btn-outline{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
}

.btn-danger{
    background:linear-gradient(135deg,#ef4444,#be123c) !important;
    color:#fff !important;
    border:none !important;
    box-shadow:0 14px 28px rgba(239,68,68,.22) !important;
}

.btn:hover{
    transform:translateY(-1px);
    color:#fff !important;
    filter:saturate(1.06);
}

/* Alerts */
.alert{
    border-radius:16px !important;
    border:1px solid rgba(148,163,184,.16) !important;
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.76), rgba(17,24,39,.58)) !important;
    color:#e5e7eb !important;
    box-shadow:0 16px 36px rgba(0,0,0,.26);
}

.alert-success{
    border-color:rgba(34,197,94,.25) !important;
}

.alert-danger{
    border-color:rgba(239,68,68,.25) !important;
}

/* Stats Cards */
.stats-grid{
    display:grid !important;
    grid-template-columns:repeat(5,1fr) !important;
    gap:16px !important;
    margin-bottom:22px !important;
}

.stat-card{
    min-height:138px !important;
    border-radius:18px !important;
    padding:20px 22px !important;
    color:#fff !important;
    position:relative !important;
    overflow:hidden !important;
    border:1px solid rgba(255,255,255,.10) !important;
    box-shadow:0 18px 40px rgba(0,0,0,.28) !important;
    isolation:isolate;
    transition:.25s ease !important;
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
}

.stat-card:hover{
    transform:translateY(-4px);
    border-color:rgba(255,255,255,.22) !important;
    filter:saturate(1.08);
    box-shadow:
        0 26px 65px rgba(0,0,0,.46),
        0 0 48px rgba(124,58,237,.18) !important;
}

.stat-card:nth-child(1){background:linear-gradient(135deg,#2563eb,#6d4cff) !important;}
.stat-card:nth-child(2){background:linear-gradient(135deg,#047857,#16a34a) !important;}
.stat-card:nth-child(3){background:linear-gradient(135deg,#0891b2,#06b6d4) !important;}
.stat-card:nth-child(4){background:linear-gradient(135deg,#f59e0b,#fb923c) !important;}
.stat-card:nth-child(5){background:linear-gradient(135deg,#be123c,#ef4444) !important;}

.stat-label{
    font-size:14px !important;
    font-weight:800 !important;
    color:rgba(255,255,255,.88) !important;
    position:relative;
    z-index:2;
}

.stat-value{
    font-size:30px !important;
    font-weight:900 !important;
    line-height:1.15 !important;
    color:#fff !important;
    position:relative;
    z-index:2;
}

.stat-value.small{
    font-size:18px !important;
    line-height:1.3 !important;
}

.stat-sub{
    color:rgba(255,255,255,.82) !important;
    font-weight:700 !important;
    position:relative;
    z-index:2;
}

/* Glass Cards */
.card,
.filters-card,
.table-card,
.summary-card{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.14), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.62), rgba(17,24,39,.46)) !important;
    border:1px solid rgba(148,163,184,.16) !important;
    backdrop-filter:blur(18px) saturate(135%);
    -webkit-backdrop-filter:blur(18px) saturate(135%);
    box-shadow:
        0 18px 48px rgba(0,0,0,.26),
        inset 0 1px 0 rgba(255,255,255,.045) !important;
    border-radius:18px !important;
    color:#e5e7eb !important;
}

.card:hover,
.filters-card:hover,
.table-card:hover,
.summary-card:hover{
    border-color:rgba(139,92,246,.42) !important;
    box-shadow:
        0 24px 65px rgba(0,0,0,.45),
        0 0 42px rgba(124,58,237,.16),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.filters-card{
    padding:16px !important;
}

/* Filters */
.filter-group label{
    color:#f8fafc !important;
    font-weight:900 !important;
    font-size:12px !important;
}

.filter-input,
.filter-select{
    height:46px !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:12px !important;
    outline:none !important;
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    font-size:14px !important;
    font-weight:700 !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
}

.filter-input::placeholder{
    color:#94a3b8 !important;
}

.filter-input:focus,
.filter-select:focus{
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:0 0 0 3px rgba(109,76,255,.14) !important;
    background:#111827 !important;
    color:#fff !important;
}

.filter-select option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

/* Table */
.table-header{
    background:rgba(7,11,20,.28) !important;
    border-bottom:1px solid rgba(148,163,184,.14) !important;
}

.table-title{
    color:#f8fafc !important;
    font-weight:900 !important;
}

.table-wrap,
table,
tbody,
tbody tr{
    background:transparent !important;
}

table{
    border-collapse:separate !important;
    border-spacing:0 !important;
}

thead th{
    background:rgba(7,11,20,.58) !important;
    color:#cbd5e1 !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    border-right:1px solid rgba(148,163,184,.08) !important;
    font-size:13px !important;
    font-weight:900 !important;
    text-transform:uppercase;
    letter-spacing:.04em;
    padding:14px 12px !important;
    white-space:nowrap;
}

tbody td{
    background:rgba(15,23,42,.22) !important;
    color:#e5e7eb !important;
    border-bottom:1px solid rgba(148,163,184,.10) !important;
    border-top:none !important;
    padding:14px 12px !important;
    vertical-align:middle;
    font-size:14px !important;
    font-weight:600;
    white-space:nowrap;
}

/* White hover remove */
tbody tr:hover,
tbody tr:hover td,
tbody td:hover{
    background:rgba(124,58,237,.12) !important;
    color:#ffffff !important;
}

tbody tr:focus,
tbody tr:active,
tbody td:focus,
tbody td:active,
tbody td:focus-visible{
    background:rgba(124,58,237,.14) !important;
    color:#ffffff !important;
    outline:none !important;
    box-shadow:none !important;
}

tbody td.text-center,
tbody td.py-4,
tbody td[colspan],
.empty-state{
    background:rgba(15,23,42,.18) !important;
    color:#94a3b8 !important;
}

table ::selection{
    background:rgba(124,58,237,.35) !important;
    color:#ffffff !important;
}

/* Table text */
.deal-id{
    color:#c4b5fd !important;
    font-weight:900 !important;
}

.client-name,
.amount,
.agent-name,
.summary-value,
tbody div[style*="font-weight:800"]{
    color:#f8fafc !important;
}

.property-name,
.pagination-info,
tbody div[style*="color:#94a3b8"]{
    color:#94a3b8 !important;
}

.paid,
.summary-value.green{
    color:#4ade80 !important;
}

.pending,
.summary-value.orange{
    color:#fbbf24 !important;
}

.summary-value.purple{
    color:#c4b5fd !important;
}

.summary-value.blue{
    color:#60a5fa !important;
}

/* Badges */
.badge{
    border:1px solid rgba(148,163,184,.18) !important;
}

.badge-green{
    background:rgba(34,197,94,.14) !important;
    color:#4ade80 !important;
    border-color:rgba(34,197,94,.24) !important;
}

.badge-blue,
.badge-teal{
    background:rgba(59,130,246,.14) !important;
    color:#60a5fa !important;
    border-color:rgba(59,130,246,.24) !important;
}

.badge-orange,
.badge-yellow{
    background:rgba(245,158,11,.14) !important;
    color:#fbbf24 !important;
    border-color:rgba(245,158,11,.24) !important;
}

.badge-red{
    background:rgba(239,68,68,.14) !important;
    color:#f87171 !important;
    border-color:rgba(239,68,68,.24) !important;
}

.badge-purple{
    background:rgba(109,76,255,.16) !important;
    color:#c4b5fd !important;
    border-color:rgba(109,76,255,.24) !important;
}

/* Actions */
.icon-btn{
    width:36px !important;
    height:36px !important;
    border:none !important;
    border-radius:12px !important;
    background:rgba(15,23,42,.62) !important;
    box-shadow:0 12px 24px rgba(0,0,0,.16) !important;
    transition:.22s ease !important;
}

.icon-view{color:#60a5fa !important;}
.icon-edit{color:#fbbf24 !important;}
.icon-del{color:#f87171 !important;}

.icon-btn:hover{
    transform:translateY(-2px);
    background:rgba(109,76,255,.20) !important;
    color:#fff !important;
}

/* Pagination */
.pagination-wrap{
    background:rgba(7,11,20,.28) !important;
    border-top:1px solid rgba(148,163,184,.14) !important;
}

.page-btn{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.18) !important;
    border-radius:10px !important;
}

.page-btn.active{
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#fff !important;
    border-color:rgba(139,92,246,.42) !important;
}

.page-btn.disabled{
    opacity:.45 !important;
}

/* Summary cards */
.summary-grid{
    display:grid !important;
    grid-template-columns:repeat(5,1fr) !important;
    gap:14px !important;
}

.summary-card{
    padding:16px !important;
    text-align:center !important;
}

.summary-label{
    color:#94a3b8 !important;
    font-size:12px !important;
    font-weight:900 !important;
}

/* =====================================================
   DEALS POPUPS - DARK PURPLE GLASS COMPACT
===================================================== */

.modal-overlay{
    background:rgba(0,0,0,.74) !important;
    backdrop-filter:blur(4px) !important;
    z-index:999999 !important;
}

.modal-overlay.open{
    display:flex !important;
}

.erp-modal,
#deal-modal-overlay .erp-modal,
#view-modal-overlay .erp-modal,
#confirm-modal-overlay .erp-modal{
    background:
        radial-gradient(circle at 20% 0%, rgba(124,58,237,.20), transparent 34%),
        linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.92)) !important;
    color:#ffffff !important;
    border:1px solid rgba(139,92,246,.42) !important;
    border-radius:16px !important;
    overflow:hidden !important;
    box-shadow:0 32px 90px rgba(0,0,0,.70) !important;
    backdrop-filter:blur(18px) !important;
    -webkit-backdrop-filter:blur(18px) !important;
}

#deal-modal-overlay .erp-modal{
    max-width:760px !important;
}

#view-modal-overlay .erp-modal{
    max-width:760px !important;
}

#confirm-modal-overlay .erp-modal{
    max-width:420px !important;
}

.modal-header{
    height:auto !important;
    min-height:58px !important;
    background:rgba(7,11,20,.42) !important;
    border-bottom:1px solid rgba(148,163,184,.16) !important;
    padding:14px 18px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
}

.modal-header h2{
    color:#ffffff !important;
    font-size:18px !important;
    font-weight:900 !important;
    letter-spacing:-.4px !important;
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    margin:0 !important;
}

.modal-header h2::before{
    content:'' !important;
    width:10px !important;
    height:10px !important;
    border-radius:50% !important;
    background:#7c3aed !important;
    box-shadow:0 0 0 4px rgba(124,58,237,.18) !important;
    display:inline-block !important;
    flex-shrink:0 !important;
}

.modal-close{
    position:relative !important;
    width:38px !important;
    height:38px !important;
    min-width:38px !important;
    min-height:38px !important;
    border-radius:10px !important;
    background:rgba(15,23,42,.82) !important;
    border:1px solid rgba(255,255,255,.14) !important;
    color:#ffffff !important;
    opacity:1 !important;
    padding:0 !important;
    box-shadow:none !important;
}

.modal-close .material-symbols-outlined{
    font-size:22px !important;
    color:#fff !important;
}

.modal-close:hover{
    background:#ef4444 !important;
    transform:translateY(-1px);
}

.modal-body{
    background:transparent !important;
    color:#ffffff !important;
    padding:16px 18px 14px !important;
    max-height:68vh !important;
    overflow-y:auto !important;
}

.modal-footer{
    background:rgba(7,11,20,.28) !important;
    border-top:1px solid rgba(148,163,184,.16) !important;
    padding:12px 18px 14px !important;
    display:flex !important;
    justify-content:flex-end !important;
    gap:10px !important;
}

.form-label,
.form-group label{
    color:#f8fafc !important;
    font-size:12px !important;
    font-weight:900 !important;
    margin-bottom:5px !important;
}

.req{
    color:#fbbf24 !important;
}

.form-control,
#deal-modal-overlay .form-control,
#view-modal-overlay .form-control,
#confirm-modal-overlay .form-control{
    background:rgba(15,23,42,.72) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.20) !important;
    border-radius:10px !important;
    font-size:13px !important;
    font-weight:700 !important;
    min-height:40px !important;
    height:40px !important;
    padding:7px 12px !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
}

textarea.form-control{
    min-height:90px !important;
    height:auto !important;
    resize:vertical !important;
}

.form-control[readonly]{
    background:rgba(15,23,42,.52) !important;
    color:#94a3b8 !important;
}

.form-control::placeholder{
    color:#94a3b8 !important;
}

.form-control:focus{
    background:rgba(15,23,42,.86) !important;
    color:#ffffff !important;
    border-color:rgba(109,76,255,.55) !important;
    box-shadow:
        0 0 0 3px rgba(109,76,255,.14),
        0 10px 24px rgba(0,0,0,.22) !important;
    outline:none !important;
}

select.form-control option{
    background:#0f172a !important;
    color:#e5e7eb !important;
}

.commission-row{
    gap:10px !important;
}

.commission-amount{
    min-height:40px !important;
    height:40px !important;
    background:rgba(109,76,255,.16) !important;
    border:1px solid rgba(109,76,255,.24) !important;
    color:#c4b5fd !important;
    border-radius:10px !important;
    padding:0 12px !important;
    font-size:13px !important;
    font-weight:900 !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
}

.detail-grid{
    color:#ffffff !important;
}

.detail-item{
    background:rgba(15,23,42,.62) !important;
    color:#e5e7eb !important;
    border:1px solid rgba(148,163,184,.16) !important;
    border-radius:12px !important;
    padding:12px 14px !important;
    box-shadow:0 10px 24px rgba(0,0,0,.20) !important;
}

.detail-label{
    color:#94a3b8 !important;
    font-size:12px !important;
    font-weight:900 !important;
    text-transform:uppercase !important;
    letter-spacing:.35px !important;
}

.detail-value,
.detail-value[style]{
    color:#f8fafc !important;
    font-weight:900 !important;
}

/* Delete modal */
#confirm-modal-overlay .modal-body{
    text-align:center !important;
}

#confirm-modal-overlay .modal-body h3{
    color:#ffffff !important;
}

#confirm-modal-overlay .modal-body p{
    color:#94a3b8 !important;
}

#confirm-modal-overlay .modal-body .material-symbols-outlined{
    color:#f87171 !important;
}

/* Modal buttons */
.modal-footer .btn-outline,
.modal-footer .btn-secondary{
    min-height:38px !important;
    height:38px !important;
    min-width:105px !important;
    border:none !important;
    border-radius:10px !important;
    background:rgba(15,23,42,.82) !important;
    color:#e5e7eb !important;
    font-weight:900 !important;
    font-size:13px !important;
    padding:7px 14px !important;
}

.modal-footer .btn-primary,
.modal-footer .btn-success{
    min-height:38px !important;
    height:38px !important;
    min-width:125px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(135deg,#6d4cff,#7c3aed) !important;
    color:#ffffff !important;
    font-weight:900 !important;
    font-size:13px !important;
    padding:7px 14px !important;
    box-shadow:0 14px 28px rgba(109,76,255,.24) !important;
}

.modal-footer .btn-danger,
#confirm-modal-overlay .btn-danger{
    min-height:38px !important;
    height:38px !important;
    min-width:115px !important;
    border:none !important;
    border-radius:10px !important;
    background:linear-gradient(135deg,#ef4444,#be123c) !important;
    color:#ffffff !important;
    font-weight:900 !important;
    font-size:13px !important;
    padding:7px 14px !important;
    box-shadow:0 14px 28px rgba(239,68,68,.24) !important;
}

.modal-footer .btn:hover{
    transform:translateY(-1px);
}

/* Modal scrollbar */
.modal-body::-webkit-scrollbar,
.table-wrap::-webkit-scrollbar,
.content-wrapper::-webkit-scrollbar{
    height:10px;
    width:8px;
}

.modal-body::-webkit-scrollbar-track,
.table-wrap::-webkit-scrollbar-track,
.content-wrapper::-webkit-scrollbar-track{
    background:rgba(15,23,42,.35);
    border-radius:999px;
}

.modal-body::-webkit-scrollbar-thumb,
.table-wrap::-webkit-scrollbar-thumb,
.content-wrapper::-webkit-scrollbar-thumb{
    background:rgba(148,163,184,.35);
    border-radius:999px;
}

.modal-body::-webkit-scrollbar-thumb:hover,
.table-wrap::-webkit-scrollbar-thumb:hover,
.content-wrapper::-webkit-scrollbar-thumb:hover{
    background:rgba(124,58,237,.55);
}

@media(max-width:1280px){
    .stats-grid,
    .summary-grid{
        grid-template-columns:repeat(3,1fr) !important;
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
        display:flex !important;
    }

    #dashboardSidebar .sidebar-wrap{
        position:relative !important;
        width:100% !important;
        min-width:100% !important;
        max-width:100% !important;
        height:auto !important;
        min-height:auto !important;
    }

    #dashboardSidebar .sidebar-inner{
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
    .stats-grid,
    .summary-grid,
    .form-grid,
    .detail-grid{
        grid-template-columns:1fr !important;
    }

    .page-header{
        align-items:flex-start !important;
        flex-direction:column !important;
    }

    .page-actions,
    .page-actions .btn{
        width:100% !important;
    }

    .page-actions .btn{
        justify-content:center !important;
    }

    .page-title{
        font-size:24px !important;
    }

    .filter-group,
    .filter-group.search{
        min-width:100% !important;
    }

    .filter-actions{
        width:100% !important;
        flex-direction:column !important;
    }

    .filter-actions .btn{
        width:100% !important;
        justify-content:center !important;
    }

    .erp-modal{
        max-width:96vw !important;
        margin:.75rem auto !important;
    }

    .modal-header h2{
        font-size:18px !important;
    }

    .modal-footer{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .modal-footer .btn,
    .modal-footer form,
    .modal-footer form .btn{
        width:100% !important;
        justify-content:center !important;
    }
}

</style>
</head>

<body>
    <div class="container-scroll">
        <div class="sidebar">
            <?php include('partials/sidebar.php'); ?>
        </div>

        <div class="main-panel">
            <div class="topbar">
                <?php include('partials/topbar.php'); ?>
            </div>

            <div class="content-wrapper">
                <?php if ($msg !== ''): ?>
                <div class="alert alert-<?= e($msgType) ?>">
                    <?= e($msg) ?>
                </div>
                <?php endif; ?>

                <div class="page-header">
                    <div>
                        <div class="page-title">Deals / Conversions</div>
                        <div class="breadcrumb-custom">
                            <a href="dashboard.php">Home</a><span>›</span>
                            <a href="agency-lead.php">Real Estate</a><span>›</span>
                            <span class="active">Deals</span>
                        </div>
                    </div>

                    <div class="page-actions">
                        <a class="btn btn-outline"
                            href="deals.php?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">
                            <span class="material-symbols-outlined">download</span>Export
                        </a>
                        <button class="btn btn-primary" type="button" onclick="openAddModal()">
                            <span class="material-symbols-outlined">add</span>Add New Deal
                        </button>
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-label">Total Deals</div>
                        <div class="stat-value"><?= (int)$totalDeals ?></div>
                        <div class="stat-sub">All deal records</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-label">Total Deal Value</div>
                        <div class="stat-value small"><?= e(moneyFmt($totalDealValue)) ?></div>
                        <div class="stat-sub">Gross deal amount</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-label">This Month Deals</div>
                        <div class="stat-value"><?= (int)$thisMonthDeals ?></div>
                        <div class="stat-sub">Current month</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-label">Converted Leads</div>
                        <div class="stat-value"><?= (int)$convertedLeads ?></div>
                        <div class="stat-sub">Lead status Converted</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-label">Total Commission</div>
                        <div class="stat-value small"><?= e(moneyFmt($totalCommissionValue)) ?></div>
                        <div class="stat-sub">Auto calculated</div>
                    </div>
                </div>

                <form class="card filters-card" method="GET" action="deals.php">
                    <div class="filters-row">
                        <div class="filter-group search">
                            <label>Search</label>
                            <input class="filter-input" type="text" name="search"
                                placeholder="Client, property, agent..." value="<?= e($search) ?>">
                        </div>

                        <div class="filter-group">
                            <label>Status</label>
                            <select class="filter-select" name="status">
                                <option value="">All Status</option>
                                <?php foreach ($dealStatuses as $st): ?>
                                <option value="<?= e($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>>
                                    <?= e($st) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label>Agent</label>
                            <select class="filter-select" name="agent_id">
                                <option value="">All Agents</option>
                                <?php foreach ($agents as $agent): ?>
                                <option value="<?= (int)$agent['id'] ?>"
                                    <?= $agentFilter === (int)$agent['id'] ? 'selected' : '' ?>>
                                    <?= e($agent['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label>Project</label>
                            <select class="filter-select" name="project_id">
                                <option value="">All Projects</option>
                                <?php foreach ($projects as $project): ?>
                                <option value="<?= (int)$project['id'] ?>"
                                    <?= $projectFilter === (int)$project['id'] ? 'selected' : '' ?>>
                                    <?= e($project['project_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label>From Date</label>
                            <input class="filter-input" type="date" name="date_from" value="<?= e($dateFrom) ?>">
                        </div>

                        <div class="filter-group">
                            <label>To Date</label>
                            <input class="filter-input" type="date" name="date_to" value="<?= e($dateTo) ?>">
                        </div>

                        <div class="filter-group" style="max-width:100px;">
                            <label>Rows</label>
                            <select class="filter-select" name="per_page">
                                <?php foreach ([10,20,50,100] as $pp): ?>
                                <option value="<?= $pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-actions">
                            <button class="btn btn-primary" type="submit">
                                <span class="material-symbols-outlined">filter_list</span>Filter
                            </button>
                            <a class="btn btn-outline" href="deals.php">
                                <span class="material-symbols-outlined">restart_alt</span>Reset
                            </a>
                        </div>
                    </div>
                </form>

                <div class="card table-card">
                    <div class="table-header">
                        <div class="table-title">Deals List <span
                                style="font-size:12px;color:#94a3b8;">(<?= (int)$totalFiltered ?> records)</span></div>
                    </div>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Deal ID</th>
                                    <th>Client Name</th>
                                    <th>Property / Project</th>
                                    <th>Deal Amount</th>
                                    <th>Paid Amount</th>
                                    <th>Balance</th>
                                    <th>Status</th>
                                    <th>Agent</th>
                                    <th>Deal Date</th>
                                    <th>Expected Closing</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if (empty($deals)): ?>
                                <tr>
                                    <td colspan="11">
                                        <div class="empty-state">
                                            <span class="material-symbols-outlined">search_off</span>
                                            <p>No deals found.</p>
                                        </div>
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($deals as $d): ?>
                                <tr>
                                    <td><span class="deal-id"><?= e($d['deal_no']) ?></span></td>
                                    <td>
                                        <div class="client-name"><?= e($d['lead_name'] ?: 'Unknown Lead') ?></div>
                                        <div style="font-size:11px;color:#94a3b8;"><?= e($d['lead_phone'] ?? '') ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight:800;color:#1e293b;">
                                            <?= e($d['property_title'] ?: 'Unknown Property') ?></div>
                                        <span
                                            class="property-name"><?= e(($d['project_name'] ?: 'No Project') . ' • ' . ($d['property_city'] ?: '') . ' ' . ($d['area_location'] ?: '')) ?></span>
                                    </td>
                                    <td><span class="amount"><?= e(moneyFmt($d['deal_amount'])) ?></span></td>
                                    <td><span class="paid"><?= e(moneyFmt($d['paid_amount'])) ?></span></td>
                                    <td><span class="pending"><?= e(moneyFmt($d['balance_amount'])) ?></span></td>
                                    <td><span
                                            class="badge <?= e(badgeClass($d['status'])) ?>"><?= e($d['status']) ?></span>
                                    </td>
                                    <td><span class="agent-name"><?= e($d['agent_name'] ?: 'Unassigned') ?></span></td>
                                    <td><?= e($d['deal_date']) ?></td>
                                    <td><?= e($d['expected_closing_date'] ?: '-') ?></td>
                                    <td>
                                        <div class="actions">
                                            <button class="icon-btn icon-view" type="button"
                                                onclick="viewDeal(<?= (int)$d['id'] ?>)" title="View">
                                                <span class="material-symbols-outlined">visibility</span>
                                            </button>
                                            <button class="icon-btn icon-edit" type="button"
                                                onclick="editDeal(<?= (int)$d['id'] ?>)" title="Edit">
                                                <span class="material-symbols-outlined">edit</span>
                                            </button>
                                            <button class="icon-btn icon-del" type="button"
                                                onclick="askDelete(<?= (int)$d['id'] ?>)" title="Delete">
                                                <span class="material-symbols-outlined">delete</span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="pagination-wrap">
                        <div class="pagination-info">
                            Showing <?= $totalFiltered > 0 ? (int)$offset + 1 : 0 ?> to
                            <?= min((int)$offset + $perPage, (int)$totalFiltered) ?> of <?= (int)$totalFiltered ?>
                            entries
                        </div>

                        <div class="pagination-btns">
                            <a class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>"
                                href="<?= e(pageUrl($page - 1, $queryBase)) ?>">‹</a>
                            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <?php if ($p === 1 || $p === $totalPages || abs($p - $page) <= 1): ?>
                            <a class="page-btn <?= $p === $page ? 'active' : '' ?>"
                                href="<?= e(pageUrl($p, $queryBase)) ?>"><?= (int)$p ?></a>
                            <?php elseif (abs($p - $page) === 2): ?>
                            <span style="padding:0 4px;color:#9ca3af;">...</span>
                            <?php endif; ?>
                            <?php endfor; ?>
                            <a class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>"
                                href="<?= e(pageUrl($page + 1, $queryBase)) ?>">›</a>
                        </div>
                    </div>
                </div>

                <div class="summary-grid">
                    <div class="summary-card">
                        <div class="summary-label">Total Deals</div>
                        <div class="summary-value blue"><?= (int)$totalDeals ?></div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-label">Total Deal Value</div>
                        <div class="summary-value"><?= e(moneyFmt($totalDealValue)) ?></div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-label">Total Paid Amount</div>
                        <div class="summary-value green"><?= e(moneyFmt($totalPaidValue)) ?></div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-label">Total Pending Amount</div>
                        <div class="summary-value orange"><?= e(moneyFmt($totalPendingValue)) ?></div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-label">Total Commission</div>
                        <div class="summary-value purple"><?= e(moneyFmt($totalCommissionValue)) ?></div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- ADD / EDIT DEAL MODAL -->
    <div class="modal-overlay" id="deal-modal-overlay">
        <div class="erp-modal">
            <div class="modal-header">
                <h2 id="modal-title">Add New Deal</h2>
                <button class="modal-close" type="button" onclick="closeDealModal()">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>

            <form method="POST" id="dealForm">
                <input type="hidden" name="action" value="save_deal">
                <input type="hidden" name="id" id="m-id">
                <input type="hidden" name="deal_no" id="m-deal-no">

                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Lead / Customer <span class="req">*</span></label>
                            <select class="form-control" name="lead_id" id="m-lead" required
                                onchange="autoSelectLeadAgent()">
                                <option value="">Select Lead</option>
                                <?php foreach ($leads as $lead): ?>
                                <option value="<?= (int)$lead['id'] ?>">
                                    <?= e($lead['lead_name']) ?><?= !empty($lead['phone']) ? ' - ' . e($lead['phone']) : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Property <span class="req">*</span></label>
                            <select class="form-control" name="property_id" id="m-property" required
                                onchange="autoFillPropertyAmount()">
                                <option value="">Select Property</option>
                                <?php foreach ($properties as $p): ?>
                                <option value="<?= (int)$p['id'] ?>">
                                    <?= e($p['title']) ?><?= !empty($p['city']) ? ' - ' . e($p['city']) : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Deal Amount (PKR) <span class="req">*</span></label>
                            <input class="form-control" name="deal_amount" id="m-amount" type="text"
                                placeholder="6500000" required oninput="calcDeal()">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Token Amount</label>
                            <input class="form-control" name="token_amount" id="m-token" type="text"
                                placeholder="500000" oninput="calcDeal()">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Paid Amount (PKR) <span class="req">*</span></label>
                            <input class="form-control" name="paid_amount" id="m-paid" type="text" placeholder="0"
                                required oninput="calcDeal()">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Balance Amount</label>
                            <input class="form-control" id="m-balance" type="text" readonly placeholder="Auto">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Status <span class="req">*</span></label>
                            <select class="form-control" name="status" id="m-status" required>
                                <option value="">Select Status</option>
                                <?php foreach ($dealStatuses as $st): ?>
                                <option value="<?= e($st) ?>"><?= e($st) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Agent <span class="req">*</span></label>
                            <select class="form-control" name="agent_id" id="m-agent" required>
                                <option value="">Select Agent</option>
                                <?php foreach ($agents as $agent): ?>
                                <option value="<?= (int)$agent['id'] ?>">
                                    <?= e($agent['name']) ?><?= !empty($agent['role_name']) ? ' - ' . e($agent['role_name']) : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Deal Date <span class="req">*</span></label>
                            <input class="form-control" name="deal_date" id="m-date" type="date" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Expected Closing</label>
                            <input class="form-control" name="expected_closing_date" id="m-closing" type="date">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Commission %</label>
                            <div class="commission-row">
                                <input class="form-control" name="commission_percent" id="m-commission-pct"
                                    type="number" placeholder="2" min="0" max="100" step="0.1" oninput="calcDeal()">
                                <div class="commission-amount" id="m-commission-amt">PKR 0</div>
                            </div>
                        </div>

                        <div class="form-group full">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" id="m-notes"
                                placeholder="Additional notes about this deal..."></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button class="btn btn-outline" type="button" onclick="closeDealModal()">Cancel</button>
                    <button class="btn btn-primary" type="submit">
                        <span class="material-symbols-outlined">save</span>Save Deal
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- VIEW MODAL -->
    <div class="modal-overlay" id="view-modal-overlay">
        <div class="erp-modal">
            <div class="modal-header">
                <h2>Deal Details</h2>
                <button class="modal-close" type="button" onclick="closeViewModal()">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>

            <div class="modal-body" id="view-modal-body"></div>

            <div class="modal-footer">
                <button class="btn btn-outline" type="button" onclick="closeViewModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- DELETE MODAL -->
    <div class="modal-overlay" id="confirm-modal-overlay">
        <div class="erp-modal" style="max-width:420px;">
            <div class="modal-body" style="text-align:center;">
                <span class="material-symbols-outlined" style="font-size:54px;color:#c94b57;">delete_forever</span>
                <h3 style="font-size:18px;font-weight:900;margin:10px 0 6px;">Delete Deal?</h3>
                <p style="font-size:13px;color:#64748b;line-height:1.5;">This action cannot be undone. Commission record
                    will also be removed.</p>
            </div>

            <div class="modal-footer" style="justify-content:center;">
                <button class="btn btn-outline" type="button" onclick="closeConfirmModal()">Cancel</button>
                <form method="POST" id="deleteForm">
                    <input type="hidden" name="action" value="delete_deal">
                    <input type="hidden" name="id" id="delete-id">
                    <button class="btn btn-danger" type="submit">
                        <span class="material-symbols-outlined">delete</span>Delete
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
    const dealsData = <?= $dealsJson ?: '[]' ?>;
    const leadsData = <?= $leadsJson ?: '[]' ?>;
    const propertiesData = <?= $propertiesJson ?: '[]' ?>;

    function byId(id) {
        return document.getElementById(id);
    }

    function moneyNumber(value) {
        return Number(String(value || '').replace(/[^\d.]/g, '')) || 0;
    }

    function formatMoney(n) {
        return 'PKR ' + Number(n || 0).toLocaleString();
    }

    function openAddModal() {
        byId('dealForm').reset();
        byId('m-id').value = '';
        byId('m-deal-no').value = '';
        byId('modal-title').textContent = 'Add New Deal';
        byId('m-date').value = new Date().toISOString().split('T')[0];
        byId('m-status').value = 'Token Paid';
        byId('m-commission-pct').value = '2';
        calcDeal();
        document.body.classList.add('deal-modal-open');
        byId('deal-modal-overlay').classList.add('open');
        setTimeout(() => {
            const body = byId('deal-modal-overlay')?.querySelector('.modal-body');
            if (body) body.scrollTop = 0;
        }, 0);
    }

    function closeDealModal() {
        byId('deal-modal-overlay').classList.remove('open');
        document.body.classList.remove('deal-modal-open');
    }

    function autoSelectLeadAgent() {
        const leadId = parseInt(byId('m-lead').value || 0);
        const lead = leadsData.find(l => parseInt(l.id) === leadId);
        if (lead && lead.assigned_agent_id) {
            byId('m-agent').value = lead.assigned_agent_id;
        }
    }

    function autoFillPropertyAmount() {
        const propertyId = parseInt(byId('m-property').value || 0);
        const prop = propertiesData.find(p => parseInt(p.id) === propertyId);

        if (prop) {
            if (prop.demand_price && Number(prop.demand_price) > 0 && !byId('m-amount').value) {
                byId('m-amount').value = Math.round(Number(prop.demand_price));
            }
            if (prop.assigned_agent_id && !byId('m-agent').value) {
                byId('m-agent').value = prop.assigned_agent_id;
            }
        }

        calcDeal();
    }

    function calcDeal() {
        const amount = moneyNumber(byId('m-amount')?.value);
        const paid = moneyNumber(byId('m-paid')?.value);
        const pct = Number(byId('m-commission-pct')?.value || 0);
        const balance = Math.max(0, amount - paid);
        const commission = (amount * pct) / 100;

        if (byId('m-balance')) byId('m-balance').value = formatMoney(balance);
        if (byId('m-commission-amt')) byId('m-commission-amt').textContent = formatMoney(commission);
    }

    function editDeal(id) {
        const d = dealsData.find(x => parseInt(x.id) === parseInt(id));
        if (!d) return alert('Deal not found.');

        byId('dealForm').reset();
        byId('modal-title').textContent = 'Edit Deal';
        byId('m-id').value = d.id || '';
        byId('m-deal-no').value = d.deal_no || '';
        byId('m-lead').value = d.lead_id || '';
        byId('m-property').value = d.property_id || '';
        byId('m-agent').value = d.agent_id || '';
        byId('m-amount').value = d.deal_amount || '';
        byId('m-token').value = d.token_amount || '';
        byId('m-paid').value = d.paid_amount || '';
        byId('m-status').value = d.status || 'Token Paid';
        byId('m-date').value = d.deal_date || '';
        byId('m-closing').value = d.expected_closing_date || '';
        byId('m-commission-pct').value = d.commission_percent || '';
        byId('m-notes').value = d.notes || '';

        calcDeal();
        document.body.classList.add('deal-modal-open');
        byId('deal-modal-overlay').classList.add('open');
        setTimeout(() => {
            const body = byId('deal-modal-overlay')?.querySelector('.modal-body');
            if (body) body.scrollTop = 0;
        }, 0);
    }

    function viewDeal(id) {
        const d = dealsData.find(x => parseInt(x.id) === parseInt(id));
        if (!d) return alert('Deal not found.');

        byId('view-modal-body').innerHTML = `
        <div class="detail-grid">
            <div class="detail-item"><div class="detail-label">Deal No</div><div class="detail-value">${escapeHtml(d.deal_no || '-')}</div></div>
            <div class="detail-item"><div class="detail-label">Status</div><div class="detail-value">${escapeHtml(d.status || '-')}</div></div>
            <div class="detail-item"><div class="detail-label">Lead / Customer</div><div class="detail-value">${escapeHtml(d.lead_name || '-')}</div></div>
            <div class="detail-item"><div class="detail-label">Agent</div><div class="detail-value">${escapeHtml(d.agent_name || '-')}</div></div>
            <div class="detail-item full"><div class="detail-label">Property</div><div class="detail-value">${escapeHtml(d.property_title || '-')}</div></div>
            <div class="detail-item"><div class="detail-label">Deal Amount</div><div class="detail-value">${formatMoney(d.deal_amount)}</div></div>
            <div class="detail-item"><div class="detail-label">Paid Amount</div><div class="detail-value">${formatMoney(d.paid_amount)}</div></div>
            <div class="detail-item"><div class="detail-label">Balance</div><div class="detail-value">${formatMoney(d.balance_amount)}</div></div>
            <div class="detail-item"><div class="detail-label">Token Amount</div><div class="detail-value">${formatMoney(d.token_amount)}</div></div>
            <div class="detail-item"><div class="detail-label">Commission %</div><div class="detail-value">${escapeHtml(d.commission_percent || '0')}%</div></div>
            <div class="detail-item"><div class="detail-label">Commission Amount</div><div class="detail-value">${formatMoney(d.commission_amount)}</div></div>
            <div class="detail-item"><div class="detail-label">Deal Date</div><div class="detail-value">${escapeHtml(d.deal_date || '-')}</div></div>
            <div class="detail-item"><div class="detail-label">Expected Closing</div><div class="detail-value">${escapeHtml(d.expected_closing_date || '-')}</div></div>
            <div class="detail-item full"><div class="detail-label">Notes</div><div class="detail-value" style="font-weight:600;color:#64748b;line-height:1.5;">${escapeHtml(d.notes || 'No notes')}</div></div>
        </div>
    `;

        document.body.classList.add('deal-modal-open');
        byId('view-modal-overlay').classList.add('open');
    }

    function closeViewModal() {
        byId('view-modal-overlay').classList.remove('open');
        document.body.classList.remove('deal-modal-open');
    }

    function askDelete(id) {
        byId('delete-id').value = id;
        document.body.classList.add('deal-modal-open');
        byId('confirm-modal-overlay').classList.add('open');
    }

    function closeConfirmModal() {
        byId('confirm-modal-overlay').classList.remove('open');
        document.body.classList.remove('deal-modal-open');
    }

    function escapeHtml(str) {
        return String(str || '').replace(/[&<>'"]/g, c => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#039;',
            '"': '&quot;'
        } [c]));
    }

    ['deal-modal-overlay', 'view-modal-overlay', 'confirm-modal-overlay'].forEach(id => {
        byId(id)?.addEventListener('click', e => {
            if (e.target === e.currentTarget) {
                e.currentTarget.classList.remove('open');
                document.body.classList.remove('deal-modal-open');
            }
        });
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            closeDealModal();
            closeViewModal();
            closeConfirmModal();
        }
    });
    </script>
</body>

</html>