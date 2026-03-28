<?php
// index.php
// This file redirects the user to the Login Page automatically.

// Point this to where your actual login file is.
// Based on your code, it seems your login is inside "admin_components"
header("Location: admin_components/admin_login.php");
exit;
?>