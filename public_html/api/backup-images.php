<?php
/**
 * Backup/Restore Images Helper using ZipArchive
 * GET /api/backup-images.php?action=backup  - creates & downloads zip
 * POST /api/backup-images.php?action=restore - restores uploaded zip
 */

$binPicsDir = dirname(__DIR__) . '/bin-pics';

// Ensure bin-pics directory exists
if (!is_dir($binPicsDir)) {
    @mkdir($binPicsDir, 0755, true);
}

// Recursive function to add files to zip
function addDirToZip($dir, $zip, $basePath = '') {
    $items = @scandir($dir);
    if (!$items) return 0;
    
    $count = 0;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        
        $path = $dir . '/' . $item;
        $zipPath = ($basePath ? $basePath . '/' : '') . $item;
        
        if (is_dir($path)) {
            $count += addDirToZip($path, $zip, $zipPath);
        } elseif (is_file($path)) {
            // Skip .gitkeep and .htaccess
            if ($item === '.gitkeep' || $item === '.htaccess') continue;
            
            if ($zip->addFile($path, $zipPath)) {
                $count++;
            }
        }
    }
    return $count;
}

// Action: Backup - create zip of all images
if ($_GET['action'] === 'backup') {
    $zipPath = tempnam(sys_get_temp_dir(), 'backup_');
    $zip = new ZipArchive();
    
    if (!$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create zip file']);
        exit;
    }
    
    // Recursively add all files from bin-pics (excluding .gitkeep and .htaccess)
    $fileCount = addDirToZip($binPicsDir, $zip, 'bin-pics');
    $zip->close();
    
    if ($fileCount > 0) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="bin-pics-backup.zip"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    } else {
        // Return empty zip if no files
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="bin-pics-backup.zip"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    }
}

// Action: Restore - extract uploaded zip
if ($_GET['action'] === 'restore') {
    if (!isset($_FILES['zip']) || $_FILES['zip']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'No zip file uploaded']);
        exit;
    }
    
    $zipPath = $_FILES['zip']['tmp_name'];
    $zip = new ZipArchive();
    
    if (!$zip->open($zipPath)) {
        http_response_code(400);
        echo json_encode(['error' => 'Failed to open zip file']);
        @unlink($zipPath);
        exit;
    }
    
    // Extract to the public_html directory (will restore bin-pics/ subdirectory)
    $rootDir = dirname(__DIR__);
    if (!$zip->extractTo($rootDir)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to extract zip file']);
        $zip->close();
        @unlink($zipPath);
        exit;
    }
    
    $zip->close();
    @unlink($zipPath);
    
    http_response_code(200);
    echo json_encode(['success' => 'Images restored successfully']);
    exit;
}

// Default
http_response_code(400);
echo json_encode(['error' => 'Invalid action']);

?>
