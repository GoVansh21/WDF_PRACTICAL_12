<?php
require_once "db.php";

$username = "admin";
$password = password_hash("Admin@123", PASSWORD_DEFAULT);

$stmt = $conn->prepare(
    "INSERT INTO admins (username, password)
     VALUES (?, ?)
     ON DUPLICATE KEY UPDATE username = username"
);
$stmt->bind_param("ss", $username, $password);
$stmt->execute();

echo "Admin setup completed. Username: admin | Password: Admin@123";
?>