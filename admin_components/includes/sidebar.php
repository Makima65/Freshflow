<?php
// C:\xampp\htdocs\RalphPHP\admin_components\includes\sidebar.php

// ============================================================
//  SECURITY: GENERATE CSRF TOKEN (The "Secret Handshake")
// ============================================================
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
// ============================================================

// ============================================================
//  SECURITY: REAL-TIME ROLE SYNC & AUTO-LOGOUT
// ============================================================
if (isset($_SESSION['user_id']) && isset($conn)) {
    $user_id_safe = intval($_SESSION['user_id']);
    
    // 1. Role Check
    $role_check = $conn->query("SELECT role FROM users WHERE user_id = $user_id_safe LIMIT 1");
    if ($role_check && $role_check->num_rows > 0) {
        $fresh_data = $role_check->fetch_assoc();
        $_SESSION['role_name'] = $fresh_data['role']; // Instantly update their session
    } else {
        // If the user was deleted entirely, log them out
        session_destroy();
        header("Location: ../admin_login.php");
        exit;
    }

    // 2. AUTO-LOGOUT LOGIC (Session Timeout)
    // First, get the timeout setting (Default to 30 mins if table doesn't exist yet)
    $timeout_minutes = 30; 
    try {
        $sys_check = $conn->query("SELECT session_timeout FROM system_settings WHERE id = 1 LIMIT 1");
        if ($sys_check && $sys_check->num_rows > 0) {
            $sys_data = $sys_check->fetch_assoc();
            $timeout_minutes = intval($sys_data['session_timeout']);
        }
    } catch (Throwable $e) { /* Ignore if table not created yet */ }

    $timeout_seconds = $timeout_minutes * 60;

    // Server-side check if they have been inactive for too long
    if (isset($_SESSION['last_activity'])) {
        $inactive_time = time() - $_SESSION['last_activity'];
        if ($inactive_time >= $timeout_seconds) {
            // They timed out! Destroy session and kick them out.
            session_unset();
            session_destroy();
            // Redirect to login with a timeout message
            header("Location: ../admin_login.php?error=timeout");
            exit;
        }
    }
    // Update their last activity time to NOW (because they just loaded a page)
    $_SESSION['last_activity'] = time();
}
// ============================================================

// 1. Get current page name to highlight the active link
$currentPage = basename($_SERVER['PHP_SELF']);

// 2. PATH CORRECTION LOGIC
if (strpos($_SERVER['PHP_SELF'], '/pages/') !== false) {
    $path_to_pages = '';            
    $path_to_admin_root = '../';    
    $path_to_components = '../';    
} else {
    $path_to_pages = 'pages/';
    $path_to_admin_root = '';
    $path_to_components = '';
}
?>

<?php 
$show_splash = false;
// 1. Check if they just logged in (via the Cookie we set in admin_verify.php)
if (isset($_COOKIE['ff_show_splash'])) {
    $show_splash = true;
    // Delete it instantly so it doesn't replay when they refresh the page
    setcookie("ff_show_splash", "", time() - 3600, "/"); 
}
// 2. Also check for the manual session (For Logout)
if (isset($_SESSION['welcome_splash'])) {
    $show_splash = true;
    unset($_SESSION['welcome_splash']);
}
?>

<div id="ff-preloader" class="bg-white dark:bg-slate-900" style="<?php echo $show_splash ? 'display: flex;' : 'display: none;'; ?> position: fixed; top: 0; left: 0; width: 100%; height: 100vh; justify-content: center; align-items: center; z-index: 999999; transition: opacity 0.6s ease-out, visibility 0.6s ease-out;">
    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; position: relative; width: 100%; height: 100%;">
        
        <video id="lightVideo" class="splash-video block dark:hidden" muted playsinline <?php echo $show_splash ? 'autoplay' : ''; ?>>
            <source src="/admin_components/assets/img/whitelogobg3sec.MP4" type="video/mp4">
        </video>

        <video id="darkVideo" class="splash-video hidden dark:block" muted playsinline <?php echo $show_splash ? 'autoplay' : ''; ?>>
            <source src="/admin_components/assets/img/blacklogobg3sec.MP4" type="video/mp4">
        </video>

    </div>
</div>

<style>
    /* PRELOADER STYLES */
    .preloader-hidden { opacity: 0 !important; visibility: hidden !important; }
    .splash-video { width: 800px; max-width: 90vw; border-radius: 10px; }
</style>

<script>
    // ----------------------------------------------------
    // SCENARIO A: THEY JUST LOGGED IN (Hide after 3s)
    // ----------------------------------------------------
    <?php if ($show_splash): ?>
    window.addEventListener('load', function() {
        const preloader = document.getElementById('ff-preloader');
        const lightVideo = document.getElementById('lightVideo');
        const darkVideo = document.getElementById('darkVideo');
        
        // Ensure videos actually play
        if(lightVideo) lightVideo.play();
        if(darkVideo) darkVideo.play();

        // Wait 3 seconds for the video
        setTimeout(() => {
            if (preloader) preloader.classList.add('preloader-hidden');
            setTimeout(() => { preloader.style.display = 'none'; }, 600);
        }, 3000); 
    });
    <?php endif; ?>
</script>
<link rel="icon" type="image/jpeg" href="/admin_components/assets/img/tabicon4.png">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&display=swap" rel="stylesheet">

