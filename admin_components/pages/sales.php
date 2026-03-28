<?php
// C:\xampp\htdocs\RalphPHP\admin_components\pages\sales.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

include_once '../includes/db_connection.php';

// --- CONFIGURATION ---
if (!defined('LOW_STOCK_THRESHOLD')) define('LOW_STOCK_THRESHOLD', 10);

// --- SECURITY CHECK ---
// Allow 'admin' AND 'staff'
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: ../admin_login.php");
    exit;
}
// (We removed the "role_name !== 'admin'" part, so staff can stay)

// --- AJAX: GET SALES DATA ---
if (isset($_GET['action']) && $_GET['action'] == 'fetch_sales') {
    header('Content-Type: application/json');
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $limit = 10;
    $offset = ($page - 1) * $limit;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    
    // Base Query
    $sql = "SELECT s.*, 
            (SELECT COUNT(*) FROM sales_items si WHERE si.sale_id = s.sale_id) as item_count 
            FROM sales s";
    
    // Search Logic
    if (!empty($search)) {
        $sql .= " WHERE s.customer_name LIKE '%" . $conn->real_escape_string($search) . "%' OR s.sale_id = '" . intval($search) . "'";
    }
    
    $sql .= " ORDER BY s.sale_date DESC LIMIT $limit OFFSET $offset";
    
    $result = $conn->query($sql);
    $sales = $result->fetch_all(MYSQLI_ASSOC);
    
    // Count Total for Pagination
    $count_sql = "SELECT COUNT(*) as total FROM sales s";
    if (!empty($search)) {
        $count_sql .= " WHERE s.customer_name LIKE '%" . $conn->real_escape_string($search) . "%' OR s.sale_id = '" . intval($search) . "'";
    }
    $total_res = $conn->query($count_sql);
    $total_rows = $total_res->fetch_assoc()['total'];
    $total_pages = ceil($total_rows / $limit);
    
    echo json_encode(['sales' => $sales, 'pagination' => ['current_page' => $page, 'total_pages' => $total_pages]]);
    exit;
}

