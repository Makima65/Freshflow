<?php
// notification_management.php

// --- SECURITY AND DATABASE CONNECTION ---
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
include_once '../../includes/db_connection.php'; 

// --- AUDIT LOGGING HELPER INCLUSION ---
$auditHelperPath = '../includes/audit_helper.php';
if (file_exists($auditHelperPath)) {
    include_once $auditHelperPath;
}
// --- Security Check (Admin Only) ---
$is_admin = isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true && isset($_SESSION["role_name"]) && $_SESSION["role_name"] === 'admin';
if (!$is_admin) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Authentication required.']);
        exit;
    }
    header("location: ../admin_login.php");
    exit;
}

$admin_user_id = $_SESSION["user_id"] ?? 0;
$admin_user_name = $_SESSION["username"] ?? 'Unknown Admin';

// --- CONFIGURATION ---
const TEMPLATES_PER_PAGE = 10;
$template_types = [
    'account' => 'Account',
    'order' => 'Order',
    'admin' => 'Admin'
];

// =================================================================
// AJAX HANDLER: Fetch Template Details 
// =================================================================
if (isset($_GET['action']) && $_GET['action'] == 'get_details' && isset($_GET['template_id'])) {
    header('Content-Type: application/json');
    $template_id = intval($_GET['template_id']);
    $details = ['success' => false, 'error' => ''];

    try {
        $sql = "SELECT template_id, template_name, subject, body, updated_at FROM email_templates WHERE template_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $template_id);
        $stmt->execute();
        $details['template'] = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$details['template']) throw new Exception("Template not found.");
        
        $details['success'] = true;

    } catch (Exception $e) {
        $details['success'] = false;
        $details['error'] = $e->getMessage();
    }

    echo json_encode($details);
    exit;
}

// =================================================================
// AJAX HANDLER: Update Template
// =================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'update_template') {
    header('Content-Type: application/json');

    $template_id = intval($_POST['template_id']);
    $subject = trim($_POST['subject']);
    $body = $_POST['body']; // Don't trim, admin may want whitespace
    $response = ['success' => false, 'message' => ''];

    if (empty($subject) || empty($body)) {
        $response['message'] = 'Error: Subject and Body cannot be empty.';
        echo json_encode($response);
        exit;
    }
    
    // Get old data for audit log
    $stmt_old = $conn->prepare("SELECT subject FROM email_templates WHERE template_id = ?");
    $stmt_old->bind_param("i", $template_id);
    $stmt_old->execute();
    $old_template = $stmt_old->get_result()->fetch_assoc();
    $stmt_old->close();

    $conn->begin_transaction();
    try {
        // 1. Update the 'email_templates' table
        // The 'updated_at' column will update automatically if you ran Step 1
        $sql_update = "UPDATE email_templates SET subject = ?, body = ? WHERE template_id = ?";
        $stmt = $conn->prepare($sql_update);
        if ($stmt === false) {
            throw new Exception("SQL Update prepare failed: " . $conn->error);
        }
        $stmt->bind_param("ssi", $subject, $body, $template_id);

        if (!$stmt->execute()) {
            throw new Exception("Database update failed: " . $stmt->error);
        }
        $stmt->close();
        
        $conn->commit();
        $response['success'] = true;
        $response['message'] = "Template successfully updated.";
        $response['template_id'] = $template_id;
        $response['new_subject'] = $subject; // Send back new subject

        // 3. AUDIT LOGGING
        if (function_exists('log_audit_action')) {
            $details = "Admin updated Email Template (ID: {$template_id}).";
            if ($old_template['subject'] !== $subject) {
                $details .= " Subject changed.";
            }
            $metadata = ['template_id' => $template_id, 'admin_user_id' => $admin_user_id];
            log_audit_action('Email Template Update', 'Notifications', $details, $metadata);
        }

    } catch (Exception $e) {
        $conn->rollback();
        // --- THIS WAS THE LINE WITH THE ERROR ---
        $response['message'] = 'Transaction Error: ' . $e->getMessage();
    }
    
    echo json_encode($response);
    exit;
}
// =================================================================
// END AJAX HANDLER
// =================================================================


