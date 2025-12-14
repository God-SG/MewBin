<?php
require 'database.php';

function getBlacklist($conn) {
    $blacklist = [];
    $sql = "SELECT address, first_name, last_name FROM blacklist";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $blacklist[] = $row;
        }
    }
    return $blacklist;
}

function normalizeContent($content) {
    return strtolower(preg_replace("/[^a-zA-Z0-9\s]/", "", $content));
}

function containsBlacklistedInfo($content, $blacklist) {
    $normalizedContent = normalizeContent($content);
    $paddedContent = ' ' . preg_replace('/\s+/', ' ', $normalizedContent) . ' ';

    foreach ($blacklist as $entry) {
        $allMatch = true;
        foreach (['address', 'first_name', 'last_name'] as $field) {
            $normalizedValue = trim(normalizeContent($entry[$field]));
            if ($normalizedValue === '') continue;
            $pattern = '/\b' . preg_quote($normalizedValue, '/') . '\b/i';
            if (!preg_match($pattern, $paddedContent)) {
                $allMatch = false;
                break;
            }
        }
        if ($allMatch) {
            return true;
        }
    }
    return false;
}

function deletePasteData($conn, $paste_id) {
    $sql = "DELETE FROM paste_views WHERE paste_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $paste_id);
    $stmt->execute();

    $sql = "DELETE FROM paste_comments WHERE paste_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $paste_id);
    $stmt->execute();

    $sql = "DELETE FROM pastes WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $paste_id);
    $stmt->execute();
}

$sql = "SELECT id, content FROM pastes";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    $blacklist = getBlacklist($conn);

    while ($row = $result->fetch_assoc()) {
        $paste_id = $row['id'];
        $content = $row['content'];

        if (containsBlacklistedInfo($content, $blacklist)) {
            deletePasteData($conn, $paste_id);
            echo "Paste ID $paste_id has been deleted due to blacklisted information.<br>";
        }
    }
} else {
    echo "No pastes found in the database.";
}

$conn->close();
?>
