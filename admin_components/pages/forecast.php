<?php
// C:\xampp\htdocs\RalphPHP\admin_components\pages\forecast.php

// Safe session start
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ob_start();

header("Cache-Control: no-cache, no-store, must-revalidate"); 
header("Pragma: no-cache"); 
header("Expires: 0"); 

ini_set('display_errors', 0); // Hide warnings for clean presentation
include_once '../includes/db_connection.php';

// Security Check
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: ../admin_login.php"); exit;
}

// --- ENGINE: LINEAR REGRESSION WITH SPOILAGE OPTIMIZATION ---
function calculateForecast($sales_history, $days_to_predict = 7) {
    $n = count($sales_history);
    if ($n < 2) return ['prediction' => 0, 'slope' => 0, 'confidence' => 0];

    $x = []; $y = [];
    $i = 1;
    foreach ($sales_history as $qty) { $x[] = $i; $y[] = $qty; $i++; }

    $sum_x = array_sum($x); $sum_y = array_sum($y);
    $sum_xx = 0; $sum_xy = 0; $sum_yy = 0;

    for ($k = 0; $k < $n; $k++) {
        $sum_xx += $x[$k] * $x[$k];
        $sum_xy += $x[$k] * $y[$k];
        $sum_yy += $y[$k] * $y[$k];
    }

    $denominator = ($n * $sum_xx) - ($sum_x * $sum_x);
    if ($denominator == 0) return ['prediction' => 0, 'slope' => 0, 'confidence' => 0];
    
    $m = (($n * $sum_xy) - ($sum_x * $sum_y)) / $denominator;
    $b = ($sum_y - ($m * $sum_x)) / $n;

    $y_mean = $sum_y / $n;
    $ss_tot = 0; $ss_res = 0;
    
    for ($k = 0; $k < $n; $k++) {
        $y_actual = $y[$k];
        $y_pred = ($m * $x[$k]) + $b;
        $ss_tot += pow($y_actual - $y_mean, 2);
        $ss_res += pow($y_actual - $y_pred, 2);
    }
    
    $r2 = ($ss_tot == 0) ? 1 : (1 - ($ss_res / $ss_tot));
    $confidence = max(0, min(100, $r2 * 100)); 

    $total_predicted = 0;
    for ($d = 1; $d <= $days_to_predict; $d++) {
        $pred = ($m * ($n + $d)) + $b;
        $total_predicted += max(0, $pred);
    }

    return [
        'prediction' => $total_predicted,
        'slope' => $m,
        'confidence' => $confidence
    ];
}

// --- DATA FETCHING (WITH PROPER CATEGORY JOIN) ---
$analysis_days = 30; 

// 1. Fetch Categories properly from the 'categories' table
$all_categories = [];
try {
    $cat_res = $conn->query("SELECT * FROM categories ORDER BY category_name ASC");
    if ($cat_res && $cat_res->num_rows > 0) {
        while ($row = $cat_res->fetch_assoc()) {
            $c_id = $row['category_id'] ?? ($row['id'] ?? null);
            $c_name = $row['category_name'] ?? ($row['name'] ?? 'Unknown');
            if ($c_id) {
                $all_categories[$c_id] = $c_name;
            }
        }
    }
} catch (Throwable $e) {}

// 2. Fetch Products and JOIN the categories table
$products = [];
try {
    $query_str = "SELECT p.product_id, p.name, p.product_brand, p.category_id, p.unit, p.image_url, c.category_name 
                  FROM products p 
                  LEFT JOIN categories c ON p.category_id = c.category_id 
                  WHERE p.status = 'Active'";
    $prod_query = $conn->query($query_str);
    if ($prod_query) {
        $products = $prod_query->fetch_all(MYSQLI_ASSOC);
    }
} catch (Throwable $e) {
    // Fallback if join fails
    $fallback = $conn->query("SELECT product_id, name, product_brand, unit, image_url FROM products WHERE status = 'Active'");
    if ($fallback) $products = $fallback->fetch_all(MYSQLI_ASSOC);
}

// 3. Extract unique brands for the dropdown
$all_brands = [];
foreach ($products as $p) {
    $brand = $p['product_brand'] ?? '';
    if (!empty($brand) && !in_array($brand, $all_brands)) {
        $all_brands[] = $brand;
    }
}
sort($all_brands);

$forecasts = [];
$global_history = array_fill(0, $analysis_days, 0); 

