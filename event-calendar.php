<?php
session_start();
require 'config/db.php';

function eventBadge($type) {
    return match ($type) {
        'USC' => '<span class="badge event-badge bg-primary ms-1">USC</span>',
        'Organization' => '<span class="badge event-badge bg-success ms-1">ORG</span>',
        'University' => '<span class="badge event-badge bg-warning ms-1">UNIV</span>',
        default => ''
    };
}

$userRole = $_SESSION['role'] ?? 'User';
$userOrg  = $_SESSION['org_id'] ?? null;
$userId   = $_SESSION['user_id'] ?? null;

/* =======================
   GET ACTIVE ACADEMIC YEAR
======================= */
$activeYear = $conn->query("
    SELECT * FROM academic_years 
    WHERE is_active = 1 
    LIMIT 1
")->fetch_assoc();

if (!$activeYear) {
    die('No active academic year found.');
}

$activeYearId = (int)$activeYear['id'];

/* =======================
   RECENT (PAST) EVENTS
======================= */
$recentEvents = $conn->query("
    SELECT *
    FROM events
    WHERE academic_year_id = $activeYearId
      AND status = 'Approved'
      AND end_date < CURDATE()
      AND (
          ('$userRole' = 'Admin')
          OR ('$userRole' = 'Sub-Admin' AND event_type IN ('Organization','University'))
          OR ('$userRole' = 'User' AND event_type = 'University')
      )
    ORDER BY end_date DESC
    LIMIT 5
");

/* =======================
   HANDLE DELETE EVENT
======================= */
if (isset($_POST['delete_event'])) {
    $event_id = (int)$_POST['event_id'];
    $stmt = $conn->prepare("DELETE FROM events WHERE id = ?");
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    header("Location: event-calendar.php");
    exit;
}

/* =======================
   HANDLE EDIT EVENT
======================= */
if (isset($_POST['edit_event'])) {
    $event_id    = (int)$_POST['event_id'];
    $title       = $_POST['title'];
    $description = $_POST['description'];
    $start_date  = $_POST['start_date'];
    $end_date    = $_POST['end_date'];
    $is_all_day  = isset($_POST['is_all_day']) ? 1 : 0;
    $start_time  = $is_all_day ? '00:00:00' : $_POST['start_time'];
    $end_time    = $is_all_day ? '23:59:59' : $_POST['end_time'];
    $event_type  = $_POST['event_type'];
    $location    = $_POST['location'] ?? null;
    $org_id      = $_POST['org_id'] ?: null;

    // ===== HISTORY =====
    $old = $conn->query("SELECT history FROM events WHERE id = $event_id")->fetch_assoc();
    $history = json_decode($old['history'] ?? '[]', true);
    $history[] = [
        'action' => 'Updated',
        'by' => $userId,
        'role' => $userRole,
        'date' => date('Y-m-d H:i:s')
    ];

    $stmt = $conn->prepare("
        UPDATE events SET
            title = ?,
            description = ?,
            start_date = ?,
            end_date = ?,
            start_time = ?,
            end_time = ?,
            is_all_day = ?,
            event_type = ?,
            location = ?,
            org_id = ?,
            history = ?
        WHERE id = ?
    ");

    $historyJson = json_encode($history);
    $stmt->bind_param(
        "ssssssissisi",
        $title,
        $description,
        $start_date,
        $end_date,
        $start_time,
        $end_time,
        $is_all_day,
        $event_type,
        $location,
        $org_id,
        $historyJson,
        $event_id
    );

    $stmt->execute();
    header("Location: event-calendar.php");
    exit;
}

/* =======================
   HANDLE ADD EVENT
======================= */
if (isset($_POST['add_event'])) {
    $title       = $_POST['title'];
    $description = $_POST['description'];
    $start_date  = $_POST['start_date'];
    $end_date    = $_POST['end_date'];
    $is_all_day  = isset($_POST['is_all_day']) ? 1 : 0;
    $start_time  = $is_all_day ? '00:00:00' : ($_POST['start_time'] ?? '00:00:00');
    $end_time    = $is_all_day ? '23:59:59' : ($_POST['end_time'] ?? '23:59:59');
    $event_type  = $_POST['event_type'];
    $location    = $_POST['location'] ?? null;
    $org_id      = $_POST['org_id'] ?: null;

    $history = json_encode([
        [
            'action' => 'Created',
            'by' => $userId,
            'role' => $userRole,
            'date' => date('Y-m-d H:i:s')
        ]
    ]);

    $stmt = $conn->prepare("
        INSERT INTO events (
            title, description,
            start_date, end_date,
            start_time, end_time,
            is_all_day,
            event_type, location,
            org_id, created_by, academic_year_id,
            status, history
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Approved', ?)
    ");

    $stmt->bind_param(
        "ssssssissiiis",
        $title,
        $description,
        $start_date,
        $end_date,
        $start_time,
        $end_time,
        $is_all_day,
        $event_type,
        $location,
        $org_id,
        $userId,
        $activeYearId,
        $history
    );

    $stmt->execute();
    header("Location: event-calendar.php");
    exit;
}

/* =======================
   FETCH EVENTS FOR CALENDAR
======================= */
$where = "WHERE e.academic_year_id = $activeYearId AND e.status = 'Approved'";

if ($userRole === 'Admin') {
    $where .= " AND e.event_type IN ('USC','Organization','University')";
} elseif ($userRole === 'Sub-Admin') {
    $where .= " AND e.event_type IN ('Organization','University')";
} else {
    $where .= " AND e.event_type = 'University'";
}

$calendarEvents = [];
$events = $conn->query("
    SELECT e.*, o.org_name
    FROM events e
    LEFT JOIN organizations o ON e.org_id = o.id
    $where
");

while ($row = $events->fetch_assoc()) {
    // Different colors for each event type
    switch($row['event_type']) {
        case 'USC':
            $color = '#3479DB'; // Blue
            $textColor = '#ffffff';
            break;
        case 'Organization':
            $color = '#10b981'; // Green
            $textColor = '#ffffff';
            break;
        case 'University':
            $color = '#f59e0b'; // Orange
            $textColor = '#000000';
            break;
        default:
            $color = '#6c757d'; // Gray
            $textColor = '#ffffff';
    }
    
    $calendarEvents[] = [
        'id' => $row['id'],
        'title' => $row['title'] . ' (' . substr($row['event_type'], 0, 3) . ')',
        'start' => $row['start_date'].'T'.$row['start_time'],
        'end' => date('Y-m-d',strtotime(($row['end_date'] ?? $row['start_date']) . ' +1 day')) . 'T' . ($row['end_time'] ?? '23:59:59'),
        'allDay' => (bool)$row['is_all_day'],
        'backgroundColor' => $color,
        'borderColor' => $color,
        'textColor' => $textColor,
        'className' => 'fc-event-' . strtolower($row['event_type']),
        'extendedProps' => [
            'org_id' => $row['org_id'],
            'type' => $row['event_type'],
            'location' => $row['location'],
            'description' => $row['description'],
            'history' => $row['history']
        ]
    ];
}

/* =======================
   UPCOMING EVENTS
======================= */
$upcomingEvents = $conn->query("
    SELECT *
    FROM events
    WHERE academic_year_id = $activeYearId
      AND status = 'Approved'
      AND start_date >= CURDATE()
      AND (
          ('$userRole' = 'Admin')
          OR ('$userRole' = 'Sub-Admin' AND event_type IN ('Organization','University'))
          OR ('$userRole' = 'User' AND event_type = 'University')
      )
    ORDER BY start_date ASC
    LIMIT 5
");

// Get event counts for stats
$totalEvents = $conn->query("SELECT COUNT(*) as count FROM events WHERE academic_year_id = $activeYearId")->fetch_assoc()['count'];
$upcomingCount = $conn->query("SELECT COUNT(*) as count FROM events WHERE academic_year_id = $activeYearId AND start_date >= CURDATE()")->fetch_assoc()['count'];
$pastCount = $conn->query("SELECT COUNT(*) as count FROM events WHERE academic_year_id = $activeYearId AND end_date < CURDATE()")->fetch_assoc()['count'];
$todayCount = $conn->query("SELECT COUNT(*) as count FROM events WHERE academic_year_id = $activeYearId AND start_date <= CURDATE() AND end_date >= CURDATE()")->fetch_assoc()['count'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Event Calendar</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
:root {
    --usc-blue: #3479DB;
    --usc-blue-light: #E8F2FF;
    --org-green: #10b981;
    --org-green-light: #ECFDF5;
    --univ-orange: #f59e0b;
    --univ-orange-light: #FEF3C7;
    --danger: #ef4444;
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
    color: var(--usc-blue);
    font-weight: 500;
}

/* Main Content Card */
.main-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    border: 1px solid #eaeaea;
    margin-bottom: 20px;
    overflow: hidden;
    transition: all 0.3s ease;
}

.main-card:hover {
    box-shadow: 0 4px 20px rgba(52, 121, 219, 0.1);
}

.card-header {
    padding: 18px 20px;
    border-bottom: 1px solid #eaeaea;
    background: white;
    display: flex;
    justify-content: space-between;
    align-items: center;
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

/* Sidebar Cards */
.sidebar-section {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.sidebar-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
    border: 1px solid #eaeaea;
    transition: all 0.3s ease;
}

.sidebar-card:hover {
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
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
    border-bottom: 2px solid var(--usc-blue-light);
}

.sidebar-card h4 {
    font-size: 14px;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 15px;
}

/* Enhanced FullCalendar Styles */
.fc {
    --fc-border-color: #eaeaea;
    --fc-button-bg-color: var(--usc-blue);
    --fc-button-border-color: var(--usc-blue);
    --fc-button-hover-bg-color: #2a5fb0;
    --fc-button-hover-border-color: #2a5fb0;
    --fc-button-active-bg-color: #2a5fb0;
    --fc-button-active-border-color: #2a5fb0;
    --fc-today-bg-color: var(--usc-blue-light);
    --fc-event-border-color: transparent;
    font-family: 'Poppins', sans-serif;
}

.fc .fc-toolbar {
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 20px;
}

.fc .fc-toolbar-title {
    font-size: 1.4rem;
    font-weight: 600;
    color: #2c3e50;
}

.fc .fc-button {
    padding: 8px 16px;
    font-weight: 500;
    font-size: 13px;
    border-radius: 8px;
    transition: all 0.3s ease;
    text-transform: none;
}

.fc .fc-button:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(52, 121, 219, 0.2);
}

.fc .fc-button-primary:not(:disabled).fc-button-active,
.fc .fc-button-primary:not(:disabled):active {
    background-color: #2a5fb0;
    border-color: #2a5fb0;
    box-shadow: 0 2px 8px rgba(52, 121, 219, 0.3);
}

.fc .fc-daygrid-day-number {
    font-weight: 500;
    color: #495057;
}

.fc .fc-day-today {
    background: var(--usc-blue-light) !important;
}

.fc .fc-day-today .fc-daygrid-day-number {
    color: var(--usc-blue);
    font-weight: 600;
}

.fc .fc-event {
    border-radius: 6px;
    padding: 4px 8px;
    margin: 2px;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 12px;
    font-weight: 500;
    border: none;
    border-left: 4px solid !important;
}

.fc .fc-event:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    opacity: 0.9;
}

.fc .fc-daygrid-event {
    border-radius: 6px;
}

.fc .fc-daygrid-event-dot {
    display: none;
}

/* Enhanced Event Colors */
.fc-event-usc {
    background: linear-gradient(135deg, var(--usc-blue), #2a5fb0) !important;
    border-left-color: var(--usc-blue) !important;
    color: white !important;
}

.fc-event-organization {
    background: linear-gradient(135deg, var(--org-green), #059669) !important;
    border-left-color: var(--org-green) !important;
    color: white !important;
}

.fc-event-university {
    background: linear-gradient(135deg, var(--univ-orange), #d97706) !important;
    border-left-color: var(--univ-orange) !important;
    color: white !important;
}

/* Calendar Header Customization */
.fc-header-toolbar {
    background: white;
    padding: 15px;
    border-radius: 12px;
    margin-bottom: 15px !important;
    border: 1px solid #eaeaea;
}

/* Event List Items */
.event-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.event-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 12px;
    background: #f8f9fa;
    border-radius: 8px;
    border: 1px solid #eaeaea;
    transition: all 0.3s ease;
    text-decoration: none;
    color: inherit;
}

.event-item:hover {
    background: white;
    border-color: var(--usc-blue-light);
    transform: translateX(5px);
    text-decoration: none;
    color: inherit;
}

.event-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 16px;
    color: white;
}

.event-icon.usc { 
    background: var(--usc-blue); 
    box-shadow: 0 2px 6px rgba(52, 121, 219, 0.3);
}
.event-icon.organization { 
    background: var(--org-green); 
    box-shadow: 0 2px 6px rgba(16, 185, 129, 0.3);
}
.event-icon.university { 
    background: var(--univ-orange); 
    box-shadow: 0 2px 6px rgba(245, 158, 11, 0.3);
}

.event-content {
    flex: 1;
    min-width: 0;
}

.event-title {
    font-size: 13px;
    font-weight: 500;
    color: #2c3e50;
    margin-bottom: 4px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.event-meta {
    display: flex;
    gap: 10px;
    font-size: 11px;
    color: #6c757d;
}

.event-meta span {
    display: flex;
    align-items: center;
    gap: 4px;
}

.event-badge {
    font-size: 10px;
    padding: 2px 8px;
    border-radius: 10px;
    font-weight: 500;
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
    border-color: var(--usc-blue-light);
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
    color: white;
}

.quick-stat-icon.total { 
    background: var(--usc-blue); 
    box-shadow: 0 2px 6px rgba(52, 121, 219, 0.3);
}
.quick-stat-icon.upcoming { 
    background: var(--org-green); 
    box-shadow: 0 2px 6px rgba(16, 185, 129, 0.3);
}
.quick-stat-icon.past { 
    background: var(--univ-orange); 
    box-shadow: 0 2px 6px rgba(245, 158, 11, 0.3);
}
.quick-stat-icon.today { 
    background: #8b5cf6; 
    box-shadow: 0 2px 6px rgba(139, 92, 246, 0.3);
}

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

/* Event History */
.history-list {
    max-height: 300px;
    overflow-y: auto;
}

.history-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 12px;
    background: #f8f9fa;
    border-radius: 8px;
    border: 1px solid #eaeaea;
    margin-bottom: 8px;
    transition: all 0.3s ease;
}

.history-item:hover {
    background: white;
    border-color: var(--usc-blue-light);
}

.history-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 14px;
    background: var(--usc-blue-light);
    color: var(--usc-blue);
}

.history-content {
    flex: 1;
    min-width: 0;
}

.history-action {
    font-size: 13px;
    font-weight: 500;
    color: #2c3e50;
    margin-bottom: 2px;
}

.history-meta {
    display: flex;
    gap: 10px;
    font-size: 11px;
    color: #6c757d;
}

/* Enhanced Legend */
.event-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 15px;
    padding: 12px;
    background: #f8f9fa;
    border-radius: 8px;
    border: 1px solid #eaeaea;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: #495057;
    padding: 6px 12px;
    background: white;
    border-radius: 6px;
    border: 1px solid #eaeaea;
    transition: all 0.3s ease;
}

.legend-item:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.legend-color {
    width: 16px;
    height: 16px;
    border-radius: 4px;
    border: 2px solid white;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.legend-color.usc { 
    background: var(--usc-blue); 
    border-color: #c7daf8;
}
.legend-color.organization { 
    background: var(--org-green); 
    border-color: #d1fae5;
}
.legend-color.university { 
    background: var(--univ-orange); 
    border-color: #fde68a;
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
    display: flex;
    align-items: center;
    gap: 10px;
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
    border-color: var(--usc-blue);
    box-shadow: 0 0 0 3px rgba(52, 121, 219, 0.1);
    outline: none;
}

.form-label {
    font-weight: 500;
    color: #495057;
    margin-bottom: 8px;
    font-size: 13px;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 30px 20px;
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

/* Responsive */
@media (max-width: 768px) {
    .content {
        padding: 15px;
    }
    
    .fc .fc-toolbar {
        flex-direction: column;
        align-items: stretch;
    }
    
    .fc .fc-toolbar-title {
        text-align: center;
        margin-bottom: 10px;
    }
    
    .quick-stats-grid {
        grid-template-columns: 1fr;
    }
    
    .event-legend {
        flex-direction: column;
        gap: 8px;
    }
}

/* Print Styles */
@media print {
    .sidebar-section {
        display: none;
    }
    
    .fc .fc-toolbar {
        display: none;
    }
    
    .fc table {
        border: 1px solid #ddd !important;
    }
}

/* Colorful Event Dots for Month View */
.fc-daygrid-event-dot {
    border-width: 5px !important;
}

.fc-event-usc .fc-daygrid-event-dot {
    border-color: var(--usc-blue) !important;
}

.fc-event-organization .fc-daygrid-event-dot {
    border-color: var(--org-green) !important;
}

.fc-event-university .fc-daygrid-event-dot {
    border-color: var(--univ-orange) !important;
}
</style>
</head>

<body>

<?php include 'partials/sidenav.php'; ?>

<div class="content">
    <!-- Page Header -->
    <div class="page-header">
        <h1>Event Calendar</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item">Dashboard</li>
                <li class="breadcrumb-item active">Calendar</li>
            </ol>
        </nav>
    </div>

    <div class="row">
        <!-- Main Calendar - Left Column -->
        <div class="col-lg-9">
            <div class="main-card">
                <div class="card-header">
                    <h2>Event Calendar</h2>
                    <?php if ($userRole === 'Admin' || $userRole === 'Sub-Admin'): ?>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addEventModal">
                        <i class="bi bi-plus-lg me-1"></i> Add Event
                    </button>
                    <?php endif; ?>
                </div>
                
                <div class="card-body">
                    <!-- Enhanced Event Legend -->
                    <div class="event-legend">
                        <div class="legend-item">
                            <span class="legend-color usc"></span>
                            <span><strong>USC Events</strong> - Student Council Activities</span>
                        </div>
                        <div class="legend-item">
                            <span class="legend-color organization"></span>
                            <span><strong>Organization Events</strong> - Club & Group Activities</span>
                        </div>
                        <div class="legend-item">
                            <span class="legend-color university"></span>
                            <span><strong>University Events</strong> - Official University Activities</span>
                        </div>
                    </div>
                    
                    <!-- FullCalendar -->
                    <div id="fullCalendar" class="mt-3"></div>
                </div>
            </div>
        </div>

        <!-- Sidebar - Right Column -->
        <div class="col-lg-3">
            <div class="sidebar-section">
                
                <!-- Quick Stats Card -->
                <div class="sidebar-card">
                    <h4>Event Statistics</h4>
                    <div class="quick-stats-grid">
                        <div class="quick-stat">
                            <div class="quick-stat-icon total">
                                <i class="bi bi-calendar-event"></i>
                            </div>
                            <div class="quick-stat-value"><?= $totalEvents ?></div>
                            <div class="quick-stat-label">Total Events</div>
                        </div>
                        <div class="quick-stat">
                            <div class="quick-stat-icon upcoming">
                                <i class="bi bi-calendar-plus"></i>
                            </div>
                            <div class="quick-stat-value"><?= $upcomingCount ?></div>
                            <div class="quick-stat-label">Upcoming</div>
                        </div>
                        <div class="quick-stat">
                            <div class="quick-stat-icon past">
                                <i class="bi bi-calendar-check"></i>
                            </div>
                            <div class="quick-stat-value"><?= $pastCount ?></div>
                            <div class="quick-stat-label">Past Events</div>
                        </div>
                        <div class="quick-stat">
                            <div class="quick-stat-icon today">
                                <i class="bi bi-calendar-day"></i>
                            </div>
                            <div class="quick-stat-value"><?= $todayCount ?></div>
                            <div class="quick-stat-label">Today</div>
                        </div>
                    </div>
                </div>

                <!-- Upcoming Events Card -->
                <div class="sidebar-card">
                    <h4>Upcoming Events</h4>
                    <?php if ($upcomingEvents->num_rows === 0): ?>
                        <div class="empty-state">
                            <i class="bi bi-calendar-x"></i>
                            <p>No upcoming events</p>
                        </div>
                    <?php else: ?>
                        <div class="event-list">
                            <?php while ($u = $upcomingEvents->fetch_assoc()): ?>
                            <a href="#" class="event-item view-event-details"
                               data-id="<?= $u['id'] ?>"
                               data-title="<?= htmlspecialchars($u['title']) ?>"
                               data-date="<?= date('M d, Y', strtotime($u['start_date'])) .
                                   ($u['start_date'] !== $u['end_date'] ? ' – ' . date('M d, Y', strtotime($u['end_date'])) : '') ?>"
                               data-time="<?= $u['is_all_day'] ? 'All Day' : 
                                   date('g:i A', strtotime($u['start_time'])) . ' – ' . date('g:i A', strtotime($u['end_time'])) ?>"
                               data-desc="<?= htmlspecialchars($u['description']) ?>"
                               data-location="<?= htmlspecialchars($u['location'] ?? 'Not specified') ?>"
                               data-type="<?= $u['event_type'] ?>"
                               data-history="<?= htmlspecialchars($u['history'] ?? '[]') ?>">
                                <div class="event-icon <?= strtolower($u['event_type']) ?>">
                                    <i class="bi bi-<?= 
                                        $u['event_type'] === 'USC' ? 'calendar-week' : 
                                        ($u['event_type'] === 'Organization' ? 'people' : 'building')
                                    ?>"></i>
                                </div>
                                <div class="event-content">
                                    <div class="event-title"><?= htmlspecialchars($u['title']) ?></div>
                                    <div class="event-meta">
                                        <span><i class="bi bi-calendar"></i> <?= date('M d', strtotime($u['start_date'])) ?></span>
                                        <?php if (!$u['is_all_day']): ?>
                                        <span><i class="bi bi-clock"></i> <?= date('g:i A', strtotime($u['start_time'])) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </a>
                            <?php endwhile; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Events Card -->
                <div class="sidebar-card">
                    <h4>Recent Events</h4>
                    <?php if ($recentEvents->num_rows === 0): ?>
                        <div class="empty-state">
                            <i class="bi bi-clock-history"></i>
                            <p>No past events</p>
                        </div>
                    <?php else: ?>
                        <div class="event-list">
                            <?php while ($r = $recentEvents->fetch_assoc()): ?>
                            <a href="#" class="event-item view-event-details"
                               data-id="<?= $r['id'] ?>"
                               data-title="<?= htmlspecialchars($r['title']) ?>"
                               data-date="<?= date('M d, Y', strtotime($r['start_date'])) .
                                   ($r['start_date'] !== $r['end_date'] ? ' – ' . date('M d, Y', strtotime($r['end_date'])) : '') ?>"
                               data-time="<?= $r['is_all_day'] ? 'All Day' : 
                                   date('g:i A', strtotime($r['start_time'])) . ' – ' . date('g:i A', strtotime($r['end_time'])) ?>"
                               data-desc="<?= htmlspecialchars($r['description']) ?>"
                               data-location="<?= htmlspecialchars($r['location'] ?? 'Not specified') ?>"
                               data-type="<?= $r['event_type'] ?>"
                               data-history="<?= htmlspecialchars($r['history'] ?? '[]') ?>">
                                <div class="event-icon <?= strtolower($r['event_type']) ?>">
                                    <i class="bi bi-<?= 
                                        $r['event_type'] === 'USC' ? 'calendar-week' : 
                                        ($r['event_type'] === 'Organization' ? 'people' : 'building')
                                    ?>"></i>
                                </div>
                                <div class="event-content">
                                    <div class="event-title"><?= htmlspecialchars($r['title']) ?></div>
                                    <div class="event-meta">
                                        <span><i class="bi bi-calendar"></i> <?= date('M d', strtotime($r['start_date'])) ?></span>
                                        <span><i class="bi bi-check-circle"></i> Completed</span>
                                    </div>
                                </div>
                            </a>
                            <?php endwhile; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Event History Card -->
                <div class="sidebar-card">
                    <h4>Event History</h4>
                    <div class="history-list" id="eventHistoryList">
                        <div class="empty-state">
                            <i class="bi bi-info-circle"></i>
                            <p>Select an event to view history</p>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- Modals -->

<!-- Event Details Modal -->
<div class="modal fade" id="eventDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-calendar-event me-2"></i>
                    <span id="eventTitle"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label text-muted small mb-1">Date & Time</label>
                            <div class="fw-medium">
                                <i class="bi bi-calendar me-2"></i>
                                <span id="eventDate"></span><br>
                                <i class="bi bi-clock me-2"></i>
                                <span id="eventTime"></span>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label text-muted small mb-1">Location</label>
                            <div class="fw-medium">
                                <i class="bi bi-geo-alt me-2"></i>
                                <span id="eventLocation"></span>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label text-muted small mb-1">Event Type</label>
                            <div id="eventTypeBadge" class="mt-1"></div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label text-muted small mb-1">Description</label>
                            <div id="eventDesc" class="border rounded p-3 bg-light" style="min-height: 120px;"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer">
                <?php if ($userRole !== 'User'): ?>
                <button class="btn btn-outline-primary" id="editEventBtn">
                    <i class="bi bi-pencil me-1"></i> Edit Event
                </button>
                
                <form method="POST" class="d-inline">
                    <input type="hidden" name="event_id" id="delete_event_id">
                    <button type="submit" name="delete_event" 
                            class="btn btn-outline-danger"
                            onclick="return confirm('Are you sure you want to delete this event?')">
                        <i class="bi bi-trash me-1"></i> Delete
                    </button>
                </form>
                <?php endif; ?>
                
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg me-1"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Add Event Modal -->
<div class="modal fade" id="addEventModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-plus-circle me-2"></i>
                        Add New Event
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Event Title *</label>
                                <input type="text" name="title" class="form-control" placeholder="Enter event title" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Event Type *</label>
                                <select name="event_type" class="form-select" required id="eventTypeSelect">
                                    <?php if ($userRole === 'Admin'): ?>
                                        <option value="USC">USC Event</option>
                                    <?php endif; ?>
                                    <?php if ($userRole === 'Admin' || $userRole === 'Sub-Admin'): ?>
                                        <option value="Organization">Organization Event</option>
                                    <?php endif; ?>
                                    <option value="University">University Event</option>
                                </select>
                            </div>
                            
                            <div class="mb-3" id="orgSelect" style="display:none;">
                                <label class="form-label">Organization</label>
                                <select name="org_id" class="form-select">
                                    <option value="">Select Organization</option>
                                    <?php
                                    $orgs = $conn->query("SELECT id, org_name FROM organizations ORDER BY org_name");
                                    while ($o = $orgs->fetch_assoc()):
                                    ?>
                                    <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['org_name']) ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Location / Venue</label>
                                <input type="text" name="location" class="form-control" placeholder="e.g., University Auditorium">
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="allDayToggle" name="is_all_day">
                                <label class="form-check-label fw-semibold">All Day Event</label>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Start Date *</label>
                                    <input type="date" name="start_date" class="form-control" required>
                                </div>
                                <div class="col-md-6 mb-3 time-field">
                                    <label class="form-label">Start Time</label>
                                    <input type="time" name="start_time" class="form-control">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">End Date *</label>
                                    <input type="date" name="end_date" class="form-control" required>
                                </div>
                                <div class="col-md-6 mb-3 time-field">
                                    <label class="form-label">End Time</label>
                                    <input type="time" name="end_time" class="form-control">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="4" placeholder="Enter event description"></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i> Cancel
                    </button>
                    <button type="submit" name="add_event" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i> Create Event
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Event Modal -->
<div class="modal fade" id="editEventModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="event_id" id="edit_event_id">
                
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil-square me-2"></i>
                        Edit Event
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Event Title *</label>
                                <input type="text" name="title" id="edit_title" class="form-control" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Event Type *</label>
                                <select name="event_type" id="edit_event_type" class="form-select" required>
                                    <option value="USC">USC Event</option>
                                    <option value="Organization">Organization Event</option>
                                    <option value="University">University Event</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Location / Venue</label>
                                <input type="text" name="location" id="edit_location" class="form-control">
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="edit_all_day" name="is_all_day">
                                <label class="form-check-label fw-semibold">All Day Event</label>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Start Date *</label>
                                    <input type="date" name="start_date" id="edit_start_date" class="form-control" required>
                                </div>
                                <div class="col-md-6 mb-3 edit-time-field">
                                    <label class="form-label">Start Time</label>
                                    <input type="time" name="start_time" id="edit_start_time" class="form-control">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">End Date *</label>
                                    <input type="date" name="end_date" id="edit_end_date" class="form-control" required>
                                </div>
                                <div class="col-md-6 mb-3 edit-time-field">
                                    <label class="form-label">End Time</label>
                                    <input type="time" name="end_time" id="edit_end_time" class="form-control">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="edit_description" class="form-control" rows="4"></textarea>
                    </div>
                    
                    <input type="hidden" name="org_id" id="edit_org_id">
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i> Cancel
                    </button>
                    <button type="submit" name="edit_event" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Initialize FullCalendar
    const calendar = new FullCalendar.Calendar(document.getElementById('fullCalendar'), {
        initialView: 'dayGridMonth',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
        },
        events: <?= json_encode($calendarEvents) ?>,
        eventTimeFormat: {
            hour: '2-digit',
            minute: '2-digit',
            hour12: true
        },
        eventDidMount: function(info) {
            // Add custom class based on event type
            const type = info.event.extendedProps.type.toLowerCase();
            info.el.classList.add('fc-event-' + type);
            
            // Add tooltip with event details
            info.el.title = `${info.event.title}\n${info.event.extendedProps.type} Event\n${info.event.extendedProps.description || 'No description'}`;
        },
        eventClick: function(info) {
            showEventDetails(info.event);
        },
        dayMaxEvents: 3,
        navLinks: true,
        nowIndicator: true,
        weekNumbers: true,
        weekNumberFormat: { week: 'numeric' },
        height: 'auto',
        contentHeight: 'auto',
        expandRows: true,
        slotMinTime: '08:00:00',
        slotMaxTime: '20:00:00'
    });

    calendar.render();

    // Event details modal
    function showEventDetails(event) {
        const start = event.start;
        const end = event.end ? new Date(event.end.getTime() - 86400000) : start;
        
        // Set basic info
        document.getElementById('eventTitle').textContent = event.title.replace(/ \(...\)$/, '');
        document.getElementById('eventDate').textContent = 
            start.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }) +
            (start.toDateString() !== end.toDateString() 
                ? ' – ' + end.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
                : '');
        
        document.getElementById('eventTime').textContent = event.allDay 
            ? 'All Day' 
            : start.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) +
              ' – ' +
              (event.end ? event.end.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) : '');
        
        document.getElementById('eventLocation').textContent = event.extendedProps.location || 'Not specified';
        document.getElementById('eventDesc').textContent = event.extendedProps.description || 'No description provided.';
        document.getElementById('delete_event_id').value = event.id;
        
        // Set event type badge
        const typeBadge = document.getElementById('eventTypeBadge');
        const type = event.extendedProps.type;
        let badgeClass = '', badgeText = '', icon = '';
        
        switch(type) {
            case 'USC': 
                badgeClass = 'primary'; 
                badgeText = 'USC Event';
                icon = 'calendar-week';
                break;
            case 'Organization': 
                badgeClass = 'success'; 
                badgeText = 'Organization Event';
                icon = 'people';
                break;
            case 'University': 
                badgeClass = 'warning'; 
                badgeText = 'University Event';
                icon = 'building';
                break;
        }
        
        typeBadge.innerHTML = `
            <span class="badge bg-${badgeClass} p-2">
                <i class="bi bi-${icon} me-1"></i> ${badgeText}
            </span>
        `;
        
        // Set up edit button
        document.getElementById('editEventBtn').onclick = function() {
            // Populate edit form
            document.getElementById('edit_event_id').value = event.id;
            document.getElementById('edit_title').value = event.title.replace(/ \(...\)$/, '');
            document.getElementById('edit_description').value = event.extendedProps.description || '';
            document.getElementById('edit_location').value = event.extendedProps.location || '';
            document.getElementById('edit_event_type').value = type;
            document.getElementById('edit_org_id').value = event.extendedProps.org_id || '';
            
            document.getElementById('edit_start_date').value = event.startStr.split('T')[0];
            document.getElementById('edit_end_date').value = event.endStr 
                ? event.endStr.split('T')[0]
                : event.startStr.split('T')[0];
            
            document.getElementById('edit_all_day').checked = event.allDay;
            
            // Show/hide time fields
            const timeFields = document.querySelectorAll('.edit-time-field');
            timeFields.forEach(el => {
                el.style.display = event.allDay ? 'none' : 'block';
            });
            
            if (!event.allDay) {
                document.getElementById('edit_start_time').value = event.startStr.split('T')[1]?.substring(0,5) || '';
                document.getElementById('edit_end_time').value = event.endStr 
                    ? event.endStr.split('T')[1]?.substring(0,5) || ''
                    : '';
            }
            
            // Close details modal and open edit modal
            bootstrap.Modal.getInstance(document.getElementById('eventDetailsModal')).hide();
            new bootstrap.Modal(document.getElementById('editEventModal')).show();
        };
        
        // Load event history
        loadEventHistory(event.extendedProps.history);
        
        // Show the modal
        new bootstrap.Modal(document.getElementById('eventDetailsModal')).show();
    }

    // Load event history
    function loadEventHistory(historyData) {
        const historyList = document.getElementById('eventHistoryList');
        historyList.innerHTML = '';
        
        try {
            const history = JSON.parse(historyData || '[]');
            
            if (history.length === 0) {
                historyList.innerHTML = `
                    <div class="empty-state">
                        <i class="bi bi-info-circle"></i>
                        <p>No history available</p>
                    </div>
                `;
                return;
            }
            
            history.reverse().forEach(h => {
                const item = document.createElement('div');
                item.className = 'history-item';
                item.innerHTML = `
                    <div class="history-icon">
                        <i class="bi bi-${h.action === 'Created' ? 'plus-circle' : 'arrow-clockwise'}"></i>
                    </div>
                    <div class="history-content">
                        <div class="history-action">${h.action} by ${h.role}</div>
                        <div class="history-meta">
                            <span><i class="bi bi-clock"></i> ${new Date(h.date).toLocaleString()}</span>
                        </div>
                    </div>
                `;
                historyList.appendChild(item);
            });
        } catch (e) {
            historyList.innerHTML = `
                <div class="empty-state">
                    <i class="bi bi-exclamation-triangle"></i>
                    <p>Unable to load history</p>
                </div>
            `;
        }
    }

    // Sidebar event click handlers
    document.querySelectorAll('.view-event-details').forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Simulate an event object for the modal
            const fakeEvent = {
                id: this.dataset.id,
                title: this.dataset.title,
                start: new Date(),
                end: new Date(),
                allDay: this.dataset.time === 'All Day',
                extendedProps: {
                    type: this.dataset.type,
                    location: this.dataset.location,
                    description: this.dataset.desc,
                    history: this.dataset.history
                }
            };
            
            showEventDetails(fakeEvent);
        });
    });

    // Event type change handler for organization select
    document.getElementById('eventTypeSelect').addEventListener('change', function() {
        document.getElementById('orgSelect').style.display = 
            this.value === 'Organization' ? 'block' : 'none';
    });

    // All day toggle handlers
    document.getElementById('allDayToggle').addEventListener('change', function() {
        document.querySelectorAll('.time-field').forEach(el => {
            el.style.display = this.checked ? 'none' : 'block';
        });
    });

    document.getElementById('edit_all_day').addEventListener('change', function() {
        document.querySelectorAll('.edit-time-field').forEach(el => {
            el.style.display = this.checked ? 'none' : 'block';
        });
    });

    // Set minimum date for start date
    const today = new Date();
    const tomorrow = new Date(today);
    tomorrow.setDate(tomorrow.getDate() + 1);
    const minDate = tomorrow.toISOString().split('T')[0];
    
    document.querySelector('input[name="start_date"]').min = minDate;
    document.querySelector('input[name="end_date"]').min = minDate;
});
</script>

</body>
</html>