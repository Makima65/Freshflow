<?php
// C:\xampp\htdocs\RalphPHP\admin_components\pages\smart_pricing.php

header("Cache-Control: no-cache, no-store, must-revalidate"); 
header("Pragma: no-cache"); 
header("Expires: 0"); 

if (session_status() == PHP_SESSION_NONE) { session_start(); }
ob_start();
ini_set('display_errors', 0); 
ini_set('log_errors', 1);

include_once '../includes/db_connection.php';

// --- SECURITY CHECK ---
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: ../admin_login.php"); exit;
}

// --- AJAX HANDLER: APPLY AI PRICE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'apply_markdown') {
    ob_clean();
    header('Content-Type: application/json');
    $product_id = intval($_POST['product_id']);
    $new_price = floatval($_POST['new_price']);
    
    try {
        $stmt = $conn->prepare("UPDATE products SET price = ? WHERE product_id = ?");
        if (!$stmt) { $stmt = $conn->prepare("UPDATE products SET price = ? WHERE id = ?"); }
        if ($stmt) {
            $stmt->bind_param("di", $new_price, $product_id);
            if ($stmt->execute()) {
                $auditHelperPath = '../includes/audit_helper.php';
                if (file_exists($auditHelperPath)) { 
                    include_once $auditHelperPath; 
                    if (function_exists('log_audit_action')) {
                        log_audit_action('AI Markdown', 'Pricing Engine', "AI dynamically reduced Product ID $product_id to ₱$new_price to prevent spoilage.");
                    }
                }
                echo json_encode(['success' => true, 'message' => 'AI Markdown applied successfully!']);
                exit;
            }
        }
        echo json_encode(['success' => false, 'message' => 'Failed to update price.']);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Database error while applying price.']);
    }
    exit;
}

// --- AJAX HANDLER: MARKET INTELLIGENCE SEARCH ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'search_market') {
    ob_clean();
    header('Content-Type: application/json');
    
    $search_term = "%" . trim($_POST['search_term']) . "%";
    $results = [];

    try {
        // Find the product in the internal database to establish a baseline
        $sql = "SELECT name, price, unit FROM products WHERE name LIKE ? LIMIT 3";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $search_term);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $base_price = floatval($row['price']);
            
            // --- CAPSTONE MOCK LOGIC: GENERATE LIVE MARKET VARIANCES ---
            // In a real enterprise system, this would query a DA/Grocery API.
            // Here, we generate a random variance (-15% to +15%) to simulate a live market.
            $variance = rand(-15, 15) / 100;
            $market_avg = $base_price + ($base_price * $variance);
            
            // Determine Trend and AI Suggestion
            if ($variance < -0.05) {
                $trend = 'down';
                $suggestion = "Decrease to ₱" . number_format($market_avg * 1.05, 2) . " to remain competitive.";
            } elseif ($variance > 0.05) {
                $trend = 'up';
                $suggestion = "Increase to ₱" . number_format($market_avg * 0.95, 2) . " to maximize profit margin.";
            } else {
                $trend = 'stable';
                $suggestion = "Optimal pricing. No changes needed.";
            }

            $results[] = [
                'name' => htmlspecialchars($row['name']),
                'our_price' => $base_price,
                'market_avg' => $market_avg,
                'trend' => $trend,
                'unit' => htmlspecialchars($row['unit']),
                'suggestion' => $suggestion
            ];
        }
        
        if(empty($results)) {
            echo json_encode(['success' => false, 'message' => 'No products found matching that search.']);
        } else {
            echo json_encode(['success' => true, 'data' => $results]);
        }
        
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Error querying market data.']);
    }
    exit;
}
ob_end_flush();

// --- 1. FETCH EXPIRING INVENTORY (Markdown Engine) ---
$expiring_items = [];
$potential_loss = 0;
$result_expiry = false;

