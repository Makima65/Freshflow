<?php
// C:\xampp\htdocs\RalphPHP\admin_components\pages\spoilage.php

header("Cache-Control: no-cache, no-store, must-revalidate"); 
header("Pragma: no-cache"); 
header("Expires: 0"); 

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
ob_start();
ini_set('display_errors', 0); // Prevent DB warnings from breaking UI
ini_set('log_errors', 1);

include_once '../includes/db_connection.php';

// --- CONFIGURATION ---
$LOCK_HOURS = 48; // Time limit before a spoilage record is locked for normal staff

// --- SAFE DATABASE AUTO-PATCHER FOR NEW MULTI-IMAGE SPOILAGE FIELDS ---
try {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'spoilage'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $col1 = $conn->query("SHOW COLUMNS FROM spoilage LIKE 'spoilage_type'");
        if ($col1 && $col1->num_rows == 0) { @$conn->query("ALTER TABLE spoilage ADD COLUMN spoilage_type VARCHAR(50) DEFAULT 'Warehouse Rot' AFTER quantity"); }
        
        $col2 = $conn->query("SHOW COLUMNS FROM spoilage LIKE 'spoilage_images'");
        if ($col2 && $col2->num_rows == 0) { @$conn->query("ALTER TABLE spoilage ADD COLUMN spoilage_images TEXT NULL AFTER reason"); }
    }
} catch (Throwable $e) { }

$auditHelperPath = '../includes/audit_helper.php';
if (file_exists($auditHelperPath)) { include_once $auditHelperPath; } 
elseif (!function_exists('log_audit_action')) { function log_audit_action($a, $b, $c, $d = []) { return true; } }

// --- SECURITY CHECK ---
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: ../admin_login.php"); exit;
}

$user_id = $_SESSION['user_id'] ?? 1;
$current_role = $_SESSION['role_name'] ?? 'Staff';

// --- MULTI-PHOTO UPLOAD HELPER ---
function handle_multiple_spoilage_uploads($file_key) {
    $uploaded_paths = [];
    if (isset($_FILES[$file_key])) {
        $total = count($_FILES[$file_key]['name']);
        for ($i = 0; $i < $total; $i++) {
            if ($_FILES[$file_key]['error'][$i] == 0) {
                $target_dir = "../../assets/img/spoilage/";
                if (!is_dir($target_dir)) @mkdir($target_dir, 0777, true);
                
                $file_ext = pathinfo($_FILES[$file_key]["name"][$i], PATHINFO_EXTENSION);
                $file_name = uniqid('waste_') . '_' . time() . '_' . $i . '.' . $file_ext;
                $target_file = $target_dir . $file_name;
                
                $check = @getimagesize($_FILES[$file_key]["tmp_name"][$i]);
                if($check !== false) {
                    if (move_uploaded_file($_FILES[$file_key]["tmp_name"][$i], $target_file)) {
                        $uploaded_paths[] = "assets/img/spoilage/" . $file_name;
                    }
                }
            }
        }
    }
    return !empty($uploaded_paths) ? json_encode($uploaded_paths) : NULL;
}

// --- EXPENSE REVERSAL HELPER ---
function reverse_spoilage_expense($conn, $prod_id, $qty) {
    try {
        $pk_col = 'id';
        $check_col = $conn->query("SHOW COLUMNS FROM expenses LIKE 'id'");
        if ($check_col && $check_col->num_rows == 0) { $pk_col = 'expense_id'; }
        // Deletes the most recent exact match to safely reverse the financial loss
        $conn->query("DELETE FROM expenses WHERE category = 'Spoilage Loss' AND product_id = $prod_id AND quantity = $qty ORDER BY $pk_col DESC LIMIT 1");
    } catch (Throwable $e) { /* Failsafe */ }
}