<style>
    :root {
        --brand-green: #1E3A1D;
        --brand-cream: #F8F5EE;
        --brand-gold: #D4AF37;
    }

    #sidebar {
        font-family: 'Roboto Mono', monospace;
        background-color: var(--brand-green);
        transition: width 0.3s ease-in-out, background-color 0.3s ease, border-color 0.3s ease;
        box-shadow: 4px 0 15px rgba(0,0,0,0.2);
        overflow-x: hidden;
        white-space: nowrap;
        display: flex;
        flex-direction: column;
        height: 100vh;
        position: fixed;
        top: 0;
        left: 0;
        z-index: 50;
    }

    #sidebar-nav::-webkit-scrollbar { display: none; }
    #sidebar-nav { -ms-overflow-style: none; scrollbar-width: none; overflow-y: auto; }

    /* DEFAULT SIZES */
    .sidebar-collapsed { width: 5rem; } 
    .sidebar-expanded { width: 17rem; } 

    #burgerBtn svg { transition: transform 0.3s ease; }
    .sidebar-expanded #burgerBtn svg { transform: rotate(180deg); }

    .sidebar-link {
        color: #aebfab;
        display: flex;
        align-items: center;
        padding: 0.75rem 1rem;
        margin-bottom: 0.25rem;
        border-radius: 0.5rem;
        transition: all 0.2s ease;
        text-decoration: none;
        height: 3rem;
        flex-shrink: 0;
    }

    .sidebar-link:hover {
        background-color: rgba(255, 255, 255, 0.08);
        color: #ffffff;
        padding-left: 1.25rem;
    }

    .sidebar-link.active {
        background-color: var(--brand-cream);
        color: var(--brand-green);
        font-weight: 700;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }
    
    .sidebar-link.active::before {
        content: '';
        position: absolute;
        left: 0;
        height: 2rem;
        width: 4px;
        background-color: var(--brand-gold);
        border-radius: 0 4px 4px 0;
    }

    .material-icons {
        font-size: 24px;
        min-width: 24px;
        display: flex;
        justify-content: center;
        align-items: center;
    }

    .nav-text {
        margin-left: 1rem;
        opacity: 0;
        transition: opacity 0.2s ease;
        visibility: hidden;
    }

    .sidebar-expanded .nav-text { opacity: 1; visibility: visible; }

    .brand-title, .section-label {
        opacity: 0;
        transition: opacity 0.2s;
        visibility: hidden;
    }
    .sidebar-expanded .brand-title, 
    .sidebar-expanded .section-label { opacity: 1; visibility: visible; }

    .section-label {
        font-size: 0.7rem;
        text-transform: uppercase;
        color: #6b8c6a;
        margin: 1rem 0 0.5rem 1.25rem;
        font-weight: bold;
        flex-shrink: 0;
    }
    
    .nav-separator {
        border-top: 1px solid rgba(255,255,255,0.1);
        margin: 0.5rem 0.5rem;
        flex-shrink: 0;
    }

    /* =========================================
       DARK MODE OVERRIDES
       ========================================= */
    .dark #sidebar {
        background-color: rgba(15, 23, 42, 0.98); 
        border-right: 1px solid #1e293b; 
        box-shadow: 4px 0 15px rgba(0,0,0,0.5);
    }
    .dark .sidebar-link {
        color: #94a3b8; 
    }
    .dark .sidebar-link:hover {
        background-color: rgba(30, 41, 59, 0.8); 
        color: #f8fafc;
    }
    .dark .sidebar-link.active {
        background-color: #1e293b; 
        color: #4ade80; 
        box-shadow: none;
    }
    .dark .sidebar-link.active::before {
        background-color: #4ade80; 
    }
    .dark .section-label {
        color: #475569; 
    }
    .dark .nav-separator {
        border-top: 1px solid #1e293b; 
    }

    /* =========================================
       CHATBOT UI (BUBBLE & DARK MODE UPGRADE)
       ========================================= */
    /* 1. THE FLOATING BUBBLE BUTTON */
    #chat-bubble-btn {
        position: fixed;
        bottom: 25px;
        right: 25px;
        width: 60px;
        height: 60px;
        border-radius: 50%;
        background-color: var(--brand-green);
        color: white;
        border: none;
        box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        cursor: pointer;
        z-index: 10000;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275), background-color 0.3s;
    }
    #chat-bubble-btn .material-icons {
        font-size: 30px;
        transition: transform 0.3s ease;
    }
    #chat-bubble-btn:hover {
        transform: scale(1.1);
        background-color: #152b14;
    }

    /* 2. THE CHAT WINDOW (Hidden by default) */
    #flex-bot { 
        position: fixed; 
        bottom: 100px; /* Floats above the bubble */
        right: 25px; 
        width: 340px; 
        border: 1px solid #ccc; 
        border-radius: 16px; 
        background: white; 
        box-shadow: 0 10px 30px rgba(0,0,0,0.15); 
        font-family: 'Roboto Mono', monospace; 
        z-index: 9999; 
        display: flex; 
        flex-direction: column; 
        overflow: hidden;
        
        /* Animation properties */
        opacity: 0;
        visibility: hidden;
        transform: translateY(20px) scale(0.95);
        transform-origin: bottom right;
        transition: opacity 0.3s ease, transform 0.3s ease, visibility 0.3s ease;
    }
    
    /* When active, it pops up! */
    #flex-bot.show-bot {
        opacity: 1;
        visibility: visible;
        transform: translateY(0) scale(1);
    }

    #chat-header { 
        background: var(--brand-green); 
        color: var(--brand-gold); 
        padding: 15px; 
        font-weight: bold; 
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .header-title { display: flex; align-items: center; gap: 8px; }
    
    #close-chat-btn {
        background: none;
        border: none;
        color: var(--brand-gold);
        cursor: pointer;
        display: flex;
        align-items: center;
        transition: transform 0.2s;
    }
    #close-chat-btn:hover { transform: rotate(90deg); }

    #chat-box { 
        height: 380px; 
        overflow-y: auto; 
        padding: 15px; 
        display: flex; 
        flex-direction: column; 
        gap: 12px; 
        background: var(--brand-cream); 
    }
    .msg { 
        padding: 12px 16px; 
        border-radius: 18px; 
        max-width: 85%; 
        font-size: 13px; 
        line-height: 1.5;
        font-family: sans-serif;
    }
    .user-msg { 
        background: var(--brand-green); 
        color: white; 
        align-self: flex-end; 
        border-bottom-right-radius: 4px;
    }
    .bot-msg { 
        background: white; 
        color: #333; 
        align-self: flex-start; 
        border: 1px solid #e0e0e0;
        border-bottom-left-radius: 4px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }
    #chat-input-area { 
        display: flex; 
        padding: 12px; 
        background: white;
        border-top: 1px solid #eee; 
    }
    #chat-input { 
        flex: 1; 
        padding: 12px; 
        border: 1px solid #ccc; 
        border-radius: 20px; 
        outline: none; 
        font-family: sans-serif;
        font-size: 13px;
        transition: border-color 0.2s;
    }
    #chat-input:focus { border-color: var(--brand-green); }
    #send-btn { 
        background: var(--brand-green); 
        color: var(--brand-gold); 
        border: none; 
        width: 40px;
        height: 40px;
        border-radius: 50%; 
        margin-left: 8px; 
        cursor: pointer; 
        display: flex;
        align-items: center;
        justify-content: center;
        transition: 0.2s;
    }
    #send-btn:hover { background: #152b14; transform: scale(1.05); }
    
    /* Stop Button Styling */
    #stop-btn {
        background: #ef4444; /* Clean Red */
        color: white;
        border: none;
        width: 38px;
        height: 38px;
        border-radius: 50%;
        display: none; /* Hidden by default */
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: 0.2s;
        flex-shrink: 0;
    }
    #stop-btn:hover { background: #dc2626; }

    /* =========================================
       3. DARK MODE CHAT SUPPORT
       ========================================= */
    .dark #chat-bubble-btn { background-color: #4ade80; color: #0f172a; }
    .dark #chat-bubble-btn:hover { background-color: #22c55e; }
    
    .dark #flex-bot { 
        background: #1e293b; 
        border-color: #334155; 
        box-shadow: 0 10px 30px rgba(0,0,0,0.5); 
    }
    .dark #chat-header { 
        background: #0f172a; 
        color: #4ade80; 
        border-bottom: 1px solid #334155; 
    }
    .dark #close-chat-btn { color: #4ade80; }
    .dark #chat-box { background: #1e293b; }
    
    .dark .bot-msg { 
        background: #334155; 
        color: #f8fafc; 
        border-color: #475569; 
    }
    .dark .user-msg { 
        background: #4ade80; 
        color: #0f172a; 
    }
    
    .dark #chat-input-area { 
        background: #1e293b; 
        border-top: 1px solid #334155; 
    }
    .dark #chat-input { 
        background: #0f172a; 
        color: #f8fafc; 
        border-color: #475569; 
    }
    .dark #chat-input:focus { border-color: #4ade80; }
    .dark #send-btn { background: #4ade80; color: #0f172a; }
    .dark #send-btn:hover { background: #22c55e; }

    @keyframes spin { 100% { transform: rotate(360deg); } }
    /* =========================================
       4. ANTI-OVERLAP FIX (Scroll Clearance)
       ========================================= */
    /* Adds invisible space to the bottom of all pages so pagination clears the button */
    body {
        padding-bottom: 90px !important;
    }
    
