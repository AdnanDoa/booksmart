CREATE DATABASE IF NOT EXISTS audiobook_platform 
  CHARACTER SET utf8mb4 
  COLLATE utf8mb4_general_ci;

USE audiobook_platform;

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";
SET NAMES utf8mb4;


-- --------------------------------------------------------
-- Table structure for table `authors`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `authors`;
CREATE TABLE IF NOT EXISTS `authors` (
  `author_id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `bio` text,
  `email` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`author_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

 

-- --------------------------------------------------------
-- Table structure for table `books`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `books`;
CREATE TABLE IF NOT EXISTS `books` (
  `book_id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(500) NOT NULL,
  `author_id` int DEFAULT NULL,
  `narrator_id` int DEFAULT NULL,
  `genre_id` int DEFAULT NULL,
  `description` text,
  `duration` int DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  -- ADDED FIELDS FOR PDF BOOKS
  `author` varchar(255) DEFAULT NULL,
  `cover_url` varchar(500) DEFAULT NULL,
  `file_type` enum('audio','pdf','ebook') DEFAULT 'audio',
  `publish_year` int DEFAULT NULL,
  `language` varchar(50) DEFAULT 'English',
  `is_public_domain` boolean DEFAULT FALSE,
  PRIMARY KEY (`book_id`),
  KEY `narrator_id` (`narrator_id`),
  KEY `idx_books_author` (`author_id`),
  KEY `idx_books_genre` (`genre_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- --------------------------------------------------------
-- Table structure for table `book_files`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `book_files`;
CREATE TABLE IF NOT EXISTS `book_files` (
  `file_id` int NOT NULL AUTO_INCREMENT,
  `book_id` int NOT NULL,
  `file_type` enum('mp3','wav','m4a','flac','pdf') NOT NULL,
  `file_url` varchar(500) NOT NULL,
  `file_size` bigint DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`file_id`),
  KEY `book_id` (`book_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `genres`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `genres`;
CREATE TABLE IF NOT EXISTS `genres` (
  `genre_id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`genre_id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- --------------------------------------------------------
-- Table structure for table `narrators`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `narrators`;
CREATE TABLE IF NOT EXISTS `narrators` (
  `narrator_id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `bio` text,
  PRIMARY KEY (`narrator_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- --------------------------------------------------------
-- Table structure for table `recommendations_log`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `recommendations_log`;
CREATE TABLE IF NOT EXISTS `recommendations_log` (
  `log_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `book_id` int NOT NULL,
  `action` enum('viewed','clicked','purchased') NOT NULL,
  `timestamp` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `user_id` (`user_id`),
  KEY `book_id` (`book_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `reviews`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `reviews`;
CREATE TABLE IF NOT EXISTS `reviews` (
  `review_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `book_id` int NOT NULL,
  `rating` tinyint DEFAULT NULL,
  `comment` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`review_id`),
  UNIQUE KEY `unique_user_book_review` (`user_id`,`book_id`),
  KEY `idx_reviews_book` (`book_id`),
  KEY `idx_reviews_rating` (`rating`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `subscriptions`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `subscriptions`;
CREATE TABLE IF NOT EXISTS `subscriptions` (
  `subscription_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `type` enum('monthly','annual','lifetime') NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` enum('active','expired','cancelled') DEFAULT 'active',
  PRIMARY KEY (`subscription_id`),
  KEY `idx_subscriptions_user` (`user_id`),
  KEY `idx_subscriptions_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `transactions`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `transactions`;
CREATE TABLE IF NOT EXISTS `transactions` (
  `transaction_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `book_id` int NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `transaction_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `payment_method` enum('credit_card','paypal','stripe','wallet') NOT NULL,
  PRIMARY KEY (`transaction_id`),
  KEY `book_id` (`book_id`),
  KEY `idx_transactions_user` (`user_id`),
  KEY `idx_transactions_date` (`transaction_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `users`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
  `user_id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('user','admin','moderator') DEFAULT 'user',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `subscription_type` enum('free','premium','family') DEFAULT 'free',
  `subscription_expiry` date DEFAULT NULL,
  `avatar_url` varchar(500) NOT NULL,
  `bio` varchar(255) DEFAULT "this is my bio",
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `user_library`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `user_library`;
CREATE TABLE IF NOT EXISTS `user_library` (
  `user_id` int NOT NULL,
  `book_id` int NOT NULL,
  `status` enum('reading','completed','wishlist','purchased') DEFAULT 'purchased',
  `progress` int DEFAULT '0' COMMENT 'Percentage completed (0-100)',
  `highlighted_passages` json DEFAULT NULL,
  `last_opened` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`,`book_id`),
  KEY `idx_user_library_user` (`user_id`),
  KEY `idx_user_library_book` (`book_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- NEW TABLES FOR PDF BOOK SUPPORT
-- --------------------------------------------------------

-- Table structure for table `book_genres` (for multiple genres per book)
DROP TABLE IF EXISTS `book_genres`;
CREATE TABLE IF NOT EXISTS `book_genres` (
  `id` int NOT NULL AUTO_INCREMENT,
  `book_id` int NOT NULL,
  `genre` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`book_id`) REFERENCES `books`(`book_id`) ON DELETE CASCADE,
  KEY `idx_book_genres_book` (`book_id`),
  KEY `idx_book_genres_genre` (`genre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table structure for table `book_subjects` (for Project Gutenberg subjects)
DROP TABLE IF EXISTS `book_subjects`;
CREATE TABLE IF NOT EXISTS `book_subjects` (
  `id` int NOT NULL AUTO_INCREMENT,
  `book_id` int NOT NULL,
  `subject` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`book_id`) REFERENCES `books`(`book_id`) ON DELETE CASCADE,
  KEY `idx_book_subjects_book` (`book_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table structure for table `book_authors` (for multiple authors)
DROP TABLE IF EXISTS `book_authors`;
CREATE TABLE IF NOT EXISTS `book_authors` (
  `id` int NOT NULL AUTO_INCREMENT,
  `book_id` int NOT NULL,
  `author_name` varchar(255) NOT NULL,
  `author_birth_year` int DEFAULT NULL,
  `author_death_year` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`book_id`) REFERENCES `books`(`book_id`) ON DELETE CASCADE,
  KEY `idx_book_authors_book` (`book_id`),
  KEY `idx_book_authors_name` (`author_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;