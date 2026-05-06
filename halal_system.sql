-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 28, 2026 at 01:39 PM
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
-- Database: `halal_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

CREATE TABLE `activity_log` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `module` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activity_log`
--

INSERT INTO `activity_log` (`id`, `user_id`, `action`, `details`, `ip_address`, `module`, `created_at`) VALUES
(1, 1, 'Registration', 'User registered via email/password', '::1', 'auth', '2026-04-28 06:42:26'),
(2, 1, 'Login', 'User logged in via email/password', '::1', 'auth', '2026-04-28 06:42:44'),
(3, 1, 'Role Applied', 'User applied as Business Owner', '::1', 'auth', '2026-04-28 06:43:40'),
(4, 1, 'Login', 'User logged in via email/password', '::1', 'auth', '2026-04-28 06:47:53'),
(5, 1, 'Login', 'User logged in via email/password', '::1', 'auth', '2026-04-28 06:51:31'),
(6, 1, 'Role Applied', 'User applied as Business Owner (auto-approved)', '::1', 'auth', '2026-04-28 06:53:25'),
(7, 1, 'LOI Created', 'Created LOI for Halal Keeps', '::1', 'loi', '2026-04-28 07:15:54'),
(8, 2, 'Registration', 'User registered via email/password', '::1', 'auth', '2026-04-28 07:17:11'),
(9, 2, 'Login', 'User logged in via email/password', '::1', 'auth', '2026-04-28 07:17:15'),
(10, 2, 'Role Applied', 'User applied as Evaluator (auto-approved)', '::1', 'auth', '2026-04-28 07:17:53'),
(11, 2, 'Login', 'User logged in via email/password', '::1', 'auth', '2026-04-28 09:12:02'),
(12, 2, 'LOI Verified', 'LOI #1 verified', '::1', 'evaluation', '2026-04-28 09:17:55'),
(13, 1, 'LOI Created', 'Created LOI for halal keeps', '::1', 'loi', '2026-04-28 09:40:09'),
(14, 2, 'LOI Verified', 'LOI #1 verified', '::1', 'evaluation', '2026-04-28 09:40:15'),
(15, 2, 'LOI Verified', 'LOI #2 returned', '::1', 'evaluation', '2026-04-28 09:41:05'),
(16, 2, 'LOI Verified', 'LOI #2 verified', '::1', 'evaluation', '2026-04-28 09:45:58'),
(17, 1, 'LOI Created', 'Created LOI for Halal Keeps', '::1', 'loi', '2026-04-28 09:50:20'),
(18, 2, 'LOI Verified', 'LOI #3 verified', '::1', 'evaluation', '2026-04-28 09:52:27'),
(19, 1, 'LOI Created', 'Created LOI for Halal Keeps', '::1', 'loi', '2026-04-28 10:03:00'),
(20, 2, 'LOI Verified', 'LOI #4 verified', '::1', 'evaluation', '2026-04-28 10:04:03'),
(21, 2, 'LOI Verified', 'LOI #4 verified', '::1', 'evaluation', '2026-04-28 10:06:04'),
(22, 1, 'LOI Created', 'Created LOI for Halal Keeps', '::1', 'loi', '2026-04-28 10:16:00'),
(23, 2, 'LOI Verified', 'LOI #5 verified', '::1', 'evaluation', '2026-04-28 10:19:55'),
(24, 1, 'LOI Created', 'Created LOI for Halal Keeps', '::1', 'loi', '2026-04-28 10:20:00'),
(25, 2, 'LOI Verified', 'LOI #6 verified', '::1', 'evaluation', '2026-04-28 10:26:14'),
(26, 2, 'LOI Verified', 'LOI #6 verified', '::1', 'evaluation', '2026-04-28 10:37:14'),
(27, 1, 'Login', 'User logged in via email/password', '::1', 'auth', '2026-04-28 10:43:24'),
(28, 2, 'Application Verified', 'App #1 verified', '::1', 'evaluation', '2026-04-28 10:47:19'),
(29, 2, 'Application Verified', 'App #1 verified', '::1', 'evaluation', '2026-04-28 10:48:42'),
(30, 1, 'LOI Created', 'Created LOI for Halal Keeps', '::1', 'loi', '2026-04-28 11:26:47'),
(31, 2, 'LOI Verified', 'LOI #7 returned', '::1', 'evaluation', '2026-04-28 11:28:05'),
(32, 1, 'LOI Created', 'Created LOI for Halal Keeps', '::1', 'loi', '2026-04-28 11:28:09'),
(33, 2, 'LOI Verified', 'LOI #8 verified', '::1', 'evaluation', '2026-04-28 11:29:12'),
(34, 2, 'LOI Verified', 'LOI #8 verified', '::1', 'evaluation', '2026-04-28 11:31:25'),
(35, 2, 'Application Verified', 'App #2 verified', '::1', 'evaluation', '2026-04-28 11:33:01'),
(36, 2, 'Application Verified', 'App #2 verified', '::1', 'evaluation', '2026-04-28 11:33:27'),
(37, 3, 'Registration', 'User registered via email/password', '::1', 'auth', '2026-04-28 11:35:12'),
(38, 3, 'Login', 'User logged in via email/password', '::1', 'auth', '2026-04-28 11:35:27');

-- --------------------------------------------------------

--
-- Table structure for table `application_requirement_uploads`
--

CREATE TABLE `application_requirement_uploads` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `requirement_label` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `application_requirement_uploads`
--

