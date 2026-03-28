<?php
// admin_components/pages/invoices.php

// --- 1. CONFIGURATION & SECURITY HEADERS ---
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

if (session_status() == PHP_SESSION_NONE) { session_start(); }
ob_start();
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// --- 2. CSRF PROTECTION ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

include_once '../includes/db_connection.php';

// --- AUDIT HELPER ---
$auditHelperPath = '../includes/audit_helper.php';
if (file_exists($auditHelperPath)) { include_once $auditHelperPath; } 
else { if (!function_exists('log_audit_action')) { function log_audit_action($a, $b, $c, $d = []) { return true; } } }

// --- SECURITY GATE ---
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    if(!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        ob_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Session expired']); exit;
    }
    header("location: ../admin_login.php"); exit;
}

// --- AUTO-SETUP: PROOFS TABLE ---
$conn->query("CREATE TABLE IF NOT EXISTS payment_proofs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX(sale_id)
)");

// --- BACKEND HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean(); header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // A. FETCH DETAILS
    if ($action === 'get_invoice_details') {
        $sale_id = intval($_POST['sale_id']);
        
        // 1. Sale Info
        $q = "SELECT s.*, c.client_name FROM sales s LEFT JOIN clients c ON s.client_id = c.client_id WHERE s.sale_id = ?";
        $stmt = $conn->prepare($q);
        $stmt->bind_param("i", $sale_id);
        $stmt->execute();
        $sale = $stmt->get_result()->fetch_assoc();

        // 2. Payments
        $qp = "SELECT * FROM payments WHERE sale_id = ? ORDER BY payment_date DESC";
        $stmt_p = $conn->prepare($qp);
        $stmt_p->bind_param("i", $sale_id);
        $stmt_p->execute();
        $payments = $stmt_p->get_result()->fetch_all(MYSQLI_ASSOC);

        // 3. Proofs
        $qpr = "SELECT * FROM payment_proofs WHERE sale_id = ? ORDER BY uploaded_at DESC";
        $stmt_pr = $conn->prepare($qpr);
        $stmt_pr->bind_param("i", $sale_id);
        $stmt_pr->execute();
        $proofs = $stmt_pr->get_result()->fetch_all(MYSQLI_ASSOC);

        // 4. Items
        $qi = "SELECT si.quantity, si.price, si.subtotal, p.name, p.unit 
               FROM sales_items si 
               JOIN products p ON si.product_id = p.product_id 
               WHERE si.sale_id = ?";
        $stmt_i = $conn->prepare($qi);
        $stmt_i->bind_param("i", $sale_id);
        $stmt_i->execute();
        $items = $stmt_i->get_result()->fetch_all(MYSQLI_ASSOC);

        echo json_encode([
            'success' => true, 
            'sale' => $sale, 
            'payments' => $payments, 
            'proofs' => $proofs, 
            'items' => $items 
        ]);
        exit;
    }

    // B. RECORD PAYMENT
    if ($action === 'record_payment') {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Security Error: Invalid Token']); exit;
        }

        $sale_id = intval($_POST['sale_id']);
        $amount = floatval($_POST['amount']);
        $method = strip_tags($_POST['method']);
        $ref = strip_tags($_POST['reference']);
        $note = strip_tags($_POST['notes']);
        $pay_date = $_POST['payment_date'] ?: date('Y-m-d H:i:s');
        $user_id = $_SESSION['user_id'] ?? 0;

        if ($amount <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid amount']); exit; }

        $conn->begin_transaction();
        try {
            $check = $conn->query("SELECT total_amount, amount_paid, payment_status FROM sales WHERE sale_id = $sale_id FOR UPDATE")->fetch_assoc();
            
            // --- PREVENT PAYMENT IF REFUNDED ---
            if ($check['payment_status'] === 'Refunded') {
                throw new Exception("Cannot process payment. This invoice has been marked as Refunded.");
            }

            $current_paid = floatval($check['amount_paid']);
            $total = floatval($check['total_amount']);
            $remaining = $total - $current_paid;

            if ($amount > $remaining + 0.01) {
                throw new Exception("Amount exceeds balance. Remaining: ₱" . number_format($remaining, 2));
            }

            $stmt = $conn->prepare("INSERT INTO payments (sale_id, amount, payment_method, reference_no, notes, payment_date, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("idssssi", $sale_id, $amount, $method, $ref, $note, $pay_date, $user_id);
            $stmt->execute();

            $new_paid = $current_paid + $amount;
            $status = 'Partial';
            if ($new_paid >= $total - 0.01) { $status = 'Paid'; } 
            
            $upd = $conn->prepare("UPDATE sales SET amount_paid = ?, payment_status = ? WHERE sale_id = ?");
            $upd->bind_param("dsi", $new_paid, $status, $sale_id);
            $upd->execute();

            $conn->commit();
            if (function_exists('log_audit_action')) log_audit_action('Payment', 'Finance', "Received P$amount for Order #$sale_id");
            
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // C. UPLOAD PROOF
    if ($action === 'upload_proof') {
        $sale_id = intval($_POST['sale_id']);
        if (!isset($_FILES['proof']) || $_FILES['proof']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Upload error.']); exit;
        }

        $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
        $filename = $_FILES['proof']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed)) {
            echo json_encode(['success' => false, 'message' => 'Invalid file type.']); exit;
        }

        $uploadDir = '../uploads/proofs/';
        if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }

        $newFilename = 'proof_' . $sale_id . '_' . time() . '_' . rand(100,999) . '.' . $ext;
        $dest = $uploadDir . $newFilename;

        if (move_uploaded_file($_FILES['proof']['tmp_name'], $dest)) {
            $dbPath = 'uploads/proofs/' . $newFilename;
            $stmt = $conn->prepare("INSERT INTO payment_proofs (sale_id, file_path) VALUES (?, ?)");
            $stmt->bind_param("is", $sale_id, $dbPath);
            $stmt->execute();
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to save file.']);
        }
        exit;
    }

    // D. ISSUE REFUND
    if ($action === 'issue_refund') {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) { 
            echo json_encode(['success' => false, 'message' => 'Security Error']); exit; 
        }

        $sale_id = intval($_POST['sale_id']);
        $refund_amount = floatval($_POST['refund_amount']); 
        $method = strip_tags($_POST['method'] ?? 'Refund');
        $ref = strip_tags($_POST['reference'] ?? '');
        $note = strip_tags($_POST['notes'] ?? 'Refund issued for returned items.');
        $pay_date = date('Y-m-d H:i:s'); // Grab current date to match parameters exactly
        $user_id = $_SESSION['user_id'] ?? 0;

        $conn->begin_transaction();
        try {
            $check = $conn->query("SELECT total_amount, amount_paid FROM sales WHERE sale_id = $sale_id FOR UPDATE")->fetch_assoc();
            $new_paid = floatval($check['amount_paid']) - $refund_amount; 

            // Negative payment to log the cash outgoing
            $neg_amount = -$refund_amount;
            
            // FIXED BIND PARAMS: We have 7 placeholders now and exactly 7 variables being bound (idssssi)
            $stmt = $conn->prepare("INSERT INTO payments (sale_id, amount, payment_method, reference_no, notes, payment_date, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("idssssi", $sale_id, $neg_amount, $method, $ref, $note, $pay_date, $user_id);
            $stmt->execute();

            $upd = $conn->prepare("UPDATE sales SET amount_paid = ?, payment_status = 'Refunded' WHERE sale_id = ?");
            $upd->bind_param("di", $new_paid, $sale_id);
            $upd->execute();

            $conn->commit();
            if (function_exists('log_audit_action')) log_audit_action('Refund', 'Finance', "Issued P$refund_amount refund ($method) for Order #$sale_id");
            echo json_encode(['success' => true]);
        } catch (Exception $e) { 
            $conn->rollback(); 
            echo json_encode(['success' => false, 'message' => $e->getMessage()]); 
        }
        exit;
    }
}
ob_end_flush();