/* =========================================
       CHATBOT UI POLISH (CHIPS & CUSTOM SCROLLBAR)
       ========================================= */
    #chat-chips {
        display: flex;
        flex-wrap: nowrap;
        gap: 8px;
        padding: 8px 12px 14px 12px !important; /* Extra bottom padding for scrollbar */
        background: white;
        overflow-x: auto;
        border-top: 1px solid #eee;
        
        /* FIREFOX SUPPORT */
        scrollbar-width: thin !important; 
        scrollbar-color: rgba(0,0,0,0.15) transparent !important;
    }
    
    /* CHROME / EDGE / SAFARI SUPPORT */
    #chat-chips::-webkit-scrollbar { 
        height: 6px !important; 
        display: block !important;
    }
    #chat-chips::-webkit-scrollbar-track { 
        background: transparent !important; 
    }
    #chat-chips::-webkit-scrollbar-thumb { 
        background-color: rgba(0,0,0,0.15) !important; 
        border-radius: 10px !important; 
    }
    
    .chip {
        background: #f1f5f9;
        color: var(--brand-green);
        border: 1px solid #e2e8f0;
        padding: 6px 12px;
        border-radius: 14px;
        font-size: 11px;
        font-weight: bold;
        cursor: pointer;
        white-space: nowrap;
        transition: 0.2s;
        flex-shrink: 0 !important; /* Stops text from squishing */
        width: max-content;
    }
    .chip:hover { background: var(--brand-green); color: var(--brand-gold); }

    /* Dark Mode Overrides */
    .dark #chat-chips { 
        background: #1e293b; 
        border-color: #334155; 
        /* FIREFOX DARK MODE */
        scrollbar-color: rgba(255,255,255,0.2) transparent !important;
    }
    .dark .chip { background: #334155; color: #4ade80; border-color: #475569; }
    .dark .chip:hover { background: #4ade80; color: #0f172a; }
    
    /* CHROME DARK MODE */
    .dark #chat-chips::-webkit-scrollbar-thumb { 
        background-color: rgba(255,255,255,0.2) !important; 
    }
    
    /* Custom Scrollbar for Chat Box */
#chat-box::-webkit-scrollbar {
    width: 6px;
}
#chat-box::-webkit-scrollbar-track {
    background: transparent;
}
#chat-box::-webkit-scrollbar-thumb {
    background-color: rgba(0,0,0,0.15);
    border-radius: 10px;
}
.dark #chat-box::-webkit-scrollbar-thumb {
    background-color: rgba(255,255,255,0.2);
}

