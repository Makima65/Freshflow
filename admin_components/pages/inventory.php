<?php
// C:\xampp\htdocs\RalphPHP\admin_components\pages\inventory.php
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

if (session_status() == PHP_SESSION_NONE) { session_start(); }

// Error Handling
ini_set('display_errors', 0);
ini_set('log_errors', 1);

include_once '../includes/db_connection.php';

// --- SECURITY CHECK ---
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    if (isset($_GET['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Session expired']);
        exit;
    }
    header("location: ../admin_login.php");
    exit;
}

// =================================================================================
//                              AJAX HISTORY HANDLER
// =================================================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_history' && isset($_GET['id'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    $product_id = intval($_GET['id']);
    
    // Get Product Name
    $p_stmt = $conn->prepare("SELECT name FROM products WHERE product_id = ?");
    $p_stmt->bind_param("i", $product_id);
    $p_stmt->execute();
    $p_name = $p_stmt->get_result()->fetch_assoc()['name'] ?? '';
    
    $safe_name = $conn->real_escape_string($p_name);
    
    // --- CCTV STRICT SEARCH LOGIC ---
    // 1. MUST contain "(ID: $product_id)" OR the explicit exact name wrapped in quotes.
    // 2. MUST be an action type related to Products or Inventory (This entirely blocks "Created User" logs).
    $sql = "SELECT username, action_type as action, details as description, log_time as date 
            FROM audit_trail 
            WHERE (details LIKE '%(ID: $product_id)%' OR details LIKE '%\'$safe_name\'%')
              AND (LOWER(action_type) LIKE '%product%' OR LOWER(action_type) LIKE '%stock%' OR LOWER(action_type) LIKE '%bulk%' OR LOWER(action_type) LIKE '%inventory%')
            ORDER BY log_time DESC LIMIT 20";
            
    $history = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

    foreach ($history as &$row) {
        $row['date'] = date('M d, Y h:i A', strtotime($row['date']));
        $row['username'] = $row['username'] ?? 'System';
    }

    echo json_encode(['product_name' => $p_name, 'history' => $history]);
    exit;
}

// =================================================================================
//                              PAGE FILTERS & SEARCH
// =================================================================================

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$stock_filter = isset($_GET['stock']) ? $_GET['stock'] : 'all';
$brand_filter = isset($_GET['brand']) ? $_GET['brand'] : 'all';
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'name_asc';

// Base WHERE
$where = ["p.status != 'Archived'"];
if ($search) $where[] = "(p.name LIKE '%$search%' OR p.product_brand LIKE '%$search%')";
if ($brand_filter !== 'all') $where[] = "p.product_brand = '" . $conn->real_escape_string($brand_filter) . "'";

// --- DYNAMIC STATUS LOGIC ---
if ($stock_filter === 'in_stock') $where[] = "COALESCE(pi.quantity, 0) > COALESCE(pi.reorder_level, 10)";
elseif ($stock_filter === 'low_stock') $where[] = "COALESCE(pi.quantity, 0) <= COALESCE(pi.reorder_level, 10) AND COALESCE(pi.quantity, 0) > 0.001";
elseif ($stock_filter === 'out_of_stock') $where[] = "COALESCE(pi.quantity, 0) <= 0.001";

$where_sql = implode(' AND ', $where);

// Sorting Logic
$sort_sql = match($sort_by) {
    'stock_asc' => 'pi.quantity ASC',
    'stock_desc' => 'pi.quantity DESC',
    'name_desc' => 'p.name DESC',
    default => 'p.name ASC'
};

// --- MAIN QUERY ---
$sql = "SELECT 
            p.product_id, p.name, p.product_brand, p.unit, c.category_name, p.image_url, 
            COALESCE(pi.quantity, 0) as quantity, 
            COALESCE(pi.reorder_level, 10) as reorder_level,
            pi.last_restock_date
        FROM products p 
        LEFT JOIN product_inventory pi ON p.product_id = pi.product_id 
        LEFT JOIN categories c ON p.category_id = c.category_id 
        WHERE $where_sql 
        ORDER BY $sort_sql";

$inventory = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

$brands = $conn->query("SELECT DISTINCT product_brand FROM products WHERE product_brand != '' ORDER BY product_brand ASC")->fetch_all(MYSQLI_ASSOC);