// --- FETCH DATA ---
$query = "
    SELECT s.sale_id, s.total_amount, s.amount_paid, s.payment_status, s.delivered_at, c.client_name 
    FROM sales s
    LEFT JOIN clients c ON s.client_id = c.client_id
    WHERE s.order_status = 'Completed'
    ORDER BY CASE WHEN s.payment_status = 'Pending' THEN 1 WHEN s.payment_status = 'Partial' THEN 2 ELSE 3 END, s.delivered_at DESC
";
$invoices = $conn->query($query)->fetch_all(MYSQLI_ASSOC);

// --- CALCULATE STATS ---
$stats = [
    'pending_count' => 0,
    'partial_count' => 0,
    'total_receivables' => 0,
    'collected_today' => 0
];

// Get Collected Today
$today_sql = "SELECT SUM(amount) as total FROM payments WHERE DATE(payment_date) = CURDATE()";
$today_res = $conn->query($today_sql)->fetch_assoc();
$stats['collected_today'] = floatval($today_res['total'] ?? 0);

foreach($invoices as $inv) {
    if($inv['payment_status'] == 'Pending') $stats['pending_count']++;
    if($inv['payment_status'] == 'Partial') $stats['partial_count']++;
    
    // --- EXCLUDE REFUNDED INVOICES FROM TOTAL RECEIVABLES ---
    if($inv['payment_status'] != 'Paid' && $inv['payment_status'] != 'Refunded') { 
        $stats['total_receivables'] += ($inv['total_amount'] - $inv['amount_paid']); 
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreshFlow - Finance</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class' };
    </script>
    
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Roboto+Mono:wght@400;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/jpeg" href="/admin_components/assets/img/tabicon4.png">
    <script>
        // DARK MODE INITIALIZATION (Prevents White Flash)
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
        
        // Smooth Page Transition
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
        body { font-family: 'Inter', sans-serif; background-color: var(--brand-cream); color: #2B2B2B; overflow-x: hidden; transition: background-color 0.3s ease; }
        
        /* --- DARK MODE GLOBAL STYLES --- */
        .dark body {
            background-color: #000000;
            background-image: radial-gradient(circle, rgba(255, 255, 255, 0.07) 1px, transparent 1px);
            background-size: 16px 16px;
            color: #f8fafc;
        }

        .font-mono, .font-heading { font-family: 'Roboto Mono', monospace; }
        
        /* THE MAGIC SMOOTH ANIMATION + GPU HACK */
        .optimized-main { will-change: margin-left, width; transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1), width 0.3s cubic-bezier(0.4, 0, 0.2, 1); transform: translateZ(0); }
        
        /* THE ULTIMATE TABLE PERFORMANCE HACK */
        .order-row { 
            content-visibility: auto; 
            contain-intrinsic-size: 64px; 
            contain: paint layout;
        }

        .status-badge { padding: 4px 12px; border-radius: 99px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
        .status-Pending { background: #fee2e2; color: #991b1b; } 
        .dark .status-Pending { background: rgba(153, 27, 27, 0.2); color: #fca5a5; border: 1px solid rgba(248, 113, 113, 0.2); }
        .status-Partial { background: #ffedd5; color: #9a3412; }
        .dark .status-Partial { background: rgba(154, 52, 18, 0.2); color: #fdba74; border: 1px solid rgba(251, 146, 60, 0.2); }
        .status-Paid { background: #dcfce7; color: #166534; }
        .dark .status-Paid { background: rgba(22, 101, 52, 0.2); color: #86efac; border: 1px solid rgba(74, 222, 128, 0.2); }
        .status-Refunded { background: #f3f4f6; color: #4b5563; text-decoration: line-through; }
        .dark .status-Refunded { background: rgba(75, 85, 99, 0.2); color: #9ca3af; border: 1px solid rgba(156, 163, 175, 0.2); }
        
        .custom-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .dark .custom-scroll::-webkit-scrollbar-thumb { background: #334155; }
        
        @keyframes slideUp { from { transform: translateY(100%); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .toast { animation: slideUp 0.3s ease-out; }

        /* Filter & Sidebar Smoothness */
        #filterContainer { transition: all 0.3s ease-in-out; max-height: 0; overflow: hidden; }
        #filterContainer.open { max-height: 500px; padding-top: 1rem; margin-top: 1rem; border-top: 1px solid #e5e7eb; }
        .dark #filterContainer.open { border-color: #334155; }

        /* Inputs matching Dark Mode */
        .form-input, .filter-select { background-color: #ffffff; border: 1px solid #d1d5db; color: #374151; border-radius: 0.5rem; transition: all 0.2s;}
        .form-input:focus, .filter-select:focus { outline: none; border-color: #1E3A1D; box-shadow: 0 0 0 3px rgba(30, 58, 29, 0.1); }
        .dark .form-input, .dark .filter-select { background-color: rgba(30, 41, 59, 0.6); border-color: #334155; color: #f8fafc; }
        .dark .form-input:focus, .dark .filter-select:focus { border-color: #4ade80; box-shadow: 0 0 0 3px rgba(74, 222, 128, 0.15); }

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
    </style>
</head>
<body class="flex h-screen overflow-hidden">
    <?php include '../includes/sidebar.php'; ?>

    <main class="ml-20 flex-1 flex flex-col h-screen overflow-hidden relative optimized-main p-6 lg:p-8">
        
       <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 flex-shrink-0">
            <div>
                <h1 class="text-3xl font-bold text-[#1E3A1D] dark:text-white tracking-tight flex items-center gap-3">
                    <span class="material-icons text-3xl dark:text-green-400">account_balance_wallet</span>
                    Finance Overview
                </h1>
                <p class="text-sm text-gray-500 dark:text-slate-400 mt-1 ml-11">Manage client invoices and record payments</p>
            </div>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8 flex-shrink-0">
            <div class="content-card p-5 border-l-4 border-orange-500 dark:border-orange-400 flex items-center justify-between group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(249,115,22,0.2)] dark:hover:shadow-[0_0_20px_rgba(249,115,22,0.3)] dark:hover:border-orange-300 transition-all duration-300">
                <div>
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase group-hover:text-orange-500 dark:group-hover:text-orange-300 transition-colors">Pending Invoices</p>
                    <p class="font-heading text-3xl font-bold text-orange-600 dark:text-orange-400 mt-1 group-hover:scale-110 transition-transform origin-left"><?= $stats['pending_count'] ?></p>
                </div>
                <div class="bg-orange-50 dark:bg-orange-900/30 p-3 rounded-full text-orange-600 dark:text-orange-400 group-hover:bg-orange-100 dark:group-hover:bg-orange-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">pending_actions</span>
                </div>
            </div>

            <div class="content-card p-5 border-l-4 border-blue-500 dark:border-blue-400 flex items-center justify-between group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(59,130,246,0.2)] dark:hover:shadow-[0_0_20px_rgba(59,130,246,0.3)] dark:hover:border-blue-300 transition-all duration-300">
                <div>
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase group-hover:text-blue-500 dark:group-hover:text-blue-300 transition-colors">Partial Payments</p>
                    <p class="font-heading text-3xl font-bold text-blue-600 dark:text-blue-400 mt-1 group-hover:scale-110 transition-transform origin-left"><?= $stats['partial_count'] ?></p>
                </div>
                <div class="bg-blue-50 dark:bg-blue-900/30 p-3 rounded-full text-blue-600 dark:text-blue-400 group-hover:bg-blue-100 dark:group-hover:bg-blue-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">pie_chart</span>
                </div>
            </div>

            <div class="content-card p-5 border-l-4 border-green-500 dark:border-green-400 flex items-center justify-between group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(34,197,94,0.2)] dark:hover:shadow-[0_0_20px_rgba(34,197,94,0.3)] dark:hover:border-green-300 transition-all duration-300">
                <div>
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase group-hover:text-green-500 dark:group-hover:text-green-300 transition-colors">Collected Today</p>
                    <p class="font-heading text-2xl font-bold text-green-600 dark:text-green-400 mt-1 group-hover:scale-110 transition-transform origin-left">₱<?= number_format($stats['collected_today'], 2) ?></p>
                </div>
                <div class="bg-green-50 dark:bg-green-900/30 p-3 rounded-full text-green-600 dark:text-green-400 group-hover:bg-green-100 dark:group-hover:bg-green-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">savings</span>
                </div>
            </div>

            <div class="content-card p-5 border-l-4 border-red-500 dark:border-red-400 flex items-center justify-between group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(239,68,68,0.2)] dark:hover:shadow-[0_0_20px_rgba(239,68,68,0.3)] dark:hover:border-red-300 transition-all duration-300">
                <div>
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase group-hover:text-red-500 dark:group-hover:text-red-300 transition-colors">Total Receivables</p>
                    <p class="font-heading text-2xl font-bold text-red-600 dark:text-red-400 mt-1 group-hover:scale-110 transition-transform origin-left">₱<?= number_format($stats['total_receivables'] / 1000, 1) ?>k</p>
                </div>
                <div class="bg-red-50 dark:bg-red-900/30 p-3 rounded-full text-red-600 dark:text-red-400 group-hover:bg-red-100 dark:group-hover:bg-red-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">account_balance</span>
                </div>
            </div>
        </div>

        <div class="content-card p-4 mb-6 flex-shrink-0">
            <div class="flex flex-col md:flex-row items-center gap-4">
                <div class="relative w-full flex-grow">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400 dark:text-slate-500"><i class="fa-solid fa-search"></i></span>
                    <input type="text" id="searchInput" placeholder="Search Invoice # or Client..." class="w-full pl-10 p-2 rounded-lg form-input transition" oninput="applyFilters()">
                </div>
                <button id="toggleFilterBtn" class="bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 hover:bg-gray-50 dark:hover:bg-slate-700 text-gray-700 dark:text-slate-200 font-bold py-2 px-4 rounded-lg flex items-center gap-2 transition shadow-sm">
                    <i class="fa-solid fa-filter"></i> Filters <i id="filterCaret" class="fa-solid fa-chevron-down ml-1 text-xs"></i>
                </button>
            </div>

            <div id="filterContainer">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Date From</label>
                        <input type="date" id="dateFrom" class="w-full p-2 form-input" onchange="applyFilters()">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Date To</label>
                        <input type="date" id="dateTo" class="w-full p-2 form-input" onchange="applyFilters()">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Status</label>
                        <select id="statusFilter" class="w-full p-2 filter-select cursor-pointer" onchange="applyFilters()">
                            <option value="">All Statuses</option>
                            <option value="Pending">Pending</option>
                            <option value="Partial">Partial</option>
                            <option value="Paid">Paid</option>
                            <option value="Refunded">Refunded</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Sort By</label>
                        <select id="sortFilter" class="w-full p-2 filter-select cursor-pointer" onchange="applyFilters()">
                            <option value="newest">Newest First</option>
                            <option value="oldest">Oldest First</option>
                        </select>
                    </div>
                </div>
                <div class="flex justify-end mt-4">
                    <button onclick="resetFilters()" class="text-sm text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300 font-bold flex items-center gap-1 transition">
                        <i class="fa-solid fa-rotate-left"></i> Reset Filters
                    </button>
                </div>
            </div>
        </div>

        <div class="content-card flex-1 overflow-hidden flex flex-col mb-4" style="contain: paint layout;">
            <div class="overflow-x-auto overflow-y-auto flex-1 custom-scroll">
                <table class="w-full text-left table-fixed min-w-[900px]">
                    <thead class="bg-[#1E3A1D] dark:bg-slate-800 text-white text-xs uppercase font-bold sticky top-0 z-10">
                        <tr>
                            <th class="p-4 pl-6 w-32">Invoice #</th>
                            <th class="p-4 w-48">Client Name</th>
                            <th class="p-4 w-32">Delivery Date</th>
                            <th class="p-4 w-32 text-right">Total Amount</th>
                            <th class="p-4 w-32 text-right">Balance</th>
                            <th class="p-4 w-32 text-center">Status</th>
                            <th class="p-4 w-24 text-right pr-6">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700/50 text-sm text-gray-700 dark:text-slate-200" id="tableBody">
                        </tbody>
                </table>
            </div>

            <div class="bg-gray-50 dark:bg-slate-900 border-t border-gray-200 dark:border-slate-700 p-4 flex flex-col md:flex-row justify-between items-center gap-4">
                <div class="text-sm text-gray-500 dark:text-slate-400 font-bold" id="pageInfo">Showing 0 to 0 of 0 entries</div>
                <div class="flex items-center gap-1" id="pagination-controls">
                </div>
            </div>
        </div>
    </main>

    <div id="toast" class="fixed bottom-6 right-6 bg-[#1E3A1D] dark:bg-slate-800 text-white px-6 py-3 rounded-lg shadow-xl hidden z-50 flex items-center gap-3 border border-green-800 dark:border-slate-600">
        <span class="material-icons text-green-400">check_circle</span>
        <span id="toast-msg" class="font-semibold text-sm"></span>
    </div>

    <div id="paymentModal" class="fixed inset-0 bg-black dark:bg-opacity-80 bg-opacity-50 hidden z-40 items-center justify-center backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-5xl flex overflow-hidden h-[85vh] transform transition-all scale-95 border dark:border-slate-700" id="modalContent">
            
            <div class="w-1/2 bg-gray-50 dark:bg-slate-900/50 border-r border-gray-200 dark:border-slate-700 flex flex-col">
                <div class="p-6 bg-white dark:bg-slate-900 border-b border-gray-200 dark:border-slate-700 flex justify-between items-center shrink-0">
                    <div>
                        <h2 class="text-2xl font-black text-gray-900 dark:text-white font-mono tracking-tight" id="modal-invoice-no">#00000</h2>
                        <p class="text-sm font-bold text-gray-500 dark:text-slate-400 mt-1" id="modal-client-name">Client Name</p>
                    </div>
                    <span id="modal-status" class="status-badge text-sm">Status</span>
                </div>
                
                <div class="p-6 overflow-y-auto custom-scroll flex-grow">
                    <div class="mb-6">
                        <h4 class="text-xs font-bold text-gray-500 dark:text-slate-400 uppercase tracking-wider mb-3">Order Items</h4>
                        <div class="bg-white dark:bg-slate-800 rounded-lg border border-gray-200 dark:border-slate-700 overflow-hidden shadow-sm">
                            <table class="w-full text-left text-xs">
                                <thead class="bg-gray-50 dark:bg-slate-900 border-b border-gray-200 dark:border-slate-700 text-gray-500 dark:text-slate-400">
                                    <tr>
                                        <th class="p-2 pl-3 font-semibold">Item</th>
                                        <th class="p-2 text-right font-semibold">Qty</th>
                                        <th class="p-2 text-right font-semibold">Price</th>
                                        <th class="p-2 pr-3 text-right font-semibold">Total</th>
                                    </tr>
                                </thead>
                                <tbody id="modal-items-list" class="divide-y divide-gray-100 dark:divide-slate-700/50 dark:text-slate-200">
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-slate-800 p-5 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm mb-6">
                        <div class="flex justify-between items-center mb-3">
                            <span class="text-sm font-bold text-gray-500 dark:text-slate-400">Total Invoice</span>
                            <span class="text-lg font-black font-mono text-gray-900 dark:text-white" id="modal-total">₱0.00</span>
                        </div>
                        <div class="flex justify-between items-center mb-3">
                            <span class="text-sm font-bold text-green-600 dark:text-green-400">Total Paid</span>
                            <span class="text-lg font-black font-mono text-green-600 dark:text-green-400" id="modal-paid">₱0.00</span>
                        </div>
                        <div class="w-full bg-gray-100 dark:bg-slate-700 rounded-full h-2.5 mb-4 border border-gray-200 dark:border-slate-600">
                            <div id="modal-progress" class="bg-green-500 dark:bg-green-400 h-2.5 rounded-full transition-all duration-500" style="width: 0%"></div>
                        </div>
                        <div class="flex justify-between items-center pt-3 border-t border-gray-100 dark:border-slate-700">
                            <span class="text-sm font-bold text-red-500 dark:text-red-400 uppercase tracking-wider" id="balance-label">Remaining Balance</span>
                            <span class="text-2xl font-black font-mono text-red-600 dark:text-red-400" id="modal-balance">₱0.00</span>
                        </div>
                    </div>

                    <div class="flex gap-2 mb-4">
                        <button onclick="switchTab('history')" id="tab-history" class="flex-1 py-2 text-xs font-bold rounded-lg bg-[#1E3A1D] dark:bg-green-600 text-white transition">History</button>
                        <button onclick="switchTab('proofs')" id="tab-proofs" class="flex-1 py-2 text-xs font-bold rounded-lg bg-gray-200 dark:bg-slate-800 text-gray-600 dark:text-slate-400 hover:bg-gray-300 dark:hover:bg-slate-700 transition">Attachments</button>
                    </div>

                    <div id="history-view" class="space-y-3"></div>
                    
                    <div id="proofs-view" class="hidden flex flex-col h-full">
                        <div class="bg-blue-50 dark:bg-slate-800 border border-blue-100 dark:border-slate-600 rounded-lg p-3 mb-4 flex flex-col items-center justify-center border-dashed border-2 cursor-pointer hover:bg-blue-100 dark:hover:bg-slate-700 transition" onclick="document.getElementById('proof-upload').click()">
                            <span class="material-icons text-blue-400 dark:text-blue-500 mb-1">cloud_upload</span>
                            <span class="text-xs text-blue-600 dark:text-blue-400 font-bold">Upload Receipt / Proof</span>
                            <input type="file" id="proof-upload" class="hidden" accept="image/*,application/pdf" onchange="uploadProof(this)">
                        </div>
                        <div id="proofs-grid" class="grid grid-cols-2 gap-3"></div>
                    </div>
                </div>
            </div>

            <div class="w-1/2 p-8 flex flex-col justify-center relative bg-white dark:bg-slate-900">
                <button onclick="closeModal()" class="absolute top-4 right-4 text-gray-300 dark:text-slate-500 hover:text-gray-600 dark:hover:text-white material-icons transition">close</button>
                
                <form id="paymentForm" class="space-y-5">
                    <input type="hidden" name="action" value="record_payment">
                    <input type="hidden" name="sale_id" id="form-sale-id">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                    <div id="normal-payment-fields">
                        <h3 class="text-xl font-bold text-[#1E3A1D] dark:text-white mb-4">Record New Payment</h3>
                        
                        <div class="mb-4">
                            <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Amount Received</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-4 font-mono font-bold text-gray-500 dark:text-slate-400">₱</span>
                                <input type="number" step="0.01" id="pay-amount" name="amount" required class="w-full pl-8 pr-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-xl text-xl font-mono font-black text-[#1E3A1D] dark:text-white focus:ring-2 focus:ring-[#1E3A1D] dark:focus:ring-green-500 focus:outline-none transition">
                                <button type="button" onclick="payFull()" class="absolute right-2 top-2 text-xs bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 font-bold px-3 py-1.5 rounded-lg hover:bg-green-200 dark:hover:bg-green-800/50 transition">Pay Full</button>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Method</label>
                                <select name="method" required class="w-full p-3 bg-gray-50 dark:bg-slate-800 border border-gray-300 dark:border-slate-600 dark:text-white rounded-xl text-sm font-bold focus:ring-2 focus:ring-[#1E3A1D] dark:focus:ring-green-500 outline-none">
                                    <option value="Cash">Cash</option>
                                    <option value="GCash">GCash</option>
                                    <option value="Bank Transfer">Bank Transfer</option>
                                    <option value="Check">Check</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Date</label>
                                <input type="date" name="payment_date" id="pay-date" required class="w-full p-3 bg-gray-50 dark:bg-slate-800 border border-gray-300 dark:border-slate-600 dark:text-white rounded-xl text-sm font-bold focus:ring-2 focus:ring-[#1E3A1D] dark:focus:ring-green-500 outline-none">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Reference No. (Optional)</label>
                            <input type="text" name="reference" placeholder="e.g. GCash Ref / Check No." class="w-full p-3 bg-gray-50 dark:bg-slate-800 border border-gray-300 dark:border-slate-600 dark:text-white rounded-xl text-sm font-mono focus:ring-2 focus:ring-[#1E3A1D] dark:focus:ring-green-500 outline-none">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Internal Notes</label>
                            <textarea name="notes" rows="2" class="w-full p-3 bg-gray-50 dark:bg-slate-800 border border-gray-300 dark:border-slate-600 dark:text-white rounded-xl text-sm focus:ring-2 focus:ring-[#1E3A1D] dark:focus:ring-green-500 outline-none custom-scroll resize-none" placeholder="Add any details about this transaction..."></textarea>
                        </div>

                        <button type="submit" id="saveBtn" class="w-full bg-[#1E3A1D] dark:bg-green-700 hover:bg-[#152a14] dark:hover:bg-green-800 mt-5 text-white font-bold py-3.5 px-4 rounded-xl shadow-lg transform transition active:scale-95 flex justify-center items-center gap-2 text-lg border border-transparent dark:border-green-600">
                            <span class="material-icons">task_alt</span> Confirm Payment
                        </button>
                    </div>
                </form>

                <div id="refund-section" class="hidden flex-col justify-center bg-white dark:bg-slate-900 rounded-xl">
                    <div class="text-center mb-5 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800/30 rounded-xl">
                        <span class="material-icons text-red-500 text-4xl mb-1">account_balance_wallet</span>
                        <h3 class="text-lg font-black text-red-900 dark:text-red-300 mb-1">Overpayment Detected</h3>
                        <p class="text-sm text-red-700 dark:text-red-400">Customer overpaid by <span id="refund-amount-display" class="font-bold font-mono text-red-500"></span>.</p>
                    </div>
                    
                    <form id="detailedRefundForm" class="space-y-4">
                        <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-2 border-b dark:border-slate-700 pb-2">Record Refund Detail</h3>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Method Given</label>
                                <select id="refund-method" required class="w-full p-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-300 dark:border-slate-600 dark:text-white rounded-lg text-sm font-bold focus:ring-2 focus:ring-red-500 outline-none">
                                    <option value="Cash">Cash</option>
                                    <option value="GCash">GCash</option>
                                    <option value="Bank Transfer">Bank Transfer</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Reference No.</label>
                                <input type="text" id="refund-ref" placeholder="Optional" class="w-full p-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-300 dark:border-slate-600 dark:text-white rounded-lg text-sm font-mono focus:ring-2 focus:ring-red-500 outline-none">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Notes</label>
                            <textarea id="refund-notes" rows="2" class="w-full p-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-300 dark:border-slate-600 dark:text-white rounded-lg text-sm focus:ring-2 focus:ring-red-500 outline-none resize-none" placeholder="Reason for refund..."></textarea>
                        </div>
                        <button type="button" onclick="window.issueRefund()" class="w-full bg-red-600 hover:bg-red-700 mt-2 text-white font-bold py-3.5 rounded-xl shadow-lg transition flex justify-center items-center gap-2 text-lg">
                            <span class="material-icons">currency_exchange</span> Process & Record Refund
                        </button>
                    </form>
                </div>

            </div>
        </div>
    </div>

    <div id="lightbox" class="fixed inset-0 bg-black bg-opacity-90 hidden z-[60] flex items-center justify-center p-4 backdrop-blur-sm" onclick="this.classList.add('hidden')">
        <span class="absolute top-4 right-4 text-white text-3xl cursor-pointer hover:text-red-500 transition material-icons">close</span>
        <img id="lightbox-img" src="" class="max-w-full max-h-[90vh] object-contain rounded-lg shadow-2xl border border-gray-700" onclick="event.stopPropagation()">
    </div>

    <script>
        // GLOBALS
        const rawInvoices = <?= json_encode($invoices) ?>;
        const csrfToken = '<?= $_SESSION['csrf_token'] ?>';
        
        let filteredData = [...rawInvoices];
        let currentPage = 1; 
        const rowsPerPage = 15;
        let currentBalance = 0; 
        let currentSaleId = 0; 
        let proofsData = [];

        // --- FILTER TOGGLE SCRIPT ---
        const toggleFilterBtn = document.getElementById('toggleFilterBtn');
        const filterContainer = document.getElementById('filterContainer');
        const filterCaret = document.getElementById('filterCaret');
        let isOpen = false;

        toggleFilterBtn.addEventListener('click', () => {
            isOpen = !isOpen;
            if (isOpen) {
                filterContainer.classList.add('open');
                filterCaret.classList.replace('fa-chevron-down', 'fa-chevron-up');
                toggleFilterBtn.classList.add('bg-gray-100');
                toggleFilterBtn.classList.add('dark:bg-slate-700');
            } else {
                filterContainer.classList.remove('open');
                filterCaret.classList.replace('fa-chevron-up', 'fa-chevron-down');
                toggleFilterBtn.classList.remove('bg-gray-100');
                toggleFilterBtn.classList.remove('dark:bg-slate-700');
            }
        });

        document.addEventListener('click', (e) => {
            if (isOpen && !filterContainer.contains(e.target) && !toggleFilterBtn.contains(e.target)) {
                filterContainer.classList.remove('open');
                filterCaret.classList.replace('fa-chevron-up', 'fa-chevron-down');
                toggleFilterBtn.classList.remove('bg-gray-100');
                toggleFilterBtn.classList.remove('dark:bg-slate-700');
                isOpen = false;
            }
        });

        // --- RENDER TABLE & PAGINATION ---
        function renderTable() {
            const tbody = document.getElementById('tableBody');
            tbody.innerHTML = '';

            const start = (currentPage - 1) * rowsPerPage;
            const end = start + rowsPerPage;
            const pageData = filteredData.slice(start, end);

            if (pageData.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="p-10 text-center text-gray-400 dark:text-slate-500 italic">No invoices found.</td></tr>';
            } else {
                pageData.forEach(inv => {
                    const bal = parseFloat(inv.total_amount) - parseFloat(inv.amount_paid);
                    const balColor = bal > 0 ? 'text-red-600 dark:text-red-400' : (bal < -0.01 ? 'text-orange-500 dark:text-orange-400' : 'text-green-600 dark:text-green-400');
                    const dDate = inv.delivered_at ? new Date(inv.delivered_at).toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'}) : 'N/A';

                    const tr = document.createElement('tr');
                    tr.className = 'order-row hover:bg-gray-50 dark:hover:bg-slate-800/50 transition group';
                    tr.innerHTML = `
                        <td class="p-4 align-middle pl-6">
                            <div class="font-mono font-bold text-[#1E3A1D] dark:text-green-400 bg-green-50 dark:bg-green-900/30 px-2 py-1 rounded inline-block whitespace-nowrap">
                                #${String(inv.sale_id).padStart(5,'0')}
                            </div>
                        </td>
                        <td class="p-4 align-middle font-medium text-gray-800 dark:text-slate-200 truncate">${inv.client_name}</td>
                        <td class="p-4 align-middle text-gray-500 dark:text-slate-400 text-xs whitespace-nowrap">${dDate}</td>
                        <td class="p-4 align-middle text-right font-bold text-gray-800 dark:text-slate-200 whitespace-nowrap">₱${parseFloat(inv.total_amount).toLocaleString('en-US',{minimumFractionDigits:2})}</td>
                        <td class="p-4 align-middle text-right font-mono font-bold ${balColor} whitespace-nowrap">₱${bal.toLocaleString('en-US',{minimumFractionDigits:2})}</td>
                        <td class="p-4 align-middle text-center">
                            <span class="status-badge status-${inv.payment_status}">${inv.payment_status}</span>
                        </td>
                        <td class="p-4 align-middle text-right pr-6">
                            <button onclick="openPaymentModal(${inv.sale_id})" class="bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 text-gray-700 dark:text-slate-300 p-2 rounded-lg font-bold transition inline-flex items-center justify-center shadow-sm">
                                <span class="material-icons text-sm">visibility</span>
                            </button>
                        </td>
                    `;
                    tbody.appendChild(tr);
                });
            }
            renderPagination();
        }

        function renderPagination() {
            const totalPages = Math.ceil(filteredData.length / rowsPerPage);
            const container = document.getElementById('pagination-controls');
            container.innerHTML = '';
            
            const startItem = filteredData.length > 0 ? ((currentPage - 1) * rowsPerPage) + 1 : 0;
            const endItem = Math.min(currentPage * rowsPerPage, filteredData.length);
            document.getElementById('pageInfo').innerText = `Showing ${startItem} to ${endItem} of ${filteredData.length} entries`;

            if (totalPages <= 1) return;

            // Prev Button
            const prevBtn = document.createElement('button');
            prevBtn.innerHTML = '<i class="fa-solid fa-chevron-left text-xs"></i>';
            prevBtn.className = `px-3 py-1.5 border rounded-md text-sm font-bold transition ${currentPage === 1 ? 'bg-gray-100 dark:bg-slate-800 text-gray-400 dark:text-slate-600 cursor-not-allowed border-gray-200 dark:border-slate-700' : 'bg-white dark:bg-slate-900 text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-800 border-gray-300 dark:border-slate-700'}`;
            prevBtn.onclick = () => { if(currentPage > 1) { currentPage--; renderTable(); } };
            container.appendChild(prevBtn);

            let startPage = Math.max(1, currentPage - 1);
            let endPage = Math.min(totalPages, currentPage + 1);
            
            if (startPage > 1) {
                container.appendChild(createPageBtn(1));
                if (startPage > 2) container.appendChild(createEllipsis());
            }

            for (let i = startPage; i <= endPage; i++) {
                container.appendChild(createPageBtn(i));
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) container.appendChild(createEllipsis());
                container.appendChild(createPageBtn(totalPages));
            }

            // Next Button
            const nextBtn = document.createElement('button');
            nextBtn.innerHTML = '<i class="fa-solid fa-chevron-right text-xs"></i>';
            nextBtn.className = `px-3 py-1.5 border rounded-md text-sm font-bold transition ${currentPage === totalPages ? 'bg-gray-100 dark:bg-slate-800 text-gray-400 dark:text-slate-600 cursor-not-allowed border-gray-200 dark:border-slate-700' : 'bg-white dark:bg-slate-900 text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-800 border-gray-300 dark:border-slate-700'}`;
            nextBtn.onclick = () => { if(currentPage < totalPages) { currentPage++; renderTable(); } };
            container.appendChild(nextBtn);
        }

        function createPageBtn(page) {
            const btn = document.createElement('button');
            btn.innerText = page;
            if (page === currentPage) {
                btn.className = 'px-3 py-1.5 bg-[#1E3A1D] dark:bg-green-700 text-white border border-[#1E3A1D] dark:border-green-600 rounded-md text-sm font-bold shadow-sm';
            } else {
                btn.className = 'px-3 py-1.5 bg-white dark:bg-slate-900 text-gray-700 dark:text-slate-300 border border-gray-300 dark:border-slate-700 rounded-md text-sm font-bold hover:bg-gray-50 dark:hover:bg-slate-800 transition';
            }
            btn.onclick = () => { currentPage = page; renderTable(); };
            return btn;
        }

        function createEllipsis() {
            const span = document.createElement('span');
            span.innerHTML = '&hellip;'; 
            span.className = 'px-2 py-1 text-gray-400 dark:text-slate-500 font-bold';
            return span;
        }

        // --- SMART REAL-TIME SEARCH & FILTERS ---
        window.applyFilters = function() {
            const search = document.getElementById('searchInput').value.toLowerCase().trim();
            const dateFrom = document.getElementById('dateFrom').value;
            const dateTo = document.getElementById('dateTo').value;
            const status = document.getElementById('statusFilter').value;
            const sort = document.getElementById('sortFilter').value;

            filteredData = rawInvoices.filter(inv => {
                const searchStr = `#${String(inv.sale_id).padStart(5,'0')} ${inv.sale_id} ${inv.client_name}`.toLowerCase();
                const dDate = inv.delivered_at ? inv.delivered_at.split(' ')[0] : '1970-01-01';
                
                if (search && !searchStr.includes(search)) return false;
                if (status && inv.payment_status !== status) return false;
                if (dateFrom && dDate < dateFrom) return false;
                if (dateTo && dDate > dateTo) return false;
                return true;
            });

            filteredData.sort((a, b) => {
                const dA = new Date(a.delivered_at || '1970-01-01'); 
                const dB = new Date(b.delivered_at || '1970-01-01');
                return sort === 'newest' ? dB - dA : dA - dB;
            });

            currentPage = 1; 
            renderTable();
        };

        window.resetFilters = function() {
            document.getElementById('searchInput').value = ''; 
            document.getElementById('dateFrom').value = '';
            document.getElementById('dateTo').value = ''; 
            document.getElementById('statusFilter').value = '';
            document.getElementById('sortFilter').value = 'newest'; 
            applyFilters();
        };

        renderTable(); // Initialize

        // --- MODAL & LOGIC ---
        const modal = document.getElementById('paymentModal');
        const modalContent = document.getElementById('modalContent');
        const fDate = document.getElementById('pay-date');

        window.showToast = function(msg) {
            const t = document.getElementById('toast'); 
            document.getElementById('toast-msg').textContent = msg;
            t.classList.remove('hidden'); 
            setTimeout(() => t.classList.add('hidden'), 3000);
        };

        window.switchTab = function(tab) {
            const th = document.getElementById('tab-history'); 
            const tp = document.getElementById('tab-proofs');
            const vh = document.getElementById('history-view'); 
            const vp = document.getElementById('proofs-view');
            
            if(tab === 'history') {
                th.className = "flex-1 py-2 text-xs font-bold rounded-lg bg-[#1E3A1D] dark:bg-green-600 text-white transition";
                tp.className = "flex-1 py-2 text-xs font-bold rounded-lg bg-gray-200 dark:bg-slate-800 text-gray-600 dark:text-slate-400 hover:bg-gray-300 dark:hover:bg-slate-700 transition";
                vh.classList.remove('hidden'); 
                vp.classList.add('hidden');
            } else {
                tp.className = "flex-1 py-2 text-xs font-bold rounded-lg bg-[#1E3A1D] dark:bg-green-600 text-white transition";
                th.className = "flex-1 py-2 text-xs font-bold rounded-lg bg-gray-200 dark:bg-slate-800 text-gray-600 dark:text-slate-400 hover:bg-gray-300 dark:hover:bg-slate-700 transition";
                vp.classList.remove('hidden'); 
                vh.classList.add('hidden'); 
                renderProofs();
            }
        };

        function renderProofs() {
            const grid = document.getElementById('proofs-grid'); 
            grid.innerHTML = '';
            if(proofsData.length === 0) { 
                grid.innerHTML = '<div class="col-span-2 text-center text-gray-400 dark:text-slate-500 text-xs py-4 italic">No attachments found.</div>'; 
                return; 
            }
            
            proofsData.forEach(p => {
                const ext = p.file_path.split('.').pop().toLowerCase(); 
                const path = '../' + p.file_path;
                let content = '';
                
                if(ext === 'pdf') {
                    content = `<a href="${path}" target="_blank" class="block w-full h-24 bg-red-50 dark:bg-red-900/20 rounded-lg flex flex-col items-center justify-center hover:bg-red-100 dark:hover:bg-red-900/40 transition border border-red-100 dark:border-red-800/30">
                                   <span class="material-icons text-red-400 dark:text-red-500 text-3xl">picture_as_pdf</span>
                                   <span class="text-[10px] text-red-600 dark:text-red-400 font-bold mt-1">View PDF</span>
                               </a>`;
                } else {
                    content = `<img src="${path}" class="w-full h-24 object-cover rounded-lg cursor-pointer hover:opacity-80 transition shadow-sm border border-gray-200 dark:border-slate-700" onclick="openLightbox('${path}')">`;
                }
                
                grid.innerHTML += `<div class="relative group">${content}</div>`;
            });
        }

        window.openLightbox = function(src) { 
            document.getElementById('lightbox-img').src = src; 
            document.getElementById('lightbox').classList.remove('hidden'); 
        };

        window.uploadProof = async function(input) {
            if (input.files && input.files[0]) {
                const fd = new FormData(); 
                fd.append('action', 'upload_proof'); 
                fd.append('sale_id', currentSaleId); 
                fd.append('proof', input.files[0]);
                
                try {
                    document.getElementById('proofs-grid').innerHTML = '<div class="col-span-2 text-center text-gray-400 dark:text-slate-500 text-xs">Uploading...</div>';
                    const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
                    if (res.success) { showToast("Uploaded!"); openPaymentModal(currentSaleId); } 
                    else { alert(res.message); renderProofs(); }
                } catch (e) { alert("Upload failed."); renderProofs(); }
            }
        };

        window.closeModal = function() {
            modalContent.classList.remove('scale-100'); 
            modalContent.classList.add('scale-95');
            setTimeout(() => { modal.classList.add('hidden'); modal.classList.remove('flex'); }, 200);
        };

        window.payFull = function() { 
            document.getElementById('pay-amount').value = currentBalance.toFixed(2); 
        };

        window.openPaymentModal = async function(saleId) {
            currentSaleId = saleId; 
            switchTab('history'); 
            document.getElementById('form-sale-id').value = saleId;
            document.getElementById('paymentForm').reset(); 
            document.getElementById('detailedRefundForm').reset();
            
            const today = new Date(); 
            const offset = today.getTimezoneOffset() * 60000; 
            fDate.value = (new Date(today - offset)).toISOString().split('T')[0];

            modal.classList.remove('hidden'); 
            modal.classList.add('flex');
            setTimeout(() => { modalContent.classList.remove('scale-95'); modalContent.classList.add('scale-100'); }, 10);

            try {
                const fd = new FormData(); 
                fd.append('action', 'get_invoice_details'); 
                fd.append('sale_id', saleId);
                const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
                
                if (res.success) {
                    const s = res.sale; 
                    proofsData = res.proofs || [];
                    
                    document.getElementById('modal-invoice-no').textContent = '#' + String(s.sale_id).padStart(5, '0');
                    document.getElementById('modal-client-name').textContent = s.client_name;
                    document.getElementById('modal-status').textContent = s.payment_status;
                    document.getElementById('modal-status').className = 'status-badge status-' + s.payment_status + ' text-sm';
                    
                    const tot = parseFloat(s.total_amount); 
                    const pd = parseFloat(s.amount_paid); 
                    currentBalance = tot - pd;
                    
                    document.getElementById('modal-total').textContent = '₱' + tot.toLocaleString('en-US',{minimumFractionDigits:2});
                    document.getElementById('modal-paid').textContent = '₱' + pd.toLocaleString('en-US',{minimumFractionDigits:2});
                    document.getElementById('modal-balance').textContent = '₱' + Math.abs(currentBalance).toLocaleString('en-US',{minimumFractionDigits:2});
                    
                    const normalFields = document.getElementById('normal-payment-fields');
                    const refundSection = document.getElementById('refund-section');
                    const amtInput = document.getElementById('pay-amount');

                    // Check if Overpaid (Needs Refund)
                    if (currentBalance < -0.01) {
                        document.getElementById('balance-label').textContent = "Overpaid (Refund Due)"; 
                        document.getElementById('balance-label').className = "text-sm font-bold text-orange-500 dark:text-orange-400 uppercase tracking-wider"; 
                        document.getElementById('modal-balance').className = "text-2xl font-black font-mono text-orange-500 dark:text-orange-400";
                        
                        normalFields.classList.add('hidden'); 
                        refundSection.classList.remove('hidden'); 
                        refundSection.classList.add('flex');
                        document.getElementById('refund-amount-display').textContent = '₱' + Math.abs(currentBalance).toLocaleString('en-US',{minimumFractionDigits:2});
                    
                    } 
                    // Check if Fully Paid or Refunded
                    else if (s.payment_status === 'Paid' || s.payment_status === 'Refunded') {
                        document.getElementById('balance-label').textContent = "Remaining Balance"; 
                        document.getElementById('balance-label').className = "text-sm font-bold text-gray-500 dark:text-slate-400 uppercase tracking-wider"; 
                        document.getElementById('modal-balance').className = "text-2xl font-black font-mono text-gray-500 dark:text-slate-400";
                        
                        normalFields.classList.remove('hidden'); 
                        refundSection.classList.add('hidden'); 
                        refundSection.classList.remove('flex');
                        document.getElementById('saveBtn').style.display = 'none'; 
                        amtInput.disabled = true;
                    
                    } 
                    // Regular Pending / Partial Payment
                    else {
                        document.getElementById('balance-label').textContent = "Remaining Balance"; 
                        document.getElementById('balance-label').className = "text-sm font-bold text-red-500 dark:text-red-400 uppercase tracking-wider"; 
                        document.getElementById('modal-balance').className = "text-2xl font-black font-mono text-red-600 dark:text-red-400";
                        
                        normalFields.classList.remove('hidden'); 
                        refundSection.classList.add('hidden'); 
                        refundSection.classList.remove('flex');
                        document.getElementById('saveBtn').style.display = 'flex'; 
                        amtInput.disabled = false; 
                        amtInput.max = currentBalance.toFixed(2); 
                        amtInput.value = currentBalance.toFixed(2);
                    }

                    // Render Items List
                    const itemsTbody = document.getElementById('modal-items-list'); 
                    itemsTbody.innerHTML = '';
                    
                    if(res.items && res.items.length > 0) {
                        res.items.forEach(item => {
                            const isBulk = ['kg', 'g', 'liter', 'bottle'].includes(item.unit.toLowerCase());
                            const dispQty = isBulk ? parseFloat(item.quantity).toFixed(2) : parseInt(item.quantity);
                            
                            itemsTbody.innerHTML += `
                                <tr class="hover:bg-gray-50 dark:hover:bg-slate-800/50 transition">
                                    <td class="p-2 pl-3">
                                        <div class="font-bold text-gray-800 dark:text-slate-200">${item.name}</div>
                                    </td>
                                    <td class="p-2 text-right">
                                        <span class="bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300 px-2 py-0.5 rounded font-mono">${dispQty} <span class="text-[10px]">${item.unit}</span></span>
                                    </td>
                                    <td class="p-2 text-right font-mono text-gray-500 dark:text-slate-400">₱${parseFloat(item.price).toLocaleString('en-US',{minimumFractionDigits:2})}</td>
                                    <td class="p-2 pr-3 text-right font-mono font-bold text-gray-800 dark:text-slate-200">₱${parseFloat(item.subtotal).toLocaleString('en-US',{minimumFractionDigits:2})}</td>
                                </tr>
                            `;
                        });
                    } else { 
                        itemsTbody.innerHTML = '<tr><td colspan="4" class="p-4 text-center text-xs text-gray-400 dark:text-slate-500 italic">No items found for this invoice.</td></tr>'; 
                    }

                    // Render Payment History
                    const hv = document.getElementById('history-view');
                    if (res.payments.length === 0) {
                        hv.innerHTML = '<div class="text-center text-gray-400 dark:text-slate-500 text-xs py-4 italic">No payment history.</div>';
                    } else {
                        hv.innerHTML = '';
                        res.payments.forEach(p => {
                            const isRefund = parseFloat(p.amount) < 0; 
                            const amtColor = isRefund ? 'text-red-500 dark:text-red-400' : 'text-green-700 dark:text-green-400'; 
                            const pfx = isRefund ? '-' : '';
                            
                            hv.innerHTML += `
                                <div class="bg-white dark:bg-slate-800 p-3 rounded-lg border border-gray-100 dark:border-slate-700 shadow-sm flex flex-col hover:border-green-200 dark:hover:border-green-800/50 transition">
                                    <div class="flex justify-between items-center">
                                        <span class="font-mono font-bold ${amtColor}">${pfx}₱${Math.abs(p.amount).toLocaleString('en-US',{minimumFractionDigits:2})}</span>
                                        <span class="text-xs text-gray-400 dark:text-slate-500 font-mono">${new Date(p.payment_date).toLocaleDateString()}</span>
                                    </div>
                                    <div class="flex justify-between items-center mt-1">
                                        <span class="text-xs bg-gray-100 dark:bg-slate-700 px-2 py-0.5 rounded text-gray-600 dark:text-slate-300">${p.payment_method}</span>
                                        <span class="text-xs text-gray-400 dark:text-slate-500">${p.reference_no || ''}</span>
                                    </div>
                                    ${p.notes ? `<div class="mt-2 text-xs text-gray-500 dark:text-slate-400 italic border-t pt-1 border-gray-100 dark:border-slate-700">"${p.notes}"</div>` : ''}
                                </div>
                            `;
                        });
                    }
                }
            } catch(e) { console.error(e); }
        };

        // --- SUBMIT PAYMENT ---
        document.getElementById('paymentForm').addEventListener('submit', async (e) => {
            e.preventDefault(); 
            const amt = parseFloat(document.getElementById('pay-amount').value);
            
            if(amt > currentBalance + 0.01) { alert("Amount exceeds balance."); return; }
            if(!confirm("Confirm Payment?")) return;
            
            try {
                const res = await fetch('', { method: 'POST', body: new FormData(e.target) }).then(r => r.json());
                if(res.success) { showToast("Saved!"); setTimeout(() => location.reload(), 1000); } 
                else { alert(res.message); }
            } catch(e) { alert("Error"); }
        });

        // --- EXPLICITLY BIND DETAILED REFUND ACTION TO WINDOW ---
        window.issueRefund = async function() {
            if(!confirm("Are you sure you want to officially issue this refund to the customer?")) return;
            
            const method = document.getElementById('refund-method').value;
            const ref = document.getElementById('refund-ref').value;
            const notes = document.getElementById('refund-notes').value;

            const fd = new FormData();
            fd.append('action', 'issue_refund');
            fd.append('sale_id', currentSaleId);
            fd.append('refund_amount', Math.abs(currentBalance));
            fd.append('method', method);
            fd.append('reference', ref);
            fd.append('notes', notes);
            fd.append('csrf_token', csrfToken); 

            try {
                const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
                if(res.success) { 
                    showToast("Refund Processed!"); 
                    setTimeout(() => location.reload(), 1000); 
                } 
                else { alert(res.message); }
            } catch(e) { 
                console.error(e); // Added to console to help track future potential issues
                alert("Error issuing refund."); 
            }
        };
    </script>
</body>
</html>