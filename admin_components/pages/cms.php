<?php
// --- SETUP & SECURITY ---
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Ensure this path correctly points to your database connection file
include_once '../../includes/db_connection.php';

// Security Check (Admin Role Required)
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !isset($_SESSION["role_name"]) || $_SESSION["role_name"] !== 'admin') {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Authentication required.']);
        exit;
    }
    header("location: ../admin_login.php");
    exit;
}

// --- DATABASE CONTENT RETRIEVAL FUNCTIONS ---

/**
 * Helper function to fetch single-row page content for Privacy/Terms.
 * Assumes 'title' and 'content' columns and id = 1.
 */
function fetch_single_content($conn, $table_name, $default_title, $default_content) {
    try {
        $stmt = $conn->prepare("SELECT title, content FROM {$table_name} WHERE id = 1 LIMIT 1");
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                return $result->fetch_assoc();
            }
        }
    } catch (Exception $e) {
        error_log("Database error fetching {$table_name}: " . $e->getMessage());
    }
    return ['title' => $default_title, 'content' => $default_content];
}

// --- INITIALIZE CONTENT ARRAYS ---

// 1. Fetch Simple Page Content
$page_contents = [
    'home' => ['title' => 'Manage Best-Selling Products', 'content' => 'Use the table below to edit, add, or remove best-selling products.'], 
    'privacy_policy' => fetch_single_content($conn, 'privacy_policy', 'Strict Privacy Policy', 'Full details about data collection and user rights are written here.'),
    'terms_conditions' => fetch_single_content($conn, 'terms_conditions', 'Terms and Conditions of Use', 'Detailed legal terms, warranties, and return policies are listed here.'),
];


// 2. Fetch About Us Items (List for about_us_content table)
$about_us_items = [];
try {
    $sql_au = "SELECT about_us_id, about_us_title, about_us_content, about_us_img FROM about_us_content ORDER BY about_us_id ASC";
    $result_au = $conn->query($sql_au);
    
    if ($result_au) {
        while ($row = $result_au->fetch_assoc()) {
            $about_us_items[] = [
                'id' => $row['about_us_id'],
                'title' => $row['about_us_title'],
                'content' => $row['about_us_content'],
                'img' => $row['about_us_img']
            ];
        }
    }
} catch (Exception $e) {
    error_log("Database error fetching About Us: " . $e->getMessage());
    $about_us_items[] = ['id' => 999, 'title' => 'DB Fetch Error', 'content' => 'Could not load content from database. Check schema.', 'img' => ''];
}


// 3. Fetch Best-Selling Items (List for best_selling table)
$best_selling_items = [];
try {
    $sql_bs = "SELECT bs_id, bs_logo, bs_product_name, bs_product_link, bs_product_img FROM best_selling ORDER BY bs_id ASC";
    $result_bs = $conn->query($sql_bs);
    
    if ($result_bs) {
        while ($row = $result_bs->fetch_assoc()) {
            $best_selling_items[] = $row;
        }
    }
} catch (Exception $e) {
    error_log("Database error fetching Best-Selling: " . $e->getMessage());
    $best_selling_items[] = ['bs_id' => 0, 'bs_product_name' => 'DB Fetch Error', 'bs_logo' => '', 'bs_product_link' => '', 'bs_product_img' => ''];
}


// 4. Fetch FAQ Items (List for faq_contents table)
$faq_items = [];
try {
    $sql_faq = "SELECT faq_id, faq_question, faq_answer FROM faq_contents ORDER BY faq_id ASC";
    $result_faq = $conn->query($sql_faq);
    
    if ($result_faq) {
        while ($row = $result_faq->fetch_assoc()) {
            $faq_items[] = [
                'id' => $row['faq_id'],
                'question' => $row['faq_question'],
                'answer' => $row['faq_answer'],
                'status' => 'Active' 
            ];
        }
    }
} catch (Exception $e) {
    error_log("Database error fetching FAQ: " . $e->getMessage());
    $faq_items[] = ['id' => 999, 'question' => 'DB Fetch Error', 'answer' => 'Could not load FAQ from database. Check schema.', 'status' => 'Draft'];
}