foreach ($products as $prod) {
    $pid = $prod['product_id'];
    $unit = $prod['unit'] ?? '';
    
    // Only weight-based metrics are allowed to be decimals now
    $is_bulk = in_array(strtolower($unit), ['kg', 'g', 'grams', 'kilograms']);
    
    // 1. Fetch Sales History (30 Days for Chart)
    $hist_sql = "SELECT DATE(sale_date) as s_date, SUM(quantity) as qty FROM sales_items si JOIN sales s ON si.sale_id = s.sale_id WHERE si.product_id = $pid AND s.order_status = 'Completed' AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL $analysis_days DAY) GROUP BY DATE(s.sale_date)";
    $hist_res = $conn->query($hist_sql);
    
    $raw_data = [];
    if ($hist_res) { while($r = $hist_res->fetch_assoc()) { $raw_data[$r['s_date']] = floatval($r['qty']); } }
    
    $sales_timeline = [];
    for($i = $analysis_days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $val = $raw_data[$d] ?? 0;
        $sales_timeline[] = $val;
        $global_history[$i] += $val;
    }

    // 2. Fetch Spoilage History
    $spoil_sql = "SELECT SUM(quantity) as wasted FROM spoilage WHERE product_id = $pid AND spoilage_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    $spoil_res = $conn->query($spoil_sql);
    $recent_wasted = ($spoil_res && $spoil_res->num_rows > 0) ? floatval($spoil_res->fetch_assoc()['wasted']) : 0;
    
    $recent_sold = array_sum(array_slice($sales_timeline, -7)); 
    
    $spoilage_rate = 0;
    if (($recent_sold + $recent_wasted) > 0) {
        $spoilage_rate = $recent_wasted / ($recent_sold + $recent_wasted);
    }

    $total_sold_30d = array_sum($sales_timeline);
    $stock_res = $conn->query("SELECT quantity FROM product_inventory WHERE product_id = $pid");
    $current_stock = ($stock_res && $stock_res->num_rows > 0) ? floatval($stock_res->fetch_assoc()['quantity']) : 0;

    if ($total_sold_30d > 0 || $recent_wasted > 0 || $current_stock > 0) {
        $result = calculateForecast($sales_timeline, 7);
        $predicted = $result['prediction'];
        
        $risk_penalty = 1.0;
        $optimization_msg = "";
        
        if ($spoilage_rate > 0.10) {
            $risk_penalty = 1.0 - $spoilage_rate; 
            $optimization_msg = "Recent Waste Detected (" . round($spoilage_rate * 100) . "%)";
        }
        
        // This rounds up if it's NOT a bulk item (e.g. bottles, packs)
        $optimized_demand = max(0, $predicted * $risk_penalty);
        if (!$is_bulk) {
            $optimized_demand = ceil($optimized_demand);
            $predicted = ceil($predicted);
        }
        
        $restock = max(0, $optimized_demand - $current_stock);
        
        $status = 'Optimal';
        if ($restock > 0) $status = ($current_stock <= 0) ? 'Critical' : 'Low Stock';
        if ($restock == 0 && $current_stock > $optimized_demand * 2) $status = 'Overstocked';

        $forecasts[] = [
            'id' => $pid,
            'name' => $prod['name'],
            'brand' => $prod['product_brand'],
            'category_name' => !empty($prod['category_name']) ? $prod['category_name'] : 'Uncategorized',
            'category_id' => $prod['category_id'] ?? '',
            'unit' => $unit,
            'image_url' => $prod['image_url'] ?? '',
            'stock' => $current_stock,
            'history_sparkline' => implode(',', $sales_timeline), 
            'predicted' => $optimized_demand,
            'raw_prediction' => $predicted,
            'confidence' => $result['confidence'],
            'trend' => $result['slope'],
            'restock' => $restock,
            'status' => $status,
            'spoilage_rate' => $spoilage_rate, 
            'opt_msg' => $optimization_msg,
            'is_bulk' => $is_bulk
        ];
    }
}

// --- FILTERING & SORTING LOGIC ---
$search = isset($_GET['search']) ? trim(strtolower($_GET['search'])) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$category_filter = isset($_GET['category']) ? $_GET['category'] : '';
$brand_filter = isset($_GET['brand']) ? $_GET['brand'] : '';
$sort_filter = isset($_GET['sort']) ? $_GET['sort'] : 'default'; 

