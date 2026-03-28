<?php
// C:\xampp\htdocs\RalphPHP\admin_components\pages\order_create.php

// 1. START SESSION & BUFFERING
session_start();
ob_start(); 

// 2. SECURITY CHECK
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: ../admin_login.php");
    exit;
}

// 3. STRICT CACHE HEADERS
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

include_once '../includes/db_connection.php';

// --- SAFE DATABASE AUTO-PATCHER FOR EXACT TIME ---
try {
    $chk = $conn->query("SHOW COLUMNS FROM sales LIKE 'created_at'");
    if ($chk && $chk->num_rows == 0) {
        @$conn->query("ALTER TABLE sales ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
    } else {
        @$conn->query("ALTER TABLE sales MODIFY COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
    }
} catch (Throwable $e) { }

// --- BASE QUERY FOR PRODUCTS WITH EXPIRY & STATUS LOGIC ---
$sql_base = "SELECT p.*, SUM(COALESCE(pi.quantity, 0)) as current_stock,
             DATEDIFF(p.expiration_date, CURDATE()) as days_to_expire,
             CASE 
                WHEN p.status = 'Inactive' THEN 'Inactive'
                WHEN p.expiration_date IS NOT NULL AND p.expiration_date != '0000-00-00' AND p.expiration_date < CURDATE() THEN 'Expired'
                WHEN SUM(COALESCE(pi.quantity, 0)) <= 0.001 THEN 'Out of Stock'
                WHEN p.expiration_date IS NOT NULL AND p.expiration_date != '0000-00-00' AND p.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'Expiring Soon'
                ELSE 'Active'
             END as computed_status
             FROM products p 
             LEFT JOIN product_inventory pi ON p.product_id = pi.product_id";

// --- AJAX HANDLER: LIVE SEARCH ---
if (isset($_GET['ajax_search'])) {
    $q = "%" . trim($_GET['query']) . "%";
    
    // Prepare Query
    if (!empty($_GET['query'])) {
        $stmt = $conn->prepare("$sql_base WHERE p.name LIKE ? OR p.product_brand LIKE ? GROUP BY p.product_id LIMIT 20");
        $stmt->bind_param("ss", $q, $q);
    } else {
        $stmt = $conn->prepare("$sql_base GROUP BY p.product_id ORDER BY p.name ASC LIMIT 50");
    }
    
    $stmt->execute();
    $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Generate HTML for Grid
    if (empty($results)) {
        echo '<div class="col-span-full text-center py-20 bg-white dark:bg-slate-900/60 rounded-xl border border-gray-200 dark:border-slate-800 border-dashed">
                <span class="material-icons text-6xl text-gray-200 dark:text-slate-700 mb-4">inventory_2</span>
                <p class="text-gray-400 dark:text-slate-500 italic">No products found.</p>
              </div>';
    } else {
        foreach ($results as $p) {
            $stock = floatval($p['current_stock']);
            $unit = htmlspecialchars($p['unit'] ?? 'pcs');
            
            // Decimal Logic Fix
            $is_bulk = in_array($unit, ['kg', 'g']);
            $displayStock = $is_bulk ? number_format($stock, 2) : number_format($stock, 0);
            
            $status = $p['computed_status'];
            $isAvailable = !in_array($status, ['Inactive', 'Expired', 'Out of Stock']);
            
            // Generate Stock/Status Badge (Increased to text-xs, added dark mode)
            $badgeHTML = "";
            if ($status === 'Inactive') {
                $badgeHTML = "<span class='text-xs font-bold uppercase px-2.5 py-1 rounded border bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400 border-gray-200 dark:border-slate-700'>Inactive</span>";
            } elseif ($status === 'Expired') {
                $badgeHTML = "<span class='text-xs font-bold uppercase px-2.5 py-1 rounded border bg-purple-50 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400 border-purple-200 dark:border-purple-800/50'>Expired</span>";
            } elseif ($status === 'Out of Stock') {
                $badgeHTML = "<span class='text-xs font-bold uppercase px-2.5 py-1 rounded border bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-400 border-red-200 dark:border-red-800/50'>Out of Stock</span>";
            } else {
                $badgeHTML = "<span class='text-xs font-bold uppercase px-2.5 py-1 rounded border bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-400 border-green-200 dark:border-green-800/50'>{$displayStock} {$unit}</span>";
            }

            // Generate Expiry Warning if applicable (Increased text size and icon size)
            $expWarning = '';
            if ($status === 'Expiring Soon' && isset($p['days_to_expire'])) {
                $days = intval($p['days_to_expire']);
                $dayText = $days == 1 ? '1 day' : "$days days";
                $expWarning = "<div class='text-sm text-orange-600 dark:text-orange-400 font-bold mt-1.5 flex justify-center items-center gap-1.5'><span class='material-icons text-base'>warning</span> Expiring in $dayText</div>";
            }

            // EXACT PATH FIX FOR IMAGES
            $raw_img = !empty($p['image_url']) ? trim($p['image_url']) : '';
            $img_src = '';
            if (!empty($raw_img)) {
                if (preg_match('/^(http|\/)/', $raw_img)) {
                    $img_src = $raw_img;
                } else {
                    $clean_path = preg_replace('/^(\.\.\/)+/', '', $raw_img);
                    $img_src = '../../' . $clean_path;
                }
            }

            // Updated Glowing Card State
            $cardState = $isAvailable ? 'cursor-pointer hover:border-[#1E3A1D] dark:hover:border-green-400 hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(34,197,94,0.2)] dark:hover:shadow-[0_0_20px_rgba(34,197,94,0.3)]' : 'opacity-60 grayscale cursor-not-allowed border-gray-200 dark:border-slate-800';
            $jsonData = htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8');
            $action = $isAvailable ? "addToCart($jsonData)" : "";

            echo "
            <div onclick='$action' class='product-card p-5 group $cardState relative bg-white dark:bg-slate-900/60 border border-gray-200 dark:border-slate-800 rounded-xl shadow-sm transition-all duration-300'>
                
                <div class='flex justify-between items-start mb-3'>
                    $badgeHTML
                    ";
            
            if ($isAvailable) {
                echo "<button type='button' class='w-8 h-8 rounded-full bg-gray-100 dark:bg-slate-800 flex items-center justify-center text-gray-400 dark:text-slate-400 group-hover:bg-[#1E3A1D] dark:group-hover:bg-green-600 group-hover:text-white transition'><span class='material-icons text-sm'>add</span></button>";
            }
            
            echo "
                </div>
                
                <div class='w-1/2 mx-auto aspect-square mb-4 rounded-lg overflow-hidden bg-gray-50 dark:bg-slate-800 flex items-center justify-center border border-gray-100 dark:border-slate-700 relative' style='aspect-ratio: 1 / 1;'>
                    <span class='material-icons text-5xl text-gray-200 dark:text-slate-600 absolute'>inventory_2</span>";
                    
                    if (!empty($img_src)) {
                        $safe_img = htmlspecialchars($img_src, ENT_QUOTES);
                        echo "<img src='$safe_img' alt='Product Image' class='object-cover w-full h-full absolute inset-0 z-10 bg-white' onerror=\"this.style.display='none';\" />";
                    }

            echo "
                </div>
                
                <div class='text-center'>
                    <h3 class='font-bold text-gray-800 dark:text-white text-xl leading-snug mb-1 truncate' title='" . htmlspecialchars($p['name']) . "'>" . htmlspecialchars($p['name']) . "</h3>
                    <span class='text-sm text-gray-500 dark:text-slate-400 font-medium uppercase tracking-wide'>" . htmlspecialchars($p['product_brand'] ?? 'Generic') . "</span>
                    $expWarning
                </div>
                
                <div class='mt-4 pt-3 border-t border-gray-100 dark:border-slate-800 flex justify-between items-end'>
                    <span class='text-[#1E3A1D] dark:text-green-400 font-mono font-bold text-2xl'>₱" . number_format($p['price'], 2) . "</span>
                    <span class='text-sm text-gray-400 dark:text-slate-500'>per $unit</span>
                </div>
                
            </div>";
        }
    }
    exit; 
}

// --- SUBMIT ORDER LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_order') {
    $client_id = intval($_POST['client_id']);
    $delivery_date = $_POST['delivery_date'];
    $items = json_decode($_POST['cart_items'], true);
    $total = floatval($_POST['total_amount']);
    $created_by = $_SESSION['user_id'] ?? 1; 

    // Server-side Date Validation
    if ($delivery_date < date('Y-m-d')) {
        echo "<script>alert('Error: Delivery date cannot be in the past.'); window.history.back();</script>";
        exit;
    }

    if ($client_id > 0 && !empty($items)) {
        // Stock Validation & Processing
        $conn->begin_transaction();
        try {
            // GRAB EXACT TIME FOR ACCURATE CREATION LOG
            $now = date('Y-m-d H:i:s'); 
            
            // Auto-defaults to Pending
            $stmt = $conn->prepare("INSERT INTO sales (created_by, client_id, total_amount, order_status, payment_status, delivery_date, created_at) VALUES (?, ?, ?, 'Pending', 'Pending', ?, ?)");
            $stmt->bind_param("iidss", $created_by, $client_id, $total, $delivery_date, $now);
            $stmt->execute();
            $sale_id = $conn->insert_id;

            $stmt_item = $conn->prepare("INSERT INTO sales_items (sale_id, product_id, quantity, price, subtotal) VALUES (?, ?, ?, ?, ?)");
            $stmt_inv = $conn->prepare("UPDATE product_inventory SET quantity = quantity - ? WHERE product_id = ? LIMIT 1");

            foreach ($items as $item) {
                // Double check stock before committing
                $check = $conn->query("SELECT SUM(quantity) as q FROM product_inventory WHERE product_id = {$item['id']}")->fetch_assoc();
                if ($check['q'] < $item['qty']) throw new Exception("Stock changed for {$item['name']}. Please retry.");

                $subtotal = $item['price'] * $item['qty'];
                $stmt_item->bind_param("iiddd", $sale_id, $item['id'], $item['qty'], $item['price'], $subtotal);
                $stmt_item->execute();

                $stmt_inv->bind_param("di", $item['qty'], $item['id']);
                $stmt_inv->execute();
            }

            // Log Action
            $auditHelperPath = '../includes/audit_helper.php';
            if (file_exists($auditHelperPath)) {
                include_once $auditHelperPath;
                if (function_exists('log_audit_action')) {
                    log_audit_action('Create Order', 'Orders', "Created Order #$sale_id for Client ID $client_id.");
                }
            }

            $conn->commit();
            header("Location: order_queue.php?success=1");
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            die("Error: " . $e->getMessage()); 
        }
    } else {
        echo "<script>alert('Please select a client and add items.'); window.history.back();</script>";
        exit;
    }
}

// Initial Load Products
$initial_products = $conn->query("$sql_base GROUP BY p.product_id ORDER BY p.name ASC LIMIT 50")->fetch_all(MYSQLI_ASSOC);
$clients = $conn->query("SELECT * FROM clients WHERE status='Active' ORDER BY client_name ASC")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreshFlow - Create Order</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Roboto+Mono:wght@400;700&display=swap" rel="stylesheet">
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
        :root { 
            --brand-green: #1E3A1D; 
            --brand-cream: #F8F5EE; 
        }
        
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--brand-cream); 
            color: #2B2B2B; 
            transition: background-color 0.3s ease;
        }
        
        /* --- DARK MODE GLOBAL STYLES --- */
        .dark body {
            background-color: #000000;
            background-image: radial-gradient(circle, rgba(255, 255, 255, 0.07) 1px, transparent 1px);
            background-size: 16px 16px;
            color: #f8fafc;
        }

        .font-mono { 
            font-family: 'Roboto Mono', monospace; 
        }
        
        .custom-scroll::-webkit-scrollbar { 
            width: 6px; 
            height: 6px; 
        }
        
        .custom-scroll::-webkit-scrollbar-thumb { 
            background: #cbd5e1; 
            border-radius: 3px; 
        }
        
        .dark .custom-scroll::-webkit-scrollbar-thumb { 
            background: #334155; 
        }
        
        .form-input { 
            background-color: #ffffff; 
            border: 1px solid #d1d5db; 
            color: #374151; 
            border-radius: 0.5rem; 
            transition: all 0.2s; 
        }
        
        .form-input:focus { 
            outline: none; 
            border-color: var(--brand-green); 
            box-shadow: 0 0 0 3px rgba(30, 58, 29, 0.1); 
        }
        
        .dark .form-input {
            background-color: rgba(30, 41, 59, 0.6);
            border-color: #334155;
            color: #f8fafc;
        }
        .dark .form-input:focus {
            border-color: #4ade80;
            box-shadow: 0 0 0 3px rgba(74, 222, 128, 0.15);
        }

        .product-card { 
            transition: all 0.3s ease; 
        }
        
        .cart-panel { 
            background-color: white; 
            border-radius: 1rem; 
            box-shadow: -10px 0 25px -5px rgba(0,0,0,0.05); 
            border-left: 1px solid #e5e7eb; 
            transition: all 0.3s ease;
        }

        .dark .cart-panel {
            background-color: rgba(15, 23, 42, 0.8);
            border-color: #1e293b;
            box-shadow: -10px 0 25px -5px rgba(0,0,0,0.5); 
        }
        
        /* Hides arrows on number inputs in cart */
        .hide-arrows::-webkit-inner-spin-button, 
        .hide-arrows::-webkit-outer-spin-button { 
            -webkit-appearance: none; 
            margin: 0; 
        }
        
        .hide-arrows { 
            -moz-appearance: textfield; 
        }
    </style>
    
    <script>
        window.onpageshow = function(event) { 
            if (event.persisted) window.location.reload(); 
        };
        window.onbeforeunload = function() { 
            document.body.innerHTML = ""; 
            if (document.documentElement.classList.contains('dark')) {
                document.body.style.backgroundColor = "#000000"; 
            } else {
                document.body.style.backgroundColor = "#F8F5EE"; 
            }
        };
    </script>
