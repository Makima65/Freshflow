<?php
// C:\xampp\htdocs\RalphPHP\admin_components\pages\order_queue.php

// 1. START SESSION & BUFFERING
session_start();
ob_start();

// 2. SECURITY CHECK (CRITICAL)
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: ../admin_login.php");
    exit;
}

// 3. STRICT CACHE HEADERS
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1
header("Pragma: no-cache"); // HTTP 1.0
header("Expires: 0"); // Proxies

include_once '../includes/db_connection.php';

// --- DATABASE AUTO-PATCHER ---
// Fixes the issue where payment_status rejects the "Replacement #..." tag
@$conn->query("ALTER TABLE sales MODIFY COLUMN payment_status VARCHAR(100) DEFAULT 'Pending'");

// --- AUDIT HELPER ---
$auditHelperPath = '../includes/audit_helper.php';
if (file_exists($auditHelperPath)) { include_once $auditHelperPath; } 
else { if (!function_exists('log_audit_action')) { function log_audit_action($a, $b, $c, $d = []) { return true; } } }

// --- 1. HANDLE AJAX REQUEST FOR ITEMS (Populates the Modal) ---
if (isset($_GET['action']) && $_GET['action'] === 'get_items' && isset($_GET['id'])) {
    ob_clean();
    header('Content-Type: application/json');
    
    $sale_id = intval($_GET['id']);
    
    $query = "
        SELECT si.*, p.name, p.product_brand, p.image_url, p.unit
        FROM sales_items si
        JOIN products p ON si.product_id = p.product_id
        WHERE si.sale_id = ?
    ";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $sale_id);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode($items);
    exit;
}

// Ensure filters are kept in URL when action is submitted
$current_qs = $_SERVER['QUERY_STRING'];
// Remove specific action flags to avoid infinite loops if we reuse query string
$current_qs = preg_replace('/(&?)success=[^&]*/', '', $current_qs);
$current_qs = preg_replace('/(&?)error=[^&]*/', '', $current_qs);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // ACTION: UPDATE STATUS
    if ($_POST['action'] === 'update_status') {
        $sale_id = intval($_POST['sale_id']);
        $new_status = $_POST['new_status'];
        
        $sql = "UPDATE sales SET order_status = ?";
        if ($new_status === 'Completed') {
            $sql .= ", delivered_at = NOW(), payment_status = 'Pending'"; 
        }
        $sql .= " WHERE sale_id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $new_status, $sale_id);
        if ($stmt->execute()) {
            if (function_exists('log_audit_action')) {
                log_audit_action('Update Status', 'Fulfillment', "Updated Order #$sale_id status to $new_status");
            }
        }
        
        $redirect_url = "order_queue.php" . ($current_qs ? "?$current_qs&" : "?") . "success=status_updated";
        header("Location: $redirect_url");
        exit;
    }

    // ACTION: CANCEL ORDER
    if ($_POST['action'] === 'cancel_order') {
        $sale_id = intval($_POST['sale_id']);

        if ($sale_id > 0) {
            $conn->begin_transaction();
            try {
                // 1. Get items to restore inventory
                $stmt = $conn->prepare("SELECT product_id, quantity FROM sales_items WHERE sale_id = ?");
                $stmt->bind_param("i", $sale_id);
                $stmt->execute();
                $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

                // 2. Restore Inventory
                $update_inv = $conn->prepare("UPDATE product_inventory SET quantity = quantity + ? WHERE product_id = ?");
                foreach ($items as $item) {
                    $update_inv->bind_param("di", $item['quantity'], $item['product_id']);
                    $update_inv->execute();
                }

                // 3. Update Status to Cancelled
                $cancel_stmt = $conn->prepare("UPDATE sales SET order_status = 'Cancelled', payment_status = 'Cancelled' WHERE sale_id = ?");
                $cancel_stmt->bind_param("i", $sale_id);
                $cancel_stmt->execute();

                $conn->commit();
                
                if (function_exists('log_audit_action')) {
                    log_audit_action('Cancel Order', 'Fulfillment', "Cancelled Order #$sale_id and restocked items");
                }

                $redirect_url = "order_queue.php" . ($current_qs ? "?$current_qs&" : "?") . "success=order_cancelled";
                header("Location: $redirect_url");
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                $redirect_url = "order_queue.php" . ($current_qs ? "?$current_qs&" : "?") . "error=cancel_failed";
                header("Location: $redirect_url");
                exit;
            }
        }
    }
}

// --- 3. FETCH ALL ORDERS FOR STATS & PHP FILTERING ---
$query = "
    SELECT s.*, c.client_name
    FROM sales s
    LEFT JOIN clients c ON s.client_id = c.client_id
    ORDER BY 
        CASE 
            WHEN s.order_status = 'Pending' THEN 1
            WHEN s.order_status = 'Packed' THEN 2
            WHEN s.order_status = 'Out for Delivery' THEN 3
            WHEN s.order_status = 'Completed' THEN 4
            ELSE 5 -- Cancelled last
        END,
        s.created_at DESC
";
$all_orders = $conn->query($query)->fetch_all(MYSQLI_ASSOC);

// --- 4. CALCULATE STATS (Based on ALL data before filtering) ---
$stats = [
    'Pending' => 0, 'Packed' => 0, 'Out for Delivery' => 0, 'Completed' => 0, 'Cancelled' => 0, 'Total_Revenue_Pending' => 0
];
foreach ($all_orders as $o) {
    $status = $o['order_status'] ?? 'Pending';
    if (isset($stats[$status])) $stats[$status]++;
    
    if ($status !== 'Cancelled' && $status !== 'Completed' && strpos($o['payment_status'] ?? '', 'Replacement') === false && (float)$o['total_amount'] > 0) {
        $stats['Total_Revenue_Pending'] += (float)($o['total_amount'] ?? 0);
    }
}

