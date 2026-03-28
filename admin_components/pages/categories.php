<?php
// C:\xampp\htdocs\RalphPHP\admin_components\pages\categories.php
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1
header("Pragma: no-cache"); // HTTP 1.0
header("Expires: 0"); // Proxies
// 1. JSON Protection & Session Start
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
ob_start(); 
ini_set('display_errors', 0); 

// --- DATABASE CONNECTION ---
include_once '../includes/db_connection.php';

// --- CONFIGURATION ---
if (!defined('LOW_STOCK_THRESHOLD')) {
    define('LOW_STOCK_THRESHOLD', 10);
}

// --- AUDIT HELPER ---
$auditHelperPath = '../includes/audit_helper.php';
if (file_exists($auditHelperPath)) {
    include_once $auditHelperPath;
} else {
    // Dummy function to prevent crash if helper is missing
    if (!function_exists('log_audit_action')) { function log_audit_action($a, $b, $c, $d = []) { return true; } }
}

// --- CONNECTION CHECK ---
if (!isset($conn) || $conn->connect_error) {
    ob_end_clean(); 
    die(json_encode(['error' => "Database connection failed."]));
}

// --- SECURITY CHECK ---
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    if (isset($_GET['action']) || isset($_POST['action'])) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Session expired.']);
        exit;
    }
    header("location: ../admin_login.php");
    exit;
}

// =================================================================================
//                                  AJAX HANDLERS
// =================================================================================

