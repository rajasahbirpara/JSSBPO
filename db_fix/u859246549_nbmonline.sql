-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Feb 23, 2026 at 12:02 PM
-- Server version: 11.8.3-MariaDB-log
-- PHP Version: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `u859246549_nbmonline`
--

-- --------------------------------------------------------

--
-- Stand-in structure for view `application_stats`
-- (See below for the actual view)
--
CREATE TABLE `application_stats` (
`application_date` date
,`total_applications` bigint(21)
,`total_revenue` decimal(32,2)
,`paid_applications` bigint(21)
,`processed_applications` bigint(21)
,`delivered_applications` bigint(21)
);

-- --------------------------------------------------------

--
-- Table structure for table `online_forms`
--

CREATE TABLE `online_forms` (
  `id` int(11) NOT NULL,
  `timestamp` datetime NOT NULL,
  `uid` varchar(50) NOT NULL,
  `hof_name` varchar(255) NOT NULL,
  `hof_dob` date NOT NULL,
  `gender` enum('MALE','FEMALE') NOT NULL,
  `contact` varchar(10) NOT NULL,
  `issue_date` date NOT NULL,
  `valid_upto` date NOT NULL,
  `village` varchar(255) NOT NULL,
  `post` varchar(255) NOT NULL,
  `dist` varchar(255) NOT NULL,
  `pin_code` varchar(6) NOT NULL,
  `member_count` int(11) NOT NULL DEFAULT 1,
  `hof_photo_url` varchar(500) DEFAULT NULL,
  `member1_name` varchar(255) DEFAULT NULL,
  `member1_age` int(11) DEFAULT NULL,
  `member1_type` enum('ADULT','CHILD','HANDICAPPED') DEFAULT NULL,
  `member1_proof_url` varchar(500) DEFAULT NULL,
  `member2_name` varchar(255) DEFAULT NULL,
  `member2_age` int(11) DEFAULT NULL,
  `member2_type` enum('ADULT','CHILD','HANDICAPPED') DEFAULT NULL,
  `member2_proof_url` varchar(500) DEFAULT NULL,
  `member3_name` varchar(255) DEFAULT NULL,
  `member3_age` int(11) DEFAULT NULL,
  `member3_type` enum('ADULT','CHILD','HANDICAPPED') DEFAULT NULL,
  `member3_proof_url` varchar(500) DEFAULT NULL,
  `member4_name` varchar(255) DEFAULT NULL,
  `member4_age` int(11) DEFAULT NULL,
  `member4_type` enum('ADULT','CHILD','HANDICAPPED') DEFAULT NULL,
  `member4_proof_url` varchar(500) DEFAULT NULL,
  `member5_name` varchar(255) DEFAULT NULL,
  `member5_age` int(11) DEFAULT NULL,
  `member5_type` enum('ADULT','CHILD','HANDICAPPED') DEFAULT NULL,
  `member5_proof_url` varchar(500) DEFAULT NULL,
  `payment_id` varchar(100) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `status` enum('PENDING','PAID','PROCESSED','DELIVERED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `online_forms`
--

INSERT INTO `online_forms` (`id`, `timestamp`, `uid`, `hof_name`, `hof_dob`, `gender`, `contact`, `issue_date`, `valid_upto`, `village`, `post`, `dist`, `pin_code`, `member_count`, `hof_photo_url`, `member1_name`, `member1_age`, `member1_type`, `member1_proof_url`, `member2_name`, `member2_age`, `member2_type`, `member2_proof_url`, `member3_name`, `member3_age`, `member3_type`, `member3_proof_url`, `member4_name`, `member4_age`, `member4_type`, `member4_proof_url`, `member5_name`, `member5_age`, `member5_type`, `member5_proof_url`, `payment_id`, `total_amount`, `status`, `created_at`, `updated_at`) VALUES
(1, '2025-08-14 06:55:52', 'NBM2025/08/001', 'RAJA SAH', '1989-09-18', 'FEMALE', '7001159731', '2025-08-14', '2026-08-13', 'DGSDGSGSGSG', 'KOLKATA', 'KOLKATA', '700002', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pay_R57hAaIKTd8BWz', 100.00, 'PAID', '2025-08-14 06:55:52', '2025-08-14 06:55:52'),
(2, '2025-08-14 06:57:18', 'NBM2025/08/002', 'RAJA SAH', '1989-09-18', 'MALE', '7001159731', '2025-08-14', '2026-08-13', 'TEST VILLAGE', 'KOLKATA', 'KOLKATA', '700002', 3, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pay_R57ih7TilxMTF1', 300.00, 'PAID', '2025-08-14 06:57:18', '2025-08-14 06:57:18'),
(3, '2025-08-14 07:23:48', 'NBM2025/01/TEST1755156230638', 'TEST USER', '1990-01-01', 'MALE', '9876543210', '2025-08-14', '2026-08-14', 'DEFAULT VILLAGE', 'DEFAULT POST', 'DEFAULT DIST', '000000', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pay_test_1755156230638', 100.00, 'PAID', '2025-08-14 07:23:48', '2025-08-14 07:23:48'),
(4, '2025-08-14 07:23:50', 'NBM2025/01/FULL1755156232358', 'FULL TEST USER', '1990-01-01', 'MALE', '9876543210', '2025-08-14', '2026-08-14', 'TEST VILLAGE', 'GPO KOLKATA', 'KOLKATA', '700001', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pay_full_test_1755156232358', 100.00, 'PAID', '2025-08-14 07:23:50', '2025-08-14 07:23:50'),
(5, '2025-08-14 07:26:27', 'NBM2025/01/TEST1755156389470', 'TEST USER', '1990-01-01', 'MALE', '9876543210', '2025-08-14', '2026-08-14', 'DEFAULT VILLAGE', 'DEFAULT POST', 'DEFAULT DIST', '000000', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pay_test_1755156389470', 100.00, 'PAID', '2025-08-14 07:26:27', '2025-08-14 07:26:27'),
(6, '2025-08-14 07:26:29', 'NBM2025/01/FULL1755156390782', 'FULL TEST USER', '1990-01-01', 'MALE', '9876543210', '2025-08-14', '2026-08-14', 'TEST VILLAGE', 'GPO KOLKATA', 'KOLKATA', '700001', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pay_full_test_1755156390782', 100.00, 'PAID', '2025-08-14 07:26:29', '2025-08-14 07:26:29'),
(7, '2025-08-14 07:31:09', 'NBM2025/08/003', 'RAJA SAH', '1990-01-01', 'MALE', '7001159731', '2025-08-14', '2026-08-14', 'DGSDGSGSGSG', 'KOLKATA', 'KOLKATA', '700002', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pay_R58IRmcFysupfu', 100.00, 'PAID', '2025-08-14 07:31:09', '2025-08-14 07:31:09'),
(8, '2025-08-14 07:32:52', 'NBM2025/08/004', 'RAJA SAH', '1990-01-01', 'MALE', '7001159731', '2025-08-14', '2026-08-14', 'DGSDGSGSGSG', 'KOLKATA', 'KOLKATA', '700002', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pay_R58KGQUikCso69', 100.00, 'PAID', '2025-08-14 07:32:52', '2025-08-14 07:32:52'),
(9, '2025-08-14 07:41:12', 'NBM2025/08/005', 'RAJA SAH', '1990-01-01', 'MALE', '7001159731', '2025-08-14', '2026-08-14', 'DGSDGSGSGSG', 'KOLKATA', 'KOLKATA', '700002', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pay_R58T4cR3uGeh8s', 100.00, 'PAID', '2025-08-14 07:41:12', '2025-08-14 07:41:12'),
(10, '2025-08-14 07:46:19', 'NBM2025/08/006', 'RAJA SAH', '1990-01-01', 'MALE', '7001159731', '2025-08-14', '2026-08-14', 'DGSDGSGSGSG', 'KOLKATA', 'KOLKATA', '700002', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pay_R58YT7WszCgyGH', 300.00, 'PAID', '2025-08-14 07:46:19', '2025-08-14 07:46:19'),
(11, '2025-08-14 08:12:26', 'NBM2025/08/007', 'RAJA SAH', '1990-01-01', 'MALE', '7001159731', '2025-08-14', '2026-08-14', 'DGSDGSGSGSG', 'KOLKATA', 'KOLKATA', '700002', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pay_R5904SQ6PMAnrn', 100.00, 'PAID', '2025-08-14 08:12:26', '2025-08-14 08:12:26'),
(12, '2025-08-14 08:33:40', 'NBM2025/08/008', 'TEST USER', '1990-01-01', 'MALE', '7001159731', '2025-08-14', '2026-08-14', 'DGSDGSGSGSG', 'KOLKATA', 'KOLKATA', '700002', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pay_R59MV2Mg7fTmj7', 100.00, 'PAID', '2025-08-14 08:33:40', '2025-08-14 08:33:40'),
(13, '2025-08-14 08:41:32', 'NBM2025/08/009', 'TEST USER', '1990-01-01', 'MALE', '7001159731', '2025-08-14', '2026-08-14', 'DGSDGSGSGSG', 'KOLKATA', 'KOLKATA', '700002', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pay_R59Uo6MJJE2yhq', 100.00, 'PAID', '2025-08-14 08:41:32', '2025-08-14 08:41:32');

-- --------------------------------------------------------

--
-- Table structure for table `payment_transactions`
--

CREATE TABLE `payment_transactions` (
  `id` int(11) NOT NULL,
  `uid` varchar(50) NOT NULL,
  `payment_id` varchar(100) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` varchar(3) DEFAULT 'INR',
  `status` varchar(50) NOT NULL,
  `gateway` varchar(50) DEFAULT 'razorpay',
  `transaction_time` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pin_codes`
--

CREATE TABLE `pin_codes` (
  `id` int(11) NOT NULL,
  `pin_code` varchar(6) NOT NULL,
  `post_office` varchar(255) NOT NULL,
  `district` varchar(255) NOT NULL,
  `state` varchar(255) DEFAULT 'West Bengal',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `pin_codes`
--

INSERT INTO `pin_codes` (`id`, `pin_code`, `post_office`, `district`, `state`, `created_at`) VALUES
(1, '700001', 'GPO KOLKATA', 'KOLKATA', 'West Bengal', '2025-08-14 06:29:50'),
(2, '700002', 'KOLKATA', 'KOLKATA', 'West Bengal', '2025-08-14 06:29:50'),
(3, '700003', 'KOLKATA', 'KOLKATA', 'West Bengal', '2025-08-14 06:29:50'),
(4, '700004', 'KOLKATA', 'KOLKATA', 'West Bengal', '2025-08-14 06:29:50'),
(5, '700005', 'KOLKATA', 'KOLKATA', 'West Bengal', '2025-08-14 06:29:50'),
(6, '734001', 'SILIGURI', 'DARJEELING', 'West Bengal', '2025-08-14 06:29:50'),
(7, '734002', 'SILIGURI', 'DARJEELING', 'West Bengal', '2025-08-14 06:29:50'),
(8, '734003', 'SILIGURI', 'DARJEELING', 'West Bengal', '2025-08-14 06:29:50'),
(9, '734004', 'SILIGURI', 'DARJEELING', 'West Bengal', '2025-08-14 06:29:50'),
(10, '734005', 'SILIGURI', 'DARJEELING', 'West Bengal', '2025-08-14 06:29:50'),
(11, '735101', 'JALPAIGURI', 'JALPAIGURI', 'West Bengal', '2025-08-14 06:29:50'),
(12, '735102', 'JALPAIGURI', 'JALPAIGURI', 'West Bengal', '2025-08-14 06:29:50'),
(13, '736101', 'COOCH BEHAR', 'COOCH BEHAR', 'West Bengal', '2025-08-14 06:29:50'),
(14, '736102', 'COOCH BEHAR', 'COOCH BEHAR', 'West Bengal', '2025-08-14 06:29:50'),
(15, '733101', 'ALIPURDUAR', 'ALIPURDUAR', 'West Bengal', '2025-08-14 06:29:50'),
(16, '733102', 'ALIPURDUAR', 'ALIPURDUAR', 'West Bengal', '2025-08-14 06:29:50'),
(17, '735211', 'MAL', 'JALPAIGURI', 'West Bengal', '2025-08-14 06:29:50'),
(18, '735212', 'MAL', 'JALPAIGURI', 'West Bengal', '2025-08-14 06:29:50'),
(19, '734501', 'KURSEONG', 'DARJEELING', 'West Bengal', '2025-08-14 06:29:50'),
(20, '734301', 'KALIMPONG', 'KALIMPONG', 'West Bengal', '2025-08-14 06:29:50');

-- --------------------------------------------------------

--
-- Table structure for table `system_logs`
--

CREATE TABLE `system_logs` (
  `id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `uid` varchar(50) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `online_forms`
--
ALTER TABLE `online_forms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_uid` (`uid`),
  ADD KEY `idx_contact` (`contact`),
  ADD KEY `idx_payment_id` (`payment_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_timestamp` (`timestamp`),
  ADD KEY `idx_created_date` (`created_at`),
  ADD KEY `idx_pin_code` (`pin_code`),
  ADD KEY `idx_district` (`dist`);

--
-- Indexes for table `payment_transactions`
--
ALTER TABLE `payment_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `uid` (`uid`),
  ADD KEY `payment_id` (`payment_id`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `pin_codes`
--
ALTER TABLE `pin_codes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `pin_code` (`pin_code`),
  ADD KEY `post_office` (`post_office`),
  ADD KEY `district` (`district`);

--
-- Indexes for table `system_logs`
--
ALTER TABLE `system_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `action` (`action`),
  ADD KEY `uid` (`uid`),
  ADD KEY `created_at` (`created_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `online_forms`
--
ALTER TABLE `online_forms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `payment_transactions`
--
ALTER TABLE `payment_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pin_codes`
--
ALTER TABLE `pin_codes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `system_logs`
--
ALTER TABLE `system_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------

--
-- Structure for view `application_stats`
--
DROP TABLE IF EXISTS `application_stats`;

CREATE ALGORITHM=UNDEFINED DEFINER=`u859246549_onlinenbm`@`127.0.0.1` SQL SECURITY DEFINER VIEW `application_stats`  AS SELECT cast(`online_forms`.`created_at` as date) AS `application_date`, count(0) AS `total_applications`, sum(`online_forms`.`total_amount`) AS `total_revenue`, count(case when `online_forms`.`status` = 'PAID' then 1 end) AS `paid_applications`, count(case when `online_forms`.`status` = 'PROCESSED' then 1 end) AS `processed_applications`, count(case when `online_forms`.`status` = 'DELIVERED' then 1 end) AS `delivered_applications` FROM `online_forms` GROUP BY cast(`online_forms`.`created_at` as date) ORDER BY cast(`online_forms`.`created_at` as date) DESC ;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `payment_transactions`
--
ALTER TABLE `payment_transactions`
  ADD CONSTRAINT `payment_transactions_ibfk_1` FOREIGN KEY (`uid`) REFERENCES `online_forms` (`uid`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
