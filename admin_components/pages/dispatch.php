<?php
// C:\xampp\htdocs\RalphPHP\admin_components\pages\dispatch.php

header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1
header("Pragma: no-cache"); // HTTP 1.0
header("Expires: 0"); // Proxies
if (session_status() == PHP_SESSION_NONE) { session_start(); }
ob_start();
ini_set('display_errors', 0);
ini_set('log_errors', 1);

include_once '../includes/db_connection.php';

// --- DATABASE AUTO-PATCHER ---
// Fixes the issue where payment_status rejects the "Replacement #..." tag
@$conn->query("ALTER TABLE sales MODIFY COLUMN payment_status VARCHAR(100) DEFAULT 'Pending'");

// --- AUDIT HELPER (CRITICAL FOR LOGGING) ---
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

// --- BACKEND HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean(); header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    // 1. ADD DRIVER
    if ($action === 'add_driver') {
        $name = trim($_POST['driver_name']);
        $plate = trim($_POST['vehicle_plate']);
        if ($name) {
            $stmt = $conn->prepare("INSERT INTO drivers (driver_name, vehicle_plate) VALUES (?, ?)");
            $stmt->bind_param("ss", $name, $plate);
            if($stmt->execute()) {
                if (function_exists('log_audit_action')) log_audit_action('Add Driver', 'Logistics', "Added new driver: $name ($plate)");
                echo json_encode(['success' => true, 'message' => 'Driver added!']);
            }
            else echo json_encode(['success' => false, 'message' => 'DB Error']);
        }
        exit;
    }

    // 2. DELETE DRIVER
    if ($action === 'delete_driver') {
        $id = intval($_POST['driver_id']);
        
        // Fetch name before deleting for the audit log
        $d = $conn->query("SELECT * FROM drivers WHERE driver_id = $id")->fetch_assoc();
        
        if ($d) {
            $conn->query("DELETE FROM drivers WHERE driver_id = $id");
            if (function_exists('log_audit_action')) log_audit_action('Delete Driver', 'Logistics', "Deleted driver: {$d['driver_name']} ({$d['vehicle_plate']})");
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // 3. ASSIGN DRIVER (Dispatch)
    if ($action === 'assign_driver') {
        $sale_id = intval($_POST['sale_id']);
        $driver_id = intval($_POST['driver_id']); 
        
        $d = $conn->query("SELECT * FROM drivers WHERE driver_id = $driver_id")->fetch_assoc();
        
        if ($sale_id && $d) {
            $stmt = $conn->prepare("UPDATE sales SET order_status = 'Out for Delivery', driver_name = ?, vehicle_plate = ?, dispatched_at = NOW() WHERE sale_id = ?");
            $stmt->bind_param("ssi", $d['driver_name'], $d['vehicle_plate'], $sale_id);
            if ($stmt->execute()) {
                if (function_exists('log_audit_action')) log_audit_action('Dispatch Order', 'Logistics', "Dispatched Order #$sale_id via {$d['driver_name']} ({$d['vehicle_plate']})");
                echo json_encode(['success' => true]);
            }
            else echo json_encode(['success' => false, 'message' => 'DB Error']);
        } else echo json_encode(['success' => false, 'message' => 'Driver not found']);
        exit;
    }
    
    // 4. MARK COMPLETED (Auto-Timestamp)
    if ($action === 'mark_delivered') {
        $sale_id = intval($_POST['sale_id']);
        if ($sale_id) {
            $stmt = $conn->prepare("UPDATE sales SET order_status = 'Completed', delivered_at = NOW() WHERE sale_id = ?");
            $stmt->bind_param("i", $sale_id);
            if ($stmt->execute()) {
                if (function_exists('log_audit_action')) log_audit_action('Complete Order', 'Logistics', "Marked Order #$sale_id as Delivered.");
                echo json_encode(['success' => true]);
            }
        }
        exit;
    }

    // 5. UPDATE DELIVERY DETAILS (Edit History)
    if ($action === 'update_delivery') {
        $sale_id = intval($_POST['sale_id']);
        $driver = trim($_POST['driver_name']);
        $plate = trim($_POST['vehicle_plate']);
        $date = $_POST['delivery_date']; // YYYY-MM-DD
        $time = $_POST['delivery_time']; // HH:MM
        
        // Combine Date and Time
        $final_datetime = date('Y-m-d H:i:s', strtotime("$date $time"));

        if ($sale_id && $driver) {
            $stmt = $conn->prepare("UPDATE sales SET driver_name = ?, vehicle_plate = ?, delivered_at = ? WHERE sale_id = ?");
            $stmt->bind_param("sssi", $driver, $plate, $final_datetime, $sale_id);
            if ($stmt->execute()) {
                if (function_exists('log_audit_action')) log_audit_action('Edit Delivery', 'Logistics', "Updated details for Order #$sale_id. Driver: $driver, Delivered At: $final_datetime");
                echo json_encode(['success' => true, 'message' => 'Record updated']);
            }
            else echo json_encode(['success' => false, 'message' => 'DB Error']);
        }
        exit;
    }
    
    // 6. FETCH DATA
    if ($action === 'fetch_data') {
        $ready = []; $active = []; $history = []; $drivers = []; $clients = [];

        // Get Active Clients for the Filter Dropdown
        $c_res = $conn->query("SELECT client_id, client_name FROM clients WHERE status='Active' ORDER BY client_name ASC");
        if ($c_res) while($c = $c_res->fetch_assoc()) $clients[] = $c;

        // A. Get Drivers
        $d_res = $conn->query("SELECT * FROM drivers ORDER BY driver_name ASC");
        if ($d_res) while($d = $d_res->fetch_assoc()) $drivers[] = $d;

        // Helper for Items string
        function getItems($conn, $sale_id) {
            $items = [];
            $q = $conn->query("SELECT si.quantity, p.name, p.unit FROM sales_items si JOIN products p ON si.product_id = p.product_id WHERE si.sale_id = $sale_id");
            if($q) while($row = $q->fetch_assoc()) { 
                $unit = $row['unit'] ?? 'pcs';
                $isBulk = in_array($unit, ['kg', 'g', 'liter', 'bottle']);
                $qty = $isBulk ? number_format((float)$row['quantity'], 2) : number_format((float)$row['quantity'], 0);
                $items[] = "$qty $unit x " . $row['name']; 
            }
            return implode(', ', $items);
        }

        // B. Fetch Ready
        $sql = "SELECT s.*, c.client_name FROM sales s LEFT JOIN clients c ON s.client_id = c.client_id WHERE s.order_status = 'Packed' ORDER BY s.sale_id ASC";
        $res = $conn->query($sql);
        if($res) while($r = $res->fetch_assoc()) {
            $r['item_summary'] = getItems($conn, $r['sale_id']);
            $r['formatted_date'] = date('M d, Y', strtotime($r['created_at'] ?? 'now'));
            $r['client_name'] = $r['client_name'] ?? 'Unknown Client';
            $ready[] = $r;
        }

        // C. Fetch Active
        $sql2 = "SELECT s.*, c.client_name FROM sales s LEFT JOIN clients c ON s.client_id = c.client_id WHERE s.order_status = 'Out for Delivery' ORDER BY s.sale_id DESC";
        $res2 = $conn->query($sql2);
        if($res2) while($r = $res2->fetch_assoc()) {
            $r['item_summary'] = getItems($conn, $r['sale_id']);
            $r['client_name'] = $r['client_name'] ?? 'Unknown Client';
            $r['driver_name'] = $r['driver_name'] ?? 'Unassigned';
            $r['vehicle_plate'] = $r['vehicle_plate'] ?? 'N/A';
            $disp = $r['dispatched_at'] ?? null;
            $r['time_ago'] = $disp ? date('h:i A', strtotime($disp)) : 'Just now';
            $active[] = $r;
        }

        // D. Fetch History (All completed orders to allow JS filtering)
        $sql3 = "SELECT s.*, c.client_name FROM sales s LEFT JOIN clients c ON s.client_id = c.client_id WHERE s.order_status = 'Completed' ORDER BY s.delivered_at DESC LIMIT 200";
        $res3 = $conn->query($sql3);
        if($res3) while($r = $res3->fetch_assoc()) {
            $r['item_summary'] = getItems($conn, $r['sale_id']);
            $r['client_name'] = $r['client_name'] ?? 'Unknown';
            $r['driver_name'] = $r['driver_name'] ?? 'Unassigned';
            $r['vehicle_plate'] = $r['vehicle_plate'] ?? '';
            $ts = strtotime($r['delivered_at'] ?? 'now');
            $r['timestamp'] = $ts; // Easy sorting
            $r['delivered_date_raw'] = date('Y-m-d', $ts);
            $r['delivered_time_raw'] = date('H:i', $ts);
            $r['delivered_pretty'] = date('M d, Y h:i A', $ts);
            $history[] = $r;
        }
        
        $today = date('Y-m-d');
        $delivered_count = $conn->query("SELECT COUNT(*) as c FROM sales WHERE order_status = 'Completed' AND DATE(delivered_at) = '$today'")->fetch_assoc()['c'];
        
        echo json_encode([
            'success' => true, 'ready' => $ready, 'active' => $active, 'history' => $history, 'drivers' => $drivers, 'clients' => $clients,
            'stats' => ['pending' => count($ready), 'active' => count($active), 'delivered' => $delivered_count]
        ]);
        exit;
    }
}
ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <meta charset="UTF-8">
    <title>FreshFlow - Logistics</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
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
        .font-heading { font-family: 'Inter', sans-serif; }
        
        .custom-scroll::-webkit-scrollbar { width: 6px; background: transparent; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 4px; }
        .dark .custom-scroll::-webkit-scrollbar-thumb { background: #334155; }
        
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        .animate-card { animation: fadeIn 0.3s ease-out forwards; }

        /* --- PRINT CSS FIX --- */
        @media print {
            @page { margin: 0; }
            body > *:not(#manifestArea) { display: none !important; }
            #manifestArea { 
                display: block !important; 
                position: absolute; 
                top: 0; 
                left: 0; 
                width: 100%; 
                height: auto; 
                background: white; 
                z-index: 9999;
                padding: 20px;
            }
            .no-print { display: none !important; }
        }
    </style>
   <script>
    window.onpageshow = function(event) {
        if (event.persisted) {
            window.location.reload();
        }
    };

    window.onbeforeunload = function() {
        document.body.innerHTML = "";       
        document.body.style.backgroundColor = document.documentElement.classList.contains('dark') ? "#000" : "#F8F5EE";
    };
</script>
</head>
<body class="flex h-screen overflow-hidden">
    
    <?php include '../includes/sidebar.php'; ?>

    <main class="ml-20 flex-1 flex flex-col h-screen overflow-hidden relative transition-all duration-300 p-6 md:p-8">
        
        <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 flex-shrink-0 no-print">
            <div>
                <h1 class="text-3xl font-bold text-[#1E3A1D] dark:text-white tracking-tight flex items-center gap-3">
                    <span class="material-icons text-3xl dark:text-green-400">local_shipping</span> Logistics & Dispatch
                </h1>
                <p class="text-sm text-gray-500 dark:text-slate-400 mt-1 ml-11">Assign drivers, print manifests, track history</p>
            </div>
            <div class="flex gap-2 mt-4 md:mt-0">
                <button onclick="toggleView('dashboard')" id="btn-dash" class="bg-[#1E3A1D] dark:bg-green-600 text-white px-5 py-2.5 rounded-lg text-sm font-bold shadow-lg flex items-center gap-2 transition transform active:scale-95">
                    <span class="material-icons text-sm">dashboard</span> Dashboard
                </button>
                <button onclick="toggleView('history')" id="btn-hist" class="bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-700 text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700 px-5 py-2.5 rounded-lg text-sm font-bold shadow-sm flex items-center gap-2 transition transform active:scale-95">
                    <span class="material-icons text-sm">history</span> History
                </button>
                <button onclick="openManageDrivers()" class="bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-700 text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700 px-5 py-2.5 rounded-lg text-sm font-bold shadow-sm flex items-center gap-2 transition transform active:scale-95">
                    <span class="material-icons text-sm">person_add</span> Drivers
                </button>
            </div>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8 text-center flex-shrink-0 no-print">
            <div class="bg-white dark:bg-slate-900/80 border border-orange-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-pointer group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(249,115,22,0.2)] dark:hover:shadow-[0_0_20px_rgba(249,115,22,0.3)] dark:hover:border-orange-400 transition-all duration-300">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-orange-600 dark:group-hover:text-orange-400 transition-colors">Queue</p>
                    <p class="font-heading text-3xl font-bold text-orange-600 dark:text-orange-400 mt-1 group-hover:scale-110 transition-transform origin-left font-mono" id="stat-pending">0</p>
                </div>
                <div class="p-3 bg-orange-100 dark:bg-orange-900/30 text-orange-600 dark:text-orange-400 rounded-lg group-hover:bg-orange-200 dark:group-hover:bg-orange-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">pending_actions</span>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900/80 border border-blue-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-pointer group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(59,130,246,0.2)] dark:hover:shadow-[0_0_20px_rgba(59,130,246,0.3)] dark:hover:border-blue-400 transition-all duration-300">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">In Transit</p>
                    <p class="font-heading text-3xl font-bold text-blue-600 dark:text-blue-400 mt-1 group-hover:scale-110 transition-transform origin-left font-mono" id="stat-active">0</p>
                </div>
                <div class="p-3 bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 rounded-lg group-hover:bg-blue-200 dark:group-hover:bg-blue-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">local_shipping</span>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900/80 border border-green-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-pointer group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(34,197,94,0.2)] dark:hover:shadow-[0_0_20px_rgba(34,197,94,0.3)] dark:hover:border-green-400 transition-all duration-300">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-green-600 dark:group-hover:text-green-400 transition-colors">Completed Today</p>
                    <p class="font-heading text-3xl font-bold text-green-700 dark:text-green-400 mt-1 group-hover:scale-110 transition-transform origin-left font-mono" id="stat-delivered">0</p>
                </div>
                <div class="p-3 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 rounded-lg group-hover:bg-green-200 dark:group-hover:bg-green-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">task_alt</span>
                </div>
            </div>
        </div>

        <div id="view-dashboard" class="flex-1 grid grid-cols-1 lg:grid-cols-2 gap-6 overflow-hidden mb-8 no-print">
            <div class="flex flex-col h-full bg-white dark:bg-slate-900/80 rounded-xl shadow border border-gray-200 dark:border-slate-800">
                <div class="p-4 border-b border-gray-100 dark:border-slate-800 bg-gray-50 dark:bg-slate-800 rounded-t-xl sticky top-0 z-10">
                    <h3 class="font-bold text-[#1E3A1D] dark:text-white flex items-center gap-2 tracking-tight"><span class="w-2.5 h-2.5 rounded-full bg-orange-400"></span> Ready to Dispatch</h3>
                </div>
                <div id="ready-list" class="flex-1 overflow-y-auto p-4 space-y-3 custom-scroll bg-gray-50/30 dark:bg-transparent"></div>
            </div>
            <div class="flex flex-col h-full bg-white dark:bg-slate-900/80 rounded-xl shadow border border-gray-200 dark:border-slate-800">
                <div class="p-4 border-b border-gray-100 dark:border-slate-800 bg-gray-50 dark:bg-slate-800 rounded-t-xl sticky top-0 z-10">
                    <h3 class="font-bold text-[#1E3A1D] dark:text-white flex items-center gap-2 tracking-tight"><span class="w-2.5 h-2.5 rounded-full bg-blue-500"></span> On The Road</h3>
                </div>
                <div id="active-list" class="flex-1 overflow-y-auto p-4 space-y-3 custom-scroll bg-gray-50/30 dark:bg-transparent"></div>
            </div>
        </div>

        <div id="view-history" class="hidden flex-1 overflow-hidden flex flex-col mb-8 no-print">
            
            <div class="bg-white dark:bg-slate-900/80 p-4 rounded-xl shadow-sm border border-gray-200 dark:border-slate-800 mb-6 flex flex-col md:flex-row justify-between items-center gap-4 flex-shrink-0">
                <div class="flex gap-2 w-full md:w-auto">
                    <select id="histTypeFilter" onchange="applyHistoryFilters()" class="p-2 text-sm border border-gray-200 dark:border-slate-700 rounded-lg focus:outline-none focus:border-[#1E3A1D] dark:focus:border-green-400 focus:ring-1 focus:ring-[#1E3A1D] dark:focus:ring-green-400 bg-white dark:bg-slate-800 cursor-pointer transition text-gray-700 dark:text-white font-medium">
                        <option value="all">All Orders</option>
                        <option value="normal">Normal Orders</option>
                        <option value="redelivery">🔴 Redeliveries</option>
                    </select>

                    <select id="histClientFilter" onchange="applyHistoryFilters()" class="p-2 text-sm border border-gray-200 dark:border-slate-700 rounded-lg focus:outline-none focus:border-[#1E3A1D] dark:focus:border-green-400 focus:ring-1 focus:ring-[#1E3A1D] dark:focus:ring-green-400 bg-white dark:bg-slate-800 cursor-pointer transition text-gray-700 dark:text-white">
                        <option value="all">All Clients</option>
                    </select>
                    
                    <select id="histSortFilter" onchange="applyHistoryFilters()" class="p-2 text-sm border border-gray-200 dark:border-slate-700 rounded-lg focus:outline-none focus:border-[#1E3A1D] dark:focus:border-green-400 focus:ring-1 focus:ring-[#1E3A1D] dark:focus:ring-green-400 bg-white dark:bg-slate-800 cursor-pointer transition text-gray-700 dark:text-white">
                        <option value="newest">Newest First</option>
                        <option value="oldest">Oldest First</option>
                    </select>
                </div>
                <div class="relative w-full md:w-64">
                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400"><span class="material-icons text-sm">search</span></span>
                    <input type="text" id="histSearchInput" onkeyup="applyHistoryFilters()" placeholder="Search Order # or Driver..." class="w-full pl-9 pr-3 py-2 text-sm border border-gray-200 dark:border-slate-700 rounded-lg focus:outline-none focus:border-[#1E3A1D] dark:focus:border-green-400 focus:ring-1 focus:ring-[#1E3A1D] dark:focus:ring-green-400 bg-white dark:bg-slate-800 text-gray-900 dark:text-white transition">
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900/80 rounded-xl shadow border border-gray-200 dark:border-slate-800 flex-1 overflow-hidden flex flex-col">
                <div class="overflow-y-auto flex-1 custom-scroll">
                    <table class="w-full text-left">
                        <thead class="bg-[#1E3A1D] dark:bg-slate-800 text-white text-xs uppercase font-bold sticky top-0 z-10">
                            <tr>
                                <th class="p-4 w-24">Order #</th>
                                <th class="p-4">Client</th>
                                <th class="p-4">Driver / Plate</th>
                                <th class="p-4">Delivered At</th>
                                <th class="p-4 text-right">Amount</th>
                                <th class="p-4 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="history-list" class="divide-y divide-gray-100 dark:divide-slate-800 text-sm text-gray-700 dark:text-gray-300"></tbody>
                    </table>
                </div>
            </div>
        </div>

    </main>

    <div id="assignModal" class="fixed inset-0 bg-[#1E3A1D] dark:bg-black bg-opacity-40 dark:bg-opacity-60 backdrop-blur-sm hidden z-50 flex justify-center items-center transition-opacity">
        <div class="bg-white dark:bg-slate-900 rounded-xl shadow-2xl w-full max-w-sm overflow-hidden border border-gray-200 dark:border-slate-700">
            <div class="bg-[#1E3A1D] dark:bg-slate-800 p-4 text-white flex justify-between items-center">
                <h3 class="font-bold flex items-center gap-2"><span class="material-icons text-sm">person_add</span> Dispatch Order</h3>
                <button onclick="closeModal('assignModal')" class="hover:text-gray-300"><span class="material-icons text-sm">close</span></button>
            </div>
            <div class="p-6">
                <p class="text-sm text-gray-500 dark:text-slate-400 mb-1 uppercase tracking-widest font-bold text-xs">Order Reference</p>
                <div class="text-3xl font-mono font-bold text-[#1E3A1D] dark:text-green-400 mb-6">#<span id="modal-sale-id"></span></div>
                <form id="assignForm" class="space-y-4">
                    <input type="hidden" name="action" value="assign_driver">
                    <input type="hidden" name="sale_id" id="form-sale-id">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Select Driver</label>
                        <select name="driver_id" id="driverSelect" required class="w-full p-2.5 border border-gray-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-gray-800 dark:text-white focus:border-[#1E3A1D] dark:focus:border-green-400 focus:ring-1 focus:ring-[#1E3A1D] dark:focus:ring-green-400 outline-none"></select>
                    </div>
                    <button type="submit" class="w-full bg-[#1E3A1D] dark:bg-green-600 hover:bg-[#2a4e29] dark:hover:bg-green-500 text-white py-3 rounded-lg font-bold shadow-md transition transform hover:-translate-y-0.5 mt-2">Confirm Assignment</button>
                </form>
            </div>
        </div>
    </div>

    <div id="editHistoryModal" class="fixed inset-0 bg-[#1E3A1D] dark:bg-black bg-opacity-40 dark:bg-opacity-60 backdrop-blur-sm hidden z-50 flex justify-center items-center transition-opacity">
        <div class="bg-white dark:bg-slate-900 rounded-xl shadow-2xl w-full max-w-sm overflow-hidden border border-gray-200 dark:border-slate-700">
            <div class="bg-[#1E3A1D] dark:bg-slate-800 p-4 text-white flex justify-between items-center">
                <h3 class="font-bold flex items-center gap-2"><span class="material-icons text-sm">edit</span> Edit Delivery Details</h3>
                <button onclick="closeModal('editHistoryModal')" class="hover:text-gray-300"><span class="material-icons text-sm">close</span></button>
            </div>
            <div class="p-6">
                <p class="text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-4 tracking-widest">Correcting Record For <span id="edit-sale-id-disp" class="font-bold text-[#1E3A1D] dark:text-green-400 font-mono text-sm"></span></p>
                <form id="editHistoryForm" class="space-y-4">
                    <input type="hidden" name="action" value="update_delivery"><input type="hidden" name="sale_id" id="edit-sale-id">
                    <div><label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Driver Name</label><input type="text" name="driver_name" id="edit-driver" class="w-full p-2 border border-gray-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-gray-800 dark:text-white focus:border-[#1E3A1D] dark:focus:border-green-400 focus:ring-1 focus:ring-[#1E3A1D] dark:focus:ring-green-400 outline-none"></div>
                    <div><label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Vehicle Plate</label><input type="text" name="vehicle_plate" id="edit-plate" class="w-full p-2 border border-gray-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-gray-800 dark:text-white focus:border-[#1E3A1D] dark:focus:border-green-400 focus:ring-1 focus:ring-[#1E3A1D] dark:focus:ring-green-400 outline-none"></div>
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Date</label><input type="date" name="delivery_date" id="edit-date" class="w-full p-2 border border-gray-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-gray-800 dark:text-white focus:border-[#1E3A1D] dark:focus:border-green-400 focus:ring-1 focus:ring-[#1E3A1D] dark:focus:ring-green-400 outline-none"></div>
                        <div><label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Time</label><input type="time" name="delivery_time" id="edit-time" class="w-full p-2 border border-gray-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-gray-800 dark:text-white focus:border-[#1E3A1D] dark:focus:border-green-400 focus:ring-1 focus:ring-[#1E3A1D] dark:focus:ring-green-400 outline-none"></div>
                    </div>
                    <button type="submit" class="w-full bg-[#1E3A1D] dark:bg-green-600 hover:bg-[#2a4e29] dark:hover:bg-green-500 text-white py-3 rounded-lg font-bold shadow-md transition transform hover:-translate-y-0.5 mt-2">Save Corrections</button>
                </form>
            </div>
        </div>
    </div>

    <div id="manageDriversModal" class="fixed inset-0 bg-[#1E3A1D] dark:bg-black bg-opacity-40 dark:bg-opacity-60 backdrop-blur-sm hidden z-50 flex justify-center items-center transition-opacity">
        <div class="bg-white dark:bg-slate-900 rounded-xl shadow-2xl w-full max-w-md overflow-hidden h-[500px] flex flex-col border border-gray-200 dark:border-slate-700">
            <div class="bg-[#1E3A1D] dark:bg-slate-800 p-4 text-white flex justify-between items-center">
                <h3 class="font-bold flex items-center gap-2"><span class="material-icons text-sm">badge</span> Manage Drivers</h3>
                <button onclick="closeModal('manageDriversModal')" class="hover:text-gray-300"><span class="material-icons text-sm">close</span></button>
            </div>
            <div class="p-4 border-b border-gray-100 dark:border-slate-800 bg-gray-50 dark:bg-slate-800/50">
                <form id="addDriverForm" class="flex gap-2">
                    <input type="hidden" name="action" value="add_driver">
                    <input type="text" name="driver_name" placeholder="Driver Name" required class="flex-1 p-2 border border-gray-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-gray-800 dark:text-white text-sm focus:border-[#1E3A1D] dark:focus:border-green-400 focus:ring-1 focus:ring-[#1E3A1D] dark:focus:ring-green-400 outline-none">
                    <input type="text" name="vehicle_plate" placeholder="Plate #" required class="w-24 p-2 border border-gray-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-gray-800 dark:text-white text-sm focus:border-[#1E3A1D] dark:focus:border-green-400 focus:ring-1 focus:ring-[#1E3A1D] dark:focus:ring-green-400 outline-none">
                    <button type="submit" class="bg-[#1E3A1D] dark:bg-green-600 text-white p-2 rounded-lg hover:bg-[#2a4e29] dark:hover:bg-green-500 transition shadow-sm"><span class="material-icons text-sm">add</span></button>
                </form>
            </div>
            <div class="flex-1 overflow-y-auto p-4 space-y-2 custom-scroll bg-gray-50/50 dark:bg-transparent" id="driverList"></div>
        </div>
    </div>

    <div id="manifestArea" class="hidden bg-white">
        <div class="max-w-[800px] mx-auto border-2 border-black p-8 mt-10">
            <div class="flex justify-between items-center border-b-2 border-black pb-4 mb-6">
                <div class="text-3xl font-bold uppercase tracking-widest">Delivery Receipt</div>
                <div class="text-right">
                    <div class="font-bold text-xl">FreshFlow</div>
                    <div class="text-sm text-gray-500">Logistics Department</div>
                </div>
            </div>
            
            <div class="grid grid-cols-2 gap-8 mb-8 text-sm font-mono border border-black p-4">
                <div>
                    <p class="mb-2"><span class="font-bold w-20 inline-block">DRIVER:</span> <span id="print-driver" class="border-b border-black w-40 inline-block"></span></p>
                    <p><span class="font-bold w-20 inline-block">PLATE:</span> <span id="print-plate" class="border-b border-black w-40 inline-block"></span></p>
                </div>
                <div class="text-right">
                    <p class="mb-2"><span class="font-bold">DATE:</span> <span id="print-date"></span></p>
                    <p><span class="font-bold">TIME:</span> <span id="print-time"></span></p>
                </div>
            </div>

            <table class="w-full border-collapse border border-black text-sm mb-8">
                <thead class="bg-gray-200">
                    <tr>
                        <th class="border border-black p-2 text-left w-24">Order ID</th>
                        <th class="border border-black p-2 text-left w-48">Client</th>
                        <th class="border border-black p-2 text-left">Items / Details</th>
                        <th class="border border-black p-2 w-32 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody id="manifest-table-body"></tbody>
            </table>

            <div class="flex justify-between pt-12 text-sm">
                <div class="text-center">
                    <div class="border-t border-black w-48 pt-1">Driver Signature</div>
                </div>
                <div class="text-center">
                    <div class="border-t border-black w-48 pt-1">Received By</div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Store history array globally so we can filter/sort it
        let globalHistoryData = [];

        // --- UI LOGIC ---
        function toggleView(view) {
            const dash = document.getElementById('view-dashboard');
            const hist = document.getElementById('view-history');
            const btnDash = document.getElementById('btn-dash');
            const btnHist = document.getElementById('btn-hist');

            if(view === 'dashboard') {
                dash.classList.remove('hidden');
                hist.classList.add('hidden');
                btnDash.className = "bg-[#1E3A1D] dark:bg-green-600 text-white px-5 py-2.5 rounded-lg text-sm font-bold shadow-lg flex items-center gap-2 transition transform active:scale-95";
                btnHist.className = "bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-700 text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700 px-5 py-2.5 rounded-lg text-sm font-bold shadow-sm flex items-center gap-2 transition transform active:scale-95";
            } else {
                dash.classList.add('hidden');
                hist.classList.remove('hidden');
                btnHist.className = "bg-[#1E3A1D] dark:bg-green-600 text-white px-5 py-2.5 rounded-lg text-sm font-bold shadow-lg flex items-center gap-2 transition transform active:scale-95";
                btnDash.className = "bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-700 text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700 px-5 py-2.5 rounded-lg text-sm font-bold shadow-sm flex items-center gap-2 transition transform active:scale-95";
            }
        }

        async function fetchData() {
            try {
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'fetch_data' }) }).then(r => r.json());
                if(res.success) {
                    renderReady(res.ready);
                    renderActive(res.active);
                    renderDrivers(res.drivers);
                    populateClientFilter(res.clients);
                    
                    globalHistoryData = res.history;
                    applyHistoryFilters(); // Renders the history table

                    document.getElementById('stat-pending').innerText = res.stats.pending;
                    document.getElementById('stat-active').innerText = res.stats.active;
                    document.getElementById('stat-delivered').innerText = res.stats.delivered;
                }
            } catch(e) { console.error(e); }
        }

        function populateClientFilter(clients) {
            const select = document.getElementById('histClientFilter');
            const currentVal = select.value;
            select.innerHTML = '<option value="all">All Clients</option>';
            clients.forEach(c => {
                select.innerHTML += `<option value="${c.client_name}">${c.client_name}</option>`;
            });
            // Try to restore previous selection
            if(currentVal && Array.from(select.options).some(o => o.value === currentVal)) {
                select.value = currentVal;
            }
        }

        function renderDrivers(drivers) {
            const driverSelect = document.getElementById('driverSelect');
            const driverList = document.getElementById('driverList');
            
            driverSelect.innerHTML = '<option value="" disabled selected>Select Driver...</option>';
            driverList.innerHTML = '';
            
            drivers.forEach(d => {
                driverSelect.innerHTML += `<option value="${d.driver_id}">${d.driver_name} (${d.vehicle_plate})</option>`;
                driverList.innerHTML += `
                    <div class="flex justify-between items-center bg-white dark:bg-slate-800 p-3 rounded-lg border border-gray-200 dark:border-slate-700 shadow-sm transition hover:shadow-md">
                        <div>
                            <p class="font-bold text-sm text-gray-800 dark:text-white">${d.driver_name}</p>
                            <p class="text-xs text-gray-500 dark:text-slate-400 font-mono">${d.vehicle_plate}</p>
                        </div>
                        <button onclick="deleteDriver(${d.driver_id})" class="text-gray-400 dark:text-slate-500 hover:text-red-500 dark:hover:text-red-400 transition bg-gray-50 dark:bg-slate-700 hover:bg-red-50 dark:hover:bg-red-900/30 p-2 rounded-full"><span class="material-icons text-sm">delete</span></button>
                    </div>`;
            });
        }

        function renderReady(orders) {
            const list = document.getElementById('ready-list');
            if(orders.length === 0) { list.innerHTML = `<div class="flex flex-col items-center justify-center h-48 text-gray-400 dark:text-slate-500"><span class="material-icons text-4xl mb-2 text-gray-300 dark:text-slate-600">inventory</span><p class="text-sm">No packed orders ready.</p></div>`; return; }
            list.innerHTML = '';
            orders.forEach(o => {
                // REDELIVERY FALLBACK DETECTION
                let isReplacement = false;
                let parentRef = '';
                if (o.payment_status && o.payment_status.includes('Replacement')) {
                    isReplacement = true;
                    parentRef = o.payment_status;
                } else if (parseFloat(o.total_amount) === 0.00) {
                    isReplacement = true;
                    parentRef = "Replacement Order";
                }

                const badgeHTML = isReplacement ? `<div class="mt-1 bg-purple-100 dark:bg-purple-900/40 text-purple-800 dark:text-purple-300 border border-purple-200 dark:border-purple-800/50 px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide w-max shadow-sm">🔴 REDELIVERY (${parentRef})</div>` : '';
                const idBadgeColor = isReplacement ? 'bg-purple-100 dark:bg-purple-900/40 text-purple-800 dark:text-purple-300 border-purple-200 dark:border-purple-800/50' : 'bg-green-50 dark:bg-green-900/20 text-[#1E3A1D] dark:text-green-400 border-green-100 dark:border-green-800/50';
                const rowBgClass = isReplacement ? 'bg-purple-50/30 dark:bg-purple-900/10' : 'bg-white dark:bg-slate-800/50';

                list.innerHTML += `
                <div class="${rowBgClass} p-4 rounded-xl shadow-sm border border-gray-200 dark:border-slate-700 hover:border-[#1E3A1D] dark:hover:border-green-400 hover:shadow-md transition animate-card mb-3">
                    <div class="flex justify-between items-start mb-2">
                        <div class="flex flex-col gap-1">
                            <span class="font-mono font-bold ${idBadgeColor} px-2 py-0.5 rounded text-sm border w-max">#${String(o.sale_id).padStart(5, '0')}</span>
                            ${badgeHTML}
                        </div>
                        <span class="text-xs text-gray-400 dark:text-slate-500">${o.formatted_date}</span>
                    </div>
                    <h4 class="font-bold text-gray-800 dark:text-white text-sm mb-1">${o.client_name}</h4>
                    <p class="text-xs text-gray-500 dark:text-slate-400 bg-gray-50/50 dark:bg-slate-900/50 p-2 rounded border border-gray-100 dark:border-slate-700 mb-4 line-clamp-2 leading-relaxed">${o.item_summary}</p>
                    <button onclick="openAssignModal(${o.sale_id})" class="w-full bg-[#1E3A1D] dark:bg-green-600 text-white hover:bg-[#2a4e29] dark:hover:bg-green-500 py-2 rounded-lg text-xs font-bold transition flex justify-center items-center gap-2 shadow-sm">
                        <span>Assign Driver</span> <span class="material-icons text-xs">arrow_forward</span>
                    </button>
                </div>`;
            });
        }

        function renderActive(orders) {
            const list = document.getElementById('active-list');
            if(orders.length === 0) { list.innerHTML = `<div class="flex flex-col items-center justify-center h-48 text-gray-400 dark:text-slate-500"><span class="material-icons text-4xl mb-2 text-gray-300 dark:text-slate-600">local_shipping</span><p class="text-sm">No active deliveries.</p></div>`; return; }
            list.innerHTML = '';
            orders.forEach(o => {
                const isThirdParty = o.driver_name.includes('Lalamove') || o.driver_name.includes('Grab');
                const badgeColor = isThirdParty ? 'bg-orange-100 dark:bg-orange-900/30 text-orange-700 dark:text-orange-400 border-orange-200 dark:border-orange-800/50' : 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 border-blue-200 dark:border-blue-800/50';
                
                // REDELIVERY FALLBACK DETECTION
                let isReplacement = false;
                let parentRef = '';
                if (o.payment_status && o.payment_status.includes('Replacement')) {
                    isReplacement = true;
                    parentRef = o.payment_status;
                } else if (parseFloat(o.total_amount) === 0.00) {
                    isReplacement = true;
                    parentRef = "Replacement Order";
                }

                const replacementBadge = isReplacement ? `<div class="mt-1 bg-purple-100 dark:bg-purple-900/40 text-purple-800 dark:text-purple-300 border border-purple-200 dark:border-purple-800/50 px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide w-max shadow-sm">🔴 REDELIVERY (${parentRef})</div>` : '';
                const idColor = isReplacement ? 'text-purple-800 dark:text-purple-300 bg-purple-100 dark:bg-purple-900/40 px-2 py-0.5 rounded border border-purple-200 dark:border-purple-800/50' : 'text-[#1E3A1D] dark:text-green-400';
                const borderColor = isReplacement ? 'border-purple-500' : 'border-[#1E3A1D] dark:border-green-500';
                const printTotal = isReplacement ? 'Pre-paid' : o.total_amount;
                const rowBgClass = isReplacement ? 'bg-purple-50/30 dark:bg-purple-900/10' : 'bg-white dark:bg-slate-800/50';

                list.innerHTML += `
                <div class="${rowBgClass} p-4 rounded-xl shadow-sm border-l-4 ${borderColor} border-y border-r border-y-gray-200 border-r-gray-200 dark:border-y-slate-700 dark:border-r-slate-700 hover:shadow-md transition animate-card mb-3">
                    <div class="flex justify-between items-start mb-2">
                        <div class="flex flex-col gap-1">
                            <div class="flex items-center gap-2">
                                <span class="font-mono font-bold ${idColor} text-sm">#${String(o.sale_id).padStart(5, '0')}</span>
                                <span class="text-[10px] uppercase font-bold text-gray-400 dark:text-slate-500">Dispatched ${o.time_ago}</span>
                            </div>
                            ${replacementBadge}
                        </div>
                        <button onclick="printManifest('${o.driver_name}', '${o.vehicle_plate}', '${String(o.sale_id).padStart(5,'0')}', '${o.client_name}', '${o.item_summary.replace(/'/g, "")}', '${printTotal}')" class="text-gray-400 hover:text-[#1E3A1D] dark:text-slate-500 dark:hover:text-green-400 transition bg-gray-50 hover:bg-gray-100 dark:bg-slate-700 dark:hover:bg-slate-600 p-1.5 rounded-full"><span class="material-icons text-sm">print</span></button>
                    </div>
                    <h4 class="font-bold text-gray-800 dark:text-white text-sm mb-3">${o.client_name}</h4>
                    <div class="flex items-center gap-2 text-xs mb-4 ${badgeColor} p-2 rounded-lg w-fit border shadow-sm">
                        <span class="material-icons text-[14px]">local_shipping</span>
                        <span class="font-bold">${o.driver_name}</span>
                        <span class="text-opacity-50">|</span>
                        <span class="font-mono">${o.vehicle_plate}</span>
                    </div>
                    <button onclick="markDone(${o.sale_id})" class="w-full bg-green-600 hover:bg-green-700 dark:bg-green-600 dark:hover:bg-green-500 text-white py-2 rounded-lg text-xs font-bold transition flex justify-center items-center gap-1 shadow-sm"><span class="material-icons text-xs">check</span> Mark Delivered</button>
                </div>`;
            });
        }

        // JS HISTORY FILTERING
        function applyHistoryFilters() {
            const searchTerm = document.getElementById('histSearchInput').value.toLowerCase();
            const clientFilter = document.getElementById('histClientFilter').value;
            const sortFilter = document.getElementById('histSortFilter').value;
            const typeFilter = document.getElementById('histTypeFilter').value;
            
            // 1. Filter
            let filtered = globalHistoryData.filter(h => {
                const searchString = `#${String(h.sale_id).padStart(5,'0')} ${h.driver_name} ${h.vehicle_plate} ${h.client_name}`.toLowerCase();
                const matchesSearch = searchString.includes(searchTerm);
                const matchesClient = clientFilter === 'all' || h.client_name === clientFilter;
                
                // REDELIVERY FALLBACK
                const isReplacement = (h.payment_status && h.payment_status.includes('Replacement')) || (parseFloat(h.total_amount) === 0.00);
                const type = isReplacement ? 'redelivery' : 'normal';
                const matchesType = typeFilter === 'all' || type === typeFilter;

                return matchesSearch && matchesClient && matchesType;
            });
            
            // 2. Sort
            filtered.sort((a, b) => {
                if (sortFilter === 'newest') return b.timestamp - a.timestamp;
                if (sortFilter === 'oldest') return a.timestamp - b.timestamp;
                return 0;
            });
            
            // 3. Render
            const list = document.getElementById('history-list');
            list.innerHTML = '';
            
            if (filtered.length === 0) {
                list.innerHTML = '<tr><td colspan="6" class="p-8 text-center text-gray-400 dark:text-slate-500 italic">No matching history records found.</td></tr>';
                return;
            }
            
            filtered.forEach(h => {
                const safeItems = h.item_summary.replace(/'/g, "\\'");
                const safeClient = h.client_name.replace(/'/g, "\\'");
                
                const isReplacement = (h.payment_status && h.payment_status.includes('Replacement')) || (parseFloat(h.total_amount) === 0.00);
                let parentRef = "Replacement Order";
                if (h.payment_status && h.payment_status.includes('Replacement')) {
                    parentRef = h.payment_status;
                }
                
                const badgeHTML = isReplacement ? `<br><span class="inline-block mt-1 bg-purple-100 dark:bg-purple-900/40 text-purple-800 dark:text-purple-300 border border-purple-200 dark:border-purple-800/50 px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide shadow-sm">🔴 REDELIVERY (${parentRef})</span>` : '';
                const amountHTML = isReplacement ? `<span class="text-purple-700 dark:text-purple-400 font-bold text-xs">₱0.00 (Pre-paid)</span>` : `₱${parseFloat(h.total_amount || 0).toLocaleString(undefined, {minimumFractionDigits: 2})}`;
                const idBadgeColor = isReplacement ? 'bg-purple-100 dark:bg-purple-900/40 text-purple-800 dark:text-purple-300 border-purple-200 dark:border-purple-800/50' : 'bg-gray-100 dark:bg-slate-700 text-[#1E3A1D] dark:text-green-400 border-gray-200 dark:border-slate-600';
                const printTotal = isReplacement ? 'Pre-paid' : h.total_amount;
                const rowBgClass = isReplacement ? 'bg-purple-50/20 dark:bg-purple-900/10' : '';

                list.innerHTML += `
                <tr class="hover:bg-gray-50 dark:hover:bg-slate-800/50 transition border-b border-gray-100 dark:border-slate-800 ${rowBgClass}">
                    <td class="p-4 font-mono font-bold text-[#1E3A1D]">
                        <span class="${idBadgeColor} border px-2 py-1 rounded">#${String(h.sale_id).padStart(5,'0')}</span>
                    </td>
                    <td class="p-4 font-bold text-gray-800 dark:text-white">${h.client_name} ${badgeHTML}</td>
                    <td class="p-4 text-gray-600 dark:text-slate-300 flex items-center gap-3">
                        <div class="bg-gray-100 dark:bg-slate-700 p-2 rounded-full"><span class="material-icons text-sm text-gray-500 dark:text-slate-400">local_shipping</span></div>
                        <div><p class="font-bold text-sm">${h.driver_name}</p><p class="text-xs text-gray-400 dark:text-slate-500 font-mono">${h.vehicle_plate}</p></div>
                    </td>
                    <td class="p-4 text-gray-500 dark:text-slate-400 text-sm">${h.delivered_pretty}</td>
                    <td class="p-4 text-right font-bold text-[#1E3A1D] dark:text-green-400 font-mono">${amountHTML}</td>
                    <td class="p-4 text-center">
                        <div class="flex justify-center gap-2">
                            <button onclick="openEditHistory(${h.sale_id}, '${h.driver_name}', '${h.vehicle_plate}', '${h.delivered_date_raw}', '${h.delivered_time_raw}')" class="text-blue-500 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300 hover:bg-blue-50 dark:hover:bg-blue-900/30 p-2 rounded-full transition" title="Edit Details"><span class="material-icons text-sm">edit</span></button>
                            <button onclick="printManifest('${h.driver_name}', '${h.vehicle_plate}', '${h.sale_id}', '${safeClient}', '${safeItems}', '${printTotal}')" class="text-gray-500 hover:text-[#1E3A1D] dark:text-slate-400 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-slate-700 p-2 rounded-full transition" title="Reprint"><span class="material-icons text-sm">print</span></button>
                        </div>
                    </td>
                </tr>`;
            });
        }

        // --- ACTIONS ---
        function openAssignModal(id) { document.getElementById('modal-sale-id').textContent = String(id).padStart(5, '0'); document.getElementById('form-sale-id').value = id; document.getElementById('assignModal').classList.remove('hidden'); }
        function openManageDrivers() { document.getElementById('manageDriversModal').classList.remove('hidden'); }
        function openEditHistory(id, driver, plate, date, time) {
            document.getElementById('edit-sale-id').value = id;
            document.getElementById('edit-sale-id-disp').textContent = String(id).padStart(5, '0');
            document.getElementById('edit-driver').value = driver;
            document.getElementById('edit-plate').value = plate;
            document.getElementById('edit-date').value = date;
            document.getElementById('edit-time').value = time;
            document.getElementById('editHistoryModal').classList.remove('hidden');
        }
        function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

        document.getElementById('assignForm').onsubmit = async (e) => { e.preventDefault(); await fetch('', { method: 'POST', body: new FormData(e.target) }); closeModal('assignModal'); fetchData(); };
        document.getElementById('editHistoryForm').onsubmit = async (e) => { e.preventDefault(); await fetch('', { method: 'POST', body: new FormData(e.target) }); closeModal('editHistoryModal'); fetchData(); };
        document.getElementById('addDriverForm').onsubmit = async (e) => { e.preventDefault(); const res = await fetch('', { method: 'POST', body: new FormData(e.target) }).then(r => r.json()); if(res.success) { e.target.reset(); fetchData(); } };
        async function deleteDriver(id) { if(confirm('Delete driver?')) { await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'delete_driver', driver_id: id }) }); fetchData(); } }
        async function markDone(id) { if(confirm('Confirm delivery complete?')) { await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'mark_delivered', sale_id: id }) }); fetchData(); } }

        function printManifest(driver, plate, id, client, items, total) {
            document.getElementById('print-driver').textContent = driver;
            document.getElementById('print-plate').textContent = plate;
            document.getElementById('print-date').textContent = new Date().toLocaleDateString();
            document.getElementById('print-time').textContent = new Date().toLocaleTimeString();
            
            const amountDisplay = total === 'Pre-paid' ? '₱0.00 (Pre-paid)' : `₱${parseFloat(total).toLocaleString(undefined, {minimumFractionDigits: 2})}`;

            document.getElementById('manifest-table-body').innerHTML = `
                <tr>
                    <td class="border border-black p-2 font-mono">#${id}</td>
                    <td class="border border-black p-2 font-bold">${client}</td>
                    <td class="border border-black p-2 text-xs italic">${items}</td>
                    <td class="border border-black p-2 font-bold text-right">${amountDisplay}</td>
                </tr>`;
            setTimeout(() => window.print(), 300);
        }

        fetchData(); setInterval(fetchData, 30000);
    </script>
</body>
</html>