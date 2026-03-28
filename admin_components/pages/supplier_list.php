<?php
// C:\xampp\htdocs\RalphPHP\admin_components\pages\supplier_list.php

header("Cache-Control: no-cache, no-store, must-revalidate"); 
header("Pragma: no-cache"); 
header("Expires: 0"); 

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
ob_start();
ini_set('display_errors', 0); // Prevent DB warnings from breaking UI
ini_set('log_errors', 1);

include_once '../includes/db_connection.php';

// --- SAFE DATABASE AUTO-PATCHER FOR SUPPLIERS ---
try {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'suppliers'");
    if ($tableCheck && $tableCheck->num_rows == 0) {
        $conn->query("CREATE TABLE suppliers (
            supplier_id INT AUTO_INCREMENT PRIMARY KEY,
            supplier_name VARCHAR(150) NOT NULL,
            contact_person VARCHAR(100) DEFAULT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            email VARCHAR(100) DEFAULT NULL,
            address TEXT DEFAULT NULL,
            status ENUM('Active', 'Inactive') DEFAULT 'Active',
            image_url VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $col1 = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'image_url'");
        if ($col1 && $col1->num_rows == 0) { @$conn->query("ALTER TABLE suppliers ADD COLUMN image_url VARCHAR(255) NULL AFTER status"); }
    }
} catch (Throwable $e) { 
    // Silently ignore strict DB errors to prevent HTTP 500
}

$auditHelperPath = '../includes/audit_helper.php';
if (file_exists($auditHelperPath)) { include_once $auditHelperPath; } 
elseif (!function_exists('log_audit_action')) { function log_audit_action($a, $b, $c, $d = []) { return true; } }

// --- SECURITY CHECK ---
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: ../admin_login.php"); exit;
}

// --- LOGO UPLOAD HELPER ---
function handle_supplier_logo_upload($file_key) {
    if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] == 0) {
        $target_dir = "../../assets/img/suppliers/";
        if (!is_dir($target_dir)) @mkdir($target_dir, 0777, true);
        
        $file_ext = pathinfo($_FILES[$file_key]["name"], PATHINFO_EXTENSION);
        $file_name = uniqid('supplier_') . '_' . time() . '.' . $file_ext;
        $target_file = $target_dir . $file_name;
        
        $check = @getimagesize($_FILES[$file_key]["tmp_name"]);
        if($check !== false) {
            if (move_uploaded_file($_FILES[$file_key]["tmp_name"], $target_file)) {
                return "assets/img/suppliers/" . $file_name;
            }
        }
    }
    return NULL;
}