// --- 5. SERVER-SIDE FILTERING & SORTING ---
$status_filter = $_GET['status'] ?? 'All';
$type_filter = $_GET['type'] ?? 'all';
$sort_filter = $_GET['sort'] ?? 'default';
$search_query = trim(strtolower($_GET['search'] ?? ''));

$filtered_orders = array_filter($all_orders, function($o) use ($status_filter, $type_filter, $search_query) {
    $s = $o['order_status'] ?? 'Pending';
    
    // Type detection
    $isReplacement = false;
    if (!empty($o['payment_status']) && strpos($o['payment_status'], 'Replacement') !== false) {
        $isReplacement = true;
    } elseif (isset($o['total_amount']) && (float)$o['total_amount'] == 0.00) {
        $isReplacement = true;
    }
    $orderType = $isReplacement ? 'redelivery' : 'normal';

    // 1. Status Match
    $statusMatch = false;
    if ($status_filter === 'All') {
        $statusMatch = ($s !== 'Cancelled'); // Keep cancelled hidden in "All" view
    } else {
        $statusMatch = ($s === $status_filter);
    }

    // 2. Type Match
    $typeMatch = ($type_filter === 'all') || ($orderType === $type_filter);

    // 3. Search Match
    $searchMatch = true;
    if ($search_query !== '') {
        $idStr = str_pad($o['sale_id'], 5, '0', STR_PAD_LEFT);
        $idMatch = strpos($idStr, $search_query) !== false || strpos((string)$o['sale_id'], $search_query) !== false;
        $clientMatch = strpos(strtolower($o['client_name'] ?? ''), $search_query) !== false;
        $searchMatch = $idMatch || $clientMatch;
    }

    return $statusMatch && $typeMatch && $searchMatch;
});

// Re-index and Sort
if ($sort_filter === 'newest') {
    usort($filtered_orders, fn($a, $b) => (int)$b['sale_id'] <=> (int)$a['sale_id']);
} elseif ($sort_filter === 'oldest') {
    usort($filtered_orders, fn($a, $b) => (int)$a['sale_id'] <=> (int)$b['sale_id']);
} else {
    // Preserve default DB order (which is by priority)
    $filtered_orders = array_values($filtered_orders); 
}

// --- 6. PAGINATION ---
$per_page = 15; // Set how many rows you want per page
$total_items = count($filtered_orders);
$total_pages = ceil($total_items / $per_page);

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
if ($page > $total_pages && $total_pages > 0) $page = $total_pages;

