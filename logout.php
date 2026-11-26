<?php
/**
 * Logout — destroys session and redirects to admin login.
 */
declare(strict_types=1);
session_start();
$_SESSION = [];
session_destroy();
header('Location: admin/login.php');
exit;
