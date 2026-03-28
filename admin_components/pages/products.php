<?php
// C:\xampp\htdocs\RalphPHP\admin_components\pages\products.php

// 1. START BUFFERING
ob_start();

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

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
} elseif (!function_exists('log_audit_action')) {
    function log_audit_action($a, $b, $c, $d = []) { return true; }
}

// --- CONNECTION CHECK ---
if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed. Error: " . (isset($conn) ? $conn->connect_error : 'Connection variable not set.'));
}

// --- SECURITY CHECK ---
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: ../admin_login.php");
    exit;
}

// ---------------------------------------------------------
// SERVER-SIDE CATEGORY LOADING
// ---------------------------------------------------------
$category_options = '<option value="">-- Select Category --</option>';
$filter_category_options = '<option value="all">All Categories</option>';
$cat_query = "SELECT * FROM categories ORDER BY category_name ASC";
$cat_result = $conn->query($cat_query);

if ($cat_result) {
    while ($row = $cat_result->fetch_assoc()) {
        $c_id = isset($row['category_id']) ? $row['category_id'] : (isset($row['id']) ? $row['id'] : null);
        $c_name = isset($row['category_name']) ? $row['category_name'] : (isset($row['name']) ? $row['name'] : 'Unknown');
        if ($c_id) {
            $category_options .= '<option value="' . $c_id . '">' . htmlspecialchars($c_name) . '</option>';
            $filter_category_options .= '<option value="' . $c_id . '">' . htmlspecialchars($c_name) . '</option>';
        }
    }
}

/**
 * Handles file uploads.
 */
function handle_upload($file_key, $image_type, $existing_url = '') {
    if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] == 0) {
        $relative_dir = ($image_type === 'main') ? "assets/img/products/main/" : "assets/img/products/thumbnails/";
        $target_dir = "../../" . $relative_dir;
        if (!is_dir($target_dir)) @mkdir($target_dir, 0777, true);
        
        $file_name = uniqid() . '-' . preg_replace("/[^a-zA-Z0-9.\-_]+/", "", basename($_FILES[$file_key]["name"]));
        $target_file = $target_dir . $file_name;
        
        if (move_uploaded_file($_FILES[$file_key]["tmp_name"], $target_file)) {
            if ($existing_url && file_exists("../../" . $existing_url)) @unlink("../../" . $existing_url);
            return $relative_dir . $file_name;
        }
    }
    return $existing_url;
}