/* =========================================
       BOUNCING DOTS ANIMATION (Thinking state)
       ========================================= */
    .typing-indicator {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
        height: 14px;
        padding: 2px 4px;
    }
    .typing-dot {
        width: 6px;
        height: 6px;
        background-color: #6b8c6a;
        border-radius: 50%;
        animation: bounce 1.4s infinite ease-in-out both;
    }
    .dark .typing-dot {
        background-color: #4ade80; 
    }
    .typing-dot:nth-child(1) { animation-delay: -0.32s; }
    .typing-dot:nth-child(2) { animation-delay: -0.16s; }

    @keyframes bounce {
        0%, 80%, 100% { transform: scale(0); }
        40% { transform: scale(1); }
    }
    
    /* =========================================
       UPGRADED INPUT FOCUS GLOW
       ========================================= */
    #chat-input:focus { 
        border-color: var(--brand-green) !important; 
        box-shadow: 0 0 0 3px rgba(30, 58, 29, 0.15) !important; 
        outline: none !important;
    }
    
    .dark #chat-input:focus { 
        border-color: #4ade80 !important; 
        box-shadow: 0 0 0 3px rgba(74, 222, 128, 0.15) !important; 
        outline: none !important;
    }
    
    /* ========================================= */
    /* COLLAPSED SIDEBAR TOOLTIPS (Premium UI)  */
    /* ========================================= */
    /* THE FIX: Drop the invisible wall so tooltips can pop out! */
    /* THE FIX: Drop ALL invisible walls so tooltips can pop out! */
    #sidebar.sidebar-collapsed,
    #sidebar.sidebar-collapsed #sidebar-nav {
        overflow: visible !important; 
    }
    
    /* Prepare the links to hold the tooltip */
    #sidebar.sidebar-collapsed a {
        position: relative;
        overflow: visible !important;
    }
    /* Prepare the links to hold the tooltip */
    #sidebar.sidebar-collapsed a {
        position: relative;
        overflow: visible !important; /* Ensure the tooltip can break out of the sidebar */
    }

    /* The Tooltip Box */
    #sidebar.sidebar-collapsed a::after {
        content: attr(data-tooltip); /* Grabs the text from the HTML attribute */
        position: absolute;
        left: 100%; /* Position it to the right of the icon */
        top: 50%;
        transform: translateY(-50%);
        background-color: #1f2937; /* Sleek dark gray */
        color: #ffffff;
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 500;
        white-space: nowrap;
        opacity: 0;
        visibility: hidden;
        transition: all 0.2s ease-in-out;
        z-index: 9999;
        pointer-events: none; /* Stops the tooltip from glitching the mouse hover */
        box-shadow: 0px 4px 6px rgba(0, 0, 0, 0.1);
        margin-left: 10px; /* Starting position */
    }

    /* The Little Triangle Arrow */
    #sidebar.sidebar-collapsed a::before {
        content: '';
        position: absolute;
        left: 100%;
        top: 50%;
        transform: translateY(-50%);
        border-width: 5px;
        border-style: solid;
        border-color: transparent #1f2937 transparent transparent;
        opacity: 0;
        visibility: hidden;
        transition: all 0.2s ease-in-out;
        z-index: 9999;
        pointer-events: none;
        margin-left: 0px; /* Starting position */
    }

    /* Animate and show on hover (Only when collapsed!) */
    #sidebar.sidebar-collapsed a:hover::after {
        opacity: 1;
        visibility: visible;
        margin-left: 15px; /* Slides in slightly to the right */
    }
    
    #sidebar.sidebar-collapsed a:hover::before {
        opacity: 1;
        visibility: visible;
        margin-left: 5px; /* Slides in slightly to the right */
    }
</style>

<aside id="sidebar" class="sidebar-collapsed text-slate-400">
    
    <div class="flex items-center p-4 mb-2 flex-shrink-0">
        <button id="burgerBtn" class="text-gray-300 hover:text-white focus:outline-none p-1 rounded-md hover:bg-white/10 dark:hover:bg-slate-800 transition-colors">
            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
        </button>
        <h1 class="brand-title text-xl font-bold ml-3 text-white tracking-tighter overflow-hidden">FRESHFLOW</h1>
    </div>

<nav id="sidebar-nav" class="flex flex-col flex-grow px-2 space-y-1">
        <?php
        if (!function_exists('create_nav_link')) {
            function create_nav_link($page, $title, $icon_name) {
                global $path_to_pages, $currentPage;
                $isActive = ($currentPage == $page);
                $activeClass = $isActive ? 'active' : '';
                $href = "{$path_to_pages}{$page}";
                echo "
                <a href='{$href}' class='sidebar-link relative {$activeClass}' data-tooltip='{$title}'>
                    <span class='material-icons'>{$icon_name}</span>
                    <span class='nav-text'>{$title}</span>
                </a>";
            }
        }

        // Determine the user's role early so we can filter the menu
        $user_role = strtolower($_SESSION['role_name'] ?? '');

        // ==========================================
        // EVERYONE GETS THIS (Staff, Admin, Super Admin)
        // ==========================================
        
        // 1. DASHBOARD
        create_nav_link('dashboard.php', 'Dashboard', 'dashboard');
        echo '<div class="nav-separator"></div>'; 
        
        // 2. ORDER FULFILLMENT (Staff needs this to pack and ship boxes)
        echo '<div class="section-label">Order Fulfillment</div>';
        create_nav_link('order_create.php', 'Create Order', 'add_shopping_cart'); 
        create_nav_link('order_queue.php', 'Order Queue', 'checklist_rtl'); 
        create_nav_link('dispatch.php', 'Dispatch / Delivery', 'local_shipping'); 
        create_nav_link('returns.php', 'Returns & Rejects', 'assignment_return');
        echo '<div class="nav-separator"></div>'; 

        // 3. CLIENTS (Staff needs this to print shipping labels/addresses)
        echo '<div class="section-label">Clients</div>';
        create_nav_link('clients.php', 'Client Directory', 'business');
        echo '<div class="nav-separator"></div>'; 

        // 4. INVENTORY (Staff needs to see what is in stock and log broken items)
        echo '<div class="section-label">Inventory</div>';
        create_nav_link('products.php', 'Product List', 'inventory_2');
        create_nav_link('categories.php', 'Categories', 'category'); 
        create_nav_link('inventory.php', 'Stock Management', 'warehouse');
        create_nav_link('spoilage.php', 'Log Spoilage', 'delete_sweep'); 
        echo '<div class="nav-separator"></div>'; 

