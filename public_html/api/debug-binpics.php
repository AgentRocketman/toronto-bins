<?php
$binPicsDir = dirname(__DIR__) . '/bin-pics';
echo "Checking: $binPicsDir\n";
echo "Is dir: " . (is_dir($binPicsDir) ? 'YES' : 'NO') . "\n";
echo "Readable: " . (is_readable($binPicsDir) ? 'YES' : 'NO') . "\n";
echo "\nContents:\n";
$items = @scandir($binPicsDir);
if ($items) {
    foreach ($items as $item) {
        $path = $binPicsDir . '/' . $item;
        $type = is_dir($path) ? 'DIR' : 'FILE';
        $size = is_file($path) ? filesize($path) : 'N/A';
        echo "  [$type] $item ($size bytes)\n";
    }
} else {
    echo "  (scandir failed)\n";
}
?>