// --- FORM SUBMISSION HANDLER (DB SAVES, UPDATES, DELETES, INSERTS) ---

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $message = '';
    $message_class = 'bg-green-600';
    $target_tab = $_POST['page_type'] ?? 'home';
    $action = $_POST['action'] ?? ''; 
    
    // CONSOLIDATED ID READING: Get the ID regardless of which tab submitted the form
    $item_id = (int)($_POST['bs_id'] ?? $_POST['faq_id'] ?? $_POST['about_us_id'] ?? 0);

    // CRITICAL FIX: Handle 'edit_open' action immediately and exit for modal display
    if ($action === 'edit_open' && $item_id > 0) {
        if ($target_tab === 'home') {
             header("Location: " . $_SERVER['PHP_SELF'] . "?tab=home&edit_bs_id=$item_id");
             exit;
        } elseif ($target_tab === 'faq') {
             header("Location: " . $_SERVER['PHP_SELF'] . "?tab=faq&edit_id=$item_id");
             exit;
        } elseif ($target_tab === 'about_us') {
             header("Location: " . $_SERVER['PHP_SELF'] . "?tab=about_us&edit_au_id=$item_id");
             exit;
        }
    }


    // 1. Handle Best-Selling Actions (CRUD LOGIC - delete, save_edit, insert_new)
    if ($target_tab === 'home' && !empty($action)) {

        if ($action === 'delete' && $item_id > 0) {
            $stmt = $conn->prepare("DELETE FROM best_selling WHERE bs_id = ?");
            $stmt->bind_param("i", $item_id);
            if ($stmt->execute()) { $message = "Product item (ID: $item_id) successfully **deleted**."; $message_class = 'bg-red-600'; } 
            else { $message = "Error deleting product item: " . $conn->error; $message_class = 'bg-yellow-600'; }
            $stmt->close();
        } elseif ($action === 'save_edit' && $item_id > 0) {
            $name = $_POST['bs_product_name'] ?? '';
            $link = $_POST['bs_product_link'] ?? '';
            $logo = $_POST['bs_logo'] ?? '';
            $img = $_POST['bs_product_img'] ?? '';

            $stmt = $conn->prepare("UPDATE best_selling SET bs_product_name = ?, bs_product_link = ?, bs_logo = ?, bs_product_img = ? WHERE bs_id = ?");
            $stmt->bind_param("ssssi", $name, $link, $logo, $img, $item_id);
            
            if ($stmt->execute()) { $message = "Best-Selling item (ID: $item_id) successfully **updated**."; } 
            else { $message = "Error updating Best-Selling item: " . $conn->error; $message_class = 'bg-yellow-600'; }
            $stmt->close();
        } elseif ($action === 'insert_new') {
            $name = $_POST['bs_product_name'] ?? '';
            $link = $_POST['bs_product_link'] ?? '';
            $logo = $_POST['bs_logo'] ?? '';
            $img = $_POST['bs_product_img'] ?? '';

            $stmt = $conn->prepare("INSERT INTO best_selling (bs_product_name, bs_product_link, bs_logo, bs_product_img) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $name, $link, $logo, $img);
            
            if ($stmt->execute()) { $message = "New Best-Selling item successfully **added** (ID: {$conn->insert_id})."; } 
            else { $message = "Error inserting new Best-Selling item: " . $conn->error; $message_class = 'bg-yellow-600'; }
            $stmt->close();
        }

    // 2. Handle FAQ Actions (CRUD LOGIC - delete, save_edit, insert_new)
    } elseif ($target_tab === 'faq' && !empty($action)) {
        
        if ($action === 'delete' && $item_id > 0) {
            $stmt = $conn->prepare("DELETE FROM faq_contents WHERE faq_id = ?");
            $stmt->bind_param("i", $item_id);
            if ($stmt->execute()) { $message = "FAQ item (ID: $item_id) successfully **deleted**."; $message_class = 'bg-red-600'; } 
            else { $message = "Error deleting FAQ item: " . $conn->error; $message_class = 'bg-yellow-600'; }
            $stmt->close();
        } elseif ($action === 'save_edit' && $item_id > 0) {
            $new_question = $_POST['new_question'] ?? '';
            $new_answer = $_POST['new_answer'] ?? '';
            $stmt = $conn->prepare("UPDATE faq_contents SET faq_question = ?, faq_answer = ? WHERE faq_id = ?");
            $stmt->bind_param("ssi", $new_question, $new_answer, $item_id);
            if ($stmt->execute()) { $message = "FAQ item (ID: $item_id) successfully **updated**."; } 
            else { $message = "Error updating FAQ item: " . $conn->error; $message_class = 'bg-yellow-600'; }
            $stmt->close();
        } elseif ($action === 'insert_new') {
             $new_question = $_POST['new_question'] ?? '';
            $new_answer = $_POST['new_answer'] ?? '';
            $stmt = $conn->prepare("INSERT INTO faq_contents (faq_question, faq_answer) VALUES (?, ?)");
            $stmt->bind_param("ss", $new_question, $new_answer);
            if ($stmt->execute()) { $message = "New FAQ item successfully **added** (ID: {$conn->insert_id})."; } 
            else { $message = "Error inserting new FAQ item: " . $conn->error; $message_class = 'bg-yellow-600'; }
            $stmt->close();
        }
    }
    
    // 3. Handle About Us Actions (CRUD LOGIC - delete, save_edit, insert_new)
    elseif ($target_tab === 'about_us' && !empty($action)) {

        if ($action === 'delete' && $item_id > 0) {
            $stmt = $conn->prepare("DELETE FROM about_us_content WHERE about_us_id = ?");
            $stmt->bind_param("i", $item_id);
            if ($stmt->execute()) { $message = "About Us item (ID: $item_id) successfully **deleted**."; $message_class = 'bg-red-600'; } 
            else { $message = "Error deleting About Us item: " . $conn->error; $message_class = 'bg-yellow-600'; }
            $stmt->close();

        } elseif ($action === 'save_edit' && $item_id > 0) {
            $new_title = $_POST['new_title'] ?? '';
            $new_content = $_POST['new_content'] ?? '';
            $new_img = $_POST['new_img'] ?? '';
            
            $stmt = $conn->prepare("UPDATE about_us_content SET about_us_title = ?, about_us_content = ?, about_us_img = ? WHERE about_us_id = ?");
            $stmt->bind_param("sssi", $new_title, $new_content, $new_img, $item_id);
            
            if ($stmt->execute()) { $message = "About Us item (ID: $item_id) successfully **updated**."; } 
            else { $message = "Error updating About Us item: " . $conn->error; $message_class = 'bg-yellow-600'; }
            $stmt->close();
            
        } elseif ($action === 'insert_new') {
            $new_title = $_POST['new_title'] ?? '';
            $new_content = $_POST['new_content'] ?? '';
            $new_img = $_POST['new_img'] ?? '';
            
            $stmt = $conn->prepare("INSERT INTO about_us_content (about_us_title, about_us_content, about_us_img) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $new_title, $new_content, $new_img);
            
            if ($stmt->execute()) { $message = "New About Us item successfully **added** (ID: {$conn->insert_id})."; } 
            else { $message = "Error inserting new About Us item: " . $conn->error; $message_class = 'bg-yellow-600'; }
            $stmt->close();
        }
    }
    
    // 4. Handle Simple Page Saves (Privacy, Terms)
    elseif (isset($_POST['page_type']) && in_array($_POST['page_type'], ['privacy_policy', 'terms_conditions'])) {
        $page_type = $_POST['page_type'];
        $db_table = $page_type; 
        $title = $_POST[$page_type . '_title'] ?? '';
        $content = $_POST[$page_type . '_content'] ?? '';
        
        $stmt = $conn->prepare("UPDATE {$db_table} SET title = ?, content = ? WHERE id = 1");
        $stmt->bind_param("ss", $title, $content); 

        if ($stmt->execute()) { $message = "Content for **" . ucwords(str_replace('_', ' ', $page_type)) . "** has been successfully updated!"; } 
        else { $message = "Error updating content for {$db_table}: " . $conn->error; $message_class = 'bg-yellow-600'; }
        $stmt->close();
        $target_tab = $page_type;
    }

    // Set message and redirect
    if (!empty($message)) {
        $_SESSION['message'] = $message;
        $_SESSION['message_class'] = $message_class;
        header("Location: " . $_SERVER['PHP_SELF'] . "?tab=" . $target_tab);
        exit;
    }
}

// Clear message after display
$message = $_SESSION['message'] ?? '';
$message_class = $_SESSION['message_class'] ?? '';
unset($_SESSION['message'], $_SESSION['message_class']);

// Determine the active tab based on URL parameter or default
$active_tab = $_GET['tab'] ?? 'home';

// --- MODAL LOGIC (Variables for Conditional Rendering) ---

// 1. FAQ Modal Logic
$edit_faq_id = (int)($_GET['edit_id'] ?? 0);
$faq_to_edit = null;
if ($edit_faq_id) {
    foreach ($faq_items as $item) {
        // FIX: Explicitly cast the array ID to int for correct comparison with the int from $_GET
        if ((int)$item['id'] === $edit_faq_id) { 
            $faq_to_edit = $item;
            break;
        }
    }
}
$show_add_modal_faq = isset($_GET['add_new']) && $_GET['add_new'] === 'faq';

// 2. About Us Modal Logic 
$edit_au_id = (int)($_GET['edit_au_id'] ?? 0);
$au_to_edit = null;
if ($edit_au_id) {
    foreach ($about_us_items as $item) {
        // FIX: Explicitly cast the array ID to int for correct comparison with the int from $_GET
        if ((int)$item['id'] === $edit_au_id) {
            $au_to_edit = $item;
            break;
        }
    }
}
$show_add_modal_au = isset($_GET['add_new']) && $_GET['add_new'] === 'au';

// 3. Best Selling Modal Logic
$edit_bs_id = (int)($_GET['edit_bs_id'] ?? 0);
$bs_to_edit = null;
if ($edit_bs_id) {
    foreach ($best_selling_items as $item) {
        if ((int)$item['bs_id'] === $edit_bs_id) {
            $bs_to_edit = $item;
            break;
        }
    }
}
$show_add_modal_bs = isset($_GET['add_new']) && $_GET['add_new'] === 'bs';


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CMS Content Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        body.dot-texture-background {
            font-family: 'Inter', sans-serif;
            background-color: #000000;
            background-image: radial-gradient(circle, rgba(255, 255, 255, 0.07) 1px, transparent 1px);
            background-size: 12px 12px;
        }
        .font-heading { font-family: 'Roboto Mono', monospace; }
        .content-card {
            background-color: rgba(15, 23, 42, 0.5); 
            border: 1px solid rgba(51, 65, 85, 0.5); 
            backdrop-filter: blur(8px);
        }
        .tab-button {
            position: relative;
        }
        .tab-active {
            color: #ef4444; 
            border-bottom-color: #ef4444;
        }
        .required::after {
            content: ' *';
            color: #ef4444;
            font-weight: bold;
        }
    </style>
</head>
<body class="dot-texture-background text-slate-300">
    
    <?php include '../includes/sidebar.php'; ?> 
    
    <div class="pl-20">
        <main id="main-content" class="p-6 md:p-8">
            <header class="flex justify-between items-center mb-8">
                <h1 class="font-heading text-4xl font-bold text-white">CMS Content</h1>
                <?php include_once '../includes/low_stock_notif.php'; ?>
            </header>
            
            <?php if ($message): ?>
            <div id="alert" class="fixed top-8 right-8 text-white py-2 px-6 rounded-lg shadow-lg text-lg transform transition-all duration-300 z-50 <?php echo htmlspecialchars($message_class); ?>">
                <?php echo $message; ?>
            </div>
            <?php endif; ?>

            <div class="content-card p-6 rounded-lg">
                <?php
                $tabs = [
                    'home' => 'Best Selling',
                    'about_us' => 'About Us',
                    'faq' => 'FAQ',
                    'privacy_policy' => 'Privacy Policy',
                    'terms_conditions' => 'Terms & Conditions'
                ];
                ?>
                <div class="border-b border-slate-700 mb-6">
                    <?php foreach ($tabs as $key => $title): ?>
                    <button data-tab="<?php echo $key; ?>" class="tab-button py-2 px-4 text-sm font-medium text-slate-400 hover:text-white border-b-2 border-transparent transition-all duration-200 <?php echo ($key == $active_tab) ? 'tab-active' : 'hover:border-slate-500/50'; ?>">
                        <?php echo $title; ?>
                    </button>
                    <?php endforeach; ?>
                </div>

                <div id="tab-content-container">
                    <?php foreach ($tabs as $key => $title): 
                        $content_data = $page_contents[$key] ?? ['title' => 'Default Title', 'content' => 'Placeholder Content'];
                        $is_active = ($key == $active_tab) ? '' : 'hidden';
                    ?>
                    <div id="content-<?php echo $key; ?>" class="tab-content space-y-6 <?php echo $is_active; ?>">
                        <h2 class="font-heading text-2xl font-semibold text-red-400 border-b border-slate-800 pb-2 mb-4">Content for: <?php echo $title; ?></h2>

                        <?php if ($key === 'home'): // --- Dedicated Best-Selling Management Section (List Editor) --- ?>
                            <div class="p-6 border border-slate-700/50 rounded-lg bg-slate-800/20">
                                <h3 class="text-xl font-heading text-white mb-4 flex items-center">
                                    <svg class="w-6 h-6 mr-2 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                    Best-Selling Products (List Editor)
                                </h3>
                                
                                <button type="button" onclick="window.location.href='<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?tab=home&add_new=bs'" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg mb-4 flex items-center space-x-2">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
                                    <span>Add New Product</span>
                                </button>
                                
                                <div class="overflow-x-auto">
                                    <table class="w-full text-left">
                                        <thead>
                                            <tr class="border-b-2 border-red-500/50">
                                                <th class="py-3 px-2 font-heading text-red-400">ID</th>
                                                <th class="py-3 px-2 font-heading text-slate-300">Name</th>
                                                <th class="py-3 px-2 font-heading text-slate-300">Link</th>
                                                <th class="py-3 px-2 font-heading text-slate-300">Logo Path</th>
                                                <th class="py-3 px-2 font-heading text-slate-300">Img Path</th>
                                                <th class="py-3 px-2 font-heading text-slate-300">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody class="text-slate-400">
                                            <?php foreach ($best_selling_items as $item): ?>
                                            <tr class="border-b border-slate-800 hover:bg-slate-800/50">
                                                <td class="py-4 px-2 font-mono text-red-400">#<?php echo $item['bs_id']; ?></td>
                                                <td class="py-4 px-2"><?php echo htmlspecialchars($item['bs_product_name']); ?></td>
                                                <td class="py-4 px-2 truncate max-w-xs"><?php echo htmlspecialchars($item['bs_product_link']); ?></td>
                                                <td class="py-4 px-2"><?php echo htmlspecialchars($item['bs_logo']); ?></td>
                                                <td class="py-4 px-2"><?php echo htmlspecialchars($item['bs_product_img']); ?></td>
                                                <td class="py-4 px-2 space-x-3">
                                                    <a href="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?tab=home&edit_bs_id=<?php echo $item['bs_id']; ?>" class="text-blue-400 hover:text-blue-300">Edit</a>
                                                    
                                                    <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="inline" onsubmit="return confirm('Are you sure you want to delete Product ID <?php echo $item['bs_id']; ?>?');">
                                                        <input type="hidden" name="bs_id" value="<?php echo $item['bs_id']; ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="page_type" value="home">
                                                        <button type="submit" class="text-red-400 hover:text-red-300">Delete</button>
                                                    </form>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        
                        <?php elseif ($key === 'about_us'): // --- Dedicated About Us Management Section (List Editor) --- ?>
                            <div class="p-6 border border-slate-700/50 rounded-lg bg-slate-800/20">
                                <h3 class="text-xl font-heading text-white mb-4 flex items-center">
                                    <svg class="w-6 h-6 mr-2 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20v-2a3 3 0 00-5.356-1.857M9 20V8m6 12V6a3 3 0 00-6 0v14"></path></svg>
                                    About Us Content Components (List Editor)
                                </h3>
                                
                                <button type="button" onclick="window.location.href='<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?tab=about_us&add_new=au'" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg mb-4 flex items-center space-x-2">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
                                    <span>Add New Component</span>
                                </button>
                                
                                <div class="overflow-x-auto">
                                    <table class="w-full text-left">
                                        <thead>
                                            <tr class="border-b-2 border-red-500/50">
                                                <th class="py-3 px-2 font-heading text-red-400">ID</th>
                                                <th class="py-3 px-2 font-heading text-slate-300">Title</th>
                                                <th class="py-3 px-2 font-heading text-slate-300">Content (Excerpt)</th>
                                                <th class="py-3 px-2 font-heading text-slate-300">Img Path</th>
                                                <th class="py-3 px-2 font-heading text-slate-300">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody class="text-slate-400">
                                            <?php foreach ($about_us_items as $item): ?>
                                            <tr class="border-b border-slate-800 hover:bg-slate-800/50">
                                                <td class="py-4 px-2 font-mono text-red-400">#<?php echo $item['id']; ?></td>
                                                <td class="py-4 px-2"><?php echo htmlspecialchars($item['title']); ?></td>
                                                <td class="py-4 px-2 truncate max-w-lg"><?php echo htmlspecialchars(substr($item['content'], 0, 100)) . '...'; ?></td>
                                                <td class="py-4 px-2"><?php echo htmlspecialchars($item['img']); ?></td>
                                                <td class="py-4 px-2 space-x-3">
                                                    <a href="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?tab=about_us&edit_au_id=<?php echo $item['id']; ?>" class="text-blue-400 hover:text-blue-300">Edit</a>
                                                    
                                                    <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="inline" onsubmit="return confirm('Are you sure you want to delete About Us ID <?php echo $item['id']; ?>?');">
                                                        <input type="hidden" name="about_us_id" value="<?php echo $item['id']; ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="page_type" value="about_us">
                                                        <button type="submit" class="text-red-400 hover:text-red-300">Delete</button>
                                                    </form>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <p class="mt-4 text-xs text-slate-500">Note: This section edits the `about_us_content` table which expects the columns `about_us_id`, `about_us_title`, `about_us_content`, and `about_us_img`.</p>
                            </div>
                        
                        <?php elseif ($key === 'faq'): // --- Dedicated FAQ Management Section (Full Content & Forms) --- ?>
                             <div class="p-6 border border-slate-700/50 rounded-lg bg-slate-800/20">
                                <h3 class="text-xl font-heading text-white mb-4 flex items-center">
                                    <svg class="w-6 h-6 mr-2 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9.243a4 4 0 00.757 1.515 4 4 0 01-1.772 3.829l-1.8 1.8a1 1 0 001.414 1.414l1.8-1.8a6 6 0 002.396-5.183l-.757-.757zm0 0l-3.293-3.293a1 1 0 011.414-1.414l3.293 3.293z"></path></svg>
                                    Frequently Asked Questions (List Editor)
                                </h3>
                                
                                <button type="button" onclick="window.location.href='<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?tab=faq&add_new=faq'" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg mb-4 flex items-center space-x-2">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
                                    <span>Add New FAQ</span>
                                </button>
                                
                                <div class="overflow-x-auto">
                                    <table class="w-full text-left">
                                        <thead>
                                            <tr class="border-b-2 border-red-500/50">
                                                <th class="py-3 px-2 font-heading text-red-400">ID</th>
                                                <th class="py-3 px-2 font-heading text-slate-300">Question</th>
                                                <th class="py-3 px-2 font-heading text-slate-300">Answer (Excerpt)</th>
                                                <th class="py-3 px-2 font-heading text-slate-300">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody class="text-slate-400">
                                            <?php foreach ($faq_items as $item): ?>
                                            <tr class="border-b border-slate-800 hover:bg-slate-800/50">
                                                <td class="py-4 px-2 font-mono text-red-400">#<?php echo $item['id']; ?></td>
                                                <td class="py-4 px-2"><?php echo htmlspecialchars($item['question']); ?></td>
                                                <td class="py-4 px-2 truncate max-w-lg"><?php echo htmlspecialchars(substr($item['answer'], 0, 100)) . '...'; ?></td>
                                                <td class="py-4 px-2 space-x-3">
                                                    
                                                    <a href="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?tab=faq&edit_id=<?php echo $item['id']; ?>" class="text-blue-400 hover:text-blue-300">Edit</a>
                                                    
                                                    <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="inline" onsubmit="return confirm('Are you sure you want to delete FAQ ID <?php echo $item['id']; ?>?');">
                                                        <input type="hidden" name="faq_id" value="<?php echo $item['id']; ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="page_type" value="faq">
                                                        <button type="submit" class="text-red-400 hover:text-red-300">Delete</button>
                                                    </form>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <p class="mt-4 text-xs text-slate-500">Note: This section edits the `faq_contents` table which expects the columns `faq_id`, `faq_question`, and `faq_answer`.</p>
                            </div>
                            
                        <?php else: // --- Standard Content Editor (Privacy, Terms) --- ?>
                            <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                                <input type="hidden" name="page_type" value="<?php echo $key; ?>">
                                <div class="mb-4">
                                    <label for="<?php echo $key; ?>_title" class="block text-sm font-medium text-slate-400 mb-1 required">Page Title</label>
                                    <input type="text" id="<?php echo $key; ?>_title" name="<?php echo $key; ?>_title" value="<?php echo htmlspecialchars($content_data['title'] ?? ''); ?>" class="w-full p-3 rounded-lg bg-slate-800/80 border border-slate-700 focus:ring-red-500 focus:border-red-500 text-white" required>
                                </div>
                                <div class="mb-6">
                                    <label for="<?php echo $key; ?>_content" class="block text-sm font-medium text-slate-400 mb-1 required">Page Content / Body Text</label>
                                    <textarea id="<?php echo $key; ?>_content" name="<?php echo $key; ?>_content" rows="15" class="w-full p-3 rounded-lg bg-slate-800/80 border border-slate-700 focus:ring-red-500 focus:border-red-500 text-white" required><?php echo htmlspecialchars($content_data['content'] ?? ''); ?></textarea>
                                </div>
                                <div class="flex justify-end pt-4 border-t border-slate-800">
                                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white font-bold py-3 px-6 rounded-lg shadow-lg transition-colors duration-200 flex items-center">
                                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                        Save <?php echo $title; ?> Content
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </main>
    </div>

    <?php if ($bs_to_edit): /* --- Best Selling Edit Modal --- */ ?>
    <div class="fixed inset-0 z-50 bg-black bg-opacity-75 flex items-center justify-center">
        <div class="bg-slate-900 p-8 rounded-lg shadow-2xl w-full max-w-3xl border border-red-500">
            <h3 class="font-heading text-2xl text-red-400 mb-6 border-b border-slate-800 pb-2">Edit Best-Selling Product (ID: <?php echo $bs_to_edit['bs_id']; ?>)</h3>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <input type="hidden" name="bs_id" value="<?php echo $bs_to_edit['bs_id']; ?>">
                <input type="hidden" name="action" value="save_edit">
                <input type="hidden" name="page_type" value="home">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-400 mb-1 required">Product Name (bs_product_name)</label>
                    <input type="text" name="bs_product_name" value="<?php echo htmlspecialchars($bs_to_edit['bs_product_name']); ?>" class="w-full p-3 rounded-lg bg-slate-800/80 border border-slate-700 focus:ring-red-500 focus:border-red-500 text-white" required>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-400 mb-1 required">Product Link (bs_product_link)</label>
                    <input type="url" name="bs_product_link" value="<?php echo htmlspecialchars($bs_to_edit['bs_product_link']); ?>" class="w-full p-3 rounded-lg bg-slate-800/80 border border-slate-700 focus:ring-red-500 focus:border-red-500 text-white" required>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-400 mb-1">Logo Path (bs_logo)</label>
                    <input type="text" name="bs_logo" value="<?php echo htmlspecialchars($bs_to_edit['bs_logo']); ?>" class="w-full p-3 rounded-lg bg-slate-800/80 border border-slate-700 focus:ring-red-500 focus:border-red-500 text-white">
                </div>
                <div class="mb-6">
                    <label class="block text-sm font-medium text-slate-400 mb-1">Image Path (bs_product_img)</label>
                    <input type="text" name="bs_product_img" value="<?php echo htmlspecialchars($bs_to_edit['bs_product_img']); ?>" class="w-full p-3 rounded-lg bg-slate-800/80 border border-slate-700 focus:ring-red-500 focus:border-red-500 text-white">
                </div>
                
                <div class="flex justify-end space-x-4 pt-4 border-t border-slate-800">
                    <a href="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?tab=home" class="py-2 px-6 rounded-lg text-slate-400 hover:text-white transition-colors">Cancel</a>
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-6 rounded-lg shadow-lg transition-colors duration-200">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($show_add_modal_bs): /* --- Best Selling Add Modal --- */ ?>
    <div class="fixed inset-0 z-50 bg-black bg-opacity-75 flex items-center justify-center">
        <div class="bg-slate-900 p-8 rounded-lg shadow-2xl w-full max-w-3xl border border-red-500">
            <h3 class="font-heading text-2xl text-red-400 mb-6 border-b border-slate-800 pb-2">Add New Best-Selling Product</h3>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <input type="hidden" name="action" value="insert_new">
                <input type="hidden" name="page_type" value="home">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-400 mb-1 required">Product Name (bs_product_name)</label>
                    <input type="text" name="bs_product_name" placeholder="e.g., Ultimate Widget Pro" class="w-full p-3 rounded-lg bg-slate-800/80 border border-slate-700 focus:ring-red-500 focus:border-red-500 text-white" required>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-400 mb-1 required">Product Link (bs_product_link)</label>
                    <input type="url" name="bs_product_link" placeholder="e.g., https://yourstore.com/widget-pro" class="w-full p-3 rounded-lg bg-slate-800/80 border border-slate-700 focus:ring-red-500 focus:border-red-500 text-white" required>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-400 mb-1">Logo Path (bs_logo)</label>
                    <input type="text" name="bs_logo" placeholder="e.g., /assets/logos/ultimate.svg" class="w-full p-3 rounded-lg bg-slate-800/80 border border-slate-700 focus:ring-red-500 focus:border-red-500 text-white">
                </div>
                <div class="mb-6">
                    <label class="block text-sm font-medium text-slate-400 mb-1">Image Path (bs_product_img)</label>
                    <input type="text" name="bs_product_img" placeholder="e.g., /assets/images/widget.jpg" class="w-full p-3 rounded-lg bg-slate-800/80 border border-slate-700 focus:ring-red-500 focus:border-red-500 text-white">
                </div>
                
                <div class="flex justify-end space-x-4 pt-4 border-t border-slate-800">
                    <a href="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?tab=home" class="py-2 px-6 rounded-lg text-slate-400 hover:text-white transition-colors">Cancel</a>
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-6 rounded-lg shadow-lg transition-colors duration-200">Add Product</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($au_to_edit): /* --- About Us Edit Modal --- */ ?>
    <div class="fixed inset-0 z-50 bg-black bg-opacity-75 flex items-center justify-center">
        <div class="bg-slate-900 p-8 rounded-lg shadow-2xl w-full max-w-3xl border border-red-500">
            <h3 class="font-heading text-2xl text-red-400 mb-6 border-b border-slate-800 pb-2">Edit About Us Component (ID: <?php echo $au_to_edit['id']; ?>)</h3>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <input type="hidden" name="about_us_id" value="<?php echo $au_to_edit['id']; ?>">
                <input type="hidden" name="action" value="save_edit">
                <input type="hidden" name="page_type" value="about_us">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-400 mb-1 required">Component Title (about_us_title)</label>
                    <input type="text" name="new_title" value="<?php echo htmlspecialchars($au_to_edit['title']); ?>" class="w-full p-3 rounded-lg bg-slate-800/80 border border-slate-700 focus:ring-red-500 focus:border-red-500 text-white" required>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-400 mb-1 required">Component Content (about_us_content)</label>
                    <textarea name="new_content" rows="6" class="w-full p-3 rounded-lg bg-slate-800/80 border border-slate-700 focus:ring-red-500 focus:border-red-500 text-white" required><?php echo htmlspecialchars($au_to_edit['content']); ?></textarea>
                </div>
                <div class="mb-6">
                    <label class="block text-sm font-medium text-slate-400 mb-1">Image Path (about_us_img)</label>
                    <input type="text" name="new_img" value="<?php echo htmlspecialchars($au_to_edit['img']); ?>" class="w-full p-3 rounded-lg bg-slate-800/80 border border-slate-700 focus:ring-red-500 focus:border-red-500 text-white">
                </div>
                
                <div class="flex justify-end space-x-4 pt-4 border-t border-slate-800">
                    <a href="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?tab=about_us" class="py-2 px-6 rounded-lg text-slate-400 hover:text-white transition-colors">Cancel</a>
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-6 rounded-lg shadow-lg transition-colors duration-200">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($show_add_modal_au): /* --- About Us Add Modal --- */ ?>
    <div class="fixed inset-0 z-50 bg-black bg-opacity-75 flex items-center justify-center">
        <div class="bg-slate-900 p-8 rounded-lg shadow-2xl w-full max-w-3xl border border-red-500">
            <h3 class="font-heading text-2xl text-red-400 mb-6 border-b border-slate-800 pb-2">Add New About Us Component</h3>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <input type="hidden" name="action" value="insert_new">
                <input type="hidden" name="page_type" value="about_us">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-400 mb-1 required">Component Title (about_us_title)</label>
                    <input type="text" name="new_title" placeholder="e.g., Our Mission Statement" class="w-full p-3 rounded-lg bg-slate-800/80 border border-slate-700 focus:ring-red-500 focus:border-red-500 text-white" required>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-400 mb-1 required">Component Content (about_us_content)</label>
                    <textarea name="new_content" rows="6" placeholder="Provide content for this section." class="w-full p-3 rounded-lg bg-slate-800/80 border border-slate-700 focus:ring-red-500 focus:border-red-500 text-white" required></textarea>
                </div>
                <div class="mb-6">
                    <label class="block text-sm font-medium text-slate-400 mb-1">Image Path (about_us_img)</label>
                    <input type="text" name="new_img" placeholder="e.g., /assets/images/mission.jpg" class="w-full p-3 rounded-lg bg-slate-800/80 border border-slate-700 focus:ring-red-500 focus:border-red-500 text-white">
                </div>
                
                <div class="flex justify-end space-x-4 pt-4 border-t border-slate-800">
                    <a href="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?tab=about_us" class="py-2 px-6 rounded-lg text-slate-400 hover:text-white transition-colors">Cancel</a>
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-6 rounded-lg shadow-lg transition-colors duration-200">Add Component</g>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($faq_to_edit): /* --- FAQ Edit Modal --- */ ?>
    <div class="fixed inset-0 z-50 bg-black bg-opacity-75 flex items-center justify-center">
        <div class="bg-slate-900 p-8 rounded-lg shadow-2xl w-full max-w-3xl border border-red-500">
            <h3 class="font-heading text-2xl text-red-400 mb-6 border-b border-slate-800 pb-2">Edit FAQ (ID: <?php echo $faq_to_edit['id']; ?>)</h3>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <input type="hidden" name="faq_id" value="<?php echo $faq_to_edit['id']; ?>">
                <input type="hidden" name="action" value="save_edit">
                <input type="hidden" name="page_type" value="faq">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-400 mb-1 required">Question</label>
                    <input type="text" name="new_question" value="<?php echo htmlspecialchars($faq_to_edit['question']); ?>" class="w-full p-3 rounded-lg bg-slate-800/80 border border-slate-700 focus:ring-red-500 focus:border-red-500 text-white" required>
                </div>
                <div class="mb-6">
                    <label class="block text-sm font-medium text-slate-400 mb-1 required">Answer</label>
                    <textarea name="new_answer" rows="8" class="w-full p-3 rounded-lg bg-slate-800/80 border border-slate-700 focus:ring-red-500 focus:border-red-500 text-white" required><?php echo htmlspecialchars($faq_to_edit['answer']); ?></textarea>
                </div>
                
                <div class="flex justify-end space-x-4 pt-4 border-t border-slate-800">
                    <a href="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?tab=faq" class="py-2 px-6 rounded-lg text-slate-400 hover:text-white transition-colors">Cancel</a>
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-6 rounded-lg shadow-lg transition-colors duration-200">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($show_add_modal_faq): /* --- FAQ Add Modal --- */ ?>
    <div class="fixed inset-0 z-50 bg-black bg-opacity-75 flex items-center justify-center">
        <div class="bg-slate-900 p-8 rounded-lg shadow-2xl w-full max-w-3xl border border-red-500">
            <h3 class="font-heading text-2xl text-red-400 mb-6 border-b border-slate-800 pb-2">Add New FAQ</h3>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <input type="hidden" name="action" value="insert_new">
                <input type="hidden" name="page_type" value="faq">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-400 mb-1 required">Question</label>
                    <input type="text" name="new_question" placeholder="e.g., What is your return policy?" class="w-full p-3 rounded-lg bg-slate-800/80 border border-slate-700 focus:ring-red-500 focus:border-red-500 text-white" required>
                </div>
                <div class="mb-6">
                    <label class="block text-sm font-medium text-slate-400 mb-1 required">Answer</label>
                    <textarea name="new_answer" rows="8" placeholder="Provide the answer to the question." class="w-full p-3 rounded-lg bg-slate-800/80 border border-slate-700 focus:ring-red-500 focus:border-red-500 text-white" required></textarea>
                </div>
                
                <div class="flex justify-end space-x-4 pt-4 border-t border-slate-800">
                    <a href="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?tab=faq" class="py-2 px-6 rounded-lg text-slate-400 hover:text-white transition-colors">Cancel</a>
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-6 rounded-lg shadow-lg transition-colors duration-200">Add FAQ</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    
    <script>
        document.addEventListener('DOMContentLoaded', (event) => {
            const tabButtons = document.querySelectorAll('.tab-button');
            const tabContents = document.querySelectorAll('.tab-content');

            const activateTab = (tabKey) => {
                // Hide all content, deactivate all buttons
                tabContents.forEach(content => content.classList.add('hidden'));
                tabButtons.forEach(button => button.classList.remove('tab-active'));

                // Find and show the corresponding content
                const activeBtn = document.querySelector(`.tab-button[data-tab="${tabKey}"]`);
                const activeContent = document.getElementById(`content-${tabKey}`);

                if (activeBtn && activeContent) {
                    activeBtn.classList.add('tab-active');
                    activeContent.classList.remove('hidden');
                }
            };

            // Event listener for tab clicks
            tabButtons.forEach(button => {
                button.addEventListener('click', (e) => {
                    e.preventDefault(); 
                    activateTab(e.target.dataset.tab);
                });
            });
            
            // Initialize the correct tab on page load based on URL or default. 
            // This ensures the correct tab is active after the PHP redirect/reload.
            const urlParams = new URLSearchParams(window.location.search);
            const initialTab = urlParams.get('tab') || 'home';
            activateTab(initialTab);

            // Optional: Hide success message after a few seconds
            const alertElement = document.getElementById('alert');
            if (alertElement) {
                setTimeout(() => {
                    alertElement.style.opacity = '0';
                    setTimeout(() => alertElement.style.display = 'none', 300);
                }, 5000);
            }
        });
    </script>
</body>
</html>