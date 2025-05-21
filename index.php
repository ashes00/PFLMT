<?php
// Configuration
$lists_dir = __DIR__ . '/lists/'; // Absolute path to the lists directory

// Start the session to store messages across redirects
session_start();

// Ensure the lists directory exists
if (!is_dir($lists_dir)) {
    // Attempt to create the directory. Use 0777 for maximum compatibility,
    // but consider more restrictive permissions (e.g., 0755) if possible
    // and ensure the web server user owns the directory.
    if (!mkdir($lists_dir, 0777, true)) {
        die("Error: Could not create the lists directory: {$lists_dir}. Please create it manually and ensure the web server has write permissions.");
    }
}

// --- Helper Functions for File Operations ---

/**
 * Get a list of all list names (without .txt extension).
 * @param string $dir The lists directory path.
 * @return array Array of list names.
 */
function get_lists($dir) {
    $lists = [];
    // Check if directory exists and is readable
    if (!is_dir($dir) || !is_readable($dir)) {
        error_log("Error: Lists directory not found or not readable: " . $dir);
        return [];
    }

    if ($handle = opendir($dir)) {
        while (false !== ($entry = readdir($handle))) {
            if ($entry != "." && $entry != "..") {
                $file_path = $dir . $entry;
                // Check if it's a file and ends with .txt
                if (is_file($file_path) && pathinfo($entry, PATHINFO_EXTENSION) === 'txt') {
                    $lists[] = pathinfo($entry, PATHINFO_FILENAME); // Get filename without extension
                }
            }
        }
        closedir($handle);
    } else {
         error_log("Error: Could not open lists directory: " . $dir);
    }
    sort($lists); // Sort lists alphabetically
    return $lists;
}

/**
 * Get IPs from a specific list file.
 * @param string $dir The lists directory path.
 * @param string $list_name The name of the list (without .txt).
 * @return array|false Array of IPs, or false if list doesn't exist or is not readable.
 */
function get_list_ips($dir, $list_name) {
    // Renamed to get_list_entries internally for clarity, but function signature remains for compatibility
    if (empty($list_name) || !preg_match('/^[a-zA-Z0-9_-]+$/', $list_name)) {
        return false; // Invalid list name
    }
    $file_path = $dir . $list_name . '.txt';

    if (!file_exists($file_path) || !is_readable($file_path)) {
        error_log("Error: List file not found or not readable: {$file_path}");
        return false; // List doesn't exist or cannot be read
    }

    $content = file_get_contents($file_path);
    if ($content === false) {
        error_log("Error: Could not read list file content: " . $file_path);
        return []; // Handle file read error, return empty array
    }

    $ips = explode("\n", $content);
    // Return raw lines, preserving order and content, including empty lines if present between entries.
    // Filter out the very last line if it's empty, which often results from the final newline in the file.
    if (count($ips) > 0 && end($ips) === '') {
        array_pop($ips);
    }
    return $ips;
}

/**
 * Save IPs to a specific list file.
 * @param string $dir The lists directory path.
 * @param string $list_name The name of the list (without .txt).
 * @param array $entries_array Array of entries (IPs, CIDRs, comments) to save.
 * @return bool True on success, false on failure.
 */
function save_list_entries($dir, $list_name, $entries_array) {
     if (empty($list_name) || !preg_match('/^[a-zA-Z0-9_-]+$/', $list_name)) {
        error_log("Error: Invalid list name for saving: " . $list_name);
        return false; // Invalid list name
    }
    $file_path = $dir . $list_name . '.txt';

    // Preserve order and content as provided
    $content = implode("\n", $entries_array);
    // Ensure a single trailing newline for POSIX compatibility / cleaner diffs
    if (!empty($content)) {
        $content .= "\n";
    }

    // Use file_put_contents with LOCK_EX for basic concurrency safety
    // and check if the write was successful
    if (file_put_contents($file_path, $content, LOCK_EX) === false) {
        error_log("Error: Could not write to list file: " . $file_path);
        return false;
    }
    return true;
}

/**
 * Create a new empty list file.
 * @param string $dir The lists directory path.
 * @param string $list_name The name of the new list (without .txt).
 * @return bool True on success, false on failure (e.g., invalid name, file already exists, write error).
 */
function create_list($dir, $list_name) {
    // Basic validation for list name
    if (empty($list_name) || !preg_match('/^[a-zA-Z0-9_-]+$/', $list_name)) {
        return false; // Invalid list name
    }
    $file_path = $dir . $list_name . '.txt';
    if (file_exists($file_path)) {
        return false; // List already exists
    }
    // Create empty file with write permissions for owner/group/others
    // Use LOCK_EX even for empty file creation
    return file_put_contents($file_path, "", LOCK_EX) !== false;
}

/**
 * Delete a list file.
 * @param string $dir The lists directory path.
 * @param string $list_name The name of the list to delete (without .txt).
 * @return bool True on success, false on failure (e.g., invalid name, list not found, delete error).
 */