if (isset($_GET['action'])) {
    ob_end_clean(); // Discard any previous output (warnings, whitespace)
    header('Content-Type: application/json');

    try {
        // 1. Search Suggestions
        if ($_GET['action'] == 'search_suggestions') {
            $term = isset($_GET['term']) ? trim($_GET['term']) : '';
            $suggestions = [];
            if (!empty($term)) {
                $like_term = "%{$term}%";
                $stmt = $conn->prepare("SELECT DISTINCT category_name FROM categories WHERE category_name LIKE ? LIMIT 5");
                $stmt->bind_param("s", $like_term);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $suggestions[] = $row['category_name'];
                }
                $stmt->close();
            }
            echo json_encode(array_values(array_unique($suggestions)));
            exit;
        }

        // 2. Fetch Categories (Table Data)
        if ($_GET['action'] == 'fetch_categories') {
            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
            $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
            $offset = ($page - 1) * $limit;
            
            // UPDATED QUERY: Added c.description
            $sql = "SELECT c.category_id, c.category_name, c.description, c.created_at, c.updated_at, COUNT(p.product_id) as product_count 
                    FROM categories c 
                    LEFT JOIN products p ON c.category_id = p.category_id";
            
            $where_clauses = [];
            $params = [];
            $types = '';
            $having_clauses = [];

            // Filters
            if (!empty($_GET['search'])) {
                $search_term = '%' . trim($_GET['search']) . '%';
                // UPDATED: Search in Description too
                $where_clauses[] = "(c.category_name LIKE ? OR c.description LIKE ?)";
                $params[] = $search_term;
                $params[] = $search_term;
                $types .= 'ss';
            }
            if(!empty($_GET['created_after'])) {
                $where_clauses[] = "c.created_at >= ?";
                $params[] = $_GET['created_after'];
                $types .= 's';
            }
            if(!empty($_GET['created_before'])) {
                $where_clauses[] = "c.created_at <= ?";
                $params[] = $_GET['created_before'] . ' 23:59:59';
                $types .= 's';
            }

            if (count($where_clauses) > 0) {
                $sql .= " WHERE " . implode(' AND ', $where_clauses);
            }
            
            $sql .= " GROUP BY c.category_id";

            // Having Clauses (Content Status)
            if (!empty($_GET['content_status'])) {
                if ($_GET['content_status'] === 'has_products') {
                    $having_clauses[] = "product_count > 0";
                } elseif ($_GET['content_status'] === 'empty') {
                     $having_clauses[] = "product_count = 0";
                }
            }

            if (count($having_clauses) > 0) {
                $sql .= " HAVING " . implode(' AND ', $having_clauses);
            }

            // Sorting
            $sort_col = $_GET['sort'] ?? 'category_id';
            $sort_dir = $_GET['dir'] ?? 'DESC';
            $allowed = ['category_id', 'category_name', 'created_at', 'updated_at', 'product_count'];
            if (!in_array($sort_col, $allowed)) $sort_col = 'category_id';
            if (strtoupper($sort_dir) !== 'ASC') $sort_dir = 'DESC';

            $sql .= " ORDER BY $sort_col $sort_dir";

            // Count Total
            $count_sql = "SELECT COUNT(*) as total FROM ($sql) as subquery";
            $count_stmt = $conn->prepare($count_sql);
            if ($types) {
                $count_stmt->bind_param($types, ...$params);
            }
            $count_stmt->execute();
            $total_records = $count_stmt->get_result()->fetch_assoc()['total'];
            $count_stmt->close();

            // Pagination
            $total_pages = ceil($total_records / $limit);
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            $types .= 'ii';

            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $categories = $result->fetch_all(MYSQLI_ASSOC);

            echo json_encode([
                'categories' => $categories,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total_records' => $total_records,
                    'total_pages' => $total_pages
                ]
            ]);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// =================================================================================
//                                  POST HANDLERS
// =================================================================================

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    ob_end_clean(); // Ensure clean JSON
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'An unknown error occurred.', 'errors' => []];
    $admin_id = $_SESSION["user_id"] ?? 0;
    
    // 1. Add or Edit Category
    if ($_POST['action'] === 'add_category' || $_POST['action'] === 'edit_category') {
        $is_edit = ($_POST['action'] === 'edit_category');
        $category_id = $is_edit ? intval($_POST['category_id']) : null;
        $category_name = trim($_POST['category_name'] ?? '');
        // UPDATED: Capture Description
        $description = trim($_POST['description'] ?? '');

        // --- AUDIT PREP: Get Old Data ---
        $old_name = '';
        if ($is_edit) {
            $old_stmt = $conn->prepare("SELECT category_name FROM categories WHERE category_id = ?");
            $old_stmt->bind_param('i', $category_id);
            $old_stmt->execute();
            $res = $old_stmt->get_result()->fetch_assoc();
            $old_name = $res['category_name'] ?? '';
            $old_stmt->close();
        }

        // Validate
        if (empty($category_name)) {
            $response['errors']['category_name'] = 'Category name is required.';
        } else {
            // Check Duplicates
            $dupe_sql = $is_edit ? "SELECT category_id FROM categories WHERE category_name = ? AND category_id != ?" : "SELECT category_id FROM categories WHERE category_name = ?";
            $dupe_stmt = $conn->prepare($dupe_sql);
            if ($is_edit) $dupe_stmt->bind_param('si', $category_name, $category_id);
            else $dupe_stmt->bind_param('s', $category_name);
            $dupe_stmt->execute();
            if ($dupe_stmt->get_result()->num_rows > 0) {
                $response['errors']['category_name'] = 'This category name already exists.';
            }
            $dupe_stmt->close();
        }
        
        if (empty($response['errors'])) {
            if ($is_edit) {
                // Update (Added Description)
                $stmt = $conn->prepare("UPDATE categories SET category_name = ?, description = ?, updated_at = CURRENT_TIMESTAMP() WHERE category_id = ?");
                $stmt->bind_param("ssi", $category_name, $description, $category_id);
            } else {
                // Insert (Added Description)
                $stmt = $conn->prepare("INSERT INTO categories (category_name, description) VALUES (?, ?)");
                $stmt->bind_param("ss", $category_name, $description);
            }

            if ($stmt->execute()) {
                $response['success'] = true;
                $new_id = $is_edit ? $category_id : $conn->insert_id;
                $response['message'] = 'Category ' . ($is_edit ? 'updated' : 'added') . ' successfully!';
                
                // --- AUDIT LOGGING ---
                if (function_exists('log_audit_action')) {
                    if ($is_edit) {
                        $changes = [];
                        $log_msg = "Updated Category (ID: $new_id)";
                        if ($old_name !== $category_name) {
                            $changes['Name'] = ['old' => $old_name, 'new' => $category_name];
                            $log_msg = "Renamed Category from '$old_name' to '$category_name' (ID: $new_id).";
                        }
                        if (!empty($changes)) {
                            log_audit_action("Update Category", 'Categories', $log_msg, ['changes' => $changes]);
                        }
                    } else {
                        log_audit_action("Create Category", 'Categories', "Created new Category '$category_name' (ID: $new_id)");
                    }
                }
            } else {
                $response['message'] = 'Database error: ' . $stmt->error;
            }
            if (isset($stmt)) $stmt->close();
        } else {
            $response['message'] = "Please correct the errors.";
        }
    }

    // 2. Delete Category
    if ($_POST['action'] === 'delete_category') {
        $category_id = intval($_POST['category_id'] ?? 0);
        
        if ($category_id > 0) {
            $name_res = $conn->query("SELECT category_name FROM categories WHERE category_id = $category_id");
            $cat_name = $name_res->fetch_assoc()['category_name'] ?? 'Unknown';

            $conn->begin_transaction();
            try {
                $conn->query("UPDATE products SET category_id = NULL WHERE category_id = $category_id");
                $conn->query("DELETE FROM categories WHERE category_id = $category_id");
                $conn->commit();
                
                $response['success'] = true;
                $response['message'] = "Category deleted successfully!";

                if (function_exists('log_audit_action')) {
                    log_audit_action("Delete Category", 'Categories', "Deleted Category '$cat_name' (ID: $category_id). Products set to Uncategorized.");
                }

            } catch (Exception $e) {
                $conn->rollback();
                $response['message'] = "Failed to delete: " . $e->getMessage();
            }
        } else {
            $response['message'] = "Invalid ID.";
        }
    }

    echo json_encode($response);
    exit();
}

