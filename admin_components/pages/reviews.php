<?php
// reviews.php

// --- SECURITY AND DATABASE CONNECTION SNIPPET ---
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
// --- DATABASE CONNECTION ---\
// Assumes this path correctly points to your connection file (e.g., ../../includes/db_connection.php)
include_once '../../includes/db_connection.php'; 

$auditHelperPath = '../includes/audit_helper.php';
if (file_exists($auditHelperPath)) {
    include_once $auditHelperPath;
}

// --- CONNECTION CHECK ---\
if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed. Error: " . (isset($conn) ? $conn->connect_error : 'Connection not found.'));
}

// --- Security Check (Admin Only) ---
$is_admin = isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true && isset($_SESSION["role_name"]) && $_SESSION["role_name"] === 'admin';

if (!$is_admin) {
    // Check if it's an AJAX request
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Authentication required.']);
        exit;
    }
    // Redirect for direct access
    header("location: ../admin_login.php");
    exit;
}

// Admin user info for logging
$admin_user_id = $_SESSION["user_id"] ?? 0;
$admin_user_name = ($_SESSION["first_name"] ?? 'Unknown') . " " . ($_SESSION["last_name"] ?? 'Admin'); 

// --- LOW STOCK CONFIGURATION ---
// Predefined threshold for low stock alert. This constant is required by the included notification file.
const LOW_STOCK_THRESHOLD = 5; 
// --- END LOW STOCK CONFIGURATION ---

// Define Allowed Ratings from DB ENUM
$allowed_ratings = ['Very dissatisfied', 'Dissatisfied', 'Neutral', 'Satisfied', 'Very satisfied'];


// =================================================================
// 1. AJAX HANDLER: Fetch Review Details (User, Product, Comment)
// =================================================================
if (isset($_GET['action']) && $_GET['action'] == 'get_details' && isset($_GET['review_id'])) {
    header('Content-Type: application/json');
    $review_id = intval($_GET['review_id']);
    $details = ['success' => false, 'review' => null, 'error' => ''];

    try {
        // --- Fetch Review, Customer, and Product Details ---
        $sql_review = "
            SELECT 
                r.review_id, r.rating, r.comment, r.created_at,
                u.first_name AS c_fname, 
                u.last_name AS c_lname, 
                u.email,
                p.name AS product_name
            FROM reviews r
            JOIN users u ON r.user_id = u.user_id
            JOIN products p ON r.product_id = p.product_id
            WHERE r.review_id = ?
        ";
        if ($stmt = $conn->prepare($sql_review)) {
            $stmt->bind_param("i", $review_id);
            $stmt->execute();
            $result_review = $stmt->get_result();
            $details['review'] = $result_review->fetch_assoc();
            $stmt->close();
        }

        if (!$details['review']) {
            $details['error'] = "Review not found.";
            throw new Exception("Review not found.");
        }

        $details['success'] = true;

    } catch (Exception $e) {
        $details['success'] = false;
        $details['error'] = $e->getMessage();
    }

    echo json_encode($details);
    exit;
}

