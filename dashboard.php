<?php
session_start();
require_once 'config/db.php';

/* ==============================
   AUTH CHECK
============================== */
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

/* ==============================
   DASHBOARD COUNTS
============================== */
$pendingSubmissions = $conn->query(
    "SELECT COUNT(*) total FROM submitted_reports WHERE status='Pending'"
)->fetch_assoc()['total'] ?? 0;

$upcomingEvents = $conn->query(
    "SELECT COUNT(*) total FROM events WHERE start_date >= CURDATE()"
)->fetch_assoc()['total'] ?? 0;

$activeOrgs = $conn->query(
    "SELECT COUNT(DISTINCT org_id) total FROM created_reports"
)->fetch_assoc()['total'] ?? 0;

$pendingFinancial = $conn->query(
    "SELECT COUNT(*) total FROM created_reports WHERE status='Draft'"
)->fetch_assoc()['total'] ?? 0;

$totalAnnouncements = $conn->query(
    "SELECT COUNT(*) total FROM announcements WHERE status='Published'"
)->fetch_assoc()['total'] ?? 0;

/* ==============================
   MONTHLY SUBMISSIONS (CHART)
============================== */
$chartData = [];
$res = $conn->query("
    SELECT MONTH(submitted_at) m, COUNT(*) total
    FROM submitted_reports
    WHERE YEAR(submitted_at)=YEAR(CURDATE())
    GROUP BY MONTH(submitted_at)
");
while ($row = $res->fetch_assoc()) {
    $chartData[(int)$row['m']] = (int)$row['total'];
}
$months = [];
$values = [];
for ($i=1;$i<=12;$i++){
    $months[] = date('M', mktime(0,0,0,$i,1));
    $values[] = $chartData[$i] ?? 0;
}

/* ==============================
   UPCOMING EVENTS
============================== */
$events = $conn->query("
    SELECT title, start_date 
    FROM events 
    WHERE start_date >= CURDATE()
    ORDER BY start_date ASC
    LIMIT 3
");

/* ==============================
   ANNOUNCEMENTS
============================== */
$announcements = $conn->query("
    SELECT title, published_at 
    FROM announcements 
    WHERE status='Published'
    ORDER BY published_at DESC
    LIMIT 3
");

/* ==============================
   RECENT ACTIVITY
============================== */
$activity = $conn->query("
    SELECT 'Submission' type, submitted_at time, status 
    FROM submitted_reports
    UNION ALL
    SELECT 'Report' type, created_at time, status 
    FROM created_reports
    ORDER BY time DESC
    LIMIT 5
");

/* ==============================
   CALENDAR SETUP
============================== */
$month = isset($_GET['m']) ? (int)$_GET['m'] : date('n');
$year  = isset($_GET['y']) ? (int)$_GET['y'] : date('Y');

$firstDayOfMonth = mktime(0,0,0,$month,1,$year);
$daysInMonth = date('t', $firstDayOfMonth);
$startDay = date('w', $firstDayOfMonth);

$eventMap = [];
$calEvents = $conn->query("
    SELECT id, title, start_date, end_date, start_time, end_time,
           is_all_day, category, event_type, location
    FROM events
    WHERE MONTH(start_date) = $month
      AND YEAR(start_date) = $year
      AND status = 'Approved'
    ORDER BY start_date ASC, start_time ASC
");

$eventMap = [];
$monthlyEvents = [];

while ($row = $calEvents->fetch_assoc()) {
    $day = date('j', strtotime($row['start_date']));
    $eventMap[$day][] = [
    'title' => $row['title'],
    'is_all_day' => $row['is_all_day'],
    'start_time' => $row['start_time'],
    'end_time' => $row['end_time']
];

    $monthlyEvents[] = $row;
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>USC Central | Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    .calendar-event-dot {
    font-size: 11px;
    color: #2563eb;
}

body{background:#f5f7fb;}
.calendar-event-dot {
    font-size: 11px;
    font-weight: 600;
    color: #2563eb;
    line-height: 1.2;
}

.main-content{margin-left:260px;padding:30px;}
.stat-card{background:#fff;border-radius:18px;padding:22px;box-shadow:0 10px 30px rgba(0,0,0,.08);}
.stat-icon{width:48px;height:48px;display:flex;align-items:center;justify-content:center;border-radius:14px;font-size:22px;color:#fff;}
.section-card{background:#fff;border-radius:18px;padding:20px;box-shadow:0 10px 30px rgba(0,0,0,.06);}
.activity-item{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #eee;}
.activity-item:last-child{border-bottom:none;}
</style>
</head>

<body>
<?php include 'partials/sidenav.php'; ?>

<div class="main-content">

<h4 class="fw-bold mb-4">Dashboard</h4>

<!-- TOP STATS -->
<div class="row g-4 mb-4">
<?php
$stats = [
    ['Pending Submissions',$pendingSubmissions,'bx-time','primary'],
    ['Upcoming Events',$upcomingEvents,'bx-calendar','success'],
    ['Active Organizations',$activeOrgs,'bx-buildings','warning'],
    ['Pending Financial Reports',$pendingFinancial,'bx-wallet','danger'],
];
foreach($stats as $s):
?>
<div class="col-xl-3 col-md-6">
    <div class="stat-card d-flex justify-content-between">
        <div>
            <small class="text-muted"><?= $s[0] ?></small>
            <h3 class="fw-bold"><?= $s[1] ?></h3>
        </div>
        <div class="stat-icon bg-<?= $s[3] ?>"><i class='bx <?= $s[2] ?>'></i></div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- CHART & EVENTS -->
<div class="row g-4 mb-4">
<div class="col-lg-8">
    <div class="section-card">
        <h6 class="fw-bold mb-3"><i class="bx bx-bar-chart"></i> Monthly Submissions</h6>
        <canvas id="chart"></canvas>
    </div>
</div>

<div class="col-lg-4">
    <div class="section-card">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="fw-bold mb-0">
                <i class="bx bx-calendar-event"></i> Events Calendar
            </h6>
            <div>
                <a href="?m=<?= $month-1 ?>&y=<?= $year ?>" class="btn btn-sm btn-light">&lt;</a>
                <a href="?m=<?= $month+1 ?>&y=<?= $year ?>" class="btn btn-sm btn-light">&gt;</a>
            </div>
        </div>

        <div class="text-center fw-semibold mb-2">
            <?= date('F Y', $firstDayOfMonth) ?>
        </div>

        <table class="table table-sm text-center align-middle">
            <thead class="small text-muted">
                <tr>
                    <th>Sun</th><th>Mon</th><th>Tue</th>
                    <th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th>
                </tr>
            </thead>
            <tbody>
            <tr>
            <?php
            for ($i=0; $i<$startDay; $i++) echo "<td></td>";

            for ($day=1; $day<=$daysInMonth; $day++) {
                $hasEvent = isset($eventMap[$day]);
                echo "<td class='small ".($hasEvent?'bg-light':'')."'>";
                echo "<div class='fw-bold'>$day</div>";

                if ($hasEvent) {
    foreach ($eventMap[$day] as $ev) {

        echo "<div class='calendar-event-dot'>• ".htmlspecialchars($ev['title'])."</div>";

        if ($ev['is_all_day']) {
            echo "<div class='text-muted small'>All Day</div>";
        } else {
            echo "<div class='text-muted small'>"
                .date('h:i A', strtotime($ev['start_time']))
                ." - "
                .date('h:i A', strtotime($ev['end_time']))
                ."</div>";
        }

    }
}
                echo "</td>";

                if ((($day + $startDay) % 7) == 0) echo "</tr><tr>";
            }
            ?>
            </tr>
            </tbody>
        </table>
        <hr class="my-3">

<h6 class="fw-bold mb-2">
    <i class="bx bx-list-ul"></i> Events This Month
</h6>

<?php if (empty($monthlyEvents)): ?>
    <div class="text-muted small">No events scheduled.</div>
<?php else: ?>
    <?php foreach ($monthlyEvents as $ev): ?>
        <div class="border rounded p-2 mb-2 small">

            <div class="fw-semibold text-primary">
                <?= htmlspecialchars($ev['title']) ?>
            </div>

            <div class="text-muted">
                📅
                <?= date('M d, Y', strtotime($ev['start_date'])) ?>
                <?php if (!empty($ev['end_date']) && $ev['end_date'] !== $ev['start_date']): ?>
                    – <?= date('M d, Y', strtotime($ev['end_date'])) ?>
                <?php endif; ?>
            </div>

            <div class="text-muted">
                ⏰
                <?php if ($ev['is_all_day']): ?>
                    All Day
                <?php else: ?>
                    <?= date('h:i A', strtotime($ev['start_time'])) ?>
                    –
                    <?= date('h:i A', strtotime($ev['end_time'])) ?>
                <?php endif; ?>
            </div>

            <?php if (!empty($ev['category'])): ?>
                <div>🏷 Category: <strong><?= htmlspecialchars($ev['category']) ?></strong></div>
            <?php endif; ?>

            <div>🏛 Type: <strong><?= htmlspecialchars($ev['event_type']) ?></strong></div>

            <?php if (!empty($ev['location'])): ?>
                <div>📍 <?= htmlspecialchars($ev['location']) ?></div>
            <?php endif; ?>

        </div>
    <?php endforeach; ?>
<?php endif; ?>


        <a href="events.php" class="btn btn-primary btn-sm w-100 mt-2">
            View All Events
        </a>
    </div>
</div>


<!-- ANNOUNCEMENTS & ACTIVITY -->
<div class="row g-4">
<div class="col-lg-6">
    <div class="section-card">
        <h6 class="fw-bold mb-3"><i class="bx bx-megaphone"></i> Announcements</h6>
        <?php while($a=$announcements->fetch_assoc()): ?>
            <div class="mb-2">
                <strong><?= htmlspecialchars($a['title']) ?></strong><br>
                <small class="text-muted"><?= date('M d, Y', strtotime($a['published_at'])) ?></small>
            </div>
        <?php endwhile; ?>
        <a href="announcements.php" class="btn btn-outline-primary btn-sm mt-2">Manage Announcements</a>
    </div>
</div>

<div class="col-lg-6">
    <div class="section-card">
        <h6 class="fw-bold mb-3"><i class="bx bx-history"></i> Recent Activity</h6>
        <?php while($r=$activity->fetch_assoc()): ?>
            <div class="activity-item">
                <span><?= $r['type'] ?> - <?= $r['status'] ?></span>
                <small><?= date('h:i A', strtotime($r['time'])) ?></small>
            </div>
        <?php endwhile; ?>
    </div>
</div>
</div>

</div>

<script>
new Chart(document.getElementById('chart'),{
    type:'bar',
    data:{
        labels:<?= json_encode($months) ?>,
        datasets:[{
            label:'Submissions',
            data:<?= json_encode($values) ?>,
            backgroundColor:'#2563eb'
        }]
    },
    options:{responsive:true}
});
</script>

</body>
</html>