function delete_list($dir, $list_name) {
     // Basic validation for list name
     if (empty($list_name) || !preg_match('/^[a-zA-Z0-9_-]+$/', $list_name)) {
        return false; // Invalid list name
    }
    $file_path = $dir . $list_name . '.txt';
    if (!file_exists($file_path)) {
        return false; // List doesn't exist
    }
    // Check if file is writable before attempting deletion
    if (!is_writable($file_path)) {
         error_log("Error: List file not writable for deletion: " . $file_path);
         return false;
    }
    return unlink($file_path);
}

/**
 * Validates if a given string is a valid entry (IP, IPv4 CIDR, or comment).
 * @param string $entry The string to validate.
 * @return bool True if valid, false otherwise.
 */
function is_valid_entry_format($entry) {
    $trimmed_entry = trim($entry);

    // If after trimming, the entry is empty, it's not a valid format for display/processing.
    // Note: add_entry() in the main loop already skips fully empty lines from textarea.
    if ($trimmed_entry === '') {
        return false; 
    }

    // Check for full-line comment (if # is the first character of the trimmed string)
    if (strpos($trimmed_entry, '#') === 0) {
        return true;
    }

    // Now, check for IP/CIDR, possibly followed by an inline comment.
    // We will validate the part *before* the first '#' if one exists (and is not at the start).
    $potential_ip_part = $trimmed_entry; // Assume no comment initially

    $comment_char_pos = strpos($trimmed_entry, '#'); // Find the first #
    
    if ($comment_char_pos !== false) {
        // A '#' exists. Since we've already checked for full-line comments,
        // this '#' must be for an inline comment.
        $potential_ip_part = substr($trimmed_entry, 0, $comment_char_pos);
    }

    // Trim the extracted potential IP part to remove any trailing spaces before the '#'
    $ip_to_validate = trim($potential_ip_part);

    // If the part to validate as IP/CIDR is empty, it means the line was
    // effectively just a comment (which should have been caught by the first check) or malformed.
    if ($ip_to_validate === '') {
        return false; // Cannot be empty after trimming the potential IP/CIDR part
    }

    // Validate the extracted IP/CIDR part
    if (filter_var($ip_to_validate, FILTER_VALIDATE_IP) ||
        preg_match('/^((25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\/(3[0-2]|[12]?[0-9]|[0-9])$/', $ip_to_validate)) {
        return true; // The IP/CIDR part is valid, so the whole original line (with its comment) is acceptable.
    }

    return false;
}

/**
 * Add an entry (IP, CIDR, comment) to a list.
 * @param string $dir The lists directory path.
 * @param string $list_name The name of the list.
 * @param string $entry_to_add The entry string.
 * @return bool True on success, false on failure.
 */
function add_entry($dir, $list_name, $entry_to_add) {
    // Validate the format of the entry_to_add
    if (!is_valid_entry_format($entry_to_add)) {
        return false; // Invalid entry format
    }

    $entries = get_list_ips($dir, $list_name); // get_list_ips now returns entries
    if ($entries === false) {
        return false; // List not found or not readable
    }
    $entries[] = $entry_to_add; // Add the new entry, preserving original (possibly untrimmed) version
    return save_list_entries($dir, $list_name, $entries);
}

/**
 * Remove an IP from a list.
 * @param string $dir The lists directory path.
 * @param string $list_name The name of the list.
 * @param string $entry_to_remove The exact entry string to remove.
 * @return bool True on success, false on failure.
 */
function remove_entry($dir, $list_name, $entry_to_remove) {
    $entries = get_list_ips($dir, $list_name);
    if ($entries === false) {
        return false; // List not found or not readable
    }

    // Normalize the entry to remove by trimming it, as form submissions might handle newlines differently.
    $trimmed_entry_to_remove = trim($entry_to_remove);
    $found_key = false;

    foreach ($entries as $key => $entry) {
        if (trim($entry) === $trimmed_entry_to_remove) {
            $found_key = $key;
            break;
        }
    }

    if ($found_key === false) {
        return false; // Entry not found
    }

    unset($entries[$found_key]);
    $entries = array_values($entries);
    return save_list_entries($dir, $list_name, $entries);
}

/**
 * Edit an IP in a list.
 * @param string $dir The lists directory path.
 * @param string $list_name The name of the list.
 * @param string $old_entry The exact entry string to replace.
 * @param string $new_entry The new entry string.
 * @return bool True on success, false on failure.
 */
function edit_entry($dir, $list_name, $old_entry, $new_entry) {
    // Validate the format of the new_entry
    if (!is_valid_entry_format($new_entry)) {
        return false; // Invalid new entry format
    }

    $entries = get_list_ips($dir, $list_name);
    if ($entries === false) {
        return false; // List not found or not readable
    }

    // Normalize the old_entry by trimming it for comparison,
    // as form submissions might handle newlines differently from file reads.
    $trimmed_old_entry_for_search = trim($old_entry);
    $found_old_key = false;

    foreach ($entries as $key => $entry_in_list) {
        if (trim($entry_in_list) === $trimmed_old_entry_for_search) {
            $found_old_key = $key;
            break;
        }
    }

    if ($found_old_key === false) {
        error_log("Edit Error: Old entry '{$old_entry}' (trimmed: '{$trimmed_old_entry_for_search}') not found in list '{$list_name}'. Entries: " . print_r(array_map('trim', $entries), true));
        return false; // Old entry not found
    }

    // Replace the old entry with the new entry (use the potentially untrimmed new_entry for saving)
    $entries[$found_old_key] = $new_entry;

    return save_list_entries($dir, $list_name, $entries);
}


