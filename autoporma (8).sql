-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 09, 2026 at 03:19 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `autoporma`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_trail`
--

CREATE TABLE `audit_trail` (
  `log_id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `username` varchar(150) NOT NULL,
  `action_type` varchar(150) NOT NULL,
  `action_category` varchar(100) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `ip_address` varchar(50) DEFAULT NULL,
  `log_time` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_trail`
--

INSERT INTO `audit_trail` (`log_id`, `user_id`, `username`, `action_type`, `action_category`, `details`, `metadata`, `ip_address`, `log_time`) VALUES
(0, 1, 'Chrysler', 'Login', 'Authentication', 'User logged in successfully.', '[]', '::1', '2026-01-25 08:26:07'),
(0, 1, 'Chrysler', 'Login', 'Authentication', 'User logged in successfully.', '[]', '::1', '2026-01-25 16:54:59'),
(0, 1, 'Chrysler', 'Create Sale', 'Sales', 'Processed Sale #10 for Minji. Total: 536.00', '[]', '::1', '2026-01-25 16:56:58'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Stock for \'Enoki\' updated from 4 to 0.', '[]', '::1', '2026-01-25 16:57:10'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Stock for \'Enoki\' updated from 0 to 3.', '[]', '::1', '2026-01-25 16:57:13'),
(0, 1, 'Chrysler', 'Login', 'Authentication', 'User logged in successfully.', '[]', '::1', '2026-01-26 02:13:15'),
(0, 1, 'Chrysler', 'Bulk Action', 'Products', 'Performed \'deactivate\' on 5 items.', '[]', '::1', '2026-01-26 04:30:49'),
(0, 1, 'Chrysler', 'Bulk Action', 'Products', 'Performed \'activate\' on 5 items.', '[]', '::1', '2026-01-26 04:30:54'),
(0, 1, 'Chrysler', 'Bulk Action', 'Products', 'Activated 5 products.', '[]', '::1', '2026-01-26 04:33:09'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Stock for \'Enoki\' updated from 3 to 11.', '[]', '::1', '2026-01-26 04:33:21'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Quick update stock for ID 16 to 10.', '[]', '::1', '2026-01-26 04:40:40'),
(0, 1, 'Chrysler', 'Delete Product', 'Products', 'Deleted product \'Enoki\'.', '[]', '::1', '2026-01-26 04:40:50'),
(0, 1, 'Chrysler', 'Bulk Action', 'Products', 'Bulk Activated 4 products.', '[]', '::1', '2026-01-26 04:40:59'),
(0, 1, 'Chrysler', 'Bulk Action', 'Products', 'Bulk Deactivated 4 products.', '[]', '::1', '2026-01-26 04:41:07'),
(0, 1, 'Chrysler', 'Bulk Action', 'Products', 'Bulk Deleted 2 products.', '[]', '::1', '2026-01-26 04:41:24'),
(0, 1, 'Chrysler', 'Bulk Action', 'Products', 'Bulk Activated 2 products.', '[]', '::1', '2026-01-26 04:41:52'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Quick update stock for ID 9 to 12.', '[]', '::1', '2026-01-26 04:41:55'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Quick update stock for ID 9 to 9.', '[]', '::1', '2026-01-26 04:41:57'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Quick update stock for ID 9 to 11.', '[]', '::1', '2026-01-26 04:43:02'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Quick update stock for ID 12 to 9.', '[]', '::1', '2026-01-26 04:43:16'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Quick update stock for ID 12 to 10.', '[]', '::1', '2026-01-26 04:43:22'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Quick update stock for ID 12 to 0.', '[]', '::1', '2026-01-26 04:43:25'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Quick update stock for ID 12 to 1.', '[]', '::1', '2026-01-26 04:43:33'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Quick update stock for ID 12 to 112.', '[]', '::1', '2026-01-26 04:43:33'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Quick update stock for ID 9 to 9.', '[]', '::1', '2026-01-26 04:44:48'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated Product \'Lauma Milk\': Price changed from 120.00 to 1120, Stock updated from 9 to 91.', '{\"changes\":{\"Price\":{\"old\":\"120.00\",\"new\":1120},\"Stock\":{\"old\":9,\"new\":91}}}', '::1', '2026-01-26 04:45:55'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Stock for \'Lauma Milk\' updated to 0.', '[]', '::1', '2026-01-26 17:47:41'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Stock for \'Lauma Milk\' updated to 7.', '[]', '::1', '2026-01-26 17:47:46'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Stock for \'Lauma Milk\' updated to 0.', '[]', '::1', '2026-01-26 17:48:00'),
(0, 1, 'Chrysler', 'Login', 'Authentication', 'User logged in successfully.', '[]', '::1', '2026-01-26 17:50:51'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Stock for \'Lauma Milk\' updated to 1.', '[]', '::1', '2026-01-26 17:57:18'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Stock for \'Lauma Milk\' updated to 3.', '[]', '::1', '2026-01-26 17:57:30'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Stock for \'Lauma Milk\' updated to 6.', '[]', '::1', '2026-01-26 17:59:29'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Stock for \'Lauma Milk\' updated to 11.', '[]', '::1', '2026-01-26 17:59:32'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Stock for \'Lauma Milk\' updated to 8.', '[]', '::1', '2026-01-26 17:59:39'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Stock for \'Lauma Milk\' updated to 6.', '[]', '::1', '2026-01-26 18:04:01'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated Product \'Lauma Milk\'', '[]', '::1', '2026-01-26 18:11:38'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Stock for \'Lauma Milk\' updated to 4.', '[]', '::1', '2026-01-27 06:55:10'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Stock for \'Lauma Milk\' updated to 0.', '[]', '::1', '2026-01-27 06:55:15'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Stock for \'Lauma Milk\' updated to 1.', '[]', '::1', '2026-01-27 06:55:26'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Stock for \'Lauma Milk\' updated to 2.', '[]', '::1', '2026-01-27 06:56:05'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Stock for \'Lauma Milk\' updated to 3.', '[]', '::1', '2026-01-27 06:56:06'),
(0, 1, 'Chrysler', 'Login', 'Authentication', 'User logged in successfully.', '[]', '::1', '2026-01-27 07:03:37'),
(0, 1, 'Chrysler', 'Login', 'Authentication', 'User logged in successfully.', '[]', '::1', '2026-01-27 12:35:38'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Saved product \'Lauma Milk\'.', '[]', '::1', '2026-01-27 12:48:05'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Saved product \'Lauma Milk\'.', '[]', '::1', '2026-01-27 12:48:22'),
(0, 1, 'Chrysler', 'Add Product', 'Products', 'Saved product \'Egg\'.', '[]', '::1', '2026-01-27 12:51:33'),
(0, 1, 'Chrysler', 'Add Product', 'Products', 'Saved product \'Kalabasa\'.', '[]', '::1', '2026-01-27 12:52:00'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Saved product \'Kalabasa\'.', '[]', '::1', '2026-01-27 12:52:07'),
(0, 1, 'Chrysler', 'Add Product', 'Products', 'Saved product \'Enoki\'.', '[]', '::1', '2026-01-27 12:52:41'),
(0, 1, 'Chrysler', 'Add Product', 'Products', 'Saved product \'Test for staff\'.', '[]', '::1', '2026-01-27 12:53:07'),
(0, 2, 'Makima', 'Login', 'Authentication', 'User logged in successfully.', '[]', '::1', '2026-01-27 13:03:01'),
(0, 2, 'Makima', 'Logout', 'Authentication', 'User logged out.', '[]', '::1', '2026-01-27 13:05:52'),
(0, 1, 'Chrysler', 'Update User', 'Users', 'Updated user: Yae (Active)', '[]', '::1', '2026-01-27 13:06:24'),
(0, 3, 'Yae', 'Login', 'Authentication', 'User logged in successfully.', '[]', '::1', '2026-01-27 13:06:31'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Updated stock for \'Test for staff\' from 225 to 222.', '[]', '::1', '2026-01-27 13:15:30'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Updated stock for \'Enoki\' from 0 to 3.', '[]', '::1', '2026-01-27 13:15:48'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated product \'Enoki\'. Stock set to: 3', '[]', '::1', '2026-01-27 13:15:53'),
(0, 3, 'Yae', 'Update Product', 'Products', 'Updated product \'Enoki\'. Stock set to: 33', '[]', '::1', '2026-01-27 13:23:53'),
(0, 3, 'Yae', 'Update Product', 'Products', 'Updated product \'Enoki\'. Stock set to: 331', '[]', '::1', '2026-01-27 13:25:20'),
(0, 3, 'Yae', 'Update Product', 'Products', 'Updated product \'Enoki\'. Stock set to: 331', '[]', '::1', '2026-01-27 13:25:48'),
(0, 3, 'Yae', 'Update Stock', 'Inventory', 'Updated stock for \'Enoki\' from 331 to 328.', '[]', '::1', '2026-01-27 13:26:13'),
(0, 3, 'Yae', 'Bulk Action', 'Products', 'Bulk deleted 6 products.', '[]', '::1', '2026-01-27 13:26:25'),
(0, 3, 'Yae', 'Add Product', 'Products', 'Added new product \'test\'. Initial Stock: 12', '[]', '::1', '2026-01-27 13:26:50'),
(0, 3, 'Yae', 'Update Product', 'Products', 'Updated product \'test\'. Stock set to: 12', '[]', '::1', '2026-01-27 13:27:57'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated \'test\'. Details: Price: 12.00 -> 122.00, Status: Active -> Inactive, Expiry: 2026-02-20 -> 2026-02-28, Category changed, Stock: 12 -> 120', '[]', '::1', '2026-01-27 13:32:42'),
(0, 1, 'Chrysler', 'Bulk Action', 'Products', 'Bulk Activated 1 products.', '[]', '::1', '2026-01-27 13:33:31'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated \'testr\'. Details: Name changed, Category changed', '[]', '::1', '2026-01-27 13:34:48'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated product \'testr\' (No major changes detected).', '[]', '::1', '2026-01-27 13:35:20'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated \'nas\'. Details: Name changed, Brand: \'lol\' -> \'loloi\', Price: 122.00 -> 1,222.00, Status: Active -> Inactive, Expiry: 2026-02-28 -> 2026-03-05, Category changed, Stock: 120 -> 1230', '[]', '::1', '2026-01-27 13:39:42'),
(0, 1, 'Chrysler', 'Bulk Action', 'Products', 'Bulk Activated 1 products.', '[]', '::1', '2026-01-27 13:40:24'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated \'nas\'. Details: Price: 1,222.00 -> 1,000.00, Stock: 1230 -> 10', '[]', '::1', '2026-01-27 13:40:53'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated \'nas\'. Details: Price: 1,000.00 -> 10.00', '[]', '::1', '2026-01-27 13:41:05'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'nas\': 10 -> 11', '[]', '::1', '2026-01-27 13:41:11'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated \'nas\'. Details: Category changed', '[]', '::1', '2026-01-27 13:57:09'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated \'nas\'. Details: Expiry: 2026-03-05 -> 2026-01-30, Category: \'Leafy Greens\' -> \'Garden Vegetables\', Stock: 11 -> 9', '[]', '::1', '2026-01-27 14:01:12'),
(0, 1, 'Chrysler', 'Login', 'Authentication', 'User logged in successfully.', '[]', '::1', '2026-01-27 17:40:43'),
(0, 1, 'Chrysler', 'Delete Product', 'Products', 'Deleted product \'nas\'.', '[]', '::1', '2026-01-28 01:34:03'),
(0, 1, 'Chrysler', 'Add Product', 'Products', 'Added new product \'Lettuce\'. Brand: PedroFarm, Initial Stock: 100', '[]', '::1', '2026-01-28 01:36:18'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated product \'Lettuce\' (No major changes detected).', '[]', '::1', '2026-01-28 01:36:27'),
(0, 1, 'Chrysler', 'Add Product', 'Products', 'Added new product \'Cabbage\'. Brand: SM, Initial Stock: 12', '[]', '::1', '2026-01-28 01:38:27'),
(0, 1, 'Chrysler', 'Add Product', 'Products', 'Added new product \'Carrot\'. Brand: Carrot man, Initial Stock: 5', '[]', '::1', '2026-01-28 01:40:18'),
(0, 1, 'Chrysler', 'Add Product', 'Products', 'Added new product \'White Mushroom\'. Brand: Monterey, Initial Stock: 0', '[]', '::1', '2026-01-28 01:42:36'),
(0, 1, 'Chrysler', 'Add Product', 'Products', 'Added new product \'Chili Peppers\'. Brand: Chilis, Initial Stock: 9', '[]', '::1', '2026-01-28 01:45:06'),
(0, 1, 'Chrysler', 'Add Product', 'Products', 'Added new product \'Milk\'. Brand: Milka, Initial Stock: 10', '[]', '::1', '2026-01-28 01:46:01'),
(0, 1, 'Chrysler', 'Login', 'Authentication', 'User logged in successfully.', '[]', '::1', '2026-01-28 16:25:56'),
(0, 1, 'Chrysler', 'Add Client', 'Clients', 'Added new client \'Jollibee\'.', '[]', '::1', '2026-01-28 16:45:05'),
(0, 1, 'Chrysler', 'Update Client', 'Clients', 'Updated client \'Jollibee\'.', '[]', '::1', '2026-01-28 16:45:09'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'Milk\': 10 -> 9', '[]', '::1', '2026-01-28 17:39:07'),
(0, 1, 'Chrysler', 'Login', 'Authentication', 'User logged in successfully.', '[]', '::1', '2026-01-29 03:53:05'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated \'Carrot\'. Details: Expiry: None -> 2026-01-31', '[]', '::1', '2026-01-29 05:57:59'),
(0, 1, 'Chrysler', 'Add Product', 'Products', 'Added new product \'hanni\'. Brand: Eggknog, Initial Stock: 123', '[]', '::1', '2026-01-29 06:55:58'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated \'hanni\'. Details: Expiry: None -> 2026-01-31', '[]', '::1', '2026-01-29 06:56:06'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'White Mushroom\': 0 -> 1', '[]', '::1', '2026-01-29 07:53:21'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'White Mushroom\': 0 -> 1', '[]', '::1', '2026-01-29 07:53:26'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'White Mushroom\': 0 -> 3', '[]', '::1', '2026-01-29 07:53:30'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'White Mushroom\': 0 -> 0', '[]', '::1', '2026-01-29 07:53:48'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'White Mushroom\': 0 -> 3', '[]', '::1', '2026-01-29 07:53:52'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'White Mushroom\': 3 -> 0', '[]', '::1', '2026-01-29 07:55:13'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated \'White Mushroom\'. Details: Status: Out of Stock -> , Stock: 0 -> 0.01', '[]', '::1', '2026-01-29 07:55:23'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated \'White Mushroom\'. Details: Stock: 0 -> 1', '[]', '::1', '2026-01-29 07:55:29'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated product \'White Mushroom\' (No major changes detected).', '[]', '::1', '2026-01-29 07:55:30'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated product \'White Mushroom\' (No major changes detected).', '[]', '::1', '2026-01-29 07:55:30'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated product \'White Mushroom\' (No major changes detected).', '[]', '::1', '2026-01-29 07:55:31'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated product \'White Mushroom\' (No major changes detected).', '[]', '::1', '2026-01-29 07:55:31'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated product \'White Mushroom\' (No major changes detected).', '[]', '::1', '2026-01-29 07:55:31'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated product \'White Mushroom\' (No major changes detected).', '[]', '::1', '2026-01-29 07:55:31'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated product \'White Mushroom\' (No major changes detected).', '[]', '::1', '2026-01-29 07:55:31'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated \'White Mushroom\'. Details: Status:  -> Active', '[]', '::1', '2026-01-29 07:55:34'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Updated stock ID 25', '[]', '::1', '2026-01-29 07:58:23'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated \'White Mushroom\'. Details: Status: Out of Stock -> Active', '[]', '::1', '2026-01-29 07:59:18'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'White Mushroom\': 1 -> 2', '[]', '::1', '2026-01-29 07:59:57'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'White Mushroom\': 2 -> 5', '[]', '::1', '2026-01-29 07:59:59'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'White Mushroom\': 5 -> 9', '[]', '::1', '2026-01-29 08:00:05'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated \'White Mushroom\'. Details: Stock: 9 -> 9.01', '[]', '::1', '2026-01-29 08:02:09'),
(0, 1, 'Chrysler', 'Delete Product', 'Products', 'Deleted product \'White Mushroom\'.', '[]', '::1', '2026-01-29 08:02:24'),
(0, 1, 'Chrysler', 'Delete Product', 'Products', 'Deleted product \'White Mushroom\'.', '[]', '::1', '2026-01-29 08:02:33'),
(0, 1, 'Chrysler', 'Bulk Action', 'Products', 'Bulk Deleted 2 products.', '[]', '::1', '2026-01-29 08:02:42'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated \'White Mushroom\'. Details: Status: Archived -> Active, Stock: 9 -> 10', '[]', '::1', '2026-01-29 08:02:59'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'White Mushroom\': 10 -> 11', '[]', '::1', '2026-01-29 08:03:04'),
(0, 1, 'Chrysler', 'Delete Product', 'Products', 'Deleted product \'Milk\'.', '[]', '::1', '2026-01-29 08:47:41'),
(0, 1, 'Chrysler', 'Delete Product', 'Products', 'Deleted product \'hanni\'.', '[]', '::1', '2026-01-29 08:48:13'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated \'hanni\'. Details: Status: Archived -> Active', '[]', '::1', '2026-01-29 09:00:52'),
(0, 1, 'Chrysler', 'Login', 'Authentication', 'User logged in successfully.', '[]', '::1', '2026-01-29 14:37:15'),
(0, 1, 'Chrysler', 'Add Product', 'Products', 'Added new product \'Egg\'. Brand: test, Initial Stock: 120', '[]', '::1', '2026-01-29 14:40:53'),
(0, 1, 'Chrysler', 'Add Product', 'Products', 'Processed Zesto', '[]', '::1', '2026-01-29 14:55:00'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Processed Egg', '[]', '::1', '2026-01-29 14:55:14'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Processed Carrot', '[]', '::1', '2026-01-29 14:55:26'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'Zesto\': 120 -> 115', '[]', '::1', '2026-01-29 14:56:36'),
(0, 1, 'Chrysler', 'Add Product', 'Products', 'Added new product \'testinbg\'. Unit: pcs, Initial Stock: 10', '[]', '::1', '2026-01-29 16:08:15'),
(0, 1, 'Chrysler', 'Delete Product', 'Products', 'Deleted product \'testinbg\'.', '[]', '::1', '2026-01-29 16:08:29'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated \'Zesto\'. Details: Unit: \'pcs\' -> \'kg\', Expiry: None -> 2026-02-07', '[]', '::1', '2026-01-29 16:17:17'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'Zesto\': 115 -> 8', '[]', '::1', '2026-01-29 16:17:55'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'Zesto\': 8 -> 0', '[]', '::1', '2026-01-29 16:17:59'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'Egg\': 120 -> 0', '[]', '::1', '2026-01-29 16:18:54'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'Egg\': 0 -> 8', '[]', '::1', '2026-01-29 16:18:58'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'Zesto\': -16 -> 2', '[]', '::1', '2026-01-29 16:26:16'),
(0, 1, 'Chrysler', 'Add Client', 'Clients', 'Added new client \'Rice n BOX\'.', '[]', '::1', '2026-01-29 16:29:15'),
(0, 1, 'Chrysler', 'Login', 'Authentication', 'User logged in successfully.', '[]', '::1', '2026-01-30 07:48:35'),
(0, 1, 'Chrysler', 'Login', 'Authentication', 'User logged in successfully.', '[]', '::1', '2026-01-31 02:32:02'),
(0, 1, 'Chrysler', 'Login', 'Authentication', 'User logged in successfully.', '[]', '::1', '2026-01-31 04:45:50'),
(0, 1, 'Chrysler', 'Login', 'Authentication', 'User logged in successfully.', '[]', '::1', '2026-02-03 05:44:57'),
(0, 1, 'Chrysler', 'Return', 'Fulfillment', 'Processed return for Sale #24 (Qty: 1)', '[]', '::1', '2026-02-03 05:55:25'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'hanni\': 121 -> 2', '[]', '::1', '2026-02-03 05:55:39'),
(0, 1, 'Chrysler', 'Return', 'Fulfillment', 'Processed return for Sale #25 (Qty: 2)', '[]', '::1', '2026-02-03 05:56:27'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'hanni\': 0 -> 2', '[]', '::1', '2026-02-03 05:56:39'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'hanni\': 2 -> 2', '[]', '::1', '2026-02-03 06:00:36'),
(0, 1, 'Chrysler', 'Return', 'Fulfillment', 'Processed return for Sale #26 (Qty: 2)', '[]', '::1', '2026-02-03 06:01:20'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'hanni\': 0 -> 3', '[]', '::1', '2026-02-03 06:01:35'),
(0, 1, 'Chrysler', 'Return', 'Fulfillment', 'Processed return for Sale #27 (Qty: 3)', '[]', '::1', '2026-02-03 06:02:19'),
(0, 1, 'Chrysler', 'Payment', 'Finance', 'Received P639 for Order #27 via Cash', '[]', '::1', '2026-02-03 06:12:17'),
(0, 1, 'Chrysler', 'Payment', 'Finance', 'Received P500 for Order #26 via Gcash', '[]', '::1', '2026-02-03 06:12:36'),
(0, 1, 'Chrysler', 'Payment', 'Finance', 'Received P400 for Order #25 via Bank Transfer', '[]', '::1', '2026-02-03 06:14:08'),
(0, 1, 'Chrysler', 'Cancel', 'Fulfillment', 'Cancelled Order #28 (Restocked)', '[]', '::1', '2026-02-03 06:43:00'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'hanni\': -6 -> 5', '[]', '::1', '2026-02-03 06:46:56'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'hanni\': 0 -> 3', '[]', '::1', '2026-02-03 06:47:58'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'hanni\': 0 -> 5', '[]', '::1', '2026-02-03 06:48:35'),
(0, 1, 'Chrysler', 'Return', 'Fulfillment', 'Processed return for Sale #31 (Qty: 2)', '[]', '::1', '2026-02-03 06:51:57'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'hanni\': 0 -> 30', '[]', '::1', '2026-02-03 08:39:36'),
(0, 1, 'Chrysler', 'Payment', 'Finance', 'Received P1320 for Order #33 via Cash', '[]', '::1', '2026-02-03 08:49:04'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'Lettuce\': 98 -> 108', '[]', '::1', '2026-02-03 09:12:37'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'Lettuce\': 108 -> 109', '[]', '::1', '2026-02-03 09:12:38'),
(0, 1, 'Chrysler', 'Login', 'Authentication', 'User logged in successfully.', '[]', '::1', '2026-02-06 14:56:09'),
(0, 1, 'Chrysler', 'Login', 'Authentication', 'User logged in successfully.', '[]', '::1', '2026-02-08 15:51:26'),
(0, 1, 'Chrysler', 'Payment', 'Finance', 'Received P456 for Order #56 via Cash', '[]', '::1', '2026-02-08 16:10:06'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'Cabbage\': 6 -> 100', '[]', '::1', '2026-02-08 17:19:12'),
(0, 1, 'Chrysler', 'Return', 'Fulfillment', 'Processed return for Sale #57 (Qty: 50)', '[]', '::1', '2026-02-08 17:21:32'),
(0, 1, 'Chrysler', 'Update Product', 'Products', 'Updated \'Cabbage\'. Details: Expiry: 2026-01-30 -> 2026-03-27', '[]', '::1', '2026-02-08 17:21:56'),
(0, 1, 'Chrysler', 'Payment', 'Finance', 'Received P7600 for Order #57 via Cash', '[]', '::1', '2026-02-08 17:59:31'),
(0, 1, 'Chrysler', 'Payment', 'Finance', 'Received P639 for Order #31 via Cash', '[]', '::1', '2026-02-08 17:59:36'),
(0, 1, 'Chrysler', 'Payment', 'Finance', 'Received P5000 for Order #55 via Cash', '[]', '::1', '2026-02-08 17:59:42'),
(0, 1, 'Chrysler', 'Payment', 'Finance', 'Received P120 for Order #22 via Cash', '[]', '::1', '2026-02-08 18:04:43'),
(0, 1, 'Chrysler', 'Payment', 'Finance', 'Received P1278 for Order #29 via Cash', '[]', '::1', '2026-02-08 18:04:47'),
(0, 1, 'Chrysler', 'Payment', 'Finance', 'Received P1065 for Order #30 via Cash', '[]', '::1', '2026-02-08 18:04:51'),
(0, 1, 'Chrysler', 'Payment', 'Finance', 'Received P120 for Order #23 via Cash', '[]', '::1', '2026-02-08 18:04:54'),
(0, 1, 'Chrysler', 'Payment', 'Finance', 'Received P757 for Order #24 via Cash', '[]', '::1', '2026-02-08 18:04:58'),
(0, 1, 'Chrysler', 'Payment', 'Finance', 'Received P152 for Order #21 via Cash', '[]', '::1', '2026-02-08 18:05:02'),
(0, 1, 'Chrysler', 'Payment', 'Finance', 'Received P213 for Order #20 via Cash', '[]', '::1', '2026-02-08 18:05:06'),
(0, 1, 'Chrysler', 'Bulk Action', 'Products', 'Bulk Deleted 1 products.', '[]', '::1', '2026-02-08 18:05:21'),
(0, 1, 'Chrysler', 'Login', 'Authentication', 'User logged in successfully.', '[]', '::1', '2026-02-08 18:22:22'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'Lettuce\': 109 -> 0', '[]', '::1', '2026-02-08 18:52:57'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'Lettuce\': 0 -> 67', '[]', '::1', '2026-02-08 18:53:01'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'Cabbage\': 50 -> 11', '[]', '::1', '2026-02-08 18:54:24'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'Cabbage\': 11 -> 1', '[]', '::1', '2026-02-08 18:54:48'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'Cabbage\': 1 -> 2', '[]', '::1', '2026-02-08 18:56:57'),
(0, 1, 'Chrysler', 'Update Stock', 'Inventory', 'Manual Stock Update for \'Cabbage\': 2 -> 30', '[]', '::1', '2026-02-08 18:57:00'),
(0, 1, 'Chrysler', 'Payment', 'Finance', 'Received P2280 for Order #156 via Cash', '[]', '::1', '2026-02-08 18:57:37');

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `category_id` int(11) NOT NULL,
  `category_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`category_id`, `category_name`, `description`, `created_at`, `updated_at`) VALUES