// ==========================================
        // ONLY ADMINS & SUPER ADMINS (Managers & Owners)
        // ==========================================
        if ($user_role === 'admin' || $user_role === 'super admin') {
            
            // 5. SUPPLY CHAIN (Managers order from suppliers)
            echo '<div class="section-label">Supply Chain</div>';
            create_nav_link('purchase_orders.php', 'Purchase Orders', 'shopping_cart'); 
            create_nav_link('supplier_list.php', 'Supplier List', 'storefront'); 
            echo '<div class="nav-separator"></div>'; 

            // 6. MANAGEMENT (Managers handle daily money and planning)
            echo '<div class="section-label">Management</div>';
            create_nav_link('invoices.php', 'Invoices', 'receipt_long'); 
            create_nav_link('expenses.php', 'Expenses', 'money_off'); 
            create_nav_link('analytics_seasonal.php', 'Seasonal Planner', 'calendar_month'); 
            echo '<div class="nav-separator"></div>'; 

            // 7. SYSTEM SECURITY (Managers hire staff and check logs)
            echo '<div class="section-label">System Security</div>';
            create_nav_link('users.php', 'User Management', 'group'); 
            create_nav_link('audit_logs.php', 'Audit Logs', 'history');
            echo '<div class="nav-separator"></div>'; 
        }

        // ==========================================
        // ONLY SUPER ADMIN (The Owner's Eyes Only)
        // ==========================================
        if ($user_role === 'super admin') {
            
            // 8. EXECUTIVE FINANCE (Only owner sees total company cash flow)
            echo '<div class="section-label">Executive</div>';
            create_nav_link('cashflow.php', 'Company Cashflow', 'account_balance_wallet'); 

            // 9. AI SECRETS (Only owner accesses predictive algorithms)
            create_nav_link('smart_pricing.php', 'Smart Pricing AI', 'auto_awesome'); 
            create_nav_link('forecast.php', 'Sales Forecast (AI)', 'trending_up'); 
        }
        ?>
    </nav>
    
    <div class="p-2 border-t border-white/10 dark:border-slate-800 mt-auto mb-4 flex-shrink-0">
        <?php create_nav_link('settings.php', 'Settings', 'settings'); ?>
        <a href="<?php echo $path_to_components; ?>logout.php" class="sidebar-link relative hover:bg-red-900/30 dark:hover:bg-red-900/40 hover:text-red-300 dark:hover:text-red-400" data-tooltip="Logout">
            <span class="material-icons">logout</span>
            <span class="nav-text">Logout</span>
        </a>
    </div>
</aside>

<div id="timeoutWarningModal" style="display: none;" class="fixed inset-0 z-[9999] bg-black bg-opacity-80 hidden flex items-center justify-center backdrop-blur-sm transition-opacity">
    <div class="bg-white dark:bg-slate-900 dark:border dark:border-slate-800 rounded-2xl shadow-2xl overflow-hidden w-full max-w-sm flex flex-col items-center p-8 text-center transform scale-105 transition-transform">
        <div class="w-20 h-20 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center mb-4 animate-pulse">
            <span class="material-icons text-red-600 dark:text-red-500 text-4xl">hourglass_empty</span>
        </div>
        <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-2 tracking-tight">Are you still there?</h2>
        <p class="text-sm text-gray-500 dark:text-slate-400 mb-6 leading-relaxed">
            You have been inactive for a while. For your security, you will be automatically logged out in 
            <span id="timeoutCountdown" class="text-4xl text-red-600 dark:text-red-500 font-bold block mt-3 mb-1">30</span> seconds.
        </p>
        
        <button onclick="stayLoggedIn()" class="w-full bg-[#1E3A1D] dark:bg-green-700 text-white px-6 py-3.5 rounded-lg text-sm font-bold shadow-md hover:bg-[#2a4e29] dark:hover:bg-green-600 transition transform active:scale-95 flex items-center justify-center gap-2">
            <span class="material-icons text-sm">touch_app</span> Stay Logged In
        </button>
    </div>
</div>

<button id="chat-bubble-btn" onclick="toggleChat()">
    <span class="material-icons" id="bubble-icon">chat</span>
</button>

