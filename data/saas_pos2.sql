-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 12, 2026 at 09:41 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `saas_pos`
--

DELIMITER $$
--
-- Procedures
--
DROP PROCEDURE IF EXISTS `oneerp_add_column_if_missing`$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `oneerp_add_column_if_missing` (IN `p_table_name` VARCHAR(128), IN `p_column_name` VARCHAR(128), IN `p_alter_sql` TEXT)   BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
          AND COLUMN_NAME = p_column_name
    ) THEN
        SET @oneerp_sql = p_alter_sql;
        PREPARE oneerp_stmt FROM @oneerp_sql;
        EXECUTE oneerp_stmt;
        DEALLOCATE PREPARE oneerp_stmt;
    END IF;
END$$

DROP PROCEDURE IF EXISTS `oneerp_add_index_if_missing`$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `oneerp_add_index_if_missing` (IN `p_table_name` VARCHAR(128), IN `p_index_name` VARCHAR(128), IN `p_alter_sql` TEXT)   BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
          AND INDEX_NAME = p_index_name
    ) THEN
        SET @oneerp_sql = p_alter_sql;
        PREPARE oneerp_stmt FROM @oneerp_sql;
        EXECUTE oneerp_stmt;
        DEALLOCATE PREPARE oneerp_stmt;
    END IF;
END$$

DROP PROCEDURE IF EXISTS `oneerp_prepare_offline_table`$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `oneerp_prepare_offline_table` (IN `p_table_name` VARCHAR(128))   BEGIN
    CALL oneerp_add_column_if_missing(p_table_name, 'uuid', CONCAT('ALTER TABLE `', p_table_name, '` ADD COLUMN `uuid` VARCHAR(64) NULL'));
    CALL oneerp_add_column_if_missing(p_table_name, 'device_id', CONCAT('ALTER TABLE `', p_table_name, '` ADD COLUMN `device_id` VARCHAR(100) NULL'));
    CALL oneerp_add_column_if_missing(p_table_name, 'sync_status', CONCAT('ALTER TABLE `', p_table_name, '` ADD COLUMN `sync_status` ENUM(''pending'',''synced'',''failed'') DEFAULT ''synced'''));
    CALL oneerp_add_column_if_missing(p_table_name, 'last_synced_at', CONCAT('ALTER TABLE `', p_table_name, '` ADD COLUMN `last_synced_at` DATETIME NULL'));
    CALL oneerp_add_column_if_missing(p_table_name, 'deleted_at', CONCAT('ALTER TABLE `', p_table_name, '` ADD COLUMN `deleted_at` DATETIME NULL'));
    CALL oneerp_add_column_if_missing(p_table_name, 'created_at_local', CONCAT('ALTER TABLE `', p_table_name, '` ADD COLUMN `created_at_local` DATETIME NULL'));
    CALL oneerp_add_column_if_missing(p_table_name, 'updated_at_local', CONCAT('ALTER TABLE `', p_table_name, '` ADD COLUMN `updated_at_local` DATETIME NULL'));
    CALL oneerp_add_index_if_missing(p_table_name, CONCAT('idx_', p_table_name, '_uuid'), CONCAT('ALTER TABLE `', p_table_name, '` ADD INDEX `idx_', p_table_name, '_uuid` (`uuid`)'));
    CALL oneerp_add_index_if_missing(p_table_name, CONCAT('idx_', p_table_name, '_sync'), CONCAT('ALTER TABLE `', p_table_name, '` ADD INDEX `idx_', p_table_name, '_sync` (`sync_status`,`last_synced_at`)'));
    CALL oneerp_add_index_if_missing(p_table_name, CONCAT('idx_', p_table_name, '_device'), CONCAT('ALTER TABLE `', p_table_name, '` ADD INDEX `idx_', p_table_name, '_device` (`device_id`)'));
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
--

CREATE TABLE `appointments` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `service_id` int(11) DEFAULT NULL,
  `appointment_date` date NOT NULL,
  `appointment_time` time NOT NULL,
  `status` varchar(50) DEFAULT 'pending',
  `branch_id` int(11) DEFAULT NULL,
  `sale_amount` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`id`, `client_id`, `customer_id`, `staff_id`, `service_id`, `appointment_date`, `appointment_time`, `status`, `branch_id`, `sale_amount`) VALUES
(4, 20, 18, 131, 6, '0000-00-00', '00:00:00', 'pending', NULL, 0.00),
(5, 20, 18, 131, 6, '0000-00-00', '00:00:00', 'pending', NULL, 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `membership_id` int(11) DEFAULT NULL,
  `date` date DEFAULT NULL,
  `check_in` time DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `client_id` int(11) DEFAULT NULL,
  `action` varchar(255) DEFAULT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `ip` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `branches`
--

CREATE TABLE `branches` (
  `id` int(11) NOT NULL,
  `client_id` int(11) DEFAULT NULL,
  `name` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `branches`
--

INSERT INTO `branches` (`id`, `client_id`, `name`, `address`, `uuid`, `device_id`, `sync_status`, `last_synced_at`, `deleted_at`, `created_at_local`, `updated_at_local`) VALUES
(7, 20, 'Saddar karachi', 'Zainab Markeet Abdullah haroon road saddar karachi', 'df69ed21646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(8, 18, 'ali', 'Punjabi Club Kharaddar karachi', 'df69fe1c646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(9, 21, 'Star', 'Star', 'df69ff78646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(10, 22, 'Rizwan Enterprises', 'Ahmad Town Main Road Khanpur', 'df6a00af646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(11, 23, 'Noushab\'s Wardrobe', 'City Test', 'df6a0213646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(12, 24, 'FMCG DISTRIBUTORS', 'KARACHI', 'df6a0375646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(13, 25, 'Agri Park Kalaswala', 'KARACHI', 'df6a0498646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(14, 26, 'Pakistan Events Hall', 'KARACHI', 'df6a05bc646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(15, 27, 'demo', 'demo', 'df6a06dd646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(16, 29, 'dua Book shop', 'Punjabi Club Kharaddar karachi', 'df6a07fb646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(17, 31, 'Cosmetics Distribute', ' Ahmad Town Main Road Khanpur', 'df6a1005646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(18, 32, 'resturent', 'I.I Chundrigar Rd', 'df6a1139646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(19, 33, 'test distrib', 'Punjabi Club Kharaddar karachi', 'df6a1238646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `branch_sessions`
--

CREATE TABLE `branch_sessions` (
  `branch_id` int(11) NOT NULL,
  `last_activity` datetime NOT NULL,
  `token` varchar(255) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `is_active` tinyint(4) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `branch_users`
--

CREATE TABLE `branch_users` (
  `id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `branch_users`
--

INSERT INTO `branch_users` (`id`, `branch_id`, `user_id`) VALUES
(4, 7, 130),
(5, 7, 131);

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `clients`
--

CREATE TABLE `clients` (
  `id` int(11) NOT NULL,
  `business_name` varchar(255) DEFAULT NULL,
  `strn_no` varchar(100) DEFAULT NULL,
  `ntn_no` varchar(100) DEFAULT NULL,
  `tenant_code` varchar(100) DEFAULT NULL,
  `business_address` varchar(255) DEFAULT NULL,
  `contact_number` varchar(50) DEFAULT NULL,
  `industry_id` int(11) DEFAULT NULL,
  `status` enum('active','suspended','deleted') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `clients`
--

INSERT INTO `clients` (`id`, `business_name`, `strn_no`, `ntn_no`, `tenant_code`, `business_address`, `contact_number`, `industry_id`, `status`, `created_at`, `uuid`, `device_id`, `sync_status`, `last_synced_at`, `deleted_at`, `created_at_local`, `updated_at_local`) VALUES
(18, 'ali', NULL, NULL, NULL, 'Punjabi Club Kharaddar karachi', '03312805498', 11, 'active', '2026-05-06 13:41:28', 'df6796d8646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(20, 'Asad Traders', NULL, NULL, NULL, 'Karachi Pakistan', '03312805498', 11, 'active', '2026-05-06 13:50:02', 'df67a88d646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(21, 'Star', NULL, NULL, NULL, 'Star', '0300000000000', 11, '', '2026-05-07 08:27:26', 'df67aa3f646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(22, 'Rizwan Enterprises', NULL, NULL, NULL, 'Ahmad Town Main Road Khanpur', '03000065202', 11, 'active', '2026-05-07 10:29:29', 'df67aba3646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(23, 'Noushab\'s Wardrobe', NULL, NULL, NULL, 'City Test', '03424109933', 11, 'active', '2026-05-08 12:40:16', 'df67acab646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(24, 'FMCG DISTRIBUTORS', NULL, NULL, NULL, 'KARACHI', '03282607998', 11, 'active', '2026-05-08 13:21:17', 'df67ada2646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(25, 'Agri Park Kalaswala', NULL, NULL, NULL, 'KARACHI', '03460244440', 11, 'active', '2026-05-08 13:51:28', 'df67ae97646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(26, 'Pakistan Events Hall', NULL, NULL, NULL, 'KARACHI', '03041204040', 11, 'active', '2026-05-08 14:08:38', 'df67af7d646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(27, 'demo', NULL, NULL, NULL, 'demo', '03421777906', 11, 'active', '2026-05-09 07:00:55', 'df67b05b646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(29, 'dua Book shop', NULL, NULL, NULL, 'Punjabi Club Kharaddar karachi', '03312805498', 11, 'active', '2026-05-11 14:43:20', 'df67b13d646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(31, 'Cosmetics Distribute', NULL, NULL, NULL, ' Ahmad Town Main Road Khanpur', '0300-0065202 ', 11, 'active', '2026-05-13 06:41:20', 'df67b219646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(32, 'resturent', NULL, NULL, NULL, 'I.I Chundrigar Rd', '0327676423', 12, 'active', '2026-06-03 08:23:42', 'df67b2f4646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(33, 'test distrib', NULL, NULL, NULL, 'Punjabi Club Kharaddar karachi', '03112638853', 12, '', '2026-06-04 07:37:56', 'df67b3d2646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(34, 'dheeraj-resturent', NULL, NULL, NULL, 'Punjabi Club Kharaddar karachi', '03312805498', 13, 'active', '2026-06-08 07:52:00', 'df67b4b1646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `client_feature_overrides`
--

CREATE TABLE `client_feature_overrides` (
  `client_id` int(11) DEFAULT NULL,
  `feature_key` varchar(100) DEFAULT NULL,
  `enabled` tinyint(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `client_feature_overrides`
--

INSERT INTO `client_feature_overrides` (`client_id`, `feature_key`, `enabled`) VALUES
(31, 'inventory', 0);

-- --------------------------------------------------------

--
-- Table structure for table `client_settings`
--

CREATE TABLE `client_settings` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `business_name` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `currency` varchar(10) DEFAULT 'USD',
  `timezone` varchar(50) DEFAULT 'UTC',
  `tax_percentage` decimal(5,2) DEFAULT 0.00,
  `discount_percentage` decimal(5,2) DEFAULT 0.00,
  `notification_sound` varchar(255) DEFAULT NULL,
  `theme_color` varchar(20) DEFAULT '#0d6efd',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `sidebar_color` varchar(7) DEFAULT '#0d6efd',
  `header_color` varchar(7) DEFAULT '#0d6efd',
  `sidebar_text_color` varchar(7) DEFAULT '#ffffff',
  `sidebar_hover_color` varchar(7) DEFAULT '#0d6efd',
  `header_text_color` varchar(7) DEFAULT '#ffffff',
  `header_hover_color` varchar(7) DEFAULT '#0d6efd',
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `client_settings`
--

INSERT INTO `client_settings` (`id`, `client_id`, `logo`, `business_name`, `address`, `email`, `phone`, `currency`, `timezone`, `tax_percentage`, `discount_percentage`, `notification_sound`, `theme_color`, `created_at`, `updated_at`, `sidebar_color`, `header_color`, `sidebar_text_color`, `sidebar_hover_color`, `header_text_color`, `header_hover_color`, `uuid`, `device_id`, `sync_status`, `last_synced_at`, `deleted_at`, `created_at_local`, `updated_at_local`) VALUES
(23, 21, NULL, 'Star', 'Star', 'naumanali001@gmail.com', '0300000000000', 'USD', 'UTC', 0.00, 0.00, NULL, '#0d6efd', '2026-05-07 08:27:26', '2026-06-10 01:44:08', '#0d6efd', '#0d6efd', '#ffffff', '#0d6efd', '#ffffff', '#0d6efd', 'df84ffe3646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(24, 23, NULL, 'Noushab\'s Wardrobe', 'City Test', 'designdkor@gmail.com', '03424109933', 'USD', 'UTC', 0.00, 0.00, NULL, '#0d6efd', '2026-05-08 12:40:16', '2026-06-10 01:44:08', '#0d6efd', '#0d6efd', '#ffffff', '#0d6efd', '#ffffff', '#0d6efd', 'df8501b7646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(26, 33, NULL, 'test distrib', 'Punjabi Club Kharaddar karachi', 'gapsoe2015@gmail.com', '03112638853', 'USD', 'UTC', 0.00, 0.00, NULL, '#0d6efd', '2026-06-04 07:37:56', '2026-06-10 01:44:08', '#0d6efd', '#0d6efd', '#ffffff', '#0d6efd', '#ffffff', '#0d6efd', 'df8502d6646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `coupons`
--

CREATE TABLE `coupons` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL,
  `discount_type` enum('percent','fixed') NOT NULL,
  `discount_value` decimal(10,2) NOT NULL,
  `min_bill` decimal(10,2) DEFAULT 0.00,
  `usage_limit` int(11) DEFAULT 0,
  `used_count` int(11) DEFAULT 0,
  `expiry_date` date DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `coupons`
--

INSERT INTO `coupons` (`id`, `client_id`, `code`, `discount_type`, `discount_value`, `min_bill`, `usage_limit`, `used_count`, `expiry_date`, `status`, `created_at`, `uuid`, `device_id`, `sync_status`, `last_synced_at`, `deleted_at`, `created_at_local`, `updated_at_local`) VALUES
(6, 29, 'SD3YP', 'fixed', 11.00, 22.00, 2, 4, '2026-05-03', 'active', '2026-05-15 12:58:41', 'df805db9646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int(11) NOT NULL,
  `client_id` int(11) DEFAULT NULL,
  `name` varchar(150) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `type` enum('Walk-in','Wholesaler','Retailer') NOT NULL DEFAULT 'Walk-in',
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(64) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'pending',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`id`, `client_id`, `name`, `phone`, `email`, `address`, `type`, `uuid`, `device_id`, `sync_status`, `last_synced_at`, `deleted_at`, `updated_at`, `created_at_local`, `updated_at_local`) VALUES
(18, 20, 'Asad Shah', '03312805498', 'asadullah.vg@gmail.com', 'testing address karachi pakistan', 'Retailer', 'df6d688a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(334, 27, 'Ahmed Raza', '03001234567', 'ahmed.raza@gmail.com', 'Gulshan-e-Iqbal, Karachi', 'Retailer', 'df6d8a2f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(335, 27, 'Sara Khan', '03111222333', 'sara.khan@yahoo.com', 'DHA Phase 6, Karachi', '', 'df6d8b0d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(336, 27, 'Bilal Sheikh', '03219876543', 'bilal.sheikh@hotmail.com', 'North Nazimabad, Karachi', 'Retailer', 'df6d8bfc646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(337, 27, 'Hina Malik', '03335557788', 'hina.malik@gmail.com', 'Clifton Block 5, Karachi', 'Walk-in', 'df6d8c7d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(338, 27, 'Usman Ali', '03456789012', 'usman.ali@outlook.com', 'Bahadurabad, Karachi', 'Retailer', 'df6d8cef646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(339, 27, 'Areeba Noor', '03099887766', 'areeba.noor@gmail.com', 'PECHS Block 2, Karachi', '', 'df6d8d5d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(340, 27, 'Faisal Qureshi', '03124567890', 'faisal.q@gmail.com', 'Federal B Area, Karachi', 'Retailer', 'df6d8dcb646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(341, 27, 'Zoya Ahmed', '03225678901', 'zoya.ahmed@yahoo.com', 'Malir Cantt, Karachi', 'Retailer', 'df6d8e38646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(342, 27, 'Hamza Tariq', '03339887755', 'hamza.tariq@gmail.com', 'Scheme 33, Karachi', 'Walk-in', 'df6d8ea2646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(343, 27, 'Maham Siddiqui', '03451239876', 'maham.s@gmail.com', 'Korangi Creek, Karachi', '', 'df6d8f0d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(607, 29, 'Laksham', '1234567890', 'lakshman@gmail.com', 'Punjabi Club Kharaddar karachi', 'Wholesaler', 'df6d8f78646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(608, 29, 'Dua', '', '', '', 'Wholesaler', 'df6d8fe6646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(609, 29, 'Karachi Book Shop (SHZ)', '', '', '', 'Wholesaler', 'df6d9058646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(610, 29, 'Parkash', '3053519697', 'test@gmail.com', 'karachi', 'Wholesaler', 'df6d90c7646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(611, 29, 'Book Bazar', '', '', '', 'Wholesaler', 'df6d9133646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(612, 29, 'Altaf T Sons 2', '', '', '', 'Wholesaler', 'df6d919d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(613, 29, 'Hamza Mukaty', '', '', '', 'Wholesaler', 'df6d9207646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(614, 29, 'Bilal (Nazmabad)', '0335-0253706', '', '', 'Wholesaler', 'df6d926e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(615, 29, 'Jadoon Old Books (Zar Walli  Bhai)', '0321 2688465', '', '', 'Wholesaler', 'df6d92d8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(616, 29, 'Younus Book (UrduBazar)', '', '', '', 'Wholesaler', 'df6d934f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(617, 29, 'Chapter 2 (DHA)', '3306668877', '', '', 'Wholesaler', 'df6d93b7646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(618, 29, 'Sarfaraz Stall', '', '', '', 'Wholesaler', 'df6d941e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(619, 29, 'Rehmat Book (UrduBazar)', '', '', '', 'Wholesaler', 'df6d9486646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(620, 29, 'Madina Books', '', '', '', 'Wholesaler', 'df6d9537646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(621, 29, 'Abdull Rehman Daraz', '0302-2912668', '', '', 'Wholesaler', 'df6d95aa646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(622, 29, 'Asif Anwar', '0332-8202677', '', '', 'Wholesaler', 'df6d9614646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(623, 29, 'Asim', '0313-2147883', '', '', 'Wholesaler', 'df6d967c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(624, 29, 'Zayan (Pattni)', '0319-8655687', '', '', 'Wholesaler', 'df6d96e3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(625, 29, 'Asif Book (Guleshan)', '', '', '', 'Wholesaler', 'df6d9778646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(626, 29, 'Karan', '', '', '', 'Wholesaler', 'df6d97e7646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(627, 29, 'This And That (Nabeel)', '0334-3549503', '', '', 'Wholesaler', 'df6d985a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(628, 29, 'Order On Door (Nabeel)', '3172673230', '', '', 'Wholesaler', 'df6d98c4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(629, 29, 'Hamza Daraz', '0332-3537189', '', '', 'Wholesaler', 'df6d992a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(630, 29, 'Expert Readers', '0315-4929490', '', '', 'Wholesaler', 'df6d9992646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(631, 29, 'Noor Book Center', '0336-1003535', '', '', 'Wholesaler', 'df6d99fb646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(632, 29, 'Tawwakal Acadmy', '0316-9042256', '', '', 'Wholesaler', 'df6d9a61646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(633, 29, 'Shahid Baloch', '0332-2149979', '', '', 'Wholesaler', 'df6d9ac8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(634, 29, 'Syed Book Land (Jackababad)', '', '', '', 'Wholesaler', 'df6d9b2e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(635, 29, 'Usmania Books (UrduBazar)', '', '', '', 'Wholesaler', 'df6d9b94646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(636, 29, 'AL Barkat (UrduBazar)', '', '', '', 'Wholesaler', 'df6d9bfa646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(637, 29, 'Al Hamd (UrduBazar)', '', '', '', 'Wholesaler', 'df6d9c62646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(638, 29, 'Al Kitab (UrduBazar)', '', '', '', 'Wholesaler', 'df6d9cc8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(639, 29, 'American Book Center (UrduBazar)', '', '', '', 'Wholesaler', 'df6d9d2d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(640, 29, 'Salman Books Center (UrduBazar)', '', '', '', 'Wholesaler', 'df6d9d92646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(641, 29, 'Sana Book (Shado)', '', '', '', 'Wholesaler', 'df6d9df8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(642, 29, 'Sana Book (Taha)', '', '', '', 'Wholesaler', 'df6d9ea9646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(643, 29, 'Sana Book (Fahad)', '', '', '', 'Wholesaler', 'df6d9f18646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(644, 29, 'Qayyume Book Depot (UrduBazar)', '', '', '', 'Wholesaler', 'df6d9f7d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(645, 29, 'MBP Book (UrduBazar)', '', '', '', 'Wholesaler', 'df6da02f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(646, 29, 'City Book Center (Fahad)', '', '', '', 'Wholesaler', 'df6da09b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(647, 29, 'Adnan Book (Stall)', '', '', '', 'Wholesaler', 'df6da103646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(648, 29, 'Raja (Stall)', '', '', '', 'Wholesaler', 'df6da169646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(649, 29, 'Al Rehman Book Center', '', '', '', 'Wholesaler', 'df6da1cf646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(650, 29, 'Rehman Book Mart', '', '', '', 'Wholesaler', 'df6da238646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(651, 29, 'Rehber Publishers (UrduBazar)', '', '', '', 'Wholesaler', 'df6da29f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(652, 29, 'Shahzaib (Rehber)', '', '', '', 'Wholesaler', 'df6da306646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(653, 29, 'Defence Book Stall', '', '', '', 'Wholesaler', 'df6da36b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(654, 29, 'Khairpur Book Stall (Khairpur)', '', '', '', 'Wholesaler', 'df6da3d4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(655, 29, 'Multan Kitab Ghar (Multan)', '', '', '', 'Wholesaler', 'df6da43b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(656, 29, 'Student Book (Hydrabad)', '', '', '', 'Wholesaler', 'df6da4a2646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(657, 29, 'M A Book Center (Hydrabad)', '', '', '', 'Wholesaler', 'df6da508646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(658, 29, 'Mustafa Anees', '', '', '', 'Wholesaler', 'df6da570646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(659, 29, 'JBD Press', '', '', '', 'Wholesaler', 'df6da5d7646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(660, 29, 'Faizee (Farzan)', '', '', '', 'Wholesaler', 'df6da63d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(661, 29, 'Umer Guleshan', '', '', '', 'Wholesaler', 'df6da6a3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(662, 29, 'Umair (Malir)', '', '', '', 'Wholesaler', 'df6da70b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(663, 29, 'Umair (Gutka)', '0321-2322586', '', '', 'Wholesaler', 'df6da7b9646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(664, 29, 'Vimco Book Center', '', '', '', 'Wholesaler', 'df6da828646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(665, 29, 'Hamid Book (Quetta)', '', '', '', 'Wholesaler', 'df6da895646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(666, 29, 'SS Book (Sakhi Hassan)', '0300-2868317', '', '', 'Wholesaler', 'df6da8ff646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(667, 29, 'Shakeel Books (Haris)', '', '', '', 'Wholesaler', 'df6da969646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(668, 29, 'Jeevan', '', '', '', 'Wholesaler', 'df6da9d1646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(669, 29, 'Rizwan Bazar', '3145347354', '', '', 'Wholesaler', 'df6daac4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(670, 29, 'Waqar Brothers (Urdu Bazar)', '3212202576', '', '', 'Wholesaler', 'df6dac65646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(671, 29, 'Star  Books (UrduBazar)', '', '', '', 'Wholesaler', 'df6dad18646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(672, 29, 'MH Book Center (UrduBazar)', '0330-2055918', '', '', 'Wholesaler', 'df6dad96646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(673, 29, 'Ilmi Book Depot (Lahore)', '0300-4414150', '', '', 'Wholesaler', 'df6dae05646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(674, 29, 'Star Book House (Hydrabad)', '0346-3632989', '', '', 'Wholesaler', 'df6dae71646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(675, 29, 'Usama (Obo)', '0348-2105448', '', '', 'Wholesaler', 'df6daedd646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(676, 29, 'Mehmood (Guddubhai)', '0333-2315808', '', '', 'Wholesaler', 'df6daf47646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(677, 29, 'Kafeel Books (UrduBazar)', '', '', '', 'Wholesaler', 'df6dafb0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(678, 29, 'Mehran Stall (Urdu Bazar)', '', '', '', 'Wholesaler', 'df6db019646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(679, 29, 'Kathiawar Book (Urdu Bazar)', '', '', '', 'Wholesaler', 'df6db084646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(680, 29, 'Inshal Naveed (Bookstore)', '3240220181', 'bookstore247.pk@gmail.com', '', 'Wholesaler', 'df6db0ed646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(681, 29, 'Novelty Books (Urdu Bazar)', '3122288229', '', '', 'Wholesaler', 'df6db322646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(682, 29, 'Sohail Brothers (Urdu Bazar)', '', '', '', 'Wholesaler', 'df6db3a2646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(683, 29, 'Dogar Publisher', '', '', '', 'Wholesaler', 'df6db433646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(684, 29, 'National Book Seller (Urdu Bazar)', '', '', '', 'Wholesaler', 'df6db4a1646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(685, 29, 'Deedar Supplier (Gulshan e Hadeed)', '3108205807', '', '', 'Wholesaler', 'df6db50c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(686, 29, 'Dawood (Home)', '', '', '', 'Wholesaler', 'df6db59e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(687, 29, 'Iqbal Book Depot (Saddar)', '', '', '', 'Wholesaler', 'df6db613646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(688, 29, 'Zam Zam Book Point', '0346-3193883', '', '', 'Wholesaler', 'df6db67e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(689, 29, 'Ideal Book', '0335-1200616', '', '', 'Wholesaler', 'df6db6e5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(690, 29, 'Rashid Shop', '', '', '', 'Wholesaler', 'df6db750646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(691, 29, 'Sahifas Book Mine', '0300-2514525', '', '', 'Wholesaler', 'df6db7bd646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(692, 29, 'Apna Kitab Ghar', '', '', '', 'Wholesaler', 'df6db888646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(693, 29, 'Atif Bhai (Citizen)', '0300-2017874', '', '', 'Wholesaler', 'df6db940646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(694, 29, 'Sawood Bazar (Korangi)', '0317-1255067', '', '', 'Wholesaler', 'df6db9e9646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(695, 29, 'Huzaifa Book (UrduBazar)', '', '', '', 'Wholesaler', 'df6dba65646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(696, 29, 'Waris', '', '', 'Nazimab', 'Wholesaler', 'df6dbad0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(697, 29, 'Faisal Gaba', '3009244680', '', '', 'Wholesaler', 'df6dbb3a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(698, 29, 'Stationary World (Urdu Bazar)', '3212249223', '', '', 'Wholesaler', 'df6dbba1646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(699, 29, 'Book Shop (Ahsan)', '0321-1252375', '', '', 'Wholesaler', 'df6dbc08646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(700, 29, 'Mehran Book (Sukkur)', '0316-8575512', '', '', 'Wholesaler', 'df6dbc6f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(701, 29, 'Tariq Book (Tariq Road)', '0333-2201612', '', '', 'Wholesaler', 'df6dbcd5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(702, 29, 'Prince Book Depot (UrduBazar)', '', '', '', 'Wholesaler', 'df6dbd3c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(703, 29, 'RBS Book Shop', '', '', '', 'Wholesaler', 'df6dbda4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(704, 29, 'Qasim Sons', '', '', '', 'Wholesaler', 'df6dbe0c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(705, 29, 'Vip Book', '', '', '', 'Wholesaler', 'df6dbe73646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(706, 29, 'Abid (Frooq Kitab Ghar)', '', '', '', 'Wholesaler', 'df6dbedb646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(707, 29, 'Shamim Book (Korangi)', '3313740170', '', '', 'Wholesaler', 'df6dbf42646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(708, 29, 'Noman Supplier', '3330376243', '', 'Malir', 'Wholesaler', 'df6dbfaa646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(709, 29, 'Masood Saleman', '3122055402', '', '', 'Wholesaler', 'df6dc024646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(710, 29, 'Fareed Publishers', '', '', '', 'Wholesaler', 'df6dc0a2646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(711, 29, 'Azad Publishers', '', '', '', 'Wholesaler', 'df6dc180646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(712, 29, 'Zam Zam (Nazimabad)', '', '', '', 'Wholesaler', 'df6dc20f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(713, 29, 'Al Wahab Book And Stationary', '0320-2062791', '', '', 'Wholesaler', 'df6dc287646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(714, 29, 'Pakistan Law House', '', '', '', 'Wholesaler', 'df6dc300646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(715, 29, 'Hamza Book Market', '', '', '', 'Wholesaler', 'df6dc375646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(716, 29, 'Sindh Kitab Ghar (Sukkur)', '', '', '', 'Wholesaler', 'df6dc3e4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(717, 29, 'Book Hub', '0331-3375277', '', '', 'Wholesaler', 'df6dc453646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(718, 29, 'Book Town', '0309-2719683', '', '', 'Wholesaler', 'df6dc4c3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(719, 29, 'Sajjid Bhai Book Fair', '', '', '', 'Wholesaler', 'df6dc534646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(720, 29, 'Bin Saeed', '', '', '', 'Wholesaler', 'df6dc5a3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(721, 29, 'SB Books (Guleshan)', '0336-1203559', '', '', 'Wholesaler', 'df6dc614646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(722, 29, 'Qammar Kitab Ghar', '', '', '', 'Wholesaler', 'df6dc687646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(723, 29, 'Hamza Book Shop (Hannan)', '3452998843', '', '', 'Wholesaler', 'df6dc6f7646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(724, 29, 'Classic Book (Urdu Bazar)', '', '', '', 'Wholesaler', 'df6dc768646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(725, 29, 'Kamran Phatan', '0333-3178944', '', '', 'Wholesaler', 'df6dc7d9646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(726, 29, 'Mehmood Stall', '0313-2632411', '', '', 'Wholesaler', 'df6dc84b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(727, 29, 'Mairaj', '', '', '', 'Wholesaler', 'df6dc8be646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(728, 29, 'Saif Online', '3313786779', '', '', 'Wholesaler', 'df6dc932646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(729, 29, 'Razaq Book (Saddar)', '3212891099', '', '', 'Wholesaler', 'df6dc9a5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(730, 29, 'Idrees Book Bank (Rawat)', '3330794447', '', '', 'Wholesaler', 'df6dca18646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(731, 29, 'Farhan (Nawabshah)', '0312- 3637001', '', '', 'Wholesaler', 'df6dca8c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(732, 29, 'Naeem Supplier', '3012561622', '', '', 'Wholesaler', 'df6dcafe646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(733, 29, 'Farooq Kitab Ghar', '', '', '', 'Wholesaler', 'df6dcb71646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(734, 29, 'Choice Book (UrduBazar)', '0300 2422629', '', '', 'Wholesaler', 'df6dcc15646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(735, 29, 'Rehber Book (Larkana)', '0335-1368782', '', '', 'Wholesaler', 'df6dcd5d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(736, 29, 'Pakistan Book House (Hydrabad)', '3443050698', '', '', 'Wholesaler', 'df6dce84646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(737, 29, 'Anwar Book Seller', '0320-4061290', '', '', 'Wholesaler', 'df6dcf7b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(738, 29, 'Tanveer Stationers', '3100104428', '', '', 'Wholesaler', 'df6dd069646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(739, 29, 'Karachi Stationers', '0333-3085553', '', '', 'Wholesaler', 'df6de4f9646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(740, 29, 'Delta Stationery', '', '', '', 'Wholesaler', 'df6de623646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(741, 29, 'Moon Bhai (UrduBazar)', '', '', '', 'Wholesaler', 'df6de6fa646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(742, 29, 'Aatish Library', '0302-2013499', '', '', 'Wholesaler', 'df6de7b3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(743, 29, 'Bilal Ahmed', '3122480637', '', '', 'Wholesaler', 'df6de83a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(744, 29, 'Maktab e Inamiya', '3452151205', '', '', 'Wholesaler', 'df6de8b3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(745, 29, 'Book Ocean', '3092775578', '', '', 'Wholesaler', 'df6de92a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(746, 29, 'Book Point', '0315-1116999', '', '', 'Wholesaler', 'df6de99b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(747, 29, 'Alam Book Stall', '3102628091', '', '', 'Wholesaler', 'df6dea0e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(748, 29, 'Usama Book Shop', '3263401150', '', '', 'Wholesaler', 'df6dea7d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(749, 29, 'Subhan Book Center', '', '', '', 'Wholesaler', 'df6deaef646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(750, 29, 'nice mossa urdu bazar', '', '', '', 'Wholesaler', 'df6deb5f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(751, 29, 'NJV School Library', '', '', '', 'Wholesaler', 'df6debd0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(752, 29, 'M A Book Seller (UrduBazar)', '', '', '', 'Wholesaler', 'df6dec40646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(753, 29, 'Shehzad Bhai (Saleman)', '0331 2344886', '', '', 'Wholesaler', 'df6decae646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(754, 29, 'Varity Book Shop', '3343095332', '', '', 'Wholesaler', 'df6ded22646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(755, 29, 'A S Books Shop', '', '', '', 'Wholesaler', 'df6ded92646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(756, 29, 'Manna Stall', '', '', '', 'Wholesaler', 'df6dee03646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(757, 29, 'Azmat Defence', '3082159151', '', '', 'Wholesaler', 'df6dee77646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(758, 29, 'Taj Books (Gulestan e Johar)', '3008919118', '', '', 'Wholesaler', 'df6defdc646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(759, 29, 'Al Falah Book Centre', '3323284238', '', '', 'Wholesaler', 'df6df055646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(760, 29, 'Prime Book Solution', '3318073751', '', '', 'Wholesaler', 'df6df0c6646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(761, 29, 'Abbas Bhai (Guleshan)', '3442434655', '', '', 'Wholesaler', 'df6df137646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(762, 29, 'Fahad Bhai Bazat', '3172625116', '', '', 'Wholesaler', 'df6df1a7646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(763, 29, 'Student Book point (UP)', '3142235415', '', '', 'Wholesaler', 'df6df216646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(764, 29, 'Afzal Bhai (ATM)', '3144076919', '', '', 'Wholesaler', 'df6df288646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(765, 29, 'Amynabad Library', '3111307348', '', '', 'Retailer', 'df6df355646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(766, 29, 'books And Books', '3009273255', '', '', 'Wholesaler', 'df6df431646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(767, 29, 'Shariq Rex', '3122543176', '', '', 'Wholesaler', 'df6df4f4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(768, 29, 'Saad Memon', '', '', '', 'Wholesaler', 'df6df5ac646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(769, 29, 'Low Price', '3002780900', '', '', 'Wholesaler', 'df6df62b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(770, 29, 'Old And New Book Shop', '3115910698', '', '', 'Wholesaler', 'df6df69f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(771, 29, 'MM Book Center', '3161221705', '', '', 'Wholesaler', 'df6df711646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(772, 29, 'Tehzeeb Book Shop', '', '', '', 'Wholesaler', 'df6df780646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(773, 29, 'Annu Book Bank', '3103434062', '', '', 'Wholesaler', 'df6df7f1646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(774, 29, 'Asif Book (Nazmabad)', '3343955748', '', '', 'Wholesaler', 'df6df860646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(775, 29, 'Kashif Hydri', '', '', '', 'Wholesaler', 'df6df8ff646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(776, 29, 'Anas Book Shalf', '3302038004', '', '', 'Wholesaler', 'df6df974646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(777, 29, 'Faisal Bhai', '3443005653', '', '', 'Wholesaler', 'df6df9e5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(778, 29, 'Zamir Book Center', '', '', '', 'Wholesaler', 'df6dfa53646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(779, 29, 'Touseef Book (UrduBazar)', '', '', '', 'Wholesaler', 'df6dfac2646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(780, 29, 'Al Fajar Book Shop', '3362311710', '', '', 'Wholesaler', 'df6dfb30646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(781, 29, 'Ibrahim Gaba', '', '', '', 'Wholesaler', 'df6dfb9f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(782, 29, 'Ahmed (BookFair)', '', '', '', 'Wholesaler', 'df6dfc0d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(783, 29, 'Kashif Shop', '', '', '', 'Wholesaler', 'df6dfc7b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(784, 29, 'Aniya Hashmi', '3172603089', '', '', 'Wholesaler', 'df6dfcea646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(785, 29, 'vip stall', '', '', '', 'Wholesaler', 'df6dfd57646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(786, 29, 'Ibrahim (Sharfabad)', '3222028267', '', '', 'Wholesaler', 'df6dfdc5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(787, 29, 'Ali Bhai Old Book', '3370386394', '', '', 'Wholesaler', 'df6dfe34646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(788, 29, 'G S Book (UrduBazar)', '3151242668', '', '', 'Wholesaler', 'df6dfea0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(789, 29, 'Saima Books (Urdu Bazaar)', '', '', '', 'Wholesaler', 'df6dff0e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(790, 29, 'Jumma Bhai', '', '', '', 'Wholesaler', 'df6dff7c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(791, 29, 'Saeed Bhai Book Fair', '', '', '', 'Wholesaler', 'df6dffea646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(792, 29, 'Karachi Book A One', '3112684295', '', '', 'Wholesaler', 'df6e0059646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(793, 29, '3 Star Book (Hydrabad)', '', '', '', 'Wholesaler', 'df6e00c8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(794, 29, 'Books N Books (Multan)', '', '', '', 'Wholesaler', 'df6e0137646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(795, 29, 'Aisha Bawani School', '3363218813', '', '', 'Wholesaler', 'df6e01a4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(796, 29, 'Meezan Book Stall (Sukkur)', '3113397863', '', '', 'Wholesaler', 'df6e0212646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(797, 29, 'Maryam Acadmy', '', '', '', 'Wholesaler', 'df6e0280646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(798, 29, 'Kamran Book Fair', '', '', '', 'Wholesaler', 'df6e02f0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(799, 29, 'Reading Tree', '0337-8070454', '', '', 'Wholesaler', 'df6e03bc646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(800, 29, 'Aftab Brothers', '', '', '', 'Wholesaler', 'df6e0430646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(801, 29, 'Childern Publisher', '', '', '', 'Wholesaler', 'df6e049f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(802, 29, 'Al Farooq', '', '', '', 'Wholesaler', 'df6e0516646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(803, 29, 'Childern Choice Book Fair', '', '', '', 'Wholesaler', 'df6e0589646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(804, 29, 'Books N Beyond Shop', '0309 7271940', '', '', 'Wholesaler', 'df6e05f8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(805, 29, 'Arif Bhai (Islamabad)', '', '', '', 'Wholesaler', 'df6e0667646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(806, 29, 'Ahmed Tando Ali', '3322783919', '', '', 'Wholesaler', 'df6e06d5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(807, 29, 'Naveed Crossing', '3114832316', '', '', 'Wholesaler', 'df6e0746646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(808, 29, 'Imran Book Fair (HYD)', '', '', '', 'Wholesaler', 'df6e07b5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(809, 29, 'Army Public School (COD)', '', '', '', 'Wholesaler', 'df6e0823646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(810, 29, 'Badar Bhai', '', '', '', 'Wholesaler', 'df6e0891646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(811, 29, 'Tahir Sons', '', '', '', 'Wholesaler', 'df6e0901646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(812, 29, 'College Book Canter', '3021184336', '', '', 'Wholesaler', 'df6e096d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(813, 29, 'Faisal Book Market', '', '', '', 'Wholesaler', 'df6e09dd646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(814, 29, 'Sohail (Hyderabad)', '3443050698', '', '', 'Wholesaler', 'df6e0a4b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(815, 29, 'Global Books (DHA)', '3258985879', '', '', 'Wholesaler', 'df6e0abb646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(816, 29, 'Sagar book (Larkana)', '', '', '', 'Wholesaler', 'df6e0b2a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(817, 29, 'Nisar And Sons', '', '', '', 'Wholesaler', 'df6e0b9a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(818, 29, 'Ghaffar Book (Quetta)', '0345 3759522', '', '', 'Wholesaler', 'df6e0c32646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(819, 29, 'Ghossia Book (Agrah Taj)', '3152419960', '', '', 'Wholesaler', 'df6e0d1c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(820, 29, 'Shehroz (Hydrabad)', '0313-3272269', '', '', 'Wholesaler', 'df6e0d90646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(821, 29, 'S S Book (Sharfabad)', '0322-2028267', '', '', 'Wholesaler', 'df6e0e02646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(822, 29, 'Generation Library', '0371- 0271865', '', '', 'Wholesaler', 'df6e0e72646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(823, 29, 'Hussnain All In One (Guleshan)', '0315-0667668', '', '', 'Wholesaler', 'df6e0ee2646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(824, 29, 'Kamran Photocopy', '', '', '', 'Wholesaler', 'df6e0f62646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(825, 29, 'Pretti Online', '0315-1081929', '', '', 'Wholesaler', 'df6e0fd3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(826, 29, 'Qadri Books (Sukkur)', '', '', '', 'Wholesaler', 'df6e1279646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(827, 29, 'Yameen (Supplier)', '0346-2871749', '', '', 'Wholesaler', 'df6e12ed646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(828, 29, 'Indus Book Shop', '', '', '', 'Wholesaler', 'df6e1358646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(829, 29, 'Azlan Stationary (Panjgoor)', '3175955000', '', '', 'Wholesaler', 'df6e13c9646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(830, 29, 'Al Fateh Book (Ashfaq)', '', '', '', 'Wholesaler', 'df6e143b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(831, 29, 'Fatima (Online)', '', '', '', 'Wholesaler', 'df6e14ad646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(832, 29, 'R J Mall All In One Dollar', '0310-9440054', '', '', 'Wholesaler', 'df6e151d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(833, 29, 'Bano Book Depot', '0312-9209911', '', '', 'Wholesaler', 'df6e158d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(834, 29, 'United Book (UrduBazar)', '', '', '', 'Wholesaler', 'df6e15ff646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(835, 29, 'Shah Stall (UrduBazar)', '', '', '', 'Wholesaler', 'df6e166e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(836, 29, 'Aslam Qureshi', '', '', '', 'Wholesaler', 'df6e16df646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(837, 29, 'Touseef Akram Niazi', '', '', '', 'Wholesaler', 'df6e174f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(838, 29, 'Noorani Book Larkana', '', '', '', 'Wholesaler', 'df6e17bf646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(839, 29, 'Zahid Brothers (PTI)', '', '', '', 'Wholesaler', 'df6e182f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(840, 29, 'Sasta.PK', '3137414111', '', '', 'Wholesaler', 'df6e189c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(841, 29, 'BBD Book', '', '', '', 'Wholesaler', 'df6e190d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(842, 29, 'HF Stationary', '0343-2073225', '', '', 'Wholesaler', 'df6e1981646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(843, 29, 'Liberty Books (Mirpur Khas)', '3033479467', '', '', 'Wholesaler', 'df6e19f4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(844, 29, 'Muhammad Hasseb', '0313-2401723', '', '', 'Wholesaler', 'df6e1a67646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(845, 29, 'Ruba Book (Landhi)', '0300-2233380', '', '', 'Wholesaler', 'df6e1ad7646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(846, 29, 'Burhani Books (Urdu Bazar)', '3368955162', '', '', 'Wholesaler', 'df6e1b49646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(847, 29, 'Cambridge Book (UrduBazar)', '', '', '', 'Wholesaler', 'df6e1bbc646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(848, 29, 'universal Book (Mirpur Khas)', '0315-9356355', '', '', 'Wholesaler', 'df6e1c2b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(849, 29, 'Iqra Book Center', '0300-2517034', '', '', 'Wholesaler', 'df6e1c9c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(850, 29, 'National Book Seller (Quetta)', '0300-3781887', '', '', 'Wholesaler', 'df6e1d0f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(851, 29, 'Aqeel Bhai (Lahore)', '0302-4380214', '', '', 'Wholesaler', 'df6e1d87646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(852, 29, 'Tent Wala Stationers', '0335-1188805', '', '', 'Wholesaler', 'df6e1df7646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(853, 29, 'Makkah Book Center', '', '', '', 'Wholesaler', 'df6e1e66646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(854, 29, 'Sagar Book (UrduBazar)', '', '', '', 'Wholesaler', 'df6e1ed6646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(855, 29, 'Jawed Brothers', '0300-3264632', '', '', 'Wholesaler', 'df6e1f48646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(856, 29, 'Liberty book store (Mirpurkhas)', '0331- 3718895', '', '', 'Wholesaler', 'df6e1fc4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(857, 29, 'Sajjad Bhai (Dadu)', '0313*0009428', '', '', 'Wholesaler', 'df6e203b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(858, 29, 'Mehran Book Centre (Nazimabad)', '3353254994', '', '', 'Wholesaler', 'df6e20ff646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(859, 29, 'Tahir Book Depo', '', '', '', 'Wholesaler', 'df6e21d3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(860, 29, 'Majid Riaz', '3005148640', '', '', 'Wholesaler', 'df6e228d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(861, 29, 'Sadiq Ali Book', '0315-9356355', '', '', 'Wholesaler', 'df6e236e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(862, 29, 'Zainab Memon', '0332-3662444', '', '', 'Wholesaler', 'df6e2450646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(863, 29, 'A And A ( Online )', '0300-2564139', '', '', 'Wholesaler', 'df6e2508646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(864, 29, 'Cadet College (Khairpur)', '0304-3236637', '', '', 'Wholesaler', 'df6e25ae646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(865, 29, 'Daughter Of St Paul', '0306-9637235', '', '', 'Wholesaler', 'df6e265d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(866, 29, 'Ahsan (Daraz)', '3402090983', '', '', 'Wholesaler', 'df6e2719646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(867, 29, 'Cambridge Book Mart (Haris)', '', '', '', 'Wholesaler', 'df6e27c5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(868, 29, 'Mehran Book (Shara e Faisal)', '3059303470', '', '', 'Wholesaler', 'df6e287d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(869, 29, 'Faizan Stall (UrduBazar)', '', '', 'urdu bazar', 'Wholesaler', 'df6e2997646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(871, 25, 'ASAD ALI BAJWA', '03460244440', '', 'ASAD ALI BAJWA S O SAEED AHMAD BAJWA', 'Retailer', 'df6e2a69646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(872, 31, 'misbah zAkir shah', '', 'misbahzakirshah@gmail.com', '', 'Retailer', 'df6e2b57646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(873, 29, 'Retail 1', '03242323', '', '', 'Retailer', 'df6e2c41646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL),
(874, 29, 'offline', '2723661684', 'gapsoedigital121@gmail.com', '418 Broadway\r\nSTE 6276', 'Retailer', NULL, NULL, 'pending', NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `customer_ledger`
--

CREATE TABLE `customer_ledger` (
  `customer_id` int(11) DEFAULT NULL,
  `debit` decimal(10,2) DEFAULT NULL,
  `credit` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer_memberships`
--

CREATE TABLE `customer_memberships` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `membership_id` int(11) NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','expired','cancelled') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `customer_memberships`
--

INSERT INTO `customer_memberships` (`id`, `client_id`, `customer_id`, `membership_id`, `start_date`, `end_date`, `status`, `created_at`) VALUES
(5, 20, 18, 5, '2026-05-07', '2026-06-05', 'active', '2026-05-07 07:21:53');

-- --------------------------------------------------------

--
-- Table structure for table `delivery_drivers`
--

CREATE TABLE `delivery_drivers` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `driver_code` varchar(30) NOT NULL,
  `driver_name` varchar(150) NOT NULL,
  `phone` varchar(30) NOT NULL,
  `cnic` varchar(30) DEFAULT NULL,
  `vehicle` varchar(150) NOT NULL,
  `license_no` varchar(80) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `status` enum('Available','On Delivery','Break','Inactive') DEFAULT 'Available',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `delivery_drivers`
--

INSERT INTO `delivery_drivers` (`id`, `client_id`, `branch_id`, `driver_code`, `driver_name`, `phone`, `cnic`, `vehicle`, `license_no`, `address`, `status`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 20, 7, 'DRV-001', 'test Order', '03134054522', '98753445678798', 'bike', '39878', 'City Test', 'Available', 130, '2026-05-08 12:06:12', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `delivery_order_assignments`
--

CREATE TABLE `delivery_order_assignments` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `sale_id` int(11) NOT NULL,
  `route_id` int(11) DEFAULT NULL,
  `driver_id` int(11) NOT NULL,
  `vehicle` varchar(150) DEFAULT NULL,
  `delivery_date` date NOT NULL,
  `delivery_time_slot` varchar(80) DEFAULT NULL,
  `status` enum('assigned','ready_for_dispatch','out_for_delivery','delivered','partial_delivered','customer_not_available','address_issue','payment_pending','failed','returned','cancelled') DEFAULT 'assigned',
  `assigned_by` int(11) DEFAULT NULL,
  `assigned_at` timestamp NULL DEFAULT current_timestamp(),
  `delivered_at` timestamp NULL DEFAULT NULL,
  `failed_reason` varchar(255) DEFAULT NULL,
  `collection_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(50) DEFAULT 'Cash',
  `settlement_id` int(11) DEFAULT NULL,
  `settlement_status` enum('unsettled','partial','settled') NOT NULL DEFAULT 'unsettled',
  `settled_at` timestamp NULL DEFAULT NULL,
  `settlement_notes` text DEFAULT NULL,
  `return_qty` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `desktop_sync_state`
--

CREATE TABLE `desktop_sync_state` (
  `id` bigint(20) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `device_uuid` varchar(100) NOT NULL,
  `table_name` varchar(100) NOT NULL,
  `last_pull_at` datetime DEFAULT NULL,
  `last_push_at` datetime DEFAULT NULL,
  `last_server_cursor` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `devices`
--

CREATE TABLE `devices` (
  `id` int(11) NOT NULL,
  `client_id` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `device_uuid` varchar(100) DEFAULT NULL,
  `device_name` varchar(150) DEFAULT NULL,
  `last_sync_at` datetime DEFAULT NULL,
  `device_key` varchar(255) DEFAULT NULL,
  `status` enum('active','blocked') DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `last_pull_at` datetime DEFAULT NULL,
  `last_push_at` datetime DEFAULT NULL,
  `app_version` varchar(50) DEFAULT NULL,
  `allowed_offline` tinyint(1) NOT NULL DEFAULT 1,
  `registered_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `devices`
--

INSERT INTO `devices` (`id`, `client_id`, `branch_id`, `device_uuid`, `device_name`, `last_sync_at`, `device_key`, `status`, `created_at`, `last_pull_at`, `last_push_at`, `app_version`, `allowed_offline`, `registered_at`) VALUES
(15, NULL, NULL, NULL, NULL, NULL, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 'active', '2026-06-10 02:30:12', NULL, NULL, NULL, 1, NULL),
(16, NULL, NULL, NULL, NULL, NULL, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', 'active', '2026-06-10 02:30:12', NULL, NULL, NULL, 1, NULL),
(17, NULL, NULL, NULL, NULL, NULL, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.4 Safari/605.1.15', 'active', '2026-06-10 02:30:12', NULL, NULL, NULL, 1, NULL),
(18, NULL, NULL, NULL, NULL, NULL, 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', 'active', '2026-06-10 02:30:12', NULL, NULL, NULL, 1, NULL),
(19, NULL, NULL, NULL, NULL, NULL, 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 'active', '2026-06-10 02:30:12', NULL, NULL, NULL, 1, NULL),
(20, NULL, NULL, NULL, NULL, NULL, 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1', 'active', '2026-06-10 02:30:12', NULL, NULL, NULL, 1, NULL),
(21, NULL, NULL, NULL, NULL, NULL, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', 'active', '2026-06-10 02:30:12', NULL, NULL, NULL, 1, NULL),
(22, NULL, NULL, NULL, NULL, NULL, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 'active', '2026-06-10 02:30:12', NULL, NULL, NULL, 1, NULL),
(23, NULL, NULL, NULL, NULL, NULL, 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36', 'active', '2026-06-10 02:30:12', NULL, NULL, NULL, 1, NULL),
(24, NULL, NULL, NULL, NULL, NULL, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 OPR/131.0.0.0', 'active', '2026-06-10 02:30:12', NULL, NULL, NULL, 1, NULL),
(25, NULL, NULL, NULL, NULL, NULL, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0.0.0 Safari/537.36', 'active', '2026-06-10 02:30:12', NULL, NULL, NULL, 1, NULL),
(26, NULL, NULL, NULL, NULL, NULL, 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36', 'active', '2026-06-10 02:30:12', NULL, NULL, NULL, 1, NULL),
(27, NULL, NULL, NULL, NULL, NULL, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', 'active', '2026-06-10 02:30:12', NULL, NULL, NULL, 1, NULL),
(28, NULL, NULL, NULL, NULL, NULL, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Safari/605.1.15', 'active', '2026-06-10 02:30:12', NULL, NULL, NULL, 1, NULL),
(29, NULL, NULL, NULL, NULL, NULL, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', 'active', '2026-06-10 02:30:12', NULL, NULL, NULL, 1, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `distribution_collection_logs`
--

CREATE TABLE `distribution_collection_logs` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `settlement_id` int(11) DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `distribution_collection_settlements`
--

CREATE TABLE `distribution_collection_settlements` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `settlement_no` varchar(60) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `settlement_date` date NOT NULL,
  `total_orders` int(11) NOT NULL DEFAULT 0,
  `total_collectable` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_received` decimal(12,2) NOT NULL DEFAULT 0.00,
  `cash_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `online_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `adjustment_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `shortage_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `extra_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `settlement_status` enum('pending','partial','settled','over','cancelled') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `settled_by` int(11) DEFAULT NULL,
  `settled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `distribution_collection_settlement_items`
--

CREATE TABLE `distribution_collection_settlement_items` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `settlement_id` int(11) NOT NULL,
  `assignment_id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `route_id` int(11) DEFAULT NULL,
  `invoice_no` varchar(80) DEFAULT NULL,
  `order_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `collected_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(80) DEFAULT 'Cash',
  `delivery_date` date DEFAULT NULL,
  `item_status` enum('settled','cancelled') NOT NULL DEFAULT 'settled',
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `distribution_delivery_updates`
--

CREATE TABLE `distribution_delivery_updates` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `sale_id` int(11) NOT NULL,
  `assignment_id` int(11) DEFAULT NULL,
  `dispatch_id` int(11) DEFAULT NULL,
  `dispatch_item_id` int(11) DEFAULT NULL,
  `route_id` int(11) DEFAULT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `previous_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) NOT NULL,
  `received_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(50) DEFAULT NULL,
  `return_qty` decimal(12,2) NOT NULL DEFAULT 0.00,
  `reason` varchar(255) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `distribution_dispatches`
--

CREATE TABLE `distribution_dispatches` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `dispatch_no` varchar(50) NOT NULL,
  `route_id` int(11) DEFAULT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `vehicle` varchar(150) DEFAULT NULL,
  `dispatch_date` date NOT NULL,
  `dispatch_time` time DEFAULT NULL,
  `total_orders` int(11) NOT NULL DEFAULT 0,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_collection` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','ready','dispatched','in_progress','completed','cancelled') NOT NULL DEFAULT 'ready',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `distribution_dispatch_items`
--

CREATE TABLE `distribution_dispatch_items` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `dispatch_id` int(11) NOT NULL,
  `assignment_id` int(11) DEFAULT NULL,
  `sale_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `route_id` int(11) DEFAULT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `order_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `collection_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `return_qty` decimal(12,2) NOT NULL DEFAULT 0.00,
  `item_status` enum('ready','dispatched','out_for_delivery','delivered','partial_delivered','customer_not_available','address_issue','payment_pending','failed','returned','cancelled') NOT NULL DEFAULT 'ready',
  `delivered_at` timestamp NULL DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `distribution_routes`
--

CREATE TABLE `distribution_routes` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `route_code` varchar(50) NOT NULL,
  `route_name` varchar(150) NOT NULL,
  `area` varchar(150) NOT NULL,
  `city` varchar(100) DEFAULT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `driver_name` varchar(150) DEFAULT NULL,
  `vehicle_name` varchar(150) DEFAULT NULL,
  `delivery_days` varchar(100) DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `status` enum('Active','Inactive','On Hold') NOT NULL DEFAULT 'Active',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `distribution_routes`
--

INSERT INTO `distribution_routes` (`id`, `client_id`, `branch_id`, `route_code`, `route_name`, `area`, `city`, `driver_id`, `driver_name`, `vehicle_name`, `delivery_days`, `start_time`, `end_time`, `status`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 22, 0, 'DR-0001', 'Rajanpur', 'Saddar Bazar', 'Rajanpur', NULL, '', 'Bike', 'Sun', '08:00:00', '18:00:00', 'Active', '', 147, '2026-05-11 15:02:51', NULL),
(2, 22, 0, 'DR-0002', 'Zahirpeer', 'Main Bazar', 'Zahirpeer', NULL, '', 'Bike', 'Mon', '08:00:00', '18:00:00', 'Active', '', 147, '2026-05-11 15:05:39', NULL),
(3, 22, 0, 'DR-0003', 'Kot Mithan', 'Main Market', 'Kot Mithan', NULL, '', 'Bike', 'Sun', '08:00:00', '12:00:00', 'Active', '', 147, '2026-05-11 15:06:21', NULL),
(4, 22, 0, 'DR-0004', 'Nawakot', 'Main Bazar', 'Nawakot', NULL, '', 'Bike', 'Tue', '08:00:00', '12:00:00', 'Active', '', 147, '2026-05-11 15:07:04', NULL),
(5, 22, 0, 'DR-0005', 'Feroza', 'Bazar', 'Feroza', NULL, '', 'Bike', 'Tue', '08:00:00', '12:00:00', 'Active', '', 147, '2026-05-11 15:07:26', NULL),
(6, 22, 0, 'DR-0006', 'Sajha', 'Bazar', 'Sajha', NULL, '', 'Bike', 'Wed', '08:00:00', '12:00:00', 'Active', '', 147, '2026-05-11 15:07:49', NULL),
(7, 22, 0, 'DR-0007', 'Kot Sambha', 'Main Market', 'Kot Sambha', NULL, '', 'Bike', 'Wed', '08:00:00', '12:00:00', 'Active', '', 147, '2026-05-11 15:08:17', NULL),
(8, 22, 0, 'DR-0008', 'Khanpur', 'All City', 'Khanpur', NULL, '', 'Bike', 'Thu', '08:00:00', '12:00:00', 'Active', '', 147, '2026-05-11 15:08:49', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `distribution_route_customers`
--

CREATE TABLE `distribution_route_customers` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `route_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `sequence_no` int(11) NOT NULL DEFAULT 0,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `distribution_route_customers`
--

INSERT INTO `distribution_route_customers` (`id`, `client_id`, `branch_id`, `route_id`, `customer_id`, `sequence_no`, `status`, `created_at`, `updated_at`) VALUES
(1, 22, 0, 8, 283, 1, 'Active', '2026-05-11 16:15:59', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `distribution_route_deliveries`
--

CREATE TABLE `distribution_route_deliveries` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `route_id` int(11) NOT NULL,
  `delivery_date` date NOT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `driver_name` varchar(150) DEFAULT NULL,
  `vehicle_name` varchar(150) DEFAULT NULL,
  `total_orders` int(11) NOT NULL DEFAULT 0,
  `pending_orders` int(11) NOT NULL DEFAULT 0,
  `delivered_orders` int(11) NOT NULL DEFAULT 0,
  `failed_orders` int(11) NOT NULL DEFAULT 0,
  `returned_orders` int(11) NOT NULL DEFAULT 0,
  `total_collection` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('Pending','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `distribution_route_delivery_items`
--

CREATE TABLE `distribution_route_delivery_items` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `delivery_id` int(11) NOT NULL,
  `route_id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `sequence_no` int(11) NOT NULL DEFAULT 0,
  `order_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_collected` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(50) DEFAULT 'Cash',
  `delivery_status` enum('Pending','Out For Delivery','Delivered','Partial','Failed','Returned') NOT NULL DEFAULT 'Pending',
  `remarks` text DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `empty_bottle_returns`
--

CREATE TABLE `empty_bottle_returns` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `sale_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `item_id` int(11) DEFAULT NULL,
  `bottle_type` varchar(150) DEFAULT '19 Liter Bottle',
  `delivered_qty` int(11) NOT NULL DEFAULT 0,
  `returned_qty` int(11) NOT NULL DEFAULT 0,
  `damaged_qty` int(11) NOT NULL DEFAULT 0,
  `lost_qty` int(11) NOT NULL DEFAULT 0,
  `deposit_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `deposit_adjustment` decimal(10,2) NOT NULL DEFAULT 0.00,
  `driver_name` varchar(150) DEFAULT NULL,
  `route_name` varchar(150) DEFAULT NULL,
  `return_date` date NOT NULL,
  `status` enum('pending','partial','completed') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `empty_bottle_returns`
--

INSERT INTO `empty_bottle_returns` (`id`, `client_id`, `branch_id`, `sale_id`, `customer_id`, `item_id`, `bottle_type`, `delivered_qty`, `returned_qty`, `damaged_qty`, `lost_qty`, `deposit_amount`, `deposit_adjustment`, `driver_name`, `route_name`, `return_date`, `status`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES
(2, 20, 0, 18, NULL, NULL, '19 Liter Bottle', 0, 1, 0, 0, 377.00, 566.00, '', '', '2026-05-07', 'completed', '', 130, '2026-05-07 11:02:00', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `description` varchar(255) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'Approved',
  `expense_date` date DEFAULT NULL,
  `expense_code` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `expenses`
--

INSERT INTO `expenses` (`id`, `client_id`, `branch_id`, `amount`, `description`, `category`, `payment_method`, `status`, `expense_date`, `expense_code`, `created_at`, `uuid`, `device_id`, `sync_status`, `last_synced_at`, `deleted_at`, `created_at_local`, `updated_at_local`) VALUES
(10, 20, 7, 500.00, 'Utilities', 'Utilities', 'Cash', 'Approved', '2026-05-07', 'EXP-000001', '2026-05-07 07:35:33', 'df664569646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `fbr_invoices`
--

CREATE TABLE `fbr_invoices` (
  `sale_id` int(11) DEFAULT NULL,
  `irn` varchar(255) DEFAULT NULL,
  `qr` text DEFAULT NULL,
  `status` enum('success','failed') DEFAULT NULL,
  `response` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fbr_queue`
--

CREATE TABLE `fbr_queue` (
  `sale_id` int(11) DEFAULT NULL,
  `retry_count` int(11) DEFAULT NULL,
  `status` enum('pending','success','failed') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `global_settings`
--

CREATE TABLE `global_settings` (
  `id` int(11) NOT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `company_logo` varchar(255) DEFAULT NULL,
  `head_office_address` text DEFAULT NULL,
  `web_url` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `tax_number` varchar(50) DEFAULT NULL,
  `default_currency` varchar(20) DEFAULT 'PKR',
  `timezone` varchar(50) DEFAULT 'Asia/Karachi',
  `enable_inventory` tinyint(1) DEFAULT 1,
  `enable_wholesale` tinyint(1) DEFAULT 0,
  `enable_fbr` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `global_settings`
--

INSERT INTO `global_settings` (`id`, `company_name`, `company_logo`, `head_office_address`, `web_url`, `email`, `phone`, `tax_number`, `default_currency`, `timezone`, `enable_inventory`, `enable_wholesale`, `enable_fbr`, `created_at`, `updated_at`) VALUES
(1, 'Gapsoe Digital ', '', 'Tariq Road', 'gapsoedigital.com', 'info@gapsoeDigital.com', '123456789', '', 'PKR', 'Asia/Karachi', 0, 0, 0, '2026-02-21 22:25:40', '2026-02-21 22:25:40');

-- --------------------------------------------------------

--
-- Table structure for table `industries`
--

CREATE TABLE `industries` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `industries`
--

INSERT INTO `industries` (`id`, `name`, `created_at`) VALUES
(10, 'Services', '2026-05-06 13:26:22'),
(11, 'Retailer', '2026-05-06 13:28:51'),
(12, 'others', '2026-05-12 12:17:32'),
(13, 'Restaurant', '2026-06-03 10:56:35');

-- --------------------------------------------------------

--
-- Table structure for table `integrations`
--

CREATE TABLE `integrations` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `integration_type` varchar(50) NOT NULL,
  `gateway_name` varchar(150) DEFAULT NULL,
  `api_key` varchar(255) DEFAULT NULL,
  `api_secret` varchar(255) DEFAULT NULL,
  `api_token` varchar(255) DEFAULT NULL,
  `status` tinyint(1) DEFAULT 0,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `integrations`
--

INSERT INTO `integrations` (`id`, `client_id`, `integration_type`, `gateway_name`, `api_key`, `api_secret`, `api_token`, `status`, `is_default`, `created_at`, `updated_at`) VALUES
(11, 29, 'JazzCash', 'JazzCash', '123456', 'cs_bb3b14c1f9aa7ea9336116815d813fdc13ea5554', 'SYED ASAD ULLAH SHAH', 0, 0, '2026-05-18 13:15:51', '2026-05-18 13:16:53');

-- --------------------------------------------------------

--
-- Table structure for table `integration_logs`
--

CREATE TABLE `integration_logs` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `integration_type` varchar(50) NOT NULL,
  `action` varchar(100) DEFAULT NULL,
  `request` text DEFAULT NULL,
  `response` text DEFAULT NULL,
  `status` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `subscription_id` int(11) NOT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `items`
--

CREATE TABLE `items` (
  `id` int(11) NOT NULL,
  `client_id` int(11) DEFAULT NULL,
  `branch_id` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `name` varchar(150) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `type` enum('product','service','menu') DEFAULT NULL,
  `item_nature` enum('product','service','raw_material','packing_material','semi_finished','finished_good','scrap','consumable','tool') NOT NULL DEFAULT 'product',
  `unit_name` varchar(50) DEFAULT NULL,
  `has_variants` tinyint(1) DEFAULT NULL,
  `variant_details` varchar(255) DEFAULT NULL,
  `has_expiry` tinyint(1) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `wholesaler_price_1` decimal(12,2) NOT NULL DEFAULT 0.00,
  `wholesaler_price_2` decimal(12,2) NOT NULL DEFAULT 0.00,
  `wholesaler_price_3` decimal(12,2) NOT NULL DEFAULT 0.00,
  `cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `low_stock_alert` int(11) NOT NULL DEFAULT 10,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `image` varchar(255) DEFAULT NULL,
  `is_raw_item` tinyint(1) DEFAULT 0,
  `track_inventory` tinyint(1) NOT NULL DEFAULT 1,
  `stock_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `category` varchar(255) DEFAULT NULL,
  `kitchen_section` varchar(100) DEFAULT NULL,
  `prep_time_minutes` int(11) NOT NULL DEFAULT 0,
  `is_restaurant_item` tinyint(1) NOT NULL DEFAULT 0,
  `supplier_id` int(11) DEFAULT NULL,
  `reorder_flag` tinyint(1) NOT NULL DEFAULT 0,
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(64) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'pending',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `items`
--

INSERT INTO `items` (`id`, `client_id`, `branch_id`, `name`, `description`, `sku`, `type`, `item_nature`, `unit_name`, `has_variants`, `variant_details`, `has_expiry`, `expiry_date`, `price`, `wholesaler_price_1`, `wholesaler_price_2`, `wholesaler_price_3`, `cost`, `low_stock_alert`, `status`, `image`, `is_raw_item`, `track_inventory`, `stock_qty`, `category`, `kitchen_section`, `prep_time_minutes`, `is_restaurant_item`, `supplier_id`, `reorder_flag`, `uuid`, `device_id`, `sync_status`, `last_synced_at`, `deleted_at`, `created_at`, `updated_at`, `created_at_local`, `updated_at_local`) VALUES
(76, 20, 0, 'Test Products', NULL, 'TEST-SKU-001', 'product', 'product', NULL, 0, NULL, 0, NULL, 20.00, 0.00, 0.00, 0.00, 0.00, 10, 'active', 'item_1778076440_4699.jpg', 0, 1, 1000.00, NULL, NULL, 0, 0, NULL, 0, 'df6f9d14646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(77, 24, 0, 'misbahzakir', NULL, '009', 'service', 'product', NULL, 0, NULL, 0, NULL, 0.00, 0.00, 0.00, 0.00, 0.00, 10, 'active', 'item_1778323306_3552.jpeg', 0, 1, 12.00, NULL, NULL, 0, 0, NULL, 0, 'df6fb4e5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(84, 27, 0, 'water', NULL, '004', 'product', 'product', NULL, 0, NULL, 0, NULL, 1000.00, 0.00, 0.00, 0.00, 0.00, 10, 'active', '', 0, 1, 8.96, NULL, NULL, 0, 0, NULL, 0, 'df6fb648646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(85, 27, 0, 'water galon', NULL, '009', 'product', 'product', NULL, 0, NULL, 0, NULL, 100.00, 0.00, 0.00, 0.00, 60.00, 10, 'active', '', 0, 1, 43.00, NULL, NULL, 0, 0, NULL, 0, 'df6fb759646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(86, 27, 0, 'kuch', NULL, '', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 200.00, 10, 'active', '', 0, 1, 198.00, NULL, NULL, 0, 0, NULL, 0, 'df6fb855646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(87, 22, 0, 'make up', NULL, '009', 'product', 'product', NULL, 0, NULL, 0, NULL, 1000.00, 0.00, 0.00, 0.00, 500.00, 10, 'active', '1778508649_1429.jpg', 0, 1, 10.00, NULL, NULL, 0, 0, NULL, 0, 'df6fb94c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(88, 29, 1, 'King Of Gluttony', '', 'IMP-977', 'product', 'product', NULL, 0, NULL, 0, NULL, 550.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fba3f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(89, 29, 1, 'I Could Give The Moon', '', 'IMP-976', 'product', 'product', NULL, 0, NULL, 0, NULL, 550.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fbb3b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(90, 29, 1, 'Release Me (Mafi)', '', 'IMP-975', 'product', 'product', NULL, 0, NULL, 0, NULL, 550.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fbc18646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(91, 29, 1, 'Printed Book (Mix)', '', 'IMP-974', 'product', 'product', NULL, 0, NULL, 0, NULL, 1500.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fbcef646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(92, 29, 1, 'Richest Man In Babylone', '', 'IMP-973', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fbdc7646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(93, 29, 1, 'Rich Dad Poor Dad (A+)', '', 'IMP-972', 'product', 'product', NULL, 0, NULL, 0, NULL, 650.00, 0.00, 0.00, 0.00, 250.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fbe9d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(94, 29, 1, 'Who is Zaynab?', '', 'IMP-971', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fbf70646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(95, 29, 1, 'Who Will Cry When You Die', '', 'IMP-970', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 110.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fc041646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(96, 29, 1, 'Marvellous Stories From The Life', '', 'IMP-969', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fc117646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(97, 29, 1, '100 M Lost Chapter', '', 'IMP-968', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fc1c1646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(98, 29, 1, 'Who Is Hussain', '', 'IMP-967', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fc259646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(99, 29, 1, 'Light Out (Navessa)', '', 'IMP-966', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 220.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fc309646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(100, 29, 1, 'Caught Up (Navessa)', '', 'IMP-965', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 220.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fc3a1646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(101, 29, 1, 'Tawakkul', '', 'IMP-964', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fc435646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(102, 29, 1, 'Together Tea', '', 'IMP-963', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fc4ca646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(103, 29, 1, 'The Strawberry Patch Pancake House', '', 'IMP-962', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fc564646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(104, 29, 1, 'Lord Of Mysteries', '', 'IMP-961', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fc5fe646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(105, 29, 1, 'A Dua Away', '', 'IMP-960', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fc692646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(106, 29, 1, 'The Love Hypothesis', '', 'IMP-959', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fc727646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(107, 29, 1, 'One Hundred Years of Solitude', '', 'IMP-958', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fc7bc646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(108, 29, 1, 'The Cinnamon Bun Book Store', '', 'IMP-957', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fc857646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(109, 29, 1, 'In The Line Of Fire', '', 'IMP-956', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fc8eb646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(110, 29, 1, 'The Ginger Bread Bakery', '', 'IMP-955', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fc980646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(111, 29, 1, 'Living Of The Q...', '', 'IMP-954', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fca15646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(112, 29, 1, 'Bell Jar', '', 'IMP-953', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fcaaa646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(113, 29, 1, 'The Art of Persuasion', '', 'IMP-952', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fcb3f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(114, 29, 1, 'Trading Technicals Book', '', 'IMP-951', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fcc06646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(115, 29, 1, 'Trading Chart patterns', '', 'IMP-950', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fcc9e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(116, 29, 1, 'Simple Trading Book', '', 'IMP-949', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fcd34646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(117, 29, 1, 'How To Day A Trade For A Living', '', 'IMP-948', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fcdc7646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(118, 29, 1, '500 Activity', '', 'IMP-947', 'product', 'product', NULL, 0, NULL, 0, NULL, 700.00, 0.00, 0.00, 0.00, 400.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fce5f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(119, 29, 1, 'The Death Of Ivan Ilyich', '', 'IMP-946', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fcef4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(120, 29, 1, 'The Landlady (Fyodor)', '', 'IMP-945', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fcf8a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(121, 29, 1, 'Uncles Dream (Fyodor)', '', 'IMP-944', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fd01f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(122, 29, 1, 'woman Down', '', 'IMP-943', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 170.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fd0b4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(123, 29, 1, 'The Pumpkin Spice Cafe', '', 'IMP-942', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fd14b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(124, 29, 1, 'War Horse', '', 'IMP-941', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fd28e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(125, 29, 1, 'Norwegian Wood', '', 'IMP-940', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fd344646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(126, 29, 1, 'The Crash (Freida)', '', 'IMP-939', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fd3f3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(127, 29, 1, 'Hexed', '', 'IMP-938', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fd48b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(128, 29, 1, 'Want To know A Secret (Freida)', '', 'IMP-937', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fd51f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(129, 29, 1, 'The Surrogate Mother (Freida)', '', 'IMP-936', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fd5b8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(130, 29, 1, 'The Wife upstairs (Freida)', '', 'IMP-935', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fd652646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(131, 29, 1, 'My Fault', '', 'IMP-934', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fd6e9646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(132, 29, 1, 'Dont Sweat the Small Stuff About Money', '', 'IMP-933', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fd77e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(133, 29, 1, 'Who Stole My Pension', '', 'IMP-932', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fd815646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(134, 29, 1, '150 Stories Of Usman Ibn Affan', '', 'IMP-931', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fd8a8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(135, 29, 1, '150 Stories Of Umer Ibn Al Khattab', '', 'IMP-930', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fd945646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(136, 29, 1, '150 Stories of Abu Bakr Al Siddiq', '', 'IMP-929', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fd9e0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(137, 29, 1, '150 Stories Of Ali Ibn Talib', '', 'IMP-928', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fda76646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(138, 29, 1, 'Prophet Stories (HardCover)', '', 'IMP-927', 'product', 'product', NULL, 0, NULL, 0, NULL, 1200.00, 0.00, 0.00, 0.00, 400.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fdd31646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(139, 29, 1, 'Prophet Stories (SoftCover)', '', 'IMP-926', 'product', 'product', NULL, 0, NULL, 0, NULL, 1500.00, 0.00, 0.00, 0.00, 800.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fdde0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(140, 29, 1, 'Thief Of night (Holly)', '', 'IMP-925', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fde78646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(141, 29, 1, 'Book Of Night (Holly)', '', 'IMP-924', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fdf0d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(142, 29, 1, 'how To Stop Overthinking Forever', '', 'IMP-923', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fdfa2646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(143, 29, 1, 'The Disciplined Trader', '', 'IMP-922', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fe03c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(144, 29, 1, 'The Things You Can See Only When You Slow Down', '', 'IMP-921', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fe0d2646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(145, 29, 1, 'Self Made Millionaires', '', 'IMP-920', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fe177646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(146, 29, 1, 'Cursive Writing Mix', '', 'IMP-919', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 80.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fe217646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(147, 29, 1, 'Heart Lamp', '', 'IMP-918', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fe2b0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(148, 29, 1, 'A Short History Of World', '', 'IMP-917', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fe348646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(149, 29, 1, 'Lord Of Flies', '', 'IMP-916', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fe3de646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(150, 29, 1, 'His And Hers', '', 'IMP-915', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fe473646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(151, 29, 1, 'The Prince', '', 'IMP-914', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fe509646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(152, 29, 1, 'Kids Story Book', '', 'IMP-913', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fe59f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(153, 29, 1, 'Word Search Set', '', 'IMP-912', 'product', 'product', NULL, 0, NULL, 0, NULL, 800.00, 0.00, 0.00, 0.00, 400.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fe85f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(154, 29, 1, 'Library Box', '', 'IMP-911', 'product', 'product', NULL, 0, NULL, 0, NULL, 1300.00, 0.00, 0.00, 0.00, 700.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fe8f9646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(155, 29, 1, '4 In 1 Writing Book', '', 'IMP-910', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fe990646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(156, 29, 1, 'The Giraffe and the Pelly and Me', '', 'IMP-909', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fea27646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(157, 29, 1, 'Tell Me Softly', '', 'IMP-907', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6feac4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(158, 29, 1, 'Alchemy Of Secrets', '', 'IMP-906', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6feba5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(159, 29, 1, 'Archers Voice', '', 'IMP-905', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fee5f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(160, 29, 1, 'Heartless (Silver)', '', 'IMP-904', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 170.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6feef9646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(161, 29, 1, 'House Without Windows', '', 'IMP-903', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6fef8e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(162, 29, 1, 'Percy Jackson Chalice Of God', '', 'IMP-902', 'product', 'product', NULL, 0, NULL, 0, NULL, 550.00, 0.00, 0.00, 0.00, 250.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6ff024646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(163, 29, 1, 'Percy Jackson Sea Of Monster', '', 'IMP-901', 'product', 'product', NULL, 0, NULL, 0, NULL, 550.00, 0.00, 0.00, 0.00, 250.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6ff328646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(164, 29, 1, 'Fajar And Noor', '', 'IMP-900', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 110.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6ff3c2646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(165, 29, 1, 'How to Survive Your Murder', '', 'IMP-899', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6ff459646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(166, 29, 1, 'Percy Jackson The Battle of the Labyrinth', '', 'IMP-898', 'product', 'product', NULL, 0, NULL, 0, NULL, 550.00, 0.00, 0.00, 0.00, 250.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6ff4ee646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(167, 29, 1, 'Manipulation', '', 'IMP-897', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df6ff7a5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(168, 29, 1, 'Twisted Kingdom', '', 'IMP-896', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 170.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df701a74646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(169, 29, 1, 'Hyper Focus', '', 'IMP-895', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df701bd1646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(170, 29, 1, 'Horrid Hennery 10-20', '', 'IMP-894', 'product', 'product', NULL, 0, NULL, 0, NULL, 2500.00, 0.00, 0.00, 0.00, 1600.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df701f5f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(171, 29, 1, 'Horrid Hennery 1-10', '', 'IMP-893', 'product', 'product', NULL, 0, NULL, 0, NULL, 2500.00, 0.00, 0.00, 0.00, 1600.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df702050646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(172, 29, 1, 'Her Soul For Revenge', '', 'IMP-892', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df702344646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(173, 29, 1, 'Percy Jackson Lightining Thief', '', 'IMP-891', 'product', 'product', NULL, 0, NULL, 0, NULL, 550.00, 0.00, 0.00, 0.00, 250.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df70241c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(174, 29, 1, 'THe Art Of Creactive Thinking', '', 'IMP-890', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7027cc646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(175, 29, 1, 'Protecting Whats Mine', '', 'IMP-889', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df702b25646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(176, 29, 1, 'One True Love', '', 'IMP-888', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df702e8b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(177, 29, 1, 'Your Brain On Porn', '', 'IMP-887', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df703489646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(178, 29, 1, 'Pride And Purjudice', '', 'IMP-886', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 250.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df70493f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(179, 29, 1, 'Wimpy Kids Mix', '', 'IMP-885', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 120.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df704a51646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(180, 29, 1, 'Inmate (Freida)', '', 'IMP-884', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df704b39646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(181, 29, 1, 'The Intruder (Freida)', '', 'IMP-883', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df704bf5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(182, 29, 1, 'Surah Kahf', '', 'IMP-882', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df704cbc646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(183, 29, 1, 'Ielts General Mini 11-19', '', 'IMP-881', 'product', 'product', NULL, 0, NULL, 0, NULL, 1500.00, 0.00, 0.00, 0.00, 850.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df704d68646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(184, 29, 1, 'Ielts Grammer', '', 'IMP-880', 'product', 'product', NULL, 0, NULL, 0, NULL, 500.00, 0.00, 0.00, 0.00, 275.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df704e0c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(185, 29, 1, 'Ielts Vocabulary', '', 'IMP-879', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 250.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df704eaf646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(186, 29, 1, 'Ielts General Full 1-19', '', 'IMP-878', 'product', 'product', NULL, 0, NULL, 0, NULL, 2500.00, 0.00, 0.00, 0.00, 1700.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df704f4f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(187, 29, 1, 'Ielts Academic Mini 11-20', '', 'IMP-877', 'product', 'product', NULL, 0, NULL, 0, NULL, 1500.00, 0.00, 0.00, 0.00, 800.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df704ff1646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(188, 29, 1, 'Ielts Academic Full 1-20', '', 'IMP-876', 'product', 'product', NULL, 0, NULL, 0, NULL, 2500.00, 0.00, 0.00, 0.00, 1600.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df705092646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(189, 29, 1, 'Official Cambridge Guide To Ielts', '', 'IMP-875', 'product', 'product', NULL, 0, NULL, 0, NULL, 800.00, 0.00, 0.00, 0.00, 350.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df705134646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(190, 29, 1, 'The Lean Startup', '', 'IMP-874', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7051f8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(191, 29, 1, 'God Of Fury', '', 'IMP-873', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df70529e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(192, 29, 1, '5 Am Club', '', 'IMP-872', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df705343646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(193, 29, 1, 'One By One (Freida)', '', 'IMP-871', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7053e4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(194, 29, 1, 'Widows Husbands Secret Lie (Freida)', '', 'IMP-870', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df705485646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(195, 29, 1, 'The Glass Girl (Kathlen)', '', 'IMP-869', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df70552c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(196, 29, 1, 'It End With US', '', 'IMP-868', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 110.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7055cc646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(197, 29, 1, 'It Start With US', '', 'IMP-867', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 110.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df70566a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(198, 29, 1, 'Do Not Disturb (Freida)', '', 'IMP-866', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df705708646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(199, 29, 1, 'Lost To The World', '', 'IMP-865', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7057c3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(200, 29, 1, 'Urdu Mix Novels', '', 'IMP-864', 'product', 'product', NULL, 0, NULL, 0, NULL, 2000.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df70586b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(201, 29, 1, 'English Mix Novels 2', '', 'IMP-863', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df70590a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(202, 29, 1, 'English Mix Novels 1', '', 'IMP-862', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7059aa646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(203, 29, 1, 'James And The Giant Peach', '', 'IMP-861', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df705a4a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(204, 29, 1, 'Reclaim Your Heart', '', 'IMP-860', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df705aec646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(205, 29, 1, '1984', '', 'IMP-859', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df705b94646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(206, 29, 1, 'Atomic Habits', 'Novel Book', 'IMP-852', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 105.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df705c8d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(207, 29, 1, 'How to Analyze People with Dark Psychology', 'Novel Book', 'IMP-851', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df705db8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(208, 29, 1, 'Attitude Is Everything', 'Novel Book', 'IMP-850', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 80.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df705ea5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(209, 29, 1, 'Rich Dad Poor Dad', 'Novel Book', 'IMP-849', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 80.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df705f8f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(210, 29, 1, 'Ikigai', 'Novel Book', 'IMP-848', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 80.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df706078646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(211, 29, 1, 'Manipulation Techniques', 'Novel Book', 'IMP-847', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df706169646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(212, 29, 1, 'Think and Grow Rich', 'Novel Book', 'IMP-846', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 110.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df706335646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(213, 29, 1, 'The Forty Rules of Love', 'Novel Book', 'IMP-845', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 110.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df706411646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(214, 29, 1, 'The Alchemist', 'Novel Book', 'IMP-844', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 105.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7064e6646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(215, 29, 1, 'Rumi Bridge to the Soul', 'Novel Book', 'IMP-843', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df70681d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(216, 29, 1, 'Reminders of Him', 'Novel Book', 'IMP-842', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df706922646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(217, 29, 1, 'Ugly Love', 'Novel Book', 'IMP-841', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7069fb646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(218, 29, 1, 'The Kite Runner (Khalid)', 'Novel Book', 'IMP-840', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 110.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df706ace646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(219, 29, 1, 'Pakistan at the Crossroads', 'Novel Book', 'IMP-839', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df706ba6646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(220, 29, 1, 'Verity', 'Novel Book', 'IMP-838', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df706e7f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(221, 29, 1, 'Games People Play', 'Novel Book', 'IMP-837', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 80.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df706fab646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(222, 29, 1, 'Eat That Frog', 'Novel Book', 'IMP-836', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7070e9646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(223, 29, 1, 'The Art of Reading Minds', 'Novel Book', 'IMP-832', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 110.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7071d8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(224, 29, 1, 'A Thousand Splendid Suns (Khalid)', 'Novel Book', 'IMP-831', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 105.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df70ea4f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(225, 29, 1, 'A Good Girls Guide to Murder (Jackson)', 'Novel Book', 'IMP-830', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df72012d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(226, 29, 1, 'Ego Is the Enemy', 'Novel Book', 'IMP-829', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df720378646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(227, 29, 1, 'The 7 Habits of Highly Effective People', 'Novel Book', 'IMP-828', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 130.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df72051f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(228, 29, 1, 'Revive Your Heart', 'Novel Book', 'IMP-827', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df72062d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(229, 29, 1, 'Shatter Me (Mafi)', 'Novel Book', 'IMP-826', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df720712646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(230, 29, 1, 'The Psychology of Money', 'Novel Book', 'IMP-825', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 110.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7207ea646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(231, 29, 1, 'November Nine', 'Novel Book', 'IMP-824', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7208c9646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(232, 29, 1, 'How to Analyze People', 'Novel Book', 'IMP-823', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7209d0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(233, 29, 1, 'Brief Answers to the Big Questions A+', 'Novel Book', 'IMP-822', 'product', 'product', NULL, 0, NULL, 0, NULL, 700.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df720aaf646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(234, 29, 1, 'How to Win Friends and Influence People', 'Novel Book', 'IMP-821', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 110.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df720b82646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(235, 29, 1, 'The 48 Laws of Power', 'Novel Book', 'IMP-820', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df720c53646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(236, 29, 1, 'The Book of Rumi: 105 Stories', 'Novel Book', 'IMP-819', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df720d21646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(237, 29, 1, 'The Spanish Love Deception', 'Novel Book', 'IMP-818', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df720df2646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(238, 29, 1, 'The New Confessions of an Economic Hit Man', 'Novel Book', 'IMP-817', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df720ebf646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(239, 29, 1, 'Sapiens (Noah)', 'Novel Book', 'IMP-815', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df720f8c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(240, 29, 1, 'The Seven Husbands of Evelyn Hugo', 'Novel Book', 'IMP-814', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 170.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df72105a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(241, 29, 1, 'One of Us Is Lying (karen)', 'Novel Book', 'IMP-813', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df72112a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(242, 29, 1, '13 Things Mentally Strong People Don\'t Do', 'Novel Book', 'IMP-812', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7211f6646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(243, 29, 1, 'The Bastard of Istanbul', 'Novel Book', 'IMP-811', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7212c4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(244, 29, 1, 'Think Like a Monk', 'Novel Book', 'IMP-810', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df721392646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(245, 29, 1, 'The Power of your subconscious mind', 'Novel Book', 'IMP-809', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 110.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df72145c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(246, 29, 1, 'The 80/20 Principle', 'Novel Book', 'IMP-808', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df72152a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(247, 29, 1, 'Prisoners Of Geography', 'Novel Book', 'IMP-807', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7215f8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(248, 29, 1, 'Things We Never Got Over', 'Novel Book', 'IMP-806', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 250.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7216c8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(249, 29, 1, 'Me and Rumi', 'Novel Book', 'IMP-805', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df721797646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(250, 29, 1, 'Everything You Wanted to Know about the Afterlife', 'Novel Book', 'IMP-804', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df721868646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(251, 29, 1, 'Layla', 'Novel Book', 'IMP-803', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df72195c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(252, 29, 1, 'King of Wrath', 'Novel Book', 'IMP-802', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df721aa5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(253, 29, 1, 'Twisted Lies', 'Novel Book', 'IMP-801', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 170.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df721b7c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(254, 29, 1, 'The Power of Positive Thinking', 'Novel Book', 'IMP-800', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df721c4f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(255, 29, 1, 'Haunting Adeline (Carlton)', 'Novel Book', 'IMP-799', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df722ba8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(256, 29, 1, 'Better Than the Movies', 'Novel Book', 'IMP-798', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df722cfc646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(257, 29, 1, 'How to Talk to Anyone', 'Novel Book', 'IMP-797', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 110.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df722dfc646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(258, 29, 1, 'The Fault in Our Stars', 'Novel Book', 'IMP-796', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df722eef646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO `items` (`id`, `client_id`, `branch_id`, `name`, `description`, `sku`, `type`, `item_nature`, `unit_name`, `has_variants`, `variant_details`, `has_expiry`, `expiry_date`, `price`, `wholesaler_price_1`, `wholesaler_price_2`, `wholesaler_price_3`, `cost`, `low_stock_alert`, `status`, `image`, `is_raw_item`, `track_inventory`, `stock_qty`, `category`, `kitchen_section`, `prep_time_minutes`, `is_restaurant_item`, `supplier_id`, `reorder_flag`, `uuid`, `device_id`, `sync_status`, `last_synced_at`, `deleted_at`, `created_at`, `updated_at`, `created_at_local`, `updated_at_local`) VALUES
(259, 29, 1, 'Emotional Intelligence', 'Novel Book', 'IMP-795', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df722fd5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(260, 29, 1, 'Secrets Of Divine Love', 'Novel Book', 'IMP-794', 'product', 'product', NULL, 0, NULL, 0, NULL, 1300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7230b5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(261, 29, 1, 'Everything is Fucked', 'Novel Book', 'IMP-793', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df72318f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(262, 29, 1, 'How to Stop Worrying and Start Living', 'Novel Book', 'IMP-792', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df723267646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(263, 29, 1, 'The Power of Now', 'Novel Book', 'IMP-791', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 110.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df723341646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(264, 29, 1, 'Cold War in the Islamic World', 'Novel Book', 'IMP-790', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df72341b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(265, 29, 1, 'Destroy Me (Mafi)', 'Novel Book', 'IMP-789', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7234ff646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(266, 29, 1, 'Trouble Triangle', 'Novel Book', 'IMP-788', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7235d7646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(267, 29, 1, 'Zero to One', 'Novel Book', 'IMP-787', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 110.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7236a3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(268, 29, 1, 'The Art of War', 'Novel Book', 'IMP-786', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df72376b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(269, 29, 1, 'The Mountain Is You', 'Novel Book', 'IMP-785', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df723849646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(270, 29, 1, 'The Fine Print (Asher)', 'Novel Book', 'IMP-784', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df723915646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(271, 29, 1, '12 Rules for Life', 'Novel Book', 'IMP-783', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 110.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7239dd646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(272, 29, 1, 'As Good As Dead (Jackson)', 'Novel Book', 'IMP-782', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df723eb7646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(273, 29, 1, 'Hunting Adeline', 'Novel Book', 'IMP-781', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7240b4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(274, 29, 1, 'The Laws of Human Nature', 'Novel Book', 'IMP-780', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7241ee646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(275, 29, 1, 'Crime and Punishment (Fyodor)', 'Novel Book', 'IMP-779', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 250.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df724330646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(276, 29, 1, 'Men Are from Mars, Women Are from Venus', 'Novel Book', 'IMP-778', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df724487646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(277, 29, 1, 'Maybe Not', 'Novel Book', 'IMP-777', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7245fc646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(278, 29, 1, 'Ignite Me (Mafi)', 'Novel Book', 'IMP-776', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df724bce646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(279, 29, 1, 'The Magic of Thinking Big', 'Novel Book', 'IMP-775', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df724d0d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(280, 29, 1, 'Hopeless', 'Novel Book', 'IMP-774', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df724e56646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(281, 29, 1, 'Imagine Me (Mafi)', 'Novel Book', 'IMP-773', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df72511a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(282, 29, 1, 'A Little Life', 'Novel Book', 'IMP-772', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 270.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df725243646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(283, 29, 1, 'The Cruel Prince (Holly)', 'Novel Book', 'IMP-771', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 170.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df725862646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(284, 29, 1, 'The Midnight Library', 'Novel Book', 'IMP-770', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df72598e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(285, 29, 1, 'Why A Students Work for C Students', 'Novel Book', 'IMP-769', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df725a9f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(286, 29, 1, 'The Monk Who Sold His Ferrari', 'Novel Book', 'IMP-768', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df725fdb646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(287, 29, 1, 'The Queen of Nothing (Holly)', 'Novel Book', 'IMP-767', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df72611a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(288, 29, 1, 'Restore Me (Mafi)', 'Novel Book', 'IMP-766', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df72e8e6646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(289, 29, 1, 'Cant Hurt Me', 'Novel Book', 'IMP-764', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df72ef0a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(290, 29, 1, 'Maybe Now', 'Novel Book', 'IMP-763', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df72f063646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(291, 29, 1, 'Reveal Me (Mafi)', 'Novel Book', 'IMP-762', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df72f545646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(292, 29, 1, 'No God but God', 'Novel Book', 'IMP-761', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df72f78e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(293, 29, 1, 'Terms and Conditions (Asher)', 'Novel Book', 'IMP-760', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df72fcf4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(294, 29, 1, 'The American Roommate Experiment', 'Novel Book', 'IMP-759', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7300c4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(295, 29, 1, 'The Power of Habit', 'Novel Book', 'IMP-758', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73046c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(296, 29, 1, 'Imran Khan Pakistan A Personal History', 'Novel Book', 'IMP-757', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 170.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df730854646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(297, 29, 1, 'The 7 Habits Of Highly Effective Teens', 'Novel Book', 'IMP-756', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df730c67646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(298, 29, 1, 'The Wicked King (Holly)', 'Novel Book', 'IMP-755', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df731409646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(299, 29, 1, 'The Intelligent Investor', 'Novel Book', 'IMP-754', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7314f7646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(300, 29, 1, 'Dark Psychology Secrets 2021', 'Novel Book', 'IMP-753', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7315c9646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(301, 29, 1, 'The Secret History of the World', 'Novel Book', 'IMP-752', 'product', 'product', NULL, 0, NULL, 0, NULL, 500.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7316a1646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(302, 29, 1, 'No Longer Human', 'Novel Book', 'IMP-751', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df731778646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(303, 29, 1, 'Thinking, Fast and Slow', 'Novel Book', 'IMP-750', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 170.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73184a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(304, 29, 1, 'Pakistan Under Seige', 'Novel Book', 'IMP-748', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73191a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(305, 29, 1, 'Then She Was Gone', 'Novel Book', 'IMP-745', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7319eb646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(306, 29, 1, 'The Clash of Civilizations (A+)', 'Novel Book', 'IMP-744', 'product', 'product', NULL, 0, NULL, 0, NULL, 600.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df731aba646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(307, 29, 1, 'The Compound Effect', 'Novel Book', 'IMP-743', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df731b8b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(308, 29, 1, 'The Archer', 'Novel Book', 'IMP-742', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df731c5f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(309, 29, 1, 'And the Mountains Echoed (Khalid)', 'Novel Book', 'IMP-741', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 110.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df731d32646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(310, 29, 1, 'The Secret History', 'Novel Book', 'IMP-740', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 250.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df731e0a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(311, 29, 1, 'Wuthering Heights', 'Novel Book', 'IMP-739', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df731ed9646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(312, 29, 1, 'Homo Deus (Noah)', 'Novel Book', 'IMP-738', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df731fa6646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(313, 29, 1, 'The Book Thief', 'Novel Book', 'IMP-737', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df732076646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(314, 29, 1, 'If We Ever Meet Again', 'Novel Book', 'IMP-736', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df732141646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(315, 29, 1, 'Believe In your Self', 'Novel Book', 'IMP-735', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df732212646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(316, 29, 1, 'Shadow Me (Mafi)', 'Novel Book', 'IMP-734', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7322e1646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(317, 29, 1, 'Fracture Me (Mafi)', 'Novel Book', 'IMP-733', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7323ae646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(318, 29, 1, 'Lost Islamic History', 'Novel Book', 'IMP-732', 'product', 'product', NULL, 0, NULL, 0, NULL, 900.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73247b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(319, 29, 1, 'Six of Crows', 'Novel Book', 'IMP-731', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df732581646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(320, 29, 1, 'The Sun and Her Flowers', 'Novel Book', 'IMP-730', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73265a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(321, 29, 1, 'Welcome Home', 'Novel Book', 'IMP-729', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 170.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73272e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(322, 29, 1, 'Defy Me (Mafi)', 'Novel Book', 'IMP-728', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7327fd646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(323, 29, 1, 'Saladin', 'Novel Book', 'IMP-727', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7328dc646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(324, 29, 1, 'Heart Bones', 'Novel Book', 'IMP-726', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df732a0f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(325, 29, 1, 'Steal Like an Artist', 'Novel Book', 'IMP-725', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df732b57646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(326, 29, 1, 'Final Offer (Asher)', 'Novel Book', 'IMP-724', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df732c7a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(327, 29, 1, 'Girl in Pieces (Kathlen)', 'Novel Book', 'IMP-723', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df732da4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(328, 29, 1, 'A Man Called Ove', 'Novel Book', 'IMP-722', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df732ec6646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(329, 29, 1, 'Three Daughters of Eve', 'Novel Book', 'IMP-721', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df732fe7646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(330, 29, 1, 'The Sweetest Oblivion (Lori)', 'Novel Book', 'IMP-720', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73310a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(331, 29, 1, 'The Art Of Psychological Warfare', 'Novel Book', 'IMP-719', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73322d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(332, 29, 1, 'The God of Small Things', 'Novel Book', 'IMP-718', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73334c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(333, 29, 1, 'The Summer I Turned Pretty', 'Novel Book', 'IMP-717', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df733471646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(334, 29, 1, 'Secrets Of Millionaire Mind', 'Novel Book', 'IMP-716', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73358e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(335, 29, 1, 'Eleven Minutes', 'Novel Book', 'IMP-715', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7336ab646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(336, 29, 1, 'My Feudal Lord', 'Novel Book', 'IMP-714', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7337c9646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(337, 29, 1, 'Who Moved My Cheese?', 'Novel Book', 'IMP-713', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7338e5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(338, 29, 1, 'Teachings of Rumi', 'Novel Book', 'IMP-712', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df733a03646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(339, 29, 1, 'Rich Dad\'s Cashflow Quadrant', 'Novel Book', 'IMP-711', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df733b1f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(340, 29, 1, 'The Maddest Obsession (Lori)', 'Novel Book', 'IMP-710', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df733c40646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(341, 29, 1, 'Sea Prayer (Khalid)', 'Novel Book', 'IMP-709', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df733d5f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(342, 29, 1, 'Tuesdays with Morrie', 'Novel Book', 'IMP-708', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df733e7b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(343, 29, 1, 'India Wins Freedom', 'Novel Book', 'IMP-707', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 170.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df733f4f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(344, 29, 1, 'An Era of Darkness', 'Novel Book', 'IMP-706', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73401f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(345, 29, 1, 'Master Your Emotions', 'Novel Book', 'IMP-705', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df734245646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(346, 29, 1, 'Animal Farm', 'Novel Book', 'IMP-704', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df734356646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(347, 29, 1, 'One of Us Is Next (karen)', 'Novel Book', 'IMP-703', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df734465646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(348, 29, 1, 'If the Sun Never Sets', 'Novel Book', 'IMP-702', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df734576646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(349, 29, 1, 'If Love Had A Price', 'Novel Book', 'IMP-701', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df734682646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(350, 29, 1, 'Sophies World', 'Novel Book', 'IMP-700', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73478d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(351, 29, 1, 'Slammed', 'Novel Book', 'IMP-699', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73489b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(352, 29, 1, 'Five Survive (Jackson)', 'Novel Book', 'IMP-698', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df734aef646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(353, 29, 1, 'The Business of the 21st Century', 'Novel Book', 'IMP-697', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df734c3c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(354, 29, 1, 'The 100: A Ranking Of The Most Influential Persons In History', 'Novel Book', 'IMP-696', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df734d94646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(355, 29, 1, 'On China (A+)', 'Novel Book', 'IMP-695', 'product', 'product', NULL, 0, NULL, 0, NULL, 800.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df734f4d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(356, 29, 1, 'It\'s Not Summer Without You', 'Novel Book', 'IMP-694', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df735089646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(357, 29, 1, 'The Unhoneymooners', 'Novel Book', 'IMP-693', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73bf9c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(358, 29, 1, 'The $100 Startup', 'Novel Book', 'IMP-692', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73c252646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(359, 29, 1, 'Dance of Thieves', 'Novel Book', 'IMP-691', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73c398646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(360, 29, 1, 'Show Your Work', 'Novel Book', 'IMP-690', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73c4b3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(361, 29, 1, 'Icebreaker', 'Novel Book', 'IMP-689', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73c5b2646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(362, 29, 1, 'If You Could See the Sun', 'Novel Book', 'IMP-688', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73c6b3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(363, 29, 1, 'Punk 57', 'Novel Book', 'IMP-685', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73c7b0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(364, 29, 1, 'How to Calm Your Mind', 'Novel Book', 'IMP-684', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73ca0b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(365, 29, 1, 'Dont Believe Everything You Think', 'Novel Book', 'IMP-683', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 80.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73cb0a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(366, 29, 1, 'Into the Water', 'Novel Book', 'IMP-682', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73cbf8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(367, 29, 1, 'The Great Gatsby', 'Novel Book', 'IMP-681', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73ccdf646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(368, 29, 1, 'Losing Hope', 'Novel Book', 'IMP-679', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73cdc6646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(369, 29, 1, 'The Predator (Runyx)', 'Novel Book', 'IMP-678', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73ceac646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(370, 29, 1, 'Sell Like Crazyy', 'Novel Book', 'IMP-677', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73cf96646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(371, 29, 1, 'Prophet of Mercy', 'Novel Book', 'IMP-676', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73d081646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(372, 29, 1, '40 on Justice', 'Novel Book', 'IMP-675', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73d1d4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(373, 29, 1, 'Before Your Memory Fades (Toshi)', 'Novel Book', 'IMP-674', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73d2ca646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(374, 29, 1, 'When in Rome', 'Novel Book', 'IMP-673', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73d3c6646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(375, 29, 1, 'The Godfather', 'Novel Book', 'IMP-672', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73d4b1646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(376, 29, 1, 'Diplomacy', 'Novel Book', 'IMP-671', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73d5a3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(377, 29, 1, 'It Happened One Summer', 'Novel Book', 'IMP-670', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73db3a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(378, 29, 1, 'The Hobbit', 'Novel Book', 'IMP-669', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 230.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73dc5d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(379, 29, 1, 'The First 20 Hours', 'Novel Book', 'IMP-668', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73dd43646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(380, 29, 1, 'Two Can Keep a Secret (Karen)', 'Novel Book', 'IMP-667', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73de30646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(381, 29, 1, 'Things We Hide from the Light', 'Novel Book', 'IMP-666', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 250.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73df2f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(382, 29, 1, 'We\'ll Always Have Summer', 'Novel Book', 'IMP-665', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73e01b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(383, 29, 1, 'The Divine Reality', 'Novel Book', 'IMP-664', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73e0f5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(384, 29, 1, 'How to Make Friends with the Dark (Kathlen)', 'Novel Book', 'IMP-663', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73e208646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(385, 29, 1, 'How To Enjoy Your Life And Your Job', 'Novel Book', 'IMP-662', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73e2f2646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(386, 29, 1, 'You ll Be the Death of Me (Karen)', 'Novel Book', 'IMP-661', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73e3cd646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(387, 29, 1, 'Never Forget You', 'Novel Book', 'IMP-660', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73e4a8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(388, 29, 1, 'The Lies We Tell', 'Novel Book', 'IMP-659', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73eafa646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(389, 29, 1, 'The Five People You Meet in Heaven', 'Novel Book', 'IMP-658', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73ec88646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(390, 29, 1, 'All Your Perfects', 'Novel Book', 'IMP-657', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73ed75646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(391, 29, 1, 'The Stolen Heir (Holly)', 'Novel Book', 'IMP-656', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73ee54646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(392, 29, 1, 'The Reaper (Runyx)', 'Novel Book', 'IMP-655', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73ef73646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(393, 29, 1, 'Family of Liars', 'Novel Book', 'IMP-654', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73f060646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(394, 29, 1, 'Kiss That Frog', 'Novel Book', 'IMP-653', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73f493646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(395, 29, 1, 'Find Me (Mafi)', 'Novel Book', 'IMP-652', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73f56d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(396, 29, 1, 'The Giver of Stars', 'Novel Book', 'IMP-651', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73f642646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(397, 29, 1, 'Rich Kid, Smart Kid', 'Novel Book', 'IMP-650', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73f766646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(398, 29, 1, 'This Girl', 'Novel Book', 'IMP-649', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73f848646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(399, 29, 1, '99 Percent Mine', 'Novel Book', 'IMP-648', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73fc5d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(400, 29, 1, 'Ruin and Rising', 'Novel Book', 'IMP-647', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73fd5c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(401, 29, 1, 'Siege and Storm', 'Novel Book', 'IMP-646', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73fe6f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(402, 29, 1, 'prayers of the pious', 'Novel Book', 'IMP-645', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df73ff48646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(403, 29, 1, 'The Emperor (Runyx)', 'Novel Book', 'IMP-644', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df749595646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(404, 29, 1, 'Angels in Your Presence', 'Novel Book', 'IMP-643', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df749934646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(405, 29, 1, 'The Finisher (Runyx)', 'Novel Book', 'IMP-642', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df749ab8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(406, 29, 1, 'Love at First Spite', 'Novel Book', 'IMP-641', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df749f88646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(407, 29, 1, 'Foul Lady Fortune', 'Novel Book', 'IMP-640', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74a0c7646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(408, 29, 1, 'Collided', 'Novel Book', 'IMP-639', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74a62c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(409, 29, 1, 'Global Discontents', 'Novel Book', 'IMP-638', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74a780646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(410, 29, 1, 'Anna and the French Kiss', 'Novel Book', 'IMP-637', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74ac90646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(411, 29, 1, 'The Bhutto Dynasty', 'Novel Book', 'IMP-636', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74b355646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(412, 29, 1, 'Courage Is Calling (A+)', 'Novel Book', 'IMP-635', 'product', 'product', NULL, 0, NULL, 0, NULL, 800.00, 0.00, 0.00, 0.00, 450.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74ba23646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(413, 29, 1, 'Daughter of the East', 'Novel Book', 'IMP-634', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74be55646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(414, 29, 1, 'Hidden Figures', 'Novel Book', 'IMP-633', 'product', 'product', NULL, 0, NULL, 0, NULL, 650.00, 0.00, 0.00, 0.00, 300.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74ca98646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(415, 29, 1, 'Annihilator (Runyx)', 'Novel Book', 'IMP-632', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74cbeb646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(416, 29, 1, 'Ricochet', 'Novel Book', 'IMP-631', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74cce6646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(417, 29, 1, 'Something Wilder', 'Novel Book', 'IMP-630', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74ce29646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(418, 29, 1, 'Home Is Where You Are Melissa Grace', 'Novel Book', 'IMP-629', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74cf32646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(419, 29, 1, 'You d Be Home Now (Kathlen)', 'Novel Book', 'IMP-628', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 140.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74d022646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(420, 29, 1, 'Set on You', 'Novel Book', 'IMP-627', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74d112646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(421, 29, 1, 'One Last Stop', 'Novel Book', 'IMP-626', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74d1f7646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(422, 29, 1, 'Bind Me', 'Novel Book', 'IMP-625', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74d2ef646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(423, 29, 1, 'Claim Me', 'Novel Book', 'IMP-624', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74d429646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(424, 29, 1, 'Hold Me', 'Novel Book', 'IMP-623', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74d516646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(425, 29, 1, 'Capture Me', 'Novel Book', 'IMP-622', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74d5f9646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(426, 29, 1, 'Live Life in Crescendo', 'Novel Book', 'IMP-621', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74d6d9646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(427, 29, 1, 'Saint', 'Novel Book', 'IMP-620', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74d7c2646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(428, 29, 1, 'Sinner', 'Novel Book', 'IMP-619', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74d8a8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(429, 29, 1, 'Midnight Mass', 'Novel Book', 'IMP-618', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74d993646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(430, 29, 1, 'Promises We Meant to Keep', 'Novel Book', 'IMP-617', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74db0d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(431, 29, 1, 'Sense and Sensibility', 'Novel Book', 'IMP-616', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74dc2f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(432, 29, 1, 'The Match', 'Novel Book', 'IMP-615', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74dd1e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO `items` (`id`, `client_id`, `branch_id`, `name`, `description`, `sku`, `type`, `item_nature`, `unit_name`, `has_variants`, `variant_details`, `has_expiry`, `expiry_date`, `price`, `wholesaler_price_1`, `wholesaler_price_2`, `wholesaler_price_3`, `cost`, `low_stock_alert`, `status`, `image`, `is_raw_item`, `track_inventory`, `stock_qty`, `category`, `kitchen_section`, `prep_time_minutes`, `is_restaurant_item`, `supplier_id`, `reorder_flag`, `uuid`, `device_id`, `sync_status`, `last_synced_at`, `deleted_at`, `created_at`, `updated_at`, `created_at_local`, `updated_at_local`) VALUES
(433, 29, 1, 'Dozakhnama', 'Novel Book', 'IMP-614', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74de01646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(434, 29, 1, 'When The Moon Split', 'Novel Book', 'IMP-613', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74dee3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(435, 29, 1, 'Three Swedish Mountain Men', 'Novel Book', 'IMP-612', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74dfca646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(436, 29, 1, 'Does It Hurt?', 'Novel Book', 'IMP-611', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74e0b6646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(437, 29, 1, 'The Art of Thinking Clearly', 'Novel Book', 'IMP-610', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74e19b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(438, 29, 1, 'How to Stop Overthinking', 'Novel Book', 'IMP-609', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 80.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74e284646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(439, 29, 1, 'Divine Speech', 'Novel Book', 'IMP-608', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74e396646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(440, 29, 1, 'Before We Were Strangers', 'Novel Book', 'IMP-607', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74e490646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(441, 29, 1, 'Getting Over You', 'Novel Book', 'IMP-606', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74e5ec646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(442, 29, 1, 'King Of Pride', 'Novel Book', 'IMP-605', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74e756646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(443, 29, 1, 'Happy Place', 'Novel Book', 'IMP-604', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74e892646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(444, 29, 1, 'If He Had Been with Me', 'Novel Book', 'IMP-603', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 170.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74e9d0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(445, 29, 1, 'I Will Teach You To Be Rich', 'Novel Book', 'IMP-602', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74eb15646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(446, 29, 1, 'Frankenstein', 'Novel Book', 'IMP-601', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74ec58646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(447, 29, 1, 'Closer to Love', 'Novel Book', 'IMP-600', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df74ed97646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(448, 29, 1, 'The Borrowers', 'Novel Book', 'IMP-599', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 220.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df751cf8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(449, 29, 1, 'Little Women', 'Novel Book', 'IMP-598', 'product', 'product', NULL, 0, NULL, 0, NULL, 500.00, 0.00, 0.00, 0.00, 280.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df751efe646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(450, 29, 1, 'Rock Paper Scissors', 'Novel Book', 'IMP-597', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df752072646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(451, 29, 1, 'The Reconstruction of Religious Thought in Islam', 'Novel Book', 'IMP-596', 'product', 'product', NULL, 0, NULL, 0, NULL, 600.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7521d7646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(452, 29, 1, 'Satan\'s Affair', 'Novel Book', 'IMP-595', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df752328646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(453, 29, 1, 'God of Pain', 'Novel Book', 'IMP-594', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df75246f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(454, 29, 1, 'God of Wrath', 'Novel Book', 'IMP-593', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7525b2646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(455, 29, 1, 'The Power Of Positive Living', 'Novel Book', 'IMP-592', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7526f5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(456, 29, 1, 'The Temporary Roomie', 'Novel Book', 'IMP-591', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df752868646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(457, 29, 1, 'Future of Geography', 'Novel Book', 'IMP-590', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7529b2646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(458, 29, 1, 'Forever Never', 'Novel Book', 'IMP-589', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df752af3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(459, 29, 1, 'The Worst Best Man', 'Novel Book', 'IMP-588', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df752c35646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(460, 29, 1, 'The Daily Dad', 'Novel Book', 'IMP-587', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df752d25646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(461, 29, 1, 'Azadi', 'Novel Book', 'IMP-586', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df752e0b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(462, 29, 1, 'When Breath Becomes Air', 'Novel Book', 'IMP-585', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df752ef1646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(463, 29, 1, 'Lessons in Chemistry', 'Novel Book', 'IMP-584', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df752fd7646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(464, 29, 1, 'The Darkest Temptation (Lori)', 'Novel Book', 'IMP-583', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7530df646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(465, 29, 1, 'Loathe to Love You', 'Novel Book', 'IMP-582', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7531cc646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(466, 29, 1, 'Tales from the Cafe (Toshi)', 'Novel Book', 'IMP-581', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7532ae646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(467, 29, 1, 'The Writing on the Wall', 'Novel Book', 'IMP-580', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df753393646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(468, 29, 1, '50 Business Classics', 'Novel Book', 'IMP-579', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df753478646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(469, 29, 1, 'No Exit from Pakistan', 'Novel Book', 'IMP-578', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df75355a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(470, 29, 1, 'Start with Why', 'Novel Book', 'IMP-577', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df75363d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(471, 29, 1, 'The Courage to Be Disliked', 'Novel Book', 'IMP-576', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 130.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df753721646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(472, 29, 1, '101 Essays That Will Change The Way You Think', 'Novel Book', 'IMP-575', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df753828646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(473, 29, 1, 'Influence: The Psychology of Persuasion', 'Novel Book', 'IMP-574', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 170.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df753913646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(474, 29, 1, '75 People Who Changed the World', 'Novel Book', 'IMP-573', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7539fb646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(475, 29, 1, 'Meeting M.......', 'Novel Book', 'IMP-572', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df753ae0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(476, 29, 1, 'A Love', 'Novel Book', 'IMP-571', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df753bc5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(477, 29, 1, 'Beyond the Story', 'Novel Book', 'IMP-570', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df753ca8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(478, 29, 1, 'Birthday Girl', 'Novel Book', 'IMP-569', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df753d8b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(479, 29, 1, 'The Extraordinary Cases of Sherlock Holmes', 'Novel Book', 'IMP-568', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df753e6d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(480, 29, 1, 'The Meltdown', 'Novel Book', 'IMP-567', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df753fa2646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(481, 29, 1, 'The Village by the Sea', 'Novel Book', 'IMP-566', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7540a8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(482, 29, 1, 'The Magic Finger', 'Novel Book', 'IMP-565', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df754190646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(483, 29, 1, 'The BFG', 'Novel Book', 'IMP-564', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df754271646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(484, 29, 1, 'Billy and the Minpins', 'Novel Book', 'IMP-563', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df754354646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(485, 29, 1, 'Charlie and the Great Glass Elevator', 'Novel Book', 'IMP-562', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df75443a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(486, 29, 1, 'A Tale of Two Cities', 'Novel Book', 'IMP-561', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df75455e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(487, 29, 1, 'The Witches', 'Novel Book', 'IMP-560', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 220.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7546f4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(488, 29, 1, 'Matilda', 'Novel Book', 'IMP-559', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 220.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7548af646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(489, 29, 1, 'Fantastic M Fox', 'Novel Book', 'IMP-558', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7549b0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(490, 29, 1, 'The Last Straw', 'Novel Book', 'IMP-557', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df754a96646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(491, 29, 1, 'Charlie and the Chocolate Factory', 'Novel Book', 'IMP-556', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 220.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df754b7a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(492, 29, 1, 'Going Solo', 'Novel Book', 'IMP-555', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df754c67646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(493, 29, 1, 'A Little Princess', 'Novel Book', 'IMP-554', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df754f83646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(494, 29, 1, 'Great Expectations', 'Novel Book', 'IMP-553', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7550a9646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(495, 29, 1, 'The Twits', 'Novel Book', 'IMP-552', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df755190646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(496, 29, 1, 'Rowley Jeffersons Awesome Friendly Adventure', 'Novel Book', 'IMP-551', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df75533e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(497, 29, 1, 'Danny the Champion of the World', 'Novel Book', 'IMP-550', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7554df646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(498, 29, 1, 'The Long Haul', 'Novel Book', 'IMP-549', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7555f0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(499, 29, 1, 'A Wrinkle in Time', 'Novel Book', 'IMP-548', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7556e0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(500, 29, 1, 'Treasure Island', 'Novel Book', 'IMP-547', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df755856646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(501, 29, 1, 'Around the World in Eighty Days', 'Novel Book', 'IMP-546', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7559ca646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(502, 29, 1, 'Alices Adventures in Wonderland', 'Novel Book', 'IMP-545', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df755b33646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(503, 29, 1, 'Georges Marvellous Medicine', 'Novel Book', 'IMP-544', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df755ce2646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(504, 29, 1, 'The Lion the Witch and the Wardrobe', 'Novel Book', 'IMP-543', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 220.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df755e56646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(505, 29, 1, 'Boy Tales of Childhood', 'Novel Book', 'IMP-542', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df75652a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(506, 29, 1, 'The Hound of the Baskervilles', 'Novel Book', 'IMP-541', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 250.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df756695646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(507, 29, 1, 'Tales from Shakespeare', 'Novel Book', 'IMP-540', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 250.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df756780646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(508, 29, 1, 'David Copperfield', 'Novel Book', 'IMP-539', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df756860646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(509, 29, 1, 'Heidi', 'Novel Book', 'IMP-538', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df75694c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(510, 29, 1, 'The Happy Prince', 'Novel Book', 'IMP-537', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df756a35646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(511, 29, 1, 'The Secret Garden', 'Novel Book', 'IMP-536', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df756e69646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(512, 29, 1, 'The Railway Children', 'Novel Book', 'IMP-535', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df756f71646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(513, 29, 1, 'Five Children and It', 'Novel Book', 'IMP-534', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df757059646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(514, 29, 1, 'Pollyanna', 'Novel Book', 'IMP-533', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df757188646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(515, 29, 1, 'The Last Battle', 'Novel Book', 'IMP-532', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7575a9646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(516, 29, 1, 'The Silver Chair', 'Novel Book', 'IMP-531', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df75768a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(517, 29, 1, 'Prince Caspian', 'Novel Book', 'IMP-530', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df75776d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(518, 29, 1, 'The Horse and His Boy', 'Novel Book', 'IMP-529', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7578d9646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(519, 29, 1, 'The Magician\'s Nephew', 'Novel Book', 'IMP-528', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df757c95646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(520, 29, 1, 'Big Shot', 'Novel Book', 'IMP-527', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df75f8e5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(521, 29, 1, 'The Great Adventures of Sherlock Holmes', 'Novel Book', 'IMP-526', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df75fc1a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(522, 29, 1, 'The Voyage of the Dawn Treader', 'Novel Book', 'IMP-525', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7601c3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(523, 29, 1, 'All In One', 'Novel Book', 'IMP-524', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df760335646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(524, 29, 1, 'The Husband Diet', 'Novel Book', 'IMP-523', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7607c1646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(525, 29, 1, 'God of Malice', 'Novel Book', 'IMP-522', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df760a10646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(526, 29, 1, 'In The Silence You Left Behind', 'Novel Book', 'IMP-521', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df761205646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(527, 29, 1, 'Wired for Success', 'Novel Book', 'IMP-520', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df761790646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(528, 29, 1, 'Understand Political Philosophy', 'Novel Book', 'IMP-519', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df761d6e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(529, 29, 1, 'The Black Swan', 'Novel Book', 'IMP-518', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7623fc646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(530, 29, 1, 'The Creative Act: A Way of Being', 'Novel Book', 'IMP-517', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df763ac6646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(531, 29, 1, 'We Are Not Like Them', 'Novel Book', 'IMP-516', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df763c90646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(532, 29, 1, 'The Way I Used to Be', 'Novel Book', 'IMP-515', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df763db1646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(533, 29, 1, 'The Summer Switch-Off', 'Novel Book', 'IMP-514', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df763ea9646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(534, 29, 1, 'Only Love Can Hurt Like This', 'Novel Book', 'IMP-513', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df763f9c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(535, 29, 1, 'Fourth Wing (Yarros)', 'Novel Book', 'IMP-512', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 230.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76418d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(536, 29, 1, 'Things We Left Behind', 'Novel Book', 'IMP-511', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 250.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df764294646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(537, 29, 1, 'Master Your Thinking', 'Novel Book', 'IMP-510', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df764380646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(538, 29, 1, 'The Long Game', 'Novel Book', 'IMP-509', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76446b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(539, 29, 1, 'Behind Closed Doors', 'Novel Book', 'IMP-508', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76455b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(540, 29, 1, 'People We Meet on Vacation', 'Novel Book', 'IMP-507', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df764681646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(541, 29, 1, 'The Cousins (Karen)', 'Novel Book', 'IMP-505', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76477a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(542, 29, 1, 'Index, A History of the', 'Novel Book', 'IMP-504', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df764862646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(543, 29, 1, 'Love and Other Words', 'Novel Book', 'IMP-503', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76496f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(544, 29, 1, 'Playing By The Rules', 'Novel Book', 'IMP-502', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df764a5e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(545, 29, 1, 'If We Were Perfect', 'Novel Book', 'IMP-501', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df764b46646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(546, 29, 1, 'The Two of Us', 'Novel Book', 'IMP-500', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df764c2e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(547, 29, 1, 'Kiss the Sky', 'Novel Book', 'IMP-499', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df764d18646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(548, 29, 1, 'The Brothers Hawthorne (Jennifer)', 'Novel Book', 'IMP-498', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df764e02646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(549, 29, 1, 'Crooked Kingdom', 'Novel Book', 'IMP-497', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df764eee646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(550, 29, 1, 'The Girls Who Disappeared', 'Novel Book', 'IMP-496', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df764fd7646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(551, 29, 1, 'Read People Like a Book', 'Novel Book', 'IMP-495', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 110.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df765183646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(552, 29, 1, 'The Housemaid (Freida)', 'Novel Book', 'IMP-494', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df765332646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(553, 29, 1, 'The Housemaid Secret (Freida)', 'Novel Book', 'IMP-493', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df765489646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(554, 29, 1, 'Lyrical', 'Novel Book', 'IMP-492', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df765582646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(555, 29, 1, 'Breakers', 'Novel Book', 'IMP-491', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76566b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(556, 29, 1, 'Best of Friends', 'Novel Book', 'IMP-490', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df765765646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(557, 29, 1, 'Twist Me', 'Novel Book', 'IMP-488', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7658cd646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(558, 29, 1, 'Keep Me', 'Novel Book', 'IMP-487', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df765a40646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(559, 29, 1, 'The Power of Discipline', 'Novel Book', 'IMP-486', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 80.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df765b80646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(560, 29, 1, 'Secret Seven Box', 'Novel Book', 'IMP-485', 'product', 'product', NULL, 0, NULL, 0, NULL, 3000.00, 0.00, 0.00, 0.00, 1500.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df765f41646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(561, 29, 1, 'Goosebumps HorrorLand With Box', 'Novel Book', 'IMP-484', 'product', 'product', NULL, 0, NULL, 0, NULL, 3000.00, 0.00, 0.00, 0.00, 1600.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7660ad646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(562, 29, 1, 'The Chronicles of Narnia 7 Book Set with Box', 'Novel Book', 'IMP-483', 'product', 'product', NULL, 0, NULL, 0, NULL, 3000.00, 0.00, 0.00, 0.00, 1540.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76622f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(563, 29, 1, 'Roald Dahl 16 Books Box Set', 'Novel Book', 'IMP-482', 'product', 'product', NULL, 0, NULL, 0, NULL, 4500.00, 0.00, 0.00, 0.00, 3000.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df766386646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(564, 29, 1, 'The Power of Geography', 'Novel Book', 'IMP-481', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7664cd646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(565, 29, 1, 'Muhammad, the World-Changer', 'Novel Book', 'IMP-480', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df766615646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(566, 29, 1, 'Why Nation Fail', 'Novel Book', 'IMP-479', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76675c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(567, 29, 1, 'Long Walk to Freedom', 'Novel Book', 'IMP-478', 'product', 'product', NULL, 0, NULL, 0, NULL, 500.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76689f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(568, 29, 1, 'Spy Stories', 'Novel Book', 'IMP-477', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df766a0e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(569, 29, 1, 'The Subtle Art of Not Giving a Fuck', 'Novel Book', 'IMP-476', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df766b85646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(570, 29, 1, 'Beyond Good And Evil', 'Novel Book', 'IMP-475', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df766db5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(571, 29, 1, 'Fragrance Of A Dead Rose', 'Novel Book', 'IMP-474', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df766fa4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(572, 29, 1, 'Repentance', 'Novel Book', 'IMP-473', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7670f9646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(573, 29, 1, 'For Those Left Behind', 'Novel Book', 'IMP-472', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76723b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(574, 29, 1, 'Hell Bent', 'Novel Book', 'IMP-471', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76737a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(575, 29, 1, 'The Third Wheel', 'Novel Book', 'IMP-470', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df767466646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(576, 29, 1, 'Rodrick Rules', 'Novel Book', 'IMP-469', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76757a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(577, 29, 1, 'The Getaway', 'Novel Book', 'IMP-468', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 130.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df767667646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(578, 29, 1, 'Wrecking Ball', 'Novel Book', 'IMP-467', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76774a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(579, 29, 1, 'Dog Days', 'Novel Book', 'IMP-466', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76782e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(580, 29, 1, 'Hard Luck', 'Novel Book', 'IMP-465', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df767916646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(581, 29, 1, 'The Ugly Truth', 'Novel Book', 'IMP-464', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df767ac0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(582, 29, 1, 'Do-It-Yourself', 'Novel Book', 'IMP-463', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df767c37646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(583, 29, 1, 'One of Us Is Back (karen)', 'Novel Book', 'IMP-462', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df767d2d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(584, 29, 1, 'Before We Say Goodbye (Toshi)', 'Novel Book', 'IMP-461', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df767e44646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(585, 29, 1, 'Crossed', 'Novel Book', 'IMP-460', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df767f33646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(586, 29, 1, 'Twisted', 'Novel Book', 'IMP-459', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df768015646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(587, 29, 1, 'King of Greed', 'Novel Book', 'IMP-458', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7680f4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(588, 29, 1, 'Palestinian Islamic Jihad', 'Novel Book', 'IMP-457', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7681d8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(589, 29, 1, 'Iron Flame (Yarros)', 'Novel Book', 'IMP-456', 'product', 'product', NULL, 0, NULL, 0, NULL, 700.00, 0.00, 0.00, 0.00, 400.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7682bf646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(590, 29, 1, 'The Girl on the Train', 'Novel Book', 'IMP-455', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7683a4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(591, 29, 1, 'Tomorrow, and Tomorrow, and Tomorrow', 'Novel Book', 'IMP-454', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76848b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(592, 29, 1, 'Spy Family Set 1/13', 'Novel Book', 'IMP-453', 'product', 'product', NULL, 0, NULL, 0, NULL, 13000.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df768596646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(593, 29, 1, 'Once Upon a Broken Heart (Garber)', 'Novel Book', 'IMP-451', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 170.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df768682646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(594, 29, 1, 'Divine Rivals', 'Novel Book', 'IMP-450', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df768768646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(595, 29, 1, 'Chainsaw Man Set 1/17', 'Novel Book', 'IMP-449', 'product', 'product', NULL, 0, NULL, 0, NULL, 18000.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76884e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(596, 29, 1, 'Learning To Love Myself', 'Novel Book', 'IMP-448', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df768935646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(597, 29, 1, 'A Curse for True Love (Garber)', 'Novel Book', 'IMP-447', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df768a19646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(598, 29, 1, 'Diary of a Wimpy Kid Old School', 'Novel Book', 'IMP-446', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df768b00646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(599, 29, 1, 'Adventures of Huckleberry Finn', 'Novel Book', 'IMP-445', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df768be6646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(600, 29, 1, 'Black Beauty', 'Novel Book', 'IMP-444', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df768cec646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(601, 29, 1, 'Oliver Twist', 'Novel Book', 'IMP-443', 'product', 'product', NULL, 0, NULL, 0, NULL, 500.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df768dd8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(602, 29, 1, 'Thinking, Fast and Slow (A+)', 'Novel Book', 'IMP-442', 'product', 'product', NULL, 0, NULL, 0, NULL, 900.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df768ebe646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(603, 29, 1, 'It Starts with Us (A+)', 'Novel Book', 'IMP-441', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 220.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df768fa5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(604, 29, 1, 'Who Rules the World', 'Novel Book', 'IMP-440', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76908c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(605, 29, 1, 'The Clash of Civilization (A+)', 'Novel Book', 'IMP-439', 'product', 'product', NULL, 0, NULL, 0, NULL, 700.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df769172646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(606, 29, 1, '12 Rules for Life (A+)', 'Novel Book', 'IMP-438', 'product', 'product', NULL, 0, NULL, 0, NULL, 800.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df769257646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO `items` (`id`, `client_id`, `branch_id`, `name`, `description`, `sku`, `type`, `item_nature`, `unit_name`, `has_variants`, `variant_details`, `has_expiry`, `expiry_date`, `price`, `wholesaler_price_1`, `wholesaler_price_2`, `wholesaler_price_3`, `cost`, `low_stock_alert`, `status`, `image`, `is_raw_item`, `track_inventory`, `stock_qty`, `category`, `kitchen_section`, `prep_time_minutes`, `is_restaurant_item`, `supplier_id`, `reorder_flag`, `uuid`, `device_id`, `sync_status`, `last_synced_at`, `deleted_at`, `created_at`, `updated_at`, `created_at_local`, `updated_at_local`) VALUES
(607, 29, 1, '21 Lessons for the 21st Century (Noah)', 'Novel Book', 'IMP-437', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76933c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(608, 29, 1, 'The 7 Habits Of Highly Effective Teens (A+)', 'Novel Book', 'IMP-436', 'product', 'product', NULL, 0, NULL, 0, NULL, 700.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df769444646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(609, 29, 1, '21 Lessons for the 21st Century (A+)', 'Novel Book', 'IMP-433', 'product', 'product', NULL, 0, NULL, 0, NULL, 800.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76984b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(610, 29, 1, 'Confess', 'Novel Book', 'IMP-431', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76994c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(611, 29, 1, 'Pakistan: A Hard Country', 'Novel Book', 'IMP-430', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df769a2d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(612, 29, 1, 'The Silent Patient', 'Novel Book', 'IMP-429', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 110.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df769b13646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(613, 29, 1, 'Twisted Games', 'Novel Book', 'IMP-427', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 170.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df769bfa646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(614, 29, 1, 'Harry Potter and the Philosophers Stone', 'Novel Book', 'IMP-425', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 250.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df769cea646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(615, 29, 1, 'Harry Potter and the Prisoner of Azkaban', 'Novel Book', 'IMP-424', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 250.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df769dd4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(616, 29, 1, 'Harry Potter and the Half Blood Prince', 'Novel Book', 'IMP-423', 'product', 'product', NULL, 0, NULL, 0, NULL, 650.00, 0.00, 0.00, 0.00, 320.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df769eda646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(617, 29, 1, 'Harry Potter and the Order of the Phoenix', 'Novel Book', 'IMP-422', 'product', 'product', NULL, 0, NULL, 0, NULL, 550.00, 0.00, 0.00, 0.00, 300.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df769fbb646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(618, 29, 1, 'Harry Potter and the Deathly Hallows', 'Novel Book', 'IMP-421', 'product', 'product', NULL, 0, NULL, 0, NULL, 550.00, 0.00, 0.00, 0.00, 320.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76a095646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(619, 29, 1, 'Harry Potter and the Goblet of Fire', 'Novel Book', 'IMP-420', 'product', 'product', NULL, 0, NULL, 0, NULL, 550.00, 0.00, 0.00, 0.00, 300.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76a16e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(620, 29, 1, 'Harry Potter And The Chamber Of Secrets', 'Novel Book', 'IMP-419', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76a2a8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(621, 29, 1, 'Harry Potter and the Cursed Child', 'Novel Book', 'IMP-418', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76a8f5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(622, 29, 1, 'Flawless (Silver)', 'Novel Book', 'IMP-416', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76aa56646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(623, 29, 1, 'Hopeless (Silver)', 'Novel Book', 'IMP-415', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76ab3f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(624, 29, 1, 'Your Fault', 'Novel Book', 'IMP-414', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76ac4d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(625, 29, 1, 'How to Sell Anything to Anybody', 'Novel Book', 'IMP-413', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76ad3d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(626, 29, 1, 'Social Intelligence', 'Novel Book', 'IMP-412', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 170.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76ae2a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(627, 29, 1, 'Factfulness', 'Novel Book', 'IMP-410', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76c1bd646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(628, 29, 1, 'Game On (Navessa)', 'Novel Book', 'IMP-409', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 220.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76c32f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(629, 29, 1, 'Ruthless Vows', 'Novel Book', 'IMP-408', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76c434646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(630, 29, 1, 'Manipulation Techniques New', 'Novel Book', 'IMP-407', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76c57c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(631, 29, 1, 'General Science and Ability', 'Novel Book', 'IMP-406', 'product', 'product', NULL, 0, NULL, 0, NULL, 1375.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76c673646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(632, 29, 1, 'Wren & Martin', 'Novel Book', 'IMP-405', 'product', 'product', NULL, 0, NULL, 0, NULL, 895.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76cae6646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(633, 29, 1, 'Kafka on the Shore', 'Novel Book', 'IMP-404', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76cbd3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(634, 29, 1, 'When You\'re Ready, This Is How You Heal', 'Novel Book', 'IMP-403', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76ccb0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(635, 29, 1, 'The Diary of a CEO', 'Novel Book', 'IMP-402', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 110.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76cd9b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(636, 29, 1, 'The Story of My Life', 'Novel Book', 'IMP-401', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df76d145646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(637, 29, 1, 'The Phantom Tollbooth', 'Novel Book', 'IMP-400', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df774f93646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(638, 29, 1, 'Train to Pakistan', 'Novel Book', 'IMP-399', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df775210646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(639, 29, 1, 'The Anthropocene Reviewed', 'Novel Book', 'IMP-398', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df775708646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(640, 29, 1, '12 Months', 'Novel Book', 'IMP-397', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7758e1646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(641, 29, 1, '48 Laws Of Power Councise', 'Novel Book', 'IMP-396', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df775d5e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(642, 29, 1, 'Master Your Motivation', 'Novel Book', 'IMP-395', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df775ebb646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(643, 29, 1, 'Master Your Learning', 'Novel Book', 'IMP-394', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df776355646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(644, 29, 1, 'Master Your Beliefs', 'Novel Book', 'IMP-393', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df776761646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(645, 29, 1, 'The Fury', 'Novel Book', 'IMP-392', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df776bd2646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(646, 29, 1, 'Legendary (Caraval)', 'Novel Book', 'IMP-391', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 130.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7771e0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(647, 29, 1, 'Finale (Caraval)', 'Novel Book', 'IMP-390', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 130.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77777c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(648, 29, 1, 'Caraval (Caraval)', 'Novel Book', 'IMP-389', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 130.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77817b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(649, 29, 1, 'The Defender', 'Novel Book', 'IMP-388', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77829c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(650, 29, 1, 'After the Prophet', 'Novel Book', 'IMP-387', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77838a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(651, 29, 1, 'The Ichigo Ichie', 'Novel Book', 'IMP-386', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77847c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(652, 29, 1, 'The Nine Lives of Pakistan', 'Novel Book', 'IMP-385', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df778569646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(653, 29, 1, '100M Offers', 'Novel Book', 'IMP-384', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 110.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77865c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(654, 29, 1, 'Stillness Is the Key', 'Novel Book', 'IMP-383', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df778747646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(655, 29, 1, 'Mind Management, Not Time Management', 'Novel Book', 'IMP-382', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df778839646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(656, 29, 1, 'A Case of Exploding Mangoes', 'Novel Book', 'IMP-381', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df778926646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(657, 29, 1, 'Sensitive', 'Novel Book', 'IMP-380', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df778a10646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(658, 29, 1, 'I Kissed Shara Wheeler', 'Novel Book', 'IMP-379', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df778afe646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(659, 29, 1, 'Do Epic Shitt', 'Novel Book', 'IMP-378', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df778be8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(660, 29, 1, 'God of Ruin', 'Novel Book', 'IMP-377', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df778cd1646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(661, 29, 1, 'The 48 Laws of Power BIG', 'Novel Book', 'IMP-376', 'product', 'product', NULL, 0, NULL, 0, NULL, 1500.00, 0.00, 0.00, 0.00, 750.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df778db8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(662, 29, 1, 'Ikigai (Hard Cover)', 'Novel Book', 'IMP-375', 'product', 'product', NULL, 0, NULL, 0, NULL, 1000.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df778ea3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(663, 29, 1, 'If We Were Villains', 'Novel Book', 'IMP-374', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df778f8f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(664, 29, 1, 'Give and Take', 'Novel Book', 'IMP-373', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77907e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(665, 29, 1, 'The Teacher (Freida)', 'Novel Book', 'IMP-372', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df779164646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(666, 29, 1, 'The Hunger Games', 'Novel Book', 'IMP-371', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77924f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(667, 29, 1, 'Mockingjay', 'Novel Book', 'IMP-370', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77933e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(668, 29, 1, 'Catching Fire', 'Novel Book', 'IMP-369', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df779427646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(669, 29, 1, 'White Fang', 'Novel Book', 'IMP-368', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df779514646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(670, 29, 1, 'Fake History', 'Novel Book', 'IMP-367', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df779608646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(671, 29, 1, 'Fake Herose', 'Novel Book', 'IMP-366', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 170.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7797da646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(672, 29, 1, 'Same as Ever', 'Novel Book', 'IMP-365', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77996c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(673, 29, 1, 'Aisha', 'Novel Book', 'IMP-364', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df779af4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(674, 29, 1, 'The Heart of the Q', 'Novel Book', 'IMP-363', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df779c80646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(675, 29, 1, 'Surah Yusuf', 'Novel Book', 'IMP-362', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df779dc3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(676, 29, 1, 'Love Stories from Quran', 'Novel Book', 'IMP-361', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df779f07646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(677, 29, 1, 'These Infinite Threads', 'Novel Book', 'IMP-360', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77a055646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(678, 29, 1, 'All This Twisted Glory', 'Novel Book', 'IMP-359', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77a1b2646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(679, 29, 1, 'If Only I Had Told Her', 'Novel Book', 'IMP-358', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77a2fb646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(680, 29, 1, 'Wheres Molly', 'Novel Book', 'IMP-356', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77a440646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(681, 29, 1, 'Surrounded by Psychopaths', 'Novel Book', 'IMP-355', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77a57f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(682, 29, 1, 'Better Than the Before', 'Novel Book', 'IMP-354', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77a6c0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(683, 29, 1, 'The Stationery Shop of Tehran', 'Novel Book', 'IMP-353', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77a80c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(684, 29, 1, 'The Prisoners Throne (Holly)', 'Novel Book', 'IMP-352', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 170.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77a94b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(685, 29, 1, 'The Secret', 'Novel Book', 'IMP-351', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77aa8d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(686, 29, 1, 'The greatest minds and ideas of all time', 'Novel Book', 'IMP-350', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77abca646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(687, 29, 1, 'The Pleasures of Philosophy', 'Novel Book', 'IMP-349', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77ad0d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(688, 29, 1, 'Fallen Leaves', 'Novel Book', 'IMP-348', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 120.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77ae50646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(689, 29, 1, 'The Lessons of History', 'Novel Book', 'IMP-347', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77af8d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(690, 29, 1, 'Betting on You', 'Novel Book', 'IMP-346', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77b0cb646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(691, 29, 1, 'The Art of Laziness', 'Novel Book', 'IMP-345', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 80.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77b20c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(692, 29, 1, 'The Honeys', 'Novel Book', 'IMP-344', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77b34d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(693, 29, 1, 'Shadow and Bone', 'Novel Book', 'IMP-341', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77b438646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(694, 29, 1, 'A Passage to India', 'Novel Book', 'IMP-340', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77b51f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(695, 29, 1, 'How Successful People Think', 'Novel Book', 'IMP-339', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77b60b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(696, 29, 1, 'The 33 Strategies of War', 'Novel Book', 'IMP-338', 'product', 'product', NULL, 0, NULL, 0, NULL, 500.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77b6fa646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(697, 29, 1, 'Ego Is the Enemy (A+)', 'Novel Book', 'IMP-337', 'product', 'product', NULL, 0, NULL, 0, NULL, 600.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77b7e4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(698, 29, 1, 'Deep Work', 'Novel Book', 'IMP-336', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 110.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77b8ce646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(699, 29, 1, 'Leadership: Six Studies in World Strategy(Soft Cover)', 'Novel Book', 'IMP-335', 'product', 'product', NULL, 0, NULL, 0, NULL, 1000.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77b9b5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(700, 29, 1, 'Homo Deus (A+)', 'Novel Book', 'IMP-334', 'product', 'product', NULL, 0, NULL, 0, NULL, 600.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77baa7646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(701, 29, 1, 'Brief Answers to the Big Questions', 'Novel Book', 'IMP-333', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77bb8e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(702, 29, 1, 'Benazir Bhutto (A+)', 'Novel Book', 'IMP-332', 'product', 'product', NULL, 0, NULL, 0, NULL, 800.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77bc78646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(703, 29, 1, 'Men Are from Mars, Women Are from Venus (A+)', 'Novel Book', 'IMP-331', 'product', 'product', NULL, 0, NULL, 0, NULL, 800.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77bdbb646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(704, 29, 1, 'The Brothers Hawthornes (A+)', 'Novel Book', 'IMP-330', 'product', 'product', NULL, 0, NULL, 0, NULL, 500.00, 0.00, 0.00, 0.00, 220.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77bf46646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(705, 29, 1, 'A Brief History of Time', 'Novel Book', 'IMP-329', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77c0bc646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(706, 29, 1, 'White nights (Fyodor)', 'Novel Book', 'IMP-328', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 80.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77c22c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(707, 29, 1, 'Still with you', 'Novel Book', 'IMP-327', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77c386646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(708, 29, 1, 'Fangirl Down', 'Novel Book', 'IMP-326', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77c47c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(709, 29, 1, 'Never Lie (Freida)', 'Novel Book', 'IMP-325', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77c566646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(710, 29, 1, 'Our Fault', 'Novel Book', 'IMP-324', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77c650646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(711, 29, 1, 'Wildfire', 'Novel Book', 'IMP-323', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 130.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77c737646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(712, 29, 1, 'Esio Trot  Roald Dahl', 'Novel Book', 'IMP-322', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77c81f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(713, 29, 1, 'The Complete Adventures of Charlie and Mr. Willy Wonka', 'Novel Book', 'IMP-321', 'product', 'product', NULL, 0, NULL, 0, NULL, 500.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77c90a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(714, 29, 1, 'Adventures of Robin Hood', 'Novel Book', 'IMP-320', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77c9f2646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(715, 29, 1, 'The Other Side of Truth', 'Novel Book', 'IMP-319', 'product', 'product', NULL, 0, NULL, 0, NULL, 500.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77cadd646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(716, 29, 1, 'The Jungle Book', 'Novel Book', 'IMP-318', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77cbc5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(717, 29, 1, 'Kidnapped', 'Novel Book', 'IMP-317', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77ccb5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(718, 29, 1, 'Pinocchio', 'Novel Book', 'IMP-316', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77d03b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(719, 29, 1, 'The Wonderful Wizard of Oz', 'Novel Book', 'IMP-315', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77d16f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(720, 29, 1, 'Wonder', 'Novel Book', 'IMP-314', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77d2a2646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(721, 29, 1, 'Robinson Crusoe', 'Novel Book', 'IMP-313', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77d3cd646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(722, 29, 1, 'A Wild Sheep Chase  Haruki Murakami', 'Novel Book', 'IMP-312', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77d4f8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(723, 29, 1, 'Hard Boiled Wonderland', 'Novel Book', 'IMP-311', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77d627646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(724, 29, 1, 'South of the Border West of the Sun  Haruki Murakami', 'Novel Book', 'IMP-310', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77d756646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(725, 29, 1, 'Sputnik Sweetheart  Haruki Murakami', 'Novel Book', 'IMP-309', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77db9a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(726, 29, 1, 'Funny Story', 'Novel Book', 'IMP-308', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77dcd8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(727, 29, 1, 'The Laws of Human Nature concise', 'Novel Book', 'IMP-307', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 110.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77de03646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(728, 29, 1, 'The Rule Book', 'Novel Book', 'IMP-306', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77df39646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(729, 29, 1, 'Behind The Net', 'Novel Book', 'IMP-305', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df77e078646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(730, 29, 1, 'The Little Friend', 'Novel Book', 'IMP-304', 'product', 'product', NULL, 0, NULL, 0, NULL, 500.00, 0.00, 0.00, 0.00, 225.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df78522c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(731, 29, 1, 'The Last Mughal', 'Novel Book', 'IMP-303', 'product', 'product', NULL, 0, NULL, 0, NULL, 600.00, 0.00, 0.00, 0.00, 250.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7853f8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(732, 29, 1, 'Birthday Kisses', 'Novel Book', 'IMP-302', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df785511646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(733, 29, 1, 'Good to Great', 'Novel Book', 'IMP-301', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 170.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df785609646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(734, 29, 1, 'Will Grayson, Will Grayson', 'Novel Book', 'IMP-300', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7856f5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(735, 29, 1, 'The Revenge of Geography', 'Novel Book', 'IMP-299', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 130.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7857eb646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(736, 29, 1, 'The Familiar', 'Novel Book', 'IMP-298', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df785c60646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(737, 29, 1, 'You Can Be the Happiest Woman in the World', 'Novel Book', 'IMP-297', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df785dd2646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(738, 29, 1, 'With the Heart in Mind', 'Novel Book', 'IMP-296', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df785ec3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(739, 29, 1, 'Maggie Moves On', 'Novel Book', 'IMP-295', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df785fa8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(740, 29, 1, 'The Wealth Money Can\'t Buy', 'Novel Book', 'IMP-294', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df786092646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(741, 29, 1, 'King of Sloth', 'Novel Book', 'IMP-293', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df78617f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(742, 29, 1, 'Charlie Love and Chelies', 'Novel Book', 'IMP-292', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 170.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7865c8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(743, 29, 1, 'To Love Jason Thorn', 'Novel Book', 'IMP-291', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7866ae646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(744, 29, 1, 'The Power of Dua', 'Novel Book', 'IMP-290', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df78678c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(745, 29, 1, 'Surrounded by Vampires', 'Novel Book', 'IMP-289', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7868b9646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(746, 29, 1, 'Bridge to Terabithia', 'Novel Book', 'IMP-288', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df786ce9646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(747, 29, 1, 'The Lion Women of Tehran', 'Novel Book', 'IMP-287', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 170.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df786dc8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(748, 29, 1, 'The Life of the Q', 'Novel Book', 'IMP-286', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df786ea2646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(749, 29, 1, 'Dark Psychology', 'Novel Book', 'IMP-285', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df786f7c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(750, 29, 1, 'A Short History of the United States', 'Novel Book', 'IMP-284', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df787323646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(751, 29, 1, 'The Hardest Fall', 'Novel Book', 'IMP-282', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df787404646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(752, 29, 1, 'Good Bad Girl', 'Novel Book', 'IMP-281', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df78ecd2646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(753, 29, 1, 'Reckless (Robert)', 'Novel Book', 'IMP-280', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 225.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df78f42e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(754, 29, 1, 'The Enchanted Castle', 'Novel Book', 'IMP-279', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df78f5b3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(755, 29, 1, 'A Gentleman in Moscow', 'Novel Book', 'IMP-278', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df78f9eb646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(756, 29, 1, 'The Kingdom of God', 'Novel Book', 'IMP-277', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df78fb8d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(757, 29, 1, 'Always and Forever', 'Novel Book', 'IMP-276', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df790018646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(758, 29, 1, 'Surrounded by Narcissists', 'Novel Book', 'IMP-275', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df790414646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(759, 29, 1, 'Inglorious Empire', 'Novel Book', 'IMP-274', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 140.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df790812646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(760, 29, 1, 'Meet Me at the Lake', 'Novel Book', 'IMP-273', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 140.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df790c64646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(761, 29, 1, 'Powerless (Robert)', 'Novel Book', 'IMP-272', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 220.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df79113c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(762, 29, 1, 'Warmth', 'Novel Book', 'IMP-271', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df791efa646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(763, 29, 1, 'I Dont Love You Anymore', 'Novel Book', 'IMP-270', 'product', 'product', NULL, 0, NULL, 0, NULL, 200.00, 0.00, 0.00, 0.00, 105.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df79202f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(764, 29, 1, 'Husayn (Jalal)', 'Novel Book', 'IMP-269', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df792128646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(765, 29, 1, 'Steel Princess', 'Novel Book', 'IMP-268', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df79221e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(766, 29, 1, 'i hope this doesnt find you', 'Novel Book', 'IMP-267', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df79230d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(767, 29, 1, 'Love Unwritten', 'Novel Book', 'IMP-266', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7923fd646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(768, 29, 1, 'Such Charming Liars (Karen)', 'Novel Book', 'IMP-265', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7924ec646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(769, 29, 1, 'Talk To A..', 'Novel Book', 'IMP-264', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7925df646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(770, 29, 1, 'The Grandest Game (Jennifer)', 'Novel Book', 'IMP-263', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7926c6646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(771, 29, 1, 'There Are Rivers in the Sky', 'Novel Book', 'IMP-262', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7927b0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(772, 29, 1, 'Deviant King', 'Novel Book', 'IMP-261', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df79289c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(773, 29, 1, 'Daydream', 'Novel Book', 'IMP-260', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df792985646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(774, 29, 1, 'Surrounded by Bad Bosses', 'Novel Book', 'IMP-259', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df792b35646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(775, 29, 1, 'When Haru Was Here\nBook by Dustin Thao', 'Novel Book', 'IMP-258', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df792c36646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(776, 29, 1, 'Nothing Like the Movies', 'Novel Book', 'IMP-257', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df792d86646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(777, 29, 1, 'The Cold Heart', 'Novel Book', 'IMP-256', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 140.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df792e79646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(778, 29, 1, '50 Psychology Classics', 'Novel Book', 'IMP-255', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 140.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df792f61646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(779, 29, 1, '50 Politics Classics', 'Novel Book', 'IMP-254', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 140.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df793048646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO `items` (`id`, `client_id`, `branch_id`, `name`, `description`, `sku`, `type`, `item_nature`, `unit_name`, `has_variants`, `variant_details`, `has_expiry`, `expiry_date`, `price`, `wholesaler_price_1`, `wholesaler_price_2`, `wholesaler_price_3`, `cost`, `low_stock_alert`, `status`, `image`, `is_raw_item`, `track_inventory`, `stock_qty`, `category`, `kitchen_section`, `prep_time_minutes`, `is_restaurant_item`, `supplier_id`, `reorder_flag`, `uuid`, `device_id`, `sync_status`, `last_synced_at`, `deleted_at`, `created_at`, `updated_at`, `created_at_local`, `updated_at_local`) VALUES
(780, 29, 1, 'Timeless Seeds of Advice', 'Novel Book', 'IMP-253', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df79312d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(781, 29, 1, 'Good Vibes, Good Life', 'Novel Book', 'IMP-252', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 130.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df793213646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(782, 29, 1, 'The Great Mughals and Their India', 'Novel Book', 'IMP-251', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7932fe646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(783, 29, 1, 'The Flowers of Buffoonery', 'Novel Book', 'IMP-250', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7933e8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(784, 29, 1, 'The True Love Experiment', 'Novel Book', 'IMP-249', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7934d0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(785, 29, 1, 'Home Fire', 'Novel Book', 'IMP-248', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 130.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7935b8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(786, 29, 1, 'Kartography', 'Novel Book', 'IMP-247', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 140.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df79369f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(787, 29, 1, 'Our Lady of Alice Bhatti', 'Novel Book', 'IMP-246', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 130.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df793793646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(788, 29, 1, 'The Striker', 'Novel Book', 'IMP-245', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7938f0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(789, 29, 1, 'Ironside (Holly)', 'Novel Book', 'IMP-244', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df793a65646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(790, 29, 1, 'Wild Eyes', 'Novel Book', 'IMP-243', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 170.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df793ba1646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(791, 29, 1, 'Wild Love', 'Novel Book', 'IMP-242', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 170.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df793ce8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(792, 29, 1, 'Daring to Take Up Space', 'Novel Book', 'IMP-241', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df793e29646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(793, 29, 1, 'Games Untold (Jennifer)', 'Novel Book', 'IMP-240', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df793fa2646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(794, 29, 1, 'The Metamorphosis (Franz)', 'Novel Book', 'IMP-239', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7941c7646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(795, 29, 1, 'Killer Instinct (Jennifer)', 'Novel Book', 'IMP-238', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7943c5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(796, 29, 1, 'Bad Blood (Jennifer)', 'Novel Book', 'IMP-237', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df794534646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(797, 29, 1, 'All In (Jennifer)', 'Novel Book', 'IMP-236', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df794679646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(798, 29, 1, 'Notes from Underground (Fyodor)', 'Novel Book', 'IMP-235', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7947bc646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(799, 29, 1, 'Pakistan and American Diplomacy', 'Novel Book', 'IMP-234', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df794902646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(800, 29, 1, 'Atomic Habits (A+)', 'Novel Book', 'IMP-233', 'product', 'product', NULL, 0, NULL, 0, NULL, 500.00, 0.00, 0.00, 0.00, 220.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df794a3f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(801, 29, 1, 'Wretched', 'Novel Book', 'IMP-232', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 130.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df794b7f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(802, 29, 1, 'Spare', 'Novel Book', 'IMP-231', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df794cbf646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(803, 29, 1, 'The Leaders Mind', 'Novel Book', 'IMP-230', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df794dfd646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(804, 29, 1, 'The Art of Not Overthinking', 'Novel Book', 'IMP-229', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 80.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df794f3c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(805, 29, 1, 'The Syndicater (Runyx)', 'Novel Book', 'IMP-228', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df795083646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(806, 29, 1, 'Spectacular (Caraval)', 'Novel Book', 'IMP-227', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df79516f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(807, 29, 1, 'Unite Me (Mafi)', 'Novel Book', 'IMP-226', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df795257646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(808, 29, 1, 'Surrounded by Setbacks', 'Novel Book', 'IMP-225', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df79533e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(809, 29, 1, 'Dont Sweat the Small Stuff', 'Novel Book', 'IMP-224', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df795424646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(810, 29, 1, 'The Enormous Crocodile', 'Novel Book', 'IMP-223', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 220.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df795507646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(811, 29, 1, 'Hooked', 'Novel Book', 'IMP-222', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 130.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7955ec646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(812, 29, 1, 'Matilda New', 'Novel Book', 'IMP-221', 'product', 'product', NULL, 0, NULL, 0, NULL, 500.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7956d1646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(813, 29, 1, 'Anne of Green Gables', 'Novel Book', 'IMP-220', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7957b6646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(814, 29, 1, 'Happily Ever After', 'Novel Book', 'IMP-219', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df795899646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(815, 29, 1, 'A Christmas Carol Story by Charles Dickens', 'Novel Book', 'IMP-218', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df795982646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(816, 29, 1, 'My Family and Other Animals Book by Gerald Durrell', 'Novel Book', 'IMP-217', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df795a68646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(817, 29, 1, 'Reckless (Silver)', 'Novel Book', 'IMP-216', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df795b50646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(818, 29, 1, 'Hot Mess', 'Novel Book', 'IMP-215', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df795c34646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(819, 29, 1, 'The Natural (Jennifer)', 'Novel Book', 'IMP-214', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df795d14646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(820, 29, 1, 'The Sirah of the Prophet (pbuh)', 'Novel Book', 'IMP-213', 'product', 'product', NULL, 0, NULL, 0, NULL, 500.00, 0.00, 0.00, 0.00, 225.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df795df8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(821, 29, 1, 'Women in Islam', 'Novel Book', 'IMP-212', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df795ee2646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(822, 29, 1, 'You Re Too Good To FeeL This  Bad', 'Novel Book', 'IMP-211', 'product', 'product', NULL, 0, NULL, 0, NULL, 200.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df795fc7646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(823, 29, 1, 'Meatless days', 'Novel Book', 'IMP-210', 'product', 'product', NULL, 0, NULL, 0, NULL, 200.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7960b3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(824, 29, 1, 'The Runaways', 'Novel Book', 'IMP-209', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df796199646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(825, 29, 1, 'Muqaddimah Book', 'Novel Book', 'IMP-208', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df79627d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(826, 29, 1, 'On the Genealogy of Morals', 'Novel Book', 'IMP-207', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df796360646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(827, 29, 1, 'The Mother', 'Novel Book', 'IMP-206', 'product', 'product', NULL, 0, NULL, 0, NULL, 500.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df796444646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(828, 29, 1, 'The Couple Next Door', 'Novel Book', 'IMP-205', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df796529646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(829, 29, 1, 'The Art of Seduction (A+)', 'Novel Book', 'IMP-204', 'product', 'product', NULL, 0, NULL, 0, NULL, 1200.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df79660f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(830, 29, 1, 'The Laws of Human Nature (A+)', 'Novel Book', 'IMP-203', 'product', 'product', NULL, 0, NULL, 0, NULL, 1200.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7966f6646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(831, 29, 1, 'The Battle of Belonging (original)', 'Novel Book', 'IMP-202', 'product', 'product', NULL, 0, NULL, 0, NULL, 500.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7967e1646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(832, 29, 1, 'Nexus (Noah)', 'Novel Book', 'IMP-201', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7968c8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(833, 29, 1, 'The Power of your subconscious mind (A+)', 'Novel Book', 'IMP-200', 'product', 'product', NULL, 0, NULL, 0, NULL, 700.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7969af646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(834, 29, 1, 'Surrounded by Psychopaths (A+)', 'Novel Book', 'IMP-199', 'product', 'product', NULL, 0, NULL, 0, NULL, 700.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df796a96646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(835, 29, 1, 'The Lord of the Rings (original)', 'Novel Book', 'IMP-198', 'product', 'product', NULL, 0, NULL, 0, NULL, 2500.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df796c2b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(836, 29, 1, 'The Golden Road', 'Novel Book', 'IMP-197', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df796dbf646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(837, 29, 1, 'Khadija', 'Novel Book', 'IMP-196', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df796f0a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(838, 29, 1, 'Ikigai Journey', 'Novel Book', 'IMP-195', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df796ffa646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(839, 29, 1, 'The Housemaid\'s Wedding (Freida)', 'Novel Book', 'IMP-194', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7970e3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(840, 29, 1, 'The Blue Hour', 'Novel Book', 'IMP-193', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df79748f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(841, 29, 1, 'The Magic of Creative Living', 'Novel Book', 'IMP-192', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df797580646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(842, 29, 1, 'Fatima (Jalal)', 'Novel Book', 'IMP-191', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df797664646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(843, 29, 1, 'Onyx Storm (Yarros)', 'Novel Book', 'IMP-190', 'product', 'product', NULL, 0, NULL, 0, NULL, 500.00, 0.00, 0.00, 0.00, 300.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df79774c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(844, 29, 1, 'The Stranger', 'Novel Book', 'IMP-189', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 105.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df797836646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(845, 29, 1, 'Master Your Mindset', 'Novel Book', 'IMP-188', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df797962646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(846, 29, 1, 'How to Read a Book', 'Novel Book', 'IMP-187', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df797a50646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(847, 29, 1, 'Dear Debbie (Freida)', 'Novel Book', 'IMP-186', 'product', 'product', NULL, 0, NULL, 0, NULL, 500.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df797b2f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(848, 29, 1, 'The Art of Seduction', 'Novel Book', 'IMP-185', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 220.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df797c0f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(849, 29, 1, 'Ali (Jalal)', 'Novel Book', 'IMP-184', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df797ceb646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(850, 29, 1, 'Powerful (Robert)', 'Novel Book', 'IMP-183', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df797dc5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(851, 29, 1, 'Reckless A+', 'Novel Book', 'IMP-182', 'product', 'product', NULL, 0, NULL, 0, NULL, 700.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df797e9d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(852, 29, 1, 'The Power of Habit (A+)', 'Novel Book', 'IMP-181', 'product', 'product', NULL, 0, NULL, 0, NULL, 700.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df798245646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(853, 29, 1, 'The 4-Hour Workweek', 'Novel Book', 'IMP-180', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7983ac646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(854, 29, 1, 'The Rules of Love (A+)', 'Novel Book', 'IMP-179', 'product', 'product', NULL, 0, NULL, 0, NULL, 700.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df798496646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(855, 29, 1, 'The 7 Habits of Highly Effective People (A+)', 'Novel Book', 'IMP-178', 'product', 'product', NULL, 0, NULL, 0, NULL, 900.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df798577646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(856, 29, 1, 'Ugly Love A+', 'Novel Book', 'IMP-177', 'product', 'product', NULL, 0, NULL, 0, NULL, 700.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df798665646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(857, 29, 1, 'The Zen Monkey and the Lotus Flower', 'Novel Book', 'IMP-176', 'product', 'product', NULL, 0, NULL, 0, NULL, 600.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df798a8b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(858, 29, 1, 'The Trial (Franz)', 'Novel Book', 'IMP-175', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df798b6c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(859, 29, 1, 'Letter To The Father (Franz)', 'Novel Book', 'IMP-174', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df798c45646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(860, 29, 1, 'Can We Be Strangers Again?', 'Novel Book', 'IMP-173', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 105.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df798d1e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(861, 29, 1, 'God of War', 'Novel Book', 'IMP-172', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df799108646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(862, 29, 1, 'Phantom', 'Novel Book', 'IMP-171', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7991ec646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(863, 29, 1, 'Normal People', 'Novel Book', 'IMP-170', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7992cb646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(864, 29, 1, 'The Guest List', 'Novel Book', 'IMP-169', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7993b3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(865, 29, 1, 'Surrounded by Liars', 'Novel Book', 'IMP-168', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a2850646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(866, 29, 1, 'Rumi\'s Secret', 'Novel Book', 'IMP-167', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a2bd9646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(867, 29, 1, 'The Book of Major Sins', 'Novel Book', 'IMP-166', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a2e36646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(868, 29, 1, 'The Reconstruction of Religious Thought in Islam Book by Muhammad Iqbal hard cover', 'Novel Book', 'IMP-165', 'product', 'product', NULL, 0, NULL, 0, NULL, 600.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a3464646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(869, 29, 1, 'Unrequited feelings', 'Novel Book', 'IMP-164', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a3660646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(870, 29, 1, 'The Millionaire Fastlane', 'Novel Book', 'IMP-163', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a3b8a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(871, 29, 1, 'How to Develop Self Confidence and influence people by public speaking', 'Novel Book', 'IMP-162', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a3e08646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(872, 29, 1, '50 Prosperity Classics', 'Novel Book', 'IMP-161', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a42eb646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(873, 29, 1, 'The Book of Why', 'Novel Book', 'IMP-160', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a4747646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(874, 29, 1, 'The NO-BS Self-Help Book', 'Novel Book', 'IMP-159', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a4dfa646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(875, 29, 1, 'The Trials of Apollo', 'Novel Book', 'IMP-158', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a530c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(876, 29, 1, 'The Tower of Nero', 'Novel Book', 'IMP-157', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a7465646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(877, 29, 1, 'The Burning Maze', 'Novel Book', 'IMP-156', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a77e3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(878, 29, 1, 'Camp Jupiter Classified', 'Novel Book', 'IMP-155', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 90.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a7a06646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(879, 29, 1, 'Famous Five 1-10', 'Novel Book', 'IMP-154', 'product', 'product', NULL, 0, NULL, 0, NULL, 3000.00, 0.00, 0.00, 0.00, 1600.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a7b4b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(880, 29, 1, 'Enid Blyton Star Read Box', 'Novel Book', 'IMP-153', 'product', 'product', NULL, 0, NULL, 0, NULL, 2800.00, 0.00, 0.00, 0.00, 1600.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a7c56646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(881, 29, 1, 'Double Down', 'Novel Book', 'IMP-152', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a7d55646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(882, 29, 1, 'The Adventures of Tom Sawyer', 'Novel Book', 'IMP-151', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a7e4a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(883, 29, 1, 'Ice Candy Man', 'Novel Book', 'IMP-150', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a7f43646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(884, 29, 1, 'Jumbo Puzzle Book 2 for kids', 'Novel Book', 'IMP-149', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a8032646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(885, 29, 1, 'Unravel Me (Mafi)', 'Novel Book', 'IMP-148', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a8123646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(886, 29, 1, 'Trading in the Zone', 'Novel Book', 'IMP-147', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a8211646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(887, 29, 1, 'The Power of Silence (A+)', 'Novel Book', 'IMP-146', 'product', 'product', NULL, 0, NULL, 0, NULL, 700.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a8303646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(888, 29, 1, 'Think Like a Monk (A+)', 'Novel Book', 'IMP-145', 'product', 'product', NULL, 0, NULL, 0, NULL, 800.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a83f3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(889, 29, 1, 'Five Survive (A+)', 'Novel Book', 'IMP-144', 'product', 'product', NULL, 0, NULL, 0, NULL, 800.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a84e2646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(890, 29, 1, 'Money Works (A+)', 'Novel Book', 'IMP-143', 'product', 'product', NULL, 0, NULL, 0, NULL, 800.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a85cb646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(891, 29, 1, 'The Final Gambit (Jennifer)', 'Novel Book', 'IMP-142', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a86b3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(892, 29, 1, 'The Inheritance Games (Jennifer)', 'Novel Book', 'IMP-141', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a879f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(893, 29, 1, 'The Housemaid Is Watching (Freida)', 'Novel Book', 'IMP-140', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a888f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(894, 29, 1, 'The Hawthorne Legacy (Jennifer)', 'Novel Book', 'IMP-139', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a897c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(895, 29, 1, 'Gandhi An Autobiography (A+)', 'Novel Book', 'IMP-138', 'product', 'product', NULL, 0, NULL, 0, NULL, 1000.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a8a66646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(896, 29, 1, 'Freedom at Midnight', 'Novel Book', 'IMP-137', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a8b51646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(897, 29, 1, 'Believe Me (Mafi)', 'Novel Book', 'IMP-136', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a8c39646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(898, 29, 1, 'The Israel Lobby and U.S. Foreign Policy', 'Novel Book', 'IMP-135', 'product', 'product', NULL, 0, NULL, 0, NULL, 500.00, 0.00, 0.00, 0.00, 220.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a8d2c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(899, 29, 1, 'The Republic (A+)', 'Novel Book', 'IMP-134', 'product', 'product', NULL, 0, NULL, 0, NULL, 700.00, 0.00, 0.00, 0.00, 225.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a8e16646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(900, 29, 1, '10 Minutes 38 Seconds in This Strange World', 'Novel Book', 'IMP-133', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a8efe646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(901, 29, 1, 'Trek to Pakistan', 'Novel Book', 'IMP-132', 'product', 'product', NULL, 0, NULL, 0, NULL, 650.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a8fee646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(902, 29, 1, 'The Murder Of History', 'Novel Book', 'IMP-131', 'product', 'product', NULL, 0, NULL, 0, NULL, 1200.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a90d7646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(903, 29, 1, 'The Way of the Superior Man', 'Novel Book', 'IMP-130', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a91d6646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(904, 29, 1, 'Too Good To Be True', 'Novel Book', 'IMP-129', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a936a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(905, 29, 1, 'Thank You for Leaving', 'Novel Book', 'IMP-128', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a9500646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(906, 29, 1, 'The Four Agreements', 'Novel Book', 'IMP-127', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a9645646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(907, 29, 1, 'Make Epic Money', 'Novel Book', 'IMP-126', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a9791646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(908, 29, 1, 'A muslim woman\'s diary', 'Novel Book', 'IMP-125', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a98de646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(909, 29, 1, 'Crisis of Islamic Civilization', 'Novel Book', 'IMP-124', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a9a2b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(910, 29, 1, 'Dark Psychology Secret2021', 'Novel Book', 'IMP-123', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a9b7a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(911, 29, 1, 'Ramadan Reflections', 'Novel Book', 'IMP-122', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a9cc3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(912, 29, 1, '100M Leads', 'Novel Book', 'IMP-121', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 110.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7a9ea4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(913, 29, 1, 'Marriage for One', 'Novel Book', 'IMP-120', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7aa0ce646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(914, 29, 1, 'the Art of Letting Go', 'Novel Book', 'IMP-119', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 110.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7aa235646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(915, 29, 1, 'Watch Me (Mafi)', 'Novel Book', 'IMP-118', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7aa382646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(916, 29, 1, 'Percy Jackson Wrath of the Triple Goddess', 'Novel Book', 'IMP-117', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 250.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7aa4cc646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(917, 29, 1, 'Percy Jackson And the Last Olympians', 'Novel Book', 'IMP-116', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 250.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7aa61a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(918, 29, 1, 'Percy Jackson And The Titans Curse', 'Novel Book', 'IMP-115', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 250.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7aa761646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(919, 29, 1, 'Nehru', 'Novel Book', 'IMP-114', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7aa8aa646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(920, 29, 1, 'Siddhartha', 'Novel Book', 'IMP-113', 'product', 'product', NULL, 0, NULL, 0, NULL, 200.00, 0.00, 0.00, 0.00, 110.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7aa9f0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(921, 29, 1, 'Eragon', 'Novel Book', 'IMP-112', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7aab38646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(922, 29, 1, 'The Idiot (Fyodor)', 'Novel Book', 'IMP-111', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7aac79646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(923, 29, 1, 'The Art of War (Black)', 'Novel Book', 'IMP-110', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7aadb9646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(924, 29, 1, 'I Am Not Jessica Chen', 'Novel Book', 'IMP-109', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7aaf01646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(925, 29, 1, 'The Survivor Wants to Die at the End', 'Novel Book', 'IMP-108', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7aaff5646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(926, 29, 1, 'The Wish Switch', 'Novel Book', 'IMP-107', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ab0e2646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(927, 29, 1, 'The Leader in You', 'Novel Book', 'IMP-106', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 125.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ab1cf646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(928, 29, 1, 'The Queens Rising', 'Novel Book', 'IMP-105', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ab2b7646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(929, 29, 1, 'The 10X Rule', 'Novel Book', 'IMP-104', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ab3a1646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(930, 29, 1, 'Keep Going', 'Novel Book', 'IMP-103', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ab48b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(931, 29, 1, 'The Lovely and the Lost (Jennifer)', 'Novel Book', 'IMP-102', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ab572646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(932, 29, 1, 'Twelve', 'Novel Book', 'IMP-101', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ab660646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(933, 29, 1, 'Little White Lies (Jennifer)', 'Novel Book', 'IMP-100', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ab749646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(934, 29, 1, 'Deadly Little Scandals (Jennifer)', 'Novel Book', 'IMP-99', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ab836646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(935, 29, 1, 'The Jew of Malta', 'Novel Book', 'IMP-98', 'product', 'product', NULL, 0, NULL, 0, NULL, 500.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ab925646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(936, 29, 1, 'The Boy Who Harnessed the Wind', 'Novel Book', 'IMP-97', 'product', 'product', NULL, 0, NULL, 0, NULL, 500.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7aba0f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(937, 29, 1, 'The Quick & Easy Way to Effective Speaking', 'Novel Book', 'IMP-96', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7abafb646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(938, 29, 1, 'Journey to the Center of the Earth', 'Novel Book', 'IMP-95', 'product', 'product', NULL, 0, NULL, 0, NULL, 500.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7abbe2646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(939, 29, 1, 'The 100 Rules of Business', 'Novel Book', 'IMP-94', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 110.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7abcc9646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(940, 29, 1, 'Lives of the Stoics', 'Novel Book', 'IMP-93', 'product', 'product', NULL, 0, NULL, 0, NULL, 200.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7abdb0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(941, 29, 1, 'Technical Analysis of the Financial Markets', 'Novel Book', 'IMP-92', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7abe9b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(942, 29, 1, 'The Champion\'s Mind', 'Novel Book', 'IMP-91', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7abfbc646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(943, 29, 1, 'The Field of Blood', 'Novel Book', 'IMP-90', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ac0a7646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(944, 29, 1, 'Letters to Milena (Franz)', 'Novel Book', 'IMP-89', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 170.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ac18f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(945, 29, 1, 'The Castle (Franz)', 'Novel Book', 'IMP-88', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ac27b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(946, 29, 1, 'Art Of Being Alone', 'Novel Book', 'IMP-87', 'product', 'product', NULL, 0, NULL, 0, NULL, 200.00, 0.00, 0.00, 0.00, 80.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ac363646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(947, 29, 1, 'Enigma', 'Novel Book', 'IMP-86', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ac44a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(948, 29, 1, 'Glorious Rivals (Jennifer)', 'Novel Book', 'IMP-85', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ac52f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(949, 29, 1, 'Happy Days! Box Set 8 books', 'Novel Book', 'IMP-84', 'product', 'product', NULL, 0, NULL, 0, NULL, 1600.00, 0.00, 0.00, 0.00, 800.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ac61b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(950, 29, 1, 'The Prince (A+)', 'Novel Book', 'IMP-83', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ac704646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(951, 29, 1, 'Mastery', 'Novel Book', 'IMP-82', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ac7ee646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(952, 29, 1, 'The Curse Of Letting Go', 'Novel Book', 'IMP-81', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ac8d6646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL);
INSERT INTO `items` (`id`, `client_id`, `branch_id`, `name`, `description`, `sku`, `type`, `item_nature`, `unit_name`, `has_variants`, `variant_details`, `has_expiry`, `expiry_date`, `price`, `wholesaler_price_1`, `wholesaler_price_2`, `wholesaler_price_3`, `cost`, `low_stock_alert`, `status`, `image`, `is_raw_item`, `track_inventory`, `stock_qty`, `category`, `kitchen_section`, `prep_time_minutes`, `is_restaurant_item`, `supplier_id`, `reorder_flag`, `uuid`, `device_id`, `sync_status`, `last_synced_at`, `deleted_at`, `created_at`, `updated_at`, `created_at_local`, `updated_at_local`) VALUES
(953, 29, 1, 'World Order', 'Novel Book', 'IMP-80', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ac9c0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(954, 29, 1, 'King of Envy', 'Novel Book', 'IMP-79', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 220.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7acaa7646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(955, 29, 1, '100 Money Models', 'Novel Book', 'IMP-78', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7acb93646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(956, 29, 1, 'Elon Musk (A+)', 'Novel Book', 'IMP-77', 'product', 'product', NULL, 0, NULL, 0, NULL, 1000.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7acc7c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(957, 29, 1, 'A History of Western Philosophy', 'Novel Book', 'IMP-76', 'product', 'product', NULL, 0, NULL, 0, NULL, 650.00, 0.00, 0.00, 0.00, 370.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7acfea646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(958, 29, 1, 'What is Sufism ? (A+)', 'Novel Book', 'IMP-75', 'product', 'product', NULL, 0, NULL, 0, NULL, 700.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ad158646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(959, 29, 1, 'The Nicomachean Ethics (A+)', 'Novel Book', 'IMP-74', 'product', 'product', NULL, 0, NULL, 0, NULL, 800.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ad298646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(960, 29, 1, 'Punk 57', 'Novel Book', 'IMP-73', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ad39c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(961, 29, 1, 'Brain Rules (A+)', 'Novel Book', 'IMP-72', 'product', 'product', NULL, 0, NULL, 0, NULL, 800.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ad488646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(962, 29, 1, 'Mindset', 'Novel Book', 'IMP-71', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ad566646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(963, 29, 1, 'Meditations', 'Novel Book', 'IMP-70', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ad640646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(964, 29, 1, 'Mans Search for Meaning', 'Novel Book', 'IMP-69', 'product', 'product', NULL, 0, NULL, 0, NULL, 200.00, 0.00, 0.00, 0.00, 80.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ad71d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(965, 29, 1, 'The Reappearance of Rachel Price (Jackson)', 'Novel Book', 'IMP-68', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ad7fa646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(966, 29, 1, 'Good Girl Bad Blood (Jackson)', 'Novel Book', 'IMP-66', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ad989646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(967, 29, 1, 'Sunrise on the Reaping (A+)', 'Novel Book', 'IMP-65', 'product', 'product', NULL, 0, NULL, 0, NULL, 800.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ada82646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(968, 29, 1, 'I Cannot Say Goodbye To You', 'Novel Book', 'IMP-64', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7adb65646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(969, 29, 1, 'Wuthering Heights', 'Novel Book', 'IMP-63', 'product', 'product', NULL, 0, NULL, 0, NULL, 550.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7adc50646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(970, 29, 1, 'Power and Progress (A+)', 'Novel Book', 'IMP-61', 'product', 'product', NULL, 0, NULL, 0, NULL, 950.00, 0.00, 0.00, 0.00, 1.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7add3a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(971, 29, 1, 'The Communist Manifesto (A+)', 'Novel Book', 'IMP-60', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ade25646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(972, 29, 1, 'As Long as the Lemon Tree', 'Novel Book', 'IMP-59', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 110.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7adf10646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(973, 29, 1, 'The Pashtuns', 'Novel Book', 'IMP-58', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 250.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7adffd646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(974, 29, 1, 'The Story of Philosophy', 'Novel Book', 'IMP-57', 'product', 'product', NULL, 0, NULL, 0, NULL, 500.00, 0.00, 0.00, 0.00, 225.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ae0ea646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(975, 29, 1, 'To Kill a Mockingbird', 'Novel Book', 'IMP-56', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 240.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ae1d3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(976, 29, 1, 'Brain Games', 'Novel Book', 'IMP-55', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ae2be646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(977, 29, 1, 'Before the Coffee Gets Cold (Toshi)', 'Novel Book', 'IMP-53', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ae3a6646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(978, 29, 1, 'Tales from the Caf?', 'Novel Book', 'IMP-52', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 135.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ae492646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(979, 29, 1, 'Before We Forget Kindness (Toshi)', 'Novel Book', 'IMP-51', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ae57c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(980, 29, 1, 'The Brothers Karamazov (Fyodor)', 'Novel Book', 'IMP-50', 'product', 'product', NULL, 0, NULL, 0, NULL, 650.00, 0.00, 0.00, 0.00, 400.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ae664646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(981, 29, 1, 'How to Read the Quran (A+)', 'Novel Book', 'IMP-49', 'product', 'product', NULL, 0, NULL, 0, NULL, 550.00, 0.00, 0.00, 0.00, 325.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ae753646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(982, 29, 1, 'Twisted Hate', 'Novel Book', 'IMP-48', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 170.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ae8e0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(983, 29, 1, 'Diplomacy', 'Novel Book', 'IMP-47', 'product', 'product', NULL, 0, NULL, 0, NULL, 500.00, 0.00, 0.00, 0.00, 150.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7ae9d0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(984, 29, 1, 'Powerless (Silver)', 'Novel Book', 'IMP-45', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7aeabc646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(985, 29, 1, 'Fearless (Robert)', 'Novel Book', 'IMP-44', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 220.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7aeba4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(986, 29, 1, 'The Politics (A+)', 'Novel Book', 'IMP-40', 'product', 'product', NULL, 0, NULL, 0, NULL, 700.00, 0.00, 0.00, 0.00, 400.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7aec89646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(987, 29, 1, 'Right Thing, Right Now (A+)', 'Novel Book', 'IMP-39', 'product', 'product', NULL, 0, NULL, 0, NULL, 700.00, 0.00, 0.00, 0.00, 400.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7aed6e646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(988, 29, 1, 'Surrounded by Idiots', 'Novel Book', 'IMP-38', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7aee54646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(989, 29, 1, 'Twisted Love', 'Novel Book', 'IMP-37', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 170.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7af4c8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(990, 29, 1, 'Doctor on Duty Treatment Guide', 'Novel Book', 'IMP-35', 'product', 'product', NULL, 0, NULL, 0, NULL, 1800.00, 0.00, 0.00, 0.00, 1200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7af5dd646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(991, 29, 1, 'The Boyfriend (Freida)', 'Novel Book', 'IMP-34', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7af6c8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(992, 29, 1, 'The Perfect Son (Freida)', 'Novel Book', 'IMP-33', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7af7b9646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(993, 29, 1, 'The Tenant (Freida)', 'Novel Book', 'IMP-32', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7af8a4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(994, 29, 1, 'Discipline Is Destiny (A+)', 'Novel Book', 'IMP-31', 'product', 'product', NULL, 0, NULL, 0, NULL, 800.00, 0.00, 0.00, 0.00, 375.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7af98c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(995, 29, 1, 'Never Split the Difference', 'Novel Book', 'IMP-30', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7afa77646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(996, 29, 1, 'Fearful (Robert)', 'Novel Book', 'IMP-29', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7afb62646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(997, 29, 1, 'The Ballad of Never After (Garber)', 'Novel Book', 'IMP-28', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 170.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7afc4c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(998, 29, 1, 'Q 30For30', 'Novel Book', 'IMP-26', 'product', 'product', NULL, 0, NULL, 0, NULL, 450.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7afd3a646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(999, 29, 1, 'Ward D (Freida)', 'Novel Book', 'IMP-25', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7afe24646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1000, 29, 1, 'Two Sides to Every Murder', 'Novel Book', 'IMP-24', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7affae646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1001, 29, 1, 'Not Quite Dead Yet (Jackson)', 'Novel Book', 'IMP-23', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7b00a1646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1002, 29, 1, 'Little Stranger', 'Novel Book', 'IMP-22', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7b018d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1003, 29, 1, 'Little Liar', 'Novel Book', 'IMP-21', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7b0276646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1004, 29, 1, 'The Secret  history', 'Novel Book', 'IMP-20', 'product', 'product', NULL, 0, NULL, 0, NULL, 500.00, 0.00, 0.00, 0.00, 250.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7b035f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1005, 29, 1, 'The Secret of Secrets', 'Novel Book', 'IMP-19', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 300.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7b0447646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1006, 29, 1, 'The Let Them Theory', 'Novel Book', 'IMP-18', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7b0533646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1007, 29, 1, 'Inside the Soul of Islam', 'Novel Book', 'IMP-17', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 110.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7b061b646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1008, 29, 1, 'The Vegetarian', 'Novel Book', 'IMP-16', 'product', 'product', NULL, 0, NULL, 0, NULL, 700.00, 0.00, 0.00, 0.00, 300.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7b0707646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1009, 29, 1, 'The Coworker (Freida)', 'Novel Book', 'IMP-15', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7b07f0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1010, 29, 1, 'Days at the Morisaki Bookshop', 'Novel Book', 'IMP-14', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7b08d9646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1011, 29, 1, 'The Art of Spending Money', 'Novel Book', 'IMP-13', 'product', 'product', NULL, 0, NULL, 0, NULL, 250.00, 0.00, 0.00, 0.00, 100.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7b09c4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1012, 29, 1, 'More Days at the Morisaki Bookshop', 'Novel Book', 'IMP-12', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7b0db9646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1013, 29, 1, 'Talking with Psychopaths and Savages', '', 'IMP-11', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 250.00, 229.99, 0.00, 130.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7b0eaf646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1014, 29, 1, 'Adaptive Markets', 'Novel Book', 'IMP-10', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 200.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7b0f94646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1015, 29, 1, 'Inside the Investor\'s Brain', 'Novel Book', 'IMP-9', 'product', 'product', NULL, 0, NULL, 0, NULL, 350.00, 0.00, 0.00, 0.00, 180.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7b111f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1016, 29, 1, 'The Locked Door (freida)', 'Novel Book', 'IMP-8', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7b122f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1017, 29, 1, 'Diary of a Wimpy Kid: Partypooper', 'Novel Book', 'IMP-7', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 160.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7b1337646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1018, 29, 1, 'Fi Amanillah', 'Novel Book', 'IMP-6', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 110.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, NULL, 0, 'df7b1440646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1019, 29, 1, 'The Sword of A...', 'Novel Book', 'IMP-5', 'product', 'product', NULL, 0, NULL, 0, NULL, 700.00, 0.00, 0.00, 0.00, 325.00, 0, 'active', '', 0, 1, 0.00, '', NULL, 0, 0, 9, 0, 'df7b1547646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1022, 29, 1, 'makeup', '', '11', 'product', 'product', NULL, 0, NULL, 0, NULL, 100.00, 70.00, 75.00, 0.00, 60.00, 10, 'active', '1778661307_2053.jpg', 0, 1, 0.00, NULL, NULL, 0, 0, NULL, 0, 'df7b1655646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1023, 29, 1, 'logo', NULL, '2', 'product', 'product', NULL, 0, NULL, 0, NULL, 2000.00, 1500.00, 1600.00, 0.00, 1000.00, 10, 'active', '1778676237_8297.png', 0, 1, 6.00, NULL, NULL, 0, 0, NULL, 0, 'df7b1762646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1026, 31, 1, 'shampoo', NULL, '', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 290.00, 10, 'active', '', 0, 1, 0.00, NULL, NULL, 0, 0, NULL, 0, 'df7b18e0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1027, 31, 1, 'Surf', NULL, '', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 0.00, 10, 'active', '', 0, 1, 0.00, NULL, NULL, 0, 0, NULL, 0, 'df7b1aa3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1028, 31, 1, 'detol', NULL, '', 'product', 'product', NULL, 0, NULL, 0, NULL, 399.00, 0.00, 0.00, 0.00, 290.00, 10, 'active', '', 0, 1, 0.00, NULL, NULL, 0, 0, NULL, 0, 'df7b1bca646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1029, 31, 1, 'oil', NULL, '', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 0.00, 10, 'active', '', 0, 1, 0.00, NULL, NULL, 0, 0, NULL, 0, 'df7b1cd0646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1030, 31, 1, 'Diaper', NULL, '', 'product', 'product', NULL, 0, NULL, 0, NULL, 300.00, 0.00, 0.00, 0.00, 0.00, 10, 'active', '', 0, 1, 0.00, NULL, NULL, 0, 0, NULL, 0, 'df7b1dcc646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1033, 29, 1, 'waterplant', NULL, '', 'product', 'product', NULL, 0, NULL, 0, NULL, 0.00, 0.00, 0.00, 0.00, 0.00, 10, 'active', '1778841679_3280.jpg', 0, 1, 0.00, NULL, NULL, 0, 0, NULL, 0, 'df7b1ecd646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1034, 29, 0, 'book', '', 'sku-001', 'product', 'product', NULL, 0, NULL, 0, NULL, 200.00, 190.00, 290.00, 300.00, 100.00, 10, 'active', 'https://karachibookshop.com/cdn/shop/files/514.png?v=1763454950&width=600', 0, 1, 3000.00, NULL, NULL, 0, 0, 9, 0, 'df7b1fd3646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1035, 23, 0, 'Basil seeds', '', 'Strawberry', 'product', 'product', NULL, 0, NULL, 0, NULL, 150.00, 130.00, 130.00, 130.00, 130.00, 10, 'active', 'item_1779189408_2394.jpg', 0, 1, 10.00, NULL, NULL, 0, 0, NULL, 0, 'df7b20e8646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1036, 27, 0, 'waterplant', '', 'sku-001', 'product', 'product', NULL, 0, NULL, 0, NULL, 0.00, 0.00, 0.00, 0.00, 0.00, 10, 'active', 'https://plus.unsplash.com/premium_photo-1664392147011-2a720f214e01?fm=jpg&q=60&w=3000&auto=format&fit=crop&ixlib=rb-4.1.0&ixid=M3wxMjA3fDB8MHxzZWFyY2h8MXx8cHJvZHVjdHxlbnwwfHwwfHx8MA%3D%3D', 0, 1, 0.00, NULL, NULL, 0, 0, NULL, 0, 'df7b21fa646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1037, 27, 0, 'CAMRA', '', 'sku-001', 'product', 'product', NULL, 0, NULL, 0, NULL, 200.00, 150.00, 189.00, 199.00, 100.00, 10, 'active', 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcQzSOrIHIncvVwcn86Yj1lG2no3rymRPhF1AQ&s', 0, 1, 0.00, NULL, NULL, 0, 0, NULL, 0, 'df7b2651646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1038, 31, 0, 'Olim 2 in 1 Shampoo + Conditioner', '', '25110', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 350.00, 0.00, 0.00, 250.00, 10, 'active', '', 0, 1, 100.00, NULL, NULL, 0, 0, NULL, 0, 'df7b2764646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1039, 31, 0, 'Olim 2 in 1 Shampoo + Conditioner', '', '25110', 'product', 'product', NULL, 0, NULL, 0, NULL, 400.00, 350.00, 0.00, 0.00, 250.00, 10, 'active', '', 0, 1, 100.00, NULL, NULL, 0, 0, NULL, 0, 'df7b286c646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1040, 29, 0, 'FIber glass', '', '', 'product', 'product', NULL, 0, NULL, 0, NULL, 900.00, 850.00, 800.00, 750.00, 700.00, 10, 'active', '', 0, 1, 0.00, NULL, NULL, 0, 0, NULL, 0, 'df7b2973646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1041, 29, 0, 'Laptop Scren for HP', '', '', 'product', 'product', NULL, 0, NULL, 0, NULL, 0.00, 1500.00, 1400.00, 1300.00, 1200.00, 10, 'active', '', 0, 1, 0.00, NULL, NULL, 0, 0, 9, 0, 'df7b2a7d646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1042, 32, 0, 'chicken steak', '', '001', 'menu', 'product', NULL, 1, NULL, 1, NULL, 1600.00, 0.00, 0.00, 0.00, 500.00, 10, 'active', 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcTsdi41IEXQS1f8ra3fegXpFwd0MzYvHJSwww&s', 0, 1, 49.00, NULL, NULL, 0, 0, NULL, 0, 'df7b2b87646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1043, 32, 0, 'chopped steak', '', '002', 'menu', 'product', NULL, 1, NULL, 1, NULL, 2500.00, 0.00, 0.00, 0.00, 1500.00, 10, 'active', 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcRMr-6DXhQ4sJGnzhCkPB4xWTX2iVp1Ebnwbg&s', 0, 1, 59.00, NULL, NULL, 0, 0, NULL, 0, 'df7b2c92646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1044, 29, 0, 'pepso', '', '', 'product', 'product', NULL, 0, NULL, 0, NULL, 240.00, 210.00, 220.00, 225.00, 200.00, 10, 'active', 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcQGtgD1zvgfrv2k_-g7SvN3SOsUA55eA-vS9Q&s', 0, 1, 60.00, NULL, NULL, 0, 0, 9, 0, 'df7b2d99646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1045, 29, 0, 'Women Pendant', '', 'book', 'product', 'product', NULL, 1, NULL, 1, NULL, 0.00, 0.00, 0.00, 0.00, 0.00, 10, 'active', '', 0, 1, 0.00, NULL, NULL, 0, 0, NULL, 0, 'df7b2ea4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1046, 29, 16, 'ice', '', '7', 'product', 'product', NULL, 1, 'red', 1, '2027-07-04', 100.00, 0.00, 0.00, 0.00, 49.96, 10, 'active', '', 0, 1, 3.00, NULL, NULL, 0, 0, NULL, 0, 'df7b2fb4646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1047, 32, 18, 'raita', '', '005', 'product', 'product', NULL, 0, '', 1, '2026-06-06', 50.00, 0.00, 0.00, 0.00, 30.00, 10, 'active', 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcTH1IpupxuD39fNBCTfLpCEuPCp8QfSybCFpgkiajuGzz5USfQUSyZy0ItGZqWugh6VWkSPn_zh41VAHqqTgEkTLKIT_-_heaPf370HGzg&s=10', 0, 1, 0.00, NULL, NULL, 0, 0, NULL, 0, 'df7b30ca646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1048, 32, 18, 'raita', '', '005', 'product', 'product', NULL, 0, '', 1, '2026-06-06', 50.00, 0.00, 0.00, 0.00, 30.00, 10, 'active', 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcTH1IpupxuD39fNBCTfLpCEuPCp8QfSybCFpgkiajuGzz5USfQUSyZy0ItGZqWugh6VWkSPn_zh41VAHqqTgEkTLKIT_-_heaPf370HGzg&s=10', 0, 1, 0.00, NULL, NULL, 0, 0, NULL, 0, 'df7b31e7646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1049, 32, 18, 'colddrink', '', '009', 'product', 'product', NULL, 0, '', 0, NULL, 100.00, 0.00, 0.00, 0.00, 60.00, 10, 'active', 'https://static.tossdown.com/images/56b8060d-dd59-4468-b589-0c1242c6200b.webp', 0, 1, 0.00, NULL, NULL, 0, 0, NULL, 0, 'df7b361f646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1050, 34, 0, 'Chiekn Karai', '', '1232', 'menu', 'product', NULL, 0, '', 0, NULL, 1600.00, 0.00, 0.00, 0.00, 1200.00, 10, 'active', '', 0, 0, 11.00, NULL, NULL, 0, 0, NULL, 0, 'df7b3732646d11f1ae7470b5e88d388e', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(1051, 29, 16, 'offline', '', '', 'product', 'product', NULL, 0, '', 0, NULL, 0.00, 0.00, 0.00, 0.00, 0.00, 10, 'active', '', 0, 1, 0.00, NULL, NULL, 0, 0, NULL, 0, NULL, NULL, 'pending', NULL, NULL, '2026-06-12 04:37:28', '2026-06-12 04:37:28', NULL, NULL),
(1052, 29, 16, 'offline1', '', '', 'product', 'product', NULL, 0, '', 0, NULL, 0.00, 0.00, 0.00, 0.00, 0.00, 10, 'active', '', 0, 1, 0.00, NULL, NULL, 0, 0, NULL, 0, 'cbdc8b1e07a986e79a79e3eeb7cff634', 'desktop_a2e45ba02a46972734bdebdb7f24b404', 'pending', NULL, NULL, '2026-06-12 05:37:14', '2026-06-12 05:37:14', '2026-06-12 05:37:14', '2026-06-12 05:37:14'),
(1053, 29, 16, 'offline32', '', '', 'product', 'product', NULL, 0, '', 0, NULL, 0.00, 0.00, 0.00, 0.00, 0.00, 10, 'active', '', 0, 1, 0.00, NULL, NULL, 0, 0, NULL, 0, '319f3521b2fcdbf892968aec00b9e2a5', 'desktop_a2e45ba02a46972734bdebdb7f24b404', 'pending', NULL, NULL, '2026-06-12 06:18:07', '2026-06-12 06:36:55', '2026-06-12 06:18:07', '2026-06-12 06:18:07');

-- --------------------------------------------------------

--
-- Table structure for table `item_barcodes`
--

CREATE TABLE `item_barcodes` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `variant_id` int(11) DEFAULT NULL,
  `barcode` varchar(100) NOT NULL,
  `barcode_image` varchar(255) DEFAULT NULL,
  `qty` int(11) NOT NULL DEFAULT 1,
  `barcode_type` varchar(50) NOT NULL DEFAULT 'Code128',
  `label_slug` varchar(150) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `item_barcodes`
--

INSERT INTO `item_barcodes` (`id`, `client_id`, `item_id`, `variant_id`, `barcode`, `barcode_image`, `qty`, `barcode_type`, `label_slug`, `created_at`) VALUES
(22, 20, 76, 0, '201415296821', 'barcode_201415296821.png', 1, 'Code128', '', '2026-05-06 14:15:29'),
(23, 27, 85, 0, '270731074259', 'barcode_270731074259.png', 1, 'UPC', '', '2026-05-11 07:31:07'),
(25, 29, 900, 0, '290820061712', 'barcode_290820061712.png', 1, 'EAN13', '', '2026-05-18 08:20:06'),
(26, 29, 653, 0, '290739076972', 'barcode_290739076972.png', 100, 'Code128', '', '2026-06-04 07:39:07');

-- --------------------------------------------------------

--
-- Table structure for table `item_batches`
--

CREATE TABLE `item_batches` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `batch_no` varchar(100) DEFAULT NULL,
  `branch` varchar(150) DEFAULT NULL,
  `supplier` varchar(150) DEFAULT NULL,
  `purchase_order_id` int(11) DEFAULT NULL,
  `mfg_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `qty` int(11) DEFAULT NULL,
  `reserved_qty` int(11) NOT NULL DEFAULT 0,
  `sold_qty` int(11) NOT NULL DEFAULT 0,
  `cost_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `sale_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `is_tracking_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `item_batches`
--

INSERT INTO `item_batches` (`id`, `client_id`, `item_id`, `batch_no`, `branch`, `supplier`, `purchase_order_id`, `mfg_date`, `expiry_date`, `qty`, `reserved_qty`, `sold_qty`, `cost_price`, `sale_price`, `notes`, `is_tracking_enabled`, `status`, `created_at`, `updated_at`) VALUES
(10, 29, 900, 'BTH-00001', 'AZ Distribution MAIN', '', 0, NULL, NULL, 5, 0, 0, 0.00, 0.00, '', 1, 'disabled', '2026-05-18 07:02:19', '2026-05-20 10:44:14');

-- --------------------------------------------------------

--
-- Table structure for table `item_price_tiers`
--

CREATE TABLE `item_price_tiers` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `tier_type` enum('wholesale','retailer') NOT NULL,
  `tier_no` int(11) NOT NULL DEFAULT 1,
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `item_price_tiers`
--

INSERT INTO `item_price_tiers` (`id`, `client_id`, `branch_id`, `item_id`, `tier_type`, `tier_no`, `price`, `cost`, `created_at`, `updated_at`, `uuid`, `device_id`, `sync_status`, `last_synced_at`, `deleted_at`, `created_at_local`, `updated_at_local`) VALUES
(3, 29, 1, 1013, 'wholesale', 1, 250.00, 0.00, '2026-05-13 10:29:26', '2026-06-10 01:44:08', 'df7d9c5b646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(4, 29, 1, 1013, 'wholesale', 2, 229.99, 0.00, '2026-05-13 10:29:26', '2026-06-10 01:44:08', 'df7d9fc7646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(9, 29, 1, 1022, 'wholesale', 1, 70.00, 0.00, '2026-05-13 11:19:37', '2026-06-10 01:44:08', 'df7da103646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(10, 29, 1, 1022, 'wholesale', 2, 75.00, 0.00, '2026-05-13 11:19:37', '2026-06-10 01:44:08', 'df7da1cb646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `item_recipes`
--

CREATE TABLE `item_recipes` (
  `id` int(11) NOT NULL,
  `product_item_id` int(11) NOT NULL,
  `raw_item_id` int(11) NOT NULL,
  `qty_required` decimal(10,3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `item_variants`
--

CREATE TABLE `item_variants` (
  `id` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `size` varchar(50) DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `item_variants`
--

INSERT INTO `item_variants` (`id`, `item_id`, `size`, `color`, `uuid`, `device_id`, `sync_status`, `last_synced_at`, `deleted_at`, `created_at_local`, `updated_at_local`) VALUES
(10, 1046, '5', 'red', 'df7cbe28646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `login_logs`
--

CREATE TABLE `login_logs` (
  `user_id` int(11) DEFAULT NULL,
  `ip` varchar(50) DEFAULT NULL,
  `device` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `login_logs`
--

INSERT INTO `login_logs` (`user_id`, `ip`, `device`, `created_at`) VALUES
(129, '2407:aa80:15:c5ce:ac65:5f6f:4930:d536', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 13:45:52'),
(43, '2407:aa80:15:c5ce:ac65:5f6f:4930:d536', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-05-06 13:51:22'),
(130, '2407:aa80:15:c5ce:ac65:5f6f:4930:d536', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 13:54:53'),
(43, '2407:aa80:15:c5ce:ac65:5f6f:4930:d536', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 13:58:46'),
(130, '2407:aa80:15:c5ce:ac65:5f6f:4930:d536', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-07 06:34:35'),
(43, '2407:aa80:15:c5ce:3c5f:2d52:fd4:b850', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-07 07:54:06'),
(130, '2407:aa80:15:c5ce:ac65:5f6f:4930:d536', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-05-07 07:55:53'),
(43, '2407:aa80:15:c5ce:ac65:5f6f:4930:d536', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-07 10:27:24'),
(43, '2407:aa80:15:c5ce:ac65:5f6f:4930:d536', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-07 10:36:25'),
(130, '2407:aa80:15:a9b:bd0e:b549:a816:7903', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-07 12:25:51'),
(130, '2407:aa80:15:a9b:5537:8d18:1f68:c5d1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-07 14:17:33'),
(130, '2407:aa80:15:656e:a939:fd9c:c2b9:2907', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-08 10:40:08'),
(43, '2407:aa80:15:656e:a939:fd9c:c2b9:2907', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-08 12:41:45'),
(134, '2407:aa80:15:656e:a939:fd9c:c2b9:2907', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-08 12:59:55'),
(130, '2407:aa80:15:656e:a939:fd9c:c2b9:2907', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-08 13:18:28'),
(43, '2407:aa80:15:656e:a939:fd9c:c2b9:2907', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-08 13:26:42'),
(43, '2407:aa80:15:656e:a939:fd9c:c2b9:2907', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-08 13:26:53'),
(135, '2407:aa80:15:656e:a939:fd9c:c2b9:2907', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-08 13:47:05'),
(134, '2407:aa80:15:656e:a939:fd9c:c2b9:2907', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-08 14:10:26'),
(137, '2407:aa80:15:656e:a939:fd9c:c2b9:2907', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-08 14:10:34'),
(135, '2407:aa80:15:656e:a939:fd9c:c2b9:2907', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-08 14:10:50'),
(136, '2407:aa80:15:656e:a939:fd9c:c2b9:2907', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-08 14:11:03'),
(133, '154.57.218.128', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 06:43:46'),
(130, '2407:aa80:15:656e:f051:c3df:ccac:626e', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 06:45:09'),
(135, '2407:aa80:15:656e:e130:f83:a851:3575', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 06:48:16'),
(135, '2407:aa80:15:656e:e130:f83:a851:3575', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 06:49:50'),
(43, '2407:aa80:15:656e:e130:f83:a851:3575', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 06:49:53'),
(43, '2407:aa80:15:656e:e130:f83:a851:3575', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 06:50:06'),
(43, '2407:aa80:15:656e:f051:c3df:ccac:626e', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 06:58:56'),
(135, '2407:aa80:15:656e:f051:c3df:ccac:626e', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 07:20:50'),
(43, '2407:aa80:15:656e:e130:f83:a851:3575', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 07:34:16'),
(43, '2407:aa80:15:656e:e130:f83:a851:3575', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 07:34:54'),
(135, '2407:aa80:15:656e:e130:f83:a851:3575', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 07:35:34'),
(130, '2407:aa80:15:656e:f051:c3df:ccac:626e', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 07:36:11'),
(138, '2407:aa80:15:656e:f051:c3df:ccac:626e', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 07:36:23'),
(133, '154.57.218.128', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 08:02:09'),
(133, '154.57.218.128', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 08:02:53'),
(133, '154.57.218.128', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 08:05:09'),
(133, '154.57.218.128', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 08:08:03'),
(133, '154.57.218.128', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 09:10:03'),
(133, '154.57.218.128', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 11:09:26'),
(138, '2407:aa80:15:9b4d:5a:2651:bd23:32a8', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-11 07:22:21'),
(138, '2407:aa80:15:9b4d:fd18:6bfb:1792:f86d', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.4 Safari/605.1.15', '2026-05-11 07:25:47'),
(138, '2407:aa80:15:9b4d:8911:2b09:2931:825a', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-11 07:33:08'),
(138, '2407:aa80:15:9b4d:8911:2b09:2931:825a', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-11 09:04:15'),
(138, '2407:aa80:15:9b4d:8169:3fff:719a:1255', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.4 Safari/605.1.15', '2026-05-11 09:10:48'),
(138, '2407:aa80:15:9b4d:8169:3fff:719a:1255', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.4 Safari/605.1.15', '2026-05-11 09:12:26'),
(138, '2407:aa80:15:9b4d:8169:3fff:719a:1255', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.4 Safari/605.1.15', '2026-05-11 09:13:44'),
(43, '2407:aa80:15:9b4d:642f:25c9:1f62:3815', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-11 10:06:11'),
(138, '2407:aa80:15:9b4d:642f:25c9:1f62:3815', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-11 10:31:30'),
(147, '2407:aa80:15:9b4d:642f:25c9:1f62:3815', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-11 10:56:25'),
(147, '2407:aa80:15:9b4d:642f:25c9:1f62:3815', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-11 11:25:18'),
(43, '2407:aa80:15:9b4d:642f:25c9:1f62:3815', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-11 14:05:21'),
(147, '2401:ba80:a34e:ae85:18ae:880f:346b:7ad3', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-11 14:53:48'),
(147, '2407:aa80:15:9b4d:642f:25c9:1f62:3815', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-11 14:56:32'),
(148, '2407:aa80:15:9b4d:642f:25c9:1f62:3815', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-11 14:56:45'),
(148, '2407:aa80:15:9b4d:642f:25c9:1f62:3815', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-11 14:58:50'),
(148, '2407:aa80:15:9b4d:642f:25c9:1f62:3815', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-11 14:58:59'),
(148, '2407:aa80:15:9b4d:642f:25c9:1f62:3815', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-11 15:00:40'),
(147, '2401:ba80:a34e:ae85:18ae:880f:346b:7ad3', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-11 16:22:17'),
(133, '2401:ba80:a34e:ae85:18ae:880f:346b:7ad3', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-11 16:22:22'),
(148, '182.190.128.64', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.4 Safari/605.1.15', '2026-05-11 18:38:55'),
(147, '2401:ba80:a34e:ae85:18ae:880f:346b:7ad3', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-11 19:23:30'),
(148, '182.190.128.64', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.4 Safari/605.1.15', '2026-05-11 20:23:44'),
(148, '101.53.235.148', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 06:38:14'),
(148, '2407:aa80:15:9b4d:642f:25c9:1f62:3815', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 06:40:06'),
(147, '2401:ba80:a390:8ee5:18ae:b9bc:62c3:2ee8', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-12 06:40:44'),
(43, '2407:aa80:15:9b4d:642f:25c9:1f62:3815', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 07:12:53'),
(148, '2407:aa80:15:9b4d:642f:25c9:1f62:3815', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 07:14:48'),
(147, '119.73.104.138', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.4 Safari/605.1.15', '2026-05-12 07:29:23'),
(43, '2407:aa80:15:9b4d:642f:25c9:1f62:3815', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 07:44:37'),
(148, '2407:aa80:15:9b4d:642f:25c9:1f62:3815', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 07:44:54'),
(148, '2407:aa80:15:9b4d:642f:25c9:1f62:3815', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 07:45:03'),
(147, '2407:aa80:15:9b4d:642f:25c9:1f62:3815', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 07:45:13'),
(148, '2407:aa80:15:9b4d:642f:25c9:1f62:3815', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 07:55:43'),
(148, '2407:aa80:15:9b4d:5cfb:bd58:46fe:e243', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 08:14:08'),
(43, '2407:aa80:15:9b4d:5cfb:bd58:46fe:e243', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 08:15:00'),
(148, '2407:aa80:15:9b4d:5cfb:bd58:46fe:e243', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 08:42:04'),
(148, '2407:aa80:15:9b4d:5cfb:bd58:46fe:e243', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 08:42:42'),
(147, '2407:aa80:15:9b4d:5cfb:bd58:46fe:e243', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 08:42:54'),
(147, '2401:ba80:a390:8ee5:18ae:b9bc:62c3:2ee8', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-12 08:52:38'),
(147, '2407:aa80:15:9b4d:c0c6:bfb7:17d1:1985', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 09:57:59'),
(147, '2407:aa80:15:9b4d:c0c6:bfb7:17d1:1985', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 10:04:28'),
(138, '2407:aa80:15:9b4d:4c6:3f98:7409:ec94', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.4 Safari/605.1.15', '2026-05-12 10:04:33'),
(147, '2407:aa80:15:9b4d:c0c6:bfb7:17d1:1985', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 10:05:20'),
(147, '2407:aa80:15:9b4d:c0c6:bfb7:17d1:1985', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 10:17:41'),
(147, '2407:aa80:15:9b4d:c0c6:bfb7:17d1:1985', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 10:18:00'),
(43, '2407:aa80:15:9b4d:c0c6:bfb7:17d1:1985', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 10:52:36'),
(138, '2407:aa80:15:9b4d:4c6:3f98:7409:ec94', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.4 Safari/605.1.15', '2026-05-12 11:25:26'),
(148, '2407:aa80:15:9b4d:c0c6:bfb7:17d1:1985', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 11:47:43'),
(147, '2407:aa80:15:9b4d:c0c6:bfb7:17d1:1985', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 11:52:11'),
(138, '2407:aa80:15:9b4d:4c6:3f98:7409:ec94', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.4 Safari/605.1.15', '2026-05-12 13:05:21'),
(147, '2407:aa80:15:9b4d:4c6:3f98:7409:ec94', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.4 Safari/605.1.15', '2026-05-12 13:09:38'),
(43, '2407:aa80:15:9b4d:c0c6:bfb7:17d1:1985', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 13:40:38'),
(147, '2407:aa80:15:9b4d:4c6:3f98:7409:ec94', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.4 Safari/605.1.15', '2026-05-12 14:15:30'),
(147, '2401:ba80:a387:8f75:18ae:dc73:eba6:fecf', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-12 15:57:30'),
(148, '154.198.108.190', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1', '2026-05-12 18:10:57'),
(147, '2407:aa80:15:3208:d12b:309:f9f4:47eb', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-13 06:29:58'),
(43, '2407:aa80:15:3208:d12b:309:f9f4:47eb', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-13 06:32:57'),
(147, '2407:aa80:15:3208:d12b:309:f9f4:47eb', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-13 06:36:29'),
(150, '2407:aa80:15:3208:d12b:309:f9f4:47eb', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-13 07:00:30'),
(150, '2407:aa80:15:3208:d12b:309:f9f4:47eb', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-13 07:40:25'),
(150, '2407:aa80:15:3208:4087:21db:7d8b:1e25', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.4 Safari/605.1.15', '2026-05-13 07:44:47'),
(150, '2407:aa80:15:3208:4087:21db:7d8b:1e25', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.4 Safari/605.1.15', '2026-05-13 07:47:29'),
(148, '2407:aa80:15:3208:4087:21db:7d8b:1e25', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.4 Safari/605.1.15', '2026-05-13 07:47:51'),
(150, '2407:aa80:15:3208:d12b:309:f9f4:47eb', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-13 07:52:13'),
(148, '2407:aa80:15:3208:d12b:309:f9f4:47eb', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-13 07:52:29'),
(148, '2407:aa80:15:3208:4087:21db:7d8b:1e25', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.4 Safari/605.1.15', '2026-05-13 07:55:35'),
(150, '2407:aa80:15:3208:4087:21db:7d8b:1e25', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.4 Safari/605.1.15', '2026-05-13 07:55:57'),
(150, '2407:aa80:15:3208:4087:21db:7d8b:1e25', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.4 Safari/605.1.15', '2026-05-13 07:58:10'),
(148, '2407:aa80:15:3208:4087:21db:7d8b:1e25', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.4 Safari/605.1.15', '2026-05-13 07:58:23'),
(148, '154.198.101.158', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1', '2026-05-13 16:29:37'),
(148, '154.198.101.158', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1', '2026-05-13 16:31:18'),
(148, '39.35.195.169', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-13 18:09:47'),
(148, '39.35.195.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-13 18:11:01'),
(150, '2407:aa80:15:2a15:80a5:4068:38a9:4188', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-14 06:26:52'),
(148, '2407:aa80:15:2a15:80a5:4068:38a9:4188', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-14 06:27:03'),
(148, '2407:aa80:15:2a15:b113:3382:361a:b68', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-14 08:36:45'),
(148, '2407:aa80:15:2a15:b113:3382:361a:b68', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-14 08:36:48'),
(150, '2407:aa80:15:2a15:154d:9f0b:81c6:2e43', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-14 11:06:03'),
(148, '2407:aa80:15:2a15:b113:3382:361a:b68', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-14 11:06:24'),
(150, '2407:aa80:15:2a15:b113:3382:361a:b68', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-14 11:07:31'),
(150, '2407:aa80:15:2a15:d448:d4e8:d23e:f98b', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.4 Safari/605.1.15', '2026-05-14 11:07:32'),
(150, '2407:aa80:15:2a15:b113:3382:361a:b68', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-14 11:32:14'),
(148, '2407:aa80:15:2a15:b113:3382:361a:b68', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-14 11:32:21'),
(148, '2407:aa80:15:2a15:b113:3382:361a:b68', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-14 11:49:57'),
(148, '2407:aa80:15:2a15:b113:3382:361a:b68', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-14 11:50:03'),
(148, '2407:aa80:15:2a15:b113:3382:361a:b68', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-14 11:50:21'),
(150, '2407:aa80:15:2a15:b113:3382:361a:b68', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-14 11:50:27'),
(150, '2407:aa80:15:2a15:b113:3382:361a:b68', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-14 11:50:50'),
(148, '2407:aa80:15:2a15:b113:3382:361a:b68', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-14 11:51:04'),
(148, '2407:aa80:15:2a15:b113:3382:361a:b68', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-14 11:52:19'),
(148, '2407:aa80:15:2a15:b113:3382:361a:b68', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-14 11:52:25'),
(148, '2407:aa80:15:2a15:b113:3382:361a:b68', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-14 11:52:52'),
(148, '2407:aa80:15:2a15:bceb:3c7b:4cb:6d5f', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-14 11:53:58'),
(148, '2407:aa80:15:2a15:154d:9f0b:81c6:2e43', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-14 12:38:02'),
(43, '2407:aa80:15:2a15:154d:9f0b:81c6:2e43', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-14 12:38:17'),
(148, '2407:aa80:15:2a15:154d:9f0b:81c6:2e43', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-14 12:40:42'),
(148, '2407:aa80:15:77ba:8998:8b4b:5890:411f', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-14 13:31:23'),
(148, '2407:aa80:15:77ba:43c:1613:47a9:c1b8', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-14 13:50:34'),
(148, '72.255.37.6', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-14 13:52:39'),
(148, '101.53.235.148', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-14 15:05:12'),
(148, '192.140.145.143', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-15 06:35:22'),
(148, '2407:aa80:15:d6bb:69e7:9276:b3e4:fa29', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-15 07:58:38'),
(148, '154.57.213.198', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-15 08:01:38'),
(148, '2407:aa80:15:d6bb:69e7:9276:b3e4:fa29', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-15 08:08:45'),
(148, '2407:aa80:15:d6bb:69e7:9276:b3e4:fa29', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-15 08:08:49'),
(148, '101.53.235.148', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-15 08:50:58'),
(148, '182.190.197.145', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36', '2026-05-15 09:52:01'),
(148, '154.57.213.198', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-15 10:10:20'),
(148, '2407:aa80:15:d6bb:69e7:9276:b3e4:fa29', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-15 10:13:35'),
(148, '2407:aa80:15:d6bb:69e7:9276:b3e4:fa29', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-15 12:44:41'),
(148, '154.57.213.198', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-15 13:20:48'),
(148, '154.57.213.198', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-15 13:23:12'),
(148, '154.57.213.198', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-15 13:23:17'),
(148, '154.57.213.198', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-15 14:05:38'),
(148, '2407:aa80:15:d6bb:481a:5727:9d4a:7b7b', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-15 14:09:27'),
(148, '154.57.213.198', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-15 14:10:03'),
(148, '154.57.213.198', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-15 14:10:06'),
(148, '2407:aa80:15:3506:299e:5ad7:53cd:8779', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-16 06:39:12'),
(148, '154.57.213.198', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-16 06:42:14'),
(148, '101.53.235.148', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-16 06:56:13'),
(148, '119.73.104.115', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-16 08:44:43'),
(148, '2407:aa80:15:3506:299e:5ad7:53cd:8779', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-16 08:45:12'),
(148, '119.73.104.115', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-16 10:23:31'),
(136, '119.73.104.115', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-16 11:03:51'),
(148, '119.73.104.115', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-16 11:10:06'),
(137, '154.80.52.145', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1', '2026-05-16 11:17:00'),
(137, '154.80.52.145', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1', '2026-05-16 11:18:04'),
(148, '119.73.104.115', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-16 11:20:16'),
(136, '119.73.104.115', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-16 11:49:15'),
(148, '119.73.104.115', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-16 11:49:19'),
(148, '119.73.104.115', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-16 11:52:04'),
(148, '119.73.104.115', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-16 11:52:36'),
(148, '119.73.104.115', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-16 11:52:49'),
(136, '119.73.104.115', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-16 11:59:59'),
(43, '2407:aa80:15:3506:299e:5ad7:53cd:8779', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-16 12:02:02'),
(148, '119.73.104.115', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-16 12:27:47'),
(148, '119.73.104.115', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-16 13:35:45'),
(136, '37.111.143.132', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-16 14:00:25'),
(148, '2407:aa80:15:c349:d478:3deb:effa:8420', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-18 07:00:04'),
(148, '101.53.235.148', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-18 07:15:08'),
(148, '2407:aa80:15:6035:45f2:1d4:9ef3:2ff3', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-18 10:10:06'),
(136, '119.73.97.105', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-18 14:17:05'),
(136, '119.73.97.105', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-18 14:19:11'),
(134, '119.73.97.105', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-18 14:19:35'),
(43, '2407:aa80:15:6035:45f2:1d4:9ef3:2ff3', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 06:32:15'),
(137, '2407:aa80:15:6035:45f2:1d4:9ef3:2ff3', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 06:39:43'),
(137, '119.63.131.155', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 06:43:49'),
(137, '119.63.131.155', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 06:44:30'),
(137, '119.63.131.155', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 06:44:54'),
(137, '2407:aa80:15:6035:45f2:1d4:9ef3:2ff3', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 06:47:32'),
(137, '2407:aa80:15:6035:45f2:1d4:9ef3:2ff3', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 06:47:44'),
(137, '2407:aa80:15:6035:45f2:1d4:9ef3:2ff3', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 06:52:03'),
(137, '2407:aa80:15:6035:45f2:1d4:9ef3:2ff3', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 06:52:10'),
(137, '2407:aa80:15:6035:45f2:1d4:9ef3:2ff3', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 06:58:15'),
(137, '2407:aa80:15:6035:45f2:1d4:9ef3:2ff3', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 06:58:23'),
(137, '2407:aa80:15:6035:45f2:1d4:9ef3:2ff3', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 07:13:48'),
(138, '2407:aa80:15:6035:45f2:1d4:9ef3:2ff3', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 07:14:00'),
(138, '2407:aa80:15:6035:45f2:1d4:9ef3:2ff3', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 07:26:03'),
(137, '2407:aa80:15:6035:45f2:1d4:9ef3:2ff3', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 07:27:52'),
(137, '2407:aa80:15:6035:45f2:1d4:9ef3:2ff3', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 07:37:55'),
(137, '2407:aa80:15:6035:45f2:1d4:9ef3:2ff3', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 07:38:07'),
(137, '2407:aa80:15:6035:45f2:1d4:9ef3:2ff3', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 07:38:13'),
(138, '2407:aa80:15:6035:45f2:1d4:9ef3:2ff3', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 07:38:27'),
(138, '2407:aa80:15:6035:45f2:1d4:9ef3:2ff3', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 07:50:18'),
(137, '2407:aa80:15:6035:45f2:1d4:9ef3:2ff3', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 07:50:26'),
(137, '154.80.20.111', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1', '2026-05-19 09:55:03'),
(137, '2407:aa80:15:dd4:f4b8:6498:50d:39ad', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 10:09:17'),
(134, '2407:aa80:15:dd4:f4b8:6498:50d:39ad', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 10:09:23'),
(134, '2407:aa80:15:dd4:f4b8:6498:50d:39ad', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 10:14:34'),
(138, '2407:aa80:15:dd4:f4b8:6498:50d:39ad', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 10:14:47'),
(134, '2401:ba80:a383:284f:1d68:9b58:e5c9:b476', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 OPR/131.0.0.0', '2026-05-19 10:22:32'),
(134, '154.198.123.59', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36', '2026-05-19 11:11:07'),
(138, '2407:aa80:15:dd4:f4b8:6498:50d:39ad', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 12:03:05'),
(134, '119.73.104.82', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-19 14:42:53'),
(138, '2407:aa80:15:dd4:f4b8:6498:50d:39ad', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-20 06:50:11'),
(148, '2407:aa80:15:dd4:2c5d:de6e:105c:5af', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-20 07:40:18'),
(148, '2407:aa80:15:dd4:f4b8:6498:50d:39ad', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-20 07:40:27'),
(43, '2407:aa80:15:dd4:f4b8:6498:50d:39ad', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-20 08:29:09'),
(148, '154.57.213.198', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36', '2026-05-20 08:38:43'),
(148, '154.57.213.198', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36', '2026-05-20 08:38:43'),
(134, '154.198.120.66', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36', '2026-05-20 11:20:40'),
(43, '2407:aa80:15:dd4:f041:e80:6acb:db9a', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-20 11:48:33'),
(43, '2407:aa80:15:dd4:f041:e80:6acb:db9a', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-21 06:37:52'),
(137, '2407:aa80:15:dd4:f041:e80:6acb:db9a', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-21 06:38:52'),
(148, '2407:aa80:15:dd4:f041:e80:6acb:db9a', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-21 06:41:01'),
(148, '2407:aa80:15:dd4:f041:e80:6acb:db9a', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-21 06:41:42'),
(138, '2407:aa80:15:dd4:f041:e80:6acb:db9a', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-21 06:42:08'),
(138, '2407:aa80:15:dd4:f041:e80:6acb:db9a', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-21 06:42:47'),
(148, '2407:aa80:15:dd4:f041:e80:6acb:db9a', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-21 06:42:57'),
(148, '2407:aa80:15:dd4:f041:e80:6acb:db9a', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-21 06:43:10'),
(138, '2407:aa80:15:dd4:f041:e80:6acb:db9a', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-21 06:43:18'),
(43, '2407:aa80:15:6483:843:3901:b3b:4cee', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-21 12:43:55'),
(150, '2407:aa80:15:6483:fd28:8f62:9166:a041', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-21 12:52:41'),
(150, '2407:aa80:15:6483:fd28:8f62:9166:a041', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-21 12:52:44'),
(43, '2407:aa80:15:6483:843:3901:b3b:4cee', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-21 13:44:48'),
(43, '2407:aa80:15:6483:74c1:ea3:1449:6be', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-21 13:47:05'),
(150, '2401:ba80:a39b:ab32:18b1:7d48:77d:faad', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36', '2026-05-21 14:31:00'),
(150, '2401:ba80:a39b:ab32:d068:798b:c084:b22a', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0.0.0 Safari/537.36', '2026-05-21 15:15:22'),
(43, '2407:aa80:15:6483:74c1:ea3:1449:6be', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-22 06:38:56'),
(43, '2407:aa80:15:6483:74c1:ea3:1449:6be', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-22 07:38:10'),
(43, '2407:aa80:15:6483:74c1:ea3:1449:6be', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-22 08:59:29'),
(43, '2407:aa80:15:30ac:b992:7092:2cb0:1540', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-22 10:24:54'),
(43, '2407:aa80:15:6051:4503:cd51:fe4f:7e6f', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-23 07:24:51'),
(148, '2407:aa80:15:6051:bf39:beec:5ebc:145a', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36', '2026-05-23 07:56:32'),
(138, '2407:aa80:15:6051:4503:cd51:fe4f:7e6f', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-23 07:58:58'),
(148, '2407:aa80:15:6051:4503:cd51:fe4f:7e6f', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-23 08:04:25'),
(148, '154.57.213.198', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36', '2026-05-23 08:12:11'),
(148, '2407:aa80:15:6051:a1d7:4b4b:ac7:b297', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36', '2026-05-23 10:21:43'),
(43, '2407:aa80:15:6051:6c07:f16a:667:4b10', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-23 10:42:29'),
(43, '2407:aa80:15:6051:6c07:f16a:667:4b10', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-23 10:42:44'),
(148, '2407:aa80:15:6051:6c07:f16a:667:4b10', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-23 10:43:03'),
(148, '2407:aa80:15:6051:a1d7:4b4b:ac7:b297', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36', '2026-05-23 11:00:38'),
(148, '2407:aa80:15:6051:6c07:f16a:667:4b10', 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36', '2026-05-23 11:21:56'),
(148, '2407:aa80:15:6051:a1d7:4b4b:ac7:b297', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36', '2026-05-23 12:41:31'),
(148, '2407:aa80:15:6051:6c07:f16a:667:4b10', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-23 13:05:40'),
(150, '2407:aa80:15:6051:6c07:f16a:667:4b10', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-23 13:06:27'),
(148, '2407:aa80:15:6051:a1d7:4b4b:ac7:b297', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36', '2026-05-23 13:41:15'),
(148, '2407:aa80:15:6051:dc2a:77b5:8a8f:ac46', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-23 13:50:05'),
(138, '2407:aa80:15:156c:4ef:24b4:132a:c2ef', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-25 07:25:40'),
(43, '2407:aa80:15:156c:4ef:24b4:132a:c2ef', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-25 08:02:07'),
(137, '119.73.97.205', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-25 12:25:34'),
(138, '2407:aa80:15:2839:69d2:4d21:9f08:bc10', 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36', '2026-05-26 06:48:50'),
(138, '154.57.213.198', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-26 10:09:52'),
(43, '154.57.213.198', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-26 10:37:31'),
(43, '2407:aa80:15:800a:851:de3d:ac24:9bc8', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-01 07:18:51'),
(43, '2407:aa80:15:800a:851:de3d:ac24:9bc8', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-01 07:18:59'),
(138, '2407:aa80:15:800a:851:de3d:ac24:9bc8', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-01 07:19:44'),
(138, '2407:aa80:15:800a:851:de3d:ac24:9bc8', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-01 07:27:41'),
(138, '2407:aa80:15:800a:851:de3d:ac24:9bc8', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-01 07:27:48'),
(43, '2407:aa80:15:800a:851:de3d:ac24:9bc8', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-01 07:27:52'),
(138, '2407:aa80:15:800a:851:de3d:ac24:9bc8', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-01 10:13:47'),
(43, '2407:aa80:15:e82f:74b0:3ad5:e45e:13ee', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-03 08:04:50'),
(148, '101.53.235.148', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-03 08:17:43'),
(152, '2407:aa80:15:1147:2d33:a16d:c643:e5a8', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-03 08:32:23'),
(148, '2407:aa80:15:1147:c00e:1fd7:32cc:8ef8', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-03 10:07:36');
INSERT INTO `login_logs` (`user_id`, `ip`, `device`, `created_at`) VALUES
(148, '2407:aa80:15:1147:c00e:1fd7:32cc:8ef8', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-03 10:09:49'),
(148, '2407:aa80:15:1147:c00e:1fd7:32cc:8ef8', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-03 10:10:45'),
(148, '2407:aa80:15:1147:c00e:1fd7:32cc:8ef8', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-03 10:17:56'),
(152, '2407:aa80:15:1147:2d33:a16d:c643:e5a8', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-03 11:08:28'),
(152, '2407:aa80:15:332a:45cb:7f33:935:dd0e', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-03 12:56:14'),
(152, '2407:aa80:15:332a:45cb:7f33:935:dd0e', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-04 06:38:38'),
(43, '2407:aa80:15:332a:ac74:9646:269d:4f55', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-04 07:02:39'),
(43, '154.57.213.198', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-04 07:05:08'),
(43, '154.57.213.198', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-04 07:05:19'),
(43, '154.57.213.198', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-04 07:10:58'),
(43, '154.57.213.198', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-04 07:12:49'),
(148, '2407:aa80:15:332a:a180:8dd4:f662:6ee3', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-04 07:18:07'),
(148, '2407:aa80:15:332a:45cb:7f33:935:dd0e', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-04 07:22:36'),
(148, '154.57.213.198', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-04 07:45:07'),
(148, '2407:aa80:15:332a:dd86:99af:244c:211b', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-04 07:52:32'),
(138, '2407:aa80:15:332a:b859:422f:3535:4809', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-04 08:09:05'),
(138, '2407:aa80:15:332a:45cb:7f33:935:dd0e', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-04 08:14:11'),
(152, '2407:aa80:15:332a:45cb:7f33:935:dd0e', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-04 08:14:29'),
(43, '2407:aa80:15:332a:45cb:7f33:935:dd0e', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-04 08:15:10'),
(148, '154.57.213.198', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-04 13:51:25'),
(148, '154.57.213.198', 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36', '2026-06-05 06:39:26'),
(43, '119.73.97.109', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-05 10:15:39'),
(43, '119.73.97.109', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-05 10:17:17'),
(152, '119.73.97.109', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-05 10:17:24'),
(152, '119.73.97.109', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Safari/605.1.15', '2026-06-05 10:20:02'),
(148, '2407:aa80:15:5b6c:b5f1:9a7b:8a63:20f9', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-05 10:42:36'),
(152, '119.73.97.109', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-05 11:04:16'),
(152, '2407:aa80:15:5b6c:24e5:52db:25c6:c183', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-05 11:06:38'),
(43, '2407:aa80:15:5b6c:24e5:52db:25c6:c183', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-05 11:07:15'),
(148, '2407:aa80:15:5b6c:ec77:abf8:d99a:af1a', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-05 13:49:03'),
(152, '119.73.97.171', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-06 06:42:12'),
(148, '2407:aa80:15:b1a7:f823:1881:72f6:b9bb', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-06 07:04:11'),
(152, '2407:aa80:15:b1a7:45a2:e0b2:ee97:3950', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-06 12:08:25'),
(152, '2407:aa80:15:b1a7:45a2:e0b2:ee97:3950', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-06 12:08:40'),
(152, '192.140.145.143', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-06 13:04:58'),
(148, '2407:aa80:15:e3b1:3909:3692:9e05:145a', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-08 06:27:47'),
(152, '2407:aa80:15:e3b1:b42b:c8d2:ab4a:aa48', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-08 06:30:03'),
(152, '2407:aa80:15:e3b1:b42b:c8d2:ab4a:aa48', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-08 07:11:40'),
(138, '2407:aa80:15:e3b1:4966:c3c2:9cdf:e363', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Safari/605.1.15', '2026-06-08 07:27:13'),
(152, '2407:aa80:15:e3b1:b42b:c8d2:ab4a:aa48', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-08 07:50:24'),
(43, '2407:aa80:15:e3b1:b42b:c8d2:ab4a:aa48', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-08 07:50:37'),
(152, '2407:aa80:15:e3b1:b42b:c8d2:ab4a:aa48', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-08 07:54:17'),
(156, '2407:aa80:15:e3b1:b42b:c8d2:ab4a:aa48', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-08 07:56:32'),
(156, '2407:aa80:15:e3b1:b42b:c8d2:ab4a:aa48', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-08 08:10:22'),
(156, '2407:aa80:15:e3b1:b42b:c8d2:ab4a:aa48', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-08 08:10:33'),
(156, '2407:aa80:15:e3b1:b42b:c8d2:ab4a:aa48', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-08 08:10:44'),
(156, '2407:aa80:15:e3b1:b42b:c8d2:ab4a:aa48', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-08 08:11:27'),
(156, '2407:aa80:15:e3b1:38a8:f181:b141:39ae', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-08 08:22:23'),
(156, '2407:aa80:15:e3b1:b42b:c8d2:ab4a:aa48', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-08 08:27:20'),
(148, '2407:aa80:15:e3b1:b42b:c8d2:ab4a:aa48', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-08 08:27:35'),
(156, '2407:aa80:15:e3b1:b42b:c8d2:ab4a:aa48', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-08 10:22:52'),
(148, '154.57.213.198', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-08 12:06:52'),
(152, '154.57.213.198', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-09 07:09:08'),
(152, '154.57.213.198', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-09 07:20:11'),
(152, '154.57.213.198', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-09 07:22:50'),
(152, '154.57.213.198', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-09 07:23:16'),
(156, '154.57.213.198', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-09 07:24:00'),
(156, '154.57.213.198', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-09 07:24:30'),
(156, '154.57.213.198', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-09 07:24:41'),
(156, '154.57.213.198', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-09 08:04:49'),
(156, '154.57.213.198', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-09 08:46:39'),
(156, '154.57.213.198', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-09 08:51:45'),
(156, '154.57.213.198', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-09 09:01:50'),
(156, '154.57.213.198', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-09 10:24:40'),
(156, '154.57.213.198', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-09 10:24:40'),
(156, '154.57.213.198', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-09 10:36:24'),
(156, '154.57.213.198', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-09 10:41:12'),
(43, '154.57.213.198', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-09 10:41:18'),
(156, '192.140.145.143', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-09 12:40:31'),
(43, '2401:ba80:a388:ec61:51bd:c11c:ec53:5062', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-09 13:00:13'),
(43, '2401:ba80:a388:ec61:51bd:c11c:ec53:5062', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-09 13:00:13'),
(43, '2401:ba80:a388:ec61:51bd:c11c:ec53:5062', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-09 13:00:23'),
(156, '2401:ba80:a388:ec61:51bd:c11c:ec53:5062', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-09 13:00:38'),
(156, '154.81.249.19', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-09 13:24:39'),
(156, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-11 20:48:37'),
(156, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-11 22:59:04'),
(148, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-11 23:05:33');

-- --------------------------------------------------------

--
-- Table structure for table `memberships`
--

CREATE TABLE `memberships` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `discount_type` enum('percent','fixed') DEFAULT 'percent',
  `discount_value` decimal(10,2) DEFAULT 0.00,
  `points_per_order` int(11) DEFAULT 0,
  `duration_days` int(11) DEFAULT 30,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_free` tinyint(1) NOT NULL DEFAULT 0,
  `preview_color` varchar(20) NOT NULL DEFAULT 'green',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `memberships`
--

INSERT INTO `memberships` (`id`, `client_id`, `name`, `description`, `discount_type`, `discount_value`, `points_per_order`, `duration_days`, `price`, `is_free`, `preview_color`, `created_at`) VALUES
(5, 20, 'Platinum Membership', 'test platinum membership for customers', 'fixed', 500.00, 0, 30, 99.99, 0, 'green', '2026-05-07 07:21:29');

-- --------------------------------------------------------

--
-- Table structure for table `membership_benefits`
--

CREATE TABLE `membership_benefits` (
  `id` int(11) NOT NULL,
  `membership_id` int(11) NOT NULL,
  `benefit_text` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `membership_benefits`
--

INSERT INTO `membership_benefits` (`id`, `membership_id`, `benefit_text`, `sort_order`, `created_at`) VALUES
(6, 5, 'test', 0, '2026-05-07 07:21:29'),
(7, 5, 'test', 1, '2026-05-07 07:21:29'),
(8, 5, 'test', 2, '2026-05-07 07:21:29'),
(9, 5, 'test', 3, '2026-05-07 07:21:29');

-- --------------------------------------------------------

--
-- Table structure for table `membership_card_templates`
--

CREATE TABLE `membership_card_templates` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `membership_id` int(11) NOT NULL,
  `template_name` varchar(150) DEFAULT NULL,
  `design_json` longtext DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `background_color` varchar(20) DEFAULT '#ffffff',
  `text_color` varchar(20) DEFAULT '#000000',
  `font_family` varchar(50) DEFAULT 'Arial',
  `header_text` varchar(255) DEFAULT '',
  `footer_text` varchar(255) DEFAULT '',
  `card_file` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message` varchar(255) NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `sale_id` int(11) DEFAULT NULL,
  `restaurant_session_id` int(11) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(20) NOT NULL DEFAULT 'Paid',
  `payment_method` varchar(50) DEFAULT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL,
  `sale_uuid` varchar(64) DEFAULT NULL,
  `restaurant_session_uuid` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `client_id`, `branch_id`, `customer_id`, `sale_id`, `restaurant_session_id`, `amount`, `status`, `payment_method`, `reference_no`, `notes`, `created_at`, `updated_at`, `uuid`, `device_id`, `sync_status`, `last_synced_at`, `deleted_at`, `created_at_local`, `updated_at_local`, `sale_uuid`, `restaurant_session_uuid`) VALUES
(1, 32, 0, 0, 74, 1, 1600.00, 'Paid', 'cash', '', '', '2026-06-04 07:18:12', '2026-06-04 07:18:12', 'df554946646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df4a1939646d11f1ae7470b5e88d388e', 'df567b01646d11f1ae7470b5e88d388e'),
(2, 32, 0, 0, 76, 3, 2500.00, 'Paid', 'cash', '', '', '2026-06-05 10:48:14', '2026-06-05 10:48:14', 'df554b6d646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df4a1ae0646d11f1ae7470b5e88d388e', 'df567eab646d11f1ae7470b5e88d388e'),
(3, 32, 18, 0, 77, 5, 150.00, 'Paid', 'cash', '', '', '2026-06-05 14:08:26', '2026-06-05 14:08:26', 'df554ce0646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df4a1ba6646d11f1ae7470b5e88d388e', 'df568225646d11f1ae7470b5e88d388e'),
(4, 27, 18, 0, 78, 9, 1800.00, 'Paid', 'cash', '9', '', '2026-06-08 08:08:54', '2026-06-08 08:08:54', 'df554da4646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df4a1c68646d11f1ae7470b5e88d388e', 'df5689de646d11f1ae7470b5e88d388e');

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` int(11) NOT NULL,
  `key_name` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `key_name`) VALUES
(1, 'dashboard_view'),
(2, 'clients_all_clients_view'),
(3, 'clients_roles_view'),
(4, 'clients_add_client'),
(5, 'clients_suspended_clients_view'),
(6, 'clients_assign_industry'),
(7, 'clients_branch_user_limits'),
(8, 'subscriptions_plans_view'),
(9, 'subscriptions_client_subscriptions_view'),
(10, 'subscriptions_invoices_view'),
(11, 'subscriptions_trial_management'),
(12, 'features_fbr_integration'),
(13, 'features_wholesale_enable_disable'),
(14, 'features_inventory_enable_disable'),
(15, 'features_industry_modules'),
(16, 'system_active_clients_view'),
(17, 'system_online_branches_view'),
(18, 'system_pos_activity_view'),
(19, 'system_fbr_status_view'),
(20, 'reports_daily_sales_view'),
(21, 'reports_cashier_shift_view'),
(22, 'reports_profit_loss_view'),
(23, 'reports_inventory_stock_view'),
(24, 'reports_low_stock_view'),
(25, 'reports_tax_summary_view'),
(26, 'reports_customer_ledger_view'),
(27, 'reports_supplier_ledger_view'),
(28, 'audit_login_logs_view'),
(29, 'audit_action_logs_view'),
(30, 'audit_ip_tracking_view'),
(31, 'settings_global_view'),
(32, 'quotation_view'),
(33, 'quotation_add'),
(34, 'quotation_edit'),
(35, 'quotation_delete'),
(36, 'quotation_convert'),
(37, 'production'),
(38, 'production_formulas'),
(39, 'production_work_orders'),
(40, 'production_batches'),
(41, 'production_wastage'),
(42, 'production_costing'),
(43, 'production_reports');

-- --------------------------------------------------------

--
-- Table structure for table `plans`
--

CREATE TABLE `plans` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `billing_cycle` enum('monthly','yearly') NOT NULL DEFAULT 'monthly',
  `industry` varchar(100) NOT NULL DEFAULT 'retail',
  `status` varchar(20) NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `plans`
--

INSERT INTO `plans` (`id`, `name`, `price`, `billing_cycle`, `industry`, `status`) VALUES
(1, 'test', 1000.00, 'monthly', 'Retailer', 'active'),
(3, 'Clients', 5000.00, 'monthly', 'retail', 'active'),
(4, 'demoPlan', 100.00, 'monthly', 'retail', 'active'),
(6, 'dua plan', 100.00, 'monthly', 'retail', 'active'),
(8, 'Rizwan Enterprises', 100.00, 'monthly', 'others', 'active'),
(10, 'Marriage hall', 100.00, 'monthly', 'services', 'active'),
(11, 'resturent', 100.00, 'monthly', 'restaurant', 'active'),
(12, 'dheer', 100.00, 'yearly', 'restaurant', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `plan_features`
--

CREATE TABLE `plan_features` (
  `id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `feature_key` varchar(100) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `plan_features`
--

INSERT INTO `plan_features` (`id`, `plan_id`, `feature_key`, `enabled`) VALUES
(111, 1, 'dashboard', 1),
(112, 1, 'invoices', 1),
(113, 1, 'pos_sales', 1),
(114, 1, 'inventory', 1),
(115, 1, 'inventory_adjustments', 1),
(116, 1, 'customers', 1),
(117, 1, 'suppliers', 1),
(118, 1, 'users', 1),
(119, 1, 'reports', 1),
(120, 1, 'wholesale_module', 1),
(121, 1, 'fbr_integration', 1),
(122, 1, 'tables', 1),
(123, 1, 'reservations', 1),
(124, 1, 'recipes', 1),
(125, 1, 'memberships', 1),
(126, 1, 'appointments', 1),
(127, 1, 'attendance', 1),
(128, 1, 'commissions', 1),
(129, 1, 'supplier_management', 1),
(130, 1, 'supplier_ledger', 1),
(131, 1, 'customer_ledger', 1),
(132, 1, 'profit_loss', 1),
(133, 1, 'daily_sales', 1),
(134, 1, 'cashier_shift', 1),
(135, 1, 'low_stock', 1),
(136, 1, 'tax_summary', 1),
(137, 1, 'discounts', 1),
(138, 1, 'taxes', 1),
(139, 1, 'multi_branch', 1),
(140, 1, 'membership', 1),
(141, 1, 'payments', 1),
(142, 1, 'expenses', 1),
(143, 1, 'returns', 1),
(144, 1, 'notifications', 1),
(145, 1, 'settings', 1),
(146, 1, 'integrations', 1),
(147, 1, 'barcode', 1),
(148, 1, 'mobile_access', 1),
(149, 1, 'products', 1),
(150, 1, 'item_variants', 1),
(191, 1, 'quotations', 1),
(240, 1, 'subscriptions', 1),
(241, 1, 'water_delivery', 1),
(242, 1, 'delivery_routes', 1),
(243, 1, 'dispatch', 1),
(244, 1, 'delivery_assign', 1),
(245, 1, 'delivery-assign', 1),
(246, 1, 'empty_bottles', 1),
(247, 1, 'real_estate', 1),
(248, 1, 're_dashboard', 1),
(249, 1, 're_agency_leads', 1),
(250, 1, 're_followups', 1),
(251, 1, 're_site_visits', 1),
(252, 1, 're_properties', 1),
(253, 1, 're_deals', 1),
(254, 1, 're_agent_assignment', 1),
(255, 1, 're_agent_commissions', 1),
(256, 1, 're_reports', 1),
(430, 3, 'dashboard', 1),
(431, 3, 'pos_sales', 1),
(432, 3, 'invoices', 1),
(433, 3, 'inventory', 1),
(434, 3, 'customers', 1),
(435, 3, 'products', 1),
(436, 3, 'item_variants', 1),
(437, 3, 'users', 1),
(438, 3, 'reports', 1),
(439, 3, 'quotations', 1),
(440, 3, 'inventory_adjustments', 1),
(441, 3, 'suppliers', 1),
(442, 3, 'wholesale_module', 1),
(443, 3, 'fbr_integration', 1),
(444, 3, 'tables', 1),
(445, 3, 'reservations', 1),
(446, 3, 'recipes', 1),
(447, 3, 'memberships', 1),
(448, 3, 'appointments', 1),
(449, 3, 'attendance', 1),
(450, 3, 'commissions', 1),
(451, 3, 'supplier_management', 1),
(452, 3, 'supplier_ledger', 1),
(453, 3, 'customer_ledger', 1),
(454, 3, 'profit_loss', 1),
(455, 3, 'daily_sales', 1),
(456, 3, 'cashier_shift', 1),
(457, 3, 'low_stock', 1),
(458, 3, 'tax_summary', 1),
(459, 3, 'discounts', 1),
(460, 3, 'taxes', 1),
(461, 3, 'multi_branch', 1),
(462, 3, 'membership', 1),
(463, 3, 'payments', 1),
(464, 3, 'expenses', 1),
(465, 3, 'returns', 1),
(466, 3, 'notifications', 1),
(467, 3, 'settings', 1),
(468, 3, 'integrations', 1),
(469, 3, 'barcode', 1),
(470, 3, 'mobile_access', 1),
(471, 3, 'delivery_routes', 1),
(472, 3, 'delivery_assign', 1),
(473, 3, 'distribution_operation', 1),
(474, 3, 'distribution', 1),
(475, 3, 'dist_routes', 1),
(476, 3, 'dist_drivers', 1),
(477, 3, 'dist_dispatch', 1),
(478, 3, 'dist_collections', 1),
(848, 6, 'dashboard', 1),
(849, 6, 'pos_sales', 1),
(850, 6, 'invoices', 1),
(851, 6, 'inventory', 1),
(852, 6, 'customers', 1),
(853, 6, 'products', 1),
(854, 6, 'item_variants', 1),
(855, 6, 'users', 1),
(856, 6, 'reports', 1),
(857, 6, 'quotations', 1),
(858, 6, 'inventory_adjustments', 1),
(859, 6, 'suppliers', 1),
(860, 6, 'wholesale_module', 1),
(861, 6, 'fbr_integration', 1),
(862, 6, 'recipes', 1),
(863, 6, 'attendance', 1),
(864, 6, 'commissions', 1),
(865, 6, 'supplier_management', 1),
(866, 6, 'supplier_ledger', 1),
(867, 6, 'customer_ledger', 1),
(868, 6, 'profit_loss', 1),
(869, 6, 'daily_sales', 1),
(870, 6, 'cashier_shift', 1),
(871, 6, 'low_stock', 1),
(872, 6, 'tax_summary', 1),
(873, 6, 'discounts', 1),
(874, 6, 'taxes', 1),
(875, 6, 'multi_branch', 1),
(876, 6, 'payments', 1),
(877, 6, 'expenses', 1),
(878, 6, 'returns', 1),
(879, 6, 'notifications', 1),
(880, 6, 'settings', 1),
(881, 6, 'integrations', 1),
(882, 6, 'barcode', 1),
(883, 6, 'mobile_access', 1),
(925, 8, 'dashboard', 1),
(926, 8, 'invoices', 1),
(927, 8, 'pos_sales', 1),
(928, 8, 'inventory', 1),
(929, 8, 'inventory_adjustments', 1),
(930, 8, 'customers', 1),
(931, 8, 'suppliers', 1),
(932, 8, 'users', 1),
(933, 8, 'reports', 1),
(934, 8, 'wholesale_module', 1),
(935, 8, 'fbr_integration', 1),
(936, 8, 'attendance', 1),
(937, 8, 'commissions', 1),
(938, 8, 'supplier_management', 1),
(939, 8, 'supplier_ledger', 1),
(940, 8, 'customer_ledger', 1),
(941, 8, 'profit_loss', 1),
(942, 8, 'daily_sales', 1),
(943, 8, 'cashier_shift', 1),
(944, 8, 'low_stock', 1),
(945, 8, 'tax_summary', 1),
(946, 8, 'discounts', 1),
(947, 8, 'taxes', 1),
(948, 8, 'payments', 1),
(949, 8, 'expenses', 1),
(950, 8, 'returns', 1),
(951, 8, 'notifications', 1),
(952, 8, 'settings', 1),
(953, 8, 'barcode', 1),
(954, 8, 'mobile_access', 1),
(955, 8, 'products', 1),
(956, 8, 'item_variants', 1),
(957, 8, 'quotations', 1),
(958, 8, 'dist_routes', 1),
(959, 8, 'dist_drivers', 1),
(960, 8, 'dist_dispatch', 1),
(961, 8, 'dist_delivery_assign', 1),
(962, 8, 'dist_distribution_operation', 1),
(963, 8, 'dist_orders', 1),
(964, 8, 'dist_delivery_update', 1),
(965, 8, 'dist_collections', 1),
(966, 8, 'dist_reports', 1),
(1073, 10, 'dashboard', 1),
(1074, 10, 'invoices', 1),
(1075, 10, 'customers', 1),
(1076, 10, 'users', 1),
(1077, 10, 'payments', 1),
(1078, 10, 'reports', 1),
(1079, 10, 'appointments', 1),
(1080, 10, 'services', 1),
(1081, 10, 'settings', 1),
(1082, 10, 'suppliers', 1),
(1083, 10, 'attendance', 1),
(1084, 10, 'commissions', 1),
(1085, 10, 'customer_ledger', 1),
(1086, 10, 'profit_loss', 1),
(1087, 10, 'tax_summary', 1),
(1088, 10, 'discounts', 1),
(1089, 10, 'taxes', 1),
(1090, 10, 'expenses', 1),
(1091, 10, 'returns', 1),
(1092, 10, 'notifications', 1),
(1093, 10, 'quotations', 1),
(1354, 4, 'dashboard', 1),
(1355, 4, 'pos_sales', 1),
(1356, 4, 'invoices', 1),
(1357, 4, 'inventory', 1),
(1358, 4, 'customers', 1),
(1359, 4, 'products', 1),
(1360, 4, 'item_variants', 1),
(1361, 4, 'users', 1),
(1362, 4, 'reports', 1),
(1363, 4, 'quotations', 1),
(1364, 4, 'production', 1),
(1365, 4, 'production_formulas', 1),
(1366, 4, 'production_work_orders', 1),
(1367, 4, 'production_batches', 1),
(1368, 4, 'production_wastage', 1),
(1369, 4, 'production_costing', 1),
(1370, 4, 'production_reports', 1),
(1371, 4, 'inventory_adjustments', 1),
(1372, 4, 'suppliers', 1),
(1373, 4, 'wholesale_module', 1),
(1374, 4, 'fbr_integration', 1),
(1375, 4, 'tables', 1),
(1376, 4, 'reservations', 1),
(1377, 4, 'recipes', 1),
(1378, 4, 'memberships', 1),
(1379, 4, 'appointments', 1),
(1380, 4, 'attendance', 1),
(1381, 4, 'commissions', 1),
(1382, 4, 'supplier_management', 1),
(1383, 4, 'supplier_ledger', 1),
(1384, 4, 'customer_ledger', 1),
(1385, 4, 'profit_loss', 1),
(1386, 4, 'daily_sales', 1),
(1387, 4, 'cashier_shift', 1),
(1388, 4, 'low_stock', 1),
(1389, 4, 'tax_summary', 1),
(1390, 4, 'discounts', 1),
(1391, 4, 'taxes', 1),
(1392, 4, 'multi_branch', 1),
(1393, 4, 'membership', 1),
(1394, 4, 'payments', 1),
(1395, 4, 'expenses', 1),
(1396, 4, 'returns', 1),
(1397, 4, 'notifications', 1),
(1398, 4, 'settings', 1),
(1399, 4, 'integrations', 1),
(1400, 4, 'barcode', 1),
(1401, 4, 'mobile_access', 1),
(1402, 4, 'services', 1),
(1403, 4, 'restaurant', 1),
(1404, 4, 'restaurant_dashboard', 1),
(1405, 4, 'restaurant_tables', 1),
(1406, 4, 'restaurant_pos', 1),
(1407, 4, 'restaurant_orders', 1),
(1408, 4, 'restaurant_kot', 1),
(1409, 4, 'restaurant_kitchen', 1),
(1410, 4, 'restaurant_waiters', 1),
(1411, 4, 'restaurant_bill_print', 1),
(1412, 4, 'restaurant_payments', 1),
(1413, 4, 'restaurant_table_transfer', 1),
(1414, 4, 'restaurant_table_merge', 1),
(1415, 4, 'restaurant_split_bill', 1),
(1416, 4, 'restaurant_reports', 1),
(1417, 4, 'restaurant_settings', 1),
(1418, 4, 'subscriptions', 1),
(1419, 4, 'water_delivery', 1),
(1420, 4, 'wp_delivery_routes', 1),
(1421, 4, 'wp_dispatch', 1),
(1422, 4, 'wp_delivery_assign', 1),
(1423, 4, 'wp_distribution_operation', 1),
(1424, 4, 'empty_bottles', 1),
(1425, 4, 'distribution', 1),
(1426, 4, 'dist_dashboard', 1),
(1427, 4, 'dist_routes', 1),
(1428, 4, 'dist_drivers', 1),
(1429, 4, 'dist_dispatch', 1),
(1430, 4, 'dist_delivery_assign', 1),
(1431, 4, 'dist_distribution_operation', 1),
(1432, 4, 'dist_orders', 1),
(1433, 4, 'dist_delivery_update', 1),
(1434, 4, 'dist_collections', 1),
(1435, 4, 'dist_reports', 1),
(1436, 4, 'real_estate', 1),
(1437, 4, 're_dashboard', 1),
(1438, 4, 're_agency_leads', 1),
(1439, 4, 're_followups', 1),
(1440, 4, 're_site_visits', 1),
(1441, 4, 're_properties', 1),
(1442, 4, 're_deals', 1),
(1443, 4, 're_agent_assignment', 1),
(1444, 4, 're_agent_commissions', 1),
(2066, 11, 'restaurant', 1),
(2067, 11, 'restaurant_dashboard', 1),
(2068, 11, 'restaurant_tables', 1),
(2069, 11, 'restaurant_pos', 1),
(2070, 11, 'restaurant_orders', 1),
(2071, 11, 'restaurant_kot', 1),
(2072, 11, 'restaurant_kitchen', 1),
(2073, 11, 'restaurant_waiters', 1),
(2074, 11, 'restaurant_bill_print', 1),
(2075, 11, 'restaurant_payments', 1),
(2076, 11, 'restaurant_reports', 1),
(2077, 11, 'invoices', 1),
(2078, 11, 'inventory', 1),
(2079, 11, 'customers', 1),
(2080, 11, 'users', 1),
(2081, 11, 'reports', 1),
(2082, 11, 'tables', 1),
(2083, 11, 'reservations', 1),
(2084, 11, 'recipes', 1),
(2193, 11, 'restaurant_bookings', 1),
(2196, 4, 'restaurant_bookings', 1),
(2218, 12, 'dashboard', 1),
(2219, 12, 'restaurant', 1),
(2220, 12, 'restaurant_bookings', 1),
(2221, 12, 'restaurant_tables', 1),
(2222, 12, 'restaurant_pos', 1),
(2223, 12, 'restaurant_orders', 1),
(2224, 12, 'restaurant_kot', 1),
(2225, 12, 'restaurant_kitchen', 1),
(2226, 12, 'restaurant_waiters', 1),
(2227, 12, 'restaurant_bill_print', 1),
(2228, 12, 'restaurant_payments', 1),
(2229, 12, 'restaurant_reports', 1),
(2230, 12, 'invoices', 1),
(2231, 12, 'inventory', 1),
(2232, 12, 'users', 1),
(2233, 12, 'tables', 1),
(2234, 12, 'reservations', 1),
(2235, 12, 'recipes', 1),
(2236, 12, 'quotations', 1);

-- --------------------------------------------------------

--
-- Table structure for table `pos_activity`
--

CREATE TABLE `pos_activity` (
  `id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `production_batches`
--

CREATE TABLE `production_batches` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `work_order_id` int(11) NOT NULL,
  `work_order_output_id` int(11) DEFAULT NULL,
  `item_id` int(11) NOT NULL,
  `batch_no` varchar(100) NOT NULL,
  `mfg_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `qty` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `unit` varchar(50) DEFAULT NULL,
  `output_type` enum('main','by_product','scrap','waste') NOT NULL DEFAULT 'main',
  `status` enum('active','expired','cancelled') NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `production_formulas`
--

CREATE TABLE `production_formulas` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `formula_no` varchar(100) DEFAULT NULL,
  `formula_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `production_formulas`
--

INSERT INTO `production_formulas` (`id`, `client_id`, `branch_id`, `formula_no`, `formula_name`, `description`, `status`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 27, 0, '1', 'beef burger', 'beef peti , bun , suces, cheese', 'active', 138, '2026-05-26 10:54:47', '2026-05-26 10:54:47');

-- --------------------------------------------------------

--
-- Table structure for table `production_formula_inputs`
--

CREATE TABLE `production_formula_inputs` (
  `id` int(11) NOT NULL,
  `formula_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `qty` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `unit` varchar(50) DEFAULT NULL,
  `wastage_percent` decimal(8,3) NOT NULL DEFAULT 0.000,
  `cost_type` enum('material','labor','service','overhead','packing','other') NOT NULL DEFAULT 'material',
  `is_stock_deduct` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `production_formula_inputs`
--

INSERT INTO `production_formula_inputs` (`id`, `formula_id`, `item_id`, `qty`, `unit`, `wastage_percent`, `cost_type`, `is_stock_deduct`) VALUES
(2, 1, 1037, 0.0002, '', 0.000, 'material', 1);

-- --------------------------------------------------------

--
-- Table structure for table `production_formula_outputs`
--

CREATE TABLE `production_formula_outputs` (
  `id` int(11) NOT NULL,
  `formula_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `qty` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `unit` varchar(50) DEFAULT NULL,
  `output_type` enum('main','by_product','scrap','waste') NOT NULL DEFAULT 'main',
  `is_stock_add` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `production_formula_outputs`
--

INSERT INTO `production_formula_outputs` (`id`, `formula_id`, `item_id`, `qty`, `unit`, `output_type`, `is_stock_add`) VALUES
(1, 1, 1037, 3.0000, '2', 'main', 1);

-- --------------------------------------------------------

--
-- Table structure for table `production_wastage_logs`
--

CREATE TABLE `production_wastage_logs` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `work_order_id` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `qty` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `unit` varchar(50) DEFAULT NULL,
  `wastage_type` enum('recoverable','non_recoverable') NOT NULL DEFAULT 'non_recoverable',
  `reason` varchar(255) DEFAULT NULL,
  `cost_impact` decimal(15,2) NOT NULL DEFAULT 0.00,
  `source_type` varchar(50) DEFAULT 'manual',
  `notes` text DEFAULT NULL,
  `is_stock_posted` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `production_wastage_logs`
--

INSERT INTO `production_wastage_logs` (`id`, `client_id`, `branch_id`, `work_order_id`, `item_id`, `qty`, `unit`, `wastage_type`, `reason`, `cost_impact`, `source_type`, `notes`, `is_stock_posted`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 27, 0, 1, 1037, 1.0000, '2', 'non_recoverable', 'meat was too old', 0.00, 'manual', '', 0, 138, '2026-06-01 08:23:52', '2026-06-01 08:25:07');

-- --------------------------------------------------------

--
-- Table structure for table `production_work_orders`
--

CREATE TABLE `production_work_orders` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `work_order_no` varchar(100) NOT NULL,
  `formula_id` int(11) DEFAULT NULL,
  `reference_type` varchar(100) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `production_date` date DEFAULT NULL,
  `expected_start` datetime DEFAULT NULL,
  `expected_end` datetime DEFAULT NULL,
  `actual_start` datetime DEFAULT NULL,
  `actual_end` datetime DEFAULT NULL,
  `status` enum('draft','planned','in_process','completed','cancelled') NOT NULL DEFAULT 'draft',
  `material_cost` decimal(15,2) NOT NULL DEFAULT 0.00,
  `labor_cost` decimal(15,2) NOT NULL DEFAULT 0.00,
  `overhead_cost` decimal(15,2) NOT NULL DEFAULT 0.00,
  `wastage_cost` decimal(15,2) NOT NULL DEFAULT 0.00,
  `other_cost` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_cost` decimal(15,2) NOT NULL DEFAULT 0.00,
  `cost_per_unit` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `production_work_orders`
--

INSERT INTO `production_work_orders` (`id`, `client_id`, `branch_id`, `work_order_no`, `formula_id`, `reference_type`, `reference_id`, `production_date`, `expected_start`, `expected_end`, `actual_start`, `actual_end`, `status`, `material_cost`, `labor_cost`, `overhead_cost`, `wastage_cost`, `other_cost`, `total_cost`, `cost_per_unit`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 27, 0, 'WO-20260601-0001', 1, '', NULL, '2026-06-01', NULL, NULL, '2026-06-01 08:12:00', NULL, 'in_process', 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.0000, '', 138, '2026-06-01 08:03:54', '2026-06-01 08:12:00');

-- --------------------------------------------------------

--
-- Table structure for table `production_work_order_inputs`
--

CREATE TABLE `production_work_order_inputs` (
  `id` int(11) NOT NULL,
  `work_order_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `expected_qty` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `actual_qty` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `unit` varchar(50) DEFAULT NULL,
  `unit_cost` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `total_cost` decimal(15,2) NOT NULL DEFAULT 0.00,
  `cost_type` enum('material','labor','service','overhead','packing','other') NOT NULL DEFAULT 'material',
  `is_stock_deduct` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `production_work_order_inputs`
--

INSERT INTO `production_work_order_inputs` (`id`, `work_order_id`, `item_id`, `expected_qty`, `actual_qty`, `unit`, `unit_cost`, `total_cost`, `cost_type`, `is_stock_deduct`) VALUES
(1, 1, 1037, 0.0002, 0.0002, '0', 0.0000, 0.00, 'material', 1);

-- --------------------------------------------------------

--
-- Table structure for table `production_work_order_outputs`
--

CREATE TABLE `production_work_order_outputs` (
  `id` int(11) NOT NULL,
  `work_order_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `expected_qty` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `actual_qty` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `unit` varchar(50) DEFAULT NULL,
  `output_type` enum('main','by_product','scrap','waste') NOT NULL DEFAULT 'main',
  `is_stock_add` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `production_work_order_outputs`
--

INSERT INTO `production_work_order_outputs` (`id`, `work_order_id`, `item_id`, `expected_qty`, `actual_qty`, `unit`, `output_type`, `is_stock_add`) VALUES
(1, 1, 1037, 3.0000, 3.0000, '2', 'main', 1);

-- --------------------------------------------------------

--
-- Table structure for table `purchase_orders`
--

CREATE TABLE `purchase_orders` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_orders`
--

INSERT INTO `purchase_orders` (`id`, `client_id`, `supplier_id`, `total_amount`, `created_at`) VALUES
(8, 20, 5, 2000.00, '2026-05-07 07:26:49');

-- --------------------------------------------------------

--
-- Table structure for table `purchase_order_items`
--

CREATE TABLE `purchase_order_items` (
  `id` int(11) NOT NULL,
  `po_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `qty` decimal(12,2) NOT NULL DEFAULT 1.00,
  `price` decimal(15,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_order_items`
--

INSERT INTO `purchase_order_items` (`id`, `po_id`, `item_id`, `qty`, `price`) VALUES
(9, 8, 76, 100.00, 20.00);

-- --------------------------------------------------------

--
-- Table structure for table `quotations`
--

CREATE TABLE `quotations` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `quotation_no` varchar(100) NOT NULL,
  `quotation_date` date NOT NULL,
  `customer_name` varchar(150) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(50) NOT NULL DEFAULT 'Pending',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `selected_items_json` longtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `quotations`
--

INSERT INTO `quotations` (`id`, `client_id`, `quotation_no`, `quotation_date`, `customer_name`, `phone`, `total_amount`, `status`, `created_at`, `selected_items_json`) VALUES
(35, 29, 'QO-1001', '2026-06-04', 'Walk-In Customer', '03332532300', 1311.45, 'View Bill', '2026-06-04 07:27:27', '[{\"id\":\"912\",\"name\":\"100M Leads\",\"sku\":\"IMP-121\",\"type\":\"product\",\"qty\":1,\"rate\":400,\"amount\":400},{\"id\":\"722\",\"name\":\"A Wild Sheep Chase  Haruki Murakami\",\"sku\":\"IMP-312\",\"type\":\"product\",\"qty\":1,\"rate\":300,\"amount\":300},{\"id\":\"218\",\"name\":\"The Kite Runner (Khalid)\",\"sku\":\"IMP-840\",\"type\":\"product\",\"qty\":1,\"rate\":250,\"amount\":250}]'),
(36, 29, 'QO-1002', '2026-06-04', 'Walk-In Customer', '', 992.25, 'View Bill', '2026-06-04 07:49:20', '[{\"id\":\"218\",\"name\":\"The Kite Runner (Khalid)\",\"sku\":\"IMP-840\",\"type\":\"product\",\"qty\":1,\"rate\":250,\"amount\":250},{\"id\":\"\",\"name\":\"das\",\"sku\":\"\",\"type\":\"product\",\"qty\":1,\"rate\":300,\"amount\":300},{\"id\":\"135\",\"name\":\"150 Stories Of Umer Ibn Al Khattab\",\"sku\":\"IMP-930\",\"type\":\"product\",\"qty\":1,\"rate\":400,\"amount\":400}]'),
(37, 29, 'QO-1003', '2026-06-04', '3 Star Book (Hydrabad)', '', 420.00, 'Draft', '2026-06-04 08:21:59', '[{\"id\":\"97\",\"name\":\"100 M Lost Chapter\",\"sku\":\"IMP-968\",\"type\":\"product\",\"qty\":1,\"rate\":400,\"amount\":400}]'),
(38, 29, 'QO-1004', '2026-06-04', '3 Star Book (Hydrabad)', '', 619.50, 'Draft', '2026-06-04 08:22:17', '[{\"id\":\"97\",\"name\":\"100 M Lost Chapter\",\"sku\":\"IMP-968\",\"type\":\"product\",\"qty\":1,\"rate\":400,\"amount\":400},{\"id\":\"\",\"name\":\"lux\",\"sku\":\"\",\"type\":\"product\",\"qty\":1,\"rate\":200,\"amount\":200}]'),
(40, 29, 'QO-1005', '2026-06-04', 'A S Books Shop', '', 350.00, 'Draft', '2026-06-04 11:21:27', '[{\"id\":\"900\",\"name\":\"10 Minutes 38 Seconds in This Strange World\",\"sku\":\"IMP-133\",\"type\":\"product\",\"qty\":1,\"rate\":350,\"amount\":350}]');

-- --------------------------------------------------------

--
-- Table structure for table `restaurant_activity_logs`
--

CREATE TABLE `restaurant_activity_logs` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL DEFAULT 0,
  `session_id` int(11) DEFAULT NULL,
  `table_id` int(11) DEFAULT NULL,
  `action_type` varchar(80) NOT NULL,
  `details` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL,
  `session_uuid` varchar(64) DEFAULT NULL,
  `table_uuid` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `restaurant_activity_logs`
--

INSERT INTO `restaurant_activity_logs` (`id`, `client_id`, `branch_id`, `session_id`, `table_id`, `action_type`, `details`, `created_by`, `created_at`, `uuid`, `device_id`, `sync_status`, `last_synced_at`, `deleted_at`, `created_at_local`, `updated_at_local`, `session_uuid`, `table_uuid`) VALUES
(1, 32, 0, 1, 2, 'start_session', '{\"table_no\":\"005\",\"guest_count\":1,\"waiter_id\":0,\"customer_id\":0,\"order_type\":\"dine_in\"}', 152, '2026-06-03 11:57:18', 'df5a22df646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(2, 32, 0, 2, 3, 'start_session', '{\"table_no\":\"9\",\"guest_count\":3,\"waiter_id\":2,\"customer_id\":0,\"order_type\":\"takeaway\"}', 152, '2026-06-03 12:57:30', 'df5a34f6646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(3, 32, 0, 1, 2, 'item_added', 'chicken steak x 1', 152, '2026-06-04 06:53:58', 'df5a362a646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(4, 32, 0, 1, 2, 'kot_sent', 'KOT-20260604-00001 sent to kitchen with 1 item lines.', 152, '2026-06-04 06:54:04', 'df5a3709646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(5, 32, 0, 1, 2, 'kot_status_updated', 'KOT-20260604-00001 changed from pending to ready', 152, '2026-06-04 06:54:49', 'df5a37d8646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(6, 32, 0, 1, 2, 'payment_completed', 'Restaurant bill paid. Invoice: INV-26-00074 Paid: 1600 Change: 0', 152, '2026-06-04 07:18:12', 'df5a3895646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(7, 32, 0, 3, 2, 'session_opened_from_pos', 'Session started from restaurant POS.', 152, '2026-06-05 10:47:22', 'df5a395a646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(8, 32, 0, 3, 2, 'item_added', 'chopped steak x 1', 152, '2026-06-05 10:47:27', 'df5a3a0e646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(9, 32, 0, 3, 2, 'payment_completed', 'Restaurant bill paid. Invoice: INV-26-00076 Paid: 2500 Change: 0', 152, '2026-06-05 10:48:14', 'df5a3ab4646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(10, 32, 0, 2, 3, 'item_added', 'chopped steak x 1', 152, '2026-06-05 10:49:33', 'df5a3be8646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(11, 32, 0, 2, 3, 'kot_sent', 'KOT-20260605-00002 sent to kitchen with 1 item lines.', 152, '2026-06-05 10:49:38', 'df5a3c8d646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(12, 32, 0, 4, 2, 'start_session', '{\"table_no\":\"005\",\"guest_count\":1,\"waiter_id\":0,\"customer_id\":0,\"order_type\":\"dine_in\"}', 152, '2026-06-05 13:03:21', 'df5a3d33646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(13, 32, 0, 4, 2, 'item_added', 'chopped steak x 1', 152, '2026-06-05 13:06:14', 'df5a3dde646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(14, 32, 0, 4, 2, 'kot_sent', 'KOT-20260605-00003 sent to kitchen with 1 item lines.', 152, '2026-06-05 13:06:16', 'df5a3e87646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(15, 32, 18, 5, 7, 'start_session', '{\"table_no\":\"1\",\"guest_count\":1,\"waiter_id\":0,\"customer_id\":0,\"order_type\":\"dine_in\"}', 152, '2026-06-05 14:06:50', 'df5a3f2c646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(16, 32, 18, 5, 7, 'item_added', 'raita x 1', 152, '2026-06-05 14:07:42', 'df5a3fd8646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(17, 32, 18, 5, 7, 'item_added', 'colddrink x 1', 152, '2026-06-05 14:07:46', 'df5a407a646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(18, 32, 18, 5, 7, 'kot_sent', 'KOT-20260605-00004 sent to kitchen with 2 item lines.', 152, '2026-06-05 14:07:50', 'df5a414a646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(19, 32, 18, 5, 7, 'payment_completed', 'Restaurant bill paid. Invoice: INV-26-00077 Paid: 150 Change: 0', 152, '2026-06-05 14:08:26', 'df5a41f8646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(20, 32, 18, 6, 7, 'start_session', '{\"table_no\":\"1\",\"guest_count\":1,\"waiter_id\":0,\"customer_id\":0,\"order_type\":\"dine_in\"}', 152, '2026-06-05 14:08:50', 'df5a42a4646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(21, 32, 18, 5, 7, 'kot_status_updated', 'KOT-20260605-00004 changed from pending to ready', 152, '2026-06-05 14:11:16', 'df5a4355646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(22, 32, 18, 7, 6, 'start_session', '{\"table_no\":\"6\",\"guest_count\":1,\"waiter_id\":0,\"customer_id\":0,\"order_type\":\"dine_in\"}', 152, '2026-06-06 06:42:23', 'df5a4400646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(23, 32, 18, 6, 7, 'item_added', 'chicken steak x 1', 152, '2026-06-06 07:14:15', 'df5a44a9646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(24, 32, 18, 6, 7, 'item_removed', 'chicken steak', 152, '2026-06-06 07:14:39', 'df5a454c646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(25, 32, 18, 6, 7, 'item_added', 'raita x 1', 152, '2026-06-06 07:14:42', 'df5a45ee646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(26, 32, 18, 6, 7, 'item_added', 'raita x 1', 152, '2026-06-06 07:14:57', 'df5a468f646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(27, 32, 18, 6, 7, 'item_removed', 'raita', 152, '2026-06-06 07:15:06', 'df5a4731646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(28, 32, 18, 6, 7, 'kot_sent', 'KOT-20260606-00005 sent to kitchen with 1 item lines.', 152, '2026-06-06 07:15:22', 'df5a47d1646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(29, 32, 18, 6, 7, 'bill_printed', 'Customer bill printed/previewed.', 152, '2026-06-06 07:15:50', 'df5a4873646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(30, 32, 0, 4, 2, 'item_added', 'colddrink x 1', 152, '2026-06-06 07:16:08', 'df5a491c646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(31, 32, 0, 4, 2, 'kot_sent', 'KOT-20260606-00006 sent to kitchen with 1 item lines.', 152, '2026-06-06 07:30:29', 'df5a49bc646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(32, 32, 0, 4, 2, 'item_added', 'chicken steak x 1', 152, '2026-06-06 07:30:58', 'df5a4a5c646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(33, 32, 0, 4, 2, 'kot_sent', 'KOT-20260606-00007 sent to kitchen with 1 item lines.', 152, '2026-06-06 07:31:03', 'df5a4afc646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(34, 32, 0, 4, 2, 'bill_printed', 'Customer bill printed/previewed.', 152, '2026-06-06 07:31:20', 'df5a4b9d646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(35, 32, 18, 7, 6, 'item_added', 'chopped steak x 1', 152, '2026-06-06 07:31:49', 'df5a4c44646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(36, 32, 18, 7, 6, 'item_added', 'colddrink x 1', 152, '2026-06-06 07:31:50', 'df5a4ce4646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(37, 32, 18, 7, 6, 'item_added', 'raita x 1', 152, '2026-06-06 07:31:51', 'df5a4d82646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(38, 32, 18, 7, 6, 'item_added', 'chicken steak x 1', 152, '2026-06-06 07:31:52', 'df5a4e21646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(39, 32, 18, 7, 6, 'kot_sent', 'KOT-20260606-00008 sent to kitchen with 4 item lines.', 152, '2026-06-06 07:31:54', 'df5a4ec0646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(40, 32, 18, 7, 6, 'kot_status_updated', 'KOT-20260606-00008 changed from pending to preparing', 152, '2026-06-06 07:32:25', 'df5a4f91646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(41, 32, 18, 7, 6, 'kot_status_updated', 'KOT-20260606-00008 changed from preparing to ready', 152, '2026-06-06 07:32:32', 'df5a5038646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(42, 32, 18, 8, 4, 'session_opened_from_pos', 'Session opened from simplified POS.', 152, '2026-06-06 07:33:27', 'df5a50dd646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(43, 32, 18, 6, 7, 'item_added', 'chopped steak x 1', 152, '2026-06-06 07:50:06', 'df5a517f646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(44, 32, 18, 8, 4, 'item_added', 'chopped steak x 1', 152, '2026-06-06 08:36:11', 'df5a5220646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(45, 32, 18, 8, 4, 'item_added', 'chopped steak x 1', 152, '2026-06-06 08:36:11', 'df5a52be646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(46, 32, 18, 8, 4, 'item_added', 'chopped steak x 1', 152, '2026-06-06 08:36:12', 'df5a535d646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(47, 32, 0, 2, 3, 'bill_printed', 'Customer bill printed/previewed.', 152, '2026-06-06 10:19:31', 'df5a53d4646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(48, 32, 0, 2, 3, 'item_added', 'chopped steak x 1', 152, '2026-06-06 12:06:40', 'df5a544b646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(49, 32, 18, 6, 7, 'kot_sent', 'KOT-20260606-00009 sent to kitchen with 1 item lines.', 152, '2026-06-06 12:07:04', 'df5a54bb646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(50, 32, 18, 7, 6, 'bill_printed', 'Bill printed from multi-table POS.', 152, '2026-06-08 07:08:47', 'df5a5530646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(51, 32, 18, 7, 6, 'bill_printed', 'Customer bill printed/previewed.', 152, '2026-06-08 07:08:47', 'df5a55a2646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(52, 32, 0, 4, 2, 'item_added', 'colddrink x 1', 152, '2026-06-08 07:12:41', 'df5a561b646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(53, 32, 0, 4, 2, 'kot_sent', 'KOT-20260608-00010 sent to kitchen with 1 item lines.', 152, '2026-06-08 07:12:50', 'df5a568d646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(54, 32, 18, 7, 6, 'bill_printed', 'Bill printed from multi-table POS.', 152, '2026-06-08 07:13:57', 'df5a56ff646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(55, 32, 0, 4, 2, 'item_added', 'raita x 1', 152, '2026-06-08 07:14:25', 'df5a5772646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(56, 27, 18, 9, 8, 'session_opened_from_pos', 'Session started from multi-table restaurant POS.', 138, '2026-06-08 07:44:25', 'df5a57e3646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(57, 27, 18, 9, 8, 'item_added', 'CAMRA x 1', 138, '2026-06-08 07:44:30', 'df5a5858646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(58, 27, 18, 9, 8, 'item_added', 'kuch x 1', 138, '2026-06-08 07:44:31', 'df5a58c9646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(59, 27, 18, 9, 8, 'item_added', 'water galon x 1', 138, '2026-06-08 07:44:31', 'df5a593c646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(60, 27, 18, 9, 8, 'item_added', 'water x 1', 138, '2026-06-08 07:44:32', 'df5a59ae646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(61, 27, 18, 9, 8, 'kot_sent', 'KOT-20260608-00011 sent to kitchen with 4 item lines.', 138, '2026-06-08 07:44:34', 'df5a5a21646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(62, 27, 18, 9, 8, 'item_added', 'kuch x 1', 138, '2026-06-08 08:00:29', 'df5a5ca2646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(63, 27, 18, 9, 8, 'kot_sent', 'KOT-20260608-00012 sent to kitchen with 1 item lines.', 138, '2026-06-08 08:00:35', 'df5a5d9c646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(64, 27, 18, 9, 8, 'payment_completed', 'Restaurant bill paid. Invoice: INV-26-00078 Paid: 1800 Change: 0.02', 138, '2026-06-08 08:08:54', 'df5a5e77646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(65, 34, 0, 10, 9, 'start_session', '{\"table_no\":\"1\",\"guest_count\":1,\"waiter_id\":0,\"customer_id\":0,\"order_type\":\"dine_in\"}', 156, '2026-06-08 08:34:38', 'df5a5f4f646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(66, 34, 0, 10, 9, 'item_added', 'Chiekn Karai x 1', 156, '2026-06-08 08:36:17', 'df5a6023646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(67, 34, 0, 11, 10, 'session_opened_from_pos', 'Session started from multi-table restaurant POS.', 156, '2026-06-08 08:41:04', 'df5a60be646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(68, 34, 0, 10, 9, 'item_added', 'Chiekn Karai x 1', 156, '2026-06-08 08:48:53', 'df5a613f646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(69, 34, 0, 10, 9, 'item_added', 'Chiekn Karai x 1', 156, '2026-06-08 08:48:53', 'df5a621b646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(70, 34, 0, 10, 9, 'kot_sent', 'KOT-20260608-00013 sent to kitchen with 3 item lines.', 156, '2026-06-08 08:49:19', 'df5a6337646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(71, 27, 18, 12, 8, 'start_session', '{\"table_no\":\"31\",\"guest_count\":1,\"waiter_id\":0,\"customer_id\":0,\"order_type\":\"dine_in\"}', 138, '2026-06-08 10:04:41', 'df5a63fb646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(72, 34, 0, 11, 10, 'item_added', 'Chiekn Karai x 1', 156, '2026-06-08 12:16:25', 'df5a64d3646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(73, 34, 0, 11, 10, 'kot_sent', 'KOT-20260608-00014 sent to kitchen with 1 item lines.', 156, '2026-06-08 12:16:27', 'df5a6579646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(74, 34, 0, 10, 9, 'kot_status_updated', 'KOT-20260608-00013 changed from pending to ready', 156, '2026-06-08 12:19:43', 'df5a65f2646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(75, 34, 0, 13, NULL, 'start_takeaway_session', '{\"order_type\":\"takeaway\",\"token_no\":\"T260608-001\",\"customer_id\":0,\"customer_name\":\"Gapsoe Digital\",\"customer_phone\":\"2723661684\",\"delivery_address\":\"\",\"delivery_charge\":0,\"rider_id\":0}', 156, '2026-06-08 13:38:39', 'df5a66c9646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(76, 34, 0, 14, NULL, 'start_delivery_session', '{\"order_type\":\"delivery\",\"token_no\":\"D260608-001\",\"customer_id\":0,\"customer_name\":\"Gapsoe Digital\",\"customer_phone\":\"2723661684\",\"delivery_address\":\"418 Broadway\\r\\nSTE 6276\",\"delivery_charge\":0,\"rider_id\":156}', 156, '2026-06-08 13:39:00', 'df5a6774646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(77, 34, 0, 15, NULL, 'start_takeaway_session', '{\"order_type\":\"takeaway\",\"token_no\":\"T260608-002\",\"customer_id\":0,\"customer_name\":\"Gapsoe Digital\",\"customer_phone\":\"2723661684\",\"delivery_address\":\"\",\"delivery_charge\":0,\"rider_id\":0}', 156, '2026-06-08 13:46:47', 'df5a6801646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(78, 34, 0, 15, 0, 'item_added', 'Chiekn Karai x 1', 156, '2026-06-08 13:46:52', 'df5a688d646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(79, 34, 0, 15, 0, 'kot_sent', 'KOT-20260608-00015 sent to kitchen with 1 item lines.', 156, '2026-06-08 13:46:56', 'df5a690d646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(80, 34, 0, 15, NULL, 'bill_printed', 'Bill printed from multi-table POS.', 156, '2026-06-08 13:47:22', 'df5a698b646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(81, 34, 0, 15, NULL, 'bill_printed', 'Customer bill printed/previewed.', 156, '2026-06-08 13:47:22', 'df5a6a09646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(82, 34, 0, 11, 10, 'bill_printed', 'Customer bill printed/previewed.', 156, '2026-06-08 14:16:48', 'df5a6a82646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(83, 34, 0, 11, 10, 'bill_printed', 'Bill printed from multi-table POS.', 156, '2026-06-08 14:16:48', 'df5a6afc646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(84, 34, 0, 16, NULL, 'start_takeaway_session', '{\"order_type\":\"takeaway\",\"token_no\":\"T260608-003\",\"customer_id\":0,\"customer_name\":\"misba\",\"customer_phone\":\"2723661684\",\"delivery_address\":\"\",\"delivery_charge\":0,\"rider_id\":0}', 156, '2026-06-08 14:17:03', 'df5a6b7b646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(85, 34, 0, 16, 0, 'item_added', 'Chiekn Karai x 1', 156, '2026-06-08 14:17:10', 'df5a6bff646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(86, 34, 0, 16, 0, 'item_added', 'Chiekn Karai x 1', 156, '2026-06-08 14:17:10', 'df5a6c77646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(87, 34, 0, 16, NULL, 'kot_sent', 'KOT-20260608-00016 sent to kitchen with 2 item lines. Type: Takeaway | Token: T260608-003 | Customer: misba | Phone: 2723661684', 156, '2026-06-08 14:17:13', 'df5a6cee646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(88, 34, 0, 16, NULL, 'kot_status_updated', 'KOT-20260608-00016 changed from pending to preparing', 156, '2026-06-08 14:17:59', 'df5a6d73646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(89, 34, 0, 10, 9, 'kot_status_updated', 'KOT-20260608-00013 changed from ready to served', 156, '2026-06-08 14:19:02', 'df5a6e21646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(90, 34, 0, 16, NULL, 'kot_status_updated', 'KOT-20260608-00016 changed from preparing to ready', 156, '2026-06-08 14:19:11', 'df5a6ea6646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(91, 34, 0, 16, NULL, 'kot_status_updated', 'KOT-20260608-00016 changed from ready to served', 156, '2026-06-08 14:19:49', 'df5a6f24646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(92, 34, 0, 15, NULL, 'kot_status_updated', 'KOT-20260608-00015 changed from pending to preparing', 156, '2026-06-08 14:40:31', 'df5a6fc5646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(93, 34, 0, 17, NULL, 'start_takeaway_session', '{\"order_type\":\"takeaway\",\"token_no\":\"T260608-004\",\"customer_id\":0,\"customer_name\":\"sana\",\"customer_phone\":\"2723661684\",\"delivery_address\":\"\",\"delivery_charge\":0,\"rider_id\":0}', 156, '2026-06-08 14:41:32', 'df5a7047646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(94, 34, 0, 17, 0, 'item_added', 'Chiekn Karai x 1', 156, '2026-06-08 14:41:38', 'df5a70cc646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(95, 34, 0, 17, NULL, 'kot_sent', 'KOT-20260608-00017 sent to kitchen with 1 item lines. Type: Takeaway | Token: T260608-004 | Customer: sana | Phone: 2723661684', 156, '2026-06-08 14:41:41', 'df5a7148646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(96, 34, 0, 11, 10, 'kot_status_updated', 'KOT-20260608-00014 changed from pending to preparing', 156, '2026-06-08 14:42:36', 'df5a71ca646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(97, 34, 0, 15, NULL, 'kot_status_updated', 'KOT-20260608-00015 changed from preparing to ready', 156, '2026-06-08 14:43:54', 'df5a72a1646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(98, 34, 0, 18, NULL, 'start_takeaway_session', '{\"order_type\":\"takeaway\",\"token_no\":\"T260609-001\",\"customer_id\":0,\"customer_name\":\"sana\",\"customer_phone\":\"2723661684\",\"delivery_address\":\"\",\"delivery_charge\":0,\"rider_id\":0}', 156, '2026-06-09 06:44:33', 'df5a7320646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(99, 34, 0, 18, 0, 'item_added', 'Chiekn Karai x 1', 156, '2026-06-09 06:44:41', 'df5a73a1646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(100, 34, 0, 18, 0, 'item_added', 'Chiekn Karai x 1', 156, '2026-06-09 06:44:42', 'df5a741f646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(101, 34, 0, 18, NULL, 'kot_sent', 'KOT-20260609-00018 sent to kitchen with 2 item lines. Type: Takeaway | Token: T260609-001 | Customer: sana | Phone: 2723661684', 156, '2026-06-09 06:44:45', 'df5a7497646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(102, 34, 0, 17, NULL, 'bill_printed', 'Customer bill printed/previewed.', 156, '2026-06-09 06:51:38', 'df5a7514646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(103, 34, 0, 17, NULL, 'bill_printed', 'Customer bill printed/previewed.', 156, '2026-06-09 06:54:34', 'df5a771e646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(104, 34, 0, 18, NULL, 'kot_status_updated', 'KOT-20260609-00018 changed from pending to preparing', 156, '2026-06-09 06:55:52', 'df5a779a646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(105, 34, 0, 18, NULL, 'kot_status_updated', 'KOT-20260609-00018 changed from preparing to ready', 156, '2026-06-09 06:56:08', 'df5a7810646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(106, 34, 0, 17, 0, 'item_added', 'Chiekn Karai x 1', 156, '2026-06-09 06:56:35', 'df5a78a9646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(107, 34, 0, 17, NULL, 'kot_sent', 'KOT-20260609-00019 sent to kitchen with 1 item lines. Type: Takeaway | Token: T260608-004 | Customer: sana | Phone: 2723661684', 156, '2026-06-09 06:56:38', 'df5a7948646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(108, 34, 0, 18, NULL, 'kot_status_updated', 'KOT-20260609-00018 changed from ready to served - Kitchen completed', 156, '2026-06-09 07:02:12', 'df5a79d1646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(109, 34, 0, 17, NULL, 'kot_status_updated', 'KOT-20260609-00019 changed from pending to preparing', 156, '2026-06-09 07:03:25', 'df5a7ab4646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(110, 34, 0, 19, 11, 'start_dine_in_session', '{\"table_no\":\"051\",\"guest_count\":1,\"waiter_id\":0,\"customer_id\":0,\"order_type\":\"dine_in\"}', 156, '2026-06-09 08:02:19', 'df5a7b34646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(111, 34, 0, 19, 11, 'item_added', 'Chiekn Karai x 1', 156, '2026-06-09 08:02:22', 'df5a7bbc646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(112, 34, 0, 19, 11, 'item_added', 'Chiekn Karai x 1', 156, '2026-06-09 08:02:23', 'df5a7c37646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(113, 34, 0, 19, 11, 'kot_sent', 'KOT-20260609-00020 sent to kitchen with 2 item lines. Type: Dine In | Table: 051', 156, '2026-06-09 08:02:26', 'df5a7cb1646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(114, 34, 0, 17, NULL, 'kot_status_updated', 'KOT-20260609-00019 changed from preparing to ready', 156, '2026-06-09 08:02:41', 'df5a7d2f646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(115, 34, 0, 19, 11, 'kot_status_updated', 'KOT-20260609-00020 changed from pending to ready', 156, '2026-06-09 08:05:14', 'df5a7daa646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(116, 34, 0, 17, NULL, 'kot_status_updated', 'KOT-20260609-00019 changed from ready to served - Kitchen completed', 156, '2026-06-09 08:05:20', 'df5a7e50646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(117, 34, 0, 19, 11, 'kot_status_updated', 'KOT-20260609-00020 changed from ready to served - Kitchen completed', 156, '2026-06-09 08:06:33', 'df5a7ed3646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(118, 34, 0, 20, NULL, 'start_takeaway_session', '{\"order_type\":\"takeaway\",\"token_no\":\"T260609-002\",\"customer_id\":0,\"customer_name\":\"Walk-in\",\"customer_phone\":\"\",\"delivery_address\":\"\",\"delivery_charge\":0,\"rider_id\":0}', 156, '2026-06-09 08:23:49', 'df5a7f51646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(119, 34, 0, 20, 0, 'item_added', 'Chiekn Karai x 1', 156, '2026-06-09 08:23:56', 'df5a7fd9646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(120, 34, 0, 20, NULL, 'kot_sent', 'KOT-20260609-00021 sent to kitchen with 1 item lines. Type: Takeaway | Token: T260609-002 | Customer: Walk-in', 156, '2026-06-09 08:23:56', 'df5a8056646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(121, 34, 0, 11, 10, 'item_added', 'Chiekn Karai x 1', 156, '2026-06-09 08:30:52', 'df5a80d9646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(122, 34, 0, 11, 10, 'item_added', 'Chiekn Karai x 1', 156, '2026-06-09 08:30:53', 'df5a8151646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(123, 34, 0, 11, 10, 'bill_printed', 'Customer bill printed/previewed.', 156, '2026-06-09 08:31:04', 'df5a81c9646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(124, 34, 0, 21, 9, 'start_dine_in_session', '{\"table_no\":\"1\",\"guest_count\":4,\"waiter_id\":0,\"customer_id\":0,\"order_type\":\"dine_in\"}', 156, '2026-06-09 08:54:09', 'df5a8244646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(125, 34, 0, 21, 9, 'item_added', 'Chiekn Karai x 1', 156, '2026-06-09 08:54:13', 'df5a82dc646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(126, 34, 0, 21, 9, 'bill_printed', 'Customer bill printed/previewed.', 156, '2026-06-09 08:54:23', 'df5a8359646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(127, 34, 0, 15, NULL, 'bill_printed', 'Customer bill printed/previewed.', 156, '2026-06-09 08:58:46', 'df5a83d4646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(128, 34, 0, 20, NULL, 'kot_status_updated', 'KOT-20260609-00021 changed from pending to ready', 156, '2026-06-09 08:59:51', 'df5a846e646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(129, 34, 0, 20, NULL, 'bill_printed', 'Customer bill printed/previewed.', 156, '2026-06-09 10:09:36', 'df5a84f5646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(130, 34, 0, 22, 11, 'start_dine_in_session', '{\"table_no\":\"051\",\"guest_count\":1,\"waiter_id\":0,\"customer_id\":0,\"order_type\":\"dine_in\"}', 156, '2026-06-09 10:13:34', 'df5a8576646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(131, 34, 0, 20, NULL, 'kot_status_updated', 'KOT-20260609-00021 changed from ready to served - Kitchen completed', 156, '2026-06-09 10:14:38', 'df5a85f8646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(132, 34, 0, 23, 13, 'session_opened_from_pos', 'Session started from multi-table restaurant POS.', 156, '2026-06-09 11:38:53', 'df5a8677646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(133, 34, 0, 23, 13, 'item_added', 'Chiekn Karai x 1', 156, '2026-06-09 11:39:30', 'df5a8871646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(134, 34, 0, 23, 13, 'kot_sent', 'KOT-20260609-00022 sent to kitchen with 1 item lines. Type: Dine In | Table: 521', 156, '2026-06-09 11:39:32', 'df5a88eb646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(135, 34, 0, 23, 13, 'item_added', 'Chiekn Karai x 1', 156, '2026-06-09 11:46:21', 'df5a8a1f646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(136, 34, 0, 23, 13, 'kot_sent', 'KOT-20260609-00023 sent to kitchen with 1 item lines. Type: Dine In | Table: 521', 156, '2026-06-09 11:46:22', 'df5a8aa4646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(137, 34, 0, 23, 13, 'kot_status_updated', 'KOT-20260609-00023 changed from pending to preparing', 156, '2026-06-09 11:46:29', 'df5a8b26646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(138, 34, 0, 23, 13, 'kot_status_updated', 'KOT-20260609-00023 changed from preparing to ready', 156, '2026-06-09 11:46:34', 'df5a8ba6646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(139, 34, 0, 23, 13, 'item_added', 'Chiekn Karai x 1', 156, '2026-06-09 12:34:26', 'df5a8c25646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(140, 34, 0, 23, 13, 'item_added', 'Chiekn Karai x 1', 156, '2026-06-09 12:34:26', 'df5a8ca4646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(141, 34, 0, 23, 13, 'item_added', 'Chiekn Karai x 1', 156, '2026-06-09 12:34:27', 'df5a8d22646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(142, 34, 0, 23, 13, 'kot_sent', 'KOT-20260609-00024 sent to kitchen with 3 item lines. Type: Dine In | Table: 521', 156, '2026-06-09 12:34:29', 'df5a8d9e646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(143, 34, 0, 24, 14, 'session_opened_from_pos', 'Session started from multi-table restaurant POS.', 156, '2026-06-09 12:35:29', 'df5a8e27646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(144, 34, 0, 24, 14, 'item_added', 'Chiekn Karai x 1', 156, '2026-06-09 12:35:34', 'df5a8ea7646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(145, 34, 0, 24, 14, 'kot_sent', 'KOT-20260609-00025 sent to kitchen with 1 item lines. Type: Dine In | Table: 33', 156, '2026-06-09 12:35:36', 'df5a8f3c646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(146, 34, 0, 25, 9, 'session_opened_from_pos', 'Session started from multi-table restaurant POS.', 156, '2026-06-09 12:45:00', 'df5a8fc3646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(147, 34, 0, 25, 9, 'item_added', 'Chiekn Karai x 1', 156, '2026-06-09 12:45:02', 'df5a9043646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(148, 34, 0, 25, 9, 'kot_sent', 'KOT-20260609-00026 sent to kitchen with 1 item lines. Type: Dine In | Table: 1', 156, '2026-06-09 12:45:05', 'df5a90c2646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(149, 34, 0, 25, 9, 'item_added', 'Chiekn Karai x 1', 156, '2026-06-09 13:01:06', 'df5a9143646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(150, 34, 0, 25, 9, 'kot_sent', 'KOT-20260609-00027 sent to kitchen with 1 item lines. Type: Dine In | Table: 1', 156, '2026-06-09 13:01:13', 'df5a91c0646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(151, 34, 0, 26, 10, 'session_opened_from_pos', 'Session started from multi-table restaurant POS.', 156, '2026-06-09 14:00:47', 'df5a9242646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(152, 34, 0, 26, 10, 'item_added', 'Chiekn Karai x 1', 156, '2026-06-09 14:00:49', 'df5a92c5646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(153, 34, 0, 26, 10, 'kot_sent', 'KOT-20260609-00028 sent to kitchen with 1 item lines. Type: Dine In | Table: 10', 156, '2026-06-09 14:00:52', 'df5a9341646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(154, 34, 0, 26, 10, 'bill_printed', 'Customer bill printed/previewed.', 156, '2026-06-09 14:06:35', 'df5a93c2646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(155, 34, 0, 27, 14, 'session_opened_from_pos', 'Session started from multi-table restaurant POS.', 156, '2026-06-09 14:08:55', 'df5a9483646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(156, 34, 0, 27, 14, 'item_added', 'Chiekn Karai x 1', 156, '2026-06-09 14:08:57', 'df5a9570646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(157, 34, 0, 27, 14, 'kot_sent', 'KOT-20260609-00029 sent to kitchen with 1 item lines. Type: Dine In | Table: 33', 156, '2026-06-09 14:08:58', 'df5a9661646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(158, 34, 0, 26, 10, 'item_added', 'Chiekn Karai x 1', 156, '2026-06-11 20:59:40', NULL, NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(159, 34, 0, 26, 10, 'kot_sent', 'KOT-20260611-00030 sent to kitchen with 1 item lines. Type: Dine In | Table: 10', 156, '2026-06-11 20:59:42', NULL, NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL),
(160, 34, 0, 26, 10, 'bill_printed', 'Customer bill printed/previewed.', 156, '2026-06-11 21:00:32', NULL, NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `restaurant_kitchen_status_logs`
--

CREATE TABLE `restaurant_kitchen_status_logs` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL DEFAULT 0,
  `kot_id` int(11) DEFAULT NULL,
  `kot_item_id` int(11) DEFAULT NULL,
  `session_id` int(11) DEFAULT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) NOT NULL,
  `notes` text DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL,
  `session_uuid` varchar(64) DEFAULT NULL,
  `kot_uuid` varchar(64) DEFAULT NULL,
  `kot_item_uuid` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `restaurant_kitchen_status_logs`
--

INSERT INTO `restaurant_kitchen_status_logs` (`id`, `client_id`, `branch_id`, `kot_id`, `kot_item_id`, `session_id`, `old_status`, `new_status`, `notes`, `changed_by`, `created_at`, `uuid`, `device_id`, `sync_status`, `last_synced_at`, `deleted_at`, `created_at_local`, `updated_at_local`, `session_uuid`, `kot_uuid`, `kot_item_uuid`) VALUES
(116, 34, 0, 30, NULL, 26, NULL, 'pending', 'KOT created and sent to kitchen. Type: Dine In | Table: 10', 156, '2026-06-11 20:59:42', NULL, NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `restaurant_kots`
--

CREATE TABLE `restaurant_kots` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL DEFAULT 0,
  `session_id` int(11) NOT NULL,
  `table_id` int(11) DEFAULT NULL,
  `waiter_id` int(11) DEFAULT NULL,
  `rider_id` int(11) DEFAULT NULL,
  `kot_no` varchar(50) NOT NULL,
  `order_type` varchar(30) NOT NULL DEFAULT 'dine_in',
  `token_no` varchar(50) DEFAULT NULL,
  `customer_name` varchar(150) DEFAULT NULL,
  `customer_phone` varchar(50) DEFAULT NULL,
  `delivery_address` text DEFAULT NULL,
  `status` enum('pending','preparing','processing','process','ready','served','completed','complete','cancelled','canceled') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `printed_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL,
  `session_uuid` varchar(64) DEFAULT NULL,
  `table_uuid` varchar(64) DEFAULT NULL,
  `waiter_uuid` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `restaurant_kots`
--

INSERT INTO `restaurant_kots` (`id`, `client_id`, `branch_id`, `session_id`, `table_id`, `waiter_id`, `rider_id`, `kot_no`, `order_type`, `token_no`, `customer_name`, `customer_phone`, `delivery_address`, `status`, `notes`, `printed_at`, `created_by`, `created_at`, `updated_at`, `completed_at`, `uuid`, `device_id`, `sync_status`, `last_synced_at`, `deleted_at`, `created_at_local`, `updated_at_local`, `session_uuid`, `table_uuid`, `waiter_uuid`) VALUES
(30, 34, 0, 26, 10, NULL, NULL, 'KOT-20260611-00030', 'dine_in', '', '', '', '', 'pending', 'Type: Dine In | Table: 10', NULL, 156, '2026-06-11 20:59:42', '2026-06-11 20:59:42', NULL, NULL, NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `restaurant_kot_items`
--

CREATE TABLE `restaurant_kot_items` (
  `id` int(11) NOT NULL,
  `kot_id` int(11) NOT NULL,
  `order_item_id` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `item_name` varchar(255) NOT NULL,
  `qty` decimal(12,2) NOT NULL DEFAULT 1.00,
  `note` text DEFAULT NULL,
  `status` enum('pending','preparing','processing','process','ready','served','completed','complete','cancelled','canceled') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL,
  `kot_uuid` varchar(64) DEFAULT NULL,
  `order_item_uuid` varchar(64) DEFAULT NULL,
  `item_uuid` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `restaurant_kot_items`
--

INSERT INTO `restaurant_kot_items` (`id`, `kot_id`, `order_item_id`, `item_id`, `item_name`, `qty`, `note`, `status`, `created_at`, `updated_at`, `uuid`, `device_id`, `sync_status`, `last_synced_at`, `deleted_at`, `created_at_local`, `updated_at_local`, `kot_uuid`, `order_item_uuid`, `item_uuid`) VALUES
(40, 26, 51, 1050, 'Chiekn Karai', 1.00, '0', 'served', '2026-06-09 12:45:05', '2026-06-10 01:44:09', 'df586905646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL, 'df7b3732646d11f1ae7470b5e88d388e'),
(41, 27, 52, 1050, 'Chiekn Karai', 1.00, '0', 'served', '2026-06-09 13:01:13', '2026-06-10 01:44:09', 'df587829646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL, 'df7b3732646d11f1ae7470b5e88d388e'),
(42, 28, 53, 1050, 'Chiekn Karai', 1.00, '0', 'served', '2026-06-09 14:00:52', '2026-06-10 01:44:09', 'df5879e6646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL, 'df7b3732646d11f1ae7470b5e88d388e'),
(43, 29, 54, 1050, 'Chiekn Karai', 1.00, '0', 'served', '2026-06-09 14:08:58', '2026-06-10 01:44:09', 'df587b07646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL, 'df7b3732646d11f1ae7470b5e88d388e'),
(44, 30, 55, 1050, 'Chiekn Karai', 1.00, '0', 'pending', '2026-06-11 20:59:42', NULL, NULL, NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `restaurant_order_items`
--

CREATE TABLE `restaurant_order_items` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL DEFAULT 0,
  `session_id` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `item_name` varchar(255) NOT NULL,
  `qty` decimal(12,2) NOT NULL DEFAULT 1.00,
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `kot_status` enum('pending','sent') NOT NULL DEFAULT 'pending',
  `item_status` enum('active','preparing','ready','served','cancelled','void') NOT NULL DEFAULT 'active',
  `special_note` text DEFAULT NULL,
  `cancelled_reason` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL,
  `session_uuid` varchar(64) DEFAULT NULL,
  `item_uuid` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `restaurant_order_items`
--

INSERT INTO `restaurant_order_items` (`id`, `client_id`, `branch_id`, `session_id`, `item_id`, `item_name`, `qty`, `price`, `discount`, `tax`, `total`, `kot_status`, `item_status`, `special_note`, `cancelled_reason`, `created_by`, `cancelled_by`, `created_at`, `updated_at`, `uuid`, `device_id`, `sync_status`, `last_synced_at`, `deleted_at`, `created_at_local`, `updated_at_local`, `session_uuid`, `item_uuid`) VALUES
(55, 34, 0, 26, 1050, 'Chiekn Karai', 1.00, 1600.00, 0.00, 0.00, 1600.00, 'sent', 'active', '0', NULL, 156, NULL, '2026-06-11 20:59:40', '2026-06-11 20:59:42', NULL, NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `restaurant_reservations`
--

CREATE TABLE `restaurant_reservations` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL DEFAULT 0,
  `table_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `customer_name` varchar(150) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `guest_count` int(11) NOT NULL DEFAULT 1,
  `reservation_date` date NOT NULL,
  `reservation_time` time NOT NULL,
  `status` enum('pending','confirmed','seated','cancelled','no_show','completed') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `restaurant_session_tables`
--

CREATE TABLE `restaurant_session_tables` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL DEFAULT 0,
  `session_id` int(11) NOT NULL,
  `table_id` int(11) NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL,
  `session_uuid` varchar(64) DEFAULT NULL,
  `table_uuid` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `restaurant_settings`
--

CREATE TABLE `restaurant_settings` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL DEFAULT 0,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `restaurant_settings`
--

INSERT INTO `restaurant_settings` (`id`, `client_id`, `branch_id`, `setting_key`, `setting_value`, `created_at`, `updated_at`, `uuid`, `device_id`, `sync_status`, `last_synced_at`, `deleted_at`, `created_at_local`, `updated_at_local`) VALUES
(1, 32, 0, 'restaurant_name', '', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83bcd8646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(2, 32, 0, 'currency_symbol', 'Rs.', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83c0b1646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(3, 32, 0, 'default_order_type', 'dine_in', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83c24a646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(4, 32, 0, 'default_guest_count', '1', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83c38b646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(5, 32, 0, 'tax_percentage', '0.00', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83c4a3646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(6, 32, 0, 'default_tax_percent', '0.00', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83c5d4646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(7, 32, 0, 'default_tax_percentage', '0.00', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83c6f6646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(8, 32, 0, 'service_charge_percentage', '0.00', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83c805646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(9, 32, 0, 'default_service_charge_percent', '0.00', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83c970646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(10, 32, 0, 'service_percentage', '0.00', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83ca84646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(11, 32, 0, 'allow_discount', '1', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83cb9e646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(12, 32, 0, 'rounding_mode', 'none', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83ccaf646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(13, 32, 0, 'kot_no_prefix', 'KOT', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83cde2646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(14, 32, 0, 'kot_print_mode', 'browser', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83cf0b646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(15, 32, 0, 'auto_print_kot', '0', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83d01c646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(16, 32, 0, 'kot_copies', '1', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83d12a646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(17, 32, 0, 'bill_no_prefix', 'INV', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83d238646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(18, 32, 0, 'bill_print_format', 'thermal', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83d338646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(19, 32, 0, 'bill_copies', '1', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83d446646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(20, 32, 0, 'show_kitchen_notes', '1', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83d543646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(21, 32, 0, 'table_after_payment_status', 'Available', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83d63b646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(22, 32, 0, 'enable_table_cleaning', '1', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83d73f646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(23, 32, 0, 'allow_item_cancel_after_kot', '1', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83d83b646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(24, 32, 0, 'require_cancel_reason', '1', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83d926646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(25, 32, 0, 'enable_table_transfer', '1', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83da3c646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(26, 32, 0, 'enable_table_merge', '1', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83db31646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(27, 32, 0, 'enable_split_bill', '1', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83dc2e646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(28, 32, 0, 'enable_takeaway_without_table', '1', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83dd17646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(29, 32, 0, 'receipt_footer_note', 'Thank you for dining with us!', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83de3d646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(30, 32, 0, 'kot_footer_note', '', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83df82646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(31, 32, 0, 'bill_terms', '', '2026-06-03 14:17:19', '2026-06-10 01:44:08', 'df83e091646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(32, 34, 0, 'restaurant_name', '', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df83e1d2646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(33, 34, 0, 'currency_symbol', 'Rs.', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df83e2f2646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(34, 34, 0, 'default_order_type', 'dine_in', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df83e3ed646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(35, 34, 0, 'default_guest_count', '1', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df83e505646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(36, 34, 0, 'tax_percentage', '0.00', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df83e5f6646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(37, 34, 0, 'default_tax_percent', '0.00', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df83e75a646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(38, 34, 0, 'default_tax_percentage', '0.00', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df83e879646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(39, 34, 0, 'service_charge_percentage', '0.00', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df83e96c646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(40, 34, 0, 'default_service_charge_percent', '0.00', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df83ea69646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(41, 34, 0, 'service_percentage', '0.00', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df83eb87646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(42, 34, 0, 'allow_discount', '1', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df83ecb6646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(43, 34, 0, 'rounding_mode', 'none', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df83edbf646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(44, 34, 0, 'kot_no_prefix', 'KOT', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df83eec8646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(45, 34, 0, 'kot_print_mode', 'browser', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df83efdd646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(46, 34, 0, 'auto_print_kot', '0', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df83f11d646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(47, 34, 0, 'kot_copies', '1', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df83f236646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(48, 34, 0, 'bill_no_prefix', 'INV', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df83f352646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(49, 34, 0, 'bill_print_format', 'thermal', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df83f458646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(50, 34, 0, 'bill_copies', '1', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df83f574646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(51, 34, 0, 'show_kitchen_notes', '1', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df83fab6646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(52, 34, 0, 'table_after_payment_status', 'Available', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df83fbb8646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(53, 34, 0, 'enable_table_cleaning', '1', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df83fcc7646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(54, 34, 0, 'allow_item_cancel_after_kot', '1', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df83fdbe646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(55, 34, 0, 'require_cancel_reason', '1', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df83feeb646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(56, 34, 0, 'enable_table_transfer', '1', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df83fff4646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(57, 34, 0, 'enable_table_merge', '1', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df840116646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(58, 34, 0, 'enable_split_bill', '1', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df840215646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(59, 34, 0, 'enable_takeaway_without_table', '1', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df840596646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(60, 34, 0, 'receipt_footer_note', 'Thank you for dining with us!', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df840772646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(61, 34, 0, 'kot_footer_note', '', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df8408ad646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(62, 34, 0, 'bill_terms', '', '2026-06-09 08:46:05', '2026-06-10 01:44:08', 'df8409e2646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `restaurant_split_bills`
--

CREATE TABLE `restaurant_split_bills` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL DEFAULT 0,
  `session_id` int(11) NOT NULL,
  `split_no` varchar(50) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax` decimal(12,2) NOT NULL DEFAULT 0.00,
  `service_charge` decimal(12,2) NOT NULL DEFAULT 0.00,
  `grand_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('open','paid','cancelled') NOT NULL DEFAULT 'open',
  `sale_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `paid_at` datetime DEFAULT NULL,
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL,
  `session_uuid` varchar(64) DEFAULT NULL,
  `sale_uuid` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `restaurant_split_bill_items`
--

CREATE TABLE `restaurant_split_bill_items` (
  `id` int(11) NOT NULL,
  `split_bill_id` int(11) NOT NULL,
  `order_item_id` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `item_name` varchar(255) NOT NULL,
  `qty` decimal(12,2) NOT NULL DEFAULT 1.00,
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL,
  `split_bill_uuid` varchar(64) DEFAULT NULL,
  `order_item_uuid` varchar(64) DEFAULT NULL,
  `item_uuid` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `restaurant_tables`
--

CREATE TABLE `restaurant_tables` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `table_no` varchar(50) NOT NULL,
  `table_name` varchar(100) DEFAULT NULL,
  `floor_name` varchar(100) DEFAULT NULL,
  `capacity` int(11) NOT NULL DEFAULT 4,
  `current_session_id` int(11) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `status` enum('Available','Occupied','Reserved','Cleaning','Out of Service','Bill Printed','Dirty') NOT NULL DEFAULT 'Available',
  `waiter_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `restaurant_tables`
--

INSERT INTO `restaurant_tables` (`id`, `client_id`, `branch_id`, `table_no`, `table_name`, `floor_name`, `capacity`, `current_session_id`, `sort_order`, `is_active`, `status`, `waiter_id`, `created_at`, `updated_at`, `uuid`, `device_id`, `sync_status`, `last_synced_at`, `deleted_at`, `created_at_local`, `updated_at_local`) VALUES
(2, 32, 18, '005', 'barera', '5', 4, 4, 0, 1, 'Occupied', 3, '2026-06-03 11:26:39', '2026-06-10 01:44:08', 'df814ada646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(3, 32, 0, '9', 'm', '1', 6, 2, 0, 1, 'Occupied', 3, '2026-06-03 12:00:27', '2026-06-10 01:44:08', 'df814e7d646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(4, 32, 18, '7', 'ibad', '1', 4, 8, 0, 1, 'Occupied', 3, '2026-06-05 13:40:51', '2026-06-10 01:44:08', 'df814f6d646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(6, 32, 18, '6', 'sana', '1', 4, 7, 0, 1, 'Occupied', 3, '2026-06-05 13:51:01', '2026-06-10 01:44:08', 'df815057646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(7, 32, 18, '1', 'asad', '1', 2, 6, 0, 1, 'Occupied', 3, '2026-06-05 14:01:42', '2026-06-10 01:44:08', 'df81510f646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(8, 27, 18, '31', 'dheeraj', 'ground', 4, 12, 0, 1, 'Occupied', 3, '2026-06-08 07:39:50', '2026-06-10 01:44:08', 'df8151b2646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(9, 34, 0, '1', '2 Person Table', '2 floor', 2, 25, 0, 1, 'Occupied', NULL, '2026-06-08 08:23:32', '2026-06-10 01:44:08', 'df815273646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(10, 34, 0, '10', 'Family Table', 'Ground Floor', 12, 26, 0, 1, 'Bill Printed', NULL, '2026-06-08 08:33:21', '2026-06-11 21:00:32', 'df81531f646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(11, 34, 0, '051', 'alis', '1', 4, NULL, 0, 1, 'Available', 156, '2026-06-09 07:51:56', '2026-06-10 01:44:08', 'df8153c5646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(13, 34, 0, '521', NULL, NULL, 4, NULL, 0, 1, 'Available', NULL, '2026-06-09 10:24:51', '2026-06-10 01:44:08', 'df81546a646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(14, 34, 0, '33', NULL, NULL, 4, 27, 0, 1, 'Occupied', NULL, '2026-06-09 10:25:06', '2026-06-10 01:44:08', 'df815516646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `restaurant_table_sessions`
--

CREATE TABLE `restaurant_table_sessions` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL DEFAULT 0,
  `table_id` int(11) DEFAULT NULL,
  `waiter_id` int(11) DEFAULT NULL,
  `rider_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `customer_name` varchar(150) DEFAULT NULL,
  `customer_phone` varchar(50) DEFAULT NULL,
  `delivery_address` text DEFAULT NULL,
  `guest_count` int(11) NOT NULL DEFAULT 1,
  `order_type` enum('dine_in','takeaway','delivery') NOT NULL DEFAULT 'dine_in',
  `token_no` varchar(50) DEFAULT NULL,
  `status` enum('open','kot_sent','bill_printed','paid','cancelled','void') NOT NULL DEFAULT 'open',
  `delivery_status` enum('pending','preparing','ready','dispatched','delivered','cancelled') DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax` decimal(12,2) NOT NULL DEFAULT 0.00,
  `service_charge` decimal(12,2) NOT NULL DEFAULT 0.00,
  `delivery_charge` decimal(12,2) NOT NULL DEFAULT 0.00,
  `round_off` decimal(12,2) NOT NULL DEFAULT 0.00,
  `grand_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `sale_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `opened_by` int(11) DEFAULT NULL,
  `closed_by` int(11) DEFAULT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `bill_printed_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `kitchen_status` varchar(50) DEFAULT 'pending',
  `kitchen_completed_at` datetime DEFAULT NULL,
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL,
  `table_uuid` varchar(64) DEFAULT NULL,
  `waiter_uuid` varchar(64) DEFAULT NULL,
  `customer_uuid` varchar(64) DEFAULT NULL,
  `sale_uuid` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `restaurant_table_sessions`
--

INSERT INTO `restaurant_table_sessions` (`id`, `client_id`, `branch_id`, `table_id`, `waiter_id`, `rider_id`, `customer_id`, `customer_name`, `customer_phone`, `delivery_address`, `guest_count`, `order_type`, `token_no`, `status`, `delivery_status`, `subtotal`, `discount`, `tax`, `service_charge`, `delivery_charge`, `round_off`, `grand_total`, `paid_amount`, `balance_amount`, `sale_id`, `notes`, `opened_by`, `closed_by`, `started_at`, `bill_printed_at`, `closed_at`, `updated_at`, `kitchen_status`, `kitchen_completed_at`, `uuid`, `device_id`, `sync_status`, `last_synced_at`, `deleted_at`, `created_at_local`, `updated_at_local`, `table_uuid`, `waiter_uuid`, `customer_uuid`, `sale_uuid`) VALUES
(1, 32, 0, 2, NULL, NULL, NULL, NULL, NULL, NULL, 1, 'dine_in', NULL, 'paid', NULL, 1600.00, 0.00, 0.00, 0.00, 0.00, 0.00, 1600.00, 1600.00, 0.00, 74, 'chicken steak', 152, 152, '2026-06-03 11:57:18', NULL, '2026-06-04 07:18:12', '2026-06-10 01:44:09', 'pending', NULL, 'df567b01646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df814ada646d11f1ae7470b5e88d388e', NULL, NULL, 'df4a1939646d11f1ae7470b5e88d388e'),
(2, 32, 0, 3, 3, NULL, NULL, NULL, NULL, NULL, 3, 'takeaway', NULL, 'kot_sent', NULL, 5000.00, 0.00, 0.00, 0.00, 0.00, 0.00, 5000.00, 0.00, 5000.00, NULL, '', 152, NULL, '2026-06-03 12:57:30', '2026-06-06 10:19:31', NULL, '2026-06-10 01:44:09', 'pending', NULL, 'df567d71646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df814e7d646d11f1ae7470b5e88d388e', 'df828d64646d11f1ae7470b5e88d388e', NULL, NULL),
(3, 32, 0, 2, NULL, NULL, NULL, NULL, NULL, NULL, 1, 'dine_in', NULL, 'paid', NULL, 2500.00, 0.00, 0.00, 0.00, 0.00, 0.00, 2500.00, 2500.00, 0.00, 76, NULL, 152, 152, '2026-06-05 10:47:22', NULL, '2026-06-05 10:48:14', '2026-06-10 01:44:09', 'pending', NULL, 'df567eab646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df814ada646d11f1ae7470b5e88d388e', NULL, NULL, 'df4a1ae0646d11f1ae7470b5e88d388e'),
(4, 32, 0, 2, 3, NULL, NULL, NULL, NULL, NULL, 1, 'dine_in', NULL, 'kot_sent', NULL, 4350.00, 0.00, 0.00, 0.00, 0.00, 0.00, 4350.00, 0.00, 4350.00, NULL, '', 152, NULL, '2026-06-05 13:03:21', '2026-06-06 07:31:20', NULL, '2026-06-10 01:44:09', 'pending', NULL, 'df567fb9646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df814ada646d11f1ae7470b5e88d388e', 'df828d64646d11f1ae7470b5e88d388e', NULL, NULL),
(5, 32, 18, 7, NULL, NULL, NULL, NULL, NULL, NULL, 1, 'dine_in', NULL, 'paid', NULL, 150.00, 0.00, 0.00, 0.00, 0.00, 0.00, 150.00, 150.00, 0.00, 77, '', 152, 152, '2026-06-05 14:06:50', NULL, '2026-06-05 14:08:26', '2026-06-10 01:44:09', 'pending', NULL, 'df568225646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df81510f646d11f1ae7470b5e88d388e', NULL, NULL, 'df4a1ba6646d11f1ae7470b5e88d388e'),
(6, 32, 18, 7, 3, NULL, NULL, NULL, NULL, NULL, 1, 'dine_in', NULL, 'kot_sent', NULL, 2550.00, 0.00, 0.00, 0.00, 0.00, 0.00, 2550.00, 0.00, 2550.00, NULL, '', 152, NULL, '2026-06-05 14:08:50', '2026-06-06 07:15:50', NULL, '2026-06-10 01:44:09', 'pending', NULL, 'df56845b646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df81510f646d11f1ae7470b5e88d388e', 'df828d64646d11f1ae7470b5e88d388e', NULL, NULL),
(7, 32, 18, 6, 3, NULL, NULL, NULL, NULL, NULL, 1, 'dine_in', NULL, 'bill_printed', NULL, 4250.00, 0.00, 0.00, 0.00, 0.00, 0.00, 4250.00, 0.00, 4250.00, NULL, '', 152, NULL, '2026-06-06 06:42:23', '2026-06-08 07:08:47', NULL, '2026-06-10 01:44:09', 'pending', NULL, 'df56859e646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df815057646d11f1ae7470b5e88d388e', 'df828d64646d11f1ae7470b5e88d388e', NULL, NULL),
(8, 32, 18, 4, 3, NULL, NULL, NULL, NULL, NULL, 4, 'dine_in', NULL, 'open', NULL, 7500.00, 0.00, 0.00, 0.00, 0.00, 0.00, 7500.00, 0.00, 7500.00, NULL, NULL, 152, NULL, '2026-06-06 07:33:27', NULL, NULL, '2026-06-10 01:44:09', 'pending', NULL, 'df568694646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df814f6d646d11f1ae7470b5e88d388e', 'df828d64646d11f1ae7470b5e88d388e', NULL, NULL),
(9, 27, 18, 8, 3, NULL, NULL, NULL, NULL, NULL, 1, 'dine_in', NULL, 'paid', NULL, 1800.00, 0.02, 0.00, 0.00, 0.00, 0.00, 1799.98, 1800.00, 0.00, 78, NULL, 138, 138, '2026-06-08 07:44:25', NULL, '2026-06-08 08:08:54', '2026-06-10 01:44:09', 'pending', NULL, 'df5689de646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df8151b2646d11f1ae7470b5e88d388e', 'df828d64646d11f1ae7470b5e88d388e', NULL, 'df4a1c68646d11f1ae7470b5e88d388e'),
(10, 34, 0, 9, NULL, NULL, NULL, NULL, NULL, NULL, 1, 'dine_in', NULL, 'paid', NULL, 4800.00, 0.00, 0.00, 0.00, 0.00, 0.00, 4800.00, 0.00, 4800.00, NULL, '', 156, NULL, '2026-06-08 08:34:38', NULL, '2026-06-09 08:53:19', '2026-06-10 01:44:09', 'pending', NULL, 'df568bfe646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df815273646d11f1ae7470b5e88d388e', NULL, NULL, NULL),
(11, 34, 0, 10, NULL, NULL, NULL, NULL, NULL, NULL, 1, 'dine_in', NULL, 'paid', NULL, 4800.00, 0.00, 0.00, 0.00, 0.00, 0.00, 4800.00, 4800.00, 0.00, NULL, NULL, 156, 156, '2026-06-08 08:41:04', '2026-06-08 14:16:48', '2026-06-09 09:01:11', '2026-06-10 01:44:09', 'pending', NULL, 'df568da0646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df81531f646d11f1ae7470b5e88d388e', NULL, NULL, NULL),
(12, 27, 18, 8, NULL, NULL, NULL, NULL, NULL, NULL, 1, 'dine_in', NULL, 'open', NULL, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, NULL, '', 138, NULL, '2026-06-08 10:04:41', NULL, NULL, '2026-06-10 01:44:09', 'pending', NULL, 'df568f1b646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df8151b2646d11f1ae7470b5e88d388e', NULL, NULL, NULL),
(13, 34, 0, NULL, NULL, NULL, NULL, 'Gapsoe Digital', '2723661684', '', 1, 'takeaway', 'T260608-001', 'open', NULL, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, NULL, '', 156, NULL, '2026-06-08 13:38:39', NULL, NULL, '2026-06-10 01:44:08', 'pending', NULL, 'df569091646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(14, 34, 0, NULL, NULL, 156, NULL, 'Gapsoe Digital', '2723661684', '418 Broadway\r\nSTE 6276', 1, 'delivery', 'D260608-001', 'open', 'pending', 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, NULL, '', 156, NULL, '2026-06-08 13:39:00', NULL, NULL, '2026-06-10 01:44:08', 'pending', NULL, 'df56920a646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(15, 34, 0, NULL, NULL, NULL, NULL, 'Gapsoe Digital', '2723661684', '', 1, 'takeaway', 'T260608-002', 'bill_printed', NULL, 1600.00, 0.00, 0.00, 0.00, 0.00, 0.00, 1600.00, 0.00, 1600.00, NULL, '', 156, NULL, '2026-06-08 13:46:47', '2026-06-08 13:47:22', NULL, '2026-06-10 01:44:08', 'pending', NULL, 'df569412646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(16, 34, 0, NULL, NULL, NULL, NULL, 'misba', '2723661684', '', 1, 'takeaway', 'T260608-003', 'kot_sent', NULL, 3200.00, 0.00, 0.00, 0.00, 0.00, 0.00, 3200.00, 0.00, 3200.00, NULL, '', 156, NULL, '2026-06-08 14:17:03', NULL, NULL, '2026-06-10 01:44:08', 'pending', NULL, 'df56958d646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(17, 34, 0, NULL, NULL, NULL, NULL, 'sana', '2723661684', '', 1, 'takeaway', 'T260608-004', 'kot_sent', NULL, 3200.00, 0.00, 0.00, 0.00, 0.00, 0.00, 3200.00, 0.00, 3200.00, NULL, '', 156, NULL, '2026-06-08 14:41:32', '2026-06-09 06:51:38', NULL, '2026-06-10 01:44:08', 'pending', NULL, 'df5696b9646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(18, 34, 0, NULL, NULL, NULL, NULL, 'sana', '2723661684', '', 1, 'takeaway', 'T260609-001', 'kot_sent', NULL, 3200.00, 0.00, 0.00, 0.00, 0.00, 0.00, 3200.00, 0.00, 3200.00, NULL, '', 156, NULL, '2026-06-09 06:44:33', NULL, NULL, '2026-06-10 01:44:08', 'pending', NULL, 'df569796646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(19, 34, 0, 11, NULL, NULL, NULL, NULL, NULL, NULL, 1, 'dine_in', NULL, 'paid', NULL, 3200.00, 0.00, 0.00, 0.00, 0.00, 0.00, 3200.00, 3200.00, 0.00, NULL, '', 156, 156, '2026-06-09 08:02:19', NULL, '2026-06-09 09:00:20', '2026-06-10 01:44:09', 'pending', NULL, 'df569871646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df8153c5646d11f1ae7470b5e88d388e', NULL, NULL, NULL),
(20, 34, 0, NULL, NULL, NULL, NULL, 'Walk-in', '', '', 1, 'takeaway', 'T260609-002', 'bill_printed', NULL, 1600.00, 0.00, 0.00, 0.00, 0.00, 0.00, 1600.00, 0.00, 1600.00, NULL, '', 156, NULL, '2026-06-09 08:23:49', '2026-06-09 10:09:36', NULL, '2026-06-10 01:44:08', 'pending', NULL, 'df56994e646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(21, 34, 0, 9, NULL, NULL, NULL, NULL, NULL, NULL, 4, 'dine_in', NULL, 'paid', NULL, 1600.00, 0.00, 0.00, 0.00, 0.00, 0.00, 1600.00, 1600.00, 0.00, NULL, '', 156, 156, '2026-06-09 08:54:09', '2026-06-09 08:54:23', '2026-06-09 09:01:03', '2026-06-10 01:44:09', 'pending', NULL, 'df569a27646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df815273646d11f1ae7470b5e88d388e', NULL, NULL, NULL),
(22, 34, 0, 11, NULL, NULL, NULL, NULL, NULL, NULL, 1, 'dine_in', NULL, 'paid', NULL, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, NULL, '', 156, 156, '2026-06-09 10:13:34', NULL, '2026-06-09 12:44:06', '2026-06-10 01:44:09', 'pending', NULL, 'df569b00646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df8153c5646d11f1ae7470b5e88d388e', NULL, NULL, NULL),
(23, 34, 0, 13, NULL, NULL, NULL, NULL, NULL, NULL, 1, 'dine_in', NULL, 'paid', NULL, 8000.00, 0.00, 0.00, 0.00, 0.00, 0.00, 8000.00, 8000.00, 0.00, NULL, NULL, 156, 156, '2026-06-09 11:38:53', NULL, '2026-06-09 12:44:19', '2026-06-10 01:44:09', 'completed', '2026-06-09 11:59:56', 'df569bd7646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df81546a646d11f1ae7470b5e88d388e', NULL, NULL, NULL),
(24, 34, 0, 14, NULL, NULL, NULL, NULL, NULL, NULL, 1, 'dine_in', NULL, 'paid', NULL, 1600.00, 0.00, 0.00, 0.00, 0.00, 0.00, 1600.00, 1600.00, 0.00, NULL, NULL, 156, 156, '2026-06-09 12:35:29', NULL, '2026-06-09 12:44:24', '2026-06-10 01:44:09', 'completed', '2026-06-09 12:41:01', 'df569cad646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df815516646d11f1ae7470b5e88d388e', NULL, NULL, NULL),
(25, 34, 0, 9, NULL, NULL, NULL, NULL, NULL, NULL, 1, 'dine_in', NULL, 'bill_printed', NULL, 3200.00, 0.00, 0.00, 0.00, 0.00, 0.00, 3200.00, 0.00, 3200.00, NULL, NULL, 156, NULL, '2026-06-09 12:45:00', NULL, NULL, '2026-06-10 01:44:09', 'completed', '2026-06-09 13:42:12', 'df569d7c646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df815273646d11f1ae7470b5e88d388e', NULL, NULL, NULL),
(26, 34, 0, 10, NULL, NULL, NULL, NULL, NULL, NULL, 1, 'dine_in', NULL, 'bill_printed', NULL, 1600.00, 0.00, 0.00, 0.00, 0.00, 0.00, 1600.00, 0.00, 1600.00, NULL, NULL, 156, NULL, '2026-06-09 14:00:47', '2026-06-09 14:06:35', NULL, '2026-06-11 21:00:32', 'completed', '2026-06-09 14:21:48', 'df569e4d646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df81531f646d11f1ae7470b5e88d388e', NULL, NULL, NULL),
(27, 34, 0, 14, NULL, NULL, NULL, NULL, NULL, NULL, 1, 'dine_in', NULL, 'bill_printed', NULL, 1600.00, 0.00, 0.00, 0.00, 0.00, 0.00, 1600.00, 0.00, 1600.00, NULL, NULL, 156, NULL, '2026-06-09 14:08:55', NULL, NULL, '2026-06-10 01:44:09', 'completed', '2026-06-09 14:21:40', 'df569f1f646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df815516646d11f1ae7470b5e88d388e', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `restaurant_table_transfers`
--

CREATE TABLE `restaurant_table_transfers` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL DEFAULT 0,
  `session_id` int(11) NOT NULL,
  `from_table_id` int(11) DEFAULT NULL,
  `to_table_id` int(11) DEFAULT NULL,
  `transfer_type` enum('transfer','merge','unmerge') NOT NULL DEFAULT 'transfer',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL,
  `session_uuid` varchar(64) DEFAULT NULL,
  `from_table_uuid` varchar(64) DEFAULT NULL,
  `to_table_uuid` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `restaurant_waiters`
--

CREATE TABLE `restaurant_waiters` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL DEFAULT 0,
  `user_id` int(11) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `restaurant_waiters`
--

INSERT INTO `restaurant_waiters` (`id`, `client_id`, `branch_id`, `user_id`, `name`, `phone`, `email`, `status`, `created_at`, `updated_at`, `uuid`, `device_id`, `sync_status`, `last_synced_at`, `deleted_at`, `created_at_local`, `updated_at_local`) VALUES
(1, 32, 0, 152, 'resturent@gmail.com', '0327676423', 'resturent@gmail.com', 'active', '2026-06-03 11:15:59', '2026-06-10 01:44:08', 'df827aee646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(2, 32, 0, 154, 'misbah', '12723661684', 'gapsoedigital121@gmail.com', 'active', '2026-06-03 11:18:01', '2026-06-10 01:44:08', 'df828c3e646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(3, 32, 18, NULL, 'ali', '2723661684', 'ali@gmail.com', 'active', '2026-06-06 06:43:59', '2026-06-10 01:44:08', 'df828d64646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `returns`
--

CREATE TABLE `returns` (
  `id` int(11) NOT NULL,
  `sale_id` int(11) DEFAULT NULL,
  `client_id` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `total_refund` decimal(10,2) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('Pending','Refunded','Exchange') DEFAULT 'Pending',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `customer_id` int(11) DEFAULT 0,
  `adjustment` varchar(10) DEFAULT 'Off',
  `refund_type` varchar(50) DEFAULT 'Refund Money',
  `refund_notes` text DEFAULT NULL,
  `generate_grn` tinyint(1) DEFAULT 0,
  `return_date` date DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `returns`
--

INSERT INTO `returns` (`id`, `sale_id`, `client_id`, `branch_id`, `user_id`, `total_refund`, `reason`, `status`, `created_at`, `customer_id`, `adjustment`, `refund_type`, `refund_notes`, `generate_grn`, `return_date`, `updated_at`, `uuid`, `device_id`, `sync_status`, `last_synced_at`, `deleted_at`, `created_at_local`, `updated_at_local`) VALUES
(5, 52, 29, 0, NULL, 5069.00, 'Damaged', 'Refunded', '2026-05-14 13:37:51', 742, 'Off', 'Refund Money', NULL, 0, NULL, '2026-06-10 01:44:08', 'df610711646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(6, 64, 29, 0, NULL, 600.00, 'Other', 'Refunded', '2026-06-04 07:25:39', 765, 'Off', 'Refund Money', NULL, 0, NULL, '2026-06-10 01:44:08', 'df611ebd646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(7, 75, 29, 0, NULL, 7840.00, 'Damaged', 'Refunded', '2026-06-05 07:19:07', 873, 'Off', 'Refund Money', NULL, 0, NULL, '2026-06-10 01:44:08', 'df6120c1646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(8, 75, 29, 0, NULL, 7840.00, 'Damaged', 'Exchange', '2026-06-05 07:32:22', 873, 'Off', 'Exchange', '', 0, '2026-06-05', '2026-06-10 01:44:08', 'df6121a8646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `return_items`
--

CREATE TABLE `return_items` (
  `id` int(11) NOT NULL,
  `return_id` int(11) DEFAULT NULL,
  `item_id` int(11) DEFAULT NULL,
  `qty` int(11) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `refund_amount` decimal(10,2) DEFAULT NULL,
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `return_items`
--

INSERT INTO `return_items` (`id`, `return_id`, `item_id`, `qty`, `price`, `refund_amount`, `uuid`, `device_id`, `sync_status`, `last_synced_at`, `deleted_at`, `created_at_local`, `updated_at_local`) VALUES
(5, 5, 1019, 2, 700.00, 1400.00, 'df639208646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(6, 5, 1018, 2, 300.00, 600.00, 'df63a60d646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(7, 5, 1017, 1, 300.00, 300.00, 'df63a73b646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(8, 5, 1024, 1, 199.00, 199.00, 'df63a7f9646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(9, 5, 1022, 1, 70.00, 70.00, 'df63a8a2646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(10, 5, 1023, 1, 1500.00, 1500.00, 'df63a977646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(11, 5, 1014, 1, 350.00, 350.00, 'df63aa1d646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(12, 5, 1015, 1, 350.00, 350.00, 'df63ab75646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(13, 5, 1022, 1, 100.00, 100.00, 'df63acac646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(14, 5, 1024, 1, 200.00, 200.00, 'df63adb9646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(15, 6, 1034, 2, 300.00, 600.00, 'df63aec2646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(16, 7, 1033, 2, 0.00, 0.00, 'df63af69646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(17, 7, 1023, 1, 2000.00, 2000.00, 'df63afff646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(18, 7, 1022, 1, 100.00, 100.00, 'df63b094646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(19, 7, 1019, 2, 700.00, 1400.00, 'df63b12b646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(20, 7, 1034, 2, 200.00, 400.00, 'df63b1c5646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(21, 7, 1040, 2, 900.00, 1800.00, 'df63b259646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(22, 7, 1041, 1, 0.00, 0.00, 'df63b2ee646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(23, 7, 1040, 1, 800.00, 800.00, 'df63b384646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(24, 7, 1034, 1, 290.00, 290.00, 'df63b418646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(25, 7, 1040, 1, 750.00, 750.00, 'df63b4ab646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(26, 7, 1018, 1, 300.00, 300.00, 'df63b542646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(27, 8, 1033, 2, 0.00, 0.00, 'df63b5d8646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(28, 8, 1023, 1, 2000.00, 2000.00, 'df63b66e646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(29, 8, 1022, 1, 100.00, 100.00, 'df63b702646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(30, 8, 1019, 2, 700.00, 1400.00, 'df63b7ce646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(31, 8, 1034, 2, 200.00, 400.00, 'df63b86a646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(32, 8, 1040, 2, 900.00, 1800.00, 'df63b906646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(33, 8, 1041, 1, 0.00, 0.00, 'df63b9a0646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(34, 8, 1040, 1, 800.00, 800.00, 'df63ba38646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(35, 8, 1034, 1, 290.00, 290.00, 'df63bad1646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(36, 8, 1040, 1, 750.00, 750.00, 'df63bb6a646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(37, 8, 1018, 1, 300.00, 300.00, 'df63bcad646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `re_agents`
--

CREATE TABLE `re_agents` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `commission_rate` decimal(5,2) DEFAULT 0.00,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `re_agent_assignments`
--

CREATE TABLE `re_agent_assignments` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `lead_id` int(11) NOT NULL,
  `old_agent_id` int(11) DEFAULT NULL,
  `new_agent_id` int(11) DEFAULT NULL,
  `assignment_type` enum('Manual','Bulk','Auto','Reassigned','Unassigned') NOT NULL DEFAULT 'Manual',
  `remarks` text DEFAULT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `re_agent_commissions`
--

CREATE TABLE `re_agent_commissions` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `deal_id` int(11) DEFAULT NULL,
  `agent_id` int(11) DEFAULT NULL,
  `property_id` int(11) DEFAULT NULL,
  `deal_no` varchar(80) DEFAULT NULL,
  `property_title` varchar(255) DEFAULT NULL,
  `deal_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `commission_percent` decimal(6,2) NOT NULL DEFAULT 0.00,
  `commission_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `balance_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payment_status` enum('Unpaid','Partial','Paid') NOT NULL DEFAULT 'Unpaid',
  `commission_status` enum('Pending','Approved','Released','Hold','Cancelled') NOT NULL DEFAULT 'Pending',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `re_agent_profiles`
--

CREATE TABLE `re_agent_profiles` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `designation` varchar(100) DEFAULT 'Sales Agent',
  `city` varchar(100) DEFAULT NULL,
  `territory` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `re_bookings`
--

CREATE TABLE `re_bookings` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `agent_id` int(11) DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL,
  `booking_date` date NOT NULL,
  `status` enum('Active','Completed','Cancelled') DEFAULT 'Active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `re_commissions`
--

CREATE TABLE `re_commissions` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `agent_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('Pending','Paid') DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `re_commission_payments`
--

CREATE TABLE `re_commission_payments` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `commission_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payment_date` date NOT NULL,
  `method` enum('Cash','Bank Transfer','Cheque','JazzCash','EasyPaisa','Other') DEFAULT 'Cash',
  `reference_no` varchar(150) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `re_deals`
--

CREATE TABLE `re_deals` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `lead_id` int(11) NOT NULL,
  `property_id` int(11) NOT NULL,
  `agent_id` int(11) DEFAULT NULL,
  `deal_no` varchar(80) DEFAULT NULL,
  `deal_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `token_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `balance_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `commission_percent` decimal(6,2) NOT NULL DEFAULT 0.00,
  `commission_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('Token Paid','Booking Confirmed','Agreement','Completed','On Going','Follow Up','Lost','Cancelled') NOT NULL DEFAULT 'Token Paid',
  `deal_date` date NOT NULL,
  `expected_closing_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `re_followups`
--

CREATE TABLE `re_followups` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `lead_id` int(11) NOT NULL,
  `property_id` int(11) DEFAULT NULL,
  `agent_id` int(11) DEFAULT NULL,
  `followup_type` varchar(100) DEFAULT NULL,
  `followup_at` datetime DEFAULT NULL,
  `followup_date` date DEFAULT NULL,
  `followup_time` time DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `next_followup_date` date DEFAULT NULL,
  `status` varchar(50) DEFAULT 'Pending',
  `response_status` enum('No Response','Interested','Not Interested','Call Later','Visit Scheduled','Deal Possible','Other') NOT NULL DEFAULT 'No Response',
  `response` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `re_installments`
--

CREATE TABLE `re_installments` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `due_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `paid_amount` decimal(12,2) DEFAULT 0.00,
  `status` enum('Pending','Partial','Paid') DEFAULT 'Pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `re_leads`
--

CREATE TABLE `re_leads` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `lead_no` varchar(50) DEFAULT NULL,
  `lead_name` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `source` varchar(100) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'New',
  `priority` enum('Low','Medium','High','Hot') DEFAULT 'Medium',
  `next_followup_at` datetime DEFAULT NULL,
  `lost_reason` varchar(255) DEFAULT NULL,
  `budget_min` decimal(15,2) DEFAULT NULL,
  `budget_max` decimal(15,2) DEFAULT NULL,
  `requirement` text DEFAULT NULL,
  `property_type` varchar(100) DEFAULT NULL,
  `preferred_city` varchar(100) DEFAULT NULL,
  `preferred_location` varchar(255) DEFAULT NULL,
  `interest_type` varchar(100) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `unit_id` int(11) DEFAULT NULL,
  `agent_id` int(11) DEFAULT NULL,
  `next_followup_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `assigned_agent_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `re_leads`
--

INSERT INTO `re_leads` (`id`, `client_id`, `branch_id`, `lead_no`, `lead_name`, `phone`, `email`, `address`, `city`, `source`, `status`, `priority`, `next_followup_at`, `lost_reason`, `budget_min`, `budget_max`, `requirement`, `property_type`, `preferred_city`, `preferred_location`, `interest_type`, `project_id`, `unit_id`, `agent_id`, `next_followup_date`, `notes`, `created_by`, `created_at`, `updated_at`, `assigned_agent_id`) VALUES
(4, 29, NULL, 'REL-20260511-0001', 'Asad', '03332532399', 'kant@gmail.com', 'Karachi', NULL, 'WhatsApp', 'Follow Up', 'Hot', NULL, NULL, 30000.00, 4000.00, '', 'Shop', '', 'Bahria', 'Rent', NULL, NULL, NULL, NULL, NULL, 148, '2026-05-11 20:25:13', '2026-05-11 20:26:01', 148);

-- --------------------------------------------------------

--
-- Table structure for table `re_payments`
--

CREATE TABLE `re_payments` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `installment_id` int(11) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `payment_date` date NOT NULL,
  `method` varchar(50) DEFAULT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `re_projects`
--

CREATE TABLE `re_projects` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `project_name` varchar(255) DEFAULT NULL,
  `developer_name` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `project_type` enum('Society','Apartment Project','Commercial Project','Mixed Project','Other') DEFAULT 'Society',
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('Active','Completed','On Hold') DEFAULT 'Active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `re_properties`
--

CREATE TABLE `re_properties` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `property_code` varchar(80) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `property_type` enum('Plot','House','Apartment','Flat','Shop','Office','Commercial','Farmhouse','Other') DEFAULT 'Plot',
  `purpose` enum('Sale','Rent','Both') DEFAULT 'Sale',
  `city` varchar(100) DEFAULT NULL,
  `area_location` varchar(255) DEFAULT NULL,
  `block_name` varchar(100) DEFAULT NULL,
  `street_no` varchar(100) DEFAULT NULL,
  `plot_no` varchar(100) DEFAULT NULL,
  `size_value` decimal(12,2) DEFAULT 0.00,
  `size_unit` enum('Sq Ft','Sq Yd','Marla','Kanal','Acre') DEFAULT 'Sq Yd',
  `demand_price` decimal(15,2) DEFAULT 0.00,
  `min_price` decimal(15,2) DEFAULT 0.00,
  `rent_price` decimal(15,2) DEFAULT 0.00,
  `owner_name` varchar(150) DEFAULT NULL,
  `owner_phone` varchar(50) DEFAULT NULL,
  `owner_email` varchar(150) DEFAULT NULL,
  `owner_address` text DEFAULT NULL,
  `assigned_agent_id` int(11) DEFAULT NULL,
  `status` enum('Available','On Hold','Token','Booked','Sold','Rented','Inactive') DEFAULT 'Available',
  `description` text DEFAULT NULL,
  `main_image` varchar(500) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `re_property_documents`
--

CREATE TABLE `re_property_documents` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `property_id` int(11) NOT NULL,
  `document_type` enum('Image','Registry','Allotment Letter','CNIC','Agreement','Map','Other') DEFAULT 'Image',
  `file_title` varchar(255) DEFAULT NULL,
  `file_path` varchar(500) NOT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `re_site_visits`
--

CREATE TABLE `re_site_visits` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `lead_id` int(11) DEFAULT NULL,
  `property_id` int(11) DEFAULT NULL,
  `manual_property` varchar(255) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `unit_id` int(11) DEFAULT NULL,
  `agent_id` int(11) DEFAULT NULL,
  `visit_date` date DEFAULT NULL,
  `visit_time` time DEFAULT NULL,
  `meeting_point` varchar(255) DEFAULT NULL,
  `visitor_count` int(11) DEFAULT 1,
  `status` enum('Scheduled','Completed','Cancelled','No Show') NOT NULL DEFAULT 'Scheduled',
  `customer_feedback` text DEFAULT NULL,
  `agent_remarks` text DEFAULT NULL,
  `visit_status` varchar(50) DEFAULT 'Scheduled',
  `remarks` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `re_units`
--

CREATE TABLE `re_units` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `unit_no` varchar(100) NOT NULL,
  `type` varchar(100) NOT NULL,
  `size_sqft` decimal(10,2) DEFAULT NULL,
  `price` decimal(12,2) NOT NULL,
  `status` enum('Available','Booked','Sold','Hold') DEFAULT 'Available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `name` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `name`) VALUES
(1, 'Super Admin'),
(2, 'Staff'),
(3, 'Client Admin'),
(4, 'Tester');

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `role_id` int(11) DEFAULT NULL,
  `permission_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `role_permissions`
--

INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(2, 1),
(2, 20),
(2, 21),
(2, 22),
(2, 23),
(2, 24),
(2, 25),
(2, 26),
(2, 27),
(3, 1),
(3, 4),
(3, 8),
(3, 9),
(3, 12),
(3, 13),
(3, 14),
(3, 15),
(3, 16),
(3, 17),
(3, 18),
(3, 19),
(3, 20),
(3, 21),
(3, 22),
(3, 23),
(3, 24),
(3, 25),
(3, 26),
(3, 27),
(3, 28),
(3, 29),
(3, 30),
(3, 31),
(1, 1),
(1, 2),
(1, 3),
(1, 4),
(1, 5),
(1, 6),
(1, 7),
(1, 8),
(1, 9),
(1, 10),
(1, 11),
(1, 12),
(1, 13),
(1, 14),
(1, 15),
(1, 16),
(1, 17),
(1, 18),
(1, 19),
(1, 20),
(1, 21),
(1, 22),
(1, 23),
(1, 24),
(1, 25),
(1, 26),
(1, 27),
(1, 28),
(1, 29),
(1, 30),
(1, 31);

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` int(11) NOT NULL,
  `invoice_no` varchar(100) DEFAULT NULL,
  `client_id` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `total` decimal(10,2) DEFAULT NULL,
  `discount` decimal(10,2) DEFAULT NULL,
  `tax` decimal(10,2) DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `counter_name` varchar(100) DEFAULT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `due_date` date DEFAULT NULL,
  `coupon_id` int(11) DEFAULT NULL,
  `coupon_discount` decimal(10,2) DEFAULT 0.00,
  `source` varchar(50) DEFAULT 'manual',
  `customer_name` varchar(255) NOT NULL DEFAULT 'Walk-in',
  `customer_type` enum('Walk-in','Wholesaler','Retailer') NOT NULL DEFAULT 'Walk-in',
  `restaurant_session_id` int(11) DEFAULT NULL,
  `restaurant_table_id` int(11) DEFAULT NULL,
  `waiter_id` int(11) DEFAULT NULL,
  `guest_count` int(11) NOT NULL DEFAULT 0,
  `restaurant_order_type` varchar(30) DEFAULT NULL,
  `service_charge` decimal(12,2) NOT NULL DEFAULT 0.00,
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(64) DEFAULT NULL,
  `local_invoice_no` varchar(100) DEFAULT NULL,
  `server_invoice_no` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'pending',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`id`, `invoice_no`, `client_id`, `branch_id`, `customer_id`, `user_id`, `total`, `discount`, `tax`, `payment_method`, `counter_name`, `reference_no`, `created_at`, `due_date`, `coupon_id`, `coupon_discount`, `source`, `customer_name`, `customer_type`, `restaurant_session_id`, `restaurant_table_id`, `waiter_id`, `guest_count`, `restaurant_order_type`, `service_charge`, `uuid`, `device_id`, `local_invoice_no`, `server_invoice_no`, `sync_status`, `last_synced_at`, `deleted_at`, `updated_at`, `created_at_local`, `updated_at_local`) VALUES
(21, 'INV-26-00021', 20, NULL, 0, 130, 24.00, 0.00, 20.00, 'cash', NULL, NULL, '2026-06-10 01:44:08', NULL, NULL, 0.00, 'manual', 'Walk-in', 'Walk-in', NULL, NULL, NULL, 0, NULL, 0.00, 'df49fd25646d11f1ae7470b5e88d388e', NULL, NULL, NULL, 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL),
(22, 'INV-26-00022', 27, NULL, 0, 138, 3600.00, 0.00, 0.00, 'cash', NULL, NULL, '2026-06-10 01:44:08', NULL, NULL, 0.00, 'manual', 'Walk-in', 'Walk-in', NULL, NULL, NULL, 0, NULL, 0.00, 'df4a09a7646d11f1ae7470b5e88d388e', NULL, NULL, NULL, 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL),
(23, 'INV-26-00023', 27, NULL, 0, 138, 1600.00, 0.00, 0.00, 'cash', NULL, NULL, '2026-06-10 01:44:08', NULL, NULL, 0.00, 'manual', 'Walk-in', 'Walk-in', NULL, NULL, NULL, 0, NULL, 0.00, 'df4a0b04646d11f1ae7470b5e88d388e', NULL, NULL, NULL, 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL),
(24, 'INV-26-00024', 27, NULL, 0, 138, 4250.06, 0.00, 0.00, 'cash', NULL, NULL, '2026-06-10 01:44:08', NULL, NULL, 0.00, 'manual', 'Walk-in', 'Walk-in', NULL, NULL, NULL, 0, NULL, 0.00, 'df4a0bfc646d11f1ae7470b5e88d388e', NULL, NULL, NULL, 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL),
(25, 'INV-26-00025', 27, NULL, 0, 138, 6194.64, 4.00, 10.00, 'cash', NULL, NULL, '2026-06-10 01:44:08', NULL, NULL, 0.00, 'manual', 'Walk-in', 'Walk-in', NULL, NULL, NULL, 0, NULL, 0.00, 'df4a0d03646d11f1ae7470b5e88d388e', NULL, NULL, NULL, 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL),
(51, 'INV-26-00051', 31, NULL, 0, 150, 300.00, 0.00, 0.00, 'cash', NULL, NULL, '2026-06-10 01:44:08', NULL, NULL, 0.00, 'manual', 'Walk-in', 'Walk-in', NULL, NULL, NULL, 0, NULL, 0.00, 'df4a0ddc646d11f1ae7470b5e88d388e', NULL, NULL, NULL, 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL),
(56, 'INV-26-00056', 29, NULL, 742, 148, 3320.00, 0.00, 0.00, 'cash', NULL, NULL, '2026-06-10 01:44:08', NULL, NULL, 0.00, 'manual', 'Walk-in', 'Wholesaler', NULL, NULL, NULL, 0, NULL, 0.00, 'df4a0ea2646d11f1ae7470b5e88d388e', NULL, NULL, NULL, 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL),
(57, 'INV-26-00057', 29, NULL, 863, 148, 3200.00, 0.00, 0.00, 'cash', NULL, NULL, '2026-06-10 01:44:08', NULL, NULL, 0.00, 'manual', 'Walk-in', 'Wholesaler', NULL, NULL, NULL, 0, NULL, 0.00, 'df4a0f64646d11f1ae7470b5e88d388e', NULL, NULL, NULL, 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL),
(58, 'INV-26-00058', 29, NULL, 755, 148, 945.00, 0.00, 0.00, 'cash', NULL, NULL, '2026-06-10 01:44:08', NULL, NULL, 0.00, 'manual', 'Walk-in', 'Wholesaler', NULL, NULL, NULL, 0, NULL, 0.00, 'df4a1021646d11f1ae7470b5e88d388e', NULL, NULL, NULL, 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL),
(59, 'INV-26-00059', 29, NULL, 863, 148, 5100.00, 0.00, 0.00, 'cash', NULL, NULL, '2026-06-10 01:44:08', NULL, NULL, 0.00, 'manual', 'Walk-in', 'Wholesaler', NULL, NULL, NULL, 0, NULL, 0.00, 'df4a1162646d11f1ae7470b5e88d388e', NULL, NULL, NULL, 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL),
(60, 'INV-26-00060', 29, NULL, 863, 148, 1500.00, 0.00, 0.00, 'cash', NULL, NULL, '2026-06-10 01:44:08', NULL, NULL, 0.00, 'manual', 'Walk-in', 'Wholesaler', NULL, NULL, NULL, 0, NULL, 0.00, 'df4a1218646d11f1ae7470b5e88d388e', NULL, NULL, NULL, 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL),
(61, 'INV-26-00061', 29, NULL, 765, 148, 4000.00, 0.00, 0.00, 'cash', NULL, NULL, '2026-06-10 01:44:08', NULL, NULL, 0.00, 'manual', 'Walk-in', 'Retailer', NULL, NULL, NULL, 0, NULL, 0.00, 'df4a12ce646d11f1ae7470b5e88d388e', NULL, NULL, NULL, 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL),
(62, 'INV-26-00062', 29, NULL, 863, 148, 2400.00, 0.00, 0.00, 'cash', NULL, NULL, '2026-06-10 01:44:08', NULL, NULL, 0.00, 'manual', 'Walk-in', 'Wholesaler', NULL, NULL, NULL, 0, NULL, 0.00, 'df4a1384646d11f1ae7470b5e88d388e', NULL, NULL, NULL, 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL),
(63, 'INV-26-00063', 29, NULL, 755, 148, 3100.00, 0.00, 0.00, 'cash', NULL, NULL, '2026-06-10 01:44:08', NULL, NULL, 0.00, 'manual', 'Walk-in', 'Wholesaler', NULL, NULL, NULL, 0, NULL, 0.00, 'df4a1439646d11f1ae7470b5e88d388e', NULL, NULL, NULL, 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL),
(64, 'INV-26-00064', 29, NULL, 765, 148, 600.00, 0.00, 0.00, 'cash', NULL, NULL, '2026-06-10 01:44:08', NULL, NULL, 0.00, 'manual', 'Walk-in', 'Retailer', NULL, NULL, NULL, 0, NULL, 0.00, 'df4a14f1646d11f1ae7470b5e88d388e', NULL, NULL, NULL, 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL),
(65, 'INV-26-00065', 29, NULL, 863, 148, 300.00, 0.00, 0.00, 'cash', NULL, NULL, '2026-06-10 01:44:08', NULL, NULL, 0.00, 'manual', 'Walk-in', 'Wholesaler', NULL, NULL, NULL, 0, NULL, 0.00, 'df4a15a7646d11f1ae7470b5e88d388e', NULL, NULL, NULL, 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL),
(66, 'INV-26-00066', 27, NULL, 334, 138, 250.00, 0.00, 0.00, 'cash', NULL, NULL, '2026-06-10 01:44:08', NULL, NULL, 0.00, 'manual', 'Walk-in', 'Retailer', NULL, NULL, NULL, 0, NULL, 0.00, 'df4a165d646d11f1ae7470b5e88d388e', NULL, NULL, NULL, 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL),
(67, 'INV-26-00067', 31, NULL, 872, 150, 699.00, 0.00, 0.00, 'cash', NULL, NULL, '2026-06-10 01:44:08', NULL, NULL, 0.00, 'manual', 'Walk-in', 'Retailer', NULL, NULL, NULL, 0, NULL, 0.00, 'df4a1713646d11f1ae7470b5e88d388e', NULL, NULL, NULL, 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL),
(68, 'INV-26-00068', 31, NULL, 872, 150, 400.00, 0.00, 0.00, 'cash', NULL, NULL, '2026-06-10 01:44:08', NULL, NULL, 0.00, 'manual', 'Walk-in', 'Retailer', NULL, NULL, NULL, 0, NULL, 0.00, 'df4a17c8646d11f1ae7470b5e88d388e', NULL, NULL, NULL, 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL),
(71, 'INV-26-00071', 27, NULL, 334, 138, 0.00, 0.00, 0.00, 'cash', NULL, NULL, '2026-06-10 01:44:08', NULL, NULL, 0.00, 'manual', 'Walk-in', 'Retailer', NULL, NULL, NULL, 0, NULL, 0.00, 'df4a187d646d11f1ae7470b5e88d388e', NULL, NULL, NULL, 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL),
(74, 'INV-26-00074', 32, 0, 0, 152, 1600.00, 0.00, 0.00, 'cash', 'Restaurant POS', '', '2026-06-10 01:44:08', NULL, NULL, 0.00, 'restaurant', 'Walk-in', 'Walk-in', 1, 2, NULL, 1, 'dine_in', 0.00, 'df4a1939646d11f1ae7470b5e88d388e', NULL, NULL, NULL, 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL),
(75, 'INV-26-00075', 29, NULL, 873, 148, 7840.00, 0.00, 0.00, 'cash', NULL, NULL, '2026-06-10 01:44:08', NULL, NULL, 0.00, 'manual', 'Walk-in', 'Retailer', NULL, NULL, NULL, 0, NULL, 0.00, 'df4a1a22646d11f1ae7470b5e88d388e', NULL, NULL, NULL, 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL),
(76, 'INV-26-00076', 32, 0, 0, 152, 2500.00, 0.00, 0.00, 'cash', 'Restaurant POS', '', '2026-06-10 01:44:08', NULL, NULL, 0.00, 'restaurant', 'Walk-in', 'Walk-in', 3, 2, NULL, 1, 'dine_in', 0.00, 'df4a1ae0646d11f1ae7470b5e88d388e', NULL, NULL, NULL, 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL),
(77, 'INV-26-00077', 32, 18, 0, 152, 150.00, 0.00, 0.00, 'cash', 'Restaurant POS', '', '2026-06-10 01:44:08', NULL, NULL, 0.00, 'restaurant', 'Walk-in', 'Walk-in', 5, 7, NULL, 1, 'dine_in', 0.00, 'df4a1ba6646d11f1ae7470b5e88d388e', NULL, NULL, NULL, 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL),
(78, 'INV-26-00078', 27, 18, 0, 138, 1799.98, 0.02, 0.00, 'cash', 'Restaurant POS', '9', '2026-06-10 01:44:08', NULL, NULL, 0.00, 'restaurant', 'Walk-in', 'Walk-in', 9, 8, 3, 1, 'dine_in', 0.00, 'df4a1c68646d11f1ae7470b5e88d388e', NULL, NULL, NULL, 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL),
(79, 'INV-26-00079', 29, NULL, 765, 148, 340.00, 0.00, 0.00, 'cash', NULL, NULL, '2026-06-11 23:08:34', NULL, NULL, 0.00, 'manual', 'Walk-in', 'Retailer', NULL, NULL, NULL, 0, NULL, 0.00, NULL, NULL, NULL, NULL, 'pending', NULL, NULL, '2026-06-12 04:08:34', NULL, NULL),
(80, 'INV-26-00080', 29, NULL, 874, 148, 0.00, 0.00, 0.00, 'cash', NULL, NULL, '2026-06-11 23:38:02', NULL, NULL, 0.00, 'manual', 'Walk-in', 'Retailer', NULL, NULL, NULL, 0, NULL, 0.00, NULL, NULL, NULL, NULL, 'pending', NULL, NULL, '2026-06-12 04:38:02', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `sale_items`
--

CREATE TABLE `sale_items` (
  `id` int(11) NOT NULL,
  `sale_id` int(11) DEFAULT NULL,
  `item_id` int(11) DEFAULT NULL,
  `item_name` varchar(255) DEFAULT NULL,
  `qty` decimal(12,2) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `price_tier_type` enum('default','wholesale','retailer') NOT NULL DEFAULT 'default',
  `price_tier_no` int(11) NOT NULL DEFAULT 0,
  `price_tier_label` varchar(50) DEFAULT NULL,
  `total` decimal(10,2) DEFAULT NULL,
  `restaurant_order_item_id` int(11) DEFAULT NULL,
  `special_note` text DEFAULT NULL,
  `uuid` varchar(64) DEFAULT NULL,
  `sale_uuid` varchar(64) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'pending',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL,
  `item_uuid` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sale_items`
--

INSERT INTO `sale_items` (`id`, `sale_id`, `item_id`, `item_name`, `qty`, `price`, `price_tier_type`, `price_tier_no`, `price_tier_label`, `total`, `restaurant_order_item_id`, `special_note`, `uuid`, `sale_uuid`, `sync_status`, `last_synced_at`, `deleted_at`, `updated_at`, `device_id`, `created_at_local`, `updated_at_local`, `item_uuid`) VALUES
(1, 21, 76, 'Test Products', 1.00, 20.00, 'default', 0, NULL, 20.00, NULL, NULL, 'df544deb646d11f1ae7470b5e88d388e', 'df49fd25646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df6f9d14646d11f1ae7470b5e88d388e'),
(2, 22, 84, 'water', 3.00, 1000.00, 'default', 0, NULL, 3000.00, NULL, NULL, 'df545014646d11f1ae7470b5e88d388e', 'df4a09a7646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df6fb648646d11f1ae7470b5e88d388e'),
(3, 22, 85, 'water galon', 3.00, 100.00, 'default', 0, NULL, 300.00, NULL, NULL, 'df5450ee646d11f1ae7470b5e88d388e', 'df4a09a7646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df6fb759646d11f1ae7470b5e88d388e'),
(4, 22, 86, 'kuch', 1.00, 300.00, 'default', 0, NULL, 300.00, NULL, NULL, 'df545191646d11f1ae7470b5e88d388e', 'df4a09a7646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df6fb855646d11f1ae7470b5e88d388e'),
(5, 23, 85, 'water galon', 1.00, 100.00, 'default', 0, NULL, 100.00, NULL, NULL, 'df545227646d11f1ae7470b5e88d388e', 'df4a0b04646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df6fb759646d11f1ae7470b5e88d388e'),
(6, 23, 84, 'water', 1.00, 1000.00, 'default', 0, NULL, 1000.00, NULL, NULL, 'df5452b7646d11f1ae7470b5e88d388e', 'df4a0b04646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df6fb648646d11f1ae7470b5e88d388e'),
(7, 23, 86, 'kuch', 2.00, 250.00, 'default', 0, NULL, 500.00, NULL, NULL, 'df545346646d11f1ae7470b5e88d388e', 'df4a0b04646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df6fb855646d11f1ae7470b5e88d388e'),
(8, 24, 84, 'water', 2.00, 1600.00, 'default', 0, NULL, 3200.00, NULL, NULL, 'df5453cb646d11f1ae7470b5e88d388e', 'df4a0bfc646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df6fb648646d11f1ae7470b5e88d388e'),
(9, 24, 85, 'water galon', 3.00, 100.00, 'default', 0, NULL, 300.00, NULL, NULL, 'df545452646d11f1ae7470b5e88d388e', 'df4a0bfc646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df6fb759646d11f1ae7470b5e88d388e'),
(10, 24, 86, 'kuch', 3.00, 250.02, 'default', 0, NULL, 750.06, NULL, NULL, 'df5454db646d11f1ae7470b5e88d388e', 'df4a0bfc646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df6fb855646d11f1ae7470b5e88d388e'),
(11, 25, 84, 'water', 6.00, 899.00, 'default', 0, NULL, 5394.00, NULL, NULL, 'df54559a646d11f1ae7470b5e88d388e', 'df4a0d03646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df6fb648646d11f1ae7470b5e88d388e'),
(12, 25, 85, 'water galon', 2.00, 100.00, 'default', 0, NULL, 200.00, NULL, NULL, 'df545627646d11f1ae7470b5e88d388e', 'df4a0d03646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df6fb759646d11f1ae7470b5e88d388e'),
(13, 25, 86, 'kuch', 1.00, 250.00, 'default', 0, NULL, 250.00, NULL, NULL, 'df5456ae646d11f1ae7470b5e88d388e', 'df4a0d03646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df6fb855646d11f1ae7470b5e88d388e'),
(82, 51, 1026, 'shampoo', 1.00, 300.00, 'default', 0, '', 300.00, NULL, NULL, 'df545738646d11f1ae7470b5e88d388e', 'df4a0ddc646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b18e0646d11f1ae7470b5e88d388e'),
(108, 56, 1012, 'More Days at the Morisaki Bookshop', 2.00, 300.00, 'wholesale', 1, 'Wholesale 1', 600.00, NULL, NULL, 'df5457c4646d11f1ae7470b5e88d388e', 'df4a0ea2646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b0db9646d11f1ae7470b5e88d388e'),
(109, 56, 1013, 'Talking with Psychopaths and Savages', 2.00, 250.00, 'wholesale', 1, 'Wholesale 1', 500.00, NULL, NULL, 'df54585a646d11f1ae7470b5e88d388e', 'df4a0ea2646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b0eaf646d11f1ae7470b5e88d388e'),
(110, 56, 1015, 'Inside the Investor\'s Brain', 1.00, 350.00, 'wholesale', 1, 'Wholesale 1', 350.00, NULL, NULL, 'df5458ed646d11f1ae7470b5e88d388e', 'df4a0ea2646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b111f646d11f1ae7470b5e88d388e'),
(111, 56, 1023, 'logo', 1.00, 1500.00, 'wholesale', 1, 'Wholesale 1', 1500.00, NULL, NULL, 'df545980646d11f1ae7470b5e88d388e', 'df4a0ea2646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1762646d11f1ae7470b5e88d388e'),
(112, 56, 1022, 'makeup', 1.00, 70.00, 'wholesale', 1, 'Wholesale 1', 70.00, NULL, NULL, 'df545a07646d11f1ae7470b5e88d388e', 'df4a0ea2646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1655646d11f1ae7470b5e88d388e'),
(113, 56, 1018, 'Fi Amanillah', 1.00, 300.00, 'wholesale', 1, 'Wholesale 1', 300.00, NULL, NULL, 'df545a8b646d11f1ae7470b5e88d388e', 'df4a0ea2646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1440646d11f1ae7470b5e88d388e'),
(114, 57, 1033, 'waterplant', 1.00, 0.00, 'wholesale', 3, 'Wholesale 3', 0.00, NULL, NULL, 'df545b13646d11f1ae7470b5e88d388e', 'df4a0f64646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1ecd646d11f1ae7470b5e88d388e'),
(115, 57, 1023, 'logo', 1.00, 2000.00, 'default', 0, '', 2000.00, NULL, NULL, 'df545b9a646d11f1ae7470b5e88d388e', 'df4a0f64646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1762646d11f1ae7470b5e88d388e'),
(116, 57, 1022, 'makeup', 2.00, 100.00, 'default', 0, '', 200.00, NULL, NULL, 'df545c20646d11f1ae7470b5e88d388e', 'df4a0f64646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1655646d11f1ae7470b5e88d388e'),
(117, 57, 1019, 'The Sword of A...', 1.00, 700.00, 'default', 0, '', 700.00, NULL, NULL, 'df545ca4646d11f1ae7470b5e88d388e', 'df4a0f64646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1547646d11f1ae7470b5e88d388e'),
(118, 57, 1018, 'Fi Amanillah', 1.00, 300.00, 'default', 0, '', 300.00, NULL, NULL, 'df545d27646d11f1ae7470b5e88d388e', 'df4a0f64646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1440646d11f1ae7470b5e88d388e'),
(119, 58, 1019, 'The Sword of A...', 1.00, 700.00, 'wholesale', 2, 'Wholesale 2', 700.00, NULL, NULL, 'df545daa646d11f1ae7470b5e88d388e', 'df4a1021646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1547646d11f1ae7470b5e88d388e'),
(120, 58, 1022, 'makeup', 1.00, 75.00, 'wholesale', 2, 'Wholesale 2', 75.00, NULL, NULL, 'df545e2d646d11f1ae7470b5e88d388e', 'df4a1021646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1655646d11f1ae7470b5e88d388e'),
(121, 58, 1022, 'makeup', 1.00, 70.00, 'wholesale', 1, 'Wholesale 1', 70.00, NULL, NULL, 'df545eb0646d11f1ae7470b5e88d388e', 'df4a1021646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1655646d11f1ae7470b5e88d388e'),
(122, 58, 1022, 'makeup', 1.00, 100.00, 'wholesale', 3, 'Wholesale 3', 100.00, NULL, NULL, 'df545f32646d11f1ae7470b5e88d388e', 'df4a1021646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1655646d11f1ae7470b5e88d388e'),
(123, 59, 1023, 'logo', 2.00, 2000.00, 'wholesale', 3, 'Wholesale 3', 4000.00, NULL, NULL, 'df545fb5646d11f1ae7470b5e88d388e', 'df4a1162646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1762646d11f1ae7470b5e88d388e'),
(124, 59, 1033, 'waterplant', 2.00, 0.00, 'wholesale', 3, 'Wholesale 3', 0.00, NULL, NULL, 'df546038646d11f1ae7470b5e88d388e', 'df4a1162646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1ecd646d11f1ae7470b5e88d388e'),
(125, 59, 1018, 'Fi Amanillah', 1.00, 300.00, 'wholesale', 3, 'Wholesale 3', 300.00, NULL, NULL, 'df5460bb646d11f1ae7470b5e88d388e', 'df4a1162646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1440646d11f1ae7470b5e88d388e'),
(126, 59, 1019, 'The Sword of A...', 1.00, 700.00, 'wholesale', 3, 'Wholesale 3', 700.00, NULL, NULL, 'df54613f646d11f1ae7470b5e88d388e', 'df4a1162646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1547646d11f1ae7470b5e88d388e'),
(127, 59, 1022, 'makeup', 1.00, 100.00, 'wholesale', 3, 'Wholesale 3', 100.00, NULL, NULL, 'df5461c2646d11f1ae7470b5e88d388e', 'df4a1162646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1655646d11f1ae7470b5e88d388e'),
(128, 59, 1034, 'waterplant', 1.00, 0.00, 'wholesale', 3, 'Wholesale 3', 0.00, NULL, NULL, 'df546247646d11f1ae7470b5e88d388e', 'df4a1162646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1fd3646d11f1ae7470b5e88d388e'),
(129, 60, 1022, 'makeup', 3.00, 100.00, 'wholesale', 3, 'Wholesale 3', 300.00, NULL, NULL, 'df5462ca646d11f1ae7470b5e88d388e', 'df4a1218646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1655646d11f1ae7470b5e88d388e'),
(130, 60, 1034, 'waterplant', 14.00, 0.00, 'wholesale', 3, 'Wholesale 3', 0.00, NULL, NULL, 'df54634c646d11f1ae7470b5e88d388e', 'df4a1218646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1fd3646d11f1ae7470b5e88d388e'),
(131, 60, 1018, 'Fi Amanillah', 4.00, 300.00, 'wholesale', 3, 'Wholesale 3', 1200.00, NULL, NULL, 'df5463d0646d11f1ae7470b5e88d388e', 'df4a1218646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1440646d11f1ae7470b5e88d388e'),
(132, 60, 1033, 'waterplant', 1.00, 0.00, 'wholesale', 3, 'Wholesale 3', 0.00, NULL, NULL, 'df546455646d11f1ae7470b5e88d388e', 'df4a1218646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1ecd646d11f1ae7470b5e88d388e'),
(133, 61, 1023, 'logo', 1.00, 2000.00, 'default', 0, '', 2000.00, NULL, NULL, 'df5464d7646d11f1ae7470b5e88d388e', 'df4a12ce646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1762646d11f1ae7470b5e88d388e'),
(134, 61, 1033, 'waterplant', 1.00, 0.00, 'default', 0, '', 0.00, NULL, NULL, 'df54655c646d11f1ae7470b5e88d388e', 'df4a12ce646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1ecd646d11f1ae7470b5e88d388e'),
(135, 61, 1034, 'waterplant', 1.00, 0.00, 'default', 0, '', 0.00, NULL, NULL, 'df5465df646d11f1ae7470b5e88d388e', 'df4a12ce646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1fd3646d11f1ae7470b5e88d388e'),
(136, 61, 1034, 'waterplant', 1.00, 0.00, 'wholesale', 3, 'Wholesale 3', 0.00, NULL, NULL, 'df546664646d11f1ae7470b5e88d388e', 'df4a12ce646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1fd3646d11f1ae7470b5e88d388e'),
(137, 61, 1033, 'waterplant', 1.00, 0.00, 'wholesale', 3, 'Wholesale 3', 0.00, NULL, NULL, 'df5466e7646d11f1ae7470b5e88d388e', 'df4a12ce646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1ecd646d11f1ae7470b5e88d388e'),
(138, 61, 1023, 'logo', 1.00, 2000.00, 'wholesale', 3, 'Wholesale 3', 2000.00, NULL, NULL, 'df546769646d11f1ae7470b5e88d388e', 'df4a12ce646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1762646d11f1ae7470b5e88d388e'),
(139, 62, 1016, 'The Locked Door (freida)', 1.00, 300.00, 'wholesale', 3, 'Wholesale 3', 300.00, NULL, NULL, 'df5467eb646d11f1ae7470b5e88d388e', 'df4a1384646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b122f646d11f1ae7470b5e88d388e'),
(140, 62, 1022, 'makeup', 1.00, 100.00, 'wholesale', 3, 'Wholesale 3', 100.00, NULL, NULL, 'df54686e646d11f1ae7470b5e88d388e', 'df4a1384646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1655646d11f1ae7470b5e88d388e'),
(141, 62, 1034, 'waterplant', 1.00, 0.00, 'wholesale', 3, 'Wholesale 3', 0.00, NULL, NULL, 'df5468ee646d11f1ae7470b5e88d388e', 'df4a1384646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1fd3646d11f1ae7470b5e88d388e'),
(142, 62, 1033, 'waterplant', 1.00, 0.00, 'wholesale', 3, 'Wholesale 3', 0.00, NULL, NULL, 'df546970646d11f1ae7470b5e88d388e', 'df4a1384646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1ecd646d11f1ae7470b5e88d388e'),
(143, 62, 1023, 'logo', 1.00, 2000.00, 'wholesale', 3, 'Wholesale 3', 2000.00, NULL, NULL, 'df5469f5646d11f1ae7470b5e88d388e', 'df4a1384646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1762646d11f1ae7470b5e88d388e'),
(144, 63, 1018, 'Fi Amanillah', 1.00, 300.00, 'wholesale', 3, 'Wholesale 3', 300.00, NULL, NULL, 'df546a79646d11f1ae7470b5e88d388e', 'df4a1439646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1440646d11f1ae7470b5e88d388e'),
(145, 63, 1019, 'The Sword of A...', 1.00, 700.00, 'wholesale', 3, 'Wholesale 3', 700.00, NULL, NULL, 'df546afa646d11f1ae7470b5e88d388e', 'df4a1439646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1547646d11f1ae7470b5e88d388e'),
(146, 63, 1022, 'makeup', 1.00, 100.00, 'wholesale', 3, 'Wholesale 3', 100.00, NULL, NULL, 'df546b7b646d11f1ae7470b5e88d388e', 'df4a1439646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1655646d11f1ae7470b5e88d388e'),
(147, 63, 1023, 'logo', 1.00, 2000.00, 'wholesale', 3, 'Wholesale 3', 2000.00, NULL, NULL, 'df546bfe646d11f1ae7470b5e88d388e', 'df4a1439646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1762646d11f1ae7470b5e88d388e'),
(148, 63, 1033, 'waterplant', 1.00, 0.00, 'wholesale', 3, 'Wholesale 3', 0.00, NULL, NULL, 'df546c81646d11f1ae7470b5e88d388e', 'df4a1439646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1ecd646d11f1ae7470b5e88d388e'),
(149, 64, 1034, 'book', 2.00, 300.00, 'wholesale', 3, 'Wholesale 3', 600.00, NULL, NULL, 'df546d03646d11f1ae7470b5e88d388e', 'df4a14f1646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1fd3646d11f1ae7470b5e88d388e'),
(150, 65, 1034, 'book', 1.00, 300.00, 'wholesale', 3, 'Wholesale 3', 300.00, NULL, NULL, 'df546d84646d11f1ae7470b5e88d388e', 'df4a15a7646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1fd3646d11f1ae7470b5e88d388e'),
(151, 65, 1033, 'waterplant', 1.00, 0.00, 'wholesale', 3, 'Wholesale 3', 0.00, NULL, NULL, 'df546e07646d11f1ae7470b5e88d388e', 'df4a15a7646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1ecd646d11f1ae7470b5e88d388e'),
(152, 66, 86, 'kuch', 1.00, 250.00, 'default', 0, '', 250.00, NULL, NULL, 'df546f7d646d11f1ae7470b5e88d388e', 'df4a165d646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df6fb855646d11f1ae7470b5e88d388e'),
(153, 67, 1030, 'Diaper', 1.00, 300.00, 'default', 0, '', 300.00, NULL, NULL, 'df54701c646d11f1ae7470b5e88d388e', 'df4a1713646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1dcc646d11f1ae7470b5e88d388e'),
(154, 67, 1028, 'detol', 1.00, 399.00, 'default', 0, '', 399.00, NULL, NULL, 'df5470b2646d11f1ae7470b5e88d388e', 'df4a1713646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1bca646d11f1ae7470b5e88d388e'),
(155, 68, 1038, 'Olim 2 in 1 Shampoo + Conditioner', 1.00, 400.00, 'default', 0, 'Default', 400.00, NULL, NULL, 'df547261646d11f1ae7470b5e88d388e', 'df4a17c8646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b2764646d11f1ae7470b5e88d388e'),
(160, 71, 1036, 'waterplant', 1.00, 0.00, 'default', 0, '', 0.00, NULL, NULL, 'df547318646d11f1ae7470b5e88d388e', 'df4a187d646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b21fa646d11f1ae7470b5e88d388e'),
(165, 74, 1042, 'chicken steak', 1.00, 1600.00, 'default', 0, 'Restaurant', 1600.00, 1, '0', 'df5473b9646d11f1ae7470b5e88d388e', 'df4a1939646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b2b87646d11f1ae7470b5e88d388e'),
(166, 75, 1033, 'waterplant', 2.00, 0.00, 'default', 0, '', 0.00, NULL, NULL, 'df547513646d11f1ae7470b5e88d388e', 'df4a1a22646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1ecd646d11f1ae7470b5e88d388e'),
(167, 75, 1023, 'logo', 1.00, 2000.00, 'default', 0, '', 2000.00, NULL, NULL, 'df5475c6646d11f1ae7470b5e88d388e', 'df4a1a22646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1762646d11f1ae7470b5e88d388e'),
(168, 75, 1022, 'makeup', 1.00, 100.00, 'default', 0, '', 100.00, NULL, NULL, 'df54764e646d11f1ae7470b5e88d388e', 'df4a1a22646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1655646d11f1ae7470b5e88d388e'),
(169, 75, 1019, 'The Sword of A...', 2.00, 700.00, 'default', 0, '', 1400.00, NULL, NULL, 'df5476d2646d11f1ae7470b5e88d388e', 'df4a1a22646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1547646d11f1ae7470b5e88d388e'),
(170, 75, 1034, 'book', 2.00, 200.00, 'default', 0, '', 400.00, NULL, NULL, 'df547753646d11f1ae7470b5e88d388e', 'df4a1a22646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1fd3646d11f1ae7470b5e88d388e'),
(171, 75, 1040, 'FIber glass', 2.00, 900.00, 'default', 0, '', 1800.00, NULL, NULL, 'df5477d1646d11f1ae7470b5e88d388e', 'df4a1a22646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b2973646d11f1ae7470b5e88d388e'),
(172, 75, 1041, 'Laptop Scren for HP', 1.00, 0.00, 'default', 0, '', 0.00, NULL, NULL, 'df54784f646d11f1ae7470b5e88d388e', 'df4a1a22646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b2a7d646d11f1ae7470b5e88d388e'),
(173, 75, 1040, 'FIber glass', 1.00, 800.00, 'wholesale', 2, 'Wholesale 2', 800.00, NULL, NULL, 'df5478cd646d11f1ae7470b5e88d388e', 'df4a1a22646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b2973646d11f1ae7470b5e88d388e'),
(174, 75, 1034, 'book', 1.00, 290.00, 'wholesale', 2, 'Wholesale 2', 290.00, NULL, NULL, 'df54794a646d11f1ae7470b5e88d388e', 'df4a1a22646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1fd3646d11f1ae7470b5e88d388e'),
(175, 75, 1040, 'FIber glass', 1.00, 750.00, 'wholesale', 3, 'Wholesale 3', 750.00, NULL, NULL, 'df5479c7646d11f1ae7470b5e88d388e', 'df4a1a22646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b2973646d11f1ae7470b5e88d388e'),
(176, 75, 1018, 'Fi Amanillah', 1.00, 300.00, 'default', 0, '', 300.00, NULL, NULL, 'df547a45646d11f1ae7470b5e88d388e', 'df4a1a22646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b1440646d11f1ae7470b5e88d388e'),
(177, 76, 1043, 'chopped steak', 1.00, 2500.00, 'default', 0, 'Restaurant', 2500.00, 2, '0', 'df547ae7646d11f1ae7470b5e88d388e', 'df4a1ae0646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b2c92646d11f1ae7470b5e88d388e'),
(178, 77, 1047, 'raita', 1.00, 50.00, 'default', 0, 'Restaurant', 50.00, 5, '0', 'df547b75646d11f1ae7470b5e88d388e', 'df4a1ba6646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b30ca646d11f1ae7470b5e88d388e'),
(179, 77, 1049, 'colddrink', 1.00, 100.00, 'default', 0, 'Restaurant', 100.00, 6, '0', 'df547bfe646d11f1ae7470b5e88d388e', 'df4a1ba6646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b361f646d11f1ae7470b5e88d388e'),
(180, 78, 1037, 'CAMRA', 1.00, 200.00, 'default', 0, 'Restaurant', 200.00, 23, '0', 'df547c88646d11f1ae7470b5e88d388e', 'df4a1c68646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df7b2651646d11f1ae7470b5e88d388e'),
(181, 78, 86, 'kuch', 1.00, 250.00, 'default', 0, 'Restaurant', 250.00, 24, '0', 'df547d0c646d11f1ae7470b5e88d388e', 'df4a1c68646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df6fb855646d11f1ae7470b5e88d388e'),
(182, 78, 85, 'water galon', 1.00, 100.00, 'default', 0, 'Restaurant', 100.00, 25, '0', 'df547d8f646d11f1ae7470b5e88d388e', 'df4a1c68646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df6fb759646d11f1ae7470b5e88d388e'),
(183, 78, 84, 'water', 1.00, 1000.00, 'default', 0, 'Restaurant', 1000.00, 26, '0', 'df547e13646d11f1ae7470b5e88d388e', 'df4a1c68646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df6fb648646d11f1ae7470b5e88d388e'),
(184, 78, 86, 'kuch', 1.00, 250.00, 'default', 0, 'Restaurant', 250.00, 27, '0', 'df547e98646d11f1ae7470b5e88d388e', 'df4a1c68646d11f1ae7470b5e88d388e', 'synced', '2026-06-10 01:44:08', NULL, NULL, NULL, NULL, NULL, 'df6fb855646d11f1ae7470b5e88d388e'),
(185, 79, 1041, 'Laptop Scren for HP', 1.00, 0.00, 'default', 0, '', 0.00, NULL, NULL, NULL, NULL, 'pending', NULL, NULL, '2026-06-12 04:08:34', NULL, NULL, NULL, NULL),
(186, 79, 1044, 'pepso', 1.00, 240.00, 'default', 0, '', 240.00, NULL, NULL, NULL, NULL, 'pending', NULL, NULL, '2026-06-12 04:08:34', NULL, NULL, NULL, NULL),
(187, 79, 1046, 'ice', 1.00, 100.00, 'default', 0, '', 100.00, NULL, NULL, NULL, NULL, 'pending', NULL, NULL, '2026-06-12 04:08:34', NULL, NULL, NULL, NULL),
(188, 80, 1051, 'offline', 1.00, 0.00, 'default', 0, '', 0.00, NULL, NULL, NULL, NULL, 'pending', NULL, NULL, '2026-06-12 04:38:02', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

CREATE TABLE `services` (
  `id` int(11) NOT NULL,
  `client_id` int(11) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `name` varchar(100) DEFAULT NULL,
  `category` varchar(150) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `duration` varchar(100) DEFAULT NULL,
  `branch` varchar(150) DEFAULT NULL,
  `staff` varchar(150) DEFAULT NULL,
  `is_popular` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `services`
--

INSERT INTO `services` (`id`, `client_id`, `photo`, `name`, `category`, `description`, `price`, `duration`, `branch`, `staff`, `is_popular`, `status`, `created_at`, `updated_at`) VALUES
(6, 20, '', 'Hair Cutting', 'Salon', 'testing servicess', 500.00, '30 min', 'Main Branch', 'Asad', 1, 'active', '2026-05-06 14:10:32', '2026-05-06 14:10:32');

-- --------------------------------------------------------

--
-- Table structure for table `staff_attendance`
--

CREATE TABLE `staff_attendance` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `status` enum('present','absent','leave') DEFAULT 'present',
  `check_in` time DEFAULT NULL,
  `check_out` time DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `staff_attendance`
--

INSERT INTO `staff_attendance` (`id`, `client_id`, `staff_id`, `attendance_date`, `status`, `check_in`, `check_out`, `created_at`) VALUES
(10, 20, 131, '2026-05-07', 'present', '11:00:00', '19:00:00', '2026-05-07 07:30:00'),
(11, 29, 148, '2026-05-18', 'present', '00:00:00', '00:00:00', '2026-05-18 11:34:17'),
(12, 29, 151, '2026-05-18', 'present', '00:00:00', '00:00:00', '2026-05-18 11:42:57'),
(13, 29, 151, '2026-06-04', 'present', '04:05:00', '08:08:00', '2026-06-04 08:01:22');

-- --------------------------------------------------------

--
-- Table structure for table `staff_commissions`
--

CREATE TABLE `staff_commissions` (
  `id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `commission_amount` double NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `commission_type` enum('percentage','fixed','tiered') DEFAULT 'percentage',
  `fixed_amount` decimal(10,2) DEFAULT 0.00,
  `min_sale_target` decimal(10,2) DEFAULT 0.00,
  `max_cap` decimal(10,2) DEFAULT 0.00,
  `branch_id` int(11) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `tiered_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tiered_config`)),
  `client_id` int(11) DEFAULT NULL,
  `paid_amount` decimal(10,2) DEFAULT 0.00,
  `pending_amount` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `staff_commission_rates`
--

CREATE TABLE `staff_commission_rates` (
  `user_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `percentage` double NOT NULL,
  `commission_type` enum('percentage','fixed','tiered') DEFAULT 'percentage',
  `fixed_amount` decimal(10,2) DEFAULT 0.00,
  `min_sale_target` decimal(10,2) DEFAULT 0.00,
  `max_cap` decimal(10,2) DEFAULT 0.00,
  `branch_id` int(11) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `tiered_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tiered_config`)),
  `client_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `staff_commission_rates`
--

INSERT INTO `staff_commission_rates` (`user_id`, `service_id`, `percentage`, `commission_type`, `fixed_amount`, `min_sale_target`, `max_cap`, `branch_id`, `status`, `tiered_config`, `client_id`) VALUES
(131, 6, 10, 'percentage', 0.00, 1000.00, 5000.00, 7, 'active', '[]', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `stock_movements`
--

CREATE TABLE `stock_movements` (
  `item_id` int(11) DEFAULT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `transfer_to_branch_id` int(11) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `qty` int(11) DEFAULT NULL,
  `type` enum('in','out','adjustment') DEFAULT NULL,
  `reason` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL,
  `item_uuid` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `stock_movements`
--

INSERT INTO `stock_movements` (`item_id`, `client_id`, `branch_id`, `transfer_to_branch_id`, `status`, `qty`, `type`, `reason`, `notes`, `reference_no`, `created_at`, `uuid`, `device_id`, `sync_status`, `last_synced_at`, `deleted_at`, `created_at_local`, `updated_at_local`, `item_uuid`) VALUES
(1042, 32, 0, NULL, 'active', 1, 'out', 'Restaurant Sale', 'Restaurant bill finalized against INV-26-00074', 'INV-26-00074', '2026-06-10 01:44:09', 'df5fb38e646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df7b2b87646d11f1ae7470b5e88d388e'),
(1043, 32, 0, NULL, 'active', 1, 'out', 'Restaurant Sale', 'Restaurant bill finalized against INV-26-00076', 'INV-26-00076', '2026-06-10 01:44:09', 'df5fd126646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df7b2c92646d11f1ae7470b5e88d388e'),
(1047, 32, 18, NULL, 'active', 1, 'out', 'Restaurant Sale', 'Restaurant bill finalized against INV-26-00077', 'INV-26-00077', '2026-06-10 01:44:09', 'df5fd2dc646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df7b30ca646d11f1ae7470b5e88d388e'),
(1049, 32, 18, NULL, 'active', 1, 'out', 'Restaurant Sale', 'Restaurant bill finalized against INV-26-00077', 'INV-26-00077', '2026-06-10 01:44:09', 'df5fd3ad646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df7b361f646d11f1ae7470b5e88d388e'),
(1037, 27, 18, NULL, 'active', 1, 'out', 'Restaurant Sale', 'Restaurant bill finalized against INV-26-00078', 'INV-26-00078', '2026-06-10 01:44:09', 'df5fd461646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df7b2651646d11f1ae7470b5e88d388e'),
(86, 27, 18, NULL, 'active', 1, 'out', 'Restaurant Sale', 'Restaurant bill finalized against INV-26-00078', 'INV-26-00078', '2026-06-10 01:44:09', 'df5fd511646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df6fb855646d11f1ae7470b5e88d388e'),
(85, 27, 18, NULL, 'active', 1, 'out', 'Restaurant Sale', 'Restaurant bill finalized against INV-26-00078', 'INV-26-00078', '2026-06-10 01:44:09', 'df5fd5b2646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df6fb759646d11f1ae7470b5e88d388e'),
(84, 27, 18, NULL, 'active', 1, 'out', 'Restaurant Sale', 'Restaurant bill finalized against INV-26-00078', 'INV-26-00078', '2026-06-10 01:44:09', 'df5fd655646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df6fb648646d11f1ae7470b5e88d388e'),
(86, 27, 18, NULL, 'active', 1, 'out', 'Restaurant Sale', 'Restaurant bill finalized against INV-26-00078', 'INV-26-00078', '2026-06-10 01:44:09', 'df5fd6f4646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL, 'df6fb855646d11f1ae7470b5e88d388e');

-- --------------------------------------------------------

--
-- Table structure for table `subscriptions`
--

CREATE TABLE `subscriptions` (
  `id` int(11) NOT NULL,
  `client_id` int(11) DEFAULT NULL,
  `plan_id` int(11) DEFAULT NULL,
  `status` enum('active','expired','trial','suspended') DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subscriptions`
--

INSERT INTO `subscriptions` (`id`, `client_id`, `plan_id`, `status`, `start_date`, `end_date`) VALUES
(26, 18, 1, 'active', '2026-05-06', '2026-08-04'),
(27, 20, 1, 'active', '2026-05-05', '2026-06-06'),
(28, 22, 3, 'active', '2026-05-07', '2026-06-07'),
(29, 23, 8, 'active', '2026-05-08', '2027-06-07'),
(30, 24, 3, 'active', '2026-05-08', '2026-06-07'),
(31, 25, 3, 'active', '2026-05-08', '2026-06-07'),
(32, 26, 10, 'active', '2026-05-19', '2026-06-20'),
(33, 27, 4, 'active', '2026-05-09', '2026-06-08'),
(34, 18, NULL, 'active', '2026-05-07', '2026-07-09'),
(35, 22, 8, 'active', '2026-05-11', '2026-06-11'),
(36, 29, 6, 'active', '2026-05-11', '2026-06-13'),
(39, 31, 8, 'active', '2026-05-13', '2026-06-20'),
(41, 26, 10, 'active', '2026-05-19', '2026-06-19'),
(42, 32, 11, 'active', '2026-06-02', '2026-07-04'),
(43, 34, 12, 'active', '2026-06-04', '2026-08-06');

-- --------------------------------------------------------

--
-- Table structure for table `subscription_features`
--

CREATE TABLE `subscription_features` (
  `id` int(11) NOT NULL,
  `subscription_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `feature_key` varchar(100) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `subscription_features`
--

INSERT INTO `subscription_features` (`id`, `subscription_id`, `client_id`, `feature_key`, `enabled`, `created_at`) VALUES
(1, 34, 18, 'dashboard', 1, '2026-05-09 14:19:23'),
(2, 34, 18, 'pos_sales', 1, '2026-05-09 14:19:23'),
(3, 34, 18, 'invoices', 1, '2026-05-09 14:19:23'),
(4, 34, 18, 'inventory', 1, '2026-05-09 14:19:23'),
(5, 34, 18, 'products', 1, '2026-05-09 14:19:23'),
(6, 34, 18, 'item_variants', 1, '2026-05-09 14:19:23'),
(7, 34, 18, 'inventory_adjustments', 1, '2026-05-09 14:19:23'),
(8, 34, 18, 'customers', 1, '2026-05-09 14:19:23'),
(9, 34, 18, 'suppliers', 1, '2026-05-09 14:19:23'),
(10, 34, 18, 'users', 1, '2026-05-09 14:19:23'),
(11, 34, 18, 'reports', 1, '2026-05-09 14:19:23'),
(12, 34, 18, 'quotations', 1, '2026-05-09 14:19:23'),
(13, 34, 18, 'wholesale_module', 1, '2026-05-09 14:19:23'),
(14, 34, 18, 'fbr_integration', 1, '2026-05-09 14:19:23'),
(15, 34, 18, 'tables', 1, '2026-05-09 14:19:23'),
(16, 34, 18, 'reservations', 1, '2026-05-09 14:19:23'),
(17, 34, 18, 'recipes', 1, '2026-05-09 14:19:23'),
(18, 34, 18, 'memberships', 1, '2026-05-09 14:19:23'),
(19, 34, 18, 'appointments', 1, '2026-05-09 14:19:23'),
(20, 34, 18, 'attendance', 1, '2026-05-09 14:19:23'),
(21, 34, 18, 'commissions', 1, '2026-05-09 14:19:23'),
(22, 34, 18, 'supplier_management', 1, '2026-05-09 14:19:23'),
(23, 34, 18, 'supplier_ledger', 1, '2026-05-09 14:19:23'),
(24, 34, 18, 'customer_ledger', 1, '2026-05-09 14:19:23'),
(25, 34, 18, 'profit_loss', 1, '2026-05-09 14:19:23'),
(26, 34, 18, 'daily_sales', 1, '2026-05-09 14:19:23'),
(27, 34, 18, 'cashier_shift', 1, '2026-05-09 14:19:23'),
(28, 34, 18, 'low_stock', 1, '2026-05-09 14:19:23'),
(29, 34, 18, 'tax_summary', 1, '2026-05-09 14:19:23'),
(30, 34, 18, 'discounts', 1, '2026-05-09 14:19:23'),
(31, 34, 18, 'taxes', 1, '2026-05-09 14:19:23'),
(32, 34, 18, 'multi_branch', 1, '2026-05-09 14:19:23'),
(33, 34, 18, 'payments', 1, '2026-05-09 14:19:23'),
(34, 34, 18, 'expenses', 1, '2026-05-09 14:19:23'),
(35, 34, 18, 'returns', 1, '2026-05-09 14:19:23'),
(36, 34, 18, 'notifications', 1, '2026-05-09 14:19:23'),
(37, 34, 18, 'settings', 1, '2026-05-09 14:19:23'),
(38, 34, 18, 'integrations', 1, '2026-05-09 14:19:23'),
(39, 34, 18, 'barcode', 1, '2026-05-09 14:19:23'),
(40, 34, 18, 'mobile_access', 1, '2026-05-09 14:19:23'),
(41, 34, 18, 'subscriptions', 1, '2026-05-09 14:19:23'),
(42, 34, 18, 'water_delivery', 1, '2026-05-09 14:19:23'),
(43, 34, 18, 'delivery_routes', 1, '2026-05-09 14:19:23'),
(44, 34, 18, 'dispatch', 1, '2026-05-09 14:19:23'),
(45, 34, 18, 'delivery_assign', 1, '2026-05-09 14:19:23'),
(46, 34, 18, 'distribution_operation', 1, '2026-05-09 14:19:23'),
(47, 34, 18, 'empty_bottles', 1, '2026-05-09 14:19:23'),
(48, 34, 18, 'distribution', 1, '2026-05-09 14:19:23'),
(49, 34, 18, 'dist_dashboard', 1, '2026-05-09 14:19:23'),
(50, 34, 18, 'dist_routes', 1, '2026-05-09 14:19:23'),
(51, 34, 18, 'dist_drivers', 1, '2026-05-09 14:19:23'),
(52, 34, 18, 'dist_dispatch', 1, '2026-05-09 14:19:23'),
(53, 34, 18, 'dist_collections', 1, '2026-05-09 14:19:23'),
(54, 34, 18, 'dist_reports', 1, '2026-05-09 14:19:23'),
(55, 34, 18, 'real_estate', 1, '2026-05-09 14:19:23'),
(56, 34, 18, 're_dashboard', 1, '2026-05-09 14:19:23'),
(57, 34, 18, 're_agency_leads', 1, '2026-05-09 14:19:23'),
(58, 34, 18, 're_followups', 1, '2026-05-09 14:19:23'),
(59, 34, 18, 're_site_visits', 1, '2026-05-09 14:19:23'),
(60, 34, 18, 're_properties', 1, '2026-05-09 14:19:23'),
(61, 34, 18, 're_deals', 1, '2026-05-09 14:19:23'),
(62, 34, 18, 're_agent_assignment', 1, '2026-05-09 14:19:23'),
(63, 34, 18, 're_agent_commissions', 1, '2026-05-09 14:19:23'),
(64, 34, 18, 're_reports', 1, '2026-05-09 14:19:23'),
(65, 35, 22, 'dashboard', 1, '2026-05-11 10:12:35'),
(66, 35, 22, 'pos_sales', 1, '2026-05-11 10:12:35'),
(67, 35, 22, 'invoices', 1, '2026-05-11 10:12:35'),
(68, 35, 22, 'inventory', 1, '2026-05-11 10:12:35'),
(69, 35, 22, 'products', 1, '2026-05-11 10:12:35'),
(70, 35, 22, 'item_variants', 1, '2026-05-11 10:12:35'),
(71, 35, 22, 'inventory_adjustments', 1, '2026-05-11 10:12:35'),
(72, 35, 22, 'customers', 1, '2026-05-11 10:12:35'),
(73, 35, 22, 'suppliers', 1, '2026-05-11 10:12:35'),
(74, 35, 22, 'users', 1, '2026-05-11 10:12:35'),
(75, 35, 22, 'reports', 1, '2026-05-11 10:12:35'),
(76, 35, 22, 'quotations', 1, '2026-05-11 10:12:35'),
(77, 35, 22, 'memberships', 1, '2026-05-11 10:12:35'),
(78, 35, 22, 'customer_ledger', 1, '2026-05-11 10:12:35'),
(79, 35, 22, 'daily_sales', 1, '2026-05-11 10:12:35'),
(80, 35, 22, 'low_stock', 1, '2026-05-11 10:12:35'),
(81, 35, 22, 'payments', 1, '2026-05-11 10:12:35'),
(82, 35, 22, 'returns', 1, '2026-05-11 10:12:35'),
(83, 35, 22, 'notifications', 1, '2026-05-11 10:12:35'),
(84, 35, 22, 'barcode', 1, '2026-05-11 10:12:35'),
(85, 35, 22, 'dispatch', 1, '2026-05-11 10:12:35'),
(86, 35, 22, 'delivery_assign', 1, '2026-05-11 10:12:35'),
(87, 35, 22, 'distribution_operation', 1, '2026-05-11 10:12:35'),
(88, 35, 22, 'distribution', 1, '2026-05-11 10:12:35'),
(89, 35, 22, 'dist_routes', 1, '2026-05-11 10:12:35'),
(90, 35, 22, 'dist_drivers', 1, '2026-05-11 10:12:35'),
(91, 35, 22, 'dist_dispatch', 1, '2026-05-11 10:12:35'),
(92, 35, 22, 'dist_reports', 1, '2026-05-11 10:12:35'),
(213, 36, 29, 'dashboard', 1, '2026-05-12 07:14:25'),
(214, 36, 29, 'pos_sales', 1, '2026-05-12 07:14:25'),
(215, 36, 29, 'invoices', 1, '2026-05-12 07:14:25'),
(216, 36, 29, 'inventory', 1, '2026-05-12 07:14:25'),
(217, 36, 29, 'products', 1, '2026-05-12 07:14:25'),
(218, 36, 29, 'item_variants', 1, '2026-05-12 07:14:25'),
(219, 36, 29, 'inventory_adjustments', 1, '2026-05-12 07:14:25'),
(220, 36, 29, 'customers', 1, '2026-05-12 07:14:25'),
(221, 36, 29, 'suppliers', 1, '2026-05-12 07:14:25'),
(222, 36, 29, 'users', 1, '2026-05-12 07:14:25'),
(223, 36, 29, 'reports', 1, '2026-05-12 07:14:25'),
(224, 36, 29, 'quotations', 1, '2026-05-12 07:14:25'),
(225, 36, 29, 'wholesale_module', 1, '2026-05-12 07:14:25'),
(226, 36, 29, 'cashier_shift', 1, '2026-05-12 07:14:25'),
(227, 36, 29, 'tax_summary', 1, '2026-05-12 07:14:25'),
(228, 36, 29, 'discounts', 1, '2026-05-12 07:14:25'),
(229, 36, 29, 'multi_branch', 1, '2026-05-12 07:14:25'),
(230, 36, 29, 'payments', 1, '2026-05-12 07:14:25'),
(231, 36, 29, 'expenses', 1, '2026-05-12 07:14:25'),
(232, 36, 29, 'returns', 1, '2026-05-12 07:14:25'),
(233, 36, 29, 'notifications', 1, '2026-05-12 07:14:25'),
(234, 36, 29, 'settings', 1, '2026-05-12 07:14:25'),
(235, 36, 29, 'integrations', 1, '2026-05-12 07:14:25'),
(236, 36, 29, 'barcode', 1, '2026-05-12 07:14:25'),
(237, 36, 29, 'mobile_access', 1, '2026-05-12 07:14:25'),
(238, 36, 29, 'delivery_routes', 1, '2026-05-12 07:14:25'),
(239, 36, 29, 'dispatch', 1, '2026-05-12 07:14:25'),
(240, 36, 29, 'delivery_assign', 1, '2026-05-12 07:14:25'),
(241, 36, 29, 'distribution_operation', 1, '2026-05-12 07:14:25'),
(242, 36, 29, 'distribution', 1, '2026-05-12 07:14:25'),
(243, 42, 32, 'restaurant_bookings', 1, '2026-06-09 11:22:45'),
(244, 43, 34, 'restaurant_bookings', 1, '2026-06-09 11:22:45');

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `supplier_type` varchar(100) DEFAULT NULL,
  `payment_terms` varchar(100) DEFAULT NULL,
  `ntn_strn` varchar(100) DEFAULT NULL,
  `credit_limit` decimal(15,2) DEFAULT 0.00,
  `full_address` text DEFAULT NULL,
  `opening_balance` decimal(15,2) DEFAULT 0.00,
  `status` varchar(50) DEFAULT 'Active',
  `notes` text DEFAULT NULL,
  `preferred_supplier` tinyint(1) DEFAULT 0,
  `photo` varchar(255) DEFAULT NULL,
  `document` varchar(255) DEFAULT NULL,
  `pending_days` int(11) DEFAULT 2,
  `address` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`id`, `client_id`, `company_name`, `contact_person`, `name`, `phone`, `email`, `supplier_type`, `payment_terms`, `ntn_strn`, `credit_limit`, `full_address`, `opening_balance`, `status`, `notes`, `preferred_supplier`, `photo`, `document`, `pending_days`, `address`, `created_at`) VALUES
(5, 20, 'Growtech Solutions', 'Asad Shah', 'Growtech Solutions', '03312805498', 'info@growtechsolutionllc.net', 'Distributor', 'Cash', '110095', 50000.00, 'Dollman Center 3rd Floor oop Dollman Mall Tariq Road Karachi', 3000.00, 'Active', '0', 0, '', '', 3, 'Dollman Center 3rd Floor oop Dollman Mall Tariq Road Karachi', '2026-05-07 07:25:38'),
(6, 22, 'dua', 'misbahzakir', 'dua', '03134054522', 'misbahzakirshah@gmail.com', 'Distributor', 'Cash', '22', 1332.97, 'Pakistan Chowk, Saddar New Chali, Karachi, Pakistan', 323.00, 'Active', '0', 0, '', '', 333, 'Pakistan Chowk, Saddar New Chali, Karachi, Pakistan', '2026-05-12 12:48:13'),
(7, 27, 'demo', 'gapsoe', 'demo', '84873739922', '', 'Distributor', 'Cash', '', 5000.00, 'karachi pakisatan', 0.00, 'Active', '0', 0, '', '', 0, 'karachi pakisatan', '2026-05-12 13:01:50'),
(8, 23, 'Fitroot', 'Ahmed', 'Fitroot', '3424109933', '', 'Manufacturer', 'Cash', '', 0.00, 'Jdbbejdhdjsjsbbdud', 0.00, 'Active', '0', 0, '', '', 0, 'Jdbbejdhdjsjsbbdud', '2026-05-19 11:14:39'),
(9, 29, 'misbah', 'asadullah shah', 'misbah', '03312805498', 'asadullah.vg@gmail.com', 'Distributor', 'Cash', '1234', 0.00, '${webhookTrigger.payload.fields__city____title__}', 100.00, 'Active', '0', 0, '', '', 0, '${webhookTrigger.payload.fields__city____title__}', '2026-05-20 07:41:13'),
(10, 29, 'JDF', 'ALi', 'JDF', '0324092302', '', 'Wholesaler', '7 Days', '', 200000.00, 'SADAR', 0.00, 'Active', '0', 0, '', '', 0, 'SADAR', '2026-06-04 08:03:11');

-- --------------------------------------------------------

--
-- Table structure for table `supplier_ledger`
--

CREATE TABLE `supplier_ledger` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `transaction_date` date DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `debit` decimal(10,2) DEFAULT NULL,
  `credit` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `supplier_payments`
--

CREATE TABLE `supplier_payments` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `payment_date` date NOT NULL,
  `payment_method` varchar(100) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `settle_full` tinyint(1) NOT NULL DEFAULT 0,
  `send_receipt` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `attachment` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `supplier_payments`
--

INSERT INTO `supplier_payments` (`id`, `client_id`, `supplier_id`, `payment_date`, `payment_method`, `amount`, `settle_full`, `send_receipt`, `notes`, `attachment`, `created_at`) VALUES
(3, 20, 5, '2026-05-07', 'Cash', 500.00, 0, 1, '', '', '2026-05-07 07:28:20');

-- --------------------------------------------------------

--
-- Table structure for table `sync_conflicts`
--

CREATE TABLE `sync_conflicts` (
  `id` bigint(20) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `device_uuid` varchar(100) DEFAULT NULL,
  `table_name` varchar(100) NOT NULL,
  `record_uuid` varchar(64) NOT NULL,
  `local_payload` longtext DEFAULT NULL,
  `server_payload` longtext DEFAULT NULL,
  `resolution_status` enum('pending','local_wins','server_wins','manual') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sync_mappings`
--

CREATE TABLE `sync_mappings` (
  `id` bigint(20) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `device_uuid` varchar(100) NOT NULL,
  `table_name` varchar(100) NOT NULL,
  `local_id` bigint(20) DEFAULT NULL,
  `local_uuid` varchar(64) NOT NULL,
  `cloud_id` bigint(20) DEFAULT NULL,
  `cloud_uuid` varchar(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sync_queue`
--

CREATE TABLE `sync_queue` (
  `id` bigint(20) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `device_id` varchar(100) NOT NULL,
  `table_name` varchar(100) NOT NULL,
  `record_uuid` varchar(100) NOT NULL,
  `action` enum('insert','update','delete') NOT NULL,
  `payload` longtext NOT NULL,
  `sync_status` enum('pending','processing','synced','failed') DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `synced_at` datetime DEFAULT NULL,
  `batch_uuid` varchar(64) DEFAULT NULL,
  `retry_count` int(11) NOT NULL DEFAULT 0,
  `next_retry_at` datetime DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `locked_by` varchar(100) DEFAULT NULL,
  `server_response` longtext DEFAULT NULL,
  `payload_hash` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sync_queue`
--

INSERT INTO `sync_queue` (`id`, `client_id`, `branch_id`, `device_id`, `table_name`, `record_uuid`, `action`, `payload`, `sync_status`, `error_message`, `created_at`, `synced_at`, `batch_uuid`, `retry_count`, `next_retry_at`, `locked_at`, `locked_by`, `server_response`, `payload_hash`) VALUES
(1, 29, 16, 'desktop_a2e45ba02a46972734bdebdb7f24b404', 'items', '319f3521b2fcdbf892968aec00b9e2a5', 'insert', '{\"id\":1053,\"uuid\":\"319f3521b2fcdbf892968aec00b9e2a5\",\"client_id\":29,\"branch_id\":16,\"device_id\":\"desktop_a2e45ba02a46972734bdebdb7f24b404\",\"name\":\"offline3\",\"sku\":\"\",\"type\":\"product\",\"description\":\"\",\"price\":0,\"cost\":0,\"wholesaler_price_1\":0,\"wholesaler_price_2\":0,\"wholesaler_price_3\":0,\"track_inventory\":1,\"stock_qty\":0,\"supplier_id\":null,\"has_variants\":0,\"variant_details\":\"\",\"has_expiry\":0,\"expiry_date\":null,\"is_raw_item\":0,\"image\":\"\",\"sync_status\":\"pending\"}', 'pending', NULL, '2026-06-12 06:18:07', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `table_reservations`
--

CREATE TABLE `table_reservations` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `table_id` int(11) NOT NULL,
  `customer_name` varchar(100) NOT NULL,
  `customer_phone` varchar(50) NOT NULL,
  `reservation_date` date NOT NULL,
  `reservation_time` time NOT NULL,
  `status` enum('Pending','Confirmed','Cancelled') NOT NULL DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `taxes`
--

CREATE TABLE `taxes` (
  `id` int(10) UNSIGNED NOT NULL,
  `client_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `type` varchar(100) NOT NULL DEFAULT 'General',
  `application_on` varchar(100) NOT NULL DEFAULT 'All Sales',
  `region` varchar(100) NOT NULL DEFAULT 'All',
  `inclusive` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `fbr_date` date DEFAULT NULL,
  `effective_date` date DEFAULT NULL,
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `taxes`
--

INSERT INTO `taxes` (`id`, `client_id`, `name`, `rate`, `type`, `application_on`, `region`, `inclusive`, `status`, `created_at`, `updated_at`, `fbr_date`, `effective_date`, `uuid`, `device_id`, `sync_status`, `last_synced_at`, `deleted_at`, `created_at_local`, `updated_at_local`) VALUES
(2, 20, 'VAT', 20.00, 'general', 'product', '7', 0, 'active', '2026-05-07 07:38:36', '2026-06-10 01:44:08', NULL, NULL, 'df7fa401646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(3, 20, 'GST', 10.00, 'general', 'product', '7', 0, 'active', '2026-05-07 07:39:58', '2026-06-10 01:44:08', NULL, NULL, 'df7fa83a646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `client_id` int(11) DEFAULT NULL,
  `role_id` int(11) NOT NULL,
  `name` varchar(150) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `status` enum('inactive','active','suspended') DEFAULT 'inactive',
  `email_verified` tinyint(1) DEFAULT 0,
  `verification_token` varchar(255) DEFAULT NULL,
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_token_expires` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `client_id`, `role_id`, `name`, `email`, `phone`, `password`, `status`, `email_verified`, `verification_token`, `reset_token`, `reset_token_expires`, `created_at`, `uuid`, `device_id`, `sync_status`, `last_synced_at`, `deleted_at`, `created_at_local`, `updated_at_local`) VALUES
(43, NULL, 1, 'Dheeraj', 'gapsoedheeraj@gmail.com', NULL, '$2y$10$bbJ1f1kjs9Gc09FDTtHDHeLep9TY7vYbpjqrd04xxoKFS2OfFjMRW', 'active', 1, NULL, NULL, NULL, '2026-06-10 01:44:08', 'df6b5569646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(129, 18, 0, 'ali@gmail.com', 'ali@gmail.com', '03312805498', '$2y$10$gQ28S7bJtVuFhp8bxPKfe.trsNEc89XnvgtKTV6kdVb1/5n9QxMqa', 'active', 0, NULL, NULL, NULL, '2026-06-10 01:44:08', 'df6b6572646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(130, 20, 0, 'Asad', 'asad@gmail.com', '03312805498', '$2y$10$jCuxcXWbXsEeZRqyHSdl5eDOHtJuZVWmOMMDDK5UFlVPPIBLvqpIm', 'active', 0, NULL, NULL, NULL, '2026-06-10 01:44:08', 'df6b6728646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(131, 20, 0, 'Misbah', 'misbahzakirshah@gmail.com', '+923134054522', '$2y$10$poQRcxSVNcNrybN1Aep96OprbrJL89whCMbZuvdPBI4wU0e1NFUrG', 'inactive', 0, NULL, NULL, NULL, '2026-06-10 01:44:08', 'df6b682e646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(132, 21, 0, 'Nauman', 'naumanali001@gmail.com', NULL, '$2y$10$klN.mPRVzi0f5er3NwN0cu4BUs9BytkBxVM2PezplrTvl72oP/vSy', 'inactive', 1, NULL, NULL, NULL, '2026-06-10 01:44:08', 'df6b6a0e646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(133, 22, 0, 'Rizwan', 'rizwaniqbal9600@gmail.com', '03000065202', '$2y$10$BhTNc47Kq6FOkg1R7SieUetzOa4TYCey1/Xf8ZIr8wtd6n4UgDh6y', 'active', 0, NULL, NULL, NULL, '2026-06-10 01:44:08', 'df6b6b76646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(134, 23, 0, 'Danish', 'designdkor@gmail.com', '03424109933', '$2y$10$NqYmtV0ll4RLeoIhPJl6VuvxsYakakZcBvCsEtAJhvGiiXkqBStYC', 'active', 0, '5bbbccf15d5f78417563f25f71c1eda25f66c0a9bd8c8dae807dc3d123101557', NULL, NULL, '2026-06-10 01:44:08', 'df6b6d03646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(135, 24, 0, 'Owais', 'designdkor12@gmail.com', '03282607998', '$2y$10$rop6FIezZOqCz4DWGBISI.QDClRVok0R3Z9KZoBKDfZZKe6a.JYs2', 'active', 0, NULL, NULL, NULL, '2026-06-10 01:44:08', 'df6b6e0f646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(136, 25, 0, 'Kalaswala', 'bajwa.agri.park@gmail.com', '03460244440', '$2y$10$p1rNHCupzX2gZfToQ1w0lub9lNnBtBtqXrXBZV8CKClsEKhGMqgY.', 'active', 0, NULL, NULL, NULL, '2026-06-10 01:44:08', 'df6b6ee6646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(137, 26, 0, 'Marriage hall', 'Pakistan.resturent@gmail.com', '03041204040', '$2y$10$IXMu6D19FaLtTm3SiJS.eecH.D.WfhzuCt6O5arZ346ELXQR2X.ia', 'active', 0, NULL, NULL, NULL, '2026-06-10 01:44:08', 'df6b6fbb646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(138, 27, 0, 'demo', 'demo@gmail.com', '03421777906', '$2y$10$80az5Ty8i8CF5f9AjpV3yOdnt.g//m6WTWcJWkKFlRU3Smcs9c6aO', 'active', 0, NULL, NULL, NULL, '2026-06-10 01:44:08', 'df6b7091646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(139, 24, 0, 'SYED ASAD ULLAH SHAH', 'syed38996@gmail.com', '+923312805498', '$2y$10$t342lVCozzrdngZzO4Cv2.7M6Iet9heUTuV/r8lTN/I27RH.m.c5q', 'inactive', 0, NULL, NULL, NULL, '2026-06-10 01:44:08', 'df6b7164646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(140, 24, 0, 'Misbah', 'misbah123@gmail.com', '03312805498', '$2y$10$d2az6G3kNQwpSLzrVxVoruqnaet72DNkOVdxuhAXA.V80r3S4o9Aa', 'inactive', 0, NULL, NULL, NULL, '2026-06-10 01:44:08', 'df6b7234646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(141, 27, 0, 'test Order', 'test@gmail.com', '+923134054522', '$2y$10$fsf1itJfkYODMUjHFYy4Sun8ie0IjCL0/3JRbMZ3IBZtsvQCfBF1K', 'active', 0, NULL, NULL, NULL, '2026-06-10 01:44:08', 'df6b737a646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(142, 24, 0, 'test Order', 'test@gmail.com', '+923134054522', '$2y$10$UcdgAN.0a.vtKWiKhjoDEe9SEczqfhQMNSQnLC227oxl3Q3rjQxeG', 'active', 0, NULL, NULL, NULL, '2026-06-10 01:44:08', 'df6b74f5646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(143, 24, 0, 'fatch', 'fatch@gmail.com', '+923134054522', '$2y$10$Od/pKwiKyn2NIDZAqEIYb.NXtOMPU5mJMAbmPjXR5j.f3vomYfSA6', 'active', 0, NULL, NULL, NULL, '2026-06-10 01:44:08', 'df6b7677646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(144, 24, 0, 'salar', 'salarshah115@gmail.com', '03158272186', '$2y$10$gzE4tYTZ/bTBiUNvUadHd.s2Scl2osisGMZqMybkrqW3HkSVFUyv6', 'active', 0, NULL, NULL, NULL, '2026-06-10 01:44:08', 'df6b77f0646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(145, 24, 0, '2m', 'testdd@gmail.com', '+923134054522', '$2y$10$8Izbhl2/ZPORoK.TgcJzFekwiYMta3zj.YWGUzY65mXhsoRwsVaF.', 'active', 0, NULL, NULL, NULL, '2026-06-10 01:44:08', 'df6b795e646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(146, 27, 0, 'get', 'get@gmail.com', '+923134054522', '$2y$10$iLeijnemMKSRwrONtohwvu/7y2h0WhzmmckJwc5k9JfSTx6FdZG6y', 'active', 0, NULL, NULL, NULL, '2026-06-10 01:44:08', 'df6b7b6a646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(148, 29, 0, 'dua', 'dua@gmail.com', '03312805498', '$2y$10$tArS0lbgGSW2FtgS.Ib4fu.GdyT8.X.HGTZvCj5u59vC4CiEH5paW', 'active', 0, NULL, '37a954d3856264a450e0aba1b9d7a42dd4a78e7f6b451c0080e707cffe0995d3', '2026-05-18 07:59:53', '2026-06-10 01:44:08', 'df6b7cb6646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(150, 31, 0, 'Rizwan Enterprises co', 'rizwaniqbal1122@gmail.com', '0300-0065202 ', '$2y$10$LxXVMXUGEvmTMVcoNAT/m.t9bHtZ7ed9/qly9HPNJnTja.7CpWX62', 'active', 0, NULL, NULL, NULL, '2026-06-10 01:44:08', 'df6b7e07646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(151, 29, 0, 'Muhammad Ahmed', 'duabookpalace6668@gmail.com', '03192862731', '$2y$10$ydLlVgdUZLX4KRdKwZkHv.UFmgPlDTNa/Cy.zJvgpuu.NJyOdJL0G', 'active', 0, NULL, NULL, NULL, '2026-06-10 01:44:08', 'df6b7f6c646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(152, 32, 0, 'resturent@gmail.com', 'resturent@gmail.com', '0327676423', '$2y$10$4AaETmtCNcYhJUERXvu3gOYRTQhXePUF7r.jXi0nkZM8LaMuGmVMi', 'active', 0, NULL, NULL, NULL, '2026-06-10 01:44:08', 'df6b80bb646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(154, 32, 0, 'misbah', 'gapsoedigital121@gmail.com', '12723661684', '$2y$10$15w42UwN9o7x.MWROQoyxubnZ2lWAApjuD0ESAE/ueW5NhOWkhdEm', 'active', 0, NULL, NULL, NULL, '2026-06-10 01:44:08', 'df6b820f646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(155, 33, 0, 'Test Dua', 'gapsoe2015@gmail.com', NULL, '$2y$10$/ZzkWZZkb09Jc0c.sGywQ.84UnfXrtCJ2CQe9HSleChbforzWjHXO', 'inactive', 0, '2fd061324f3a08f30564c3a55930022289cca9b85baa136be47e25f295fcd984', NULL, NULL, '2026-06-10 01:44:08', 'df6b834a646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL),
(156, 34, 0, 'dheeraj-resturent', 'dheeresturent@gmail.com', '03312805498', '$2y$10$4MTsmkLuVWWxQJQf05as8efYgfahd.FEW.Gx3swsybtfC2jUApBH.', 'active', 0, NULL, NULL, NULL, '2026-06-10 01:44:08', 'df6b841b646d11f1ae7470b5e88d388e', NULL, 'synced', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `users_permissions`
--

CREATE TABLE `users_permissions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `feature_key` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users_permissions`
--

INSERT INTO `users_permissions` (`id`, `user_id`, `feature_key`) VALUES
(249, 131, 'dashboard'),
(250, 131, 'invoices'),
(251, 131, 'pos_sales'),
(252, 131, 'inventory'),
(253, 131, 'inventory_adjustments'),
(254, 139, 'dashboard'),
(255, 139, 'pos_sales'),
(256, 139, 'inventory'),
(257, 140, 'dashboard'),
(258, 140, 'invoices'),
(259, 140, 'pos_sales'),
(260, 140, 'inventory'),
(261, 141, 'reservations'),
(262, 141, 'memberships'),
(263, 141, 'recipes'),
(264, 142, 'invoices'),
(265, 142, 'pos_sales'),
(266, 142, 'inventory_adjustments'),
(267, 143, 'pos_sales'),
(268, 143, 'invoices'),
(269, 144, 'dashboard'),
(270, 144, 'invoices'),
(271, 144, 'pos_sales'),
(272, 144, 'inventory'),
(273, 145, 'invoices'),
(274, 145, 'inventory'),
(275, 145, 'customers'),
(276, 146, 'pos_sales'),
(277, 146, 'tables'),
(278, 146, 'recipes'),
(279, 133, 'invoices'),
(280, 133, 'inventory'),
(281, 133, 'pos_sales'),
(282, 133, 'suppliers'),
(283, 133, 'customers'),
(284, 133, 'reports'),
(285, 133, 'customer_ledger'),
(286, 133, 'profit_loss'),
(287, 133, 'expenses'),
(288, 133, 'returns'),
(289, 151, 'invoices'),
(290, 151, 'pos_sales');

-- --------------------------------------------------------

--
-- Table structure for table `user_roles`
--

CREATE TABLE `user_roles` (
  `user_id` int(11) DEFAULT NULL,
  `role_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_roles`
--

INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES
(43, 1),
(129, 1),
(130, 3),
(133, 3),
(135, 3),
(136, 3),
(137, 3),
(138, 3),
(148, 3),
(150, 3),
(152, 3),
(156, 3);

-- --------------------------------------------------------

--
-- Table structure for table `water_dispatches`
--

CREATE TABLE `water_dispatches` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `dispatch_no` varchar(50) NOT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `route_id` int(11) DEFAULT NULL,
  `vehicle` varchar(150) DEFAULT NULL,
  `dispatch_date` date NOT NULL,
  `dispatch_time` time DEFAULT NULL,
  `total_orders` int(11) DEFAULT 0,
  `total_bottles` int(11) DEFAULT 0,
  `total_empty_due` int(11) DEFAULT 0,
  `status` enum('draft','ready','dispatched','completed','cancelled') DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `water_dispatch_items`
--

CREATE TABLE `water_dispatch_items` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `dispatch_id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `assignment_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `bottles_qty` int(11) DEFAULT 0,
  `empty_due` int(11) DEFAULT 0,
  `amount` decimal(12,2) DEFAULT 0.00,
  `payment_status` varchar(50) DEFAULT NULL,
  `item_status` enum('pending','loaded','delivered','returned','cancelled') DEFAULT 'pending',
  `loaded_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `water_routes`
--

CREATE TABLE `water_routes` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `route_code` varchar(50) NOT NULL,
  `route_name` varchar(150) NOT NULL,
  `area` varchar(150) DEFAULT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `driver_name` varchar(150) DEFAULT NULL,
  `vehicle_name` varchar(150) DEFAULT NULL,
  `delivery_days` varchar(100) DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `status` enum('Active','Inactive','On Hold') NOT NULL DEFAULT 'Active',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `water_route_customers`
--

CREATE TABLE `water_route_customers` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `route_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `sequence_no` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `water_route_deliveries`
--

CREATE TABLE `water_route_deliveries` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `route_id` int(11) NOT NULL,
  `delivery_date` date NOT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `driver_name` varchar(150) DEFAULT NULL,
  `vehicle_name` varchar(150) DEFAULT NULL,
  `total_orders` int(11) NOT NULL DEFAULT 0,
  `pending_orders` int(11) NOT NULL DEFAULT 0,
  `delivered_orders` int(11) NOT NULL DEFAULT 0,
  `total_collection` decimal(12,2) NOT NULL DEFAULT 0.00,
  `empty_bottles_collected` int(11) NOT NULL DEFAULT 0,
  `status` enum('Pending','In Progress','Completed') NOT NULL DEFAULT 'Pending',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `water_route_delivery_items`
--

CREATE TABLE `water_route_delivery_items` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `delivery_id` int(11) NOT NULL,
  `route_id` int(11) NOT NULL,
  `sale_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `sequence_no` int(11) NOT NULL DEFAULT 0,
  `delivered_qty` int(11) NOT NULL DEFAULT 0,
  `empty_returned_qty` int(11) NOT NULL DEFAULT 0,
  `damaged_qty` int(11) NOT NULL DEFAULT 0,
  `lost_qty` int(11) NOT NULL DEFAULT 0,
  `payment_collected` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('Pending','Delivered','Skipped') NOT NULL DEFAULT 'Pending',
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wholesale_customers`
--

CREATE TABLE `wholesale_customers` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `business_name` varchar(255) NOT NULL,
  `tenant_code` varchar(100) DEFAULT NULL,
  `subscription_plan` varchar(100) DEFAULT 'Standard',
  `status` enum('active','inactive') DEFAULT 'active',
  `effective_from` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `min_order_qty` int(11) DEFAULT 10,
  `min_order_value` decimal(12,2) DEFAULT 0.00,
  `assigned_price_list` varchar(150) DEFAULT 'Default Price List',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `branches`
--
ALTER TABLE `branches`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_branches_uuid` (`uuid`),
  ADD KEY `idx_branches_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_branches_device` (`device_id`);

--
-- Indexes for table `branch_sessions`
--
ALTER TABLE `branch_sessions`
  ADD PRIMARY KEY (`branch_id`);

--
-- Indexes for table `branch_users`
--
ALTER TABLE `branch_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `branch_id` (`branch_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_categories_uuid` (`uuid`),
  ADD KEY `idx_categories_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_categories_device` (`device_id`);

--
-- Indexes for table `clients`
--
ALTER TABLE `clients`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_industry` (`industry_id`),
  ADD KEY `idx_clients_uuid` (`uuid`),
  ADD KEY `idx_clients_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_clients_device` (`device_id`);

--
-- Indexes for table `client_settings`
--
ALTER TABLE `client_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_client_settings` (`client_id`),
  ADD KEY `idx_client_settings_uuid` (`uuid`),
  ADD KEY `idx_client_settings_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_client_settings_device` (`device_id`);

--
-- Indexes for table `coupons`
--
ALTER TABLE `coupons`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_coupons_uuid` (`uuid`),
  ADD KEY `idx_coupons_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_coupons_device` (`device_id`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_customers_uuid` (`uuid`),
  ADD KEY `idx_customers_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_customers_device` (`device_id`);

--
-- Indexes for table `customer_memberships`
--
ALTER TABLE `customer_memberships`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `delivery_drivers`
--
ALTER TABLE `delivery_drivers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_client_driver_code` (`client_id`,`driver_code`),
  ADD KEY `idx_client_status` (`client_id`,`status`);

--
-- Indexes for table `delivery_order_assignments`
--
ALTER TABLE `delivery_order_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_client_sale_assignment` (`client_id`,`sale_id`),
  ADD UNIQUE KEY `uniq_delivery_assignment_sale` (`client_id`,`sale_id`),
  ADD KEY `idx_driver_date` (`driver_id`,`delivery_date`),
  ADD KEY `idx_route_date` (`route_id`,`delivery_date`),
  ADD KEY `idx_client_status` (`client_id`,`status`);

--
-- Indexes for table `desktop_sync_state`
--
ALTER TABLE `desktop_sync_state`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_desktop_sync_state` (`device_uuid`,`table_name`),
  ADD KEY `idx_desktop_sync_state_client` (`client_id`,`branch_id`);

--
-- Indexes for table `devices`
--
ALTER TABLE `devices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `device_uuid_unique` (`device_uuid`),
  ADD KEY `idx_devices_client_branch` (`client_id`,`branch_id`),
  ADD KEY `idx_devices_status` (`status`);

--
-- Indexes for table `distribution_collection_logs`
--
ALTER TABLE `distribution_collection_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_dist_collection_logs_settlement` (`settlement_id`),
  ADD KEY `idx_dist_collection_logs_client` (`client_id`,`created_at`);

--
-- Indexes for table `distribution_collection_settlements`
--
ALTER TABLE `distribution_collection_settlements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_dist_settlement_no` (`client_id`,`settlement_no`),
  ADD KEY `idx_dist_settlement_driver` (`client_id`,`driver_id`,`settlement_date`),
  ADD KEY `idx_dist_settlement_status` (`client_id`,`settlement_status`),
  ADD KEY `idx_dist_settlement_date` (`client_id`,`settlement_date`);

--
-- Indexes for table `distribution_collection_settlement_items`
--
ALTER TABLE `distribution_collection_settlement_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_dist_settlement_assignment` (`client_id`,`assignment_id`),
  ADD KEY `idx_dist_settlement_items_settlement` (`settlement_id`),
  ADD KEY `idx_dist_settlement_items_driver` (`client_id`,`driver_id`),
  ADD KEY `idx_dist_settlement_items_sale` (`client_id`,`sale_id`);

--
-- Indexes for table `distribution_delivery_updates`
--
ALTER TABLE `distribution_delivery_updates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ddu_client_sale` (`client_id`,`sale_id`),
  ADD KEY `idx_ddu_assignment` (`assignment_id`),
  ADD KEY `idx_ddu_dispatch` (`dispatch_id`),
  ADD KEY `idx_ddu_status` (`client_id`,`new_status`),
  ADD KEY `idx_ddu_driver_date` (`client_id`,`driver_id`,`updated_at`);

--
-- Indexes for table `distribution_dispatches`
--
ALTER TABLE `distribution_dispatches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_distribution_dispatch_no` (`client_id`,`dispatch_no`),
  ADD KEY `idx_distribution_dispatch_client` (`client_id`,`status`),
  ADD KEY `idx_distribution_dispatch_route` (`client_id`,`route_id`),
  ADD KEY `idx_distribution_dispatch_driver` (`client_id`,`driver_id`),
  ADD KEY `idx_distribution_dispatch_date` (`client_id`,`dispatch_date`);

--
-- Indexes for table `distribution_dispatch_items`
--
ALTER TABLE `distribution_dispatch_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_distribution_dispatch_sale` (`client_id`,`sale_id`),
  ADD KEY `idx_distribution_dispatch_items_dispatch` (`dispatch_id`),
  ADD KEY `idx_distribution_dispatch_items_sale` (`client_id`,`sale_id`),
  ADD KEY `idx_distribution_dispatch_items_status` (`client_id`,`item_status`);

--
-- Indexes for table `distribution_routes`
--
ALTER TABLE `distribution_routes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_distribution_client_route_code` (`client_id`,`route_code`),
  ADD KEY `idx_distribution_routes_client_status` (`client_id`,`status`),
  ADD KEY `idx_distribution_routes_driver` (`driver_id`),
  ADD KEY `idx_distribution_routes_area_city` (`client_id`,`area`,`city`);

--
-- Indexes for table `distribution_route_customers`
--
ALTER TABLE `distribution_route_customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_distribution_route_customer` (`client_id`,`route_id`,`customer_id`),
  ADD KEY `idx_distribution_customer` (`client_id`,`customer_id`),
  ADD KEY `idx_distribution_route_sequence` (`route_id`,`sequence_no`);

--
-- Indexes for table `distribution_route_deliveries`
--
ALTER TABLE `distribution_route_deliveries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_distribution_route_date` (`client_id`,`route_id`,`delivery_date`),
  ADD UNIQUE KEY `uniq_distribution_delivery` (`client_id`,`route_id`,`delivery_date`),
  ADD KEY `idx_distribution_delivery_date_status` (`client_id`,`delivery_date`,`status`),
  ADD KEY `idx_distribution_delivery_route` (`route_id`);

--
-- Indexes for table `distribution_route_delivery_items`
--
ALTER TABLE `distribution_route_delivery_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_distribution_delivery_sale` (`client_id`,`delivery_id`,`sale_id`),
  ADD KEY `idx_distribution_delivery` (`delivery_id`),
  ADD KEY `idx_distribution_route_customer` (`route_id`,`customer_id`),
  ADD KEY `idx_distribution_sale` (`sale_id`),
  ADD KEY `idx_distribution_item_status` (`client_id`,`delivery_status`);

--
-- Indexes for table `empty_bottle_returns`
--
ALTER TABLE `empty_bottle_returns`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_client_id` (`client_id`),
  ADD KEY `idx_branch_id` (`branch_id`),
  ADD KEY `idx_sale_id` (`sale_id`),
  ADD KEY `idx_customer_id` (`customer_id`),
  ADD KEY `idx_item_id` (`item_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_return_date` (`return_date`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_expense_code` (`expense_code`),
  ADD KEY `client_id_idx` (`client_id`),
  ADD KEY `branch_id_idx` (`branch_id`),
  ADD KEY `idx_expenses_client_id` (`client_id`),
  ADD KEY `idx_expenses_branch_id` (`branch_id`),
  ADD KEY `idx_expenses_created_at` (`created_at`),
  ADD KEY `idx_expenses_uuid` (`uuid`),
  ADD KEY `idx_expenses_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_expenses_device` (`device_id`);

--
-- Indexes for table `global_settings`
--
ALTER TABLE `global_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `industries`
--
ALTER TABLE `industries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `integrations`
--
ALTER TABLE `integrations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_client_gateway` (`client_id`,`integration_type`);

--
-- Indexes for table `integration_logs`
--
ALTER TABLE `integration_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `items`
--
ALTER TABLE `items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_items_restaurant_menu` (`client_id`,`branch_id`,`is_restaurant_item`),
  ADD KEY `idx_items_kitchen_section` (`kitchen_section`),
  ADD KEY `idx_items_uuid` (`uuid`),
  ADD KEY `idx_items_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_items_device` (`device_id`);

--
-- Indexes for table `item_barcodes`
--
ALTER TABLE `item_barcodes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_barcode` (`barcode`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `client_id` (`client_id`);

--
-- Indexes for table `item_batches`
--
ALTER TABLE `item_batches`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `item_price_tiers`
--
ALTER TABLE `item_price_tiers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_client_branch_item` (`client_id`,`branch_id`,`item_id`),
  ADD KEY `idx_tier_type` (`tier_type`),
  ADD KEY `idx_item_price_tiers_uuid` (`uuid`),
  ADD KEY `idx_item_price_tiers_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_item_price_tiers_device` (`device_id`);

--
-- Indexes for table `item_recipes`
--
ALTER TABLE `item_recipes`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `item_variants`
--
ALTER TABLE `item_variants`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_item_variants_uuid` (`uuid`),
  ADD KEY `idx_item_variants_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_item_variants_device` (`device_id`);

--
-- Indexes for table `memberships`
--
ALTER TABLE `memberships`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `membership_benefits`
--
ALTER TABLE `membership_benefits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_membership_id` (`membership_id`);

--
-- Indexes for table `membership_card_templates`
--
ALTER TABLE `membership_card_templates`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_client_branch_customer` (`client_id`,`branch_id`,`customer_id`),
  ADD KEY `idx_sale_id` (`sale_id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_payment_client` (`client_id`),
  ADD KEY `idx_payment_method` (`payment_method`),
  ADD KEY `idx_payment_created_at` (`created_at`),
  ADD KEY `idx_payment_status` (`status`),
  ADD KEY `idx_payments_restaurant_session` (`restaurant_session_id`),
  ADD KEY `idx_payments_uuid` (`uuid`),
  ADD KEY `idx_payments_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_payments_device` (`device_id`),
  ADD KEY `idx_payments_sale_uuid` (`sale_uuid`),
  ADD KEY `idx_payments_session_uuid` (`restaurant_session_uuid`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `plans`
--
ALTER TABLE `plans`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `plan_features`
--
ALTER TABLE `plan_features`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_plan_feature` (`plan_id`,`feature_key`);

--
-- Indexes for table `pos_activity`
--
ALTER TABLE `pos_activity`
  ADD PRIMARY KEY (`id`),
  ADD KEY `branch_id` (`branch_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_pos_activity_uuid` (`uuid`),
  ADD KEY `idx_pos_activity_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_pos_activity_device` (`device_id`);

--
-- Indexes for table `production_batches`
--
ALTER TABLE `production_batches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pb_client_batch` (`client_id`,`batch_no`),
  ADD KEY `idx_pb_client` (`client_id`),
  ADD KEY `idx_pb_branch` (`branch_id`),
  ADD KEY `idx_pb_order` (`work_order_id`),
  ADD KEY `idx_pb_output` (`work_order_output_id`),
  ADD KEY `idx_pb_item` (`item_id`),
  ADD KEY `idx_pb_status` (`status`),
  ADD KEY `idx_pb_expiry` (`expiry_date`);

--
-- Indexes for table `production_formulas`
--
ALTER TABLE `production_formulas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pf_client` (`client_id`),
  ADD KEY `idx_pf_branch` (`branch_id`),
  ADD KEY `idx_pf_status` (`status`);

--
-- Indexes for table `production_formula_inputs`
--
ALTER TABLE `production_formula_inputs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pfi_formula` (`formula_id`),
  ADD KEY `idx_pfi_item` (`item_id`);

--
-- Indexes for table `production_formula_outputs`
--
ALTER TABLE `production_formula_outputs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pfo_formula` (`formula_id`),
  ADD KEY `idx_pfo_item` (`item_id`);

--
-- Indexes for table `production_wastage_logs`
--
ALTER TABLE `production_wastage_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pwl_client` (`client_id`),
  ADD KEY `idx_pwl_order` (`work_order_id`);

--
-- Indexes for table `production_work_orders`
--
ALTER TABLE `production_work_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pwo_client_no` (`client_id`,`work_order_no`),
  ADD KEY `idx_pwo_client` (`client_id`),
  ADD KEY `idx_pwo_branch` (`branch_id`),
  ADD KEY `idx_pwo_formula` (`formula_id`),
  ADD KEY `idx_pwo_status` (`status`);

--
-- Indexes for table `production_work_order_inputs`
--
ALTER TABLE `production_work_order_inputs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pwoi_order` (`work_order_id`),
  ADD KEY `idx_pwoi_item` (`item_id`);

--
-- Indexes for table `production_work_order_outputs`
--
ALTER TABLE `production_work_order_outputs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pwoo_order` (`work_order_id`),
  ADD KEY `idx_pwoo_item` (`item_id`);

--
-- Indexes for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `idx_supplier_id` (`supplier_id`);

--
-- Indexes for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `po_id` (`po_id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `idx_po_id` (`po_id`),
  ADD KEY `idx_item_id` (`item_id`);

--
-- Indexes for table `quotations`
--
ALTER TABLE `quotations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `restaurant_activity_logs`
--
ALTER TABLE `restaurant_activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ral_client_branch` (`client_id`,`branch_id`),
  ADD KEY `idx_ral_session` (`session_id`),
  ADD KEY `idx_ral_table` (`table_id`),
  ADD KEY `idx_ral_action` (`action_type`),
  ADD KEY `idx_restaurant_activity_logs_uuid` (`uuid`),
  ADD KEY `idx_restaurant_activity_logs_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_restaurant_activity_logs_device` (`device_id`);

--
-- Indexes for table `restaurant_kitchen_status_logs`
--
ALTER TABLE `restaurant_kitchen_status_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ksl_client_branch` (`client_id`,`branch_id`),
  ADD KEY `idx_ksl_kot` (`kot_id`),
  ADD KEY `idx_ksl_kot_item` (`kot_item_id`),
  ADD KEY `idx_ksl_session` (`session_id`),
  ADD KEY `idx_restaurant_kitchen_status_logs_uuid` (`uuid`),
  ADD KEY `idx_restaurant_kitchen_status_logs_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_restaurant_kitchen_status_logs_device` (`device_id`);

--
-- Indexes for table `restaurant_kots`
--
ALTER TABLE `restaurant_kots`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_kots_client_branch` (`client_id`,`branch_id`),
  ADD KEY `idx_kots_session` (`session_id`),
  ADD KEY `idx_kots_table` (`table_id`),
  ADD KEY `idx_kots_waiter` (`waiter_id`),
  ADD KEY `idx_kots_status` (`status`),
  ADD KEY `idx_kots_no` (`kot_no`),
  ADD KEY `idx_kots_rider` (`rider_id`),
  ADD KEY `idx_kots_order_type` (`order_type`),
  ADD KEY `idx_kots_token_no` (`token_no`),
  ADD KEY `idx_restaurant_kots_uuid` (`uuid`),
  ADD KEY `idx_restaurant_kots_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_restaurant_kots_device` (`device_id`),
  ADD KEY `idx_kots_uuid_relations` (`session_uuid`,`table_uuid`,`waiter_uuid`);

--
-- Indexes for table `restaurant_kot_items`
--
ALTER TABLE `restaurant_kot_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ki_kot` (`kot_id`),
  ADD KEY `idx_ki_order_item` (`order_item_id`),
  ADD KEY `idx_ki_item` (`item_id`),
  ADD KEY `idx_ki_status` (`status`),
  ADD KEY `idx_restaurant_kot_items_uuid` (`uuid`),
  ADD KEY `idx_restaurant_kot_items_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_restaurant_kot_items_device` (`device_id`),
  ADD KEY `idx_ki_uuid_relations` (`kot_uuid`,`order_item_uuid`,`item_uuid`);

--
-- Indexes for table `restaurant_order_items`
--
ALTER TABLE `restaurant_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_roi_client_branch` (`client_id`,`branch_id`),
  ADD KEY `idx_roi_session` (`session_id`),
  ADD KEY `idx_roi_item` (`item_id`),
  ADD KEY `idx_roi_kot_status` (`kot_status`),
  ADD KEY `idx_roi_item_status` (`item_status`),
  ADD KEY `idx_restaurant_order_items_uuid` (`uuid`),
  ADD KEY `idx_restaurant_order_items_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_restaurant_order_items_device` (`device_id`),
  ADD KEY `idx_roi_uuid_relations` (`session_uuid`,`item_uuid`);

--
-- Indexes for table `restaurant_reservations`
--
ALTER TABLE `restaurant_reservations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rr_client_branch` (`client_id`,`branch_id`),
  ADD KEY `idx_rr_table` (`table_id`),
  ADD KEY `idx_rr_customer` (`customer_id`),
  ADD KEY `idx_rr_date_time` (`reservation_date`,`reservation_time`),
  ADD KEY `idx_rr_status` (`status`),
  ADD KEY `idx_rr_client_branch_date` (`client_id`,`branch_id`,`reservation_date`),
  ADD KEY `idx_rr_table_date_time` (`table_id`,`reservation_date`,`reservation_time`),
  ADD KEY `idx_restaurant_reservations_uuid` (`uuid`),
  ADD KEY `idx_restaurant_reservations_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_restaurant_reservations_device` (`device_id`);

--
-- Indexes for table `restaurant_session_tables`
--
ALTER TABLE `restaurant_session_tables`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_session_table` (`session_id`,`table_id`),
  ADD KEY `idx_rst_client_branch` (`client_id`,`branch_id`),
  ADD KEY `idx_rst_table` (`table_id`),
  ADD KEY `idx_restaurant_session_tables_uuid` (`uuid`),
  ADD KEY `idx_restaurant_session_tables_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_restaurant_session_tables_device` (`device_id`),
  ADD KEY `idx_rst_uuid_relations` (`session_uuid`,`table_uuid`);

--
-- Indexes for table `restaurant_settings`
--
ALTER TABLE `restaurant_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_restaurant_setting` (`client_id`,`branch_id`,`setting_key`),
  ADD KEY `idx_rs_client_branch` (`client_id`,`branch_id`),
  ADD KEY `idx_restaurant_settings_uuid` (`uuid`),
  ADD KEY `idx_restaurant_settings_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_restaurant_settings_device` (`device_id`);

--
-- Indexes for table `restaurant_split_bills`
--
ALTER TABLE `restaurant_split_bills`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rsb_client_branch` (`client_id`,`branch_id`),
  ADD KEY `idx_rsb_session` (`session_id`),
  ADD KEY `idx_rsb_customer` (`customer_id`),
  ADD KEY `idx_rsb_status` (`status`),
  ADD KEY `idx_rsb_sale` (`sale_id`),
  ADD KEY `idx_restaurant_split_bills_uuid` (`uuid`),
  ADD KEY `idx_restaurant_split_bills_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_restaurant_split_bills_device` (`device_id`),
  ADD KEY `idx_rsb_uuid_relations` (`session_uuid`,`sale_uuid`);

--
-- Indexes for table `restaurant_split_bill_items`
--
ALTER TABLE `restaurant_split_bill_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rsbi_split_bill` (`split_bill_id`),
  ADD KEY `idx_rsbi_order_item` (`order_item_id`),
  ADD KEY `idx_rsbi_item` (`item_id`),
  ADD KEY `idx_restaurant_split_bill_items_uuid` (`uuid`),
  ADD KEY `idx_restaurant_split_bill_items_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_restaurant_split_bill_items_device` (`device_id`),
  ADD KEY `idx_rsbi_uuid_relations` (`split_bill_uuid`,`order_item_uuid`,`item_uuid`);

--
-- Indexes for table `restaurant_tables`
--
ALTER TABLE `restaurant_tables`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_restaurant_tables_client_branch` (`client_id`,`branch_id`),
  ADD KEY `idx_restaurant_tables_status` (`status`),
  ADD KEY `idx_restaurant_tables_current_session` (`current_session_id`),
  ADD KEY `idx_rt_client_branch` (`client_id`,`branch_id`),
  ADD KEY `idx_rt_status` (`status`),
  ADD KEY `idx_rt_active` (`is_active`),
  ADD KEY `idx_rt_waiter` (`waiter_id`),
  ADD KEY `idx_restaurant_tables_uuid` (`uuid`),
  ADD KEY `idx_restaurant_tables_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_restaurant_tables_device` (`device_id`);

--
-- Indexes for table `restaurant_table_sessions`
--
ALTER TABLE `restaurant_table_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rts_client_branch` (`client_id`,`branch_id`),
  ADD KEY `idx_rts_table_status` (`table_id`,`status`),
  ADD KEY `idx_rts_waiter` (`waiter_id`),
  ADD KEY `idx_rts_customer` (`customer_id`),
  ADD KEY `idx_rts_sale` (`sale_id`),
  ADD KEY `idx_rts_order_type` (`order_type`),
  ADD KEY `idx_rts_rider` (`rider_id`),
  ADD KEY `idx_rts_token_no` (`token_no`),
  ADD KEY `idx_rts_delivery_status` (`delivery_status`),
  ADD KEY `idx_restaurant_table_sessions_uuid` (`uuid`),
  ADD KEY `idx_restaurant_table_sessions_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_restaurant_table_sessions_device` (`device_id`),
  ADD KEY `idx_rts_uuid_relations` (`table_uuid`,`waiter_uuid`,`sale_uuid`);

--
-- Indexes for table `restaurant_table_transfers`
--
ALTER TABLE `restaurant_table_transfers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rtt_client_branch` (`client_id`,`branch_id`),
  ADD KEY `idx_rtt_session` (`session_id`),
  ADD KEY `idx_rtt_from_table` (`from_table_id`),
  ADD KEY `idx_rtt_to_table` (`to_table_id`),
  ADD KEY `idx_rtt_type` (`transfer_type`),
  ADD KEY `idx_restaurant_table_transfers_uuid` (`uuid`),
  ADD KEY `idx_restaurant_table_transfers_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_restaurant_table_transfers_device` (`device_id`),
  ADD KEY `idx_rtt_uuid_relations` (`session_uuid`,`from_table_uuid`,`to_table_uuid`);

--
-- Indexes for table `restaurant_waiters`
--
ALTER TABLE `restaurant_waiters`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_waiters_client_branch` (`client_id`,`branch_id`),
  ADD KEY `idx_waiters_user` (`user_id`),
  ADD KEY `idx_waiters_status` (`status`),
  ADD KEY `idx_restaurant_waiters_uuid` (`uuid`),
  ADD KEY `idx_restaurant_waiters_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_restaurant_waiters_device` (`device_id`);

--
-- Indexes for table `returns`
--
ALTER TABLE `returns`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_returns_uuid` (`uuid`),
  ADD KEY `idx_returns_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_returns_device` (`device_id`);

--
-- Indexes for table `return_items`
--
ALTER TABLE `return_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_return_items_uuid` (`uuid`),
  ADD KEY `idx_return_items_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_return_items_device` (`device_id`);

--
-- Indexes for table `re_agents`
--
ALTER TABLE `re_agents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`);

--
-- Indexes for table `re_agent_assignments`
--
ALTER TABLE `re_agent_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_re_assign_client` (`client_id`),
  ADD KEY `idx_re_assign_lead` (`lead_id`),
  ADD KEY `idx_re_assign_old_agent` (`old_agent_id`),
  ADD KEY `idx_re_assign_new_agent` (`new_agent_id`),
  ADD KEY `idx_re_assign_date` (`assigned_at`);

--
-- Indexes for table `re_agent_commissions`
--
ALTER TABLE `re_agent_commissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_re_agent_commission_deal` (`client_id`,`deal_id`),
  ADD KEY `idx_re_agent_comm_client` (`client_id`),
  ADD KEY `idx_re_agent_comm_agent` (`agent_id`),
  ADD KEY `idx_re_agent_comm_property` (`property_id`),
  ADD KEY `idx_re_agent_comm_status` (`commission_status`),
  ADD KEY `idx_re_agent_comm_payment` (`payment_status`);

--
-- Indexes for table `re_agent_profiles`
--
ALTER TABLE `re_agent_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_re_agent_profile_user` (`client_id`,`user_id`),
  ADD KEY `idx_re_agent_profile_client` (`client_id`),
  ADD KEY `idx_re_agent_profile_user` (`user_id`),
  ADD KEY `idx_re_agent_profile_branch` (`branch_id`);

--
-- Indexes for table `re_bookings`
--
ALTER TABLE `re_bookings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `unit_id` (`unit_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `agent_id` (`agent_id`);

--
-- Indexes for table `re_commissions`
--
ALTER TABLE `re_commissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `booking_id` (`booking_id`),
  ADD KEY `agent_id` (`agent_id`);

--
-- Indexes for table `re_commission_payments`
--
ALTER TABLE `re_commission_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_re_comm_pay_client` (`client_id`),
  ADD KEY `idx_re_comm_pay_commission` (`commission_id`),
  ADD KEY `idx_re_comm_pay_date` (`payment_date`);

--
-- Indexes for table `re_deals`
--
ALTER TABLE `re_deals`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_re_deals_client_no` (`client_id`,`deal_no`),
  ADD KEY `idx_re_deals_client` (`client_id`),
  ADD KEY `idx_re_deals_branch` (`branch_id`),
  ADD KEY `idx_re_deals_lead` (`lead_id`),
  ADD KEY `idx_re_deals_property` (`property_id`),
  ADD KEY `idx_re_deals_agent` (`agent_id`),
  ADD KEY `idx_re_deals_status` (`status`),
  ADD KEY `idx_re_deals_date` (`deal_date`);

--
-- Indexes for table `re_followups`
--
ALTER TABLE `re_followups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `lead_id` (`lead_id`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `re_installments`
--
ALTER TABLE `re_installments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `booking_id` (`booking_id`);

--
-- Indexes for table `re_leads`
--
ALTER TABLE `re_leads`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `agent_id` (`agent_id`),
  ADD KEY `status` (`status`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `re_payments`
--
ALTER TABLE `re_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `booking_id` (`booking_id`),
  ADD KEY `installment_id` (`installment_id`);

--
-- Indexes for table `re_projects`
--
ALTER TABLE `re_projects`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`);

--
-- Indexes for table `re_properties`
--
ALTER TABLE `re_properties`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_re_property_client_code` (`client_id`,`property_code`),
  ADD KEY `idx_re_properties_client` (`client_id`),
  ADD KEY `idx_re_properties_branch` (`branch_id`),
  ADD KEY `idx_re_properties_project` (`project_id`),
  ADD KEY `idx_re_properties_agent` (`assigned_agent_id`),
  ADD KEY `idx_re_properties_status` (`status`),
  ADD KEY `idx_re_properties_type` (`property_type`),
  ADD KEY `idx_re_properties_city` (`city`);

--
-- Indexes for table `re_property_documents`
--
ALTER TABLE `re_property_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_re_docs_client` (`client_id`),
  ADD KEY `idx_re_docs_property` (`property_id`),
  ADD KEY `idx_re_docs_type` (`document_type`);

--
-- Indexes for table `re_site_visits`
--
ALTER TABLE `re_site_visits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `lead_id` (`lead_id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `visit_status` (`visit_status`);

--
-- Indexes for table `re_units`
--
ALTER TABLE `re_units`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `project_id` (`project_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_client_type` (`client_id`),
  ADD KEY `idx_sales_restaurant_session` (`restaurant_session_id`),
  ADD KEY `idx_sales_restaurant_table` (`restaurant_table_id`),
  ADD KEY `idx_sales_waiter` (`waiter_id`),
  ADD KEY `idx_sales_restaurant_order_type` (`restaurant_order_type`),
  ADD KEY `idx_sales_uuid` (`uuid`),
  ADD KEY `idx_sales_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_sales_device` (`device_id`);

--
-- Indexes for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sale_items_restaurant_order_item` (`restaurant_order_item_id`),
  ADD KEY `idx_sale_items_uuid` (`uuid`),
  ADD KEY `idx_sale_items_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_sale_items_device` (`device_id`),
  ADD KEY `idx_sale_items_sale_uuid` (`sale_uuid`),
  ADD KEY `idx_sale_items_item_uuid` (`item_uuid`);

--
-- Indexes for table `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `staff_attendance`
--
ALTER TABLE `staff_attendance`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `staff_commissions`
--
ALTER TABLE `staff_commissions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `staff_commission_rates`
--
ALTER TABLE `staff_commission_rates`
  ADD PRIMARY KEY (`user_id`,`service_id`);

--
-- Indexes for table `stock_movements`
--
ALTER TABLE `stock_movements`
  ADD KEY `idx_stock_movements_uuid` (`uuid`),
  ADD KEY `idx_stock_movements_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_stock_movements_device` (`device_id`),
  ADD KEY `idx_stock_movements_item_uuid` (`item_uuid`);

--
-- Indexes for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`);

--
-- Indexes for table `subscription_features`
--
ALTER TABLE `subscription_features`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_subscription_feature` (`subscription_id`,`feature_key`),
  ADD KEY `idx_client_feature` (`client_id`,`feature_key`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `supplier_ledger`
--
ALTER TABLE `supplier_ledger`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_supplier` (`supplier_id`),
  ADD KEY `idx_supplier_ledger_client` (`client_id`),
  ADD KEY `idx_supplier_ledger_supplier` (`supplier_id`),
  ADD KEY `idx_supplier_ledger_date` (`transaction_date`),
  ADD KEY `idx_supplier_ledger_reference` (`reference_type`,`reference_id`);

--
-- Indexes for table `supplier_payments`
--
ALTER TABLE `supplier_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_supplier_payments_client` (`client_id`),
  ADD KEY `idx_supplier_payments_supplier` (`supplier_id`),
  ADD KEY `idx_supplier_payments_date` (`payment_date`);

--
-- Indexes for table `sync_conflicts`
--
ALTER TABLE `sync_conflicts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sync_conflicts_record` (`table_name`,`record_uuid`),
  ADD KEY `idx_sync_conflicts_status` (`resolution_status`);

--
-- Indexes for table `sync_mappings`
--
ALTER TABLE `sync_mappings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_sync_map_local_uuid` (`device_uuid`,`table_name`,`local_uuid`),
  ADD KEY `idx_sync_map_cloud` (`table_name`,`cloud_id`),
  ADD KEY `idx_sync_map_client_branch` (`client_id`,`branch_id`);

--
-- Indexes for table `sync_queue`
--
ALTER TABLE `sync_queue`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sq_pending` (`sync_status`,`created_at`),
  ADD KEY `idx_sq_device` (`device_id`,`sync_status`),
  ADD KEY `idx_sq_record` (`table_name`,`record_uuid`),
  ADD KEY `idx_sq_client_branch` (`client_id`,`branch_id`),
  ADD KEY `idx_sq_retry` (`next_retry_at`,`retry_count`);

--
-- Indexes for table `table_reservations`
--
ALTER TABLE `table_reservations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_table_reservations_uuid` (`uuid`),
  ADD KEY `idx_table_reservations_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_table_reservations_device` (`device_id`);

--
-- Indexes for table `taxes`
--
ALTER TABLE `taxes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `idx_taxes_uuid` (`uuid`),
  ADD KEY `idx_taxes_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_taxes_device` (`device_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `idx_users_uuid` (`uuid`),
  ADD KEY `idx_users_sync` (`sync_status`,`last_synced_at`),
  ADD KEY `idx_users_device` (`device_id`);

--
-- Indexes for table `users_permissions`
--
ALTER TABLE `users_permissions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `water_dispatches`
--
ALTER TABLE `water_dispatches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_dispatch_no_client` (`client_id`,`dispatch_no`),
  ADD KEY `idx_client_date` (`client_id`,`dispatch_date`),
  ADD KEY `idx_driver_date` (`driver_id`,`dispatch_date`),
  ADD KEY `idx_route_date` (`route_id`,`dispatch_date`);

--
-- Indexes for table `water_dispatch_items`
--
ALTER TABLE `water_dispatch_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_dispatch_sale` (`client_id`,`dispatch_id`,`sale_id`),
  ADD KEY `idx_sale` (`sale_id`),
  ADD KEY `idx_dispatch` (`dispatch_id`);

--
-- Indexes for table `water_routes`
--
ALTER TABLE `water_routes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_client_route_code` (`client_id`,`route_code`),
  ADD KEY `idx_client_status` (`client_id`,`status`),
  ADD KEY `idx_driver` (`driver_id`);

--
-- Indexes for table `water_route_customers`
--
ALTER TABLE `water_route_customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_route_customer` (`route_id`,`customer_id`),
  ADD KEY `idx_client_customer` (`client_id`,`customer_id`);

--
-- Indexes for table `water_route_deliveries`
--
ALTER TABLE `water_route_deliveries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_route_date` (`client_id`,`route_id`,`delivery_date`),
  ADD KEY `idx_date_status` (`delivery_date`,`status`);

--
-- Indexes for table `water_route_delivery_items`
--
ALTER TABLE `water_route_delivery_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_delivery` (`delivery_id`),
  ADD KEY `idx_route_customer` (`route_id`,`customer_id`),
  ADD KEY `idx_sale` (`sale_id`);

--
-- Indexes for table `wholesale_customers`
--
ALTER TABLE `wholesale_customers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_client_id` (`client_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `branches`
--
ALTER TABLE `branches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `branch_users`
--
ALTER TABLE `branch_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `clients`
--
ALTER TABLE `clients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `client_settings`
--
ALTER TABLE `client_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `coupons`
--
ALTER TABLE `coupons`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=875;

--
-- AUTO_INCREMENT for table `customer_memberships`
--
ALTER TABLE `customer_memberships`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `delivery_drivers`
--
ALTER TABLE `delivery_drivers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `delivery_order_assignments`
--
ALTER TABLE `delivery_order_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `desktop_sync_state`
--
ALTER TABLE `desktop_sync_state`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `devices`
--
ALTER TABLE `devices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `distribution_collection_logs`
--
ALTER TABLE `distribution_collection_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `distribution_collection_settlements`
--
ALTER TABLE `distribution_collection_settlements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `distribution_collection_settlement_items`
--
ALTER TABLE `distribution_collection_settlement_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `distribution_delivery_updates`
--
ALTER TABLE `distribution_delivery_updates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `distribution_dispatches`
--
ALTER TABLE `distribution_dispatches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `distribution_dispatch_items`
--
ALTER TABLE `distribution_dispatch_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `distribution_routes`
--
ALTER TABLE `distribution_routes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `distribution_route_customers`
--
ALTER TABLE `distribution_route_customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `distribution_route_deliveries`
--
ALTER TABLE `distribution_route_deliveries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `distribution_route_delivery_items`
--
ALTER TABLE `distribution_route_delivery_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `empty_bottle_returns`
--
ALTER TABLE `empty_bottle_returns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `global_settings`
--
ALTER TABLE `global_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `industries`
--
ALTER TABLE `industries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `integrations`
--
ALTER TABLE `integrations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `integration_logs`
--
ALTER TABLE `integration_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `items`
--
ALTER TABLE `items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1054;

--
-- AUTO_INCREMENT for table `item_barcodes`
--
ALTER TABLE `item_barcodes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `item_batches`
--
ALTER TABLE `item_batches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `item_price_tiers`
--
ALTER TABLE `item_price_tiers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `item_recipes`
--
ALTER TABLE `item_recipes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `item_variants`
--
ALTER TABLE `item_variants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `memberships`
--
ALTER TABLE `memberships`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `membership_benefits`
--
ALTER TABLE `membership_benefits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `membership_card_templates`
--
ALTER TABLE `membership_card_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `plans`
--
ALTER TABLE `plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `plan_features`
--
ALTER TABLE `plan_features`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2237;

--
-- AUTO_INCREMENT for table `pos_activity`
--
ALTER TABLE `pos_activity`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `production_batches`
--
ALTER TABLE `production_batches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `production_formulas`
--
ALTER TABLE `production_formulas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `production_formula_inputs`
--
ALTER TABLE `production_formula_inputs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `production_formula_outputs`
--
ALTER TABLE `production_formula_outputs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `production_wastage_logs`
--
ALTER TABLE `production_wastage_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `production_work_orders`
--
ALTER TABLE `production_work_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `production_work_order_inputs`
--
ALTER TABLE `production_work_order_inputs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `production_work_order_outputs`
--
ALTER TABLE `production_work_order_outputs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `quotations`
--
ALTER TABLE `quotations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `restaurant_activity_logs`
--
ALTER TABLE `restaurant_activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=161;

--
-- AUTO_INCREMENT for table `restaurant_kitchen_status_logs`
--
ALTER TABLE `restaurant_kitchen_status_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=117;

--
-- AUTO_INCREMENT for table `restaurant_kots`
--
ALTER TABLE `restaurant_kots`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `restaurant_kot_items`
--
ALTER TABLE `restaurant_kot_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT for table `restaurant_order_items`
--
ALTER TABLE `restaurant_order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=56;

--
-- AUTO_INCREMENT for table `restaurant_reservations`
--
ALTER TABLE `restaurant_reservations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `restaurant_session_tables`
--
ALTER TABLE `restaurant_session_tables`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `restaurant_settings`
--
ALTER TABLE `restaurant_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=63;

--
-- AUTO_INCREMENT for table `restaurant_split_bills`
--
ALTER TABLE `restaurant_split_bills`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `restaurant_split_bill_items`
--
ALTER TABLE `restaurant_split_bill_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `restaurant_tables`
--
ALTER TABLE `restaurant_tables`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `restaurant_table_sessions`
--
ALTER TABLE `restaurant_table_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `restaurant_table_transfers`
--
ALTER TABLE `restaurant_table_transfers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `restaurant_waiters`
--
ALTER TABLE `restaurant_waiters`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `returns`
--
ALTER TABLE `returns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `return_items`
--
ALTER TABLE `return_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT for table `re_agents`
--
ALTER TABLE `re_agents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `re_agent_assignments`
--
ALTER TABLE `re_agent_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `re_agent_commissions`
--
ALTER TABLE `re_agent_commissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `re_agent_profiles`
--
ALTER TABLE `re_agent_profiles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `re_bookings`
--
ALTER TABLE `re_bookings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `re_commissions`
--
ALTER TABLE `re_commissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `re_commission_payments`
--
ALTER TABLE `re_commission_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `re_deals`
--
ALTER TABLE `re_deals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `re_followups`
--
ALTER TABLE `re_followups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `re_installments`
--
ALTER TABLE `re_installments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `re_leads`
--
ALTER TABLE `re_leads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `re_payments`
--
ALTER TABLE `re_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `re_projects`
--
ALTER TABLE `re_projects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `re_properties`
--
ALTER TABLE `re_properties`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `re_property_documents`
--
ALTER TABLE `re_property_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `re_site_visits`
--
ALTER TABLE `re_site_visits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `re_units`
--
ALTER TABLE `re_units`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=81;

--
-- AUTO_INCREMENT for table `sale_items`
--
ALTER TABLE `sale_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=189;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `staff_attendance`
--
ALTER TABLE `staff_attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `staff_commissions`
--
ALTER TABLE `staff_commissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `subscriptions`
--
ALTER TABLE `subscriptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `subscription_features`
--
ALTER TABLE `subscription_features`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=246;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `supplier_ledger`
--
ALTER TABLE `supplier_ledger`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supplier_payments`
--
ALTER TABLE `supplier_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `sync_conflicts`
--
ALTER TABLE `sync_conflicts`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sync_mappings`
--
ALTER TABLE `sync_mappings`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sync_queue`
--
ALTER TABLE `sync_queue`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `table_reservations`
--
ALTER TABLE `table_reservations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `taxes`
--
ALTER TABLE `taxes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=157;

--
-- AUTO_INCREMENT for table `users_permissions`
--
ALTER TABLE `users_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=291;

--
-- AUTO_INCREMENT for table `water_dispatches`
--
ALTER TABLE `water_dispatches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `water_dispatch_items`
--
ALTER TABLE `water_dispatch_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `water_routes`
--
ALTER TABLE `water_routes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `water_route_customers`
--
ALTER TABLE `water_route_customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `water_route_deliveries`
--
ALTER TABLE `water_route_deliveries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `water_route_delivery_items`
--
ALTER TABLE `water_route_delivery_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wholesale_customers`
--
ALTER TABLE `wholesale_customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `branch_sessions`
--
ALTER TABLE `branch_sessions`
  ADD CONSTRAINT `branch_sessions_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `branch_users`
--
ALTER TABLE `branch_users`
  ADD CONSTRAINT `branch_users_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `branch_users_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `clients`
--
ALTER TABLE `clients`
  ADD CONSTRAINT `fk_industry` FOREIGN KEY (`industry_id`) REFERENCES `industries` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `client_settings`
--
ALTER TABLE `client_settings`
  ADD CONSTRAINT `client_settings_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `expenses`
--
ALTER TABLE `expenses`
  ADD CONSTRAINT `expenses_branch_fk` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `expenses_client_fk` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `pos_activity`
--
ALTER TABLE `pos_activity`
  ADD CONSTRAINT `pos_activity_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `pos_activity_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `production_formula_inputs`
--
ALTER TABLE `production_formula_inputs`
  ADD CONSTRAINT `fk_pfi_formula` FOREIGN KEY (`formula_id`) REFERENCES `production_formulas` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `production_formula_outputs`
--
ALTER TABLE `production_formula_outputs`
  ADD CONSTRAINT `fk_pfo_formula` FOREIGN KEY (`formula_id`) REFERENCES `production_formulas` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `production_wastage_logs`
--
ALTER TABLE `production_wastage_logs`
  ADD CONSTRAINT `fk_pwl_order` FOREIGN KEY (`work_order_id`) REFERENCES `production_work_orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `production_work_orders`
--
ALTER TABLE `production_work_orders`
  ADD CONSTRAINT `fk_pwo_formula` FOREIGN KEY (`formula_id`) REFERENCES `production_formulas` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `production_work_order_inputs`
--
ALTER TABLE `production_work_order_inputs`
  ADD CONSTRAINT `fk_pwoi_order` FOREIGN KEY (`work_order_id`) REFERENCES `production_work_orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `production_work_order_outputs`
--
ALTER TABLE `production_work_order_outputs`
  ADD CONSTRAINT `fk_pwoo_order` FOREIGN KEY (`work_order_id`) REFERENCES `production_work_orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD CONSTRAINT `fk_po_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `purchase_orders_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Constraints for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  ADD CONSTRAINT `fk_poi_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`),
  ADD CONSTRAINT `fk_poi_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `purchase_order_items_ibfk_1` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`),
  ADD CONSTRAINT `purchase_order_items_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`);

--
-- Constraints for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD CONSTRAINT `subscriptions_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`);

--
-- Constraints for table `subscription_features`
--
ALTER TABLE `subscription_features`
  ADD CONSTRAINT `fk_subscription_features_subscription` FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `supplier_ledger`
--
ALTER TABLE `supplier_ledger`
  ADD CONSTRAINT `fk_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
