<?php
// admin_components/chat_api.php
session_start();
require_once 'includes/db_connection.php'; 

// =========================================================================
'YOUR_API_KEY_HERE'; 
// =========================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = trim($_POST['message'] ?? '');
    
    // --- CLEAR CHAT MEMORY COMMAND ---
    if ($message === 'CLEAR_CHAT_HISTORY') {
        unset($_SESSION['chat_history']);
        echo "Memory cleared! Hello, I am FreshFlow AI. How can I assist you with your inventory today? 🥬📊";
        exit;
    }

    // ---------------------------------------------------------------------
    // STEP A: FETCH REAL-TIME DATA (EYES OF THE AI)
    // ---------------------------------------------------------------------
    
    // 1A. ALREADY EXPIRED Data Check (The Bad Stuff)
    $expired_data = "";
    $expired_count = 0;
    $query_already_exp = "SELECT p.name, pi.quantity, p.expiration_date 
                          FROM product_inventory pi 
                          JOIN products p ON pi.product_id = p.product_id 
                          WHERE p.expiration_date < CURDATE() 
                          AND pi.quantity > 0";
    $res_already_exp = $conn->query($query_already_exp);
    if ($res_already_exp && $res_already_exp->num_rows > 0) {
        while ($row = $res_already_exp->fetch_assoc()) {
            $date = date("M d, Y", strtotime($row['expiration_date']));
            $expired_data .= "- [CRITICAL] {$row['name']} ({$row['quantity']} units EXPIRED ON {$date})\n";
            $expired_count++;
        }
    } else {
        $expired_data = "Zero expired items in stock. Excellent!\n";
    }

    // 1B. EXPIRING SOON Data Check (Next 14 Days)
    $expiring_data = "";
    $exp_count = 0;
    $query_exp = "SELECT p.name, pi.quantity, p.expiration_date 
                  FROM product_inventory pi 
                  JOIN products p ON pi.product_id = p.product_id 
                  WHERE p.expiration_date >= CURDATE() 
                  AND p.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY) 
                  AND pi.quantity > 0";
    $res_exp = $conn->query($query_exp);
    if ($res_exp && $res_exp->num_rows > 0) {
        while ($row = $res_exp->fetch_assoc()) {
            $date = date("M d, Y", strtotime($row['expiration_date']));
            $expiring_data .= "- {$row['name']} ({$row['quantity']} left, expires {$date})\n";
            $exp_count++;
        }
    } else {
        $expiring_data = "No items are expiring in the next 14 days.\n";
    }

    // 2. Low Stock Data Check
    $low_stock_data = "";
    $low_count = 0;
    $query_low = "SELECT p.name, pi.quantity 
                  FROM product_inventory pi 
                  JOIN products p ON pi.product_id = p.product_id 
                  WHERE pi.quantity <= 10";
    $res_low = $conn->query($query_low);
    if ($res_low && $res_low->num_rows > 0) {
        while ($row = $res_low->fetch_assoc()) {
            $low_stock_data .= "- {$row['name']} (Only {$row['quantity']} left)\n";
            $low_count++;
        }
    } else {
        $low_stock_data = "All stock levels are currently healthy.\n";
    }

    // 3. System Statistics
    $total_users = $conn->query("SELECT COUNT(*) as total FROM users")->fetch_assoc()['total'] ?? 0;
    $total_products = $conn->query("SELECT COUNT(*) as total FROM products")->fetch_assoc()['total'] ?? 0;
    
    // 4. Financial Total Value Check (SQL-BASED MATH TO PREVENT FATAL ERROR)
    $total_value = 0;
    $query_value = "SELECT SUM(pi.quantity * p.price) as total_val 
                    FROM product_inventory pi 
                    JOIN products p ON pi.product_id = p.product_id";
    $res_value = $conn->query($query_value);
    if ($res_value && $row = $res_value->fetch_assoc()) {
        $total_value = number_format((float)($row['total_val'] ?? 0), 2);
    }

    // 5. Today's Sales Check
    $today_sales = 0;
    $query_sales = "SELECT SUM(total_amount) as sales FROM sales WHERE order_status = 'Completed' AND DATE(delivered_at) = CURDATE()";
    $res_sales = $conn->query($query_sales);
    if ($res_sales && $row = $res_sales->fetch_assoc()) {
        $today_sales = number_format((float)($row['sales'] ?? 0), 2);
    }

    // 6. Unpaid Collectibles Check (SQL-BASED MATH TO PREVENT FATAL ERROR)
    $unpaid_invoices = 0;
    $unpaid_count = 0;
    $query_inv = "SELECT SUM(total_amount - amount_paid) as unpaid, COUNT(*) as count 
                  FROM sales 
                  WHERE order_status = 'Completed' 
                  AND payment_status != 'Paid' 
                  AND payment_status != 'Cancelled'";
    $res_inv = $conn->query($query_inv);
    if ($res_inv && $row = $res_inv->fetch_assoc()) {
        $unpaid_invoices = number_format((float)($row['unpaid'] ?? 0), 2);
        $unpaid_count = $row['count'] ?? 0;
    }
    
    // 7. Smart AI Pricing & Potential Loss Check
    $smart_pricing_data = "";
    $potential_loss = 0;
    
    $query_pricing = "SELECT p.name, p.price, pi.quantity, p.expiration_date,
                      DATEDIFF(p.expiration_date, CURDATE()) as days_left
                      FROM product_inventory pi 
                      JOIN products p ON pi.product_id = p.product_id 
                      WHERE p.expiration_date <= DATE_ADD(NOW(), INTERVAL 14 DAY) 
                      AND p.expiration_date >= NOW()
                      AND pi.quantity > 0";
                      
    $res_pricing = $conn->query($query_pricing);
    if ($res_pricing && $res_pricing->num_rows > 0) {
        while ($row = $res_pricing->fetch_assoc()) {
            $days_left = (int)$row['days_left'];
            $base_price = (float)$row['price'];
            $qty = (int)$row['quantity'];
            
            // Your exact logic from smart_pricing.php
            if ($days_left <= 1) { $new_price = $base_price * 0.40; } // 60% off 
            elseif ($days_left <= 3) { $new_price = $base_price * 0.60; } // 40% off
            elseif ($days_left <= 7) { $new_price = $base_price * 0.85; } // 15% off 
            else { $new_price = $base_price; }
            
            // If a discount is recommended, calculate the risk and add it to AI memory
            if ($new_price < $base_price) {
                $potential_loss += ($base_price * $qty); // Calculate total money at risk
                $formatted_new = number_format($new_price, 2);
                $formatted_base = number_format($base_price, 2);
                $smart_pricing_data .= "- {$row['name']}: Drop from ₱{$formatted_base} to ₱{$formatted_new} ({$days_left} days left)\n";
            }
        }
    }
    
    if (empty($smart_pricing_data)) {
        $smart_pricing_data = "No items require emergency price markdowns right now.\n";
    }

    // ---------------------------------------------------------------------
    // STEP B: THE SYSTEM INSTRUCTION (THE AI'S BRAIN & PERSONALITY)
    // ---------------------------------------------------------------------
    $system_instruction = "You are 'FreshFlow AI', an intelligent, friendly, and proactive onboarding assistant built directly into the FreshFlow Inventory Management System. 

