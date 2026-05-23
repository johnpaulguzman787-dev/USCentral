    <?php
    require_once "config/db.php";

    /* ==========================
    CREATE ATTENDANCE (DOCX)
    ========================== */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_attendance'])) {

        require_once __DIR__ . '/vendor/autoload.php';
        require_once __DIR__ . '/docx/attendance_template.php';

        // ✅ GET DATA FROM FORM
        $title  = $_POST['title'] ?? 'Attendance Sheet';
        $event  = $_POST['event'] ?? '';
        $date   = $_POST['date'] ?? '';
        $venue  = $_POST['venue'] ?? '';
        $cols   = $_POST['columns'] ?? [];

        // ✅ BUILD DOCX USING TEMPLATE
        $phpWord = buildAttendanceDocx([
            'title'   => $title,
            'event'   => $event,
            'date'    => $date,
            'venue'   => $venue,
            'columns' => $cols
        ]);

        // Save DOCX
        $fileName = 'Attendance_' . time() . '.docx';
        $tempFile = tempnam(sys_get_temp_dir(), 'docx');

        \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007')
            ->save($tempFile);

        $fileData = file_get_contents($tempFile);
        unlink($tempFile);

        $fileSize = strlen($fileData);

        $stmt = $conn->prepare("
            INSERT INTO attendance_files (title, file_name, file_size, file_data)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("ssis", $title, $fileName, $fileSize, $fileData);
        $stmt->send_long_data(3, $fileData);
        $stmt->execute();

        header("Location: usc-management.php?attendance_created=1");
        exit;
    }



    /* ==========================
    DOWNLOAD ATTENDANCE
    ========================== */
    if (isset($_GET['download_attendance'])) {

        $id = (int)$_GET['download_attendance'];

        $stmt = $conn->prepare("
            SELECT file_name, file_data
            FROM attendance_files
            WHERE id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $file = $stmt->get_result()->fetch_assoc();

        if ($file) {
            header("Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document");
            header("Content-Disposition: attachment; filename=\"{$file['file_name']}\"");
            echo $file['file_data'];
            exit;
        }
    }

    /* ==========================
    DELETE ATTENDANCE
    ========================== */
    if (isset($_GET['delete_attendance'])) {

        $id = (int)$_GET['delete_attendance'];
        $stmt = $conn->prepare("DELETE FROM attendance_files WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();

        header("Location: usc-management.php?attendance_deleted=1");
        exit;
    }

    // Fetch attendance list
    $attendanceFiles = $conn->query("
        SELECT id, title, file_name, file_size, created_at
        FROM attendance_files
        ORDER BY created_at DESC
    ");

    /* ==========================
    PREVIEW PDF
    ========================== */
    if (isset($_GET['preview_file'])) {

        $id = (int)$_GET['preview_file'];

        $stmt = $conn->prepare("
            SELECT file_name, file_data
            FROM file_management
            WHERE id = ? AND file_type = 'pdf'
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $file = $stmt->get_result()->fetch_assoc();

        if ($file) {
            header("Content-Type: application/pdf");
            header("Content-Disposition: inline; filename=\"".$file['file_name']."\"");
            echo $file['file_data'];
            exit;
        }
    }


    /* ==========================
    DELETE FILE
    ========================== */
    if (isset($_GET['delete_file'])) {

        $id = (int)$_GET['delete_file'];

        $stmt = $conn->prepare("DELETE FROM file_management WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();

        header("Location: usc-management.php?deleted=1");
        exit;
    }


    /* ==========================
    FILE MANAGEMENT (BLOB)
    ========================== */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_file'])) {

        if (!empty($_FILES['file']['name'])) {

            $allowed = ['pdf', 'docx'];
            $fileName = $_FILES['file']['name'];
            $fileSize = $_FILES['file']['size'];
            $tmpName  = $_FILES['file']['tmp_name'];
            $fileType = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if (!in_array($fileType, $allowed)) {
                die("Invalid file type");
            }

            $fileData = file_get_contents($tmpName);

            $stmt = $conn->prepare("
                INSERT INTO file_management
                (file_name, file_type, file_size, file_data)
                VALUES (?, ?, ?, ?)
            ");
    $stmt = $conn->prepare("
        INSERT INTO file_management
        (file_name, file_type, file_size, file_data)
        VALUES (?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "ssis",
        $fileName,
        $fileType,
        $fileSize,
        $fileData
    );

    $stmt->execute();


            header("Location: usc-management.php?file_uploaded=1");
            exit;
        }
    }


    /* ==========================
    DASHBOARD COUNTS
    ========================== */

    $annCount = $conn->query("SELECT COUNT(*) total FROM announcements")->fetch_assoc()['total'] ?? 0;
    $pendingAnn = $conn->query("SELECT COUNT(*) total FROM announcements WHERE status='Pending'")->fetch_assoc()['total'] ?? 0;
    $eventCount = $conn->query("SELECT COUNT(*) total FROM events")->fetch_assoc()['total'] ?? 0;

    $announcements = $conn->query("
        SELECT id, title, category, status, published_at 
        FROM announcements 
        ORDER BY created_at DESC 
        LIMIT 5
    ");

    $events = $conn->query("
        SELECT title, start_date 
        FROM events 
        ORDER BY start_date ASC 
        LIMIT 5
    ");

    $fileFilter = $_GET['type'] ?? 'all';

$sql = "
    SELECT id, file_name, file_type, file_size, uploaded_at
    FROM file_management
";

if ($fileFilter === 'pdf') {
    $sql .= " WHERE file_type = 'pdf'";
} elseif ($fileFilter === 'docx') {
    $sql .= " WHERE file_type = 'docx'";
}

$sql .= " ORDER BY uploaded_at DESC";

$files = $conn->query($sql);


    if (isset($_GET['download_file'])) {

        $id = (int)$_GET['download_file'];

        $stmt = $conn->prepare("
            SELECT file_name, file_type, file_data
            FROM file_management
            WHERE id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $file = $stmt->get_result()->fetch_assoc();

        if ($file) {
            header("Content-Type: application/octet-stream");
            header("Content-Disposition: attachment; filename=\"".$file['file_name']."\"");
            echo $file['file_data'];
            exit;
        }
    }

    if (isset($_GET['view_announcement'])) {

    $id = (int)$_GET['view_announcement'];

    $stmt = $conn->prepare("
        SELECT title, category, content, status
        FROM announcements
        WHERE id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    $data = $stmt->get_result()->fetch_assoc();

    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

    ?>

 <!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>USC Management</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

<style>
    .content {
        margin-left: 280px;
        padding: 25px;
        min-height: 100vh;
        transition: all 0.3s ease;
    }

    @media (max-width: 992px) {
        .content {
            margin-left: 0;
            padding: 15px;
        }
    }

    body {
        background: #f4f6fb;
        font-family: 'Poppins', sans-serif;
    }
    
    .card-box {
        border-radius: 16px;
        padding: 20px;
        color: #fff;
    }
    
    .bg-blue { background: linear-gradient(135deg,#5a8dee,#3b6edc); }
    .bg-orange { background: linear-gradient(135deg,#ffa94d,#ff922b); }
    .bg-green { background: linear-gradient(135deg,#63e6be,#38d9a9); }
    .bg-red { background: linear-gradient(135deg,#ff8787,#fa5252); }

    .table td, .table th {
        vertical-align: middle;
    }
    
    .badge {
        border-radius: 12px;
    }
    
    .left-column {
        width: 66.666%;
    }
    
    .right-column {
        width: 33.333%;
    }
    
    @media (max-width: 768px) {
        .left-column,
        .right-column {
            width: 100%;
        }
    }
</style>
</head>

<body>

<?php include 'partials/sidenav.php'; ?>

<div class="content">
    <!-- HEADER -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold">USC Management</h3>
            <small class="text-muted">Unified Student Council Management</small>
        </div>
    </div>

    <!-- STATS -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card-box bg-blue">
                <h6>Announcements</h6>
                <h2><?= $annCount ?></h2>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-box bg-orange">
                <h6>Pending Announcements</h6>
                <h2><?= $pendingAnn ?></h2>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-box bg-green">
                <h6>Upcoming Events</h6>
                <h2><?= $eventCount ?></h2>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-box bg-red">
                <h6>System Status</h6>
                <h2>Active</h2>
            </div>
        </div>
    </div>

    <!-- MAIN CONTENT AREA - SPLIT INTO LEFT AND RIGHT COLUMNS -->
    <div class="row">
        <!-- LEFT COLUMN (8) -->
        <div class="col-lg-8 left-column">
            <div class="d-flex flex-column gap-4">
                <!-- FILE MANAGEMENT CARD -->
                <div class="card shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center gap-2">
                            <strong>File Management</strong>
                            <a href="usc-management.php"
                               class="btn btn-sm <?= ($fileFilter=='all'?'btn-dark':'btn-outline-dark') ?>">
                                All
                            </a>
                            <a href="usc-management.php?type=pdf"
                               class="btn btn-sm <?= ($fileFilter=='pdf'?'btn-danger':'btn-outline-danger') ?>">
                                PDF
                            </a>
                            <a href="usc-management.php?type=docx"
                               class="btn btn-sm <?= ($fileFilter=='docx'?'btn-primary':'btn-outline-primary') ?>">
                                DOCX
                            </a>
                        </div>
                        <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#uploadFileModal">
                            + Upload Files
                        </button>
                    </div>

                    <div class="card-body p-0">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>File Name</th>
                                    <th>Type</th>
                                    <th>Date Uploaded</th>
                                    <th>Size</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($files->num_rows > 0): ?>
                                    <?php while($f = $files->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($f['file_name']) ?></td>
                                        <td><?= strtoupper($f['file_type']) ?></td>
                                        <td><?= date("M d, Y", strtotime($f['uploaded_at'])) ?></td>
                                        <td><?= round($f['file_size']/1024, 2) ?> KB</td>
                                        <td class="d-flex gap-1">
                                            <?php if ($f['file_type'] === 'pdf'): ?>
                                                <button
                                                    class="btn btn-sm btn-outline-secondary"
                                                    onclick="previewPDF(<?= $f['id'] ?>)">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                            <?php endif; ?>
                                            <a href="?download_file=<?= $f['id'] ?>"
                                            class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-download"></i>
                                            </a>
                                            <a href="?delete_file=<?= $f['id'] ?>"
                                            class="btn btn-sm btn-outline-danger"
                                            onclick="return confirm('Delete this file?')">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted">No files uploaded</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ANNOUNCEMENTS CARD -->
                <div class="card shadow-sm">
                    <div class="card-header d-flex justify-content-between">
                        <strong>Announcements</strong>
                        <a href="announcement.php" class="btn btn-sm btn-warning">+ Create</a>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Title</th>
                                    <th>Category</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($announcements->num_rows > 0): ?>
                                    <?php while($row = $announcements->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['title']) ?></td>
                                        <td><?= $row['category'] ?></td>
                                        <td>
                                            <span class="badge bg-<?= $row['status']=='Published'?'success':'warning' ?>">
                                                <?= $row['status'] ?>
                                            </span>
                                        </td>
                                        <td>
    <button
        class="btn btn-sm btn-outline-primary"
        onclick="viewAnnouncement(<?= $row['id'] ?>)">
        <i class="bi bi-eye"></i> View
    </button>
</td>

                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="text-center text-muted">No announcements</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN (4) -->
        <div class="col-lg-4 right-column">
            <div class="d-flex flex-column gap-4">
                <!-- UPCOMING EVENTS CARD -->
                <div class="card shadow-sm">
                    <div class="card-header d-flex justify-content-between">
                        <strong>Upcoming Events</strong>
                        <a href="event-calendar.php" class="btn btn-sm btn-primary">View</a>
                    </div>
                    <div class="card-body">
                        <?php if ($events->num_rows > 0): ?>
                            <?php while($e = $events->fetch_assoc()): ?>
                                <div class="d-flex justify-content-between border-bottom py-2">
                                    <span><?= htmlspecialchars($e['title']) ?></span>
                                    <small class="text-muted"><?= $e['start_date'] ?></small>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <p class="text-muted text-center">No upcoming events</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ATTENDANCE MAKER CARD -->
                <div class="card shadow-sm">
                    <div class="card-header d-flex justify-content-between">
                        <strong>Attendance Maker</strong>
                        <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#attendanceModal">
                            + Create Attendance
                        </button>
                    </div>

                    <div class="card-body p-0">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Title</th>
                                    <th>Date Created</th>
                                    <th>Size</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($attendanceFiles->num_rows > 0): ?>
                                    <?php while($a = $attendanceFiles->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($a['title']) ?></td>
                                        <td><?= date("M d, Y", strtotime($a['created_at'])) ?></td>
                                        <td><?= round($a['file_size']/1024,2) ?> KB</td>
                                        <td class="d-flex gap-1">
                                            <a href="?download_attendance=<?= $a['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-download"></i>
                                            </a>
                                            <a href="?delete_attendance=<?= $a['id'] ?>"
                                            class="btn btn-sm btn-outline-danger"
                                            onclick="return confirm('Delete this attendance file?')">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted">No attendance created</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- PDF PREVIEW MODAL -->
    <div class="modal fade" id="pdfPreviewModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">PDF Preview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0" style="height:80vh;">
                    <iframe
                        id="pdfFrame"
                        src=""
                        style="width:100%; height:100%; border:none;">
                    </iframe>
                </div>
            </div>
        </div>
    </div>

    <!-- VIEW ANNOUNCEMENT MODAL -->
<div class="modal fade" id="viewAnnouncementModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="annTitle">Announcement</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-1" id="annCategory"></p>
                <hr>
                <div id="annContent" style="white-space: pre-wrap;"></div>
            </div>
            <div class="modal-footer">
                <span class="badge" id="annStatus"></span>
                <button class="btn btn-secondary" data-bs-dismiss="modal">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>


    <!-- UPLOAD FILE MODAL -->
    <div class="modal fade" id="uploadFileModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Upload File</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="upload_file">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Select File</label>
                            <input type="file" name="file" class="form-control" required
                                accept=".pdf,.docx">
                            <small class="text-muted">
                                Allowed: PDF, DOCX
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-warning">
                            Upload
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ATTENDANCE MODAL -->
    <div class="modal fade" id="attendanceModal">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="create_attendance">
                    <div class="modal-header">
                        <h5>Create Attendance</h5>
                        <button class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-2">
                            <label>Attendance Title</label>
                            <input type="text" name="title" class="form-control" required>
                        </div>
                        <div class="row">
                            <div class="col">
                                <label>Event</label>
                                <input type="text" name="event" class="form-control">
                            </div>
                            <div class="col">
                                <label>Date</label>
                                <input type="date" name="date" class="form-control">
                            </div>
                        </div>
                        <div class="mb-2 mt-2">
                            <label>Venue</label>
                            <input type="text" name="venue" class="form-control">
                        </div>
                        <hr>
                        <label class="fw-bold">Table Columns</label>
                        <div id="columnsWrapper">
                            <div class="input-group mb-2">
                                <input
                                    type="text"
                                    name="columns[]"
                                    class="form-control"
                                    placeholder="Column name (e.g. Student Name)"
                                    required
                                >
                                <button
                                    type="button"
                                    class="btn btn-outline-danger"
                                    onclick="removeColumn(this)">
                                    ✕
                                </button>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-primary mt-2"
                            onclick="addColumn()">
                            + Add Column
                        </button>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button class="btn btn-warning">Generate DOCX</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function previewPDF(id) {
        const frame = document.getElementById('pdfFrame');
        frame.src = '?preview_file=' + id;

        const modal = new bootstrap.Modal(
            document.getElementById('pdfPreviewModal')
        );
        modal.show();
    }

    function addColumn() {
        const wrapper = document.getElementById('columnsWrapper');

        const div = document.createElement('div');
        div.className = 'input-group mb-2';

        div.innerHTML = `
            <input
                type="text"
                name="columns[]"
                class="form-control"
                placeholder="Column name"
                required
            >
            <button
                type="button"
                class="btn btn-outline-danger"
                onclick="removeColumn(this)">
                ✕
            </button>
        `;

        wrapper.appendChild(div);
    }
                                    
    function removeColumn(btn) {
        btn.parentElement.remove();
    }

    function viewAnnouncement(id) {
    fetch('?view_announcement=' + id)
        .then(res => res.json())
        .then(data => {

            document.getElementById('annTitle').innerText = data.title;
            document.getElementById('annCategory').innerText = data.category;
            document.getElementById('annContent').innerText = data.content;

            const statusBadge = document.getElementById('annStatus');
            statusBadge.innerText = data.status;
            statusBadge.className =
                'badge ' + (data.status === 'Published'
                    ? 'bg-success'
                    : 'bg-warning');

            const modal = new bootstrap.Modal(
                document.getElementById('viewAnnouncementModal')
            );
            modal.show();
        });
}

    </script>
</div>
</body>
</html>