// --- CENTRALIZED HANDLER WITH DETAILED AUDIT ---
function handle_request($conn) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
        ob_clean(); // Clean buffer
        header('Content-Type: application/json');
        
        if ($_GET['action'] == 'get_product' && isset($_GET['id'])) {
            $product_id = intval($_GET['id']);
            $stmt = $conn->prepare("
                SELECT p.*, pi.quantity, c.category_name, c.category_id as joined_cat_id
                FROM products p
                LEFT JOIN product_inventory pi ON p.product_id = pi.product_id
                LEFT JOIN categories c ON p.category_id = c.category_id
                WHERE p.product_id = ? LIMIT 1
            ");
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($product = $result->fetch_assoc()) {
                if(empty($product['category_id']) && !empty($product['joined_cat_id'])) {
                    $product['category_id'] = $product['joined_cat_id'];
                }
                echo json_encode(['success' => true, 'data' => $product]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Not found']);
            }
            $stmt->close();
            exit;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        ob_clean(); // Clean buffer
        header('Content-Type: application/json');
        $action = $_POST['action_type'] ?? '';
        $admin_id = $_SESSION["user_id"] ?? 0;

        // 1. ADD / EDIT PRODUCT
        if ($action === 'add_product' || $action === 'edit_product') {
            $is_edit = ($action === 'edit_product');
            $product_id = $is_edit ? intval($_POST['product_id']) : 0;
            
            $errors = [];
            if (empty(trim($_POST['name']))) $errors['name'] = 'Product name required.';
            if (empty($_POST['category_id'])) $errors['category_id'] = 'Category required.';
            if (!isset($_POST['price'])) $errors['price'] = 'Price required.';
            if (empty(trim($_POST['unit']))) $errors['unit'] = 'Unit required.';

            if (empty($errors)) {
                $conn->begin_transaction();
                try {
                    // Collect New Data
                    $name = trim($_POST['name']);
                    $category_id = (!empty($_POST['category_id'])) ? $_POST['category_id'] : NULL;
                    $description = trim($_POST['description']);
                    $specifications = trim($_POST['specifications']);
                    $price = floatval($_POST['price']);
                    
                    // Fused Weight Logic
                    $unit = trim($_POST['unit']);
                    $is_bulk_unit = in_array($unit, ['kg', 'g']);
                    $weight = (!$is_bulk_unit && !empty($_POST['weight'])) ? floatval($_POST['weight']) : 0;
                    
                    $expiration_date = $_POST['expiration_date'] ?? '';
                    if (empty($expiration_date) || $expiration_date === 'null') {
                        $expiration_date = NULL;
                    }

                    $status = $_POST['status'];
                    $product_brand = trim($_POST['product_brand']);
                    $quantity = floatval($_POST['quantity']);
                    $image_url = handle_upload('image_url', 'main', $_POST['existing_image_url'] ?? '');

                    // Auto-Disable Logic
                    if ($quantity <= 0.001) {
                        $status = 'Out of Stock';
                    }

                    // --- [STEP 1] FETCH OLD DATA FOR COMPARISON (CCTV LOGIC) ---
                    $changes = [];
                    $old_stock = 0;
                    $new_exp = (!empty($expiration_date) && strpos($expiration_date, '0000') === false) ? date('Y-m-d', strtotime($expiration_date)) : 'None';
                    
                    if ($is_edit) {
                        $old_stmt = $conn->prepare("SELECT p.*, pi.quantity FROM products p LEFT JOIN product_inventory pi ON p.product_id = pi.product_id WHERE p.product_id = ?");
                        $old_stmt->bind_param("i", $product_id);
                        $old_stmt->execute();
                        $old_data = $old_stmt->get_result()->fetch_assoc();
                        
                        if ($old_data) {
                            $old_stock = floatval($old_data['quantity'] ?? 0);
                            
                            if ($old_data['name'] !== $name) $changes[] = "Name changed to '$name'";
                            
                            $old_brand = trim($old_data['product_brand'] ?? '');
                            if ($old_brand !== $product_brand) $changes[] = "Brand: '$old_brand' -> '$product_brand'";
                            
                            if (floatval($old_data['price']) !== $price) $changes[] = "Price: " . number_format($old_data['price'], 2) . " -> " . number_format($price, 2);
                            
                            if ($old_data['status'] !== $status) $changes[] = "Status: {$old_data['status']} -> $status";
                            
                            $old_unit = isset($old_data['unit']) ? $old_data['unit'] : '';
                            if ($old_unit !== $unit) $changes[] = "Unit: '$old_unit' -> '$unit'";

                            // Explicit Expiry Date Logging
                            $old_exp = (!empty($old_data['expiration_date']) && strpos($old_data['expiration_date'], '0000') === false) ? date('Y-m-d', strtotime($old_data['expiration_date'])) : 'None';
                            if ($old_exp !== $new_exp) {
                                $changes[] = "Expiry: $old_exp -> $new_exp";
                            }
                            
                            if ($old_data['category_id'] != $category_id) {
                                $changes[] = "Category updated";
                            }

                            if (abs($old_stock - $quantity) > 0.001) $changes[] = "Stock: $old_stock -> $quantity";
                        }
                    }

                    // --- [STEP 2] PERFORM UPDATE/INSERT ---
                    if ($is_edit) {
                        $stmt = $conn->prepare("UPDATE products SET category_id=?, name=?, description=?, specifications=?, price=?, weight=?, unit=?, expiration_date=?, status=?, image_url=?, product_brand=? WHERE product_id=?");
                        $stmt->bind_param("sssdsdsssssi", $category_id, $name, $description, $specifications, $price, $weight, $unit, $expiration_date, $status, $image_url, $product_brand, $product_id);
                        if(!$stmt->execute()) throw new Exception($stmt->error);
                        
                        $conn->query("DELETE FROM product_inventory WHERE product_id = $product_id");
                        $stmt = $conn->prepare("INSERT INTO product_inventory (product_id, quantity) VALUES (?, ?)");
                        $stmt->bind_param("id", $product_id, $quantity);
                        $stmt->execute();
                    } else {
                        $stmt = $conn->prepare("INSERT INTO products (category_id, name, description, specifications, price, weight, unit, expiration_date, status, image_url, product_brand) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("sssdsdsssss", $category_id, $name, $description, $specifications, $price, $weight, $unit, $expiration_date, $status, $image_url, $product_brand);
                        if(!$stmt->execute()) throw new Exception($stmt->error);
                        $product_id = $conn->insert_id;
                        
                        $stmt = $conn->prepare("INSERT INTO product_inventory (product_id, quantity) VALUES (?, ?)");
                        $stmt->bind_param("id", $product_id, $quantity);
                        $stmt->execute();
                    }
                    
                    // Thumbnails
                    $thumb_urls = [];
                    for($i=1; $i<=4; $i++) $thumb_urls[$i] = handle_upload("thumbnail_img_$i", 'thumbnail', $_POST["existing_thumbnail_img_$i"] ?? '');
                    
                    $conn->query("DELETE FROM thumbnail WHERE product_id = $product_id");
                    $stmt = $conn->prepare("INSERT INTO thumbnail (product_id, thumbnail_img_1, thumbnail_img_2, thumbnail_img_3, thumbnail_img_4) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param("issss", $product_id, $thumb_urls[1], $thumb_urls[2], $thumb_urls[3], $thumb_urls[4]);
                    $stmt->execute();

                    $conn->commit();
                    
                    // --- [STEP 3] ID-TAGGED LOGGING FOR CCTV ---
                    if (function_exists('log_audit_action')) {
                        if ($is_edit) {
                            if (!empty($changes)) {
                                log_audit_action('Update Product', 'Products', "Modified '$name' (ID: $product_id). Changes: " . implode(", ", $changes));
                            } else {
                                log_audit_action('Update Product', 'Products', "Saved '$name' (ID: $product_id) with no changes.");
                            }
                        } else {
                            log_audit_action('Add Product', 'Products', "Added new product '$name' (ID: $product_id). Unit: $unit, Initial Stock: $quantity, Expiry: $new_exp");
                        }
                    }

                    echo json_encode(['success' => true, 'message' => "Product saved successfully!"]);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode(['success' => false, 'message' => "Error: " . $e->getMessage()]);
                }
            } else {
                echo json_encode(['success' => false, 'message' => "Validation errors.", 'errors' => $errors]);
            }
        }
        
        // 2. DELETE PRODUCT
        elseif ($action === 'delete_product') {
            $product_id = intval($_POST['delete_product_id'] ?? 0);
            if ($product_id > 0) {
                try {
                    $name_query = $conn->query("SELECT name FROM products WHERE product_id = $product_id");
                    $prod_name = ($name_query && $row = $name_query->fetch_assoc()) ? $row['name'] : "Unknown";

                    $conn->begin_transaction();
                    $conn->query("SET FOREIGN_KEY_CHECKS=0");
                    $conn->query("DELETE FROM product_inventory WHERE product_id = $product_id");
                    $conn->query("DELETE FROM thumbnail WHERE product_id = $product_id");
                    $conn->query("DELETE FROM products WHERE product_id = $product_id");
                    $conn->query("SET FOREIGN_KEY_CHECKS=1");
                    $conn->commit();

                    if (function_exists('log_audit_action')) {
                        log_audit_action('Delete Product', 'Products', "Permanently deleted '$prod_name' (ID: $product_id).");
                    }

                    echo json_encode(['success' => true, 'message' => "Product deleted."]);
                } catch (Exception $e) {
                    $conn->rollback();
                    $conn->query("SET FOREIGN_KEY_CHECKS=1");
                    echo json_encode(['success' => false, 'message' => "Failed: " . $e->getMessage()]);
                }
            }
        }
        
        // 3. UPDATE STOCK (Quick Action)
        elseif ($action === 'update_stock') {
            $product_id = intval($_POST['product_id'] ?? 0);
            $quantity = floatval($_POST['quantity'] ?? -1); 
            
            if ($product_id > 0 && $quantity >= 0) {
                $old_q_query = $conn->query("SELECT quantity FROM product_inventory WHERE product_id = $product_id");
                $old_quantity = ($old_q_query && $row = $old_q_query->fetch_assoc()) ? $row['quantity'] : 0;
                
                $name_query = $conn->query("SELECT name FROM products WHERE product_id = $product_id");
                $prod_name = ($name_query && $row = $name_query->fetch_assoc()) ? $row['name'] : "Unknown";

                $conn->query("DELETE FROM product_inventory WHERE product_id = $product_id");
                
                $stmt = $conn->prepare("INSERT INTO product_inventory (product_id, quantity) VALUES (?, ?)");
                $stmt->bind_param("id", $product_id, $quantity);
                $stmt->execute();
                
                $conn->query("UPDATE products SET status = 'Active' WHERE product_id = $product_id");

                if (function_exists('log_audit_action')) {
                    $log_details = "Quick Stock Update for '$prod_name' (ID: $product_id): " . floatval($old_quantity) . " -> " . floatval($quantity);
                    log_audit_action('Update Stock', 'Products', $log_details);
                }

                echo json_encode(['success' => true, 'message' => "Stock updated."]);
            }
        }
        
        // 4. BULK ACTIONS (DETAILED INDIVIDUAL LOGS)
        elseif ($action === 'bulk_action') {
            $raw_ids = $_POST['product_ids'] ?? [];
            if(is_string($raw_ids)) { $ids = array_map('intval', explode(',', $raw_ids)); } 
            else { $ids = array_map('intval', $raw_ids); }
            $type = $_POST['bulk_action_type'] ?? '';
            
            if(!empty($ids) && $type) {
                try {
                    $conn->begin_transaction();
                    $success_count = 0;

                    // Log individually to maintain history accurately per product
                    foreach($ids as $id) {
                        $res = $conn->query("SELECT name FROM products WHERE product_id = $id");
                        $p_name = ($res && $r = $res->fetch_assoc()) ? $r['name'] : "Product";

                        if($type === 'delete') {
                            $conn->query("SET FOREIGN_KEY_CHECKS=0");
                            $conn->query("DELETE FROM product_inventory WHERE product_id = $id");
                            $conn->query("DELETE FROM thumbnail WHERE product_id = $id");
                            $conn->query("DELETE FROM products WHERE product_id = $id");
                            $conn->query("SET FOREIGN_KEY_CHECKS=1");
                            if (function_exists('log_audit_action')) log_audit_action('Bulk Delete', 'Products', "Bulk Deleted '$p_name' (ID: $id).");
                        } elseif($type === 'activate') {
                            $conn->query("UPDATE products SET status='Active' WHERE product_id = $id");
                            if (function_exists('log_audit_action')) log_audit_action('Bulk Update', 'Products', "Bulk set '$p_name' (ID: $id) to Active.");
                        } elseif($type === 'deactivate') {
                            $conn->query("UPDATE products SET status='Inactive' WHERE product_id = $id");
                            if (function_exists('log_audit_action')) log_audit_action('Bulk Update', 'Products', "Bulk set '$p_name' (ID: $id) to Inactive.");
                        }
                        $success_count++;
                    }
                    
                    $conn->commit();
                    echo json_encode(['success' => true, 'message' => "Processed $success_count items."]);
                } catch (Exception $e) {
                    $conn->rollback();
                    $conn->query("SET FOREIGN_KEY_CHECKS=1");
                    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'No items selected.']);
            }
        }
        exit();
    }
}

handle_request($conn);

// 2. FLUSH BUFFER
ob_end_flush();

// --- PAGINATION & FILTERING ---
$products_per_page = 10;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $products_per_page;
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$brand_filter = isset($_GET['brand']) ? $_GET['brand'] : 'all';
$category_filter = isset($_GET['category']) ? $_GET['category'] : 'all';
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'newest';

// --- AUTO-DISABLE STATUS LOGIC (SQL) ---
$status_case = "CASE 
    WHEN p.status = 'Inactive' THEN 'Inactive'
    WHEN COALESCE(pi.quantity, 0) <= 0.001 THEN 'Out of Stock' 
    WHEN p.expiration_date IS NOT NULL AND p.expiration_date != '0000-00-00' AND p.expiration_date < CURDATE() THEN 'Expired'
    WHEN (p.expiration_date IS NOT NULL AND p.expiration_date != '0000-00-00' AND p.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)) 
          AND (COALESCE(pi.quantity, 0) <= " . LOW_STOCK_THRESHOLD . ") 
    THEN 'Expiring & Low Stock'
    WHEN p.expiration_date IS NOT NULL AND p.expiration_date != '0000-00-00' AND p.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'Expiring Soon'
    WHEN COALESCE(pi.quantity, 0) <= " . LOW_STOCK_THRESHOLD . " THEN 'Low Stock'
    ELSE 'Active'
END";

$where_clauses = []; 
if (!empty($search_term)) $where_clauses[] = "(p.name LIKE '%$search_term%' OR p.product_brand LIKE '%$search_term%')";
if ($brand_filter !== 'all') $where_clauses[] = "p.product_brand = '" . $conn->real_escape_string($brand_filter) . "'";
if ($category_filter !== 'all') $where_clauses[] = "p.category_id = '" . $conn->real_escape_string($category_filter) . "'";
if ($status_filter !== 'all') $where_clauses[] = "($status_case) = '" . $conn->real_escape_string($status_filter) . "'";

$where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$sort_sql = match($sort_by) {
    'oldest' => 'p.created_at ASC',
    'name_asc' => 'p.name ASC',
    'name_desc' => 'p.name DESC',
    'price_asc' => 'p.price ASC',
    'price_desc' => 'p.price DESC',
    default => 'p.created_at DESC'
};

$count_res = $conn->query("SELECT COUNT(DISTINCT p.product_id) as count FROM products p LEFT JOIN product_inventory pi ON p.product_id = pi.product_id $where_sql");
$total_products_count = $count_res->fetch_assoc()['count'];
$total_pages = ceil($total_products_count / $products_per_page);

$products_sql = "SELECT p.*, MAX(pi.quantity) as quantity, c.category_name, $status_case as computed_status
                 FROM products p 
                 LEFT JOIN product_inventory pi ON p.product_id = pi.product_id 
                 LEFT JOIN categories c ON p.category_id = c.category_id 
                 $where_sql 
                 GROUP BY p.product_id 
                 ORDER BY $sort_sql LIMIT $products_per_page OFFSET $offset";
$products_data = $conn->query($products_sql)->fetch_all(MYSQLI_ASSOC);

if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['products' => $products_data, 'pagination' => ['total_products' => $total_products_count, 'total_pages' => $total_pages, 'current_page' => $current_page]]);
    exit;
}

