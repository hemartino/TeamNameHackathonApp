<?php
// api/submit_eval.php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/db.php";

session_start();
if (empty($_SESSION['user_id'])) {
    respond(["success" => false, "message" => "Not authenticated."], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(["success" => false, "message" => "Method not allowed."], 405);
}

$body      = json_decode(file_get_contents("php://input"), true) ?? [];
$facultyId = (int)($body['teacher_id'] ?? 0);   // frontend sends "teacher_id"
$role      = $_SESSION['role'];
$ratings   = $body['ratings'] ?? [];
$comment   = trim($body['comment'] ?? '');

if (!$facultyId || empty($ratings)) {
    respond(["success" => false, "message" => "Missing required fields."]);
}

// Only students/ph/dean can evaluate (not admin)
$evalRoles = ['student','ph','dean'];
if (!in_array($role, $evalRoles, true)) {
    respond(["success" => false, "message" => "Admins do not submit evaluations."]);
}

// Get active period
$periodId = activePeriod($conn);
if (!$periodId) {
    respond(["success" => false, "message" => "No active evaluation period."]);
}

// Check period is still open
$now = date('Y-m-d');
$pr  = $conn->query("SELECT date_open, date_close FROM eval_periods WHERE id=$periodId")->fetch_assoc();
if ($now < $pr['date_open'] || $now > $pr['date_close']) {
    respond(["success" => false, "message" => "The evaluation period is not currently open."]);
}

// Build anonymous token (no email stored)
// We reconstruct a pseudo-email hash from session data + user_id
$anonSeed  = $_SESSION['email_hash'] . $_SESSION['user_id'];
$token     = hash('sha256', $anonSeed . "|{$periodId}|{$facultyId}");

// Check for duplicate submission
$dupCheck = $conn->prepare(
    "SELECT id FROM evaluations WHERE evaluator_token=? AND period_id=? AND faculty_id=?"
);
$dupCheck->bind_param("sii", $token, $periodId, $facultyId);
$dupCheck->execute();
$existing = $dupCheck->get_result()->fetch_assoc();
$dupCheck->close();
if ($existing) {
    respond(["success" => false, "message" => "You have already evaluated this faculty member."]);
}

// Validate criteria belong to this role
$validCrit = [];
$cStmt = $conn->prepare("SELECT id FROM criteria WHERE evaluator_role=?");
$cStmt->bind_param("s", $role);
$cStmt->execute();
$cRes = $cStmt->get_result();
while ($row = $cRes->fetch_assoc()) { $validCrit[$row['id']] = true; }
$cStmt->close();

foreach (array_keys($ratings) as $cid) {
    if (!isset($validCrit[$cid])) {
        respond(["success" => false, "message" => "Invalid criterion: $cid"]);
    }
}
if (count($ratings) !== count($validCrit)) {
    respond(["success" => false, "message" => "Please rate all criteria."]);
}

// Validate rating values
foreach ($ratings as $cid => $val) {
    $v = (int)$val;
    if ($v < 1 || $v > 5) {
        respond(["success" => false, "message" => "Ratings must be between 1 and 5."]);
    }
}

// --- Transaction ---
$conn->begin_transaction();
try {
    // Insert evaluation
    $ins = $conn->prepare(
        "INSERT INTO evaluations (period_id, faculty_id, evaluator_role, evaluator_token, comment_text)
         VALUES (?, ?, ?, ?, ?)"
    );
    $commentVal = $comment ?: null;
    $ins->bind_param("iisss", $periodId, $facultyId, $role, $token, $commentVal);
    $ins->execute();
    $evalId = $conn->insert_id;
    $ins->close();

    // Insert ratings
    $rIns = $conn->prepare(
        "INSERT INTO eval_ratings (evaluation_id, criterion_id, rating) VALUES (?, ?, ?)"
    );
    foreach ($ratings as $cid => $val) {
        $v = (int)$val;
        $rIns->bind_param("isi", $evalId, $cid, $v);
        $rIns->execute();
    }
    $rIns->close();

    // Audit log
    $roleLabels = ['student'=>'Student [Anon]','ph'=>'Prog. Head [Anon]','dean'=>'Dean [Anon]'];
    $actor      = $roleLabels[$role] ?? 'Evaluator [Anon]';
    $facRow     = $conn->query("SELECT name FROM faculty WHERE id=$facultyId")->fetch_assoc();
    $facName    = $facRow['name'] ?? 'Unknown';
    $action     = "Submitted evaluation for $facName";

    $aLog = $conn->prepare(
        "INSERT INTO audit_log (actor, action, log_type) VALUES (?, ?, 'eval')"
    );
    $aLog->bind_param("ss", $actor, $action);
    $aLog->execute();
    $aLog->close();

    $conn->commit();
    respond(["success" => true, "message" => "Evaluation submitted anonymously."]);

} catch (Exception $e) {
    $conn->rollback();
    respond(["success" => false, "message" => "Submission failed. Please try again."], 500);
}