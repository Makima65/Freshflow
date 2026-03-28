<?php
if (extension_loaded('mysqli')) {
    echo "✅ MySQLi is ENABLED!";
} else {
    echo "❌ MySQLi is STILL DISABLED. Check your php.ini again.";
}
?>