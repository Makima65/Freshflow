<?php
// FILE: admin_components/pages/purchase_orders.php

header("Cache-Control: no-cache, no-store, must-revalidate"); 
header("Pragma: no-cache"); 
header("Expires: 0"); 

if (session_status() == PHP_SESSION_NONE) { session_start(); }
ob_start();
ini_set('display_errors', 0);
ini_set('log_errors', 1);

include_once '../includes/db_connection.php';

// --- SAFE DATABASE AUTO-PATCHER ---
try {
    $chk = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'expected_date'");
    if ($chk && $chk->num_rows == 0) {
        @$conn->query("ALTER TABLE purchase_orders ADD COLUMN expected_date DATE NULL AFTER status");
    }
    // Added total_amount to support Expense Tracking
    $chk_amt = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'total_amount'");
    if ($chk_amt && $chk_amt->num_rows == 0) {
        @$conn->query("ALTER TABLE purchase_orders ADD COLUMN total_amount DECIMAL(10,2) DEFAULT 0 AFTER expected_date");
    }
} catch (Throwable $e) { }

// --- AUDIT LOGGING ---
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

// --- SMART RESTOCK FUNCTION ---
function smart_restock($conn, $pid, $qty) {
    // FIXED: Removed the accidental 'clone $conn' typo that caused the crash!
    $has_expiry_col = false;
    $chk = $conn->query("SHOW COLUMNS FROM product_inventory LIKE 'expiration_date'");
    if ($chk && $chk->num_rows > 0) {
        $has_expiry_col = true;
    }
    
    $conn->query("UPDATE product_inventory SET quantity = quantity + $qty WHERE product_id = $pid ORDER BY quantity DESC LIMIT 1");
    if ($conn->affected_rows === 0) {
        if ($has_expiry_col) {
            $exp_date = date('Y-m-d', strtotime('+14 days')); 
            $conn->query("INSERT INTO product_inventory (product_id, quantity, expiration_date) VALUES ($pid, $qty, '$exp_date')");
        } else {
            $conn->query("INSERT INTO product_inventory (product_id, quantity) VALUES ($pid, $qty)");
        }
    }
    $conn->query("UPDATE products SET status = 'Active' WHERE product_id = $pid AND status = 'Out of Stock'");
}

