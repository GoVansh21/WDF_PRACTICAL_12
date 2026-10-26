<?php
require_once "auth.php";

$message = "";
$error = "";
$editing = null;

$uploadDir = __DIR__ . "/uploads/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function savePoster($fieldName, $uploadDir) {
    if (!isset($_FILES[$fieldName]) ||
        $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES[$fieldName];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Poster upload failed.");
    }

    if ($file['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException("Poster must be 2 MB or smaller.");
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];

    if (!isset($allowed[$mime]) ||
        @getimagesize($file['tmp_name']) === false) {
        throw new RuntimeException(
            "Only valid JPG, PNG, or WebP images are allowed."
        );
    }

    $filename = bin2hex(random_bytes(16)) . "." . $allowed[$mime];
    $destination = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException("Could not save the uploaded poster.");
    }

    return $filename;
}

function removePoster($filename, $uploadDir) {
    if ($filename && basename($filename) === $filename) {
        $path = $uploadDir . $filename;
        if (is_file($path)) {
            unlink($path);
        }
    }
}

function validDate($date) {
    $d = DateTime::createFromFormat('!Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

function redirectWithMessage($text) {
    $_SESSION['flash_message'] = $text;
    header("Location: index.php");
    exit;
}

if (!empty($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}

$categories = [
    "Workshop", "Seminar", "Conference", "Cultural", "Sports", "Other"
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = "Invalid request token. Refresh and try again.";
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'save') {
                $id = (int)($_POST['id'] ?? 0);
                $title = trim($_POST['title'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $eventDate = trim($_POST['event_date'] ?? '');
                $venue = trim($_POST['venue'] ?? '');
                $category = trim($_POST['category'] ?? '');
                $status = trim($_POST['status'] ?? 'Open');

                if ($title === '' || strlen($title) > 150 ||
                    $description === '' || $venue === '' ||
                    strlen($venue) > 150 ||
                    !validDate($eventDate) ||
                    !in_array($category, $categories, true) ||
                    !in_array($status, ['Open', 'Closed'], true)) {
                    throw new RuntimeException(
                        "Please enter valid values in all required fields."
                    );
                }

                $oldPoster = null;
                if ($id > 0) {
                    $check = $conn->prepare(
                        "SELECT poster FROM events WHERE id = ?"
                    );
                    $check->bind_param("i", $id);
                    $check->execute();
                    $existing = $check->get_result()->fetch_assoc();

                    if (!$existing) {
                        throw new RuntimeException("Event not found.");
                    }
                    $oldPoster = $existing['poster'];
                }

                $newPoster = savePoster('poster', $uploadDir);
                $poster = $newPoster ?? $oldPoster;

                if ($id > 0) {
                    $stmt = $conn->prepare(
                        "UPDATE events
                         SET title=?, description=?, event_date=?,
                             venue=?, category=?, status=?, poster=?
                         WHERE id=?"
                    );
                    $stmt->bind_param(
                        "sssssssi",
                        $title, $description, $eventDate,
                        $venue, $category, $status, $poster, $id
                    );
                    $stmt->execute();

                    if ($newPoster !== null) {
                        removePoster($oldPoster, $uploadDir);
                    }
                    redirectWithMessage("Event updated successfully.");
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO events
                         (title, description, event_date, venue,
                          category, status, poster)
                         VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->bind_param(
                        "sssssss",
                        $title, $description, $eventDate,
                        $venue, $category, $status, $poster
                    );
                    $stmt->execute();

                    redirectWithMessage("Event added successfully.");
                }
            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);

                if ($id < 1) {
                    throw new RuntimeException("Invalid event ID.");
                }

                $stmt = $conn->prepare(
                    "SELECT poster FROM events WHERE id = ?"
                );
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $event = $stmt->get_result()->fetch_assoc();

                if (!$event) {
                    throw new RuntimeException("Event not found.");
                }

                $stmt = $conn->prepare("DELETE FROM events WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();

                removePoster($event['poster'], $uploadDir);
                redirectWithMessage("Event deleted successfully.");
            }
        } catch (Throwable $e) {
            $error = $e instanceof RuntimeException
                ? $e->getMessage()
                : "An unexpected error occurred. Please check the server logs.";
        }
    }
}

if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];

    if ($id > 0) {
        $stmt = $conn->prepare("SELECT * FROM events WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editing = $stmt->get_result()->fetch_assoc();

        if (!$editing) {
            $error = "Event not found.";
        }
    }
}

$search = trim($_GET['search'] ?? '');
$filterCategory = trim($_GET['category'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');

$sql = "SELECT * FROM events WHERE 1=1";
$types = "";
$params = [];

if ($search !== '') {
    $sql .= " AND (title LIKE ? OR venue LIKE ? OR description LIKE ?)";
    $like = "%" . $search . "%";
    $types .= "sss";
    array_push($params, $like, $like, $like);
}

if (in_array($filterCategory, $categories, true)) {
    $sql .= " AND category = ?";
    $types .= "s";
    $params[] = $filterCategory;
}

if (in_array($filterStatus, ['Open', 'Closed'], true)) {
    $sql .= " AND status = ?";
    $types .= "s";
    $params[] = $filterStatus;
}

$sql .= " ORDER BY event_date ASC, id DESC";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$events = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Event Management System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
      rel="stylesheet">
<style>
body { background: #f4f6f9; }
.poster { width: 90px; height: 65px; object-fit: cover; }
.description { max-width: 260px; white-space: normal; }
</style>
</head>
<body>
<nav class="navbar navbar-dark bg-primary">
    <div class="container">
        <span class="navbar-brand">Event Management System</span>
        <div class="text-white">
            Welcome, <?= h($_SESSION['admin_username'] ?? 'Admin') ?>
            <a href="logout.php" class="btn btn-light btn-sm ms-2">Logout</a>
        </div>
    </div>
</nav>

<div class="container py-4">
    <h2 class="mb-4">Manage Events</h2>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-header">
            <strong><?= $editing ? 'Edit Event' : 'Add New Event' ?></strong>
        </div>
        <div class="card-body">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token"
                       value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id"
                       value="<?= h($editing['id'] ?? 0) ?>">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Event Title *</label>
                        <input class="form-control" name="title" maxlength="150"
                               required value="<?= h($editing['title'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Event Date *</label>
                        <input type="date" class="form-control" name="event_date"
                               required value="<?= h($editing['event_date'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Venue *</label>
                        <input class="form-control" name="venue" maxlength="150"
                               required value="<?= h($editing['venue'] ?? '') ?>">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Category *</label>
                        <select class="form-select" name="category" required>
                            <option value="">Select category</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= h($cat) ?>"
                                    <?= (($editing['category'] ?? '') === $cat)
                                        ? 'selected' : '' ?>>
                                    <?= h($cat) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Status *</label>
                        <select class="form-select" name="status" required>
                            <option value="Open"
                                <?= (($editing['status'] ?? 'Open') === 'Open')
                                    ? 'selected' : '' ?>>Open</option>
                            <option value="Closed"
                                <?= (($editing['status'] ?? '') === 'Closed')
                                    ? 'selected' : '' ?>>Closed</option>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Description *</label>
                        <textarea class="form-control" name="description"
                                  rows="3" required><?= h($editing['description'] ?? '') ?></textarea>
                    </div>

                    <div class="col-md-8">
                        <label class="form-label">Poster (JPG, PNG, WebP; max 2 MB)</label>
                        <input type="file" class="form-control" name="poster"
                               accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                        <?php if (!empty($editing['poster'])): ?>
                            <small class="text-muted">Current poster:
                                <?= h($editing['poster']) ?>. Leave empty to keep it.
                            </small>
                        <?php endif; ?>
                    </div>

                    <div class="col-12">
                        <button class="btn btn-primary" type="submit">
                            <?= $editing ? 'Update Event' : 'Add Event' ?>
                        </button>
                        <?php if ($editing): ?>
                            <a href="index.php" class="btn btn-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header"><strong>Event List</strong></div>
        <div class="card-body">
            <form method="get" class="row g-2 mb-3">
                <div class="col-md-5">
                    <input class="form-control" name="search"
                           placeholder="Search title, venue or description"
                           value="<?= h($search) ?>">
                </div>
                <div class="col-md-3">
                    <select class="form-select" name="category">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= h($cat) ?>"
                                <?= $filterCategory === $cat ? 'selected' : '' ?>>
                                <?= h($cat) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select class="form-select" name="status">
                        <option value="">All Statuses</option>
                        <option value="Open"
                            <?= $filterStatus === 'Open' ? 'selected' : '' ?>>Open</option>
                        <option value="Closed"
                            <?= $filterStatus === 'Closed' ? 'selected' : '' ?>>Closed</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button class="btn btn-primary" type="submit">Search</button>
                    <a class="btn btn-outline-secondary" href="index.php">Reset</a>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Poster</th>
                        <th>Title / Description</th>
                        <th>Date</th>
                        <th>Venue</th>
                        <th>Category</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($events->num_rows === 0): ?>
                        <tr><td colspan="8" class="text-center">
                            No events found.
                        </td></tr>
                    <?php else: ?>
                        <?php while ($event = $events->fetch_assoc()): ?>
                            <tr>
                                <td><?= h($event['id']) ?></td>
                                <td>
                                    <?php if ($event['poster']): ?>
                                        <img class="poster"
                                             src="uploads/<?= rawurlencode($event['poster']) ?>"
                                             alt="Event poster">
                                    <?php else: ?>
                                        <span class="text-muted">No poster</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= h($event['title']) ?></strong>
                                    <div class="description small text-muted">
                                        <?= h($event['description']) ?>
                                    </div>
                                </td>
                                <td><?= h($event['event_date']) ?></td>
                                <td><?= h($event['venue']) ?></td>
                                <td><?= h($event['category']) ?></td>
                                <td>
                                    <span class="badge <?= $event['status'] === 'Open'
                                        ? 'bg-success' : 'bg-secondary' ?>">
                                        <?= h($event['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <a class="btn btn-warning btn-sm mb-1"
                                       href="index.php?edit=<?= (int)$event['id'] ?>">
                                        Edit
                                    </a>
                                    <form method="post" class="d-inline"
                                          onsubmit="return confirm('Delete this event?');">
                                        <input type="hidden" name="csrf_token"
                                               value="<?= h($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id"
                                               value="<?= (int)$event['id'] ?>">
                                        <button class="btn btn-danger btn-sm mb-1"
                                                type="submit">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>