<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h2>Running Book Import</h2>";


require_once 'book_import_service.php';


$bookService = getBookService();


echo "<p>Starting book import...</p>";
$importedCount = $bookService->syncBooks(1, 36);

echo "<p style='color: green;'>✅ Successfully imported $importedCount books!</p>";
echo "<p><a href='catalog.php'>View Books in Catalog</a></p>";
?>