// =================================================================
// MAIN PAGE LOAD: Fetch Template List
// =================================================================
$where_clauses = [];
$params = [];
$types = '';

$search_query = trim($_GET['search_query'] ?? '');
$filter_type = trim($_GET['filter_type'] ?? '');
$current_page = max(1, intval($_GET['page'] ?? 1));
$offset = ($current_page - 1) * TEMPLATES_PER_PAGE;


if (!empty($search_query)) {
    $where_clauses[] = "(template_name LIKE ? OR subject LIKE ?)";
    $search_param = "%{$search_query}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ss';
}

if (!empty($filter_type) && array_key_exists($filter_type, $template_types)) {
    $where_clauses[] = "template_name LIKE ?";
    $type_param = "{$filter_type}_%";
    $params[] = $type_param;
    $types .= 's';
}
$where_sql = count($where_clauses) > 0 ? "WHERE " . implode(' AND ', $where_clauses) : "";

// New: Count total templates for pagination
$sql_count = "SELECT COUNT(*) AS total FROM email_templates {$where_sql}";
$total_templates = 0;
$total_pages = 1;
$count_params = $params;
$count_types = $types;

if ($stmt_count = $conn->prepare($sql_count)) {
    if (!empty($count_types)) {
        $bind_params = [];
        $bind_params[] = $count_types;
        foreach ($count_params as $key => $value) {
            $bind_params[] = &$count_params[$key];
        }
        call_user_func_array([$stmt_count, 'bind_param'], $bind_params);
    }
    $stmt_count->execute();
    $total_templates = $stmt_count->get_result()->fetch_assoc()['total'];
    $stmt_count->close();
    
    $total_pages = ceil($total_templates / TEMPLATES_PER_PAGE);
    $current_page = min($current_page, max(1, $total_pages));
    $offset = ($current_page - 1) * TEMPLATES_PER_PAGE;

} else {
    error_log("Template Count Query Preparation Failed: " . $conn->error);
}

// Main query to fetch templates
$sql_templates = "
    SELECT template_id, template_name, subject, updated_at
    FROM email_templates
    {$where_sql} ORDER BY template_name ASC 
    LIMIT ? OFFSET ? 
";

$templates = [];
$paged_params = $params;
$paged_types = $types . 'ii';
$paged_params[] = TEMPLATES_PER_PAGE;
$paged_params[] = $offset;

if ($stmt = $conn->prepare($sql_templates)) {
    $bind_params = [];
    $bind_params[] = $paged_types;
    foreach ($paged_params as $key => $value) {
        $bind_params[] = &$paged_params[$key];
    }
    if (!empty($paged_types)) {
        call_user_func_array([$stmt, 'bind_param'], $bind_params);
    }
    
    $stmt->execute();
    $templates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    error_log("Main Templates Query Preparation Failed: " . $conn->error);
}

// Fetch stats for header cards
$total_account = $conn->query("SELECT COUNT(*) AS total FROM email_templates WHERE template_name LIKE 'account_%'")->fetch_assoc()['total'] ?? 0;
$total_order = $conn->query("SELECT COUNT(*) AS total FROM email_templates WHERE template_name LIKE 'order_%'")->fetch_assoc()['total'] ?? 0;


function get_pagination_base_url_templates($exclude_page = true) {
    $query_params = $_GET;
    if ($exclude_page) {
        unset($query_params['page']);
    }
    if (empty($query_params)) {
        return basename($_SERVER['PHP_SELF']) . '?';
    }
    return basename($_SERVER['PHP_SELF']) . '?' . http_build_query($query_params) . '&';
}

