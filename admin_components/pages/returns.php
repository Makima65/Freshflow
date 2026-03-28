<?php
// C:\xampp\htdocs\RalphPHP\admin_components\pages\returns.php

// 1. PHP CACHE BUSTERS
header("Cache-Control: no-cache, no-store, must-revalidate"); 
header("Pragma: no-cache"); 
header("Expires: 0"); 

// 2. START BUFFERING
if (session_status() == PHP_SESSION_NONE) { session_start(); }
ob_start();
ini_set('display_errors', 0);
ini_set('log_errors', 1);

include_once '../includes/db_connection.php';

// --- CONFIGURATION ---
$RETURN_LOCK_HOURS = 48; // Time limit before an order can no longer be returned by clients

// --- DATABASE AUTO-PATCHER ---
$conn->query("ALTER TABLE returns MODIFY COLUMN condition_status VARCHAR(50)");
$check_col = $conn->query("SHOW COLUMNS FROM returns LIKE 'proof_image'");
if ($check_col && $check_col->num_rows == 0) $conn->query("ALTER TABLE returns ADD COLUMN proof_image TEXT NULL");

$conn->query("ALTER TABLE returns MODIFY COLUMN proof_image TEXT NULL");

$check_col2 = $conn->query("SHOW COLUMNS FROM returns LIKE 'action_requested'");
if ($check_col2 && $check_col2->num_rows == 0) $conn->query("ALTER TABLE returns ADD COLUMN action_requested VARCHAR(50) DEFAULT 'Replace'");

$check_spoil_type = $conn->query("SHOW COLUMNS FROM spoilage LIKE 'spoilage_type'");
if ($check_spoil_type && $check_spoil_type->num_rows == 0) {
    @$conn->query("ALTER TABLE spoilage ADD COLUMN spoilage_type VARCHAR(50) DEFAULT 'Warehouse Rot' AFTER quantity");
}
$check_spoil_img = $conn->query("SHOW COLUMNS FROM spoilage LIKE 'spoilage_images'");
if ($check_spoil_img && $check_spoil_img->num_rows == 0) {
    @$conn->query("ALTER TABLE spoilage ADD COLUMN spoilage_images TEXT NULL AFTER reason");
}
$check_spoil_user = $conn->query("SHOW COLUMNS FROM spoilage LIKE 'recorded_by'");
if ($check_spoil_user && $check_spoil_user->num_rows == 0) {
    @$conn->query("ALTER TABLE spoilage ADD COLUMN recorded_by INT NULL");
}

// --- AUDIT HELPER ---
$auditHelperPath = '../includes/audit_helper.php';
if (file_exists($auditHelperPath)) { include_once $auditHelperPath; } 
else { if (!function_exists('log_audit_action')) { function log_audit_action($a, $b, $c, $d = []) { return true; } } }

// --- SECURITY CHECK ---
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    if(!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        ob_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Session expired']); exit;
    }
    header("location: ../admin_login.php"); exit;
}

$user_id = $_SESSION['user_id'] ?? 1;
$current_role = $_SESSION['role_name'] ?? 'Staff';

// --- CORE FIX: SMART RESTOCK FUNCTION ---
function smart_restock($conn, $pid, $qty) {
    $has_expiry_col = $conn->query("SHOW COLUMNS FROM product_inventory LIKE 'expiration_date'")->num_rows > 0;
    
    $conn->query("UPDATE product_inventory SET quantity = quantity + $qty WHERE product_id = $pid ORDER BY quantity DESC LIMIT 1");
    if ($conn->affected_rows === 0) {
        if ($has_expiry_col) {
            $exp_date = date('Y-m-d', strtotime('+7 days'));
            $conn->query("INSERT INTO product_inventory (product_id, quantity, expiration_date) VALUES ($pid, $qty, '$exp_date')");
        } else {
            $conn->query("INSERT INTO product_inventory (product_id, quantity) VALUES ($pid, $qty)");
        }
    }
    $conn->query("UPDATE products SET status = 'Active' WHERE product_id = $pid AND status = 'Out of Stock'");
}

// --- EXPENSE HELPER ---
function log_spoilage_expense($conn, $product_id, $qty, $spoilage_type, $reason) {
    // Get product details for financial expense loss calculation
    $prodInfo = $conn->query("SELECT name, price, unit FROM products WHERE product_id = $product_id")->fetch_assoc();
    $unit_price = isset($prodInfo['price']) ? floatval($prodInfo['price']) : 0;
    $total_loss = $qty * $unit_price;

    if ($total_loss > 0) {
        $unit = !empty($prodInfo['unit']) ? strtolower($prodInfo['unit']) : '';
        $formatted_qty = fmod($qty, 1) == 0 ? number_format($qty, 0) : number_format($qty, 2);
        
        $expense_desc = "Spoilage ($spoilage_type) - Lost {$formatted_qty}{$unit} of " . ($prodInfo['name'] ?? 'Unknown') . ". Reason: $reason";
        $expense_cat = 'Spoilage Loss';
        $exp_date = date('Y-m-d');

        $exp_sql = "INSERT INTO expenses (expense_date, category, amount, quantity, payment_method, description, product_id) VALUES (?, ?, ?, ?, 'Other', ?, ?)";
        try {
            if ($stmt_exp = $conn->prepare($exp_sql)) {
                $stmt_exp->bind_param("ssddsi", $exp_date, $expense_cat, $total_loss, $qty, $expense_desc, $product_id);
                $stmt_exp->execute();
                $stmt_exp->close();
            }
        } catch(Throwable $e) { /* Failsafe */ }
    }
}

// --- MULTI-PHOTO UPLOAD HELPER ---
function handle_multiple_return_uploads($file_key) {
    $uploaded_paths = [];
    if (isset($_FILES[$file_key])) {
        $total = count($_FILES[$file_key]['name']);
        for ($i = 0; $i < $total; $i++) {
            if ($_FILES[$file_key]['error'][$i] == 0) {
                $target_dir = "../../assets/img/returns/";
                if (!is_dir($target_dir)) @mkdir($target_dir, 0777, true);
                
                $file_ext = pathinfo($_FILES[$file_key]["name"][$i], PATHINFO_EXTENSION);
                $file_name = uniqid('ret_') . '_' . time() . '_' . $i . '.' . $file_ext;
                $target_file = $target_dir . $file_name;
                
                $check = @getimagesize($_FILES[$file_key]["tmp_name"][$i]);
                if($check !== false) {
                    if (move_uploaded_file($_FILES[$file_key]["tmp_name"][$i], $target_file)) {
                        $uploaded_paths[] = "assets/img/returns/" . $file_name;
                    }
                }
            }
        }
    }
    return !empty($uploaded_paths) ? json_encode($uploaded_paths) : NULL;
}