// --- ANALYTICS (Top Cards) ---
// Total Sales Today
$today_sales = $conn->query("SELECT SUM(total_amount) as total FROM sales WHERE DATE(sale_date) = CURDATE()")->fetch_assoc()['total'] ?? 0;
// Total Sales This Month
$month_sales = $conn->query("SELECT SUM(total_amount) as total FROM sales WHERE MONTH(sale_date) = MONTH(CURRENT_DATE())")->fetch_assoc()['total'] ?? 0;
// Count Pending Payments
$pending_pay = $conn->query("SELECT COUNT(*) as count FROM sales WHERE payment_status = 'Pending'")->fetch_assoc()['count'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreshFlow - Sales History</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --brand-green: #1E3A1D; --brand-cream: #F8F5EE; --accent-red: #B33333; }
        body { font-family: 'Inter', sans-serif; background-color: var(--brand-cream); color: #2B2B2B; }
        .content-card { background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 0.75rem; overflow: hidden; }
        .btn-primary { background-color: var(--brand-green); color: white; }
        .status-badge { padding: 2px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .status-Paid { background-color: #dcfce7; color: #166534; }
        .status-Pending { background-color: #fef9c3; color: #854d0e; }
        .status-Partial { background-color: #dbeafe; color: #1e40af; }
    </style>
</head>
<body>
    
    <?php include '../includes/sidebar.php'; ?>

    <div class="ml-20 transition-all duration-300">
        <main class="p-6 md:p-8">
            <header class="flex justify-between items-center mb-8">
                <div>
                    <h1 class="text-3xl font-bold text-[#1E3A1D] font-mono">Sales History</h1>
                    <p class="text-sm text-gray-500">Track vegetable orders and revenue</p>
                </div>
                <a href="sales_create.php" class="btn-primary font-bold py-2 px-6 rounded-lg flex items-center gap-2 shadow-lg hover:-translate-y-0.5 transition">
                    <span class="material-icons">point_of_sale</span> <span>Record New Sale</span>
                </a>
            </header>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div class="content-card p-6 border-l-4 border-green-600">
                    <p class="text-xs font-bold text-gray-400 uppercase">Sales Today</p>
                    <p class="text-3xl font-bold text-[#1E3A1D]">₱<?= number_format($today_sales, 2) ?></p>
                </div>
                <div class="content-card p-6 border-l-4 border-blue-600">
                    <p class="text-xs font-bold text-gray-400 uppercase">This Month</p>
                    <p class="text-3xl font-bold text-blue-800">₱<?= number_format($month_sales, 2) ?></p>
                </div>
                <div class="content-card p-6 border-l-4 border-yellow-500">
                    <p class="text-xs font-bold text-gray-400 uppercase">Pending Payments</p>
                    <p class="text-3xl font-bold text-yellow-700"><?= $pending_pay ?> Orders</p>
                </div>
            </div>

            <div class="content-card p-4 mb-6">
                <input type="text" id="searchInput" placeholder="Search by Customer Name or Sale ID..." class="w-full p-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-700">
            </div>

            <div class="content-card">
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="bg-[#1E3A1D] text-white">
                            <tr>
                                <th class="p-4">ID</th>
                                <th class="p-4">Date</th>
                                <th class="p-4">Customer</th>
                                <th class="p-4">Items</th>
                                <th class="p-4">Total</th>
                                <th class="p-4">Status</th>
                                <th class="p-4 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody id="salesTableBody" class="text-gray-700 divide-y divide-gray-100">
                            <tr><td colspan="7" class="p-8 text-center text-gray-400">Loading sales data...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="p-4 border-t border-gray-100 flex justify-between" id="pagination"></div>
            </div>
        </main>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        let currentPage = 1;
        
        const fetchSales = async (page = 1, search = '') => {
            const res = await fetch(`?action=fetch_sales&page=${page}&search=${search}`);
            const data = await res.json();
            renderTable(data.sales);
            renderPagination(data.pagination);
        };

        const renderTable = (sales) => {
            const tbody = document.getElementById('salesTableBody');
            tbody.innerHTML = '';
            if(!sales.length) { tbody.innerHTML = '<tr><td colspan="7" class="p-8 text-center text-gray-400">No sales records found.</td></tr>'; return; }
            
            sales.forEach(s => {
                const date = new Date(s.sale_date).toLocaleDateString();
                const statusClass = `status-${s.payment_status}`;
                tbody.innerHTML += `
                    <tr class="hover:bg-gray-50 transition">
                        <td class="p-4 font-mono text-sm text-gray-500">#${s.sale_id}</td>
                        <td class="p-4 text-sm">${date}</td>
                        <td class="p-4 font-bold text-gray-800">${s.customer_name}</td>
                        <td class="p-4 text-sm text-gray-600">${s.item_count} items</td>
                        <td class="p-4 font-mono font-bold">₱${parseFloat(s.total_amount).toFixed(2)}</td>
                        <td class="p-4"><span class="status-badge ${statusClass}">${s.payment_status}</span></td>
                        <td class="p-4 text-right">
                            <button class="text-gray-400 hover:text-[#1E3A1D]"><span class="material-icons">visibility</span></button>
                        </td>
                    </tr>
                `;
            });
        };

        const renderPagination = (p) => {
            const container = document.getElementById('pagination');
            let html = `<span class="text-sm text-gray-500">Page ${p.current_page} of ${p.total_pages}</span>`;
            html += `<div class="flex gap-2">`;
            if(p.current_page > 1) html += `<button onclick="changePage(${p.current_page - 1})" class="px-3 py-1 border rounded hover:bg-gray-100">Prev</button>`;
            if(p.current_page < p.total_pages) html += `<button onclick="changePage(${p.current_page + 1})" class="px-3 py-1 border rounded hover:bg-gray-100">Next</button>`;
            html += `</div>`;
            container.innerHTML = html;
        };

        window.changePage = (page) => { currentPage = page; fetchSales(page, document.getElementById('searchInput').value); };
        
        let debounceTimer;
        document.getElementById('searchInput').addEventListener('input', (e) => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => fetchSales(1, e.target.value), 300);
        });

        fetchSales();
    });
    </script>
</body>
</html>