<?php
include 'config/db.php';

/* =======================
   CREATE ACADEMIC YEAR
======================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_academic_year'])) {
    $yearStart = (int)$_POST['year_start'];
    $yearEnd   = (int)$_POST['year_end'];

    $stmt = $conn->prepare("
        INSERT INTO academic_years (year_start, year_end, is_active)
        VALUES (?, ?, 0)
    ");
    $stmt->bind_param("ii", $yearStart, $yearEnd);
    $stmt->execute();

    header("Location: system-admin.php");
    exit;
}

/* =======================
   SET ACTIVE ACADEMIC YEAR
======================= */
if (isset($_GET['set_active_year'])) {
    $yearId = (int)$_GET['set_active_year'];

    $conn->query("UPDATE academic_years SET is_active = 0");
    $conn->query("UPDATE academic_years SET is_active = 1 WHERE id = $yearId");

    header("Location: system-admin.php");
    exit;
}

/* =======================
   GET ACTIVE ACADEMIC YEAR
======================= */
$activeYear = $conn->query("
    SELECT * FROM academic_years 
    WHERE is_active = 1 
    LIMIT 1
")->fetch_assoc();

$activeYearId = $activeYear['id'] ?? null;

/* =======================
   FETCH ACADEMIC YEARS
======================= */
$academicYears = $conn->query("
    SELECT * FROM academic_years
    ORDER BY year_start DESC
");

/* =======================
   CREATE USER
======================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {

    if (!$activeYearId) {
        die("No active academic year set.");
    }

    $plainPassword  = $_POST['generated_password'];
    $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("
        INSERT INTO users 
        (student_id, first_name, last_name, middle_name, phone_number, email, password, role, position, academic_year_id) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "sssssssssi",
        $_POST['student_id'],
        $_POST['first_name'],
        $_POST['last_name'],
        $_POST['middle_name'],
        $_POST['phone_number'],
        $_POST['email'],
        $hashedPassword,
        $_POST['role'],
        $_POST['position'],
        $activeYearId
    );

    $stmt->execute();
}

/* =======================
   UPDATE USER (FROM MODAL)
======================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $stmt = $conn->prepare("
        UPDATE users 
        SET role=?, position=? 
        WHERE id=?
    ");
    $stmt->bind_param(
        "ssi",
        $_POST['role'],
        $_POST['position'],
        $_POST['user_id']
    );
    $stmt->execute();

    header("Location: system-admin.php");
    exit;
}

/* =======================
   DELETE USER
======================= */
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];

    $conn->query("DELETE FROM org_officers WHERE user_id=$id");
    $conn->query("UPDATE organizations SET president_id=NULL WHERE president_id=$id");
    $conn->query("DELETE FROM users WHERE id=$id");

    header("Location: system-admin.php");
    exit;
}

/* =======================
   USER ROLE COUNTS
======================= */
$roleCounts = [
    'Admin' => 0,
    'Sub-Admin' => 0,
    'User' => 0
];