$offset = ($page - 1) * $per_page;
$orders_to_display = array_slice($filtered_orders, $offset, $per_page);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreshFlow - Order Fulfillment</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
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
        body { font-family: 'Inter', sans-serif; background-color: var(--brand-cream); color: #2B2B2B; transition: background-color 0.3s ease;}
        
        /* DARK MODE BODY */
        .dark body {
            background-color: #000000;
            background-image: radial-gradient(circle, rgba(255, 255, 255, 0.07) 1px, transparent 1px);
            background-size: 16px 16px;
            color: #f8fafc;
        }

        .font-mono { font-family: 'JetBrains Mono', monospace; }
        .font-heading { font-family: 'Inter', sans-serif; }
        
        .custom-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scroll::-webkit-scrollbar-track { background: transparent; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 3px; }
        .dark .custom-scroll::-webkit-scrollbar-thumb { background: #334155; }
        
        /* Stepper */
        .step-connector { flex-grow: 1; height: 2px; background-color: #E2E8F0; margin: 0 4px; }
        .dark .step-connector { background-color: #334155; }
        .step-connector.active { background-color: var(--brand-green); }
        .dark .step-connector.active { background-color: #4ade80; }

        .step-circle { width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: bold; color: white; background-color: #E2E8F0; }
        .dark .step-circle { background-color: #334155; }
        .step-circle.active { background-color: var(--brand-green); }
        .dark .step-circle.active { background-color: #4ade80; color: #000; }
        
        /* Actions */
        .btn-action { transition: all 0.2s; }
        .btn-action:active { transform: scale(0.95); }
        
        /* Cancelled Style */
        .order-row[data-status="Cancelled"] { opacity: 0.6; background-color: #f9fafb !important; }
        .dark .order-row[data-status="Cancelled"] { background-color: #0f172a !important; }
        .order-row[data-status="Cancelled"] td { text-decoration: line-through; color: #9ca3af; }
        .order-row[data-status="Cancelled"] .no-strike { text-decoration: none; }

        @media print {
            body * { visibility: hidden; }
            #orderModal, #orderModal * { visibility: visible; }
            #orderModal { position: absolute; left: 0; top: 0; width: 100%; height: auto; background: white; padding: 0; }
            .no-print { display: none !important; }
        }
    </style>

    <script>
        window.onpageshow = function(event) { if (event.persisted) window.location.reload(); };
        window.onbeforeunload = function() { document.body.style.backgroundColor = document.documentElement.classList.contains('dark') ? "#000" : "#F8F5EE"; };
    </script>
</head>

<script>
    // ----------------------------------------------------------------
    // AJAX LIVE SEARCH, FILTERING, & PAGINATION
    // ----------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function() {
        
        const searchInput = document.getElementById('searchInput');
        const typeSelect = document.getElementById('typeSelect');
        const sortSelect = document.getElementById('sortSelect');
        const filterForm = document.getElementById('filterForm');
        
        // This targets the big container that holds BOTH your table and the pagination footer
        const tableContainer = document.getElementById('tableDataArea');

        let timeoutId;

        // Function to actually fetch the new data (Now accepts a specific URL for pagination!)
        function performAjaxSearch(fetchUrl = null) {
            if (!tableContainer) return; // Safety check

            let url;
            if (fetchUrl) {
                // If a specific URL is passed (like clicking a Pagination link)
                url = new URL(fetchUrl, window.location.origin);
            } else {
                // 1. Get all the current filter values from the URL form
                url = new URL(window.location.pathname, window.location.origin);
                const formData = new FormData(filterForm);
                
                // Build the query string
                for (const [key, value] of formData.entries()) {
                    url.searchParams.set(key, value);
                }
            }

            // 2. Add a slight visual cue that it's loading
            tableContainer.style.opacity = '0.5';

            // 3. Fetch the new page in the background
            fetch(url.toString())
            .then(response => response.text())
            .then(html => {
                // 4. Create a fake DOM to read the response
                const parser = new DOMParser();
                const newDoc = parser.parseFromString(html, 'text/html');
                
                // 5. Find the new table container using our bulletproof ID
                const newTableContainer = newDoc.getElementById('tableDataArea');
                
                // 6. Swap the HTML instantly!
                if (newTableContainer) {
                    tableContainer.innerHTML = newTableContainer.innerHTML;
                }
                
                // 7. Update the browser URL without reloading
                window.history.pushState({}, '', url.toString());
                
                // Remove loading visual
                tableContainer.style.opacity = '1';
            })
            .catch(err => {
                console.error('AJAX Error:', err);
                tableContainer.style.opacity = '1';
            });
        }
        
        // --- LISTENERS ---

        // Listen for typing in the search box
        if (searchInput) {
            searchInput.addEventListener('keyup', function() {
                clearTimeout(timeoutId);
                timeoutId = setTimeout(() => performAjaxSearch(), 300); 
            });
            
            // Prevent hitting 'Enter' from reloading the page
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') e.preventDefault(); 
            });
        }

        // Listen for changes on the dropdowns
        if (typeSelect) typeSelect.addEventListener('change', () => performAjaxSearch());
        if (sortSelect) sortSelect.addEventListener('change', () => performAjaxSearch());

        // Hijack the status buttons so they don't reload the page
        window.setStatus = function(status) {
            document.getElementById('statusInput').value = status;
            
            // Update button styles manually (so it looks clicked)
            const buttons = document.querySelectorAll('button[onclick^="setStatus"]');
            buttons.forEach(btn => {
                if (btn.textContent.trim() === status || (status === 'Out for Delivery' && btn.textContent.trim() === 'In Transit')) {
                    btn.className = 'bg-[#1E3A1D] dark:bg-slate-600 text-white shadow-md px-4 py-1.5 rounded-md text-sm font-medium transition whitespace-nowrap';
                } else {
                    btn.className = 'text-gray-500 dark:text-slate-400 hover:bg-white dark:hover:bg-slate-700 hover:text-gray-800 dark:hover:text-white px-4 py-1.5 rounded-md text-sm font-medium transition whitespace-nowrap';
                }
            });

            performAjaxSearch();
        };

        // --- 4. PAGINATION INTERCEPTOR (The Magic Trick!) ---
        document.addEventListener('click', function(e) {
            // Check if the user clicked a pagination link inside our table area
            const pageLink = e.target.closest('a[href*="?page="]');
            if (pageLink && tableContainer.contains(pageLink)) {
                e.preventDefault(); // Stop page reload
                performAjaxSearch(pageLink.href); // Run AJAX with the specific page link's URL
            }
        });

    });
</script>
<body class="flex h-screen overflow-hidden">
    
    <?php include '../includes/sidebar.php'; ?>

    <main class="ml-20 flex-1 flex flex-col h-screen overflow-hidden relative transition-all duration-300 p-6 md:p-8">
        
        <header class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-8 flex-shrink-0">
            <div>
                <h1 class="text-3xl font-bold text-[#1E3A1D] dark:text-white tracking-tight flex items-center gap-3">
                    <span class="material-icons text-3xl dark:text-green-400">inventory</span> 
                    Fulfillment Queue
                </h1>
                <p class="text-sm text-gray-500 dark:text-slate-400 mt-1 ml-11">Track, pack, and ship client orders</p>
            </div>
            <a href="order_create.php" class="bg-[#1E3A1D] dark:bg-green-600 hover:bg-[#2a4e29] dark:hover:bg-green-500 text-white px-5 py-2.5 rounded-lg font-bold shadow-lg flex items-center gap-2 transition transform active:scale-95">
                <span class="material-icons text-sm">add</span> New Order
            </a>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8 flex-shrink-0">
            
            <div class="bg-white dark:bg-slate-900/80 border border-orange-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-pointer group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(249,115,22,0.2)] dark:hover:shadow-[0_0_20px_rgba(249,115,22,0.3)] dark:hover:border-orange-400 transition-all duration-300">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-orange-600 dark:group-hover:text-orange-400 transition-colors">To Pack</p>
                    <p class="font-heading text-3xl font-bold text-orange-600 dark:text-orange-400 mt-1 group-hover:scale-110 transition-transform origin-left font-mono"><?= $stats['Pending'] ?></p>
                </div>
                <div class="p-3 bg-orange-100 dark:bg-orange-900/30 text-orange-600 dark:text-orange-400 rounded-lg group-hover:bg-orange-200 dark:group-hover:bg-orange-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">pending_actions</span>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900/80 border border-blue-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-pointer group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(59,130,246,0.2)] dark:hover:shadow-[0_0_20px_rgba(59,130,246,0.3)] dark:hover:border-blue-400 transition-all duration-300">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">To Ship</p>
                    <p class="font-heading text-3xl font-bold text-blue-600 dark:text-blue-400 mt-1 group-hover:scale-110 transition-transform origin-left font-mono"><?= $stats['Packed'] ?></p>
                </div>
                <div class="p-3 bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 rounded-lg group-hover:bg-blue-200 dark:group-hover:bg-blue-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">inventory_2</span>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900/80 border border-red-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-pointer group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(239,68,68,0.2)] dark:hover:shadow-[0_0_20px_rgba(239,68,68,0.3)] dark:hover:border-red-400 transition-all duration-300">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-red-600 dark:group-hover:text-red-400 transition-colors">Cancelled</p>
                    <p class="font-heading text-3xl font-bold text-red-600 dark:text-red-400 mt-1 group-hover:scale-110 transition-transform origin-left font-mono"><?= $stats['Cancelled'] ?></p>
                </div>
                <div class="p-3 bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 rounded-lg group-hover:bg-red-200 dark:group-hover:bg-red-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">cancel</span>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900/80 border border-green-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-pointer group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(34,197,94,0.2)] dark:hover:shadow-[0_0_20px_rgba(34,197,94,0.3)] dark:hover:border-green-400 transition-all duration-300">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-green-600 dark:group-hover:text-green-400 transition-colors">Open Value</p>
                    <p class="font-heading text-2xl font-bold text-green-600 dark:text-green-400 mt-1 group-hover:scale-110 transition-transform origin-left font-mono">₱<?= number_format($stats['Total_Revenue_Pending'] / 1000, 1) ?>k</p>
                </div>
                <div class="p-3 bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400 rounded-lg group-hover:bg-green-200 dark:group-hover:bg-green-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">payments</span>
                </div>
            </div>

        </div>

        <form method="GET" id="filterForm" action="order_queue.php" class="bg-white dark:bg-slate-900/80 p-4 rounded-xl shadow-sm border border-gray-200 dark:border-slate-800 mb-6 flex flex-col md:flex-row justify-between items-center gap-4 flex-shrink-0">
            <input type="hidden" name="status" id="statusInput" value="<?= htmlspecialchars($status_filter) ?>">
            
            <div class="flex bg-gray-50 dark:bg-slate-800 p-1 rounded-lg border border-gray-200 dark:border-slate-700 shadow-sm overflow-x-auto w-full md:w-auto">
                <button type="button" onclick="setStatus('All')" class="<?= $status_filter === 'All' ? 'bg-[#1E3A1D] dark:bg-slate-600 text-white shadow-md' : 'text-gray-500 dark:text-slate-400 hover:bg-white dark:hover:bg-slate-700 hover:text-gray-800 dark:hover:text-white' ?> px-4 py-1.5 rounded-md text-sm font-medium transition whitespace-nowrap">All</button>
                <button type="button" onclick="setStatus('Pending')" class="<?= $status_filter === 'Pending' ? 'bg-[#1E3A1D] dark:bg-slate-600 text-white shadow-md' : 'text-gray-500 dark:text-slate-400 hover:bg-white dark:hover:bg-slate-700 hover:text-gray-800 dark:hover:text-white' ?> px-4 py-1.5 rounded-md text-sm font-medium transition whitespace-nowrap">Pending</button>
                <button type="button" onclick="setStatus('Packed')" class="<?= $status_filter === 'Packed' ? 'bg-[#1E3A1D] dark:bg-slate-600 text-white shadow-md' : 'text-gray-500 dark:text-slate-400 hover:bg-white dark:hover:bg-slate-700 hover:text-gray-800 dark:hover:text-white' ?> px-4 py-1.5 rounded-md text-sm font-medium transition whitespace-nowrap">Packed</button>
                <button type="button" onclick="setStatus('Out for Delivery')" class="<?= $status_filter === 'Out for Delivery' ? 'bg-[#1E3A1D] dark:bg-slate-600 text-white shadow-md' : 'text-gray-500 dark:text-slate-400 hover:bg-white dark:hover:bg-slate-700 hover:text-gray-800 dark:hover:text-white' ?> px-4 py-1.5 rounded-md text-sm font-medium transition whitespace-nowrap">In Transit</button>
                <button type="button" onclick="setStatus('Cancelled')" class="<?= $status_filter === 'Cancelled' ? 'bg-red-600 dark:bg-red-800 text-white shadow-md' : 'text-gray-500 dark:text-slate-400 hover:bg-red-50 dark:hover:bg-red-900/30 hover:text-red-600 dark:hover:text-red-400' ?> px-4 py-1.5 rounded-md text-sm font-medium transition whitespace-nowrap">Cancelled</button>
            </div>
            
            <div class="flex gap-2 w-full md:w-auto">
                <select name="type" id="typeSelect" class="pl-3 pr-8 py-2 text-sm border border-gray-200 dark:border-slate-700 rounded-lg focus:outline-none focus:border-[#1E3A1D] dark:focus:border-green-400 bg-white dark:bg-slate-800 cursor-pointer transition text-gray-700 dark:text-white font-medium">
                    <option value="all" <?= $type_filter == 'all' ? 'selected' : '' ?>>All Orders</option>
                    <option value="normal" <?= $type_filter == 'normal' ? 'selected' : '' ?>>Normal Orders</option>
                    <option value="redelivery" <?= $type_filter == 'redelivery' ? 'selected' : '' ?>>🔴 Redeliveries</option>
                </select>

                <select name="sort" id="sortSelect" class="pl-3 pr-8 py-2 text-sm border border-gray-200 dark:border-slate-700 rounded-lg focus:outline-none focus:border-[#1E3A1D] dark:focus:border-green-400 bg-white dark:bg-slate-800 cursor-pointer transition text-gray-700 dark:text-white">
                    <option value="default" <?= $sort_filter == 'default' ? 'selected' : '' ?>>Default Priority</option>
                    <option value="newest" <?= $sort_filter == 'newest' ? 'selected' : '' ?>>Newest First</option>
                    <option value="oldest" <?= $sort_filter == 'oldest' ? 'selected' : '' ?>>Oldest First</option>
                </select>

                <div class="relative w-full md:w-64">
                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400"><span class="material-icons text-sm">search</span></span>
                    <input type="text" name="search" id="searchInput" value="<?= htmlspecialchars($search_query) ?>" placeholder="Search ID or Client..." class="w-full pl-9 pr-3 py-2 text-sm border border-gray-200 dark:border-slate-700 rounded-lg focus:outline-none focus:border-[#1E3A1D] dark:focus:border-green-400 bg-white dark:bg-slate-800 text-gray-900 dark:text-white transition" autocomplete="off">
                </div>
            </div>
        </form>

        <div class="bg-white dark:bg-slate-900/80 rounded-xl shadow border border-gray-200 dark:border-slate-800 flex-1 overflow-hidden flex flex-col mb-4" id="tableDataArea">
            <div class="overflow-y-auto flex-1 custom-scroll">
                <table class="w-full text-left" id="ordersTable">
                    <thead class="bg-[#1E3A1D] dark:bg-slate-800 text-white text-xs uppercase font-bold sticky top-0 z-10 shadow-sm">
                        <tr>
                            <th class="p-4 w-24">Order ID</th>
                            <th class="p-4">Client Details</th>
                            <th class="p-4 w-48 text-center">Timeline</th>
                            <th class="p-4 w-40">Dates</th>
                            <th class="p-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody id="ordersTableBody" class="divide-y divide-gray-100 dark:divide-slate-800 text-sm text-gray-700 dark:text-gray-300">
                        <?php if (empty($orders_to_display)): ?>
                            <tr><td colspan="5" class="p-10 text-center text-gray-400 italic">No orders match your filter criteria.</td></tr>
                        <?php else: ?>
                            <?php foreach ($orders_to_display as $o): 
                                $s = $o['order_status'] ?? 'Pending';
                                
                                // DETECT IF THIS IS A REPLACEMENT ORDER
                                $isReplacement = false;
                                $parentRef = '';
                                
                                if (!empty($o['payment_status']) && strpos($o['payment_status'], 'Replacement') !== false) {
                                    $isReplacement = true;
                                    $parentRef = $o['payment_status']; 
                                } elseif (isset($o['total_amount']) && (float)$o['total_amount'] == 0.00) {
                                    $isReplacement = true;
                                    $parentRef = "Replacement Order"; 
                                }

                                $orderType = $isReplacement ? 'redelivery' : 'normal';

                                // Timeline Steps
                                $step1 = ($s == 'Pending' || $s == 'Packed' || $s == 'Out for Delivery' || $s == 'Completed');
                                $step2 = ($s == 'Packed' || $s == 'Out for Delivery' || $s == 'Completed');
                                $step3 = ($s == 'Out for Delivery' || $s == 'Completed');
                                $step4 = ($s == 'Completed');
                                
                                // Exact Dates Logic
                                $deliveryStr = $o['delivery_date'] ?? null;
                                $createdStr = $o['created_at'] ?? null;
                                $deliveredStr = $o['delivered_at'] ?? null;

                                $isToday = ($deliveryStr && date('Y-m-d') == date('Y-m-d', strtotime($deliveryStr)));
                                $isOverdue = ($deliveryStr && date('Y-m-d') > date('Y-m-d', strtotime($deliveryStr))) && $s != 'Completed' && $s != 'Cancelled';
                                
                                $clientName = htmlspecialchars($o['client_name'] ?? 'Unknown Client');
                                $isCancelled = ($s == 'Cancelled');
                                
                                // Dynamic Styles for Replacement
                                $idBadgeColor = $isReplacement ? 'bg-purple-100 dark:bg-purple-900/40 text-purple-800 dark:text-purple-300 border-purple-200 dark:border-purple-800' : 'bg-green-50 dark:bg-green-900/20 text-[#1E3A1D] dark:text-green-400 border-green-100 dark:border-green-800';
                                $rowBgClass = $isReplacement && !$isCancelled ? 'bg-purple-50/30 dark:bg-purple-900/10' : '';
                            ?>
                            <tr class="order-row group hover:bg-gray-50 dark:hover:bg-slate-800/50 transition <?= $rowBgClass ?>" 
                                data-status="<?= $s ?>" 
                                data-type="<?= $orderType ?>"
                                data-id="<?= $o['sale_id'] ?>">
                                
                                <td class="p-4 align-top">
                                    <div class="font-mono font-bold px-2 py-1 rounded border inline-block no-strike <?= $idBadgeColor ?>">
                                        #<?= str_pad($o['sale_id'], 5, '0', STR_PAD_LEFT) ?>
                                    </div>
                                </td>
                                <td class="p-4 align-top">
                                    <div class="font-bold text-gray-800 dark:text-white text-sm flex flex-col md:flex-row md:items-center gap-2">
                                        <?= $clientName ?>
                                        <?php if($isReplacement && !$isCancelled): ?>
                                            <span class="inline-block bg-purple-100 dark:bg-purple-900/50 text-purple-800 dark:text-purple-300 border border-purple-200 dark:border-purple-800 px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide no-strike shadow-sm w-max">
                                                🔴 REDELIVERY (<?= $parentRef ?>)
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if(!$isCancelled): ?>
                                        <div class="text-xs text-gray-500 dark:text-slate-400 mt-1 flex items-center gap-1"><span class="material-icons text-[10px]">person</span> Valid Customer</div>
                                        <div class="mt-2 text-xs font-bold text-gray-400 dark:text-slate-500 uppercase">
                                            Value: 
                                            <?php if($isReplacement): ?>
                                                <span class="text-purple-700 dark:text-purple-400 font-mono text-sm font-bold bg-white dark:bg-slate-800 px-1 py-0.5 rounded border border-purple-100 dark:border-purple-800">₱0.00 (Pre-paid)</span>
                                            <?php else: ?>
                                                <span class="text-gray-700 dark:text-slate-300 font-mono text-sm">₱<?= number_format((float)($o['total_amount'] ?? 0), 2) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-xs text-red-400 mt-1 font-bold uppercase no-strike">ORDER CANCELLED</div>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 align-middle">
                                    <?php if(!$isCancelled): ?>
                                        <div class="flex items-center justify-center w-full max-w-[180px] mx-auto">
                                            <div class="step-circle <?= $step1 ? 'active' : '' ?>" title="Pending">1</div>
                                            <div class="step-connector <?= $step2 ? 'active' : '' ?>"></div>
                                            <div class="step-circle <?= $step2 ? 'active' : '' ?>" title="Packed">2</div>
                                            <div class="step-connector <?= $step3 ? 'active' : '' ?>"></div>
                                            <div class="step-circle <?= $step3 ? 'active' : '' ?>" title="Out for Delivery">3</div>
                                            <div class="step-connector <?= $step4 ? 'active' : '' ?>"></div>
                                            <div class="step-circle <?= $step4 ? 'active' : '' ?>" title="Completed"><span class="material-icons text-[10px]">check</span></div>
                                        </div>
                                        <div class="text-[10px] font-bold text-gray-400 dark:text-slate-500 mt-1 uppercase tracking-wide text-center w-full max-w-[180px] mx-auto"><?= $s ?></div>
                                    <?php else: ?>
                                        <div class="bg-red-50 dark:bg-red-900/30 text-red-500 dark:text-red-400 text-xs font-bold px-3 py-1 rounded-full text-center border border-red-100 dark:border-red-800 no-strike mx-auto w-max">
                                            <span class="material-icons text-sm align-middle">block</span> Cancelled
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 align-top">
                                    <div class="text-[10px] font-bold text-gray-400 dark:text-slate-500 uppercase tracking-wider mb-0.5">Delivery Due</div>
                                    <div class="text-sm font-bold text-gray-800 dark:text-white">
                                        <?= $deliveryStr ? date('M d, Y', strtotime($deliveryStr)) : 'Not Set' ?>
                                    </div>
                                    
                                    <div class="mt-2 text-[10px] text-gray-500 dark:text-slate-400 font-mono">
                                        <span class="font-bold text-gray-400 dark:text-slate-500 uppercase">Created:</span><br>
                                        <?= $createdStr ? date('M d, Y h:i A', strtotime($createdStr)) : 'Unknown' ?>
                                    </div>
                                    
                                    <?php if($s == 'Completed' && $deliveredStr): ?>
                                        <div class="mt-2 text-[10px] text-green-600 dark:text-green-400 font-mono">
                                            <span class="font-bold uppercase">Delivered:</span><br>
                                            <?= date('M d, Y h:i A', strtotime($deliveredStr)) ?>
                                        </div>
                                        <span class="inline-block mt-1 px-2 py-0.5 rounded text-[10px] font-bold bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 border border-green-200 dark:border-green-800 no-strike">DELIVERED</span>
                                    <?php elseif($isToday && !$isCancelled): ?>
                                        <span class="inline-block mt-1 px-2 py-0.5 rounded text-[10px] font-bold bg-orange-100 dark:bg-orange-900/30 text-orange-700 dark:text-orange-400 border border-orange-200 dark:border-orange-800 no-strike">DUE TODAY</span>
                                    <?php elseif($isOverdue): ?>
                                        <span class="inline-block mt-1 px-2 py-0.5 rounded text-[10px] font-bold bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 border border-red-200 dark:border-red-800 no-strike">OVERDUE</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 align-middle text-right">
                                    <div class="flex justify-end gap-2 items-center no-strike">
                                        
                                        <?php if ($s == 'Packed'): ?>
                                            <form method="POST" action="order_queue.php?<?= htmlspecialchars($_SERVER['QUERY_STRING']) ?>" class="inline">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="sale_id" value="<?= $o['sale_id'] ?>">
                                                <button type="submit" name="new_status" value="Pending" title="Undo to Pending" class="bg-gray-100 dark:bg-slate-700 hover:bg-gray-200 dark:hover:bg-slate-600 text-gray-600 dark:text-gray-300 px-2 py-1.5 rounded shadow-sm btn-action transition"><span class="material-icons text-sm">undo</span></button>
                                            </form>
                                        <?php endif; ?>

                                        <form method="POST" action="order_queue.php?<?= htmlspecialchars($_SERVER['QUERY_STRING']) ?>" class="inline">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="sale_id" value="<?= $o['sale_id'] ?>">
                                            
                                            <?php if ($s == 'Pending'): ?>
                                                <button type="submit" name="new_status" value="Packed" class="bg-[#1E3A1D] dark:bg-green-700 hover:bg-[#2a4e29] dark:hover:bg-green-600 text-white px-3 py-1.5 rounded text-xs font-bold shadow-sm btn-action flex items-center gap-1">Pack <span class="material-icons text-[14px]">inventory_2</span></button>
                                            
                                            <?php elseif ($s == 'Packed'): ?>
                                                <span class="text-xs text-blue-500 dark:text-blue-400 font-bold bg-blue-50 dark:bg-blue-900/20 px-2 py-1 rounded border border-blue-100 dark:border-blue-800 cursor-default" title="Assign Driver in Dispatch Module">Ready for Dispatch</span>
                                            
                                            <?php elseif ($s == 'Out for Delivery'): ?>
                                                <span class="text-xs text-purple-500 dark:text-purple-400 font-bold bg-purple-50 dark:bg-purple-900/20 px-2 py-1 rounded border border-purple-100 dark:border-purple-800 cursor-default">On the Way</span>
                                            
                                            <?php elseif ($s == 'Cancelled'): ?>
                                                <span class="text-xs text-gray-400 dark:text-slate-500 italic">No Actions</span>
                                            <?php endif; ?>
                                        </form>
                                        
                                        <button onclick="openOrderModal(<?= $o['sale_id'] ?>, '<?= addslashes($clientName) ?>', '<?= $deliveryStr ? date('M d, Y', strtotime($deliveryStr)) : 'N/A' ?>')" class="border border-gray-200 dark:border-slate-700 hover:border-[#1E3A1D] dark:hover:border-green-400 hover:text-[#1E3A1D] dark:hover:text-green-400 text-gray-500 dark:text-slate-400 px-2 py-1.5 rounded transition bg-white dark:bg-slate-800" title="View Details"><span class="material-icons text-sm">visibility</span></button>
                                        
                                        <?php if ($s != 'Cancelled' && $s != 'Completed'): ?>
                                            <form method="POST" action="order_queue.php?<?= htmlspecialchars($_SERVER['QUERY_STRING']) ?>" class="inline" onsubmit="return confirm('Are you sure you want to CANCEL this order?\n\n- Stock will be restored\n- Order will be marked Cancelled');">
                                                <input type="hidden" name="action" value="cancel_order">
                                                <input type="hidden" name="sale_id" value="<?= $o['sale_id'] ?>">
                                                <button type="submit" class="bg-gray-100 dark:bg-slate-800 hover:bg-red-50 dark:hover:bg-red-900/20 text-gray-400 dark:text-slate-500 hover:text-red-500 dark:hover:text-red-400 px-2 py-1.5 rounded transition border border-gray-200 dark:border-slate-700 hover:border-red-200 dark:hover:border-red-800" title="Cancel Order"><span class="material-icons text-sm">block</span></button>
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
            <div class="p-4 border-t border-gray-200 dark:border-slate-800 bg-gray-50 dark:bg-slate-900 flex flex-col md:flex-row justify-between items-center text-sm z-10 sticky bottom-0">
                <span class="text-gray-500 dark:text-slate-400 mb-2 md:mb-0">
                    Showing <span class="font-bold text-gray-900 dark:text-white"><?= $total_items > 0 ? $offset + 1 : 0 ?></span> 
                    to <span class="font-bold text-gray-900 dark:text-white"><?= min($offset + $per_page, $total_items) ?></span> 
                    of <span class="font-bold text-gray-900 dark:text-white"><?= $total_items ?></span> orders
                </span>
                
                <?php if($total_pages > 1): ?>
                <div class="flex items-center gap-1 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg p-1 shadow-sm">
                    <?php for($i=1; $i<=$total_pages; $i++): ?>
                        <a href="?page=<?= $i ?>&status=<?= urlencode($status_filter) ?>&type=<?= urlencode($type_filter) ?>&sort=<?= urlencode($sort_filter) ?>&search=<?= urlencode($search_query) ?>" 
                           class="w-8 h-8 flex items-center justify-center rounded font-bold transition <?= $i === $page ? 'bg-[#1E3A1D] dark:bg-green-600 text-white shadow' : 'bg-transparent text-gray-600 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-700' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>
    </main>

    <div id="orderModal" class="fixed inset-0 bg-[#1E3A1D] dark:bg-black bg-opacity-40 dark:bg-opacity-60 hidden z-50 flex justify-center items-center backdrop-blur-sm modal print-full-width p-4 transition-opacity">
        <div class="bg-white dark:bg-slate-900 rounded-xl shadow-2xl w-full max-w-2xl overflow-hidden flex flex-col max-h-[90vh]">
            <div class="p-6 border-b border-gray-200 dark:border-slate-800 flex justify-between items-start bg-gray-50 dark:bg-slate-800 print:bg-white">
                <div><div class="text-[#1E3A1D] dark:text-green-400 font-black text-2xl tracking-tight uppercase">PACKING SLIP</div><div class="text-sm text-gray-500 dark:text-slate-400 mt-1">FreshFlow Distribution Center</div></div>
                <div class="text-right"><h2 class="text-xl font-mono font-bold text-[#1E3A1D] dark:text-white" id="modalOrderId">#00000</h2><p class="text-xs text-gray-500 dark:text-slate-400 font-bold" id="modalDate">Jan 01, 2026</p></div>
            </div>
            <div class="px-6 py-4 bg-white dark:bg-slate-900 border-b border-gray-100 dark:border-slate-800 grid grid-cols-2 gap-4">
                <div><span class="text-[10px] font-bold text-gray-400 dark:text-slate-500 uppercase tracking-wider">Bill To</span><div class="font-bold text-gray-800 dark:text-white text-lg" id="modalClientName">Client Name</div></div>
                <div><span class="text-[10px] font-bold text-gray-400 dark:text-slate-500 uppercase tracking-wider">Order Status</span><div class="text-sm text-gray-600 dark:text-slate-300 font-bold">Standard Fulfillment</div></div>
            </div>
            <div class="flex-1 overflow-y-auto p-6 custom-scroll">
                <table class="w-full text-left border-collapse">
                    <thead><tr class="text-xs font-bold text-gray-400 dark:text-slate-500 uppercase border-b-2 border-gray-100 dark:border-slate-800"><th class="pb-2 w-20">Qty</th><th class="pb-2">Description</th><th class="pb-2 text-right">Unit Price</th><th class="pb-2 text-right">Amount</th></tr></thead>
                    <tbody id="modalItemsBody" class="text-sm text-gray-700 dark:text-gray-300"></tbody>
                    <tfoot><tr class="border-t-2 border-gray-100 dark:border-slate-800"><td colspan="3" class="pt-4 text-right font-bold text-gray-500 dark:text-slate-400 uppercase text-xs tracking-wider">Total Due</td><td class="pt-4 text-right font-black font-mono text-xl text-[#1E3A1D] dark:text-green-400" id="modalTotal">₱0.00</td></tr></tfoot>
                </table>
            </div>
            <div class="bg-gray-50 dark:bg-slate-800 p-4 flex justify-between items-center border-t border-gray-200 dark:border-slate-700 no-print">
                <div class="text-xs text-gray-400 dark:text-slate-500 italic">Generated by FreshFlow System</div>
                <div class="flex gap-3"><button onclick="window.print()" class="bg-white dark:bg-slate-700 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-white px-4 py-2 rounded-lg font-bold hover:bg-gray-50 dark:hover:bg-slate-600 flex items-center gap-2 shadow-sm transition"><span class="material-icons text-sm">print</span> Print Slip</button><button onclick="closeModal()" class="bg-[#1E3A1D] dark:bg-green-600 text-white px-6 py-2 rounded-lg font-bold hover:bg-[#2a4e29] dark:hover:bg-green-500 shadow-md transition transform hover:-translate-y-0.5">Done</button></div>
            </div>
        </div>
    </div>

    <script>
        // ==========================================
        // UI AND MODAL LOGIC
        // ==========================================
        const modal = document.getElementById('orderModal');
        const modalBody = document.getElementById('modalItemsBody');
        
        function closeModal() { 
            modal.classList.add('hidden'); 
        }
        
        async function openOrderModal(saleId, clientName, dateStr) {
            document.getElementById('modalOrderId').textContent = "#" + String(saleId).padStart(5, '0');
            document.getElementById('modalClientName').textContent = clientName;
            document.getElementById('modalDate').textContent = dateStr;
            modalBody.innerHTML = '<tr><td colspan="4" class="text-center py-8"><span class="animate-spin material-icons text-gray-300 dark:text-slate-600">autorenew</span></td></tr>';
            modal.classList.remove('hidden');
            
            try {
                const response = await fetch(`order_queue.php?action=get_items&id=${saleId}&t=${new Date().getTime()}`);
                const items = await response.json();
                modalBody.innerHTML = '';
                let total = 0;
                
                if (items.length === 0) { 
                    modalBody.innerHTML = '<tr><td colspan="4" class="text-center py-4 italic text-gray-400 dark:text-slate-500">No items found.</td></tr>'; 
                } else {
                    items.forEach(item => {
                        const subtotal = parseFloat(item.quantity) * parseFloat(item.price);
                        total += subtotal;
                        
                        const isBulk = ['kg', 'g', 'liter', 'bottle'].includes(item.unit);
                        const displayQty = isBulk ? parseFloat(item.quantity).toFixed(2) : parseFloat(item.quantity).toFixed(0);
                        
                        modalBody.innerHTML += `
                        <tr class="border-b border-gray-50 dark:border-slate-800/50 transition hover:bg-gray-50 dark:hover:bg-slate-800/50">
                            <td class="py-3 pr-2">
                                <div class="font-mono font-bold text-[#1E3A1D] dark:text-green-400 text-center bg-green-50 dark:bg-green-900/20 border border-green-100 dark:border-green-800/50 rounded px-1 py-1">
                                    ${displayQty} <span class="text-[9px] text-gray-500 dark:text-slate-500 font-sans uppercase block leading-none mt-0.5">${item.unit || ''}</span>
                                </div>
                            </td>
                            <td class="py-3 pl-2">
                                <div class="font-bold text-gray-800 dark:text-white">${item.name}</div>
                                <div class="text-[10px] text-gray-400 dark:text-slate-500 uppercase tracking-wide">${item.product_brand || 'Generic'}</div>
                            </td>
                            <td class="py-3 text-right text-gray-500 dark:text-slate-400 font-mono text-xs">₱${parseFloat(item.price).toFixed(2)}</td>
                            <td class="py-3 text-right font-bold text-[#1E3A1D] dark:text-green-400 font-mono">₱${subtotal.toFixed(2)}</td>
                        </tr>`;
                    });
                }
                
                const isRedelivery = document.querySelector(`.order-row[data-id="${saleId}"]`).dataset.type === 'redelivery';
                if (isRedelivery) {
                    document.getElementById('modalTotal').textContent = '₱0.00 (Pre-paid)';
                } else {
                    document.getElementById('modalTotal').textContent = '₱' + total.toLocaleString('en-US', {minimumFractionDigits: 2});
                }
                
            } catch (error) { 
                console.error(error); 
                modalBody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-red-500 dark:text-red-400">Error loading items.</td></tr>'; 
            }
        }

        document.addEventListener('keydown', (e) => { 
            if(e.key === 'Escape') closeModal(); 
        });

        // Ensure search input stays focused and cursor goes to the end
        window.addEventListener('DOMContentLoaded', (event) => {
            const searchBox = document.getElementById('searchInput');
            if (searchBox && (searchBox.value.length > 0 || new URLSearchParams(window.location.search).has('search'))) {
                searchBox.focus();
                const val = searchBox.value;
                searchBox.value = '';
                searchBox.value = val;
            }
        });
    </script>
</body>
</html>