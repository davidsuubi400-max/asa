<?php
/**
 * upload.php — BookShelf backend (scaffold)
 * Handles: file upload, validation, DB insert
 *
 * SETUP:
 *  1. Create a MySQL database and run schema.sql
 *  2. Fill in DB credentials below
 *  3. Create an /uploads/ folder and make it writable (chmod 755)
 *  4. Point your form's fetch() POST to this file
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// ─── CONFIG ────────────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_NAME', 'bookshelf');
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('MAX_FILE_SIZE', 50 * 1024 * 1024); // 50 MB

// ─── ONLY ACCEPT POST ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ─── READ + VALIDATE FIELDS ────────────────────────────────────────────
$title    = trim($_POST['title']    ?? '');
$author   = trim($_POST['author']   ?? '');
$category = trim($_POST['category'] ?? '');
$desc     = trim($_POST['desc']     ?? '');

$validCategories = ['science', 'arts', 'tech', 'business', 'medicine', 'law'];

if (!$title)                             respond(false, 'Title is required.');
if (!$author)                            respond(false, 'Author is required.');
if (!in_array($category, $validCategories)) respond(false, 'Invalid category.');
if (empty($_FILES['book_file']))         respond(false, 'No file uploaded.');

// ─── VALIDATE PDF ──────────────────────────────────────────────────────
$file = $_FILES['book_file'];

if ($file['error'] !== UPLOAD_ERR_OK)    respond(false, 'Upload error: ' . $file['error']);
if ($file['size'] > MAX_FILE_SIZE)       respond(false, 'File exceeds 50 MB limit.');

// Verify MIME type (don't trust extension alone)
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']);
if ($mime !== 'application/pdf')         respond(false, 'Only PDF files are accepted.');

// ─── SAVE PDF ──────────────────────────────────────────────────────────
if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

$filename = uniqid('book_', true) . '.pdf';
$dest     = UPLOAD_DIR . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    respond(false, 'Could not save file. Check server permissions.');
}

// ─── OPTIONAL COVER IMAGE ──────────────────────────────────────────────
$coverFilename = null;
if (!empty($_FILES['cover']) && $_FILES['cover']['error'] === UPLOAD_ERR_OK) {
    $coverMime = $finfo->file($_FILES['cover']['tmp_name']);
    if (in_array($coverMime, ['image/jpeg', 'image/png'])) {
        $ext           = $coverMime === 'image/png' ? '.png' : '.jpg';
        $coverFilename = uniqid('cover_', true) . $ext;
        move_uploaded_file($_FILES['cover']['tmp_name'], UPLOAD_DIR . $coverFilename);
    }
}

// ─── SAVE TO DATABASE ──────────────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $pdo->prepare("
        INSERT INTO books (title, author, category, description, file_path, cover_path, uploaded_at)
        VALUES (:title, :author, :category, :desc, :file_path, :cover_path, NOW())
    ");

    $stmt->execute([
        ':title'      => $title,
        ':author'     => $author,
        ':category'   => $category,
        ':desc'       => $desc,
        ':file_path'  => $filename,
        ':cover_path' => $coverFilename,
    ]);

    respond(true, 'Book uploaded successfully.', ['id' => $pdo->lastInsertId()]);

} catch (PDOException $e) {
    // Don't expose DB details to client
    error_log('BookShelf DB error: ' . $e->getMessage());
    respond(false, 'Database error. Please try again.');
}

// ─── HELPER ────────────────────────────────────────────────────────────
function respond(bool $success, string $message, array $data = []): void {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}
