<?php
// C:\xampp\htdocs\RalphPHP\admin_components\pages\cashflow.php

ob_start();
if (session_status() == PHP_SESSION_NONE) { session_start(); }

// Catch errors gracefully instead of throwing 500 Server Errors
ini_set('display_errors', 0); 
ini_set('log_errors', 1);

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

include_once '../includes/db_connection.php';
include_once '../includes/audit_helper.php';

// --- SECURITY CHECK ---
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: ../admin_login.php");
    exit;
}

// --- GET FILTER PARAMETERS ---
$filter_start = isset($_GET['start_date']) && !empty($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$filter_end = isset($_GET['end_date']) && !empty($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

$transactions = [];
$total_income = 0;
$total_expense = 0;
$error_msg = "";

try {
    $clean_start = $conn->real_escape_string($filter_start);
    $clean_end = $conn->real_escape_string($filter_end);

    // --- 1. FETCH INCOME (SALES) SAFELY ---
    try {
        // Dynamically check for delivered_at column to avoid 500 errors
        $sales_date_col = "DATE(delivery_date)";
        $check_col = $conn->query("SHOW COLUMNS FROM sales LIKE 'delivered_at'");
        if ($check_col && $check_col->num_rows > 0) {
            $sales_date_col = "COALESCE(DATE(delivered_at), DATE(delivery_date))";
        }

        $sales_sql = "
            SELECT sale_id, $sales_date_col as t_date, total_amount 
            FROM sales 
            WHERE order_status IN ('Completed', 'Delivered') 
            AND $sales_date_col >= '$clean_start' 
            AND $sales_date_col <= '$clean_end'
        ";
        
        $sales_res = $conn->query($sales_sql);
        if ($sales_res) {
            while ($r = $sales_res->fetch_assoc()) {
                $amt = floatval($r['total_amount']);
                if ($amt > 0) { // Only count sales that generated money
                    $transactions[] = [
                        'date' => $r['t_date'],
                        'type' => 'Income',
                        'category' => 'Sales Revenue',
                        'description' => 'Payment received for Order #' . str_pad($r['sale_id'], 5, '0', STR_PAD_LEFT),
                        'amount' => $amt,
                        'ref_id' => $r['sale_id']
                    ];
                    $total_income += $amt;
                }
            }
        }
    } catch (\Throwable $e) { $error_msg .= "Sales DB Error: " . $e->getMessage(); }

    // --- 2. FETCH EXPENSES (MONEY OUT) SAFELY ---
    try {
        // Dynamically check for ID column name to avoid 500 errors
        $exp_pk = 'id';
        $check_exp = $conn->query("SHOW COLUMNS FROM expenses LIKE 'expense_id'");
        if ($check_exp && $check_exp->num_rows > 0) { $exp_pk = 'expense_id'; }

        $exp_sql = "
            SELECT expense_date, category, description, amount, $exp_pk as id 
            FROM expenses 
            WHERE expense_date >= '$clean_start' AND expense_date <= '$clean_end'
        ";
        
        $exp_res = $conn->query($exp_sql);
        if ($exp_res) {
            while ($r = $exp_res->fetch_assoc()) {
                $amt = floatval($r['amount']);
                if ($amt > 0) {
                    $transactions[] = [
                        'date' => $r['expense_date'],
                        'type' => 'Expense',
                        'category' => $r['category'],
                        'description' => $r['description'] ?: 'General Expense',
                        'amount' => $amt,
                        'ref_id' => $r['id']
                    ];
                    $total_expense += $amt;
                }
            }
        }
    } catch (\Throwable $e) { $error_msg .= " Expenses DB Error: " . $e->getMessage(); }

} catch (\Throwable $e) {
    $error_msg = "Critical System Error: " . $e->getMessage();
}

// --- 3. SORT ALL TRANSACTIONS BY DATE (NEWEST FIRST) ---
usort($transactions, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

$net_cashflow = $total_income - $total_expense;

// --- 4. PREPARE CHART DATA ---
$chart_dates = [];
$chart_income = [];
$chart_expense = [];

try {
    $period = new DatePeriod(
         new DateTime($filter_start),
         new DateInterval('P1D'),
         (new DateTime($filter_end))->modify('+1 day')
    );

    foreach ($period as $dt) {
        $d = $dt->format('Y-m-d');
        $chart_dates[] = $dt->format('M d'); 
        $chart_income[$d] = 0;
        $chart_expense[$d] = 0;
    }

    foreach ($transactions as $t) {
        $d = $t['date'];
        if (isset($chart_income[$d])) {
            if ($t['type'] === 'Income') { $chart_income[$d] += $t['amount']; } 
            else { $chart_expense[$d] += $t['amount']; }
        }
    }
} catch(\Throwable $e) {}

$final_chart_income = array_values($chart_income);
$final_chart_expense = array_values($chart_expense);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreshFlow - Cashflow Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;600;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/jpeg" href="/admin_components/assets/img/tabicon4.png">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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
        body { font-family: 'Inter', sans-serif; background-color: var(--brand-cream); color: #1e293b; transition: background-color 0.3s ease; }
        
        /* DARK MODE BODY */
        .dark body {
            background-color: #000000;
            background-image: radial-gradient(circle, rgba(255, 255, 255, 0.07) 1px, transparent 1px);
            background-size: 16px 16px;
            color: #f8fafc;
        }

        .font-mono { font-family: 'Roboto Mono', monospace; }
        
        .custom-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scroll::-webkit-scrollbar-track { background: transparent; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .dark .custom-scroll::-webkit-scrollbar-thumb { background: #334155; }
        
        /* FORM INPUTS (Light & Dark Support) */
        .input-modern { @apply px-4 py-2 bg-white border border-gray-300 text-gray-900 rounded-lg text-sm focus:ring-2 focus:ring-[#1E3A1D]/20 focus:border-[#1E3A1D] outline-none shadow-sm transition-all; }
        .dark .input-modern { background-color: #1e293b; border-color: #334155; color: #f8fafc; }
        .dark .input-modern:focus { border-color: #4ade80; box-shadow: 0 0 0 3px rgba(74, 222, 128, 0.1); }

        .optimized-main { will-change: margin-left, width; transition: margin-left 0.3s ease, width 0.3s ease; }

        /* --- PERFECT PRINT LAYOUT --- */
        @media print {
            @page { size: landscape; margin: 10mm; }
            body { background-color: white !important; color: black !important; background-image: none !important; }
            /* Hide UI Elements */
            aside, .print-hide { display: none !important; }
            /* Expand Main Content */
            main { margin: 0 !important; padding: 0 !important; overflow: visible !important; height: auto !important; position: static !important; width: 100% !important; }
            /* Stack Grid items safely */
            .flex-col.xl\:flex-row { flex-direction: column !important; }
            .w-full.xl\:w-1\/3 { width: 100% !important; margin-bottom: 20px; }
            .flex-1 { flex: none !important; height: auto !important; }
            /* Clean Up Containers */
            .rounded-3xl, .rounded-2xl { border-radius: 8px !important; border: 1px solid #ddd !important; box-shadow: none !important; }
            .overflow-hidden, .overflow-y-auto, .custom-scroll { overflow: visible !important; height: auto !important; display: block !important; }
            /* Table Formatting */
            table { width: 100% !important; border-collapse: collapse !important; }
            th { background-color: #f8fafc !important; color: #1e293b !important; padding: 10px !important; border-bottom: 2px solid #ddd !important; }
            td { padding: 8px 10px !important; border-bottom: 1px solid #eee !important; color: black !important; }
            tr { page-break-inside: avoid !important; }
            /* Ensure colors print exactly */
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }
    </style>
</head>
<body style="display:none;" id="secure-body" class="flex h-screen overflow-hidden">

    <?php include '../includes/sidebar.php'; ?>

    <main class="ml-20 flex-1 flex flex-col h-screen overflow-hidden relative optimized-main p-6 lg:p-8">
        
        <header class="flex flex-col xl:flex-row justify-between items-start xl:items-center mb-6 flex-shrink-0 gap-6">
            <div>
                <h1 class="text-3xl font-black text-[#1E3A1D] dark:text-white tracking-tight flex items-center gap-3">
                    <span class="material-icons text-3xl dark:text-green-400">currency_exchange</span> 
                    Cashflow Statement
                </h1>
                <p class="text-sm text-gray-500 dark:text-slate-400 mt-1 ml-11 font-medium">Track your overall financial health by merging Sales and Expenses.</p>
            </div>
        </header>

        <?php if (!empty($error_msg)): ?>
            <div class="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800/50 text-red-700 dark:text-red-400 px-4 py-3 rounded-xl mb-6 shadow-sm flex items-center gap-3 font-bold text-sm">
                <span class="material-icons text-red-500">error</span>
                <?= htmlspecialchars($error_msg) ?>
            </div>
        <?php endif; ?>

        <div id="metricsArea" class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6 flex-shrink-0">
            
            <div class="bg-white dark:bg-slate-900/80 border border-green-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-default group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(34,197,94,0.2)] dark:hover:shadow-[0_0_20px_rgba(34,197,94,0.3)] dark:hover:border-green-400 transition-all duration-300 shadow-sm">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-green-600 dark:group-hover:text-green-400 transition-colors">Money In (Sales)</p>
                    <p class="text-3xl font-bold text-green-600 dark:text-green-400 mt-1 font-mono group-hover:scale-110 transition-transform origin-left">₱<?= number_format($total_income, 2) ?></p>
                </div>
                <div class="p-3 bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400 rounded-lg group-hover:bg-green-200 dark:group-hover:bg-green-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">payments</span>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900/80 border border-red-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-default group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(239,68,68,0.2)] dark:hover:shadow-[0_0_20px_rgba(239,68,68,0.3)] dark:hover:border-red-400 transition-all duration-300 shadow-sm">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-red-600 dark:group-hover:text-red-400 transition-colors">Money Out (Expenses)</p>
                    <p class="text-3xl font-bold text-red-600 dark:text-red-400 mt-1 font-mono group-hover:scale-110 transition-transform origin-left">₱<?= number_format($total_expense, 2) ?></p>
                </div>
                <div class="p-3 bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 rounded-lg group-hover:bg-red-200 dark:group-hover:bg-red-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">money_off</span>
                </div>
            </div>

            <?php 
                $is_positive = $net_cashflow >= 0;
                $net_color_text = $is_positive ? 'text-blue-600 dark:text-blue-400' : 'text-red-600 dark:text-red-400';
                $net_color_hover_text = $is_positive ? 'group-hover:text-blue-600 dark:group-hover:text-blue-400' : 'group-hover:text-red-600 dark:group-hover:text-red-400';
                $net_border = $is_positive ? 'border-blue-100 dark:border-slate-800' : 'border-red-100 dark:border-slate-800';
                $net_icon = $is_positive ? 'account_balance' : 'warning';
                
                $glow_class = $is_positive 
                    ? 'hover:shadow-[0_8px_20px_rgba(59,130,246,0.2)] dark:hover:shadow-[0_0_20px_rgba(59,130,246,0.3)] dark:hover:border-blue-400' 
                    : 'hover:shadow-[0_8px_20px_rgba(239,68,68,0.2)] dark:hover:shadow-[0_0_20px_rgba(239,68,68,0.3)] dark:hover:border-red-400';

                $icon_bg_class = $is_positive
                    ? 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 group-hover:bg-blue-200 dark:group-hover:bg-blue-800/50'
                    : 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 group-hover:bg-red-200 dark:group-hover:bg-red-800/50';
            ?>
            <div class="bg-white dark:bg-slate-900/80 border <?= $net_border ?> p-5 rounded-xl flex items-center justify-between cursor-default group hover:-translate-y-1 transition-all duration-300 shadow-sm <?= $glow_class ?>">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider <?= $net_color_hover_text ?> transition-colors">Net Cashflow Position</p>
                    <p class="text-3xl font-bold <?= $net_color_text ?> mt-1 font-mono group-hover:scale-110 transition-transform origin-left">₱<?= number_format($net_cashflow, 2) ?></p>
                </div>
                <div class="p-3 rounded-lg transition-colors <?= $icon_bg_class ?>">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all"><?= $net_icon ?></span>
                </div>
            </div>

        </div>

        <div class="bg-white dark:bg-slate-900/80 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-800 p-4 flex flex-col xl:flex-row gap-4 justify-between items-start xl:items-center flex-shrink-0 z-10 mb-6 print-hide">
            <form id="filterForm" method="GET" action="" class="flex flex-wrap gap-3 items-end w-full xl:w-auto">
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 dark:text-slate-400 uppercase mb-1.5 ml-1">Start Date</label>
                    <input type="date" name="start_date" id="startDate" value="<?= htmlspecialchars($filter_start) ?>" class="input-modern cursor-pointer">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 dark:text-slate-400 uppercase mb-1.5 ml-1">End Date</label>
                    <input type="date" name="end_date" id="endDate" value="<?= htmlspecialchars($filter_end) ?>" class="input-modern cursor-pointer">
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="bg-[#1E3A1D] dark:bg-green-600 text-white px-6 py-2.5 rounded-lg text-sm font-bold hover:bg-[#2a4e29] dark:hover:bg-green-500 transition shadow-sm border border-transparent">Generate</button>
                    <button type="button" id="resetFiltersBtn" class="text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-slate-800 px-5 py-2.5 rounded-lg text-sm font-bold hover:bg-gray-200 dark:hover:bg-slate-700 transition border border-gray-200 dark:border-slate-700">Reset</button>
                </div>
            </form>

            <button type="button" onclick="window.print()" class="bg-white dark:bg-slate-800 text-gray-800 dark:text-slate-200 border border-gray-300 dark:border-slate-700 hover:bg-gray-50 dark:hover:bg-slate-700 px-5 py-2.5 rounded-lg text-sm font-bold shadow-sm transition flex items-center gap-2 whitespace-nowrap active:scale-95">
                <span class="material-icons text-[18px]">print</span> Print / Export
            </button>
        </div>

        <div class="flex-1 flex flex-col xl:flex-row gap-6 overflow-hidden">
            
            <div id="chartContainer" class="flex-1 relative w-full h-full">
                    <div id="chartDataPayload" class="hidden" 
                         data-labels='<?= htmlspecialchars(json_encode($chart_dates), ENT_QUOTES, 'UTF-8') ?>'
                         data-income='<?= htmlspecialchars(json_encode($final_chart_income), ENT_QUOTES, 'UTF-8') ?>'
                         data-expense='<?= htmlspecialchars(json_encode($final_chart_expense), ENT_QUOTES, 'UTF-8') ?>'>
                    </div>
                    <canvas id="cashflowChart"></canvas>
                </div>
            </div>

            <div id="tableDataArea" class="flex-1 bg-white dark:bg-slate-900/80 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-800 flex flex-col overflow-hidden relative">
                <div class="px-6 py-5 border-b border-gray-100 dark:border-slate-700 bg-gray-50/50 dark:bg-slate-800 flex justify-between items-center print-hide">
                    <h2 class="text-sm font-bold text-gray-800 dark:text-white flex items-center gap-2">
                        <span class="material-icons text-blue-600 dark:text-blue-400">view_list</span> Transaction Ledger
                    </h2>
                    <span class="text-[10px] font-bold text-gray-500 dark:text-slate-400 uppercase tracking-widest bg-gray-200 dark:bg-slate-700 px-3 py-1 rounded-full">Single Source of Truth</span>
                </div>
                
                <div class="overflow-y-auto flex-1 custom-scroll">
                    <table class="w-full text-left border-collapse">
                        <thead class="bg-[#1E3A1D] dark:bg-slate-800 text-white text-[10px] uppercase tracking-widest font-bold sticky top-0 z-10 shadow-sm">
                            <tr>
                                <th class="p-4 pl-6 w-32">Date</th>
                                <th class="p-4 w-32">Flow Type</th>
                                <th class="p-4 w-48">Category</th>
                                <th class="p-4 w-auto">Description / Ref</th>
                                <th class="p-4 pr-6 text-right w-40">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-slate-800 text-sm">
                            <?php if(empty($transactions)): ?>
                                <tr><td colspan="5" class="p-16 text-center text-gray-400 dark:text-slate-500 font-medium">No financial transactions found for this date range.</td></tr>
                            <?php else: ?>
                                <?php foreach($transactions as $t): 
                                    $is_income = ($t['type'] === 'Income');
                                    $row_bg = $is_income ? 'hover:bg-green-50/30 dark:hover:bg-green-900/10' : 'hover:bg-red-50/30 dark:hover:bg-red-900/10';
                                    
                                    $type_badge = $is_income 
                                        ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 border-green-200 dark:border-green-800/50' 
                                        : 'bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-400 border-red-100 dark:border-red-800/50';
                                        
                                    $amt_color = $is_income ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400';
                                    $prefix = $is_income ? '+' : '-';
                                ?>
                                <tr class="transition-colors duration-200 group <?= $row_bg ?>">
                                    <td class="p-4 pl-6 align-middle whitespace-nowrap">
                                        <div class="font-bold text-gray-800 dark:text-white"><?= date('M d, Y', strtotime($t['date'])) ?></div>
                                    </td>
                                    <td class="p-4 align-middle">
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-md text-[10px] font-bold border shadow-sm <?= $type_badge ?>">
                                            <span class="material-icons text-[12px] mr-1"><?= $is_income ? 'call_received' : 'call_made' ?></span>
                                            <?= $t['type'] ?>
                                        </span>
                                    </td>
                                    <td class="p-4 align-middle">
                                        <div class="font-bold text-gray-700 dark:text-slate-300 text-xs"><?= htmlspecialchars($t['category']) ?></div>
                                    </td>
                                    <td class="p-4 align-middle">
                                        <div class="text-[13px] text-gray-600 dark:text-slate-400 font-medium truncate max-w-[300px] italic" title="<?= htmlspecialchars($t['description']) ?>">
                                            <?= htmlspecialchars($t['description']) ?>
                                        </div>
                                    </td>
                                    <td class="p-4 pr-6 align-middle text-right">
                                        <div class="font-mono font-black text-[15px] <?= $amt_color ?>">
                                            <?= $prefix ?>₱<?= number_format($t['amount'], 2) ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>

    <script>
        document.getElementById('secure-body').style.display = 'block';
        
        const isDarkMode = document.documentElement.classList.contains('dark');
        let cashflowChartInstance = null;

        // --- 1. CHART RENDER ENGINE ---
        function renderChart(labels, incomeData, expenseData) {
            const ctx = document.getElementById('cashflowChart').getContext('2d');
            
            if (cashflowChartInstance) {
                cashflowChartInstance.destroy(); // Destroy old chart before drawing new one
            }

            const colorIncome = isDarkMode ? '#4ade80' : '#22c55e';
            const colorExpense = isDarkMode ? '#f87171' : '#ef4444';

            cashflowChartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        { label: 'Income (In)', data: incomeData, backgroundColor: colorIncome, borderRadius: 4, barPercentage: 0.6, categoryPercentage: 0.8 },
                        { label: 'Expenses (Out)', data: expenseData, backgroundColor: colorExpense, borderRadius: 4, barPercentage: 0.6, categoryPercentage: 0.8 }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 8, color: isDarkMode ? '#cbd5e1' : '#475569', font: { family: "'Inter', sans-serif", size: 11, weight: 'bold' } } },
                        tooltip: {
                            backgroundColor: isDarkMode ? '#1e293b' : 'rgba(255, 255, 255, 0.98)', titleColor: isDarkMode ? '#f8fafc' : '#1e293b', bodyColor: isDarkMode ? '#cbd5e1' : '#475569', borderColor: isDarkMode ? '#334155' : '#e2e8f0', borderWidth: 1, padding: 12, boxPadding: 6, titleFont: { family: "'Inter', sans-serif", size: 13 }, bodyFont: { family: "'Roboto Mono', monospace", size: 12 },
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) { label += ': '; }
                                    if (context.parsed.y !== null) { label += new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(context.parsed.y); }
                                    return label;
                                }
                            }
                        }
                    },
                    scales: {
                        x: { grid: { display: false, drawBorder: false }, ticks: { font: { family: "'Inter', sans-serif", size: 10 }, color: isDarkMode ? '#94a3b8' : '#64748b' } },
                        y: { beginAtZero: true, grid: { color: isDarkMode ? '#334155' : '#f1f5f9', drawBorder: false }, ticks: { font: { family: "'Roboto Mono', monospace", size: 10 }, color: isDarkMode ? '#94a3b8' : '#64748b', callback: function(value) { return '₱' + value; } } }
                    }
                }
            });
        }

        // Initialize Chart on first load
        function initInitialChart() {
            const payload = document.getElementById('chartDataPayload');
            if(payload) {
                const labels = JSON.parse(payload.getAttribute('data-labels'));
                const income = JSON.parse(payload.getAttribute('data-income'));
                const expense = JSON.parse(payload.getAttribute('data-expense'));
                renderChart(labels, income, expense);
            }
        }
        initInitialChart();

        // --- 2. HTMX-STYLE AJAX DASHBOARD ENGINE ---
        const filterFormAjax = document.getElementById('filterForm');
        const metricsArea = document.getElementById('metricsArea');
        const tableContainer = document.getElementById('tableDataArea');
        const chartContainer = document.getElementById('chartContainer');
        const resetBtn = document.getElementById('resetFiltersBtn');

        function performAjaxSearch() {
            if (!tableContainer || !metricsArea) return;

            const url = new URL(window.location.pathname, window.location.origin);
            const formData = new FormData(filterFormAjax);
            for (const [key, value] of formData.entries()) {
                if (value) url.searchParams.set(key, value);
            }

            // Visual loading state
            metricsArea.style.opacity = '0.5';
            tableContainer.style.opacity = '0.5';
            chartContainer.style.opacity = '0.5';

            fetch(url.toString())
            .then(res => res.text())
            .then(html => {
                const parser = new DOMParser();
                const newDoc = parser.parseFromString(html, 'text/html');
                
                // Swap Metrics
                const newMetrics = newDoc.getElementById('metricsArea');
                if (newMetrics) metricsArea.innerHTML = newMetrics.innerHTML;

                // Swap Table
                const newTable = newDoc.getElementById('tableDataArea');
                if (newTable) tableContainer.innerHTML = newTable.innerHTML;

                // Swap Chart Data & Re-render Chart
                const newPayload = newDoc.getElementById('chartDataPayload');
                if (newPayload) {
                    const labels = JSON.parse(newPayload.getAttribute('data-labels'));
                    const income = JSON.parse(newPayload.getAttribute('data-income'));
                    const expense = JSON.parse(newPayload.getAttribute('data-expense'));
                    
                    document.getElementById('chartDataPayload').outerHTML = newPayload.outerHTML;
                    renderChart(labels, income, expense);
                }

                // Update URL & restore opacity
                window.history.pushState({}, '', url.toString());
                metricsArea.style.opacity = '1';
                tableContainer.style.opacity = '1';
                chartContainer.style.opacity = '1';
            })
            .catch(err => {
                console.error('AJAX Error:', err);
                metricsArea.style.opacity = '1'; tableContainer.style.opacity = '1'; chartContainer.style.opacity = '1';
            });
        }

        // --- 3. LISTENERS ---
        if (filterFormAjax) {
            // Auto-submit when dates change without needing the button!
            const inputs = filterFormAjax.querySelectorAll('input[type="date"]');
            inputs.forEach(input => {
                input.addEventListener('change', performAjaxSearch);
            });

            // Handle manual "Generate" click
            filterFormAjax.addEventListener('submit', function(e) {
                e.preventDefault();
                performAjaxSearch();
            });
        }

        if (resetBtn) {
            resetBtn.addEventListener('click', function(e) {
                e.preventDefault();
                
                // Automatically calculate current month start/end dates
                const date = new Date();
                const firstDay = new Date(date.getFullYear(), date.getMonth(), 1).toLocaleDateString('en-CA');
                const lastDay = new Date(date.getFullYear(), date.getMonth() + 1, 0).toLocaleDateString('en-CA');
                
                document.getElementById('startDate').value = firstDay;
                document.getElementById('endDate').value = lastDay;
                
                performAjaxSearch();
            });
        }

        window.onpageshow = function(event) {
            if (event.persisted) window.location.reload();
        };
    </script>
</body>
</html>