(1, 'Leafy Greens', NULL, '2026-01-25 17:03:40', '2026-01-25 17:03:40'),
(2, 'Garden Vegetables', NULL, '2026-01-25 17:03:40', '2026-01-25 17:03:40'),
(3, 'Roots, Potatoes & Onions', NULL, '2026-01-25 17:03:40', '2026-01-25 17:03:40'),
(4, 'Mushrooms', NULL, '2026-01-25 17:03:40', '2026-01-25 17:03:40'),
(5, 'Herbs & Seasonings', NULL, '2026-01-25 17:03:40', '2026-01-25 17:03:40');

-- --------------------------------------------------------

--
-- Table structure for table `clients`
--

CREATE TABLE `clients` (
  `client_id` int(11) NOT NULL,
  `client_name` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `contact_number` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `clients`
--

INSERT INTO `clients` (`client_id`, `client_name`, `contact_person`, `contact_number`, `address`, `status`, `created_at`) VALUES
(1, 'Jollibee', 'Ralph Silva', '09218648143', 'cubao', 'Active', '2026-01-28 16:45:05'),
(2, 'Rice n BOX', 'Makima', '0934236523', 'Marikina', 'Active', '2026-01-29 16:29:15');

-- --------------------------------------------------------

--
-- Table structure for table `deliveries`
--

CREATE TABLE `deliveries` (
  `delivery_id` int(11) NOT NULL,
  `sale_id` int(11) DEFAULT NULL,
  `driver_name` varchar(100) DEFAULT NULL,
  `vehicle_plate` varchar(20) DEFAULT NULL,
  `delivery_status` enum('Pending','In Transit','Delivered','Failed') DEFAULT 'Pending',
  `departure_time` datetime DEFAULT NULL,
  `arrival_time` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `drivers`
--

CREATE TABLE `drivers` (
  `driver_id` int(11) NOT NULL,
  `driver_name` varchar(100) NOT NULL,
  `vehicle_plate` varchar(50) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `drivers`
--

INSERT INTO `drivers` (`driver_id`, `driver_name`, `vehicle_plate`, `status`) VALUES
(1, 'Mang Boy', 'L300 - ABC 123', 'Active'),
(2, 'Kuya Ed', 'Van - XYZ 888', 'Active'),
(3, 'Lalamove (3rd Party)', 'N/A', 'Active'),
(4, 'RYU', '123', 'Active');

-- --------------------------------------------------------

--
-- Table structure for table `email_templates`
--

CREATE TABLE `email_templates` (
  `template_id` int(11) NOT NULL,
  `template_name` varchar(100) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `email_templates`
--

INSERT INTO `email_templates` (`template_id`, `template_name`, `subject`, `body`, `updated_at`) VALUES
(1, 'order_confirmation', 'AUTOPORMA - Order Confirmation #{{order_id}}', '\n<html>\n<head>\n    <style>\n        body { font-family: Arial, sans-serif; background-color: #0d0d0d; color: #eeeeee; margin: 0; padding: 20px; }\n        .email-container { max-width: 600px; margin: auto; background-color: #1a1a1a; border-radius: 12px; border: 1px solid #333; overflow: hidden; }\n        .header { background-color: #b22222; color: #fff; padding: 30px; text-align: center; }\n        .content { padding: 30px 40px; color: #cccccc; }\n        .footer { text-align: center; padding: 20px; font-size: 12px; color: #888; border-top: 1px solid #333; }\n        .item-list { margin: 20px 0; padding: 10px; background-color: #2a2a2a; border-radius: 8px; }\n        .item-row { padding: 10px 0; border-bottom: 1px solid #444; }\n        .item-name { color: #eee; }\n        .item-price { float: right; color: #fff; font-weight: bold; }\n        .address-box { margin-top: 20px; padding: 15px; background-color: #2a2a2a; border-radius: 8px; }\n    </style>\n</head>\n<body>\n    <div class=\"email-container\">\n        <div class=\"header\">\n            <h1>Thank You For Your Order, {{username}}!</h1>\n        </div>\n        <div class=\"content\">\n            <p>Your order (ID: <strong>#{{order_id}}</strong>) has been successfully placed.</p>\n            \n            <h3>Order Summary</h3>\n            <div class=\"item-list\">\n                {{item_list}}\n                <div style=\"padding-top: 15px; margin-top: 10px; border-top: 2px solid #b22222; text-align: right; font-size: 1.2em; font-weight: bold; color: #fff;\">\n                    Total: {{total_amount}}\n                </div>\n            </div>\n\n            <h3>Shipping Address</h3>\n            <div class=\"address-box\">\n                {{shipping_address}}\n            </div>\n            \n            <p style=\"margin-top: 20px;\">We will notify you again once your order has been shipped. Thank you for shopping with AUTOPORMA!</p>\n        </div>\n        <div class=\"footer\">\n            &copy; {{current_year}} AUTOPORMA. All rights reserved.\n        </div>\n    </div>\n</body>\n</html>\n', '2025-11-04 19:45:27'),
(2, 'account_otp', 'Your AUTOPORMA One-Time Verification Code (OTP)', '<html>\r\n<head>\r\n    <style>\r\n        @import url(https://db.onlinewebfonts.com/c/465b1cbe35b5ca0de556720c955abece?family=Abolition+W00+Regular);\r\n        body { font-family: Arial, sans-serif; background-color: #0a0a0a; color: #eeeeee; margin: 0; padding: 0; }\r\n        .email-container { max-width: 600px; margin: 20px auto; background-color: #121212; border-radius: 12px; box-shadow: 0 6px 15px rgba(0, 0, 0, 0.4); overflow: hidden; border: 1px solid #333333; }\r\n        .header { background-color: #c02828; /* Brighter Red */ color: #ffffff; padding: 30px 20px; text-align: center; }\r\n        .header h1 { font-family: \"Abolition\", sans-serif; margin: 0; font-size: 36px; letter-spacing: 2px; text-transform: uppercase; }\r\n        .content { padding: 40px; line-height: 1.7; color: #cccccc; }\r\n        .content p { margin-bottom: 1.5em; }\r\n        .otp-box { background-color: #000000; color: #c02828; font-size: 40px; font-weight: bold; padding: 25px; border-radius: 8px; text-align: center; letter-spacing: 5px; border: 2px dashed #c02828; margin: 30px 0; }\r\n        .footer { text-align: center; padding: 30px; font-size: 12px; color: #888888; border-top: 1px solid #333333; }\r\n    </style>\r\n</head>\r\n<body>\r\n    <div class=\"email-container\">\r\n        <div class=\"header\">\r\n            <h1>ACCOUNT VERIFICATION</h1>\r\n        </div>\r\n        <div class=\"content\">\r\n            <p>Hi {{username}},</p>\r\n            <p>Thank you for signing up. Please use the following One-Time Password (OTP) to verify your email address and activate your account. This code is valid for 5 minutes.</p>\r\n            <div class=\"otp-box\">{{otp_code}}</div>\r\n            <p>If you did not sign up for this account, please ignore this email.</p>\r\n        </div>\r\n        <div class=\"footer\">\r\n            &copy; {{current_year}} AUTOPORMA. All rights reserved.\r\n        </div>\r\n    </div>\r\n</body>\r\n</html>', '2025-11-04 19:46:17'),
(3, 'account_password_reset', 'Your AUTOPORMA Password Reset Request', '<html>\r\n<head>\r\n    <style>\r\n        @import url(https://db.onlinewebfonts.com/c/465b1cbe35b5ca0de556720c955abece?family=Abolition+W00+Regular);\r\n        body { font-family: Arial, sans-serif; background-color: #0a0a0a; color: #eeeeee; margin: 0; padding: 0; }\r\n        .email-container { max-width: 600px; margin: 20px auto; background-color: #121212; border-radius: 12px; box-shadow: 0 6px 15px rgba(0, 0, 0, 0.4); overflow: hidden; border: 1px solid #333333; }\r\n        .header { background-color: #c02828; /* Brighter Red */ color: #ffffff; padding: 30px 20px; text-align: center; }\r\n        .header h1 { font-family: \"Abolition\", sans-serif; margin: 0; font-size: 36px; letter-spacing: 2px; text-transform: uppercase; }\r\n        .content { padding: 40px; line-height: 1.7; color: #cccccc; }\r\n        .content p { margin-bottom: 1.5em; }\r\n        .reset-button { display: inline-block; background-color: #c02828; color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 16px; }\r\n        .footer { text-align: center; padding: 30px; font-size: 12px; color: #888888; border-top: 1px solid #333333; }\r\n    </style>\r\n</head>\r\n<body>\r\n    <div class=\"email-container\">\r\n        <div class=\"header\">\r\n            <h1>PASSWORD RESET</h1>\r\n        </div>\r\n        <div class=\"content\">\r\n            <p>Hi {{username}},</p>\r\n            <p>We received a request to reset your password. Click the button below to set a new password. This link is valid for 1 hour.</p>\r\n            <p style=\"text-align: center; margin: 30px 0;\">\r\n                <a href=\"{{reset_link}}\" class=\"reset-button\">Reset Your Password</a>\r\n            </p>\r\n            <p>If you did not request this, please ignore this email.</p>\r\n        </div>\r\n        <div class=\"footer\">\r\n            &copy; {{current_year}} AUTOPORMA. All rights reserved.\r\n        </div>\r\n    </div>\r\n</body>\r\n</html>', '2025-11-04 19:46:10'),
(4, 'account_change_password_otp', 'Your AUTOPORMA Password Change Code', '\r\n<html>\r\n<head>\r\n    <style>\r\n        @import url(https://db.onlinewebfonts.com/c/465b1cbe35b5ca0de556720c955abece?family=Abolition+W00+Regular);\r\n        body { font-family: Arial, sans-serif; background-color: #0a0a0a; color: #eeeeee; margin: 0; padding: 0; }\r\n        .email-container { max-width: 600px; margin: 20px auto; background-color: #121212; border-radius: 12px; box-shadow: 0 6px 15px rgba(0, 0, 0, 0.4); overflow: hidden; border: 1px solid #333333; }\r\n        .header { background-color: #c02828; color: #ffffff; padding: 30px 20px; text-align: center; }\r\n        .header h1 { font-family: \"Abolition\", sans-serif; margin: 0; font-size: 36px; letter-spacing: 2px; text-transform: uppercase; }\r\n        .content { padding: 40px; line-height: 1.7; color: #cccccc; }\r\n        .content p { margin-bottom: 1.5em; }\r\n        .otp-box { background-color: #000000; color: #c02828; font-size: 40px; font-weight: bold; padding: 25px; border-radius: 8px; text-align: center; letter-spacing: 5px; border: 2px dashed #c02828; margin: 30px 0; }\r\n        .footer { text-align: center; padding: 30px; font-size: 12px; color: #888888; border-top: 1px solid #333333; }\r\n    </style>\r\n</head>\r\n<body>\r\n    <div class=\"email-container\">\r\n        <div class=\"header\">\r\n            <h1>Password Change Request</h1>\r\n        </div>\r\n        <div class=\"content\">\r\n            <p>Hi {{username}},</p>\r\n            <p>We received a request to change the password for your account. Please use the verification code below to confirm this change. This code is valid for 10 minutes.</p>\r\n            <div class=\"otp-box\">{{otp_code}}</div>\r\n            <p>If you did not request this change, please secure your account immediately.</p>\r\n        </div>\r\n        <div class=\"footer\">\r\n            &copy; {{current_year}} AUTOPORMA. All rights reserved.\r\n        </div>\r\n    </div>\r\n</body>\r\n</html>\r\n', '2025-11-04 20:19:27'),
(5, 'account_change_email_otp', 'Your AUTOPORMA Email Change Code', '\r\n<html>\r\n<head>\r\n    <style>\r\n        @import url(https://db.onlinewebfonts.com/c/465b1cbe35b5ca0de556720c955abece?family=Abolition+W00+Regular);\r\n        body { font-family: Arial, sans-serif; background-color: #0a0a0a; color: #eeeeee; margin: 0; padding: 0; }\r\n        .email-container { max-width: 600px; margin: 20px auto; background-color: #121212; border-radius: 12px; box-shadow: 0 6px 15px rgba(0, 0, 0, 0.4); overflow: hidden; border: 1px solid #333333; }\r\n        .header { background-color: #c02828; color: #ffffff; padding: 30px 20px; text-align: center; }\r\n        .header h1 { font-family: \"Abolition\", sans-serif; margin: 0; font-size: 36px; letter-spacing: 2px; text-transform: uppercase; }\r\n        .content { padding: 40px; line-height: 1.7; color: #cccccc; }\r\n        .content p { margin-bottom: 1.5em; }\r\n        .otp-box { background-color: #000000; color: #c02828; font-size: 40px; font-weight: bold; padding: 25px; border-radius: 8px; text-align: center; letter-spacing: 5px; border: 2px dashed #c02828; margin: 30px 0; }\r\n        .footer { text-align: center; padding: 30px; font-size: 12px; color: #888888; border-top: 1px solid #333333; }\r\n    </style>\r\n</head>\r\n<body>\r\n    <div class=\"email-container\">\r\n        <div class=\"header\">\r\n            <h1>Confirm Your Email Change</h1>\r\n        </div>\r\n        <div class=\"content\">\r\n            <p>Hi there,</p>\r\n            <p>You requested to change the email address for your AUTOPORMA account. Please use the verification code below to confirm this change. This code is valid for 15 minutes.</p>\r\n            <div class=\"otp-box\">{{otp_code}}</div>\r\n            <p>If you did not request this change, please ignore this email.</p>\r\n        </div>\r\n        <div class=\"footer\">\r\n            &copy; {{current_year}} AUTOPORMA. All rights reserved.\r\n        </div>\r\n    </div>\r\n</body>\r\n</html>\r\n', '2025-11-04 20:19:37');

-- --------------------------------------------------------

--
-- Table structure for table `inventory_log`
--

CREATE TABLE `inventory_log` (
  `log_id` int(11) UNSIGNED NOT NULL,
  `product_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL COMMENT 'ID of the admin user who made the change',
  `change_type` enum('PRODUCT_ADD','PRODUCT_EDIT','STOCK_ADJUSTMENT','ORDER_SALE','ORDER_CANCELLATION') NOT NULL,
  `quantity_change` int(11) NOT NULL COMMENT 'The delta (+/-) of the quantity change',
  `notes` text DEFAULT NULL,
  `log_timestamp` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(50) NOT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `payment_date` datetime DEFAULT current_timestamp(),
  `recorded_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`payment_id`, `sale_id`, `amount`, `payment_method`, `reference_no`, `payment_date`, `recorded_by`) VALUES
(1, 27, 639.00, 'Cash', '', '2026-02-03 14:12:17', 1),
(2, 26, 500.00, 'Gcash', '', '2026-02-03 14:12:36', 1),
(3, 25, 400.00, 'Bank Transfer', '', '2026-02-03 14:14:08', 1),
(4, 33, 1320.00, 'Cash', '', '2026-02-03 16:49:04', 1),
(5, 56, 456.00, 'Cash', '456', '2026-02-09 00:10:06', 1),
(6, 57, 7600.00, 'Cash', '', '2026-02-09 01:59:31', 1),
(7, 31, 639.00, 'Cash', '', '2026-02-09 01:59:36', 1),
(8, 55, 5000.00, 'Cash', '', '2026-02-09 01:59:42', 1),
(9, 22, 120.00, 'Cash', '', '2026-02-09 02:04:43', 1),
(10, 29, 1278.00, 'Cash', '', '2026-02-09 02:04:47', 1),
(11, 30, 1065.00, 'Cash', '', '2026-02-09 02:04:51', 1),
(12, 23, 120.00, 'Cash', '', '2026-02-09 02:04:54', 1),
(13, 24, 757.00, 'Cash', '', '2026-02-09 02:04:58', 1),
(14, 21, 152.00, 'Cash', '', '2026-02-09 02:05:02', 1),
(15, 20, 213.00, 'Cash', '', '2026-02-09 02:05:06', 1),
(16, 156, 2280.00, 'Cash', '', '2026-02-09 02:57:37', 1);

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `product_id` int(11) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `specifications` text DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `unit_type` varchar(50) DEFAULT 'pcs',
  `expiration_date` date DEFAULT NULL,
  `weight` decimal(10,2) NOT NULL,
  `unit` varchar(50) NOT NULL DEFAULT 'pcs',
  `status` enum('Active','Inactive','Out of Stock','Archived') DEFAULT 'Active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `image_url` varchar(255) DEFAULT NULL,
  `product_brand` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`product_id`, `category_id`, `name`, `description`, `specifications`, `price`, `unit_type`, `expiration_date`, `weight`, `unit`, `status`, `created_at`, `updated_at`, `image_url`, `product_brand`) VALUES
(22, 1, 'Lettuce', '', '0', 190.00, 'pcs', '2026-02-28', 5.00, 'pcs', 'Active', '2026-01-28 09:36:18', '2026-02-09 02:53:01', 'assets/img/products/main/6979681b0b0fd-Lettuce.webp', 'PedroFarm'),
(23, 2, 'Cabbage', '', '0', 152.00, 'pcs', '2026-03-27', 10.00, 'pcs', 'Active', '2026-01-28 09:38:27', '2026-02-09 01:21:56', 'assets/img/products/main/69796893a2fc2-cabbage.jpg', 'SM'),
(24, 3, 'Carrot', '', '0', 120.00, 'g', '2026-01-31', 2.00, 'pcs', 'Active', '2026-01-28 09:40:18', '2026-01-29 22:55:26', 'assets/img/products/main/6979690228006-carrot.png', 'Carrot man'),
(25, 4, 'White Mushroom', '', '0', 120.00, 'pcs', '2026-03-26', 0.50, 'pcs', 'Active', '2026-01-28 09:42:36', '2026-01-29 16:02:59', 'assets/img/products/main/6979698c0215e-whitemushroom.jpg', 'Monterey'),
(26, 5, 'Chili Peppers', '', '0', 8.00, 'pcs', '2026-01-31', 0.05, 'pcs', 'Active', '2026-01-28 09:45:06', '2026-01-28 09:45:06', 'assets/img/products/main/69796a22e46d2-Chillipepers.jpg', 'Chilis'),
(29, 2, 'Egg', '', '0', 120.00, 'kg', '2026-02-25', 18.00, 'pcs', 'Active', '2026-01-29 22:40:53', '2026-01-30 00:18:58', '', 'test'),
(30, 3, 'Zesto', '', '0', 10.00, 'pcs', '2026-02-07', 0.00, 'kg', 'Active', '2026-01-29 22:55:00', '2026-01-30 00:26:16', '', 'Zesty');

-- --------------------------------------------------------

--
-- Table structure for table `product_audit_log`
--

CREATE TABLE `product_audit_log` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action_type` varchar(50) NOT NULL,
  `changes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`changes`)),
  `timestamp` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_inventory`
--

CREATE TABLE `product_inventory` (
  `inventory_id` int(200) NOT NULL,
  `product_id` int(200) NOT NULL,
  `quantity` int(200) DEFAULT 0,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reorder_level` int(11) DEFAULT 10,
  `last_restock_date` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `product_inventory`
--

INSERT INTO `product_inventory` (`inventory_id`, `product_id`, `quantity`, `updated_at`, `reorder_level`, `last_restock_date`) VALUES
(0, 26, 9, '2026-01-30 17:06:26', 10, NULL),
(0, 24, -5, '2026-02-09 02:30:23', 10, NULL),
(0, 25, 0, '2026-02-03 16:40:22', 10, NULL),
(0, 24, 5, '2026-01-30 17:06:26', 10, NULL),
(0, 29, 4, '2026-02-03 13:54:24', 10, NULL),
(0, 30, 8, '2026-02-09 02:50:05', 10, NULL),
(0, 22, 67, '2026-02-09 02:53:01', 10, NULL),
(0, 23, 15, '2026-02-09 02:57:16', 10, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `returns`
--

CREATE TABLE `returns` (
  `return_id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `condition_status` enum('Good','Spoiled') DEFAULT 'Good',
  `return_date` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `returns`
--

INSERT INTO `returns` (`return_id`, `sale_id`, `product_id`, `quantity`, `reason`, `condition_status`, `return_date`) VALUES
(1, 24, 28, 1.00, '0', 'Spoiled', '2026-02-03 13:55:25'),
(2, 25, 28, 2.00, '0', 'Spoiled', '2026-02-03 13:56:27'),
(3, 26, 28, 2.00, '0', 'Spoiled', '2026-02-03 14:01:19'),
(4, 27, 28, 3.00, '0', 'Good', '2026-02-03 14:02:19'),
(5, 31, 28, 2.00, '0', 'Good', '2026-02-03 14:51:57'),
(6, 57, 23, 50.00, '0', 'Spoiled', '2026-02-09 01:21:32');

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `sale_id` int(11) NOT NULL,
  `customer_name` varchar(100) DEFAULT NULL,
  `sale_date` datetime DEFAULT current_timestamp(),
  `total_amount` decimal(10,2) DEFAULT 0.00,
  `amount_paid` decimal(10,2) DEFAULT 0.00,
  `payment_status` enum('Pending','Partial','Paid') DEFAULT 'Pending',
  `payment_method` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `client_id` int(11) DEFAULT NULL,
  `order_status` enum('Pending','Packed','Out for Delivery','Completed','Cancelled') DEFAULT 'Pending',
  `delivery_date` date DEFAULT NULL,
  `driver_name` varchar(100) DEFAULT NULL,
  `vehicle_plate` varchar(50) DEFAULT NULL,
  `dispatched_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`sale_id`, `customer_name`, `sale_date`, `total_amount`, `amount_paid`, `payment_status`, `payment_method`, `notes`, `created_by`, `client_id`, `order_status`, `delivery_date`, `driver_name`, `vehicle_plate`, `dispatched_at`, `delivered_at`) VALUES
(19, NULL, '2026-01-30 17:06:42', 380.00, 0.00, 'Pending', NULL, NULL, 1, 1, 'Completed', '2026-01-31', 'Mang Boy', 'L300 - ABC 123', '2026-01-30 17:07:53', '2026-01-30 17:08:13'),
(20, NULL, '2026-01-30 17:10:57', 213.00, 213.00, 'Paid', NULL, NULL, 1, 2, 'Completed', '2026-01-30', 'Kuya Ed', 'Van - XYZ 888', '2026-01-30 17:11:31', '2026-01-30 17:11:34'),
(21, NULL, '2026-01-30 17:23:48', 152.00, 152.00, 'Paid', NULL, NULL, 1, 1, 'Completed', '2026-01-30', 'Kuya Ed', 'Van - XYZ 888', '2026-01-30 17:24:03', '2026-01-30 17:24:28'),
(22, NULL, '2026-01-30 17:26:28', 120.00, 120.00, 'Paid', NULL, NULL, 1, 1, 'Completed', '2026-01-30', 'Kuya Ed', 'Van - XYZ 888', '2026-01-30 17:26:46', '2026-02-03 14:47:49'),
(23, NULL, '2026-01-30 17:43:27', 120.00, 120.00, 'Paid', NULL, NULL, 1, 2, 'Completed', '2026-01-30', NULL, NULL, NULL, '2026-02-03 14:46:29'),
(24, NULL, '2026-02-03 13:54:24', 757.00, 757.00, 'Paid', NULL, NULL, 1, 2, 'Completed', '2026-02-03', 'Lalamove (3rd Party)', 'N/A', '2026-02-03 13:54:52', '2026-02-03 13:54:57'),
(25, NULL, '2026-02-03 13:55:51', 426.00, 400.00, 'Partial', NULL, NULL, 1, 1, 'Completed', '2026-02-03', 'RYU', '123', '2026-02-03 13:56:02', '2026-02-03 13:56:06'),
(26, NULL, '2026-02-03 14:00:48', 426.00, 500.00, 'Paid', NULL, NULL, 1, 2, 'Completed', '2026-02-03', 'Kuya Ed', 'Van - XYZ 888', '2026-02-03 14:01:00', '2026-02-03 14:01:04'),
(27, NULL, '2026-02-03 14:01:47', 639.00, 639.00, 'Paid', NULL, NULL, 1, 1, 'Completed', '2026-02-03', 'Kuya Ed', 'Van - XYZ 888', '2026-02-03 14:01:56', '2026-02-03 14:02:03'),
(28, NULL, '2026-02-03 14:32:39', 639.00, 0.00, '', NULL, NULL, 1, 1, 'Cancelled', '2026-02-03', NULL, NULL, NULL, NULL),
(29, NULL, '2026-02-03 14:45:53', 1278.00, 1278.00, 'Paid', NULL, NULL, 1, 2, 'Completed', '2026-02-03', 'Kuya Ed', 'Van - XYZ 888', '2026-02-03 14:47:45', '2026-02-03 14:47:48'),
(30, NULL, '2026-02-03 14:47:04', 1065.00, 1065.00, 'Paid', NULL, NULL, 1, 2, 'Completed', '2026-02-03', 'Kuya Ed', 'Van - XYZ 888', '2026-02-03 14:47:27', '2026-02-03 14:47:47'),
(31, NULL, '2026-02-03 14:48:16', 639.00, 639.00, 'Paid', NULL, NULL, 1, 1, 'Completed', '2026-02-03', 'Lalamove (3rd Party)', 'N/A', '2026-02-03 14:51:13', '2026-02-03 14:51:15'),
(32, NULL, '2026-02-03 14:48:41', 1065.00, 0.00, 'Pending', NULL, NULL, 1, 1, 'Completed', '2026-02-03', 'Lalamove (3rd Party)', 'N/A', '2026-02-09 02:45:52', '2026-02-09 02:45:55'),
(33, NULL, '2026-02-03 16:40:22', 1320.00, 1320.00, 'Paid', NULL, NULL, 1, 1, 'Completed', '2026-02-05', 'Lalamove (3rd Party)', 'N/A', '2026-02-03 16:40:33', '2026-02-03 16:40:34'),
(34, NULL, '2026-01-06 16:51:44', 500.00, 0.00, 'Paid', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, '2026-01-06 16:51:44'),
(35, NULL, '2026-01-09 16:51:44', 1500.00, 0.00, 'Paid', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, '2026-01-09 16:51:44'),
(36, NULL, '2026-01-10 16:51:44', 800.00, 0.00, 'Paid', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, '2026-01-10 16:51:44'),
(37, NULL, '2026-01-14 16:51:44', 2000.00, 0.00, 'Paid', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, '2026-01-14 16:51:44'),
(38, NULL, '2026-01-16 16:51:44', 1200.00, 0.00, 'Paid', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, '2026-01-16 16:51:44'),
(39, NULL, '2026-01-19 16:51:44', 3000.00, 0.00, 'Paid', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, '2026-01-19 16:51:44'),
(40, NULL, '2026-01-24 16:51:44', 450.00, 0.00, 'Paid', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, '2026-01-24 16:51:44'),
(41, NULL, '2026-01-29 16:51:44', 5000.00, 0.00, 'Paid', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, '2026-01-29 16:51:44'),
(42, NULL, '2026-01-31 16:51:44', 2500.00, 0.00, 'Paid', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, '2026-01-31 16:51:44'),
(43, NULL, '2026-02-02 16:51:44', 6000.00, 0.00, 'Paid', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, '2026-02-02 16:51:44'),
(44, NULL, '2025-01-15 00:00:00', 500.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, '2025-01-15 00:00:00'),
(45, NULL, '2025-02-15 00:00:00', 600.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, '2025-02-15 00:00:00'),
(46, NULL, '2025-03-15 00:00:00', 1200.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, '2025-03-15 00:00:00'),
(47, NULL, '2025-04-15 00:00:00', 1300.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, '2025-04-15 00:00:00'),
(48, NULL, '2025-05-15 00:00:00', 1500.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, '2025-05-15 00:00:00'),
(49, NULL, '2025-06-15 00:00:00', 1400.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, '2025-06-15 00:00:00'),
(50, NULL, '2025-07-15 00:00:00', 1300.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, '2025-07-15 00:00:00'),
(51, NULL, '2025-08-15 00:00:00', 1200.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, '2025-08-15 00:00:00'),
(52, NULL, '2025-09-15 00:00:00', 1100.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, '2025-09-15 00:00:00'),
(53, NULL, '2025-10-15 00:00:00', 2000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, '2025-10-15 00:00:00'),
(54, NULL, '2025-11-15 00:00:00', 3500.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, '2025-11-15 00:00:00'),
(55, NULL, '2025-12-15 00:00:00', 5000.00, 5000.00, 'Paid', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, '2025-12-15 00:00:00'),
(56, NULL, '2026-02-09 00:09:44', 456.00, 456.00, 'Paid', NULL, NULL, 1, 1, 'Completed', '2026-02-08', 'Kuya Ed', 'Van - XYZ 888', '2026-02-09 00:09:51', '2026-02-09 00:09:53'),
(57, NULL, '2026-02-09 01:20:18', 7600.00, 7600.00, 'Paid', NULL, NULL, 1, 1, 'Completed', '2026-02-08', 'Kuya Ed', 'Van - XYZ 888', '2026-02-09 01:21:04', '2026-02-09 01:21:06'),
(58, NULL, '2025-01-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(59, NULL, '2025-02-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(60, NULL, '2025-03-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(61, NULL, '2025-04-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(62, NULL, '2025-05-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(63, NULL, '2025-06-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(64, NULL, '2025-07-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(65, NULL, '2025-08-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(66, NULL, '2025-09-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(67, NULL, '2025-10-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(68, NULL, '2025-11-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(69, NULL, '2025-12-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(70, NULL, '2025-01-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(71, NULL, '2025-02-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(72, NULL, '2025-03-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(73, NULL, '2025-04-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(74, NULL, '2025-05-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(75, NULL, '2025-06-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(76, NULL, '2025-07-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(77, NULL, '2025-08-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(78, NULL, '2025-09-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(79, NULL, '2025-10-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(80, NULL, '2025-11-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(81, NULL, '2025-12-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(82, NULL, '2025-01-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(83, NULL, '2025-02-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(84, NULL, '2025-03-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(85, NULL, '2025-04-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(86, NULL, '2025-05-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(87, NULL, '2025-06-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(88, NULL, '2025-07-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(89, NULL, '2025-08-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(90, NULL, '2025-09-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(91, NULL, '2025-10-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(92, NULL, '2025-11-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(93, NULL, '2025-12-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(94, NULL, '2025-01-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(95, NULL, '2025-02-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(96, NULL, '2025-03-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(97, NULL, '2025-04-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(98, NULL, '2025-05-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(99, NULL, '2025-06-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(100, NULL, '2025-07-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(101, NULL, '2025-08-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(102, NULL, '2025-09-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(103, NULL, '2025-10-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(104, NULL, '2025-11-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(105, NULL, '2025-12-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(106, NULL, '2025-01-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(107, NULL, '2025-02-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(108, NULL, '2025-03-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(109, NULL, '2025-04-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(110, NULL, '2025-05-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(111, NULL, '2025-06-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(112, NULL, '2025-07-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(113, NULL, '2025-08-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(114, NULL, '2025-09-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(115, NULL, '2025-10-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(116, NULL, '2025-11-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(117, NULL, '2025-12-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(118, NULL, '2025-01-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(119, NULL, '2025-02-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(120, NULL, '2025-03-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(121, NULL, '2025-04-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(122, NULL, '2025-05-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(123, NULL, '2025-06-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(124, NULL, '2025-07-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(125, NULL, '2025-08-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(126, NULL, '2025-09-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(127, NULL, '2025-10-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(128, NULL, '2025-11-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(129, NULL, '2025-12-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(130, NULL, '2025-01-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(131, NULL, '2025-02-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(132, NULL, '2025-03-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(133, NULL, '2025-04-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(134, NULL, '2025-05-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(135, NULL, '2025-06-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(136, NULL, '2025-07-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(137, NULL, '2025-08-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(138, NULL, '2025-09-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(139, NULL, '2025-10-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(140, NULL, '2025-11-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(141, NULL, '2025-12-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(142, NULL, '2025-01-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(143, NULL, '2025-02-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(144, NULL, '2025-03-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(145, NULL, '2025-04-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(146, NULL, '2025-05-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(147, NULL, '2025-06-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(148, NULL, '2025-07-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(149, NULL, '2025-08-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(150, NULL, '2025-09-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(151, NULL, '2025-10-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(152, NULL, '2025-11-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(153, NULL, '2025-12-15 00:00:00', 1000.00, 0.00, 'Pending', NULL, NULL, NULL, 1, 'Completed', NULL, NULL, NULL, NULL, NULL),
(154, NULL, '2026-02-09 02:30:23', 1200.00, 0.00, 'Pending', NULL, NULL, 1, 1, 'Completed', '2026-02-08', 'Kuya Ed', 'Van - XYZ 888', '2026-02-09 02:30:44', '2026-02-09 02:30:46'),
(155, NULL, '2026-02-09 02:50:05', 100.00, 0.00, 'Pending', NULL, NULL, 1, 2, 'Completed', '2026-02-08', 'RYU', '123', '2026-02-09 02:50:37', '2026-02-09 02:50:52'),
(156, NULL, '2026-02-09 02:57:16', 2280.00, 2280.00, 'Paid', NULL, NULL, 1, 2, 'Completed', '2026-02-10', 'Lalamove (3rd Party)', 'N/A', '2026-02-09 02:57:27', '2026-02-09 02:57:29');

-- --------------------------------------------------------

--
-- Table structure for table `sales_items`
--

CREATE TABLE `sales_items` (
  `id` int(11) NOT NULL,
  `sale_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `price_at_sale` decimal(10,2) DEFAULT NULL,
  `quantity` decimal(10,2) DEFAULT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(10,2) DEFAULT NULL,
  `returned_qty` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sales_items`
--

INSERT INTO `sales_items` (`id`, `sale_id`, `product_id`, `price_at_sale`, `quantity`, `price`, `subtotal`, `returned_qty`) VALUES
(23, 19, 22, NULL, 2.00, 190.00, 380.00, 0.00),
(25, 21, 23, NULL, 1.00, 152.00, 152.00, 0.00),
(26, 22, 29, NULL, 1.00, 120.00, 120.00, 0.00),
(27, 23, 29, NULL, 1.00, 120.00, 120.00, 0.00),
(28, 24, 23, NULL, 2.00, 152.00, 304.00, 0.00),
(29, 24, 29, NULL, 2.00, 120.00, 240.00, 0.00),
(39, 33, 25, NULL, 11.00, 120.00, 1320.00, 0.00),
(41, 43, 22, NULL, 50.00, 10.00, 500.00, 0.00),
(42, 42, 22, NULL, 50.00, 10.00, 500.00, 0.00),
(43, 41, 22, NULL, 50.00, 10.00, 500.00, 0.00),
(44, 40, 22, NULL, 50.00, 10.00, 500.00, 0.00),
(45, 39, 22, NULL, 50.00, 10.00, 500.00, 0.00),
(46, 38, 22, NULL, 50.00, 10.00, 500.00, 0.00),
(47, 37, 22, NULL, 50.00, 10.00, 500.00, 0.00),
(48, 36, 22, NULL, 50.00, 10.00, 500.00, 0.00),
(49, 35, 22, NULL, 50.00, 10.00, 500.00, 0.00),
(50, 34, 22, NULL, 50.00, 10.00, 500.00, 0.00),
(51, 44, 22, NULL, 100.00, 10.00, 1000.00, 0.00),
(52, 45, 22, NULL, 100.00, 10.00, 1000.00, 0.00),
(53, 46, 22, NULL, 100.00, 10.00, 1000.00, 0.00),
(54, 47, 22, NULL, 100.00, 10.00, 1000.00, 0.00),
(55, 48, 22, NULL, 100.00, 10.00, 1000.00, 0.00),
(56, 49, 22, NULL, 100.00, 10.00, 1000.00, 0.00),
(57, 50, 22, NULL, 100.00, 10.00, 1000.00, 0.00),
(58, 51, 22, NULL, 100.00, 10.00, 1000.00, 0.00),
(59, 52, 22, NULL, 100.00, 10.00, 1000.00, 0.00),
(60, 53, 22, NULL, 100.00, 10.00, 1000.00, 0.00),
(61, 54, 22, NULL, 100.00, 10.00, 1000.00, 0.00),
(62, 55, 22, NULL, 100.00, 10.00, 1000.00, 0.00),
(66, 56, 23, NULL, 3.00, 152.00, 456.00, 0.00),
(67, 57, 23, NULL, 50.00, 152.00, 7600.00, 50.00),
(68, 58, 22, NULL, 60.00, 10.00, 100.00, 0.00),
(69, 59, 22, NULL, 79.60, 10.00, 100.00, 0.00),
(70, 60, 22, NULL, 149.00, 10.00, 100.00, 0.00),
(71, 61, 22, NULL, 138.00, 10.00, 100.00, 0.00),
(72, 62, 22, NULL, 118.00, 10.00, 100.00, 0.00),
(73, 63, 22, NULL, 162.00, 10.00, 100.00, 0.00),
(74, 64, 22, NULL, 90.00, 10.00, 100.00, 0.00),
(75, 65, 22, NULL, 61.00, 10.00, 100.00, 0.00),
(76, 66, 22, NULL, 110.00, 10.00, 100.00, 0.00),
(77, 67, 22, NULL, 113.00, 10.00, 100.00, 0.00),
(78, 68, 22, NULL, 271.00, 10.00, 100.00, 0.00),
(79, 69, 22, NULL, 208.00, 10.00, 100.00, 0.00),
(80, 70, 23, NULL, 87.40, 10.00, 100.00, 0.00),
(81, 71, 23, NULL, 84.60, 10.00, 100.00, 0.00),
(82, 72, 23, NULL, 89.00, 10.00, 100.00, 0.00),
(83, 73, 23, NULL, 81.00, 10.00, 100.00, 0.00),
(84, 74, 23, NULL, 132.00, 10.00, 100.00, 0.00),
(85, 75, 23, NULL, 142.00, 10.00, 100.00, 0.00),
(86, 76, 23, NULL, 123.00, 10.00, 100.00, 0.00),
(87, 77, 23, NULL, 87.00, 10.00, 100.00, 0.00),
(88, 78, 23, NULL, 118.00, 10.00, 100.00, 0.00),
(89, 79, 23, NULL, 63.00, 10.00, 100.00, 0.00),
(90, 80, 23, NULL, 346.50, 10.00, 100.00, 0.00),
(91, 81, 23, NULL, 208.00, 10.00, 100.00, 0.00),
(92, 82, 24, NULL, 28.00, 10.00, 100.00, 0.00),
(93, 83, 24, NULL, 58.40, 10.00, 100.00, 0.00),
(94, 84, 24, NULL, 126.00, 10.00, 100.00, 0.00),
(95, 85, 24, NULL, 96.00, 10.00, 100.00, 0.00),
(96, 86, 24, NULL, 78.00, 10.00, 100.00, 0.00),
(97, 87, 24, NULL, 108.00, 10.00, 100.00, 0.00),
(98, 88, 24, NULL, 100.00, 10.00, 100.00, 0.00),
(99, 89, 24, NULL, 55.00, 10.00, 100.00, 0.00),
(100, 90, 24, NULL, 84.00, 10.00, 100.00, 0.00),
(101, 91, 24, NULL, 158.00, 10.00, 100.00, 0.00),
(102, 92, 24, NULL, 250.50, 10.00, 100.00, 0.00),
(103, 93, 24, NULL, 303.50, 10.00, 100.00, 0.00),
(104, 94, 25, NULL, 94.00, 10.00, 100.00, 0.00),
(105, 95, 25, NULL, 39.60, 10.00, 100.00, 0.00),
(106, 96, 25, NULL, 134.00, 10.00, 100.00, 0.00),
(107, 97, 25, NULL, 162.00, 10.00, 100.00, 0.00),
(108, 98, 25, NULL, 73.00, 10.00, 100.00, 0.00),
(109, 99, 25, NULL, 115.00, 10.00, 100.00, 0.00),
(110, 100, 25, NULL, 71.00, 10.00, 100.00, 0.00),
(111, 101, 25, NULL, 98.00, 10.00, 100.00, 0.00),
(112, 102, 25, NULL, 52.00, 10.00, 100.00, 0.00),
(113, 103, 25, NULL, 83.00, 10.00, 100.00, 0.00),
(114, 104, 25, NULL, 347.00, 10.00, 100.00, 0.00),
(115, 105, 25, NULL, 128.50, 10.00, 100.00, 0.00),
(116, 106, 26, NULL, 31.80, 10.00, 100.00, 0.00),
(117, 107, 26, NULL, 61.60, 10.00, 100.00, 0.00),
(118, 108, 26, NULL, 95.00, 10.00, 100.00, 0.00),
(119, 109, 26, NULL, 66.00, 10.00, 100.00, 0.00),
(120, 110, 26, NULL, 135.00, 10.00, 100.00, 0.00),
(121, 111, 26, NULL, 75.00, 10.00, 100.00, 0.00),
(122, 112, 26, NULL, 114.00, 10.00, 100.00, 0.00),
(123, 113, 26, NULL, 72.00, 10.00, 100.00, 0.00),
(124, 114, 26, NULL, 98.00, 10.00, 100.00, 0.00),
(125, 115, 26, NULL, 103.00, 10.00, 100.00, 0.00),
(126, 116, 26, NULL, 255.00, 10.00, 100.00, 0.00),
(127, 117, 26, NULL, 305.50, 10.00, 100.00, 0.00),
(140, 130, 29, NULL, 76.80, 10.00, 100.00, 0.00),
(141, 131, 29, NULL, 41.20, 10.00, 100.00, 0.00),
(142, 132, 29, NULL, 43.00, 10.00, 100.00, 0.00),
(143, 133, 29, NULL, 45.00, 10.00, 100.00, 0.00),
(144, 134, 29, NULL, 128.00, 10.00, 100.00, 0.00),
(145, 135, 29, NULL, 93.00, 10.00, 100.00, 0.00),
(146, 136, 29, NULL, 49.00, 10.00, 100.00, 0.00),
(147, 137, 29, NULL, 136.00, 10.00, 100.00, 0.00),
(148, 138, 29, NULL, 133.00, 10.00, 100.00, 0.00),
(149, 139, 29, NULL, 100.00, 10.00, 100.00, 0.00),
(150, 140, 29, NULL, 194.50, 10.00, 100.00, 0.00),
(151, 141, 29, NULL, 314.00, 10.00, 100.00, 0.00),
(152, 142, 30, NULL, 44.60, 10.00, 100.00, 0.00),
(153, 143, 30, NULL, 67.00, 10.00, 100.00, 0.00),
(154, 144, 30, NULL, 150.00, 10.00, 100.00, 0.00),
(155, 145, 30, NULL, 123.00, 10.00, 100.00, 0.00),
(156, 146, 30, NULL, 51.00, 10.00, 100.00, 0.00),
(157, 147, 30, NULL, 103.00, 10.00, 100.00, 0.00),
(158, 148, 30, NULL, 44.00, 10.00, 100.00, 0.00),
(159, 149, 30, NULL, 81.00, 10.00, 100.00, 0.00),
(160, 150, 30, NULL, 100.00, 10.00, 100.00, 0.00),
(161, 151, 30, NULL, 126.00, 10.00, 100.00, 0.00),
(162, 152, 30, NULL, 349.00, 10.00, 100.00, 0.00),
(163, 153, 30, NULL, 189.00, 10.00, 100.00, 0.00),
(164, 154, 24, NULL, 10.00, 120.00, 1200.00, 0.00),
(165, 155, 30, NULL, 10.00, 10.00, 100.00, 0.00),
(166, 156, 23, NULL, 15.00, 152.00, 2280.00, 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `spoilage`
--

CREATE TABLE `spoilage` (
  `spoilage_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `spoilage_date` datetime DEFAULT current_timestamp(),
  `recorded_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `spoilage`
--

INSERT INTO `spoilage` (`spoilage_id`, `product_id`, `quantity`, `reason`, `spoilage_date`, `recorded_by`) VALUES
(1, 27, 10.00, 'Expired / Rotten', '2026-01-29 01:22:00', 1),
(2, 28, 1.00, 'Return from Sale #24: idk', '2026-02-03 13:55:25', NULL),
(3, 28, 2.00, 'Return from Sale #25: n/a', '2026-02-03 13:56:27', NULL),
(4, 28, 2.00, 'Return from Sale #26: rotten', '2026-02-03 14:01:19', NULL),
(5, 22, 25.00, 'Rotten / Wilted', '2026-02-01 17:26:32', 1),
(6, 23, 10.00, 'Moldy', '2026-01-29 17:26:32', 1),
(7, 22, 15.00, 'Expired Batch', '2026-01-09 17:26:32', 1),
(8, 23, 50.00, 'Return from Sale #57: rotten', '2026-02-09 01:21:32', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `thumbnail`
--

CREATE TABLE `thumbnail` (
  `thumbnail_id` int(200) NOT NULL,
  `product_id` int(200) NOT NULL,
  `thumbnail_img_1` varchar(255) DEFAULT NULL,
  `thumbnail_img_2` varchar(255) DEFAULT NULL,
  `thumbnail_img_3` varchar(255) DEFAULT NULL,
  `thumbnail_img_4` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `thumbnail`
--

INSERT INTO `thumbnail` (`thumbnail_id`, `product_id`, `thumbnail_img_1`, `thumbnail_img_2`, `thumbnail_img_3`, `thumbnail_img_4`) VALUES
(0, 0, '', '', '', ''),
(0, 0, '', '', '', ''),
(0, 0, '', '', '', ''),
(0, 0, '', '', '', ''),
(0, 0, '', '', '', ''),
(0, 1, '', '', '', ''),
(0, 4, '', '', '', ''),
(0, 3, '', '', '', ''),
(0, 2, '', '', '', ''),
(0, 5, '', '', '', ''),
(0, 2, '', '', '', ''),
(0, 6, '', '', '', ''),
(0, 7, '', '', '', ''),
(0, 8, '', '', '', ''),
(0, 11, '', '', '', ''),
(0, 14, '', '', '', ''),
(0, 13, '', '', '', ''),
(0, 22, '', '', '', ''),
(0, 26, '', '', '', ''),
(0, 25, '', '', '', ''),
(0, 29, '', '', '', ''),
(0, 24, '', '', '', ''),
(0, 30, '', '', '', ''),
(0, 23, '', '', '', '');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `first_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','staff') DEFAULT 'admin',
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` datetime DEFAULT current_timestamp(),
  `last_login` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `first_name`, `last_name`, `password`, `role`, `status`, `created_at`, `last_login`) VALUES
(1, 'Chrysler', 'Ralph Chrysler', 'Silva', '$2y$10$cHK17JCwxYoFiwGbY5ZUX.nEEXKxWG2/WXzMyWK9/VTPoJDB3jLYO', 'admin', 'Active', '2026-01-23 16:04:09', '2026-02-09 02:22:22'),
(2, 'Makima', 'Makima', 'Makima', '$2y$10$QiUbUk.9fFBX0/iLGjYpM.fAGOkm11.maLeS93vpi93Djc1OXFSQ6', 'admin', 'Active', '2026-01-23 17:22:16', '2026-01-27 21:03:01'),
(3, 'Yae', 'Yae', 'Miko', '$2y$10$hdttR91AwuT6VKlmjWKM1ut40c3cOiUgUL.IKf5GMY/gyOi2Cb8Fi', 'staff', 'Active', '2026-01-23 17:22:33', '2026-01-27 21:06:31'),
(4, 'Ryu', 'Ryu', 'Ballesteros', '$2y$10$PnEvTP5Wrl2AJtKS1X5MrOWr6vpAZ.qsGe8y2546yzTY9zU.QNqJW', 'staff', 'Active', '2026-01-25 11:46:10', '2026-01-25 11:49:18');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`category_id`);

--
-- Indexes for table `clients`
--
ALTER TABLE `clients`
  ADD PRIMARY KEY (`client_id`);

--
-- Indexes for table `deliveries`
--
ALTER TABLE `deliveries`
  ADD PRIMARY KEY (`delivery_id`),
  ADD KEY `sale_id` (`sale_id`);

--
-- Indexes for table `drivers`
--
ALTER TABLE `drivers`
  ADD PRIMARY KEY (`driver_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `sale_id` (`sale_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`product_id`);

--
-- Indexes for table `returns`
--
ALTER TABLE `returns`
  ADD PRIMARY KEY (`return_id`),
  ADD KEY `sale_id` (`sale_id`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`sale_id`);

--
-- Indexes for table `sales_items`
--
ALTER TABLE `sales_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `spoilage`
--
ALTER TABLE `spoilage`
  ADD PRIMARY KEY (`spoilage_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `clients`
--
ALTER TABLE `clients`
  MODIFY `client_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `deliveries`
--
ALTER TABLE `deliveries`
  MODIFY `delivery_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `drivers`
--
ALTER TABLE `drivers`
  MODIFY `driver_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `product_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `returns`
--
ALTER TABLE `returns`
  MODIFY `return_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `sale_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=157;

--
-- AUTO_INCREMENT for table `sales_items`
--
ALTER TABLE `sales_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=167;

--
-- AUTO_INCREMENT for table `spoilage`
--
ALTER TABLE `spoilage`
  MODIFY `spoilage_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `deliveries`
--
ALTER TABLE `deliveries`
  ADD CONSTRAINT `deliveries_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`sale_id`);

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`sale_id`) ON DELETE CASCADE;

--
-- Constraints for table `returns`
--
ALTER TABLE `returns`
  ADD CONSTRAINT `returns_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`sale_id`) ON DELETE CASCADE;

--
-- Constraints for table `sales_items`
--
ALTER TABLE `sales_items`
  ADD CONSTRAINT `sales_items_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`sale_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sales_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