// --- BACKEND HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean(); header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // 1. GET SALE ITEMS
    if ($action === 'get_sale_items') {
        $sale_id = intval($_POST['sale_id']);
        $query = "
            SELECT si.product_id, si.quantity as sold_qty, si.returned_qty, 
                   (si.quantity - si.returned_qty) as remaining_qty,
                   p.name, p.unit
            FROM sales_items si
            JOIN products p ON si.product_id = p.product_id
            WHERE si.sale_id = ? AND (si.quantity - si.returned_qty) > 0.001
        ";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $sale_id);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['success' => true, 'items' => $items]);
        exit;
    }

    // 2. GET RETURN HISTORY
    if ($action === 'get_return_history') {
        $sale_id = intval($_POST['sale_id']);
        $query = "
            SELECT r.*, p.name, p.unit
            FROM returns r
            JOIN products p ON r.product_id = p.product_id
            WHERE r.sale_id = ?
            ORDER BY r.return_id DESC
        ";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $sale_id);
        $stmt->execute();
        $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['success' => true, 'history' => $history]);
        exit;
    }

    // 3. GET HELD ITEMS
    if ($action === 'get_held_items') {
        $query = "
            SELECT r.*, p.name, p.unit
            FROM returns r
            JOIN products p ON r.product_id = p.product_id
            WHERE r.condition_status = 'Damaged'
            ORDER BY r.return_id ASC
        ";
        $held = $conn->query($query)->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['success' => true, 'held_items' => $held]);
        exit;
    }

    // 4. RESOLVE HELD ITEM
    if ($action === 'resolve_hold') {
        $return_id = intval($_POST['return_id']);
        $resolution = $_POST['resolution']; 

        $conn->begin_transaction();
        try {
            $ret = $conn->query("SELECT * FROM returns WHERE return_id = $return_id")->fetch_assoc();
            if (!$ret) throw new Exception("Return record not found.");

            $pid = $ret['product_id'];
            $qty = floatval($ret['quantity']);

            if ($resolution === 'restock') {
                smart_restock($conn, $pid, $qty);
                $conn->query("UPDATE returns SET condition_status = 'Damaged (Restocked)' WHERE return_id = $return_id");
                if (function_exists('log_audit_action')) log_audit_action('Resolve Hold', 'Returns', "Fixed packaging & restocked item (Return #$return_id).");
            
            } else if ($resolution === 'waste') {
                $spoil_type = "Damaged in Transit";
                $spoil_reason = "Wasted (Packaging Destroyed) - Return #$return_id";
                
                $spoilage_images_json = $ret['proof_image'];
                
                $stmt_spoil = $conn->prepare("INSERT INTO spoilage (product_id, quantity, spoilage_type, reason, spoilage_images, recorded_by) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt_spoil->bind_param("idsssi", $pid, $qty, $spoil_type, $spoil_reason, $spoilage_images_json, $user_id);
                $stmt_spoil->execute();

                log_spoilage_expense($conn, $pid, $qty, $spoil_type, $spoil_reason);
                
                $conn->query("UPDATE returns SET condition_status = 'Damaged (Wasted)' WHERE return_id = $return_id");
                if (function_exists('log_audit_action')) log_audit_action('Resolve Hold', 'Returns', "Disposed damaged package to spoilage (Return #$return_id).");
            }
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Item resolved successfully!']);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // 5. PROCESS RETURN
    if ($action === 'process_return') {
        $sale_id = intval($_POST['sale_id']);
        $items_to_return = json_decode($_POST['items'], true); 

        if (empty($items_to_return) || !is_array($items_to_return)) {
            echo json_encode(['success' => false, 'message' => 'No items selected.']); exit;
        }

        $conn->begin_transaction();
        try {
            $orig_order = $conn->query("SELECT client_id, delivered_at FROM sales WHERE sale_id = $sale_id")->fetch_assoc();
            if (!$orig_order) throw new Exception("Order not found.");
            
            $client_id = $orig_order['client_id'] ?? 0;
            
            // SECURITY: Backend Time Lock Check
            $hours_passed = (time() - strtotime($orig_order['delivered_at'])) / 3600;
            if ($hours_passed > $RETURN_LOCK_HOURS && $current_role !== 'Super Admin') {
                throw new Exception("Return window has closed. Orders cannot be returned after {$RETURN_LOCK_HOURS} hours.");
            }
            
            $replacement_items_queue = []; 

            foreach ($items_to_return as $index => $item_data) {
                $product_id = intval($item_data['product_id']);
                $qty_to_return = floatval($item_data['qty']);
                $condition = $item_data['condition']; 
                $reason = trim($item_data['reason']);
                $action_req = $item_data['action_req'] ?? 'Replace'; 

                if ($qty_to_return <= 0.0001) continue;

                $file_key = 'item_photos_' . $index; 
                $proof_images_json = handle_multiple_return_uploads($file_key);

                $check = $conn->prepare("SELECT quantity, returned_qty, price FROM sales_items WHERE sale_id = ? AND product_id = ?");
                $check->bind_param("ii", $sale_id, $product_id);
                $check->execute();
                $db_item = $check->get_result()->fetch_assoc();

                if (!$db_item || ($db_item['quantity'] - $db_item['returned_qty'] < ($qty_to_return - 0.001))) {
                    throw new Exception("Qty exceeds remaining amount for Product ID: $product_id");
                }
                
                $item_price = floatval($db_item['price']);

                $stmt = $conn->prepare("INSERT INTO returns (sale_id, product_id, quantity, reason, condition_status, proof_image, action_requested) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iidssss", $sale_id, $product_id, $qty_to_return, $reason, $condition, $proof_images_json, $action_req);
                $stmt->execute();

                $upd = $conn->prepare("UPDATE sales_items SET returned_qty = returned_qty + ? WHERE sale_id = ? AND product_id = ?");
                $upd->bind_param("dii", $qty_to_return, $sale_id, $product_id);
                $upd->execute();

                if ($action_req === 'Refund') {
                    $refund_value = $qty_to_return * $item_price;
                    $conn->query("UPDATE sales SET total_amount = GREATEST(0, total_amount - $refund_value) WHERE sale_id = $sale_id");
                    $conn->query("UPDATE sales_items SET quantity = GREATEST(0, quantity - $qty_to_return), subtotal = GREATEST(0, subtotal - $refund_value) WHERE sale_id = $sale_id AND product_id = $product_id");
                } else {
                    $replacement_items_queue[] = ['product_id' => $product_id, 'qty' => $qty_to_return];
                }

                if ($condition === 'Good') {
                    smart_restock($conn, $product_id, $qty_to_return);
                } elseif ($condition === 'Spoiled') {
                    $spoil_type = "Client Reject";
                    $spoil_reason = "Return Order #$sale_id: " . $reason;
                    $spoil = $conn->prepare("INSERT INTO spoilage (product_id, quantity, spoilage_type, reason, spoilage_images, recorded_by) VALUES (?, ?, ?, ?, ?, ?)");
                    $spoil->bind_param("idsssi", $product_id, $qty_to_return, $spoil_type, $spoil_reason, $proof_images_json, $user_id);
                    $spoil->execute();

                    log_spoilage_expense($conn, $product_id, $qty_to_return, $spoil_type, $spoil_reason);
                }

                if (function_exists('log_audit_action')) {
                    $p_res = $conn->query("SELECT name, unit FROM products WHERE product_id = $product_id")->fetch_assoc();
                    $p_name = $p_res['name'] ?? "Item #$product_id";
                    $p_unit = $p_res['unit'] ?? "pcs";
                    $res_text = ($action_req === 'Replace') ? "[Queued for Replacement]" : "[Refunded]";
                    log_audit_action('Process Return', 'Returns', "Processed $action_req for '$p_name' (Order #$sale_id). Qty: $qty_to_return $p_unit. Status: $condition. $res_text");
                }
            }
            
            if (!empty($replacement_items_queue) && $client_id > 0) {
                $zero = 0.00;
                $status = 'Pending';
                $pay_status = 'Replacement #' . $sale_id; 
                $today = date('Y-m-d');
                
                $stmt_new_sale = $conn->prepare("INSERT INTO sales (client_id, total_amount, order_status, payment_status, delivery_date) VALUES (?, ?, ?, ?, ?)");
                $stmt_new_sale->bind_param("idsss", $client_id, $zero, $status, $pay_status, $today);
                $stmt_new_sale->execute();
                $new_sale_id = $conn->insert_id;
                
                $stmt_new_item = $conn->prepare("INSERT INTO sales_items (sale_id, product_id, quantity, price, subtotal) VALUES (?, ?, ?, ?, ?)");
                
                foreach ($replacement_items_queue as $rep) {
                    $pid = $rep['product_id'];
                    $qty = $rep['qty'];
                    $stmt_new_item->bind_param("iiddd", $new_sale_id, $pid, $qty, $zero, $zero);
                    $stmt_new_item->execute();
                    $conn->query("UPDATE product_inventory SET quantity = quantity - $qty WHERE product_id = $pid ORDER BY quantity DESC LIMIT 1");
                }
                if (function_exists('log_audit_action')) log_audit_action('Create Order', 'Returns', "Auto-generated ₱0.00 Replacement Order #$new_sale_id.");
            }

            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Returns processed successfully!']);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // 6. EDIT RETURN ITEM
    if ($action === 'edit_return_item') {
        $return_id = intval($_POST['return_id']);
        $new_reason = trim($_POST['reason']);
        
        if($return_id <= 0 || empty($new_reason)) {
            echo json_encode(['success' => false, 'message' => 'Invalid data.']); exit;
        }

        $conn->begin_transaction();
        try {
            $curr = $conn->query("SELECT proof_image FROM returns WHERE return_id = $return_id")->fetch_assoc();
            if(!$curr) throw new Exception("Return record not found.");

            $current_images = [];
            if (!empty($curr['proof_image'])) {
                $decoded = json_decode($curr['proof_image'], true);
                $current_images = is_array($decoded) ? $decoded : [$curr['proof_image']];
            }

            if (isset($_POST['remove_images']) && is_array($_POST['remove_images'])) {
                foreach ($_POST['remove_images'] as $rem_img) {
                    if (($key = array_search($rem_img, $current_images)) !== false) {
                        unset($current_images[$key]);
                    }
                }
                $current_images = array_values($current_images); 
            }

            $new_images_json = handle_multiple_return_uploads('edit_proof_images');
            if ($new_images_json) {
                $new_images = json_decode($new_images_json, true);
                $current_images = array_merge($current_images, $new_images);
            }

            $final_images_json = !empty($current_images) ? json_encode($current_images) : NULL;

            $stmt = $conn->prepare("UPDATE returns SET reason = ?, proof_image = ? WHERE return_id = ?");
            $stmt->bind_param("ssi", $new_reason, $final_images_json, $return_id);
            $stmt->execute();

            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Return record updated.']);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}
ob_end_flush();

// --- FETCH DATA FOR UI ---
$orders_sql = "
    SELECT s.sale_id, s.delivered_at, s.driver_name, c.client_name, 
           (SELECT COUNT(*) FROM returns r WHERE r.sale_id = s.sale_id) as return_count,
           (SELECT SUM(quantity - returned_qty) FROM sales_items si WHERE si.sale_id = s.sale_id) as remaining_returnable
    FROM sales s
    LEFT JOIN clients c ON s.client_id = c.client_id
    WHERE s.order_status IN ('Completed', 'Delivered')
    ORDER BY s.delivered_at DESC
    LIMIT 200
";
$orders = $conn->query($orders_sql)->fetch_all(MYSQLI_ASSOC);
$clients = $conn->query("SELECT DISTINCT c.client_name FROM sales s JOIN clients c ON s.client_id = c.client_id WHERE s.order_status IN ('Completed', 'Delivered') ORDER BY c.client_name ASC")->fetch_all(MYSQLI_ASSOC);

$total_delivered = count($orders);
$orders_with_returns = 0;
$total_returns_count = $conn->query("SELECT SUM(quantity) as total FROM returns")->fetch_assoc()['total'] ?? 0;
$held_count = $conn->query("SELECT COUNT(*) as hc FROM returns WHERE condition_status = 'Damaged'")->fetch_assoc()['hc'] ?? 0;

foreach($orders as $o) { if ($o['return_count'] > 0) $orders_with_returns++; }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreshFlow - Returns</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class' };
    </script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/jpeg" href="/admin_components/assets/img/tabicon4.png">
    <script>
        // DARK MODE INITIALIZATION
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
        
        // Prevent white flash on reload
        window.onpageshow = function(event) { if (event.persisted) window.location.reload(); };
        window.onbeforeunload = function() { 
            document.body.innerHTML = ""; 
            if (document.documentElement.classList.contains('dark')) {
                document.body.style.backgroundColor = "#000000"; 
            } else {
                document.body.style.backgroundColor = "#F8F5EE"; 
            }
        };
    </script>

    <style>
        :root { --brand-green: #1E3A1D; --brand-cream: #F8F5EE; }
        body { font-family: 'Inter', sans-serif; background-color: var(--brand-cream); color: #2B2B2B; transition: background-color 0.3s ease; }
        
        /* --- DARK MODE GLOBAL STYLES --- */
        .dark body {
            background-color: #000000;
            background-image: radial-gradient(circle, rgba(255, 255, 255, 0.07) 1px, transparent 1px);
            background-size: 16px 16px;
            color: #f8fafc;
        }

        .font-mono, .font-heading { font-family: 'Roboto Mono', monospace; }
        .custom-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .dark .custom-scroll::-webkit-scrollbar-thumb { background: #334155; }
        
        /* Inputs & Filters */
        .form-input, .filter-select { background-color: #ffffff; border: 1px solid #d1d5db; color: #374151; border-radius: 0.5rem; transition: all 0.2s; }
        .form-input:focus, .filter-select:focus { outline: none; border-color: #B33333; box-shadow: 0 0 0 3px rgba(179, 51, 51, 0.1); }
        .dark .form-input, .dark .filter-select { background-color: rgba(30, 41, 59, 0.6); border-color: #334155; color: #f8fafc; }
        .dark .form-input:focus, .dark .filter-select:focus { border-color: #f87171; box-shadow: 0 0 0 3px rgba(248, 113, 113, 0.15); }
        
        /* Content Card Styles for Glowing Hover */
        .content-card { 
            background-color: #ffffff; 
            border: 1px solid #e5e7eb; 
            border-radius: 0.75rem; 
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); 
            transition: all 0.3s ease; 
        }
        .dark .content-card {
            background-color: rgba(15, 23, 42, 0.6); 
            backdrop-filter: blur(12px);
            border-color: #1e293b; 
        }

        .modal-z { z-index: 50; } 
        .modal-z-top { z-index: 60; } 
        .gallery-z { z-index: 70; }
    </style>
</head>
<body class="flex h-screen overflow-hidden">

    <?php include '../includes/sidebar.php'; ?>

    <main class="ml-20 flex-1 flex flex-col h-screen overflow-hidden relative transition-all duration-300 p-6 md:p-8">
        
       <header class="flex justify-between items-center mb-6 flex-shrink-0">
            <div>
                <h1 class="text-3xl font-bold text-[#1E3A1D] dark:text-white tracking-tight flex items-center gap-3">
                    <span class="material-icons text-3xl dark:text-green-400">assignment_return</span> Returns Management
                </h1>
                <p class="text-sm text-gray-500 dark:text-slate-400 mt-1 ml-11">
                    Process customer returns, review proof photos, and manage damaged items.
                </p>
            </div>
            <div class="flex items-center gap-3 print-hide">
                <button onclick="openHeldItemsModal()" class="bg-orange-100 dark:bg-orange-900/30 text-orange-700 dark:text-orange-400 border border-orange-200 dark:border-orange-800/50 hover:bg-orange-200 dark:hover:bg-orange-800/50 px-4 py-2.5 rounded-lg text-sm font-bold transition flex items-center gap-2 shadow-sm">
                    <span class="material-icons text-[18px]">inventory</span> Review Held Items
                </button>
                
            </div>
        </header>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6 flex-shrink-0">
            <div class="content-card p-5 border-l-4 border-blue-500 dark:border-blue-400 flex items-center justify-between group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(59,130,246,0.2)] dark:hover:shadow-[0_0_20px_rgba(59,130,246,0.3)] dark:hover:border-blue-300 transition-all duration-300">
                <div>
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase group-hover:text-blue-500 dark:group-hover:text-blue-300 transition-colors">Delivered Orders</p>
                    <p class="font-heading text-3xl font-bold text-blue-600 dark:text-blue-400 mt-1 group-hover:scale-110 transition-transform origin-left"><?= number_format($total_delivered) ?></p>
                </div>
                <div class="bg-blue-50 dark:bg-blue-900/30 p-3 rounded-full text-blue-600 dark:text-blue-400 group-hover:bg-blue-100 dark:group-hover:bg-blue-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">local_shipping</span>
                </div>
            </div>

            <div class="content-card p-5 border-l-4 border-red-500 dark:border-red-400 flex items-center justify-between group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(239,68,68,0.2)] dark:hover:shadow-[0_0_20px_rgba(239,68,68,0.3)] dark:hover:border-red-300 transition-all duration-300">
                <div>
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase group-hover:text-red-500 dark:group-hover:text-red-300 transition-colors">Orders w/ Returns</p>
                    <p class="font-heading text-3xl font-bold text-red-600 dark:text-red-400 mt-1 group-hover:scale-110 transition-transform origin-left"><?= number_format($orders_with_returns) ?></p>
                </div>
                <div class="bg-red-50 dark:bg-red-900/30 p-3 rounded-full text-red-600 dark:text-red-400 group-hover:bg-red-100 dark:group-hover:bg-red-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">assignment_return</span>
                </div>
            </div>

            <div class="content-card p-5 border-l-4 border-yellow-500 dark:border-yellow-400 flex items-center justify-between group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(234,179,8,0.2)] dark:hover:shadow-[0_0_20px_rgba(234,179,8,0.3)] dark:hover:border-yellow-300 transition-all duration-300">
                <div>
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase group-hover:text-yellow-500 dark:group-hover:text-yellow-300 transition-colors">Total Items Returned</p>
                    <p class="font-heading text-3xl font-bold text-yellow-600 dark:text-yellow-400 mt-1 group-hover:scale-110 transition-transform origin-left"><?= number_format($total_returns_count, 2) ?></p>
                </div>
                <div class="bg-yellow-50 dark:bg-yellow-900/30 p-3 rounded-full text-yellow-600 dark:text-yellow-400 group-hover:bg-yellow-100 dark:group-hover:bg-yellow-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">remove_shopping_cart</span>
                </div>
            </div>
        </div>

        <div class="flex flex-col md:flex-row gap-4 mb-4 flex-shrink-0">
            <input type="text" id="orderSearch" placeholder="Search Order ID..." class="form-input flex-1 p-3 text-sm" oninput="applyFilters()">
            <select id="clientFilter" class="filter-select p-3 text-sm min-w-[200px]" onchange="applyFilters()">
                <option value="">All Clients</option>
                <?php foreach($clients as $c): ?>
                    <option value="<?= htmlspecialchars($c['client_name']) ?>"><?= htmlspecialchars($c['client_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="statusFilter" class="filter-select p-3 text-sm" onchange="applyFilters()">
                <option value="">All Status</option>
                <option value="Clean">No Returns</option>
                <option value="Has Returns">Has Returns</option>
            </select>
        </div>

        <div class="content-card flex-1 overflow-hidden flex flex-col mb-4">
            <div class="overflow-y-auto flex-1 custom-scroll pb-24">
                <table class="w-full text-left">
                    <thead class="bg-[#1E3A1D] dark:bg-slate-800 text-white text-xs uppercase font-bold sticky top-0 z-10">
                        <tr>
                            <th class="p-4 pl-6">Order ID & Client</th>
                            <th class="p-4">Delivery Date / Driver</th>
                            <th class="p-4">Return Status</th>
                            <th class="p-4 pr-6 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700/50 text-sm">
                        <?php if(empty($orders)): ?>
                            <tr><td colspan="4" class="p-8 text-center text-gray-500 dark:text-slate-400 italic">No completed deliveries available for return processing.</td></tr>
                        <?php else: ?>
                            <?php foreach($orders as $o): 
                                $remaining = floatval($o['remaining_returnable']);
                                $hours_passed = (time() - strtotime($o['delivered_at'])) / 3600;
                                $canBypass = ($current_role === 'Super Admin');
                                $isLocked = ($hours_passed > $RETURN_LOCK_HOURS && !$canBypass);
                                $fullyReturned = ($remaining <= 0.001);
                            ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-slate-800/50 transition order-row <?= $isLocked ? 'bg-gray-50/50 dark:bg-slate-800/30' : '' ?>" data-client="<?= htmlspecialchars($o['client_name']) ?>" data-returns="<?= $o['return_count'] > 0 ? 'Has Returns' : 'Clean' ?>" data-id="<?= str_pad($o['sale_id'], 5, '0', STR_PAD_LEFT) ?>">
                                <td class="p-4 pl-6 align-middle">
                                    <div class="font-mono font-bold text-gray-900 dark:text-white text-lg">#<?= str_pad($o['sale_id'], 5, '0', STR_PAD_LEFT) ?></div>
                                    <div class="text-xs text-gray-500 dark:text-slate-400 font-bold mt-1"><?= htmlspecialchars($o['client_name']) ?></div>
                                </td>
                                <td class="p-4 align-middle">
                                    <div class="flex items-center gap-1.5 text-gray-800 dark:text-slate-200 font-semibold"><span class="material-icons text-[14px] text-gray-400 dark:text-slate-500">calendar_today</span> <?= date('M d, Y h:i A', strtotime($o['delivered_at'])) ?></div>
                                    <div class="text-[11px] text-gray-500 dark:text-slate-400 mt-1 flex items-center gap-1"><span class="material-icons text-[14px]">local_shipping</span> <?= htmlspecialchars($o['driver_name']) ?></div>
                                </td>
                                <td class="p-4 align-middle">
                                    <?php if($o['return_count'] > 0): ?>
                                        <span class="bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-400 border border-red-200 dark:border-red-800/50 px-2.5 py-1 rounded text-xs font-bold flex items-center w-max gap-1">
                                            <span class="material-icons text-[14px]">error</span> <?= $o['return_count'] ?> Return(s) Logged
                                        </span>
                                    <?php else: ?>
                                        <span class="bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-400 border border-green-200 dark:border-green-800/50 px-2.5 py-1 rounded text-xs font-bold flex items-center w-max gap-1">
                                            <span class="material-icons text-[14px]">check_circle</span> Clean
                                        </span>
                                    <?php endif; ?>

                                    <?php if($isLocked): ?>
                                        <div class="text-[10px] text-gray-400 dark:text-slate-500 mt-1 flex items-center gap-1"><span class="material-icons text-[12px]">lock</span> Past <?= $RETURN_LOCK_HOURS ?>h limit</div>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 pr-6 align-middle text-right">
                                    <div class="flex justify-end gap-2">
                                        <?php if($o['return_count'] > 0): ?>
                                            <button onclick="openViewReturnsModal(<?= $o['sale_id'] ?>)" class="text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-900/30 hover:bg-blue-100 dark:hover:bg-blue-800/50 p-2 rounded border border-blue-200 dark:border-blue-800/50 transition" title="View Return History">
                                                <span class="material-icons text-[18px]">history</span>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if(!$fullyReturned): ?>
                                            <?php if(!$isLocked): ?>
                                                <button onclick="openReturnModal(<?= $o['sale_id'] ?>)" class="text-[#1E3A1D] dark:text-green-400 bg-[#F8F5EE] dark:bg-green-900/20 border border-[#1E3A1D]/20 dark:border-green-800/50 hover:bg-[#1E3A1D] dark:hover:bg-green-800 hover:text-white dark:hover:text-white px-3 py-1.5 rounded text-xs font-bold transition flex items-center gap-1">
                                                    <span class="material-icons text-[16px]">add_circle</span> Process Return
                                                </button>
                                            <?php else: ?>
                                                <button disabled class="text-gray-400 dark:text-slate-500 bg-gray-100 dark:bg-slate-800 border border-gray-200 dark:border-slate-700 px-3 py-1.5 rounded text-xs font-bold cursor-not-allowed flex items-center gap-1">
                                                    <span class="material-icons text-[16px]">lock</span> Locked
                                                </button>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-xs text-gray-400 dark:text-slate-500 italic font-semibold px-2 py-1 bg-gray-50 dark:bg-slate-800 rounded border border-gray-100 dark:border-slate-700">Fully Returned</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <div id="flashMessage" class="fixed bottom-6 right-6 z-[100] bg-[#1E3A1D] dark:bg-slate-800 border dark:border-slate-700 text-white px-6 py-4 rounded-lg shadow-2xl flex items-center gap-3 transform translate-y-20 transition-all duration-300 opacity-0 pointer-events-none">
        <span class="material-icons text-green-400" id="flashIcon">check_circle</span>
        <div><h4 class="font-bold text-sm">Notification</h4><p class="text-xs text-gray-300" id="flashText"></p></div>
    </div>

    <div id="heldItemsModal" class="fixed inset-0 bg-[#1E3A1D] dark:bg-black dark:bg-opacity-70 bg-opacity-40 hidden flex items-center justify-center modal-z backdrop-blur-sm transition-opacity">
        <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-4xl overflow-hidden max-h-[90vh] flex flex-col border dark:border-slate-700">
            <div class="bg-orange-500 dark:bg-orange-600 p-5 text-white flex justify-between items-center flex-shrink-0">
                <h2 class="text-lg font-bold flex items-center gap-2"><span class="material-icons">inventory_2</span> Review Held Items (Packaging Issues)</h2>
                <button type="button" onclick="closeModal('heldItemsModal')" class="text-orange-200 hover:text-white transition"><span class="material-icons">close</span></button>
            </div>
            <div class="bg-orange-50 dark:bg-orange-900/20 p-4 border-b border-orange-100 dark:border-orange-800/30 flex items-start gap-3 flex-shrink-0">
                <span class="material-icons text-orange-400 mt-0.5">info</span>
                <p class="text-sm text-orange-800 dark:text-orange-300">These items were returned because their packaging was damaged. Review them and decide whether to <b>Restock</b> (if you replaced the packaging) or move them to <b>Spoilage</b> (if the product itself is ruined).</p>
            </div>
            <div class="p-6 overflow-y-auto custom-scroll flex-1 bg-gray-50 dark:bg-slate-900/50">
                <div id="heldItemsContainer" class="space-y-4"></div>
            </div>
        </div>
    </div>

    <div id="viewReturnsModal" class="fixed inset-0 bg-[#1E3A1D] dark:bg-black dark:bg-opacity-70 bg-opacity-40 hidden flex items-center justify-center modal-z backdrop-blur-sm transition-opacity">
        <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-3xl overflow-hidden max-h-[90vh] flex flex-col border dark:border-slate-700">
            <div class="bg-blue-600 p-5 text-white flex justify-between items-center flex-shrink-0">
                <h2 class="text-lg font-bold flex items-center gap-2"><span class="material-icons">history</span> Return History: Order #<span id="view-hist-sale-id"></span></h2>
                <button type="button" onclick="closeModal('viewReturnsModal')" class="text-blue-200 hover:text-white transition"><span class="material-icons">close</span></button>
            </div>
            <div class="p-6 overflow-y-auto custom-scroll flex-1 bg-blue-50/30 dark:bg-slate-900/50">
                <div id="returnsHistoryContainer" class="space-y-4"></div>
            </div>
        </div>
    </div>

    <div id="editReturnModal" class="fixed inset-0 bg-black bg-opacity-60 hidden flex items-center justify-center modal-z-top backdrop-blur-sm transition-opacity">
        <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden max-h-[90vh] flex flex-col border dark:border-slate-700">
            <div class="bg-gray-800 dark:bg-slate-800 p-4 text-white flex justify-between items-center flex-shrink-0">
                <h2 class="text-base font-bold flex items-center gap-2"><span class="material-icons">edit</span> Edit Return Record</h2>
                <button type="button" onclick="closeModal('editReturnModal')" class="text-gray-300 hover:text-white transition"><span class="material-icons">close</span></button>
            </div>
            
            <div class="p-6 overflow-y-auto custom-scroll flex-1 bg-gray-50 dark:bg-slate-900/50">
                <form id="editReturnForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="edit_return_item">
                    <input type="hidden" name="return_id" id="edit_return_id">
                    <div id="edit_deletedImagesContainer"></div>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-[10px] font-bold text-gray-400 uppercase mb-1">Product</label>
                            <input type="text" id="edit_return_product" readonly class="form-input text-sm font-bold w-full bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400 cursor-not-allowed">
                        </div>

                        <div>
                            <label class="block text-[10px] font-bold text-gray-400 uppercase mb-1">Reason for Return</label>
                            <textarea name="reason" id="edit_return_reason" rows="2" class="form-input text-sm w-full custom-scroll" required></textarea>
                        </div>

                        <div class="border-t border-gray-200 dark:border-slate-700 pt-4 mt-2">
                            <div class="mb-3">
                                <label class="block text-[10px] font-bold text-gray-400 uppercase mb-2">Current Saved Photos (Hover to delete)</label>
                                <div id="edit_currentEditPhotoGrid" class="flex flex-wrap gap-2 p-2 bg-gray-100 dark:bg-slate-800 rounded-lg border border-gray-200 dark:border-slate-700 min-h-[60px] items-center text-xs text-gray-400"></div>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-400 uppercase mb-2">Upload Additional Photos</label>
                                <div class="flex items-center gap-4">
                                    <label class="cursor-pointer">
                                        <div class="px-4 py-1.5 bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded shadow-sm text-xs font-semibold text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700 transition flex items-center gap-1">
                                            <span class="material-icons text-[14px]">add_photo_alternate</span> Select Photos
                                            <input type="file" name="edit_proof_images[]" id="edit_returnFileInput" multiple accept="image/*" class="hidden" onchange="handleFileSelect(this, 'editRetDT', 'edit_returnPhotoGrid', 'edit_returnPhotoText')">
                                        </div>
                                    </label>
                                    <span id="edit_returnPhotoText" class="text-[10px] text-gray-400 italic">0 additional photos selected</span>
                                </div>
                                <div id="edit_returnPhotoGrid" class="flex flex-wrap gap-2 hidden p-2 mt-2 bg-gray-100 dark:bg-slate-800 rounded-lg border border-gray-200 dark:border-slate-700"></div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="p-4 border-t border-gray-100 dark:border-slate-700 bg-white dark:bg-slate-900 flex justify-end gap-3 flex-shrink-0">
                <button type="button" onclick="closeModal('editReturnModal')" class="px-4 py-2 text-gray-500 dark:text-slate-400 hover:bg-gray-100 dark:hover:bg-slate-800 rounded-lg text-sm font-bold transition">Cancel</button>
                <button type="button" onclick="submitEditReturn()" class="bg-gray-800 text-white px-5 py-2 rounded-lg text-sm font-bold hover:bg-gray-900 shadow transition flex items-center gap-1">
                    <span class="material-icons text-sm">save</span> Save
                </button>
            </div>
        </div>
    </div>

    <div id="returnModal" class="fixed inset-0 bg-[#1E3A1D] dark:bg-black dark:bg-opacity-70 bg-opacity-40 hidden flex items-center justify-center modal-z backdrop-blur-sm transition-opacity">
        <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-5xl overflow-hidden max-h-[90vh] flex flex-col border dark:border-slate-700">
            <div class="bg-[#1E3A1D] dark:bg-slate-800 p-5 text-white flex justify-between items-center flex-shrink-0">
                <h2 class="text-lg font-bold flex items-center gap-2"><span class="material-icons">assignment_return</span> Process Return for Order #<span id="modal-sale-id"></span></h2>
                <button type="button" onclick="closeReturnModal()" class="text-green-200 hover:text-white transition"><span class="material-icons">close</span></button>
            </div>
            
            <div class="p-6 overflow-y-auto custom-scroll flex-1 bg-gray-50 dark:bg-slate-900/50">
                <form id="returnForm">
                    <input type="hidden" name="action" value="process_return">
                    <input type="hidden" name="sale_id" id="form-sale-id">
                    
                    <div id="returnItemsContainer" class="space-y-4"></div>
                    
                    <div class="mt-6 flex justify-center">
                        <button type="button" onclick="addReturnRow()" class="bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-400 border border-green-300 dark:border-green-800/50 hover:bg-green-200 dark:hover:bg-green-800/50 px-4 py-2 rounded-lg text-sm font-bold shadow-sm flex items-center gap-2 transition">
                            <span class="material-icons text-sm">add</span> Add Another Item to Return
                        </button>
                    </div>
                </form>
            </div>
            
            <div class="p-5 border-t border-gray-100 dark:border-slate-700 bg-white dark:bg-slate-900 flex justify-end gap-3 flex-shrink-0 shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.05)]">
                <button type="button" onclick="closeReturnModal()" class="px-5 py-2.5 text-gray-500 dark:text-slate-400 hover:bg-gray-100 dark:hover:bg-slate-800 rounded-lg text-sm font-bold transition">Cancel</button>
                <button type="button" id="submitReturnBtn" onclick="submitReturnForm()" class="bg-red-700 text-white px-6 py-2.5 rounded-lg text-sm font-bold hover:bg-red-800 shadow-md transition transform hover:-translate-y-0.5 flex items-center gap-2">
                    <span class="material-icons text-sm">check_circle</span> Process All Returns
                </button>
            </div>
        </div>
    </div>

    <div id="evidenceGalleryModal" class="fixed inset-0 bg-black bg-opacity-80 hidden flex items-center justify-center gallery-z backdrop-blur-sm transition-opacity p-4">
        <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-4xl overflow-hidden max-h-[90vh] flex flex-col border dark:border-slate-700">
            <div class="bg-gray-900 p-4 text-white flex justify-between items-center flex-shrink-0">
                <h2 class="text-base font-bold flex items-center gap-2"><span class="material-icons">photo_library</span> Evidence Album: <span id="galleryTitle" class="text-gray-300 font-normal ml-1"></span></h2>
                <button type="button" onclick="closeGallery()" class="text-gray-400 hover:text-white transition bg-gray-800 hover:bg-gray-700 p-1 rounded-full"><span class="material-icons">close</span></button>
            </div>
            
            <div class="p-6 overflow-y-auto custom-scroll flex-1 bg-gray-100 dark:bg-slate-800">
                <div id="galleryContainer" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4"></div>
            </div>
        </div>
    </div>

    <script>
        // --- GLOBAL UTILS ---
        let availableReturnItems = [];
        let rowCounter = 0;
        let flashTimeout;

        const uploadState = {
            'editRetDT': new DataTransfer()
        };

        const showFlash = (msg, type = 'success') => {
            if(flashTimeout) clearTimeout(flashTimeout);
            document.getElementById('flashText').textContent = msg;
            const fm = document.getElementById('flashMessage');
            const fi = document.getElementById('flashIcon');
            fm.className = `fixed bottom-6 right-6 z-[100] ${type === 'error' ? 'bg-red-700' : 'bg-[#1E3A1D]'} text-white px-6 py-4 rounded-lg shadow-2xl flex items-center gap-3 transform transition-all duration-300`;
            fi.textContent = type === 'error' ? 'error' : 'check_circle';
            fm.classList.remove('translate-y-20', 'opacity-0');
            flashTimeout = setTimeout(() => { fm.classList.add('translate-y-20', 'opacity-0'); }, 3000);
        };

        function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

        // --- MULTIPLE PHOTO UPLOAD FOR ALL MODALS ---
        function handleFileSelect(input, dtKey, gridId, textId) {
            if(!uploadState[dtKey]) uploadState[dtKey] = new DataTransfer();
            const dt = uploadState[dtKey];
            for (let file of input.files) { dt.items.add(file); }
            input.files = dt.files;
            renderPreviewGrid(input, dtKey, gridId, textId);
        }

        function renderPreviewGrid(input, dtKey, gridId, textId) {
            const container = document.getElementById(gridId);
            const text = document.getElementById(textId);
            const dt = uploadState[dtKey];
            container.innerHTML = ''; 
            
            if (dt.files.length > 0) {
                text.textContent = `${dt.files.length} photo(s) selected`;
                container.classList.remove('hidden');
                
                Array.from(dt.files).forEach((file, index) => {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const wrapper = document.createElement('div');
                        wrapper.className = "group h-12 w-12 flex-shrink-0 relative rounded shadow border border-gray-300 overflow-hidden bg-white";
                        
                        const img = document.createElement('img');
                        img.src = e.target.result;
                        img.className = "w-full h-full object-cover";
                        
                        const delBtn = document.createElement('button');
                        delBtn.type = "button";
                        delBtn.className = "absolute top-0 right-0 bg-red-600 text-white rounded-bl p-0.5 opacity-0 group-hover:opacity-100 transition cursor-pointer";
                        delBtn.innerHTML = '<span class="material-icons text-[10px]">close</span>';
                        
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

        // --- GALLERY ---
        function openGallery(imagesArray, productName) {
            document.getElementById('galleryTitle').textContent = productName;
            const container = document.getElementById('galleryContainer');
            container.innerHTML = '';

            imagesArray.forEach(imgSrc => {
                const a = document.createElement('a');
                a.href = "../../" + imgSrc;
                a.target = "_blank";
                a.className = "block aspect-square rounded-xl overflow-hidden shadow-sm hover:shadow-lg transition transform hover:-translate-y-1 bg-white border border-gray-200";
                const img = document.createElement('img');
                img.src = "../../" + imgSrc;
                img.className = "w-full h-full object-cover";
                a.appendChild(img);
                container.appendChild(a);
            });

            document.getElementById('evidenceGalleryModal').classList.remove('hidden');
        }
        function closeGallery() { document.getElementById('evidenceGalleryModal').classList.add('hidden'); }

      // --- HELD ITEMS LOGIC ---
        async function openHeldItemsModal() {
            const container = document.getElementById('heldItemsContainer');
            container.innerHTML = '<div class="text-center py-8 text-orange-400"><span class="animate-spin material-icons">autorenew</span> Fetching...</div>';
            document.getElementById('heldItemsModal').classList.remove('hidden');

            try {
                const formData = new FormData(); formData.append('action', 'get_held_items');
                const res = await fetch('', { method: 'POST', body: formData }).then(r => r.json());
                
                if (res.success && res.held_items.length > 0) {
                    container.innerHTML = '';
                    res.held_items.forEach(r => {
                        const isBulk = ['kg', 'g', 'liter', 'bottle'].includes(r.unit);
                        const qty = isBulk ? parseFloat(r.quantity).toFixed(2) : Math.floor(r.quantity);
                        
                        let evidenceArray = [];
                        if (r.proof_image) {
                            try { evidenceArray = JSON.parse(r.proof_image); } catch(e) { evidenceArray = [r.proof_image]; }
                            if(!Array.isArray(evidenceArray)) evidenceArray = [r.proof_image];
                        }

                        let imgHtml = '';
                        if(evidenceArray.length > 0) {
                            imgHtml = `
                                <div class="cursor-pointer group relative w-16 h-16 rounded border border-gray-300 dark:border-slate-600 overflow-hidden flex-shrink-0" onclick='openGallery(${JSON.stringify(evidenceArray)}, "${r.name.replace(/"/g, '&quot;')}")'>
                                    <img src="../../${evidenceArray[0]}" class="w-full h-full object-cover">
                                    <div class="absolute inset-0 bg-black bg-opacity-40 flex items-center justify-center opacity-0 group-hover:opacity-100 transition"><span class="material-icons text-white text-sm">photo_library</span></div>
                                    ${evidenceArray.length > 1 ? `<span class="absolute bottom-0 right-0 bg-black bg-opacity-70 text-white text-[10px] px-1 font-bold">+${evidenceArray.length - 1}</span>` : ''}
                                </div>
                            `;
                        } else {
                            imgHtml = `
                                <div class="w-16 h-16 rounded border border-gray-200 dark:border-slate-700 bg-gray-100 dark:bg-slate-800 flex items-center justify-center flex-shrink-0 text-gray-300 dark:text-slate-600">
                                    <span class="material-icons text-2xl">image_not_supported</span>
                                </div>
                            `;
                        }

                        container.innerHTML += `
                            <div class="bg-white dark:bg-slate-800 p-4 rounded-xl border border-orange-200 dark:border-slate-700 shadow-sm flex flex-col md:flex-row gap-4 items-start md:items-center">
                                ${imgHtml}
                                <div class="flex-1">
                                    <div class="font-bold text-gray-900 dark:text-white text-lg">${r.name}</div>
                                    <div class="text-xs text-gray-500 dark:text-slate-400 font-mono mt-0.5">Return Qty: <span class="font-bold text-orange-600 dark:text-orange-400">-${qty} ${r.unit}</span></div>
                                    <div class="mt-2 text-sm text-gray-700 dark:text-orange-300 bg-orange-50 dark:bg-orange-900/20 p-2.5 rounded border border-orange-100 dark:border-orange-800/30 italic flex gap-2">
                                        <span class="material-icons text-orange-400 text-sm mt-0.5">warning</span>
                                        "Damaged Packaging: ${r.reason}"
                                    </div>
                                    <div class="text-[10px] text-gray-400 dark:text-slate-500 mt-2 font-mono flex items-center gap-1">Order #${String(r.sale_id).padStart(5,'0')} &bull; Logged: ${r.return_date}</div>
                                </div>
                                <div class="flex flex-col gap-2 w-full md:w-auto mt-4 md:mt-0">
                                    <button onclick="resolveHold(${r.return_id}, 'restock')" class="bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 hover:bg-blue-100 dark:hover:bg-blue-800/50 border border-blue-200 dark:border-blue-800/50 px-4 py-2 rounded font-bold text-xs shadow-sm transition flex items-center justify-center gap-1">
                                        <span class="material-icons text-[16px]">inventory</span> Repackaged & Restocked
                                    </button>
                                    <button onclick="resolveHold(${r.return_id}, 'waste')" class="bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-800/50 border border-red-200 dark:border-red-800/50 px-4 py-2 rounded font-bold text-xs shadow-sm transition flex items-center justify-center gap-1">
                                        <span class="material-icons text-[16px]">delete_sweep</span> Send to Spoilage / Waste
                                    </button>
                                </div>
                            </div>
                        `;
                    });
                } else {
                    container.innerHTML = '<div class="text-center py-10"><span class="material-icons text-5xl text-orange-200 dark:text-orange-500/40 mb-2">check_circle</span><p class="text-gray-500 dark:text-slate-300 font-bold">All caught up!</p><p class="text-sm text-gray-400 dark:text-slate-500">No damaged packaging held items to review.</p></div>';
                }
            } catch(e) { container.innerHTML = '<div class="text-center py-8 text-red-500 dark:text-red-400">Error loading data.</div>'; }
        }

        async function resolveHold(returnId, resolution) {
            const actionText = resolution === 'restock' ? 'RESTOCK this item back into inventory' : 'send this item to SPOILAGE / WASTE';
            if(!confirm(`Are you sure you want to ${actionText}?`)) return;

            try {
                const formData = new FormData(); formData.append('action', 'resolve_hold');
                formData.append('return_id', returnId); formData.append('resolution', resolution);
                const res = await fetch('', { method: 'POST', body: formData }).then(r => r.json());
                if (res.success) { showFlash(res.message); openHeldItemsModal(); } 
                else { showFlash(res.message, 'error'); }
            } catch(e) { showFlash('System error.', 'error'); }
        }
        // --- VIEW HISTORY LOGIC ---
        async function openViewReturnsModal(saleId) {
            document.getElementById('view-hist-sale-id').textContent = String(saleId).padStart(5, '0');
            const container = document.getElementById('returnsHistoryContainer');
            container.innerHTML = '<div class="text-center py-8 text-gray-400 dark:text-slate-500"><span class="animate-spin material-icons">autorenew</span> Loading history...</div>';
            document.getElementById('viewReturnsModal').classList.remove('hidden');

            try {
                const formData = new FormData(); formData.append('action', 'get_return_history'); formData.append('sale_id', saleId);
                const res = await fetch('', { method: 'POST', body: formData }).then(r => r.json());
                
                if (res.success && res.history.length > 0) {
                    container.innerHTML = '';
                    res.history.forEach(r => {
                        const isBulk = ['kg', 'g', 'liter', 'bottle'].includes(r.unit);
                        const qty = isBulk ? parseFloat(r.quantity).toFixed(2) : Math.floor(r.quantity);
                        
                        let evidenceArray = [];
                        if (r.proof_image) {
                            try { evidenceArray = JSON.parse(r.proof_image); } catch(e) { evidenceArray = [r.proof_image]; }
                            if(!Array.isArray(evidenceArray)) evidenceArray = [r.proof_image];
                        }

                        let imgHtml = '';
                        if(evidenceArray.length > 0) {
                            imgHtml = `
                                <div class="cursor-pointer group relative w-16 h-16 rounded border border-gray-300 dark:border-slate-600 overflow-hidden flex-shrink-0" onclick='openGallery(${JSON.stringify(evidenceArray)}, "${r.name.replace(/"/g, '&quot;')}")'>
                                    <img src="../../${evidenceArray[0]}" class="w-full h-full object-cover">
                                    <div class="absolute inset-0 bg-black bg-opacity-40 flex items-center justify-center opacity-0 group-hover:opacity-100 transition"><span class="material-icons text-white text-sm">photo_library</span></div>
                                    ${evidenceArray.length > 1 ? `<span class="absolute bottom-0 right-0 bg-black bg-opacity-70 text-white text-[10px] px-1 font-bold">+${evidenceArray.length - 1}</span>` : ''}
                                </div>
                            `;
                        } else {
                            imgHtml = `
                                <div class="w-16 h-16 rounded border border-gray-200 dark:border-slate-700 bg-gray-100 dark:bg-slate-800 flex items-center justify-center flex-shrink-0 text-gray-300 dark:text-slate-600">
                                    <span class="material-icons text-2xl">image_not_supported</span>
                                </div>
                            `;
                        }

                        let condBadge = '';
                        if(r.condition_status.includes('Good')) condBadge = '<span class="px-2 py-0.5 bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-400 border border-green-200 dark:border-green-800/50 text-[10px] font-bold uppercase rounded flex items-center gap-1"><span class="material-icons text-[12px]">check_circle</span> Good (Restocked)</span>';
                        else if(r.condition_status.includes('Damaged (Restocked)')) condBadge = '<span class="px-2 py-0.5 bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-400 border border-blue-200 dark:border-blue-800/50 text-[10px] font-bold uppercase rounded flex items-center gap-1"><span class="material-icons text-[12px]">inventory</span> Packaging Fixed</span>';
                        else if(r.condition_status.includes('Damaged')) condBadge = '<span class="px-2 py-0.5 bg-orange-100 dark:bg-orange-900/30 text-orange-800 dark:text-orange-400 border border-orange-200 dark:border-orange-800/50 text-[10px] font-bold uppercase rounded flex items-center gap-1"><span class="material-icons text-[12px]">warning</span> Damaged Packaging</span>';
                        else condBadge = '<span class="px-2 py-0.5 bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-400 border border-red-200 dark:border-red-800/50 text-[10px] font-bold uppercase rounded flex items-center gap-1"><span class="material-icons text-[12px]">delete</span> Spoiled / Rotten</span>';

                        let actBadge = r.action_requested === 'Refund' 
                            ? '<span class="ml-2 px-1.5 py-0.5 bg-gray-200 dark:bg-slate-700 text-gray-700 dark:text-slate-300 text-[9px] font-bold uppercase rounded">Refunded</span>'
                            : '<span class="ml-2 px-1.5 py-0.5 bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 text-[9px] font-bold uppercase rounded">Replaced</span>';

                        const encodedEv = encodeURIComponent(JSON.stringify(evidenceArray));

                        container.innerHTML += `
                            <div class="bg-white dark:bg-slate-800 p-4 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm mb-4 flex gap-4 items-start relative group">
                                <button onclick="openEditReturnModal(${r.return_id}, '${r.name}', '${r.reason}', '${encodedEv}')" class="absolute top-3 right-3 text-gray-400 dark:text-slate-500 hover:text-blue-600 dark:hover:text-blue-400 opacity-0 group-hover:opacity-100 transition"><span class="material-icons text-sm">edit</span></button>
                                ${imgHtml}
                                <div class="flex-1">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <div class="font-bold text-gray-900 dark:text-white text-lg">${r.name}</div>
                                            <div class="text-xs text-gray-500 dark:text-slate-400 font-mono mt-0.5">Return Qty: <span class="font-bold text-red-600 dark:text-red-400">-${qty} ${r.unit}</span> ${actBadge}</div>
                                        </div>
                                    </div>
                                    <div class="mt-2.5 flex items-center gap-2">${condBadge}</div>
                                    <div class="mt-2 text-sm text-gray-700 dark:text-slate-300 bg-gray-50 dark:bg-slate-900/50 p-2.5 rounded-lg border border-gray-100 dark:border-slate-700 italic">"${r.reason}"</div>
                                    <div class="text-[10px] text-gray-400 dark:text-slate-500 mt-2 font-mono flex items-center gap-1"><span class="material-icons text-[12px]">schedule</span> Logged: ${r.return_date}</div>
                                </div>
                            </div>
                        `;
                    });
                } else {
                    container.innerHTML = '<div class="text-center py-8 text-gray-400 dark:text-slate-500 italic">No return history found.</div>';
                }
            } catch(e) { container.innerHTML = '<div class="text-center py-8 text-red-500 dark:text-red-400">Error loading data.</div>'; }
        }

        // --- EDIT RETURN MODAL ---
        function openEditReturnModal(returnId, productName, reason, evidenceJsonStr) {
            document.getElementById('editReturnForm').reset();
            document.getElementById('edit_return_id').value = returnId;
            document.getElementById('edit_return_product').value = productName;
            document.getElementById('edit_return_reason').value = reason;
            document.getElementById('edit_deletedImagesContainer').innerHTML = '';
            
            uploadState.editRetDT = new DataTransfer();
            document.getElementById('edit_returnFileInput').files = uploadState.editRetDT.files;
            document.getElementById('edit_returnPhotoGrid').innerHTML = '';
            document.getElementById('edit_returnPhotoGrid').classList.add('hidden');
            document.getElementById('edit_returnPhotoText').textContent = "0 additional photos selected";

            const currentGrid = document.getElementById('edit_currentEditPhotoGrid');
            currentGrid.innerHTML = '';
            
            let imgs = [];
            try { imgs = JSON.parse(decodeURIComponent(evidenceJsonStr)); } catch(e) {}
            
            if(imgs && imgs.length > 0) {
                imgs.forEach(src => {
                    const wrapper = document.createElement('div');
                    wrapper.className = "group h-12 w-12 flex-shrink-0 relative rounded border border-gray-300 shadow-sm overflow-hidden bg-white";
                    const imgTag = document.createElement('img');
                    imgTag.src = "../../" + src;
                    imgTag.className = "w-full h-full object-cover";
                    const delBtn = document.createElement('button');
                    delBtn.type = "button";
                    delBtn.className = "absolute top-0 right-0 bg-red-600 text-white rounded-bl p-0.5 opacity-0 group-hover:opacity-100 transition cursor-pointer";
                    delBtn.innerHTML = '<span class="material-icons text-[10px]">delete</span>';
                    
                    delBtn.onclick = function() {
                        if(confirm("Permanently delete this photo?")) {
                            const hiddenInput = document.createElement('input');
                            hiddenInput.type = 'hidden';
                            hiddenInput.name = 'remove_images[]';
                            hiddenInput.value = src;
                            document.getElementById('edit_deletedImagesContainer').appendChild(hiddenInput);
                            wrapper.remove();
                            if(currentGrid.children.length === 0) currentGrid.textContent = "All old photos removed.";
                        }
                    };
                    wrapper.appendChild(imgTag);
                    wrapper.appendChild(delBtn);
                    currentGrid.appendChild(wrapper);
                });
            } else { currentGrid.textContent = "No photos attached."; }

            document.getElementById('editReturnModal').classList.remove('hidden');
        }

        async function submitEditReturn() {
            const form = document.getElementById('editReturnForm');
            if(!form.checkValidity()) { form.reportValidity(); return; }
            
            const btn = document.querySelector('#editReturnModal button.bg-gray-800');
            const orgHtml = btn.innerHTML;
            btn.innerHTML = '<span class="animate-spin material-icons text-sm">autorenew</span> Saving...';
            btn.disabled = true;

            try {
                const res = await fetch('', { method: 'POST', body: new FormData(form) }).then(r => r.json());
                if (res.success) { 
                    showFlash(res.message); 
                    closeModal('editReturnModal'); 
                    // Refresh history modal content
                    const saleId = document.getElementById('view-hist-sale-id').textContent;
                    openViewReturnsModal(parseInt(saleId, 10));
                } else { showFlash(res.message, 'error'); }
            } catch(e) { showFlash('System error.', 'error'); }
            
            btn.innerHTML = orgHtml;
            btn.disabled = false;
        }

        document.getElementById('editReturnForm').addEventListener('submit', function(e) {
            e.preventDefault();
            submitEditReturn();
        });

        // --- PROCESS RETURN MODAL LOGIC ---
        // --- PROCESS RETURN MODAL LOGIC ---
        function closeReturnModal() { 
            closeModal('returnModal');
            // Cleanup upload states so they don't leak to next session
            for(let key in uploadState) { if(key.startsWith('row_')) delete uploadState[key]; }
        }

        async function openReturnModal(saleId) {
            document.getElementById('modal-sale-id').textContent = String(saleId).padStart(5, '0');
            document.getElementById('form-sale-id').value = saleId;
            document.getElementById('returnForm').reset();
            document.getElementById('returnItemsContainer').innerHTML = '<div class="text-center py-8 text-gray-400 dark:text-slate-500"><span class="animate-spin material-icons">autorenew</span> Loading items...</div>';
            document.getElementById('returnModal').classList.remove('hidden');

            // Clean up state
            for(let key in uploadState) { if(key.startsWith('row_')) delete uploadState[key]; }
            rowCounter = 0;

            try {
                const formData = new FormData(); formData.append('action', 'get_sale_items'); formData.append('sale_id', saleId);
                const res = await fetch('', { method: 'POST', body: formData }).then(r => r.json());
                
                if(res.success && res.items.length > 0) {
                    availableReturnItems = res.items;
                    document.getElementById('returnItemsContainer').innerHTML = '';
                    addReturnRow(); 
                } else {
                    document.getElementById('returnItemsContainer').innerHTML = '<div class="text-center text-red-500 dark:text-red-400 py-8 bg-red-50 dark:bg-red-900/20 rounded-xl border border-red-100 dark:border-red-800/30 font-bold"><span class="material-icons text-4xl mb-2">check_circle</span><br>All items from this order have already been returned.</div>';
                    document.querySelector('#returnModal button[onclick="addReturnRow()"]').style.display = 'none';
                    document.getElementById('submitReturnBtn').style.display = 'none';
                }
            } catch(e) { document.getElementById('returnItemsContainer').innerHTML = '<div class="text-center py-8 text-red-500 dark:text-red-400">Error loading data.</div>'; }
        }

        // --- LIVE COUNTER & DECIMAL ENFORCEMENT LOGIC ---
        
        const DECIMAL_UNITS = ['kg', 'g', 'liter', 'l', 'ml'];

        function isDecimalUnit(unit) {
            if(!unit) return false;
            return DECIMAL_UNITS.includes(unit.toLowerCase());
        }

        function blockDecimal(event, inputEle) {
            const row = inputEle.closest('.return-row');
            if(!row) return true;
            const select = row.querySelector('select[name*="[product_id]"]');
            if(!select || !select.value) return true;
            
            const option = select.options[select.selectedIndex];
            const unit = option.getAttribute('data-unit');
            
            if (!isDecimalUnit(unit)) {
                if (event.key === '.' || event.key === ',') {
                    event.preventDefault();
                    return false;
                }
            }
            return true;
        }

        function addReturnRow() {
            const container = document.getElementById('returnItemsContainer');
            const index = rowCounter++;
            const dtKey = 'row_' + index;
            uploadState[dtKey] = new DataTransfer(); 

            let optionsHtml = '<option value="">-- Choose Item --</option>';
            availableReturnItems.forEach(item => {
                optionsHtml += `<option value="${item.product_id}" data-max="${item.remaining_qty}" data-unit="${item.unit}">${item.name} (Max: ${item.remaining_qty} ${item.unit})</option>`;
            });

            const rowHtml = `
                <div class="return-row bg-white dark:bg-slate-800 p-5 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm relative group" id="row-${index}">
                    <button type="button" onclick="removeReturnRow(${index})" class="absolute -top-3 -right-3 bg-red-100 dark:bg-red-900 text-red-600 dark:text-red-400 hover:bg-red-600 dark:hover:bg-red-600 hover:text-white dark:hover:text-white rounded-full p-1.5 shadow-md transition opacity-0 group-hover:opacity-100">
                        <span class="material-icons text-[14px]">close</span>
                    </button>
                    
                    <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
                        <div class="lg:col-span-4">
                            <label class="block text-[10px] font-bold text-gray-400 dark:text-slate-500 uppercase mb-1">Item to Return</label>
                            <select name="items[${index}][product_id]" class="w-full p-2.5 bg-white dark:bg-slate-900 border border-gray-300 dark:border-slate-600 dark:text-white rounded-lg text-sm font-bold focus:border-[#1E3A1D] dark:focus:border-green-500 outline-none" required onchange="updateLiveCounters()">
                                ${optionsHtml}
                            </select>
                        </div>
                        <div class="lg:col-span-2">
                            <label class="block text-[10px] font-bold text-gray-400 dark:text-slate-500 uppercase mb-1 flex items-center justify-between">
                                Return Qty
                                <span id="live-qty-text-${index}" class="text-[10px] font-bold text-blue-600 dark:text-blue-400 ml-1"></span>
                            </label>
                            <input type="number" step="0.01" min="0.01" name="items[${index}][qty]" id="qty-${index}" required class="w-full p-2.5 bg-white dark:bg-slate-900 border border-gray-300 dark:border-slate-600 rounded-lg text-sm font-mono font-bold text-red-600 dark:text-red-400 focus:border-[#1E3A1D] dark:focus:border-green-500 outline-none" placeholder="0.00" onkeydown="return blockDecimal(event, this)" oninput="updateLiveCounters()">
                        </div>
                        <div class="lg:col-span-3">
                            <label class="block text-[10px] font-bold text-gray-400 dark:text-slate-500 uppercase mb-1 flex items-center gap-1">Item Condition <span class="material-icons text-[12px] text-gray-400 dark:text-slate-500" title="Good = Restock. Damaged Packaging = Held for Review. Spoiled = Write off.">help_outline</span></label>
                            <select name="items[${index}][condition]" class="w-full p-2.5 bg-white dark:bg-slate-900 border border-gray-300 dark:border-slate-600 rounded-lg text-sm focus:border-[#1E3A1D] dark:focus:border-green-500 outline-none font-bold text-gray-700 dark:text-slate-200">
                                <option value="Good">Good (Restock immediately)</option>
                                <option value="Damaged">Damaged Packaging (Hold item)</option>
                                <option value="Spoiled">Spoiled / Rotten (Waste item)</option>
                            </select>
                        </div>
                        <div class="lg:col-span-3">
                            <label class="block text-[10px] font-bold text-gray-400 dark:text-slate-500 uppercase mb-1 flex justify-between">Resolution</label>
                            <select name="items[${index}][action_req]" class="w-full p-2.5 border border-orange-200 dark:border-orange-800/50 rounded-lg text-sm focus:border-[#B33333] dark:focus:border-red-500 outline-none bg-orange-50 dark:bg-orange-900/20 text-orange-900 dark:text-orange-300 font-bold">
                                <option value="Replace">Replace (Auto-Queue Order)</option>
                                <option value="Refund">Refund / Credit</option>
                            </select>
                        </div>
                        <div class="lg:col-span-6">
                            <label class="block text-[10px] font-bold text-gray-400 dark:text-slate-500 uppercase mb-1">Reason</label>
                            <input type="text" name="items[${index}][reason]" placeholder="e.g. Broken seal" required class="w-full p-2.5 bg-white dark:bg-slate-900 border border-gray-300 dark:border-slate-600 dark:text-white rounded-lg text-sm focus:border-[#1E3A1D] dark:focus:border-green-500 outline-none">
                        </div>
                        <div class="lg:col-span-6">
                            <label class="block text-[10px] font-bold text-gray-400 dark:text-slate-500 uppercase mb-1">Photo Evidence (Album)</label>
                            <div class="flex items-center gap-3">
                                <label class="cursor-pointer">
                                    <div class="px-3 py-1.5 bg-gray-100 dark:bg-slate-700 border border-gray-300 dark:border-slate-600 rounded text-xs font-semibold text-gray-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-600 transition flex items-center gap-1">
                                        <span class="material-icons text-[14px]">add_a_photo</span> Photos
                                        <input type="file" name="item_photos_${index}[]" id="file-${index}" multiple accept="image/*" class="hidden" onchange="handleFileSelect(this, '${dtKey}', 'grid-${index}', 'text-${index}')">
                                    </div>
                                </label>
                                <span id="text-${index}" class="text-[10px] text-gray-400 dark:text-slate-500 italic">0 selected</span>
                            </div>
                            <div id="grid-${index}" class="flex flex-wrap gap-1.5 hidden p-1.5 mt-1.5 bg-gray-50 dark:bg-slate-900/50 rounded border border-gray-200 dark:border-slate-700"></div>
                        </div>
                    </div>
                </div>
            
            
            
            `;
            container.insertAdjacentHTML('beforeend', rowHtml);
            updateLiveCounters();
        }

        function removeReturnRow(index) {
            document.getElementById(`row-${index}`).remove();
            delete uploadState['row_' + index];
            updateLiveCounters();
        }

        function updateLiveCounters() {
            let productTotals = {};
            let productMaxes = {};
            let productUnits = {};

            const rows = document.querySelectorAll('.return-row');
            
            // 1. Calculate how much of each item has been typed into all rows combined
            rows.forEach(row => {
                const indexMatch = row.id.match(/\d+/);
                if(!indexMatch) return;
                const index = indexMatch[0];
                
                const select = row.querySelector(`select[name="items[${index}][product_id]"]`);
                const qtyInput = document.getElementById(`qty-${index}`);
                
                if (select && select.value) {
                    const pid = select.value;
                    const option = select.options[select.selectedIndex];
                    const max = parseFloat(option.getAttribute('data-max')) || 0;
                    const unit = option.getAttribute('data-unit') || '';
                    
                    productMaxes[pid] = max;
                    productUnits[pid] = unit;
                    
                    if (!isDecimalUnit(unit)) {
                        qtyInput.step = "1";
                        qtyInput.min = "1";
                        if(qtyInput.value.includes('.') || qtyInput.value.includes(',')) {
                            qtyInput.value = qtyInput.value.replace(/[\.,]/g, '');
                        }
                    } else {
                        qtyInput.step = "0.01";
                        qtyInput.min = "0.01";
                    }

                    let val = parseFloat(qtyInput.value) || 0;
                    if (!productTotals[pid]) productTotals[pid] = 0;
                    
                    // Round the addition to prevent 1.5 + 1.5 = 3.000000000004
                    productTotals[pid] = Math.round((productTotals[pid] + val) * 1000) / 1000;
                }
            });

            // 2. Loop back through and update the text limit warnings
            rows.forEach(row => {
                const indexMatch = row.id.match(/\d+/);
                if(!indexMatch) return;
                const index = indexMatch[0];
                
                const select = row.querySelector(`select[name="items[${index}][product_id]"]`);
                const qtyInput = document.getElementById(`qty-${index}`);
                const liveText = document.getElementById(`live-qty-text-${index}`);
                
                if (select && select.value) {
                    const pid = select.value;
                    const maxAllowed = productMaxes[pid];
                    const unit = productUnits[pid];
                    const isDec = isDecimalUnit(unit);
                    
                    const thisRowVal = parseFloat(qtyInput.value) || 0;
                    const totalEnteredEverywhere = productTotals[pid] || 0;
                    
                    const otherRowsUsed = Math.round((totalEnteredEverywhere - thisRowVal) * 1000) / 1000;
                    const remainingForThisRow = Math.round((maxAllowed - otherRowsUsed) * 1000) / 1000;
                    
                    const formattedRem = isDec ? remainingForThisRow.toFixed(2) : Math.floor(remainingForThisRow);
                    
                    if (thisRowVal <= remainingForThisRow + 0.001) {
                        liveText.textContent = `(Avail: ${formattedRem} ${unit})`;
                        liveText.className = "text-[10px] font-bold text-blue-600 ml-2";
                        qtyInput.classList.remove('border-red-500', 'bg-red-50', 'text-red-700');
                        qtyInput.setCustomValidity(""); // Clear the block
                    } else {
                        liveText.textContent = `(Over limit!)`;
                        liveText.className = "text-[10px] font-bold text-red-600 animate-pulse ml-2";
                        qtyInput.classList.add('border-red-500', 'bg-red-50', 'text-red-700');
                        qtyInput.setCustomValidity("Exceeds available quantity."); // Force form to stop
                    }
                    
                    // Remove native 'max' so the browser stops secretly fighting our math
                    qtyInput.removeAttribute('max');
                    
                } else {
                    liveText.textContent = '';
                    qtyInput.removeAttribute('max');
                    qtyInput.setCustomValidity("");
                }
            });
        }

        async function submitReturnForm() {
            const form = document.getElementById('returnForm');
            if(!form.checkValidity()) { form.reportValidity(); return; }
            
            const rows = document.querySelectorAll('.return-row');
            if(rows.length === 0) { showFlash('Please add at least one item.', 'error'); return; }

            // Verify if ANY inputs are over limit before submitting
            let overLimit = false;
            document.querySelectorAll('input[name*="[qty]"]').forEach(input => {
                if(input.classList.contains('border-red-500')) overLimit = true;
            });
            if (overLimit) {
                showFlash("You cannot return more items than what was delivered. Please fix highlighted fields.", "error");
                return;
            }

            const btn = document.getElementById('submitReturnBtn');
            const orgText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="animate-spin material-icons text-sm">autorenew</span> Processing...';
            
            const formData = new FormData(form);
            rows.forEach((row) => {
                const indexMatch = row.id.match(/\d+/);
                if(indexMatch) {
                    const idx = indexMatch[0];
                    const dtKey = 'row_' + idx;
                    if(uploadState[dtKey]) {
                        formData.delete(`item_photos_${idx}[]`);
                        const files = uploadState[dtKey].files;
                        for(let i=0; i<files.length; i++) {
                            formData.append(`item_photos_${idx}[]`, files[i]);
                        }
                    }
                }
            });

            const itemsData = [];
            rows.forEach((row) => {
                const indexMatch = row.id.match(/\d+/);
                if(indexMatch) {
                    const idx = indexMatch[0];
                    itemsData.push({
                        product_id: document.querySelector(`[name="items[${idx}][product_id]"]`).value,
                        qty: document.querySelector(`[name="items[${idx}][qty]"]`).value,
                        condition: document.querySelector(`[name="items[${idx}][condition]"]`).value,
                        reason: document.querySelector(`[name="items[${idx}][reason]"]`).value,
                        action_req: document.querySelector(`[name="items[${idx}][action_req]"]`).value
                    });
                }
            });
            
            formData.set('items', JSON.stringify(itemsData));

            try {
                const res = await fetch('', { method: 'POST', body: formData }).then(r => r.json());
                if(res.success) { showFlash(res.message); setTimeout(() => location.reload(), 1500); } 
                else { showFlash(res.message, 'error'); btn.disabled = false; btn.innerHTML = orgText; }
            } catch(e) { showFlash("System error processing return.", "error"); btn.disabled = false; btn.innerHTML = orgText; }
        }

        // --- FILTER LOGIC ---
        function applyFilters() {
            const client = document.getElementById('clientFilter').value.toLowerCase();
            const status = document.getElementById('statusFilter').value;
            const search = document.getElementById('orderSearch').value.toLowerCase();
            
            document.querySelectorAll('.order-row').forEach(row => {
                const rClient = row.getAttribute('data-client').toLowerCase();
                const rStatus = row.getAttribute('data-returns');
                const rId = row.getAttribute('data-id');
                
                let match = true;
                if(client && rClient !== client) match = false;
                if(status && rStatus !== status) match = false;
                if(search && !rId.includes(search)) match = false;
                
                row.style.display = match ? '' : 'none';
            });
        }
    </script>
</body>
</html>