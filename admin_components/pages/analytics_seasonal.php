<?php

header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1
header("Pragma: no-cache"); // HTTP 1.0
header("Expires: 0"); // Proxies
session_start();
ini_set('display_errors', 0);
include_once '../includes/db_connection.php';

// Security Check
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: ../admin_login.php"); exit;
}

// --- GET FILTERS ---
// 1. Set Current Year
$current_year = date('Y');

// 2. DEFAULT TO LAST YEAR (2025) if no specific year is clicked
// This fixes the "Insufficient Data" error on load
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : ($current_year - 1);
$selected_pid = isset($_GET['pid']) ? (int)$_GET['pid'] : null;

// 3. Fetch Available Years for Toggle
$year_query = $conn->query("SELECT DISTINCT YEAR(sale_date) as yr FROM sales ORDER BY yr DESC");
$available_years = [];
while($yr_row = $year_query->fetch_assoc()) {
    $available_years[] = $yr_row['yr'];
}
// Ensure we always have at least last year and this year in the list
if (empty($available_years)) { 
    $available_years[] = $current_year; 
    $available_years[] = $current_year - 1; 
}
$available_years = array_unique($available_years);
rsort($available_years);

// --- ENGINE: SEASONAL INDEX ANALYZER ---
function getSeasonalInsights($conn, $product_id, $year) {
    // Construct conditions based on parameters
    $conditions = ["s.order_status = 'Completed'"];
    if ($product_id) { $conditions[] = "si.product_id = $product_id"; }
    if ($year) { $conditions[] = "YEAR(s.sale_date) = '$year'"; }
    
    $where_sql = implode(" AND ", $conditions);

    // 1. Fetch Monthly Sales
    $sql = "
        SELECT MONTH(s.sale_date) as mo, SUM(si.quantity) as qty
        FROM sales_items si
        JOIN sales s ON si.sale_id = s.sale_id
        WHERE $where_sql
        GROUP BY MONTH(s.sale_date)
        ORDER BY mo ASC
    ";
    
    $res = $conn->query($sql);
    $monthly_data = array_fill(1, 12, 0); 
    $total_sales = 0;
    
    while ($row = $res->fetch_assoc()) {
        $monthly_data[$row['mo']] = floatval($row['qty']);
        $total_sales += floatval($row['qty']);
    }
    
    // 2. Statistics
    $avg_monthly = ($total_sales > 0) ? $total_sales / 12 : 0;
    $max_month_val = max($monthly_data);
    
    if ($max_month_val > 0) {
        $peak_month_num = array_search($max_month_val, $monthly_data);
        $peak_month_name = date('F', mktime(0, 0, 0, $peak_month_num, 10));
    } else {
        $peak_month_name = "N/A";
    }
    
    $seasonal_indices = [];
    for ($m = 1; $m <= 12; $m++) {
        $actual = $monthly_data[$m];
        $index = ($avg_monthly > 0) ? ($actual / $avg_monthly) : 0;
        $seasonal_indices[$m] = $index;
    }

    return [
        'data' => array_values($monthly_data), 
        'indices' => $seasonal_indices,
        'avg' => $avg_monthly,
        'total' => $total_sales,
        'peak_month' => $peak_month_name,
        'peak_val' => $max_month_val
    ];
}

// --- DATA FETCHING ---
$prod_res = $conn->query("SELECT product_id, name FROM products WHERE status = 'Active'");
$products = $prod_res->fetch_all(MYSQLI_ASSOC);

$insights = getSeasonalInsights($conn, $selected_pid, $selected_year);

// --- SMART PRESCRIPTIVE LOGIC (INVENTORY AWARE + YEAR CONTEXT) ---
$current_month = (int)date('n'); 
$next_month = ($current_month % 12) + 1;
$next_month_name = date('F', mktime(0, 0, 0, $next_month, 10));

// Default Status
$status_color = "blue";
$status_icon = "insights";
$status_title = "Steady Operations";
$prescription = "Market stability detected based on <strong>$selected_year</strong> trends. <br><strong>Strategy:</strong> Maintain standard inventory levels.";