// --- BACKEND HANDLERS (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean(); header('Content-Type: application/json');
    $action = $_POST['action_type'] ?? '';

    // 1. CREATE NEW PO
    if ($action === 'create_po') {
        $supplier = trim($_POST['supplier_name']);
        $expected_date = trim($_POST['expected_date']);
        $total_amount = floatval($_POST['total_amount'] ?? 0);
        $ref_no = "PO-" . date("Ymd") . "-" . rand(100, 999);
        $items_json = json_decode($_POST['items'], true);

        if (empty($supplier) || empty($items_json)) {
            echo json_encode(['success' => false, 'message' => 'Supplier and at least one item are required.']); exit;
        }

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO purchase_orders (reference_no, supplier_name, expected_date, total_amount, status, created_at) VALUES (?, ?, ?, ?, 'Pending', NOW())");
            $stmt->bind_param("sssd", $ref_no, $supplier, $expected_date, $total_amount);
            if (!$stmt->execute()) throw new Exception("Error creating PO.");
            $po_id = $conn->insert_id;

            $stmt_item = $conn->prepare("INSERT INTO purchase_order_items (po_id, product_id, quantity) VALUES (?, ?, ?)");
            foreach ($items_json as $item) {
                $pid = intval($item['product_id']);
                $qty = floatval($item['qty']);
                $stmt_item->bind_param("iid", $po_id, $pid, $qty);
                $stmt_item->execute();
            }

            if (function_exists('log_audit_action')) log_audit_action('Create PO', 'Inventory', "Created PO $ref_no for $supplier (Total: ₱".number_format($total_amount,2).")");

            $conn->commit();
            echo json_encode(['success' => true, 'message' => "Purchase Order $ref_no created successfully."]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // 2. RECEIVE PO (INVENTORY UPDATE + SMART EXPENSE LOGGING)
    if ($action === 'receive_po') {
        $po_id = intval($_POST['po_id']);

        $conn->begin_transaction();
        try {
            $po = $conn->query("SELECT * FROM purchase_orders WHERE id = $po_id")->fetch_assoc();
            if (!$po) throw new Exception("PO not found.");
            if ($po['status'] !== 'Pending') throw new Exception("Only pending POs can be received.");

            // A. Update PO Status
            $conn->query("UPDATE purchase_orders SET status = 'Completed', updated_at = NOW() WHERE id = $po_id");

            // B. Add items to Inventory AND generate summary for expenses
            $items_res = $conn->query("SELECT poi.product_id, poi.quantity, p.name, p.unit FROM purchase_order_items poi LEFT JOIN products p ON poi.product_id = p.product_id WHERE poi.po_id = $po_id");
            if(!$items_res) { 
                $items_res = $conn->query("SELECT poi.product_id, poi.quantity, p.name, p.unit FROM purchase_order_items poi LEFT JOIN products p ON poi.product_id = p.id WHERE poi.po_id = $po_id"); 
            }
            
            $items = $items_res->fetch_all(MYSQLI_ASSOC);
            
            $total_qty_ordered = 0;
            $item_summary_array = [];

            foreach ($items as $item) {
                smart_restock($conn, $item['product_id'], $item['quantity']);
                
                // Track total quantities and names
                $qty = floatval($item['quantity']);
                $total_qty_ordered += $qty;
                $unit = !empty($item['unit']) ? strtolower($item['unit']) : '';
                
                // Format decimal nicely (e.g. 1.5kg or 5pcs)
                $formatted_qty = fmod($qty, 1) == 0 ? number_format($qty, 0) : number_format($qty, 2);
                $item_summary_array[] = $formatted_qty . $unit . " " . $item['name'];
            }

            // C. AUTO-LOG TO EXPENSES (WITH TOTAL QTY & CLEAN DESCRIPTION)
            $exp_date = date('Y-m-d');
            $exp_cat = 'Restock';
            $po_ref = $po['reference_no'];
            $supplier = $po['supplier_name'];
            $exp_amount = isset($po['total_amount']) ? floatval($po['total_amount']) : 0;
            
            // Create a clean description
            $summary_string = implode(", ", $item_summary_array);
            $exp_desc = "Order $po_ref from $supplier. ($summary_string)";
            
            if ($exp_amount > 0) {
                $exp_sql = "INSERT INTO expenses (expense_date, category, amount, quantity, payment_method, description) VALUES (?, ?, ?, ?, 'Other', ?)";
                try {
                    if ($stmt_exp = $conn->prepare($exp_sql)) {
                        $stmt_exp->bind_param("ssdds", $exp_date, $exp_cat, $exp_amount, $total_qty_ordered, $exp_desc);
                        $stmt_exp->execute();
                        $stmt_exp->close();
                    }
                } catch(Throwable $e) { /* Failsafe */ }
            }

            if (function_exists('log_audit_action')) log_audit_action('Receive PO', 'Inventory', "Received PO {$po['reference_no']}. Stock & Expenses updated.");

            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'PO Received! Inventory & Expenses successfully updated.']);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // 3. DELETE PO
    if ($action === 'delete_po') {
        $po_id = intval($_POST['po_id']);
        
        $check = $conn->query("SELECT status FROM purchase_orders WHERE id = $po_id")->fetch_assoc();
        if ($check && $check['status'] === 'Pending') {
            $stmt = $conn->prepare("DELETE FROM purchase_orders WHERE id = ?");
            $stmt->bind_param("i", $po_id);
            if($stmt->execute()) {
                $conn->query("DELETE FROM purchase_order_items WHERE po_id = $po_id");
                if (function_exists('log_audit_action')) log_audit_action('Delete PO', 'Inventory', "Deleted Pending PO ID: $po_id");
                echo json_encode(['success' => true, 'message' => 'Purchase Order deleted.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error.']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Cannot delete completed orders.']);
        }
        exit;
    }
}
ob_end_flush();

// --- FETCH DATA FOR UI ---
$products = [];
$prods_res = $conn->query("SELECT product_id, name, unit FROM products WHERE status != 'Inactive' ORDER BY name ASC");
if (!$prods_res) { 
    $prods_res = $conn->query("SELECT id as product_id, name, unit FROM products WHERE status != 'Inactive' ORDER BY name ASC");
}
if ($prods_res) {
    while($p = $prods_res->fetch_assoc()) { $products[] = $p; }
}

// --- FETCH ACTIVE SUPPLIERS ---
$active_suppliers = [];
try {
    $sup_res = $conn->query("SELECT supplier_name FROM suppliers WHERE status = 'Active' ORDER BY supplier_name ASC");
    if ($sup_res) {
        $active_suppliers = $sup_res->fetch_all(MYSQLI_ASSOC);
    }
} catch (Throwable $e) {}

$stats = ['Pending' => 0, 'Completed' => 0];
$stat_sql = $conn->query("SELECT status, COUNT(*) as count FROM purchase_orders GROUP BY status"); 
if ($stat_sql) {
    while ($row = $stat_sql->fetch_assoc()) {
        if ($row['status'] == 'Pending') $stats['Pending'] = $row['count'];
        else $stats['Completed'] += $row['count'];
    }
}

$filter = $_GET['view'] ?? 'all';
$whereClause = "";
if ($filter == 'pending') $whereClause = "WHERE po.status = 'Pending'";
if ($filter == 'completed') $whereClause = "WHERE po.status = 'Completed'";

$sql = "SELECT po.id, po.reference_no, po.supplier_name, po.status, po.created_at, po.expected_date, po.total_amount, 
        GROUP_CONCAT(CONCAT(p.name, ' (', poi.quantity, ' ', IFNULL(p.unit, ''), ')') SEPARATOR ', ') as product_summary
        FROM purchase_orders po 
        LEFT JOIN purchase_order_items poi ON po.id = poi.po_id 
        LEFT JOIN products p ON poi.product_id = p.product_id 
        $whereClause
        GROUP BY po.id 
        ORDER BY FIELD(po.status, 'Pending', 'Completed'), po.created_at DESC"; 
$result = $conn->query($sql);

if (!$result) { 
    $sql = "SELECT po.id, po.reference_no, po.supplier_name, po.status, po.created_at, po.expected_date, po.total_amount,
            GROUP_CONCAT(CONCAT(p.name, ' (', poi.quantity, ' ', IFNULL(p.unit, ''), ')') SEPARATOR ', ') as product_summary
            FROM purchase_orders po 
            LEFT JOIN purchase_order_items poi ON po.id = poi.po_id 
            LEFT JOIN products p ON poi.product_id = p.id 
            $whereClause
            GROUP BY po.id 
            ORDER BY FIELD(po.status, 'Pending', 'Completed'), po.created_at DESC";
    $result = $conn->query($sql);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreshFlow - Purchase Orders</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
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

        .font-mono { font-family: 'Roboto Mono', monospace; }
        .custom-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .dark .custom-scroll::-webkit-scrollbar-thumb { background: #334155; }
        .modal-z { z-index: 50; }
        
        .form-input { background-color: #ffffff; border: 1px solid #d1d5db; color: #374151; border-radius: 0.5rem; transition: all 0.2s; padding: 0.5rem 0.75rem; outline: none; }
        .form-input:focus { border-color: #1E3A1D; box-shadow: 0 0 0 3px rgba(30, 58, 29, 0.1); }
        .dark .form-input { background-color: #1e293b; border-color: #334155; color: #f8fafc; }
        .dark .form-input:focus { border-color: #4ade80; box-shadow: 0 0 0 3px rgba(74, 222, 128, 0.1); }
        
        .status-Pending { background-color: #fef08a; color: #a16207; border: 1px solid #fde047; }
        .dark .status-Pending { background: rgba(161, 98, 7, 0.2); color: #fde047; border-color: rgba(253, 224, 71, 0.3); }
        
        .status-Completed { background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .dark .status-Completed { background: rgba(22, 101, 52, 0.2); color: #86efac; border-color: rgba(74, 222, 128, 0.3); }
        
        /* Hide number input arrows globally for cleaner UI */
        input[type=number]::-webkit-inner-spin-button, 
        input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
        input[type=number] { -moz-appearance: textfield; }
    </style>
    <script>
        window.onpageshow = function(event) { if (event.persisted) window.location.reload(); };
        window.onbeforeunload = function() { document.body.innerHTML = ""; document.body.style.backgroundColor = document.documentElement.classList.contains('dark') ? "#000" : "#F8F5EE"; };
    </script>
</head>

<body class="flex h-screen overflow-hidden">

    <?php include '../includes/sidebar.php'; ?>

    <main class="ml-20 flex-1 flex flex-col h-screen overflow-hidden relative transition-all duration-300 p-6 md:p-8">
        
        <header class="flex justify-between items-center mb-6 flex-shrink-0">
            <div>
                <h1 class="text-3xl font-bold text-[#1E3A1D] dark:text-white tracking-tight flex items-center gap-3">
                    <span class="material-icons text-3xl dark:text-green-400">shopping_cart</span> Purchase Orders
                </h1>
                <p class="text-sm text-gray-500 dark:text-slate-400 mt-1 ml-11">
                    Order stock from suppliers and automatically log expenses.
                </p>
            </div>
            <button onclick="openCreateModal()" class="bg-[#1E3A1D] dark:bg-green-600 text-white hover:bg-[#2a4e29] dark:hover:bg-green-500 px-5 py-2.5 rounded-lg text-sm font-bold shadow-lg transition transform active:scale-95 flex items-center gap-2">
                <span class="material-icons text-sm">add_shopping_cart</span> Draft New PO
            </button>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6 flex-shrink-0">
            <div class="bg-white dark:bg-slate-900/80 border border-orange-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-pointer group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(249,115,22,0.2)] dark:hover:shadow-[0_0_20px_rgba(249,115,22,0.3)] dark:hover:border-orange-400 transition-all duration-300 shadow-sm">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-orange-600 dark:group-hover:text-orange-400 transition-colors">Pending Arrivals</p>
                    <p class="text-3xl font-bold text-orange-600 dark:text-orange-400 mt-1 font-mono group-hover:scale-110 transition-transform origin-left"><?= number_format($stats['Pending']) ?> Orders</p>
                </div>
                <div class="p-3 bg-orange-100 dark:bg-orange-900/30 text-orange-600 dark:text-orange-400 rounded-lg group-hover:bg-orange-200 dark:group-hover:bg-orange-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">pending_actions</span>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900/80 border border-green-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-pointer group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(34,197,94,0.2)] dark:hover:shadow-[0_0_20px_rgba(34,197,94,0.3)] dark:hover:border-green-400 transition-all duration-300 shadow-sm">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-green-600 dark:group-hover:text-green-400 transition-colors">Completed Deliveries</p>
                    <p class="text-3xl font-bold text-green-700 dark:text-green-400 mt-1 font-mono group-hover:scale-110 transition-transform origin-left"><?= number_format($stats['Completed']) ?> Orders</p>
                </div>
                <div class="p-3 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 rounded-lg group-hover:bg-green-200 dark:group-hover:bg-green-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">inventory_2</span>
                </div>
            </div>
        </div>

        <div class="flex flex-col md:flex-row justify-between items-center gap-4 mb-4 flex-shrink-0">
            <div class="flex bg-gray-50 dark:bg-slate-800 p-1 rounded-lg border border-gray-200 dark:border-slate-700 shadow-sm overflow-x-auto w-full md:w-auto">
                <a href="?view=all" class="px-4 py-1.5 rounded-md text-sm font-medium transition whitespace-nowrap <?= $filter == 'all' ? 'bg-[#1E3A1D] dark:bg-slate-600 text-white shadow-md' : 'text-gray-500 dark:text-slate-400 hover:bg-white dark:hover:bg-slate-700 hover:text-gray-800 dark:hover:text-white' ?>">All</a>
                <a href="?view=pending" class="px-4 py-1.5 rounded-md text-sm font-medium transition whitespace-nowrap <?= $filter == 'pending' ? 'bg-[#1E3A1D] dark:bg-slate-600 text-white shadow-md' : 'text-gray-500 dark:text-slate-400 hover:bg-white dark:hover:bg-slate-700 hover:text-gray-800 dark:hover:text-white' ?>">Pending</a>
                <a href="?view=completed" class="px-4 py-1.5 rounded-md text-sm font-medium transition whitespace-nowrap <?= $filter == 'completed' ? 'bg-[#1E3A1D] dark:bg-slate-600 text-white shadow-md' : 'text-gray-500 dark:text-slate-400 hover:bg-white dark:hover:bg-slate-700 hover:text-gray-800 dark:hover:text-white' ?>">Completed</a>
            </div>
            
            <div class="relative w-full md:w-64">
                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400"><span class="material-icons text-sm">search</span></span>
                <input type="text" id="searchInput" onkeyup="searchTable()" placeholder="Search PO # or Supplier..." class="w-full pl-9 pr-3 py-2 text-sm border border-gray-200 dark:border-slate-700 rounded-lg focus:outline-none focus:border-[#1E3A1D] dark:focus:border-green-400 bg-white dark:bg-slate-800 text-gray-900 dark:text-white transition">
            </div>
        </div>

        <div class="bg-white dark:bg-slate-900/80 rounded-xl shadow border border-gray-200 dark:border-slate-800 flex-1 overflow-hidden flex flex-col mb-4">
            <div class="overflow-y-auto flex-1 custom-scroll pb-24">
                <table class="w-full text-left">
                    <thead class="bg-[#1E3A1D] dark:bg-slate-800 text-white text-xs uppercase font-bold sticky top-0 z-10 shadow-sm">
                        <tr>
                            <th class="p-4 pl-6">Order Ref / Supplier</th>
                            <th class="p-4">Requested Products</th>
                            <th class="p-4">Expected Date</th>
                            <th class="p-4">Status</th>
                            <th class="p-4 pr-6 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-800 text-sm text-gray-700 dark:text-gray-300" id="tableBody">
                        <?php if(!$result || $result->num_rows == 0): ?>
                            <tr><td colspan="5" class="p-8 text-center text-gray-400 dark:text-slate-500 italic">No purchase orders found.</td></tr>
                        <?php else: ?>
                            <?php while($po = $result->fetch_assoc()): ?>
                            <tr class="order-row hover:bg-gray-50 dark:hover:bg-slate-800/50 transition group">
                                <td class="p-4 pl-6 align-middle">
                                    <div class="font-mono font-bold text-gray-900 dark:text-white text-sm flex items-center gap-2">
                                        <?= htmlspecialchars($po['reference_no']) ?>
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-slate-400 mt-0.5 flex items-center gap-1"><span class="material-icons text-[12px]">store</span> <?= htmlspecialchars($po['supplier_name']) ?></div>
                                    <?php if(isset($po['total_amount']) && floatval($po['total_amount']) > 0): ?>
                                        <div class="text-[11px] text-red-600 dark:text-red-400 font-bold font-mono mt-1">Cost: ₱<?= number_format($po['total_amount'], 2) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 align-middle">
                                    <div class="text-xs font-bold text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-slate-800 p-2 rounded border border-gray-100 dark:border-slate-700 max-w-sm line-clamp-2" title="<?= htmlspecialchars($po['product_summary']) ?>">
                                        <?= $po['product_summary'] ? htmlspecialchars($po['product_summary']) : '<span class="italic text-gray-400 dark:text-slate-500">No Items Logged</span>' ?>
                                    </div>
                                </td>
                                <td class="p-4 align-middle">
                                    <div class="text-gray-800 dark:text-white font-medium"><?= !empty($po['expected_date']) ? date('M d, Y', strtotime($po['expected_date'])) : '<i class="text-gray-400 dark:text-slate-500">Not Set</i>' ?></div>
                                    <div class="text-[10px] text-gray-400 dark:text-slate-500 mt-0.5">Created: <?= date('M d', strtotime($po['created_at'])) ?></div>
                                </td>
                                <td class="p-4 align-middle">
                                    <span class="inline-block px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider status-<?= $po['status'] ?>">
                                        <?= $po['status'] ?>
                                    </span>
                                </td>
                                <td class="p-4 pr-6 align-middle">
                                    <div class="flex justify-end items-center gap-2">
                                        <?php if($po['status'] === 'Pending'): ?>
                                            <button type="button" onclick="receivePO(<?= $po['id'] ?>, '<?= $po['reference_no'] ?>')" class="p-2 text-green-600 dark:text-green-400 hover:bg-green-50 dark:hover:bg-green-900/30 rounded-lg transition font-bold flex items-center gap-1 text-xs border border-green-200 dark:border-green-800/50 bg-white dark:bg-slate-800 shadow-sm" title="Mark items arrived & add to inventory">
                                                <span class="material-icons text-sm">inventory</span> Receive
                                            </button>
                                            
                                            <button type="button" onclick="deletePO(<?= $po['id'] ?>, '<?= $po['reference_no'] ?>')" class="p-1.5 text-gray-400 dark:text-slate-500 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 rounded-full transition focus:outline-none flex items-center justify-center" title="Delete PO">
                                                <span class="material-icons text-[18px]">delete</span>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-xs text-gray-400 dark:text-slate-500 italic flex items-center gap-1"><span class="material-icons text-[14px]">check_circle</span> Locked</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
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

    <div id="createPoModal" class="fixed inset-0 bg-[#1E3A1D] dark:bg-black bg-opacity-40 dark:bg-opacity-60 hidden flex items-center justify-center modal-z backdrop-blur-sm transition-opacity p-4">
        <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-3xl overflow-hidden max-h-[90vh] flex flex-col border border-gray-200 dark:border-slate-700">
            <div class="bg-[#1E3A1D] dark:bg-slate-800 p-5 text-white flex justify-between items-center flex-shrink-0 shadow-md z-10">
                <h2 class="text-lg font-bold flex items-center gap-2"><span class="material-icons">add_shopping_cart</span> Draft Purchase Order</h2>
                <button type="button" onclick="closeCreateModal()" class="text-gray-300 hover:text-white transition"><span class="material-icons">close</span></button>
            </div>
            
            <div class="p-6 overflow-y-auto custom-scroll flex-1 bg-gray-50 dark:bg-slate-900">
                <form id="createPoForm">
                    <input type="hidden" name="action_type" value="create_po">

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                        <div>
                            <div class="flex justify-between items-end mb-1">
                                <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase">Supplier <span class="text-red-500">*</span></label>
                            </div>
                            <select name="supplier_name" required class="form-input text-sm font-bold text-gray-800 dark:text-white w-full cursor-pointer">
                                <option value="">-- Select --</option>
                                <?php foreach($active_suppliers as $sup): ?>
                                    <option value="<?= htmlspecialchars($sup['supplier_name']) ?>"><?= htmlspecialchars($sup['supplier_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Expected Delivery</label>
                            <input type="date" name="expected_date" id="expected_date" class="form-input text-sm font-bold text-gray-800 dark:text-white w-full cursor-pointer">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Est. Total Cost (₱) <span class="text-red-500">*</span></label>
                            <input type="number" name="total_amount" step="0.01" min="0" required placeholder="0.00" class="form-input text-sm font-bold text-red-600 dark:text-red-400 font-mono w-full focus:ring-red-200 dark:focus:ring-red-900/30">
                        </div>
                    </div>

                    <div class="mb-2 flex justify-between items-end border-b border-gray-200 dark:border-slate-800 pb-2">
                        <h3 class="font-bold text-gray-700 dark:text-slate-300 text-sm">Products to Order</h3>
                        <button type="button" onclick="addPoRow()" class="text-xs font-bold text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-900/30 hover:bg-blue-100 dark:hover:bg-blue-800/50 px-3 py-1.5 rounded flex items-center gap-1 transition">
                            <span class="material-icons text-sm">add</span> Add Row
                        </button>
                    </div>

                    <div id="poItemsContainer" class="space-y-3 mb-2">
                    </div>
                </form>
            </div>
            
            <div class="p-5 border-t border-gray-200 dark:border-slate-800 bg-white dark:bg-slate-900 flex justify-end gap-3 flex-shrink-0 z-10">
                <button type="button" onclick="closeCreateModal()" class="px-5 py-2.5 text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 rounded-lg text-sm font-bold transition">Cancel</button>
                <button type="button" id="submitPoBtn" onclick="submitCreatePo()" class="bg-[#1E3A1D] dark:bg-green-600 text-white px-6 py-2.5 rounded-lg text-sm font-bold hover:bg-[#2a4e29] dark:hover:bg-green-500 shadow-md transition transform hover:-translate-y-0.5 flex items-center gap-2">
                    <span class="material-icons text-sm">send</span> Send PO to Supplier
                </button>
            </div>
        </div>
    </div>

    <script>
        const products = <?= json_encode($products) ?>;
        let rowCounter = 0;
        let flashTimeout;

        const showFlash = (msg, type = 'success') => {
            if(flashTimeout) clearTimeout(flashTimeout);
            const fm = document.getElementById('flashMessage');
            const fi = document.getElementById('flashIcon');
            const ft = document.getElementById('flashText');
            ft.textContent = msg;
            fm.className = `fixed bottom-6 right-6 z-[100] ${type === 'error' ? 'bg-red-700' : 'bg-[#1E3A1D] dark:bg-green-700'} text-white px-6 py-4 rounded-lg shadow-2xl flex items-center gap-3 transform transition-all duration-300`;
            fi.textContent = type === 'error' ? 'error' : 'check_circle';
            fm.classList.remove('translate-y-20', 'opacity-0');
            flashTimeout = setTimeout(() => { fm.classList.add('translate-y-20', 'opacity-0'); }, 3000);
        };

        function searchTable() {
            const input = document.getElementById('searchInput').value.toLowerCase();
            document.querySelectorAll('.order-row').forEach(row => {
                row.style.display = row.innerText.toLowerCase().includes(input) ? '' : 'none';
            });
        }

        function openCreateModal() {
            document.getElementById('createPoForm').reset();
            document.getElementById('expected_date').valueAsDate = new Date(new Date().setDate(new Date().getDate() + 1)); 
            document.getElementById('poItemsContainer').innerHTML = '';
            addPoRow();
            document.getElementById('createPoModal').classList.remove('hidden');
        }

        function closeCreateModal() { document.getElementById('createPoModal').classList.add('hidden'); }

        function addPoRow() {
            const container = document.getElementById('poItemsContainer');
            
            let options = '<option value="">-- Select Product --</option>';
            products.forEach(p => {
                options += `<option value="${p.product_id}" data-unit="${p.unit}">${p.name}</option>`;
            });

            const row = document.createElement('div');
            row.className = 'po-row bg-white dark:bg-slate-800 p-4 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm flex gap-3 items-end relative pr-10';
            
            row.innerHTML = `
                <div class="flex-1">
                    <label class="block text-[10px] font-bold text-gray-400 dark:text-slate-500 uppercase mb-1">Product</label>
                    <select required class="row-product w-full p-2.5 border border-gray-300 dark:border-slate-600 rounded-lg text-sm bg-white dark:bg-slate-900 focus:border-[#1E3A1D] dark:focus:border-green-400 outline-none font-bold text-gray-800 dark:text-white" onchange="updateRowUnit(this)">
                        ${options}
                    </select>
                </div>
                <div class="w-32">
                    <label class="block text-[10px] font-bold text-gray-400 dark:text-slate-500 uppercase mb-1">Order Qty</label>
                    <div class="relative">
                        <input type="number" min="0.01" step="0.01" required 
                            class="row-qty w-full p-2.5 pr-12 border border-gray-300 dark:border-slate-600 rounded-lg text-sm bg-white dark:bg-slate-900 focus:border-[#1E3A1D] dark:focus:border-green-400 outline-none font-mono font-bold text-blue-700 dark:text-blue-400"
                            onkeydown="return enforceQuantityRules(event, this)" 
                            oninput="sanitizeQuantity(this)">
                        <span class="row-unit absolute right-3 top-2.5 text-xs text-gray-400 dark:text-slate-500 font-bold uppercase"></span>
                    </div>
                </div>
                <button type="button" onclick="this.closest('.po-row').remove();" class="absolute top-1/2 -translate-y-1/2 right-2 text-gray-400 dark:text-slate-500 hover:text-red-500 dark:hover:text-red-400 bg-gray-50 dark:bg-slate-700 hover:bg-red-50 dark:hover:bg-red-900/30 p-1.5 rounded-lg transition">
                    <span class="material-icons text-sm block">close</span>
                </button>
            `;
            container.appendChild(row);
        }

        function updateRowUnit(select) {
            const row = select.closest('.po-row');
            const unitSpan = row.querySelector('.row-unit');
            const qtyInput = row.querySelector('.row-qty');
            const opt = select.options[select.selectedIndex];
            const unit = opt.value ? opt.getAttribute('data-unit').toLowerCase() : '';
            
            unitSpan.textContent = unit;
            sanitizeQuantity(qtyInput);
        }

        function enforceQuantityRules(event, input) {
            const row = input.closest('.po-row');
            const unit = row.querySelector('.row-unit').textContent.toLowerCase();
            const allowedDecimals = ['kg', 'g', 'liter', 'l'];
            
            if (['e', 'E', '+', '-'].includes(event.key)) return false;

            if (!allowedDecimals.includes(unit) && ['.', ','].includes(event.key)) {
                event.preventDefault(); 
                return false;
            }
            return true;
        }

        function sanitizeQuantity(input) {
            const row = input.closest('.po-row');
            const unit = row.querySelector('.row-unit').textContent.toLowerCase();
            const allowedDecimals = ['kg', 'g', 'liter', 'l'];

            if (input.value === '') return;

            if (!allowedDecimals.includes(unit)) {
                if (input.value.includes('.')) {
                    input.value = Math.floor(input.value);
                }
            }
        }

        async function submitCreatePo() {
            const form = document.getElementById('createPoForm');
            if(!form.checkValidity()) { form.reportValidity(); return; }

            const items = [];
            let isValid = true;
            document.querySelectorAll('.po-row').forEach(row => {
                const pid = row.querySelector('.row-product').value;
                const qtyInput = row.querySelector('.row-qty');
                let qty = parseFloat(qtyInput.value);

                if(!pid || qty <= 0) isValid = false;
                else items.push({ product_id: pid, qty: qty });
            });

            if(!isValid || items.length === 0) { showFlash("Please ensure all items have valid products and quantities.", "error"); return; }

            const fd = new FormData(form);
            fd.set('items', JSON.stringify(items));

            const btn = document.getElementById('submitPoBtn');
            const orgText = btn.innerHTML;
            btn.disabled = true; btn.innerHTML = '<span class="animate-spin material-icons text-sm">autorenew</span> Sending...';
            
            try {
                const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
                if(res.success) { showFlash(res.message); setTimeout(() => location.reload(), 1500); } 
                else { showFlash(res.message, 'error'); btn.disabled = false; btn.innerHTML = orgText; }
            } catch(e) { showFlash("System error.", "error"); btn.disabled = false; btn.innerHTML = orgText; }
        }

        async function receivePO(poId, refNo) {
            if(!confirm(`Are you sure you want to MARK AS RECEIVED for ${refNo}?\n\nThis will permanently lock the PO, add all items directly to your Inventory, AND automatically log the financial cost into Expenses.`)) return;
            
            const fd = new FormData(); fd.append('action_type', 'receive_po'); fd.append('po_id', poId);
            try {
                const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
                if(res.success) { showFlash(res.message); setTimeout(() => location.reload(), 1500); } 
                else { showFlash(res.message, 'error'); }
            } catch(e) { showFlash("System error.", "error"); }
        }

        async function deletePO(poId, refNo) {
            if(!confirm(`Are you sure you want to DELETE Pending Order ${refNo}?`)) return;
            const fd = new FormData(); fd.append('action_type', 'delete_po'); fd.append('po_id', poId);
            try {
                const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
                if(res.success) { showFlash(res.message); setTimeout(() => location.reload(), 1500); } 
                else { showFlash(res.message, 'error'); }
            } catch(e) { showFlash("System error.", "error"); }
        }
    </script>
</body>
</html>