// =================================================================
// 2. AJAX HANDLER: Delete a Review
// =================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'delete_review') {
    header('Content-Type: application/json');

    $review_id = intval($_POST['review_id']);
    $response = ['success' => false, 'message' => ''];

    if ($review_id <= 0) {
        $response['message'] = 'Error: Invalid Review ID.';
        echo json_encode($response);
        exit;
    }

    $conn->begin_transaction();

    try {
        // --- 1. Get review info for logging before deleting ---
        $sql_get_info = "SELECT comment, user_id, product_id FROM reviews WHERE review_id = ?";
        $review_info = null;
        if ($stmt = $conn->prepare($sql_get_info)) {
            $stmt->bind_param("i", $review_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $review_info = $result->fetch_assoc();
            $stmt->close();
        }

        if (!$review_info) {
            throw new Exception("Error: Review not found (ID: $review_id).");
        }

        // --- 2. Delete the review ---
        $sql_delete = "DELETE FROM reviews WHERE review_id = ?";
        if ($stmt = $conn->prepare($sql_delete)) {
            $stmt->bind_param("i", $review_id);
            $stmt->execute();
            $affected_rows = $stmt->affected_rows;
            $stmt->close();
        } else {
            throw new Exception("Error preparing delete statement: " . $conn->error);
        }

        if ($affected_rows == 0) {
             throw new Exception("Review not found or already deleted.");
        }

        // --- 3. AUDIT LOGGING ---
        if (function_exists('log_audit_action')) {
            $audit_action = "Review Delete";
            $comment_snippet = substr($review_info['comment'], 0, 100) . (strlen($review_info['comment']) > 100 ? '...' : '');
            
            $details = "Deleted review (ID: {$review_id}). Customer: {$review_info['user_id']}, Product: {$review_info['product_id']}. Comment: \"{$comment_snippet}\"";
            
            $metadata = [
                'review_id' => $review_id,
                'user_id' => $review_info['user_id'],
                'product_id' => $review_info['product_id'],
                'admin_user_id' => $admin_user_id,
                'admin_name' => $admin_user_name,
            ];
            
            log_audit_action($audit_action, 'Reviews', $details, $metadata);
        }
        // --- END AUDIT LOGGING ---

        // Commit transaction
        $conn->commit();
        $response['success'] = true;
        $response['message'] = "Review #{$review_id} has been permanently deleted.";
        $response['review_id'] = $review_id;

    } catch (Exception $e) {
        $conn->rollback();
        $response['message'] = 'Database Error: ' . $e->getMessage();
    }

    echo json_encode($response);
    exit;
}

// =================================================================
// 3. MAIN PAGE LOAD: Fetch Reviews List with Filtering
// =================================================================

$where_clauses = [];
$params = [];
$types = '';

$search_review_id = trim($_GET['search_review_id'] ?? '');
$search_product_name = trim($_GET['search_product_name'] ?? '');
$filter_rating = trim($_GET['filter_rating'] ?? '');
$filter_date = trim($_GET['filter_date'] ?? '');

if (!empty($search_review_id)) {
    $where_clauses[] = "r.review_id = ?";
    $params[] = $search_review_id;
    $types .= 'i';
}
if (!empty($search_product_name)) {
    $where_clauses[] = "p.name LIKE ?";
    $params[] = "%" . $search_product_name . "%";
    $types .= 's';
}
if (in_array($filter_rating, $allowed_ratings)) {
    $where_clauses[] = "r.rating = ?";
    $params[] = $filter_rating;
    $types .= 's';
}
if (!empty($filter_date)) {
    $where_clauses[] = "DATE(r.created_at) = ?";
    $params[] = $filter_date;
    $types .= 's';
}

$where_sql = count($where_clauses) > 0 ? "WHERE " . implode(' AND ', $where_clauses) : "";

$sql_reviews = "
    SELECT 
        r.review_id, 
        r.rating, 
        r.comment, 
        r.created_at,
        u.first_name, 
        u.last_name,
        p.name AS product_name
    FROM 
        reviews r
    JOIN 
        users u ON r.user_id = u.user_id
    JOIN
        products p ON r.product_id = p.product_id
    {$where_sql}
    ORDER BY 
        r.created_at DESC
";

$reviews = [];
if ($stmt = $conn->prepare($sql_reviews)) {
    if (!empty($types)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $reviews[] = $row;
    }
    $stmt->close();
}

// --- Dashboard Card Data Calculations ---
$total_reviews = count($reviews);
$total_rating_value = 0;
$rating_map = [
    'Very dissatisfied' => 1,
    'Dissatisfied' => 2,
    'Neutral' => 3,
    'Satisfied' => 4,
    'Very satisfied' => 5
];

