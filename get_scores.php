<?php
// api/get_scores.php
// Returns average ratings per faculty per criterion group for the active period.
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/db.php";

session_start();
if (empty($_SESSION['user_id'])) {
    respond(["success" => false, "message" => "Not authenticated."], 401);
}

$periodId = activePeriod($conn);
if (!$periodId) {
    respond(["success" => false, "message" => "No active period."]);
}

// Build: { facultyId: { student: { s1: avg, s2: avg }, ph: {...}, dean: {...} } }
$sql = "
    SELECT
        e.faculty_id,
        e.evaluator_role,
        r.criterion_id,
        ROUND(AVG(r.rating), 2) AS avg_rating,
        COUNT(r.id)             AS count
    FROM eval_ratings r
    JOIN evaluations  e ON r.evaluation_id = e.id
    WHERE e.period_id = ?
    GROUP BY e.faculty_id, e.evaluator_role, r.criterion_id
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $periodId);
$stmt->execute();
$res = $stmt->get_result();

$scores = [];
while ($row = $res->fetch_assoc()) {
    $fid  = $row['faculty_id'];
    $role = $row['evaluator_role'];
    $cid  = $row['criterion_id'];
    $scores[$fid][$role][$cid] = (float)$row['avg_rating'];
}
$stmt->close();

// Also return faculty list
$faculty = [];
$fRes = $conn->query("SELECT id, name, department, initials, color_hex, bg_hex FROM faculty WHERE is_active=1 ORDER BY id");
while ($row = $fRes->fetch_assoc()) {
    $faculty[] = $row;
}

respond([
    "success"   => true,
    "period_id" => $periodId,
    "scores"    => $scores,
    "faculty"   => $faculty
]);