$base_url = get_pagination_base_url_templates();
$start_index = $offset + 1;
$end_index = $offset + count($templates);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body.dot-texture-background { font-family: 'Inter', sans-serif; background-color: #000000; background-image: radial-gradient(circle, rgba(255, 255, 255, 0.07) 1px, transparent 1px); background-size: 12px 12px; }
        .font-heading { font-family: 'Roboto Mono', monospace; }
        .content-card { background-color: rgba(15, 23, 42, 0.5); border: 1px solid rgba(51, 65, 85, 0.5); backdrop-filter: blur(8px); }
        .badge-Account { background-color: rgba(99, 102, 241, 0.2); color: #A5B4FC; }
        .badge-Order { background-color: rgba(52, 211, 153, 0.2); color: #6EE7B7; }
        .badge-Admin { background-color: rgba(251, 191, 36, 0.2); color: #FCD34D; }
        .badge-Other { background-color: rgba(148, 163, 184, 0.2); color: #CBD5E1; }

        .pagination-link { display: inline-flex; justify-content: center; align-items: center; width: 36px; height: 36px; border: 1px solid #4b5563; color: #d1d5db; border-radius: 8px; transition: all 0.2s; }
        .pagination-link:hover { background-color: #4b5563; color: white; }
        .pagination-link.active { background-color: #ef4444; border-color: #ef4444; color: white; font-weight: bold; }
        .pagination-link.disabled { opacity: 0.5; cursor: not-allowed; }
        .pagination-ellipsis { display: inline-flex; justify-content: center; align-items: center; width: 36px; height: 36px; color: #6b7280; }
        
        #template-body-preview {
            width: 100%;
            height: 50vh;
            background-color: #ffffff;
            border: 1px solid #4b5563;
            border-radius: 8px;
        }
        
        #edit-body {
            min-height: 400px;
            font-family: 'Roboto Mono', monospace;
            font-size: 0.9rem;
            line-height: 1.6;
        }
    </style>
</head>
<body class="dot-texture-background text-slate-300">
    
    <?php include '../includes/sidebar.php'; ?> 

    <div class="pl-20">
        <main id="main-content" class="p-6 md:p-8">
            <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8">
                <h1 class="font-heading text-4xl font-bold text-white mb-4 md:mb-0">Notification Management</h1>
                <div class="flex items-center space-x-4"> 
                    <?php include '../includes/low_stock_notif.php'; ?>
                </div>
            </header>

            <div id="feedback-message" class="mb-4"></div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                <div class="content-card p-6 rounded-lg"><p class="text-sm text-slate-400">Total Templates (Filtered)</p><p class="font-heading text-3xl font-bold text-white"><?php echo $total_templates; ?></p></div>
                <div class="content-card p-6 rounded-lg"><p class="text-sm text-slate-400">Account Templates</p><p class="font-heading text-3xl font-bold text-white text-indigo-400"><?php echo $total_account; ?></p></div>
                <div class="content-card p-6 rounded-lg"><p class="text-sm text-slate-400">Order Templates</p><p class="font-heading text-3xl font-bold text-white text-emerald-400"><?php echo $total_order; ?></p></div>
            </div>

            <form method="GET" class="content-card p-4 rounded-lg flex flex-wrap items-center justify-between gap-4 mb-6">
                <input type="text" name="search_query" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="By Name or Subject" class="flex-grow bg-slate-900/70 border border-slate-700 text-slate-300 p-2 rounded-md transition duration-150 min-w-[150px]" />
                <select name="filter_type" class="bg-slate-900/70 border border-slate-700 text-slate-300 p-2 rounded-md w-full md:w-auto">
                    <option value="">Filter by Type</option>
                    <?php foreach ($template_types as $key => $label): ?>
                        <option value="<?php echo htmlspecialchars($key); ?>" <?php echo ($filter_type === $key) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white font-semibold py-2 px-4 rounded-md w-full md:w-auto transition duration-150">Apply Filters</button>
            </form>

            <div class="content-card p-6 rounded-lg">
                <h2 class="font-heading text-2xl font-semibold text-white mb-4">Email Templates</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="border-b-2 border-red-500/50">
                                <th class="py-3 px-2 font-heading text-red-400">Template Name</th>
                                <th class="py-3 px-2 font-heading text-slate-300">Type</th>
                                <th class="py-3 px-2 font-heading text-slate-300">Subject</th>
                                <th class="py-3 px-2 font-heading text-slate-300">Last Updated</th>
                                <th class="py-3 px-2 font-heading text-slate-300">Action</th>
                            </tr>
                        </thead>
                        <tbody class="text-slate-400">
                            <?php if (!empty($templates)): foreach ($templates as $template): ?>
                                <?php
                                    $type_label = 'Other';
                                    $type_class = 'Other';
                                    if (strpos($template['template_name'], 'account_') === 0) {
                                        $type_label = 'Account';
                                        $type_class = 'Account';
                                    } elseif (strpos($template['template_name'], 'order_') === 0) {
                                        $type_label = 'Order';
                                        $type_class = 'Order';
                                    } elseif (strpos($template['template_name'], 'admin_') === 0) {
                                        $type_label = 'Admin';
                                        $type_class = 'Admin';
                                    }
                                ?>
                                <tr class="border-b border-slate-800 hover:bg-slate-800/50">
                                    <td class="py-4 px-2 font-mono text-red-400"><?php echo htmlspecialchars($template['template_name']); ?></td>
                                    <td class="py-4 px-2"><span class="px-3 py-1 text-xs font-semibold rounded-full badge-<?php echo $type_class; ?>"><?php echo $type_label; ?></span></td>
                                    <td id="subject-<?php echo $template['template_id']; ?>" class="py-4 px-2"><?php echo htmlspecialchars($template['subject']); ?></td>
                                    <td class="py-4 px-2"><?php echo date('Y-m-d H:i', strtotime($template['updated_at'])); ?></td>
                                    <td class="py-4 px-2 text-left"> <div class="relative inline-block text-left">
                                            <button onclick="toggleActionMenu(this)" class="actionMenuBtn p-2 rounded-full hover:bg-slate-700" type="button">
                                                <svg class="w-5 h-5 text-slate-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                    <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z" />
                                                </svg>
                                            </button>
                                            <div class="action-menu origin-top-right absolute right-0 mt-2 w-48 rounded-md shadow-lg bg-slate-800 ring-1 ring-black ring-opacity-5 z-20 hidden">
                                                <div class="py-1">
                                                    <button onclick="openEditModal(<?php echo $template['template_id']; ?>)" class="w-full text-left block px-4 py-2 text-sm text-yellow-400 hover:bg-slate-700">Edit Template</button>
                                                    <button onclick="fetchTemplateDetails(<?php echo $template['template_id']; ?>)" class="w-full text-left block px-4 py-2 text-sm text-blue-400 hover:bg-slate-700">View Content</button>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="5" class="py-4 px-2 text-center text-slate-500">No templates found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="mt-6 flex flex-col md:flex-row justify-between items-center text-sm">
                    <p id="resultsCount" class="text-slate-500 mb-4 md:mb-0">
                        Showing <span class="font-semibold text-white"><?php echo count($templates) > 0 ? $start_index : 0; ?></span> to <span class="font-semibold text-white"><?php echo $end_index; ?></span> of <span class="font-semibold text-white"><?php echo $total_templates; ?></span> results
                    </p>
                    <nav id="paginationContainer" class="flex items-center space-x-2">
                        <?php if ($total_pages > 1): ?>
                            <?php 
                            $prev_page_url = $current_page > 1 ? htmlspecialchars($base_url . 'page=' . ($current_page - 1)) : '#';
                            $prev_disabled = $current_page <= 1 ? 'disabled' : '';
                            echo "<a href='{$prev_page_url}' class='pagination-link {$prev_disabled}'>&laquo;</a>";

                            $max_pages_to_show = 5;
                            $start_page = max(1, $current_page - floor($max_pages_to_show / 2));
                            $end_page = min($total_pages, $start_page + $max_pages_to_show - 1);
                            if ($end_page - $start_page + 1 < $max_pages_to_show) {
                                $start_page = max(1, $end_page - $max_pages_to_show + 1);
                            }
                            
                            $show_start_ellipsis = $start_page > 1;
                            $show_end_ellipsis = $end_page < $total_pages;

                            if ($show_start_ellipsis) {
                                echo "<a href='" . htmlspecialchars($base_url . 'page=1') . "' class='pagination-link " . (1 == $current_page ? 'active' : '') . "'>1</a>";
                                if ($start_page > 2) {
                                    echo "<span class='pagination-ellipsis'>...</span>";
                                }
                            }
                            
                            for ($i = $start_page; $i <= $end_page; $i++) {
                                $page_url = htmlspecialchars($base_url . 'page=' . $i);
                                $active_class = $i == $current_page ? 'active' : '';
                                echo "<a href='{$page_url}' class='pagination-link {$active_class}'>{$i}</a>";
                            }
                            
                            if ($show_end_ellipsis) {
                                if ($end_page < $total_pages - 1) {
                                    echo "<span class='pagination-ellipsis'>...</span>";
                                }
                                echo "<a href='" . htmlspecialchars($base_url . 'page=' . $total_pages) . "' class='pagination-link " . ($total_pages == $current_page ? 'active' : '') . "'>{$total_pages}</a>";
                            }

                            $next_page_url = $current_page < $total_pages ? htmlspecialchars($base_url . 'page=' . ($current_page + 1)) : '#';
                            $next_disabled = $current_page >= $total_pages ? 'disabled' : '';
                            echo "<a href='{$next_page_url}' class='pagination-link {$next_disabled}'>&raquo;</a>";
                            ?>
                        <?php endif; ?>
                    </nav>
                </div>
                </div>
        </main>
    </div>

    <div id="viewDetailsModal" class="fixed inset-0 bg-black bg-opacity-70 backdrop-blur-sm hidden z-50 overflow-y-auto">
        <div class="flex items-start justify-center min-h-screen p-4">
            <div class="content-card w-full max-w-4xl mt-10 p-6 rounded-lg text-slate-300">
                <div class="flex justify-between items-center border-b pb-3 mb-4 border-slate-700">
                    <h3 class="font-heading text-2xl font-bold text-white">View Template: <span id="modal-template-name-display" class="text-red-400"></span></h3>
                    <button onclick="closeModal('viewDetailsModal')" class="text-red-400 hover:text-red-300 text-3xl">&times;</button>
                </div>
                <div id="modal-content-area" class="space-y-4">
                    <p id="loading-details" class="text-center text-slate-400">Loading...</p>
                </div>
            </div>
        </div>
    </div>
    
    <div id="editModal" class="fixed inset-0 bg-black bg-opacity-70 backdrop-blur-sm hidden z-50 overflow-y-auto">
        <div class="flex items-start justify-center min-h-screen p-4">
            <form id="templateEditForm" class="content-card w-full max-w-4xl mt-10 p-6 rounded-lg text-slate-300">
                <input type="hidden" name="action" value="update_template">
                <input type="hidden" name="template_id" id="edit-template-id">
                
                <div class="flex justify-between items-center border-b pb-3 mb-4 border-slate-700">
                    <h3 class="font-heading text-2xl font-bold text-white">Edit Template: <span id="edit-modal-template-name-display" class="text-red-400"></span></h3>
                    <button type="button" onclick="closeModal('editModal')" class="text-red-400 hover:text-red-300 text-3xl">&times;</button>
                </div>
                
                <div id="edit-modal-feedback" class="mb-4 hidden p-3 rounded-lg text-sm"></div>
                
                <div class="space-y-4">
                    <div>
                        <label for="edit-subject" class="block text-sm font-medium text-slate-400 mb-1">Subject</label>
                        <input type="text" id="edit-subject" name="subject" class="w-full bg-slate-900/70 border border-slate-700 text-white p-2 rounded-md focus:ring-red-500 focus:border-red-500" required>
                    </div>
                     <div>
                        <label for="edit-body" class="block text-sm font-medium text-slate-400 mb-1">Body (HTML)</label>
                        <textarea id="edit-body" name="body" class="w-full bg-slate-900/70 border border-slate-700 text-white p-2 rounded-md focus:ring-red-500 focus:border-red-500" required></textarea>
                        <p class="text-xs text-slate-500 mt-1">Use placeholders like {{username}}, {{otp_code}}, {{order_id}}, etc.</p>
                    </div>
                </div>
                
                <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white font-semibold py-2 px-4 rounded-md transition duration-150 mt-6">Save Changes</button>
            </form>
        </div>
    </div>


    <script>
        const viewDetailsModal = document.getElementById('viewDetailsModal');
        const editModal = document.getElementById('editModal');

        function closeModal(id) {
            document.getElementById(id).classList.add('hidden');
        }
        
        function toggleActionMenu(button) {
            const menu = button.closest('.relative').querySelector('.action-menu');
            const isHidden = menu.classList.contains('hidden');
            document.querySelectorAll('.action-menu').forEach(m => m.classList.add('hidden'));
            if (isHidden) {
                menu.classList.remove('hidden');
            }
        }
        
        document.addEventListener('click', (e) => { 
            if (!e.target.closest('.actionMenuBtn')) { 
                document.querySelectorAll('.action-menu').forEach(menu => menu.classList.add('hidden')); 
            }
        });

        // --- Open Edit Modal ---
        function openEditModal(templateId) {
            const feedbackDiv = document.getElementById('edit-modal-feedback');
            feedbackDiv.classList.add('hidden');
            
            // Fetch data to populate
            fetch(`notification_management.php?action=get_details&template_id=${templateId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.template) {
                        const t = data.template;
                        document.getElementById('edit-template-id').value = t.template_id;
                        document.getElementById('edit-modal-template-name-display').textContent = t.template_name;
                        document.getElementById('edit-subject').value = t.subject;
                        document.getElementById('edit-body').value = t.body;
                        editModal.classList.remove('hidden');
                    } else {
                        alert(`Error: ${data.error || 'Could not load template details.'}`);
                    }
                })
                .catch(error => {
                    console.error('Error fetching details:', error);
                    alert('Network error. Could not load template details.');
                });
        }

        // --- Submit Edit Form ---
        document.getElementById('templateEditForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const feedbackDiv = document.getElementById('edit-modal-feedback');
            
            fetch('notification_management.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                feedbackDiv.textContent = data.message;
                feedbackDiv.className = `mb-4 p-3 rounded-lg text-sm ${data.success ? 'bg-green-600/20 text-green-400' : 'bg-red-600/20 text-red-400'}`;
                feedbackDiv.classList.remove('hidden');

                if (data.success) {
                    // Update the subject in the main table
                    const subjectCell = document.getElementById(`subject-${data.template_id}`);
                    if(subjectCell) {
                        subjectCell.textContent = data.new_subject;
                    }
                    setTimeout(() => {
                        closeModal('editModal');
                    }, 2000);
                }
            })
            .catch(error => {
                feedbackDiv.textContent = 'A network error occurred.';
                feedbackDiv.className = 'mb-4 p-3 rounded-lg text-sm bg-red-600/20 text-red-400';
                feedbackDiv.classList.remove('hidden');
                console.error('Error:', error);
            });
        });
        

        // --- Fetch and View Template Details ---
        function fetchTemplateDetails(templateId) {
            const modalContent = document.getElementById('modal-content-area');
            modalContent.innerHTML = '<p id="loading-details" class="text-center text-slate-400">Loading template...</p>';
            viewDetailsModal.classList.remove('hidden');

            fetch(`notification_management.php?action=get_details&template_id=${templateId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.template) {
                        const t = data.template;
                        document.getElementById('modal-template-name-display').textContent = t.template_name;
                        modalContent.innerHTML = `
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-slate-400 mb-1">Subject</label>
                                    <p class="p-2 bg-slate-900/70 border border-slate-700 rounded-md">${t.subject}</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-400 mb-1">Live Preview</label>
                                    <iframe id="template-body-preview" srcdoc=""></iframe>
                                </div>
                            </div>
                        `;
                        // Safely set iframe content
                        const iframe = document.getElementById('template-body-preview');
                        iframe.srcdoc = t.body;
                    }
                    else {
                        modalContent.innerHTML = `<p class="text-center text-red-400">Error: ${data.error || 'Could not load details.'}</p>`;
                    }
                })
                .catch(error => {
                    console.error('Error fetching details:', error);
                    modalContent.innerHTML = '<p class="text-center text-red-400">Network error. Check console for details.</p>';
                });
        }
    </script>
</body>
</html>