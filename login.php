<?php
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/db.php";

$body = json_decode(file_get_contents("php://input"), true);

if (!$body) {
    respond(["success" => false, "message" => "Invalid request body."], 400);
}

$email     = strtolower(trim($body["email"]     ?? ""));
$id_number = trim($body["id_number"] ?? "");
$role      = trim($body["role"]      ?? "");

if (!$email || !$id_number || !$role) {
    respond(["success" => false, "message" => "Missing fields."], 400);
}

$stmt = $conn->prepare(
    "SELECT id, role FROM users 
     WHERE gmail_address = ? AND id_number = ? AND role = ? 
     LIMIT 1"
);

if (!$stmt) {
    respond(["success" => false, "message" => "Query error: " . $conn->error], 500);
}

$stmt->bind_param("sss", $email, $id_number, $role);
$stmt->execute();
$result = $stmt->get_result();
$user   = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    respond(["success" => false, "message" => "Invalid credentials. Please try again."]);
}

respond(["success" => true, "role" => $user["role"]]);