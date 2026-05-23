<?php
include 'config/db.php';
session_start();

$id = (int)$_GET['id'];

/* ===============================
   FETCH ORGANIZATION OVERVIEW
=============================== */
$orgData = $conn->query("
    SELECT * FROM org_data 
    WHERE org_id = $id
")->fetch_assoc();

/* ===============================
   FETCH ORGANIZATION
=============================== */
$org = $conn->query("
    SELECT o.*, CONCAT(u.first_name,' ',u.last_name) president
    FROM organizations o
    JOIN users u ON o.president_id = u.id
    WHERE o.id = $id
")->fetch_assoc();

/* ===============================
   FETCH OFFICERS
=============================== */
$officers = $conn->query("
    SELECT o.position, u.first_name, u.last_name
    FROM org_officers o
    JOIN users u ON o.user_id = u.id
    WHERE o.org_id = $id
");
?>

<!DOCTYPE html>
<html>
<head>
<title><?= $org['org_name'] ?> | Organization View</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body{
    background:#f4f6fb;
    margin-left:300px;
    padding:30px;
}
.card-box{
    background:#fff;
    border-radius:16px;
    padding:25px;
    box-shadow:0 10px 30px rgba(0,0,0,.08);
}
.org-header{
    display:flex;
    align-items:center;
    gap:20px;
}
.org-logo{
    width:90px;
    height:90px;
    border-radius:14px;
    object-fit:cover;
}
.org-chart{
    margin-top:30px;
    text-align:center;
}
.chart-box{
    display:inline-block;
    background:#f8f9ff;
    border-radius:14px;
    padding:15px 20px;
    margin:10px;
    min-width:220px;
    box-shadow:0 5px 15px rgba(0,0,0,.08);
}
.chart-title{
    font-weight:600;
    color:#555;
}
.chart-name{
    font-size:18px;
    font-weight:600;
}
.line{
    height:40px;
    border-left:2px dashed #ccc;
    margin:0 auto;
}

/* ADVISER CARD */
.adviser-card{
    background:#f8f9ff;
    border-radius:16px;
    padding:20px;
    text-align:center;
    box-shadow:0 5px 15px rgba(0,0,0,.08);
}
.adviser-img{
    width:80px;
    height:80px;
    border-radius:50%;
    object-fit:cover;
    margin-bottom:10px;
}
.adviser-name{
    font-weight:600;
    font-size:16px;
}
.adviser-label{
    font-size:13px;
    color:#777;
}
</style>
</head>

<body>

<?php include 'partials/sidenav.php'; ?>

<div class="container-fluid">

<!-- ================= HEADER ================= -->
<div class="card-box mb-4">
    <div class="org-header">
        <?php if($org['org_logo']): ?>
            <img src="<?= $org['org_logo'] ?>" class="org-logo">
        <?php endif; ?>

        <div>
            <h3 class="mb-0"><?= $org['org_name'] ?></h3>
            <div class="text-muted"><?= $org['org_acronym'] ?></div>
            <div class="mt-1">
                <span class="badge bg-primary">Recognized Organization</span>
            </div>
        </div>
    </div>
</div>

<!-- ================= OVERVIEW + ADVISER ROW ================= -->
<div class="row g-4 mb-4">

    <!-- ORGANIZATION OVERVIEW (LEFT) -->
    <div class="col-lg-8">
        <div class="card-box h-100">
            <h5 class="mb-3">Organization Overview</h5>
            <p class="mb-0 text-muted" style="line-height:1.7">
                <?= !empty($orgData['overview']) 
                    ? nl2br(htmlspecialchars($orgData['overview'])) 
                    : 'No organization overview provided.' ?>
            </p>
        </div>
    </div>

    <!-- ADVISER PANEL (RIGHT) -->
    <div class="col-lg-4">
        <div class="adviser-card h-100">

            <?php if(!empty($orgData['adviser_image'])): ?>
                <img src="<?= $orgData['adviser_image'] ?>" class="adviser-img">
            <?php endif; ?>

            <div class="adviser-label mb-1">Faculty Adviser</div>

            <div class="adviser-name">
                <?= trim(
                    ($orgData['adviser_first_name'] ?? '') . ' ' .
                    ($orgData['adviser_middle_name'] ?? '') . ' ' .
                    ($orgData['adviser_last_name'] ?? '')
                ) ?: 'Not Assigned' ?>
            </div>

            <?php if(!empty($orgData['adviser_contact'])): ?>
                <div class="mt-2 small text-muted">
                    📞 <?= $orgData['adviser_contact'] ?>
                </div>
            <?php endif; ?>

            <?php if(!empty($orgData['adviser_email'])): ?>
                <div class="small text-muted">
                    ✉ <?= $orgData['adviser_email'] ?>
                </div>
            <?php endif; ?>

        </div>
    </div>

</div>

<!-- ================= ORG CHART ================= -->
<div class="card-box">
    <h5 class="mb-4">Organization Chart</h5>

    <div class="org-chart">

        <!-- PRESIDENT -->
        <div class="chart-box border-primary">
            <div class="chart-title">President</div>
            <div class="chart-name"><?= $org['president'] ?></div>
        </div>

        <div class="line"></div>

        <!-- OFFICERS -->
        <div class="d-flex justify-content-center flex-wrap">
            <?php while($o = $officers->fetch_assoc()): ?>
                <div class="chart-box">
                    <div class="chart-title"><?= $o['position'] ?></div>
                    <div class="chart-name">
                        <?= $o['first_name'].' '.$o['last_name'] ?>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>

    </div>
</div>

</div>

</body>
</html>