INSERT INTO `application_requirement_uploads` (`id`, `application_id`, `requirement_label`, `file_path`, `uploaded_at`) VALUES
(6, 2, '1. Letter of Intent', 'documents/69f09a258f114_1777375781.pdf', '2026-04-28 11:29:41'),
(8, 2, '2.2 Name of products/menu and corresponding ingredients', 'documents/69f09a2cbcdfc_1777375788.pdf', '2026-04-28 11:29:48'),
(9, 2, '2.3 List of raw materials/ingredients with source', 'documents/69f09a301f415_1777375792.pdf', '2026-04-28 11:29:52'),
(10, 2, '2.4 Halal Certificate of meat products', 'documents/69f09a347eeb8_1777375796.pdf', '2026-04-28 11:29:56'),
(11, 2, '2.5 Kitchen layout', 'documents/69f09a39230dd_1777375801.pdf', '2026-04-28 11:30:01'),
(12, 2, '2.6 Alcohol and liquor prohibition compliance', 'documents/69f09a3d6e380_1777375805.pdf', '2026-04-28 11:30:05'),
(13, 2, '2.7 Halal Certification for ingredients', 'documents/69f09a41d1eef_1777375809.pdf', '2026-04-28 11:30:09'),
(14, 2, '2.8 Type of packaging materials', 'documents/69f09a46c6b85_1777375814.pdf', '2026-04-28 11:30:14'),
(15, 2, '2.9 GMP, HACCP, SSOP, GHP, TQM, ISO', 'documents/69f09a4cda618_1777375820.pdf', '2026-04-28 11:30:20'),
(16, 2, '2.10 Pest Control, SEC Registration, License to Operate', 'documents/69f09a521d2b0_1777375826.pdf', '2026-04-28 11:30:26'),
(17, 2, '2.11 Flow chart of processing / storage / equipment', 'documents/69f09a570a126_1777375831.pdf', '2026-04-28 11:30:31'),
(18, 2, '2.12 Transportation used for Halal Products', 'documents/69f09a5cb2d0f_1777375836.pdf', '2026-04-28 11:30:36'),
(19, 2, '2.13 Storage of raw materials and finished products', 'documents/69f09a626cc44_1777375842.pdf', '2026-04-28 11:30:42'),
(20, 2, '2.14 Waste disposal management', 'documents/69f09a67d5909_1777375847.pdf', '2026-04-28 11:30:47'),
(21, 2, '2.15 Halal Prayer room', 'documents/69f09a6e42ed0_1777375854.pdf', '2026-04-28 11:30:54'),
(22, 2, '2.16 Appointment of Muslim cook and crew', 'documents/69f09a735ed62_1777375859.pdf', '2026-04-28 11:30:59'),
(23, 2, '2.17 Halal Assurance System Manual', 'documents/69f09a7a7775a_1777375866.jpeg', '2026-04-28 11:31:06'),
(24, 2, '2.1 Company Profile', 'documents/69f09a7de40ad_1777375869.pdf', '2026-04-28 11:31:09');

-- --------------------------------------------------------

--
-- Table structure for table `application_verification`
--

CREATE TABLE `application_verification` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `evaluator_id` int(11) NOT NULL,
  `verification_status` enum('pending','verified','returned','incomplete') DEFAULT 'pending',
  `feedback` text DEFAULT NULL,
  `verified_requirements` text DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `application_verification`
--

INSERT INTO `application_verification` (`id`, `application_id`, `evaluator_id`, `verification_status`, `feedback`, `verified_requirements`, `verified_at`, `created_at`) VALUES
(4, 2, 2, 'verified', '', NULL, '2026-04-28 11:33:01', '2026-04-28 11:33:01'),
(5, 2, 2, 'verified', '', NULL, '2026-04-28 11:33:27', '2026-04-28 11:33:27');

-- --------------------------------------------------------

--
-- Table structure for table `corrective_action_reports`
--

CREATE TABLE `corrective_action_reports` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `laboratory_report_id` int(11) DEFAULT NULL,
  `inspection_id` int(11) DEFAULT NULL,
  `report_content` text NOT NULL,
  `corrective_actions` text DEFAULT NULL,
  `status` enum('draft','submitted','reviewed','approved') DEFAULT 'draft',
  `prepared_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `final_decisions`
--

CREATE TABLE `final_decisions` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `potential_decision_id` int(11) DEFAULT NULL,
  `committee_member_id` int(11) NOT NULL,
  `decision` enum('approved','rejected','deferred','pending') DEFAULT 'pending',
  `decision_notes` text DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `halal_certificates`
--

CREATE TABLE `halal_certificates` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `final_decision_id` int(11) NOT NULL,
  `certificate_number` varchar(100) NOT NULL,
  `business_name` varchar(255) NOT NULL,
  `business_address` text DEFAULT NULL,
  `certificate_type` varchar(100) DEFAULT NULL,
  `issue_date` date NOT NULL,
  `expiry_date` date NOT NULL,
  `halal_logo_path` varchar(500) DEFAULT NULL,
  `certificate_file_path` varchar(500) DEFAULT NULL,
  `decorated_by` int(11) DEFAULT NULL,
  `awarded_by` int(11) DEFAULT NULL,
  `status` enum('prepared','decorated','awarded','active','expired','revoked','suspended') DEFAULT 'prepared',
  `payment_amount` decimal(10,2) DEFAULT 0.00,
  `payment_status` enum('unpaid','paid') DEFAULT 'unpaid',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `halal_restaurants`
--

CREATE TABLE `halal_restaurants` (
  `id` int(11) NOT NULL,
  `business_owner_id` int(11) NOT NULL,
  `certificate_id` int(11) DEFAULT NULL,
  `restaurant_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `address` text NOT NULL,
  `city` varchar(100) DEFAULT NULL,
  `province` varchar(100) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `operating_hours` text DEFAULT NULL,
  `cuisine_type` varchar(100) DEFAULT NULL,
  `image_path` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `avg_rating` decimal(3,2) DEFAULT 0.00,
  `total_reviews` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hdp_applications`
--

CREATE TABLE `hdp_applications` (
  `id` int(11) NOT NULL,
  `loi_id` int(11) NOT NULL,
  `business_owner_id` int(11) NOT NULL,
  `application_form_path` varchar(500) DEFAULT NULL,
  `supporting_papers` text DEFAULT NULL,
  `request_form_path` varchar(500) DEFAULT NULL,
  `reviews_ratings` text DEFAULT NULL,
  `laboratory_report_path` varchar(500) DEFAULT NULL,
  `requirements_complete` tinyint(1) DEFAULT 0,
  `status` enum('draft','submitted','under_review','verified','incomplete','approved') DEFAULT 'draft',
  `submitted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `hdp_applications`
