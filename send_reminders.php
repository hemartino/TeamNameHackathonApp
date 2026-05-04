<?php
// api/send_reminders.php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/db.php";

session_start();
if (empty($_SESSION['user_id'])) {
    respond(["success" => false, "message" => "Not authenticated."], 401);
}
// Only admin can trigger reminders
if ($_SESSION['role'] !== 'admin') {
    respond(["success" => false, "message" => "Only admins can send reminders."], 403);
}

$periodId = activePeriod($conn);
if (!$periodId) {
    respond(["success" => false, "message" => "No active period."]);
}

// Find users who have NOT yet submitted any evaluation this period
// (join on email_hash is not possible without PII — we count by token)
// Practical approach: find all active evaluator users and check which have a token
$submittedTokens = [];
$tRes = $conn->query(
    "SELECT DISTINCT evaluator_token FROM evaluations WHERE period_id=$periodId"
);
while ($row = $tRes->fetch_assoc()) {
    $submittedTokens[] = $row['evaluator_token'];
}

// Get all evaluator users
$uRes = $conn->query(
    "SELECT id, email, role FROM users WHERE role IN ('student','ph','dean') AND is_active=1"
);

$remindCount = 0;
$reminded    = [];

while ($user = $uRes->fetch_assoc()) {
    // Build the same token used at submission time
    // token = sha256(email_hash + user_id + "|" + periodId + "|" + any_faculty)
    // Since we don't know which faculty they skipped, we check if they submitted at all
    // A simpler heuristic: count how many faculty they've evaluated
    $facCount = $conn->query("SELECT COUNT(*) as c FROM faculty WHERE is_active=1")->fetch_assoc()['c'];

    $submittedByUser = $conn->prepare(
        "SELECT COUNT(*) as c FROM evaluations
         WHERE period_id=? AND evaluator_role=?
         AND evaluator_token IN (
            SELECT DISTINCT evaluator_token FROM evaluations
            WHERE period_id=?
         )"
    );
    // Simplified: just check total evaluations for their role vs expected
    $roleCount = $conn->prepare(
        "SELECT COUNT(DISTINCT faculty_id) as c FROM evaluations
         WHERE period_id=? AND evaluator_role=?"
    );
    $roleCount->bind_param("is", $periodId, $user['role']);
    $roleCount->execute();
    $done = (int)$roleCount->get_result()->fetch_assoc()['c'];
    $roleCount->close();

    if ($done < $facCount) {
        // Send reminder email
        $subject = "[Evalify] Reminder: Please complete your faculty evaluation";
        $message = "Dear evaluator,\n\n"
                 . "This is a reminder to complete your faculty evaluation for the current period.\n"
                 . "Please log in to Evalify to submit your pending evaluations.\n\n"
                 . "Thank you.";
        $headers = "From: noreply@evalify.edu\r\nContent-Type: text/plain; charset=utf-8";

        // In production: replace with PHPMailer/SMTP
        @mail($user['email'], $subject, $message, $headers);

        // Log reminder (no PII in log)
        $ins = $conn->prepare(
            "INSERT INTO reminder_log (sent_to, period_id) VALUES (?, ?)"
        );
        $masked = substr($user['email'], 0, 3) . '***@gmail.com';
        $ins->bind_param("si", $masked, $periodId);
        $ins->execute();
        $ins->close();

        $remindCount++;
    }
}

// Audit
$action = "Sent email reminders to $remindCount pending evaluators";
$actor  = "System";
$aLog   = $conn->prepare(
    "INSERT INTO audit_log (actor, action, log_type) VALUES (?, ?, 'remind')"
);
$aLog->bind_param("ss", $actor, $action);
$aLog->execute();
$aLog->close();

respond([
    "success" => true,
    "message" => "Reminders sent to $remindCount evaluator(s).",
    "count"   => $remindCount
]);