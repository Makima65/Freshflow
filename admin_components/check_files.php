<?php
// FILE: check_files.php
$path = __DIR__ . '/includes/';

echo "<h1>📂 Files in: $path</h1>";

if (is_dir($path)) {
    $files = scandir($path);
    echo "<ul>";
    foreach ($files as $file) {
        if ($file != "." && $file != "..") {
            echo "<li>📄 $file</li>";
        }
    }
    echo "</ul>";
} else {
    echo "<h3 style='color:red'>❌ The folder 'includes' does not exist here!</h3>";
}
?>