--

INSERT INTO `hdp_applications` (`id`, `loi_id`, `business_owner_id`, `application_form_path`, `supporting_papers`, `request_form_path`, `reviews_ratings`, `laboratory_report_path`, `requirements_complete`, `status`, `submitted_at`, `created_at`, `updated_at`) VALUES
(2, 8, 1, NULL, NULL, NULL, NULL, NULL, 0, 'verified', '2026-04-28 11:33:07', '2026-04-28 11:29:27', '2026-04-28 11:33:27');

-- --------------------------------------------------------

--
-- Table structure for table `inspections`
--

CREATE TABLE `inspections` (
  `id` int(11) NOT NULL,
  `schedule_id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `auditor_technical_id` int(11) DEFAULT NULL,
  `auditor_shariah_id` int(11) DEFAULT NULL,
  `audit_findings` text DEFAULT NULL,
  `conformity_status` enum('conforming','non_conforming','partial','pending') DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `inspection_date` date DEFAULT NULL,
  `status` enum('pending','in_progress','completed') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inspection_schedules`
--

CREATE TABLE `inspection_schedules` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `evaluator_id` int(11) NOT NULL,
  `tor_id` int(11) DEFAULT NULL,
  `schedule_date` date NOT NULL,
  `schedule_time` time DEFAULT NULL,
  `location` text DEFAULT NULL,
  `inspectors` text DEFAULT NULL,
  `inspection_fees` decimal(10,2) DEFAULT 0.00,
  `status` enum('scheduled','confirmed','in_progress','completed','cancelled','rescheduled') DEFAULT 'scheduled',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `laboratory_analyses`
--

CREATE TABLE `laboratory_analyses` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `analyst_id` int(11) NOT NULL,
  `analysis_method` text DEFAULT NULL,
  `analysis_results` text DEFAULT NULL,
  `conclusion` text DEFAULT NULL,
  `status` enum('pending','in_progress','completed','failed') DEFAULT 'pending',
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `laboratory_reports`
--

CREATE TABLE `laboratory_reports` (
  `id` int(11) NOT NULL,
  `laboratory_request_id` int(11) DEFAULT NULL,
  `application_id` int(11) NOT NULL,
  `analyst_id` int(11) NOT NULL,
  `report_number` varchar(50) DEFAULT NULL,
  `report_content` text NOT NULL,
  `report_file_path` varchar(500) DEFAULT NULL,
  `halal_status` enum('halal','haram','mushbooh') DEFAULT NULL,
  `status` enum('draft','finalized','sent') DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `laboratory_requests`
--

CREATE TABLE `laboratory_requests` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `business_owner_id` int(11) NOT NULL,
  `request_number` varchar(50) DEFAULT NULL,
  `sample_description` text NOT NULL,
  `analysis_requirements` text NOT NULL,
  `payment_testing_fee` decimal(10,2) DEFAULT 0.00,
  `payment_status` enum('unpaid','paid','waived') DEFAULT 'unpaid',
  `status` enum('submitted','received','testing','completed','rejected') DEFAULT 'submitted',
  `received_by` int(11) DEFAULT NULL,
  `received_at` timestamp NULL DEFAULT NULL,
  `receiving_notes` text DEFAULT NULL,
  `analyst_id` int(11) DEFAULT NULL,
  `testing_started_at` timestamp NULL DEFAULT NULL,
  `test_result` enum('pass','fail','inconclusive') DEFAULT NULL,
  `analysis_details` text DEFAULT NULL,
  `halal_status` enum('halal','haram','mushbooh') DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `letter_of_intent`
--

CREATE TABLE `letter_of_intent` (
  `id` int(11) NOT NULL,
  `business_owner_id` int(11) NOT NULL,
  `company_name` varchar(255) NOT NULL,
  `company_address` text NOT NULL,
  `company_info` text NOT NULL,
  `date_of_intent` date DEFAULT NULL,
  `menu_list` text DEFAULT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `contact_email` varchar(255) DEFAULT NULL,
  `contact_phone` varchar(50) DEFAULT NULL,
  `letter_content` text NOT NULL,
  `supporting_documents` text DEFAULT NULL,
  `status` enum('draft','submitted','under_review','verified','returned','rejected') DEFAULT 'draft',
  `remarks` text DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `letter_of_intent`
--

INSERT INTO `letter_of_intent` (`id`, `business_owner_id`, `company_name`, `company_address`, `company_info`, `date_of_intent`, `menu_list`, `contact_person`, `contact_email`, `contact_phone`, `letter_content`, `supporting_documents`, `status`, `remarks`, `submitted_at`, `created_at`, `updated_at`) VALUES
(7, 1, 'Halal Keeps', 'Purok 12, Sirawan, Toril, Davao City', '', '2026-04-28', 'Chicken Adobo\nChicken Curry\nSinigang\nFried Rice', 'Jasmine Duran', 'jasmine123@gmail.com', '09054541332', '', NULL, 'returned', '', '2026-04-28 05:26:47', '2026-04-28 11:26:47', '2026-04-28 11:28:05'),
(8, 1, 'Halal Keeps', 'Purok 12, Sirawan, Toril, Davao City', '', '2026-04-28', 'Chicken Adobo\nChicken Curry\nSinigang\nFried Rice', 'Jasmine Duran', 'jasmine123@gmail.com', '09054541332', '', NULL, 'verified', '', '2026-04-28 05:28:09', '2026-04-28 11:28:09', '2026-04-28 11:29:12');

-- --------------------------------------------------------

--
-- Table structure for table `loi_requirements`
--

CREATE TABLE `loi_requirements` (
  `id` int(11) NOT NULL,
  `loi_id` int(11) NOT NULL,
  `evaluator_id` int(11) NOT NULL,
  `requirements` text NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `loi_requirements`
--

INSERT INTO `loi_requirements` (`id`, `loi_id`, `evaluator_id`, `requirements`, `sent_at`) VALUES
(7, 8, 2, '1. Letter of Intent\n2.1 Company Profile\n2.2 Name of products/menu and corresponding ingredients used in the manufacture of the products/menu\n2.3 List of raw material/ingredients with corresponding source (name of distributor; local/imported)\n2.4 Halal Certificate of meat products\n2.5 Kitchen layout\n2.6 Alcohol and liquor base are prohibited in Halal Kitchen\n2.7 Halal Certification for ingredients\n2.8 Type of packaging materials\n2.9 GMP, HACCP, SSOP, GHP, TQM, ISO\n2.10 Pest Control, SEC Registration, License to Operate and from other government agencies\n2.11 Flow chart of processing of said products including description of storage system and equipments/machineries used in the processing\n2.12 Transportation used for Halal Products\n2.13 Storage of raw materials and finished product before or after cooking must be maintaining at satisfactory level in terms of cleanliness and safety\n2.14 Waste disposal management\n2.15 Halal Prayer room\n2.16 Appointment of at least two (2) Muslim cook and two (2) Muslim crew\n2.17 Halal Assurance System Manual', '2026-04-28 11:29:12'),
(8, 8, 2, '1. Letter of Intent\n2.1 Company Profile\n2.2 Name of products/menu and corresponding ingredients used in the manufacture of the products/menu\n2.3 List of raw material/ingredients with corresponding source (name of distributor; local/imported)\n2.4 Halal Certificate of meat products\n2.5 Kitchen layout\n2.6 Alcohol and liquor base are prohibited in Halal Kitchen\n2.7 Halal Certification for ingredients\n2.8 Type of packaging materials\n2.9 GMP, HACCP, SSOP, GHP, TQM, ISO\n2.10 Pest Control, SEC Registration, License to Operate and from other government agencies\n2.11 Flow chart of processing of said products including description of storage system and equipments/machineries used in the processing\n2.12 Transportation used for Halal Products\n2.13 Storage of raw materials and finished product before or after cooking must be maintaining at satisfactory level in terms of cleanliness and safety\n2.14 Waste disposal management\n2.15 Halal Prayer room\n2.16 Appointment of at least two (2) Muslim cook and two (2) Muslim crew\n2.17 Halal Assurance System Manual', '2026-04-28 11:31:25');

-- --------------------------------------------------------

--
-- Table structure for table `loi_verification`
--

CREATE TABLE `loi_verification` (
  `id` int(11) NOT NULL,
  `loi_id` int(11) NOT NULL,
  `evaluator_id` int(11) NOT NULL,
  `verification_status` enum('pending','verified','returned') DEFAULT 'pending',
  `feedback` text DEFAULT NULL,
  `verified_letter_path` varchar(500) DEFAULT NULL,
  `notify_inspection` tinyint(1) DEFAULT 0,
  `verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `loi_verification`
--

INSERT INTO `loi_verification` (`id`, `loi_id`, `evaluator_id`, `verification_status`, `feedback`, `verified_letter_path`, `notify_inspection`, `verified_at`, `created_at`) VALUES
(11, 7, 2, 'returned', '', NULL, 0, '2026-04-28 11:28:05', '2026-04-28 11:28:05'),
(12, 8, 2, 'verified', '', NULL, 0, '2026-04-28 11:29:12', '2026-04-28 11:29:12'),
(13, 8, 2, 'verified', '', NULL, 0, '2026-04-28 11:31:25', '2026-04-28 11:31:25');

-- --------------------------------------------------------

--
-- Table structure for table `menu_items`
--

CREATE TABLE `menu_items` (
  `id` int(11) NOT NULL,
  `restaurant_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `image_path` varchar(500) DEFAULT NULL,
  `is_available` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ncr_reports`
--

CREATE TABLE `ncr_reports` (
  `id` int(11) NOT NULL,
  `inspection_id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `ncr_number` varchar(50) DEFAULT NULL,
  `finding_details` text NOT NULL,
  `category` enum('major','minor','observation') DEFAULT 'minor',
  `corrective_action_required` text DEFAULT NULL,
  `corrective_action_taken` text DEFAULT NULL,
  `deadline` date DEFAULT NULL,
  `status` enum('open','in_progress','resolved','closed') DEFAULT 'open',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` enum('info','success','warning','error','action_required') DEFAULT 'info',
  `link` varchar(500) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `title`, `message`, `type`, `link`, `is_read`, `created_at`) VALUES
(26, 2, 'New Letter of Intent', 'Jasmine Duran has submitted a Letter of Intent for Halal Keeps', 'action_required', '/halal_final/dashboard/evaluator/verify_loi.php', 0, '2026-04-28 11:26:47'),
(27, 1, 'LOI Returned', 'Your Letter of Intent has been returned. Please check the feedback and resubmit.', 'warning', '/halal_final/dashboard/business_owner/letter_of_intent.php?action=view&id=7', 1, '2026-04-28 11:28:05'),
(28, 2, 'New Letter of Intent', 'Jasmine Duran has submitted a Letter of Intent for Halal Keeps', 'action_required', '/halal_final/dashboard/evaluator/verify_loi.php', 0, '2026-04-28 11:28:09'),
(29, 1, 'LOI Approved — Requirements Sent', 'Your Letter of Intent has been approved. The list of requirements you need to submit has been sent. Please review and proceed with your application.', 'success', '/halal_final/dashboard/business_owner/letter_of_intent.php?action=view&id=8', 0, '2026-04-28 11:29:12'),
(30, 2, 'New HDP Application', 'Jasmine Duran has submitted an HDP application.', 'action_required', '/halal_final/dashboard/evaluator/verify_applications.php', 1, '2026-04-28 11:31:19'),
(31, 1, 'LOI Approved — Requirements Sent', 'Your Letter of Intent has been approved. The list of requirements you need to submit has been sent. Please review and proceed with your application.', 'success', '/halal_final/dashboard/business_owner/letter_of_intent.php?action=view&id=8', 0, '2026-04-28 11:31:25'),
(32, 1, 'Application Verified', 'Your HDP application has been verified.', 'success', '/halal_final/dashboard/business_owner/applications.php', 1, '2026-04-28 11:33:01'),
(33, 2, 'New HDP Application', 'Jasmine Duran has submitted an HDP application.', 'action_required', '/halal_final/dashboard/evaluator/verify_applications.php', 1, '2026-04-28 11:33:07'),
(34, 1, 'Application Verified', 'Your HDP application has been verified.', 'success', '/halal_final/dashboard/business_owner/applications.php', 0, '2026-04-28 11:33:27');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `order_number` varchar(50) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `restaurant_id` int(11) NOT NULL,
  `items_json` text NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `delivery_fee` decimal(10,2) DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL,
  `delivery_address` text DEFAULT NULL,
  `payment_method` enum('cash','gcash','bank_transfer','card') DEFAULT 'cash',
  `payment_status` enum('pending','paid','refunded') DEFAULT 'pending',
  `order_status` enum('pending','confirmed','preparing','ready','out_for_delivery','delivered','cancelled') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `ordered_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `delivered_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `reference_type` enum('inspection','laboratory','certification','order') NOT NULL,
  `reference_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `transaction_reference` varchar(255) DEFAULT NULL,
  `receipt_path` varchar(500) DEFAULT NULL,
  `status` enum('pending','verified','rejected') DEFAULT 'pending',
  `verified_by` int(11) DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `potential_decisions`
--

CREATE TABLE `potential_decisions` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `corrective_action_id` int(11) DEFAULT NULL,
  `committee_member_id` int(11) NOT NULL,
  `evidence_summary` text DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `decision` enum('recommend_approve','recommend_reject','need_more_info','pending') DEFAULT 'pending',
  `notify_payment` tinyint(1) DEFAULT 0,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reviews`
--

CREATE TABLE `reviews` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `restaurant_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `rating` int(11) NOT NULL CHECK (`rating` >= 1 and `rating` <= 5),
  `food_rating` int(11) DEFAULT NULL CHECK (`food_rating` >= 1 and `food_rating` <= 5),
  `service_rating` int(11) DEFAULT NULL CHECK (`service_rating` >= 1 and `service_rating` <= 5),
  `comment` text DEFAULT NULL,
  `response` text DEFAULT NULL,
  `response_by` int(11) DEFAULT NULL,
  `response_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `role_name` varchar(100) NOT NULL,
  `role_category` enum('customer','business','hcb_employee','lab_employee') NOT NULL,
  `description` text DEFAULT NULL,
  `requires_approval` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `role_name`, `role_category`, `description`, `requires_approval`, `created_at`) VALUES
(1, 'Customer', 'customer', 'End customer who orders food from halal-certified restaurants', 0, '2026-04-28 06:20:28'),
(2, 'Business Owner', 'business', 'Halal business owner applying for halal certification', 1, '2026-04-28 06:20:28'),
(3, 'Evaluator', 'hcb_employee', 'Evaluates and verifies applications and creates inspection schedules', 1, '2026-04-28 06:20:28'),
(4, 'Auditor - Technical', 'hcb_employee', 'Conducts technical audits and inspections', 1, '2026-04-28 06:20:28'),
(5, 'Auditor - Shariah', 'hcb_employee', 'Conducts Shariah compliance audits and inspections', 1, '2026-04-28 06:20:28'),
(6, 'Impartial Committee', 'hcb_employee', 'Reviews potential decisions and evidence for certification', 1, '2026-04-28 06:20:28'),
(7, 'Decision Committee', 'hcb_employee', 'Makes final decisions on halal certification grants', 1, '2026-04-28 06:20:28'),
(8, 'President', 'hcb_employee', 'Awards halal certificates and logos to certified businesses', 1, '2026-04-28 06:20:28'),
(9, 'Receiving Officer', 'lab_employee', 'Receives and manages laboratory analysis requirements', 1, '2026-04-28 06:20:28'),
(10, 'Laboratory Analyst', 'lab_employee', 'Conducts laboratory analysis on submitted samples', 1, '2026-04-28 06:20:28');

-- --------------------------------------------------------

--
-- Table structure for table `role_applications`
--

CREATE TABLE `role_applications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `application_letter` text DEFAULT NULL,
  `resume_path` varchar(500) DEFAULT NULL,
  `credentials_path` varchar(500) DEFAULT NULL,
  `additional_docs` text DEFAULT NULL,
  `business_name` varchar(255) DEFAULT NULL,
  `business_address` text DEFAULT NULL,
  `business_type` varchar(100) DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_type` varchar(50) DEFAULT 'text',
  `description` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `updated_at`) VALUES
(1, 'site_name', 'Halal Institute of Development Philippines', 'text', 'System name', '2026-04-28 06:20:29'),
(2, 'site_tagline', 'Halal Certification Body', 'text', 'Site tagline', '2026-04-28 06:20:29'),
(3, 'certification_validity_years', '2', 'text', 'Number of years a halal certificate is valid', '2026-04-28 06:20:29'),
(4, 'inspection_base_fee', '5000.00', 'text', 'Base inspection fee in PHP', '2026-04-28 06:20:29'),
(5, 'laboratory_base_fee', '3000.00', 'text', 'Base laboratory analysis fee in PHP', '2026-04-28 06:20:29'),
(6, 'certification_fee', '10000.00', 'text', 'Halal certification fee in PHP', '2026-04-28 06:20:29'),
(7, 'google_client_id', '', 'text', 'Google OAuth Client ID', '2026-04-28 06:20:29'),
(8, 'google_client_secret', '', 'text', 'Google OAuth Client Secret', '2026-04-28 06:20:29');

-- --------------------------------------------------------

--
-- Table structure for table `terms_of_reference`
--

CREATE TABLE `terms_of_reference` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `evaluator_id` int(11) NOT NULL,
  `tor_content` text NOT NULL,
  `registration_agreement` text DEFAULT NULL,
  `payment_for_inspection` decimal(10,2) DEFAULT 0.00,
  `inspection_date` date DEFAULT NULL,
  `inspection_fees` decimal(10,2) DEFAULT 0.00,
  `status` enum('draft','sent','accepted','rejected') DEFAULT 'draft',
  `business_response` enum('pending','accepted','rejected') DEFAULT 'pending',
  `business_remarks` text DEFAULT NULL,
  `responded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `google_id` varchar(255) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `avatar` varchar(500) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `role_id` int(11) DEFAULT NULL,
  `role_status` enum('pending','approved','rejected','none') DEFAULT 'none',
  `is_active` tinyint(1) DEFAULT 1,
  `email_verified` tinyint(1) DEFAULT 0,
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `google_id`, `email`, `full_name`, `first_name`, `last_name`, `avatar`, `password`, `phone`, `address`, `role_id`, `role_status`, `is_active`, `email_verified`, `last_login`, `created_at`, `updated_at`) VALUES
(1, NULL, 'jasmine123@gmail.com', 'Jasmine Duran', 'Jasmine', 'Duran', NULL, '$2y$10$hqMRO.jHHLvzxkxty0xzmOVByCM/drGsA/skx5ufSh2nsL2P7ZQx.', '09054541332', 'PUROK 12, SIRAWAN, TORIL, DAVAO CITY', 2, 'approved', 1, 1, '2026-04-28 10:43:24', '2026-04-28 06:42:26', '2026-04-28 10:43:24'),
(2, NULL, 'jamieduran@gmail.com', 'Jamie Duran', 'Jamie', 'Duran', NULL, '$2y$10$gvyjSS1iUxwsE624.DMKB.cjM/HEj69firjeykDCl/ixtDpJQtXmO', '0912345678', 'Toril, Davao City', 3, 'approved', 1, 1, '2026-04-28 09:12:02', '2026-04-28 07:17:11', '2026-04-28 09:12:02'),
(3, NULL, 'jasminedeporosduran@gmail.com', 'Jams Duran', 'Jams', 'Duran', NULL, '$2y$10$cn/wek.e8OwpcUe1j3kS.eaDlzSN1pbdU.b736flS4cizg.edD6si', '09054541332', NULL, NULL, 'none', 1, 1, '2026-04-28 11:35:27', '2026-04-28 11:35:12', '2026-04-28 11:35:27');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `application_requirement_uploads`
--
ALTER TABLE `application_requirement_uploads`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`);

--
-- Indexes for table `application_verification`
--
ALTER TABLE `application_verification`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`),
  ADD KEY `evaluator_id` (`evaluator_id`);

--
-- Indexes for table `corrective_action_reports`
--
ALTER TABLE `corrective_action_reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`),
  ADD KEY `laboratory_report_id` (`laboratory_report_id`),
  ADD KEY `inspection_id` (`inspection_id`),
  ADD KEY `prepared_by` (`prepared_by`);

--
-- Indexes for table `final_decisions`
--
ALTER TABLE `final_decisions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`),
  ADD KEY `potential_decision_id` (`potential_decision_id`),
  ADD KEY `committee_member_id` (`committee_member_id`);

--
-- Indexes for table `halal_certificates`
--
ALTER TABLE `halal_certificates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `certificate_number` (`certificate_number`),
  ADD KEY `application_id` (`application_id`),
  ADD KEY `final_decision_id` (`final_decision_id`),
  ADD KEY `decorated_by` (`decorated_by`),
  ADD KEY `awarded_by` (`awarded_by`);

--
-- Indexes for table `halal_restaurants`
--
ALTER TABLE `halal_restaurants`
  ADD PRIMARY KEY (`id`),
  ADD KEY `business_owner_id` (`business_owner_id`),
  ADD KEY `certificate_id` (`certificate_id`);

--
-- Indexes for table `hdp_applications`
--
ALTER TABLE `hdp_applications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `loi_id` (`loi_id`),
  ADD KEY `idx_hdp_owner` (`business_owner_id`),
  ADD KEY `idx_hdp_status` (`status`);

--
-- Indexes for table `inspections`
--
ALTER TABLE `inspections`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`),
  ADD KEY `auditor_technical_id` (`auditor_technical_id`),
  ADD KEY `auditor_shariah_id` (`auditor_shariah_id`),
  ADD KEY `idx_inspections_schedule` (`schedule_id`);

--
-- Indexes for table `inspection_schedules`
--
ALTER TABLE `inspection_schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`),
  ADD KEY `evaluator_id` (`evaluator_id`),
  ADD KEY `tor_id` (`tor_id`);

--
-- Indexes for table `laboratory_analyses`
--
ALTER TABLE `laboratory_analyses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `request_id` (`request_id`),
  ADD KEY `analyst_id` (`analyst_id`);

--
-- Indexes for table `laboratory_reports`
--
ALTER TABLE `laboratory_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `report_number` (`report_number`),
  ADD KEY `laboratory_request_id` (`laboratory_request_id`),
  ADD KEY `application_id` (`application_id`),
  ADD KEY `analyst_id` (`analyst_id`);

--
-- Indexes for table `laboratory_requests`
--
ALTER TABLE `laboratory_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`),
  ADD KEY `business_owner_id` (`business_owner_id`),
  ADD KEY `received_by` (`received_by`),
  ADD KEY `analyst_id` (`analyst_id`);

--
-- Indexes for table `letter_of_intent`
--
ALTER TABLE `letter_of_intent`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_loi_owner` (`business_owner_id`),
  ADD KEY `idx_loi_status` (`status`);

--
-- Indexes for table `loi_requirements`
--
ALTER TABLE `loi_requirements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `loi_id` (`loi_id`),
  ADD KEY `evaluator_id` (`evaluator_id`);

--
-- Indexes for table `loi_verification`
--
ALTER TABLE `loi_verification`
  ADD PRIMARY KEY (`id`),
  ADD KEY `loi_id` (`loi_id`),
  ADD KEY `evaluator_id` (`evaluator_id`);

--
-- Indexes for table `menu_items`
--
ALTER TABLE `menu_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `restaurant_id` (`restaurant_id`);

--
-- Indexes for table `ncr_reports`
--
ALTER TABLE `ncr_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ncr_number` (`ncr_number`),
  ADD KEY `inspection_id` (`inspection_id`),
  ADD KEY `application_id` (`application_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notifications_user` (`user_id`),
  ADD KEY `idx_notifications_read` (`is_read`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_number` (`order_number`),
  ADD KEY `idx_orders_customer` (`customer_id`),
  ADD KEY `idx_orders_restaurant` (`restaurant_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `verified_by` (`verified_by`);

--
-- Indexes for table `potential_decisions`
--
ALTER TABLE `potential_decisions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`),
  ADD KEY `corrective_action_id` (`corrective_action_id`),
  ADD KEY `committee_member_id` (`committee_member_id`);

--
-- Indexes for table `reviews`
--
ALTER TABLE `reviews`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `response_by` (`response_by`),
  ADD KEY `idx_reviews_restaurant` (`restaurant_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `role_name` (`role_name`);

--
-- Indexes for table `role_applications`
--
ALTER TABLE `role_applications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `role_id` (`role_id`),
  ADD KEY `reviewed_by` (`reviewed_by`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `terms_of_reference`
--
ALTER TABLE `terms_of_reference`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`),
  ADD KEY `evaluator_id` (`evaluator_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `google_id` (`google_id`),
  ADD KEY `idx_users_google_id` (`google_id`),
  ADD KEY `idx_users_email` (`email`),
  ADD KEY `idx_users_role` (`role_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_log`
--
ALTER TABLE `activity_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT for table `application_requirement_uploads`
--
ALTER TABLE `application_requirement_uploads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `application_verification`
--
ALTER TABLE `application_verification`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `corrective_action_reports`
--
ALTER TABLE `corrective_action_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `final_decisions`
--
ALTER TABLE `final_decisions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `halal_certificates`
--
ALTER TABLE `halal_certificates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `halal_restaurants`
--
ALTER TABLE `halal_restaurants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hdp_applications`
--
ALTER TABLE `hdp_applications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `inspections`
--
ALTER TABLE `inspections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inspection_schedules`
--
ALTER TABLE `inspection_schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `laboratory_analyses`
--
ALTER TABLE `laboratory_analyses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `laboratory_reports`
--
ALTER TABLE `laboratory_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `laboratory_requests`
--
ALTER TABLE `laboratory_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `letter_of_intent`
--
ALTER TABLE `letter_of_intent`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `loi_requirements`
--
ALTER TABLE `loi_requirements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `loi_verification`
--
ALTER TABLE `loi_verification`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `menu_items`
--
ALTER TABLE `menu_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ncr_reports`
--
ALTER TABLE `ncr_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `potential_decisions`
--
ALTER TABLE `potential_decisions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reviews`
--
ALTER TABLE `reviews`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `role_applications`
--
ALTER TABLE `role_applications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `terms_of_reference`
--
ALTER TABLE `terms_of_reference`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD CONSTRAINT `activity_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `application_requirement_uploads`
--
ALTER TABLE `application_requirement_uploads`
  ADD CONSTRAINT `application_requirement_uploads_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `hdp_applications` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `application_verification`
--
ALTER TABLE `application_verification`
  ADD CONSTRAINT `application_verification_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `hdp_applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `application_verification_ibfk_2` FOREIGN KEY (`evaluator_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `corrective_action_reports`
--
ALTER TABLE `corrective_action_reports`
  ADD CONSTRAINT `corrective_action_reports_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `hdp_applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `corrective_action_reports_ibfk_2` FOREIGN KEY (`laboratory_report_id`) REFERENCES `laboratory_reports` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `corrective_action_reports_ibfk_3` FOREIGN KEY (`inspection_id`) REFERENCES `inspections` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `corrective_action_reports_ibfk_4` FOREIGN KEY (`prepared_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `final_decisions`
--
ALTER TABLE `final_decisions`
  ADD CONSTRAINT `final_decisions_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `hdp_applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `final_decisions_ibfk_2` FOREIGN KEY (`potential_decision_id`) REFERENCES `potential_decisions` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `final_decisions_ibfk_3` FOREIGN KEY (`committee_member_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `halal_certificates`
--
ALTER TABLE `halal_certificates`
  ADD CONSTRAINT `halal_certificates_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `hdp_applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `halal_certificates_ibfk_2` FOREIGN KEY (`final_decision_id`) REFERENCES `final_decisions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `halal_certificates_ibfk_3` FOREIGN KEY (`decorated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `halal_certificates_ibfk_4` FOREIGN KEY (`awarded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `halal_restaurants`
--
ALTER TABLE `halal_restaurants`
  ADD CONSTRAINT `halal_restaurants_ibfk_1` FOREIGN KEY (`business_owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `halal_restaurants_ibfk_2` FOREIGN KEY (`certificate_id`) REFERENCES `halal_certificates` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `hdp_applications`
--
ALTER TABLE `hdp_applications`
  ADD CONSTRAINT `hdp_applications_ibfk_1` FOREIGN KEY (`loi_id`) REFERENCES `letter_of_intent` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `hdp_applications_ibfk_2` FOREIGN KEY (`business_owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `inspections`
--
ALTER TABLE `inspections`
  ADD CONSTRAINT `inspections_ibfk_1` FOREIGN KEY (`schedule_id`) REFERENCES `inspection_schedules` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `inspections_ibfk_2` FOREIGN KEY (`application_id`) REFERENCES `hdp_applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `inspections_ibfk_3` FOREIGN KEY (`auditor_technical_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `inspections_ibfk_4` FOREIGN KEY (`auditor_shariah_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `inspection_schedules`
--
ALTER TABLE `inspection_schedules`
  ADD CONSTRAINT `inspection_schedules_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `hdp_applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `inspection_schedules_ibfk_2` FOREIGN KEY (`evaluator_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `inspection_schedules_ibfk_3` FOREIGN KEY (`tor_id`) REFERENCES `terms_of_reference` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `laboratory_analyses`
--
ALTER TABLE `laboratory_analyses`
  ADD CONSTRAINT `laboratory_analyses_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `laboratory_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `laboratory_analyses_ibfk_2` FOREIGN KEY (`analyst_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `laboratory_reports`
--
ALTER TABLE `laboratory_reports`
  ADD CONSTRAINT `laboratory_reports_ibfk_1` FOREIGN KEY (`laboratory_request_id`) REFERENCES `laboratory_requests` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `laboratory_reports_ibfk_2` FOREIGN KEY (`application_id`) REFERENCES `hdp_applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `laboratory_reports_ibfk_3` FOREIGN KEY (`analyst_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `laboratory_requests`
--
ALTER TABLE `laboratory_requests`
  ADD CONSTRAINT `laboratory_requests_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `hdp_applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `laboratory_requests_ibfk_2` FOREIGN KEY (`business_owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `laboratory_requests_ibfk_3` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `laboratory_requests_ibfk_4` FOREIGN KEY (`analyst_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `letter_of_intent`
--
ALTER TABLE `letter_of_intent`
  ADD CONSTRAINT `letter_of_intent_ibfk_1` FOREIGN KEY (`business_owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `loi_requirements`
--
ALTER TABLE `loi_requirements`
  ADD CONSTRAINT `loi_requirements_ibfk_1` FOREIGN KEY (`loi_id`) REFERENCES `letter_of_intent` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `loi_requirements_ibfk_2` FOREIGN KEY (`evaluator_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `loi_verification`
--
ALTER TABLE `loi_verification`
  ADD CONSTRAINT `loi_verification_ibfk_1` FOREIGN KEY (`loi_id`) REFERENCES `letter_of_intent` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `loi_verification_ibfk_2` FOREIGN KEY (`evaluator_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `menu_items`
--
ALTER TABLE `menu_items`
  ADD CONSTRAINT `menu_items_ibfk_1` FOREIGN KEY (`restaurant_id`) REFERENCES `halal_restaurants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ncr_reports`
--
ALTER TABLE `ncr_reports`
  ADD CONSTRAINT `ncr_reports_ibfk_1` FOREIGN KEY (`inspection_id`) REFERENCES `inspections` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ncr_reports_ibfk_2` FOREIGN KEY (`application_id`) REFERENCES `hdp_applications` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `orders_ibfk_2` FOREIGN KEY (`restaurant_id`) REFERENCES `halal_restaurants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `potential_decisions`
--
ALTER TABLE `potential_decisions`
  ADD CONSTRAINT `potential_decisions_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `hdp_applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `potential_decisions_ibfk_2` FOREIGN KEY (`corrective_action_id`) REFERENCES `corrective_action_reports` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `potential_decisions_ibfk_3` FOREIGN KEY (`committee_member_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `reviews`
--
ALTER TABLE `reviews`
  ADD CONSTRAINT `reviews_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reviews_ibfk_2` FOREIGN KEY (`restaurant_id`) REFERENCES `halal_restaurants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reviews_ibfk_3` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `reviews_ibfk_4` FOREIGN KEY (`response_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `role_applications`
--
ALTER TABLE `role_applications`
  ADD CONSTRAINT `role_applications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `role_applications_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `role_applications_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `terms_of_reference`
--
ALTER TABLE `terms_of_reference`
  ADD CONSTRAINT `terms_of_reference_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `hdp_applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `terms_of_reference_ibfk_2` FOREIGN KEY (`evaluator_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