// --- Action Handling ---

// Initialize message variables (will be overwritten if messages exist in session)
$message = '';
$message_type = '';

// Handle POST requests for actions (Create, Delete, Add, Remove, Edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'Create List':
            $list_name = trim($_POST['new_list_name'] ?? '');
            if (create_list($lists_dir, $list_name)) {
                $message = "List '{$list_name}' created successfully.";
                $message_type = 'success';
            } else {
                $message = "Error creating list. Name may be invalid, empty, or already exists.";
                $message_type = 'error';
            }
            // Redirect to the new list or back to the index
            $redirect_url = ($message_type === 'success') ? "?list=" . urlencode($list_name) : "index.php";
            $_SESSION['message'] = $message;
            $_SESSION['message_type'] = $message_type;
            header("Location: " . $redirect_url);
            exit; // Stop script execution after redirect
            break;
        case 'Delete List':
            $list_name = trim($_POST['list_name'] ?? '');
             if (delete_list($lists_dir, $list_name)) {
                $message = "List '{$list_name}' deleted successfully.";
                // If the deleted list was the one currently viewed, clear the selection
                if (isset($_GET['list']) && $_GET['list'] === $list_name) {
                    unset($_GET['list']);
                }
                $message_type = 'success';
            } else {
                $message = "Error deleting list '{$list_name}'. It might not exist or permissions are incorrect.";
                $message_type = 'error';
            }
            // Redirect back to the index page after deletion
            $_SESSION['message'] = $message;
            $_SESSION['message_type'] = $message_type;
            header("Location: index.php");
            exit; // Stop script execution after redirect
            break;
        case 'Clear List':
            $list_name = trim($_POST['list_name'] ?? '');
            // To clear the list, we save an empty array of entries to it.
            if (save_list_entries($lists_dir, $list_name, [])) {
                $message = "List '{$list_name}' cleared successfully.";
                $message_type = 'success';
            } else {
                $message = "Error clearing list '{$list_name}'. It might not exist or permissions are incorrect.";
                $message_type = 'error';
            }
            // Redirect back to the (now empty) list page
            $_SESSION['message'] = $message;
            $_SESSION['message_type'] = $message_type;
            header("Location: ?list=" . urlencode($list_name));
            exit; // Stop script execution after redirect
            break;
        case 'Add IP':
            $list_name = trim($_POST['list_name'] ?? '');
            $ips_input_string = trim($_POST['ip_to_add'] ?? '');

            // Split the input string by newlines and process each line
            $potential_ips = explode("\n", $ips_input_string);

            $successful_adds = 0;
            $failed_ips = [];
            $total_ips_attempted = 0;

            // Process each potential IP
            foreach ($potential_ips as $line_entry) {
                $trimmed_line_entry = trim($line_entry);
                if (empty($trimmed_line_entry)) {
                    continue; // Skip empty lines
                }
                $total_ips_attempted++;

                // Add the original line_entry to preserve leading/trailing spaces if it's part of a comment, for example
                if (add_entry($lists_dir, $list_name, $line_entry)) {
                    $successful_adds++;
                } else {
                    $failed_ips[] = $line_entry; // Store the original input line for clarity
                }
            }

            // Construct the message based on results
            if ($total_ips_attempted === 0) {
                 $message = "No valid IPs provided to add to list '{$list_name}'.";
                 $message_type = 'warning'; // Not an error, just nothing to do
            } elseif ($successful_adds === $total_ips_attempted) {
                $message = "Successfully added {$successful_adds} IP(s) to list '{$list_name}'.";
                $message_type = 'success';
            } elseif ($successful_adds > 0 && count($failed_ips) > 0) {
                 $message = "Successfully added {$successful_adds} IP(s) to list '{$list_name}'. Failed to add " . count($failed_ips) . ": " . htmlspecialchars(implode(', ', $failed_ips)) . ".";
                 $message_type = 'warning'; // Partial success
            } else { // $successful_adds === 0 && count($failed_ips) > 0 (all attempted failed)
                 $message = "Error adding entries to list '{$list_name}'. Failed to add " . count($failed_ips) . " entries: " . htmlspecialchars(implode(', ', $failed_ips)) . ". They might be invalid format, or the list was not found/writable.";
                 $message_type = 'error';
            }
            // Keep the list selected after action
            // Redirect back to the list page
            $_SESSION['message'] = $message;
            $_SESSION['message_type'] = $message_type;
            header("Location: ?list=" . urlencode($list_name));
            exit; // Stop script execution after redirect
            break;
        case 'Remove IP':
            $list_name = trim($_POST['list_name'] ?? '');
            // The value from POST is likely already trimmed or doesn't have the specific newline
            // that might exist in the file-read version.
            $entry_to_remove = $_POST['ip_to_remove'] ?? ''; 
             if (remove_entry($lists_dir, $list_name, $entry_to_remove)) {
                $message = "Entry '{$entry_to_remove}' removed from list '{$list_name}'.";
                $message_type = 'success';
            } else {
                $message = "Error removing entry '{$entry_to_remove}' from list '{$list_name}'. It might not be found in the list.";
                $message_type = 'error';
            }
            // Redirect back to the list page
            $_SESSION['message'] = $message;
            $_SESSION['message_type'] = $message_type;
            header("Location: ?list=" . urlencode($list_name));
            exit; // Stop script execution after redirect
            break;
        case 'Edit IP':
            $list_name = trim($_POST['list_name'] ?? '');
            $old_entry = $_POST['old_ip'] ?? ''; // Do not trim, match exact old entry
            $new_entry = $_POST['new_ip'] ?? ''; // New entry will be validated by edit_entry
             if (edit_entry($lists_dir, $list_name, $old_entry, $new_entry)) {
                $message = "Entry '{$old_entry}' updated to '{$new_entry}' in list '{$list_name}'.";
                $message_type = 'success';
            } else {
                $message = "Error editing entry '{$old_entry}' in list '{$list_name}'. New entry might be invalid format, or old entry not found.";
                $message_type = 'error';
            }
            // Redirect back to the list page
            $_SESSION['message'] = $message;
            $_SESSION['message_type'] = $message_type;
            header("Location: ?list=" . urlencode($list_name));
            exit; // Stop script execution after redirect
            break;
        default:
            // Handle unknown action or initial load
            break;
    }
}

