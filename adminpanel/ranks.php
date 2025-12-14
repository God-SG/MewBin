<?php
session_start();
include('../database.php');
include_once(__DIR__ . '/admin_2fa_gate.php');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['login_token']) || empty($_SESSION['login_token'])) {
    header('Location: /login');
    exit();
}

$loginToken = $_SESSION['login_token'];
$query = "SELECT username, rank FROM users WHERE login_token = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $loginToken);
$stmt->execute();
$result = $stmt->get_result();
$userData = $result->fetch_assoc();

if (!$userData || !in_array($userData['rank'], ['Admin', 'Manager', 'Founder'])) {
    header('Location: /login');
    exit();
}

$username = $userData['username'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        empty($_SESSION['csrf_token']) ||
        empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }

    $successMessage = null;
    $errorMessage = null;

    function handleRankGifUpload($fileInputName) {
        if (!isset($_FILES[$fileInputName]) || $_FILES[$fileInputName]['error'] !== UPLOAD_ERR_OK) {
            return null;
        }
        
        $file = $_FILES[$fileInputName];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['gif', 'png', 'jpg', 'jpeg', 'webp'];
        
        if (!in_array($ext, $allowed)) {
            return null;
        }
        
        // Include R2 helper
        require_once __DIR__ . '/../includes/r2-helper.php';
        
        // Generate a unique file key for R2
        $fileKey = 'ranks/' . uniqid('rank_', true) . '.' . $ext;
        
        // Upload to R2
        $r2 = R2Helper::getInstance();
        $publicUrl = $r2->uploadFile(
            $file['tmp_name'],
            $fileKey,
            mime_content_type($file['tmp_name'])
        );
        
        return $publicUrl ? $fileKey : null;
    }

    if (isset($_POST['delete_rank_id']) && !empty($_POST['delete_rank_id'])) {
        $ids = $_POST['delete_rank_id'];
        if (!is_array($ids)) $ids = [$ids];
        $allOk = true;
        foreach ($ids as $id) {
            if (is_numeric($id)) {
                $stmt = $conn->prepare("SELECT rank_name FROM rank WHERE rank_id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                $rankRow = $result->fetch_assoc();
                $oldRankName = $rankRow ? $rankRow['rank_name'] : null;
                $stmt->close();

                $stmt = $conn->prepare("DELETE FROM rank WHERE rank_id = ?");
                $stmt->bind_param("i", $id);
                if (!$stmt->execute()) {
                    $allOk = false;
                }
                $stmt->close();


            }
        }
        if (
            isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
        ) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $allOk]);
            exit();
        }
        header("Location: ranks.php?deleted=1");
        exit();
    }
    elseif (
        isset($_POST['rank_id'], $_POST['rank_name'], $_POST['rankOrder']) &&
        is_array($_POST['rank_id']) && is_array($_POST['rank_name']) && is_array($_POST['rankOrder'])
    ) {
        $allOk = true;
        foreach ($_POST['rank_name'] as $index => $name) {
            if (isset($_POST['rank_id'][$index]) && is_numeric($_POST['rank_id'][$index])) {
                $id = intval($_POST['rank_id'][$index]);
                $name = htmlspecialchars(trim($name));
                $tableColor = htmlspecialchars(trim($_POST['tableColor'][$index] ?? ''));
                $tableHoverColor = htmlspecialchars(trim($_POST['tableHoverColor'][$index] ?? ''));
                $rankColor = htmlspecialchars(trim($_POST['rankColor'][$index] ?? ''));
                $rankGif = htmlspecialchars(trim($_POST['rankGif'][$index] ?? ''));
                $rankGifFileInput = "rankGifFile_$index";
                if (!empty($_FILES[$rankGifFileInput]['name'])) {
                    $uploaded = handleRankGifUpload($rankGifFileInput);
                    if ($uploaded) $rankGif = $uploaded;
                }
                $rankOrder = intval($_POST['rankOrder'][$index]);
                $rankTag = htmlspecialchars(trim($_POST['rankTag'][$index] ?? ''));

                $stmt = $conn->prepare("SELECT rank_name FROM rank WHERE rank_id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                $oldRow = $result->fetch_assoc();
                $oldRankName = $oldRow ? $oldRow['rank_name'] : null;
                $stmt->close();

                $stmt = $conn->prepare("UPDATE rank SET rank_name=?, tableColor=?, tableHoverColor=?, rankColor=?, rankGif=?, rankOrder=?, rankTag=? WHERE rank_id=?");
                if (!$stmt) {
                    $allOk = false;
                    continue;
                }
                $stmt->bind_param("sssssssi", $name, $tableColor, $tableHoverColor, $rankColor, $rankGif, $rankOrder, $rankTag, $id);
                if (!$stmt->execute()) {
                    $allOk = false;
                }
                $stmt->close();

                if ($oldRankName && $oldRankName !== $name) {
                    $stmt = $conn->prepare("UPDATE users SET rank = ? WHERE rank = ?");
                    $stmt->bind_param("ss", $name, $oldRankName);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
        if (
            isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
        ) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $allOk]);
            exit();
        }
        if ($allOk) {
            $successMessage = "All ranks updated successfully!";
        } else {
            $errorMessage = "Failed to update some ranks.";
        }
    }
    elseif (
        isset($_POST['rank_name'], $_POST['rankOrder']) &&
        !is_array($_POST['rank_name']) && !is_array($_POST['rankOrder'])
    ) {
        $name = htmlspecialchars(trim($_POST['rank_name']));
        $tableColor = htmlspecialchars(trim($_POST['tableColor'] ?? ''));
        $tableHoverColor = htmlspecialchars(trim($_POST['tableHoverColor'] ?? ''));
        $rankColor = htmlspecialchars(trim($_POST['rankColor'] ?? ''));
        $rankGif = htmlspecialchars(trim($_POST['rankGif'] ?? ''));
        if (!empty($_FILES['rankGifFile']['name'])) {
            $uploaded = handleRankGifUpload('rankGifFile');
            if ($uploaded) $rankGif = $uploaded;
        }
        $rankOrder = intval($_POST['rankOrder']);
        $rankTag = htmlspecialchars(trim($_POST['rankTag'] ?? ''));

        $stmt = $conn->prepare("INSERT INTO rank (rank_name, tableColor, tableHoverColor, rankColor, rankGif, rankOrder, rankTag) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssis", $name, $tableColor, $tableHoverColor, $rankColor, $rankGif, $rankOrder, $rankTag);
        if ($stmt->execute()) {
            $successMessage = "Rank added successfully!";
        } else {
            $errorMessage = "Failed to add the rank.";
        }
        $stmt->close();
    }
}