</head>
<body class="h-screen flex overflow-hidden bg-[var(--brand-cream)] dark:bg-gray-900">
    
    <?php include '../includes/sidebar.php'; ?>

    <div class="ml-20 flex-1 flex flex-col h-screen overflow-hidden relative transition-all duration-300">
        
        <header class="px-8 py-6 flex-shrink-0 flex items-end justify-between">
            <div>
                <h1 class="text-3xl font-bold text-[#1E3A1D] dark:text-white tracking-tight flex items-center gap-3">
                    <span class="material-icons text-3xl dark:text-green-400">add_shopping_cart</span>
                    Create Order
                </h1>
                <p class="text-sm text-gray-500 dark:text-slate-400 mt-1 ml-11">Select products and build client order</p>
            </div>
            <div class="text-right">
                <p class="text-xs font-bold text-gray-400 dark:text-slate-500 uppercase tracking-widest">Date</p>
                <p class="text-lg font-bold text-gray-700 dark:text-white font-mono"><?= date('M d, Y') ?></p>
            </div>
        </header>

        <div class="flex-1 flex overflow-hidden px-8 pb-8 gap-6">
            
            <div class="flex-1 flex flex-col min-w-0">
                
                <div class="mb-6 relative flex-shrink-0">
                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400 dark:text-slate-500">
                        <span class="material-icons">search</span>
                    </span>
                    <input type="text" id="searchInput" class="w-full pl-10 p-3 form-input dark:placeholder-slate-500" placeholder="Search products by name or brand..." autocomplete="off">
                </div>

                <div class="overflow-y-auto custom-scroll pr-2 pb-4">
                    <div id="productGrid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        
                        <?php foreach ($initial_products as $p): 
                            $stock = floatval($p['current_stock']);
                            $unit = htmlspecialchars($p['unit'] ?? 'pcs');
                            
                            $is_bulk = in_array($unit, ['kg', 'g']);
                            $displayStock = $is_bulk ? number_format($stock, 2) : number_format($stock, 0);
                            
                            $status = $p['computed_status'];
                            $isAvailable = !in_array($status, ['Inactive', 'Expired', 'Out of Stock']);
                            
                            // Badges increased to text-xs, added dark mode
                            $badgeHTML = "";
                            if ($status === 'Inactive') {
                                $badgeHTML = "<span class='text-xs font-bold uppercase px-2.5 py-1 rounded border bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400 border-gray-200 dark:border-slate-700'>Inactive</span>";
                            } elseif ($status === 'Expired') {
                                $badgeHTML = "<span class='text-xs font-bold uppercase px-2.5 py-1 rounded border bg-purple-50 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400 border-purple-200 dark:border-purple-800/50'>Expired</span>";
                            } elseif ($status === 'Out of Stock') {
                                $badgeHTML = "<span class='text-xs font-bold uppercase px-2.5 py-1 rounded border bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-400 border-red-200 dark:border-red-800/50'>Out of Stock</span>";
                            } else {
                                $badgeHTML = "<span class='text-xs font-bold uppercase px-2.5 py-1 rounded border bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-400 border-green-200 dark:border-green-800/50'>{$displayStock} {$unit}</span>";
                            }

                            // Expiry Warning increased to text-sm
                            $expWarning = '';
                            if ($status === 'Expiring Soon' && isset($p['days_to_expire'])) {
                                $days = intval($p['days_to_expire']);
                                $dayText = $days == 1 ? '1 day' : "$days days";
                                $expWarning = "<div class='text-sm text-orange-600 dark:text-orange-400 font-bold mt-1.5 flex justify-center items-center gap-1.5'><span class='material-icons text-base'>warning</span> Expiring in $dayText</div>";
                            }
                            
                            // EXACT PATH FIX FOR IMAGES ON PAGE LOAD
                            $raw_img = !empty($p['image_url']) ? trim($p['image_url']) : '';
                            $img_src = '';
                            if (!empty($raw_img)) {
                                if (preg_match('/^(http|\/)/', $raw_img)) {
                                    $img_src = $raw_img;
                                } else {
                                    $clean_path = preg_replace('/^(\.\.\/)+/', '', $raw_img);
                                    $img_src = '../../' . $clean_path;
                                }
                            }

                            $cardState = $isAvailable ? 'cursor-pointer hover:border-[#1E3A1D] dark:hover:border-green-400 hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(34,197,94,0.2)] dark:hover:shadow-[0_0_20px_rgba(34,197,94,0.3)]' : 'opacity-60 grayscale cursor-not-allowed border-gray-200 dark:border-slate-800';
                            $jsonData = htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8');
                            $action = $isAvailable ? "addToCart($jsonData)" : "";
                        ?>
                        
                        <div onclick="<?= $action ?>" class="product-card p-5 group <?= $cardState ?> relative bg-white dark:bg-slate-900/60 border border-gray-200 dark:border-slate-800 rounded-xl shadow-sm transition-all duration-300">
                            
                            <div class="flex justify-between items-start mb-3">
                                <?= $badgeHTML ?>
                                
                                <?php if($isAvailable): ?>
                                    <button type="button" class="w-8 h-8 rounded-full bg-gray-100 dark:bg-slate-800 flex items-center justify-center text-gray-400 dark:text-slate-400 group-hover:bg-[#1E3A1D] dark:group-hover:bg-green-600 group-hover:text-white transition">
                                        <span class="material-icons text-sm">add</span>
                                    </button>
                                <?php endif; ?>
                            </div>

                            <div class="w-1/2 mx-auto aspect-square mb-4 rounded-lg overflow-hidden bg-gray-50 dark:bg-slate-800 flex items-center justify-center border border-gray-100 dark:border-slate-700 relative" style="aspect-ratio: 1 / 1;">
                                <span class="material-icons text-5xl text-gray-200 dark:text-slate-600 absolute">inventory_2</span>
                                
                                <?php if (!empty($img_src)): ?>
                                    <img src="<?= htmlspecialchars($img_src, ENT_QUOTES) ?>" alt="Product Image" class="object-cover w-full h-full absolute inset-0 z-10 bg-white" onerror="this.style.display='none';" />
                                <?php endif; ?>
                            </div>

                            <div class="text-center">
                                <h3 class="font-bold text-gray-800 dark:text-white text-xl leading-snug mb-1 truncate" title="<?= htmlspecialchars($p['name']) ?>">
                                    <?= htmlspecialchars($p['name']) ?>
                                </h3>
                                <span class="text-sm text-gray-500 dark:text-slate-400 font-medium uppercase tracking-wide">
                                    <?= htmlspecialchars($p['product_brand'] ?? 'Generic') ?>
                                </span>
                                <?= $expWarning ?>
                            </div>

                            <div class="mt-4 pt-3 border-t border-gray-100 dark:border-slate-800 flex justify-between items-end">
                                <span class="text-[#1E3A1D] dark:text-green-400 font-mono font-bold text-2xl">
                                    ₱<?= number_format($p['price'], 2) ?>
                                </span>
                                <span class="text-sm text-gray-400 dark:text-slate-500">
                                    per <?= $unit ?>
                                </span>
                            </div>
                        </div>
                        
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="w-1/3 cart-panel flex flex-col z-10 flex-shrink-0 h-full">
                <form method="POST" id="orderForm" class="flex flex-col h-full">
                    
                    <input type="hidden" name="action" value="submit_order">
                    <input type="hidden" name="cart_items" id="cart_items_input">
                    <input type="hidden" name="total_amount" id="total_amount_input">

                    <div class="p-6 border-b border-gray-100 dark:border-slate-800 overflow-y-auto custom-scroll max-h-[40vh] bg-white dark:bg-slate-900/80 rounded-t-2xl">
                        <h2 class="text-lg font-bold text-[#1E3A1D] dark:text-white mb-4 flex items-center gap-2">
                            <span class="material-icons text-sm dark:text-green-400">receipt_long</span> Order Details
                        </h2>
                        
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Select Client</label>
                                <select name="client_id" required class="w-full p-2.5 form-input text-sm">
                                    <option value="">-- Choose Client --</option>
                                    <?php foreach ($clients as $c): ?>
                                        <option value="<?= $c['client_id'] ?>"><?= htmlspecialchars($c['client_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Delivery Date</label>
                                <input type="date" name="delivery_date" required class="w-full p-2.5 form-input text-sm" 
                                       value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="flex-1 overflow-y-auto p-4 space-y-2 custom-scroll bg-gray-50 dark:bg-slate-900/40" id="cart_container">
                        <div id="empty_cart_msg" class="flex flex-col items-center justify-center h-full text-gray-400 dark:text-slate-500">
                            <span class="material-icons text-4xl mb-2 text-gray-300 dark:text-slate-600">shopping_basket</span>
                            <span class="text-sm italic">Cart is empty</span>
                        </div>
                    </div>

                    <div class="p-6 bg-white dark:bg-slate-800/80 border-t border-gray-200 dark:border-slate-800 rounded-b-2xl">
                        <div class="flex justify-between items-center mb-4">
                            <span class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-widest">Total Amount</span>
                            <span class="text-3xl font-bold text-[#1E3A1D] dark:text-green-400 font-mono" id="display_total">₱0.00</span>
                        </div>
                        <button type="submit" class="w-full bg-[#1E3A1D] dark:bg-green-700 hover:bg-[#162e15] dark:hover:bg-green-600 text-white font-bold py-3.5 rounded-lg shadow-lg flex justify-center items-center gap-2 transition transform active:scale-95">
                            <span class="material-icons text-sm">check_circle</span> Confirm Order
                        </button>
                    </div>
                    
                </form>
            </div>
        </div>
    </div>

    <script>
        let cart = [];
        let searchTimeout;

        // --- LIVE SEARCH SCRIPT ---
        document.getElementById('searchInput').addEventListener('input', function(e) {
            clearTimeout(searchTimeout);
            const query = e.target.value;
            
            searchTimeout = setTimeout(() => {
                fetch(`order_create.php?ajax_search=1&query=${encodeURIComponent(query)}`)
                    .then(response => response.text())
                    .then(html => {
                        document.getElementById('productGrid').innerHTML = html;
                    })
                    .catch(err => console.error('Search error:', err));
            }, 300); // 300ms debounce
        });

        // --- CART LOGIC ---
        function addToCart(product) {
            const maxStock = parseFloat(product.current_stock);
            const unitType = product.unit ? product.unit : 'pcs';
            
            // Bottle and Liter excluded from bulk
            const isBulk = ['kg', 'g'].includes(unitType);
            
            if (maxStock <= 0) {
                alert("This item is out of stock!");
                return;
            }

            const existing = cart.find(item => item.id == product.product_id);
            
            if (existing) { 
                if (existing.qty + 1 > maxStock) {
                    if (existing.qty < maxStock) {
                        existing.qty = maxStock; 
                    } else {
                        alert(`Cannot add more. Max stock is ${maxStock} ${unitType}.`);
                        return;
                    }
                } else {
                    existing.qty++; 
                }
            } else { 
                let initialQty = 1;
                if (maxStock < 1) {
                    initialQty = maxStock;
                }
                
                cart.push({ 
                    id: product.product_id, 
                    name: product.name, 
                    price: parseFloat(product.price), 
                    unit: unitType,
                    is_bulk: isBulk,
                    qty: initialQty,
                    max_stock: maxStock
                }); 
            }
            renderCart();
        }

        function updateQty(id, change) {
            const item = cart.find(i => i.id == id);
            if (item) {
                let newQty = item.qty + change;
                newQty = Math.round(newQty * 100) / 100; 
                
                if (newQty > item.max_stock) {
                    if (item.qty < item.max_stock) {
                        newQty = item.max_stock;
                    } else {
                        alert(`Cannot exceed available stock (${item.max_stock} ${item.unit}).`);
                        return;
                    }
                }
                item.qty = newQty;
                if (item.qty <= 0) cart = cart.filter(i => i.id != id);
            }
            renderCart();
        }

        function updateQtyDirect(id, value) {
            const item = cart.find(i => i.id == id);
            if (item) {
                let newQty = parseFloat(value);
                
                if (isNaN(newQty) || newQty <= 0) {
                    cart = cart.filter(i => i.id != id);
                } else {
                    if (!item.is_bulk) {
                        newQty = Math.floor(newQty); 
                    } else {
                        newQty = Math.round(newQty * 100) / 100; 
                    }
                    
                    if (newQty > item.max_stock) {
                        alert(`Cannot exceed available stock (${item.max_stock} ${item.unit}).`);
                        newQty = item.max_stock;
                    }
                    item.qty = newQty;
                }
            }
            renderCart();
        }

        function renderCart() {
            const container = document.getElementById('cart_container');
            const totalDisplay = document.getElementById('display_total');
            container.innerHTML = '';
            let total = 0;

            if (cart.length === 0) { 
                container.innerHTML = `
                    <div id="empty_cart_msg" class="flex flex-col items-center justify-center h-full text-gray-400 dark:text-slate-500">
                        <span class="material-icons text-4xl mb-2 text-gray-300 dark:text-slate-600">shopping_basket</span>
                        <span class="text-sm italic">Cart is empty</span>
                    </div>`; 
            } else {
                cart.forEach(item => {
                    total += item.price * item.qty;
                    
                    const stepVal = item.is_bulk ? "0.01" : "1";
                    const displayQty = item.is_bulk ? item.qty.toFixed(2) : Math.floor(item.qty);

                    container.innerHTML += `
                        <div class="bg-white dark:bg-slate-800/60 p-3 rounded-lg border border-gray-200 dark:border-slate-700 shadow-sm flex justify-between items-center group hover:border-[#1E3A1D] dark:hover:border-green-400 transition">
                            <div class="flex-1 min-w-0 pr-3">
                                <div class="font-bold text-sm text-gray-800 dark:text-white truncate" title="${item.name}">
                                    ${item.name}
                                </div>
                                <div class="text-xs text-gray-500 dark:text-slate-400 font-mono mt-0.5">
                                    ₱${item.price.toFixed(2)} / ${item.unit}
                                </div>
                            </div>
                            
                            <div class="flex items-center gap-1 bg-gray-50 dark:bg-slate-900/50 rounded-lg p-1 border border-gray-100 dark:border-slate-700 flex-shrink-0">
                                <button onclick="updateQty(${item.id}, -1)" type="button" class="w-6 h-6 flex items-center justify-center bg-white dark:bg-slate-700 rounded text-gray-400 dark:text-slate-400 hover:text-red-500 dark:hover:text-red-400 shadow-sm transition focus:outline-none">
                                    <span class="material-icons text-[10px]">remove</span>
                                </button>
                                
                                <input type="number" step="${stepVal}" value="${displayQty}" 
                                       onchange="updateQtyDirect(${item.id}, this.value)" 
                                       class="font-bold text-sm w-12 text-center text-[#1E3A1D] dark:text-green-400 bg-transparent border-none focus:ring-1 focus:ring-green-600 rounded p-0 m-0 hide-arrows">
                                       
                                <button onclick="updateQty(${item.id}, 1)" type="button" class="w-6 h-6 flex items-center justify-center bg-white dark:bg-slate-700 rounded text-gray-400 dark:text-slate-400 hover:text-green-600 dark:hover:text-green-400 shadow-sm transition focus:outline-none">
                                    <span class="material-icons text-[10px]">add</span>
                                </button>
                            </div>
                        </div>`;
                });
            }
            
            totalDisplay.textContent = '₱' + total.toLocaleString('en-US', {minimumFractionDigits: 2});
            document.getElementById('total_amount_input').value = total;
            document.getElementById('cart_items_input').value = JSON.stringify(cart);
        }
    </script>
</body>
</html>