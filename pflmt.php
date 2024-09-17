<?php
// Configuration
$baseDir = '/var/www/pflmt/lists/';
$allowedExt = 'txt';
$pageVersion = '4.0'; // Update the version number as needed

// Initialize variables
$fileContent = '';
$filename = '';
$message = '';
$showEditor = false;
$iframeSrc = '';

// Function to sanitize file names
function sanitizeFileName($filename) {
    return preg_replace('/[^a-zA-Z0-9_\.\-]/', '', $filename);
}

// Handle file selection
if (isset($_GET['file'])) {
    $filename = sanitizeFileName($_GET['file']);
    $filepath = $baseDir . $filename;

    if (file_exists($filepath) && is_file($filepath) && pathinfo($filepath, PATHINFO_EXTENSION) === $allowedExt) {
        $fileContent = file_get_contents($filepath);
        $showEditor = true;
        $iframeSrc = "http://sub.DOMAIN.local/lists/" . urlencode($filename);
    } else {
        $message = 'Invalid file.';
    }
}

// Handle file saving
if (isset($_POST['save'])) {
    $filename = sanitizeFileName($_POST['filename']);
    $filepath = $baseDir . $filename;
    $content = $_POST['content'];

    if (file_exists($filepath) && is_file($filepath) && pathinfo($filepath, PATHINFO_EXTENSION) === $allowedExt) {
        file_put_contents($filepath, $content);
        $message = "File Saved!<br><a href=\"https://sub.DOMAIN.local/lists/" . urlencode($filename) . "\" target=\"_blank\">Download File</a>";
        $iframeSrc = "https://sub.DOMAIN.local/lists/" . urlencode($filename);
        $fileContent = ''; // Clear file content to return to Start Section
        $filename = '';
        $showEditor = false;
    } else {
        $message = 'Invalid file.';
    }
}

// Handle file cancellation
if (isset($_POST['cancel'])) {
    $message = 'Canceled!';
    $fileContent = ''; // Clear file content to return to Start Section
    $filename = '';
    $showEditor = false;
    $iframeSrc = ''; // Clear iframe source
}

// List available text files
$files = array_diff(scandir($baseDir), array('.', '..'));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portable Friendly List Modification Tool Version <?php echo htmlspecialchars($pageVersion); ?></title>
    <style>
        body { font-family: Arial, sans-serif; }
        .container { width: 80%; margin: 0 auto; }
        textarea { width: 100%; height: 300px; }
        .button { padding: 10px 20px; margin: 5px; }
        .message { color: green; }
        .refresh-link { color: blue; text-decoration: underline; cursor: pointer; }
        .icon { cursor: pointer; }
        iframe { width: 100%; height: 400px; border: 1px solid #ccc; overflow: auto; margin-top: 20px; }
    </style>
    <script>
        function refreshPage() {
            window.location.href = 'pflmt.php';
        }
    </script>
</head>
<body>
    <div class="container">
        <h1>Portable Friendly Modification Tool Version <?php echo htmlspecialchars($pageVersion); ?> <span class="refresh-link icon" onclick="refreshPage()">🔄</span></h1>

        <?php if (!$showEditor): ?>
            <h3>Start Section: Select a file to edit</h3>
            <form action="" method="get">
                <select name="file">
                    <?php
                    // Display available text files in the lists directory
                    foreach ($files as $file) {
                        if (pathinfo($file, PATHINFO_EXTENSION) === $allowedExt) {
                            echo "<option value=\"" . htmlspecialchars($file) . "\">" . htmlspecialchars($file) . "</option>";
                        }
                    }
                    ?>
                </select>
                <button type="submit" class="button">Edit</button>
            </form>
        <?php else: ?>
            <h3>Editing: <?php echo htmlspecialchars($filename); ?></h3>
            <form action="" method="post">
                <textarea name="content"><?php echo htmlspecialchars($fileContent); ?></textarea>
                <input type="hidden" name="filename" value="<?php echo htmlspecialchars($filename); ?>">
                <button type="submit" name="save" class="button">Save</button>
                <button type="submit" name="cancel" class="button">Cancel</button>
            </form>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="message"><?php echo $message; ?></div>
        <?php endif; ?>

        <?php if ($iframeSrc): ?>
            <iframe src="<?php echo htmlspecialchars($iframeSrc); ?>" title="File Preview"></iframe>
        <?php endif; ?>

    </div>
</body>
</html>