$ranksResult = $conn->query("SELECT * FROM rank ORDER BY rankOrder ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Admin Panel - Rank Management</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        <?php
        $css = file_get_contents(__DIR__ . '/links.php');
        if (preg_match('/<style>(.*?)<\/style>/s', $css, $matches)) {
            echo $matches[1];
        }
        ?>
        .ranks-table th,
        .ranks-table td {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .ranks-table th,
        .ranks-table td {
            vertical-align: middle;
        }
        .ranks-table th:nth-child(1), 
        .ranks-table td:nth-child(1) {
            width: 40px;
            text-align: center;
        }
        .ranks-table th:nth-child(3), 
        .ranks-table td:nth-child(3),
        .ranks-table th:nth-child(4), 
        .ranks-table td:nth-child(4),
        .ranks-table th:nth-child(5),
        .ranks-table td:nth-child(5) {
            width: 90px;
        }
        .ranks-table th:nth-child(6), 
        .ranks-table td:nth-child(6) {
            width: 220px;
        }
        .ranks-table th:nth-child(7), 
        .ranks-table td:nth-child(7) {
            width: 160px;
        }
        .ranks-table th:nth-child(8), 
        .ranks-table td:nth-child(8) {
            width: 70px;
        }
        .ranks-table th:nth-child(9), 
        .ranks-table td:nth-child(9) {
            width: 90px;
        }
        .drag-handle {
            cursor: grab;
            color: #aaa;
            font-size: 1.2em;
            transition: color 0.2s;
        }
        .drag-handle:hover {
            color: #fff;
        }
        .dragging-row {
            opacity: 0.5;
        }
        .drag-placeholder {
            background-color: var(--gray-2);
            height: 48px;
            border: 2px dashed var(--primary);
        }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">
            <i class="fas fa-shield-alt"></i>
            Admin
        </a>
        <ul class="nav nav-tabs">
            <li class="nav-item">
                <a class="nav-link" href="index.php">
                    <i class="fas fa-users"></i> Users
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="pastes.php">
                    <i class="fas fa-file-code"></i> Pastes
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="delete_logs.php">
                    <i class="fas fa-history"></i> Delete Logs
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="badges.php">
                    <i class="fas fa-award"></i> Badges
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="ranks.php">
                    <i class="fas fa-crown"></i> Ranks
                </a>
            </li>
        </ul>
        <div class="navbar-actions">
            <a href="../home" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-home"></i> Home
            </a>
        </div>
    </div>
</nav>

<div class="main-content">
    <div class="container-fluid">
        <div class="page-header">
            <h1 class="page-title">Manage Ranks</h1>
        </div>
        <?php if (isset($successMessage)): ?>
            <div class="alert alert-success"><?= $successMessage ?></div>
        <?php elseif (isset($errorMessage)): ?>
            <div class="alert alert-danger"><?= $errorMessage ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <div class="mb-3">
                        <label class="form-label">Rank Name</label>
                        <input type="text" name="rank_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Table Color</label>
                        <input type="text" name="tableColor" class="form-control" placeholder="e.g., #ff0000 or linear-gradient(...)">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Table Hover Color</label>
                        <input type="text" name="tableHoverColor" class="form-control" placeholder="e.g., #00ff00">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Rank Color</label>
                        <input type="text" name="rankColor" class="form-control" placeholder="e.g., #0000ff">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Rank Gif File</label>
                        <input type="file" name="rankGifFile" class="form-control" accept=".gif,.png,.jpg,.jpeg,.webp">
                        <small class="form-text text-white" style="color: #fff !important;">Only the file name will be saved. File will be uploaded to <code>img/</code>.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Rank Tag</label>
                        <input type="text" name="rankTag" class="form-control" placeholder="e.g., STAFF">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Order</label>
                        <input type="number" name="rankOrder" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-outline-success">
                        <i class="fas fa-plus"></i> Add Rank
                    </button>
                </form>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-body">
                <h5 class="card-title">Edit Existing Ranks</h5>
                <br>
                <form id="saveAllForm" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <div class="table-responsive">
                        <table class="table table-hover ranks-table">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th style="width: 130px;">Name</th>
                                    <th>Table Color</th>
                                    <th>Hover Color</th>
                                    <th>Rank Color</th>
                                    <th>Gif</th>
                                    <th>Tag</th>
                                    <th style="width: 140px;">Order</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $rowIndex = 0; while ($rank = $ranksResult->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <span class="drag-handle" draggable="true" title="Drag to reorder">
                                                <i class="fas fa-bars"></i>
                                            </span>
                                        </td>
                                        <td style="width: 130px;">
                                            <input type="hidden" name="rank_id[]" value="<?= $rank['rank_id'] ?>">
                                            <input type="text" name="rank_name[]" value="<?= htmlspecialchars($rank['rank_name']) ?>" class="form-control">
                                        </td>
                                        <td><input type="text" name="tableColor[]" value="<?= htmlspecialchars($rank['tableColor']) ?>" class="form-control"></td>
                                        <td><input type="text" name="tableHoverColor[]" value="<?= htmlspecialchars($rank['tableHoverColor']) ?>" class="form-control"></td>
                                        <td><input type="text" name="rankColor[]" value="<?= htmlspecialchars($rank['rankColor']) ?>" class="form-control"></td>
                                        <td>
                                            <input type="text" name="rankGif[]" value="<?= htmlspecialchars($rank['rankGif']) ?>" class="form-control" placeholder="Or upload below">
                                            <input type="file" name="rankGifFile_<?= $rowIndex ?>" class="form-control mt-1" accept=".gif,.png,.jpg,.jpeg,.webp">
                                            <?php if (!empty($rank['rankGif'])): ?>
                                                <small class="form-text text-white" style="color: #fff !important;">Current: <?= htmlspecialchars($rank['rankGif']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><input type="text" name="rankTag[]" value="<?= htmlspecialchars($rank['rankTag']) ?>" class="form-control"></td>
                                        <td style="width: 140px;">
                                            <input type="number" name="rankOrder[]" value="<?= htmlspecialchars($rank['rankOrder']) ?>" class="form-control">
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteRow(this, <?= $rank['rank_id'] ?>)">
                                                <i class="fas fa-trash-alt"></i> Delete
                                            </button>
                                        </td>
                                    </tr>
                                <?php $rowIndex++; endwhile; ?>
                                <?php if ($ranksResult->num_rows === 0): ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-4">
                                            <i class="fas fa-folder-open fa-2x mb-3 text-muted"></i>
                                            <p>No ranks found</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" id="saveAllButton" class="btn btn-outline-success mt-3">
                        <i class="fas fa-save"></i> Save All
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const tableBody = document.querySelector('.ranks-table tbody');
        let draggedRow = null;
        let placeholder = document.createElement('tr');
        placeholder.classList.add('drag-placeholder');
        placeholder.innerHTML = `<td colspan="9"></td>`;
        let startIndex = null;

        tableBody.querySelectorAll('.drag-handle').forEach(handle => {
            handle.setAttribute('draggable', 'true');
        });

        let userSelectBackup = '';
        function disableUserSelect() {
            userSelectBackup = document.body.style.userSelect;
            document.body.style.userSelect = 'none';
        }
        function enableUserSelect() {
            document.body.style.userSelect = userSelectBackup;
        }

        tableBody.addEventListener('dragstart', (e) => {
            const handle = e.target.closest('.drag-handle');
            if (!handle) return;
            draggedRow = handle.closest('tr');
            draggedRow.classList.add('dragging-row');
            placeholder.style.height = `${draggedRow.offsetHeight}px`;
            draggedRow.parentNode.insertBefore(placeholder, draggedRow.nextSibling);
            startIndex = Array.from(tableBody.children).indexOf(draggedRow);
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', '');
            disableUserSelect();
        });

        tableBody.addEventListener('dragend', (e) => {
            if (draggedRow) {
                draggedRow.classList.remove('dragging-row');
                placeholder.remove();
                draggedRow = null;
                enableUserSelect();
            }
        });

        tableBody.addEventListener('dragover', (e) => {
            e.preventDefault();
            if (!draggedRow) return;
            const targetRow = e.target.closest('tr');
            if (!targetRow || targetRow === draggedRow || targetRow === placeholder) return;
            const bounding = targetRow.getBoundingClientRect();
            const offset = e.clientY - bounding.top;
            const height = bounding.height / 2;
            if (offset > height) {
                targetRow.after(placeholder);
            } else {
                targetRow.before(placeholder);
            }
        });

        tableBody.addEventListener('dragover', (e) => {
            e.preventDefault();
            if (!draggedRow) return;
            if (!e.target.closest('tr')) {
                tableBody.appendChild(placeholder);
            }
        });

        tableBody.addEventListener('drop', (e) => {
            e.preventDefault();
            if (draggedRow && placeholder.parentNode) {
                placeholder.replaceWith(draggedRow);
                updateOrderInputs();
            }
        });

        function updateOrderInputs() {
            const rows = tableBody.querySelectorAll('tr');
            rows.forEach((row, index) => {
                const orderInput = row.querySelector('input[name="rankOrder[]"]');
                if (orderInput) {
                    orderInput.value = index + 1;
                }
            });
        }

        document.getElementById('saveAllButton').addEventListener('click', () => {
            const form = document.getElementById('saveAllForm');
            const formData = new FormData(form);
            fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Failed to save ranks. Please try again.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        });
    });

    function deleteRow(button, rankId) {
        if (!confirm("Are you sure you want to delete this rank?")) return;
        const row = button.closest('tr');
        const formData = new FormData();
        formData.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token']) ?>');
        formData.append('delete_rank_id', rankId);
        fetch('', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                row.remove();
                alert('Rank deleted successfully!');
            } else {
                alert('Failed to delete rank. Please try again.');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        });
    }
</script>
</body>
</html>
