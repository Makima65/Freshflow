<?php
// C:\xampp\htdocs\RalphPHP\admin_components\pages\expenses.php

ob_start();
if (session_status() == PHP_SESSION_NONE) { session_start(); }

// --- 1. SECURITY: PREVENT BACK-BUTTON CACHING AFTER LOGOUT ---
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// --- DATABASE CONNECTION & AUDIT HELPER ---
include_once '../includes/db_connection.php';
include_once '../includes/audit_helper.php';

// --- SECURITY CHECK ---
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: ../admin_login.php");
    exit;
}

// --- DETERMINE PRIMARY KEY TO PREVENT CRASHES ---
$pk_col = 'id';
try {
    $check_col = $conn->query("SHOW COLUMNS FROM expenses LIKE 'id'");
    if ($check_col && $check_col->num_rows == 0) {
        $pk_col = 'expense_id';
    }
} catch (Throwable $e) {}

/**
 * HELPER FUNCTION: Syncs inventory based on expense type
 */
function updateProductStock($conn, $product_id, $category, $quantity, $is_undo = false) {
    if (!$product_id || $quantity <= 0) return;

    $operator = '';
    if ($category === 'Restock') {
        $operator = $is_undo ? "-" : "+";
    } elseif ($category === 'Spoilage Loss') {
        $operator = $is_undo ? "+" : "-";
    }

    if ($operator !== '') {
        $sql = "UPDATE products SET stock_quantity = stock_quantity $operator ? WHERE product_id = ?";
        try {
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("di", $quantity, $product_id);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {}
    }
}

// --- GET FILTER PARAMETERS (ADDED SEARCH AND SORT) ---
$filter_start = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$filter_end = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$filter_category = isset($_GET['filter_category']) ? $_GET['filter_category'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort_filter = isset($_GET['sort']) ? $_GET['sort'] : 'default';

// Construct Dynamic WHERE clauses
$where_clauses = ["1=1"];
if (!empty($filter_start)) { $clean_start = $conn->real_escape_string($filter_start); $where_clauses[] = "e.expense_date >= '$clean_start'"; }
if (!empty($filter_end)) { $clean_end = $conn->real_escape_string($filter_end); $where_clauses[] = "e.expense_date <= '$clean_end'"; }
if (!empty($filter_category)) { $clean_cat = $conn->real_escape_string($filter_category); $where_clauses[] = "e.category = '$clean_cat'"; }
if (!empty($search)) { 
    $clean_search = $conn->real_escape_string($search); 
    $where_clauses[] = "(e.description LIKE '%$clean_search%' OR p.name LIKE '%$clean_search%' OR e.payment_method LIKE '%$clean_search%')"; 
}
$where_sql = "WHERE " . implode(" AND ", $where_clauses);

// Construct Dynamic ORDER BY clauses
$sort_sql = "ORDER BY e.expense_date DESC";
try {
    $chk_created = $conn->query("SHOW COLUMNS FROM expenses LIKE 'created_at'");
    if ($chk_created && $chk_created->num_rows > 0) {
        $sort_sql = "ORDER BY e.expense_date DESC, e.created_at DESC";
    } else {
        $sort_sql = "ORDER BY e.expense_date DESC, e.$pk_col DESC";
    }
} catch (Throwable $e) {}

if ($sort_filter === 'date_asc') {
    $sort_sql = str_replace("DESC", "ASC", $sort_sql);
} elseif ($sort_filter === 'amount_desc') {
    $sort_sql = "ORDER BY e.amount DESC, e.expense_date DESC";
} elseif ($sort_filter === 'amount_asc') {
    $sort_sql = "ORDER BY e.amount ASC, e.expense_date DESC";
}

// --- EXPORT TO CSV ---
if (isset($_GET['export_csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Expense_Report_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, array('Date', 'Category', 'Product Reference', 'Qty', 'Description', 'Payment Method', 'Amount (PHP)'));
    
    // Uses the same dynamic where and sort filters as the table
    $export_sql = "SELECT e.*, p.name as product_name FROM expenses e LEFT JOIN products p ON e.product_id = p.product_id $where_sql $sort_sql";
    $exp_res = $conn->query($export_sql);
    if(!$exp_res) { 
        $export_sql = "SELECT e.*, p.name as product_name FROM expenses e LEFT JOIN products p ON e.product_id = p.id $where_sql $sort_sql"; 
        $exp_res = $conn->query($export_sql); 
    }
    
    if ($exp_res) {
        while ($row = $exp_res->fetch_assoc()) {
            fputcsv($output, array($row['expense_date'], $row['category'], $row['product_name'] ? $row['product_name'] : 'N/A', isset($row['quantity']) ? $row['quantity'] : 0, $row['description'], $row['payment_method'], $row['amount']));
        }
    }
    if (function_exists('log_audit_action')) { log_audit_action('Export Data', 'Expenses', "Exported expenses to CSV."); }
    fclose($output); exit;
}

// --- HANDLE FLASH MESSAGES ---
$message = ''; $msg_type = '';
if (isset($_SESSION['flash_msg'])) {
    $message = $_SESSION['flash_msg']; $msg_type = $_SESSION['flash_type'];
    unset($_SESSION['flash_msg']); unset($_SESSION['flash_type']);
}

// --- SAFE REDIRECT HELPER ---
function getSafeRedirectUrl() {
    $qs = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
    if (!empty($qs)) {
        $qs = preg_replace('/&?export_csv=1/', '', $qs);
        return "expenses.php?" . $qs;
    }
    return "expenses.php";
}

// --- ACTION: DELETE EXPENSE ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_expense'])) {
    $expense_id = intval($_POST['expense_id']);
    
    try {
        $stmt = $conn->prepare("SELECT product_id, category, quantity FROM expenses WHERE $pk_col = ?");
        if ($stmt) {
            $stmt->bind_param("i", $expense_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if($row = $res->fetch_assoc()) {
                if ($row['category'] === 'Spoilage Loss') {
                    $_SESSION['flash_msg'] = "Cannot delete auto-generated Spoilage records from here. Please go to the Spoilage module.";
                    $_SESSION['flash_type'] = "error";
                    $stmt->close();
                    header("Location: " . getSafeRedirectUrl()); exit;
                }
                updateProductStock($conn, $row['product_id'], $row['category'], floatval($row['quantity']), true);
            }
            $stmt->close();
        }

        $del_sql = "DELETE FROM expenses WHERE $pk_col = ?"; 
        if ($stmt = $conn->prepare($del_sql)) {
            $stmt->bind_param("i", $expense_id);
            if ($stmt->execute()) {
                $_SESSION['flash_msg'] = "Expense deleted and Inventory reversed!"; $_SESSION['flash_type'] = "success";
                if (function_exists('log_audit_action')) { log_audit_action('Delete Expense', 'Expenses', "Deleted expense ID: " . $expense_id . " (Stock Reverted)"); }
            } else {
                $_SESSION['flash_msg'] = "Error deleting expense."; $_SESSION['flash_type'] = "error";
            }
            $stmt->close();
        }
    } catch (Throwable $e) {
        $_SESSION['flash_msg'] = "System Error: " . $e->getMessage();
        $_SESSION['flash_type'] = "error";
    }
    header("Location: " . getSafeRedirectUrl()); exit;
}

// --- ACTION: ADD OR EDIT EXPENSE ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $date = $_POST['expense_date'];
    $category = $_POST['category'];
    $amount = floatval($_POST['amount']);
    $payment_method = $_POST['payment_method'];
    $description = trim($_POST['description']);
    $product_id = !empty($_POST['product_id']) ? intval($_POST['product_id']) : NULL;
    $qty = $product_id ? floatval($_POST['quantity'] ?? 0) : 0;
    
    try {
        if ($_POST['action'] == 'add') {
            $sql = "INSERT INTO expenses (expense_date, category, amount, quantity, payment_method, description, product_id) VALUES (?, ?, ?, ?, ?, ?, ?)";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("ssddssi", $date, $category, $amount, $qty, $payment_method, $description, $product_id);
                if ($stmt->execute()) {
                    updateProductStock($conn, $product_id, $category, $qty); 
                    $_SESSION['flash_msg'] = "Expense logged successfully!"; $_SESSION['flash_type'] = "success";
                    if (function_exists('log_audit_action')) { log_audit_action('Add Expense', 'Expenses', "Logged new " . $category . " " . ($product_id ? "(Qty: $qty)" : "") . ": ₱" . number_format($amount, 2)); }
                }
                $stmt->close();
            }
        } elseif ($_POST['action'] == 'edit' && isset($_POST['edit_id'])) {
            $edit_id = intval($_POST['edit_id']);
            
            $old_stmt = $conn->prepare("SELECT product_id, category, quantity FROM expenses WHERE $pk_col = ?");
            if ($old_stmt) {
                $old_stmt->bind_param("i", $edit_id);
                $old_stmt->execute();
                $old_res = $old_stmt->get_result();
                if($old = $old_res->fetch_assoc()) {
                    if ($old['category'] === 'Spoilage Loss') {
                        $_SESSION['flash_msg'] = "Cannot edit auto-generated Spoilage records from here. Please go to the Spoilage module.";
                        $_SESSION['flash_type'] = "error";
                        $old_stmt->close();
                        header("Location: " . getSafeRedirectUrl()); exit;
                    }
                    updateProductStock($conn, $old['product_id'], $old['category'], floatval($old['quantity']), true);
                }
                $old_stmt->close();
            }

            $upd_sql = "UPDATE expenses SET expense_date=?, category=?, amount=?, quantity=?, payment_method=?, description=?, product_id=? WHERE $pk_col=?";
            if ($stmt = $conn->prepare($upd_sql)) {
                $stmt->bind_param("ssddssii", $date, $category, $amount, $qty, $payment_method, $description, $product_id, $edit_id);
                if ($stmt->execute()) {
                    updateProductStock($conn, $product_id, $category, $qty); 
                    $_SESSION['flash_msg'] = "Changes saved and Inventory synced!"; $_SESSION['flash_type'] = "success";
                    if (function_exists('log_audit_action')) { log_audit_action('Edit Expense', 'Expenses', "Updated expense ID " . $edit_id . " " . ($product_id ? "(Qty: $qty)" : "") . " to ₱" . number_format($amount, 2)); }
                }
                $stmt->close();
            }
        }
    } catch (Throwable $e) {
        $_SESSION['flash_msg'] = "System Error: Missing column or invalid data. (" . $e->getMessage() . ")";
        $_SESSION['flash_type'] = "error";
    }
    
    header("Location: " . getSafeRedirectUrl()); exit;
}

// --- FETCH PRODUCTS FOR DROPDOWN ---
$products = [];
try {
    $has_unit = false;
    $col_check = $conn->query("SHOW COLUMNS FROM products LIKE 'unit'");
    if ($col_check && $col_check->num_rows > 0) { $has_unit = true; }
    
    $unit_sql = $has_unit ? ", unit" : "";
    $sql_prod = "SELECT product_id, name $unit_sql FROM products WHERE status != 'Inactive'";
    $res = $conn->query($sql_prod);
    
    if (!$res) { // Fallback
        $sql_prod = "SELECT id as product_id, name $unit_sql FROM products WHERE status != 'Inactive'";
        $res = $conn->query($sql_prod);
    }
    if ($res) { 
        while ($row = $res->fetch_assoc()) { 
            if (!isset($row['unit'])) $row['unit'] = '';
            $products[] = $row; 
        } 
    }
} catch(Throwable $e) {}

// --- FETCH QUICK STATS ---
$current_month_total = 0; $total_restock = 0; $total_spoilage = 0;
try {
    $stats_sql = "SELECT SUM(amount) as total_month, SUM(CASE WHEN category = 'Restock' THEN amount ELSE 0 END) as total_restock, SUM(CASE WHEN category = 'Spoilage Loss' THEN amount ELSE 0 END) as total_spoilage FROM expenses WHERE MONTH(expense_date) = MONTH(CURRENT_DATE()) AND YEAR(expense_date) = YEAR(CURRENT_DATE())";
    $res_total = $conn->query($stats_sql);
    if ($res_total && $row = $res_total->fetch_assoc()) {
        $current_month_total = floatval($row['total_month']); $total_restock = floatval($row['total_restock']); $total_spoilage = floatval($row['total_spoilage']);
    }
} catch(Throwable $e) {}

// --- PREPARE DATA FOR TABLE & CHART ---
// 1. Fetch entire chart distribution based on current filters (unpaginated)
$chartData = [];
try {
    $agg_sql = "SELECT e.category, SUM(e.amount) as cat_total FROM expenses e LEFT JOIN products p ON e.product_id = p.product_id $where_sql GROUP BY e.category";
    $agg_res = $conn->query($agg_sql);
    if (!$agg_res) {
        $agg_sql = "SELECT e.category, SUM(e.amount) as cat_total FROM expenses e LEFT JOIN products p ON e.product_id = p.id $where_sql GROUP BY e.category";
        $agg_res = $conn->query($agg_sql);
    }
    if ($agg_res) {
        while ($row = $agg_res->fetch_assoc()) {
            $chartData[$row['category']] = (float)$row['cat_total'];
        }
    }
} catch(Throwable $e) {}

// 2. Fetch Pagination Logic
$per_page = 15; 
$total_items = 0;
try {
    $count_query = "SELECT COUNT(*) as total FROM expenses e LEFT JOIN products p ON e.product_id = p.product_id $where_sql";
    $count_res = $conn->query($count_query);
    if(!$count_res) {
        $count_query = "SELECT COUNT(*) as total FROM expenses e LEFT JOIN products p ON e.product_id = p.id $where_sql";
        $count_res = $conn->query($count_query);
    }
    if ($count_res && $row = $count_res->fetch_assoc()) {
        $total_items = (int)$row['total'];
    }
} catch(Throwable $e) {}

$total_pages = ceil($total_items / $per_page);
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
if ($page > $total_pages && $total_pages > 0) $page = $total_pages;

$offset = ($page - 1) * $per_page;

// 3. Fetch specific chunk of data for the active page
$expensesList = [];
try {
    $sql_list = "SELECT e.*, p.name as product_name FROM expenses e LEFT JOIN products p ON e.product_id = p.product_id $where_sql $sort_sql LIMIT $per_page OFFSET $offset";
    $res_list = $conn->query($sql_list);
    if (!$res_list) { 
        $sql_list = "SELECT e.*, p.name as product_name FROM expenses e LEFT JOIN products p ON e.product_id = p.id $where_sql $sort_sql LIMIT $per_page OFFSET $offset";
        $res_list = $conn->query($sql_list);
    }
    if ($res_list) {
        while ($row = $res_list->fetch_assoc()) {
            $expensesList[] = $row;
        }
    }
} catch(Throwable $e) {}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreshFlow - Expense Tracker</title>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    
    <script>
        // DARK MODE INITIALIZATION
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class' };
    </script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/jpeg" href="/admin_components/assets/img/tabicon4.png">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        .font-mono { font-family: 'JetBrains Mono', monospace; }
        
        .custom-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .dark .custom-scroll::-webkit-scrollbar-thumb { background: #334155; }
        
        /* Updated Input Styling */
        .form-input, .filter-select { 
            background-color: #ffffff; 
            border: 1px solid #d1d5db; 
            color: #374151; 
            border-radius: 0.5rem; 
            transition: all 0.2s; 
        }
        .form-input { padding: 0.5rem 0.75rem; }
        .form-input:focus, .filter-select:focus { 
            outline: none; 
            border-color: #1E3A1D; 
            box-shadow: 0 0 0 3px rgba(30, 58, 29, 0.1); 
        }
        .form-input[readonly] { background-color: #f3f4f6; cursor: not-allowed; }
        
        /* Dark Mode Inputs */
        .dark .form-input, .dark .filter-select { background-color: rgba(30, 41, 59, 0.6); border-color: #334155; color: #f8fafc; }
        .dark .form-input:focus, .dark .filter-select:focus { border-color: #4ade80; box-shadow: 0 0 0 3px rgba(74, 222, 128, 0.15); }
        .dark .form-input[readonly] { background-color: #1e293b; }

        /* Collapsible Filter Animation */
        #filterOptions { transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.4s ease, padding 0.4s ease, margin 0.4s ease; border-color: 0.4s ease; max-height: 0; overflow: hidden; opacity: 0; padding-top: 0; margin-top: 0; border-top: 1px solid transparent; }
        #filterOptions.open { max-height: 500px; opacity: 1; padding-top: 1rem; margin-top: 1rem; border-top-color: #e5e7eb; }

        .modal-z { z-index: 50; }
        #qtyContainer { transition: opacity 0.3s ease, height 0.3s ease; }
    </style>
</head>
<body class="flex h-screen overflow-hidden">

    <?php include '../includes/sidebar.php'; ?>

    <main class="ml-20 flex-1 flex flex-col h-screen overflow-hidden relative transition-all duration-300 p-6 md:p-8">
        
        <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 flex-shrink-0 gap-6">
            <div>
                <h1 class="text-3xl font-bold text-[#1E3A1D] dark:text-white tracking-tight flex items-center gap-3">
                    <span class="material-icons text-3xl dark:text-green-400">receipt_long</span> Expense Tracker
                </h1>
                <p class="text-sm text-gray-500 dark:text-slate-400 mt-1 ml-11">Monitor operating costs and auto-sync physical stock levels.</p>
            </div>
            
            <div class="flex flex-wrap gap-4">
                
                <div class="bg-white dark:bg-slate-900/60 border-l-4 border-l-red-400 dark:border-l-red-500 border-t border-r border-b border-gray-200 dark:border-slate-800 p-4 rounded-xl shadow flex items-center justify-between min-w-[170px] cursor-pointer group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(239,68,68,0.2)] dark:hover:shadow-[0_0_20px_rgba(239,68,68,0.3)] transition-all duration-300">
                    <div>
                        <p class="text-[10px] font-bold text-gray-500 dark:text-slate-400 uppercase tracking-wider mb-0.5 group-hover:text-red-500 dark:group-hover:text-red-400 transition-colors">Spoilage (Month)</p>
                        <p class="text-xl font-bold text-gray-900 dark:text-white font-mono tracking-tight group-hover:scale-110 transition-transform origin-left">₱<?= number_format($total_spoilage, 2) ?></p>
                    </div>
                    <div class="bg-red-50 dark:bg-red-900/30 p-2.5 rounded-full text-red-600 dark:text-red-400 group-hover:bg-red-100 dark:group-hover:bg-red-800/50 transition-colors ml-4">
                        <span class="material-icons text-xl group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">trending_down</span>
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-900/60 border-l-4 border-l-blue-400 dark:border-l-blue-500 border-t border-r border-b border-gray-200 dark:border-slate-800 p-4 rounded-xl shadow flex items-center justify-between min-w-[170px] cursor-pointer group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(59,130,246,0.2)] dark:hover:shadow-[0_0_20px_rgba(59,130,246,0.3)] transition-all duration-300">
                    <div>
                        <p class="text-[10px] font-bold text-gray-500 dark:text-slate-400 uppercase tracking-wider mb-0.5 group-hover:text-blue-500 dark:group-hover:text-blue-400 transition-colors">Restock (Month)</p>
                        <p class="text-xl font-bold text-gray-900 dark:text-white font-mono tracking-tight group-hover:scale-110 transition-transform origin-left">₱<?= number_format($total_restock, 2) ?></p>
                    </div>
                    <div class="bg-blue-50 dark:bg-blue-900/30 p-2.5 rounded-full text-blue-600 dark:text-blue-400 group-hover:bg-blue-100 dark:group-hover:bg-blue-800/50 transition-colors ml-4">
                        <span class="material-icons text-xl group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">inventory_2</span>
                    </div>
                </div>

                <div class="bg-[#1E3A1D] dark:bg-slate-900/80 border-l-4 border-l-green-400 dark:border-l-green-500 border-t border-r border-b border-[#1E3A1D] dark:border-slate-800 p-4 rounded-xl shadow flex items-center justify-between min-w-[190px] cursor-pointer group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(34,197,94,0.2)] dark:hover:shadow-[0_0_20px_rgba(34,197,94,0.3)] transition-all duration-300">
                    <div>
                        <p class="text-[10px] font-bold text-green-100 dark:text-slate-400 uppercase tracking-wider mb-0.5 group-hover:text-white dark:group-hover:text-green-400 transition-colors">Total (Month)</p>
                        <p class="text-2xl font-bold text-white font-mono tracking-tight group-hover:scale-110 transition-transform origin-left">₱<?= number_format($current_month_total, 2) ?></p>
                    </div>
                    <div class="bg-white/10 dark:bg-green-900/30 p-2.5 rounded-full text-white dark:text-green-400 border border-white/20 dark:border-transparent group-hover:bg-white/20 dark:group-hover:bg-green-800/50 transition-colors ml-4">
                        <span class="material-icons text-xl group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">account_balance_wallet</span>
                    </div>
                </div>

            </div>
        </header>

        <?php if ($message): ?>
            <div id="flashMessage" class="fixed bottom-6 right-6 z-[100] <?= $msg_type == 'success' ? 'bg-[#1E3A1D] dark:bg-green-800' : 'bg-red-700 dark:bg-red-900' ?> text-white px-6 py-4 rounded-lg shadow-2xl flex items-center gap-3">
                <span class="material-icons <?= $msg_type == 'success' ? 'text-green-400' : 'text-white' ?>"><?= $msg_type == 'success' ? 'check_circle' : 'error' ?></span>
                <div>
                    <h4 class="font-bold text-sm">Notification</h4>
                    <p class="text-xs text-gray-300 dark:text-gray-200"><?= htmlspecialchars($message) ?></p>
                </div>
            </div>
            <script>setTimeout(() => { document.getElementById('flashMessage').style.display = 'none'; }, 6000);</script>
        <?php endif; ?>

        <div class="flex-1 flex flex-col lg:flex-row gap-6 overflow-hidden">
            
            <div class="w-full lg:w-[380px] bg-white dark:bg-slate-900/80 rounded-xl shadow border border-gray-200 dark:border-slate-800 flex flex-col flex-shrink-0 z-10" id="formPanel">
                <div class="bg-[#1E3A1D] dark:bg-green-900/50 p-5 text-white flex justify-between items-center rounded-t-xl dark:border-b dark:border-slate-700" id="formHeader">
                    <h2 class="text-lg font-bold flex items-center gap-2" id="formTitle">
                        <span class="material-icons text-sm dark:text-green-400" id="formIcon">add_circle</span> <span id="formText">Log Transaction</span>
                    </h2>
                    <button type="button" onclick="cancelEdit()" id="cancelEditBtn" class="hidden text-xs text-red-200 hover:text-white font-bold transition">Cancel Edit</button>
                </div>
                
                <form action="" method="POST" class="p-6 overflow-y-auto custom-scroll space-y-4">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="edit_id" id="edit_id" value="">

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Date <span class="text-red-500">*</span></label>
                            <input type="date" id="expDate" name="expense_date" required class="form-input text-sm w-full font-bold" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Category <span class="text-red-500">*</span></label>
                            <select id="expCategory" name="category" required class="form-input text-sm w-full cursor-pointer font-bold bg-gray-50 dark:bg-slate-800" onchange="toggleProductField()">
                                <option value="Operating">Operating</option>
                                <option value="Payroll">Payroll</option>
                                <option value="Marketing">Marketing</option>
                                <option value="Restock">Restock</option>
                                <option value="Logistics">Logistics</option>
                                <option value="Spoilage Loss">Spoilage Loss</option>
                                <option value="Miscellaneous">Miscellaneous</option>
                            </select>
                        </div>
                    </div>

                    <div id="productField" class="bg-blue-50 dark:bg-blue-900/20 p-3 rounded-lg border border-blue-100 dark:border-blue-900/50" style="display: none;">
                        <label class="block text-xs font-bold text-blue-700 dark:text-blue-400 uppercase mb-1">Link to Product</label>
                        <select id="expProduct" name="product_id" class="form-input text-sm w-full cursor-pointer mb-2 border-blue-200 dark:border-blue-800" onchange="updateQtyLabel()">
                            <option value="">-- Optional: Select Product --</option>
                            <?php foreach($products as $p): ?>
                                <option value="<?= $p['product_id'] ?>" data-unit="<?= htmlspecialchars($p['unit']) ?>"><?= htmlspecialchars($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-[10px] text-blue-500 dark:text-blue-400 italic flex items-center gap-1"><span class="material-icons text-[12px]">info</span> Quantity will automatically sync with inventory.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Total Cost (₱) <span class="text-red-500">*</span></label>
                            <input type="number" step="0.01" id="expAmount" name="amount" required placeholder="0.00" class="form-input text-sm w-full font-mono font-bold text-red-600 dark:text-red-400">
                        </div>
                        <div id="qtyContainer" style="display: none;">
                            <label id="qtyLabel" class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Quantity</label>
                            <input type="number" step="any" id="expQty" name="quantity" min="0" placeholder="0" class="form-input text-sm w-full font-mono font-bold text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-slate-800">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Payment Method <span class="text-red-500">*</span></label>
                        <select id="expMethod" name="payment_method" required class="form-input text-sm w-full cursor-pointer font-bold">
                            <option value="Cash">Cash</option>
                            <option value="Gcash">Gcash</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Description / Notes</label>
                        <textarea id="expDesc" name="description" rows="2" placeholder="Brief details about this expense..." class="form-input text-sm w-full custom-scroll"></textarea>
                    </div>

                    <button type="submit" id="submitBtn" class="w-full bg-[#1E3A1D] dark:bg-green-700 text-white px-6 py-3 rounded-lg text-sm font-bold hover:bg-[#2a4e29] dark:hover:bg-green-600 shadow-md transition transform hover:-translate-y-0.5 flex items-center justify-center gap-2 mt-2">
                        <span class="material-icons text-sm" id="submitIcon">save</span>
                        <span id="submitText">Save Transaction</span>
                    </button>
                </form>
            </div>
            
            <div class="flex-1 flex flex-col gap-4 overflow-hidden">
                
                <form method="GET" action="expenses.php" id="filterForm" class="bg-white dark:bg-slate-900/80 p-3 rounded-xl shadow-sm border border-gray-200 dark:border-slate-800 relative z-20 flex-shrink-0">
                    <div class="flex flex-col md:flex-row items-center gap-4">
                        <div class="relative w-full flex-grow">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400 dark:text-slate-500"><span class="material-icons">search</span></span>
                            <input type="text" name="search" id="searchInput" placeholder="Search description, product or reference..." value="<?= htmlspecialchars($search) ?>" class="w-full pl-10 p-2 rounded-lg form-input transition" autocomplete="off">
                        </div>
                        <button type="button" id="toggleFilterBtn" class="bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-700 hover:bg-gray-50 dark:hover:bg-slate-700 text-gray-700 dark:text-gray-300 font-bold py-2 px-4 rounded-lg flex items-center gap-2 transition shadow-sm">
                            <span class="material-icons">filter_list</span> Filters
                        </button>
                        <a href="expenses.php?export_csv=1&<?= http_build_query($_GET) ?>" class="bg-[#1E3A1D] dark:bg-green-700 hover:bg-[#162e15] dark:hover:bg-green-600 text-white px-4 py-2 rounded-lg text-sm font-bold shadow-md transition flex items-center justify-center gap-2 whitespace-nowrap">
                            <span class="material-icons text-[18px]">download</span> Export CSV
                        </a>
                    </div>
                    
                    <div id="filterOptions" class="<?= ($filter_category !== '' || $filter_start !== '' || $filter_end !== '' || $sort_filter !== 'default') ? 'open' : '' ?>">
                        <div class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end mt-4">
                            
                            <div>
                                <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 mb-1 uppercase">Category</label>
                                <select name="filter_category" id="categoryFilter" class="w-full p-2 filter-select">
                                    <option value="">All Categories</option>
                                    <option value="Operating" <?= $filter_category == 'Operating' ? 'selected' : '' ?>>Operating</option>
                                    <option value="Payroll" <?= $filter_category == 'Payroll' ? 'selected' : '' ?>>Payroll</option>
                                    <option value="Marketing" <?= $filter_category == 'Marketing' ? 'selected' : '' ?>>Marketing</option>
                                    <option value="Restock" <?= $filter_category == 'Restock' ? 'selected' : '' ?>>Restock</option>
                                    <option value="Logistics" <?= $filter_category == 'Logistics' ? 'selected' : '' ?>>Logistics</option>
                                    <option value="Spoilage Loss" <?= $filter_category == 'Spoilage Loss' ? 'selected' : '' ?>>Spoilage Loss</option>
                                    <option value="Miscellaneous" <?= $filter_category == 'Miscellaneous' ? 'selected' : '' ?>>Miscellaneous</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 mb-1 uppercase">From Date</label>
                                <input type="date" name="start_date" id="dateFromFilter" value="<?= htmlspecialchars($filter_start) ?>" class="w-full p-2 filter-select">
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 mb-1 uppercase">To Date</label>
                                <input type="date" name="end_date" id="dateToFilter" value="<?= htmlspecialchars($filter_end) ?>" class="w-full p-2 filter-select">
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 mb-1 uppercase">Sort By</label>
                                <select name="sort" id="sortFilter" class="w-full p-2 filter-select">
                                    <option value="default" <?= $sort_filter == 'default' ? 'selected' : '' ?>>Date (Newest First)</option>
                                    <option value="date_asc" <?= $sort_filter == 'date_asc' ? 'selected' : '' ?>>Date (Oldest First)</option>
                                    <option value="amount_desc" <?= $sort_filter == 'amount_desc' ? 'selected' : '' ?>>Amount (Highest First)</option>
                                    <option value="amount_asc" <?= $sort_filter == 'amount_asc' ? 'selected' : '' ?>>Amount (Lowest First)</option>
                                </select>
                            </div>
                            
                            <div class="flex gap-2">
                                <button type="button" id="resetFiltersBtn" class="bg-gray-200 dark:bg-slate-700 hover:bg-gray-300 dark:hover:bg-slate-600 text-gray-700 dark:text-gray-300 font-bold py-2 px-4 rounded-lg text-sm w-full text-center flex items-center justify-center transition">Reset</button>
                            </div>

                        </div>
                    </div>
                </form>

               <div class="flex flex-col xl:flex-row gap-4 flex-1 overflow-hidden">
                    
                    <?php if(!empty($chartData)): ?>
                    <div class="bg-white dark:bg-slate-900/80 rounded-xl shadow border border-gray-200 dark:border-slate-800 p-4 w-full xl:w-64 flex flex-col justify-center items-center flex-shrink-0">
                        <h3 class="text-xs font-bold text-gray-500 dark:text-slate-400 uppercase tracking-widest w-full text-center mb-4">Distribution</h3>
                        <div class="w-full h-40 relative">
                            <canvas id="expenseChart"></canvas>
                        </div>
                    </div>
                    <?php endif; ?>

                   <div class="flex-1 bg-white dark:bg-slate-900/80 rounded-xl shadow border border-gray-200 dark:border-slate-800 flex flex-col overflow-hidden relative" id="tableDataArea">
                        <div class="overflow-y-auto flex-1 custom-scroll">
                            <table class="w-full text-left min-w-[800px]">
                                <thead class="bg-[#1E3A1D] dark:bg-green-900 text-white text-xs uppercase font-bold sticky top-0 z-10 dark:border-b dark:border-slate-700">
                                    <tr>
                                        <th class="p-4 pl-6 w-28">Date</th>
                                        <th class="p-4 w-40">Category</th>
                                        <th class="p-4 w-auto">Details</th>
                                        <th class="p-4 text-center w-24">Qty</th>
                                        <th class="p-4 text-right w-32">Amount</th>
                                        <th class="p-4 pr-6 text-center w-28">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-slate-800 text-sm">
                                    <?php if(empty($expensesList)): ?>
                                        <tr><td colspan="6" class="p-10 text-center text-gray-400 dark:text-slate-500 italic">No expenses found for this period.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($expensesList as $exp): 
                                        // Category Badge Styling
                                        $cat = $exp['category'];
                                        $cat_class = "bg-gray-100 dark:bg-slate-800 text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-slate-700";
                                        if ($cat == 'Spoilage Loss') $cat_class = "bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 border border-red-200 dark:border-red-900/50";
                                        else if ($cat == 'Restock') $cat_class = "bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-400 border border-blue-200 dark:border-blue-900/50";
                                        else if ($cat == 'Payroll') $cat_class = "bg-purple-50 dark:bg-purple-900/20 text-purple-700 dark:text-purple-400 border border-purple-200 dark:border-purple-900/50";
                                        else if ($cat == 'Marketing') $cat_class = "bg-pink-50 dark:bg-pink-900/20 text-pink-700 dark:text-pink-400 border border-pink-200 dark:border-pink-900/50";
                                        else if ($cat == 'Operating') $cat_class = "bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400 border border-amber-200 dark:border-amber-900/50";
                                        else if ($cat == 'Logistics') $cat_class = "bg-teal-50 dark:bg-teal-900/20 text-teal-700 dark:text-teal-400 border border-teal-200 dark:border-teal-900/50";

                                        $clean_desc = htmlspecialchars($exp['description'] ?? '');
                                        
                                        $current_id = $exp[$pk_col];
                                        $js_date = htmlspecialchars($exp['expense_date'], ENT_QUOTES);
                                        $js_cat = htmlspecialchars($exp['category'], ENT_QUOTES);
                                        $js_amt = floatval($exp['amount']);
                                        $js_method = htmlspecialchars($exp['payment_method'], ENT_QUOTES);
                                        $js_desc = htmlspecialchars($exp['description'], ENT_QUOTES);
                                        $js_prod = htmlspecialchars($exp['product_id'] ?? '', ENT_QUOTES);
                                        $js_prod_name = htmlspecialchars($exp['product_name'] ?? '', ENT_QUOTES);
                                        $js_qty = floatval($exp['quantity'] ?? 0);
                                        $display_qty = $js_qty > 0 ? (fmod($js_qty, 1) == 0 ? number_format($js_qty, 0) : number_format($js_qty, 2)) : '-';
                                        
                                        $is_spoilage = ($cat === 'Spoilage Loss');
                                        $is_editable = true; 
                                        ?>
                                        <tr class="hover:bg-gray-50 dark:hover:bg-slate-800/50 transition group">
                                            <td class="p-4 pl-6 align-middle font-medium text-gray-900 dark:text-white">
                                                <?= date('M d, Y', strtotime($exp['expense_date'])) ?>
                                            </td>
                                            <td class="p-4 align-middle">
                                                <span class="inline-flex items-center justify-center px-2.5 py-1 rounded text-[10px] font-bold uppercase tracking-wider <?= $cat_class ?> whitespace-nowrap">
                                                    <?= htmlspecialchars($cat) ?>
                                                </span>
                                            </td>
                                            <td class="p-4 align-middle">
                                                <div class="text-xs text-gray-700 dark:text-slate-300 font-medium truncate max-w-[200px]"><?= $clean_desc ?: '<i class="text-gray-400 dark:text-slate-500">No description provided.</i>' ?></div>
                                                <?php if($exp['product_name']): ?>
                                                <div class="text-[10px] font-bold text-blue-600 dark:text-blue-400 mt-1 flex items-center gap-1">
                                                    <span class="material-icons text-[12px]">inventory_2</span>
                                                    <?= htmlspecialchars($exp['product_name']) ?>
                                                </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="p-4 align-middle text-center font-bold <?= $js_qty > 0 ? 'text-blue-600 dark:text-blue-400' : 'text-gray-400 dark:text-slate-500' ?>">
                                                <?= $js_qty > 0 ? $display_qty : '-' ?>
                                            </td>
                                            <td class="p-4 align-middle text-right font-mono font-bold text-red-700 dark:text-red-400">
                                                -₱<?= number_format($exp['amount'], 2) ?>
                                            </td>
                                            <td class="p-4 pr-6 align-middle">
                                                <div class="flex justify-center items-center gap-1">
                                                    <button type="button" onclick="openViewModal(<?= $current_id ?>, '<?= $js_date ?>', '<?= $js_cat ?>', <?= $js_amt ?>, '<?= $js_method ?>', '<?= $js_desc ?>', '<?= $js_prod_name ?>', <?= $js_qty ?>)" class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 bg-blue-50 dark:bg-blue-900/30 hover:bg-blue-100 dark:hover:bg-blue-800/50 transition p-1.5 rounded" title="View Details">
                                                        <span class="material-icons text-[16px]">visibility</span>
                                                    </button>
                                                    <?php if ($is_spoilage): ?>
                                                        <span class="material-icons text-red-300 dark:text-red-800/50 text-[16px] p-1.5 cursor-not-allowed bg-red-50 dark:bg-red-900/20 rounded" title="Locked (Auto-generated Spoilage. Edit in Spoilage Module)">lock</span>
                                                    <?php elseif ($is_editable): ?>
                                                        <button type="button" onclick="editExpense(<?= $current_id ?>, '<?= $js_date ?>', '<?= $js_cat ?>', <?= $js_amt ?>, '<?= $js_method ?>', '<?= $js_desc ?>', '<?= $js_prod ?>', <?= $js_qty ?>)" class="text-yellow-600 dark:text-yellow-400 hover:text-yellow-800 dark:hover:text-yellow-300 bg-yellow-50 dark:bg-yellow-900/30 hover:bg-yellow-100 dark:hover:bg-yellow-800/50 transition p-1.5 rounded opacity-0 group-hover:opacity-100" title="Edit">
                                                            <span class="material-icons text-[16px]">edit</span>
                                                        </button>
                                                        <form method="POST" action="" onsubmit="return confirm('Are you sure you want to delete this expense? This will also reverse any associated stock levels.');" class="inline opacity-0 group-hover:opacity-100 transition">
                                                            <input type="hidden" name="expense_id" value="<?= $current_id ?>">
                                                            <button type="submit" name="delete_expense" class="text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300 bg-red-50 dark:bg-red-900/30 hover:bg-red-100 dark:hover:bg-red-800/50 p-1.5 rounded" title="Delete">
                                                                <span class="material-icons text-[16px]">delete</span>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if($total_pages > 0): ?>
                        <div class="p-3 border-t border-gray-200 dark:border-slate-800 bg-gray-50 dark:bg-slate-900 flex flex-col md:flex-row justify-between items-center text-sm z-10 sticky bottom-0">
                            <span class="text-gray-500 dark:text-slate-400 mb-2 md:mb-0">
                                Showing <span class="font-bold text-gray-900 dark:text-white"><?= $total_items > 0 ? $offset + 1 : 0 ?></span> 
                                to <span class="font-bold text-gray-900 dark:text-white"><?= min($offset + $per_page, $total_items) ?></span> 
                                of <span class="font-bold text-gray-900 dark:text-white"><?= $total_items ?></span> expenses
                            </span>
                            
                            <?php if($total_pages > 1): ?>
                            <div class="flex items-center gap-1 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg p-1 shadow-sm">
                                <?php for($i=1; $i<=$total_pages; $i++): ?>
                                    <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&filter_category=<?= urlencode($filter_category) ?>&start_date=<?= urlencode($filter_start) ?>&end_date=<?= urlencode($filter_end) ?>&sort=<?= urlencode($sort_filter) ?>" 
                                       class="w-8 h-8 flex items-center justify-center rounded font-bold transition <?= $i === $page ? 'bg-[#1E3A1D] dark:bg-green-700 text-white shadow' : 'bg-transparent text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-slate-700' ?>">
                                        <?= $i ?>
                                    </a>
                                <?php endfor; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>
            </div>
        </div>
    </main>

    <div id="viewModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center modal-z opacity-0 transition-opacity duration-300 backdrop-blur-sm">
        <div class="bg-[#F8F5EE] dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-md mx-4 transform scale-95 transition-transform duration-300 overflow-hidden flex flex-col border dark:border-slate-800">
            <div class="bg-white dark:bg-slate-800 p-6 pb-4 border-b border-gray-200 dark:border-slate-700 flex justify-between items-start">
                <div>
                    <h2 class="text-xl font-bold text-[#1E3A1D] dark:text-white flex items-center gap-2"><span class="material-icons dark:text-green-400">receipt_long</span> Expense Details</h2>
                    <p class="text-sm text-gray-500 dark:text-slate-400 mt-1">Full transaction record</p>
                </div>
                <button onclick="closeViewModal()" class="text-gray-400 hover:text-red-500 transition"><span class="material-icons">close</span></button>
            </div>
            
            <div class="p-6 space-y-5">
                <div class="flex justify-between items-end border-b border-gray-200 dark:border-slate-800 pb-4">
                    <div>
                        <p class="text-[10px] font-bold text-gray-500 dark:text-slate-400 uppercase tracking-widest mb-1">Total Amount</p>
                        <h3 class="text-3xl font-black font-mono text-red-600 dark:text-red-400" id="viewAmount">₱0.00</h3>
                    </div>
                    <div class="text-right">
                        <span id="viewCat" class="inline-block px-3 py-1 rounded text-xs font-bold border shadow-sm mb-1">Category</span>
                        <p class="text-xs font-bold text-gray-500 dark:text-slate-400" id="viewDate">Date</p>
                    </div>
                </div>
                
                <div class="grid grid-cols-2 gap-4 bg-white dark:bg-slate-800/50 p-4 rounded-xl border border-gray-200 dark:border-slate-800 shadow-sm">
                    <div>
                        <p class="text-[10px] font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Payment Method</p>
                        <p class="text-sm font-bold text-gray-900 dark:text-white flex items-center gap-1.5"><span class="material-icons text-[14px] text-gray-400">account_balance_wallet</span> <span id="viewMethod">Cash</span></p>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Quantity</p>
                        <p class="text-sm font-bold text-blue-700 dark:text-blue-400 font-mono" id="viewQty">-</p>
                    </div>
                    <div class="col-span-2 border-t border-gray-100 dark:border-slate-700 pt-3 mt-1">
                        <p class="text-[10px] font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Linked Product</p>
                        <p class="text-sm font-bold text-gray-900 dark:text-white flex items-center gap-1.5"><span class="material-icons text-[14px] text-blue-500">inventory_2</span> <span id="viewProduct">None</span></p>
                    </div>
                </div>

                <div>
                    <p class="text-[10px] font-bold text-gray-500 dark:text-slate-400 uppercase mb-1.5 ml-1">Description / Notes</p>
                    <div class="bg-white dark:bg-slate-800/50 p-4 rounded-xl border border-gray-200 dark:border-slate-800 shadow-sm text-sm text-gray-700 dark:text-slate-300 whitespace-pre-wrap min-h-[60px] italic" id="viewDesc"></div>
                </div>
            </div>
            
            <div class="p-5 border-t border-gray-100 dark:border-slate-800 bg-white dark:bg-slate-800 flex justify-end flex-shrink-0">
                <button onclick="closeViewModal()" class="bg-gray-100 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-slate-600 px-5 py-2.5 rounded-lg text-sm font-bold transition shadow-sm">Close</button>
            </div>
        </div>
    </div>

    <script>
       // --- HTMX-STYLE AJAX FILTER LOGIC ---
        document.getElementById('toggleFilterBtn').addEventListener('click', function() {
            document.getElementById('filterOptions').classList.toggle('open');
        });

        // ==========================================
        // HTMX-STYLE AJAX ENGINE (Filters, Search, Pagination)
        // ==========================================
        const filterForm = document.getElementById('filterForm');
        const liveSearchInput = document.getElementById('searchInput');
        const tableContainer = document.getElementById('tableDataArea');
        const resetBtn = document.getElementById('resetFiltersBtn');
        let searchTimeout;

        function performAjaxSearch(fetchUrl = null) {
            if (!tableContainer) return;

            let url;
            if (fetchUrl) {
                // If a specific URL is passed (like clicking a Pagination link)
                url = new URL(fetchUrl, window.location.origin);
            } else {
                // Otherwise build the URL from the filter form
                url = new URL(window.location.pathname, window.location.origin);
                if (filterForm) {
                    const formData = new FormData(filterForm);
                    for (const [key, value] of formData.entries()) {
                        if (value) url.searchParams.set(key, value);
                    }
                }
            }

            tableContainer.style.opacity = '0.5';

            fetch(url.toString())
            .then(res => res.text())
            .then(html => {
                const parser = new DOMParser();
                const newDoc = parser.parseFromString(html, 'text/html');
                const newTableContainer = newDoc.getElementById('tableDataArea');

                if (newTableContainer) {
                    tableContainer.innerHTML = newTableContainer.innerHTML;
                }

                window.history.pushState({}, '', url.toString());
                tableContainer.style.opacity = '1';
            })
            .catch(err => {
                console.error('AJAX Error:', err);
                tableContainer.style.opacity = '1';
            });
        }

        // 1. Live Search Typing Interceptor
        if (liveSearchInput) {
            liveSearchInput.addEventListener('keyup', function(e) {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => performAjaxSearch(), 300);
            });
            liveSearchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') e.preventDefault();
            });
        }

        // 2. Dropdown & Date Filter Auto-submit
        if (filterForm) {
            const filterInputs = filterForm.querySelectorAll('select, input[type="date"]');
            filterInputs.forEach(input => {
                input.addEventListener('change', () => performAjaxSearch());
            });

            filterForm.addEventListener('submit', function(e) {
                e.preventDefault();
                performAjaxSearch();
            });
        }

        // 3. Reset Button Action
        if (resetBtn) {
            resetBtn.addEventListener('click', function(e) {
                e.preventDefault();
                
                // Clear search bar
                if (liveSearchInput) liveSearchInput.value = '';
                
                // Reset all form inputs to default
                if (filterForm) {
                    const selects = filterForm.querySelectorAll('select');
                    selects.forEach(select => {
                        select.value = select.options[0].value;
                    });
                    const dates = filterForm.querySelectorAll('input[type="date"]');
                    dates.forEach(date => {
                        date.value = ''; // Clears the date fields
                    });
                }
                
                // Run the search with empty filters
                performAjaxSearch();
            });
        }

        // 4. Pagination Interceptor
        document.addEventListener('click', function(e) {
            const pageLink = e.target.closest('a[href*="?page="]');
            if (pageLink && tableContainer && tableContainer.contains(pageLink)) {
                e.preventDefault(); // Stop the page from hard reloading!
                performAjaxSearch(pageLink.href); // Run AJAX with the specific page link's URL
            }
        });


        // --- DYNAMIC QUANTITY FIELD LOGIC ---
        const productData = <?= json_encode($products) ?>;
        const prodSelect = document.getElementById('expProduct');
        const qtyContainer = document.getElementById('qtyContainer');
        const qtyInput = document.getElementById('expQty');
        const qtyLabel = document.getElementById('qtyLabel');

        function toggleProductField() {
            const cat = document.getElementById('expCategory').value;
            const prodField = document.getElementById('productField');
            
            if (cat === 'Restock' || cat === 'Spoilage Loss') {
                prodField.style.display = 'block';
                if(prodSelect.value) {
                    qtyContainer.style.display = 'block';
                    setTimeout(() => qtyContainer.style.opacity = '1', 10);
                } else {
                    qtyContainer.style.opacity = '0';
                    setTimeout(() => qtyContainer.style.display = 'none', 300);
                }
            } else {
                prodField.style.display = 'none';
                prodSelect.value = '';
                qtyInput.value = '';
                qtyContainer.style.opacity = '0';
                setTimeout(() => qtyContainer.style.display = 'none', 300);
            }
        }

        function updateQtyLabel() {
            const cat = document.getElementById('expCategory').value;
            if(!prodSelect.value) {
                qtyContainer.style.opacity = '0';
                setTimeout(() => qtyContainer.style.display = 'none', 300);
                qtyInput.value = '';
                return;
            }
            
            const selectedOpt = prodSelect.options[prodSelect.selectedIndex];
            const unit = selectedOpt.getAttribute('data-unit') || 'units';
            
            if (cat === 'Restock') {
                qtyLabel.innerHTML = `Quantity Added (${unit}) <span class="text-blue-500 font-normal">will add to stock</span>`;
            } else if (cat === 'Spoilage Loss') {
                qtyLabel.innerHTML = `Quantity Wasted (${unit}) <span class="text-red-500 font-normal">will deduct stock</span>`;
            }
            
            qtyContainer.style.display = 'block';
            setTimeout(() => qtyContainer.style.opacity = '1', 10);
        }

        // --- EDIT FORM LOGIC ---
        function editExpense(id, date, category, amount, method, description, productId, qty) {
            document.getElementById('formAction').value = 'edit';
            document.getElementById('edit_id').value = id;
            
            document.getElementById('formHeader').classList.replace('bg-[#1E3A1D]', 'bg-yellow-600');
            document.getElementById('formHeader').classList.replace('dark:bg-green-900/50', 'dark:bg-yellow-900/50');
            document.getElementById('formIcon').innerText = 'edit';
            document.getElementById('formText').innerText = 'Edit Transaction';
            document.getElementById('cancelEditBtn').classList.remove('hidden');
            
            const btn = document.getElementById('submitBtn');
            btn.classList.replace('bg-[#1E3A1D]', 'bg-yellow-600');
            btn.classList.replace('hover:bg-[#2a4e29]', 'hover:bg-yellow-700');
            btn.classList.replace('dark:bg-green-700', 'dark:bg-yellow-600');
            btn.classList.replace('dark:hover:bg-green-600', 'dark:hover:bg-yellow-500');
            document.getElementById('submitIcon').innerText = 'update';
            document.getElementById('submitText').innerText = 'Update Transaction';

            document.getElementById('expDate').value = date;
            document.getElementById('expCategory').value = category;
            document.getElementById('expAmount').value = amount;
            document.getElementById('expMethod').value = method;
            document.getElementById('expDesc').value = description;
            
            toggleProductField();
            if(productId && (category === 'Restock' || category === 'Spoilage Loss')) {
                document.getElementById('expProduct').value = productId;
                updateQtyLabel();
                document.getElementById('expQty').value = qty;
            }
            
            document.getElementById('formPanel').scrollIntoView({ behavior: 'smooth' });
        }

        function cancelEdit() {
            document.getElementById('formAction').value = 'add';
            document.getElementById('edit_id').value = '';
            
            document.getElementById('formHeader').classList.replace('bg-yellow-600', 'bg-[#1E3A1D]');
            document.getElementById('formHeader').classList.replace('dark:bg-yellow-900/50', 'dark:bg-green-900/50');
            document.getElementById('formIcon').innerText = 'add_circle';
            document.getElementById('formText').innerText = 'Log Transaction';
            document.getElementById('cancelEditBtn').classList.add('hidden');
            
            const btn = document.getElementById('submitBtn');
            btn.classList.replace('bg-yellow-600', 'bg-[#1E3A1D]');
            btn.classList.replace('hover:bg-yellow-700', 'hover:bg-[#2a4e29]');
            btn.classList.replace('dark:bg-yellow-600', 'dark:bg-green-700');
            btn.classList.replace('dark:hover:bg-yellow-500', 'dark:hover:bg-green-600');
            document.getElementById('submitIcon').innerText = 'save';
            document.getElementById('submitText').innerText = 'Save Transaction';

            document.getElementById('expAmount').value = '';
            document.getElementById('expDesc').value = '';
            document.getElementById('expProduct').value = '';
            document.getElementById('expProduct').dispatchEvent(new Event('change'));
            document.getElementById('expDate').value = '<?= date('Y-m-d') ?>';
        }

        // --- VIEW MODAL LOGIC ---
        function openViewModal(id, date, category, amount, method, description, productName, qty) {
            document.getElementById('viewAmount').innerText = '₱' + parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            
            const d = new Date(date);
            document.getElementById('viewDate').innerText = d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
            
            document.getElementById('viewCat').innerText = category;
            document.getElementById('viewMethod').innerText = method;
            document.getElementById('viewProduct').innerText = productName || 'General Expense (No Product)';
            document.getElementById('viewQty').innerText = qty > 0 ? qty : 'N/A';
            document.getElementById('viewDesc').innerText = description || 'No description provided.';

            const catBadge = document.getElementById('viewCat');
            catBadge.className = "inline-block px-3 py-1 rounded text-xs font-bold border shadow-sm mb-1";
            if (category == 'Spoilage Loss') catBadge.classList.add("bg-red-50", "dark:bg-red-900/20", "text-red-700", "dark:text-red-400", "border-red-200", "dark:border-red-900/50");
            else if (category == 'Restock') catBadge.classList.add("bg-blue-50", "dark:bg-blue-900/20", "text-blue-700", "dark:text-blue-400", "border-blue-200", "dark:border-blue-900/50");
            else if (category == 'Payroll') catBadge.classList.add("bg-purple-50", "dark:bg-purple-900/20", "text-purple-700", "dark:text-purple-400", "border-purple-200", "dark:border-purple-900/50");
            else if (category == 'Marketing') catBadge.classList.add("bg-pink-50", "dark:bg-pink-900/20", "text-pink-700", "dark:text-pink-400", "border-pink-200", "dark:border-pink-900/50");
            else if (category == 'Operating') catBadge.classList.add("bg-amber-50", "dark:bg-amber-900/20", "text-amber-700", "dark:text-amber-400", "border-amber-200", "dark:border-amber-900/50");
            else if (category == 'Logistics') catBadge.classList.add("bg-teal-50", "dark:bg-teal-900/20", "text-teal-700", "dark:text-teal-400", "border-teal-200", "dark:border-teal-900/50");
            else catBadge.classList.add("bg-gray-100", "dark:bg-slate-800", "text-gray-600", "dark:text-gray-400", "border-gray-200", "dark:border-slate-700");

            const m = document.getElementById('viewModal');
            m.classList.remove('hidden');
            m.classList.add('flex');
            setTimeout(() => {
                m.classList.remove('opacity-0');
                m.firstElementChild.classList.remove('scale-95');
            }, 10);
        }

        function closeViewModal() {
            const m = document.getElementById('viewModal');
            m.classList.add('opacity-0');
            m.firstElementChild.classList.add('scale-95');
            setTimeout(() => {
                m.classList.add('hidden');
                m.classList.remove('flex');
            }, 300);
        }

        // --- CHART JS ---
        <?php if(!empty($chartData)): ?>
        const isDark = document.documentElement.classList.contains('dark');
        const ctx = document.getElementById('expenseChart').getContext('2d');
        const labels = <?= json_encode(array_keys($chartData)) ?>;
        const data = <?= json_encode(array_values($chartData)) ?>;
        
        const colors = {
            'Operating': '#f59e0b',
            'Payroll': '#8b5cf6',
            'Marketing': '#ec4899',
            'Restock': '#3b82f6',
            'Spoilage Loss': '#ef4444',
            'Logistics': '#14b8a6',
            'Miscellaneous': '#64748b'
        };
        const bgColors = labels.map(l => colors[l] || '#9ca3af');

        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{ 
                    data: data, 
                    backgroundColor: bgColors, 
                    borderColor: isDark ? '#0f172a' : '#ffffff',
                    borderWidth: isDark ? 2 : 0, 
                    hoverOffset: 4 
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { 
                        backgroundColor: isDark ? 'rgba(15, 23, 42, 0.95)' : 'rgba(255, 255, 255, 0.95)',
                        titleColor: isDark ? '#f8fafc' : '#1e293b', 
                        bodyColor: isDark ? '#f8fafc' : '#1e293b',
                        borderColor: isDark ? '#334155' : '#e2e8f0', 
                        borderWidth: 1,
                        padding: 10, boxPadding: 6,
                        callbacks: { label: function(context) {
                            let label = context.label || ''; if (label) { label += ': '; }
                            if (context.parsed !== null) { label += new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(context.parsed); }
                            return label;
                        }}
                    }
                },
                cutout: '75%'
            }
        });
        <?php endif; ?>

    </script>
</body>
</html>