$total_products = $conn->query("SELECT COUNT(*) as c FROM products")->fetch_assoc()['c'];
$active_products = $conn->query("SELECT COUNT(*) as c FROM products p JOIN product_inventory pi ON p.product_id = pi.product_id WHERE p.status = 'Active' AND pi.quantity > 0.001")->fetch_assoc()['c'];
$out_of_stock_products = $conn->query("SELECT COUNT(*) as c FROM product_inventory WHERE quantity <= 0.001")->fetch_assoc()['c'];
$total_stock_value = $conn->query("SELECT SUM(p.price * pi.quantity) as v FROM products p JOIN product_inventory pi ON p.product_id = pi.product_id WHERE p.status='Active'")->fetch_assoc()['v'] ?? 0;
$brands = $conn->query("SELECT DISTINCT product_brand FROM products WHERE product_brand != '' ORDER BY product_brand ASC")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreshFlow - Product Management</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class' };
    </script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/jpeg" href="/admin_components/assets/img/tabicon4.png">
    <script>
        // DARK MODE INITIALIZATION (Prevents White Flash)
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
        
        window.onpageshow = function(event) { if (event.persisted) window.location.reload(); };
        window.onbeforeunload = function() { 
            document.body.innerHTML = ""; 
            if (document.documentElement.classList.contains('dark')) {
                document.body.style.backgroundColor = "#000000"; 
            } else {
                document.body.style.backgroundColor = "#F8F5EE"; 
            }
        };
    </script>

    <style>
        :root { --brand-green: #1E3A1D; --brand-cream: #F8F5EE; }
        body { font-family: 'Inter', sans-serif; background-color: var(--brand-cream); color: #2B2B2B; transition: background-color 0.3s ease; }
        
        /* --- DARK MODE GLOBAL STYLES --- */
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
        
        /* Status Badges */
        .status-badge { padding: 4px 12px; border-radius: 99px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; display: inline-block; }
        .status-Active { background-color: #dcfce7; color: #166534; } 
        .dark .status-Active { background-color: rgba(22, 101, 52, 0.3); color: #4ade80; border: 1px solid rgba(74, 222, 128, 0.2); }
        .status-Inactive { background-color: #f3f4f6; color: #4b5563; }
        .dark .status-Inactive { background-color: rgba(75, 85, 99, 0.3); color: #9ca3af; border: 1px solid rgba(156, 163, 175, 0.2); }
        .status-Out-of-Stock { background-color: #fee2e2; color: #991b1b; } 
        .dark .status-Out-of-Stock { background-color: rgba(153, 27, 27, 0.3); color: #f87171; border: 1px solid rgba(248, 113, 113, 0.2); }
        .status-Low-Stock { background-color: #fef9c3; color: #854d0e; }
        .dark .status-Low-Stock { background-color: rgba(133, 77, 14, 0.3); color: #facc15; border: 1px solid rgba(250, 204, 21, 0.2); }
        .status-Expiring-Soon { background-color: #ffedd5; color: #9a3412; }
        .dark .status-Expiring-Soon { background-color: rgba(154, 52, 18, 0.3); color: #fb923c; border: 1px solid rgba(251, 146, 60, 0.2); }
        .status-Expiring-Low-Stock { background-color: #fdba74; color: #9a3412; }
        .dark .status-Expiring-Low-Stock { background-color: rgba(154, 52, 18, 0.5); color: #f97316; border: 1px solid rgba(249, 115, 22, 0.3); }
        .status-Expired { background-color: #e5e7eb; color: #374151; text-decoration: line-through; }
        .dark .status-Expired { background-color: rgba(55, 65, 81, 0.3); color: #6b7280; border: 1px solid rgba(107, 114, 128, 0.2); text-decoration: line-through; }
        
        @keyframes slideUp { from { transform: translateY(100%); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .toast { animation: slideUp 0.3s ease-out; }
        
        /* Form Inputs */
        .form-input, .filter-select { background-color: #ffffff; border: 1px solid #d1d5db; color: #374151; border-radius: 0.5rem; transition: all 0.2s; }
        .form-input:focus, .filter-select:focus { outline: none; border-color: var(--brand-green); box-shadow: 0 0 0 3px rgba(30, 58, 29, 0.1); }
        .form-input:disabled { background-color: #f3f4f6; cursor: not-allowed; color: #9ca3af; }
        .dark .form-input, .dark .filter-select { background-color: rgba(30, 41, 59, 0.6); border-color: #334155; color: #f8fafc; }
        .dark .form-input:focus, .dark .filter-select:focus { border-color: #4ade80; box-shadow: 0 0 0 3px rgba(74, 222, 128, 0.15); }
        .dark .form-input:disabled { background-color: rgba(15, 23, 42, 0.4); color: #64748b; border-color: #1e293b; }

        /* Content Card Styles for Glowing Hover */
        .content-card { 
            background-color: #ffffff; 
            border: 1px solid #e5e7eb; 
            border-radius: 0.75rem; 
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); 
            transition: all 0.3s ease; 
        }
        .dark .content-card {
            background-color: rgba(15, 23, 42, 0.6); 
            backdrop-filter: blur(12px);
            border-color: #1e293b; 
        }

        #filterOptions { transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.4s ease, padding 0.4s ease, margin 0.4s ease; border-color 0.4s ease; max-height: 0; overflow: hidden; opacity: 0; padding-top: 0; margin-top: 0; border-top: 1px solid transparent; }
        #filterOptions.open { max-height: 500px; opacity: 1; padding-top: 1rem; margin-top: 1rem; border-top-color: #e5e7eb; }
        .dark #filterOptions.open { border-top-color: #334155; }
        
        .relative-cell { position: relative; }
        .action-menu { z-index: 1000 !important; position: absolute; right: 3.5rem; top: 10px; min-width: 150px; background: white; border: 1px solid #e5e7eb; border-radius: 0.5rem; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
        .dark .action-menu { background: #1e293b; border-color: #334155; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.5); }

        #floatingBulkBar { transition: transform 0.3s ease-in-out; }
        #floatingBulkBar.hidden-bar { transform: translate(-50%, 150%); }
    </style>
</head>
<body>
    
    <?php include '../includes/sidebar.php'; ?>

    <main class="ml-20 transition-all duration-300 p-6 md:p-8">
        
        <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 flex-shrink-0">
            <div>
                <h1 class="text-3xl font-bold text-[#1E3A1D] dark:text-white tracking-tight flex items-center gap-3">
                    <span class="material-icons text-3xl dark:text-green-400">inventory_2</span>
                    Product Management
                </h1>
                <p class="text-sm text-gray-500 dark:text-slate-400 mt-1 ml-11">Manage inventory, prices, and stock levels</p>
            </div>
            <button id="addProductBtn" class="bg-[#1E3A1D] dark:bg-green-700 hover:bg-[#2a4e29] dark:hover:bg-green-600 text-white px-5 py-2.5 rounded-lg font-bold shadow-lg flex items-center gap-2 transition transform active:scale-95 mt-4 md:mt-0 border border-transparent dark:border-green-500">
                <span class="material-icons text-sm">add</span> Add Product
            </button>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8 text-center flex-shrink-0">
            <div class="content-card p-5 border-l-4 border-blue-500 dark:border-blue-400 flex items-center justify-between group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(59,130,246,0.2)] dark:hover:shadow-[0_0_20px_rgba(59,130,246,0.3)] dark:hover:border-blue-300 transition-all duration-300">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-blue-500 dark:group-hover:text-blue-300 transition-colors">Total Products</p>
                    <p class="text-3xl font-bold text-blue-600 dark:text-blue-400 mt-1 font-mono group-hover:scale-110 transition-transform origin-left"><?= number_format($total_products) ?></p>
                </div>
                <div class="bg-blue-50 dark:bg-blue-900/30 p-3 rounded-full text-blue-600 dark:text-blue-400 group-hover:bg-blue-100 dark:group-hover:bg-blue-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">inventory_2</span>
                </div>
            </div>

            <div class="content-card p-5 border-l-4 border-green-500 dark:border-green-400 flex items-center justify-between group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(34,197,94,0.2)] dark:hover:shadow-[0_0_20px_rgba(34,197,94,0.3)] dark:hover:border-green-300 transition-all duration-300">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-green-500 dark:group-hover:text-green-300 transition-colors">Active Listings</p>
                    <p class="text-3xl font-bold text-green-600 dark:text-green-400 mt-1 font-mono group-hover:scale-110 transition-transform origin-left"><?= number_format($active_products) ?></p>
                </div>
                <div class="bg-green-50 dark:bg-green-900/30 p-3 rounded-full text-green-600 dark:text-green-400 group-hover:bg-green-100 dark:group-hover:bg-green-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">check_circle</span>
                </div>
            </div>

            <div class="content-card p-5 border-l-4 border-red-500 dark:border-red-400 flex items-center justify-between group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(239,68,68,0.2)] dark:hover:shadow-[0_0_20px_rgba(239,68,68,0.3)] dark:hover:border-red-300 transition-all duration-300">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-red-500 dark:group-hover:text-red-300 transition-colors">Out of Stock</p>
                    <p class="text-3xl font-bold text-red-600 dark:text-red-400 mt-1 font-mono group-hover:scale-110 transition-transform origin-left"><?= number_format($out_of_stock_products) ?></p>
                </div>
                <div class="bg-red-50 dark:bg-red-900/30 p-3 rounded-full text-red-600 dark:text-red-400 group-hover:bg-red-100 dark:group-hover:bg-red-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">remove_shopping_cart</span>
                </div>
            </div>

            <div class="content-card p-5 border-l-4 border-orange-500 dark:border-orange-400 flex items-center justify-between group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(249,115,22,0.2)] dark:hover:shadow-[0_0_20px_rgba(249,115,22,0.3)] dark:hover:border-orange-300 transition-all duration-300">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-orange-500 dark:group-hover:text-orange-300 transition-colors">Stock Value</p>
                    <p class="text-2xl font-bold text-orange-600 dark:text-orange-400 mt-1 font-mono group-hover:scale-110 transition-transform origin-left">₱<?= number_format($total_stock_value/1000, 1) ?>k</p>
                </div>
                <div class="bg-orange-50 dark:bg-orange-900/30 p-3 rounded-full text-orange-600 dark:text-orange-400 group-hover:bg-orange-100 dark:group-hover:bg-orange-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">attach_money</span>
                </div>
            </div>
        </div>

        <div class="content-card p-4 mb-6 relative z-20 flex-shrink-0">
            <div class="flex flex-col md:flex-row items-center gap-4">
                <div class="relative w-full flex-grow">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400 dark:text-slate-500"><span class="material-icons">search</span></span>
                    <input type="text" id="searchInput" placeholder="Search products..." value="<?= htmlspecialchars($search_term) ?>" class="w-full pl-10 p-2 rounded-lg form-input transition" autocomplete="off">
                </div>
                <button id="toggleFilterBtn" class="bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 hover:bg-gray-50 dark:hover:bg-slate-700 text-gray-700 dark:text-slate-200 font-bold py-2 px-4 rounded-lg flex items-center gap-2 transition shadow-sm">
                    <span class="material-icons">filter_list</span> Filters
                </button>
            </div>
            
<div id="filterOptions">
                <div class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end mt-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 mb-1 uppercase">Status</label>
                        <select id="statusFilter" class="w-full p-2 filter-select cursor-pointer">
                            <option value="all">All Statuses</option>
                            <option value="Active">Active</option>
                            <option value="Low Stock">Low Stock</option>
                            <option value="Out of Stock">Out of Stock</option>
                            <option value="Expiring Soon">Expiring Soon</option>
                            <option value="Expiring & Low Stock">Expiring & Low Stock</option>
                            <option value="Expired">Expired</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 mb-1 uppercase">Category</label>
                        <select id="categoryFilter" class="w-full p-2 filter-select cursor-pointer">
                            <?= $filter_category_options ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 mb-1 uppercase">Brand</label>
                        <select id="brandFilter" class="w-full p-2 filter-select cursor-pointer">
                            <option value="all">All Brands</option>
                            <?php foreach ($brands as $brand): ?>
                            <option value="<?= htmlspecialchars($brand['product_brand']) ?>"><?= htmlspecialchars($brand['product_brand']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 mb-1 uppercase">Sort By</label>
                        <select id="sortFilter" class="w-full p-2 filter-select cursor-pointer">
                            <option value="newest">Newest</option>
                            <option value="oldest">Oldest</option>
                            <option value="price_asc">Price: Low to High</option>
                            <option value="price_desc">Price: High to Low</option>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button id="resetFilters" class="bg-gray-200 dark:bg-slate-700 hover:bg-gray-300 dark:hover:bg-slate-600 text-gray-700 dark:text-slate-300 border border-transparent dark:border-slate-600 font-bold py-2 px-4 rounded-lg text-sm w-full transition">Reset</button>
                        <button id="applyFilters" class="bg-[#1E3A1D] dark:bg-green-700 hover:bg-[#2a4e29] dark:hover:bg-green-600 text-white font-bold py-2 px-4 rounded-lg text-sm w-full shadow transition">Apply</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="content-card flex-1 overflow-hidden flex flex-col min-h-[500px] mb-20">
            <div class="overflow-y-auto flex-1 custom-scroll pb-20"> 
                <table class="w-full text-left table-fixed min-w-[1000px]">
                    <thead class="bg-[#1E3A1D] dark:bg-slate-800 text-white text-xs uppercase font-bold sticky top-0 z-10">
                        <tr>
                            <th class="p-4 w-10 text-center"><input type="checkbox" id="selectAllCheckbox" class="w-4 h-4 rounded text-[#1E3A1D] dark:bg-slate-800 border-gray-300 dark:border-slate-600 cursor-pointer"></th>
                            <th class="p-4 w-64">Product Details</th>
                            <th class="p-4 w-32">Category</th>
                            <th class="p-4 w-24">Expiry</th>
                            <th class="p-4 w-24">Price</th>
                            <th class="p-4 w-24 text-right">Stock</th>
                            <th class="p-4 w-32 text-center">Status</th>
                            <th class="p-4 w-24 text-right pr-6">Action</th>
                        </tr>
                    </thead>
                    <tbody id="productsTableBody" class="text-sm text-gray-700 dark:text-slate-200 divide-y divide-gray-100 dark:divide-slate-700/50">
                        </tbody>
                </table>
            </div>
            <div class="p-4 border-t border-gray-100 dark:border-slate-700 flex justify-between items-center bg-gray-50 dark:bg-slate-900 flex-shrink-0" id="pagination-container"></div>
        </div>
    </main>

<div id="floatingBulkBar" class="fixed bottom-6 left-1/2 transform -translate-x-1/2 z-[90] hidden-bar">
        <div class="bg-[#1E3A1D] dark:bg-slate-800 text-white px-6 py-3 rounded-full shadow-2xl flex items-center gap-6 border border-green-800 dark:border-slate-600">
            <span class="font-bold whitespace-nowrap text-sm" id="selectionCount">0 Selected</span>
            <div class="h-6 w-px bg-green-700 dark:bg-slate-600"></div>
            <div class="flex items-center gap-3">
                <button onclick="executeBulk('activate')" class="hover:bg-[#2a4e29] dark:hover:bg-slate-700 px-3 py-1.5 rounded-lg text-xs font-bold transition flex items-center gap-1">
                    <span class="material-icons text-sm">check_circle</span> Set Active
                </button>
                <button onclick="executeBulk('deactivate')" class="hover:bg-[#2a4e29] dark:hover:bg-slate-700 px-3 py-1.5 rounded-lg text-xs font-bold transition flex items-center gap-1">
                    <span class="material-icons text-sm">block</span> Set Inactive
                </button>
                <button onclick="confirmBulkDelete()" class="bg-red-600 dark:bg-red-700 hover:bg-red-700 dark:hover:bg-red-800 px-3 py-1.5 rounded-lg text-xs font-bold transition flex items-center gap-1 shadow-md ml-2 border border-transparent dark:border-red-600">
                    <span class="material-icons text-sm">delete</span> Delete
                </button>
            </div>
            <div class="h-6 w-px bg-green-700 dark:bg-slate-600"></div>
            <button id="cancelSelection" class="text-green-200 dark:text-slate-400 hover:text-white dark:hover:text-white text-xs font-bold transition">Cancel</button>
        </div>
    </div>

    <div id="flashMessage" class="fixed bottom-6 right-6 z-[100] bg-[#1E3A1D] dark:bg-slate-800 text-white px-6 py-4 rounded-lg shadow-2xl flex items-center gap-3 transform translate-y-20 transition-all duration-300 opacity-0 pointer-events-none border dark:border-slate-700">
        <span class="material-icons text-green-400">check_circle</span>
        <div><h4 class="font-bold text-sm">Notification</h4><p class="text-xs text-gray-300" id="flashText"></p></div>
    </div>

    <div id="bulkDeleteModal" class="fixed inset-0 bg-black bg-opacity-60 dark:bg-opacity-80 flex justify-center items-center z-[100] hidden transition-opacity duration-300 backdrop-blur-sm">
        <div class="bg-white dark:bg-slate-900 rounded-xl shadow-2xl w-full max-w-sm p-8 text-center border-t-4 border-red-600 dark:border-red-500">
             <div class="w-16 h-16 bg-red-50 dark:bg-red-900/30 rounded-full flex items-center justify-center mx-auto mb-5">
                <span class="material-icons text-red-600 dark:text-red-500 text-3xl">delete_sweep</span>
             </div>
             <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-2">Delete Multiple Items?</h2>
             <p class="text-gray-500 dark:text-slate-400 mb-6 text-sm leading-relaxed">
                 You are about to delete <strong id="bulkDeleteCount" class="text-red-600 dark:text-red-400">0</strong> products.<br>
                 <span class="text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-slate-500">This action cannot be undone.</span>
             </p>
             <div class="flex justify-center gap-3">
                <button type="button" id="cancelBulkDeleteBtn" class="px-5 py-2.5 rounded-lg text-sm font-bold text-gray-600 dark:text-slate-300 bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 transition border border-transparent dark:border-slate-600">Cancel</button>
                <button type="button" onclick="executeBulk('delete')" class="px-5 py-2.5 rounded-lg text-sm font-bold text-white bg-red-600 dark:bg-red-700 hover:bg-red-700 dark:hover:bg-red-800 shadow-md transition border border-transparent dark:border-red-600">Yes, Delete All</button>
             </div>
        </div>
    </div>

    <div id="productModal" class="fixed inset-0 bg-[#1E3A1D] dark:bg-black bg-opacity-40 dark:bg-opacity-70 hidden flex items-center justify-center z-50 backdrop-blur-sm transition-opacity">
        <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-4xl max-h-[90vh] overflow-y-auto custom-scroll border dark:border-slate-700">
            <form id="productForm" enctype="multipart/form-data">
                <input type="hidden" name="action_type" id="action_type">
                <input type="hidden" name="product_id" id="product_id">
                <div class="bg-[#1E3A1D] dark:bg-slate-800 p-6 text-white flex justify-between items-center sticky top-0 z-10 shadow-sm">
                    <h2 id="modalTitle" class="text-xl font-bold flex items-center gap-2"><span class="material-icons">inventory</span> Product Details</h2>
                    <button type="button" id="cancelBtn" class="hover:text-gray-300 transition"><span class="material-icons">close</span></button>
                </div>
                <div class="p-8 space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <div class="space-y-4">
                            <div><label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Product Name *</label><input type="text" id="name" name="name" class="w-full p-2.5 form-input" required></div>
                            <div class="grid grid-cols-2 gap-4">
                                <div><label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Category *</label><select id="category_id" name="category_id" class="w-full p-2.5 form-input" required><?= $category_options ?></select></div>
                                <div><label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Brand</label><input type="text" id="product_brand" name="product_brand" class="w-full p-2.5 form-input"></div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div><label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Price (₱) *</label><input type="number" id="price" name="price" step="0.01" min="0" class="w-full p-2.5 form-input" required></div>
                                <div>
                                    <label id="stock-label" class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Stock (Qty) *</label>
                                    <input type="number" id="quantity" name="quantity" min="0" step="0.01" class="w-full p-2.5 form-input" required>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Unit *</label>
                                    <select id="unit" name="unit" class="w-full p-2.5 form-input" required>
                                        <option value="pcs">Pieces (pcs)</option>
                                        <option value="kg">Kilograms (kg)</option>
                                        <option value="g">Grams (g)</option>
                                        <option value="pack">Pack</option>
                                        <option value="box">Box</option>
                                        <option value="liter">Liter</option>
                                        <option value="bottle">Bottle</option>
                                    </select>
                                </div>
                                <div id="weight-container">
                                    <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Weight</label>
                                    <input type="number" id="weight" name="weight" step="0.01" class="w-full p-2.5 form-input" placeholder="Optional">
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div><label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Expiry Date</label><input type="date" id="expiration_date" name="expiration_date" class="w-full p-2.5 form-input"></div>
                                <div><label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Status</label><select id="status" name="status" class="w-full p-2.5 form-input"><option value="Active">Active</option><option value="Inactive">Inactive</option></select></div>
                            </div>
                        </div>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Main Image</label>
                                <div class="relative border-2 border-dashed border-gray-300 dark:border-slate-600 rounded-xl p-2 hover:border-[#1E3A1D] dark:hover:border-green-500 bg-gray-50 dark:bg-slate-800 transition group cursor-pointer" onclick="document.querySelector('input[name=image_url]').click()">
                                    <img id="image_url_preview" src="../../assets/img/placeholder.png" class="w-full h-40 object-contain mix-blend-multiply dark:mix-blend-normal rounded-lg">
                                    <div class="absolute inset-0 flex items-center justify-center bg-black bg-opacity-0 group-hover:bg-opacity-10 dark:group-hover:bg-opacity-40 rounded-xl transition">
                                        <span class="bg-white dark:bg-slate-700 dark:text-white px-3 py-1 rounded text-xs font-bold shadow opacity-0 group-hover:opacity-100 transition">Upload</span>
                                    </div>
                                </div>
                                <input type="file" name="image_url" class="hidden" onchange="previewImage(this, 'image_url_preview')">
                                <input type="hidden" name="existing_image_url" id="existing_image_url">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Description</label>
                                <textarea id="description" name="description" rows="3" class="w-full p-2.5 form-input resize-none"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end pt-6 border-t border-gray-100 dark:border-slate-700">
                        <button type="submit" id="submitBtn" class="bg-[#1E3A1D] dark:bg-green-700 hover:bg-[#2a4e29] dark:hover:bg-green-600 text-white font-bold py-3 px-8 rounded-lg shadow-lg transition transform hover:-translate-y-0.5 border border-transparent dark:border-green-500">Save Product</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

<div id="viewProductModal" class="fixed inset-0 bg-black bg-opacity-50 dark:bg-opacity-80 flex justify-center items-center z-50 hidden transition-opacity duration-300 backdrop-blur-sm">
        <div class="bg-white dark:bg-slate-900 rounded-xl shadow-2xl w-full max-w-4xl overflow-hidden relative border dark:border-slate-700">
            <button id="closeViewBtn" class="absolute top-4 right-4 text-gray-400 dark:text-slate-500 hover:text-gray-700 dark:hover:text-white transition z-10 p-2 rounded-full hover:bg-gray-100 dark:hover:bg-slate-800"><span class="material-icons">close</span></button>
            <div class="p-8">
                <div class="flex items-start justify-between mb-6">
                    <div>
                        <h2 id="viewModalTitle" class="text-3xl font-extrabold text-[#1E3A1D] dark:text-white tracking-tight">Product Details</h2>
                        <span id="view_status" class="inline-block mt-2"></span>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div class="bg-gray-50 dark:bg-slate-800 rounded-xl border border-gray-100 dark:border-slate-700 p-4 flex items-center justify-center h-[400px]">
                        <img id="view_image_url" src="" class="w-full h-full object-contain mix-blend-multiply dark:mix-blend-normal rounded-lg">
                    </div>
                    <div class="space-y-0">
                        <div class="flex justify-between items-center py-3 border-b border-gray-100 dark:border-slate-700"><span class="text-xs font-bold text-gray-400 dark:text-slate-500 uppercase tracking-widest">Price</span><span id="view_price" class="text-xl font-bold text-[#1E3A1D] dark:text-green-400 font-mono"></span></div>
                        <div class="flex justify-between items-center py-3 border-b border-gray-100 dark:border-slate-700"><span class="text-xs font-bold text-gray-400 dark:text-slate-500 uppercase tracking-widest">Stock</span><span id="view_quantity" class="text-sm font-bold text-gray-800 dark:text-slate-200 font-mono"></span></div>
                        <div class="flex justify-between items-center py-3 border-b border-gray-100 dark:border-slate-700"><span class="text-xs font-bold text-gray-400 dark:text-slate-500 uppercase tracking-widest">Unit Type</span><span id="view_unit" class="text-sm text-gray-600 dark:text-slate-300"></span></div>
                        <div class="flex justify-between items-center py-3 border-b border-gray-100 dark:border-slate-700"><span class="text-xs font-bold text-gray-400 dark:text-slate-500 uppercase tracking-widest">Brand</span><span id="view_product_brand" class="text-sm text-gray-600 dark:text-slate-300"></span></div>
                        <div class="flex justify-between items-center py-3 border-b border-gray-100 dark:border-slate-700"><span class="text-xs font-bold text-gray-400 dark:text-slate-500 uppercase tracking-widest">Category</span><span id="view_category_name" class="text-sm text-gray-600 dark:text-slate-300 font-semibold"></span></div>
                        <div id="view_weight_row" class="flex justify-between items-center py-3 border-b border-gray-100 dark:border-slate-700"><span class="text-xs font-bold text-gray-400 dark:text-slate-500 uppercase tracking-widest">Weight</span><span id="view_weight" class="text-sm text-gray-600 dark:text-slate-300 font-mono"></span></div>
                        <div class="flex justify-between items-center py-3 border-b border-gray-100 dark:border-slate-700"><span class="text-xs font-bold text-gray-400 dark:text-slate-500 uppercase tracking-widest">Expiration</span><span id="view_expiration" class="text-sm text-gray-600 dark:text-slate-300 font-mono"></span></div>
                        <div class="pt-4">
                            <span class="text-xs font-bold text-gray-400 dark:text-slate-500 uppercase tracking-widest block mb-2">Description</span>
                            <p id="view_description" class="text-sm text-gray-600 dark:text-slate-300 leading-relaxed bg-gray-50 dark:bg-slate-800 p-3 rounded-lg border border-gray-100 dark:border-slate-700"></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="deleteModal" class="fixed inset-0 bg-black bg-opacity-50 dark:bg-opacity-80 flex justify-center items-center z-50 hidden transition-opacity duration-300 backdrop-blur-sm">
        <div class="bg-white dark:bg-slate-900 rounded-xl shadow-2xl w-full max-w-sm p-8 text-center border border-gray-100 dark:border-slate-700">
             <div class="w-16 h-16 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center mx-auto mb-5 shadow-inner">
                <span class="material-icons text-red-600 dark:text-red-400 text-3xl">delete_forever</span>
             </div>
             <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-2">Delete This Product?</h2>
             <p class="text-gray-500 dark:text-slate-400 mb-8 text-sm leading-relaxed">You are about to permanently delete this item. This action cannot be undone.</p>
             <form id="deleteForm">
                 <input type="hidden" name="action_type" value="delete_product">
                 <input type="hidden" id="delete_product_id" name="delete_product_id" value="">
                 <div class="flex justify-center gap-3">
                    <button type="button" id="cancelDeleteBtn" class="px-5 py-2.5 rounded-lg text-sm font-bold text-gray-600 dark:text-slate-300 bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 transition border border-transparent dark:border-slate-600">Cancel</button>
                    <button type="submit" class="px-5 py-2.5 rounded-lg text-sm font-bold text-white bg-red-600 dark:bg-red-700 hover:bg-red-700 dark:hover:bg-red-800 shadow-md transition border border-transparent dark:border-red-600">Yes, Delete</button>
                 </div>
             </form>
        </div>
    </div>

    <script>
    function previewImage(i, p) { if (i.files && i.files[0]) { const r = new FileReader(); r.onload = e => document.getElementById(p).src = e.target.result; r.readAsDataURL(i.files[0]) } }

    document.addEventListener('DOMContentLoaded', () => {
        const productModal = document.getElementById('productModal');
        const viewProductModal = document.getElementById('viewProductModal');
        const deleteModal = document.getElementById('deleteModal');
        const bulkDeleteModal = document.getElementById('bulkDeleteModal'); 
        const productForm = document.getElementById('productForm');
        const tableBody = document.getElementById('productsTableBody');
        const paginationContainer = document.getElementById('pagination-container');
        const flashMessage = document.getElementById('flashMessage');
        const flashText = document.getElementById('flashText');
        const unitSelect = document.getElementById('unit');
        const weightInput = document.getElementById('weight');
        const weightContainer = document.getElementById('weight-container');
        const stockLabel = document.getElementById('stock-label');
        const defaultPlaceholder = '../../assets/img/placeholder.png'; 

        let currentFilters = new URLSearchParams(window.location.search);

        // --- FILTER TOGGLE ---
        document.getElementById('toggleFilterBtn').addEventListener('click', () => {
             const fo = document.getElementById('filterOptions');
             fo.classList.toggle('open');
        });

        // --- LIVE SEARCH ---
        let searchDebounceTimeout;
        document.getElementById('searchInput').addEventListener('input', (e) => {
            clearTimeout(searchDebounceTimeout);
            searchDebounceTimeout = setTimeout(() => {
                currentFilters.set('search', e.target.value);
                currentFilters.set('page', 1);
                fetchProducts(1);
            }, 300); // 300ms delay to wait for user to finish typing
        });

        // --- UNIT LOGIC (FUSED) ---
        const updateWeightField = () => {
            const val = unitSelect.value;
            const bulkUnits = ['kg', 'g'];
            
            if (bulkUnits.includes(val)) {
                weightContainer.style.display = 'none'; 
                weightInput.value = ''; 
                stockLabel.textContent = `Total Weight (${val}) *`;
                document.getElementById('quantity').placeholder = 'e.g. 18.6';
                document.getElementById('quantity').step = "0.01"; 
            } else {
                weightContainer.style.display = 'block'; 
                stockLabel.textContent = 'Stock (Qty) *';
                document.getElementById('quantity').placeholder = '0';
                document.getElementById('quantity').step = "1"; 
            }
        };
        unitSelect.addEventListener('change', updateWeightField);

let flashTimeout;
        const showFlash = (msg, type = 'success') => {
            if(flashTimeout) clearTimeout(flashTimeout);
            flashText.textContent = msg;
            // Updated to handle dark mode backgrounds and borders for the flash toast
            flashMessage.className = `fixed bottom-6 right-6 z-[100] text-white px-6 py-4 rounded-lg shadow-2xl flex items-center gap-3 transform transition-all duration-300 border dark:border-slate-700 ${type === 'error' || type === 'delete' ? 'bg-red-600 dark:bg-red-700' : 'bg-[#1E3A1D] dark:bg-slate-800'}`;
            flashMessage.classList.remove('translate-y-20', 'opacity-0');
            flashTimeout = setTimeout(() => { flashMessage.classList.add('translate-y-20', 'opacity-0'); }, 3000);
        };
        
        const closeModal = (modal) => modal.classList.add('hidden');
        const openModal = (modal) => modal.classList.remove('hidden');

        const fetchJSON = async (url, options = {}) => {
            options.headers = { ...options.headers, 'X-Requested-With': 'XMLHttpRequest' };
            const response = await fetch(url, options);
            if (!response.ok) throw new Error(`HTTP error!`);
            return response.json();
        };

        // --- MODAL TRIGGERS ---
        document.getElementById('addProductBtn').addEventListener('click', () => {
            productForm.reset();
            document.getElementById('modalTitle').innerHTML = '<span class="material-icons">add_box</span> Add Product';
            document.getElementById('action_type').value = 'add_product';
            document.getElementById('product_id').value = '';
            document.getElementById('image_url_preview').src = defaultPlaceholder;
            updateWeightField();
            openModal(productModal);
        });

        document.getElementById('cancelBtn').addEventListener('click', () => closeModal(productModal));
        document.getElementById('closeViewBtn').addEventListener('click', () => closeModal(viewProductModal));
        document.getElementById('cancelDeleteBtn').addEventListener('click', () => closeModal(deleteModal));
        
        if(document.getElementById('cancelBulkDeleteBtn')) {
            document.getElementById('cancelBulkDeleteBtn').addEventListener('click', () => closeModal(bulkDeleteModal));
        }

        // --- FETCH & RENDER ---
        const fetchProducts = async (page = 1) => {
            currentFilters.set('page', page);
            try {
                const data = await fetchJSON(`?${currentFilters.toString()}`);
                renderProducts(data.products);
                renderPagination(data.pagination);
                updateBulkUI();
            } catch (e) { console.error(e); }
        };

        const renderProducts = (products) => {
            tableBody.innerHTML = '';
            if (!products || products.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="8" class="p-10 text-center text-gray-400 dark:text-slate-500 italic">No products found.</td></tr>';
                return;
            }
            products.forEach(p => {
                const displayStatus = p.computed_status;
                const statusClass = `status-${displayStatus.replace(/[\s&]+/g, '-')}`; 
                const imageSrc = p.image_url ? `../../${p.image_url}` : defaultPlaceholder;
                const isInactive = (displayStatus === 'Inactive'); 
                
                // Dark mode row background colors
                const rowClass = isInactive ? 'bg-gray-100 dark:bg-slate-800/40 text-gray-500 dark:text-slate-400' : 'hover:bg-gray-50 dark:hover:bg-slate-800/60 text-gray-800 dark:text-slate-200';
                
                const isDiscrete = ['pcs', 'pack', 'box', 'liter', 'bottle'].includes(p.unit);
                const stepVal = isDiscrete ? "1" : "0.01";
                const displayQty = isDiscrete ? parseFloat(p.quantity).toFixed(0) : parseFloat(p.quantity).toFixed(2);

                tableBody.innerHTML += `
                    <tr class="transition border-b border-gray-100 dark:border-slate-700/50 relative-cell ${rowClass}">
                        <td class="p-4 align-middle"><input type="checkbox" data-id="${p.product_id}" class="product-checkbox rounded text-green-700 dark:bg-slate-800 border-gray-300 dark:border-slate-600 w-4 h-4 cursor-pointer focus:ring-green-600"></td>
                        <td class="p-4 align-middle flex items-center gap-3">
                            <img src="${imageSrc}" class="w-10 h-10 object-cover rounded-lg shadow-sm border border-gray-200 dark:border-slate-700">
                            <div>
                                <p class="font-bold text-sm ${isInactive ? 'text-gray-500 dark:text-slate-500' : 'text-gray-800 dark:text-white'}">${p.name}</p>
                                <p class="text-xs text-gray-400 dark:text-slate-500">${p.product_brand || ''}</p>
                            </div>
                        </td>
                        <td class="p-4 align-middle text-sm">${p.category_name || '-'}</td>
                        <td class="p-4 align-middle text-sm">${p.expiration_date || '-'}</td>
                        <td class="p-4 align-middle font-mono text-sm font-bold">₱${parseFloat(p.price).toFixed(2)}</td>
                        <td class="p-4 align-middle flex items-center gap-1">
                            <input type="number" value="${displayQty}" step="${stepVal}" data-id="${p.product_id}" class="quick-stock-input w-20 text-center p-1 rounded border border-gray-200 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 text-xs focus:border-green-600 dark:focus:border-green-500 outline-none" onchange="if(this.step == 1) this.value = Math.floor(this.value);">
                            <span class="text-xs text-gray-400 dark:text-slate-500">${p.unit}</span>
                        </td>
                        <td class="p-4 align-middle"><span class="status-badge ${statusClass}">${displayStatus}</span></td>
                        <td class="p-4 align-middle text-right">
                             <div class="relative inline-block text-left">
                                <button onclick="event.stopPropagation(); window.toggleActionMenu(${p.product_id})" class="text-gray-400 dark:text-slate-500 hover:text-[#1E3A1D] dark:hover:text-green-400 transition focus:outline-none p-2 rounded-full hover:bg-gray-200 dark:hover:bg-slate-700">
                                    <span class="material-icons">more_vert</span>
                                </button>
                                <div id="action-menu-${p.product_id}" class="action-menu hidden absolute right-8 top-8 mt-2 w-48 bg-white dark:bg-slate-800 rounded-lg shadow-xl border border-gray-100 dark:border-slate-700 z-50 text-left">
                                    <div class="py-1">
                                        <a href="#" onclick="openViewModal(${p.product_id}); return false;" class="block px-4 py-2 text-sm text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700 flex items-center gap-2 transition">
                                            <span class="material-icons text-sm text-blue-500 dark:text-blue-400">visibility</span> View Details
                                        </a>
                                        <a href="#" onclick="editProduct(${p.product_id}); return false;" class="block px-4 py-2 text-sm text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700 flex items-center gap-2 transition">
                                            <span class="material-icons text-sm text-orange-500 dark:text-orange-400">edit</span> Edit
                                        </a>
                                        <hr class="border-gray-100 dark:border-slate-700">
                                        <a href="#" onclick="window.openDeleteModal(${p.product_id}); return false;" class="block px-4 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-slate-700 flex items-center gap-2 transition">
                                            <span class="material-icons text-sm">delete</span> Delete
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>`;
            });
        };

const renderPagination = ({ total_pages, current_page }) => {
            if (total_pages <= 1) { paginationContainer.innerHTML = ''; return; }
            let links = '';
            for (let i = 1; i <= total_pages; i++) { 
                const active = i == current_page 
                    ? 'bg-[#1E3A1D] dark:bg-green-700 text-white border-[#1E3A1D] dark:border-green-600 shadow-sm' 
                    : 'bg-white dark:bg-slate-800 text-gray-600 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-700 border-gray-200 dark:border-slate-600';
                
                links += `<button onclick="fetchProducts(${i})" class="px-3 py-1.5 rounded border text-sm font-bold transition ${active}">${i}</button>`; 
            }
            paginationContainer.innerHTML = `<span class="text-xs font-bold text-gray-500 dark:text-slate-400 uppercase tracking-widest">Page ${current_page} of ${total_pages}</span><div class="flex gap-1">${links}</div>`;
        };
        // --- FILTERS ---
        document.getElementById('applyFilters').addEventListener('click', () => {
             currentFilters.set('search', document.getElementById('searchInput').value);
             currentFilters.set('status', document.getElementById('statusFilter').value);
             currentFilters.set('brand', document.getElementById('brandFilter').value);
             currentFilters.set('category', document.getElementById('categoryFilter').value);
             currentFilters.set('sort', document.getElementById('sortFilter').value);
             fetchProducts(1);
        });
        
        document.getElementById('resetFilters').addEventListener('click', () => {
            document.getElementById('searchInput').value = '';
            document.getElementById('statusFilter').value = 'all';
            document.getElementById('brandFilter').value = 'all';
            document.getElementById('categoryFilter').value = 'all';
            document.getElementById('sortFilter').value = 'newest';
            currentFilters = new URLSearchParams();
            fetchProducts(1);
        });

        // --- QUICK STOCK UPDATE ---
        let stockTimeout;
        tableBody.addEventListener('input', e => {
            if (e.target.classList.contains('quick-stock-input')) {
                clearTimeout(stockTimeout);
                stockTimeout = setTimeout(async () => {
                    const formData = new FormData();
                    formData.append('action_type', 'update_stock');
                    formData.append('product_id', e.target.dataset.id);
                    formData.append('quantity', e.target.value);
                    try { 
                        await fetchJSON('', { method: 'POST', body: formData }); 
                        showFlash('Stock updated.');
                        fetchProducts(currentFilters.get('page') || 1); 
                    } catch { showFlash('Error updating stock', 'error'); }
                }, 800);
            }
        });

        // --- EDIT PRODUCT (SAFE DATA BINDING & EXPIRY FIX) ---
        window.editProduct = async (id) => {
            productForm.reset();
            document.getElementById('modalTitle').innerHTML = '<span class="material-icons">edit</span> Edit Product';
            document.getElementById('action_type').value = 'edit_product';
            try {
                const { data } = await fetchJSON(`?action=get_product&id=${id}`);
                
                const setVal = (elId, val) => { const el = document.getElementById(elId); if(el) el.value = val; };
                
                setVal('product_id', data.product_id);
                setVal('name', data.name);
                setVal('price', data.price);
                setVal('quantity', data.quantity);
                setVal('category_id', data.category_id || data.joined_cat_id || '');
                setVal('product_brand', data.product_brand || '');
                setVal('description', data.description || '');
                setVal('specifications', data.specifications || '');
                setVal('status', data.status || 'Active');
                
                // FIXED EXPIRY PARSING: Safely extracts exactly YYYY-MM-DD
                const expEl = document.getElementById('expiration_date');
                if (expEl) {
                    if (data.expiration_date && !data.expiration_date.startsWith('0000')) {
                        expEl.value = String(data.expiration_date).substring(0, 10);
                    } else {
                        expEl.value = '';
                    }
                }

                if(data.unit) {
                    setVal('unit', data.unit);
                    updateWeightField();
                    if (data.weight) setVal('weight', data.weight);
                }

                const imgEl = document.getElementById('image_url_preview');
                if (imgEl) imgEl.src = data.image_url ? `../../${data.image_url}` : defaultPlaceholder;
                setVal('existing_image_url', data.image_url || '');

                openModal(productModal);
            } catch (e) { 
                console.error(e);
                showFlash('Error loading product', 'error'); 
            }
        };
        
        window.openViewModal = async (id) => {
             try {
                const { data } = await fetchJSON(`?action=get_product&id=${id}`);
                document.getElementById('viewModalTitle').textContent = data.name;
                document.getElementById('view_status').innerHTML = `<span class="status-badge status-${(data.status || '').replace(/\s+/g, '-')}">${data.status}</span>`;
                document.getElementById('view_product_brand').textContent = data.product_brand || 'N/A';
                document.getElementById('view_category_name').textContent = data.category_name || 'Uncategorized';
                document.getElementById('view_price').textContent = `₱${parseFloat(data.price).toFixed(2)}`;
                
                const isBulk = ['kg', 'g'].includes(data.unit);
                document.getElementById('view_quantity').textContent = isBulk ? parseFloat(data.quantity).toFixed(2) : parseFloat(data.quantity).toFixed(0);
                
                document.getElementById('view_weight').textContent = data.weight || '-';
                document.getElementById('view_unit').textContent = data.unit || 'pcs';
                document.getElementById('view_expiration').textContent = data.expiration_date && !data.expiration_date.startsWith('0000') ? String(data.expiration_date).substring(0, 10) : '-';
                document.getElementById('view_description').textContent = data.description || 'No description available.';
                document.getElementById('view_image_url').src = data.image_url ? `../../${data.image_url}` : defaultPlaceholder;
                
                document.getElementById('view_weight_row').style.display = isBulk ? 'none' : 'flex';

                openModal(viewProductModal);
            } catch (e) { showFlash('Could not load details.', 'error'); }
        }

        window.openDeleteModal = (id) => {
            const el = document.getElementById('delete_product_id');
            if (el) { el.value = id; openModal(deleteModal); }
        };

        // --- BULK ACTION LOGIC ---
        const floatingBar = document.getElementById('floatingBulkBar');
        const selectAllCb = document.getElementById('selectAllCheckbox');
        
        const updateBulkUI = () => {
            const selected = tableBody.querySelectorAll('.product-checkbox:checked');
            const count = selected.length;
            
            if (count > 0) {
                floatingBar.classList.remove('hidden-bar');
                document.getElementById('selectionCount').textContent = `${count} Selected`;
            } else {
                floatingBar.classList.add('hidden-bar');
            }
            
            if (tableBody.querySelectorAll('.product-checkbox').length > 0) {
                selectAllCb.checked = (count === tableBody.querySelectorAll('.product-checkbox').length);
            } else { selectAllCb.checked = false; }
        };

        selectAllCb.addEventListener('change', () => {
            tableBody.querySelectorAll('.product-checkbox').forEach(cb => cb.checked = selectAllCb.checked);
            updateBulkUI();
        });

        tableBody.addEventListener('change', (e) => {
            if (e.target.classList.contains('product-checkbox')) updateBulkUI();
        });

        document.getElementById('cancelSelection').addEventListener('click', () => {
            tableBody.querySelectorAll('.product-checkbox').forEach(cb => cb.checked = false);
            selectAllCb.checked = false;
            updateBulkUI();
        });

        window.executeBulk = async (type) => {
            const selected = tableBody.querySelectorAll('.product-checkbox:checked');
            const ids = Array.from(selected).map(cb => cb.dataset.id);
            
            if (ids.length === 0) return;

            if (type !== 'delete') {
                if (!confirm(`Are you sure you want to ${type} the selected products?`)) return;
            }

            const formData = new FormData();
            formData.append('action_type', 'bulk_action');
            formData.append('bulk_action_type', type);
            ids.forEach(id => formData.append('product_ids[]', id));

            try {
                const res = await fetchJSON('', { method: 'POST', body: formData });
                showFlash(res.message);
                if (res.success) {
                    fetchProducts(1);
                    document.getElementById('cancelSelection').click();
                    const bModal = document.getElementById('bulkDeleteModal');
                    if(bModal) closeModal(bModal);
                }
            } catch (e) { showFlash('Action failed.', 'error'); }
        };

        window.confirmBulkDelete = () => {
            const selectedCount = tableBody.querySelectorAll('.product-checkbox:checked').length;
            const cntEl = document.getElementById('bulkDeleteCount');
            if(cntEl) cntEl.textContent = selectedCount;
            const bModal = document.getElementById('bulkDeleteModal');
            if(bModal) openModal(bModal);
        };

        // --- FORM SUBMITS ---
        productForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('submitBtn');
            btn.innerHTML = 'Saving...'; btn.disabled = true;
            try {
                const res = await fetchJSON('', { method: 'POST', body: new FormData(productForm) });
                if (res.success) { 
                    showFlash(res.message); 
                    closeModal(productModal); 
                    fetchProducts(1); 
                } else { showFlash(res.message, 'error'); }
            } catch { showFlash('System error', 'error'); }
            finally { btn.innerHTML = 'Save Product'; btn.disabled = false; }
        });
        
        document.getElementById('deleteForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                const res = await fetchJSON('', { method: 'POST', body: new FormData(document.getElementById('deleteForm')) });
                if (res.success) { showFlash(res.message); closeModal(deleteModal); fetchProducts(1); }
                else { showFlash(res.message, 'error'); }
            } catch { showFlash('Delete failed', 'error'); }
        });

        window.toggleActionMenu = (id) => {
            const menu = document.getElementById(`action-menu-${id}`);
            const isHidden = menu.classList.contains('hidden');
            
            document.querySelectorAll('.action-menu').forEach(m => m.classList.add('hidden'));
            
            if (isHidden) {
                menu.classList.remove('hidden');
            }
        };
        
        window.addEventListener('click', (e) => {
            if (!e.target.closest('.relative-cell')) {
                document.querySelectorAll('.action-menu').forEach(m => m.classList.add('hidden'));
            }
        });

        window.fetchProducts = fetchProducts; 
        fetchProducts(1);
    });
    </script>
</body>
</html>