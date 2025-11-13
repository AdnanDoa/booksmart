<?php
ini_set('display_errors',1);
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

echo "DB debug test\n";
echo "=================\n";
echo "PHP version: " . PHP_VERSION . "\n";
echo "mysqli extension loaded: " . (extension_loaded('mysqli') ? 'yes' : 'no') . "\n";

$path = __DIR__ . '/db_connect.php';
echo "db_connect.php exists: " . (file_exists($path) ? 'yes' : 'no') . "\n";

// Try to include and inspect $conn
try {
    // Snapshot defined vars before include
    $vars_before = array_keys(get_defined_vars());
    $real = realpath($path);
    echo "realpath: " . ($real ?: '(realpath failed)') . "\n";
    echo "is_readable: " . (is_readable($path) ? 'yes' : 'no') . "\n";
    // show the first 200 characters of the file to ensure it's the expected file
    $content = @file_get_contents($path);
    if ($content === false) {
        echo "file_get_contents: failed to read file\n";
    } else {
        echo "file head: \n" . substr($content, 0, 400) . "\n--- end file head ---\n";
    }

    require_once $path;
} catch (Throwable $e) {
    echo "Include threw: " . $e->getMessage() . "\n";
}

if (isset($conn)) {
    echo "\$conn is set. Type: " . gettype($conn) . "\n";
    if ($conn instanceof mysqli) {
        echo "-> mysqli object detected\n";
        echo "connect_errno: " . $conn->connect_errno . "\n";
        echo "connect_error: " . ($conn->connect_error ?? '(none)') . "\n";
        echo "host_info: " . ($conn->host_info ?? '(none)') . "\n";
    } else {
        echo "-> \$conn is not a mysqli instance. Dumping var:\n";
        var_export($conn);
        echo "\n";
    }
} else {
    echo "\$conn is NOT set after including db_connect.php\n";
}

// Show which files PHP thinks have been included
$inc = get_included_files();
echo "\nIncluded files (" . count($inc) . "):\n";
foreach ($inc as $f) {
    echo " - " . $f . "\n";
}

// Show auto_prepend_file directive which can alter include behaviour
$apf = ini_get('auto_prepend_file');
echo "\nauto_prepend_file: " . ($apf ?: '(none)') . "\n";

// Show variable snapshot diffs
$vars_after = array_keys(get_defined_vars());
$new_vars = array_diff($vars_after, $vars_before);
echo "\nNew variables introduced by include (count=" . count($new_vars) . "):\n";
foreach ($new_vars as $v) {
    echo " - " . $v . "\n";
}

// If mysqli not loaded, show loaded extensions for diagnosis
if (!extension_loaded('mysqli')) {
    echo "\nLoaded extensions:\n";
    print_r(get_loaded_extensions());
}

echo "\nDone.\n";

?>