// --- Check for messages in session after redirect ---
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'] ?? 'success'; // Default to success if type isn't set
    unset($_SESSION['message']); // Clear message from session
    unset($_SESSION['message_type']); // Clear message type from session
}

// --- Data Retrieval for Rendering ---

$selected_list = $_GET['list'] ?? null;
$ips_in_selected_list = [];
$list_url = '';

if ($selected_list) {
    $ips_in_selected_list = get_list_ips($lists_dir, $selected_list);
    if ($ips_in_selected_list === false) {
        // List not found or error reading, clear selection
        $selected_list = null;
        // Only set error message if one wasn't already set by a POST action
        if (empty($message)) $message = "Error: List '{$_GET['list']}' not found or could not be read.";
        $message_type = 'error';
    } else {
         // Construct the direct URL (assuming the lists directory is web-accessible)
         // This URL needs to be relative to the web root where index.php is located
         // Adjust the path calculation based on your server setup if needed.
         // realpath(__DIR__) gives the server's file system path to the directory containing index.php
         // realpath($_SERVER['DOCUMENT_ROOT']) gives the server's file system path to the web root
         // We calculate the relative path from web root to the lists directory.
         $base_app_path = str_replace(realpath($_SERVER['DOCUMENT_ROOT']), '', realpath(__DIR__));
         $base_app_path = str_replace('\\', '/', $base_app_path); // Fix for Windows paths
         // Ensure leading slash
         if (substr($base_app_path, 0, 1) !== '/') {
             $base_app_path = '/' . $base_app_path;
         }
         // Ensure trailing slash
         if (substr($base_app_path, -1) !== '/') {
             $base_app_path .= '/';
         }

         $list_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . $base_app_path . 'lists/' . urlencode($selected_list) . '.txt';
    }
}