// Check if we are looking at an empty year
if ($insights['total'] == 0) {
    $status_color = "gray";
    $status_icon = "cloud_off";
    $status_title = "Insufficient Data";
    $prescription = "No sales data available for <strong>$selected_year</strong> yet. <br><strong>Strategy:</strong> Start recording sales or switch to a previous year to view historical trends.";
} 
// IF DATA EXISTS, RUN LOGIC
else {
    if ($selected_pid) {
        // --- SINGLE PRODUCT VIEW (Deep Dive) ---
        // 1. Fetch Current Stock
        $stock_q = $conn->query("SELECT quantity FROM product_inventory WHERE product_id = $selected_pid");
        $current_stock = ($stock_q && $stock_q->num_rows > 0) ? floatval($stock_q->fetch_assoc()['quantity']) : 0;

        $next_index = $insights['indices'][$next_month] ?? 0;
        
        // 2. Compare Trend vs Stock
        if ($next_index > 1.15) { 
            $percent = round(($next_index - 1) * 100);
            if ($current_stock < 50) { 
                $status_color = "red"; $status_icon = "warning"; $status_title = "🚨 Stockout Risk";
                $prescription = "<strong>CRITICAL:</strong> Viral spike of <strong>+$percent%</strong> seen in $selected_year history, but you only have <strong>$current_stock units</strong>.<br><strong>Action:</strong> Place emergency order.";
            } else {
                $status_color = "emerald"; $status_icon = "rocket_launch"; $status_title = "Peak Season Ready";
                $prescription = "Demand surge of <strong>+$percent%</strong> expected based on historical trends.<br><strong>Strategy:</strong> You are well stocked ($current_stock units).";
            }
        } elseif ($next_index < 0.85 && $next_index > 0) {
            $percent = round((1 - $next_index) * 100);
            if ($current_stock > 100) {
                $status_color = "orange"; $status_icon = "production_quantity_limits"; $status_title = "⚠️ Overstock Warning";
                $prescription = "Historical data shows a <strong>-$percent%</strong> drop in $next_month_name. You have $current_stock units.<br><strong>Action:</strong> Run clearance promo.";
            } else {
                $status_color = "amber"; $status_icon = "trending_down"; $status_title = "Off-Peak Season";
                $prescription = "Demand drop of <strong>-$percent%</strong> expected.<br><strong>Strategy:</strong> Reduce purchase orders.";
            }
        }
    } else {
        // --- MANAGER VIEW (Scanning All Products for Risks) ---
        $risks = []; // Overstock risks
        $opportunities = []; // Restock opportunities

        foreach ($products as $p) {
            $pid = $p['product_id'];
            // Analyze seasonality for this product
            $p_insights = getSeasonalInsights($conn, $pid, $selected_year);
            $p_idx = $p_insights['indices'][$next_month] ?? 0;
            
            // Fetch Current Stock for this product
            $sq = $conn->query("SELECT quantity FROM product_inventory WHERE product_id = $pid");
            $stk = ($sq && $sq->num_rows > 0) ? floatval($sq->fetch_assoc()['quantity']) : 0;

            // Logic: Find Mismatches between History and Inventory
            if ($p_idx > 1.20 && $stk < 20) {
                $opportunities[] = "{$p['name']} (High Demand, Low Stock)";
            }
            if ($p_idx < 0.85 && $stk > 100) { 
                $risks[] = "{$p['name']} (Dropping Demand, Overstocked)";
            }
        }

        if (!empty($risks)) {
            $status_color = "red"; $status_icon = "warning"; $status_title = "Overstock / Spoilage Risks";
            $item_string = implode(", ", array_slice($risks, 0, 3));
            $prescription = "<strong>Attention:</strong> The AI detected high inventory for items entering a slow season: <br><strong>$item_string</strong>.<br><strong>Action:</strong> Stop ordering these immediately and sell off current stock.";
        } elseif (!empty($opportunities)) {
            $status_color = "violet"; $status_icon = "shopping_cart_checkout"; $status_title = "Restock Opportunities";
            $item_string = implode(", ", array_slice($opportunities, 0, 3));
            $prescription = "Based on $selected_year trends, next month is busy for: <strong>$item_string</strong>, but stock is low.<br><strong>Action:</strong> Prepare inventory.";
        }
    }
}

