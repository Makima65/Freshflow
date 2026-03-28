<?php
// C:\xampp\htdocs\RalphPHP\admin_components\pages\sales_create.php

ob_start();
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

include_once '../includes/db_connection.php';

// --- CONFIGURATION ---
if (!defined('LOW_STOCK_THRESHOLD')) define('LOW_STOCK_THRESHOLD', 10);
$auditHelperPath = '../includes/audit_helper.php';
if (file_exists($auditHelperPath)) include_once $auditHelperPath;
else if (!function_exists('log_audit_action')) { function log_audit_action($a, $b, $c, $d = []) { return true; } }

// --- SECURITY ---
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: ../admin_login.php");
    exit;
}

// --- PRE-LOAD PRODUCTS ---
$products = [];
$p_query = "SELECT p.product_id, p.name, p.price, pi.quantity 
            FROM products p 
            JOIN product_inventory pi ON p.product_id = pi.product_id 
            WHERE p.status = 'Active' AND pi.quantity > 0 
            ORDER BY p.name ASC";
$p_result = $conn->query($p_query);
if ($p_result) {
    while($row = $p_result->fetch_assoc()) {
        $products[] = $row;
    }
}

// --- HANDLE FORM SUBMISSION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_sale') {
    ob_clean(); 
    header('Content-Type: application/json');
    
    $customer_name = trim($_POST['customer_name'] ?? '');
    $payment_method = $_POST['payment_method'] ?? 'Cash';
    $payment_status = $_POST['payment_status'] ?? 'Pending';
    $items = json_decode($_POST['items'] ?? '[]', true); 
    $admin_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? 0;

    if (empty($customer_name) || empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Customer name and items are required.']);
        exit;
    }

    $conn->begin_transaction();
    try {
        $total_amount = 0;
        foreach ($items as $item) {
            $pid = intval($item['id']);
            $qty = floatval($item['qty']); 
            
            // Check stock
            $check = $conn->query("SELECT quantity FROM product_inventory WHERE product_id = $pid FOR UPDATE");
            if ($check && $check->num_rows > 0) {
                $stock = $check->fetch_assoc()['quantity'];
                if ($stock < $qty) {
                    throw new Exception("Not enough stock for Product ID: $pid. Available: $stock");
                }
            }
            $total_amount += ($item['price'] * $qty);
        }

        // Create Sale Record
        $stmt = $conn->prepare("INSERT INTO sales (customer_name, total_amount, payment_status, payment_method, created_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sdssi", $customer_name, $total_amount, $payment_status, $payment_method, $admin_id);
        $stmt->execute();
        $sale_id = $conn->insert_id;
        $stmt->close();

        // Process Items
        $item_stmt = $conn->prepare("INSERT INTO sales_items (sale_id, product_id, price_at_sale, quantity, subtotal) VALUES (?, ?, ?, ?, ?)");
        $update_stock = $conn->prepare("UPDATE product_inventory SET quantity = quantity - ? WHERE product_id = ?");
        
        foreach ($items as $item) {
            $pid = intval($item['id']);
            $price = floatval($item['price']);
            $qty = floatval($item['qty']);
            $subtotal = $price * $qty;

            $item_stmt->bind_param("iiddd", $sale_id, $pid, $price, $qty, $subtotal);
            $item_stmt->execute();

            $update_stock->bind_param("di", $qty, $pid);
            $update_stock->execute();
        }
        $item_stmt->close();
        $update_stock->close();

        // Update Status
        $conn->query("UPDATE products p 
                      JOIN product_inventory pi ON p.product_id = pi.product_id 
                      SET p.status = 'Out of stock' 
                      WHERE pi.quantity <= 0");

        if(function_exists('log_audit_action')) {
            log_audit_action('Create Sale', 'Sales', "Processed Sale #$sale_id for $customer_name. Total: " . number_format($total_amount, 2));
        }

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Sale recorded successfully!', 'sale_id' => $sale_id]);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Transaction failed: ' . $e->getMessage()]);
    }
    exit;
}

ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreshFlow - New Sale</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #F8F5EE; color: #2B2B2B; }
        .font-heading { font-family: 'Roboto Mono', monospace; }
        .content-card { background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 0.75rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .form-input { background-color: #fff; border: 1px solid #d1d5db; border-radius: 0.5rem; }
        .form-input:focus { outline: none; border-color: #1E3A1D; ring: 2px solid #1E3A1D; }
        
        /* Stepper CSS */
        .stepper { display: flex; align-items: center; border: 1px solid #d1d5db; border-radius: 0.5rem; overflow: hidden; max-width: 140px; }
        .stepper button { background: #f3f4f6; color: #374151; padding: 0.5rem; width: 2.5rem; font-weight: bold; transition: background 0.2s; }
        .stepper button:hover { background: #e5e7eb; }
        .stepper input { border: none; text-align: center; width: 100%; outline: none; -moz-appearance: textfield; }
        .stepper input::-webkit-outer-spin-button, .stepper input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    </style>
</head>
<body>
    
    <?php include '../includes/sidebar.php'; ?>

    <div class="ml-20 transition-all duration-300">
        <main class="p-6 md:p-8 max-w-6xl mx-auto">
            
            <header class="flex justify-between items-center mb-6">
                <div>
                    <h1 class="font-heading text-3xl font-bold text-[#1E3A1D]">New Transaction</h1>
                    <p class="text-sm text-gray-500">Record sales and deduct inventory</p>
                </div>
                <a href="sales.php" class="text-gray-500 hover:text-[#1E3A1D] flex items-center gap-2">
                    <span class="material-icons">arrow_back</span> Back to History
                </a>
            </header>

            <form id="salesForm" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                
                <div class="lg:col-span-2 space-y-6">
                    <div class="content-card p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="font-bold text-lg text-[#1E3A1D]">Items</h2>
                            <button type="button" id="addItemBtn" class="text-sm bg-green-100 text-green-800 py-1 px-3 rounded hover:bg-green-200 font-bold flex items-center gap-1">
                                <span class="material-icons text-sm">add</span> Add Item
                            </button>
                        </div>
                        
                        <div class="overflow-hidden border rounded-lg">
                            <table class="w-full text-left">
                                <thead class="bg-gray-50 text-gray-600 text-xs uppercase font-bold">
                                    <tr>
                                        <th class="p-3 w-5/12">Product</th>
                                        <th class="p-3 w-20">Stock</th>
                                        <th class="p-3 w-20">Price</th>
                                        <th class="p-3 w-40 text-center">Qty / Weight</th> <th class="p-3 w-24 text-right">Subtotal</th>
                                        <th class="p-3 w-10"></th>
                                    </tr>
                                </thead>
                                <tbody id="itemsTableBody" class="divide-y divide-gray-100 text-sm"></tbody>
                            </table>
                        </div>
                        <div id="emptyState" class="text-center py-8 text-gray-400 text-sm italic">
                            No items added yet. Click "Add Item" to start.
                        </div>
                    </div>
                </div>

                <div class="space-y-6">
                    <div class="content-card p-6">
                        <h2 class="font-bold text-lg text-[#1E3A1D] mb-4">Customer Details</h2>
                        
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Customer Name</label>
                                <input type="text" id="customer_name" name="customer_name" class="w-full p-2 form-input" placeholder="e.g. Aling Nena" required>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Method</label>
                                    <select name="payment_method" id="payment_method" class="w-full p-2 form-input">
                                        <option value="Cash">Cash</option>
                                        <option value="GCash">GCash</option>
                                        <option value="Bank Transfer">Bank Transfer</option>
                                        <option value="Credit">Credit</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Status</label>
                                    <select name="payment_status" id="payment_status" class="w-full p-2 form-input">
                                        <option value="Paid">Paid</option>
                                        <option value="Pending">Pending</option>
                                        <option value="Partial">Partial</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="content-card p-6 bg-[#1E3A1D] text-white">
                        <div class="flex justify-between items-center mb-2 opacity-80">
                            <span>Total Items</span>
                            <span id="totalCount">0</span>
                        </div>
                        <div class="flex justify-between items-end border-t border-white/20 pt-4">
                            <span class="font-bold text-lg">GRAND TOTAL</span>
                            <span class="font-mono text-3xl font-bold" id="grandTotal">₱0.00</span>
                        </div>
                        
                        <button type="submit" id="submitSaleBtn" class="w-full mt-6 bg-white text-[#1E3A1D] font-bold py-3 rounded-lg hover:bg-gray-100 transition shadow-lg disabled:opacity-50 disabled:cursor-not-allowed">
                            CONFIRM SALE
                        </button>
                    </div>
                </div>
            </form>
        </main>
    </div>

    <script>
        const INVENTORY_DATA = <?= json_encode($products); ?>;
    </script>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const tableBody = document.getElementById('itemsTableBody');
        const emptyState = document.getElementById('emptyState');
        const totalCountEl = document.getElementById('totalCount');
        const grandTotalEl = document.getElementById('grandTotal');
        const form = document.getElementById('salesForm');
        
        let rowCount = 0;

        function addRow() {
            emptyState.style.display = 'none';
            rowCount++;
            
            const tr = document.createElement('tr');
            tr.className = "bg-white hover:bg-gray-50 transition item-row";
            tr.innerHTML = `
                <td class="p-2 align-middle">
                    <select class="product-select w-full p-2 border rounded bg-gray-50 focus:bg-white focus:ring-1 focus:ring-green-500 outline-none text-sm">
                        <option value="">-- Select Product --</option>
                        ${INVENTORY_DATA.map(p => `<option value="${p.product_id}" data-price="${p.price}" data-stock="${p.quantity}">${p.name}</option>`).join('')}
                    </select>
                </td>
                <td class="p-2 align-middle text-xs text-gray-500 stock-cell">-</td>
                <td class="p-2 align-middle font-mono text-gray-600 price-cell">0.00</td>
                <td class="p-2 align-middle flex justify-center">
                    <div class="stepper">
                        <button type="button" class="minus-btn">−</button>
                        <input type="number" min="0.01" step="0.01" class="qty-input" disabled placeholder="0">
                        <button type="button" class="plus-btn">+</button>
                    </div>
                </td>
                <td class="p-2 align-middle text-right font-bold font-mono subtotal-cell">0.00</td>
                <td class="p-2 align-middle text-center">
                    <button type="button" class="text-red-400 hover:text-red-600 remove-row"><span class="material-icons text-sm">close</span></button>
                </td>
            `;
            tableBody.appendChild(tr);

            // Select Elements
            const select = tr.querySelector('.product-select');
            const qtyInput = tr.querySelector('.qty-input');
            const plusBtn = tr.querySelector('.plus-btn');
            const minusBtn = tr.querySelector('.minus-btn');
            const removeBtn = tr.querySelector('.remove-row');

            // Product Selection Logic
            select.addEventListener('change', () => {
                const option = select.options[select.selectedIndex];
                if (option.value) {
                    tr.querySelector('.price-cell').textContent = parseFloat(option.dataset.price).toFixed(2);
                    tr.querySelector('.stock-cell').textContent = option.dataset.stock;
                    qtyInput.disabled = false;
                    qtyInput.max = option.dataset.stock; 
                    qtyInput.value = 1; // Default to 1 unit/kg
                    calculateRow(tr);
                } else {
                    resetRow(tr);
                }
            });

            // STEPPER LOGIC (Plus Button)
            plusBtn.addEventListener('click', () => {
                if (qtyInput.disabled) return;
                let currentVal = parseFloat(qtyInput.value) || 0;
                let max = parseFloat(qtyInput.max);
                if (currentVal < max) {
                    let newVal = currentVal + 1;
                    // If result has no decimal, keep it integer
                    if(newVal % 1 !== 0) newVal = parseFloat(newVal.toFixed(2));
                    qtyInput.value = newVal;
                    calculateRow(tr);
                } else {
                    alert("Max stock reached!");
                }
            });

            // STEPPER LOGIC (Minus Button)
            minusBtn.addEventListener('click', () => {
                if (qtyInput.disabled) return;
                let currentVal = parseFloat(qtyInput.value) || 0;
                if (currentVal > 1) {
                    let newVal = currentVal - 1;
                    if(newVal % 1 !== 0) newVal = parseFloat(newVal.toFixed(2));
                    qtyInput.value = newVal;
                    calculateRow(tr);
                }
            });

            // Manual Input Logic (Handling weight decimals like 1.5)
            qtyInput.addEventListener('input', () => {
                const max = parseFloat(qtyInput.max);
                const val = parseFloat(qtyInput.value);
                if(val > max) {
                    qtyInput.classList.add('text-red-600', 'font-bold');
                } else {
                    qtyInput.classList.remove('text-red-600', 'font-bold');
                }
                calculateRow(tr);
            });

            removeBtn.addEventListener('click', () => {
                tr.remove();
                if(tableBody.children.length === 0) emptyState.style.display = 'block';
                calculateGrandTotal();
            });
        }

        function calculateRow(tr) {
            const select = tr.querySelector('.product-select');
            const price = parseFloat(select.options[select.selectedIndex].dataset.price || 0);
            const qty = parseFloat(tr.querySelector('.qty-input').value || 0);
            const subtotal = price * qty;
            tr.querySelector('.subtotal-cell').textContent = subtotal.toFixed(2);
            calculateGrandTotal();
        }

        function resetRow(tr) {
            tr.querySelector('.price-cell').textContent = '0.00';
            tr.querySelector('.stock-cell').textContent = '-';
            tr.querySelector('.qty-input').value = '';
            tr.querySelector('.qty-input').disabled = true;
            tr.querySelector('.subtotal-cell').textContent = '0.00';
            calculateGrandTotal();
        }

        function calculateGrandTotal() {
            let total = 0;
            let count = 0;
            document.querySelectorAll('.item-row').forEach(row => {
                const sub = parseFloat(row.querySelector('.subtotal-cell').textContent);
                total += sub;
                count++;
            });
            grandTotalEl.textContent = '₱' + total.toFixed(2);
            totalCountEl.textContent = count;
        }

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('submitSaleBtn');
            const items = [];
            let hasStockError = false;
            
            document.querySelectorAll('.item-row').forEach(row => {
                const select = row.querySelector('.product-select');
                const qtyInput = row.querySelector('.qty-input');
                const qty = parseFloat(qtyInput.value);
                const max = parseFloat(qtyInput.max);

                if(select.value && qty > 0) {
                    if (qty > max) hasStockError = true;
                    items.push({
                        id: select.value,
                        price: select.options[select.selectedIndex].dataset.price,
                        qty: qty
                    });
                }
            });

            if (items.length === 0) { alert("Please add at least one item."); return; }
            if (hasStockError) { alert("One or more items exceed available stock. Please check the red highlighted inputs."); return; }
            if (!confirm("Are you sure you want to process this sale?")) return;

            btn.disabled = true;
            btn.textContent = "Processing...";

            const formData = new FormData(form);
            formData.append('action', 'save_sale');
            formData.append('items', JSON.stringify(items));

            try {
                const res = await fetch('', { method: 'POST', body: formData });
                const result = await res.json(); 

                if (result.success) {
                    alert("Sale Recorded Successfully!");
                    window.location.href = 'sales.php'; 
                } else {
                    alert("Error: " + result.message);
                    btn.disabled = false;
                    btn.textContent = "CONFIRM SALE";
                }
            } catch (err) {
                console.error(err);
                alert("Server Error. Check console.");
                btn.disabled = false;
            }
        });

        document.getElementById('addItemBtn').addEventListener('click', addRow);
        
        if(INVENTORY_DATA.length > 0) {
            addRow();
        } else {
            emptyState.textContent = "No active products found in inventory. Go add some products first!";
            document.getElementById('addItemBtn').disabled = true;
        }
    });
    </script>
</body>
</html>