// 1. Apply Search, Status, Category, and Brand Filters
if ($search !== '' || $status_filter !== '' || $category_filter !== '' || $brand_filter !== '') {
    $forecasts = array_filter($forecasts, function($f) use ($search, $status_filter, $category_filter, $brand_filter) {
        $match_search = $search === '' || strpos(strtolower($f['name']), $search) !== false || strpos(strtolower($f['brand']), $search) !== false;
        $match_status = $status_filter === '' || $f['status'] === $status_filter;
        $match_category = $category_filter === '' || (string)$f['category_id'] === (string)$category_filter;
        $match_brand = $brand_filter === '' || $f['brand'] === $brand_filter;
        return $match_search && $match_status && $match_category && $match_brand;
    });
}

// 2. Apply Sorting logic
usort($forecasts, function($a, $b) use ($sort_filter) {
    if ($sort_filter === 'demand_desc') {
        return $b['predicted'] <=> $a['predicted']; 
    } elseif ($sort_filter === 'stock_asc') {
        return $a['stock'] <=> $b['stock']; 
    } elseif ($sort_filter === 'trend_desc') {
        return $b['trend'] <=> $a['trend']; 
    } elseif ($sort_filter === 'name_asc') {
        return strcasecmp($a['name'], $b['name']); 
    } else {
        $p = ['Critical' => 1, 'Low Stock' => 2, 'Optimal' => 3, 'Overstocked' => 4];
        return $p[$a['status']] <=> $p[$b['status']];
    }
});

// Reset keys after filtering
$forecasts = array_values($forecasts);

// --- PAGINATION ---
$per_page = 10; 
$total_items = count($forecasts);
$total_pages = ceil($total_items / $per_page);

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
if ($page > $total_pages && $total_pages > 0) $page = $total_pages;

$offset = ($page - 1) * $per_page;
$current_page_items = array_slice($forecasts, $offset, $per_page);