$metrics = $conn->query("
    SELECT 
        COUNT(p.product_id) as total_products, 
        SUM(CASE WHEN pi.quantity <= pi.reorder_level AND pi.quantity > 0.001 THEN 1 ELSE 0 END) as low_stock,
        SUM(CASE WHEN pi.quantity <= 0.001 THEN 1 ELSE 0 END) as out_of_stock,
        SUM(p.price * COALESCE(pi.quantity, 0)) as total_value
    FROM products p 
    LEFT JOIN product_inventory pi ON p.product_id = pi.product_id 
    WHERE p.status != 'Archived'
")->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreshFlow - Inventory</title>
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
        
        /* Status Badges */
        .status-badge { padding: 4px 12px; border-radius: 99px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; display: inline-block; border: 1px solid transparent; }
        
        .status-Good { background: #dcfce7; color: #166534; }
        .dark .status-Good { background: rgba(22, 101, 52, 0.2); color: #86efac; border-color: rgba(74, 222, 128, 0.3); }

        .status-Low { background: #fef9c3; color: #854d0e; }
        .dark .status-Low { background: rgba(133, 77, 14, 0.2); color: #fde047; border-color: rgba(253, 224, 71, 0.3); }

        .status-Critical { background: #fee2e2; color: #991b1b; }
        .dark .status-Critical { background: rgba(153, 27, 27, 0.2); color: #fca5a5; border-color: rgba(248, 113, 113, 0.3); }

        /* Custom Scrollbar */
        .custom-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .dark .custom-scroll::-webkit-scrollbar-thumb { background: #334155; }
        
        /* Form Inputs */
        .form-input, .filter-select { background-color: #ffffff; border: 1px solid #d1d5db; color: #374151; border-radius: 0.5rem; transition: all 0.2s; }
        .form-input:focus, .filter-select:focus { outline: none; border-color: var(--brand-green); box-shadow: 0 0 0 3px rgba(30, 58, 29, 0.1); }
        
        .dark .form-input, .dark .filter-select { background-color: #1e293b; border-color: #334155; color: #f8fafc; }
        .dark .form-input:focus, .dark .filter-select:focus { border-color: #4ade80; box-shadow: 0 0 0 3px rgba(74, 222, 128, 0.1); }

        #filterOptions { transition: all 0.3s ease-in-out; max-height: 0; overflow: hidden; opacity: 0; }
        #filterOptions.open { max-height: 500px; opacity: 1; padding-top: 1rem; border-top: 1px solid #e5e7eb; }
        .dark #filterOptions.open { border-top-color: #334155; }

        @media print {
            #sidebar, .no-print, #filterContainer, button { display: none !important; }
            body { background-color: white; color: black; }
            .ml-20 { margin-left: 0 !important; padding: 0 !important; }
            .content-card { box-shadow: none; border: 1px solid #ccc; }
            table { width: 100%; border-collapse: collapse; font-size: 12px; }
            th, td { border: 1px solid #ddd; padding: 8px; }
            thead { background-color: #f0f0f0 !important; color: black !important; }
        }
    </style>
    <script>
        window.onpageshow = function(event) { if (event.persisted) window.location.reload(); };
        window.onbeforeunload = function() { document.body.innerHTML = ""; document.body.style.backgroundColor = document.documentElement.classList.contains('dark') ? "#000" : "#F8F5EE"; };
    </script>
</head>
<body class="flex h-screen overflow-hidden">

    <div class="no-print">
        <?php include '../includes/sidebar.php'; ?>
    </div>

    <main class="ml-20 flex-1 flex flex-col h-screen overflow-hidden relative transition-all duration-300 p-6 md:p-8">
        
        <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 no-print flex-shrink-0">
            <div>
                <h1 class="text-3xl font-bold text-[#1E3A1D] dark:text-white tracking-tight flex items-center gap-3">
                    <span class="material-icons text-3xl dark:text-green-400">analytics</span>
                    Inventory Report
                </h1>
                <p class="text-sm text-gray-500 dark:text-slate-400 mt-1 ml-11">Real-time stock tracking & valuation</p>
            </div>
            <button onclick="window.print()" class="bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-700 text-gray-700 dark:text-slate-200 px-4 py-2.5 rounded-lg font-bold hover:bg-gray-50 dark:hover:bg-slate-700 flex items-center gap-2 shadow-sm transition transform active:scale-95 mt-4 md:mt-0">
                <span class="material-icons text-sm">print</span> Print Report
            </button>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8 text-center no-print flex-shrink-0">
            
            <div class="bg-white dark:bg-slate-900/80 border border-blue-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-pointer group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(59,130,246,0.2)] dark:hover:shadow-[0_0_20px_rgba(59,130,246,0.3)] dark:hover:border-blue-400 transition-all duration-300 shadow-sm">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">Total Products</p>
                    <p class="text-3xl font-bold text-blue-600 dark:text-blue-400 mt-1 font-mono group-hover:scale-110 transition-transform origin-left"><?= number_format($metrics['total_products']) ?></p>
                </div>
                <div class="p-3 bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 rounded-lg group-hover:bg-blue-200 dark:group-hover:bg-blue-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">inventory_2</span>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900/80 border border-orange-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-pointer group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(249,115,22,0.2)] dark:hover:shadow-[0_0_20px_rgba(249,115,22,0.3)] dark:hover:border-orange-400 transition-all duration-300 shadow-sm">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-orange-600 dark:group-hover:text-orange-400 transition-colors">Low Stock</p>
                    <p class="text-3xl font-bold text-orange-600 dark:text-orange-400 mt-1 font-mono group-hover:scale-110 transition-transform origin-left"><?= number_format($metrics['low_stock']) ?></p>
                </div>
                <div class="p-3 bg-orange-100 dark:bg-orange-900/30 text-orange-600 dark:text-orange-400 rounded-lg group-hover:bg-orange-200 dark:group-hover:bg-orange-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">warning</span>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900/80 border border-red-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-pointer group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(239,68,68,0.2)] dark:hover:shadow-[0_0_20px_rgba(239,68,68,0.3)] dark:hover:border-red-400 transition-all duration-300 shadow-sm">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-red-600 dark:group-hover:text-red-400 transition-colors">Out of Stock</p>
                    <p class="text-3xl font-bold text-red-600 dark:text-red-400 mt-1 font-mono group-hover:scale-110 transition-transform origin-left"><?= number_format($metrics['out_of_stock']) ?></p>
                </div>
                <div class="p-3 bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 rounded-lg group-hover:bg-red-200 dark:group-hover:bg-red-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">remove_shopping_cart</span>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900/80 border border-green-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-pointer group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(34,197,94,0.2)] dark:hover:shadow-[0_0_20px_rgba(34,197,94,0.3)] dark:hover:border-green-400 transition-all duration-300 shadow-sm">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-green-600 dark:group-hover:text-green-400 transition-colors">Total Value</p>
                    <p class="text-2xl font-bold text-green-600 dark:text-green-400 mt-1 font-mono group-hover:scale-110 transition-transform origin-left">₱<?= number_format($metrics['total_value'] / 1000, 1) ?>k</p>
                </div>
                <div class="p-3 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 rounded-lg group-hover:bg-green-200 dark:group-hover:bg-green-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">payments</span>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-900/80 p-4 rounded-xl shadow-sm border border-gray-200 dark:border-slate-800 mb-6 relative z-20 no-print flex-shrink-0" id="filterContainer">
            <div class="flex flex-col md:flex-row items-center gap-4">
                <div class="relative w-full flex-grow">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400"><span class="material-icons text-sm">search</span></span>
                    <input type="text" id="searchInput" placeholder="Search inventory..." value="<?= htmlspecialchars($search) ?>" class="w-full pl-9 pr-3 py-2 text-sm border border-gray-200 dark:border-slate-700 rounded-lg focus:outline-none focus:border-[#1E3A1D] dark:focus:border-green-400 bg-white dark:bg-slate-800 text-gray-900 dark:text-white transition" autocomplete="off" onkeydown="if(event.key === 'Enter') document.getElementById('applyFilters').click()">
                </div>
                <button id="toggleFilterBtn" class="bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-700 hover:bg-gray-50 dark:hover:bg-slate-700 text-gray-700 dark:text-slate-200 text-sm font-bold py-2 px-4 rounded-lg flex items-center gap-2 transition shadow-sm w-full md:w-auto justify-center">
                    <span class="material-icons text-sm">filter_list</span> Filters <span id="filterCountBadge" class="hidden bg-red-600 text-white rounded-full w-5 h-5 flex justify-center items-center text-[10px] ml-1"></span>
                </button>
            </div>
            
            <div id="filterOptions">
                <form action="" method="GET" id="filterForm">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end mt-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 mb-1 uppercase tracking-wider">Stock Status</label>
                            <select name="stock" class="w-full p-2 text-sm filter-select cursor-pointer">
                                <option value="all">All</option>
                                <option value="in_stock" <?= $stock_filter=='in_stock'?'selected':'' ?>>In Stock</option>
                                <option value="low_stock" <?= $stock_filter=='low_stock'?'selected':'' ?>>Low Stock</option>
                                <option value="out_of_stock" <?= $stock_filter=='out_of_stock'?'selected':'' ?>>Out of Stock</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 mb-1 uppercase tracking-wider">Brand</label>
                            <select name="brand" class="w-full p-2 text-sm filter-select cursor-pointer">
                                <option value="all">All Brands</option>
                                <?php foreach ($brands as $b): ?>
                                <option value="<?= htmlspecialchars($b['product_brand']) ?>" <?= $brand_filter==$b['product_brand']?'selected':'' ?>><?= htmlspecialchars($b['product_brand']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 mb-1 uppercase tracking-wider">Sort By</label>
                            <select name="sort" class="w-full p-2 text-sm filter-select cursor-pointer">
                                 <option value="name_asc" <?= $sort_by=='name_asc'?'selected':'' ?>>Name (A-Z)</option>
                                 <option value="name_desc" <?= $sort_by=='name_desc'?'selected':'' ?>>Name (Z-A)</option>
                                 <option value="stock_asc" <?= $sort_by=='stock_asc'?'selected':'' ?>>Stock (Low-High)</option>
                                 <option value="stock_desc" <?= $sort_by=='stock_desc'?'selected':'' ?>>Stock (High-Low)</option>
                            </select>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" id="resetFiltersBtn" class="bg-gray-200 dark:bg-slate-700 hover:bg-gray-300 dark:hover:bg-slate-600 text-gray-700 dark:text-slate-300 font-bold py-2 px-4 rounded-lg text-sm w-full text-center flex items-center justify-center transition">Reset</button>
                            <button type="submit" id="applyFilters" class="bg-[#1E3A1D] dark:bg-green-600 hover:bg-[#2a4e29] dark:hover:bg-green-500 text-white font-bold py-2 px-4 rounded-lg text-sm w-full shadow-md transition transform hover:-translate-y-0.5">Apply</button>
                        </div>
                    </div>
                    <input type="hidden" name="search" id="hiddenSearch" value="<?= htmlspecialchars($search) ?>">
                </form>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-900/80 rounded-xl shadow border border-gray-200 dark:border-slate-800 flex-1 overflow-hidden flex flex-col min-h-[400px]" id="tableDataArea">
            <div class="overflow-y-auto flex-1 custom-scroll">
                <table class="w-full text-left">
                    <thead class="bg-[#1E3A1D] dark:bg-slate-800 text-white text-xs uppercase font-bold sticky top-0 z-10 shadow-sm">
                        <tr>
                            <th class="p-4">Product</th>
                            <th class="p-4">Category</th>
                            <th class="p-4 text-center">Stock Level</th>
                            <th class="p-4 text-center">Safety Stock</th>
                            <th class="p-4 text-center">Status</th>
                            <th class="p-4 text-right no-print">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-800 text-sm text-gray-700 dark:text-gray-300">
                        <?php if (empty($inventory)): ?>
                            <tr><td colspan="6" class="p-10 text-center text-gray-400 dark:text-slate-500 italic">No products found.</td></tr>
                        <?php else: ?>
                            <?php foreach($inventory as $item): 
                                $status = 'Good';
                                $statusClass = 'status-Good';
                                if ($item['quantity'] <= 0.001) {
                                    $status = 'Critical';
                                    $statusClass = 'status-Critical';
                                } elseif ($item['quantity'] <= $item['reorder_level']) {
                                    $status = 'Low';
                                    $statusClass = 'status-Low';
                                }
                                $img = $item['image_url'] ? "../../" . $item['image_url'] : "../../assets/img/placeholder.png";
                                
                                $unit = $item['unit'] ? $item['unit'] : 'pcs';
                            ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-slate-800/50 transition">
                                <td class="p-4">
                                    <div class="flex items-center gap-3">
                                        <img src="<?= $img ?>" class="w-10 h-10 rounded-lg object-cover border border-gray-200 dark:border-slate-700 shadow-sm no-print bg-white dark:bg-slate-900">
                                        <div>
                                            <div class="font-bold text-gray-800 dark:text-white"><?= htmlspecialchars($item['name']) ?></div>
                                            <div class="text-[10px] uppercase font-bold text-gray-400 dark:text-slate-500"><?= htmlspecialchars($item['product_brand']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="p-4 text-gray-600 dark:text-slate-400 text-xs font-medium"><?= htmlspecialchars($item['category_name'] ?: 'Uncategorized') ?></td>
                                
                                <td class="p-4 text-center font-mono font-bold text-lg text-[#1E3A1D] dark:text-green-400">
                                    <?= floatval($item['quantity']) ?> <span class="text-[10px] uppercase text-gray-400 dark:text-slate-500 font-sans font-bold"><?= $unit ?></span>
                                </td>

                                <td class="p-4 text-center text-gray-400 dark:text-slate-500 text-xs font-mono font-bold">
                                    <span class="text-gray-300 dark:text-slate-600">Min:</span> <?= $item['reorder_level'] ?>
                                </td>

                                <td class="p-4 text-center">
                                    <span class="status-badge <?= $statusClass ?>"><?= $status ?></span>
                                </td>

                                <td class="p-4 text-right flex justify-end gap-2 no-print">
                                    <button onclick="viewHistory(<?= $item['product_id'] ?>)" class="bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 text-gray-500 dark:text-slate-400 hover:text-[#1E3A1D] dark:hover:text-green-400 px-3 py-1.5 rounded-lg text-xs font-bold transition flex items-center gap-1 border border-gray-200 dark:border-slate-700 hover:border-gray-300 dark:hover:border-slate-600">
                                        <span class="material-icons text-sm">history</span> Log
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <div id="historyModal" class="fixed inset-0 bg-[#1E3A1D] dark:bg-black bg-opacity-40 dark:bg-opacity-60 hidden z-50 flex justify-center items-center backdrop-blur-sm modal no-print transition-opacity">
        <div class="bg-white dark:bg-slate-900 rounded-xl shadow-2xl w-full max-w-lg overflow-hidden flex flex-col max-h-[80vh] border border-gray-200 dark:border-slate-700">
            <div class="bg-[#1E3A1D] dark:bg-slate-800 p-4 text-white flex justify-between items-center flex-shrink-0 border-b border-[#2a4e29] dark:border-slate-700">
                <h2 class="text-lg font-bold flex items-center gap-2"><span class="material-icons text-sm">history</span> Audit Log</h2>
                <button onclick="closeHistory()" class="hover:text-gray-300 transition"><span class="material-icons text-sm">close</span></button>
            </div>
            <div id="historyContent" class="overflow-y-auto flex-1 p-4 space-y-3 custom-scroll bg-white dark:bg-slate-900"></div>
        </div>
    </div>

<script>
        document.addEventListener('DOMContentLoaded', function() {
            const liveSearchInput = document.getElementById('searchInput');
            const hiddenSearchInput = document.getElementById('hiddenSearch');
            const filterFormAjax = document.getElementById('filterForm');
            const tableContainer = document.getElementById('tableDataArea');
            const selectInputs = filterFormAjax.querySelectorAll('select');
            const resetBtn = document.getElementById('resetFiltersBtn'); 
            let typingTimer;

            function performAjaxSearch() {
                if (!tableContainer) return;

                // 1. Sync the visible search bar to the hidden form input
                if (liveSearchInput && hiddenSearchInput) {
                    hiddenSearchInput.value = liveSearchInput.value;
                }

                // 2. Build the URL
                const url = new URL(window.location.pathname, window.location.origin);
                const formData = new FormData(filterFormAjax);
                
                for (const [key, value] of formData.entries()) {
                    if (value) url.searchParams.set(key, value);
                }

                // 3. Loading state
                tableContainer.style.opacity = '0.5';

                // 4. Fetch the new data
                fetch(url.toString())
                .then(res => res.text())
                .then(html => {
                    const parser = new DOMParser();
                    const newDoc = parser.parseFromString(html, 'text/html');
                    const newTableContainer = newDoc.getElementById('tableDataArea');

                    // Swap table instantly!
                    if (newTableContainer) {
                        tableContainer.innerHTML = newTableContainer.innerHTML;
                    }

                    // Update URL without reload
                    window.history.pushState({}, '', url.toString());
                    tableContainer.style.opacity = '1';
                })
                .catch(err => {
                    console.error('AJAX Error:', err);
                    tableContainer.style.opacity = '1';
                });
            }

            // --- LISTENERS ---

            if (liveSearchInput) {
                liveSearchInput.addEventListener('keyup', function() {
                    clearTimeout(typingTimer);
                    typingTimer = setTimeout(performAjaxSearch, 300);
                });
                
                liveSearchInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') e.preventDefault();
                });
            }

            selectInputs.forEach(select => {
                select.addEventListener('change', performAjaxSearch);
            });

            if (filterFormAjax) {
                filterFormAjax.addEventListener('submit', function(e) {
                    e.preventDefault();
                    performAjaxSearch();
                });
            }
            
            // 4. Handle the Reset Button via AJAX (MOVED INSIDE!)
            if (resetBtn) {
                resetBtn.addEventListener('click', function(e) {
                    e.preventDefault(); // Stop any page reloading

                    // Clear the search bars
                    if (liveSearchInput) liveSearchInput.value = '';
                    if (hiddenSearchInput) hiddenSearchInput.value = '';

                    // Reset all dropdowns back to their default (first) option
                    selectInputs.forEach(select => {
                        select.value = select.options[0].value;
                    });

                    // Instantly update the table with the cleared filters!
                    performAjaxSearch();
                });
            }
            
        }); // <-- THIS CLOSING BRACKET MUST BE AT THE VERY END!
    </script>
    <script>
        // --- FILTERS & UI ---
        const toggleFilterBtn = document.getElementById('toggleFilterBtn');
        const filterOptions = document.getElementById('filterOptions');
        const filterBadge = document.getElementById('filterCountBadge');
        const searchInput = document.getElementById('searchInput');
        const hiddenSearch = document.getElementById('hiddenSearch');

        toggleFilterBtn.addEventListener('click', () => {
            const isOpen = filterOptions.style.maxHeight && filterOptions.style.maxHeight !== '0px';
            filterOptions.style.maxHeight = isOpen ? '0px' : filterOptions.scrollHeight + 'px';
            filterOptions.style.opacity = isOpen ? '0' : '1';
        });

        searchInput.addEventListener('input', (e) => hiddenSearch.value = e.target.value);

        // Update Badge Count
        let count = 0;
        if ('<?= $stock_filter ?>' !== 'all') count++;
        if ('<?= $brand_filter ?>' !== 'all') count++;
        if (count > 0) {
            filterBadge.textContent = count;
            filterBadge.classList.remove('hidden');
            filterBadge.classList.add('flex');
        }

        // --- HISTORY LOGIC ---
        const hModal = document.getElementById('historyModal');
        const hContent = document.getElementById('historyContent');
        
        async function viewHistory(id) {
            hModal.classList.remove('hidden');
            hContent.innerHTML = '<div class="text-center py-8 text-gray-400 dark:text-slate-500"><span class="material-icons animate-spin text-2xl">autorenew</span><br>Loading logs...</div>';
            try {
                // Fetch with cache busting
                const res = await fetch(`?action=get_history&id=${id}&t=${new Date().getTime()}`);
                const data = await res.json();
                
                if(!data.history || data.history.length === 0) {
                    hContent.innerHTML = '<div class="text-center py-8 text-gray-400 dark:text-slate-500 italic">No history found for this item.</div>';
                    return;
                }

                hContent.innerHTML = data.history.map(log => `
                    <div class="bg-gray-50 dark:bg-slate-800/50 p-3 rounded-lg border border-gray-100 dark:border-slate-700 hover:border-gray-200 dark:hover:border-slate-600 transition">
                        <div class="font-bold text-[#1E3A1D] dark:text-green-400 text-sm">${log.description}</div>
                        <div class="text-xs text-gray-400 dark:text-slate-500 flex justify-between mt-1 pt-1 border-t border-gray-100 dark:border-slate-700">
                            <span class="flex items-center gap-1"><span class="material-icons text-[10px]">person</span> ${log.username}</span>
                            <span class="font-mono">${log.date}</span>
                        </div>
                    </div>
                `).join('');
            } catch(e) { hContent.innerHTML = '<div class="text-red-500 dark:text-red-400 text-center py-4">Error loading data.</div>'; }
        }
        function closeHistory() { hModal.classList.add('hidden'); }
    </script>
</body>
</html>