<?php
// book_import_service.php
require_once 'config.php';

class BookImportService {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function fetchBooksFromAPI($page = 1, $limit = 36) {
        $apiUrl = GUTENDEX_API . "?languages=en&mime_type=application/pdf&page={$page}";
        $json = @file_get_contents($apiUrl);
        
        if ($json === false) {
            error_log("Failed to fetch books from API");
            return [];
        }
        
        $data = json_decode($json, true);
        return isset($data['results']) ? array_slice($data['results'], 0, $limit) : [];
    }
    
    public function pickCover($formats, $title) {
        // Priority 1: Direct image links
        if (isset($formats['image/jpeg']) && filter_var($formats['image/jpeg'], FILTER_VALIDATE_URL)) {
            return $formats['image/jpeg'];
        }
        
        // Priority 2: Any image format
        foreach ($formats as $mime => $url) {
            if (!$url) continue;
            if (stripos($mime, 'image') !== false) return $url;
        }
        
        // Priority 3: Open Library fallback
        $encoded = rawurlencode($title);
        return "https://covers.openlibrary.org/b/title/{$encoded}-L.jpg";
    }
    
    public function findPDF($formats) {
        foreach ($formats as $mime => $url) {
            if (!$url) continue;
            
            // Check MIME type
            if (stripos($mime, 'pdf') !== false) return $url;
            
            // Check file extension
            $path = parse_url($url, PHP_URL_PATH) ?: '';
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($ext === 'pdf') return $url;
        }
        return null;
    }
    
    public function extractAuthors($bookData) {
        $authors = [];
        if (!empty($bookData['authors'])) {
            foreach ($bookData['authors'] as $author) {
                $authors[] = [
                    'name' => $author['name'] ?? 'Unknown',
                    'birth_year' => $author['birth_year'] ?? null,
                    'death_year' => $author['death_year'] ?? null
                ];
            }
        }
        return $authors;
    }
    
    public function extractGenres($bookData) {
        $genres = [];
        if (!empty($bookData['subjects'])) {
            $genres = array_slice($bookData['subjects'], 0, 5);
        }
        return $genres;
    }
    
    public function extractDescription($bookData) {
        if (!empty($bookData['subjects'])) {
            return implode(', ', array_slice($bookData['subjects'], 0, 4));
        }
        return 'Classic literature from Project Gutenberg';
    }
    
    public function importBooks($books) {
        $imported = 0;
        
        foreach ($books as $book) {
            $title = $book['title'] ?? 'Untitled';
            $authors = $this->extractAuthors($book);
            $primaryAuthor = !empty($authors) ? $authors[0]['name'] : 'Unknown Author';
            $description = $this->extractDescription($book);
            $pdfUrl = $this->findPDF($book['formats'] ?? []);
            $coverUrl = $this->pickCover($book['formats'] ?? [], $title);
            $genres = $this->extractGenres($book);
            
            if (empty($pdfUrl)) continue;
            
            try {
                // Check if book already exists
                $stmt = $this->pdo->prepare("SELECT book_id FROM books WHERE title = ? AND author = ?");
                $stmt->execute([$title, $primaryAuthor]);
                
                if ($stmt->rowCount() === 0) {
                    // Insert book
                    $stmt = $this->pdo->prepare("INSERT INTO books (title, author, description, cover_url, file_type, is_public_domain, created_at) 
                                               VALUES (?, ?, ?, ?, 'pdf', TRUE, NOW())");
                    $stmt->execute([$title, $primaryAuthor, $description, $coverUrl]);
                    $bookId = $this->pdo->lastInsertId();
                    
                    // Insert PDF file
                    $stmt = $this->pdo->prepare("INSERT INTO book_files (book_id, file_type, file_url) 
                                               VALUES (?, 'pdf', ?)");
                    $stmt->execute([$bookId, $pdfUrl]);
                    
                    // Insert genres
                    foreach ($genres as $genre) {
                        $stmt = $this->pdo->prepare("INSERT INTO book_genres (book_id, genre) VALUES (?, ?)");
                        $stmt->execute([$bookId, $genre]);
                    }
                    
                    // Insert authors
                    foreach ($authors as $author) {
                        $stmt = $this->pdo->prepare("INSERT INTO book_authors (book_id, author_name, author_birth_year, author_death_year) 
                                                   VALUES (?, ?, ?, ?)");
                        $stmt->execute([$bookId, $author['name'], $author['birth_year'], $author['death_year']]);
                    }
                    
                    $imported++;
                }
            } catch(PDOException $e) {
                error_log("Error importing book '{$title}': " . $e->getMessage());
            }
        }
        
        return $imported;
    }
    
    public function syncBooks($page = 1, $limit = 36) {
        $books = $this->fetchBooksFromAPI($page, $limit);
        return $this->importBooks($books);
    }
}

// Function to get book service instance
function getBookService() {
    global $pdo;
    return new BookImportService($pdo);
}
?>