try {
    $sql_expiry = "
        SELECT p.product_id, p.name, p.price, p.unit,
               SUM(pi.quantity) as total_qty, 
               p.expiration_date as next_expiry,
               DATEDIFF(p.expiration_date, CURDATE()) as days_left
        FROM products p
        JOIN product_inventory pi ON p.product_id = pi.product_id
        WHERE pi.quantity > 0 
          AND p.expiration_date IS NOT NULL 
          AND p.expiration_date != '0000-00-00'
          AND DATEDIFF(p.expiration_date, CURDATE()) BETWEEN 0 AND 14
          AND p.status != 'Inactive'
        GROUP BY p.product_id
        ORDER BY days_left ASC
    ";
    $result_expiry = $conn->query($sql_expiry);
} catch (Throwable $e) {
    try {
        $sql_expiry_fallback = "
            SELECT p.id as product_id, p.name, p.price, p.unit,
                   SUM(pi.quantity) as total_qty, 
                   p.expiration_date as next_expiry,
                   DATEDIFF(p.expiration_date, CURDATE()) as days_left
            FROM products p
            JOIN product_inventory pi ON p.id = pi.product_id
            WHERE pi.quantity > 0 
              AND p.expiration_date IS NOT NULL 
              AND p.expiration_date != '0000-00-00'
              AND DATEDIFF(p.expiration_date, CURDATE()) BETWEEN 0 AND 14
              AND p.status != 'Inactive'
            GROUP BY p.id
            ORDER BY days_left ASC
        ";
        $result_expiry = $conn->query($sql_expiry_fallback);
    } catch (Throwable $e2) {
        $result_expiry = false; 
    }
}

if ($result_expiry) {
    while($row = $result_expiry->fetch_assoc()) {
        $expiring_items[] = $row;
        $potential_loss += (floatval($row['price']) * floatval($row['total_qty'])); 
    }
}

