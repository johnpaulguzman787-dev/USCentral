<?php
include 'config/db.php';

/* =======================
   DELETE ANNOUNCEMENT
======================= */
if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $conn->query("DELETE FROM announcements WHERE id=$id");
    header("Location: announcement.php");
    exit;
}

/* =======================
   UPDATE ANNOUNCEMENT
======================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_id'])) {
    $id       = (int) $_POST['edit_id'];
    $title    = trim($_POST['title']);
    $content  = $_POST['content'];
    $category = $_POST['category'];
    
    // 🔒 PREVENT STATUS CHANGE IF ALREADY PUBLISHED
    $check = $conn->query("SELECT status FROM announcements WHERE id=$id")->fetch_assoc();
    if ($check['status'] === 'Published') {
        $status = 'Published';
    } else {
        $status = $_POST['status'];
    }

    /* IMAGE */
    $imageSQL = "";
    if (!empty($_FILES['image']['tmp_name'])) {
        $img  = file_get_contents($_FILES['image']['tmp_name']);
        $type = mime_content_type($_FILES['image']['tmp_name']);
        $imageBase64 = 'data:' . $type . ';base64,' . base64_encode($img);
        $imageSQL = ", image='$imageBase64'";
    }

    /* DOCUMENT */
    $docSQL = "";
    if (!empty($_FILES['document']['tmp_name'])) {
        $docData = file_get_contents($_FILES['document']['tmp_name']);
        $docType = mime_content_type($_FILES['document']['tmp_name']);
        $documentBase64 = 'data:' . $docType . ';base64,' . base64_encode($docData);
        $documentName = $_FILES['document']['name'];
        $docSQL = ", document='$documentBase64', document_name='$documentName'";
    }

    $conn->query("
        UPDATE announcements SET
            title='$title',
            content='$content',
            category='$category',
            status='$status'
            $imageSQL
            $docSQL
        WHERE id=$id
    ");

    header("Location: announcement.php?tab=$status");
    exit;
}

/* =======================
   SESSION START & AUTH GUARD
======================= */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];

/* =======================
   CREATE ANNOUNCEMENT
======================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['publish_action'])) {
    $title    = trim($_POST['title']);
    $content  = $_POST['content'];
    $category = $_POST['category'];
    $action   = $_POST['publish_action'];

    $status = 'Draft';
    $published_at = null;

    if ($action === 'publish') {
        $status = 'Published';
        $published_at = date('Y-m-d H:i:s');
    }

    /* IMAGE BASE64 */
    $imageBase64 = null;
    if (!empty($_FILES['image']['tmp_name'])) {
        $img  = file_get_contents($_FILES['image']['tmp_name']);
        $type = mime_content_type($_FILES['image']['tmp_name']);
        $imageBase64 = 'data:' . $type . ';base64,' . base64_encode($img);
    }

    /* DOCUMENT BASE64 */
    $documentBase64 = null;
    $documentName   = null;

    if (!empty($_FILES['document']['tmp_name'])) {
        $allowed = [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ];

        $docType = mime_content_type($_FILES['document']['tmp_name']);

        if (in_array($docType, $allowed)) {
            $docData = file_get_contents($_FILES['document']['tmp_name']);
            $documentBase64 = 'data:' . $docType . ';base64,' . base64_encode($docData);
            $documentName = $_FILES['document']['name'];
        }
    }

    /* INSERT */
    $stmt = $conn->prepare("
        INSERT INTO announcements
        (title, content, category, status, image, document, document_name, created_by, published_at)
        VALUES (?,?,?,?,?,?,?,?,?)
    ");

    $stmt->bind_param(
        "sssssssis",
        $title,
        $content,
        $category,
        $status,
        $imageBase64,
        $documentBase64,
        $documentName,
        $user_id,
        $published_at
    );

    $stmt->execute();
    header("Location: announcement.php");
    exit;
}

/* =======================
   COUNTS
======================= */
$counts = [];
$cq = $conn->query("SELECT status, COUNT(*) c FROM announcements GROUP BY status");
while ($r = $cq->fetch_assoc()) {
    $counts[$r['status']] = $r['c'];
}

$tab = $_GET['tab'] ?? 'Published';

