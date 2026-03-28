<?php
/**
 * low_stock_notif.php
 * Renders the Admin Profile and Low Stock Alert icon/tooltip.
 */

function renderAdminHeaderAndAlert($conn) {
    // Define the threshold
    if (!defined('LOW_STOCK_THRESHOLD')) {
        define('LOW_STOCK_THRESHOLD', 5);
    }
    
    $total_products_for_alert = 0;
    $out_of_stock_for_alert = 0;

    // CRITICAL CHECK: Only attempt database query if $conn is valid
    if (isset($conn) && ($conn instanceof mysqli) && !$conn->connect_error) {
        $threshold = LOW_STOCK_THRESHOLD;
        $sql = "
            SELECT 
                COUNT(p.product_id) as total_low_stock_count,
                SUM(CASE WHEN pi.quantity = 0 THEN 1 ELSE 0 END) as out_of_stock_count
            FROM products p 
            JOIN product_inventory pi ON p.product_id = pi.product_id 
            WHERE pi.quantity <= ? AND p.status = 'Active'";
        
        $stmt = $conn->prepare($sql);

        if ($stmt) {
            $stmt->bind_param("i", $threshold);
            $stmt->execute();
            $alert_counts = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $total_products_for_alert = $alert_counts['total_low_stock_count'] ?? 0;
            $out_of_stock_for_alert = $alert_counts['out_of_stock_count'] ?? 0;
        } else {
            error_log("SQL Prepare failed in low_stock_notif.php: " . $conn->error);
        }
    } else {
        error_log("Database connection (\$conn) not available or failed for low stock check.");
    }
    
    // --- START: HTML Output ---
    ?>
    <div class="flex items-center space-x-4">
        
        <?php if ($total_products_for_alert > 0): ?>
            <div class="relative group p-2 rounded-lg cursor-pointer bg-red-900/40 hover:bg-red-800/60 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-6 h-6 text-red-400">
                    <path fill-rule="evenodd" d="M2.25 12c0-5.56 4.54-10.05 10.12-10.05 5.58 0 10.12 4.49 10.12 10.05s-4.54 10.05-10.12 10.05C6.79 22.05 2.25 17.56 2.25 12zm10.13 4.22c.38 0 .69-.3.69-.67v-4.52c0-.37-.3-.67-.69-.67s-.69.3-.69.67v4.52c0 .37.31.67.69.67zm-.01-6.17a.8.8 0 100-1.6.8.8 0 000 1.6z" clip-rule="evenodd" />
                </svg>

                <div class="absolute right-0 top-full mt-2 w-72 bg-red-700 text-white p-3 rounded-lg shadow-xl border border-red-800 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-opacity duration-200 z-50 transform origin-top-right">
                    <div class="font-bold border-b border-red-600 pb-1 mb-2">STOCK ALERT!</div>
                    <p class="text-sm">
                        🚨 <?= $total_products_for_alert ?> product(s) are low on stock.
                        <br>
                        <span class="font-bold text-red-200">This includes <?= $out_of_stock_for_alert ?> out-of-stock item(s).</span>
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION["username"])): ?>
        <div class="flex items-center space-x-4 bg-slate-900/50 border border-slate-800 p-2 rounded-lg">
            <img src="https://placehold.co/40x40/ef4444/ffffff?text=<?php echo htmlspecialchars(strtoupper(substr($_SESSION["username"], 0, 1))); ?>" alt="Admin Avatar" class="w-10 h-10 rounded-full border-2 border-red-500">
            <span class="text-slate-300 font-medium">Welcome, <?php echo htmlspecialchars($_SESSION["username"]); ?>!</span>
        </div>
        <?php endif; ?>

    </div>
    <?php
    // --- END: HTML Output ---
}

// Check if the function is defined and if $conn is set in the calling scope
if (function_exists('renderAdminHeaderAndAlert') && isset($conn)) {
    // PASS $conn TO THE FUNCTION
    renderAdminHeaderAndAlert($conn);
} else {
    // Fallback: only render the username if $conn is missing, allowing the rest of the page to load
    if (isset($_SESSION["username"])) {
        ?>
        <div class="flex items-center space-x-4">
            <div class="flex items-center space-x-4 bg-slate-900/50 border border-slate-800 p-2 rounded-lg">
                <img src="https://placehold.co/40x40/ef4444/ffffff?text=<?php echo htmlspecialchars(strtoupper(substr($_SESSION["username"], 0, 1))); ?>" alt="Admin Avatar" class="w-10 h-10 rounded-full border-2 border-red-500">
                <span class="text-slate-300 font-medium">Welcome, <?php echo htmlspecialchars($_SESSION["username"]); ?>!</span>
            </div>
        </div>
        <?php
    }
}
?>