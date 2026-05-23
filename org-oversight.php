<?php
include 'config/db.php';
session_start();

/* ===============================
PERMISSION SYSTEM
=============================== */
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['Admin','Sub-Admin'])) {
    die('Access Denied');
}

/* ===============================
GET ACTIVE ACADEMIC YEAR
=============================== */
$activeAY = $conn->query("
    SELECT * FROM academic_years 
    WHERE is_active = 1 
    LIMIT 1
")->fetch_assoc();

if (!$activeAY) {
    die('No active academic year set.');
}

$activeAcademicYearId = $activeAY['id'];

/* ===============================
HANDLE CREATE ORG
=============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_org'])) {

    // Handle logo
    $logoBase64 = null;
    if (!empty($_FILES['org_logo']['tmp_name'])) {
        $imageData = file_get_contents($_FILES['org_logo']['tmp_name']);
        $imageType = mime_content_type($_FILES['org_logo']['tmp_name']);
        $logoBase64 = 'data:' . $imageType . ';base64,' . base64_encode($imageData);
    }

    // Insert org
    $stmt = $conn->prepare("
        INSERT INTO organizations 
        (org_logo, org_acronym, org_name, department, president_id, academic_year_id, status, is_active)
        VALUES (?,?,?,?,?, ?, 'RECOGNIZED', 1)
    ");

    $stmt->bind_param(
        "ssssii",
        $logoBase64,
        $_POST['org_acronym'],
        $_POST['org_name'],
        $_POST['department'],
        $_POST['president_id'],
        $activeAcademicYearId
    );

    if (!$stmt->execute()) {
        die("Error creating organization: " . $stmt->error);
    }

    // Get the new org ID
    $newOrgId = $conn->insert_id;

    // Update the president's org_id in users table
    $presidentId = (int) $_POST['president_id'];
    $updateStmt = $conn->prepare("UPDATE users SET org_id=? WHERE id=?");
    $updateStmt->bind_param("ii", $newOrgId, $presidentId);
    $updateStmt->execute();

    header("Location: org-oversight.php");
    exit;
}


/* ===============================
TOGGLE ACTIVE / INACTIVE
=============================== */
if (isset($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    $conn->query("
        UPDATE organizations 
        SET is_active = IF(is_active=1,0,1)
        WHERE id=$id
    ");
    header("Location: org-oversight.php");
    exit;
}

/* ===============================
DELETE ORG
=============================== */
if (isset($_GET['delete'])) {
    $orgId = (int)$_GET['delete'];
    $conn->query("DELETE FROM organizations WHERE id=$orgId");
    header("Location: org-oversight.php");
    exit;
}

/* ===============================
PRESIDENTS
=============================== */
$presidents = $conn->query("
    SELECT u.id, u.first_name, u.last_name
    FROM users u
    LEFT JOIN organizations o ON u.id = o.president_id
    WHERE u.position='President' AND o.id IS NULL
");

/* ===============================
STATISTICS
=============================== */
$stats = $conn->query("
    SELECT
        COUNT(*) total_orgs,
        SUM(is_active=1) active_orgs,
        SUM(is_active=0) inactive_orgs
    FROM organizations
")->fetch_assoc();

/* ===============================
ORG LIST
=============================== */
$orgs = $conn->query("
    SELECT o.*, CONCAT(u.first_name,' ',u.last_name) president
    FROM organizations o
    JOIN users u ON o.president_id=u.id
");

/* ===============================
DEPARTMENT STATISTICS
=============================== */
$departmentStats = $conn->query("
    SELECT department, COUNT(*) as count 
    FROM organizations 
    GROUP BY department 
    ORDER BY count DESC
");

/* ===============================
DEPARTMENT COLORS
=============================== */
$departmentColors = [
    'UNIVERSITY ORGANIZATION' => '#1F2937', // Dark Gray / University color
    'COLLEGE OF ARTS AND SCIENCES' => '#3B82F6', // Blue
    'COLLEGE OF BUSINESS MANAGEMENT AND ACCOUNTANCY' => '#10B981', // Green
    'COLLEGE OF CRIMINAL JUSTICE EDUCATION' => '#6366F1', // Indigo
    'COLLEGE OF ENGINEERING AND ARCHITECTURE' => '#F59E0B', // Amber
    'COLLEGE OF HEALTH SCIENCES' => '#EF4444', // Red
    'COLLEGE OF HOSPITALITY AND TOURISM MANAGEMENT' => '#8B5CF6', // Purple
    'COLLEGE OF HUMAN SCIENCES' => '#EC4899', // Pink
    'COLLEGE OF INFORMATION AND TECHNOLOGY EDUCATION' => '#06B6D4', // Cyan
    'COLLEGE OF PHARMACY' => '#14B8A6', // Teal
    'COLLEGE OF TEACHER EDUCATION' => '#F97316', // Orange
];

/* ===============================
DEPARTMENT ICONS
=============================== */
$departmentIcons = [
    'UNIVERSITY ORGANIZATION' => 'bi-building', // University icon
    'COLLEGE OF ARTS AND SCIENCES' => 'bi-palette',
    'COLLEGE OF BUSINESS MANAGEMENT AND ACCOUNTANCY' => 'bi-graph-up',
    'COLLEGE OF CRIMINAL JUSTICE EDUCATION' => 'bi-shield-check',
    'COLLEGE OF ENGINEERING AND ARCHITECTURE' => 'bi-gear',
    'COLLEGE OF HEALTH SCIENCES' => 'bi-heart-pulse',
    'COLLEGE OF HOSPITALITY AND TOURISM MANAGEMENT' => 'bi-cup-straw',
    'COLLEGE OF HUMAN SCIENCES' => 'bi-people',
    'COLLEGE OF INFORMATION AND TECHNOLOGY EDUCATION' => 'bi-laptop',
    'COLLEGE OF PHARMACY' => 'bi-capsule',
    'COLLEGE OF TEACHER EDUCATION' => 'bi-book',
];

?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Organization Oversight</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/org-oversight.css">
<style>

</style>
</head>

<body>

<?php include 'partials/sidenav.php'; ?>

<div class="content">
    <!-- Page Header -->
    <div class="page-header">
        <h1>Organization Oversight</h1>
    </div>

    <div class="row">
        <!-- Main Content - Left Column -->
        <div class="col-lg-8">
            <div class="main-card">
                <div class="card-header">
                    <h2>Organization List</h2>
                    <button class="btn btn-primary add-btn" data-bs-toggle="modal" data-bs-target="#addOrg">
                        <i class="bi bi-plus-lg me-1"></i> Add Organization
                    </button>
                </div>
                
                <div class="card-body">
                    <!-- Search -->
                    <div class="search-container">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" id="search" placeholder="Search organizations..." class="form-control">
                        </div>
                    </div>

                    <!-- Organizations Table -->
                    <div class="table-responsive">
                        <table class="table" id="orgTable">
                            <thead>
                                <tr>
                                    <th>Organization</th>
                                    <th>President</th>
                                    <th>Department</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($o = $orgs->fetch_assoc()): 
                                $deptColor = $departmentColors[$o['department']] ?? '#6c757d';
                                $deptIcon = $departmentIcons[$o['department']] ?? 'bi-building';
                                ?>
                                <tr>
                                    <td>
                                        <div class="org-info">
                                            <div class="org-avatar">
                                                <?php if($o['org_logo']): ?>
                                                    <img src="<?= $o['org_logo'] ?>" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">
                                                <?php else: ?>
                                                    <?= strtoupper(substr($o['org_acronym'], 0, 1)) ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="org-details">
                                                <h6><?= htmlspecialchars($o['org_name']) ?></h6>
                                                <small><?= htmlspecialchars($o['org_acronym']) ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($o['president']) ?></td>
                                    <td>
                                        <div class="dept-dot">
                                            <span class="dot" style="background-color: <?= $deptColor ?>;"></span>
                                            <?= htmlspecialchars($o['department']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-column gap-1">
                                            <span class="badge <?= $o['is_active']?'badge-active':'badge-inactive' ?>">
                                                <?= $o['is_active']?'Active':'Inactive' ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <a href="org-view.php?id=<?= $o['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                View
                                            </a>
                                            <a href="?toggle=<?= $o['id'] ?>" class="btn btn-sm <?= $o['is_active']?'btn-success':'btn-danger' ?>">
                                                <?= $o['is_active']?'Active':'Inactive' ?>
                                            </a>
                                            <a href="?delete=<?= $o['id'] ?>" onclick="return confirm('Delete this organization?')" class="btn btn-sm delete-btn btn-danger">
                                                Delete
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                        <nav>
                            <ul class="pagination justify-content-end mt-3" id="orgPagination"></ul>
                        </nav>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar - Right Column - EXACTLY LIKE ANNOUNCEMENTS -->
        <div class="col-lg-4">
            <div class="sidebar-section">
                
                <!-- Statistics Card -->
                <div class="stats-card">
                    <h4><i class="bi bi-bar-chart me-2"></i> Organization Statistics</h4>
                    <div class="stats-item">
                        <div class="stats-label">
                            <i class="bi bi-building"></i>
                            <span>Total Organizations</span>
                        </div>
                        <div class="stats-value"><?= $stats['total_orgs'] ?></div>
                    </div>
                    <div class="stats-item">
                        <div class="stats-label">
                            <i class="bi bi-check-circle"></i>
                            <span>Active</span>
                        </div>
                        <div class="stats-value"><?= $stats['active_orgs'] ?></div>
                    </div>
                    <div class="stats-item">
                        <div class="stats-label">
                            <i class="bi bi-x-circle"></i>
                            <span>Inactive</span>
                        </div>
                        <div class="stats-value"><?= $stats['inactive_orgs'] ?></div>
                    </div>
                </div>

                <!-- CARD 2: Departments Overview -->
                <div class="sidebar-card">
                    <h4>Departments Overview</h4>
                    <?php 
                    $departmentStats->data_seek(0);
                    if(mysqli_num_rows($departmentStats) > 0): 
                    ?>
                        <div class="category-list">
                            <?php 
                            while($dept = $departmentStats->fetch_assoc()): 
                            $deptColor = $departmentColors[$dept['department']] ?? '#6c757d';
                            $deptIcon = $departmentIcons[$dept['department']] ?? 'bi-building';
                            
                            // Create CSS class from department name
                            $deptClass = strtolower(explode(' ', $dept['department'])[2] ?? 'arts');
                            ?>
                            <div class="category-item">
                                <div class="category-info">
                                    <span class="category-badge <?= $deptClass ?>"></span>
                                    <span class="category-name"><?= htmlspecialchars($dept['department']) ?></span>
                                </div>
                                <span class="category-count"><?= $dept['count'] ?></span>
                            </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-building"></i>
                            <p>No departments found</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- CARD 3: Recent Organizations -->
                <div class="sidebar-card">
                    <h4>Recent Organizations</h4>
                    <?php 
                    $recentOrgs = $conn->query("
                        SELECT o.org_name, o.org_acronym, o.department, o.created_at,
                               DATE_FORMAT(o.created_at, '%b %d') AS short_date
                        FROM organizations o
                        ORDER BY o.created_at DESC
                        LIMIT 3
                    ");
                    
                    if($recentOrgs && $recentOrgs->num_rows > 0): 
                    ?>
                        <div class="recent-list">
                            <?php while($org = $recentOrgs->fetch_assoc()): ?>
                            <div class="recent-item">
                                <div class="recent-icon">
                                    <i class="bi bi-building"></i>
                                </div>
                                <div class="recent-content">
                                    <div class="recent-title"><?= htmlspecialchars($org['org_name']) ?></div>
                                    <div class="recent-meta">
                                        <span><i class="bi bi-card-heading"></i> <?= htmlspecialchars($org['org_acronym']) ?></span>
                                        <span><i class="bi bi-calendar"></i> <?= $org['short_date'] ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-clock"></i>
                            <p>No recent organizations</p>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- Add Organization Modal -->
<div class="modal fade" id="addOrg" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="create_org">
                
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-plus-circle me-2"></i>
                        Add New Organization
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="modal-grid">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-image"></i>
                                Organization Logo
                            </label>
                            <input type="file" name="org_logo" class="form-control" accept="image/*">
                            <small class="text-muted">Optional. Recommended size: 200x200 pixels</small>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-card-heading"></i>
                                Acronym *
                            </label>
                            <input name="org_acronym" class="form-control" placeholder="e.g., CSA" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-building"></i>
                                Organization Name *
                            </label>
                            <input name="org_name" class="form-control" placeholder="Complete organization name" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-diagram-3"></i>
                                Department *
                            </label>
                            <select name="department" class="form-select" required>
                                <option value="">Select Department</option>
                                <?php foreach($departmentColors as $dept => $color): ?>
                                <option><?= $dept ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group mt-3">
                        <label class="form-label">
                            <i class="bi bi-person-badge"></i>
                            President *
                        </label>
                        <select name="president_id" class="form-select" required>
                            <option value="">Select President</option>
                            <?php 
                            $presidents->data_seek(0);
                            while($p = $presidents->fetch_assoc()): 
                            ?>
                            <option value="<?= $p['id'] ?>">
                                <?= htmlspecialchars($p['first_name'] . ' ' . $p['last_name']) ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                        <small class="text-muted">Only users with "President" position not assigned to any organization are shown</small>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i> Create Organization
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const ROWS_PER_PAGE = 10;
    const table = document.getElementById("orgTable");
    const tbody = table.querySelector("tbody");
    const rows = Array.from(tbody.querySelectorAll("tr"));
    const pagination = document.getElementById("orgPagination");
    const searchInput = document.getElementById("search");

    let currentPage = 1;
    let filteredRows = [...rows];

    function renderTable() {
        tbody.innerHTML = "";
        const start = (currentPage - 1) * ROWS_PER_PAGE;
        const end = start + ROWS_PER_PAGE;

        filteredRows.slice(start, end).forEach(row => {
            tbody.appendChild(row);
        });

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
            li.onclick = function (e) {
                e.preventDefault();
                currentPage = i;
                renderTable();
            };
            pagination.appendChild(li);
        }
    }

    // Real-time search
    searchInput.addEventListener("input", function () {
        const value = this.value.toLowerCase().trim();
        filteredRows = value === "" ? [...rows] : rows.filter(row =>
            row.textContent.toLowerCase().includes(value)
        );
        currentPage = 1;
        renderTable();
    });

    // Add hover effects to sidebar cards (like announcements page)
    const sidebarCards = document.querySelectorAll('.sidebar-card');
    sidebarCards.forEach(card => {
        card.addEventListener('mouseenter', () => {
            card.style.transform = 'translateY(-3px)';
        });
        
        card.addEventListener('mouseleave', () => {
            card.style.transform = 'translateY(0)';
        });
    });


    renderTable();
});
</script>
</body>
</html>