<div id="flex-bot">
  <div id="chat-header">
      <div class="header-title">
          <span class="material-icons" style="font-size: 20px;">smart_toy</span> 
          FreshFlow AI
      </div>
      <div style="display: flex; gap: 4px; align-items: center;">
          <button onclick="clearChat()" title="Clear AI Memory" style="background: none; border: none; color: var(--brand-gold); cursor: pointer; display: flex;">
              <span class="material-icons" style="font-size: 20px; transition: 0.2s;">delete_sweep</span>
          </button>
          <button id="close-chat-btn" onclick="toggleChat()">
              <span class="material-icons">close</span>
          </button>
      </div>
  </div>
  
  <div id="chat-box">
    <div class="msg bot-msg">Hello! Ask me about expiring items or low stock. 🥬📊</div>
  </div>
  
  <div id="chat-chips">
      <button class="chip" onclick="sendChip('What is expiring soon?')">Expiring Soon</button>
      <button class="chip" onclick="sendChip('Show low stock items')">Low Stock Alerts</button>
      <button class="chip" onclick="sendChip('How many total users do we have?')">Total Users</button>
  </div>

  <div id="chat-input-area">
    <input type="text" id="chat-input" placeholder="Type your message...">
    <button id="send-btn" onclick="sendMessage()"><span class="material-icons">send</span></button>
<button id="stop-btn" onclick="stopChat()"><span class="material-icons">stop</span></button>
    </button>
  </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const burgerBtn = document.getElementById('burgerBtn');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('main-content') || document.querySelector('.ml-20, .ml-\\[17rem\\]');

        if(burgerBtn && sidebar) {
            burgerBtn.addEventListener('click', () => {
                if (sidebar.classList.contains('sidebar-collapsed')) {
                    sidebar.classList.replace('sidebar-collapsed', 'sidebar-expanded');
                    if (mainContent) mainContent.classList.replace('ml-20', 'ml-[17rem]');
                } else {
                    sidebar.classList.replace('sidebar-expanded', 'sidebar-collapsed');
                    if (mainContent) mainContent.classList.replace('ml-[17rem]', 'ml-20');
                }
            });
        }

        const sidebarNav = document.getElementById('sidebar-nav');
        if (sidebarNav) {
            const savedScroll = sessionStorage.getItem('sidebarScrollPos');
            if (savedScroll) sidebarNav.scrollTop = parseInt(savedScroll, 10);
            window.addEventListener('beforeunload', () => {
                sessionStorage.setItem('sidebarScrollPos', sidebarNav.scrollTop);
            });
        }
    });

    // ============================================================
    //  UPGRADED LIVE COUNTDOWN & SMART MULTI-TAB SYNC LOGIC
    // ============================================================
    let lastActivityTime = Date.now();
    
    // Set initial activity globally so all tabs start on the same page
    localStorage.setItem('freshflow_last_activity', lastActivityTime.toString()); 
    
    // Subtracts 10 seconds to guarantee the browser beats the server's strict timer
    const maxIdleTimeMs = (<?php echo isset($timeout_seconds) ? $timeout_seconds : 1800; ?> - 10) * 1000; 
    const warningTimeMs = 30 * 1000; 
    let isWarningShowing = false;

    // 1. Listen for flares (Logouts AND Activity Resets) from other tabs
    window.addEventListener('storage', function(event) {
        if (event.key === 'freshflow_logout') {
            const logoutReason = event.newValue; 
            if (logoutReason && logoutReason.includes('timeout')) {
                window.location.replace('<?php echo $path_to_admin_root; ?>admin_login.php?error=timeout'); 
            } else {
                window.location.replace('<?php echo $path_to_admin_root; ?>admin_login.php?status=loggedout'); 
            }
        }
        
        // NEW: Listen for activity on other tabs!
        if (event.key === 'freshflow_last_activity') {
            let globalActivity = parseInt(event.newValue);
            if (globalActivity > lastActivityTime) {
                lastActivityTime = globalActivity; // Reset this tab's timer
                
                // If this tab was showing the warning, but another tab was clicked, hide the warning!
                if (isWarningShowing) {
                    isWarningShowing = false;
                    const modal = document.getElementById('timeoutWarningModal');
                    if (modal) modal.classList.add('hidden');
                }
            }
        }
    });

    // ----------------------------------------------------
    // SCENARIO B: THEY CLICKED LOGOUT (Play video, then leave)
    // ----------------------------------------------------
    document.querySelectorAll('a[href*="logout.php"]').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault(); 
            const logoutUrl = this.href; 
            
            // Send the manual flare to all other tabs FIRST
            localStorage.setItem('freshflow_logout', 'manual_' + Date.now());
            
            // === SHOW THE LOGOUT VIDEO SPLASH SCREEN ===
            const preloader = document.getElementById('ff-preloader');
            const lightVideo = document.getElementById('lightVideo');
            const darkVideo = document.getElementById('darkVideo');
            
            if (preloader) {
                preloader.style.display = 'flex';
                
                // Small delay to ensure display registers before removing hidden class
                setTimeout(() => { 
                    preloader.classList.remove('preloader-hidden'); 
                    
                    // Reset and Play the videos from the beginning
                    if(lightVideo) { lightVideo.currentTime = 0; lightVideo.play(); }
                    if(darkVideo) { darkVideo.currentTime = 0; darkVideo.play(); }
                }, 10);
            }
            
            // Wait exactly 3 seconds for the MP4 video to finish, THEN log them out
            setTimeout(() => { window.location.href = logoutUrl; }, 3000);
        });
    });

    // 3. Reset the timestamp when the user moves the mouse
    function resetTimer() {
        if (!isWarningShowing) {
            let now = Date.now();
            lastActivityTime = now;
            
            // Send activity flare to other tabs (throttled to once every 2 seconds to prevent spam)
            if (now - (parseInt(localStorage.getItem('freshflow_last_activity')) || 0) > 2000) {
                localStorage.setItem('freshflow_last_activity', now.toString());
            }
        }
    }

    ['mousemove', 'mousedown', 'keypress', 'touchmove', 'scroll'].forEach(ev => 
        document.addEventListener(ev, resetTimer, { passive: true })
    );

    // 4. Check the actual system clock every second
    setInterval(function checkIdleTime() {
        // Double check local storage just in case the tab was asleep and missed the event
        let globalActivity = parseInt(localStorage.getItem('freshflow_last_activity')) || lastActivityTime;
        if (globalActivity > lastActivityTime) {
            lastActivityTime = globalActivity;
            if (isWarningShowing) {
                isWarningShowing = false;
                const modal = document.getElementById('timeoutWarningModal');
                if (modal) modal.classList.add('hidden');
            }
        }

        let timeIdleMs = Date.now() - lastActivityTime;
        let timeLeftMs = maxIdleTimeMs - timeIdleMs;

        if (timeLeftMs <= warningTimeMs && timeLeftMs > 0) {
            isWarningShowing = true;
            const modal = document.getElementById('timeoutWarningModal');
            if (modal) {
                modal.classList.remove('hidden');
                document.getElementById('timeoutCountdown').innerText = Math.ceil(timeLeftMs / 1000);
            }
        } else if (timeLeftMs <= 0) {
            localStorage.setItem('freshflow_logout', 'timeout_' + Date.now()); 
            window.location.replace('<?php echo isset($path_to_components) ? $path_to_components : ""; ?>logout.php?timeout=1');
        }
    }, 1000);

    function stayLoggedIn() {
        let now = Date.now();
        lastActivityTime = now;
        
        // NEW: Instantly tell all other tabs "I AM STILL HERE! HIDE YOUR WARNINGS!"
        localStorage.setItem('freshflow_last_activity', now.toString()); 
        
        isWarningShowing = false;
        const modal = document.getElementById('timeoutWarningModal');
        if (modal) modal.classList.add('hidden');
        
        fetch(window.location.href, { method: 'HEAD' }).catch(() => {});
    }

    // ============================================================
    //  CHATBOT JAVASCRIPT LOGIC (UPGRADED: MEMORY + TYPEWRITER)
    // ============================================================
    
    // 1. Memory Functions (Fixes the Disappearing Chat)
    function saveChatMemory() {
        const chatBox = document.getElementById("chat-box");
        localStorage.setItem("freshflow_chat_memory", chatBox.innerHTML);
    }

    function loadChatMemory() {
        const chatBox = document.getElementById("chat-box");
        const savedChat = localStorage.getItem("freshflow_chat_memory");
        if (savedChat) {
            chatBox.innerHTML = savedChat;
            chatBox.scrollTop = chatBox.scrollHeight;
        }
    }

    // Load memory as soon as the page loads!
    document.addEventListener('DOMContentLoaded', loadChatMemory);