if ($total_reviews > 0) {
    foreach ($reviews as $review) {
        $total_rating_value += $rating_map[$review['rating']] ?? 0;
    }
    $average_rating = $total_rating_value / $total_reviews;
} else {
    $average_rating = 0;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Reviews</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        body.dot-texture-background {
            font-family: 'Inter', sans-serif;
            background-color: #000000;
            background-image: radial-gradient(circle, rgba(255, 255, 255, 0.07) 1px, transparent 1px);
            background-size: 12px 12px;
        }

        .font-heading {
            font-family: 'Roboto Mono', monospace;
        }
        
        .content-card {
            background-color: rgba(15, 23, 42, 0.5); 
            border: 1px solid rgba(51, 65, 85, 0.5); 
            backdrop-filter: blur(8px);
        }

        /* Rating badge styles */
        .badge-Very-dissatisfied { background-color: rgba(239, 68, 68, 0.2); color: #F87171; }
        .badge-Dissatisfied { background-color: rgba(249, 115, 22, 0.2); color: #FB923C; }
        .badge-Neutral { background-color: rgba(251, 191, 36, 0.2); color: #FCD34D; }
        .badge-Satisfied { background-color: rgba(52, 211, 153, 0.2); color: #6EE7B7; }
        .badge-Very-satisfied { background-color: rgba(59, 130, 246, 0.2); color: #93C5FD; }
    </style>
</head>
<body class="dot-texture-background text-slate-300">
    
    <?php include '../includes/sidebar.php'; ?> 
    <div class="pl-20">
        <main id="main-content" class="p-6 md:p-8">
            <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8">
                <h1 class="font-heading text-4xl font-bold text-white mb-4 md:mb-0">Manage Reviews</h1>
                
                <div class="flex items-center space-x-4"> 
                    
                    <?php include '../includes/low_stock_notif.php'; ?>

                </div>
                </header>

            <div id="feedback-message" class="mb-4"></div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                <div class="content-card p-6 rounded-lg">
                    <p class="text-sm text-slate-400">Total Reviews (Filtered)</p>
                    <p class="font-heading text-3xl font-bold text-white"><?php echo $total_reviews; ?></p>
                </div>
                <div class="content-card p-6 rounded-lg">
                    <p class="text-sm text-slate-400">Average Rating</p>
                    <p class="font-heading text-3xl font-bold text-white text-yellow-400 flex items-center">
                        <?php echo number_format($average_rating, 1); ?>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-7 h-7 ml-2 text-yellow-400">
                            <path fill-rule="evenodd" d="M10.788 3.21c.448-1.077 1.976-1.077 2.424 0l2.082 5.006 5.404.434c1.164.093 1.636 1.545.749 2.305l-4.117 3.527 1.257 5.273c.271 1.136-.964 2.033-1.96 1.425L12 18.354 7.373 21.18c-.996.608-2.231-.29-1.96-1.425l1.257-5.273-4.117-3.527c-.887-.76-.415-2.212.749-2.305l5.404-.434 2.082-5.005Z" clip-rule="evenodd" />
                        </svg>
                    </p>
                </div>
            </div>

            <form method="GET" class="content-card p-4 rounded-lg flex flex-wrap items-center justify-between gap-4 mb-6">
                <input type="number" name="search_review_id" value="<?php echo htmlspecialchars($search_review_id); ?>" placeholder="By Review ID" class="flex-grow bg-slate-900/70 border border-slate-700 text-slate-300 p-2 rounded-md transition duration-150 min-w-[150px]" />
                <input type="text" name="search_product_name" value="<?php echo htmlspecialchars($search_product_name); ?>" placeholder="By Product Name" class="flex-grow bg-slate-900/70 border border-slate-700 text-slate-300 p-2 rounded-md transition duration-150 min-w-[150px]" />
                
                <select name="filter_rating" class="bg-slate-900/70 border border-slate-700 text-slate-300 p-2 rounded-md w-full md:w-auto">
                    <option value="">Filter by Rating</option>
                    <?php foreach ($allowed_ratings as $rating): ?>
                        <option value="<?php echo htmlspecialchars($rating); ?>" <?php echo ($filter_rating === $rating) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($rating); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                 <input type="date" name="filter_date" value="<?php echo htmlspecialchars($filter_date); ?>" class="bg-slate-900/70 border border-slate-700 text-slate-300 p-2 rounded-md w-full md:w-auto" />
                
                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white font-semibold py-2 px-4 rounded-md w-full md:w-auto transition duration-150">
                    Apply Filters
                </button>
            </form>
            
            <div class="content-card p-6 rounded-lg">
                <h2 class="font-heading text-2xl font-semibold text-white mb-4">Review List</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="border-b-2 border-red-500/50">
                                <th class="py-3 px-2 font-heading text-red-400">Review ID</th>
                                <th class="py-3 px-2 font-heading text-slate-300">Customer</th>
                                <th class="py-3 px-2 font-heading text-slate-300">Product</th>
                                <th class="py-3 px-2 font-heading text-slate-300">Rating</th>
                                <th class="py-3 px-2 font-heading text-slate-300">Comment</th>
                                <th class="py-3 px-2 font-heading text-slate-300">Date</th>
                                <th class="py-3 px-2 font-heading text-slate-300">Action</th>
                            </tr>
                        </thead>
                        <tbody class="text-slate-400" id="reviews-table-body">
                            
                            <?php if (!empty($reviews)): ?>
                                <?php foreach ($reviews as $review): ?>
                                    <tr data-review-id="<?php echo htmlspecialchars($review['review_id']); ?>" class="border-b border-slate-800 hover:bg-slate-800/50">
                                        <td class="py-4 px-2 font-mono text-red-400">#REV<?php echo htmlspecialchars($review['review_id']); ?></td>
                                        <td class="py-4 px-2"><?php echo htmlspecialchars($review['first_name'] . ' ' . $review['last_name']); ?></td>
                                        <td class="py-4 px-2"><?php echo htmlspecialchars($review['product_name']); ?></td>
                                        <td class="py-4 px-2">
                                            <?php 
                                            $rating_class = str_replace(' ', '-', $review['rating']);
                                            ?>
                                            <span class="px-3 py-1 text-xs font-semibold rounded-full badge-<?php echo $rating_class; ?>">
                                                <?php echo htmlspecialchars($review['rating']); ?>
                                            </span>
                                        </td>
                                        <td class="py-4 px-2 text-sm italic">
                                            "<?php echo htmlspecialchars(substr($review['comment'], 0, 50)); ?><?php echo strlen($review['comment']) > 50 ? '...' : ''; ?>"
                                        </td>
                                        <td class="py-4 px-2"><?php echo date('Y-m-d', strtotime($review['created_at'])); ?></td>
                                        
                                        <td class="py-4 px-2 relative">
                                            <button onclick="toggleDropdown(event, <?php echo $review['review_id']; ?>)" class="p-2 rounded-full hover:bg-slate-700 text-slate-400 hover:text-white transition-colors">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5">
                                                  <path d="M10 3a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3ZM10 8.5a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3ZM11.5 15.5a1.5 1.5 0 1 0-3 0 1.5 1.5 0 0 0 3 0Z" />
                                                </svg>
                                            </button>
                                            <div id="dropdown-<?php echo $review['review_id']; ?>" class="action-dropdown hidden absolute right-0 top-full mt-2 w-48 bg-slate-800 border border-slate-700 rounded-md shadow-lg z-10">
                                                <button onclick="fetchReviewDetails(<?php echo $review['review_id']; ?>)" class="block w-full text-left px-4 py-2 text-sm text-slate-300 hover:bg-slate-700 transition-colors">
                                                    View Details
                                                </button>
                                                <div class="border-t border-slate-700"></div> <button onclick="openDeleteModal(<?php echo $review['review_id']; ?>)" class="block w-full text-left px-4 py-2 text-sm text-red-400 hover:bg-slate-700 hover:text-red-300 transition-colors">
                                                    Delete Review
                                                </button>
                                            </div>
                                        </td>
                                        </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="py-4 px-2 text-center text-slate-500">No reviews found matching the filter criteria.</td>
                                </tr>
                            <?php endif; ?>

                        </tbody>
                    </table>
                </div>
            </div>
            
        </main>
    </div>

    <div id="reviewDetailsModal" class="fixed inset-0 bg-black bg-opacity-70 backdrop-blur-sm hidden z-50 overflow-y-auto">
        <div class="flex items-start justify-center min-h-screen p-4">
            <div class="content-card w-full max-w-2xl mt-10 p-6 rounded-lg text-slate-300">
                <div class="flex justify-between items-center border-b pb-3 mb-4 border-slate-700">
                    <h3 class="font-heading text-2xl font-bold text-white">Review Details: <span id="modal-review-id-display" class="text-red-400"></span></h3>
                    <button onclick="closeModal('reviewDetailsModal')" class="text-red-400 hover:text-red-300 text-3xl">&times;</button>
                </div>

                <div id="modal-content-area" class="space-y-4">
                    <p id="loading-details" class="text-center text-slate-400">Loading review details...</p>
                    
                    <div id="review-content" class="hidden space-y-4">
                        <div class="p-4 bg-slate-900/70 rounded-lg">
                            <h4 class="font-heading text-lg text-yellow-400 mb-2">Review Info</h4>
                            <p><strong>Customer:</strong> <span id="r-c-name"></span> (<span id="r-c-email"></span>)</p>
                            <p><strong>Product:</strong> <span id="r-p-name"></span></p>
                            <p><strong>Date:</strong> <span id="r-date"></span></p>
                        </div>
                        <div class="p-4 bg-slate-900/70 rounded-lg">
                            <h4 class="font-heading text-lg text-yellow-400 mb-2">Rating & Comment</h4>
                            <p><strong>Rating:</strong> <span id="r-rating-badge" class="px-3 py-1 text-xs font-semibold rounded-full"></span></p>
                            <p class="mt-3"><strong>Comment:</strong></p>
                            <blockquote id="r-comment" class="border-l-4 border-red-500/50 pl-4 italic text-slate-400 mt-2">
                            </blockquote>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <div id="reviewDeleteModal" class="fixed inset-0 bg-black bg-opacity-70 backdrop-blur-sm hidden z-50 overflow-y-auto">
        <div class="flex items-start justify-center min-h-screen p-4">
            <form id="reviewDeleteForm" class="content-card w-full max-w-md mt-10 p-6 rounded-lg text-slate-300">
                <input type="hidden" name="action" value="delete_review">
                <input type="hidden" name="review_id" id="delete-review-id">

                <div class="flex justify-between items-center border-b pb-3 mb-4 border-slate-700">
                    <h3 class="font-heading text-2xl font-bold text-white">Delete Review: <span id="delete-modal-review-id-display" class="text-red-400"></span></h3>
                    <button type="button" onclick="closeModal('reviewDeleteModal')" class="text-red-400 hover:text-red-300 text-3xl">&times;</button>
                </div>

                <div id="delete-modal-feedback" class="mb-4 hidden p-3 rounded-lg text-sm"></div>
                
                <p class="text-slate-400">Are you sure you want to permanently delete this review? This action cannot be undone.</p>

                <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white font-semibold py-2 px-4 rounded-md transition duration-150 mt-6">
                    Yes, Delete Review
                </button>
            </form>
        </div>
    </div>


    <script>
        const reviewDetailsModal = document.getElementById('reviewDetailsModal');
        const reviewDeleteModal = document.getElementById('reviewDeleteModal');
        const globalFeedback = document.getElementById('feedback-message');
        const deleteModalFeedback = document.getElementById('delete-modal-feedback');

        // Helper to close any modal
        function closeModal(id) {
            document.getElementById(id).classList.add('hidden');
            if (id === 'reviewDeleteModal') {
                deleteModalFeedback.classList.add('hidden');
                deleteModalFeedback.innerHTML = '';
            }
        }

        // --- Delete Review Modal Logic ---
        function openDeleteModal(reviewId) {
            document.getElementById('delete-review-id').value = reviewId;
            document.getElementById('delete-modal-review-id-display').textContent = `#REV${reviewId}`;
            reviewDeleteModal.classList.remove('hidden');
        }

        // --- AJAX Form Submission for Delete Review ---
        document.getElementById('reviewDeleteForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const form = e.target;
            const formData = new FormData(form);
            const reviewId = formData.get('review_id');

            // Show loading state
            showFeedback('Deleting review...', false, 'bg-blue-600/20 text-blue-400');
            
            fetch('reviews.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success on main page
                    showFeedback(data.message, true, 'bg-green-600/20 text-green-400');
                    
                    // Remove the row from the table
                    const row = document.querySelector(`tr[data-review-id="${data.review_id}"]`);
                    if (row) {
                        row.remove();
                    }
                    closeModal('reviewDeleteModal');

                } else {
                    showFeedback(data.message, false, 'bg-red-600/20 text-red-400');
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                showFeedback('An unexpected network error occurred.', false, 'bg-red-600/20 text-red-400');
            });
        });

        // Feedback function (global or modal)
        function showFeedback(message, isSuccess, className = '') {
            const target = isSuccess ? globalFeedback : deleteModalFeedback;
            target.innerHTML = message;
            target.className = className + ' p-3 rounded-lg font-medium';
            target.classList.remove('hidden');
            
            if (isSuccess) {
                // Clear global success message after a delay
                setTimeout(() => {
                     globalFeedback.innerHTML = '';
                     globalFeedback.classList.add('hidden');
                }, 3000);
            }
        }


        // --- Review Details Modal Logic ---
        function fetchReviewDetails(reviewId) {
            // Reset modal content
            document.getElementById('review-content').classList.add('hidden');
            document.getElementById('loading-details').classList.remove('hidden');
            reviewDetailsModal.classList.remove('hidden');
            document.getElementById('modal-review-id-display').textContent = `#REV${reviewId}`;

            fetch(`reviews.php?action=get_details&review_id=${reviewId}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('loading-details').classList.add('hidden');
                    if (data.success && data.review) {
                        populateReviewDetailsModal(data.review);
                        document.getElementById('review-content').classList.remove('hidden');
                    } else {
                         document.getElementById('modal-content-area').innerHTML = `<p class="text-center text-red-400">Error: ${data.error || 'Could not load review details.'}</p>`;
                    }
                })
                .catch(error => {
                    console.error('Error fetching details:', error);
                    document.getElementById('loading-details').classList.add('hidden');
                    document.getElementById('modal-content-area').innerHTML = '<p class="text-center text-red-400">Network error. Check console for details.</p>';
                });
        }

        function populateReviewDetailsModal(review) {
            const date = new Date(review.created_at).toLocaleString();
            const ratingClass = 'badge-' + review.rating.replace(/\s/g, '-');
            
            document.getElementById('r-c-name').textContent = `${review.c_fname} ${review.c_lname}`;
            document.getElementById('r-c-email').textContent = review.email;
            document.getElementById('r-p-name').textContent = review.product_name;
            document.getElementById('r-date').textContent = date;
            
            const ratingBadge = document.getElementById('r-rating-badge');
            ratingBadge.textContent = review.rating;
            ratingBadge.className = `px-3 py-1 text-xs font-semibold rounded-full ${ratingClass}`;

            document.getElementById('r-comment').textContent = review.comment || '(No comment left)';
        }

        // --- NEW DROPDOWN MENU LOGIC ---

        /**
         * Closes all open action dropdowns
         */
        function closeAllDropdowns() {
            document.querySelectorAll('.action-dropdown').forEach(dropdown => {
                dropdown.classList.add('hidden');
            });
        }

        /**
         * Toggles a specific action dropdown
         */
        function toggleDropdown(event, reviewId) {
            // Stop the click from bubbling up to the document
            event.stopPropagation();

            const targetDropdown = document.getElementById(`dropdown-${reviewId}`);
            if (!targetDropdown) return;

            // Check if this dropdown is already open
            const isAlreadyOpen = !targetDropdown.classList.contains('hidden');

            // First, close all dropdowns
            closeAllDropdowns();

            // If it wasn't already open, open it
            if (!isAlreadyOpen) {
                targetDropdown.classList.remove('hidden');
            }
            // If it was already open, it will stay closed because closeAllDropdowns() just ran.
        }

        // Add a global click listener to close dropdowns when clicking anywhere else
        document.addEventListener('click', closeAllDropdowns);

    </script>
</body>
</html>
<?php 
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    $conn->close();
}
?>