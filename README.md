## IP List Manager

<p align="center">
  <img src="./images/screen.jpeg" alt="PFLMT Screenshot" width="50%" style="max-width: 100%; height: auto;">
</p>

This is a simple, single-file PHP web application for managing lists of IP addresses, CIDR blocks, and comments stored in plain text files.

### Requirements

To run this application, you need:

*   A web server (like Apache, Nginx, or Caddy) configured to serve PHP files.
*   PHP installed (version 7.0 or higher is recommended).
*   The web server process must have **write permissions** to the directory where `index.php` is located, specifically to create and modify the `lists/` subdirectory and the `.txt` files within it.

### Setup

1.  Place the `index.php` file in a directory accessible by your web server.
2.  Ensure the web server has write permissions to this directory.
3.  The application will automatically attempt to create a `lists/` subdirectory in the same location as `index.php` if it doesn't exist. If this fails due to permissions, you may need to create the `lists/` directory manually and ensure the web server can write to it.
4.  Access the application through your web browser by navigating to the URL where you placed `index.php`.

### Features

*   **List Management:** Create and delete multiple named lists.
*   **Entry Management:** Add, edit, and remove entries within a selected list.
*   **Multi-line Add:** Paste multiple entries (IPs, CIDRs, comments) into a textarea to add them in bulk.
*   **CIDR Support:** Accepts and displays IPv4 CIDR blocks (e.g., `192.168.1.0/24`).
*   **Comment Support:**
    *   Full-line comments starting with `#` are supported and preserved.
    *   Inline comments starting with ` # ` (space-hash-space) or `\t#\t` (tab-hash-tab) after an IP or CIDR are supported and preserved.
*   **Order Preservation:** Entries are stored and displayed in the exact order they are added. No automatic sorting is applied.
*   **Scrollable IP List:** The list of entries has its own scrollbar for easy navigation of long lists without scrolling the entire page.
*   **Client-Side Search:** Quickly filter the displayed list entries by typing in the search box.
*   **Theme Toggle:** Switch between a light and dark mode interface. Your preference is saved in your browser's local storage. Dark mode is the default theme.
*   **Clear List:** Empty the contents of a list without deleting the list file itself.

### How to Use

1.  **Creating a List:**
    *   In the left column, enter a name for your new list in the "New list name" field.
    *   Click the "Create List" button.
    *   The new list will appear in the list below.

2.  **Selecting a List:**
    *   Click on a list name in the left column.
    *   The right column will update to show the contents and management options for that list.
    *   Clicking "IP Lists" at the top of the left column will return to the main view without a list selected.

3.  **Adding Entries:**
    *   Select a list in the left column.
    *   In the right column, use the "Add IP Addresses" textarea. You can enter single IPs, CIDRs, full-line comments (starting with `#`), or IPs/CIDRs with inline comments (separated by ` # ` or `\t#\t`), one entry per line.
    *   Click the "Add IP" button. Valid entries will be added to the list, preserving their order. Invalid entries will be reported in a message.

4.  **Editing Entries:**
    *   Select a list.
    *   Find the entry you want to edit in the list.
    *   Click the "Edit" button next to the entry.
    *   The entry will become an editable text field. Make your changes.
    *   Click "Save" to update the entry or "Cancel" to discard changes.

5.  **Removing Entries:**
    *   Select a list.
    *   Find the entry you want to remove.
    *   Click the "Remove" button next to the entry.
    *   Confirm the removal in the dialog box.

6.  **Clearing a List:**
    *   In the left column, find the list you want to clear.
    *   Click the "Clear List" button next to the list name.
    *   Confirm the action in the dialog box. All entries will be removed from the list.

7.  **Deleting a List:**
    *   In the left column, find the list you want to delete.
    *   Click the "Delete List" button next to the list name.
    *   Confirm the action in the dialog box. The list file will be permanently deleted.

8.  **Searching Entries:**
    *   Select a list.
    *   Type into the "Search for IP in this list..." field in the right column.
    *   The list below will automatically filter to show only entries containing the text you type.

9.  **Theme Toggle:**
    *   Click the sun (☀) or moon (☾) icon in the bottom left corner of the left column to switch between light and dark modes.

### File Structure

```
/path/to/your/webroot/
└── PFLMT-2/
    ├── index.php
    └── lists/
        ├── listname1.txt
        ├── listname2.txt
        └── ...
```

The `lists/` directory is automatically created and contains your list files as plain text files, with one entry per line, preserving order and comments.

---

*Note: This is a basic application suitable for internal use or small-scale management. It lacks advanced security features like user authentication or robust input sanitization beyond basic format checks. Ensure the `lists/` directory is not directly accessible via the web if it contains sensitive information.*