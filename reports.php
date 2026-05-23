<?php
include 'config/db.php';
session_start();

// Approve submitted report
if(isset($_POST['approve'])) {
    $id = (int) $_POST['report_id'];
    $stmt = $conn->prepare("UPDATE submitted_reports SET status='Approved', reason=NULL WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header("Location: reports.php");
    exit;
}

// Not Approved with reason
if(isset($_POST['not_approved'])) {
    $id = (int) $_POST['report_id'];
    $reason = $_POST['reason'];
    $stmt = $conn->prepare("UPDATE submitted_reports SET status='Not Approved', reason=? WHERE id=?");
    $stmt->bind_param("si", $reason, $id);
    $stmt->execute();
    header("Location: reports.php");
    exit;
}

/* =======================
AUTH
======================= */
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = (int) $_SESSION['user_id'];

// GET USER ROLE
$stmtRole = $conn->prepare("SELECT role FROM users WHERE id=?");
$stmtRole->bind_param("i", $userId);
$stmtRole->execute();
$resRole = $stmtRole->get_result()->fetch_assoc();
$role = $resRole['role'] ?? 'User';

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

// Function to get dashboard statistics
function getDashboardStats($conn, $ayId, $orgId = null) {
    $stats = [];
    
    // 1. Report Status Summary - filtered by org_id if provided
    if ($orgId) {
        $stats['totalPublished'] = $conn->query("SELECT COUNT(*) as count FROM created_reports WHERE status='Published' AND academic_year_id=$ayId AND org_id=$orgId")->fetch_assoc()['count'];
        $stats['totalDrafts'] = $conn->query("SELECT COUNT(*) as count FROM created_reports WHERE status='Draft' AND academic_year_id=$ayId AND org_id=$orgId")->fetch_assoc()['count'];
        $stats['totalNotPublished'] = $conn->query("SELECT COUNT(*) as count FROM created_reports WHERE status != 'Published' AND academic_year_id=$ayId AND org_id=$orgId")->fetch_assoc()['count'];
    } else {
        $stats['totalPublished'] = $conn->query("SELECT COUNT(*) as count FROM created_reports WHERE status='Published' AND academic_year_id=$ayId")->fetch_assoc()['count'];
        $stats['totalDrafts'] = $conn->query("SELECT COUNT(*) as count FROM created_reports WHERE status='Draft' AND academic_year_id=$ayId")->fetch_assoc()['count'];
        $stats['totalNotPublished'] = $conn->query("SELECT COUNT(*) as count FROM created_reports WHERE status != 'Published' AND academic_year_id=$ayId")->fetch_assoc()['count'];
    }

    // 2. Submission Activity - FILTERED BY ACADEMIC YEAR
    $stats['totalOrganizations'] = $conn->query("SELECT COUNT(*) as count FROM organizations")->fetch_assoc()['count'];
    
    // Orgs that have submitted reports in current academic year
    $stats['orgsSubmitted'] = $conn->query("
        SELECT COUNT(DISTINCT s.org_id) as count 
        FROM submitted_reports s
        JOIN created_reports cr ON s.report_id = cr.id
        WHERE s.status='Approved' 
        AND cr.academic_year_id = $ayId
    ")->fetch_assoc()['count'];
    
    $stats['orgsPending'] = $stats['totalOrganizations'] - $stats['orgsSubmitted'];

    // Latest Submission Time for current academic year
    $latestSubmission = $conn->query("
        SELECT MAX(s.submitted_at) as latest 
        FROM submitted_reports s
        JOIN created_reports cr ON s.report_id = cr.id
        WHERE cr.academic_year_id = $ayId
    ")->fetch_assoc()['latest'];
    
    $stats['latestTime'] = $latestSubmission ? date('M d, Y h:i A', strtotime($latestSubmission)) : 'No submissions yet';

    // Submission Trend (Today, This Week, This Month) - filtered by academic year
    $today = date('Y-m-d');
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $monthStart = date('Y-m-d', strtotime('first day of this month'));

    $stats['submissionsToday'] = $conn->query("
        SELECT COUNT(*) as count 
        FROM submitted_reports s
        JOIN created_reports cr ON s.report_id = cr.id
        WHERE DATE(s.submitted_at) = '$today'
        AND cr.academic_year_id = $ayId
    ")->fetch_assoc()['count'];
    
    $stats['submissionsThisWeek'] = $conn->query("
        SELECT COUNT(*) as count 
        FROM submitted_reports s
        JOIN created_reports cr ON s.report_id = cr.id
        WHERE DATE(s.submitted_at) >= '$weekStart'
        AND cr.academic_year_id = $ayId
    ")->fetch_assoc()['count'];
    
    $stats['submissionsThisMonth'] = $conn->query("
        SELECT COUNT(*) as count 
        FROM submitted_reports s
        JOIN created_reports cr ON s.report_id = cr.id
        WHERE DATE(s.submitted_at) >= '$monthStart'
        AND cr.academic_year_id = $ayId
    ")->fetch_assoc()['count'];

    // Top Submitting Organizations for current academic year
    $topOrgs = $conn->query("
        SELECT o.org_name, COUNT(s.id) as submission_count
        FROM submitted_reports s
        JOIN created_reports cr ON s.report_id = cr.id
        JOIN organizations o ON s.org_id = o.id
        WHERE s.status = 'Approved'
        AND cr.academic_year_id = $ayId
        GROUP BY s.org_id
        ORDER BY submission_count DESC
        LIMIT 3
    ");
    
    $stats['topOrgs'] = [];
    while($org = $topOrgs->fetch_assoc()) {
        $stats['topOrgs'][] = $org;
    }

    // 3. Recent Activity Feed - filtered by academic year
    $recentActivities = $conn->query("
        (
            SELECT 
                'published' AS type,
                cr.id AS report_id,
                cr.title AS subject,
                cr.published_at AS activity_date,
                CONCAT('Report \"', cr.title, '\" was published') AS description,
                NULL AS org_name
            FROM created_reports cr
            WHERE cr.status = 'Published'
            AND cr.academic_year_id = $ayId
            ORDER BY cr.published_at DESC
            LIMIT 5
        )
        UNION
        (
            SELECT 
                'submitted' AS type,
                s.id AS report_id,
                r.title AS subject,
                s.submitted_at AS activity_date,
                CONCAT('Organization submitted report') AS description,
                o.org_name
            FROM submitted_reports s
            JOIN created_reports r ON s.report_id = r.id
            JOIN organizations o ON s.org_id = o.id
            WHERE s.status = 'Approved'
            AND r.academic_year_id = $ayId
            ORDER BY s.submitted_at DESC
            LIMIT 5
        )
        UNION
        (
            SELECT 
                'updated' AS type,
                cr.id AS report_id,
                cr.title AS subject,
                cr.updated_at AS activity_date,
                CONCAT('Draft \"', cr.title, '\" was updated') AS description,
                NULL AS org_name
            FROM created_reports cr
            WHERE cr.status = 'Draft'
            AND cr.updated_at IS NOT NULL
            AND cr.academic_year_id = $ayId
            ORDER BY cr.updated_at DESC
            LIMIT 5
        )
        UNION
        (
            SELECT 
                'not_approved' AS type,
                s.id AS report_id,
                r.title AS subject,
                s.submitted_at AS activity_date,
                CONCAT('Report was marked Not Approved') AS description,
                o.org_name
            FROM submitted_reports s
            JOIN created_reports r ON s.report_id = r.id
            JOIN organizations o ON s.org_id = o.id
            WHERE s.status = 'Not Approved'
            AND r.academic_year_id = $ayId
            ORDER BY s.submitted_at DESC
            LIMIT 5
        )
        ORDER BY activity_date DESC
        LIMIT 10
    ");
    
    $stats['recentActivities'] = [];
    while($activity = $recentActivities->fetch_assoc()) {
        $stats['recentActivities'][] = $activity;
    }
    
    return $stats;
}

// Get initial dashboard stats
$stats = getDashboardStats($conn, $ayId, $orgId);

$msg = '';

/* =======================
CREATE (DRAFT / PUBLISH)
======================= */
if (isset($_POST['create_report'])) {

    // Only Users are restricted from creating multiple reports
    if ($role === 'User') {
        $check = $conn->prepare("
            SELECT r.id 
            FROM created_reports r
            WHERE r.org_id=? AND r.academic_year_id=? 
            LIMIT 1
        ");
        $check->bind_param("ii", $orgId, $ayId);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();

        if ($existing) {
            $msg = "Your organization has already submitted a report. You can only edit it.";
        }
    }

    // If no message yet (or Admin), proceed with creating the report
    if (!$msg) {
        $status = $_POST['action'] === 'publish' ? 'Published' : 'Draft';
        $publishedAt = $status === 'Published' ? date('Y-m-d H:i:s') : null;

        $fileData = null;
        $fileType = null;
        $fileName = null;

        if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $fileTmp  = $_FILES['file']['tmp_name'];
            $fileType = $_FILES['file']['type'];
            $fileName = $_FILES['file']['name'];
            $fileData = base64_encode(file_get_contents($fileTmp));
        }

        $stmt = $conn->prepare("
            INSERT INTO created_reports
            (org_id, created_by, academic_year_id, title, category, description, file_data, file_type, file_name, status, published_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ");

        $stmt->bind_param(
            "iiissssssss",
            $orgId,
            $userId,
            $ayId,
            $_POST['title'],
            $_POST['category'],
            $_POST['description'],
            $fileData,
            $fileType,
            $fileName,
            $status,
            $publishedAt
        );

        $stmt->execute();
        $reportId = $stmt->insert_id;
        $msg = "Report saved as $status.";
        
        // Update stats after creating report
        $stats = getDashboardStats($conn, $ayId, $orgId);
    }
}

/* =======================
UPDATE
======================= */
if (isset($_POST['update_report'])) {

    $id = (int) $_POST['report_id'];

    // GET CURRENT STATUS (LOCK CHECK)
    $check = $conn->prepare("
        SELECT status FROM created_reports 
        WHERE id=? AND org_id=?
    ");
    $check->bind_param("ii", $id, $orgId);
    $check->execute();
    $current = $check->get_result()->fetch_assoc();

    if (!$current) {
        die("Invalid report.");
    }

    $currentStatus = $current['status'];

    // STATUS CHANGE LOGIC
    $newStatus = $currentStatus;
    $publishedAt = null;

    if ($currentStatus === 'Draft' && isset($_POST['status']) && $_POST['status'] === 'Published') {
        $newStatus = 'Published';
        $publishedAt = date('Y-m-d H:i:s');

        // INSERT SUBMISSION RECORD
        $sub = $conn->prepare("
            INSERT INTO submitted_reports (report_id, org_id)
            VALUES (?, ?)
        ");
        $sub->bind_param("ii", $id, $orgId);
        $sub->execute();
    }

    // FILE HANDLING
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {

        $fileTmp  = $_FILES['file']['tmp_name'];
        $fileType = $_FILES['file']['type'];
        $fileName = $_FILES['file']['name']; // ⭐ ORIGINAL NAME
        $fileData = base64_encode(file_get_contents($fileTmp));

        $stmt = $conn->prepare("
            UPDATE created_reports
            SET title=?, category=?, description=?, 
                file_data=?, file_type=?, file_name=?,
                status=?, published_at=IFNULL(published_at, ?)
            WHERE id=? AND org_id=?
        ");

        $stmt->bind_param(
            "ssssssssii",
            $_POST['title'],
            $_POST['category'],
            $_POST['description'],
            $fileData,
            $fileType,
            $fileName,
            $newStatus,
            $publishedAt,
            $id,
            $orgId
        );

    } else {

        $stmt = $conn->prepare("
            UPDATE created_reports
            SET title=?, category=?, description=?, 
                status=?, published_at=IFNULL(published_at, ?)
            WHERE id=? AND org_id=?
        ");

        $stmt->bind_param(
            "sssssii",
            $_POST['title'],
            $_POST['category'],
            $_POST['description'],
            $newStatus,
            $publishedAt,
            $id,
            $orgId
        );
    }

    $stmt->execute();
    $msg = "Report updated successfully.";
    
    // Update stats after updating report
    $stats = getDashboardStats($conn, $ayId, $orgId);
}

/* =======================
DELETE
======================= */
if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $conn->query("DELETE FROM created_reports WHERE id=$id AND org_id=$orgId");
    header("Location: reports.php");
    exit;
}

/* =======================
FETCH
======================= */
$published = $conn->query("
    SELECT * FROM created_reports
    WHERE org_id=$orgId AND academic_year_id=$ayId AND status='Published'
    ORDER BY published_at DESC
");

$drafts = $conn->query("
    SELECT * FROM created_reports
    WHERE org_id=$orgId AND academic_year_id=$ayId AND status='Draft'
    ORDER BY created_at DESC
");

function getSubmittedOrgs($conn, $reportId) {
    return $conn->query("
        SELECT o.org_name, s.submitted_at
        FROM submitted_reports s
        JOIN organizations o ON s.org_id = o.id
        WHERE s.report_id = $reportId
        ORDER BY s.submitted_at DESC
    ");
}

function hasOrgSubmitted($conn, $reportId, $orgId) {
    $stmt = $conn->prepare("
        SELECT 1 FROM submitted_reports 
        WHERE report_id=? AND org_id=? LIMIT 1
    ");
    $stmt->bind_param("ii", $reportId, $orgId);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

// Optional: Get all orgs for the dropdown/display
function getAllOrgs($conn) {
    return $conn->query("SELECT id, org_name FROM organizations ORDER BY org_name ASC");
}

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reports Management</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
:root {
    --primary: #3479DB;
    --primary-light: #E8F2FF;
    --gray-50: #f8f9fa;
    --gray-100: #e9ecef;
    --gray-200: #dee2e6;
    --gray-300: #ced4da;
    --gray-700: #495057;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Poppins', sans-serif;
    background: #f5f7fa;
    color: #333;
}

.content {
    margin-left: 280px;
    padding: 25px;
    min-height: 100vh;
}

@media (max-width: 768px) {
    .content {
        margin-left: 0;
        padding: 15px;
        padding-top: 70px;
    }
}

/* Header */
.page-header {
    margin-bottom: 25px;
}

.page-header h1 {
    font-size: 24px;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 5px;
}

.breadcrumb-item {
    font-size: 14px;
    color: #6c757d;
}

.breadcrumb-item.active {
    color: var(--primary);
    font-weight: 500;
}

/* Main Content Card */
.main-card {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #eaeaea;
    margin-bottom: 20px;
    overflow: hidden;
}

.card-header {
    padding: 18px 20px;
    border-bottom: 1px solid #eaeaea;
    background: white;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}

.card-header h2 {
    font-size: 16px;
    font-weight: 600;
    color: #2c3e50;
    margin: 0;
}

.card-body {
    padding: 20px;
}

/* Search */
.search-container {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-bottom: 15px;
}

.search-box {
    flex: 1;
    position: relative;
}

.search-box input {
    width: 100%;
    padding: 8px 12px 8px 35px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 13px;
}

.search-box input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(52, 121, 219, 0.1);
}

.search-box i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
    font-size: 14px;
}

/* Tables - CENTERED AND RESPONSIVE */
.table-responsive {
    border-radius: 6px;
    overflow: hidden;
    margin: 0 auto;
    max-width: 100%;
}

.table {
    margin-bottom: 0;
    border-collapse: separate;
    border-spacing: 0;
    width: 100%;
}

.table thead {
    background: #f8f9fa;
}

.table th {
    padding: 12px 15px;
    font-weight: 600;
    color: #495057;
    font-size: 13px;
    border-bottom: 2px solid #eaeaea;
    background: #f8f9fa;
    text-align: center;
    text-transform: capitalize;
}

.table td {
    padding: 12px 15px;
    border-bottom: 1px solid #eaeaea;
    vertical-align: middle;
    font-size: 13px;
    text-align: center;
}

.table td:first-child {
    text-align: left;
    text-transform: capitalize;
}

.table tbody tr:hover {
    background-color: #f8f9fa;
}

/* Badges */
.badge {
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 500;
    text-transform: capitalize;
}

.badge-Published {
    background: #D1FAE5;
    color: #059669;
}

.badge-Draft {
    background: #FEF3C7;
    color: #d97706;
}

/* Sidebar Cards */
.sidebar-section {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.sidebar-card {
    background: white;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #eaeaea;
    transition: all 0.3s ease;
}

.sidebar-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.12);
    transform: translateY(-2px);
}

.sidebar-card h3 {
    font-size: 15px;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-bottom: 10px;
    border-bottom: 2px solid var(--primary-light);
}

.sidebar-card h4 {
    font-size: 14px;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 15px;
}

/* Stats Card */
.stats-card {
    background: white;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #eaeaea;
}

.stats-card h4 {
    font-size: 15px;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 15px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.stats-card h4 i {
    color: var(--primary);
}

.stats-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid #f0f0f0;
}

.stats-item:last-child {
    border-bottom: none;
}

.stats-label {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    color: #6c757d;
}

.stats-label i {
    width: 20px;
    text-align: center;
}

.stats-label i.bi-megaphone {
    color: var(--primary);
}

.stats-label i.bi-file-earmark-check {
    color: #10b981;
}

.stats-label i.bi-file-earmark {
    color: #f59e0b;
}

.stats-label i.bi-eye {
    color: #8b5cf6;
}

.stats-label i.bi-clock {
    color: #8b5cf6;
}

.stats-label i.bi-graph-up {
    color: #059669;
}

.stats-value {
    font-weight: 600;
    font-size: 14px;
    color: #2c3e50;
}

.stats-total {
    margin-top: 15px;
    padding-top: 15px;
    border-top: 2px solid #eaeaea;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.stats-total-label {
    font-weight: 600;
    color: #2c3e50;
    font-size: 14px;
}

.stats-total-value {
    font-weight: 700;
    color: var(--primary);
    font-size: 16px;
}

/* Quick Stats */
.quick-stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    margin-top: 10px;
}

.quick-stat {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 15px;
    text-align: center;
    border: 1px solid #eaeaea;
    transition: all 0.3s ease;
}

.quick-stat:hover {
    background: white;
    border-color: var(--primary-light);
    transform: translateY(-2px);
}

.quick-stat-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 10px;
    font-size: 16px;
}

.quick-stat-icon.primary { background: var(--primary-light); color: var(--primary); }
.quick-stat-icon.success { background: #D1FAE5; color: #059669; }
.quick-stat-icon.warning { background: #FEF3C7; color: #d97706; }
.quick-stat-icon.info { background: #F3E8FF; color: #8b5cf6; }

.quick-stat-value {
    font-size: 18px;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 4px;
}

.quick-stat-label {
    font-size: 11px;
    color: #6c757d;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Tab Navigation */
.nav-pills .nav-link {
    padding: 8px 20px;
    font-size: 13px;
    font-weight: 500;
    color: #6c757d;
    border-radius: 20px;
    margin-right: 8px;
    border: 1px solid #eaeaea;
    background: white;
    text-transform: capitalize;
}

.nav-pills .nav-link.active {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

.nav-pills .nav-link .badge {
    font-size: 10px;
    padding: 2px 6px;
    background: rgba(255,255,255,0.2) !important;
}

/* Announcement Item */
.announcement-title {
    font-size: 14px;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 5px;
    text-transform: capitalize;
}

.announcement-meta {
    display: flex;
    gap: 15px;
    font-size: 11px;
    color: #6c757d;
    margin-bottom: 8px;
    flex-wrap: wrap;
}

.announcement-meta span {
    display: flex;
    align-items: center;
    gap: 4px;
}

.announcement-preview {
    font-size: 12px;
    color: #495057;
    line-height: 1.5;
    margin-bottom: 10px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-transform: capitalize;
}

.announcement-actions {
    display: flex;
    gap: 8px;
    justify-content: center;
    flex-wrap: wrap;
}

/* Modal Enhancements */
.modal-content {
    border: none;
    border-radius: 12px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.15);
    overflow: hidden;
}

.modal-header {
    background: linear-gradient(135deg, #3479DB 0%, #2a5fb0 100%);
    color: white;
    border-radius: 12px 12px 0 0;
    padding: 20px 25px;
    border: none;
}

.modal-header .modal-title {
    font-size: 18px;
    font-weight: 600;
    color: white;
    margin: 0;
    text-transform: capitalize;
}

.modal-header .btn-close {
    filter: brightness(0) invert(1);
    opacity: 0.8;
    background-size: 60%;
}

.modal-body {
    padding: 25px;
    background: #f8f9fa;
}

.modal-footer {
    background: white;
    border-top: 1px solid #eaeaea;
    padding: 20px 25px;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    flex-wrap: wrap;
}

/* Form Controls */
.form-control, .form-select {
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 10px 15px;
    font-size: 14px;
    transition: all 0.3s ease;
    background: white;
}

.form-control:focus, .form-select:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(52, 121, 219, 0.1);
    outline: none;
}

.form-label {
    font-weight: 500;
    color: #495057;
    margin-bottom: 8px;
    font-size: 13px;
    text-transform: capitalize;
}

/* Preview Area */
.preview-area {
    background: white;
    border: 1px solid #eaeaea;
    border-radius: 8px;
    padding: 20px;
    height: 100%;
    min-height: 300px;
    overflow-y: auto;
}

.preview-title {
    font-size: 18px;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 10px;
    padding-bottom: 10px;
    border-bottom: 2px solid var(--primary-light);
    text-transform: capitalize;
}

.preview-meta {
    display: flex;
    gap: 15px;
    margin-bottom: 15px;
    font-size: 12px;
    color: #6c757d;
    flex-wrap: wrap;
}

.preview-content {
    font-size: 14px;
    line-height: 1.6;
    color: #495057;
    white-space: pre-wrap;
}

/* Document Preview */
.document-preview {
    background: white;
    border: 1px solid #eaeaea;
    border-radius: 8px;
    padding: 15px;
    margin-top: 15px;
}

.document-preview h6 {
    font-size: 14px;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 10px;
    text-transform: capitalize;
}

/* Pagination */
.pagination {
    margin-bottom: 0;
    justify-content: center;
    flex-wrap: wrap;
}

.page-link {
    border: 1px solid #eaeaea;
    color: #6c757d;
    font-size: 13px;
    padding: 6px 12px;
}

.page-item.active .page-link {
    background: var(--primary);
    border-color: var(--primary);
    color: white;
}

.page-link:hover {
    background: var(--primary-light);
    color: var(--primary);
    border-color: var(--primary);
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 20px;
    color: #6c757d;
}

.empty-state i {
    font-size: 48px;
    margin-bottom: 15px;
    opacity: 0.5;
}

.empty-state p {
    margin: 0;
    font-size: 14px;
}

/* Custom adjustments for reports page */
.report-stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-top: 10px;
}

@media (max-width: 1200px) {
    .report-stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .report-stats-grid {
        grid-template-columns: 1fr;
    }
}

.report-stat {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 15px;
    text-align: center;
    border: 1px solid #eaeaea;
    transition: all 0.3s ease;
}

.report-stat:hover {
    background: white;
    border-color: var(--primary-light);
    transform: translateY(-2px);
}

.report-stat-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 10px;
    font-size: 16px;
}

.report-stat-icon.published { background: #D1FAE5; color: #059669; }
.report-stat-icon.draft { background: #FEF3C7; color: #d97706; }
.report-stat-icon.pending { background: #E8F2FF; color: var(--primary); }

.report-stat-value {
    font-size: 18px;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 4px;
}

.report-stat-label {
    font-size: 11px;
    color: #6c757d;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Submission Trend */
.submission-trend {
    margin-top: 15px;
}

.trend-chart {
    height: 40px;
    background: #f8f9fa;
    border-radius: 6px;
    overflow: hidden;
    margin: 10px 0;
}

.trend-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--primary), #2a5fb0);
    border-radius: 6px;
}

.trend-numbers {
    display: flex;
    justify-content: space-between;
    font-size: 11px;
    color: #6c757d;
    flex-wrap: wrap;
}

.trend-number {
    text-align: center;
    flex: 1;
    min-width: 80px;
    margin-bottom: 5px;
}

.trend-value {
    font-weight: 600;
    color: #2c3e50;
    display: block;
    font-size: 12px;
}

/* Top Organizations */
.top-orgs-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.top-org-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 6px;
    border: 1px solid #eaeaea;
    flex-wrap: wrap;
}

.top-org-info {
    display: flex;
    align-items: center;
    gap: 8px;
}

.top-org-rank {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    font-weight: 600;
    color: white;
    flex-shrink: 0;
}

.top-org-rank.gold { background: #f59e0b; }
.top-org-rank.silver { background: #94a3b8; }
.top-org-rank.bronze { background: #f97316; }

.top-org-name {
    font-size: 12px;
    font-weight: 500;
    color: #2c3e50;
    text-transform: capitalize;
}

.top-org-count {
    font-size: 11px;
    font-weight: 600;
    color: var(--primary);
    background: var(--primary-light);
    padding: 2px 8px;
    border-radius: 10px;
}

/* Responsive Design */
@media (max-width: 1200px) {
    .content {
        margin-left: 0;
        padding: 20px;
    }
    
    .row {
        flex-direction: column;
    }
    
    .col-lg-8, .col-lg-4 {
        width: 100%;
    }
    
    .sidebar-section {
        margin-top: 20px;
    }
}

@media (max-width: 992px) {
    .table-responsive {
        margin: 0;
    }
    
    .table th, .table td {
        padding: 10px 8px;
        font-size: 12px;
    }
    
    .announcement-actions {
        justify-content: flex-start;
    }
    
    .announcement-actions .btn {
        margin-bottom: 5px;
    }
}

@media (max-width: 768px) {
    .content {
        padding: 15px;
        padding-top: 70px;
    }
    
    .page-header h1 {
        font-size: 20px;
    }
    
    .card-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .card-header h2 {
        font-size: 15px;
    }
    
    .nav-pills {
        width: 100%;
        justify-content: center;
    }
    
    .nav-pills .nav-link {
        margin-bottom: 5px;
        font-size: 12px;
        padding: 6px 15px;
    }
    
    .table {
        display: block;
        overflow-x: auto;
        white-space: nowrap;
    }
    
    .table thead, .table tbody, .table th, .table td, .table tr {
        display: block;
    }
    
    .table thead tr {
        position: absolute;
        top: -9999px;
        left: -9999px;
    }
    
    .table tr {
        border: 1px solid #eaeaea;
        margin-bottom: 10px;
        border-radius: 8px;
    }
    
    .table td {
        border: none;
        border-bottom: 1px solid #eaeaea;
        position: relative;
        padding-left: 50%;
        text-align: right;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .table td:before {
        content: attr(data-label);
        position: absolute;
        left: 15px;
        width: 45%;
        padding-right: 10px;
        white-space: nowrap;
        text-align: left;
        font-weight: 600;
        color: #495057;
        text-transform: capitalize;
    }
    
    .table td:first-child {
        text-align: right;
    }
    
    .announcement-title {
        flex-direction: column;
        gap: 5px;
    }
    
    .announcement-meta {
        gap: 8px;
        font-size: 10px;
    }
    
    .quick-stats-grid {
        grid-template-columns: 1fr;
    }
    
    .modal-dialog {
        margin: 10px;
    }
    
    .modal-body {
        padding: 15px;
    }
    
    .modal-footer {
        padding: 15px;
    }
}

@media (max-width: 576px) {
    .content {
        padding: 10px;
        padding-top: 60px;
    }
    
    .search-box input {
        font-size: 12px;
        padding: 6px 10px 6px 30px;
    }
    
    .search-box i {
        left: 10px;
        font-size: 12px;
    }
    
    .badge {
        font-size: 10px;
        padding: 3px 8px;
    }
    
    .btn {
        font-size: 12px;
        padding: 6px 12px;
    }
    
    .btn-sm {
        padding: 4px 8px;
        font-size: 11px;
    }
    
    .stats-card, .sidebar-card {
        padding: 15px;
    }
    
    .stats-item {
        padding: 10px 0;
    }
    
    .stats-label, .stats-value {
        font-size: 12px;
    }
}

/* Toast Container */
.toast-container {
    z-index: 9999;
}

.btn-create-report:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(52, 121, 219, 0.2);
    color: white;
}

.btn-create-report:disabled {
    background: #94a3b8;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}
</style>
</head>

<body>
<?php include 'partials/sidenav.php'; ?>

<div class="content">
    <!-- Page Header -->
    <div class="page-header">
        <h1>Reports Management</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="#">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Reports</li>
            </ol>
        </nav>
    </div>

    <div class="row">
        <!-- LEFT SIDE: MAIN CONTENT -->
        <div class="col-lg-8">
            <!-- Main Card: Reports -->
            <div class="main-card mb-4">
                <div class="card-header">
                    <h2>Reports – Academic Year <?= $ay['year_start'].'-'.$ay['year_end'] ?></h2>
                    <div>
                        <?php
                        if ($role === 'User') {
                            $orgReport = $conn->prepare("
                                SELECT id FROM created_reports 
                                WHERE org_id=? AND academic_year_id=? LIMIT 1
                            ");
                            $orgReport->bind_param("ii", $orgId, $ayId);
                            $orgReport->execute();
                            $orgReportExists = $orgReport->get_result()->num_rows > 0;

                            if (!$orgReportExists): ?>
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
                                    <i class="bi bi-plus-lg me-1"></i> Create Report
                                </button>
                            <?php else: ?>
                                <button class="btn btn-primary" disabled title="Your organization already submitted">
                                    <i class="bi bi-plus-lg me-1"></i> Create Report
                                </button>
                            <?php endif;
                        } else { ?>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
                                <i class="bi bi-plus-lg me-1"></i> Create Report
                            </button>
                        <?php } ?>
                    </div>
                </div>

                <div class="card-body">
                    <!-- Tab Navigation -->
                    <ul class="nav nav-pills mb-4">
                        <li class="nav-item">
                            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#published">
                                <i class="bi bi-check-circle me-1"></i> Published
                                <span class="badge bg-success ms-1"><?= $published->num_rows ?></span>
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#drafts">
                                <i class="bi bi-pencil me-1"></i> Drafts
                                <span class="badge bg-warning ms-1"><?= $drafts->num_rows ?></span>
                            </button>
                        </li>
                    </ul>

                    <!-- Search Box -->
                    <div class="search-container mb-3">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" id="reportSearch" class="form-control" placeholder="Search reports...">
                        </div>
                    </div>

                    <!-- Tab Content -->
                    <div class="tab-content">
                        <!-- PUBLISHED TAB -->
                        <div class="tab-pane fade show active" id="published">
                            <div class="table-responsive">
                                <table class="table" id="reportTable">
                                    <thead>
                                        <tr>
                                            <th>Title</th>
                                            <th>Category</th>
                                            <th>Date</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $published->data_seek(0); // Reset pointer
                                        $counter = 0;
                                        while($r = $published->fetch_assoc()): 
                                            $counter++;
                                            $title = htmlspecialchars($r['title']);
                                            $category = htmlspecialchars($r['category']);
                                            $date = date('M d, Y', strtotime($r['published_at']));
                                            $status = $r['status'];
                                        ?>
                                        <tr>
                                            <td data-label="Title">
                                                <div class="announcement-title">
                                                    <?= ucfirst($title) ?>
                                                </div>
                                            </td>
                                            <td data-label="Category">
                                                <span class="badge bg-light text-dark"><?= ucfirst($category) ?></span>
                                            </td>
                                            <td data-label="Date">
                                                <small class="text-muted"><?= $date ?></small>
                                            </td>
                                            <td data-label="Status">
                                                <span class="badge badge-Published"><?= ucfirst($status) ?></span>
                                            </td>
                                            <td data-label="Actions">
                                                <div class="announcement-actions">
                                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#view<?= $r['id'] ?>">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#edit<?= $r['id'] ?>">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <a href="?delete=<?= $r['id'] ?>" class="btn btn-sm btn-outline-danger"
                                                    onclick="return confirm('Delete this report?')">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>

                                        <!-- VIEW MODAL -->
                                        <div class="modal fade" id="view<?= $r['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title"><?= ucfirst($title) ?></h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="preview-area">
                                                            <div class="preview-meta">
                                                                <span><i class="bi bi-tag"></i> <?= ucfirst($category) ?></span>
                                                                <span><i class="bi bi-calendar"></i> <?= date('M d, Y h:i A', strtotime($r['published_at'])) ?></span>
                                                                <span><i class="bi bi-check-circle"></i> <?= ucfirst($status) ?></span>
                                                            </div>
                                                            <hr>
                                                            <div class="preview-content">
                                                                <?= nl2br(htmlspecialchars($r['description'])) ?>
                                                            </div>
                                                            <hr>
                                                            <h6 class="fw-bold">Organizations Submitted</h6>

                                                            <?php
                                                            $allOrgs = getAllOrgs($conn);
                                                            $submittedList = [];
                                                            $notSubmittedList = [];

                                                            // Fetch submitted_reports for this report
                                                            $subs = $conn->query("
                                                                SELECT s.*, o.org_name
                                                                FROM submitted_reports s
                                                                JOIN organizations o ON s.org_id = o.id
                                                                WHERE s.report_id = {$r['id']}
                                                            ");

                                                            $submittedOrgs = [];
                                                            $notApprovedOrPending = [];

                                                            while($s = $subs->fetch_assoc()) {
                                                                if ($s['status'] === 'Approved') {
                                                                    $submittedOrgs[$s['org_id']] = $s['submitted_at'];
                                                                } else {
                                                                    $notApprovedOrPending[$s['org_id']] = $s['status'];
                                                                }
                                                            }

                                                            // Separate orgs into submitted / not submitted
                                                            while($org = $allOrgs->fetch_assoc()) {
                                                                $orgIdLoop = $org['id'];
                                                                if (isset($submittedOrgs[$orgIdLoop])) {
                                                                    $submittedList[] = [
                                                                        'name' => $org['org_name'],
                                                                        'date' => $submittedOrgs[$orgIdLoop]
                                                                    ];
                                                                } else {
                                                                    $notSubmittedList[] = [
                                                                        'name' => $org['org_name'],
                                                                        'status' => $notApprovedOrPending[$orgIdLoop] ?? 'Not Submitted'
                                                                    ];
                                                                }
                                                            }
                                                            ?>

                                                            <!-- Submitted Orgs -->
                                                            <div class="mb-3">
                                                                <div style="max-height: 150px; overflow-y: auto;">
                                                                    <?php foreach(array_slice($submittedList, 0, 5) as $org): ?>
                                                                        <div class="top-org-item mb-2">
                                                                            <div class="top-org-info">
                                                                                <i class="bi bi-check-circle text-success"></i>
                                                                                <span class="top-org-name"><?= ucfirst(htmlspecialchars($org['name'])) ?></span>
                                                                            </div>
                                                                            <small class="text-muted">
                                                                                <?= date('M d, Y', strtotime($org['date'])) ?>
                                                                            </small>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                    <?php if(count($submittedList) > 5): ?>
                                                                        <div class="text-center text-muted">
                                                                            +<?= count($submittedList) - 5 ?> more...
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>

                                                            <h6 class="fw-bold">Organizations Not Submitted</h6>
                                                            <div style="max-height: 150px; overflow-y: auto;">
                                                                <?php foreach(array_slice($notSubmittedList, 0, 5) as $org): ?>
                                                                    <div class="top-org-item mb-2">
                                                                        <div class="top-org-info">
                                                                            <i class="bi bi-clock text-warning"></i>
                                                                            <span class="top-org-name"><?= ucfirst(htmlspecialchars($org['name'])) ?></span>
                                                                        </div>
                                                                        <span class="badge bg-light text-dark">
                                                                            <?= ucfirst($org['status'] === 'Pending' ? 'Pending' : 'Not Approved') ?>
                                                                        </span>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                                <?php if(count($notSubmittedList) > 5): ?>
                                                                    <div class="text-center text-muted">
                                                                        +<?= count($notSubmittedList) - 5 ?> more...
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>

                                                            <?php if (!empty($r['file_data'])): ?>
                                                                <hr>
                                                                <div class="document-preview">
                                                                    <h6>Attachment</h6>
                                                                    <a class="btn btn-outline-primary"
                                                                    href="data:<?= $r['file_type'] ?>;base64,<?= $r['file_data'] ?>"
                                                                    download="<?= htmlspecialchars($r['file_name']) ?>">
                                                                        <i class="bi bi-download me-1"></i> Download <?= htmlspecialchars($r['file_name']) ?>
                                                                    </a>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- EDIT MODAL -->
                                        <div class="modal fade" id="edit<?= $r['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <form method="POST" enctype="multipart/form-data">
                                                        <input type="hidden" name="update_report">
                                                        <input type="hidden" name="report_id" value="<?= $r['id'] ?>">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Edit Report</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="mb-3">
                                                                <label class="form-label">Title</label>
                                                                <input class="form-control" name="title" value="<?= htmlspecialchars($r['title']) ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Category</label>
                                                                <input class="form-control" name="category" value="<?= htmlspecialchars($r['category']) ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Description</label>
                                                                <textarea class="form-control" name="description" rows="4"><?= htmlspecialchars($r['description']) ?></textarea>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">File Attachment</label>
                                                                <input type="file" name="file" class="form-control"
                                                                accept=".pdf,.doc,.docx,.jpg,.png"
                                                                onchange="previewFile(this, 'editPreview<?= $r['id'] ?>')">
                                                                <div id="editPreview<?= $r['id'] ?>" class="mt-3"></div>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button class="btn btn-primary">Save Changes</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if($published->num_rows == 0): ?>
                            <div class="empty-state">
                                <i class="bi bi-file-earmark"></i>
                                <p>No published reports found.</p>
                            </div>
                            <?php endif; ?>
                            <nav>
                                <ul class="pagination justify-content-end mt-3" id="reportPagination"></ul>
                            </nav>
                        </div>

                        <!-- DRAFTS TAB -->
                        <div class="tab-pane fade" id="drafts">
                            <div class="table-responsive">
                                <table class="table" id="draftTable">
                                    <thead>
                                        <tr>
                                            <th>Title</th>
                                            <th>Category</th>
                                            <th>Created</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $drafts->data_seek(0); // Reset pointer
                                        while($r = $drafts->fetch_assoc()): 
                                            $title = htmlspecialchars($r['title']);
                                            $category = htmlspecialchars($r['category']);
                                            $date = date('M d, Y', strtotime($r['created_at']));
                                            $status = $r['status'];
                                        ?>
                                        <tr>
                                            <td data-label="Title">
                                                <div class="announcement-title">
                                                    <?= ucfirst($title) ?>
                                                </div>
                                            </td>
                                            <td data-label="Category">
                                                <span class="badge bg-light text-dark"><?= ucfirst($category) ?></span>
                                            </td>
                                            <td data-label="Created">
                                                <small class="text-muted"><?= $date ?></small>
                                            </td>
                                            <td data-label="Status">
                                                <span class="badge badge-Draft"><?= ucfirst($status) ?></span>
                                            </td>
                                            <td data-label="Actions">
                                                <div class="announcement-actions">
                                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#viewDraft<?= $r['id'] ?>">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editDraft<?= $r['id'] ?>">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <a href="?delete=<?= $r['id'] ?>" class="btn btn-sm btn-outline-danger"
                                                    onclick="return confirm('Delete this report?')">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>

                                        <!-- VIEW MODAL FOR DRAFTS -->
                                        <div class="modal fade" id="viewDraft<?= $r['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title"><?= ucfirst($title) ?></h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="preview-area">
                                                            <div class="preview-meta">
                                                                <span><i class="bi bi-tag"></i> <?= ucfirst($category) ?></span>
                                                                <span><i class="bi bi-calendar"></i> <?= date('M d, Y h:i A', strtotime($r['created_at'])) ?></span>
                                                                <span><i class="bi bi-pencil"></i> <?= ucfirst($status) ?></span>
                                                            </div>
                                                            <hr>
                                                            <div class="preview-content">
                                                                <?= nl2br(htmlspecialchars($r['description'])) ?>
                                                            </div>
                                                            <?php if (!empty($r['file_data'])): ?>
                                                                <hr>
                                                                <div class="document-preview">
                                                                    <h6>Attachment</h6>
                                                                    <a class="btn btn-outline-primary"
                                                                    href="data:<?= $r['file_type'] ?>;base64,<?= $r['file_data'] ?>"
                                                                    download="<?= htmlspecialchars($r['file_name']) ?>">
                                                                        <i class="bi bi-download me-1"></i> Download <?= htmlspecialchars($r['file_name']) ?>
                                                                    </a>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- EDIT MODAL FOR DRAFTS -->
                                        <div class="modal fade" id="editDraft<?= $r['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <form method="POST" enctype="multipart/form-data">
                                                        <input type="hidden" name="update_report">
                                                        <input type="hidden" name="report_id" value="<?= $r['id'] ?>">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Edit Report</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="mb-3">
                                                                <label class="form-label">Title</label>
                                                                <input class="form-control" name="title" value="<?= htmlspecialchars($r['title']) ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Category</label>
                                                                <input class="form-control" name="category" value="<?= htmlspecialchars($r['category']) ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Description</label>
                                                                <textarea class="form-control" name="description" rows="4"><?= htmlspecialchars($r['description']) ?></textarea>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Status</label>
                                                                <select class="form-select" name="status">
                                                                    <option value="Draft" <?= $r['status'] === 'Draft' ? 'selected' : '' ?>>Draft</option>
                                                                    <option value="Published">Publish Now</option>
                                                                </select>
                                                                <small class="text-muted d-block mt-1">
                                                                    <i class="bi bi-exclamation-triangle"></i>
                                                                    Once published, status can no longer be changed.
                                                                </small>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">File Attachment</label>
                                                                <input type="file" name="file" class="form-control"
                                                                accept=".pdf,.doc,.docx,.jpg,.png"
                                                                onchange="previewFile(this, 'editPreviewDraft<?= $r['id'] ?>')">
                                                                <div id="editPreviewDraft<?= $r['id'] ?>" class="mt-3"></div>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button class="btn btn-primary">Save Changes</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if($drafts->num_rows == 0): ?>
                            <div class="empty-state">
                                <i class="bi bi-file-earmark"></i>
                                <p>No draft reports found.</p>
                            </div>
                            <?php endif; ?>
                            <nav>
                                <ul class="pagination justify-content-end mt-3" id="draftPagination"></ul>
                            </nav>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Admin Section (if admin) -->
<?php if($role === 'Admin'): ?>
<div class="main-card">
    <div class="card-header">
        <h2>Submitted Reports</h2>
    </div>
    <div class="card-body">
        <!-- Search Box moved here -->
        <div class="search-container mb-3">
            <div class="search-box">
                <i class="bi bi-search"></i>
                <input type="text" id="adminSearch" class="form-control" placeholder="Search submitted reports...">
            </div>
        </div>
        
        <div class="table-responsive">
            <table class="table" id="adminTable">
                <thead>
                    <tr>
                        <th>Report Title</th>
                        <th>Organization</th>
                        <th>Submitted At</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $subs = $conn->query("
                        SELECT s.*, r.title, r.category, r.description, r.file_data, r.file_type, r.file_name, o.org_name
                        FROM submitted_reports s
                        JOIN created_reports r ON s.report_id = r.id
                        JOIN organizations o ON s.org_id = o.id
                        ORDER BY s.submitted_at DESC
                    ");

                    while($s = $subs->fetch_assoc()):
                        $title = htmlspecialchars($s['title']);
                        $orgName = htmlspecialchars($s['org_name']);
                        $category = htmlspecialchars($s['category']);
                        $submittedAt = date('M d, Y h:i A', strtotime($s['submitted_at']));
                        $status = $s['status'];
                    ?>
                    <tr>
                        <td data-label="Report Title">
                            <div class="announcement-title">
                                <?= ucfirst($title) ?>
                            </div>
                        </td>
                        <td data-label="Organization">
                            <span class="badge bg-light text-dark"><?= ucfirst($orgName) ?></span>
                        </td>
                        <td data-label="Submitted At">
                            <small class="text-muted"><?= $submittedAt ?></small>
                        </td>
                        <td data-label="Status">
                            <?php if($status === 'Approved'): ?>
                                <span class="badge bg-success"><?= ucfirst($status) ?></span>
                            <?php elseif($status === 'Pending'): ?>
                                <span class="badge bg-warning"><?= ucfirst($status) ?></span>
                            <?php else: ?>
                                <span class="badge bg-danger"><?= ucfirst($status) ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Actions">
                            <div class="announcement-actions">
                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#viewSub<?= $s['id'] ?>">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <?php if($status !== 'Approved'): ?>
                                    <button class="btn btn-sm btn-outline-success" onclick="updateStatus(<?= $s['id'] ?>,'Approved')">
                                        <i class="bi bi-check"></i>
                                    </button>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#notApprovedModal<?= $s['id'] ?>">
                                    <i class="bi bi-x"></i>
                                </button>
                            </div>
                        </td>
                    </tr>

                    <!-- VIEW MODAL -->
                    <div class="modal fade" id="viewSub<?= $s['id'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title"><?= ucfirst($title) ?> – <?= ucfirst($orgName) ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="preview-area">
                                        <div class="preview-meta">
                                            <span><i class="bi bi-tag"></i> <?= ucfirst($category) ?></span>
                                            <span><i class="bi bi-building"></i> <?= ucfirst($orgName) ?></span>
                                            <span><i class="bi bi-calendar"></i> <?= $submittedAt ?></span>
                                        </div>
                                        <hr>
                                        <div class="preview-content">
                                            <?= nl2br(htmlspecialchars($s['description'])) ?>
                                        </div>
                                        <?php if (!empty($s['reason'])): ?>
                                            <hr>
                                            <div class="alert alert-danger">
                                                <h6><i class="bi bi-exclamation-triangle me-1"></i> Not Approved Reason</h6>
                                                <p class="mb-0"><?= nl2br(htmlspecialchars($s['reason'])) ?></p>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($s['file_data'])): ?>
                                            <hr>
                                            <div class="document-preview">
                                                <h6>Attachment</h6>
                                                <a class="btn btn-outline-primary"
                                                href="data:<?= $s['file_type'] ?>;base64,<?= $s['file_data'] ?>"
                                                download="<?= htmlspecialchars($s['file_name']) ?>">
                                                    <i class="bi bi-download me-1"></i> Download <?= htmlspecialchars($s['file_name']) ?>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- NOT APPROVED MODAL -->
                    <div class="modal fade" id="notApprovedModal<?= $s['id'] ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form method="POST">
                                    <input type="hidden" name="report_id" value="<?= $s['id'] ?>">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Not Approved Reason</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="mb-3">
                                            <label class="form-label">Reason for Not Approving</label>
                                            <textarea name="reason" class="form-control" placeholder="Enter reason for not approving this report..." rows="4" required></textarea>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" name="not_approved" class="btn btn-danger">Submit</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php if($subs->num_rows == 0): ?>
        <div class="empty-state">
            <i class="bi bi-file-earmark"></i>
            <p>No submitted reports found.</p>
        </div>
        <?php endif; ?>
        <nav>
            <ul class="pagination justify-content-end mt-3" id="adminPagination"></ul>
        </nav>
    </div>
</div>
<?php endif; ?>
        </div>

        <!-- RIGHT SIDE: DASHBOARD CARDS -->
        <div class="col-lg-4">
            <div class="sidebar-section">
                <!-- Report Statistics Card -->
                <div class="sidebar-card">
                    <h3>
                        <span>Report Statistics</span>
                        <span class="badge bg-light text-dark">AY <?= $ay['year_start'].'-'.$ay['year_end'] ?></span>
                    </h3>
                    
                    <div class="report-stats-grid">
                        <div class="report-stat">
                            <div class="report-stat-icon published">
                                <i class="bi bi-check-circle"></i>
                            </div>
                            <div class="report-stat-value" id="publishedCount"><?= $stats['totalPublished'] ?></div>
                            <div class="report-stat-label">Published</div>
                        </div>
                        
                        <div class="report-stat">
                            <div class="report-stat-icon draft">
                                <i class="bi bi-pencil"></i>
                            </div>
                            <div class="report-stat-value" id="draftCount"><?= $stats['totalDrafts'] ?></div>
                            <div class="report-stat-label">Drafts</div>
                        </div>
                        
                        <div class="report-stat">
                            <div class="report-stat-icon pending">
                                <i class="bi bi-clock"></i>
                            </div>
                            <div class="report-stat-value" id="pendingCount"><?= $stats['totalNotPublished'] ?></div>
                            <div class="report-stat-label">Pending</div>
                        </div>
                    </div>
                    
                    <div class="stats-total">
                        <div class="stats-total-label">Total Reports</div>
                        <div class="stats-total-value" id="totalReports"><?= $stats['totalPublished'] + $stats['totalDrafts'] + $stats['totalNotPublished'] ?></div>
                    </div>
                </div>

                <!-- Submission Activity Card -->
                <div class="sidebar-card">
                    <h3>Submission Activity</h3>
                    
                    <div class="quick-stats-grid">
                        <div class="quick-stat">
                            <div class="quick-stat-icon primary">
                                <i class="bi bi-building"></i>
                            </div>
                            <div class="quick-stat-value" id="orgsSubmitted"><?= $stats['orgsSubmitted'] ?>/<?= $stats['totalOrganizations'] ?></div>
                            <div class="quick-stat-label">Orgs Submitted</div>
                        </div>
                        
                        <div class="quick-stat">
                            <div class="quick-stat-icon warning">
                                <i class="bi bi-exclamation-triangle"></i>
                            </div>
                            <div class="quick-stat-value" id="orgsPending"><?= $stats['orgsPending'] ?></div>
                            <div class="quick-stat-label">Pending</div>
                        </div>
                    </div>
                    
                    <div class="submission-trend">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <small class="text-muted">Submission Trend</small>
                            <small class="text-muted" id="latestSubmission"><?= $stats['latestTime'] ?></small>
                        </div>
                        
                        <div class="trend-chart">
                            <div class="trend-fill" id="trendBar" style="width: <?= min(100, ($stats['submissionsThisMonth']/max(1, $stats['totalOrganizations'])) * 100) ?>%"></div>
                        </div>
                        
                        <div class="trend-numbers">
                            <div class="trend-number">
                                <span class="trend-value" id="submissionsToday"><?= $stats['submissionsToday'] ?></span>
                                <span>Today</span>
                            </div>
                            <div class="trend-number">
                                <span class="trend-value" id="submissionsThisWeek"><?= $stats['submissionsThisWeek'] ?></span>
                                <span>This Week</span>
                            </div>
                            <div class="trend-number">
                                <span class="trend-value" id="submissionsThisMonth"><?= $stats['submissionsThisMonth'] ?></span>
                                <span>This Month</span>
                            </div>
                        </div>
                    </div>
                    
                    <?php if(count($stats['topOrgs']) > 0): ?>
                    <div class="mt-3">
                        <h4>Top Submitting Organizations</h4>
                        <div class="top-orgs-list">
                            <?php 
                            $rank = 1;
                            foreach($stats['topOrgs'] as $org): 
                                $rankClass = $rank == 1 ? 'gold' : ($rank == 2 ? 'silver' : 'bronze');
                            ?>
                            <div class="top-org-item">
                                <div class="top-org-info">
                                    <div class="top-org-rank <?= $rankClass ?>"><?= $rank ?></div>
                                    <span class="top-org-name"><?= ucfirst(htmlspecialchars($org['org_name'])) ?></span>
                                </div>
                                <span class="top-org-count"><?= $org['submission_count'] ?></span>
                            </div>
                            <?php 
                            $rank++;
                            endforeach; 
                            ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- CREATE MODAL -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="create_report">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Report</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Report Title</label>
                        <input class="form-control" name="title" placeholder="Enter report title" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Category</label>
                        <input class="form-control" name="category" placeholder="Enter category" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" placeholder="Enter report description" rows="4"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">File Attachment (Optional)</label>
                        <input type="file" name="file" class="form-control"
                        accept=".pdf,.doc,.docx,.jpg,.png"
                        onchange="previewFile(this, 'createPreview')">
                        <div id="createPreview" class="mt-3"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-warning" name="action" value="draft">Save as Draft</button>
                    <button class="btn btn-primary" name="action" value="publish">Publish Report</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Function to preview uploaded files
function previewFile(input, previewId) {
    const preview = document.getElementById(previewId);
    preview.innerHTML = '';

    if (!input.files || !input.files[0]) return;

    const file = input.files[0];
    const type = file.type;

    // IMAGE PREVIEW
    if (type.startsWith('image/')) {
        const img = document.createElement('img');
        img.className = 'img-fluid rounded border';
        img.style.maxHeight = '200px';
        img.src = URL.createObjectURL(file);
        preview.appendChild(img);
    }

    // PDF PREVIEW
    else if (type === 'application/pdf') {
        const iframe = document.createElement('iframe');
        iframe.src = URL.createObjectURL(file);
        iframe.style.width = '100%';
        iframe.style.height = '300px';
        iframe.className = 'border rounded';
        preview.appendChild(iframe);
    }

    // DOC / DOCX PREVIEW
    else {
        preview.innerHTML = `
            <div class="alert alert-info d-flex align-items-center gap-2">
                <i class="bi bi-file-earmark"></i>
                <div>
                    <strong>${file.name}</strong>
                    <div class="text-muted">(Preview not available for this file type)</div>
                </div>
            </div>
        `;
    }
}

// Function to update report status (Approve/Not Approve)
function updateStatus(id, status) { 
    const form = document.createElement('form');
    form.method = 'POST'; 
    const inputId = document.createElement('input'); 
    inputId.type = 'hidden'; 
    inputId.name = 'report_id'; 
    inputId.value = id; 
    form.appendChild(inputId); 
    const inputStatus = document.createElement('input'); 
    inputStatus.type = 'hidden'; 
    inputStatus.name = status === 'Approved' ? 'approve' : 'not_approved'; 
    inputStatus.value = '1'; 
    form.appendChild(inputStatus); 
    document.body.appendChild(form); 
    form.submit(); 
}

// Table pagination and search functionality
function setupTable(tableId, searchId, paginationId) {
    const ROWS_PER_PAGE = 5;
    const table = document.getElementById(tableId);
    if (!table) return;

    const tbody = table.querySelector("tbody");
    const rows = Array.from(tbody.querySelectorAll("tr"));
    const searchInput = document.getElementById(searchId);
    const pagination = document.getElementById(paginationId);

    let currentPage = 1;
    let filteredRows = [...rows];

    function renderTable() {
        tbody.innerHTML = "";
        const start = (currentPage - 1) * ROWS_PER_PAGE;
        const end = start + ROWS_PER_PAGE;
        filteredRows.slice(start, end).forEach(row => tbody.appendChild(row));
        renderPagination();
    }

    function renderPagination() {
        pagination.innerHTML = "";
        const pageCount = Math.ceil(filteredRows.length / ROWS_PER_PAGE);
        if (pageCount <= 1) return;

        for (let i = 1; i <= pageCount; i++) {
            const li = document.createElement("li");
            li.className = "page-item " + (i === currentPage ? "active" : "");
            li.innerHTML = `<a class="page-link" href="#">${i}</a>`;
            li.onclick = e => {
                e.preventDefault();
                currentPage = i;
                renderTable();
            };
            pagination.appendChild(li);
        }
    }

    searchInput.addEventListener("input", function () {
        const value = this.value.toLowerCase().trim();
        filteredRows = value === ""
            ? [...rows]
            : rows.filter(row => row.textContent.toLowerCase().includes(value));
        currentPage = 1;
        renderTable();
    });

    renderTable();
}

// Toast notification function
function showToast(message, type = 'info') {
    const toastContainer = document.getElementById('toastContainer') || createToastContainer();
    const toastId = 'toast-' + Date.now();
    
    const toastHTML = `
        <div id="${toastId}" class="toast align-items-center text-bg-${type} border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body">
                    ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    `;
    
    toastContainer.innerHTML += toastHTML;
    const toastElement = document.getElementById(toastId);
    const toast = new bootstrap.Toast(toastElement, {delay: 3000});
    toast.show();
    
    toastElement.addEventListener('hidden.bs.toast', function () {
        this.remove();
    });
}

function createToastContainer() {
    const container = document.createElement('div');
    container.id = 'toastContainer';
    container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
    container.style.zIndex = '9999';
    document.body.appendChild(container);
    return container;
}

// Initialize page
document.addEventListener("DOMContentLoaded", function () {
    setupTable("reportTable", "reportSearch", "reportPagination");
    setupTable("draftTable", "reportSearch", "draftPagination");
    <?php if($role === 'Admin'): ?>
    setupTable("adminTable", "adminSearch", "adminPagination");
    <?php endif; ?>
    
    // Auto-refresh dashboard when modal is closed
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        modal.addEventListener('hidden.bs.modal', function () {
            // Wait a moment for database updates to complete
            setTimeout(refreshDashboard, 1000);
        });
    });
    
    // Make tables responsive on mobile
    function makeTablesResponsive() {
        const tables = document.querySelectorAll('table');
        tables.forEach(table => {
            const headers = [];
            const ths = table.querySelectorAll('thead th');
            ths.forEach(th => {
                headers.push(th.textContent.trim());
            });
            
            const tds = table.querySelectorAll('tbody td');
            tds.forEach((td, index) => {
                const headerIndex = index % headers.length;
                td.setAttribute('data-label', headers[headerIndex]);
            });
        });
    }
    
    // Call on load and resize
    makeTablesResponsive();
    window.addEventListener('resize', makeTablesResponsive);
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>