function calculate_ai_markdown($base_price, $days_left) {
    if ($days_left <= 1) return $base_price * 0.40; // 60% off 
    if ($days_left <= 3) return $base_price * 0.60; // 40% off
    if ($days_left <= 7) return $base_price * 0.85; // 15% off 
    return $base_price; 
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreshFlow - Smart Pricing AI</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
        .custom-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .dark .custom-scroll::-webkit-scrollbar-thumb { background: #334155; }
        .ai-gradient { background: linear-gradient(135deg, #1E3A1D 0%, #2a4e29 100%); }
        .glow-text { text-shadow: 0 0 15px rgba(255,255,255,0.3); }
        .pulse-red { animation: pulseRed 2s infinite; }
        @keyframes pulseRed {
            0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
            70% { box-shadow: 0 0 0 6px rgba(239, 68, 68, 0); }
            100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }
    </style>
</head>

<body class="flex h-screen overflow-hidden">

    <?php include '../includes/sidebar.php'; ?>

    <main class="ml-20 flex-1 flex flex-col h-screen overflow-hidden relative transition-all duration-300 p-6 md:p-8">
        
        <header class="flex justify-between items-center mb-6 flex-shrink-0">
            <div>
                <h1 class="text-3xl font-black text-[#1E3A1D] dark:text-white tracking-tight flex items-center gap-3">
                    <span class="material-icons text-3xl text-blue-600 dark:text-blue-400">auto_awesome</span> 
                    Smart Pricing & Markdown AI
                </h1>
                <p class="text-sm text-gray-500 dark:text-slate-400 mt-1 ml-11 font-medium">
                    Machine Learning models for dynamic pricing, waste reduction, and competitor analysis.
                </p>
            </div>
            <div class="bg-white dark:bg-slate-800 px-4 py-2 rounded-lg border border-gray-200 dark:border-slate-700 shadow-sm flex items-center gap-3 transition">
                <span class="relative flex h-3 w-3">
                  <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                  <span class="relative inline-flex rounded-full h-3 w-3 bg-green-500"></span>
                </span>
                <span class="text-xs font-bold text-gray-600 dark:text-slate-300 uppercase tracking-widest">AI Engine Active</span>
            </div>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6 flex-shrink-0">
            
            <div class="bg-white dark:bg-slate-900/80 border border-red-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-default group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(239,68,68,0.2)] dark:hover:shadow-[0_0_20px_rgba(239,68,68,0.3)] dark:hover:border-red-400 transition-all duration-300 shadow-sm">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-red-600 dark:group-hover:text-red-400 transition-colors">Capital at Risk (Next 14 Days)</p>
                    <p class="text-3xl font-black text-red-600 dark:text-red-400 mt-1 font-mono group-hover:scale-110 transition-transform origin-left">₱<?= number_format($potential_loss, 2) ?></p>
                </div>
                <div class="p-3 bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 rounded-lg group-hover:bg-red-200 dark:group-hover:bg-red-800/50 transition-colors">
                    <span class="material-icons text-3xl group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">warning</span>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900/80 border border-blue-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-default group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(59,130,246,0.2)] dark:hover:shadow-[0_0_20px_rgba(59,130,246,0.3)] dark:hover:border-blue-400 transition-all duration-300 shadow-sm">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">Items Flagged for Spoilage</p>
                    <p class="text-3xl font-black text-blue-600 dark:text-blue-400 mt-1 font-mono group-hover:scale-110 transition-transform origin-left"><?= count($expiring_items) ?></p>
                </div>
                <div class="p-3 bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 rounded-lg group-hover:bg-blue-200 dark:group-hover:bg-blue-800/50 transition-colors">
                    <span class="material-icons text-3xl group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">trending_down</span>
                </div>
            </div>

            <div class="ai-gradient border border-transparent dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-default group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(34,197,94,0.3)] dark:hover:shadow-[0_0_20px_rgba(34,197,94,0.4)] dark:hover:border-green-400 transition-all duration-300 shadow-lg text-white">
                <div class="text-left">
                    <p class="text-xs font-bold text-green-300 uppercase tracking-wider mb-1 glow-text group-hover:text-green-200 transition-colors">Market Intelligence</p>
                    <p class="text-3xl font-black font-mono text-white group-hover:scale-110 transition-transform origin-left">Live Search</p>
                </div>
                <div class="p-3 bg-green-800/50 text-green-300 rounded-lg group-hover:bg-green-700/50 transition-colors">
                    <span class="material-icons text-3xl group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">psychology</span>
                </div>
            </div>
        </div>

        <div class="flex-1 flex flex-col lg:flex-row gap-6 overflow-hidden">
            
            <div class="flex-1 bg-white dark:bg-slate-900/80 rounded-2xl shadow border border-gray-200 dark:border-slate-800 flex flex-col overflow-hidden transition">
                <div class="p-5 border-b border-gray-100 dark:border-slate-700 flex justify-between items-center bg-gray-50/50 dark:bg-slate-800">
                    <div>
                        <h2 class="text-lg font-bold text-gray-800 dark:text-white flex items-center gap-2">
                            <span class="material-icons text-orange-500 dark:text-orange-400">inventory_2</span> Spoilage Recovery Engine
                        </h2>
                    </div>
                </div>
                
                <div class="px-5 py-3 border-b border-gray-100 dark:border-slate-700 bg-white dark:bg-slate-900/80 flex gap-3 z-10">
                    <div class="relative flex-1">
                        <span class="material-icons absolute left-3 top-2.5 text-gray-400 dark:text-slate-500 text-[18px]">filter_list</span>
                        <input type="text" id="spoilageSearch" placeholder="Filter inventory by name..." 
                               class="w-full pl-9 pr-4 py-2 text-sm border border-gray-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-[#1E3A1D] dark:focus:ring-green-500 outline-none bg-gray-50 dark:bg-slate-800 text-gray-900 dark:text-white focus:bg-white dark:focus:bg-slate-900 transition-colors">
                    </div>
                </div>

                <div class="overflow-y-auto flex-1 custom-scroll">
                    <table class="w-full text-left">
                        <thead class="bg-white dark:bg-slate-800 text-gray-400 dark:text-slate-400 text-[10px] uppercase tracking-wider font-bold sticky top-0 z-10 border-b border-gray-100 dark:border-slate-700 shadow-sm">
                            <tr>
                                <th class="p-4 pl-6">Perishable Item</th>
                                <th class="p-4 text-center">Days Left</th>
                                <th class="p-4 text-right">Base Price</th>
                                <th class="p-4 text-right bg-blue-50/50 dark:bg-blue-900/10">AI Suggested</th>
                                <th class="p-4 pr-6 text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody id="spoilageTableBody" class="divide-y divide-gray-50 dark:divide-slate-800 text-sm">
                            <?php if(empty($expiring_items)): ?>
                                <tr><td colspan="5" class="p-10 text-center text-gray-400 dark:text-slate-500 italic">Inventory is healthy! No items nearing expiration.</td></tr>
                            <?php else: ?>
                                <?php foreach($expiring_items as $item): 
                                    $days = intval($item['days_left']);
                                    $base = floatval($item['price']);
                                    $suggested = calculate_ai_markdown($base, $days);
                                    
                                    // Light and Dark mode urgency classes
                                    $urgency_class = "text-green-600 bg-green-50 border-green-200 dark:text-green-400 dark:bg-green-900/30 dark:border-green-800/50";
                                    $pulse = "";
                                    if ($days <= 3) { $urgency_class = "text-orange-600 bg-orange-50 border-orange-200 dark:text-orange-400 dark:bg-orange-900/30 dark:border-orange-800/50"; }
                                    if ($days <= 1) { $urgency_class = "text-red-600 bg-red-50 border-red-200 dark:text-red-400 dark:bg-red-900/30 dark:border-red-800/50"; $pulse = "pulse-red"; }
                                    
                                    $discount_percent = ($base > 0) ? round((($base - $suggested) / $base) * 100) : 0;
                                ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-slate-800/50 transition group spoilage-row">
                                    <td class="p-4 pl-6 align-middle">
                                        <div class="font-bold text-gray-900 dark:text-white item-name"><?= htmlspecialchars($item['name']) ?></div>
                                        <div class="text-[10px] font-mono text-gray-400 dark:text-slate-500 mt-0.5"><?= htmlspecialchars($item['total_qty']) ?> <?= htmlspecialchars($item['unit']) ?> in stock</div>
                                    </td>
                                    
                                    <td class="p-4 align-middle text-center">
                                        <div class="inline-flex items-center justify-center px-3 py-1 rounded-full text-xs font-bold border <?= $urgency_class ?> <?= $pulse ?>">
                                            <span class="material-icons text-[14px] mr-1">timer</span> <?= $days ?> Days
                                        </div>
                                    </td>
                                    
                                    <td class="p-4 align-middle text-right">
                                        <span class="font-mono text-gray-500 dark:text-slate-400 line-through text-xs">₱<?= number_format($base, 2) ?></span>
                                    </td>
                                    
                                    <td class="p-4 align-middle text-right bg-blue-50/30 dark:bg-blue-900/10">
                                        <?php if ($suggested < $base && $base > 0): ?>
                                            <div class="font-mono font-black text-blue-700 dark:text-blue-400 text-lg">₱<?= number_format($suggested, 2) ?></div>
                                            <div class="text-[10px] font-bold text-blue-500 dark:text-blue-500 uppercase mt-0.5">▼ <?= $discount_percent ?>% Markdown</div>
                                        <?php else: ?>
                                            <div class="font-mono font-bold text-gray-700 dark:text-gray-300">₱<?= number_format($suggested, 2) ?></div>
                                            <div class="text-[10px] text-gray-400 dark:text-slate-500">Stable</div>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td class="p-4 pr-6 align-middle text-center">
                                        <?php if ($suggested < $base && $base > 0): ?>
                                            <button onclick="applyAIPricing(<?= $item['product_id'] ?>, <?= $suggested ?>, '<?= addslashes($item['name']) ?>')" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-lg text-xs font-bold shadow-md transition transform active:scale-95 flex items-center justify-center gap-1 mx-auto w-full max-w-[120px]">
                                                <span class="material-icons text-[14px]">bolt</span> Apply
                                            </button>
                                        <?php else: ?>
                                            <span class="text-xs text-gray-400 dark:text-slate-500 italic">No Action</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="w-full lg:w-1/3 bg-white dark:bg-slate-900/80 rounded-2xl shadow border border-gray-200 dark:border-slate-800 flex flex-col overflow-hidden flex-shrink-0 transition">
                <div class="p-5 border-b border-gray-100 dark:border-slate-700 bg-gray-50/50 dark:bg-slate-800">
                    <h2 class="text-lg font-bold text-gray-800 dark:text-white flex items-center gap-2">
                        <span class="material-icons text-green-600 dark:text-green-400">travel_explore</span> Competitor Tracker
                    </h2>
                    
                    <div class="mt-4 relative">
                        <span class="material-icons absolute left-3 top-2.5 text-gray-400 dark:text-slate-500 text-[18px]">search</span>
                        <input type="text" id="marketSearchInput" placeholder="Search market (e.g. Ampalaya)..." 
                               class="w-full pl-9 pr-10 py-2 text-sm border border-gray-300 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-[#1E3A1D] dark:focus:ring-green-500 outline-none shadow-sm transition bg-white dark:bg-slate-900 text-gray-900 dark:text-white">
                        <button onclick="executeMarketSearch()" class="absolute right-1 top-1 bg-[#1E3A1D] dark:bg-green-600 text-white p-1.5 rounded-md hover:bg-green-800 dark:hover:bg-green-500 transition-colors">
                            <span class="material-icons text-[16px] block">manage_search</span>
                        </button>
                    </div>
                    <p class="text-[10px] text-gray-500 dark:text-slate-400 mt-2 uppercase tracking-wide font-bold">Live Market Comparison Engine</p>
                </div>
                
                <div id="marketResultsContainer" class="overflow-y-auto flex-1 p-5 space-y-4 custom-scroll bg-gray-50 dark:bg-slate-900/50">
                    
                    <div class="text-center text-gray-400 dark:text-slate-500 py-10 flex flex-col items-center justify-center h-full">
                        <span class="material-icons text-5xl mb-3 opacity-20">analytics</span>
                        <p class="text-sm font-medium">Search an item above to pull<br>live market comparisons.</p>
                    </div>

                </div>
            </div>

        </div>
    </main>

    <div id="flashMessage" class="fixed bottom-6 right-6 z-[100] bg-[#1E3A1D] dark:bg-green-700 text-white px-6 py-4 rounded-lg shadow-2xl flex items-center gap-3 transform translate-y-20 transition-all duration-300 opacity-0 pointer-events-none">
        <span class="material-icons text-green-400" id="flashIcon">check_circle</span>
        <div><h4 class="font-bold text-sm">Notification</h4><p class="text-xs text-gray-300" id="flashText"></p></div>
    </div>

    <script>
        // --- 1. FLASH MESSAGE SYSTEM ---
        let flashTimeout;
        const showFlash = (msg, type = 'success') => {
            if(flashTimeout) clearTimeout(flashTimeout);
            document.getElementById('flashText').textContent = msg;
            const fm = document.getElementById('flashMessage');
            const fi = document.getElementById('flashIcon');
            fm.className = `fixed bottom-6 right-6 z-[100] ${type === 'error' ? 'bg-red-700' : 'bg-[#1E3A1D] dark:bg-green-700'} text-white px-6 py-4 rounded-lg shadow-2xl flex items-center gap-3 transform transition-all duration-300`;
            fi.textContent = type === 'error' ? 'error' : 'check_circle';
            fm.classList.remove('translate-y-20', 'opacity-0');
            flashTimeout = setTimeout(() => { fm.classList.add('translate-y-20', 'opacity-0'); }, 3000);
        };

        // --- 2. APPLY AI PRICING (LEFT PANEL) ---
        async function applyAIPricing(productId, newPrice, productName) {
            if(!confirm(`Authorize AI to update the system price of ${productName} to ₱${newPrice.toFixed(2)}?`)) return;
            
            const fd = new FormData();
            fd.append('action_type', 'apply_markdown');
            fd.append('product_id', productId);
            fd.append('new_price', newPrice);

            try {
                const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
                if(res.success) {
                    showFlash(res.message);
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showFlash(res.message, 'error');
                }
            } catch (error) {
                showFlash("System error while applying AI pricing.", "error");
            }
        }

        // --- 3. SPOILAGE TABLE FILTER (LEFT PANEL) ---
        document.getElementById('spoilageSearch')?.addEventListener('keyup', function() {
            const filterText = this.value.toLowerCase();
            const rows = document.querySelectorAll('.spoilage-row');
            
            rows.forEach(row => {
                const itemName = row.querySelector('.item-name')?.textContent.toLowerCase() || "";
                if (itemName.includes(filterText)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });

        // --- 4. LIVE COMPETITOR SEARCH (RIGHT PANEL) ---
        async function executeMarketSearch() {
            const searchInput = document.getElementById('marketSearchInput');
            const term = searchInput.value.trim();
            const container = document.getElementById('marketResultsContainer');

            if (!term) {
                searchInput.focus();
                return;
            }

            // Show Loading State
            container.innerHTML = `
                <div class="text-center text-[#1E3A1D] dark:text-green-400 py-10 flex flex-col items-center justify-center">
                    <span class="material-icons animate-spin text-4xl mb-3">sync</span>
                    <p class="text-sm font-bold animate-pulse">Scanning market index for "${term}"...</p>
                </div>
            `;

            const fd = new FormData();
            fd.append('action_type', 'search_market');
            fd.append('search_term', term);

            try {
                const res = await fetch('', { method: 'POST', body: fd }).then(r => r.json());
                
                if (res.success && res.data) {
                    // Generate HTML for results (Updated with Dark Mode classes)
                    container.innerHTML = res.data.map(item => `
                        <div class="bg-white dark:bg-slate-800 p-4 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm relative overflow-hidden group transform transition hover:-translate-y-1 hover:shadow-md">
                            <div class="flex justify-between items-start mb-3">
                                <div><h3 class="font-bold text-gray-900 dark:text-white text-sm">${item.name}</h3></div>
                                ${item.trend === 'down' ? `<span class="bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-400 border border-red-100 dark:border-red-800/50 px-2 py-0.5 rounded text-[10px] font-bold flex items-center gap-1"><span class="material-icons text-[12px]">arrow_downward</span> DROP</span>` : 
                                 item.trend === 'up' ? `<span class="bg-green-50 dark:bg-green-900/30 text-green-600 dark:text-green-400 border border-green-100 dark:border-green-800/50 px-2 py-0.5 rounded text-[10px] font-bold flex items-center gap-1"><span class="material-icons text-[12px]">arrow_upward</span> RISE</span>` : 
                                 `<span class="bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-300 border border-gray-200 dark:border-slate-600 px-2 py-0.5 rounded text-[10px] font-bold">STABLE</span>`}
                            </div>

                            <div class="grid grid-cols-2 gap-2 mb-3">
                                <div class="bg-gray-50 dark:bg-slate-700/50 p-2 rounded-lg border border-gray-100 dark:border-slate-600">
                                    <p class="text-[9px] text-gray-400 dark:text-slate-400 uppercase font-bold tracking-wider">Our Price</p>
                                    <p class="font-mono font-bold text-gray-800 dark:text-slate-200 text-sm">₱${item.our_price.toFixed(2)} <span class="text-[9px] text-gray-400 dark:text-slate-500 font-sans">/${item.unit}</span></p>
                                </div>
                                <div class="bg-blue-50/50 dark:bg-blue-900/30 p-2 rounded-lg border border-blue-100 dark:border-blue-800/50">
                                    <p class="text-[9px] text-blue-500 dark:text-blue-400 uppercase font-bold tracking-wider flex items-center gap-1"><span class="material-icons text-[10px]">public</span> Web Avg</p>
                                    <p class="font-mono font-bold text-blue-800 dark:text-blue-300 text-sm">₱${item.market_avg.toFixed(2)} <span class="text-[9px] text-blue-400 dark:text-blue-500 font-sans">/${item.unit}</span></p>
                                </div>
                            </div>

                            <div class="text-[11px] text-[#1E3A1D] dark:text-green-300 bg-green-50/50 dark:bg-green-900/30 p-2 rounded flex items-start gap-1.5 border border-green-100 dark:border-green-800/50">
                                <span class="material-icons text-[14px] mt-0.5 text-green-600 dark:text-green-400">lightbulb</span>
                                <span class="leading-tight font-medium">${item.suggestion}</span>
                            </div>
                        </div>
                    `).join('');
                } else {
                    // No results found
                    container.innerHTML = `
                        <div class="text-center text-gray-500 dark:text-slate-400 py-10 flex flex-col items-center justify-center">
                            <span class="material-icons text-4xl mb-3 text-red-300 dark:text-red-900">search_off</span>
                            <p class="text-sm font-medium">No internal products found matching "${term}".<br>Cannot perform market comparison.</p>
                        </div>
                    `;
                }
            } catch (error) {
                container.innerHTML = `
                    <div class="text-center text-red-500 dark:text-red-400 py-10">
                        <span class="material-icons text-4xl mb-2">error</span>
                        <p class="text-sm">Connection error while fetching market data.</p>
                    </div>
                `;
            }
        }

        // Allow pressing Enter to search Market
        document.getElementById('marketSearchInput')?.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') executeMarketSearch();
        });
    </script>
</body>
</html>