// --- PAGE LOAD ANALYTICS ---
ob_end_flush(); 

$total_categories = $conn->query("SELECT COUNT(*) as count FROM categories")->fetch_assoc()['count'] ?? 0;
$uncategorized_products = $conn->query("SELECT COUNT(*) as count FROM products WHERE category_id IS NULL OR category_id = 0")->fetch_assoc()['count'] ?? 0;
$most_populated_result = $conn->query("SELECT c.category_name, COUNT(p.product_id) as product_count FROM categories c LEFT JOIN products p ON c.category_id = p.category_id GROUP BY c.category_id ORDER BY product_count DESC LIMIT 1");
$most_populated_category = ($most_populated_result && $most_populated_result->num_rows > 0) ? $most_populated_result->fetch_assoc() : ['category_name' => 'None', 'product_count' => 0];
$new_categories = $conn->query("SELECT COUNT(*) as count FROM categories WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_assoc()['count'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreshFlow - Category Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
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
        :root { --brand-green: #1E3A1D; --brand-cream: #F8F5EE; --accent-green: #3E6E41; --accent-red: #B33333; }
        body { font-family: 'Inter', sans-serif; background-color: var(--brand-cream); color: #2B2B2B; transition: background-color 0.3s ease; }
        
        /* DARK MODE BODY */
        .dark body {
            background-color: #000000;
            background-image: radial-gradient(circle, rgba(255, 255, 255, 0.07) 1px, transparent 1px);
            background-size: 16px 16px;
            color: #f8fafc;
        }

        .font-heading { font-family: 'Roboto Mono', monospace; }
        
        /* Form Inputs Light & Dark Mode Support */
        .form-input, .filter-select { background-color: #ffffff; border: 1px solid #d1d5db; color: #374151; border-radius: 0.5rem; transition: all 0.2s ease;}
        .form-input:focus, .filter-select:focus { outline: none; border-color: var(--brand-green); box-shadow: 0 0 0 3px rgba(30, 58, 29, 0.1); }
        
        .dark .form-input, .dark .filter-select { background-color: #1e293b; border-color: #334155; color: #f8fafc; }
        .dark .form-input:focus, .dark .filter-select:focus { border-color: #4ade80; box-shadow: 0 0 0 3px rgba(74, 222, 128, 0.1); }

        .modal-card { border-radius: 1rem; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); }
        .modal { transition: opacity 0.3s ease; }
        .modal.hidden { opacity: 0; pointer-events: none; }
        
        .btn-primary { background-color: var(--brand-green); color: white; }
        .btn-primary:hover { background-color: #2a4e29; }
        .dark .btn-primary { background-color: #16a34a; }
        .dark .btn-primary:hover { background-color: #15803d; }
        
        .btn-danger { background-color: var(--accent-red); color: white; }
        .btn-danger:hover { background-color: #991b1b; }
        
        .sort-link.active { color: var(--brand-green); font-weight: bold; }
        .dark .sort-link.active { color: #4ade80; }
        
        .is-invalid { border-color: var(--accent-red) !important; }
        .error-message { color: var(--accent-red); font-size: 0.75rem; margin-top: 0.25rem; }
        .dark .error-message { color: #f87171; }
        label.required::after { content: '*'; color: var(--accent-red); margin-left: 0.25rem; }
        
        #filterContainer { transition: all 0.3s ease-in-out; max-height: 0; overflow: hidden; }
        #filterContainer.open { max-height: 500px; padding-top: 1rem; margin-top: 1rem; border-top: 1px solid #e5e7eb; }
        .dark #filterContainer.open { border-top-color: #334155; }
        
        .overflow-visible-container { overflow: visible !important; min-height: 300px; }
        .relative-cell { position: relative; }

        .custom-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .dark .custom-scroll::-webkit-scrollbar-thumb { background: #334155; }
    </style>
    <script>
    window.onpageshow = function(event) {
        if (event.persisted) {
            window.location.reload();
        }
    };

    window.onbeforeunload = function() {
        document.body.innerHTML = "";       
        document.body.style.backgroundColor = document.documentElement.classList.contains('dark') ? "#000" : "#F8F5EE";
    };
    </script>
</head>
<body>

    <?php include_once '../includes/sidebar.php'; ?>

    <div class="ml-20 transition-all duration-300">
        <main class="p-6 md:p-8">
            <div id="flashMessage" class="fixed top-8 right-8 text-white py-3 px-6 rounded-lg shadow-xl text-sm font-bold transform translate-x-full transition-all duration-500 z-[200] opacity-0 pointer-events-none"></div>

            <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8">
                <div>
                    <h1 class="font-heading text-3xl font-bold text-[#1E3A1D] dark:text-white mb-1 flex items-center gap-3">
                        <span class="material-icons text-3xl dark:text-green-400">category</span> Category Management
                    </h1>
                    <p class="text-sm text-gray-500 dark:text-slate-400 ml-11">Organize and structure your product catalog</p>
                </div>
                <div class="flex items-center space-x-4 mt-4 md:mt-0">
                    <button id="addCategoryBtn" class="bg-[#1E3A1D] dark:bg-green-600 text-white hover:bg-[#2a4e29] dark:hover:bg-green-500 font-bold py-2.5 px-6 rounded-lg flex items-center space-x-2 shadow-lg transition transform active:scale-95">
                        <span class="material-icons text-sm">add</span> <span>Add Category</span>
                    </button>
                </div>
            </header>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8 text-center flex-shrink-0">
                
                <div class="bg-white dark:bg-slate-900/80 border border-blue-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-pointer group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(59,130,246,0.2)] dark:hover:shadow-[0_0_20px_rgba(59,130,246,0.3)] dark:hover:border-blue-400 transition-all duration-300">
                    <div class="text-left">
                        <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">Total Categories</p>
                        <p class="font-heading text-3xl font-bold text-blue-600 dark:text-blue-400 mt-1 group-hover:scale-110 transition-transform origin-left font-mono"><?= $total_categories ?></p>
                    </div>
                    <div class="p-3 bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 rounded-lg group-hover:bg-blue-200 dark:group-hover:bg-blue-800/50 transition-colors">
                        <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">folder_copy</span>
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-900/80 border border-green-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-pointer group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(34,197,94,0.2)] dark:hover:shadow-[0_0_20px_rgba(34,197,94,0.3)] dark:hover:border-green-400 transition-all duration-300">
                    <div class="text-left">
                        <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-green-600 dark:group-hover:text-green-400 transition-colors">New This Week</p>
                        <p class="font-heading text-3xl font-bold text-green-600 dark:text-green-400 mt-1 group-hover:scale-110 transition-transform origin-left font-mono"><?= $new_categories ?></p>
                    </div>
                    <div class="p-3 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 rounded-lg group-hover:bg-green-200 dark:group-hover:bg-green-800/50 transition-colors">
                        <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">trending_up</span>
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-900/80 border border-red-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-pointer group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(239,68,68,0.2)] dark:hover:shadow-[0_0_20px_rgba(239,68,68,0.3)] dark:hover:border-red-400 transition-all duration-300">
                    <div class="text-left">
                        <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-red-600 dark:group-hover:text-red-400 transition-colors">Uncategorized</p>
                        <p class="font-heading text-3xl font-bold text-red-600 dark:text-red-400 mt-1 group-hover:scale-110 transition-transform origin-left font-mono"><?= $uncategorized_products ?></p>
                    </div>
                    <div class="p-3 bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 rounded-lg group-hover:bg-red-200 dark:group-hover:bg-red-800/50 transition-colors">
                        <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">warning</span>
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-900/80 border border-orange-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-pointer group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(249,115,22,0.2)] dark:hover:shadow-[0_0_20px_rgba(249,115,22,0.3)] dark:hover:border-orange-400 transition-all duration-300">
                    <div class="text-left w-[70%]">
                        <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-orange-600 dark:group-hover:text-orange-400 transition-colors">Top Category</p>
                        <p class="font-bold text-xl text-orange-600 dark:text-orange-400 mt-1 group-hover:scale-105 transition-transform origin-left truncate"><?= htmlspecialchars($most_populated_category['category_name']) ?></p>
                        <p class="text-[10px] text-gray-500 dark:text-slate-500 font-mono mt-0.5"><?= $most_populated_category['product_count'] ?> items</p>
                    </div>
                    <div class="p-3 bg-orange-100 dark:bg-orange-900/30 text-orange-600 dark:text-orange-400 rounded-lg group-hover:bg-orange-200 dark:group-hover:bg-orange-800/50 transition-colors">
                        <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">star</span>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900/80 p-4 rounded-xl shadow-sm border border-gray-200 dark:border-slate-800 mb-6 relative z-10 flex-shrink-0">
                <div class="flex flex-col md:flex-row items-center gap-4">
                    <div class="relative w-full flex-grow" id="search-wrapper">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400"><i class="fa-solid fa-search"></i></span>
                        <input type="text" id="searchInput" name="search" placeholder="Search categories..." class="w-full pl-10 p-2 rounded-lg form-input text-sm" autocomplete="off">
                        <div id="suggestions-container" class="absolute mt-1 w-full bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg shadow-xl z-20 hidden max-h-60 overflow-y-auto custom-scroll"></div>
                    </div>
                    <button id="toggleFilterBtn" class="bg-gray-50 dark:bg-slate-800 border border-gray-300 dark:border-slate-700 hover:bg-gray-100 dark:hover:bg-slate-700 text-gray-700 dark:text-slate-200 text-sm font-bold py-2 px-4 rounded-lg flex items-center gap-2 transition shadow-sm w-full md:w-auto justify-center">
                        <i class="fa-solid fa-filter"></i> Filters <i id="filterCaret" class="fa-solid fa-chevron-down ml-1 text-xs"></i>
                    </button>
                </div>

                <div id="filterContainer">
                    <form id="filterForm" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 mb-1 uppercase tracking-wider">Created After</label>
                            <input type="date" id="created_after" name="created_after" class="form-input w-full p-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 mb-1 uppercase tracking-wider">Created Before</label>
                            <input type="date" id="created_before" name="created_before" class="form-input w-full p-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 mb-1 uppercase tracking-wider">Content Status</label>
                            <select id="content_status" name="content_status" class="filter-select w-full p-2 text-sm cursor-pointer">
                                <option value="">All Categories</option>
                                <option value="has_products">Has Products</option>
                                <option value="empty">Empty Categories</option>
                            </select>
                        </div>
                        <div class="md:col-span-3 flex justify-end gap-2 mt-2">
                            <button type="button" id="resetFiltersBtn" class="bg-gray-200 dark:bg-slate-700 hover:bg-gray-300 dark:hover:bg-slate-600 text-gray-700 dark:text-slate-300 text-sm font-bold py-2 px-4 rounded-lg transition">Reset</button>
                            <button type="submit" id="applyFiltersBtn" class="bg-[#1E3A1D] dark:bg-green-600 text-white hover:bg-[#2a4e29] dark:hover:bg-green-500 text-sm font-bold py-2 px-4 rounded-lg transition">Apply Filters</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900/80 rounded-xl shadow border border-gray-200 dark:border-slate-800 flex-1 overflow-hidden flex flex-col mb-4 overflow-visible-container">
                <div class="overflow-x-auto min-h-[400px] custom-scroll">
                    <table class="w-full text-left whitespace-nowrap">
                        <thead class="bg-[#1E3A1D] dark:bg-slate-800 text-white text-xs uppercase font-bold sticky top-0 z-10">
                            <tr>
                                <th class="p-4"><a href="#" class="sort-link hover:text-green-200 dark:hover:text-green-400" data-sort="category_id">ID</a></th>
                                <th class="p-4"><a href="#" class="sort-link hover:text-green-200 dark:hover:text-green-400" data-sort="category_name">Category Name</a></th>
                                <th class="p-4">Description</th>
                                <th class="p-4"><a href="#" class="sort-link hover:text-green-200 dark:hover:text-green-400" data-sort="product_count">Products</a></th>
                                <th class="p-4"><a href="#" class="sort-link hover:text-green-200 dark:hover:text-green-400" data-sort="created_at">Created</a></th>
                                <th class="p-4"><a href="#" class="sort-link hover:text-green-200 dark:hover:text-green-400" data-sort="updated_at">Updated</a></th>
                                <th class="p-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="categoriesTableBody" class="text-sm text-gray-700 dark:text-gray-300 divide-y divide-gray-100 dark:divide-slate-800"></tbody>
                    </table>
                </div>
                <div class="p-4 border-t border-gray-100 dark:border-slate-800 flex flex-col md:flex-row justify-between items-center gap-4 bg-gray-50 dark:bg-slate-900 sticky bottom-0" id="pagination-container"></div>
            </div>
        </main>
    </div>

    <div id="categoryModal" class="fixed inset-0 bg-[#1E3A1D] dark:bg-black bg-opacity-40 dark:bg-opacity-60 flex justify-center items-center z-50 hidden transition-opacity duration-300 backdrop-blur-sm">
        <div class="modal-card w-11/12 md:w-1/3 bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700 overflow-hidden">
            <form id="categoryForm" novalidate>
                <input type="hidden" name="action" id="formAction">
                <input type="hidden" name="category_id" id="category_id">
                <div class="bg-[#1E3A1D] dark:bg-slate-800 p-5 text-white flex justify-between items-center">
                    <h2 id="modalTitle" class="text-lg font-bold flex items-center gap-2"><span class="material-icons text-sm">category</span> Add Category</h2>
                    <button type="button" id="cancelBtn" class="text-white hover:text-gray-300 transition"><span class="material-icons text-sm">close</span></button>
                </div>
                <div class="p-6">
                    <div class="mb-4">
                        <label for="category_name" class="block text-xs font-bold text-gray-700 dark:text-slate-400 uppercase tracking-wider mb-2 required">Category Name</label>
                        <input type="text" id="category_name" name="category_name" class="w-full p-2 form-input text-sm" required placeholder="e.g., Beverages">
                        <div class="error-message" id="error-category_name"></div>
                    </div>
                    <div class="mb-2">
                        <label for="description" class="block text-xs font-bold text-gray-700 dark:text-slate-400 uppercase tracking-wider mb-2">Description</label>
                        <textarea id="description" name="description" rows="3" class="w-full p-2 form-input text-sm custom-scroll" placeholder="Brief details about this category..."></textarea>
                    </div>
                </div>
                <div class="bg-gray-50 dark:bg-slate-800 p-4 flex justify-end space-x-3 border-t border-gray-100 dark:border-slate-700">
                    <button type="button" onclick="document.getElementById('categoryModal').classList.add('hidden')" class="px-5 py-2.5 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-700 rounded-lg text-sm font-bold transition">Cancel</button>
                    <button type="submit" id="submitBtn" class="bg-[#1E3A1D] dark:bg-green-600 hover:bg-[#2a4e29] dark:hover:bg-green-500 text-white font-bold py-2.5 px-6 rounded-lg shadow-md transition transform hover:-translate-y-0.5 flex items-center gap-2">
                        <span class="material-icons text-sm">save</span> Save
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="deleteModal" class="fixed inset-0 bg-[#1E3A1D] dark:bg-black bg-opacity-40 dark:bg-opacity-60 flex justify-center items-center z-50 hidden transition-opacity duration-300 backdrop-blur-sm">
        <div class="modal-card w-96 p-6 text-center bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700">
            <div class="w-16 h-16 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center mx-auto mb-4 border border-red-200 dark:border-red-800/50">
                <span class="material-icons text-red-600 dark:text-red-400 text-3xl">warning</span>
            </div>
            <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-2">Delete Category?</h2>
            <p id="delete-message" class="text-gray-500 dark:text-slate-400 mb-6 text-sm"></p>
            <form id="deleteForm">
                <input type="hidden" name="action" value="delete_category">
                <input type="hidden" id="delete_category_id" name="category_id">
                <div class="flex justify-center space-x-3">
                    <button type="button" id="cancelDeleteBtn" class="bg-gray-200 dark:bg-slate-800 hover:bg-gray-300 dark:hover:bg-slate-700 text-gray-800 dark:text-slate-300 text-sm font-bold py-2.5 px-6 rounded-lg transition">Cancel</button>
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2.5 px-6 rounded-lg shadow-md transition transform hover:-translate-y-0.5 flex items-center gap-2">
                        <span class="material-icons text-sm">delete_forever</span> Yes, Delete
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const categoryModal = document.getElementById('categoryModal');
        const deleteModal = document.getElementById('deleteModal');
        const categoryForm = document.getElementById('categoryForm');
        const deleteForm = document.getElementById('deleteForm');
        const tableBody = document.getElementById('categoriesTableBody');
        const paginationContainer = document.getElementById('pagination-container');
        const flashMessage = document.getElementById('flashMessage');
        const searchInput = document.getElementById('searchInput');
        const suggestionsContainer = document.getElementById('suggestions-container');
        const filterContainer = document.getElementById('filterContainer');
        const filterForm = document.getElementById('filterForm');
        let searchTimeout;
        
        let currentFilters = { page: 1, limit: 10, search: '', sort: 'category_id', dir: 'DESC', created_after: '', created_before: '', content_status: '' };

        const showFlashMessage = (message, type = 'success') => {
            flashMessage.textContent = message;
            flashMessage.className = `fixed top-8 right-8 text-white py-3 px-6 rounded-lg shadow-xl text-sm font-bold transform transition-all duration-300 z-[200] translate-x-0 opacity-100 ${type === 'success' ? 'bg-[#1E3A1D] dark:bg-green-700' : 'bg-[#B33333] dark:bg-red-700'}`;
            setTimeout(() => { flashMessage.classList.add('translate-x-full', 'opacity-0'); }, 3000);
        };

        const fetchCategories = async () => {
            const params = new URLSearchParams(currentFilters);
            tableBody.innerHTML = `<tr><td colspan="7" class="text-center p-8"><span class="material-icons animate-spin text-gray-400 dark:text-slate-600">refresh</span></td></tr>`;
            try {
                const response = await fetch(`?action=fetch_categories&${params.toString()}`);
                const text = await response.text();
                try {
                    const data = JSON.parse(text);
                    if (data.error) {
                        tableBody.innerHTML = `<tr><td colspan="7" class="text-center p-8 text-red-500 dark:text-red-400">${data.error}</td></tr>`;
                    } else {
                        renderTable(data.categories);
                        renderPagination(data.pagination);
                    }
                } catch(e) {
                    tableBody.innerHTML = `<tr><td colspan="7" class="text-center p-8 text-red-500 dark:text-red-400">System Error. Check Console.</td></tr>`;
                    console.error("JSON Error:", text);
                }
            } catch (error) {
                tableBody.innerHTML = `<tr><td colspan="7" class="text-center p-8 text-red-500 dark:text-red-400">Connection Error.</td></tr>`;
            }
        };

        const renderTable = (categories) => {
            tableBody.innerHTML = '';
            if (!categories || categories.length === 0) {
                tableBody.innerHTML = `<tr><td colspan="7" class="text-center p-12 text-gray-400 dark:text-slate-500 italic">No categories found matching criteria.</td></tr>`;
                return;
            }
            categories.forEach(cat => {
                const row = document.createElement('tr');
                row.className = 'border-b border-gray-100 dark:border-slate-800/50 hover:bg-gray-50 dark:hover:bg-slate-800/50 transition relative-cell';
                row.innerHTML = `
                    <td class="p-4 text-gray-500 dark:text-slate-400 font-mono text-xs">#${String(cat.category_id).padStart(4, '0')}</td>
                    <td class="p-4 font-bold text-gray-800 dark:text-white">${escapeHTML(cat.category_name)}</td>
                    <td class="p-4 text-sm text-gray-500 dark:text-slate-400 truncate max-w-[200px]" title="${escapeHTML(cat.description || '')}">${escapeHTML(cat.description || '-')}</td>
                    <td class="p-4 text-sm"><span class="bg-gray-100 dark:bg-slate-800 text-gray-600 dark:text-slate-300 py-1 px-3 rounded-full font-bold border border-gray-200 dark:border-slate-700">${cat.product_count}</span></td>
                    <td class="p-4 text-xs text-gray-500 dark:text-slate-400">${formatDateTime(cat.created_at)}</td>
                    <td class="p-4 text-xs text-gray-500 dark:text-slate-400">${formatDateTime(cat.updated_at)}</td>
                    <td class="p-4 text-right">
                        <div class="relative inline-block text-left">
                            <button onclick="window.toggleMenu(${cat.category_id})" class="text-gray-400 hover:text-gray-600 dark:hover:text-slate-300 p-2 rounded-full hover:bg-gray-100 dark:hover:bg-slate-700 transition"><span class="material-icons text-sm">more_vert</span></button>
                            <div id="menu-${cat.category_id}" class="action-menu hidden absolute right-0 mt-1 bg-white dark:bg-slate-800 rounded-lg shadow-[0_3px_10px_rgb(0,0,0,0.15)] border border-gray-200 dark:border-slate-700 z-[60] text-left py-2 w-32 overflow-hidden">
                                <a href="#" onclick="openEditModal(${cat.category_id}, '${escapeHTML(cat.category_name, true)}', '${escapeHTML(cat.description || '', true)}'); return false;" class="block px-4 py-2.5 text-xs font-semibold hover:bg-gray-50 dark:hover:bg-slate-700 flex items-center gap-2 text-gray-700 dark:text-gray-200 transition"><span class="material-icons text-sm">edit</span> Edit</a>
                                <a href="#" onclick="openDeleteModal(${cat.category_id}, '${escapeHTML(cat.category_name, true)}'); return false;" class="block px-4 py-2.5 text-xs font-semibold hover:bg-red-50 dark:hover:bg-red-900/30 flex items-center gap-2 text-red-600 dark:text-red-400 transition border-t border-gray-100 dark:border-slate-700"><span class="material-icons text-sm">delete</span> Delete</a>
                            </div>
                        </div>
                    </td>`;
                tableBody.appendChild(row);
            });
        };

        const renderPagination = (p) => {
            if (p.total_pages <= 1) { paginationContainer.innerHTML = `<span class="text-sm text-gray-500 dark:text-slate-400">Showing all <span class="font-bold text-gray-900 dark:text-white">${p.total_records}</span> categories</span>`; return; }
            let links = '';
            for (let i = 1; i <= p.total_pages; i++) {
                links += `<button onclick="changePage(${i})" class="w-8 h-8 flex items-center justify-center rounded-lg text-sm font-bold transition shadow-sm border ${i == p.page ? 'bg-[#1E3A1D] dark:bg-green-600 text-white border-transparent' : 'bg-white dark:bg-slate-800 text-gray-700 dark:text-slate-300 border-gray-200 dark:border-slate-700 hover:bg-gray-50 dark:hover:bg-slate-700'}">${i}</button>`;
            }
            paginationContainer.innerHTML = `<span class="text-sm text-gray-500 dark:text-slate-400">Showing page <span class="font-bold text-gray-900 dark:text-white">${p.page}</span> of <span class="font-bold text-gray-900 dark:text-white">${p.total_pages}</span></span><div class="flex items-center gap-1">${links}</div>`;
        };

        window.changePage = (p) => { currentFilters.page = p; fetchCategories(); };
        window.changeSort = (col) => {
            currentFilters.dir = (currentFilters.sort === col && currentFilters.dir === 'ASC') ? 'DESC' : 'ASC';
            currentFilters.sort = col;
            fetchCategories();
        };

        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                currentFilters.search = searchInput.value;
                fetchCategories();
            }, 300);
        });

        document.getElementById('addCategoryBtn').addEventListener('click', () => {
            categoryForm.reset(); document.getElementById('modalTitle').innerHTML = '<span class="material-icons text-sm">category</span> Add Category'; document.getElementById('formAction').value = 'add_category';
            clearValidationErrors(); categoryModal.classList.remove('hidden');
        });
        
        document.getElementById('toggleFilterBtn').addEventListener('click', () => {
             const isOpen = filterContainer.style.maxHeight && filterContainer.style.maxHeight !== '0px';
             filterContainer.style.maxHeight = isOpen ? '0' : '500px';
             filterContainer.classList.toggle('open', !isOpen);
        });

        filterForm.addEventListener('submit', (e) => { e.preventDefault(); const fd = new FormData(filterForm); currentFilters.created_after = fd.get('created_after'); currentFilters.created_before = fd.get('created_before'); currentFilters.content_status = fd.get('content_status'); currentFilters.page = 1; fetchCategories(); });
        document.getElementById('resetFiltersBtn').addEventListener('click', () => { filterForm.reset(); currentFilters = { ...currentFilters, created_after: '', created_before: '', content_status: '' }; fetchCategories(); });

        window.openEditModal = (id, name, description) => {
            categoryForm.reset(); clearValidationErrors();
            document.getElementById('modalTitle').innerHTML = '<span class="material-icons text-sm">edit</span> Edit Category';
            document.getElementById('formAction').value = 'edit_category';
            document.getElementById('category_id').value = id;
            document.getElementById('category_name').value = name;
            document.getElementById('description').value = description;
            categoryModal.classList.remove('hidden');
        };

        window.openDeleteModal = (id, name) => {
            document.getElementById('delete_category_id').value = id;
            document.getElementById('delete-message').innerHTML = `Are you sure you want to delete category "<strong>${escapeHTML(name)}</strong>"? <br>All linked products will be marked as Uncategorized.`;
            deleteModal.classList.remove('hidden');
        };

        document.getElementById('cancelBtn').addEventListener('click', () => categoryModal.classList.add('hidden'));
        document.getElementById('cancelDeleteBtn').addEventListener('click', () => deleteModal.classList.add('hidden'));

        categoryForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('submitBtn');
            const orig = btn.innerHTML;
            btn.innerHTML = '<span class="animate-spin material-icons text-sm">autorenew</span> Saving...';
            btn.disabled = true;
            try {
                const res = await fetch('', { method: 'POST', body: new FormData(categoryForm), headers: {'X-Requested-With': 'XMLHttpRequest'} });
                const data = await res.json();
                if (data.success) { categoryModal.classList.add('hidden'); showFlashMessage(data.message); fetchCategories(); }
                else if (data.errors) displayValidationErrors(data.errors);
                else showFlashMessage(data.message, 'error');
            } catch { showFlashMessage('Error saving category.', 'error'); } 
            finally { btn.innerHTML = orig; btn.disabled = false; }
        });

        deleteForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = deleteForm.querySelector('button[type="submit"]');
            const orig = btn.innerHTML;
            btn.innerHTML = '<span class="animate-spin material-icons text-sm">autorenew</span> Deleting...';
            btn.disabled = true;
            try {
                const res = await fetch('', { method: 'POST', body: new FormData(deleteForm), headers: {'X-Requested-With': 'XMLHttpRequest'} });
                const data = await res.json();
                deleteModal.classList.add('hidden');
                showFlashMessage(data.message, data.success ? 'success' : 'error');
                if (data.success) fetchCategories();
            } catch { showFlashMessage('Delete failed.', 'error'); } 
            finally { btn.innerHTML = orig; btn.disabled = false; }
        });

        window.toggleMenu = (id) => {
            document.querySelectorAll('.action-menu').forEach(m => m.classList.add('hidden'));
            document.getElementById(`menu-${id}`).classList.remove('hidden');
        };
        window.addEventListener('click', e => { if (!e.target.closest('.relative')) document.querySelectorAll('.action-menu').forEach(m => m.classList.add('hidden')); });

        const clearValidationErrors = () => { categoryForm.querySelectorAll('.error-message').forEach(e => e.textContent = ''); categoryForm.querySelectorAll('.is-invalid').forEach(e => e.classList.remove('is-invalid')); };
        const displayValidationErrors = (errs) => { clearValidationErrors(); for (const k in errs) { document.getElementById(k).classList.add('is-invalid'); document.getElementById(`error-${k}`).textContent = errs[k]; } };
        const escapeHTML = (str) => { if (typeof str !== 'string') return ''; const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }; return str.replace(/[&<>"']/g, m => map[m]); };
        const formatDateTime = (s) => { 
            if(!s) return 'N/A'; 
            const d = new Date(s); 
            return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }); 
        };

        fetchCategories();
    });
    </script>
</body>
</html>