$all_lists = get_lists($lists_dir);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Prevent FOUC (Flash of Unstyled Content) and set default theme -->
    <script>
        (function() {
            const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            let currentTheme = localStorage.getItem('theme');

            // Set dark mode as default if no preference is stored and system preference is not light
            if (currentTheme === null) {
                 currentTheme = prefersDark ? 'dark' : 'dark'; // Default to dark if no stored preference
            }

            if (currentTheme === 'dark') {
                document.documentElement.classList.add('dark-mode');
            }
            // Determine initial icon based on theme and set CSS variable
            // This runs before the DOM element #theme-icon is available to JS,
            // so we set a CSS variable that the CSS rule for #theme-icon can use.
            let initialIconChar = '☾'; // Default for light mode
            if (currentTheme === 'dark') {
                initialIconChar = '☀'; // Sun for dark mode (to switch to light)
            }
            // Set the CSS variable directly on the html element's style
            document.documentElement.style.setProperty('--current-theme-icon', "'" + initialIconChar + "'");
        })();
    </script>
    <title>IP List Manager</title>
    <style>
        :root {
            --body-bg: #f4f7f6;
            --text-color: #333;
            --left-column-bg: #2c3e50;
            --left-column-text: #ecf0f1;
            --left-column-h2-text: #ecf0f1;
            --left-column-li-bg: #34495e;
            --left-column-li-a-text: #ecf0f1;
            --left-column-li-a-hover-text: #bdc3c7;
            --left-column-form-bg: #34495e;
            --left-column-form-border: #2c3e50;
            --left-column-form-input-bg: #4a627a;
            --left-column-form-input-text: #ecf0f1;
            --left-column-form-input-border: #5a748e;
            --right-column-bg: #ffffff;
            --right-column-h-text: #34495e;
            --form-bg: #ecf0f1;
            --form-border: #bdc3c7;
            --input-text-color: #333; /* For general inputs in right column */
            --input-bg-color: #fff; /* For general inputs in right column */
            --input-border-color: #bdc3c7;
            --ip-list-item-bg: #f0f0f0;
            --ip-list-item-border: #ddd;
            --ip-list-item-edit-input-border: #ccc;
            --message-success-bg: #d4edda;
            --message-success-text: #155724;
            --message-success-border: #c3e6cb;
            --message-warning-bg: #fff3cd;
            --message-warning-text: #856404;
            --message-warning-border: #ffeeba;
            --message-error-bg: #f8d7da;
            --message-error-text: #721c24;
            --message-error-border: #f5c6cb;
            --list-url-box-bg: #ecf0f1;
            --list-url-box-border: #bdc3c7;
            --list-url-box-link-text: #3498db;
            --ip-list-container-bg: #ecf0f1;
            --ip-list-container-border: #bdc3c7;
            --no-search-results-text: #777;
            --current-theme-icon: '☾'; /* Default to moon for light mode, will be overridden by inline script */
        }
        /* Basic modern styling */
        body { font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; margin: 0; padding: 0; background-color: var(--body-bg); color: var(--text-color); transition: background-color 0.2s, color 0.2s; }
        .container { display: flex; min-height: 100vh; }
        .left-column { width: 400px; background-color: var(--left-column-bg); color: var(--left-column-text); padding: 20px; box-sizing: border-box; flex-shrink: 0; transition: background-color 0.2s, color 0.2s; position: relative; /* For theme toggle positioning */ } /* Increased width again */
        .right-column { flex-grow: 1; padding: 20px; box-sizing: border-box; background-color: var(--right-column-bg); transition: background-color 0.2s; }
        h1, h2, h3 { color: var(--right-column-h-text); margin-top: 0; }
        .left-column h2 { color: var(--left-column-h2-text); margin-bottom: 20px; }
        .left-column h2 a { color: var(--left-column-h2-text); text-decoration: none; } /* Ensure link in h2 also uses theme color */
        ul { list-style: none; padding: 0; margin: 0; }
        .left-column li { margin-bottom: 10px; display: flex; align-items: center; justify-content: space-between; background-color: var(--left-column-li-bg); padding: 4px 0 4px 8px; border-radius: 4px; } /* Reverted to previous state: background, specific padding, and border-radius */
        .left-column li a { color: var(--left-column-li-a-text); text-decoration: none; flex-grow: 1; padding: 5px 0; }
        .left-column li a:hover { text-decoration: underline; color: var(--left-column-li-a-hover-text); }
        .left-column li .list-actions-container { /* New container for buttons */
            white-space: nowrap; /* Prevent buttons from wrapping */
        }
        /* Target the forms specifically for the list action buttons */
        .left-column li .list-actions-container form {
            padding: 0; /* Remove padding from the form itself */
            margin: 0;  /* Ensure no margin */
            border: none; /* Remove any border from the form */
            background-color: transparent; /* Ensure form itself has no background */
        }

        form { margin-bottom: 20px; padding: 15px; border: 1px solid var(--form-border); background-color: var(--form-bg); border-radius: 4px; }
        .left-column form { background-color: var(--left-column-form-bg); border-color: var(--left-column-form-border); padding: 10px; margin-bottom: 15px; }
        .left-column form input[type="text"] { background-color: var(--left-column-form-input-bg); color: var(--left-column-form-input-text); border: 1px solid var(--left-column-form-input-border); padding: 8px; border-radius: 4px; width: calc(100% - 20px); /* Make it wider */ margin-right: 0; /* Remove horizontal margin */ display: block; /* Stack vertically */ margin-bottom: 10px; /* Add vertical space below */ box-sizing: border-box; /* Include padding/border in width */ }
        .left-column form input[type="submit"] { background-color: #2ecc71; color: white; border: none; cursor: pointer; padding: 8px 12px; border-radius: 4px; }
        .left-column form input[type="submit"]:hover { background-color: #27ad60; }

        input[type="text"], textarea, input[type="submit"], button { padding: 8px; margin-right: 5px; border-radius: 4px; border: 1px solid var(--input-border-color); box-sizing: border-box; background-color: var(--input-bg-color); color: var(--input-text-color); }
        #search-ip-input { width: calc(100% - 10px); margin-bottom: 10px; background-color: var(--input-bg-color); color: var(--input-text-color); border-color: var(--input-border-color); } /* Style for search input */
        textarea { width: 100%; min-height: 80px; margin-bottom: 10px; font-family: inherit; background-color: var(--input-bg-color); color: var(--input-text-color); border-color: var(--input-border-color); }
        input[type="submit"], button { background-color: #3498db; color: white; border: none; cursor: pointer; }
        input[type="submit"]:hover, button:hover { background-color: #2980b9; }
        
        .list-action-button { padding: 3px 6px; margin-left: 3px; border-radius: 4px; font-size: 0.9em; border: none; } /* Adjusted padding, radius, font-size to better match 'Create List' button's borderless look */
        .clear-list-button { background-color: #f39c12; /* Orange */ }
        .delete-button { background-color: #e74c3c; /* Red */ }
        .delete-button:hover { background-color: #c0392b; }

        .ip-list { list-style: none; padding: 0; margin: 0; } /* Removed default ul margin */
        .ip-list-item {
            background-color: var(--ip-list-item-bg); /* Light grey background for IP items */
            border-bottom: 1px solid var(--ip-list-item-border); padding: 5px 10px; /* Compact padding */
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 3px; /* Compact margin */
            border-radius: 3px;
        }
        .ip-list-item:last-child { border-bottom: none; }
        .ip-list-item form { margin: 0; padding: 0; border: none; background: none; display: inline-block; }
        .ip-list-item input[type="text"] { border: 1px solid var(--ip-list-item-edit-input-border); padding: 3px; font-size: 0.9em; background-color: var(--input-bg-color); color: var(--input-text-color); } /* Compact edit input */
        .ip-list-item button { padding: 3px 6px; margin-right: 2px; font-size: 0.9em; } /* Compact buttons */
        .ip-list-item .edit-form { display: none; flex-grow: 1; align-items: center; } /* Hide edit form by default */
        .ip-list-item .edit-form input[type="text"] { flex-grow: 1; margin-right: 5px; }
        .ip-list-item.editing .edit-form { display: flex; } /* Show when editing */
        .ip-list-item.editing .view-mode { display: none; } /* Hide view mode when editing */
        .ip-list-item .view-mode { flex-grow: 1; display: flex; justify-content: space-between; align-items: center; }
        .ip-list-item .view-mode span { flex-grow: 1; margin-right: 10px; word-break: break-all; }

        .no-search-results { display: none; padding: 10px; text-align: center; color: var(--no-search-results-text); }
        .message { padding: 10px; margin-bottom: 20px; border-radius: 4px; word-break: break-word; white-space: pre-wrap; /* Ensure message text color also uses theme */ color: var(--text-color); }
        .message.success { background-color: var(--message-success-bg); color: var(--message-success-text); border: 1px solid var(--message-success-border); }
        /* Adjusted warning/error colors slightly for better contrast */
        .message.warning { background-color: var(--message-warning-bg); color: var(--message-warning-text); border: 1px solid var(--message-warning-border); }
        .message.error { background-color: var(--message-error-bg); color: var(--message-error-text); border: 1px solid var(--message-error-border); }

        .list-url-box { background-color: var(--list-url-box-bg); padding: 10px; border: 1px solid var(--list-url-box-border); margin-bottom: 20px; word-break: break-all; border-radius: 4px; }
        .list-url-box a { color: var(--list-url-box-link-text); text-decoration: none; }
        .list-url-box a:hover { text-decoration: underline; }

        /* New styles for the scrollable IP list container */
        .ip-list-container {
            max-height: 400px; /* Set a fixed height */
            overflow-y: auto;   /* Add vertical scrollbar when content exceeds height */
            border: 1px solid var(--ip-list-container-border); /* Optional: Add a border to frame the list */
            padding: 5px; /* Compact padding inside the container */
            background-color: var(--ip-list-container-bg); /* Match form background or choose another light color */
            border-radius: 4px;
            margin-top: 15px; /* Space above the list container */
        }

        /* Theme Toggle Styles */
        .theme-toggle-container {
            position: absolute;
            bottom: 20px;
            left: 20px; /* Aligns with padding of .left-column */
        }
        #theme-toggle {
            color: var(--left-column-text);
            font-size: 20px; /* Reverted to original icon size */
            cursor: pointer;
            background: none; /* Remove background */
            border: none; /* Remove border */
            padding: 5px; /* Add some padding if needed for click area or spacing */
        }
        #theme-icon::before {
            content: var(--current-theme-icon);
            /* display: flex, align-items, justify-content can be removed if not needed for simple text icon */
        }
        #theme-toggle:hover {
            /* Optional: Add a text color hover effect if desired, e.g., color: var(--left-column-li-a-hover-text); */
            opacity: 0.8; /* Simple hover effect */
        }

        /* GitHub Link Styles */
        .github-link-container {
            position: absolute;
            bottom: 20px; /* Matches theme toggle's vertical position */
            right: 20px;  /* Aligns with padding of .left-column */
        }
        .github-link-container a {
            display: inline-block; /* Ensures the clickable area covers the icon */
        }
        .github-link-container .github-icon {
            fill: var(--left-column-text); /* Use theme variable for icon color */
            transition: fill 0.2s, opacity 0.2s;
            vertical-align: middle; /* Good practice for inline SVGs */
        }
        .github-link-container a:hover .github-icon {
            fill: var(--left-column-li-a-hover-text); /* Use theme variable for hover color */
            opacity: 0.8;
        }
        /* Dark Mode Styles */
        html.dark-mode body { /* Target body when html has dark-mode class */
            --body-bg: #1a1a1a;
            --text-color: #e0e0e0;
            --left-column-bg: #1f2937; /* Darker blue-gray */
            --left-column-text: #d1d5db; /* Lighter gray */
            --left-column-h2-text: #d1d5db;
            --left-column-li-bg: #374151; /* Medium dark blue-gray */
            --left-column-li-a-text: #d1d5db;
            --left-column-li-a-hover-text: #9ca3af; /* Slightly lighter gray for hover */
            --left-column-form-bg: #374151;
            --left-column-form-border: #1f2937;
            --left-column-form-input-bg: #4b5563; /* Dark gray */
            --left-column-form-input-text: #d1d5db;
            --left-column-form-input-border: #6b7280; /* Lighter gray border */
            --right-column-bg: #2d2d2d;
            --right-column-h-text: #c7c7c7;
            --form-bg: #3a3a3a;
            --form-border: #555;
            --input-text-color: #e0e0e0;
            --input-bg-color: #444;
            --input-border-color: #666;
            --ip-list-item-bg: #4a4a4a;
            --ip-list-item-border: #555;
            --ip-list-item-edit-input-border: #666;
            --list-url-box-bg: #3a3a3a;
            --list-url-box-border: #555;
            --list-url-box-link-text: #60a5fa; /* Lighter blue for links */
            --ip-list-container-bg: #3a3a3a;
            --ip-list-container-border: #555;
            --no-search-results-text: #aaa;
        }
        /* Ensure that direct children of html.dark-mode also get themed if necessary,
           though most styles are inherited or applied via the body selector above.
           This is more of a catch-all if specific elements aren't inheriting as expected. */
        html.dark-mode {
            /* You could add specific overrides for html element itself if needed, but usually not necessary */
        }

    </style>
    <script>
        // Simple JS to toggle edit forms
        function toggleEdit(elementId) {
            const entryElement = document.getElementById(elementId);
            if (entryElement) {
                entryElement.classList.toggle('editing');
                // If switching to edit mode, focus the input
                if (entryElement.classList.contains('editing')) {
                    const inputField = entryElement.querySelector('.edit-form input[type="text"]');
                    if (inputField) {
                        inputField.focus();
                        inputField.select(); // Select current text
                    }
                }
            }
        }

        function filterIPs() {
            const searchTerm = document.getElementById('search-ip-input').value.toLowerCase();
            const ipListItems = document.querySelectorAll('.ip-list-container .ip-list-item');
            const noResultsMessage = document.getElementById('no-search-results');
            const emptyListMessage = document.querySelector('.ip-list-container > p'); // The "This list is currently empty." message
            let matchesFound = false;

            ipListItems.forEach(item => {
                const entryText = item.querySelector('.view-mode span').textContent.toLowerCase();
                if (entryText.includes(searchTerm)) {
                    item.style.display = 'flex';
                    matchesFound = true;
                } else {
                    item.style.display = 'none';
                }
            });

            if (emptyListMessage) { // If the "list is empty" message element exists
                if (ipListItems.length === 0) { // List is genuinely empty
                    emptyListMessage.style.display = 'block';
                    if (noResultsMessage) noResultsMessage.style.display = 'none';
                } else { // List has items
                    emptyListMessage.style.display = 'none';
                    if (noResultsMessage) {
                        if (!matchesFound && searchTerm !== '') {
                            noResultsMessage.style.display = 'block';
                        } else {
                            noResultsMessage.style.display = 'none';
                        }
                    }
                }
            } else if (noResultsMessage) { // Only handle noResultsMessage if emptyListMessage isn't relevant
                 if (!matchesFound && searchTerm !== '' && ipListItems.length > 0) {
                    noResultsMessage.style.display = 'block';
                } else {
                    noResultsMessage.style.display = 'none';
                }
            }
        }

        // Theme Toggle JavaScript
        document.addEventListener('DOMContentLoaded', () => {
            const themeToggle = document.getElementById('theme-toggle');
            // const themeIcon = document.getElementById('theme-icon'); // Not directly needed if CSS handles icon via ::before
            const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            let currentTheme = localStorage.getItem('theme');
            
            // Re-evaluate theme preference based on localStorage or default (dark)
            if (currentTheme === null) {
                 currentTheme = prefersDark ? 'dark' : 'dark'; // Default to dark if no stored preference
            }

            function setTheme(theme) {
                document.documentElement.classList.toggle('dark-mode', theme === 'dark'); // Apply class to html element
                const newIconChar = theme === 'dark' ? '☀' : '☾';
                document.documentElement.style.setProperty('--current-theme-icon', "'" + newIconChar + "'");
                localStorage.setItem('theme', theme);
            }

            // The inline script in <head> already applied the initial theme class and set the CSS var for the icon.
            // So, no need to set themeIcon.textContent here for initialization.
            
            themeToggle.addEventListener('click', () => {
                // Determine the new theme based on the current state of documentElement
                const newTheme = document.documentElement.classList.contains('dark-mode') ? 'light' : 'dark';
                setTheme(newTheme);
            });
        });
    </script>
</head>
<body>
    <div class="container">
        <div class="left-column">
            <h2><a href="index.php">IP Lists</a></h2>
            <!-- List creation form -->
            <form action="" method="post">
                <input type="text" name="new_list_name" placeholder="New list name" required>
                <input type="submit" name="action" value="Create List">
            </form>
            <!-- List of existing lists -->
            <ul>
                <?php foreach ($all_lists as $list_name): ?>
                    <li>
                        <a href="?list=<?php echo urlencode($list_name); ?>">
                            <?php echo htmlspecialchars($list_name); ?>
                        </a>
                        <div class="list-actions-container"> <!-- Container for buttons -->
                            <form action="" method="post" onsubmit="return confirm('Are you sure you want to DELETE list \'<?php echo htmlspecialchars(addslashes($list_name)); ?>\'? This cannot be undone.');" style="display: inline-block; margin: 0;">
                                <input type="hidden" name="list_name" value="<?php echo htmlspecialchars($list_name); ?>">
                                <input type="submit" name="action" value="Delete List" class="list-action-button delete-button">
                            </form>
                            <form action="" method="post" onsubmit="return confirm('Are you sure you want to CLEAR all entries from list \'<?php echo htmlspecialchars(addslashes($list_name)); ?>\'? This cannot be undone.');" style="display: inline-block; margin: 0;">
                                <input type="hidden" name="list_name" value="<?php echo htmlspecialchars($list_name); ?>">
                                <input type="submit" name="action" value="Clear List" class="list-action-button clear-list-button">
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="theme-toggle-container">
                <button id="theme-toggle" title="Toggle theme">
                    <span id="theme-icon"></span> <!-- Icon content will be set by CSS -->
                </button>
            </div>
            <!-- GitHub Link -->
            <div class="github-link-container">
                <a href="https://github.com/ashes00/PFLMT" target="_blank" rel="noopener noreferrer" title="ashes00">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 16 16" class="github-icon">
                        <path fill-rule="evenodd" d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/>
                    </svg>
                </a>
            </div>
        </div>
        <div class="right-column">
            <?php if ($message): ?>
                <div class="message <?php echo htmlspecialchars($message_type); ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if ($selected_list): ?>
                <h2>List: <?php echo htmlspecialchars($selected_list); ?></h2>

                <?php if ($list_url): ?>
                    <p>Direct URL:</p>
                    <div class="list-url-box">
                        <a href="<?php echo htmlspecialchars($list_url); ?>" target="_blank"><?php echo htmlspecialchars($list_url); ?></a>
                    </div>
                <?php else: ?>
                     <p>Could not determine direct URL for this list. Ensure the 'lists' directory is web-accessible.</p>
                <?php endif; ?>


                <h3>IP Addresses</h3>

                <!-- Add IP Form -->
                <form action="" method="post">
                    <input type="hidden" name="list_name" value="<?php echo htmlspecialchars($selected_list); ?>">
                    <textarea name="ip_to_add" placeholder="Enter IP addresses, one per line" required></textarea>
                    <input type="submit" name="action" value="Add IP">
                </form>

                <!-- Search IP Field -->
                <div>
                    <input type="text" id="search-ip-input" placeholder="Search for IP in this list..." onkeyup="filterIPs()">
                </div>

                <!-- IP List Container with Scrollbar -->
                <div class="ip-list-container">
                    <!-- List of IPs -->
                    <div id="no-search-results" class="no-search-results">No matching IPs found.</div>
                    <?php if (!empty($ips_in_selected_list)): ?>
                        <ul class="ip-list">
                            <?php foreach ($ips_in_selected_list as $index => $entry): ?>
                                <?php $entry_element_id = 'entry-' . $index . '-' . md5($entry); // Unique ID using index and content hash ?>
                                <li class="ip-list-item" id="<?php echo $entry_element_id; ?>">
                                    <div class="view-mode">
                                        <span><?php echo htmlspecialchars($entry); ?></span>
                                        <div>
                                            <button type="button" onclick="toggleEdit('<?php echo $entry_element_id; ?>')">Edit</button>
                                            <form action="" method="post" onsubmit="return confirm('Are you sure you want to remove entry \'<?php echo htmlspecialchars(addslashes($entry)); ?>\'?');" style="display: inline;">
                                                <input type="hidden" name="list_name" value="<?php echo htmlspecialchars($selected_list); ?>">
                                                <input type="hidden" name="ip_to_remove" value="<?php echo htmlspecialchars($entry); ?>">
                                                <button type="submit" name="action" value="Remove IP" class="delete-button">Remove</button>
                                            </form>
                                        </div>
                                    </div>
                                    <div class="edit-form">
                                         <form action="" method="post" style="display: inline; flex-grow: 1; display: flex; align-items: center;">
                                            <input type="hidden" name="list_name" value="<?php echo htmlspecialchars($selected_list); ?>">
                                            <input type="hidden" name="old_ip" value="<?php echo htmlspecialchars($entry); ?>">
                                            <input type="text" name="new_ip" value="<?php echo htmlspecialchars($entry); ?>" required>
                                            <button type="submit" name="action" value="Edit IP">Save</button>
                                            <button type="button" onclick="toggleEdit('<?php echo $entry_element_id; ?>')">Cancel</button>
                                         </form>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p>This list is currently empty.</p>
                    <?php endif; ?>
                </div> <!-- End ip-list-container -->

            <?php else: ?>
                <p>Select a list from the left or create a new one.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