$chart_labels = [];
for($i = $analysis_days - 1; $i >= 0; $i--) $chart_labels[] = date('M d', strtotime("-$i days"));
$js_labels = json_encode($chart_labels);
$js_data = json_encode($global_history);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreshFlow - Inventory Optimization</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400&display=swap" rel="stylesheet">
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

        .trend-up { color: #16a34a; } .trend-down { color: #dc2626; } .trend-flat { color: #9ca3af; }

        /* INPUT / SELECT STYLES */
        .form-input, .filter-select { background-color: #ffffff; border: 1px solid #d1d5db; color: #374151; border-radius: 0.5rem; transition: all 0.2s; }
        .form-input:focus, .filter-select:focus { outline: none; border-color: var(--brand-green); box-shadow: 0 0 0 3px rgba(30, 58, 29, 0.1); }
        
        .dark .form-input, .dark .filter-select { background-color: #1e293b; border-color: #334155; color: #f8fafc; }
        .dark .form-input:focus, .dark .filter-select:focus { border-color: #4ade80; box-shadow: 0 0 0 3px rgba(74, 222, 128, 0.1); }
        
        /* FILTER OPTIONS TRANSITION */
        #filterOptions { transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.4s ease, padding 0.4s ease, margin 0.4s ease; max-height: 0; overflow: hidden; opacity: 0; padding-top: 0; margin-top: 0; border-top: 1px solid transparent; }
        #filterOptions.open { max-height: 500px; opacity: 1; padding-top: 1rem; margin-top: 1rem; border-top-color: #e5e7eb; }
        .dark #filterOptions.open { border-top-color: #334155; }

        /* GLOW EFFECTS */
        .pulse-red { animation: pulseRed 2s infinite; }
        @keyframes pulseRed {
            0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
            70% { box-shadow: 0 0 0 6px rgba(239, 68, 68, 0); }
            100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }

        /* --- CLEAN PRINT LAYOUT CONFIGURATION --- */
        @media print {
            @page { size: landscape; margin: 10mm; }
            body { background-color: white !important; }
            /* Hide sidebar, action buttons, filters, pagination, and action columns */
            aside, #filterForm, .print-hide, header .flex.gap-3 { display: none !important; }
            /* Expand main content */
            main { margin-left: 0 !important; padding: 0 !important; overflow: visible !important; height: auto !important; position: static !important; width: 100% !important; }
            /* Ensure colors print properly */
            .bg-\[\#1E3A1D\] { background-color: #1E3A1D !important; color: white !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .bg-green-50 { background-color: #f0fdf4 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .bg-red-50 { background-color: #fef2f2 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .bg-blue-50 { background-color: #eff6ff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .bg-orange-50 { background-color: #fff7ed !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            /* Clean up containers */
            .shadow-sm, .shadow, .rounded-xl { box-shadow: none !important; border: 1px solid #e5e7eb !important; border-radius: 0 !important; }
            .overflow-y-auto, .overflow-auto, .custom-scroll { overflow: visible !important; height: auto !important; display: block !important; }
            /* Table formatting */
            table { width: 100% !important; min-width: 0 !important; page-break-inside: auto; }
            tr { page-break-inside: avoid; page-break-after: auto; }
            td, th { padding: 12px 8px !important; }
            .flex-1 { flex: none !important; }
        }
    </style>
    <script>
    window.onpageshow = function(event) { if (event.persisted) { window.location.reload(); } };
    window.onbeforeunload = function() { 
        if(!document.documentElement.classList.contains('dark')) {
            document.body.style.backgroundColor = "#F8F5EE"; 
        }
    };
    </script>
</head>
<body style="display:none;" id="secure-body" class="flex h-screen overflow-hidden">

    <?php include '../includes/sidebar.php'; ?>
    
    <main class="ml-20 flex-1 flex flex-col h-screen overflow-hidden relative transition-all duration-300 p-4 md:p-6">
        
        <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-4 flex-shrink-0">
            <div>
                <h1 class="text-3xl font-bold text-[#1E3A1D] dark:text-white tracking-tight flex items-center gap-3">
                    <span class="material-icons text-3xl dark:text-green-400">insights</span> Inventory Optimization
                </h1>
                <p class="text-sm text-gray-500 dark:text-slate-400 mt-1 ml-11">Demand forecasting with adaptive risk assessment (Recent 7-Day Weighting).</p>
            </div>
            <div class="flex gap-3 mt-4 md:mt-0 print-hide">
                <button onclick="window.print()" class="bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-700 text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700 px-5 py-2.5 rounded-lg text-sm font-bold shadow-sm flex items-center gap-2 transition transform active:scale-95">
                    <span class="material-icons text-sm">print</span> Print / Export
                </button>
                <button onclick="window.location.reload()" class="bg-[#1E3A1D] dark:bg-green-600 hover:bg-[#2a4e29] dark:hover:bg-green-500 text-white px-5 py-2.5 rounded-lg text-sm font-bold shadow-lg flex items-center gap-2 transition transform active:scale-95">
                    <span class="material-icons text-sm">autorenew</span> Retrain Model
                </button>
            </div>
        </header>

        <div class="bg-white dark:bg-slate-900/80 p-4 rounded-xl shadow-sm border border-gray-200 dark:border-slate-800 mb-4 flex-shrink-0 group hover:-translate-y-0.5 hover:shadow-[0_8px_20px_rgba(30,58,29,0.15)] dark:hover:shadow-[0_0_20px_rgba(74,222,128,0.15)] dark:hover:border-green-800/50 transition-all duration-300">
            <div class="flex justify-between items-start mb-2">
                <div>
                    <h2 class="font-bold text-gray-800 dark:text-white text-base group-hover:text-[#1E3A1D] dark:group-hover:text-green-400 transition-colors">Aggregate Demand Trend</h2>
                    <p class="text-xs text-gray-500 dark:text-slate-400">Total unit sales across all products (Last 30 Days)</p>
                </div>
                <div class="text-right print-hide">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-500 uppercase tracking-wider">Forecast Horizon</p>
                    <p class="text-base font-bold text-[#1E3A1D] dark:text-green-400">Next 7 Days</p>
                </div>
            </div>
            <div class="h-40 w-full">
                <canvas id="globalChart"></canvas>
            </div>
        </div>

        <form method="GET" action="forecast.php" id="filterForm" class="bg-white dark:bg-slate-900/80 p-3 rounded-xl shadow-sm border border-gray-200 dark:border-slate-800 mb-4 relative z-20 flex-shrink-0">
            <div class="flex flex-col md:flex-row items-center gap-4">
                <div class="relative w-full flex-grow">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400 dark:text-slate-500"><span class="material-icons">search</span></span>
                    <input type="text" name="search" id="searchInput" placeholder="Search product or brand..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" class="w-full pl-10 p-2 rounded-lg form-input transition" autocomplete="off">
                </div>
                <button type="button" id="toggleFilterBtn" class="bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-700 hover:bg-gray-50 dark:hover:bg-slate-700 text-gray-700 dark:text-slate-200 font-bold py-2 px-4 rounded-lg flex items-center gap-2 transition shadow-sm">
                    <span class="material-icons">filter_list</span> Filters
                </button>
            </div>
            
            <div id="filterOptions" class="<?= ($status_filter !== '' || $category_filter !== '' || $brand_filter !== '' || $sort_filter !== 'default') ? 'open' : '' ?>">
                <div class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end mt-4">
                    
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 mb-1 uppercase">Status</label>
                        <select name="status" id="statusFilter" class="w-full p-2 filter-select">
                            <option value="">All Statuses</option>
                            <option value="Critical" <?= $status_filter == 'Critical' ? 'selected' : '' ?>>Critical</option>
                            <option value="Low Stock" <?= $status_filter == 'Low Stock' ? 'selected' : '' ?>>Low Stock</option>
                            <option value="Optimal" <?= $status_filter == 'Optimal' ? 'selected' : '' ?>>Optimal</option>
                            <option value="Overstocked" <?= $status_filter == 'Overstocked' ? 'selected' : '' ?>>Overstocked</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 mb-1 uppercase">Category</label>
                        <select name="category" id="categoryFilter" class="w-full p-2 filter-select">
                            <option value="">All Categories</option>
                            <?php foreach($all_categories as $c_id => $c_name): ?>
                                <option value="<?= htmlspecialchars($c_id) ?>" <?= $category_filter == $c_id ? 'selected' : '' ?>><?= htmlspecialchars($c_name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 mb-1 uppercase">Brand</label>
                        <select name="brand" id="brandFilter" class="w-full p-2 filter-select">
                            <option value="">All Brands</option>
                            <?php foreach($all_brands as $b): ?>
                                <option value="<?= htmlspecialchars($b) ?>" <?= $brand_filter === $b ? 'selected' : '' ?>><?= htmlspecialchars($b) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 mb-1 uppercase">Sort By</label>
                        <select name="sort" id="sortFilter" class="w-full p-2 filter-select">
                            <option value="default" <?= $sort_filter == 'default' ? 'selected' : '' ?>>Priority (Default)</option>
                            <option value="demand_desc" <?= $sort_filter == 'demand_desc' ? 'selected' : '' ?>>Highest Demand</option>
                            <option value="stock_asc" <?= $sort_filter == 'stock_asc' ? 'selected' : '' ?>>Lowest Stock</option>
                            <option value="trend_desc" <?= $sort_filter == 'trend_desc' ? 'selected' : '' ?>>Highest Trend</option>
                            <option value="name_asc" <?= $sort_filter == 'name_asc' ? 'selected' : '' ?>>Name (A-Z)</option>
                        </select>
                    </div>
                    
                    <div class="flex gap-2">
                        <button type="button" id="resetFiltersBtn" class="bg-gray-200 dark:bg-slate-700 hover:bg-gray-300 dark:hover:bg-slate-600 text-gray-700 dark:text-slate-300 font-bold py-2 px-4 rounded-lg text-sm w-full text-center flex items-center justify-center transition">Reset</button>
                    </div>

                </div>
            </div>
        </form>

        <div class="bg-white dark:bg-slate-900/80 rounded-xl shadow border border-gray-200 dark:border-slate-800 flex-1 overflow-hidden flex flex-col mb-4" id="tableDataArea">
            <div class="overflow-auto flex-1 custom-scroll">
                <table class="w-full text-left min-w-[1000px]">
                    <thead class="bg-[#1E3A1D] dark:bg-slate-800 text-white text-xs uppercase font-bold sticky top-0 z-30 shadow-md">
                        <tr>
                            <th class="px-6 py-4 w-[30%]">Product Analysis</th>
                            <th class="px-6 py-4 text-center w-32">Trend (30d)</th>
                            <th class="px-6 py-4 text-center w-48">Optimized Forecast (7d)</th>
                            <th class="px-6 py-4 text-center w-48">Spoilage Risk (7d)</th>
                            <th class="px-6 py-4 text-center w-40 print-hide">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-800 text-sm relative z-0">
                        <?php if(empty($current_page_items)): ?>
                            <tr>
                                <td colspan="5" class="px-6 py-8 text-center text-gray-500 dark:text-slate-400 font-medium print-hide">No forecasting data found for your search.</td>
                            </tr>
                        <?php endif; ?>
                        
                        <?php foreach($current_page_items as $f): 
                            $trendIcon = $f['trend'] > 0.1 ? 'north_east' : ($f['trend'] < -0.1 ? 'south_east' : 'remove');
                            $trendClass = $f['trend'] > 0.1 ? 'trend-up' : ($f['trend'] < -0.1 ? 'trend-down' : 'trend-flat');
                            $hasSpoilageRisk = $f['spoilage_rate'] > 0.10;
                            
                            $isBulk = $f['is_bulk'];
                            $displayStock = $isBulk ? number_format($f['stock'], 2) : number_format($f['stock'], 0);
                            $displayPredicted = $isBulk ? number_format($f['predicted'], 2) : number_format($f['predicted'], 0);
                            $displayRaw = $isBulk ? number_format($f['raw_prediction'], 2) : number_format($f['raw_prediction'], 0);
                            $displayRestock = $isBulk ? number_format($f['restock'], 2) : number_format($f['restock'], 0);
                            
                            // Image fixing logic
                            $raw_img = !empty($f['image_url']) ? trim($f['image_url']) : '';
                            $img_src = '';
                            if (!empty($raw_img)) {
                                if (preg_match('/^(http|\/)/', $raw_img)) {
                                    $img_src = $raw_img;
                                } else {
                                    $clean_path = preg_replace('/^(\.\.\/)+/', '', $raw_img);
                                    $img_src = '../../' . $clean_path;
                                }
                            }
                        ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-slate-800/50 transition group">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-4">
                                    <div class="w-12 h-12 rounded-lg bg-gray-50 dark:bg-slate-800 flex items-center justify-center border border-gray-200 dark:border-slate-700 shadow-sm relative overflow-hidden flex-shrink-0">
                                        <span class="material-icons text-2xl text-gray-200 dark:text-slate-600 absolute z-0">inventory_2</span>
                                        <?php if (!empty($img_src)): ?>
                                            <img src="<?= htmlspecialchars($img_src, ENT_QUOTES) ?>" alt="Product" class="object-cover w-full h-full absolute inset-0 bg-white dark:bg-slate-800" onerror="this.style.display='none';" />
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="font-bold text-gray-900 dark:text-white text-base leading-tight"><?= htmlspecialchars($f['name']) ?></div>
                                        <div class="text-xs text-gray-500 dark:text-slate-400 flex items-center gap-1 mt-1">
                                            <?= htmlspecialchars($f['category_name']) ?> &bull; <?= htmlspecialchars($f['brand'] ?: 'Generic') ?> &bull; Stock: <span class="font-mono font-bold text-[#1E3A1D] dark:text-green-400 bg-green-50 dark:bg-green-900/30 px-1 rounded"><?= $displayStock ?> <?= htmlspecialchars($f['unit']) ?></span>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <div class="w-24 h-10 mx-auto relative">
                                    <canvas class="sparkline" data-history="[<?= $f['history_sparkline'] ?>]" data-color="<?= $f['trend'] >= 0 ? '#16a34a' : '#dc2626' ?>"></canvas>
                                </div>
                                <div class="text-[10px] font-bold <?= $trendClass ?> flex justify-center items-center mt-1 uppercase tracking-wider whitespace-nowrap">
                                    <?= $f['trend'] > 0 ? '+' : '' ?><?= number_format($f['trend'], 2) ?> vel.
                                </div>
                            </td>
                            <td class="px-6 py-4 text-center align-middle">
                                <div class="inline-block px-5 py-2 bg-blue-50 dark:bg-blue-900/10 rounded-xl border border-blue-100 dark:border-blue-800/50 relative shadow-sm whitespace-nowrap">
                                    <span class="block text-[10px] text-blue-500 dark:text-blue-400 font-bold uppercase tracking-wide mb-0.5">Target Demand</span>
                                    <span class="block text-2xl font-black text-blue-700 dark:text-blue-300 font-mono"><?= $displayPredicted ?> <span class="text-xs text-blue-400 dark:text-blue-500 font-normal font-sans"><?= htmlspecialchars($f['unit']) ?></span></span>
                                    
                                    <?php if($hasSpoilageRisk): ?>
                                        <div class="absolute -top-2.5 -right-2.5 bg-orange-100 dark:bg-orange-900/30 text-orange-700 dark:text-orange-400 text-[10px] font-bold px-2 py-0.5 rounded-full border border-orange-200 dark:border-orange-800/50 shadow-sm" title="Reduced from <?= $displayRaw ?> due to recent waste">
                                            -<?= round($f['spoilage_rate']*100) ?>%
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-center align-middle">
                                <?php if($hasSpoilageRisk): ?>
                                    <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-bold bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-400 border border-red-200 dark:border-red-800/50 shadow-sm whitespace-nowrap pulse-red">
                                        <span class="material-icons text-[14px]">delete_outline</span>
                                        High (<?= round($f['spoilage_rate']*100) ?>%)
                                    </span>
                                    <p class="text-[10px] text-gray-400 dark:text-slate-500 mt-1.5 italic whitespace-nowrap">Recent waste factored.</p>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-400 border border-green-200 dark:border-green-800/50 shadow-sm whitespace-nowrap">
                                        <span class="material-icons text-[14px] mr-1">thumb_up</span> Stable
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-center align-middle print-hide">
                                <?php if($f['status'] === 'Critical'): ?>
                                    <div class="flex items-center justify-center gap-2 text-red-700 dark:text-red-400 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800/50 p-2.5 rounded-lg shadow-sm w-max mx-auto pulse-red">
                                        <span class="material-icons text-sm animate-pulse">warning</span>
                                        <div class="text-left leading-tight">
                                            <div class="text-[10px] font-bold uppercase tracking-wider">Critical</div>
                                            <div class="text-xs whitespace-nowrap">Buy <span class="font-black">+<?= $displayRestock ?></span></div>
                                        </div>
                                    </div>
                                <?php elseif($f['status'] === 'Low Stock'): ?>
                                    <div class="flex items-center justify-center gap-2 text-orange-700 dark:text-orange-400 bg-orange-50 dark:bg-orange-900/30 border border-orange-200 dark:border-orange-800/50 p-2.5 rounded-lg shadow-sm w-max mx-auto group-hover:shadow-[0_0_15px_rgba(249,115,22,0.3)] transition-shadow">
                                        <span class="material-icons text-sm">add_shopping_cart</span>
                                        <div class="text-left leading-tight">
                                            <div class="text-[10px] font-bold uppercase tracking-wider">Restock</div>
                                            <div class="text-xs whitespace-nowrap">Buy <span class="font-black">+<?= $displayRestock ?></span></div>
                                        </div>
                                    </div>
                                <?php elseif($f['status'] === 'Overstocked'): ?>
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 border border-blue-200 dark:border-blue-800/50 shadow-sm whitespace-nowrap">Overstocked</span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-400 border border-green-200 dark:border-green-800/50 shadow-sm whitespace-nowrap">Healthy Stock</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if($total_pages > 0): ?>
            <div class="p-3 border-t border-gray-200 dark:border-slate-800 bg-gray-50 dark:bg-slate-900/50 flex flex-col md:flex-row justify-between items-center text-sm z-10 sticky bottom-0 print-hide">
                <span class="text-gray-500 dark:text-slate-400 mb-2 md:mb-0">
                    Showing <span class="font-bold text-gray-900 dark:text-white"><?= $total_items > 0 ? $offset + 1 : 0 ?></span> 
                    to <span class="font-bold text-gray-900 dark:text-white"><?= min($offset + $per_page, $total_items) ?></span> 
                    of <span class="font-bold text-gray-900 dark:text-white"><?= $total_items ?></span> products
                </span>
                
                <?php if($total_pages > 1): ?>
                <div class="flex items-center gap-1 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg p-1 shadow-sm">
                    <?php for($i=1; $i<=$total_pages; $i++): ?>
                        <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>&category=<?= urlencode($category_filter) ?>&brand=<?= urlencode($brand_filter) ?>&sort=<?= urlencode($sort_filter) ?>" 
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

    <script>
        document.getElementById('secure-body').style.display = 'block';
        
        // Toggle Filter Options
        document.getElementById('toggleFilterBtn').addEventListener('click', function() {
            document.getElementById('filterOptions').classList.toggle('open');
        });

        // ==========================================
        // HTMX-STYLE AJAX & CHART ENGINE
        // ==========================================
        const filterFormAjax = document.getElementById('filterForm');
        const liveSearchInput = document.getElementById('searchInput');
        const tableContainer = document.getElementById('tableDataArea');
        const resetBtn = document.getElementById('resetFiltersBtn');
        let searchTimeout;

        // --- SPARKLINE RENDERER ---
        function renderSparklines() {
            document.querySelectorAll('.sparkline').forEach(canvas => {
                // Destroy old chart instances if they exist to prevent memory leaks
                if (canvas.chartInstance) {
                    canvas.chartInstance.destroy();
                }
                const history = JSON.parse(canvas.dataset.history);
                const color = canvas.dataset.color;
                
                canvas.chartInstance = new Chart(canvas.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: history.map((_, i) => i),
                        datasets: [{ data: history, borderColor: color, borderWidth: 2, pointRadius: 0, fill: false, tension: 0.3 }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { display: false }, tooltip: { enabled: false } },
                        scales: { x: { display: false }, y: { display: false, min: 0 } },
                        layout: { padding: { top: 4, bottom: 4, left: 2, right: 2 } }
                    }
                });
            });
        }

        // --- AJAX SEARCH & FILTER LOGIC ---
        function performAjaxSearch(fetchUrl = null) {
            if (!tableContainer) return;

            let url;
            if (fetchUrl) {
                // If a specific URL is passed (like clicking a Pagination link)
                url = new URL(fetchUrl, window.location.origin);
            } else {
                // Otherwise build the URL from the filter form
                url = new URL(window.location.pathname, window.location.origin);
                const formData = new FormData(filterFormAjax);
                for (const [key, value] of formData.entries()) {
                    if (value) url.searchParams.set(key, value);
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
                    
                    // RE-DRAW SPARKLINE CHARTS FOR THE NEW ROWS!
                    renderSparklines();
                }

                window.history.pushState({}, '', url.toString());
                tableContainer.style.opacity = '1';
            })
            .catch(err => {
                console.error('AJAX Error:', err);
                tableContainer.style.opacity = '1';
            });
        }

        // 1. Live Search Typing
        if (liveSearchInput) {
            liveSearchInput.addEventListener('keyup', function(e) {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => performAjaxSearch(), 300);
            });
            liveSearchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') e.preventDefault();
            });
        }

        // 2. Dropdown Auto-submit
        if (filterFormAjax) {
            const selects = filterFormAjax.querySelectorAll('select');
            selects.forEach(select => {
                select.addEventListener('change', () => performAjaxSearch());
            });

            filterFormAjax.addEventListener('submit', function(e) {
                e.preventDefault();
                performAjaxSearch();
            });
        }

        // 3. Reset Button Action
        if (resetBtn) {
            resetBtn.addEventListener('click', function(e) {
                e.preventDefault();
                if (liveSearchInput) liveSearchInput.value = '';
                
                const selects = filterFormAjax.querySelectorAll('select');
                selects.forEach(select => {
                    select.value = select.options[0].value;
                });
                
                performAjaxSearch();
            });
        }

        // 4. PAGINATION INTERCEPTOR (The Magic Trick!)
        document.addEventListener('click', function(e) {
            // Check if the user clicked a pagination link inside our table area
            const pageLink = e.target.closest('a[href*="?page="]');
            if (pageLink && tableContainer.contains(pageLink)) {
                e.preventDefault(); // Stop page reload
                performAjaxSearch(pageLink.href); // Run AJAX with the page link's URL
            }
        });

        // --- INITIALIZE CHARTS ON FIRST LOAD ---
        
        // 1. Draw the initial Sparklines
        renderSparklines();

        // 2. Draw the Global Aggregate Chart
        const ctx = document.getElementById('globalChart').getContext('2d');
        const gradient = ctx.createLinearGradient(0, 0, 0, 300);
        gradient.addColorStop(0, 'rgba(74, 222, 128, 0.25)'); 
        gradient.addColorStop(1, 'rgba(74, 222, 128, 0)');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= $js_labels ?>,
                datasets: [{
                    label: 'Actual Sales Volume',
                    data: <?= $js_data ?>,
                    borderColor: document.documentElement.classList.contains('dark') ? '#4ade80' : '#1E3A1D',
                    backgroundColor: gradient,
                    borderWidth: 2.5,
                    pointRadius: 0,
                    pointHoverRadius: 6,
                    pointHoverBackgroundColor: '#dc2626',
                    pointHoverBorderColor: '#fff',
                    pointHoverBorderWidth: 2,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { 
                    legend: { display: false }, 
                    tooltip: { 
                        mode: 'index', 
                        intersect: false,
                        backgroundColor: document.documentElement.classList.contains('dark') ? '#1e293b' : '#1E3A1D',
                        titleFont: { family: 'Inter', size: 13 },
                        bodyFont: { family: 'JetBrains Mono', size: 14, weight: 'bold' },
                        padding: 10,
                        cornerRadius: 8,
                        displayColors: false
                    } 
                },
                scales: { 
                    x: { grid: { display: false }, ticks: { font: { family: 'Inter', size: 11 }, color: '#9ca3af' } }, 
                    y: { beginAtZero: true, grid: { borderDash: [4, 4], color: document.documentElement.classList.contains('dark') ? '#334155' : '#f3f4f6' }, ticks: { font: { family: 'JetBrains Mono', size: 11 }, color: '#6b7280' } } 
                },
                interaction: { mode: 'nearest', axis: 'x', intersect: false }
            }
        });
    </script>
</body>
</html>