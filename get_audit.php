<?php
// api/get_audit.php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/db.php";

session_start();
if (empty($_SESSION['user_id'])) {
    respond(["success" => false, "message" => "Not authenticated."], 401);
}
// Only ph, dean, admin can view audit trail
$allowedRoles = ['ph','dean','admin'];
if (!in_array($_SESSION['role'], $allowedRoles, true)) {
    respond(["success" => false, "message" => "Access denied."], 403);
}

$limit  = min((int)($_GET['limit']  ?? 50), 200);
$offset = (int)($_GET['offset'] ?? 0);
$filter = trim($_GET['q'] ?? '');

if ($filter !== '') {
    $like = "%$filter%";
    $stmt = $conn->prepare(
        "SELECT actor, action, log_type, created_at
         FROM audit_log
         WHERE action LIKE ? OR actor LIKE ?
         ORDER BY created_at DESC
         LIMIT ? OFFSET ?"
    );
    $stmt->bind_param("ssii", $like, $like, $limit, $offset);
} else {
    $stmt = $conn->prepare(
        "SELECT actor, action, log_type, created_at
         FROM audit_log
         ORDER BY created_at DESC
         LIMIT ? OFFSET ?"
    );
    $stmt->bind_param("ii", $limit, $offset);
}

$stmt->execute();
$res  = $stmt->get_result();
$logs = [];
while ($row = $res->fetch_assoc()) {
    $logs[] = [
        "t"    => $row['created_at'],
        "who"  => $row['actor'],
        "what" => $row['action'],
        "type" => $row['log_type']
    ];
}
$stmt->close();

respond(["success" => true, "logs" => $logs]);