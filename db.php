<?php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "evalify_db";
$port = 3306;

$conn = new mysqli($host, $user, $pass, $db, $port);

if ($conn->connect_error) {
    http_response_code(500);
    die(json_encode([
        "success" => false,
        "message" => "Database connection failed: " . $conn->connect_error
    ]));
}

$conn->set_charset("utf8mb4");

function respond(array $data, int $status = 200): void {
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($data);
    exit;
}