// --- DARK MODE THEME MAPPER FOR DYNAMIC STATUS COLORS ---
$styleMap = [
    'blue' => ['bg' => 'bg-blue-50 dark:bg-blue-900/20', 'border' => 'border-blue-200 dark:border-blue-800/50', 'text' => 'text-blue-600 dark:text-blue-400', 'icon_bg' => 'bg-blue-100 dark:bg-blue-900/50', 'glow' => 'rgba(59,130,246,0.3)', 'border_focus' => 'dark:hover:border-blue-400'],
    'gray' => ['bg' => 'bg-gray-50 dark:bg-slate-800/50', 'border' => 'border-gray-200 dark:border-slate-700', 'text' => 'text-gray-600 dark:text-slate-400', 'icon_bg' => 'bg-gray-200 dark:bg-slate-700', 'glow' => 'rgba(156,163,175,0.3)', 'border_focus' => 'dark:hover:border-slate-500'],
    'red' => ['bg' => 'bg-red-50 dark:bg-red-900/20', 'border' => 'border-red-200 dark:border-red-800/50', 'text' => 'text-red-600 dark:text-red-400', 'icon_bg' => 'bg-red-100 dark:bg-red-900/50', 'glow' => 'rgba(239,68,68,0.3)', 'border_focus' => 'dark:hover:border-red-400'],
    'emerald' => ['bg' => 'bg-emerald-50 dark:bg-emerald-900/20', 'border' => 'border-emerald-200 dark:border-emerald-800/50', 'text' => 'text-emerald-600 dark:text-emerald-400', 'icon_bg' => 'bg-emerald-100 dark:bg-emerald-900/50', 'glow' => 'rgba(16,185,129,0.3)', 'border_focus' => 'dark:hover:border-emerald-400'],
    'orange' => ['bg' => 'bg-orange-50 dark:bg-orange-900/20', 'border' => 'border-orange-200 dark:border-orange-800/50', 'text' => 'text-orange-600 dark:text-orange-400', 'icon_bg' => 'bg-orange-100 dark:bg-orange-900/50', 'glow' => 'rgba(249,115,22,0.3)', 'border_focus' => 'dark:hover:border-orange-400'],
    'amber' => ['bg' => 'bg-amber-50 dark:bg-amber-900/20', 'border' => 'border-amber-200 dark:border-amber-800/50', 'text' => 'text-amber-600 dark:text-amber-400', 'icon_bg' => 'bg-amber-100 dark:bg-amber-900/50', 'glow' => 'rgba(245,158,11,0.3)', 'border_focus' => 'dark:hover:border-amber-400'],
    'violet' => ['bg' => 'bg-violet-50 dark:bg-violet-900/20', 'border' => 'border-violet-200 dark:border-violet-800/50', 'text' => 'text-violet-600 dark:text-violet-400', 'icon_bg' => 'bg-violet-100 dark:bg-violet-900/50', 'glow' => 'rgba(139,92,246,0.3)', 'border_focus' => 'dark:hover:border-violet-400'],
];
$activeStyle = $styleMap[$status_color] ?? $styleMap['blue'];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <meta charset="UTF-8">
    <title>FreshFlow - Seasonal Intelligence</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
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

        /* --- CLEAN PRINT LAYOUT FIX FOR CAPSTONE PRESENTATIONS --- */
        @media print {
            @page { size: landscape; margin: 10mm; }
            body { background-color: white !important; }
            /* Hide sidebar, header, filter form, and buttons */
            aside, header, form, .print-hide { display: none !important; }
            /* Expand main content to fill the page */
            main { margin-left: 0 !important; padding: 0 !important; overflow: visible !important; height: auto !important; position: static !important; width: 100% !important; }
            /* Preserve background colors perfectly */
            .bg-\[\#1E3A1D\] { background-color: #1E3A1D !important; color: white !important; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            /* Clean up borders and shadows */
            .shadow-sm, .shadow, .rounded-xl { box-shadow: none !important; border: 1px solid #e5e7eb !important; border-radius: 0 !important; }
            /* Ensure table and chart display side by side or cleanly stack */
            .overflow-y-auto, .overflow-auto, .custom-scroll { overflow: visible !important; height: auto !important; display: block !important; }
            .grid { display: flex !important; flex-wrap: wrap !important; gap: 1rem !important; }
            .grid > div { flex: 1 1 30% !important; min-width: 250px; }
            /* Stack the chart and matrix nicely */
            .flex-1.grid { display: block !important; } 
            .flex-1.grid > div { margin-bottom: 20px; page-break-inside: avoid; }
            canvas { max-width: 100% !important; height: auto !important; }
            table { width: 100% !important; page-break-inside: auto; border-collapse: collapse; }
            tr { page-break-inside: avoid; page-break-after: auto; }
            td, th { padding: 8px !important; border-bottom: 1px solid #ddd; }
        }
    </style>
    <script>
    window.onpageshow = function(event) {
        if (event.persisted) {
            window.location.reload();
        }
    };
    window.onbeforeunload = function() {
        if(!document.documentElement.classList.contains('dark')) {
            document.body.style.backgroundColor = "#F8F5EE"; 
        }
    };
    </script>
</head>
<body style="display:none;" id="secure-body" class="flex h-screen overflow-hidden text-gray-800 dark:text-gray-100">
    
    <?php include '../includes/sidebar.php'; ?>

    <main class="ml-20 flex-1 flex flex-col h-screen overflow-hidden relative transition-all duration-300 p-6 md:p-8">
        
        <header class="flex flex-col xl:flex-row justify-between items-start xl:items-center mb-8 flex-shrink-0">
            <div>
                <h1 class="text-3xl font-bold text-[#1E3A1D] dark:text-white tracking-tight flex items-center gap-3">
                    <span class="material-icons text-3xl dark:text-green-400">calendar_month</span> Seasonal Planner
                </h1>
                <p class="text-sm text-gray-500 dark:text-slate-400 mt-1 ml-11">Analyze monthly volume velocity and demand trends.</p>
            </div>
            
            <div class="flex flex-col md:flex-row items-start md:items-center gap-4 mt-4 xl:mt-0">
                <div class="flex bg-white dark:bg-slate-800 p-1 rounded-lg border border-gray-200 dark:border-slate-700 shadow-sm transition">
                    <?php foreach($available_years as $year): ?>
                        <a href="?year=<?= $year ?>&pid=<?= $selected_pid ?>" 
                           class="px-4 py-1.5 rounded-md text-xs font-bold transition-all duration-200 <?= $selected_year == $year ? 'bg-[#1E3A1D] dark:bg-green-600 text-white shadow-sm' : 'text-gray-500 dark:text-slate-400 hover:bg-gray-50 dark:hover:bg-slate-700 hover:text-gray-800 dark:hover:text-white' ?>">
                            <?= $year ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <form action="" method="GET" class="flex items-center w-full md:w-auto">
                    <input type="hidden" name="year" value="<?= $selected_year ?>">
                    <div class="relative w-full md:w-64">
                        <select name="pid" onchange="this.form.submit()" class="appearance-none w-full bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-700 text-gray-700 dark:text-white text-sm rounded-lg focus:ring-1 focus:ring-[#1E3A1D] dark:focus:ring-green-400 focus:border-[#1E3A1D] dark:focus:border-green-400 block p-2.5 shadow-sm font-medium outline-none cursor-pointer transition">
                            <option value="">-- All Products Aggregate --</option>
                            <?php foreach($products as $p): ?>
                                <option value="<?= $p['product_id'] ?>" <?= $selected_pid == $p['product_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 dark:text-slate-500">
                            <span class="material-icons text-sm">expand_more</span>
                        </div>
                    </div>
                </form>
            </div>
        </header>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6 flex-shrink-0">
            <div class="bg-white dark:bg-slate-900/80 p-5 rounded-xl border border-green-100 dark:border-slate-800 flex items-center gap-4 transition-all duration-300 shadow-sm cursor-default group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(34,197,94,0.2)] dark:hover:shadow-[0_0_20px_rgba(34,197,94,0.3)] dark:hover:border-green-400">
                <div class="w-12 h-12 rounded-lg bg-green-50 dark:bg-green-900/30 text-green-600 dark:text-green-400 flex items-center justify-center group-hover:bg-green-100 dark:group-hover:bg-green-800/50 transition-colors">
                    <span class="material-icons text-2xl group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">event_available</span>
                </div>
                <div>
                    <p class="text-[10px] font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-green-600 dark:group-hover:text-green-400 transition-colors">Peak Month (<?= $selected_year ?>)</p>
                    <p class="text-xl font-black text-gray-800 dark:text-white mt-0.5 group-hover:scale-110 transition-transform origin-left"><?= $insights['peak_month'] ?></p>
                    <p class="text-xs text-green-600 dark:text-green-400 font-bold flex items-center mt-1 font-mono">
                        <span class="material-icons text-[12px] mr-1">arrow_upward</span>
                        <?= number_format($insights['peak_val']) ?> units
                    </p>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900/80 p-5 rounded-xl border border-blue-100 dark:border-slate-800 flex items-center gap-4 transition-all duration-300 shadow-sm cursor-default group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(59,130,246,0.2)] dark:hover:shadow-[0_0_20px_rgba(59,130,246,0.3)] dark:hover:border-blue-400">
                <div class="w-12 h-12 rounded-lg bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 flex items-center justify-center group-hover:bg-blue-100 dark:group-hover:bg-blue-800/50 transition-colors">
                    <span class="material-icons text-2xl group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">inventory_2</span>
                </div>
                <div>
                    <p class="text-[10px] font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">Total Yearly Volume</p>
                    <p class="text-xl font-black text-gray-800 dark:text-white mt-0.5 group-hover:scale-110 transition-transform origin-left font-mono"><?= number_format($insights['total']) ?></p>
                    <p class="text-xs text-gray-500 dark:text-slate-400 font-medium mt-1">Avg. <?= number_format($insights['avg'], 0) ?> / month</p>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900/80 p-5 rounded-xl border <?= $activeStyle['border'] ?> flex items-center gap-4 transition-all duration-300 shadow-sm cursor-default group hover:-translate-y-1 hover:shadow-[0_8px_20px_<?= $activeStyle['glow'] ?>] dark:hover:shadow-[0_0_20px_<?= $activeStyle['glow'] ?>] <?= $activeStyle['border_focus'] ?>">
                <div class="w-12 h-12 rounded-lg <?= $activeStyle['icon_bg'] ?> <?= $activeStyle['text'] ?> flex items-center justify-center transition-colors">
                    <span class="material-icons text-2xl group-hover:drop-shadow-[0_0_8px_currentColor] transition-all"><?= $status_icon ?></span>
                </div>
                <div>
                    <p class="text-[10px] font-bold <?= $activeStyle['text'] ?> uppercase tracking-wider">Next Month Outlook</p>
                    <p class="text-xl font-black text-gray-800 dark:text-white mt-0.5 group-hover:scale-110 transition-transform origin-left"><?= $next_month_name ?></p>
                    <p class="text-xs font-bold <?= $activeStyle['text'] ?> mt-1 font-mono">
                        Index Score: <?= number_format(isset($next_index) ? $next_index : 1.0, 2) ?>x
                    </p>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-900/80 rounded-xl shadow-sm border border-gray-200 dark:border-slate-800 mb-6 flex-shrink-0 overflow-hidden transition">
            <div class="p-6 <?= $activeStyle['bg'] ?> border-l-4 <?= $activeStyle['border'] ?>">
                <div class="flex gap-4 items-start">
                    <div class="p-2 bg-white dark:bg-slate-800 rounded-lg shadow-sm <?= $activeStyle['text'] ?> border border-gray-100 dark:border-slate-700 flex-shrink-0">
                        <span class="material-icons text-2xl">psychology_alt</span>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-base font-bold text-gray-800 dark:text-white mb-1 flex flex-wrap items-center gap-2">
                            AI Strategic Recommendation
                            <span class="<?= $activeStyle['icon_bg'] ?> <?= $activeStyle['text'] ?> text-[10px] px-2 py-0.5 rounded uppercase tracking-wider font-bold border <?= $activeStyle['border'] ?>">
                                <?= $status_title ?>
                            </span>
                        </h3>
                        <p class="text-gray-600 dark:text-slate-300 text-sm leading-relaxed mt-1">
                            <?= $prescription ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex-1 grid grid-cols-1 xl:grid-cols-2 gap-6 min-h-0 overflow-hidden mb-4">
            
            <div class="bg-white dark:bg-slate-900/80 p-6 rounded-xl shadow-sm border border-gray-200 dark:border-slate-800 flex flex-col h-full overflow-hidden transition">
                <div class="flex justify-between items-start mb-6 flex-shrink-0">
                    <div>
                        <h3 class="font-bold text-gray-800 dark:text-white text-lg">Historical Seasonal Curve</h3>
                        <p class="text-xs text-gray-500 dark:text-slate-400">Demand velocity across the selected fiscal year.</p>
                    </div>
                    <div class="flex flex-col items-end gap-1">
                         <span class="flex items-center gap-1.5 text-[10px] font-bold text-gray-500 dark:text-slate-400 uppercase"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span> Peak (>1.2x)</span>
                         <span class="flex items-center gap-1.5 text-[10px] font-bold text-gray-500 dark:text-slate-400 uppercase"><span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span> Low (<0.8x)</span>
                    </div>
                </div>
                <div class="flex-1 w-full relative min-h-[250px]">
                    <canvas id="seasonalChart"></canvas>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900/80 rounded-xl shadow-sm border border-gray-200 dark:border-slate-800 flex flex-col h-full overflow-hidden transition">
                <div class="p-5 border-b border-gray-100 dark:border-slate-700 flex justify-between items-center bg-gray-50 dark:bg-slate-800 flex-shrink-0">
                    <h3 class="font-bold text-[#1E3A1D] dark:text-green-400 text-sm uppercase tracking-wide">Monthly Velocity Matrix</h3>
                    <button onclick="window.print()" class="text-xs text-blue-600 dark:text-blue-400 font-bold hover:text-blue-800 dark:hover:text-blue-300 transition flex items-center gap-1 bg-blue-50 dark:bg-blue-900/30 px-2 py-1 rounded border border-blue-100 dark:border-blue-800/50 print-hide">
                        <span class="material-icons text-[14px]">print</span> Export
                    </button>
                </div>
                <div class="overflow-y-auto flex-1 custom-scroll">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-[#1E3A1D] dark:bg-slate-800 text-white text-[10px] uppercase font-bold tracking-wider sticky top-0 z-10 shadow-sm">
                            <tr>
                                <th class="px-6 py-4">Month</th>
                                <th class="px-6 py-4 text-right">Volume</th>
                                <th class="px-6 py-4 text-center">Seasonal Index</th>
                                <th class="px-6 py-4">Status Indicator</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-slate-800">
                            <?php 
                            $months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
                            for($m=1; $m<=12; $m++): 
                                $idx = $insights['indices'][$m];
                                $vol = $insights['data'][$m-1];
                                $is_current = ($m == (int)date('n') && $selected_year == (int)date('Y'));
                                $row_bg = $is_current ? "bg-blue-50/40 dark:bg-blue-900/10" : "hover:bg-gray-50 dark:hover:bg-slate-800/50";
                                
                                // Dark Mode Compatible Badges
                                $badge = '<span class="px-2 py-1 rounded bg-gray-50 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 text-gray-500 dark:text-slate-300 text-[10px] font-bold uppercase tracking-wider shadow-sm">Normal</span>';
                                if($idx > 1.2) $badge = '<span class="px-2 py-1 rounded bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800/50 text-emerald-700 dark:text-emerald-400 text-[10px] font-bold uppercase tracking-wider shadow-sm">High Demand</span>';
                                if($idx < 0.8 && $vol > 0) $badge = '<span class="px-2 py-1 rounded bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800/50 text-amber-700 dark:text-amber-400 text-[10px] font-bold uppercase tracking-wider shadow-sm">Slow</span>';
                                if($vol == 0) $badge = '<span class="px-2 py-1 rounded bg-gray-100 dark:bg-slate-800 border border-gray-200 dark:border-slate-700 text-gray-400 dark:text-slate-500 text-[10px] font-bold uppercase tracking-wider">No Data</span>';
                            ?>
                            <tr class="transition <?= $row_bg ?>">
                                <td class="px-6 py-4 font-bold text-gray-800 dark:text-white flex items-center gap-2">
                                    <?= $months[$m-1] ?> 
                                    <?php if($is_current) echo '<span class="w-2 h-2 rounded-full bg-blue-500 animate-pulse" title="Current Month"></span>'; ?>
                                </td>
                                <td class="px-6 py-4 text-right font-mono text-gray-600 dark:text-slate-300 font-medium"><?= number_format($vol) ?></td>
                                <td class="px-6 py-4 text-center">
                                    <span class="font-mono text-sm font-bold text-[#1E3A1D] dark:text-green-400"><?= number_format($idx, 2) ?>x</span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-4">
                                        <div class="w-24 bg-gray-200 dark:bg-slate-700 rounded-full h-1.5 overflow-hidden border border-gray-300 dark:border-slate-600">
                                            <div class="h-full rounded-full bg-[#1E3A1D] dark:bg-green-500" style="width: <?= min(100, $idx * 100) ?>%"></div>
                                        </div>
                                        <?= $badge ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>

    <script>
        document.getElementById('secure-body').style.display = 'block';

        const ctx = document.getElementById('seasonalChart').getContext('2d');
        const isDarkMode = document.documentElement.classList.contains('dark');
        
        const months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
        const data = <?= json_encode($insights['data']) ?>;
        const avg = <?= $insights['avg'] ?>;
        
        // Adaptive colors based on dark mode status
        const colorHigh = isDarkMode ? '#34d399' : '#10B981'; // Emerald 400 vs 500
        const colorLow = isDarkMode ? '#fbbf24' : '#F59E0B';  // Amber 400 vs 500
        const colorZero = isDarkMode ? '#334155' : '#E5E7EB'; // Slate 700 vs Gray 200
        const colorNorm = isDarkMode ? '#4ade80' : '#1E3A1D'; // Green 400 vs Brand Green

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: months,
                datasets: [{
                    label: 'Sales Volume',
                    data: data,
                    backgroundColor: data.map(val => val > avg * 1.2 ? colorHigh : (val < avg * 0.8 && val > 0 ? colorLow : (val === 0 ? colorZero : colorNorm))),
                    borderRadius: 4,
                    borderSkipped: false,
                    barThickness: 24
                }, {
                    type: 'line',
                    label: 'Yearly Average',
                    data: Array(12).fill(avg),
                    borderColor: isDarkMode ? '#64748b' : '#9ca3af',
                    borderWidth: 2,
                    borderDash: [5, 5],
                    pointRadius: 0,
                    order: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { 
                    legend: { display: false },
                    tooltip: { 
                        backgroundColor: isDarkMode ? '#1e293b' : '#1E3A1D',
                        padding: 12,
                        cornerRadius: 8,
                        titleFont: { size: 13, family: "'Inter', sans-serif" },
                        bodyFont: { size: 13, family: "'JetBrains Mono', monospace", weight: 'bold' },
                        displayColors: false
                    }
                },
                scales: { 
                    y: { 
                        beginAtZero: true, 
                        grid: { borderDash: [4, 4], color: isDarkMode ? '#334155' : '#f3f4f6' },
                        ticks: { font: { size: 11, family: "'JetBrains Mono', monospace" }, color: isDarkMode ? '#94a3b8' : '#6b7280' }
                    },
                    x: { 
                        grid: { display: false },
                        ticks: { font: { size: 11, family: "'Inter', sans-serif" }, color: isDarkMode ? '#94a3b8' : '#9ca3af' }
                    }
                },
                interaction: { mode: 'index', intersect: false }
            }
        });
    </script>
</body>
</html>