/* =======================
   FETCH ANNOUNCEMENTS
======================= */
$stmt = $conn->prepare("
    SELECT 
        a.*,
        CONCAT(u.first_name,' ',u.last_name) AS author,
        DATE_FORMAT(a.created_at, '%b %d, %Y') AS formatted_date,
        DATE_FORMAT(a.published_at, '%b %d, %Y') AS formatted_published
    FROM announcements a
    JOIN users u ON a.created_by = u.id
    WHERE a.status = ?
    ORDER BY a.category, a.created_at DESC
");
$stmt->bind_param("s", $tab);
$stmt->execute();
$result = $stmt->get_result();

$grouped = [
    'Announcement' => [],
    'Memo' => [],
    'Reminder' => []
];

while ($row = $result->fetch_assoc()) {
    if (isset($grouped[$row['category']])) {
        $grouped[$row['category']][] = $row;
    }
}

/* =======================
   FETCH DRAFTS (FOR MODAL)
======================= */
$drafts = [];
$draftStmt = $conn->prepare("
    SELECT id, title, category, created_at, content, status,
           DATE_FORMAT(created_at, '%b %d, %Y') AS formatted_date
    FROM announcements
    WHERE status = 'Draft'
    ORDER BY created_at DESC
");
$draftStmt->execute();
$draftResult = $draftStmt->get_result();

while ($d = $draftResult->fetch_assoc()) {
    $drafts[] = $d;
}

// Get total counts for stats
$totalAnnouncements = array_sum($counts);
$totalPublished = $counts['Published'] ?? 0;
$totalDrafts = $counts['Draft'] ?? 0;

/* =======================
   FETCH ADDITIONAL DATA FOR RIGHT SIDEBAR CARDS
======================= */

// 1. Get user's draft count (safe query)
$userDraftCount = 0;
$userDraftQuery = $conn->prepare("SELECT COUNT(*) as count FROM announcements WHERE created_by = ? AND status = 'Draft'");
if ($userDraftQuery) {
    $userDraftQuery->bind_param("i", $user_id);
    $userDraftQuery->execute();
    $userDraftResult = $userDraftQuery->get_result();
    if ($userDraftResult) {
        $userDraftRow = $userDraftResult->fetch_assoc();
        $userDraftCount = $userDraftRow['count'] ?? 0;
    }
}

// 2. Get user's published count (safe query)
$userPublishedCount = 0;
$userPublishedQuery = $conn->prepare("SELECT COUNT(*) as count FROM announcements WHERE created_by = ? AND status = 'Published'");
if ($userPublishedQuery) {
    $userPublishedQuery->bind_param("i", $user_id);
    $userPublishedQuery->execute();
    $userPublishedResult = $userPublishedQuery->get_result();
    if ($userPublishedResult) {
        $userPublishedRow = $userPublishedResult->fetch_assoc();
        $userPublishedCount = $userPublishedRow['count'] ?? 0;
    }
}

// 3. Get total read count (safe query)
$totalReads = 0;
$readCountQuery = $conn->query("SELECT SUM(read_count) as total FROM announcements");
if ($readCountQuery) {
    $readCountRow = $readCountQuery->fetch_assoc();
    $totalReads = $readCountRow['total'] ?? 0;
}

// 4. Get recent announcements (last 3)
$recentAnnouncements = [];
$recentQuery = $conn->query("
    SELECT a.id, a.title, a.category, a.created_at,
           DATE_FORMAT(a.created_at, '%b %d') AS short_date
    FROM announcements a
    WHERE a.status = 'Published'
    ORDER BY a.created_at DESC
    LIMIT 3
");
if ($recentQuery) {
    while ($row = $recentQuery->fetch_assoc()) {
        $recentAnnouncements[] = $row;
    }
}

// 5. Get top read announcements
$topReadAnnouncements = [];
$topReadQuery = $conn->query("
    SELECT id, title, read_count, category
    FROM announcements
    WHERE read_count > 0 AND status = 'Published'
    ORDER BY read_count DESC
    LIMIT 3
");
if ($topReadQuery) {
    while ($row = $topReadQuery->fetch_assoc()) {
        $topReadAnnouncements[] = $row;
    }
}

// 6. Get average read count
$avgReads = 0;
$avgQuery = $conn->query("SELECT AVG(read_count) as avg FROM announcements WHERE status = 'Published'");
if ($avgQuery) {
    $avgRow = $avgQuery->fetch_assoc();
    $avgReads = round($avgRow['avg'] ?? 0);
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Announcements Management</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/announcement.css">
<style>

</style>
</head>

<body>

<?php include 'partials/sidenav.php'; ?>

<div class="content">
    <!-- Page Header -->
    <div class="page-header">
        <h1>Announcements Management</h1>
    </div>

    <div class="row">
        <!-- Main Content - Left Column -->
        <div class="col-lg-8">
            <div class="main-card">
                <div class="card-header">
                    <h2>Announcements</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createAnnouncementModal">
                        <i class="bi bi-plus-lg me-1"></i> Create Announcement
                    </button>
                </div>
                
                <div class="card-body">
                    <!-- Status Tabs -->
                    <ul class="nav nav-pills mb-4">
                        <li class="nav-item">
                            <a class="nav-link <?= $tab == 'Published' ? 'active' : '' ?>" href="?tab=Published">
                                <i class="bi bi-megaphone me-1"></i> Published
                                <span class="badge bg-light text-dark ms-1"><?= $totalPublished ?></span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $tab == 'Draft' ? 'active' : '' ?>" href="?tab=Draft">
                                <i class="bi bi-file-earmark me-1"></i> Drafts
                                <span class="badge bg-light text-dark ms-1"><?= $totalDrafts ?></span>
                            </a>
                        </li>
                    </ul>

                    <!-- Search -->
                    <div class="search-container">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" id="search" placeholder="Search announcements..." class="form-control">
                        </div>
                    </div>

                    <!-- Announcements by Category -->
                    <?php foreach ($grouped as $category => $items): ?>
                        <?php if (count($items) === 0) continue; ?>
                        
                        <div class="mb-4">
                            <h6 class="mb-3 d-flex align-items-center">
                                <span class="badge badge-<?= $category ?> me-2">
                                    <?= $category ?>
                                </span>
                                <span class="text-muted small">
                                    (<?= count($items) ?> announcement<?= count($items) !== 1 ? 's' : '' ?>)
                                </span>
                            </h6>
                            
                            <div class="announcements-container" id="container-<?= strtolower($category) ?>">
                                <?php foreach ($items as $a): ?>
                                <div class="announcement-item" data-search="<?= strtolower($a['title'] . ' ' . $a['author']) ?>">
                                    <div class="announcement-title">
                                        <span><?= htmlspecialchars($a['title']) ?></span>
                                        <span class="badge badge-<?= $a['status'] ?>">
                                            <?= $a['status'] ?>
                                        </span>
                                    </div>
                                    
                                    <div class="announcement-meta">
                                        <span>
                                            <i class="bi bi-person"></i>
                                            <?= htmlspecialchars($a['author']) ?>
                                        </span>
                                        <span>
                                            <i class="bi bi-calendar"></i>
                                            <?= $a['formatted_published'] ?? $a['formatted_date'] ?>
                                        </span>
                                        <span>
                                            <i class="bi bi-eye"></i>
                                            <?= (int)$a['read_count'] ?> reads
                                        </span>
                                    </div>
                                    
                                    <div class="announcement-preview">
                                        <?= nl2br(htmlspecialchars(substr($a['content'], 0, 150) . (strlen($a['content']) > 150 ? '...' : ''))) ?>
                                    </div>
                                    
                                    <div class="announcement-actions">
                                        <button 
                                            class="btn btn-primary btn-sm editBtn"
                                            data-id="<?= $a['id'] ?>"
                                            data-title="<?= htmlspecialchars($a['title'], ENT_QUOTES) ?>"
                                            data-content="<?= htmlspecialchars($a['content'], ENT_QUOTES) ?>"
                                            data-category="<?= $a['category'] ?>"
                                            data-status="<?= $a['status'] ?>"
                                            data-image="<?= $a['image'] ?>"
                                            data-document="<?= $a['document'] ?>"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editAnnouncementModal">
                                            <i class="bi bi-pencil me-1"></i> Edit
                                        </button>
                                        
                                        <a href="?delete=<?= $a['id'] ?>"
                                           class="btn btn-danger btn-sm"
                                           onclick="return confirm('Delete this announcement?')">
                                            <i class="bi bi-trash me-1"></i> Delete
                                        </a>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <nav class="mt-3">
                                <ul class="pagination justify-content-end pagination-container" 
                                    data-container="container-<?= strtolower($category) ?>"></ul>
                            </nav>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Sidebar - Right Column -->
        <div class="col-lg-4">
            <div class="sidebar-section">
                
                <!-- Statistics Card -->
                <div class="stats-card">
                    <h4><i class="bi bi-bar-chart me-2"></i>Announcement Statistics</h4>
                    <div class="stats-item">
                        <div class="stats-label">
                            <i class="bi bi-megaphone"></i>
                            <span>Total Announcements</span>
                        </div>
                        <div class="stats-value"><?= $totalAnnouncements ?></div>
                    </div>
                    <div class="stats-item">
                        <div class="stats-label">
                            <i class="bi bi-file-earmark-check"></i>
                            <span>Published</span>
                        </div>
                        <div class="stats-value"><?= $totalPublished ?></div>
                    </div>
                    <div class="stats-item">
                        <div class="stats-label">
                            <i class="bi bi-file-earmark"></i>
                            <span>Drafts</span>
                        </div>
                        <div class="stats-value"><?= $totalDrafts ?></div>
                    </div>
                    <div class="stats-item">
                        <div class="stats-label">
                            <i class="bi bi-eye"></i>
                            <span>Total Reads</span>
                        </div>
                        <div class="stats-value"><?= $totalReads ?></div>
                    </div>
                    <div class="stats-total">
                        <div class="stats-total-label">Active Status</div>
                        <div class="stats-total-value">
                            <span class="badge badge-<?= $tab ?>"><?= $tab ?></span>
                        </div>
                    </div>
                </div>

                <!-- CARD 1: Quick Stats -->
                <div class="sidebar-card">
                    <h4>Quick Stats</h4>
                    <div class="quick-stats-grid">
                        <div class="quick-stat">
                            <div class="quick-stat-icon primary">
                                <i class="bi bi-person"></i>
                            </div>
                            <div class="quick-stat-value"><?= $userDraftCount + $userPublishedCount ?></div>
                            <div class="quick-stat-label">Your Posts</div>
                        </div>
                        <div class="quick-stat">
                            <div class="quick-stat-icon success">
                                <i class="bi bi-check-circle"></i>
                            </div>
                            <div class="quick-stat-value"><?= $userPublishedCount ?></div>
                            <div class="quick-stat-label">Published</div>
                        </div>
                        <div class="quick-stat">
                            <div class="quick-stat-icon warning">
                                <i class="bi bi-file-earmark"></i>
                            </div>
                            <div class="quick-stat-value"><?= $userDraftCount ?></div>
                            <div class="quick-stat-label">Your Drafts</div>
                        </div>
                        <div class="quick-stat">
                            <div class="quick-stat-icon info">
                                <i class="bi bi-graph-up"></i>
                            </div>
                            <div class="quick-stat-value"><?= $avgReads ?></div>
                            <div class="quick-stat-label">Avg Reads</div>
                        </div>
                    </div>
                </div>

                <!-- CARD 2: Recent Activity -->
                <div class="sidebar-card">
                    <h4>Recent Activity</h4>
                    <?php if (!empty($recentAnnouncements)): ?>
                        <div class="recent-list">
                            <?php foreach ($recentAnnouncements as $recent): ?>
                            <div class="recent-item">
                                <div class="recent-icon <?= strtolower($recent['category']) ?>">
                                    <i class="bi bi-<?= 
                                        $recent['category'] == 'Announcement' ? 'megaphone' : 
                                        ($recent['category'] == 'Memo' ? 'file-text' : 'bell')
                                    ?>"></i>
                                </div>
                                <div class="recent-content">
                                    <div class="recent-title"><?= htmlspecialchars($recent['title']) ?></div>
                                    <div class="recent-meta">
                                        <span><i class="bi bi-calendar"></i> <?= $recent['short_date'] ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-clock"></i>
                            <p>No recent activity</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- CARD 3: Top Reads -->
                <div class="sidebar-card">
                    <h4>Top Reads</h4>
                    <?php if (!empty($topReadAnnouncements)): ?>
                        <div class="top-reads">
                            <?php foreach ($topReadAnnouncements as $topRead): ?>
                            <div class="top-read-item">
                                <div>
                                    <div class="read-title"><?= htmlspecialchars($topRead['title']) ?></div>
                                    <div class="read-meta">
                                        <span class="badge badge-<?= $topRead['category'] ?>"><?= $topRead['category'] ?></span>
                                    </div>
                                </div>
                                <div class="read-count">
                                    <i class="bi bi-eye"></i>
                                    <?= $topRead['read_count'] ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-eye"></i>
                            <p>No read data available</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Categories Overview -->
                <div class="sidebar-card">
                    <h3>Categories Overview</h3>
                    <?php if (!empty($grouped)): ?>
                        <div class="category-list">
                            <?php foreach ($grouped as $category => $items): ?>
                            <div class="category-item">
                                <div class="category-info">
                                    <span class="category-badge <?= strtolower($category) ?>"></span>
                                    <span class="category-name"><?= $category ?></span>
                                </div>
                                <span class="category-count"><?= count($items) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-tag"></i>
                            <p>No categories available</p>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- Modals -->

<!-- View Drafts Modal -->
<div class="modal fade" id="draftsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-file-earmark-text me-2"></i>
                    Draft Announcements
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            
            <div class="modal-body">
                <?php if (count($drafts) === 0): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-file-earmark-x display-4 mb-3"></i>
                        <p class="mb-0">No draft announcements available.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Category</th>
                                    <th>Created</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($drafts as $d): ?>
                                <tr>
                                    <td><?= htmlspecialchars($d['title']) ?></td>
                                    <td>
                                        <span class="badge badge-<?= $d['category'] ?>">
                                            <?= $d['category'] ?>
                                        </span>
                                    </td>
                                    <td><?= $d['formatted_date'] ?></td>
                                    <td>
                                        <button
                                            class="btn btn-sm btn-outline-primary editBtn"
                                            data-id="<?= $d['id'] ?>"
                                            data-title="<?= htmlspecialchars($d['title'], ENT_QUOTES) ?>"
                                            data-content="<?= htmlspecialchars($d['content'], ENT_QUOTES) ?>"
                                            data-category="<?= $d['category'] ?>"
                                            data-status="<?= $d['status'] ?>"
                                            data-bs-dismiss="modal"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editAnnouncementModal">
                                            <i class="bi bi-pencil me-1"></i> Edit
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Create Announcement Modal -->
<div class="modal fade" id="createAnnouncementModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data" id="announcementForm">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-megaphone me-2"></i>
                        Create Announcement
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="modal-grid">
                        <div class="form-group">
                            <label class="form-label">Title *</label>
                            <input class="form-control" name="title" placeholder="Enter announcement title" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Category *</label>
                            <select class="form-select" name="category" required>
                                <option value="">Select category</option>
                                <option value="Announcement">Announcement</option>
                                <option value="Memo">Memo</option>
                                <option value="Reminder">Reminder</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group mt-3">
                        <label class="form-label">Content *</label>
                        <textarea class="form-control" name="content" rows="5" placeholder="Enter announcement content" required></textarea>
                    </div>
                    
                    <div class="modal-grid mt-3">
                        <div class="form-group">
                            <label class="form-label">Image (Optional)</label>
                            <input type="file" name="image" class="form-control" accept="image/*">
                            <small class="text-muted">Max size: 5MB. Supported: JPG, PNG, GIF</small>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Document (Optional)</label>
                            <input type="file" name="document" class="form-control" accept=".pdf,.docx">
                            <small class="text-muted">Max size: 10MB. Supported: PDF, DOCX</small>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button name="publish_action" value="draft" class="btn btn-outline-secondary">
                        <i class="bi bi-file-earmark me-1"></i> Save as Draft
                    </button>
                    <button name="publish_action" value="publish" class="btn btn-primary">
                        <i class="bi bi-megaphone me-1"></i> Publish Now
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Announcement Modal -->
<div class="modal fade" id="editAnnouncementModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="edit_id" id="edit_id">
                
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil-square me-2"></i>
                        Edit Announcement
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body row">
                    <!-- Left: Form -->
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Title *</label>
                            <input class="form-control" name="title" id="edit_title" required>
                        </div>
                        
                        <div class="form-group mt-3">
                            <label class="form-label">Content *</label>
                            <textarea class="form-control" name="content" id="edit_content" rows="6" required></textarea>
                        </div>
                        
                        <div class="modal-grid mt-3">
                            <div class="form-group">
                                <label class="form-label">Category *</label>
                                <select class="form-select" name="category" id="edit_category" required>
                                    <option value="Announcement">Announcement</option>
                                    <option value="Memo">Memo</option>
                                    <option value="Reminder">Reminder</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" id="edit_status">
                                    <option value="Draft">Draft</option>
                                    <option value="Published">Published</option>
                                </select>
                                <small id="statusLockNote" class="text-danger d-none mt-1">
                                    <i class="bi bi-exclamation-triangle"></i> Published announcements cannot change status.
                                </small>
                            </div>
                        </div>
                        
                        <div class="modal-grid mt-3">
                            <div class="form-group">
                                <label class="form-label">Replace Image</label>
                                <input type="file" name="image" id="edit_image_input" class="form-control" accept="image/*">
                                <small class="text-muted">Leave empty to keep current image</small>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Replace Document</label>
                                <input type="file" name="document" id="edit_document_input" class="form-control" accept=".pdf,.docx">
                                <small class="text-muted">Leave empty to keep current document</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right: Live Preview -->
                    <div class="col-md-6">
                        <div class="preview-area">
                            <h6 class="preview-title" id="preview_title">Live Preview</h6>
                            
                            <div class="preview-meta">
                                <span>
                                    <i class="bi bi-tag"></i>
                                    <span class="badge" id="preview_category"></span>
                                </span>
                                <span>
                                    <i class="bi bi-circle"></i>
                                    Status: <span id="preview_status"></span>
                                </span>
                            </div>
                            
                            <!-- Image Preview -->
                            <div id="preview_image_container" class="mb-3" style="display:none;">
                                <img id="preview_image" class="img-fluid rounded">
                            </div>
                            
                            <!-- Content -->
                            <div id="preview_content" class="preview-content"></div>
                            
                            <!-- Document Preview -->
                            <div id="preview_document_container" class="document-preview" style="display:none;">
                                <h6>
                                    <i class="bi bi-paperclip me-1"></i>
                                    Attachment Preview
                                </h6>
                                <iframe
                                    id="preview_document_frame"
                                    style="width:100%; height:250px; border:1px solid #ddd; border-radius:4px;">
                                </iframe>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i> Update Announcement
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const ROWS_PER_PAGE = 5;

    // Initialize pagination for each category container
    document.querySelectorAll('.announcements-container').forEach(container => {
        const items = container.querySelectorAll('.announcement-item');
        const pagination = document.querySelector(`[data-container="${container.id}"]`);
        
        if (items.length === 0) {
            pagination.style.display = 'none';
            return;
        }

        let currentPage = 1;
        let filteredItems = Array.from(items);

        function renderItems() {
            items.forEach(item => item.style.display = 'none');
            
            const start = (currentPage - 1) * ROWS_PER_PAGE;
            const end = start + ROWS_PER_PAGE;
            
            filteredItems.slice(start, end).forEach(item => {
                item.style.display = 'block';
            });

            renderPagination();
        }

        function renderPagination() {
            pagination.innerHTML = '';
            const pageCount = Math.ceil(filteredItems.length / ROWS_PER_PAGE);
            
            if (pageCount <= 1) {
                pagination.style.display = 'none';
                return;
            }
            
            pagination.style.display = 'flex';

            for (let i = 1; i <= pageCount; i++) {
                const li = document.createElement('li');
                li.className = `page-item ${i === currentPage ? 'active' : ''}`;
                li.innerHTML = `<a class="page-link" href="#">${i}</a>`;
                
                li.onclick = (e) => {
                    e.preventDefault();
                    currentPage = i;
                    renderItems();
                };
                
                pagination.appendChild(li);
            }
        }

        // Search functionality
        const searchInput = document.getElementById('search');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const value = this.value.toLowerCase().trim();
                
                filteredItems = Array.from(items).filter(item => {
                    const searchText = item.dataset.search || '';
                    return searchText.includes(value);
                });
                
                currentPage = 1;
                renderItems();
            });
        }

        renderItems();
    });

    // Edit Modal Functionality
    const editModal = document.getElementById('editAnnouncementModal');
    
    document.querySelectorAll('.editBtn').forEach(btn => {
        btn.addEventListener('click', () => {
            // Set form values
            document.getElementById('edit_id').value = btn.dataset.id;
            document.getElementById('edit_title').value = btn.dataset.title;
            document.getElementById('edit_content').value = btn.dataset.content;
            document.getElementById('edit_category').value = btn.dataset.category;
            document.getElementById('edit_status').value = btn.dataset.status;

            // Status lock for published announcements
            const statusSelect = document.getElementById('edit_status');
            const statusLockNote = document.getElementById('statusLockNote');
            if (btn.dataset.status === 'Published') {
                statusSelect.disabled = true;
                statusLockNote.classList.remove('d-none');
            } else {
                statusSelect.disabled = false;
                statusLockNote.classList.add('d-none');
            }

            // Update preview
            updatePreview(btn);
        });
    });

    // Live preview updates
    document.getElementById('edit_title').addEventListener('input', (e) => {
        document.getElementById('preview_title').textContent = e.target.value || 'Live Preview';
    });

    document.getElementById('edit_content').addEventListener('input', (e) => {
        document.getElementById('preview_content').textContent = e.target.value;
    });

    document.getElementById('edit_category').addEventListener('change', (e) => {
        const badge = document.getElementById('preview_category');
        badge.textContent = e.target.value;
        badge.className = `badge badge-${e.target.value}`;
    });

    document.getElementById('edit_status').addEventListener('change', (e) => {
        document.getElementById('preview_status').textContent = e.target.value;
    });

    // Image preview
    document.getElementById('edit_image_input').addEventListener('change', function(e) {
        const file = e.target.files[0];
        const preview = document.getElementById('preview_image');
        const container = document.getElementById('preview_image_container');
        
        if (file) {
            const reader = new FileReader();
            reader.onload = (event) => {
                preview.src = event.target.result;
                container.style.display = 'block';
            };
            reader.readAsDataURL(file);
        } else {
            container.style.display = 'none';
        }
    });

    // Document preview
    document.getElementById('edit_document_input').addEventListener('change', function(e) {
        const file = e.target.files[0];
        const container = document.getElementById('preview_document_container');
        
        if (file) {
            const reader = new FileReader();
            reader.onload = (event) => {
                const frame = document.getElementById('preview_document_frame');
                frame.src = event.target.result;
                container.style.display = 'block';
            };
            reader.readAsDataURL(file);
        } else {
            container.style.display = 'none';
        }
    });

    function updatePreview(btn) {
        // Title
        document.getElementById('preview_title').textContent = btn.dataset.title || 'Live Preview';
        
        // Category badge
        const badge = document.getElementById('preview_category');
        badge.textContent = btn.dataset.category;
        badge.className = `badge badge-${btn.dataset.category}`;
        
        // Status
        document.getElementById('preview_status').textContent = btn.dataset.status;
        
        // Content
        document.getElementById('preview_content').textContent = btn.dataset.content;
        
        // Image
        const imageContainer = document.getElementById('preview_image_container');
        const previewImage = document.getElementById('preview_image');
        if (btn.dataset.image) {
            previewImage.src = btn.dataset.image;
            imageContainer.style.display = 'block';
        } else {
            imageContainer.style.display = 'none';
        }
        
        // Document
        const docContainer = document.getElementById('preview_document_container');
        const docFrame = document.getElementById('preview_document_frame');
        if (btn.dataset.document) {
            if (btn.dataset.document.includes('application/pdf')) {
                docFrame.src = btn.dataset.document;
            } else {
                docFrame.src = `https://docs.google.com/gview?embedded=1&url=${encodeURIComponent(btn.dataset.document)}`;
            }
            docContainer.style.display = 'block';
        } else {
            docContainer.style.display = 'none';
        }
    }

    // Modal reset on close
    const modals = ['createAnnouncementModal', 'editAnnouncementModal', 'draftsModal'];
    modals.forEach(modalId => {
        const modal = document.getElementById(modalId);
        modal.addEventListener('hidden.bs.modal', () => {
            if (modalId === 'createAnnouncementModal') {
                document.getElementById('announcementForm').reset();
            }
        });
    });

    // Add hover effects to sidebar cards
    const sidebarCards = document.querySelectorAll('.sidebar-card');
    sidebarCards.forEach(card => {
        card.addEventListener('mouseenter', () => {
            card.style.transform = 'translateY(-3px)';
        });
        
        card.addEventListener('mouseleave', () => {
            card.style.transform = 'translateY(0)';
        });
    });

    // Add hover effects to quick stats
    const quickStats = document.querySelectorAll('.quick-stat');
    quickStats.forEach(stat => {
        stat.addEventListener('mouseenter', () => {
            stat.style.transform = 'translateY(-3px) scale(1.02)';
        });
        
        stat.addEventListener('mouseleave', () => {
            stat.style.transform = 'translateY(0) scale(1)';
        });
    });
});
</script>

</body>
</html>