// --- HANDLERS ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] == 'get_spoilage') {
    ob_clean(); header('Content-Type: application/json');
    $id = intval($_GET['id']);
    
    $query = "
        SELECT sp.*, p.name, p.unit, p.product_brand 
        FROM spoilage sp 
        JOIN products p ON sp.product_id = p.product_id 
        WHERE sp.spoilage_id = $id LIMIT 1
    ";
    $res = $conn->query($query)->fetch_assoc();
    
    echo json_encode(['success' => true, 'data' => $res]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean(); header('Content-Type: application/json');
    $action = $_POST['action_type'] ?? '';

    // 1. UNDO SPOILAGE
    if ($action === 'undo_spoilage') {
        $id = intval($_POST['spoilage_id']);
        $conn->begin_transaction();
        try {
            $record = $conn->query("SELECT product_id, quantity, spoilage_date FROM spoilage WHERE spoilage_id = $id")->fetch_assoc();
            if (!$record) throw new Exception("Record not found.");
            
            // Backend Time Lock Check (Super Admins bypass this)
            $hours_passed = (time() - strtotime($record['spoilage_date'])) / 3600;
            if ($hours_passed > $LOCK_HOURS && $current_role !== 'Super Admin') {
                throw new Exception("Record is locked. Cannot undo after {$LOCK_HOURS} hours.");
            }

            $prod_id = $record['product_id'];
            $qty = floatval($record['quantity']);

            $conn->query("DELETE FROM spoilage WHERE spoilage_id = $id");
            $conn->query("UPDATE product_inventory SET quantity = quantity + $qty WHERE product_id = $prod_id");

            // --- REVERSE FINANCIAL EXPENSE ---
            reverse_spoilage_expense($conn, $prod_id, $qty);

            $conn->commit();
            log_audit_action('Undo Spoilage', 'Inventory', "Reversed spoilage ID: $id, restored $qty units to Product ID: $prod_id. Financial loss reversed.");
            echo json_encode(['success' => true, 'message' => 'Spoilage reversed, inventory restored, and financial loss removed.']);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Failed to undo: ' . $e->getMessage()]);
        }
        exit;
    }

    // 2. LOG NEW SPOILAGE
    if ($action === 'log_spoilage') {
        $product_id = intval($_POST['product_id']);
        $qty = floatval($_POST['quantity']);
        $spoilage_type = trim($_POST['spoilage_type']);
        $reason = trim($_POST['reason']);

        if ($product_id <= 0 || $qty <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid product or quantity.']); exit;
        }

        $spoilage_images = handle_multiple_spoilage_uploads('spoilage_images');

        $conn->begin_transaction();
        try {
            // Get current stock AND price to log financial loss
            $prodQuery = "SELECT p.name, p.unit, p.price, pi.quantity FROM products p LEFT JOIN product_inventory pi ON p.product_id = pi.product_id WHERE p.product_id = $product_id";
            $stockCheck = $conn->query($prodQuery)->fetch_assoc();
            
            if (!$stockCheck || $stockCheck['quantity'] < $qty) {
                throw new Exception("Not enough stock in inventory. Current stock is " . ($stockCheck['quantity'] ?? 0));
            }

            // Backend safety check: Prevent decimals for non-decimal units
            $decimalUnits = ['kg', 'g', 'liter', 'l', 'ml'];
            if (!empty($stockCheck['unit']) && !in_array(strtolower($stockCheck['unit']), $decimalUnits)) {
                if (fmod($qty, 1) !== 0.0) {
                    throw new Exception("Decimal quantities are not allowed for unit: " . $stockCheck['unit'] . ".");
                }
            }

            $stmt = $conn->prepare("INSERT INTO spoilage (product_id, quantity, spoilage_type, reason, spoilage_images, recorded_by) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("idsssi", $product_id, $qty, $spoilage_type, $reason, $spoilage_images, $user_id);
            if (!$stmt->execute()) { throw new Exception("Database error inserting record."); }

            $new_spoilage_id = $stmt->insert_id;

            $stmt_inv = $conn->prepare("UPDATE product_inventory SET quantity = quantity - ? WHERE product_id = ?");
            $stmt_inv->bind_param("di", $qty, $product_id);
            $stmt_inv->execute();

            // --- AUTO-LOG TO EXPENSES ---
            $exp_date = date('Y-m-d');
            $exp_cat = 'Spoilage Loss';
            $exp_amount = $qty * floatval($stockCheck['price'] ?? 0); // Calculate true loss
            
            $unit = !empty($stockCheck['unit']) ? strtolower($stockCheck['unit']) : '';
            $formatted_qty = fmod($qty, 1) == 0 ? number_format($qty, 0) : number_format($qty, 2);
            $exp_desc = "Spoilage ($spoilage_type): {$formatted_qty}{$unit} " . $stockCheck['name'] . ". " . ($reason ? "Reason: $reason" : "");
            
            if ($exp_amount > 0) {
                $exp_sql = "INSERT INTO expenses (expense_date, category, amount, quantity, payment_method, description, product_id) VALUES (?, ?, ?, ?, 'Other', ?, ?)";
                try {
                    if ($stmt_exp = $conn->prepare($exp_sql)) {
                        $stmt_exp->bind_param("ssddsi", $exp_date, $exp_cat, $exp_amount, $qty, $exp_desc, $product_id);
                        $stmt_exp->execute();
                        $stmt_exp->close();
                    }
                } catch(Throwable $e) { /* Failsafe */ }
            }

            $conn->commit();
            log_audit_action('Log Spoilage', 'Inventory', "Logged $qty units of Product ID $product_id as $spoilage_type. Financial loss recorded.");
            echo json_encode(['success' => true, 'message' => 'Spoilage logged, inventory deducted, and financial loss recorded.']);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // 3. EDIT SPOILAGE RECORD (APPEND/DELETE PICS)
    if ($action === 'edit_spoilage') {
        $id = intval($_POST['edit_spoilage_id']);
        $spoilage_type = trim($_POST['edit_spoilage_type']);
        $reason = trim($_POST['edit_reason']);

        $curr_record = $conn->query("SELECT product_id, quantity, spoilage_images, spoilage_date FROM spoilage WHERE spoilage_id = $id")->fetch_assoc();
        
        if (!$curr_record) {
            echo json_encode(['success' => false, 'message' => "Record not found."]); exit;
        }

        // Backend Time Lock Check (Super Admins bypass this)
        $hours_passed = (time() - strtotime($curr_record['spoilage_date'])) / 3600;
        if ($hours_passed > $LOCK_HOURS && $current_role !== 'Super Admin') {
            echo json_encode(['success' => false, 'message' => "Record is locked. Cannot edit after {$LOCK_HOURS} hours."]); exit;
        }

        $current_images = [];
        if (!empty($curr_record['spoilage_images'])) {
            $decoded = json_decode($curr_record['spoilage_images'], true);
            $current_images = is_array($decoded) ? $decoded : [$curr_record['spoilage_images']];
        }

        // Process deletions
        if (isset($_POST['remove_images']) && is_array($_POST['remove_images'])) {
            foreach ($_POST['remove_images'] as $rem_img) {
                if (($key = array_search($rem_img, $current_images)) !== false) {
                    unset($current_images[$key]);
                }
            }
            $current_images = array_values($current_images); 
        }

        // Process brand new uploads and APPEND them
        $new_images_json = handle_multiple_spoilage_uploads('edit_spoilage_images');
        if ($new_images_json) {
            $new_images = json_decode($new_images_json, true);
            $current_images = array_merge($current_images, $new_images);
        }

        $final_images_json = !empty($current_images) ? json_encode($current_images) : NULL;

        $stmt = $conn->prepare("UPDATE spoilage SET spoilage_type=?, reason=?, spoilage_images=? WHERE spoilage_id=?");
        $stmt->bind_param("sssi", $spoilage_type, $reason, $final_images_json, $id);
        
        if ($stmt->execute()) {
            
            // --- NEW: SYNC EDITS WITH EXPENSES TABLE ---
            $prod_id = $curr_record['product_id'];
            $qty = floatval($curr_record['quantity']);
            
            $prodInfo = $conn->query("SELECT name, unit FROM products WHERE product_id = $prod_id")->fetch_assoc();
            $unit = !empty($prodInfo['unit']) ? strtolower($prodInfo['unit']) : '';
            $formatted_qty = fmod($qty, 1) == 0 ? number_format($qty, 0) : number_format($qty, 2);
            $exp_desc = "Spoilage ($spoilage_type): {$formatted_qty}{$unit} " . ($prodInfo['name'] ?? 'Unknown') . ". " . ($reason ? "Reason: $reason" : "");

            $pk_col = 'id';
            $check_col = $conn->query("SHOW COLUMNS FROM expenses LIKE 'id'");
            if ($check_col && $check_col->num_rows == 0) { $pk_col = 'expense_id'; }

            // Updates the exact expense linked to this product and quantity
            $stmt_exp = $conn->prepare("UPDATE expenses SET description = ? WHERE category = 'Spoilage Loss' AND product_id = ? AND quantity = ? ORDER BY $pk_col DESC LIMIT 1");
            if ($stmt_exp) {
                $stmt_exp->bind_param("sid", $exp_desc, $prod_id, $qty);
                $stmt_exp->execute();
            }
            // ------------------------------------------

            log_audit_action('Edit Spoilage', 'Inventory', "Updated reason/photos for spoilage ID $id ($spoilage_type)");
            echo json_encode(['success' => true, 'message' => 'Spoilage record and Expense details updated successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error updating record.']);
        }
        exit;
    }

    // 4. HARD DELETE (SUPER ADMIN ONLY - FOR CLEANUP)
    if ($action === 'hard_delete') {
        if ($current_role !== 'Super Admin') {
            echo json_encode(['success' => false, 'message' => 'Unauthorized. Only Super Admins can hard delete.']); exit;
        }
        
        $id = intval($_POST['spoilage_id']);

        // --- REVERSE FINANCIAL EXPENSE BEFORE DELETING ---
        try {
            $record = $conn->query("SELECT product_id, quantity FROM spoilage WHERE spoilage_id = $id")->fetch_assoc();
            if ($record) {
                reverse_spoilage_expense($conn, $record['product_id'], floatval($record['quantity']));
            }
        } catch (Throwable $e) {}

        $stmt = $conn->prepare("DELETE FROM spoilage WHERE spoilage_id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            log_audit_action('Hard Delete Spoilage', 'Inventory', "Super Admin permanently deleted spoilage ID: $id and reversed financial loss.");
            echo json_encode(['success' => true, 'message' => 'Record and financial loss permanently deleted.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error deleting record.']);
        }
        exit;
    }
}
ob_end_flush();

// --- FETCH DATA ---
$products_query = "
    SELECT p.product_id, p.name, p.product_brand, p.unit, p.price, p.image_url, IFNULL(pi.quantity, 0) as stock 
    FROM products p 
    LEFT JOIN product_inventory pi ON p.product_id = pi.product_id 
    WHERE p.status = 'Active' 
    ORDER BY p.name ASC
";
$products = $conn->query($products_query)->fetch_all(MYSQLI_ASSOC);

$history_query = "
    SELECT sp.*, p.name, p.product_brand, p.unit, p.price, p.image_url, u.first_name, u.last_name 
    FROM spoilage sp 
    JOIN products p ON sp.product_id = p.product_id 
    LEFT JOIN users u ON sp.recorded_by = u.user_id 
    ORDER BY sp.spoilage_date DESC LIMIT 100
";
$history = $conn->query($history_query)->fetch_all(MYSQLI_ASSOC);

// --- CALCULATE METRICS ---
$metrics_query = "
    SELECT 
        COUNT(sp.spoilage_id) as total_incidents,
        SUM(sp.quantity) as total_items_lost,
        SUM(sp.quantity * p.price) as total_financial_loss
    FROM spoilage sp
    JOIN products p ON sp.product_id = p.product_id
";
$metrics = $conn->query($metrics_query)->fetch_assoc();
$total_incidents = $metrics['total_incidents'] ?? 0;
$total_items_lost = $metrics['total_items_lost'] ?? 0;
$total_financial_loss = $metrics['total_financial_loss'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreshFlow - Spoilage & Waste Tracking</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    <link rel="icon" type="image/jpeg" href="/admin_components/assets/img/tabicon4.png">
    <script>
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
        tailwind.config = { darkMode: 'class' };
    </script>

    <style>
        :root { --brand-green: #1E3A1D; --brand-cream: #F8F5EE; }
        body { font-family: 'Inter', sans-serif; background-color: var(--brand-cream); color: #2B2B2B; transition: background-color 0.3s ease; }
        
        /* DARK MODE BODY */
        .dark body {
            background-color: #000000;
            background-image: radial-gradient(circle, rgba(255, 255, 255, 0.07) 1px, transparent 1px);
            background-size: 16px 16px;
            color: #f8fafc;
        }

        .font-mono { font-family: 'JetBrains Mono', monospace; }
        .custom-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .dark .custom-scroll::-webkit-scrollbar-thumb { background: #334155; }
        
        .modal-z { z-index: 50; }
        .gallery-z { z-index: 60; }
        
        /* FORM INPUTS */
        .form-input { background-color: #ffffff; border: 1px solid #d1d5db; color: #374151; border-radius: 0.5rem; transition: all 0.2s; padding: 0.5rem 0.75rem; }
        .form-input:focus { outline: none; border-color: #1E3A1D; box-shadow: 0 0 0 3px rgba(30, 58, 29, 0.1); }
        .form-input[readonly] { background-color: #f3f4f6; cursor: not-allowed; }
        
        .dark .form-input { background-color: #1e293b; border-color: #334155; color: #f8fafc; }
        .dark .form-input:focus { border-color: #4ade80; box-shadow: 0 0 0 3px rgba(74, 222, 128, 0.1); }
        .dark .form-input[readonly] { background-color: #0f172a; cursor: not-allowed; }
        
        /* SPOILAGE CATEGORY BADGES */
        .type-expired { background-color: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .dark .type-expired { background-color: rgba(153, 27, 27, 0.2); color: #fca5a5; border-color: rgba(248, 113, 113, 0.3); }

        .type-transit { background-color: #ffedd5; color: #c2410c; border: 1px solid #fed7aa; }
        .dark .type-transit { background-color: rgba(194, 65, 12, 0.2); color: #fdba74; border-color: rgba(253, 186, 116, 0.3); }

        .type-reject { background-color: #fce7f3; color: #be185d; border: 1px solid #fbcfe8; }
        .dark .type-reject { background-color: rgba(190, 24, 93, 0.2); color: #f9a8d4; border-color: rgba(244, 114, 182, 0.3); }

        .type-warehouse { background-color: #e0e7ff; color: #4338ca; border: 1px solid #c7d2fe; }
        .dark .type-warehouse { background-color: rgba(67, 56, 202, 0.2); color: #a5b4fc; border-color: rgba(129, 140, 248, 0.3); }
    </style>
    <script>
        (function() {
            window.onpageshow = function(event) {
                if (event.persisted || (window.performance && window.performance.navigation.type === 2)) {
                    document.body.style.display = 'none'; window.location.reload(); 
                }
            };
        })();
    </script>
</head>

<body style="display:none;" id="secure-body" class="flex h-screen overflow-hidden">

    <?php include '../includes/sidebar.php'; ?>

    <main class="ml-20 flex-1 flex flex-col h-screen overflow-hidden relative transition-all duration-300 p-6 md:p-8">
        
        <header class="flex justify-between items-center mb-6 flex-shrink-0">
            <div>
                <h1 class="text-3xl font-bold text-[#1E3A1D] dark:text-white tracking-tight flex items-center gap-3">
                    <span class="material-icons text-3xl dark:text-green-400">delete_sweep</span> Waste & Spoilage Log
                </h1>
                <p class="text-sm text-gray-500 dark:text-slate-400 mt-1 ml-11">
                    Track rejected items, warehouse rot, and view photo documentation.
                </p>
            </div>
            <button onclick="openLogModal()" class="bg-red-700 dark:bg-red-600 text-white hover:bg-red-800 dark:hover:bg-red-500 px-5 py-2.5 rounded-lg text-sm font-bold shadow-lg transition transform active:scale-95 flex items-center gap-2">
                <span class="material-icons text-sm">remove_shopping_cart</span> Log Spoilage
            </button>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6 flex-shrink-0">
            <div class="bg-white dark:bg-slate-900/80 border border-blue-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-default group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(59,130,246,0.2)] dark:hover:shadow-[0_0_20px_rgba(59,130,246,0.3)] dark:hover:border-blue-400 transition-all duration-300 shadow-sm">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">Total Incidents</p>
                    <p class="text-3xl font-bold text-blue-600 dark:text-blue-400 mt-1 font-mono group-hover:scale-110 transition-transform origin-left"><?= number_format($total_incidents) ?></p>
                </div>
                <div class="p-3 bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 rounded-lg group-hover:bg-blue-200 dark:group-hover:bg-blue-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">receipt_long</span>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900/80 border border-orange-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-default group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(249,115,22,0.2)] dark:hover:shadow-[0_0_20px_rgba(249,115,22,0.3)] dark:hover:border-orange-400 transition-all duration-300 shadow-sm">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-orange-600 dark:group-hover:text-orange-400 transition-colors">Total Items Lost</p>
                    <p class="text-3xl font-bold text-orange-600 dark:text-orange-400 mt-1 font-mono group-hover:scale-110 transition-transform origin-left"><?= number_format($total_items_lost, 2) ?></p>
                </div>
                <div class="p-3 bg-orange-100 dark:bg-orange-900/30 text-orange-600 dark:text-orange-400 rounded-lg group-hover:bg-orange-200 dark:group-hover:bg-orange-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">remove_shopping_cart</span>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900/80 border border-red-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-default group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(239,68,68,0.2)] dark:hover:shadow-[0_0_20px_rgba(239,68,68,0.3)] dark:hover:border-red-400 transition-all duration-300 shadow-sm">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-red-600 dark:group-hover:text-red-400 transition-colors">Est. Financial Loss</p>
                    <p class="text-3xl font-bold text-red-600 dark:text-red-400 mt-1 font-mono group-hover:scale-110 transition-transform origin-left">₱<?= number_format($total_financial_loss, 2) ?></p>
                </div>
                <div class="p-3 bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 rounded-lg group-hover:bg-red-200 dark:group-hover:bg-red-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">trending_down</span>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-900/80 rounded-xl shadow border border-gray-200 dark:border-slate-800 flex-1 overflow-hidden flex flex-col mb-4">
            <div class="overflow-y-auto flex-1 custom-scroll pb-24">
                <table class="w-full text-left">
                    <thead class="bg-[#1E3A1D] dark:bg-slate-800 text-white text-xs uppercase font-bold sticky top-0 z-10">
                        <tr>
                            <th class="p-4 pl-6">Product Details</th>
                            <th class="p-4">Spoilage Category & Reason</th>
                            <th class="p-4 text-center">Qty Lost</th>
                            <th class="p-4">Est. Financial Loss</th>
                            <th class="p-4">Logged By / Date</th>
                            <th class="p-4 pr-6 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-800 text-sm">
                        <?php if(empty($history)): ?>
                            <tr><td colspan="6" class="p-8 text-center text-gray-400 dark:text-slate-500 italic">No spoilage records found. Looking good!</td></tr>
                        <?php else: ?>
                            <?php foreach($history as $h): 
                                $isBulk = in_array(strtolower($h['unit']), ['kg', 'g', 'liter', 'l', 'ml']);
                                $displayQty = $isBulk ? number_format($h['quantity'], 2) : number_format($h['quantity'], 0);
                                $totalLoss = floatval($h['quantity']) * floatval($h['price'] ?? 0);
                                
                                $typeBadgeClass = 'type-warehouse';
                                $cat = $h['spoilage_type'] ?? 'Warehouse Rot';
                                if (strpos(strtolower($cat), 'expire') !== false) $typeBadgeClass = 'type-expired';
                                else if (strpos(strtolower($cat), 'transit') !== false) $typeBadgeClass = 'type-transit';
                                else if (strpos(strtolower($cat), 'reject') !== false) $typeBadgeClass = 'type-reject';

                                $prodImg = !empty($h['image_url']) ? trim($h['image_url']) : '';
                                $img_src = '';
                                if (!empty($prodImg)) {
                                    $img_src = preg_match('/^(http|\/)/', $prodImg) ? $prodImg : '../../' . preg_replace('/^(\.\.\/)+/', '', $prodImg);
                                }

                                $evidenceArray = [];
                                if (!empty($h['spoilage_images'])) {
                                    $decoded = json_decode($h['spoilage_images'], true);
                                    if (is_array($decoded)) { $evidenceArray = $decoded; } 
                                    else { $evidenceArray = [$h['spoilage_images']]; }
                                }

                                $hours_passed = (time() - strtotime($h['spoilage_date'])) / 3600;
                                $isLocked = ($hours_passed > $LOCK_HOURS);
                                $canEdit = (!$isLocked || $current_role === 'Super Admin');
                            ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-slate-800/50 transition <?= $isLocked ? 'bg-gray-50/50 dark:bg-slate-800/30' : '' ?> group">
                                <td class="p-4 pl-6 align-middle">
                                    <div class="flex items-center gap-4">
                                        <div class="w-12 h-12 rounded-lg bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-slate-700 flex items-center justify-center shadow-sm relative overflow-hidden flex-shrink-0">
                                            <span class="material-icons text-2xl text-gray-300 dark:text-slate-600 absolute z-0">inventory_2</span>
                                            <?php if(!empty($img_src)): ?>
                                                <img src="<?= htmlspecialchars($img_src, ENT_QUOTES) ?>" class="object-cover w-full h-full absolute inset-0 bg-white dark:bg-slate-800 z-10" onerror="this.style.display='none';">
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="font-bold text-gray-900 dark:text-white text-sm <?= $isLocked ? 'text-gray-500 dark:text-slate-400' : '' ?>">
                                                <?= htmlspecialchars($h['name']) ?>
                                            </div>
                                            <div class="text-[10px] text-gray-400 dark:text-slate-500 font-mono mt-0.5"><?= htmlspecialchars($h['product_brand'] ?: 'No Brand') ?></div>
                                        </div>
                                    </div>
                                </td>
                                
                                <td class="p-4 align-middle">
                                    <div class="flex flex-col items-start gap-1.5">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider <?= $typeBadgeClass ?> <?= $isLocked ? 'opacity-70' : '' ?>">
                                            <?= htmlspecialchars($cat) ?>
                                        </span>
                                        <?php if(!empty($h['reason'])): ?>
                                            <div class="text-xs text-gray-600 dark:text-slate-300 italic max-w-xs truncate" title="<?= htmlspecialchars($h['reason']) ?>">
                                                "<?= htmlspecialchars($h['reason']) ?>"
                                            </div>
                                        <?php endif; ?>

                                        <?php if(!empty($evidenceArray)): ?>
                                            <button onclick='openGallery(<?= json_encode($evidenceArray) ?>, "<?= addslashes(htmlspecialchars($h['name'])) ?>")' class="flex items-center gap-1 text-[10px] font-bold text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 bg-blue-50 dark:bg-blue-900/30 border border-blue-100 dark:border-blue-800/50 px-2.5 py-1 rounded transition">
                                                <span class="material-icons text-[14px]">photo_library</span> View Evidence (<?= count($evidenceArray) ?>)
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                
                                <td class="p-4 align-middle text-center">
                                    <div class="inline-block bg-red-50 dark:bg-red-900/30 border border-red-100 dark:border-red-800/50 px-3 py-1.5 rounded-lg shadow-sm <?= $isLocked ? 'opacity-80' : '' ?>">
                                        <span class="font-mono font-black text-red-700 dark:text-red-400 text-lg">-<?= $displayQty ?></span>
                                        <span class="text-red-400 dark:text-red-500 text-xs ml-0.5"><?= htmlspecialchars($h['unit']) ?></span>
                                    </div>
                                </td>
                                
                                <td class="p-4 align-middle">
                                    <div class="font-mono font-bold <?= $isLocked ? 'text-gray-500 dark:text-slate-400' : 'text-gray-800 dark:text-slate-200' ?>">₱<?= number_format($totalLoss, 2) ?></div>
                                    <div class="text-[10px] text-gray-400 dark:text-slate-500 mt-1">Based on current unit price</div>
                                </td>
                                
                                <td class="p-4 align-middle">
                                    <div class="text-xs text-gray-800 dark:text-slate-200 font-bold"><?= htmlspecialchars($h['first_name'] . ' ' . $h['last_name']) ?></div>
                                    <div class="text-[10px] text-gray-500 dark:text-slate-400 font-mono mt-1 flex items-center gap-1">
                                        <?= date('M d, Y', strtotime($h['spoilage_date'])) ?> &bull; <?= date('h:i A', strtotime($h['spoilage_date'])) ?>
                                        <?php if($isLocked): ?>
                                            <span class="material-icons text-[12px] text-gray-400 dark:text-slate-500 ml-1" title="Locked (Past <?= $LOCK_HOURS ?>h)">lock</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                
                                <td class="p-4 pr-6 align-middle text-right">
                                    <?php if($canEdit): ?>
                                        <div class="relative inline-block dropdown-container">
                                            <button type="button" onclick="toggleMenu(event, 'menu-<?= $h['spoilage_id'] ?>')" class="p-1.5 text-gray-400 dark:text-slate-500 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-slate-700 rounded-full transition focus:outline-none opacity-50 group-hover:opacity-100">
                                                <span class="material-icons">more_vert</span>
                                            </button>
                                            <div id="menu-<?= $h['spoilage_id'] ?>" class="user-dropdown-menu hidden absolute right-0 top-full mt-1 w-40 bg-white dark:bg-slate-800 rounded-lg shadow-xl border border-gray-200 dark:border-slate-700 z-50 overflow-hidden">
                                                <div class="flex flex-col">
                                                    <button onclick="openEditModal(<?= $h['spoilage_id'] ?>)" class="w-full text-left px-4 py-2.5 text-xs text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-slate-700 flex items-center gap-2 font-bold transition">
                                                        <span class="material-icons text-sm">edit</span> Edit Details
                                                    </button>
                                                    <button onclick="undoSpoilage(<?= $h['spoilage_id'] ?>)" class="w-full text-left px-4 py-2.5 text-xs text-orange-600 dark:text-orange-400 hover:bg-orange-50 dark:hover:bg-orange-900/30 flex items-center gap-2 font-bold border-t border-gray-100 dark:border-slate-700 transition">
                                                        <span class="material-icons text-sm">undo</span> Undo & Restore
                                                    </button>
                                                    <?php if($current_role === 'Super Admin'): ?>
                                                    <button onclick="hardDelete(<?= $h['spoilage_id'] ?>)" class="w-full text-left px-4 py-2.5 text-xs text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 flex items-center gap-2 font-bold border-t border-gray-100 dark:border-slate-700 transition">
                                                        <span class="material-icons text-sm">delete_forever</span> Hard Delete
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <button onclick="openViewModal(<?= $h['spoilage_id'] ?>)" class="text-[10px] bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400 px-3 py-1.5 rounded-lg border border-gray-200 dark:border-slate-700 font-bold hover:bg-gray-200 dark:hover:bg-slate-700 transition shadow-sm uppercase tracking-wider flex items-center gap-1">
                                            <span class="material-icons text-[12px]">visibility</span> View Only
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <div id="flashMessage" class="fixed bottom-6 right-6 z-[100] bg-[#1E3A1D] dark:bg-green-700 text-white px-6 py-4 rounded-lg shadow-2xl flex items-center gap-3 transform translate-y-20 transition-all duration-300 opacity-0 pointer-events-none">
        <span class="material-icons text-green-400" id="flashIcon">check_circle</span>
        <div><h4 class="font-bold text-sm">Notification</h4><p class="text-xs text-gray-300" id="flashText"></p></div>
    </div>

    <div id="logSpoilageModal" class="fixed inset-0 bg-[#1E3A1D] dark:bg-black bg-opacity-40 dark:bg-opacity-60 hidden flex items-center justify-center modal-z backdrop-blur-sm transition-opacity">
        <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-2xl overflow-hidden max-h-[90vh] flex flex-col border border-gray-200 dark:border-slate-700">
            <div class="bg-red-700 dark:bg-slate-800 p-5 text-white flex justify-between items-center flex-shrink-0 border-b border-red-800 dark:border-slate-700">
                <h2 class="text-lg font-bold flex items-center gap-2"><span class="material-icons">remove_shopping_cart</span> Log Waste or Spoilage</h2>
                <button type="button" onclick="closeLogModal()" class="text-red-200 dark:text-slate-400 hover:text-white transition focus:outline-none"><span class="material-icons">close</span></button>
            </div>
            
            <div class="p-6 overflow-y-auto custom-scroll flex-1 bg-gray-50 dark:bg-slate-900">
                <form id="logSpoilageForm" enctype="multipart/form-data">
                    <input type="hidden" name="action_type" value="log_spoilage">
                    <div class="space-y-5">
                        
                        <div class="bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-800/50 text-blue-800 dark:text-blue-400 p-3 rounded-lg text-xs flex gap-2">
                            <span class="material-icons text-sm mt-0.5">info</span>
                            <p>Logging spoilage immediately deducts stock. You can upload multiple photos. Hover over a photo to delete it before submitting.</p>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Select Product & Available Stock <span class="text-red-500">*</span></label>
                            <select name="product_id" id="product_id" required class="form-input w-full bg-white dark:bg-slate-800 text-sm font-medium cursor-pointer">
                                <option value="" disabled selected>-- Choose Product --</option>
                                <?php foreach($products as $p): ?>
                                    <option value="<?= $p['product_id'] ?>" data-unit="<?= htmlspecialchars($p['unit']) ?>">
                                        <?= htmlspecialchars($p['name']) ?> (Stock: <?= $p['stock'] ?> <?= htmlspecialchars($p['unit']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Quantity Lost <span class="text-red-500">*</span></label>
                                <input type="number" name="quantity" id="quantity" required step="1" min="1" placeholder="0" class="form-input w-full font-mono text-lg font-bold text-red-600 dark:text-red-400">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Spoilage Category <span class="text-red-500">*</span></label>
                                <select name="spoilage_type" required class="form-input w-full bg-white dark:bg-slate-800 text-sm font-bold cursor-pointer">
                                    <option value="Warehouse Rot">Warehouse Rot</option>
                                    <option value="Expired Product">Expired Product</option>
                                    <option value="Damaged in Transit">Damaged in Transit</option>
                                    <option value="Client Reject">Client Reject</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Reason / Details</label>
                            <textarea name="reason" rows="2" placeholder="Briefly describe what happened..." class="form-input w-full text-sm resize-none"></textarea>
                        </div>

                        <div class="border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4 rounded-xl">
                            <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-2">Evidence Photos (Optional)</label>
                            <div class="flex items-center gap-3 mb-3">
                                <label class="cursor-pointer bg-gray-100 dark:bg-slate-700 hover:bg-gray-200 dark:hover:bg-slate-600 text-gray-700 dark:text-slate-300 px-4 py-2 rounded-lg text-xs font-bold border border-gray-300 dark:border-slate-600 transition flex items-center gap-2">
                                    <span class="material-icons text-sm">add_a_photo</span> Add Photos
                                    <input type="file" id="logFileInput" accept="image/*" multiple class="hidden" onchange="handleMultipleSelect(this, 'logDT', 'logPhotoGrid', 'logPhotoText')">
                                </label>
                                <span id="logPhotoText" class="text-xs text-gray-400 dark:text-slate-500 italic">0 photos selected</span>
                            </div>
                            <div id="logPhotoGrid" class="flex flex-wrap gap-2 hidden p-2 bg-gray-50 dark:bg-slate-900 rounded-lg border border-gray-200 dark:border-slate-700"></div>
                        </div>

                    </div>
                </form>
            </div>
            
            <div class="p-5 border-t border-gray-100 dark:border-slate-800 bg-white dark:bg-slate-900 flex justify-end gap-3 flex-shrink-0">
                <button type="button" onclick="closeLogModal()" class="px-5 py-2.5 text-gray-600 dark:text-slate-300 bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 border border-gray-200 dark:border-slate-700 rounded-lg text-sm font-bold transition">Cancel</button>
                <button type="submit" form="logSpoilageForm" id="submitLogBtn" class="bg-red-700 dark:bg-red-600 text-white px-6 py-2.5 rounded-lg text-sm font-bold hover:bg-red-800 dark:hover:bg-red-500 shadow-md transition transform hover:-translate-y-0.5 flex items-center gap-2">
                    <span class="material-icons text-sm">save</span> Confirm Spoilage
                </button>
            </div>
        </div>
    </div>

    <div id="editSpoilageModal" class="fixed inset-0 bg-[#1E3A1D] dark:bg-black bg-opacity-40 dark:bg-opacity-60 hidden flex items-center justify-center modal-z backdrop-blur-sm transition-opacity">
        <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-xl overflow-hidden max-h-[90vh] flex flex-col border border-gray-200 dark:border-slate-700">
            <div class="bg-gray-800 dark:bg-slate-800 p-5 text-white flex justify-between items-center flex-shrink-0 border-b border-gray-700">
                <h2 class="text-lg font-bold flex items-center gap-2"><span class="material-icons">edit</span> Edit Spoilage Details</h2>
                <button type="button" onclick="closeEditModal()" class="text-gray-300 hover:text-white transition focus:outline-none"><span class="material-icons">close</span></button>
            </div>
            
            <div class="p-6 overflow-y-auto custom-scroll flex-1 bg-gray-50 dark:bg-slate-900">
                <form id="editSpoilageForm" enctype="multipart/form-data">
                    <input type="hidden" name="action_type" value="edit_spoilage">
                    <input type="hidden" name="edit_spoilage_id" id="edit_spoilage_id">
                    
                    <div id="deletedImagesContainer"></div>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Product</label>
                            <input type="text" id="edit_product_name" readonly class="form-input w-full font-bold">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Spoilage Category</label>
                            <select name="edit_spoilage_type" id="edit_spoilage_type" required class="form-input w-full bg-white dark:bg-slate-800 text-sm font-bold cursor-pointer">
                                <option value="Warehouse Rot">Warehouse Rot</option>
                                <option value="Expired Product">Expired Product</option>
                                <option value="Damaged in Transit">Damaged in Transit</option>
                                <option value="Client Reject">Client Reject</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Reason / Details</label>
                            <textarea name="edit_reason" id="edit_reason" rows="2" class="form-input w-full text-sm resize-none"></textarea>
                        </div>

                        <div class="border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4 rounded-xl">
                            <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-2 flex items-center justify-between">
                                <span>Manage Evidence</span>
                            </label>
                            
                            <div class="mb-4">
                                <p class="text-[10px] text-gray-400 dark:text-slate-500 mb-1">Currently Attached (Click red X to delete):</p>
                                <div id="currentImagesGrid" class="flex flex-wrap gap-2 p-2 bg-gray-50 dark:bg-slate-900 rounded-lg border border-gray-200 dark:border-slate-700 min-h-[60px] text-xs text-gray-400 dark:text-slate-500 flex items-center justify-center"></div>
                            </div>

                            <div class="border-t border-gray-100 dark:border-slate-700 pt-3">
                                <div class="flex items-center gap-3 mb-2">
                                    <label class="cursor-pointer bg-gray-100 dark:bg-slate-700 hover:bg-gray-200 dark:hover:bg-slate-600 text-gray-700 dark:text-slate-300 px-3 py-1.5 rounded-lg text-xs font-bold border border-gray-300 dark:border-slate-600 transition flex items-center gap-1">
                                        <span class="material-icons text-[14px]">add_a_photo</span> Add More Photos
                                        <input type="file" name="edit_spoilage_images[]" id="editFileInput" accept="image/*" multiple class="hidden" onchange="handleMultipleSelect(this, 'editDT', 'editPhotoGrid', 'editPhotoText')">
                                    </label>
                                    <span id="editPhotoText" class="text-xs text-gray-400 dark:text-slate-500 italic">0 new photos</span>
                                </div>
                                <div id="editPhotoGrid" class="flex flex-wrap gap-2 hidden p-2 bg-gray-50 dark:bg-slate-900 rounded-lg border border-gray-200 dark:border-slate-700"></div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="p-5 border-t border-gray-100 dark:border-slate-800 bg-white dark:bg-slate-900 flex justify-end gap-3 flex-shrink-0">
                <button type="button" onclick="closeEditModal()" class="px-5 py-2.5 text-gray-600 dark:text-slate-300 bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 border border-gray-200 dark:border-slate-700 rounded-lg text-sm font-bold transition">Cancel</button>
                <button type="submit" form="editSpoilageForm" id="submitEditBtn" class="bg-gray-800 dark:bg-[#1E3A1D] text-white px-6 py-2.5 rounded-lg text-sm font-bold hover:bg-gray-900 dark:hover:bg-[#2a4e29] shadow-md transition transform hover:-translate-y-0.5 flex items-center gap-2">
                    <span class="material-icons text-sm">save</span> Save Changes
                </button>
            </div>
        </div>
    </div>

    <div id="viewSpoilageModal" class="fixed inset-0 bg-[#1E3A1D] dark:bg-black bg-opacity-40 dark:bg-opacity-60 hidden flex items-center justify-center modal-z backdrop-blur-sm transition-opacity">
        <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-xl overflow-hidden max-h-[90vh] flex flex-col border border-gray-200 dark:border-slate-700">
            <div class="bg-gray-900 dark:bg-slate-800 p-5 text-white flex justify-between items-center flex-shrink-0 border-b border-gray-700">
                <h2 class="text-lg font-bold flex items-center gap-2"><span class="material-icons">visibility</span> View Locked Record</h2>
                <button type="button" onclick="closeViewModal()" class="text-gray-300 hover:text-white transition focus:outline-none"><span class="material-icons">close</span></button>
            </div>
            
            <div class="p-6 overflow-y-auto custom-scroll flex-1 bg-gray-50 dark:bg-slate-900">
                <div class="space-y-4">
                    <div class="bg-yellow-50 dark:bg-yellow-900/30 border border-yellow-200 dark:border-yellow-800/50 p-3 rounded-lg text-xs flex gap-2 text-yellow-800 dark:text-yellow-400">
                        <span class="material-icons text-sm">lock</span>
                        <p>This record is locked because more than <?= $LOCK_HOURS ?> hours have passed. It can no longer be edited by staff.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Product</label>
                        <div id="view_product_name" class="p-2 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg text-sm font-bold dark:text-white"></div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Category</label>
                            <div id="view_spoilage_type" class="p-2 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg text-sm font-bold dark:text-white"></div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Date Logged</label>
                            <div id="view_date" class="p-2 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg text-sm font-mono dark:text-slate-300"></div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Reason</label>
                        <div id="view_reason" class="p-2 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg text-sm min-h-[50px] italic dark:text-slate-300"></div>
                    </div>
                </div>
            </div>
            
            <div class="p-5 border-t border-gray-100 dark:border-slate-800 bg-white dark:bg-slate-900 flex justify-end flex-shrink-0">
                <button type="button" onclick="closeViewModal()" class="px-6 py-2.5 bg-gray-800 dark:bg-slate-700 text-white rounded-lg text-sm font-bold hover:bg-gray-900 dark:hover:bg-slate-600 transition shadow-md">Close</button>
            </div>
        </div>
    </div>

    <div id="evidenceGalleryModal" class="fixed inset-0 bg-black bg-opacity-90 hidden flex flex-col gallery-z backdrop-blur-md transition-opacity">
        <div class="p-5 flex justify-between items-center flex-shrink-0 text-white border-b border-gray-800">
            <h2 id="galleryTitle" class="text-xl font-bold">Evidence Photos</h2>
            <button type="button" onclick="closeGallery()" class="text-gray-300 hover:text-white transition focus:outline-none"><span class="material-icons text-3xl">close</span></button>
        </div>
        <div class="flex-1 overflow-y-auto p-8 custom-scroll">
            <div id="galleryContainer" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6 max-w-6xl mx-auto">
                </div>
        </div>
    </div>

    <script>
        document.getElementById('secure-body').style.display = 'block';

        // Add dynamic formatting constraint when selecting a product
        document.getElementById('product_id').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const unit = selectedOption.getAttribute('data-unit') ? selectedOption.getAttribute('data-unit').toLowerCase() : '';
            const qtyInput = document.getElementById('quantity');
            
            const decimalUnits = ['kg', 'g', 'liter', 'l', 'ml'];
            
            if (unit && decimalUnits.includes(unit)) {
                qtyInput.step = "0.01";
                qtyInput.min = "0.01";
                qtyInput.placeholder = "0.00";
            } else {
                qtyInput.step = "1";
                qtyInput.min = "1";
                qtyInput.placeholder = "0";
                if(qtyInput.value && qtyInput.value % 1 !== 0) {
                    qtyInput.value = Math.floor(qtyInput.value); // Round down decimals if switching back to integer unit
                }
            }
        });

        let flashTimeout;
        const showFlash = (msg, type = 'success') => {
            if(flashTimeout) clearTimeout(flashTimeout);
            document.getElementById('flashText').textContent = msg;
            const fm = document.getElementById('flashMessage');
            const fi = document.getElementById('flashIcon');
            fm.className = `fixed bottom-6 right-6 z-[100] ${type === 'error' ? 'bg-red-700 dark:bg-red-800' : 'bg-[#1E3A1D] dark:bg-green-700'} text-white px-6 py-4 rounded-lg shadow-2xl flex items-center gap-3 transform transition-all duration-300`;
            fi.textContent = type === 'error' ? 'error' : 'check_circle';
            fm.classList.remove('translate-y-20', 'opacity-0');
            flashTimeout = setTimeout(() => { fm.classList.add('translate-y-20', 'opacity-0'); }, 3000);
        };

        function toggleMenu(event, menuId) {
            event.stopPropagation();
            const menu = document.getElementById(menuId);
            const isHidden = menu.classList.contains('hidden');
            document.querySelectorAll('.user-dropdown-menu').forEach(m => m.classList.add('hidden'));
            if (isHidden) menu.classList.remove('hidden');
        }

        document.addEventListener('click', function() {
            document.querySelectorAll('.user-dropdown-menu').forEach(menu => menu.classList.add('hidden'));
        });

        // --- UPLOAD STATE MANAGER ---
        const uploadState = { logDT: new DataTransfer(), editDT: new DataTransfer() };

        function handleMultipleSelect(input, dtKey, gridId, textId) {
            const dt = uploadState[dtKey];
            for (let file of input.files) { dt.items.add(file); }
            input.files = dt.files;
            renderPreviewGrid(input, dtKey, gridId, textId);
        }

        function renderPreviewGrid(input, dtKey, gridId, textId) {
            const container = document.getElementById(gridId);
            const text = document.getElementById(textId);
            container.innerHTML = '';
            
            const dt = uploadState[dtKey];
            
            if (dt.files.length > 0) {
                text.textContent = `${dt.files.length} photo(s) ready`;
                container.classList.remove('hidden');
                
                Array.from(dt.files).forEach((file, index) => {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const wrapper = document.createElement('div');
                        wrapper.className = "relative w-16 h-16 rounded-md overflow-hidden border border-gray-300 group";
                        
                        const img = document.createElement('img');
                        img.src = e.target.result;
                        img.className = "w-full h-full object-cover";
                        
                        const delBtn = document.createElement('div');
                        delBtn.className = "absolute inset-0 bg-red-600 bg-opacity-80 flex items-center justify-center opacity-0 group-hover:opacity-100 cursor-pointer transition-opacity";
                        delBtn.innerHTML = '<span class="material-icons text-white text-sm">delete</span>';
                        delBtn.onclick = function() {
                            const newDt = new DataTransfer();
                            for(let i = 0; i < dt.files.length; i++) {
                                if(i !== index) newDt.items.add(dt.files[i]);
                            }
                            uploadState[dtKey] = newDt;
                            input.files = newDt.files;
                            renderPreviewGrid(input, dtKey, gridId, textId);
                        };

                        wrapper.appendChild(img);
                        wrapper.appendChild(delBtn);
                        container.appendChild(wrapper);
                    }
                    reader.readAsDataURL(file);
                });
            } else {
                text.textContent = "0 photos selected";
                container.classList.add('hidden');
            }
        }

        // --- GALLERY MODAL LOGIC ---
        function openGallery(imagesArray, productName) {
            document.getElementById('galleryTitle').textContent = productName;
            const container = document.getElementById('galleryContainer');
            container.innerHTML = '';

            imagesArray.forEach(imgSrc => {
                const a = document.createElement('a');
                a.href = "../../" + imgSrc;
                a.target = "_blank";
                a.className = "block aspect-square rounded-xl overflow-hidden shadow-sm hover:shadow-lg transition transform hover:-translate-y-1 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700";
                
                const img = document.createElement('img');
                img.src = "../../" + imgSrc;
                img.className = "w-full h-full object-cover";
                
                a.appendChild(img);
                container.appendChild(a);
            });

            document.getElementById('evidenceGalleryModal').classList.remove('hidden');
        }

        function closeGallery() {
            document.getElementById('evidenceGalleryModal').classList.add('hidden');
        }

        // --- LOG MODAL LOGIC ---
        function openLogModal() {
            document.getElementById('logSpoilageForm').reset();
            uploadState.logDT = new DataTransfer();
            document.getElementById('logFileInput').files = uploadState.logDT.files;
            document.getElementById('logPhotoGrid').innerHTML = '';
            document.getElementById('logPhotoGrid').classList.add('hidden');
            document.getElementById('logPhotoText').textContent = "0 photos selected";
            
            // Reset quantity input rules to integer defaults upon opening
            const qtyInput = document.getElementById('quantity');
            qtyInput.step = "1";
            qtyInput.min = "1";
            qtyInput.placeholder = "0";

            document.getElementById('logSpoilageModal').classList.remove('hidden');
        }

        function closeLogModal() {
            document.getElementById('logSpoilageModal').classList.add('hidden');
        }

        // --- EDIT MODAL LOGIC ---
        async function openEditModal(spoilageId) {
            try {
                const res = await fetch(`?action=get_spoilage&id=${spoilageId}`).then(r => r.json());
                if(res.success) {
                    const data = res.data;
                    document.getElementById('edit_spoilage_id').value = data.spoilage_id;
                    document.getElementById('edit_product_name').value = `${data.name} (-${data.quantity} ${data.unit})`;
                    document.getElementById('edit_spoilage_type').value = data.spoilage_type || 'Warehouse Rot';
                    document.getElementById('edit_reason').value = data.reason || '';
                    
                    document.getElementById('deletedImagesContainer').innerHTML = '';
                    uploadState.editDT = new DataTransfer();
                    document.getElementById('editFileInput').files = uploadState.editDT.files;
                    document.getElementById('editPhotoGrid').innerHTML = '';
                    document.getElementById('editPhotoGrid').classList.add('hidden');
                    document.getElementById('editPhotoText').textContent = "0 new photos";

                    const currentGrid = document.getElementById('currentImagesGrid');
                    currentGrid.innerHTML = '';
                    
                    if (data.spoilage_images) {
                        let parsed = [];
                        try {
                            parsed = JSON.parse(data.spoilage_images);
                            if(!Array.isArray(parsed)) parsed = [data.spoilage_images];
                        } catch(e) {
                            parsed = [data.spoilage_images];
                        }
                        
                        if (parsed.length > 0) {
                            parsed.forEach(src => {
                                const wrapper = document.createElement('div');
                                wrapper.className = "relative w-12 h-12 rounded-md overflow-hidden border border-gray-300 dark:border-slate-600 group";
                                
                                const imgTag = document.createElement('img');
                                imgTag.src = "../../" + src;
                                imgTag.className = "w-full h-full object-cover";
                                
                                const delBtn = document.createElement('div');
                                delBtn.className = "absolute inset-0 bg-red-600 bg-opacity-80 flex items-center justify-center opacity-0 group-hover:opacity-100 cursor-pointer transition-opacity";
                                delBtn.innerHTML = '<span class="material-icons text-white text-sm">close</span>';
                                delBtn.onclick = function() {
                                    if(confirm("Mark this photo for deletion on save?")) {
                                        const hiddenInput = document.createElement('input');
                                        hiddenInput.type = 'hidden';
                                        hiddenInput.name = 'remove_images[]';
                                        hiddenInput.value = src;
                                        document.getElementById('deletedImagesContainer').appendChild(hiddenInput);
                                        wrapper.remove();
                                        if(currentGrid.children.length === 0) {
                                            currentGrid.textContent = "All saved photos marked for deletion.";
                                        }
                                    }
                                };

                                wrapper.appendChild(imgTag);
                                wrapper.appendChild(delBtn);
                                currentGrid.appendChild(wrapper);
                            });
                        } else {
                            currentGrid.textContent = "No photos currently attached.";
                        }
                    } else {
                        currentGrid.textContent = "No photos currently attached to this record.";
                    }

                    document.getElementById('editSpoilageModal').classList.remove('hidden');
                } else {
                    showFlash("Could not load details.", "error");
                }
            } catch(e) {
                showFlash("Server error fetching data.", "error");
            }
        }

        function closeEditModal() {
            document.getElementById('editSpoilageModal').classList.add('hidden');
        }

        // --- VIEW MODAL LOGIC ---
        async function openViewModal(spoilageId) {
            try {
                const res = await fetch(`?action=get_spoilage&id=${spoilageId}`).then(r => r.json());
                if(res.success) {
                    const data = res.data;
                    document.getElementById('view_product_name').textContent = `${data.name} (-${data.quantity} ${data.unit})`;
                    document.getElementById('view_spoilage_type').textContent = data.spoilage_type || 'Warehouse Rot';
                    document.getElementById('view_reason').textContent = data.reason || 'No reason provided.';
                    document.getElementById('view_date').textContent = new Date(data.spoilage_date).toLocaleString();
                    document.getElementById('viewSpoilageModal').classList.remove('hidden');
                }
            } catch(e) {}
        }
        function closeViewModal() {
            document.getElementById('viewSpoilageModal').classList.add('hidden');
        }

        // --- ACTIONS ---
        async function undoSpoilage(id) {
            if(!confirm(`UNDO SPOILAGE: This will restore the items back into inventory and reverse the financial loss. Are you sure?`)) return;
            const fd = new FormData();
            fd.append('action_type', 'undo_spoilage');
            fd.append('spoilage_id', id);

            try {
                const res = await fetch('', { method:'POST', body:fd }).then(r => r.json());
                if(res.success) { showFlash(res.message); setTimeout(() => window.location.reload(), 1500); } 
                else { showFlash(res.message, 'error'); }
            } catch(e) { showFlash("Error communicating with server.", "error"); }
        }

        async function hardDelete(id) {
            if(!confirm(`WARNING: This will permanently delete the log and reverse the financial loss, WITHOUT restoring inventory. Use only for cleaning up test data.`)) return;
            const fd = new FormData();
            fd.append('action_type', 'hard_delete');
            fd.append('spoilage_id', id);

            try {
                const res = await fetch('', { method:'POST', body:fd }).then(r => r.json());
                if(res.success) { showFlash(res.message); setTimeout(() => window.location.reload(), 1500); } 
                else { showFlash(res.message, 'error'); }
            } catch(e) { showFlash("Server error.", "error"); }
        }

        // --- FORM SUBMITS ---
        document.getElementById('logSpoilageForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('submitLogBtn');
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<span class="animate-spin material-icons text-sm">autorenew</span> Uploading...';
            btn.disabled = true;

            try {
                const res = await fetch('', { method:'POST', body: new FormData(e.target) }).then(r => r.json());
                if(res.success) { showFlash(res.message); closeLogModal(); setTimeout(() => window.location.reload(), 1500); } 
                else { showFlash(res.message, 'error'); btn.innerHTML = originalHTML; btn.disabled = false; }
            } catch(e) { showFlash("Error submitting form.", "error"); btn.innerHTML = originalHTML; btn.disabled = false; }
        });

        document.getElementById('editSpoilageForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('submitEditBtn');
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<span class="animate-spin material-icons text-sm">autorenew</span> Saving...';
            btn.disabled = true;

            try {
                const res = await fetch('', { method:'POST', body: new FormData(e.target) }).then(r => r.json());
                if(res.success) { showFlash(res.message); closeEditModal(); setTimeout(() => window.location.reload(), 1500); } 
                else { showFlash(res.message, 'error'); btn.innerHTML = originalHTML; btn.disabled = false; }
            } catch(e) { showFlash("Error updating record.", "error"); btn.innerHTML = originalHTML; btn.disabled = false; }
        });
    </script>
</body>
</html>