// --- HANDLERS ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] == 'get_supplier') {
    ob_clean(); header('Content-Type: application/json');
    $id = intval($_GET['id']);
    $stmt = $conn->prepare("SELECT * FROM suppliers WHERE supplier_id = ? LIMIT 1");
    $stmt->bind_param("i", $id); $stmt->execute();
    $res = $stmt->get_result();
    echo json_encode(['success' => true, 'data' => $res->fetch_assoc()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean(); header('Content-Type: application/json');
    $action = $_POST['action_type'] ?? '';

    if ($action === 'delete_supplier') {
        $id = intval($_POST['delete_supplier_id']);
        
        $stmt = $conn->prepare("DELETE FROM suppliers WHERE supplier_id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            log_audit_action('Delete Supplier', 'Suppliers', "Deleted supplier ID: $id");
            echo json_encode(['success' => true, 'message' => 'Supplier deleted successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete supplier.']);
        }
        exit;
    }

    if ($action === 'save_supplier') {
        $supplier_id = intval($_POST['supplier_id'] ?? 0);
        $supplier_name = trim($_POST['supplier_name']);
        $contact_person = trim($_POST['contact_person']);
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone']);
        $address = trim($_POST['address']);
        $status = $_POST['status'] ?? 'Active';

        if (empty($supplier_name)) {
            echo json_encode(['success' => false, 'message' => 'Business Name is required.']); exit;
        }

        // Handle hidden photo removal flag
        if (isset($_POST['remove_image']) && $_POST['remove_image'] == '1' && $supplier_id > 0) {
            $conn->query("UPDATE suppliers SET image_url = NULL WHERE supplier_id = $supplier_id");
        }

        $image_url = handle_supplier_logo_upload('image_url');

        if ($supplier_id > 0) {
            $query = "UPDATE suppliers SET supplier_name=?, contact_person=?, email=?, phone=?, address=?, status=?";
            $params = [$supplier_name, $contact_person, $email, $phone, $address, $status];
            $types = "ssssss";

            if ($image_url) {
                $query .= ", image_url=?";
                $params[] = $image_url;
                $types .= "s";
            }
            $query .= " WHERE supplier_id=?";
            $params[] = $supplier_id;
            $types .= "i";

            $stmt = $conn->prepare($query);
            $stmt->bind_param($types, ...$params);
            
            if ($stmt->execute()) {
                log_audit_action('Update Supplier', 'Suppliers', "Updated supplier: $supplier_name");
                echo json_encode(['success' => true, 'message' => 'Supplier updated successfully.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error.']);
            }
        } else {
            $stmt = $conn->prepare("INSERT INTO suppliers (supplier_name, contact_person, email, phone, address, status, image_url) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssss", $supplier_name, $contact_person, $email, $phone, $address, $status, $image_url);
            
            if ($stmt->execute()) {
                log_audit_action('Add Supplier', 'Suppliers', "Added new supplier: $supplier_name");
                echo json_encode(['success' => true, 'message' => 'Supplier created successfully.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error.']);
            }
        }
        exit;
    }
}
ob_end_flush();

// --- FETCH SUPPLIERS ---
$suppliers = $conn->query("SELECT * FROM suppliers ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreshFlow - Supplier Directory</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
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
        :root { --brand-green: #1E3A1D; --brand-cream: #F8F5EE; }
        body { font-family: 'Inter', sans-serif; background-color: var(--brand-cream); color: #2B2B2B; transition: background-color 0.3s ease; }
        
        /* DARK MODE BODY */
        .dark body {
            background-color: #000000;
            background-image: radial-gradient(circle, rgba(255, 255, 255, 0.07) 1px, transparent 1px);
            background-size: 16px 16px;
            color: #f8fafc;
        }

        .font-mono { font-family: 'JetBrains Mono', monospace; }
        .custom-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .dark .custom-scroll::-webkit-scrollbar-thumb { background: #334155; }
        .modal-z { z-index: 50; }
        
        /* FORM INPUTS (Light & Dark Support) */
        .form-input { background-color: #ffffff; border: 1px solid #d1d5db; color: #374151; border-radius: 0.5rem; transition: all 0.2s; padding: 0.5rem 0.75rem; }
        .form-input:focus { outline: none; border-color: #1E3A1D; box-shadow: 0 0 0 3px rgba(30, 58, 29, 0.1); }
        
        .dark .form-input { background-color: #1e293b; border-color: #334155; color: #f8fafc; }
        .dark .form-input:focus { border-color: #4ade80; box-shadow: 0 0 0 3px rgba(74, 222, 128, 0.1); }

        /* STATUS BADGES */
        .status-Active { background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .dark .status-Active { background-color: rgba(22, 101, 52, 0.2); color: #86efac; border-color: rgba(74, 222, 128, 0.3); }
        
        .status-Inactive { background-color: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .dark .status-Inactive { background-color: rgba(153, 27, 27, 0.2); color: #fca5a5; border-color: rgba(248, 113, 113, 0.3); }
    </style>
    
    <script>
        (function() {
            window.onpageshow = function(event) {
                if (event.persisted || (window.performance && window.performance.navigation.type === 2)) {
                    document.body.style.display = 'none';
                    window.location.reload(); 
                }
            };
        })();
    </script>
</head>

<body style="display:none;" id="secure-body" class="flex h-screen overflow-hidden">

    <?php include '../includes/sidebar.php'; ?>

    <main class="ml-20 flex-1 flex flex-col h-screen overflow-hidden relative transition-all duration-300 p-6 md:p-8">
        
        <header class="flex justify-between items-center mb-8 flex-shrink-0">
            <div>
                <h1 class="text-3xl font-bold text-[#1E3A1D] dark:text-white tracking-tight flex items-center gap-3">
                    <span class="material-icons text-3xl dark:text-green-400">storefront</span> Supplier Directory
                </h1>
                <p class="text-sm text-gray-500 dark:text-slate-400 mt-1 ml-11">
                    Manage your vendors, farmers, and supply chain partners.
                </p>
            </div>
            <button onclick="openModal()" class="bg-[#1E3A1D] dark:bg-green-600 text-white hover:bg-[#2a4e29] dark:hover:bg-green-500 px-5 py-2.5 rounded-lg text-sm font-bold shadow-lg transition transform active:scale-95 flex items-center gap-2">
                <span class="material-icons text-sm">domain_add</span> Add New Supplier
            </button>
        </header>

        <div class="bg-white dark:bg-slate-900/80 rounded-xl shadow border border-gray-200 dark:border-slate-800 flex-1 overflow-hidden flex flex-col mb-4">
            <div class="overflow-y-auto flex-1 custom-scroll pb-24">
                <table class="w-full text-left">
                    <thead class="bg-[#1E3A1D] dark:bg-slate-800 text-white text-xs uppercase font-bold sticky top-0 z-10">
                        <tr>
                            <th class="p-4 pl-6">Business Details</th>
                            <th class="p-4">Contact Info</th>
                            <th class="p-4">Location / Address</th>
                            <th class="p-4">Status</th>
                            <th class="p-4 pr-8 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-800 text-sm text-gray-700 dark:text-gray-300">
                        <?php if(empty($suppliers)): ?>
                            <tr><td colspan="5" class="p-8 text-center text-gray-400 dark:text-slate-500 italic">No suppliers found. Add your first vendor!</td></tr>
                        <?php else: ?>
                            <?php foreach($suppliers as $s): 
                                $hasLogo = !empty($s['image_url']);
                                $initial = strtoupper(substr($s['supplier_name'], 0, 1));
                            ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-slate-800/50 transition group">
                                <td class="p-4 pl-6 align-middle">
                                    <div class="flex items-center gap-4">
                                        <?php if($hasLogo): ?>
                                            <img src="../../<?= htmlspecialchars($s['image_url']) ?>" class="w-12 h-12 rounded-full object-cover border-2 border-white dark:border-slate-800 shadow-sm flex-shrink-0 bg-white dark:bg-slate-900">
                                        <?php else: ?>
                                            <div class="w-12 h-12 rounded-full bg-gradient-to-br from-blue-50 to-indigo-100 dark:from-blue-900/40 dark:to-indigo-900/40 border-2 border-white dark:border-slate-800 shadow-sm flex items-center justify-center font-black text-indigo-700 dark:text-indigo-400 text-lg flex-shrink-0">
                                                <?= $initial ?>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="font-bold text-gray-900 dark:text-white text-base flex items-center gap-2">
                                                <?= htmlspecialchars($s['supplier_name']) ?>
                                            </div>
                                            <div class="text-xs text-gray-500 dark:text-slate-400 mt-1 flex items-center gap-2">
                                                <span>Rep: <?= htmlspecialchars($s['contact_person'] ?: 'N/A') ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="p-4 align-middle">
                                    <div class="flex flex-col gap-1.5 text-xs">
                                        <?php if(!empty($s['email'])): ?>
                                            <div class="flex items-center gap-2 text-gray-600 dark:text-slate-300"><span class="material-icons text-[14px] text-gray-400 dark:text-slate-500">email</span> <?= htmlspecialchars($s['email']) ?></div>
                                        <?php else: ?>
                                            <div class="flex items-center gap-2 text-gray-400 dark:text-slate-500 italic"><span class="material-icons text-[14px] opacity-50">email</span> No email</div>
                                        <?php endif; ?>
                                        
                                        <?php if(!empty($s['phone'])): ?>
                                            <div class="flex items-center gap-2 text-gray-600 dark:text-slate-300 font-mono"><span class="material-icons text-[14px] text-gray-400 dark:text-slate-500">phone</span> <?= htmlspecialchars($s['phone']) ?></div>
                                        <?php else: ?>
                                            <div class="flex items-center gap-2 text-gray-400 dark:text-slate-500 italic"><span class="material-icons text-[14px] opacity-50">phone</span> No phone</div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="p-4 align-middle">
                                    <div class="flex items-start gap-2 text-xs text-gray-600 dark:text-slate-300 max-w-xs">
                                        <span class="material-icons text-[16px] text-gray-400 dark:text-slate-500 mt-0.5">place</span>
                                        <span class="leading-relaxed"><?= !empty($s['address']) ? nl2br(htmlspecialchars($s['address'])) : '<i class="text-gray-400 dark:text-slate-500">Address not provided</i>' ?></span>
                                    </div>
                                </td>
                                <td class="p-4 align-middle">
                                    <div class="flex flex-col gap-2 items-start">
                                        <span class="inline-flex items-center gap-1.5 text-xs font-bold px-2.5 py-1 rounded-full status-<?= $s['status'] ?>">
                                            <span class="w-1.5 h-1.5 rounded-full <?= $s['status']=='Active'?'bg-green-600':'bg-red-600' ?>"></span> <?= $s['status'] ?>
                                        </span>
                                    </div>
                                </td>
                                <td class="p-4 pr-6 align-middle">
                                    <div class="flex justify-end items-center">
                                        <div class="relative inline-block dropdown-container">
                                            <button type="button" onclick="toggleMenu(event, 'supplier-menu-<?= $s['supplier_id'] ?>')" class="p-1.5 text-gray-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-slate-700 rounded-full transition focus:outline-none flex items-center justify-center opacity-50 group-hover:opacity-100">
                                                <span class="material-icons">more_vert</span>
                                            </button>
                                            
                                            <div id="supplier-menu-<?= $s['supplier_id'] ?>" class="supplier-dropdown-menu hidden absolute right-0 top-full mt-1 w-36 bg-white dark:bg-slate-800 rounded-lg shadow-[0_3px_10px_rgb(0,0,0,0.15)] border border-gray-200 dark:border-slate-700 z-[60] overflow-hidden">
                                                <div class="flex flex-col">
                                                    <button type="button" onclick="editSupplier(<?= htmlspecialchars(json_encode($s), ENT_QUOTES, 'UTF-8') ?>)" class="w-full text-left px-4 py-2.5 text-xs text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-slate-700 flex items-center gap-2 font-semibold transition">
                                                        <span class="material-icons text-sm">edit</span> Edit Profile
                                                    </button>
                                                    <button type="button" onclick="deleteSupplier(<?= $s['supplier_id'] ?>, '<?= htmlspecialchars(addslashes($s['supplier_name'])) ?>')" class="w-full text-left px-4 py-2.5 text-xs text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 flex items-center gap-2 font-semibold transition border-t border-gray-100 dark:border-slate-700">
                                                        <span class="material-icons text-sm">delete</span> Delete
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <div id="flashMessage" class="fixed bottom-6 right-6 z-[100] bg-[#1E3A1D] dark:bg-green-700 text-white px-6 py-4 rounded-lg shadow-2xl flex items-center gap-3 transform translate-y-20 transition-all duration-300 opacity-0 pointer-events-none">
        <span class="material-icons text-green-400" id="flashIcon">check_circle</span>
        <div><h4 class="font-bold text-sm">Notification</h4><p class="text-xs text-gray-300" id="flashText"></p></div>
    </div>

    <div id="supplierModal" class="fixed inset-0 bg-[#1E3A1D] dark:bg-black bg-opacity-40 dark:bg-opacity-60 hidden flex items-center justify-center modal-z backdrop-blur-sm transition-opacity">
        <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-4xl overflow-hidden max-h-[90vh] flex flex-col border border-gray-200 dark:border-slate-700">
            <div class="bg-[#1E3A1D] dark:bg-slate-800 p-5 text-white flex justify-between items-center flex-shrink-0">
                <h2 class="text-lg font-bold flex items-center gap-2"><span class="material-icons" id="modalIcon">domain_add</span> <span id="modalTitle">Add New Supplier</span></h2>
                <button type="button" onclick="closeModal()" class="text-gray-300 hover:text-white transition"><span class="material-icons">close</span></button>
            </div>
            
            <div class="p-6 overflow-y-auto custom-scroll flex-1 bg-gray-50 dark:bg-slate-900">
                <form id="supplierForm" enctype="multipart/form-data">
                    <input type="hidden" name="action_type" value="save_supplier">
                    <input type="hidden" name="supplier_id" id="supplier_id">
                    <input type="hidden" name="remove_image" id="remove_image" value="0">

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        
                        <div class="md:col-span-1 flex flex-col items-center justify-start pt-2">
                            <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-3 text-center w-full">Supplier Logo</label>
                            
                            <div class="relative w-32 h-32 group mx-auto rounded-full border-4 border-white dark:border-slate-700 shadow-lg bg-gray-50 dark:bg-slate-800 flex items-center justify-center overflow-hidden transition-all duration-300">
                                
                                <div id="logoPlaceholder" class="absolute inset-0 flex flex-col items-center justify-center text-gray-300 dark:text-slate-500 z-0 bg-gray-100 dark:bg-slate-800">
                                    <span class="material-icons text-5xl">storefront</span>
                                </div>

                                <img id="logoPreview" src="" class="absolute inset-0 w-full h-full object-cover z-10 hidden">
                                
                                <div class="absolute inset-0 bg-black/50 dark:bg-black/60 backdrop-blur-sm flex items-center justify-center opacity-0 group-hover:opacity-100 transition-all duration-300 z-20">
                                    
                                    <label id="noPhotoLink" class="cursor-pointer flex flex-col items-center justify-center w-full h-full text-white/80 hover:text-white transition-colors hidden">
                                        <span class="material-icons text-3xl mb-1">add_a_photo</span>
                                        <span class="text-[10px] font-bold tracking-widest uppercase">Upload</span>
                                        <input type="file" id="imageUploadInputBlank" name="image_url" accept="image/*" class="hidden" onchange="previewLogo(this)">
                                    </label>

                                    <div id="hasPhotoOverlay" class="hidden flex gap-3">
                                        <label class="cursor-pointer w-10 h-10 flex items-center justify-center bg-white/20 hover:bg-white text-white hover:text-[#1E3A1D] rounded-full transition-all shadow-md" title="Change Photo">
                                            <span class="material-icons text-[18px]">edit</span>
                                            <input type="file" id="imageUploadInput" name="image_url" accept="image/*" class="hidden" onchange="previewLogo(this)">
                                        </label>
                                        <button type="button" onclick="removePhoto(event)" class="w-10 h-10 flex items-center justify-center bg-red-500/80 hover:bg-red-500 text-white rounded-full transition-all shadow-md" title="Remove Photo">
                                            <span class="material-icons text-[18px]">delete_outline</span>
                                        </button>
                                    </div>

                                </div>
                            </div>

                            <p class="text-[10px] text-gray-400 dark:text-slate-500 mt-4 text-center px-4 leading-tight">Optional. Max 2MB (JPG/PNG).</p>
                        </div>

                        <div class="md:col-span-2 space-y-4">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Business Name <span class="text-red-500">*</span></label>
                                    <input type="text" name="supplier_name" id="supplier_name" required class="form-input text-sm font-medium w-full" placeholder="e.g. Benguet Farms Inc.">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Contact Person</label>
                                    <input type="text" name="contact_person" id="contact_person" class="form-input text-sm font-medium w-full" placeholder="e.g. Juan Dela Cruz">
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Phone Number</label>
                                    <input type="text" name="phone" id="phone" class="form-input text-sm font-mono w-full" placeholder="09XX-XXX-XXXX">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Email Address</label>
                                    <input type="email" name="email" id="email" class="form-input text-sm w-full" placeholder="orders@supplier.com">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Account Status</label>
                                    <select name="status" id="status" class="form-input text-sm font-bold cursor-pointer w-full md:w-1/2">
                                        <option value="Active">Active</option>
                                        <option value="Inactive">Inactive</option>
                                    </select>
                                </div>
                            </div>

                            <div class="pt-2 border-t border-gray-200 dark:border-slate-800 mt-2">
                                <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Physical Address</label>
                                <textarea name="address" id="address" rows="3" class="form-input text-sm w-full custom-scroll" placeholder="Complete address..."></textarea>
                            </div>
                        </div>

                    </div>
                </form>
            </div>
            
            <div class="p-5 border-t border-gray-100 dark:border-slate-800 bg-white dark:bg-slate-900 flex justify-end gap-3 flex-shrink-0">
                <button type="button" onclick="closeModal()" class="px-5 py-2.5 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-800 rounded-lg text-sm font-bold transition">Cancel</button>
                <button type="submit" form="supplierForm" id="submitBtn" class="bg-[#1E3A1D] dark:bg-green-600 text-white px-6 py-2.5 rounded-lg text-sm font-bold hover:bg-[#2a4e29] dark:hover:bg-green-500 shadow-md transition transform hover:-translate-y-0.5 flex items-center gap-2">
                    <span class="material-icons text-sm" id="btnIcon">save</span> <span id="btnText">Save Supplier</span>
                </button>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('secure-body').style.display = 'block';
        const modal = document.getElementById('supplierModal');
        const form = document.getElementById('supplierForm');

        let flashTimeout;
        const showFlash = (msg, type = 'success') => {
            if(flashTimeout) clearTimeout(flashTimeout);
            document.getElementById('flashText').textContent = msg;
            const fm = document.getElementById('flashMessage');
            const fi = document.getElementById('flashIcon');
            fm.className = `fixed bottom-6 right-6 z-[100] ${type === 'error' ? 'bg-red-700' : 'bg-[#1E3A1D] dark:bg-green-700'} text-white px-6 py-4 rounded-lg shadow-2xl flex items-center gap-3 transform transition-all duration-300`;
            fi.textContent = type === 'error' ? 'error' : 'check_circle';
            fm.classList.remove('translate-y-20', 'opacity-0');
            flashTimeout = setTimeout(() => { fm.classList.add('translate-y-20', 'opacity-0'); }, 3000);
        };

        function toggleMenu(event, menuId) {
            event.stopPropagation();
            const menu = document.getElementById(menuId);
            const isHidden = menu.classList.contains('hidden');
            document.querySelectorAll('.supplier-dropdown-menu').forEach(m => m.classList.add('hidden'));
            if (isHidden) menu.classList.remove('hidden');
        }

        document.addEventListener('click', function() {
            document.querySelectorAll('.supplier-dropdown-menu').forEach(menu => menu.classList.add('hidden'));
        });

        function updateModalPhotoState(hasImage = false) {
            const hasOverlay = document.getElementById('hasPhotoOverlay');
            const noLink = document.getElementById('noPhotoLink');
            
            if (hasImage) {
                hasOverlay.classList.remove('hidden');
                noLink.classList.add('hidden');
            } else {
                hasOverlay.classList.add('hidden');
                noLink.classList.remove('hidden');
            }
        }

        function previewLogo(input) {
            const preview = document.getElementById('logoPreview');
            const placeholder = document.getElementById('logoPlaceholder');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.classList.remove('hidden');
                    placeholder.classList.add('hidden');
                    
                    updateModalPhotoState(true); 
                    document.getElementById('remove_image').value = '0';
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function removePhoto(e) {
            e.preventDefault();
            e.stopPropagation();
            
            document.getElementById('imageUploadInput').value = '';
            document.getElementById('imageUploadInputBlank').value = '';
            
            document.getElementById('logoPreview').classList.add('hidden');
            document.getElementById('logoPreview').src = '';
            document.getElementById('logoPlaceholder').classList.remove('hidden');
            
            updateModalPhotoState(false); 
            
            document.getElementById('remove_image').value = '1';
        }

        function closeModal() {
            modal.classList.add('hidden');
        }

        function openModal() {
            form.reset();
            document.getElementById('supplier_id').value = '';
            document.getElementById('modalTitle').textContent = 'Add New Supplier';
            document.getElementById('modalIcon').textContent = 'domain_add';
            document.getElementById('btnText').textContent = 'Create Supplier';
            document.getElementById('btnIcon').textContent = 'add_circle';
            
            document.getElementById('remove_image').value = '0';
            document.getElementById('logoPreview').classList.add('hidden'); 
            document.getElementById('logoPreview').src = ""; 
            document.getElementById('logoPlaceholder').classList.remove('hidden');
            
            updateModalPhotoState(false); 

            modal.classList.remove('hidden');
        }

        window.editSupplier = (supplier) => {
            document.getElementById('modalTitle').textContent = 'Edit Supplier Profile';
            document.getElementById('modalIcon').textContent = 'storefront';
            document.getElementById('btnText').textContent = 'Update Supplier';
            document.getElementById('btnIcon').textContent = 'save';
            
            document.getElementById('supplier_id').value = supplier.supplier_id;
            document.getElementById('supplier_name').value = supplier.supplier_name;
            document.getElementById('contact_person').value = supplier.contact_person || '';
            document.getElementById('phone').value = supplier.phone || '';
            document.getElementById('email').value = supplier.email || '';
            document.getElementById('address').value = supplier.address || '';
            document.getElementById('status').value = supplier.status || 'Active';

            document.getElementById('remove_image').value = '0';

            if(supplier.image_url) { 
                document.getElementById('logoPreview').src = "../../" + supplier.image_url; 
                document.getElementById('logoPreview').classList.remove('hidden'); 
                document.getElementById('logoPlaceholder').classList.add('hidden'); 
                updateModalPhotoState(true);
            } else {
                document.getElementById('logoPreview').classList.add('hidden'); 
                document.getElementById('logoPreview').src = ""; 
                document.getElementById('logoPlaceholder').classList.remove('hidden');
                updateModalPhotoState(false);
            }

            modal.classList.remove('hidden');
        }

        async function deleteSupplier(id, name) {
            if(!confirm(`Are you sure you want to permanently delete supplier: ${name}?`)) return;
            const fd = new FormData(); 
            fd.append('action_type', 'delete_supplier'); 
            fd.append('delete_supplier_id', id);
            
            try {
                const res = await fetch('', { method:'POST', body:fd }).then(r => r.json());
                if(res.success) {
                    showFlash(res.message);
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showFlash(res.message, 'error');
                }
            } catch(e) { showFlash("Error deleting supplier.", "error"); }
        }

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('submitBtn');
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<span class="animate-spin material-icons text-sm">autorenew</span> Saving...';
            btn.disabled = true;

            const formData = new FormData(form);

            try {
                const res = await fetch('', { method:'POST', body: formData }).then(r => r.json());
                if(res.success) {
                    showFlash(res.message);
                    closeModal();
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showFlash(res.message, 'error');
                    btn.innerHTML = originalHTML;
                    btn.disabled = false;
                }
            } catch(e) {
                showFlash("Error saving supplier.", "error");
                btn.innerHTML = originalHTML;
                btn.disabled = false;
            }
        });
    </script>
</body>
</html>