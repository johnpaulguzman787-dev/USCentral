<?php
include 'config/db.php';
session_start();

/* =======================
AUTH
======================= */
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = (int) $_SESSION['user_id'];

/* =======================
GET USER ORG
======================= */
$stmt = $conn->prepare("SELECT org_id FROM users WHERE id=?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();

if (!$res || empty($res['org_id'])) {
    die("No organization assigned.");
}

$orgId = (int) $res['org_id'];

/* =======================
ACTIVE ACADEMIC YEAR
======================= */
$ay = $conn->query("
    SELECT id, year_start, year_end 
    FROM academic_years 
    WHERE is_active=1 LIMIT 1
")->fetch_assoc();

if (!$ay) die("No active academic year.");
$ayId = (int) $ay['id'];

$msg = '';

/* =======================
SUBMIT REPORT
======================= */
if (isset($_POST['submit_report'])) {
    $reportId = (int) $_POST['report_id'];

    // CHECK IF ALREADY SUBMITTED
    $check = $conn->prepare("
        SELECT id FROM submitted_reports 
        WHERE report_id=? AND org_id=?
    ");
    $check->bind_param("ii", $reportId, $orgId);
    $check->execute();
    $exists = $check->get_result()->fetch_assoc();

    if ($exists) {
        $msg = "You have already submitted this report.";
    } else {
        $ins = $conn->prepare("
            INSERT INTO submitted_reports (report_id, org_id)
            VALUES (?, ?)
        ");
        $ins->bind_param("ii", $reportId, $orgId);
        $ins->execute();
        $msg = "Report submitted successfully!";
    }
}

/* =======================
FETCH PUBLISHED REPORTS
======================= */
$published = $conn->query("
    SELECT * FROM created_reports
    WHERE status='Published' AND academic_year_id=$ayId
    ORDER BY published_at DESC
");

?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>User Reports</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{background:#f4f6fb;padding:30px;}
.card-box{background:#fff;border-radius:16px;padding:24px;}
</style>
</head>
<body>

<div class="container">
<h3>Reports – AY <?= $ay['year_start'].'-'.$ay['year_end'] ?></h3>
<?php if($msg): ?><div class="alert alert-success"><?= $msg ?></div><?php endif; ?>

<div class="card-box mt-3">
<table class="table">
<thead>
<tr><th>Title</th><th>Category</th><th>Date</th><th>Action</th></tr>
</thead>
<tbody>
<?php while($r = $published->fetch_assoc()): ?>
<tr>
<td><?= htmlspecialchars($r['title']) ?></td>
<td><?= htmlspecialchars($r['category']) ?></td>
<td><?= date('M d, Y', strtotime($r['published_at'])) ?></td>
<td>
<form method="POST" style="display:inline;">
    <input type="hidden" name="report_id" value="<?= $r['id'] ?>">
    <button name="submit_report" class="btn btn-sm btn-success"
    onclick="return confirm('Submit this report?')">Submit</button>
</form>

<?php if (!empty($r['file_data'])): ?>
<a class="btn btn-sm btn-outline-primary"
href="data:<?= $r['file_type'] ?>;base64,<?= $r['file_data'] ?>"
download="<?= htmlspecialchars($r['file_name']) ?>">Download</a>
<?php endif; ?>
</td>
</tr>
<?php endwhile; ?>
</tbody>
</table>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