Your job is to act as an expert inventory advisor and a friendly software guide for new administrators or business owners. 

### CURRENT REAL-TIME DATABASE STATUS:
* Registered System Users: $total_users
* Total Unique Products in Catalog: $total_products
* Total Inventory Value: ₱$total_value
* Today's Total Sales: ₱$today_sales
* Unpaid Collectibles: $unpaid_count pending records (Totaling ₱$unpaid_invoices)
* Potential Spoilage Loss Risk: ₱$potential_loss

[SMART PRICING RECOMMENDATIONS]:
$smart_pricing_data

[ALREADY EXPIRED ($expired_count items)]:
$expired_data

[EXPIRING SOON ($exp_count items)]:
$expiring_data

[LOW STOCK ALERT ($low_count items)]:
$low_stock_data

### YOUR CAPABILITIES & RULES:
1. ONBOARDING: Explain that FreshFlow makes management effortless. 
2. PROACTIVE SUGGESTIONS: Suggest business strategies for expiring stock, low stock, or unpaid collectibles.
3. STRICT LANGUAGE MIRRORING (CRITICAL): Your default language is pure English. If the user types in English (like 'hi there' or 'how are you'), your ENTIRE reply MUST be in 100% English. DO NOT use a single word of Tagalog unless the user types a Tagalog word first. ONLY switch to Taglish IF the user explicitly uses Tagalog words in their message.
4. HISTORICAL DATA (CRITICAL): You only know TODAY's financial data. If the user asks about yesterday, last month, or a specific date (like 'March 17'), politely explain that you only track real-time daily metrics, and give them a Magic Link to 'dashboard.php', 'invoices.php', or 'cashflow.php' to view the full history.
5. MAGIC NAVIGATION LINKS (CRITICAL): If the user asks how to do something, where to go, or how to access a feature, you MUST provide a clickable HTML link to the exact page they need. 
   Format your links EXACTLY like this: <a href='filename.php' style='color: #0056b3; font-weight: bold; text-decoration: underline;'>Name of Page</a>

   Here is your exact map of the FreshFlow system. Use these EXACT filenames:
   - Create a new order -> order_create.php
   - Check order queue/status -> order_queue.php
   - Dispatch or Delivery -> dispatch.php
   - Returns and Rejects -> returns.php
   - View Client Directory -> clients.php
   - Add/Edit Products -> products.php
   - Manage Categories -> categories.php
   - Manage Stock/Inventory Levels -> inventory.php
   - Purchase Orders -> purchase_orders.php
   - Supplier List -> supplier_list.php
   - Finance (Invoices/Expenses/Cashflow) -> invoices.php, expenses.php, or cashflow.php
   - Smart AI Pricing -> smart_pricing.php
   - Sales Forecast -> forecast.php
   - Spoilage Report -> spoilage.php
   - User Management -> users.php
   - System Audit Logs -> audit_logs.php

