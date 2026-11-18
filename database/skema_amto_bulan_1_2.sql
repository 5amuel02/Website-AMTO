-- MariaDB dump 10.19  Distrib 10.4.28-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: moodle
-- ------------------------------------------------------
-- Server version	10.4.28-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `mdl_amto_registrations`
--

DROP TABLE IF EXISTS `mdl_amto_registrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mdl_amto_registrations` (
  `id` bigint(10) NOT NULL AUTO_INCREMENT,
  `registration_no` varchar(50) NOT NULL,
  `userid` bigint(10) NOT NULL DEFAULT 0,
  `username` varchar(100) NOT NULL,
  `fullname` varchar(200) NOT NULL,
  `nik` varchar(50) NOT NULL,
  `email` varchar(150) NOT NULL,
  `phone` varchar(50) NOT NULL,
  `organization` varchar(200) NOT NULL,
  `amel_no` varchar(100) DEFAULT '',
  `schedule_id` bigint(10) NOT NULL DEFAULT 0,
  `program_name` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL DEFAULT '',
  `doc_ktp` varchar(255) DEFAULT '',
  `doc_ijazah` varchar(255) DEFAULT '',
  `doc_pendukung` varchar(255) DEFAULT '',
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `verified_by` bigint(10) NOT NULL DEFAULT 0,
  `timecreated` bigint(10) NOT NULL DEFAULT 0,
  `timemodified` bigint(10) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mdl_amto_registrations`
--

LOCK TABLES `mdl_amto_registrations` WRITE;
/*!40000 ALTER TABLE `mdl_amto_registrations` DISABLE KEYS */;
/*!40000 ALTER TABLE `mdl_amto_registrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mdl_amto_schedules`
--

DROP TABLE IF EXISTS `mdl_amto_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mdl_amto_schedules` (
  `id` bigint(10) NOT NULL AUTO_INCREMENT,
  `batch_code` varchar(50) NOT NULL,
  `program_name` varchar(255) NOT NULL,
  `category_type` varchar(100) NOT NULL,
  `training_mode` varchar(100) NOT NULL,
  `room_location` varchar(150) NOT NULL,
  `instructor_name` varchar(150) NOT NULL,
  `start_date` varchar(30) NOT NULL,
  `end_date` varchar(30) NOT NULL,
  `schedule_time` varchar(100) NOT NULL,
  `quota` int(10) NOT NULL DEFAULT 24,
  `status` varchar(40) NOT NULL DEFAULT 'Open Registration',
  `timecreated` bigint(10) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mdl_amto_schedules`
--

LOCK TABLES `mdl_amto_schedules` WRITE;
/*!40000 ALTER TABLE `mdl_amto_schedules` DISABLE KEYS */;
INSERT INTO `mdl_amto_schedules` VALUES (1,'BATCH-AMTO-2601','Basic License Category B1.1 (Turbine Aeroplanes)','Basic License','Blended (Theory & Practical)','Classroom A-201 & Hangar B-01','Ir. Budi Santoso, AMEL','2026-12-01','2027-01-30','Senin - Jumat, 08:00 - 16:00 WIB',24,'Open Registration',1794964342),(2,'BATCH-AMTO-2602','Basic License Category B2 (Avionics Maintenance)','Basic License','Blended (Theory & Practical)','Avionics Lab C-102 & Hangar B-02','Rizky Pratama, S.T., M.T.','2026-12-08','2027-02-05','Senin - Jumat, 08:00 - 16:00 WIB',20,'Open Registration',1794964342),(3,'BATCH-AMTO-2603','Boeing 737-800 NG Airframe & Powerplant Type Rating','Type Rating','Theory & Simulator/Hangar','Classroom A-305 & Engine Shop','Capt. Hendra Wijaya / Tim Instruktur TATC','2026-12-15','2027-01-20','Senin - Jumat, 08:30 - 16:30 WIB',16,'Open Registration',1794964342),(4,'BATCH-AMTO-2604','Mandatory Training: Human Factors, SMS & EWIS','Mandatory / Recurrent','Theory (Classroom / Online LMS)','Auditorium TATC Lt. 2','Drs. Dedi Kusuma (QA & Safety)','2026-11-25','2026-11-29','Senin - Jumat, 09:00 - 15:00 WIB',30,'Ongoing',1794964342);
/*!40000 ALTER TABLE `mdl_amto_schedules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mdl_amto_repository`
--

DROP TABLE IF EXISTS `mdl_amto_repository`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mdl_amto_repository` (
  `id` bigint(10) NOT NULL AUTO_INCREMENT,
  `module_code` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `category_type` varchar(100) NOT NULL,
  `batch_code` varchar(80) NOT NULL DEFAULT 'Semua Batch',
  `file_type` varchar(60) NOT NULL DEFAULT 'PDF Training Manual',
  `file_path` varchar(255) NOT NULL DEFAULT '',
  `file_size` varchar(40) NOT NULL DEFAULT '',
  `doc_version` varchar(50) NOT NULL DEFAULT 'Rev. 01 - 2026',
  `description` text DEFAULT NULL,
  `uploaded_by` bigint(10) NOT NULL DEFAULT 0,
  `uploader_name` varchar(150) NOT NULL DEFAULT 'Admin TATC',
  `status` varchar(30) NOT NULL DEFAULT 'Published',
  `timecreated` bigint(10) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mdl_amto_repository`
--

LOCK TABLES `mdl_amto_repository` WRITE;
/*!40000 ALTER TABLE `mdl_amto_repository` DISABLE KEYS */;
/*!40000 ALTER TABLE `mdl_amto_repository` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-18 11:21:03
