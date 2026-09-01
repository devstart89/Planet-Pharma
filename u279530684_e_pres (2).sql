-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Sep 01, 2026 at 06:21 AM
-- Server version: 11.8.8-MariaDB-log
-- PHP Version: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `u279530684_e_pres`
--

-- --------------------------------------------------------

--
-- Table structure for table `api_keys`
--

CREATE TABLE `api_keys` (
  `id` int(11) NOT NULL,
  `api_key` varchar(255) NOT NULL,
  `pharmacy_name` varchar(255) DEFAULT NULL,
  `facility_id` int(11) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `secret_key` varchar(255) NOT NULL,
  `rate_limit` int(11) DEFAULT 60,
  `rate_window` int(11) DEFAULT 60
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `api_logs`
--

CREATE TABLE `api_logs` (
  `id` int(11) NOT NULL,
  `api_key` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `endpoint` varchar(255) DEFAULT NULL,
  `request_id` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(255) DEFAULT NULL,
  `ip_address` varchar(100) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `clusters`
--

CREATE TABLE `clusters` (
  `id` int(11) NOT NULL,
  `cluster_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `department`
--

CREATE TABLE `department` (
  `department_id` int(11) NOT NULL,
  `department_name` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `health_facilities`
--

CREATE TABLE `health_facilities` (
  `id` int(11) NOT NULL,
  `pharmacy_id` int(11) DEFAULT NULL,
  `facility_name` varchar(150) DEFAULT NULL,
  `facility_type` enum('Regular','Hospice') NOT NULL DEFAULT 'Regular',
  `address` text DEFAULT NULL,
  `contact_number` varchar(50) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hf_description`
--

CREATE TABLE `hf_description` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `cluster_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `ip_address` varchar(100) DEFAULT NULL,
  `attempt_time` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `medicines`
--

CREATE TABLE `medicines` (
  `id` int(11) NOT NULL,
  `generic_name` varchar(255) NOT NULL,
  `brand_name` varchar(255) DEFAULT NULL,
  `dosage` varchar(100) DEFAULT NULL,
  `uom` varchar(20) DEFAULT NULL,
  `signa` varchar(128) DEFAULT NULL,
  `frequency` varchar(100) DEFAULT NULL,
  `duration` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `patients`
--

CREATE TABLE `patients` (
  `id` int(11) NOT NULL,
  `his_id` varchar(20) NOT NULL,
  `member_card_no` varchar(50) DEFAULT NULL,
  `account_type` enum('MC','YC') NOT NULL DEFAULT 'YC',
  `qr_code` varchar(255) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `email` varchar(128) DEFAULT 'n/a',
  `suffix` varchar(150) NOT NULL,
  `gender` enum('MALE','FEMALE') DEFAULT NULL,
  `birthday` text DEFAULT NULL,
  `age` int(11) DEFAULT NULL,
  `civil_status` varchar(50) DEFAULT NULL,
  `house_no_street` text DEFAULT NULL,
  `barangay` varchar(100) DEFAULT NULL,
  `contact_number` varchar(50) DEFAULT NULL,
  `yellow_card` varchar(50) DEFAULT NULL,
  `makati_health_plus_no` varchar(20) DEFAULT NULL,
  `department` varchar(255) DEFAULT NULL,
  `employment_type` enum('JOB ORDER','CASUAL','REGULAR','CONTRACTUAL') DEFAULT NULL,
  `expiration_date` date DEFAULT NULL,
  `membership_type` varchar(50) DEFAULT NULL,
  `facility_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `house_address` varchar(255) DEFAULT NULL,
  `makati_employee` varchar(10) DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `upload_batch` varchar(50) DEFAULT NULL,
  `is_duplicate_upload` tinyint(1) NOT NULL,
  `external_id` varchar(50) DEFAULT NULL,
  `last_medical_record_date` datetime DEFAULT NULL,
  `last_prescription_date` datetime DEFAULT NULL,
  `last_refill_date` datetime DEFAULT NULL,
  `last_medical_consult` timestamp NULL DEFAULT NULL,
  `priority_type` varchar(233) NOT NULL,
  `member_type` varchar(128) DEFAULT NULL,
  `cluster` varchar(128) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_by_username` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pharmacy`
--

CREATE TABLE `pharmacy` (
  `id` int(11) NOT NULL,
  `facility_id` int(11) DEFAULT NULL,
  `pharmacy_name` varchar(150) DEFAULT NULL,
  `slug` varchar(100) NOT NULL,
  `store_code` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `printer_connection_type` enum('wired','wireless') NOT NULL DEFAULT 'wired',
  `printer_label` varchar(100) DEFAULT NULL,
  `printer_ip` varchar(45) DEFAULT NULL,
  `printer_port` smallint(5) UNSIGNED DEFAULT NULL,
  `printer_protocol` enum('epos_xml','raw') DEFAULT 'epos_xml',
  `printer_paper_width` enum('58mm','80mm') NOT NULL DEFAULT '80mm',
  `printer_settings_updated_at` timestamp NULL DEFAULT NULL,
  `contact_number` varchar(50) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `prescriptions`
--

CREATE TABLE `prescriptions` (
  `id` int(11) NOT NULL,
  `prescription_number` varchar(30) DEFAULT NULL,
  `patient_id` int(11) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `doctor_id` int(11) DEFAULT NULL,
  `facility_id` int(11) DEFAULT NULL,
  `diagnosis` varchar(255) NOT NULL,
  `status` enum('For Signing','Signed','Denied') NOT NULL DEFAULT 'For Signing',
  `is_refill` enum('YES','NO') DEFAULT 'NO',
  `for_refill` enum('YES','NO') NOT NULL DEFAULT 'YES',
  `remarks` varchar(255) NOT NULL DEFAULT 'N/A',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `transmittal_id` int(11) DEFAULT NULL,
  `transmitted_at` datetime DEFAULT NULL,
  `signature_path` varchar(255) DEFAULT NULL,
  `signed_by` varchar(150) DEFAULT NULL,
  `signed_at` datetime DEFAULT NULL,
  `pulled` int(11) DEFAULT NULL,
  `pulled_by` varchar(255) DEFAULT NULL,
  `medicine_status` enum('Pending','Dispensed','Partial','Cancelled') DEFAULT NULL,
  `dispensed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `prescription_medicines`
--

CREATE TABLE `prescription_medicines` (
  `id` int(11) NOT NULL,
  `prescription_id` int(11) NOT NULL,
  `medicine_name` varchar(255) NOT NULL,
  `dosage` varchar(100) DEFAULT NULL,
  `uom` varchar(20) DEFAULT NULL,
  `signa` varchar(128) DEFAULT NULL,
  `frequency` varchar(100) DEFAULT NULL,
  `duration` varchar(100) DEFAULT NULL,
  `quantity` int(11) DEFAULT 0,
  `dispensed_quantity` int(11) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `status` enum('pending','partial','not-dispensed','dispensed') NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `pharmacy_status` varchar(20) DEFAULT 'Pending',
  `pharmacy_reason` text DEFAULT NULL,
  `served_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `queues`
--

CREATE TABLE `queues` (
  `id` int(11) NOT NULL,
  `pharmacy_id` int(11) NOT NULL DEFAULT 1,
  `prescription_id` int(11) DEFAULT NULL,
  `walk_in_name` varchar(150) DEFAULT NULL,
  `source` enum('E-Pres','Walk-in') NOT NULL,
  `category` enum('Regular','Priority') NOT NULL DEFAULT 'Regular',
  `queue_number` int(11) NOT NULL,
  `status` enum('Waiting','Now Serving','Completed','Unclaimed','Cancelled') DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `called_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `unclaimed_at` datetime DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_reason` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `queue_status_log`
--

CREATE TABLE `queue_status_log` (
  `id` int(11) NOT NULL,
  `queue_id` int(11) NOT NULL,
  `old_status` varchar(32) DEFAULT NULL,
  `new_status` varchar(32) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transmittals`
--

CREATE TABLE `transmittals` (
  `id` int(11) NOT NULL,
  `date_generated` datetime NOT NULL,
  `prescription_date` date NOT NULL,
  `health_facility` varchar(150) NOT NULL,
  `pharmacist` varchar(150) NOT NULL,
  `num_patients` int(11) DEFAULT 0,
  `status` enum('Generated','Signed','Denied') NOT NULL DEFAULT 'Generated',
  `transmitted` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `facility_id` int(11) DEFAULT NULL,
  `generated_by` varchar(150) DEFAULT NULL,
  `delivery_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transmittal_prescriptions`
--

CREATE TABLE `transmittal_prescriptions` (
  `id` int(11) NOT NULL,
  `transmittal_id` int(11) NOT NULL,
  `prescription_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `email` varchar(150) NOT NULL,
  `username` varchar(128) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('super_admin','doctor','pharmacy','health_facility') NOT NULL,
  `license_number` varchar(50) DEFAULT NULL,
  `ptr_number` varchar(50) DEFAULT NULL,
  `facility_id` int(11) DEFAULT NULL,
  `pharmacy_id` int(11) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `remember_token` varchar(255) DEFAULT NULL,
  `failed_attempts` int(11) DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `signature_path` varchar(255) DEFAULT NULL,
  `signature` varchar(255) DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `api_keys`
--
ALTER TABLE `api_keys`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `api_logs`
--
ALTER TABLE `api_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_api_logs_key_time` (`api_key`,`created_at`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `clusters`
--
ALTER TABLE `clusters`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `department`
--
ALTER TABLE `department`
  ADD PRIMARY KEY (`department_id`);

--
-- Indexes for table `health_facilities`
--
ALTER TABLE `health_facilities`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `hf_description`
--
ALTER TABLE `hf_description`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `medicines`
--
ALTER TABLE `medicines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_medicines_generic_name` (`generic_name`),
  ADD KEY `idx_medicines_status` (`status`);

--
-- Indexes for table `patients`
--
ALTER TABLE `patients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_his` (`facility_id`,`his_id`),
  ADD KEY `fk_patient_creator` (`created_by`);

--
-- Indexes for table `pharmacy`
--
ALTER TABLE `pharmacy`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pharmacy_slug` (`slug`);

--
-- Indexes for table `prescriptions`
--
ALTER TABLE `prescriptions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `prescription_number` (`prescription_number`),
  ADD KEY `patient_id` (`patient_id`);

--
-- Indexes for table `prescription_medicines`
--
ALTER TABLE `prescription_medicines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `prescription_id` (`prescription_id`);

--
-- Indexes for table `queues`
--
ALTER TABLE `queues`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_queues_lookup` (`pharmacy_id`,`category`,`status`,`created_at`);

--
-- Indexes for table `queue_status_log`
--
ALTER TABLE `queue_status_log`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `transmittals`
--
ALTER TABLE `transmittals`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `transmittal_prescriptions`
--
ALTER TABLE `transmittal_prescriptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `transmittal_id` (`transmittal_id`),
  ADD KEY `prescription_id` (`prescription_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `email_2` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `api_logs`
--
ALTER TABLE `api_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `clusters`
--
ALTER TABLE `clusters`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `department`
--
ALTER TABLE `department`
  MODIFY `department_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `health_facilities`
--
ALTER TABLE `health_facilities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `hf_description`
--
ALTER TABLE `hf_description`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `medicines`
--
ALTER TABLE `medicines`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `patients`
--
ALTER TABLE `patients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `pharmacy`
--
ALTER TABLE `pharmacy`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `prescriptions`
--
ALTER TABLE `prescriptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `prescription_medicines`
--
ALTER TABLE `prescription_medicines`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `queues`
--
ALTER TABLE `queues`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `queue_status_log`
--
ALTER TABLE `queue_status_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `transmittals`
--
ALTER TABLE `transmittals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `transmittal_prescriptions`
--
ALTER TABLE `transmittal_prescriptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `patients`
--
ALTER TABLE `patients`
  ADD CONSTRAINT `fk_facility` FOREIGN KEY (`facility_id`) REFERENCES `health_facilities` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_patient_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `prescriptions`
--
ALTER TABLE `prescriptions`
  ADD CONSTRAINT `prescriptions_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `prescription_medicines`
--
ALTER TABLE `prescription_medicines`
  ADD CONSTRAINT `prescription_medicines_ibfk_1` FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `transmittal_prescriptions`
--
ALTER TABLE `transmittal_prescriptions`
  ADD CONSTRAINT `transmittal_prescriptions_ibfk_1` FOREIGN KEY (`transmittal_id`) REFERENCES `transmittals` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transmittal_prescriptions_ibfk_2` FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;