6. TONE & PERSONALITY (CRITICAL): Act like a real, highly intelligent human colleague, not a coded robot. Balance professional inventory advice with lighthearted, natural humor. Vary your tone dynamically.
7. FORMATTING: Use <br> for line breaks. Do NOT use markdown like ** or *.
8. SUPPLIER EMAIL DRAFTER (CRITICAL): If the user asks you to restock an item, email a supplier, or order supplies, you MUST generate a professional email draft. Wrap the email draft exactly in this HTML so it stands out beautifully:
   <div style='background-color: #f4f6f9; border-left: 4px solid #0056b3; padding: 15px; margin-top: 10px; margin-bottom: 10px; font-family: monospace; font-size: 13px; color: #333;'>
   <b>Subject:</b> [Write Subject Here]<br><br>[Write Email Body Here]<br><br>Best regards,<br>FreshFlow Management
   </div>
9. SMART PRICING EXPERT: You have a built-in markdown engine. If the user asks about reducing prices, avoiding spoilage, or smart pricing, look at the [SMART PRICING RECOMMENDATIONS]. Tell the user exactly which products need a price drop and how much money is at risk. Always provide a Magic Link to 'smart_pricing.php' so they can automatically apply the AI Markdown!
10. BE HIGHLY CONCISE (CRITICAL): You are a fast, busy business assistant. Keep your answers short, crisp, and direct. DO NOT write long paragraphs. Limit your responses to 2-3 short sentences unless you are specifically listing database items. Never ramble.";



    // ---------------------------------------------------------------------
    // STEP C: ADDING MEMORY (Groq Format with Capstone Crash Prevention)
    // ---------------------------------------------------------------------
    if (!isset($_SESSION['chat_history'])) {
        $_SESSION['chat_history'] = [];
    }

    $_SESSION['chat_history'][] = [
        "role" => "user", 
        "content" => $message
    ];

    // THE FIX: Only keep the last 6 messages in memory (3 interactions). 
    // This guarantees you NEVER exceed Groq's 6000 token limit during your presentation!
    if (count($_SESSION['chat_history']) > 6) {
        $_SESSION['chat_history'] = array_slice($_SESSION['chat_history'], -6);
    }

    // ---------------------------------------------------------------------
    // STEP D: SEND TO AI (GROQ LLAMA 3 - SUPER FAST, UNLIMITED)
    // ---------------------------------------------------------------------
    $url = "https://api.groq.com/openai/v1/chat/completions";
    
    $messages_payload = [];
    $messages_payload[] = [
        "role" => "system",
        "content" => $system_instruction
    ];
    
    foreach ($_SESSION['chat_history'] as $msg) {
        $messages_payload[] = $msg;
    }

    $data = [
        "model" => "llama-3.1-8b-instant", 
        "messages" => $messages_payload,
        "temperature" => 0.7 
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key 
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // ---------------------------------------------------------------------
    // STEP E: FORMAT RESPONSE & SAVE AI MEMORY
    // ---------------------------------------------------------------------
    if ($http_code == 200) {
        $result = json_decode($response, true);
        if (isset($result['choices'][0]['message']['content'])) {
            $ai_reply = $result['choices'][0]['message']['content'];
            
            $_SESSION['chat_history'][] = [
                "role" => "assistant", 
                "content" => $ai_reply
            ];

            // Remove the stubborn markdown asterisks the AI tries to use
            $ai_reply = str_replace(['**', '*'], '', $ai_reply);

            // Print the reply without htmlspecialchars so the links actually become clickable!
            echo nl2br($ai_reply);
        } else {
            array_pop($_SESSION['chat_history']);
            echo "I'm having trouble analyzing the data right now. Please try again.";
        }
    } elseif ($http_code == 429) {
        array_pop($_SESSION['chat_history']); 
        echo "To ensure smooth system performance, the AI has a safe-usage limit. I just need a quick 60-second breather to process our conversation! Please wait one minute, then try your message again. ⏱️";
    } elseif ($http_code == 401) {
        array_pop($_SESSION['chat_history']); 
        echo "<em>(SYSTEM ERROR: The API Key is invalid or missing. Please check your chat_api.php file.)</em>";
    } else {
        // --- THIS IS THE NEW DIAGNOSTIC FIX ---
        $curl_error = curl_error($ch);
        $raw_response = htmlspecialchars($response);
        array_pop($_SESSION['chat_history']); 
        
        echo "<em>(SYSTEM ERROR DIAGNOSTIC: HTTP Code [$http_code] | cURL Error: [$curl_error] | Groq Says: [$raw_response])</em>";
    }
}
?>