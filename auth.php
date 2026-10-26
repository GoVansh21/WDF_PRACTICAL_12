
<?php
require_once "db.php";

if (
    empty($_SESSION['admin_id']) ||
    ($_SESSION['role'] ?? '') !== 'admin'
) {
    header("Location: login.php");
    exit;
}
?>