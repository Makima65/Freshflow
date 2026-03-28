<?php
// C:\xampp\htdocs\RalphPHP\admin_components\pages\audit_logs.php
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1
header("Pragma: no-cache"); // HTTP 1.0
header("Expires: 0"); // Proxies
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

include_once '../includes/db_connection.php';

// --- CONFIGURATION ---
// Set Timezone to Philippines
date_default_timezone_set('Asia/Manila');
if ($conn) $conn->query("SET time_zone = '+08:00'");

// --- SECURITY: ADMIN ONLY ---
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || 
    ($_SESSION["role_name"] !== 'admin' && $_SESSION["role_name"] !== 'Super Admin')) {
    header("location: ../admin_login.php");
    exit;
}

// --- PAGINATION & FILTERS ---
$perPage = 20;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$search = trim($_GET['search'] ?? '');
$user_filter = intval($_GET['user_id'] ?? 0);
$action_filter = trim($_GET['action_type'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');

// Role Filter (Tabs)
$role_filter = trim($_GET['role_type'] ?? 'admin');
// Allow Admin tab to show Super Admins if needed, mostly used for filtering the join
if ($role_filter !== 'admin' && $role_filter !== 'staff') {
    $role_filter = 'admin';
}

// Count Active Filters for Badge
$active_filter_count = 0;
if ($user_filter > 0) $active_filter_count++;
if ($action_filter !== '') $active_filter_count++;
if ($date_from !== '') $active_filter_count++;
if ($date_to !== '') $active_filter_count++;

// --- BUILD QUERY ---
$where = [];
$params = [];
$types = '';

// Base Join
$from_sql = "FROM audit_trail a LEFT JOIN users u ON a.user_id = u.user_id";

// 1. Role Filter Logic
if ($role_filter === 'admin') {
    $where[] = "(u.role = 'admin' OR u.role = 'Super Admin' OR u.role IS NULL)";
} else {
    $where[] = "u.role = ?";
    $params[] = $role_filter;
    $types .= "s";
}

// 2. Other Filters
if ($search !== '') {
    // Look in BOTH the users table and the audit table for the name
    $where[] = "(u.username LIKE ? OR a.username LIKE ? OR a.details LIKE ? OR a.action_type LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= "ssss";
}
if ($user_filter > 0) {
    $where[] = "a.user_id = ?";
    $params[] = $user_filter;
    $types .= "i";
}
if ($action_filter !== '') {
    $where[] = "a.action_type = ?";
    $params[] = $action_filter;
    $types .= "s";
}
if ($date_from !== '') {
    $where[] = "a.log_time >= ?";
    $params[] = $date_from . " 00:00:00";
    $types .= "s";
}
if ($date_to !== '') {
    $where[] = "a.log_time <= ?";
    $params[] = $date_to . " 23:59:59";
    $types .= "s";
}

$where_sql = "WHERE " . implode(" AND ", $where);

// Fetch users for dropdown filter safely
$users = [];
try {
    $user_res = $conn->query("SELECT user_id, username, role FROM users ORDER BY username ASC");
    if($user_res) $users = $user_res->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {}

// --- EXECUTE QUERIES ---
try {
    // 1. Total Count
    $count_sql = "SELECT COUNT(*) as total $from_sql $where_sql";
    $stmt = $conn->prepare($count_sql);
    if (!empty($types)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $totalRows = $stmt->get_result()->fetch_assoc()['total'];
    $totalPages = max(1, ceil($totalRows / $perPage));
    $stmt->close();


    // 2. Fetch Data
    $sql = "SELECT a.*, u.role as user_role_label, u.username as actual_username $from_sql $where_sql ORDER BY a.log_time DESC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $params[] = $perPage;
    $params[] = $offset;
    $types .= "ii";
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // 3. Stats (All Time)
    $stats_total = $conn->query("SELECT COUNT(*) as c FROM audit_trail")->fetch_assoc()['c'];
    $stats_today = $conn->query("SELECT COUNT(*) as c FROM audit_trail WHERE DATE(log_time) = CURDATE()")->fetch_assoc()['c'];
    $stats_users = $conn->query("SELECT COUNT(*) as c FROM users WHERE status = 'Active'")->fetch_assoc()['c'];
    $action_types = $conn->query("SELECT DISTINCT action_type FROM audit_trail ORDER BY action_type ASC")->fetch_all(MYSQLI_ASSOC);

} catch (Exception $e) {
    die("Database Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreshFlow - Audit Logs</title>
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

        .font-mono { font-family: 'JetBrains Mono', monospace; }
        .custom-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scroll::-webkit-scrollbar-track { background: transparent; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .dark .custom-scroll::-webkit-scrollbar-thumb { background: #334155; }
        .modal-z { z-index: 50; }
        
        /* FORM INPUTS (Light & Dark Support) */
        .form-input { background-color: #ffffff; border: 1px solid #d1d5db; color: #374151; border-radius: 0.5rem; transition: all 0.2s; padding: 0.5rem 0.75rem; }
        .form-input:focus { outline: none; border-color: #1E3A1D; box-shadow: 0 0 0 3px rgba(30, 58, 29, 0.1); }
        
        .dark .form-input { background-color: #1e293b; border-color: #334155; color: #f8fafc; }
        .dark .form-input:focus { border-color: #4ade80; box-shadow: 0 0 0 3px rgba(74, 222, 128, 0.1); }

        /* Badges */
        .badge-General { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
        .dark .badge-General { background: rgba(55, 65, 81, 0.3); color: #d1d5db; border-color: rgba(107, 114, 128, 0.3); }

        .badge-Security { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .dark .badge-Security { background: rgba(153, 27, 27, 0.2); color: #fca5a5; border-color: rgba(248, 113, 113, 0.3); }

        .badge-System { background: #e0e7ff; color: #1e40af; border: 1px solid #93c5fd; }
        .dark .badge-System { background: rgba(30, 64, 175, 0.2); color: #93c5fd; border-color: rgba(96, 165, 250, 0.3); }
        
        #filterOptions { transition: max-height 0.3s ease-out, opacity 0.3s ease-out; }
    </style>
    
    <script>
        (function() {
            window.onpageshow = function(event) {
                if (event.persisted || (window.performance && window.performance.navigation.type === 2)) {
                    document.body.style.display = 'none';
                    window.location.reload(); 
                }
            };
        })();
    </script>
</head>

<body style="display:none;" id="secure-body" class="flex h-screen overflow-hidden">

    <?php include '../includes/sidebar.php'; ?>

    <main class="ml-20 flex-1 flex flex-col h-screen overflow-hidden relative transition-all duration-300 p-6 md:p-8">
        
        <header class="flex justify-between items-center mb-8 flex-shrink-0">
            <div>
                <h1 class="text-3xl font-bold text-[#1E3A1D] dark:text-white tracking-tight flex items-center gap-3">
                    <span class="material-icons text-3xl dark:text-green-400">history</span> System Audit Logs
                </h1>
                <p class="text-sm text-gray-500 dark:text-slate-400 mt-1 ml-11">
                    Track all user activities, security events, and data modifications.
                </p>
            </div>
            <button onclick="window.location.reload()" class="bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-700 text-gray-700 dark:text-slate-200 px-4 py-2.5 rounded-lg font-bold hover:bg-gray-50 dark:hover:bg-slate-700 flex items-center gap-2 shadow-sm transition transform active:scale-95">
                <span class="material-icons text-sm">refresh</span> Refresh
            </button>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6 flex-shrink-0">
            <div class="bg-white dark:bg-slate-900/80 border border-blue-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-default group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(59,130,246,0.2)] dark:hover:shadow-[0_0_20px_rgba(59,130,246,0.3)] dark:hover:border-blue-400 transition-all duration-300 shadow-sm">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">Total Records</p>
                    <p class="text-3xl font-bold text-blue-600 dark:text-blue-400 mt-1 font-mono group-hover:scale-110 transition-transform origin-left"><?= number_format($stats_total) ?></p>
                </div>
                <div class="p-3 bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 rounded-lg group-hover:bg-blue-200 dark:group-hover:bg-blue-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">storage</span>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900/80 border border-green-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-default group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(34,197,94,0.2)] dark:hover:shadow-[0_0_20px_rgba(34,197,94,0.3)] dark:hover:border-green-400 transition-all duration-300 shadow-sm">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-green-600 dark:group-hover:text-green-400 transition-colors">Today's Logs</p>
                    <p class="text-3xl font-bold text-green-700 dark:text-green-400 mt-1 font-mono group-hover:scale-110 transition-transform origin-left"><?= number_format($stats_today) ?></p>
                </div>
                <div class="p-3 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 rounded-lg group-hover:bg-green-200 dark:group-hover:bg-green-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">schedule</span>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900/80 border border-orange-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-default group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(249,115,22,0.2)] dark:hover:shadow-[0_0_20px_rgba(249,115,22,0.3)] dark:hover:border-orange-400 transition-all duration-300 shadow-sm">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-orange-600 dark:group-hover:text-orange-400 transition-colors">Active Users</p>
                    <p class="text-3xl font-bold text-orange-600 dark:text-orange-400 mt-1 font-mono group-hover:scale-110 transition-transform origin-left"><?= number_format($stats_users) ?></p>
                </div>
                <div class="p-3 bg-orange-100 dark:bg-orange-900/30 text-orange-600 dark:text-orange-400 rounded-lg group-hover:bg-orange-200 dark:group-hover:bg-orange-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">group</span>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900/80 border border-purple-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-default group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(168,85,247,0.2)] dark:hover:shadow-[0_0_20px_rgba(168,85,247,0.3)] dark:hover:border-purple-400 transition-all duration-300 shadow-sm">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-purple-600 dark:group-hover:text-purple-400 transition-colors">Unique Actions</p>
                    <p class="text-3xl font-bold text-purple-600 dark:text-purple-400 mt-1 font-mono group-hover:scale-110 transition-transform origin-left"><?= count($action_types) ?></p>
                </div>
                <div class="p-3 bg-purple-100 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400 rounded-lg group-hover:bg-purple-200 dark:group-hover:bg-purple-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">analytics</span>
                </div>
            </div>
        </div>

        <div id="roleTabsContainer" class="flex flex-col md:flex-row justify-between items-center gap-4 mb-4 flex-shrink-0">
    <div class="flex bg-gray-50 dark:bg-slate-800 p-1 rounded-lg border border-gray-200 dark:border-slate-700 shadow-sm overflow-x-auto w-full md:w-auto">
        <a href="?role_type=admin" class="role-tab-btn px-4 py-1.5 rounded-md text-sm font-medium transition whitespace-nowrap <?= $role_filter == 'admin' ? 'bg-[#1E3A1D] dark:bg-slate-600 text-white shadow-md' : 'text-gray-500 dark:text-slate-400 hover:bg-white dark:hover:bg-slate-700 hover:text-gray-800 dark:hover:text-white' ?>">
            Admins & System
        </a>
        <a href="?role_type=staff" class="role-tab-btn px-4 py-1.5 rounded-md text-sm font-medium transition whitespace-nowrap <?= $role_filter == 'staff' ? 'bg-[#1E3A1D] dark:bg-slate-600 text-white shadow-md' : 'text-gray-500 dark:text-slate-400 hover:bg-white dark:hover:bg-slate-700 hover:text-gray-800 dark:hover:text-white' ?>">
            Staff
        </a>
    </div>
</div>

            <div class="flex gap-2 w-full md:w-auto">
                <div class="relative w-full md:w-64">
                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400"><span class="material-icons text-sm">search</span></span>
                    <input type="text" id="searchInput" placeholder="Search logs..." value="<?= htmlspecialchars($search) ?>" class="w-full pl-9 pr-3 py-2 text-sm border border-gray-200 dark:border-slate-700 rounded-lg focus:outline-none focus:border-[#1E3A1D] dark:focus:border-green-400 bg-white dark:bg-slate-800 text-gray-900 dark:text-white transition" autocomplete="off" onkeydown="if(event.key === 'Enter') document.getElementById('applyFilters').click()">
                </div>
                <button id="toggleFilterBtn" class="bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-700 hover:bg-gray-50 dark:hover:bg-slate-700 text-gray-700 dark:text-slate-200 text-sm font-bold py-2 px-4 rounded-lg flex items-center gap-2 transition shadow-sm w-full md:w-auto justify-center">
                    <span class="material-icons text-sm">filter_list</span> Filters 
                    <?php if($active_filter_count > 0): ?>
                        <span class="bg-red-600 text-white rounded-full w-5 h-5 flex justify-center items-center text-[10px] ml-1"><?= $active_filter_count ?></span>
                    <?php endif; ?>
                </button>
            </div>
        </div>

        <div id="filterOptions" class="bg-white dark:bg-slate-900/80 p-4 rounded-xl shadow-sm border border-gray-200 dark:border-slate-800 mb-6 flex-shrink-0" style="max-height: 0px; opacity: 0; overflow: hidden; display: none;">
            <input type="hidden" name="role_type" id="hiddenRoleType" value="<?= htmlspecialchars($role_filter) ?>">
                <input type="hidden" name="role_type" value="<?= htmlspecialchars($role_filter) ?>">
                <input type="hidden" name="search" id="hiddenSearch" value="<?= htmlspecialchars($search) ?>">
                
                <div class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 mb-1 uppercase tracking-wider">User</label>
                        <select name="user_id" class="w-full p-2 text-sm form-input cursor-pointer">
                            <option value="">All Users</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?= $u['user_id'] ?>" <?= $user_filter == $u['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 mb-1 uppercase tracking-wider">Action Type</label>
                        <select name="action_type" class="w-full p-2 text-sm form-input cursor-pointer">
                            <option value="">All Actions</option>
                            <?php foreach ($action_types as $t): ?>
                                <option value="<?= htmlspecialchars($t['action_type']) ?>" <?= $action_filter === $t['action_type'] ? 'selected' : '' ?>><?= htmlspecialchars($t['action_type']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 mb-1 uppercase tracking-wider">Date From</label>
                        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="w-full p-2 text-sm form-input cursor-pointer">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 mb-1 uppercase tracking-wider">Date To</label>
                        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="w-full p-2 text-sm form-input cursor-pointer">
                    </div>
                    <div class="flex gap-2">
                        <button type="button" id="resetFiltersBtn" class="bg-gray-200 dark:bg-slate-700 hover:bg-gray-300 dark:hover:bg-slate-600 text-gray-700 dark:text-slate-300 font-bold py-2 px-4 rounded-lg text-sm w-full text-center flex items-center justify-center transition">Clear</button>
                        <button type="submit" id="applyFilters" class="bg-[#1E3A1D] dark:bg-green-600 hover:bg-[#2a4e29] dark:hover:bg-green-500 text-white font-bold py-2 px-4 rounded-lg text-sm w-full shadow-md transition transform hover:-translate-y-0.5">Apply</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="bg-white dark:bg-slate-900/80 rounded-xl shadow border border-gray-200 dark:border-slate-800 flex-1 overflow-hidden flex flex-col mb-4" id="tableDataArea">
            <div class="overflow-y-auto flex-1 custom-scroll pb-24">
                <table class="w-full text-left">
                    <thead class="bg-[#1E3A1D] dark:bg-slate-800 text-white text-xs uppercase font-bold sticky top-0 z-10">
                        <tr>
                            <th class="p-4 pl-6 w-40">Timestamp</th>
                            <th class="p-4">User</th>
                            <th class="p-4 w-32">Module</th>
                            <th class="p-4">Action</th>
                            <th class="p-4 pr-6 text-right">Details</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-800 text-sm text-gray-700 dark:text-gray-300">
                        <?php if(empty($logs)): ?>
                            <tr><td colspan="5" class="p-8 text-center text-gray-400 dark:text-slate-500 italic">No audit records found matching your criteria.</td></tr>
                        <?php else: ?>
                            <?php foreach($logs as $log): 
                                $moduleBadge = 'badge-General';
                                $m_lower = strtolower($log['module_name'] ?? '');
                                
                                // Simplified badge assignment logic safely handling missing variables
                                if(strpos($m_lower, 'security') !== false || strpos($m_lower, 'auth') !== false) {
                                    $moduleBadge = 'badge-Security';
                                } elseif(strpos($m_lower, 'system') !== false) {
                                    $moduleBadge = 'badge-System';
                                }
                                
                                $ip = htmlspecialchars($log['ip_address'] ?? 'Unknown IP');
                                $agent = htmlspecialchars($log['user_agent'] ?? 'Unknown Agent');
                                $meta = htmlspecialchars($log['meta_data'] ?? '');
                            ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-slate-800/50 transition cursor-pointer" onclick="viewLogDetails(this)" data-meta="<?= $meta ?>" data-ip="<?= $ip ?>" data-agent="<?= $agent ?>">
                                <td class="p-4 pl-6 align-top">
                                    <div class="font-mono text-[11px] text-gray-500 dark:text-slate-400 font-bold whitespace-nowrap">
                                        <?= date('M d, Y', strtotime($log['log_time'])) ?><br>
                                        <span class="text-gray-900 dark:text-white text-xs"><?= date('h:i:s A', strtotime($log['log_time'])) ?></span>
                                    </div>
                                </td>
                                <td class="p-4 align-top">
                                    <div class="font-bold text-gray-900 dark:text-white text-sm">
                                        <?= htmlspecialchars($log['actual_username'] ?? $log['username'] ?? 'System') ?>
                                    </div>
                                    <?php if(!empty($log['user_role_label'])): ?>
                                        <div class="text-[10px] uppercase tracking-wider text-gray-400 dark:text-slate-500 font-bold mt-0.5">
                                            <?= htmlspecialchars($log['user_role_label']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 align-top">
                                    <span class="inline-block px-2.5 py-1 rounded text-[10px] font-bold uppercase tracking-wider <?= $moduleBadge ?>">
                                        <?= htmlspecialchars($log['module_name'] ?? 'System') ?>
                                    </span>
                                </td>
                                <td class="p-4 align-top">
                                    <div class="log-action font-bold text-[#1E3A1D] dark:text-green-400 text-xs uppercase tracking-wider mb-1">
                                        <?= htmlspecialchars($log['action_type']) ?>
                                    </div>
                                    <div class="log-details text-xs text-gray-600 dark:text-slate-300 line-clamp-2" title="<?= htmlspecialchars($log['details']) ?>">
                                        <?= htmlspecialchars($log['details']) ?>
                                    </div>
                                </td>
                                <td class="p-4 pr-6 align-middle text-right">
                                    <button type="button" class="p-2 text-gray-400 dark:text-slate-500 hover:text-[#1E3A1D] dark:hover:text-green-400 bg-gray-50 dark:bg-slate-800 hover:bg-gray-100 dark:hover:bg-slate-700 rounded-full transition focus:outline-none inline-flex items-center justify-center border border-gray-200 dark:border-slate-700">
                                        <span class="material-icons text-sm">visibility</span>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if($totalPages > 0): ?>
            <div class="p-4 border-t border-gray-200 dark:border-slate-800 bg-gray-50 dark:bg-slate-900 flex flex-col md:flex-row justify-between items-center text-sm z-10 sticky bottom-0">
                <span class="text-gray-500 dark:text-slate-400 mb-2 md:mb-0">
                    Showing <span class="font-bold text-gray-900 dark:text-white"><?= $totalRows > 0 ? $offset + 1 : 0 ?></span> 
                    to <span class="font-bold text-gray-900 dark:text-white"><?= min($offset + $perPage, $totalRows) ?></span> 
                    of <span class="font-bold text-gray-900 dark:text-white"><?= $totalRows ?></span> records
                </span>
                
                <?php if($totalPages > 1): ?>
                <div class="flex items-center gap-1 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg p-1 shadow-sm">
                    <?php 
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    if($page > 1) {
                        echo '<a href="?page='.($page-1).'&role_type='.urlencode($role_filter).'&action_type='.urlencode($action_filter).'&user_id='.urlencode($user_filter).'&search='.urlencode($search).'&date_from='.urlencode($date_from).'&date_to='.urlencode($date_to).'" class="w-8 h-8 flex items-center justify-center rounded transition bg-transparent text-gray-600 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-700"><span class="material-icons text-sm">chevron_left</span></a>';
                    }

                    for($i=$startPage; $i<=$endPage; $i++) {
                        $activeClass = $i === $page ? 'bg-[#1E3A1D] dark:bg-green-600 text-white shadow' : 'bg-transparent text-gray-600 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-700';
                        echo '<a href="?page='.$i.'&role_type='.urlencode($role_filter).'&action_type='.urlencode($action_filter).'&user_id='.urlencode($user_filter).'&search='.urlencode($search).'&date_from='.urlencode($date_from).'&date_to='.urlencode($date_to).'" class="w-8 h-8 flex items-center justify-center rounded font-bold transition '.$activeClass.'">'.$i.'</a>';
                    }

                    if($page < $totalPages) {
                        echo '<a href="?page='.($page+1).'&role_type='.urlencode($role_filter).'&action_type='.urlencode($action_filter).'&user_id='.urlencode($user_filter).'&search='.urlencode($search).'&date_from='.urlencode($date_from).'&date_to='.urlencode($date_to).'" class="w-8 h-8 flex items-center justify-center rounded transition bg-transparent text-gray-600 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-700"><span class="material-icons text-sm">chevron_right</span></a>';
                    }
                    ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <div id="viewModal" class="fixed inset-0 bg-[#1E3A1D] dark:bg-black bg-opacity-40 dark:bg-opacity-60 flex justify-center items-center z-50 hidden backdrop-blur-sm transition-opacity modal-z">
        <div class="bg-white dark:bg-slate-900 rounded-2xl w-full max-w-lg shadow-2xl overflow-hidden flex flex-col border border-gray-200 dark:border-slate-700 relative">
            <div class="bg-[#1E3A1D] dark:bg-slate-800 p-5 text-white flex justify-between items-center flex-shrink-0 border-b border-[#2a4e29] dark:border-slate-700">
                <h2 class="text-lg font-bold flex items-center gap-2"><span class="material-icons text-sm">manage_search</span> Log Details</h2>
                <button onclick="closeModal()" class="text-gray-300 hover:text-white transition focus:outline-none"><span class="material-icons">close</span></button>
            </div>
            
            <div class="p-6 overflow-y-auto custom-scroll max-h-[70vh] bg-gray-50 dark:bg-slate-900">
                <div class="mb-4">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-500 uppercase tracking-wider mb-1">Action Overview</p>
                    <div id="modalAction" class="font-bold text-lg text-[#1E3A1D] dark:text-green-400 mb-1"></div>
                    <div id="modalDetails" class="text-sm text-gray-700 dark:text-slate-300 bg-white dark:bg-slate-800 p-3 rounded-lg border border-gray-200 dark:border-slate-700"></div>
                </div>

                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div class="bg-white dark:bg-slate-800 p-3 rounded-lg border border-gray-200 dark:border-slate-700">
                        <p class="text-[10px] font-bold text-gray-400 dark:text-slate-500 uppercase tracking-wider mb-1">Timestamp</p>
                        <p id="modalTime" class="text-sm font-mono font-bold text-gray-800 dark:text-white"></p>
                    </div>
                    <div class="bg-white dark:bg-slate-800 p-3 rounded-lg border border-gray-200 dark:border-slate-700">
                        <p class="text-[10px] font-bold text-gray-400 dark:text-slate-500 uppercase tracking-wider mb-1">Module</p>
                        <p id="modalModule" class="text-sm font-bold text-gray-800 dark:text-white"></p>
                    </div>
                </div>

                <div class="mb-4">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-500 uppercase tracking-wider mb-1">Session Info</p>
                    <div class="bg-white dark:bg-slate-800 p-3 rounded-lg border border-gray-200 dark:border-slate-700 text-xs font-mono space-y-1 text-gray-600 dark:text-slate-300">
                        <p><span class="font-bold text-gray-400 dark:text-slate-500 w-16 inline-block">User:</span> <span id="modalUser"></span></p>
                        <p><span class="font-bold text-gray-400 dark:text-slate-500 w-16 inline-block">IP:</span> <span id="modalIP"></span></p>
                        <p><span class="font-bold text-gray-400 dark:text-slate-500 w-16 inline-block align-top">Agent:</span> <span id="modalAgent" class="inline-block w-[260px] truncate" title=""></span></p>
                    </div>
                </div>

                <div id="modalMetaContainer" class="hidden">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-500 uppercase tracking-wider mb-1">Metadata / Changes</p>
                    <div id="modalMeta" class="bg-gray-800 dark:bg-black p-3 rounded-lg border border-gray-700 text-xs font-mono text-green-400 whitespace-pre-wrap overflow-x-auto custom-scroll"></div>
                </div>
            </div>
            
            <div class="p-5 border-t border-gray-200 dark:border-slate-800 bg-white dark:bg-slate-900 flex justify-end flex-shrink-0">
                <button onclick="closeModal()" class="bg-[#1E3A1D] dark:bg-green-600 text-white px-6 py-2.5 rounded-lg text-sm font-bold hover:bg-[#2a4e29] dark:hover:bg-green-500 shadow-md transition transform hover:-translate-y-0.5">Close</button>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('secure-body').style.display = 'block';
        
        // ==========================================
        // HTMX-STYLE AJAX ENGINE (Stitches Search, Tabs & Filters)
        // ==========================================
        const filterForm = document.getElementById('filterForm');
        const liveSearchInput = document.getElementById('searchInput');
        const tableContainer = document.getElementById('tableDataArea');
        const resetBtn = document.getElementById('resetFiltersBtn');
        
        // Track the currently clicked tab in memory
        let activeRole = new URLSearchParams(window.location.search).get('role_type') || '';
        let searchTimeout;

        function performAjaxSearch(fetchUrl = null) {
            if (!tableContainer) return;

            let url;
            if (fetchUrl) {
                url = new URL(fetchUrl, window.location.origin);
                // Keep our memory in sync if a pagination link changes the role
                activeRole = url.searchParams.get('role_type') || ''; 
            } else {
                url = new URL(window.location.pathname, window.location.origin);
                
                // 1. Force the Search Input into the URL
                if (liveSearchInput && liveSearchInput.value.trim() !== '') {
                    url.searchParams.set('search', liveSearchInput.value.trim());
                }

                // 2. Force the Active Tab into the URL
                if (activeRole) {
                    url.searchParams.set('role_type', activeRole);
                }

                // 3. Grab the Dropdowns and Dates from the Filter Form
                if (filterForm) {
                    const formData = new FormData(filterForm);
                    for (const [key, value] of formData.entries()) {
                        // Skip search and role_type if they are in the form, we handled them above!
                        if (value && key !== 'search' && key !== 'role_type') {
                            url.searchParams.set(key, value);
                        }
                    }
                }
            }

            tableContainer.style.opacity = '0.5';

            fetch(url.toString())
            .then(res => res.text())
            .then(html => {
                const parser = new DOMParser();
                const newDoc = parser.parseFromString(html, 'text/html');
                
                // Swap the Table and Pagination
                const newTableContainer = newDoc.getElementById('tableDataArea');
                if (newTableContainer) {
                    tableContainer.innerHTML = newTableContainer.innerHTML;
                }

                // Swap the Tabs (this updates the colors so the active tab gets highlighted!)
                const oldTab = document.querySelector('.role-tab-btn');
                if (oldTab) {
                    const oldTabContainer = oldTab.parentElement;
                    const newTabContainer = newDoc.querySelector('.role-tab-btn')?.parentElement;
                    if (oldTabContainer && newTabContainer) {
                        oldTabContainer.innerHTML = newTabContainer.innerHTML;
                    }
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
            liveSearchInput.addEventListener('keyup', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(performAjaxSearch, 300);
            });
            liveSearchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') e.preventDefault();
            });
        }

        // 2. Dropdown & Date Filters Auto-submit
        if (filterForm) {
            const inputs = filterForm.querySelectorAll('select, input[type="date"]');
            inputs.forEach(input => {
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
                
                // Clear the search bar
                if (liveSearchInput) liveSearchInput.value = '';
                
                // Reset form dropdowns and dates
                if (filterForm) {
                    const inputs = filterForm.querySelectorAll('select, input[type="date"]');
                    inputs.forEach(input => {
                        if (input.tagName === 'SELECT') input.value = input.options[0].value;
                        else input.value = ''; 
                    });
                }
                
                // Note: We intentionally do NOT reset `activeRole` here. 
                // If you are on the "Staff" tab and hit reset, it should keep you on "Staff" but wipe the dates/search.
                performAjaxSearch();
            });
        }

        // 4. Role Tabs Interceptor
        document.addEventListener('click', function(e) {
            const tabBtn = e.target.closest('.role-tab-btn');
            if (tabBtn) {
                e.preventDefault(); // Stop the page from refreshing
                
                // Read the link the tab was trying to go to, and save that role
                const urlParams = new URLSearchParams(tabBtn.search);
                activeRole = urlParams.get('role_type') || '';
                
                performAjaxSearch();
            }
        });

        // 5. Pagination Interceptor
        document.addEventListener('click', function(e) {
            const pageLink = e.target.closest('a[href*="?page="]');
            if (pageLink && tableContainer && tableContainer.contains(pageLink)) {
                e.preventDefault(); 
                performAjaxSearch(pageLink.href); 
            }
        });

        // ==========================================
        // UI TOGGLES & MODALS
        // ==========================================
        const toggleFilterBtn = document.getElementById('toggleFilterBtn');
        const filterOptions = document.getElementById('filterOptions');
        const searchInputBox = document.getElementById('searchInput');
        const hiddenSearchBox = document.getElementById('hiddenSearch');

        if (toggleFilterBtn && filterOptions) {
            toggleFilterBtn.addEventListener('click', () => {
                const isHidden = filterOptions.style.display === 'none';
                if (isHidden) {
                    filterOptions.style.display = 'block';
                    setTimeout(() => {
                        filterOptions.style.opacity = '1';
                        filterOptions.style.maxHeight = '500px';
                    }, 10);
                } else {
                    filterOptions.style.opacity = '0';
                    filterOptions.style.maxHeight = '0px';
                    setTimeout(() => {
                        filterOptions.style.display = 'none';
                    }, 300);
                }
            });
        }

        if (searchInputBox && hiddenSearchBox) {
            searchInputBox.addEventListener('input', (e) => hiddenSearchBox.value = e.target.value);
        }

        // --- Modal Logic ---
        const modal = document.getElementById('viewModal');

        function viewLogDetails(row) {
            const timeStr = row.cells[0].innerText.trim().replace(/\n/g, ' ');
            const userStr = row.cells[1].innerText.trim().replace(/\n/g, ' ');
            const modStr = row.cells[2].innerText.trim();
            const actionText = row.querySelector('.log-action').innerText.trim();
            const detailText = row.querySelector('.log-details').getAttribute('title');

            document.getElementById('modalTime').textContent = timeStr;
            document.getElementById('modalUser').textContent = userStr;
            document.getElementById('modalModule').textContent = modStr;
            document.getElementById('modalAction').textContent = actionText;
            document.getElementById('modalDetails').textContent = detailText;
            
            document.getElementById('modalIP').textContent = row.dataset.ip;
            document.getElementById('modalAgent').textContent = row.dataset.agent;
            document.getElementById('modalAgent').title = row.dataset.agent;

            const metaContainer = document.getElementById('modalMetaContainer');
            const metaDiv = document.getElementById('modalMeta');
            
            if (row.dataset.meta && row.dataset.meta.trim() !== '') {
                try {
                    const metaObj = JSON.parse(row.dataset.meta);
                    if (metaObj.changes && typeof metaObj.changes === 'object') {
                        let html = '<ul class="space-y-1">';
                        for (const [key, val] of Object.entries(metaObj.changes)) {
                            if (val && typeof val === 'object' && 'old' in val && 'new' in val) {
                                html += `<li><span class="text-gray-400">${key}:</span> <span class="text-red-400">${val.old}</span> &rarr; <span class="text-green-400">${val.new}</span></li>`;
                            } else {
                                html += `<li><span class="text-gray-400">${key}:</span> <span class="text-green-400">${JSON.stringify(val)}</span></li>`;
                            }
                        }
                        html += '</ul>';
                        metaDiv.innerHTML = html;
                    } else {
                        metaDiv.textContent = JSON.stringify(metaObj, null, 2);
                    }
                    metaContainer.classList.remove('hidden');
                } catch(e) {
                    metaDiv.textContent = row.dataset.meta;
                    metaContainer.classList.remove('hidden');
                }
            } else {
                metaContainer.classList.add('hidden');
            }
            modal.classList.remove('hidden');
        }

        function closeModal() {
            modal.classList.add('hidden');
        }

    </script>
</body>
</html>