// Toggle Chat Window Visibility
    function toggleChat() {
        const botWindow = document.getElementById('flex-bot');
        const bubbleIcon = document.getElementById('bubble-icon');
        const inputField = document.getElementById('chat-input');
        
        botWindow.classList.toggle('show-bot');
        
        if (botWindow.classList.contains('show-bot')) {
            bubbleIcon.innerText = 'keyboard_arrow_down';
            inputField.focus();
        } else {
            bubbleIcon.innerText = 'chat';
        }
    }

    // ==========================================
    // THE MISSING CHIP BUTTON LOGIC
    // ==========================================
    function sendChip(text) {
        document.getElementById("chat-input").value = text;
        sendMessage();
    }

    // Send Message Logic (Fixes the Instant Text Dump)
    // ==========================================
    // CHATBOT LOGIC & STATE (Upgraded with Stop Button)
    // ==========================================
    let charIndex = 0;
    let currentBotMessage = "";
    let currentMsgId = "";
    let currentTypingTimer = null; // Tracks typing speed
    let currentAbortController = null; // Tracks server request

    function stopChat() {
        // 1. Abort the server request if it's still "Thinking..."
        if (currentAbortController) currentAbortController.abort();
        
        // 2. Halt the typewriter effect
        if (currentTypingTimer) clearTimeout(currentTypingTimer);
        
        // 3. Reset the UI Buttons
        document.getElementById('send-btn').style.display = 'flex';
        document.getElementById('stop-btn').style.display = 'none';
        document.getElementById('chat-input').disabled = false;
        document.getElementById('chat-input').focus();

        // 4. Remove bouncing dots
        let thinkingDots = document.querySelector('.typing-indicator');
        if (thinkingDots) {
            let botMsgContainer = thinkingDots.closest('.msg.bot-msg');
            if (botMsgContainer) botMsgContainer.remove();
        }
        
        // 5. Save whatever text was already typed to local storage memory!
        saveChatMemory(); 
    }

    async function sendMessage() {
        let inputField = document.getElementById("chat-input");
        let message = inputField.value.trim();
        if (!message) return;

        let chatBox = document.getElementById("chat-box");
        
        // Toggle Buttons: Hide Send, Show Stop
        document.getElementById('send-btn').style.display = 'none';
        document.getElementById('stop-btn').style.display = 'flex';
        inputField.disabled = true;

        // Append User Message & Save to memory
        chatBox.innerHTML += `<div class="msg user-msg">${message}</div>`;
        inputField.value = "";
        chatBox.scrollTop = chatBox.scrollHeight;
        saveChatMemory(); 

        // Add 'Thinking' Animation
        let typingId = "typing-" + Date.now();
        chatBox.innerHTML += `
            <div id="${typingId}" class="msg bot-msg">
                <div class="typing-indicator">
                    <div class="typing-dot"></div>
                    <div class="typing-dot"></div>
                    <div class="typing-dot"></div>
                </div>
            </div>`;
        chatBox.scrollTop = chatBox.scrollHeight;

        // Format data for PHP
        let formData = new URLSearchParams();
        formData.append("message", message);

        // Initialize the AbortController for the Stop Button
        currentAbortController = new AbortController();

        try {
            let response = await fetch("<?php echo $path_to_components; ?>chat_api.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: formData.toString(),
                signal: currentAbortController.signal // Link fetch to the Stop button
            });
            
            let text = await response.text();
            
            // Remove the typing indicator
            let typingElement = document.getElementById(typingId);
            if (typingElement) typingElement.remove();

            // Start the Typewriter Effect
            currentMsgId = "bot-" + Date.now();
            chatBox.innerHTML += `<div id="${currentMsgId}" class="msg bot-msg"></div>`;
            currentBotMessage = text;
            charIndex = 0;
            typeWriter();

        } catch (error) {
            if (error.name === 'AbortError') {
                console.log('Chat stopped by user.');
                return; // Exit silently because the user clicked Stop
            }
            let typingElement = document.getElementById(typingId);
            if (typingElement) typingElement.remove();
            chatBox.innerHTML += `<div class="msg bot-msg" style="color: red;">Connection error. Please try again.</div>`;
            stopChat(); // Reset UI on error
        }
    }

    function typeWriter() {
        if (charIndex < currentBotMessage.length) {
            let chatBox = document.getElementById("chat-box");
            let msgDiv = document.getElementById(currentMsgId);
            if(msgDiv) {
                // Handle HTML tags so links don't break
                if (currentBotMessage.charAt(charIndex) === '<') {
                    let tag = "";
                    while (currentBotMessage.charAt(charIndex) !== '>' && charIndex < currentBotMessage.length) {
                        tag += currentBotMessage.charAt(charIndex);
                        charIndex++;
                    }
                    tag += '>';
                    msgDiv.innerHTML += tag;
                    charIndex++;
                } else {
                    msgDiv.innerHTML += currentBotMessage.charAt(charIndex);
                    charIndex++;
                }
                chatBox.scrollTop = chatBox.scrollHeight;
                
                // Track the timer so the Stop button can cancel it
                currentTypingTimer = setTimeout(typeWriter, 15); 
            }
        } else {
            // Done typing naturally! Reset UI to original state.
            document.getElementById('send-btn').style.display = 'flex';
            document.getElementById('stop-btn').style.display = 'none';
            document.getElementById('chat-input').disabled = false;
            document.getElementById('chat-input').focus();
            
            // Save the finished AI response to local storage memory!
            saveChatMemory();
        }
    }
    // Tell the backend to clear the PHP Session memory AND local memory
    function clearChat() {
        let chatBox = document.getElementById("chat-box");
        
        chatBox.innerHTML = `
            <div class="msg bot-msg" style="display:flex; gap: 4px; align-items: center;">
                <span class="material-icons" style="font-size: 14px; animation: spin 2s linear infinite;">sync</span>
                Wiping memory...
            </div>`;
        
        fetch("<?php echo $path_to_components; ?>chat_api.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: "message=CLEAR_CHAT_HISTORY"
        })
        .then(response => response.text())
        .then(data => {
            chatBox.innerHTML = `<div class="msg bot-msg">${data}</div>`;
            localStorage.removeItem("freshflow_chat_memory"); // Wipe browser memory too!
        })
        .catch(error => {
            chatBox.innerHTML = `<div class="msg bot-msg">Error clearing memory.</div>`;
        });
    }

    // Press Enter to send
    document.getElementById("chat-input").addEventListener("keypress", function(event) {
        if (event.key === "Enter") {
            event.preventDefault();
            sendMessage();
        }
    });
    
    // ============================================================
    //  DRAGGABLE BUBBLE LOGIC (Premium Flex)
    // ============================================================
    const bubble = document.getElementById('chat-bubble-btn');
    const botWindow = document.getElementById('flex-bot');
    
    let isDragging = false;
    let startX, startY, initialX, initialY;

    // Mouse Events
    if(bubble) {
        bubble.addEventListener('mousedown', dragStart);
        document.addEventListener('mousemove', drag);
        document.addEventListener('mouseup', dragEnd);

        function dragStart(e) {
            initialX = e.clientX - bubble.getBoundingClientRect().left;
            initialY = e.clientY - bubble.getBoundingClientRect().top;
            isDragging = true;
            bubble.style.transition = 'none'; // Remove transition so it follows mouse instantly
        }

        function drag(e) {
            if (!isDragging) return;
            e.preventDefault();
            
            // Calculate new position
            let currentX = e.clientX - initialX;
            let currentY = e.clientY - initialY;

            // Safety Boundaries (Keep it inside the screen)
            const maxX = window.innerWidth - bubble.offsetWidth;
            const maxY = window.innerHeight - bubble.offsetHeight;
            
            currentX = Math.max(0, Math.min(currentX, maxX));
            currentY = Math.max(0, Math.min(currentY, maxY));

            bubble.style.left = currentX + 'px';
            bubble.style.top = currentY + 'px';
            bubble.style.right = 'auto'; // Disable default right/bottom CSS
            bubble.style.bottom = 'auto';

            // Make the chat window follow the bubble
            if(botWindow) {
                botWindow.style.right = (window.innerWidth - currentX - 60) + 'px';
                botWindow.style.bottom = (window.innerHeight - currentY + 10) + 'px';
            }
        }

        function dragEnd(e) {
            if (!isDragging) return;
            isDragging = false;
            bubble.style.transition = 'transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275), background-color 0.3s';
        }

        // Prevent chat from opening if you were just dragging it
        bubble.onclick = function(e) {
            // If the bubble was dragged, it moved. If it just clicked, it didn't move much.
            // We override the inline onclick="toggleChat()" here.
            toggleChat(); 
        };
    }
    
    
</script>