$countResult = $conn->query("
    SELECT role, COUNT(*) as total
    FROM users
    GROUP BY role
");

while ($row = $countResult->fetch_assoc()) {
    if (isset($roleCounts[$row['role']])) {
        $roleCounts[$row['role']] = $row['total'];
    }
}

/* =======================
   FETCH USERS (FILTERED BY ACTIVE AY)
======================= */
$users = $conn->query("
    SELECT 
        u.*,
        COALESCE(o1.org_name, o2.org_name) AS org_name
    FROM users u
    LEFT JOIN organizations o1 ON u.id = o1.president_id
    LEFT JOIN org_officers oo ON u.id = oo.user_id
    LEFT JOIN organizations o2 ON oo.org_id = o2.id
    WHERE u.academic_year_id = $activeYearId
    ORDER BY u.created_at DESC
");

$modalTempPassword = substr(str_shuffle("ABCDEFGHJKLMNPQRSTUVWXYZ23456789"), 0, 8);
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>System Administration</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/system-admin.css">
</head>

<body>

<?php include 'partials/sidenav.php'; ?>

<div class="content">
    <!-- Page Header -->
    <div class="page-header">
        <h1>System Administration</h1>
    </div>

    <div class="row">
        <!-- Main Content - Left Column -->
        <div class="col-lg-8">
            <div class="main-card">
                <div class="card-header">
                    <h2>User List</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUser">
                        <i class="bi bi-person-plus me-1"></i> Add User
                    </button>
                </div>
                
                <div class="card-body">
                    <!-- Search -->
                    <div class="search-container">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" id="search" placeholder="Search users..." class="form-control">
                        </div>
                    </div>

                    <!-- Users Table -->
                    <div class="table-responsive">
                        <table class="table" id="userTable">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email Address</th>
                                    <th>Role</th>
                                    <th>Position</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($u = $users->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <div class="user-info">
                                            <div class="user-avatar">
                                                <?= strtoupper(substr($u['first_name'], 0, 1)) ?>
                                            </div>
                                            <div class="user-details">
                                                <h6><?= htmlspecialchars($u['first_name'] . " " . $u['last_name']) ?></h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($u['email']) ?></td>
                                    <td>
                                        <span class="badge badge-<?= strtolower(str_replace('-', '', $u['role'])) ?>">
                                            <?= $u['role'] ?>
                                        </span>
                                    </td>
                                    <td><?= $u['position'] ? htmlspecialchars($u['position']) : '—' ?></td>
                                    <td>
                                        <button class="btn btn-primary btn-sm" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#edit<?= $u['id'] ?>">
                                            Edit
                                        </button>
                                        <a href="?delete=<?= $u['id'] ?>" 
                                           class="btn btn-danger btn-sm"
                                           onclick="return confirm('Delete this user?')">
                                            Delete
                                        </a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                        <nav>
                            <ul class="pagination justify-content-end mt-3" id="userPagination"></ul>
                        </nav>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar - Right Column -->
        <div class="col-lg-4">
            <!-- Academic Years Card -->
            <div class="sidebar-card">
                <h3>
                    Academic Year
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addAcademicYear">
                        <i class="bi bi-plus-lg me-1"></i> Add
                    </button>
                </h3>
                
                <div class="year-list">
                    <?php 
                    $academicYears->data_seek(0);
                    while($ay = $academicYears->fetch_assoc()): 
                    ?>
                    <div class="year-item">
                        <div>
                            <div class="year-text"><?= $ay['year_start'] ?>–<?= $ay['year_end'] ?></div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="year-status <?= $ay['is_active'] ? 'active' : 'inactive' ?>">
                                <?= $ay['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                            <?php if(!$ay['is_active']): ?>
                            <a href="?set_active_year=<?= $ay['id'] ?>" class="btn btn-sm btn-outline-primary">
                                Set Active
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>

            <!-- User Overview Card -->
            <div class="sidebar-card">
                <h3>User Overview</h3>
                <div class="row mb-3">
                    <div class="col-6">
                        <div class="text-muted small">Total Users</div>
                        <div class="fw-bold h6"><?= array_sum($roleCounts) ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Active Year</div>
                        <div class="fw-bold h6">
                            <?= $activeYear ? $activeYear['year_start'] . '-' . $activeYear['year_end'] : 'Not set' ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Combined Stats Card -->
            <div class="stats-card">
                <h4>User Statistics</h4>
                <div class="stats-item">
                    <div class="stats-label">
                        <i class="bi bi-shield-check"></i>
                        <span>Admin Users</span>
                    </div>
                    <div class="stats-value"><?= $roleCounts['Admin'] ?></div>
                </div>
                <div class="stats-item">
                    <div class="stats-label">
                        <i class="bi bi-person-badge"></i>
                        <span>Sub-Admins</span>
                    </div>
                    <div class="stats-value"><?= $roleCounts['Sub-Admin'] ?></div>
                </div>
                <div class="stats-item">
                    <div class="stats-label">
                        <i class="bi bi-people"></i>
                        <span>Regular Users</span>
                    </div>
                    <div class="stats-value"><?= $roleCounts['User'] ?></div>
                </div>
                <div class="stats-total">
                    <div class="stats-total-label">Total Users</div>
                    <div class="stats-total-value"><?= array_sum($roleCounts) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit User Modals -->
<?php
$users->data_seek(0);
while($u = $users->fetch_assoc()):
?>
<div class="modal fade" id="edit<?= $u['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="update_user">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                
                <div class="modal-header">
                    <h5 class="modal-title">Edit User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-select" required>
                            <option value="Admin" <?= $u['role']=='Admin'?'selected':'' ?>>Admin</option>
                            <option value="Sub-Admin" <?= $u['role']=='Sub-Admin'?'selected':'' ?>>Sub-Admin</option>
                            <option value="User" <?= $u['role']=='User'?'selected':'' ?>>User</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Position</label>
                        <select name="position" class="form-select" required>
                            <option value="President" <?= $u['position']=='President'?'selected':'' ?>>President</option>
                            <option value="Vice President" <?= $u['position']=='Vice President'?'selected':'' ?>>Vice President</option>
                            <option value="Secretary" <?= $u['position']=='Secretary'?'selected':'' ?>>Secretary</option>
                            <option value="Treasurer" <?= $u['position']=='Treasurer'?'selected':'' ?>>Treasurer</option>
                            <option value="Auditor" <?= $u['position']=='Auditor'?'selected':'' ?>>Auditor</option>
                            <option value="Public Relations Officer" <?= $u['position']=='Public Relations Officer'?'selected':'' ?>>Public Relations Officer</option>
                            <option value="Representative" <?= $u['position']=='Representative'?'selected':'' ?>>Representative</option>
                        </select>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endwhile; ?>

<!-- Add User Modal - ENHANCED -->
<div class="modal fade" id="addUser" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="create_user">
                
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-person-plus me-2"></i>
                        Add New User
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="modal-grid">
                        <div class="form-group">
                            <label class="form-label">Student ID *</label>
                            <input name="student_id" class="form-control" placeholder="Enter student ID" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Email Address *</label>
                            <input type="email" name="email" class="form-control" placeholder="Enter email" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">First Name *</label>
                            <input type="text" name="first_name" class="form-control" placeholder="First name" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Last Name *</label>
                            <input type="text" name="last_name" class="form-control" placeholder="Last name" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Middle Name</label>
                            <input type="text" name="middle_name" class="form-control" placeholder="Middle name">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" name="phone_number" class="form-control" placeholder="Phone number">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Role *</label>
                            <select name="role" class="form-select" required>
                                <option value="" selected disabled>Select role</option>
                                <option value="Admin">Admin</option>
                                <option value="Sub-Admin">Sub-Admin</option>
                                <option value="User">User</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Position *</label>
                            <select name="position" class="form-select" required>
                                <option value="" selected disabled>Select position</option>
                                <option value="President">President</option>
                                <option value="Vice President">Vice President</option>
                                <option value="Secretary">Secretary</option>
                                <option value="Treasurer">Treasurer</option>
                                <option value="Auditor">Auditor</option>
                                <option value="Public Relations Officer">Public Relations Officer</option>
                                <option value="Public Relations Officer">Representative</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group mt-4">
                        <label class="form-label">Generated Password</label>
                        <div class="password-display-container">
                            <p class="password-display"><?= $modalTempPassword ?></p>
                            <p class="password-hint">Copy this password and share it with the user</p>
                        </div>
                        <input type="hidden" name="generated_password" value="<?= $modalTempPassword ?>">
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-person-plus me-1"></i> Create User
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Academic Year Modal - ENHANCED -->
<div class="modal fade" id="addAcademicYear" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="create_academic_year">
                
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-calendar-plus me-2"></i>
                        Add Academic Year
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="modal-grid">
                        <div class="form-group">
                            <label class="form-label">Start Year *</label>
                            <input type="number" id="year_start" name="year_start" 
                                   class="form-control" min="2000" max="2100" 
                                   placeholder="e.g., 2025" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">End Year</label>
                            <input type="number" id="year_end" name="year_end" 
                                   class="form-control" readonly>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-4 mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        The end year is automatically calculated as start year + 1
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i> Save Academic Year
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {

    // Auto-calculate academic year
    const yearStart = document.getElementById('year_start');
    const yearEnd = document.getElementById('year_end');

    if (yearStart && yearEnd) {
        yearStart.addEventListener('input', function() {
            yearEnd.value = this.value ? parseInt(this.value) + 1 : '';
        });
    }

    // Modal autofocus
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('shown.bs.modal', function() {
            const input = this.querySelector('.form-control, .form-select');
            if (input) input.focus();
        });
    });

});
</script>

<script>
document.addEventListener("DOMContentLoaded", function () {

    const ROWS_PER_PAGE = 10;

    const table = document.getElementById("userTable");
    const tbody = table.querySelector("tbody");
    const rows = Array.from(tbody.querySelectorAll("tr"));
    const pagination = document.getElementById("userPagination");
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

    // 🔍 REAL-TIME SEARCH (RESTORES DATA WHEN CLEARED)
    searchInput.addEventListener("input", function () {
        const value = this.value.toLowerCase().trim();

        filteredRows = value === ""
            ? [...rows]
            : rows.filter(row =>
                row.textContent.toLowerCase().includes(value)
              );

        currentPage = 1;
        renderTable();
    });

    renderTable();
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>