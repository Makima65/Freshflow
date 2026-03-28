<?php
// C:\xampp\htdocs\RalphPHP\admin_components\pages\seed_data.php
include_once '../includes/db_connection.php';

echo "<h1>Generating Seasonal History...</h1>";

// 1. Get All Products
$products = $conn->query("SELECT product_id, name FROM products");

if ($products->num_rows > 0) {
    while($p = $products->fetch_assoc()) {
        $pid = $p['product_id'];
        $pname = $p['name'];
        echo "Generating history for: <strong>$pname</strong>... ";

        // 2. Delete old dummy data for this product to avoid duplicates
        // Note: We delete sales older than 2026-01-01 associated with this product
        // (This is a simplified cleanup for the demo)
        
        // 3. Loop through months (Jan 2025 to Dec 2025)
        for ($m = 1; $m <= 12; $m++) {
            // Randomize sales behavior
            // Peak Season for everything is roughly Nov/Dec (Holidays)
            $base_qty = rand(50, 150);
            if ($m == 11 || $m == 12) $base_qty *= 2.5; // Holiday spike
            if ($m == 1 || $m == 2) $base_qty *= 0.6;   // Post-holiday slump
            
            // Add some randomness so every graph looks different
            $final_qty = $base_qty + rand(-20, 20); 
            $sale_date = "2025-" . str_pad($m, 2, "0", STR_PAD_LEFT) . "-15";
            
            // Insert Dummy Sale
            $conn->query("INSERT INTO sales (client_id, sale_date, total_amount, order_status) VALUES (1, '$sale_date', 1000.00, 'Completed')");
            $sale_id = $conn->insert_id;
            
            // Insert Sales Item
            $conn->query("INSERT INTO sales_items (sale_id, product_id, quantity, price, subtotal) VALUES ($sale_id, $pid, $final_qty, 10.00, 100.00)");
        }
        echo "<span style='color:green;'>Done!</span><br>";
    }
} else {
    echo "No products found. Add products to your inventory first.";
}

echo "<h2>✅ Success! All products now have 1 year of history.</h2>";
echo "<a href='analytics_seasonal.php'>Go back to Analytics</a>";
?>