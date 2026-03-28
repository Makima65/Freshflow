<?php
// C:\xampp\htdocs\RalphPHP\admin_components\pages\dashboard.php

session_start();

// 1. STRICT CACHE HEADERS
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1
header("Pragma: no-cache"); // HTTP 1.0
header("Expires: 0"); // Proxies

ob_start();

// TEMPORARILY ENABLE ERRORS TO PREVENT BLANK 500 SCREENS
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- DATABASE CONNECTION ---
include_once '../includes/db_connection.php';

// Check Login
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: ../admin_login.php");
    exit;
}

// --- CONFIGURATION ---
if (!defined('LOW_STOCK_THRESHOLD')) {
    define('LOW_STOCK_THRESHOLD', 10);
}

// CRASH-PROOF HELPER: Safely declare function
if (!function_exists('fetch_assoc_safe')) {
    function fetch_assoc_safe($conn, $sql) {
        try {
            $result = $conn->query($sql);
            if ($result) {
                return $result->fetch_assoc();
            }
        } catch (Throwable $e) {}
        return []; 
    }
}

// ==========================================
// NOTIFICATION TABLE AUTO-PATCHER
// ==========================================
try {
    if (isset($conn)) {
        $conn->query("CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY, 
            message TEXT NOT NULL, 
            alert_type VARCHAR(50) DEFAULT 'info', 
            link VARCHAR(255) NULL, 
            image_url VARCHAR(255) NULL, 
            is_read TINYINT(1) DEFAULT 0, 
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $colCheck = $conn->query("SHOW COLUMNS FROM notifications LIKE 'image_url'");
        if ($colCheck && $colCheck->num_rows == 0) {
            $conn->query("ALTER TABLE notifications ADD COLUMN image_url VARCHAR(255) NULL AFTER link");
        }
    }
} catch (Throwable $e) {}

// =================================================================
// SYSTEM AUTO-ALERTS (Runs automatically to check Stock & Expiry)
// =================================================================
try {
    $today = date('Y-m-d');
    if (isset($conn)) {
        $alert_query = $conn->query("SELECT p.product_id, p.name, p.image_url, p.expiration_date, pi.quantity 
                                     FROM products p 
                                     LEFT JOIN product_inventory pi ON p.product_id = pi.product_id
                                     WHERE p.status = 'Active'");
                                     
        if($alert_query) {
            while($prod = $alert_query->fetch_assoc()) {
                $msg = "";
                $type = "info";
                
                // 1. CHECK STOCK
                $stock = floatval($prod['quantity']);
                if($stock <= 0) {
                    $msg = "🚨 Out of Stock: {$prod['name']} is completely empty!";
                    $type = "danger";
                } elseif($stock <= LOW_STOCK_THRESHOLD) {
                    $msg = "⚠️ Low Stock: Only {$stock} left of {$prod['name']}.";
                    $type = "warning";
                }
                
                // 2. CHECK EXPIRATION
                if(!empty($prod['expiration_date']) && $prod['expiration_date'] != '0000-00-00') {
                    try {
                        $exp_date = new DateTime($prod['expiration_date']);
                        $now = new DateTime($today);
                        $diff = $now->diff($exp_date);
                        $days_left = (int)$diff->format('%R%a');
                        
                        if($days_left < 0) {
                            $msg = "❌ EXPIRED: {$prod['name']} expired on {$prod['expiration_date']}!";
                            $type = "danger";
                        } elseif($days_left >= 0 && $days_left <= 3) {
                            $msg = "⏳ Expiring Soon: {$prod['name']} expires in {$days_left} day(s)!";
                            $type = "warning";
                        }
                    } catch (Exception $e) {}
                }
                
                // 3. LOG ALERT
                if($msg !== "") {
                    $safe_msg = $conn->real_escape_string($msg);
                    $safe_img = $conn->real_escape_string($prod['image_url'] ?? '');
                    
                    $check_spam = $conn->query("SELECT id FROM notifications WHERE message = '$safe_msg' AND DATE(created_at) = '$today'");
                    if($check_spam && $check_spam->num_rows == 0) {
                        $conn->query("INSERT INTO notifications (message, alert_type, image_url) VALUES ('$safe_msg', '$type', '$safe_img')");
                    }
                }
            }
        }
    }
} catch (Throwable $e) {}
// =================================================================

// =================================================================================
//                       1. ORDER FULFILLMENT STATS
// =================================================================================
$ops_query = "
    SELECT 
        SUM(CASE WHEN order_status = 'Pending' THEN 1 ELSE 0 END) as to_pack,
        SUM(CASE WHEN order_status = 'Packed' THEN 1 ELSE 0 END) as ready_to_dispatch,
        SUM(CASE WHEN order_status = 'Out for Delivery' THEN 1 ELSE 0 END) as on_route,
        SUM(CASE WHEN order_status = 'Completed' AND DATE(delivered_at) = CURDATE() THEN 1 ELSE 0 END) as delivered_today,
        SUM(CASE WHEN order_status = 'Completed' AND payment_status != 'Paid' AND payment_status != 'Cancelled' THEN (total_amount - amount_paid) ELSE 0 END) as total_collectibles,
        SUM(CASE WHEN order_status = 'Completed' AND MONTH(delivered_at) = MONTH(CURRENT_DATE()) AND YEAR(delivered_at) = YEAR(CURRENT_DATE()) THEN total_amount ELSE 0 END) as revenue_this_month
    FROM sales
    WHERE order_status != 'Cancelled'
";
$ops = fetch_assoc_safe($conn, $ops_query);

$to_pack = $ops['to_pack'] ?? 0;
$ready_to_dispatch = $ops['ready_to_dispatch'] ?? 0;
$on_route = $ops['on_route'] ?? 0;
$delivered_today = $ops['delivered_today'] ?? 0;
$total_collectibles = $ops['total_collectibles'] ?? 0;
$revenue_this_month = $ops['revenue_this_month'] ?? 0;

// =================================================================================
//                       2. INVENTORY HEALTH
// =================================================================================
$has_expiry = false;
try {
    $col_check = $conn->query("SHOW COLUMNS FROM products LIKE 'expiration_date'");
    if($col_check && $col_check->num_rows > 0) { $has_expiry = true; }
} catch (Throwable $e) {}

$expiry_logic_1 = $has_expiry ? "WHEN p.expiration_date IS NOT NULL AND p.expiration_date != '0000-00-00' AND p.expiration_date < CURDATE() THEN 'Expired'" : "";
$expiry_logic_2 = $has_expiry ? "WHEN (p.expiration_date IS NOT NULL AND p.expiration_date != '0000-00-00' AND p.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)) AND (COALESCE(pi.quantity, 0) <= " . LOW_STOCK_THRESHOLD . ") THEN 'Expiring & Low Stock'" : "";
$expiry_logic_3 = $has_expiry ? "WHEN p.expiration_date IS NOT NULL AND p.expiration_date != '0000-00-00' AND p.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'Expiring Soon'" : "";

$stats_query = "
    SELECT 
        COUNT(*) as total_products,
        SUM(CASE WHEN status_bucket = 'Out of Stock' THEN 1 ELSE 0 END) as out_of_stock,
        SUM(CASE WHEN status_bucket = 'Expired' THEN 1 ELSE 0 END) as expired_count,
        SUM(CASE WHEN status_bucket = 'Expiring & Low Stock' THEN 1 ELSE 0 END) as expiring_low_stock,
        SUM(CASE WHEN status_bucket = 'Expiring Soon' THEN 1 ELSE 0 END) as expiring_soon,
        SUM(CASE WHEN status_bucket = 'Low Stock' THEN 1 ELSE 0 END) as low_stock,
        SUM(CASE WHEN status_bucket = 'Healthy' THEN 1 ELSE 0 END) as healthy_stock
    FROM (
        SELECT 
            CASE 
                WHEN COALESCE(pi.quantity, 0) <= 0 THEN 'Out of Stock'
                $expiry_logic_1
                $expiry_logic_2
                $expiry_logic_3
                WHEN COALESCE(pi.quantity, 0) <= " . LOW_STOCK_THRESHOLD . " THEN 'Low Stock'
                ELSE 'Healthy'
            END as status_bucket
        FROM products p
        LEFT JOIN product_inventory pi ON p.product_id = pi.product_id
        WHERE p.status != 'Archived'
    ) as calc_table
";
$inv_stats = fetch_assoc_safe($conn, $stats_query);

$stock_data = json_encode([
    $inv_stats['healthy_stock'] ?? 0, 
    $inv_stats['expiring_low_stock'] ?? 0, 
    $inv_stats['expiring_soon'] ?? 0, 
    $inv_stats['low_stock'] ?? 0, 
    $inv_stats['out_of_stock'] ?? 0, 
    $inv_stats['expired_count'] ?? 0
]);

// =================================================================================
//                       3. SALES CHART DATA
// =================================================================================
$dates = [];
$sales_data = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $dates[] = date('M d', strtotime($d));
    $daily = 0;
    try {
        $sql = "SELECT SUM(total_amount) as daily_total FROM sales WHERE order_status = 'Completed' AND DATE(delivered_at) = '$d'";
        $res = $conn->query($sql);
        if ($res) { $daily = floatval($res->fetch_assoc()['daily_total'] ?? 0); }
    } catch (Throwable $e) {}
    $sales_data[] = $daily;
}
$chart_dates = json_encode($dates);
$chart_sales = json_encode($sales_data);

// =================================================================================
//                       4. RECENT ORDERS TABLE
// =================================================================================
$recent_orders = [];
try {
    $ro_query = "SELECT s.*, c.client_name 
                 FROM sales s 
                 LEFT JOIN clients c ON s.client_id = c.client_id 
                 ORDER BY s.sale_date DESC LIMIT 5";
    $ro_res = $conn->query($ro_query);
    if($ro_res) $recent_orders = $ro_res->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) {}

ob_end_flush();
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <link rel="icon" type="image/jpeg" href="/admin_components/assets/img/tabicon4.png">
    <title>FreshFlow - Command Center</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script> tailwind.config = { darkMode: 'class' }; </script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root { --brand-green: #1E3A1D; --brand-cream: #F8F5EE; --text-dark: #2B2B2B; }
        
        /* INSTANT BACKGROUND COLORS */
        html { background-color: var(--brand-cream); } 
        html.dark { background-color: #000000 !important; } 
        
        body { font-family: 'Inter', sans-serif; opacity: 0; transition: opacity 0.2s ease-in-out, background-color 0.3s ease; color: var(--text-dark); } 
        body.tailwind-loaded { opacity: 1; }

        @keyframes slideUpFade {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-slide-up {
            animation: slideUpFade 0.6s ease-out forwards;
            opacity: 0; 
        }
        .delay-100 { animation-delay: 0.1s; }
        .delay-200 { animation-delay: 0.2s; }
        .delay-300 { animation-delay: 0.3s; }
        
        .optimized-main { 
            will-change: margin-left, width; 
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1), width 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
            transform: translateZ(0); 
        }

        .dark body {
            background-image: radial-gradient(circle, rgba(255, 255, 255, 0.07) 1px, transparent 1px);
            background-size: 16px 16px;
            color: #f8fafc;
        }
        .font-heading { font-family: 'Roboto Mono', monospace; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .dark .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; }
        .content-card { 
            background-color: #ffffff; 
            border: 1px solid #e5e7eb; 
            border-radius: 0.75rem; 
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); 
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease; 
            contain: content; 
            will-change: transform;
        }
        .dark .content-card {
            background-color: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(12px);
            border-color: #1e293b;
        }
        .status-badge { padding: 2px 8px; border-radius: 99px; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; }
        .st-Pending { background: #fee2e2; color: #991b1b; }
        .dark .st-Pending { background: rgba(153, 27, 27, 0.2); color: #f87171; }
        .st-Packed { background: #eff6ff; color: #1e40af; }
        .dark .st-Packed { background: rgba(30, 64, 175, 0.2); color: #60a5fa; }
        .st-Out { background: #faf5ff; color: #6b21a8; }
        .dark .st-Out { background: rgba(107, 33, 168, 0.2); color: #c084fc; }
        .st-Completed { background: #f0fdf4; color: #166534; }
        .dark .st-Completed { background: rgba(22, 101, 52, 0.2); color: #4ade80; }
        .st-Cancelled { background: #f3f4f6; color: #374151; text-decoration: line-through; }
        .dark .st-Cancelled { background: rgba(55, 65, 81, 0.3); color: #9ca3af; text-decoration: line-through; }
    </style>

    <script>
        window.onpageshow = function(event) { if (event.persisted) window.location.reload(); };
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
        document.documentElement.classList.add('js-loaded');
        window.addEventListener('DOMContentLoaded', () => {
            setTimeout(() => { document.body.classList.add('tailwind-loaded'); }, 50);
        });
        window.onbeforeunload = function() {
            document.body.innerHTML = ""; 
            document.body.style.backgroundColor = document.documentElement.classList.contains('dark') ? "#000000" : "#F8F5EE"; 
        };
    </script>
</head>
<body>
    
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="ml-20 optimized-main">
        <main class="p-6 md:p-8">
            <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8">
                <div>
                    <div class="flex items-center gap-3 mb-1">
                        <div class="bg-green-100 dark:bg-green-900/40 p-1.5 rounded-lg text-[#1E3A1D] dark:text-green-400">
                            <span class="material-icons text-2xl drop-shadow-sm">space_dashboard</span>
                        </div>
                        <h1 class="font-heading text-3xl font-bold text-[#1E3A1D] dark:text-white">Dashboard</h1>
                    </div>
                    <p class="text-sm text-gray-500 dark:text-slate-400 ml-1">
                        Welcome back, <span class="font-semibold"><?= htmlspecialchars($_SESSION["username"] ?? 'Admin') ?></span>! Here's what's happening today.
                    </p>
                </div>
                
                <?php
                // ==========================================
                // BULLETPROOF USER & NOTIF VARIABLES (LIVE DB SYNC)
                // ==========================================
                $unread_count = 0;
                
                // 1. Set Session defaults just in case
                $u_name = $_SESSION["username"] ?? 'Admin';
                $u_role = $_SESSION["role_name"] ?? 'Super Admin';
                $u_pic = $_SESSION["profile_image"] ?? null;

                // 2. Fetch live data from the 'users' table
                $current_uid = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
                if ($current_uid > 0 && isset($conn)) {
                    $live_user = fetch_assoc_safe($conn, "SELECT first_name, last_name, role, profile_image FROM users WHERE user_id = $current_uid");
                    
                    if (!empty($live_user)) {
                        // Override session data with fresh DB data
                        $u_name = trim(($live_user['first_name'] ?? '') . ' ' . ($live_user['last_name'] ?? ''));
                        $u_role = $live_user['role'] ?? $u_role;
                        
                        // FIX: Add '../../' so the dashboard can find the root assets folder
                        if (!empty($live_user['profile_image'])) {
                            $u_pic = '../../' . ltrim($live_user['profile_image'], '/');
                        } else {
                            $u_pic = null;
                        }
                    }
                }

                // 3. Failsafe for empty names and generate the initial 
                $u_name = !empty($u_name) ? $u_name : 'Admin';
                $u_initial = strtoupper(substr($u_name, 0, 1));

                try {
                    if (isset($conn)) {
                        $unread_result = $conn->query("SELECT COUNT(*) as c FROM notifications WHERE is_read = 0");
                        $unread_count = $unread_result ? ($unread_result->fetch_assoc()['c'] ?? 0) : 0;
                    }
                } catch (Throwable $e) {} 
                ?>

                <div class="flex items-center space-x-4">
                    <div class="text-right hidden md:block">
                        <p class="text-xs text-gray-400 dark:text-slate-400 font-bold uppercase">Current Date</p>
                        <p class="text-sm font-bold text-[#1E3A1D] dark:text-white"><?= date('F d, Y') ?></p>
                    </div>

                    <div class="relative group flex items-center" onclick="document.getElementById('fbNotifDropdown').classList.toggle('hidden')">
                        <div class="w-10 h-10 bg-gray-100 dark:bg-slate-800/80 rounded-full flex items-center justify-center cursor-pointer hover:bg-gray-200 dark:hover:bg-slate-700 transition shadow-sm border border-gray-200 dark:border-slate-600/50">
                            <span class="material-icons text-gray-600 dark:text-gray-300">notifications</span>
                            <?php if($unread_count > 0): ?>
                            <span class="absolute -top-1 -right-1 flex h-4 w-4">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-4 w-4 bg-red-500 text-[9px] font-bold text-white items-center justify-center"><?= $unread_count ?></span>
                            </span>
                            <?php endif; ?>
                        </div>

                        <div id="fbNotifDropdown" class="hidden absolute right-0 top-full mt-3 w-80 bg-white dark:bg-slate-800 rounded-xl shadow-2xl border border-gray-200 dark:border-slate-700 overflow-hidden transform transition-all z-[9999]">
                            <div class="p-3 border-b border-gray-100 dark:border-slate-700 flex justify-between items-center bg-gray-50 dark:bg-slate-900/50">
                                <span class="font-bold text-sm text-gray-800 dark:text-white">Notifications</span>
                                <?php if($unread_count > 0): ?>
                                    <button onclick="markAllRead(event)" class="text-xs text-blue-600 hover:text-blue-800 dark:text-blue-400 font-semibold">Mark all read</button>
                                <?php endif; ?>
                            </div>
                            <div class="max-h-80 overflow-y-auto custom-scrollbar">
                                <?php
                                    try {
                                        if (isset($conn)) {
                                            $notifs = $conn->query("SELECT * FROM notifications ORDER BY created_at DESC LIMIT 10");
                                            if($notifs && $notifs->num_rows > 0):
                                                while($n = $notifs->fetch_assoc()):
                                ?>
                                <div onclick="readAndRedirect(<?= $n['id'] ?>, 'products.php')" class="p-3 border-b border-gray-50 dark:border-slate-700/50 hover:bg-gray-100 dark:hover:bg-slate-700/70 transition cursor-pointer flex gap-3 <?= $n['is_read'] ? 'opacity-60' : 'bg-blue-50/40 dark:bg-blue-900/30' ?>">
                                    
                                    <?php if(!empty($n['image_url'])): ?>
                                        <div class="w-10 h-10 rounded-md overflow-hidden flex-shrink-0 border border-gray-200 shadow-sm mt-0.5">
                                            <img src="../../<?= htmlspecialchars($n['image_url']) ?>" alt="Product" class="w-full h-full object-cover">
                                        </div>
                                    <?php else: ?>
                                        <div class="w-9 h-9 rounded-full bg-blue-100 dark:bg-blue-900/50 flex-shrink-0 flex items-center justify-center mt-0.5">
                                            <span class="material-icons text-blue-600 dark:text-blue-400 text-[16px]">notifications_active</span>
                                        </div>
                                    <?php endif; ?>

                                    <div>
                                        <p class="text-[13px] text-gray-800 dark:text-gray-200 leading-tight"><?= htmlspecialchars($n['message']) ?></p>
                                        <span class="text-[10px] text-blue-500 font-medium mt-1 block"><?= date('M d, h:i A', strtotime($n['created_at'])) ?></span>
                                    </div>
                                </div>
                                <?php 
                                                endwhile; 
                                            else: 
                                ?>
                                    <div class="p-6 text-center flex flex-col items-center justify-center">
                                        <span class="material-icons text-gray-300 dark:text-slate-600 text-4xl mb-2">notifications_none</span>
                                        <span class="text-xs text-gray-500 dark:text-slate-400 mt-2">No new notifications</span>
                                    </div>
                                <?php 
                                            endif;
                                        }
                                    } catch(Exception $e) {} 
                                ?>
                            </div>
                        </div>
                    </div>
                    
                    
                    
                    <div class="relative" id="profile-menu-container">
                        
                        <div class="group relative flex items-center cursor-pointer" onclick="toggleProfileDropdown()">
                            
                          
                            <div class="hidden md:flex flex-col items-end justify-center overflow-hidden transition-all duration-300 max-w-0 opacity-0 group-hover:max-w-[250px] group-hover:opacity-100 group-hover:mr-3 group-hover:pl-2">
                                <p class="text-sm font-bold text-[#1E3A1D] dark:text-white whitespace-nowrap"><?= htmlspecialchars($u_name) ?></p>
                                <p class="text-xs text-[#1E3A1D]/70 dark:text-emerald-400 font-bold uppercase tracking-wider whitespace-nowrap"><?= htmlspecialchars($u_role) ?></p>
                            </div>

                            <div class="relative w-10 h-10 shrink-0 rounded-full bg-[#1E3A1D] dark:bg-green-700 text-white flex items-center justify-center font-bold shadow-lg overflow-hidden ring-2 ring-transparent hover:ring-[#1E3A1D]/50 dark:hover:ring-emerald-500 transition-all z-10">
                                <?php if(!empty($u_pic)): ?>
                                    <img src="<?= htmlspecialchars($u_pic) ?>" class="w-full h-full object-cover" alt="Profile">
                                <?php else: ?>
                                    <span><?= htmlspecialchars($u_initial) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div id="profile-dropdown" class="absolute top-14 right-0 w-64 bg-white dark:bg-slate-800 rounded-xl shadow-2xl border border-gray-100 dark:border-slate-700 opacity-0 invisible transform scale-95 transition-all duration-200 z-50">
                            
                            <div class="p-4 border-b border-gray-100 dark:border-slate-700 flex items-center space-x-3">
                                <div class="w-12 h-12 rounded-full bg-emerald-100 dark:bg-emerald-900 text-[#1E3A1D] dark:text-emerald-400 flex items-center justify-center font-bold text-lg overflow-hidden shrink-0">
                                    <?php if(!empty($u_pic)): ?>
                                        <img src="<?= htmlspecialchars($u_pic) ?>" class="w-full h-full object-cover" alt="Profile">
                                    <?php else: ?>
                                        <?= htmlspecialchars($u_initial) ?>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <p class="text-sm font-bold text-gray-900 dark:text-white leading-tight"><?= htmlspecialchars($u_name) ?></p>
                                    <p class="text-xs text-emerald-600 dark:text-emerald-400 font-medium mt-1 uppercase"><?= htmlspecialchars($u_role) ?></p>
                                </div>
                            </div>

                            <div class="p-2">
                                <a href="settings.php" class="flex items-center px-3 py-2.5 text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-slate-700 hover:text-[#1E3A1D] dark:hover:text-white rounded-lg transition-colors">
                                    <span class="material-icons text-[20px] mr-3">settings</span>
                                    <span>Settings</span>
                                </a>
                            </div>

                            <div class="p-2 border-t border-gray-100 dark:border-slate-700">
                                <a href="../logout.php" class="flex items-center px-3 py-2.5 text-sm font-bold text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors">
                                    <span class="material-icons text-[20px] mr-3">logout</span>
                                    <span>Logout</span>
                                </a>
                            </div>
                        </div>
                    </div>
                    </div>
            </header>
            <h2 class="text-xs font-bold text-gray-400 dark:text-slate-500 uppercase tracking-widest mb-4">Operations Pipeline</h2>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8 animate-slide-up delay-100">
                
                <a href="order_queue.php" class="content-card p-5 border-l-4 border-orange-400 dark:border-orange-500 flex items-center justify-between cursor-pointer group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(249,115,22,0.2)] dark:hover:shadow-[0_0_20px_rgba(249,115,22,0.3)] dark:hover:border-orange-400 transition-all duration-300">
                    <div>
                        <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase group-hover:text-orange-500 dark:group-hover:text-orange-300 transition-colors">Pending Orders</p>
                        <p class="font-heading text-3xl font-bold text-orange-600 dark:text-orange-400 mt-1 group-hover:scale-110 transition-transform origin-left"><?= $to_pack ?></p>
                        <p class="text-[10px] text-gray-400 dark:text-slate-500 mt-1">Needs Packing</p>
                    </div>
                    <div class="bg-orange-50 dark:bg-orange-900/30 p-3 rounded-full text-orange-600 dark:text-orange-400 group-hover:bg-orange-100 dark:group-hover:bg-orange-800/50 transition-colors">
                        <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">inventory_2</span>
                    </div>
                </a>

                <a href="dispatch.php" class="content-card p-5 border-l-4 border-blue-500 dark:border-blue-400 flex items-center justify-between cursor-pointer group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(59,130,246,0.2)] dark:hover:shadow-[0_0_20px_rgba(59,130,246,0.3)] dark:hover:border-blue-300 transition-all duration-300">
                    <div>
                        <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase group-hover:text-blue-500 dark:group-hover:text-blue-300 transition-colors">Ready to Ship</p>
                        <p class="font-heading text-3xl font-bold text-blue-600 dark:text-blue-400 mt-1 group-hover:scale-110 transition-transform origin-left"><?= $ready_to_dispatch ?></p>
                        <p class="text-[10px] text-gray-400 dark:text-slate-500 mt-1">Assign Driver</p>
                    </div>
                    <div class="bg-blue-50 dark:bg-blue-900/30 p-3 rounded-full text-blue-600 dark:text-blue-400 group-hover:bg-blue-100 dark:group-hover:bg-blue-800/50 transition-colors">
                        <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">local_shipping</span>
                    </div>
                </a>

                <a href="dispatch.php" class="content-card p-5 border-l-4 border-purple-500 dark:border-purple-400 flex items-center justify-between cursor-pointer group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(168,85,247,0.2)] dark:hover:shadow-[0_0_20px_rgba(168,85,247,0.3)] dark:hover:border-purple-300 transition-all duration-300">
                    <div>
                        <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase group-hover:text-purple-500 dark:group-hover:text-purple-300 transition-colors">On the Road</p>
                        <p class="font-heading text-3xl font-bold text-purple-600 dark:text-purple-400 mt-1 group-hover:scale-110 transition-transform origin-left"><?= $on_route ?></p>
                        <p class="text-[10px] text-gray-400 dark:text-slate-500 mt-1">Active Deliveries</p>
                    </div>
                    <div class="bg-purple-50 dark:bg-purple-900/30 p-3 rounded-full text-purple-600 dark:text-purple-400 group-hover:bg-purple-100 dark:group-hover:bg-purple-800/50 transition-colors">
                        <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">near_me</span>
                    </div>
                </a>

                <div class="content-card p-5 border-l-4 border-green-600 dark:border-green-500 flex items-center justify-between cursor-pointer group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(34,197,94,0.2)] dark:hover:shadow-[0_0_20px_rgba(34,197,94,0.3)] dark:hover:border-green-400 transition-all duration-300">
                    <div>
                        <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase group-hover:text-green-600 dark:group-hover:text-green-300 transition-colors">Completed Today</p>
                        <p class="font-heading text-3xl font-bold text-green-700 dark:text-green-400 mt-1 group-hover:scale-110 transition-transform origin-left"><?= $delivered_today ?></p>
                        <p class="text-[10px] text-gray-400 dark:text-slate-500 mt-1">Successful Drops</p>
                    </div>
                    <div class="bg-green-50 dark:bg-green-900/30 p-3 rounded-full text-green-700 dark:text-green-400 group-hover:bg-green-100 dark:group-hover:bg-green-800/50 transition-colors">
                        <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">check_circle</span>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8 animate-slide-up delay-200">
                <div class="lg:col-span-2 space-y-6">
                    <div class="grid grid-cols-2 gap-4">
                        
                        <a href="invoices.php" class="content-card p-5 bg-gradient-to-br from-white to-red-50 dark:from-slate-900/80 dark:to-red-900/20 border border-red-100 dark:border-red-900/50 hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(239,68,68,0.2)] dark:hover:shadow-[0_0_20px_rgba(239,68,68,0.3)] transition-all duration-300 group cursor-pointer">
                            <div class="flex justify-between items-start">
                                <div>
                                    <p class="text-xs font-bold text-red-400 dark:text-red-500 uppercase tracking-wider group-hover:text-red-600 dark:group-hover:text-red-400 transition-colors">Unpaid Invoices</p>
                                    <h3 class="font-heading text-2xl font-bold text-red-700 dark:text-red-400 mt-1 group-hover:scale-105 transition-transform origin-left">₱<?= number_format($total_collectibles, 2) ?></h3>
                                </div>
                                <span class="material-icons text-red-300 dark:text-red-800 group-hover:text-red-500 dark:group-hover:text-red-400 group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">receipt_long</span>
                            </div>
                            <p class="text-[10px] text-red-400 dark:text-red-500/80 mt-2 font-medium">Total Collectibles</p>
                        </a>
                        
                        <div class="content-card p-5 bg-gradient-to-br from-white to-green-50 dark:from-slate-900/80 dark:to-green-900/20 border border-green-100 dark:border-green-900/50 hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(34,197,94,0.2)] dark:hover:shadow-[0_0_20px_rgba(34,197,94,0.3)] transition-all duration-300 group cursor-pointer">
                            <div class="flex justify-between items-start">
                                <div>
                                    <p class="text-xs font-bold text-green-600 dark:text-green-500 uppercase tracking-wider group-hover:text-green-700 dark:group-hover:text-green-400 transition-colors">Monthly Revenue</p>
                                    <h3 class="font-heading text-2xl font-bold text-[#1E3A1D] dark:text-white mt-1 group-hover:scale-105 transition-transform origin-left">₱<?= number_format($revenue_this_month, 2) ?></h3>
                                </div>
                                <span class="material-icons text-green-300 dark:text-green-800 group-hover:text-green-500 dark:group-hover:text-green-400 group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">trending_up</span>
                            </div>
                            <p class="text-[10px] text-green-600 dark:text-green-500/80 mt-2 font-medium">Total Sales (<?= date('M') ?>)</p>
                        </div>
                    </div>

                    <div class="content-card p-6">
                        <h2 class="font-bold text-gray-700 dark:text-slate-200 mb-4 flex items-center gap-2"><span class="material-icons text-sm">bar_chart</span> Daily Sales Revenue</h2>
                        <div class="relative h-64 w-full"><canvas id="salesChart"></canvas></div>
                    </div>
                </div>

                <div class="content-card p-6 flex flex-col">
                    <h2 class="font-bold text-gray-700 dark:text-slate-200 mb-4 flex items-center gap-2"><span class="material-icons text-sm">health_and_safety</span> Inventory Health</h2>
                    <div class="relative h-48 w-full flex justify-center mb-4"><canvas id="stockChart"></canvas></div>
                    
                    <div class="flex-1 overflow-y-auto pr-2 space-y-3 custom-scrollbar">
                        <?php 
                        $alerts = [
                            ['label' => 'Out of Stock', 'count' => $inv_stats['out_of_stock'] ?? 0, 'color' => 'bg-red-600 dark:bg-red-500', 'text' => 'text-red-700 dark:text-red-400'],
                            ['label' => 'Expired', 'count' => $inv_stats['expired_count'] ?? 0, 'color' => 'bg-purple-600 dark:bg-purple-500', 'text' => 'text-purple-700 dark:text-purple-400'],
                            ['label' => 'Critial (Low & Exp)', 'count' => $inv_stats['expiring_low_stock'] ?? 0, 'color' => 'bg-orange-600 dark:bg-orange-500', 'text' => 'text-orange-700 dark:text-orange-400'],
                            ['label' => 'Low Stock', 'count' => $inv_stats['low_stock'] ?? 0, 'color' => 'bg-yellow-500 dark:bg-yellow-400', 'text' => 'text-yellow-700 dark:text-yellow-400'],
                        ];
                        foreach($alerts as $a): 
                            if($a['count'] > 0):
                        ?>
                        <div class="flex justify-between items-center p-2 rounded bg-gray-50 dark:bg-slate-800/50 border border-gray-100 dark:border-slate-700/50">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full <?= $a['color'] ?>"></span>
                                <span class="text-xs font-medium text-gray-600 dark:text-slate-400"><?= $a['label'] ?></span>
                            </div>
                            <span class="text-sm font-bold <?= $a['text'] ?>"><?= $a['count'] ?></span>
                        </div>
                        <?php endif; endforeach; ?>
                        
                        <div class="flex justify-between items-center p-2 rounded bg-green-50 dark:bg-green-900/20 border border-green-100 dark:border-green-800/50">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-[#1E3A1D] dark:bg-green-400"></span>
                                <span class="text-xs font-medium text-gray-600 dark:text-slate-300">Healthy Stock</span>
                            </div>
                            <span class="text-sm font-bold text-[#1E3A1D] dark:text-green-400"><?= $inv_stats['healthy_stock'] ?? 0 ?></span>
                        </div>
                    </div>
                    <a href="inventory.php" class="mt-4 text-center text-xs font-bold text-[#1E3A1D] dark:text-green-400 hover:underline">Manage Inventory →</a>
                </div>
            </div>

            <div class="content-card overflow-hidden animate-slide-up delay-300">
                <div class="p-4 border-b border-gray-100 dark:border-slate-800 flex justify-between items-center bg-gray-50 dark:bg-slate-800/50">
                    <h3 class="font-bold text-gray-700 dark:text-slate-300 text-sm uppercase tracking-wide">Latest Orders</h3>
                    <a href="order_queue.php" class="text-xs font-bold text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300">View Queue</a>
                </div>
                <table class="w-full text-left">
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-800 text-sm">
                        <?php if (empty($recent_orders)): ?>
                            <tr><td class="p-4 text-center text-gray-400 dark:text-slate-500 italic">No orders yet.</td></tr>
                        <?php else: ?>
                            <?php foreach($recent_orders as $o): 
                                $statusClass = 'st-' . str_replace(' ', '', $o['order_status']);
                                if($o['order_status'] == 'Out for Delivery') $statusClass = 'st-Out';
                            ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-slate-800/40 transition">
                                <td class="p-3 pl-5 font-mono font-bold text-[#1E3A1D] dark:text-white text-xs">#<?= str_pad($o['sale_id'], 5, '0', STR_PAD_LEFT) ?></td>
                                <td class="p-3 font-medium text-gray-700 dark:text-slate-300"><?= htmlspecialchars($o['client_name'] ?? 'Unknown') ?></td>
                                <td class="p-3 text-gray-500 dark:text-slate-500 text-xs"><?= date('M d, h:i A', strtotime($o['sale_date'])) ?></td>
                                <td class="p-3 text-right font-bold text-gray-700 dark:text-white">₱<?= number_format($o['total_amount'], 2) ?></td>
                                <td class="p-3 text-right">
                                    <span class="status-badge <?= $statusClass ?>"><?= $o['order_status'] ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </main>
    </div>

    <script>
        Chart.defaults.font.family = "'Inter', sans-serif";
        
        // Check Dark Mode Status for Chart Colors
        const isDark = document.documentElement.classList.contains('dark');
        const gridColor = isDark ? 'rgba(255, 255, 255, 0.05)' : 'rgba(0, 0, 0, 0.05)';
        const textColor = isDark ? '#94a3b8' : '#64748b'; // slate-400 / slate-500
        const brandGreen = isDark ? '#4ade80' : '#1E3A1D';

        // --- SALES CHART ---
        const ctxSales = document.getElementById('salesChart').getContext('2d');
        const gradient = ctxSales.createLinearGradient(0, 0, 0, 300);
        if (isDark) {
            gradient.addColorStop(0, 'rgba(74, 222, 128, 0.25)'); // Green-400 glow
            gradient.addColorStop(1, 'rgba(74, 222, 128, 0)');
        } else {
            gradient.addColorStop(0, 'rgba(30, 58, 29, 0.1)'); // Dark green
            gradient.addColorStop(1, 'rgba(30, 58, 29, 0)');
        }

        new Chart(ctxSales, {
            type: 'line',
            data: {
                labels: <?= $chart_dates ?>,
                datasets: [{
                    label: 'Revenue (₱)',
                    data: <?= $chart_sales ?>,
                    borderColor: brandGreen,
                    backgroundColor: gradient,
                    borderWidth: 2,
                    pointBackgroundColor: brandGreen,
                    pointBorderColor: isDark ? '#0f172a' : '#fff',
                    pointRadius: 3,
                    fill: true,
                    tension: 0.3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { 
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: isDark ? '#1e293b' : '#1E3A1D',
                        titleColor: '#fff',
                        bodyColor: '#fff'
                    }
                },
                scales: {
                    y: { 
                        beginAtZero: true, 
                        grid: { color: gridColor, borderDash: [2, 4] },
                        ticks: { color: textColor }
                    },
                    x: { 
                        grid: { display: false },
                        ticks: { color: textColor }
                    }
                }
            }
        });

        // --- INVENTORY CHART ---
        const ctxStock = document.getElementById('stockChart').getContext('2d');
        new Chart(ctxStock, {
            type: 'doughnut',
            data: {
                labels: ['Healthy', 'Critical', 'Expiring', 'Low', 'Out', 'Expired'],
                datasets: [{
                    data: <?= $stock_data ?>,
                    backgroundColor: [
                        isDark ? '#4ade80' : '#1E3A1D', // Healthy
                        '#ea580c', // Critical
                        '#f97316', // Expiring
                        '#eab308', // Low
                        '#dc2626', // Out
                        '#9333ea'  // Expired
                    ],
                    borderColor: isDark ? '#0f172a' : '#ffffff',
                    borderWidth: isDark ? 2 : 0,
                    hoverOffset: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: { legend: { display: false } }
            }
        });
    </script>
    <script>
<script>
async function markAllRead(e) {
    e.stopPropagation(); 
    try {
        const fd = new FormData();
        fd.append('action', 'mark_notifications_read');
        // Make sure this path points to your ajax handler!
        await fetch('../includes/ajax_notifications.php', { method: 'POST', body: fd });
        window.location.reload(); 
    } catch(err) { console.error(err); }
}
</script>
</script>
<script>
// Marks ALL notifications as read WITHOUT reloading the page!
async function markAllRead(e) {
    e.stopPropagation(); 
    const btn = e.target; // The button you just clicked
    btn.innerHTML = 'Clearing...'; // Cool loading text
    
    try {
        const fd = new FormData();
        fd.append('action', 'mark_notifications_read');
        let response = await fetch('../includes/ajax_notifications.php', { method: 'POST', body: fd });
        let data = await response.json();
        
        if (data.success) {
            // 1. Hide the "Mark all read" button
            btn.style.display = 'none';
            
            // 2. Find the Red Dot and instantly delete it from the screen
            const ping = document.querySelector('.animate-ping');
            if (ping && ping.parentElement) {
                ping.parentElement.remove();
            }
            
            // 3. Find all unread items and make them look "read" (fade them out slightly)
            // Note: We escape the slash in the class name with \\
            const unreadItems = document.querySelectorAll('.bg-blue-50\\/30');
            unreadItems.forEach(item => {
                item.classList.remove('bg-blue-50/30', 'dark:bg-blue-900/20');
                item.classList.add('opacity-70'); // The class we use for read items
            });
        }
    } catch(err) { 
        console.error(err); 
        btn.innerHTML = 'Error!';
    }
}

// Marks ONE notification as read, then instantly teleports to products.php
async function readAndRedirect(notifId, url) {
    try {
        const fd = new FormData();
        fd.append('action', 'mark_one_read');
        fd.append('notif_id', notifId);
        await fetch('../includes/ajax_notifications.php', { method: 'POST', body: fd });
        window.location.href = url; // Teleport the user!
    } catch(err) { 
        window.location.href = url; // Teleport even if the database fails
    }
}
function toggleProfileDropdown() {
    const dropdown = document.getElementById('profile-dropdown');
    
    // Toggle classes to animate it fading and scaling in
    dropdown.classList.toggle('opacity-0');
    dropdown.classList.toggle('invisible');
    dropdown.classList.toggle('scale-95');
    dropdown.classList.toggle('scale-100');
}

// Close the dropdown automatically if the user clicks anywhere else on the screen
document.addEventListener('click', function(event) {
    const container = document.getElementById('profile-menu-container');
    const dropdown = document.getElementById('profile-dropdown');
    
    // If they clicked outside the profile area, and the menu is currently open, close it
    if (!container.contains(event.target) && !dropdown.classList.contains('invisible')) {
        dropdown.classList.add('opacity-0', 'invisible', 'scale-95');
        dropdown.classList.remove('scale-100');
    }
});
</script>
</body>
</html>