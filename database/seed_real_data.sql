-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: bus_ticketing_db
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

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
-- Table structure for table `booking_seats`
--

DROP TABLE IF EXISTS `booking_seats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `booking_seats` (
  `booking_seat_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` int(10) unsigned NOT NULL,
  `trip_id` int(10) unsigned NOT NULL,
  `seat_number` smallint(5) unsigned NOT NULL,
  `passenger_type` enum('REGULAR','STUDENT','SENIOR','PWD') NOT NULL DEFAULT 'REGULAR',
  `price` decimal(10,2) NOT NULL,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `id_number` varchar(50) DEFAULT NULL,
  `seat_status` enum('BOOKED','CANCELLED') NOT NULL DEFAULT 'BOOKED',
  `active_seat` smallint(5) unsigned GENERATED ALWAYS AS (case when `seat_status` = 'BOOKED' then `seat_number` else NULL end) STORED,
  PRIMARY KEY (`booking_seat_id`),
  UNIQUE KEY `uq_trip_active_seat` (`trip_id`,`active_seat`),
  KEY `idx_bs_booking` (`booking_id`),
  KEY `fk_bs_booking` (`booking_id`,`trip_id`),
  CONSTRAINT `fk_bs_booking` FOREIGN KEY (`booking_id`, `trip_id`) REFERENCES `bookings` (`booking_id`, `trip_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_bs_seat` CHECK (`seat_number` >= 1),
  CONSTRAINT `chk_bs_price` CHECK (`price` >= 0)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `booking_seats`
--

LOCK TABLES `booking_seats` WRITE;
/*!40000 ALTER TABLE `booking_seats` DISABLE KEYS */;
/*!40000 ALTER TABLE `booking_seats` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_booking_seats_capacity_insert
BEFORE INSERT ON booking_seats
FOR EACH ROW
BEGIN
    DECLARE v_total SMALLINT UNSIGNED;
    SELECT b.total_seats INTO v_total
    FROM trips t JOIN buses b ON b.bus_id = t.bus_id
    WHERE t.trip_id = NEW.trip_id;
    IF NEW.seat_number > v_total THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Invalid seat: seat number exceeds the bus seating capacity.';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_booking_seats_capacity_update
BEFORE UPDATE ON booking_seats
FOR EACH ROW
BEGIN
    DECLARE v_total SMALLINT UNSIGNED;
    SELECT b.total_seats INTO v_total
    FROM trips t JOIN buses b ON b.bus_id = t.bus_id
    WHERE t.trip_id = NEW.trip_id;
    IF NEW.seat_number > v_total THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Invalid seat: seat number exceeds the bus seating capacity.';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `bookings`
--

DROP TABLE IF EXISTS `bookings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bookings` (
  `booking_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `trip_id` int(10) unsigned NOT NULL,
  `booking_reference` varchar(20) NOT NULL,
  `total_discount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `booking_date` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('PENDING','CONFIRMED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  `cancelled_by` enum('PASSENGER','ADMIN') DEFAULT NULL,
  PRIMARY KEY (`booking_id`),
  UNIQUE KEY `uq_bookings_reference` (`booking_reference`),
  UNIQUE KEY `uq_bookings_id_trip` (`booking_id`,`trip_id`),
  KEY `idx_bookings_user` (`user_id`),
  KEY `idx_bookings_trip` (`trip_id`),
  CONSTRAINT `fk_bookings_trip` FOREIGN KEY (`trip_id`) REFERENCES `trips` (`trip_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_bookings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bookings`
--

LOCK TABLES `bookings` WRITE;
/*!40000 ALTER TABLE `bookings` DISABLE KEYS */;
/*!40000 ALTER TABLE `bookings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `buses`
--

DROP TABLE IF EXISTS `buses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `buses` (
  `bus_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `bus_number` varchar(20) NOT NULL,
  `bus_type` varchar(80) NOT NULL,
  `total_seats` smallint(5) unsigned NOT NULL,
  `status` enum('ACTIVE','MAINTENANCE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  PRIMARY KEY (`bus_id`),
  UNIQUE KEY `uq_buses_number` (`bus_number`),
  CONSTRAINT `chk_buses_seats` CHECK (`total_seats` between 1 and 80)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `buses`
--

LOCK TABLES `buses` WRITE;
/*!40000 ALTER TABLE `buses` DISABLE KEYS */;
INSERT INTO `buses` VALUES (1,'PH-8012','Standard Regular Coach',40,'ACTIVE'),(2,'PH-5240','Deluxe / First Class Coach',32,'ACTIVE'),(3,'PH-3198','Ordinary Bus (Non-Aircon)',45,'ACTIVE'),(4,'PH-9421','Double-Decker Bus',60,'ACTIVE'),(5,'PH-7105','Standard Regular Coach',40,'ACTIVE'),(6,'PH-6382','Deluxe / First Class Coach',32,'ACTIVE');
/*!40000 ALTER TABLE `buses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `drivers`
--

DROP TABLE IF EXISTS `drivers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `drivers` (
  `driver_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `license_number` varchar(30) NOT NULL,
  `phone_number` varchar(20) NOT NULL,
  `license_expiry` date NOT NULL,
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  PRIMARY KEY (`driver_id`),
  UNIQUE KEY `uq_drivers_license` (`license_number`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `drivers`
--

LOCK TABLES `drivers` WRITE;
/*!40000 ALTER TABLE `drivers` DISABLE KEYS */;
INSERT INTO `drivers` VALUES (1,'Juan','Dela Cruz','N02-18-045921','09170000101','2030-12-31','ACTIVE'),(2,'Maria','Santos','N03-19-012847','09170000102','2030-12-31','ACTIVE'),(3,'Pedro','Reyes','N01-16-098234','09170000103','2030-12-31','ACTIVE'),(4,'Ana','Garcia','N04-20-034190','09170000104','2030-12-31','ACTIVE'),(5,'Eduardo','Mendoza','N01-17-062831','09170000105','2030-12-31','ACTIVE'),(6,'Ramon','Bautista','N03-21-075412','09170000106','2030-12-31','ACTIVE');
/*!40000 ALTER TABLE `drivers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `manual_payment_instructions`
--

DROP TABLE IF EXISTS `manual_payment_instructions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `manual_payment_instructions` (
  `payment_option` enum('GCASH','MAYA','MARIBANK','BANK_TRANSFER') NOT NULL,
  `account_name` varchar(120) NOT NULL DEFAULT '',
  `account_number` varchar(120) NOT NULL DEFAULT '',
  `instructions` text DEFAULT NULL,
  `qr_image_path` varchar(255) DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`payment_option`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `manual_payment_instructions`
--

LOCK TABLES `manual_payment_instructions` WRITE;
/*!40000 ALTER TABLE `manual_payment_instructions` DISABLE KEYS */;
INSERT INTO `manual_payment_instructions` VALUES ('GCASH','','',NULL,NULL,0,'2026-11-21 03:48:53'),('MAYA','','',NULL,NULL,0,'2026-11-21 03:48:53'),('MARIBANK','','',NULL,NULL,0,'2026-11-21 03:48:53'),('BANK_TRANSFER','','',NULL,NULL,0,'2026-11-21 03:48:53');
/*!40000 ALTER TABLE `manual_payment_instructions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payments` (
  `payment_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` int(10) unsigned NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` enum('CASH','E_WALLET','CARD','ONLINE','MANUAL_TRANSFER') NOT NULL,
  `online_payment_option` enum('GCASH','MAYA','MARIBANK','BANK_TRANSFER') DEFAULT NULL,
  `payment_status` enum('PENDING','PAID','FAILED','REFUND_REQUESTED','REFUNDED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  `payment_date` datetime NOT NULL DEFAULT current_timestamp(),
  `transfer_reference` varchar(100) DEFAULT NULL,
  `transfer_submitted_at` datetime DEFAULT NULL,
  `refund_payment_option` enum('GCASH','MAYA','MARIBANK','BANK_TRANSFER') DEFAULT NULL,
  `refund_account_name` varchar(120) DEFAULT NULL,
  `refund_account_number` varchar(120) DEFAULT NULL,
  `refund_transfer_reference` varchar(100) DEFAULT NULL,
  `refund_processed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`payment_id`),
  KEY `idx_payments_booking` (`booking_id`),
  KEY `idx_payments_date` (`payment_date`),
  CONSTRAINT `fk_payments_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`) ON UPDATE CASCADE,
  CONSTRAINT `chk_payments_amount` CHECK (`amount` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payments`
--

LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `routes`
--

DROP TABLE IF EXISTS `routes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `routes` (
  `route_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `origin` varchar(60) NOT NULL,
  `destination` varchar(60) NOT NULL,
  `distance_km` decimal(7,2) NOT NULL,
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  PRIMARY KEY (`route_id`),
  UNIQUE KEY `uq_routes_origin_dest` (`origin`,`destination`),
  CONSTRAINT `chk_routes_distance` CHECK (`distance_km` > 0),
  CONSTRAINT `chk_routes_different` CHECK (`origin` <> `destination`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `routes`
--

LOCK TABLES `routes` WRITE;
/*!40000 ALTER TABLE `routes` DISABLE KEYS */;
INSERT INTO `routes` VALUES (1,'Manila','Baguio City',250.00,'ACTIVE'),(2,'Baguio City','Manila',250.00,'ACTIVE'),(3,'Davao City','General Santos City',150.00,'ACTIVE'),(4,'General Santos City','Davao City',150.00,'ACTIVE'),(5,'Cebu City','Dumaguete City',170.00,'ACTIVE'),(6,'Dumaguete City','Cebu City',170.00,'ACTIVE'),(7,'Pagadian City','Zamboanga City',190.00,'ACTIVE'),(8,'Zamboanga City','Pagadian City',190.00,'ACTIVE'),(9,'Manila','Batangas Pier',110.00,'ACTIVE'),(10,'Batangas Pier','Manila',110.00,'ACTIVE');
/*!40000 ALTER TABLE `routes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tickets`
--

DROP TABLE IF EXISTS `tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tickets` (
  `ticket_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `booking_seat_id` int(10) unsigned NOT NULL,
  `ticket_number` varchar(20) NOT NULL,
  `issued_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ticket_id`),
  UNIQUE KEY `uq_tickets_seat` (`booking_seat_id`),
  UNIQUE KEY `uq_tickets_number` (`ticket_number`),
  CONSTRAINT `fk_tickets_seat` FOREIGN KEY (`booking_seat_id`) REFERENCES `booking_seats` (`booking_seat_id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tickets`
--

LOCK TABLES `tickets` WRITE;
/*!40000 ALTER TABLE `tickets` DISABLE KEYS */;
/*!40000 ALTER TABLE `tickets` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_tickets_confirmed_only
BEFORE INSERT ON tickets
FOR EACH ROW
BEGIN
    DECLARE v_booking_status VARCHAR(20);
    DECLARE v_seat_status    VARCHAR(20);
    SELECT b.status, bs.seat_status INTO v_booking_status, v_seat_status
    FROM booking_seats bs JOIN bookings b ON b.booking_id = bs.booking_id
    WHERE bs.booking_seat_id = NEW.booking_seat_id;
    IF v_booking_status <> 'CONFIRMED' OR v_seat_status <> 'BOOKED' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Ticket can only be issued for a confirmed booking with a booked seat.';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `trips`
--

DROP TABLE IF EXISTS `trips`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `trips` (
  `trip_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `bus_id` int(10) unsigned NOT NULL,
  `driver_id` int(10) unsigned NOT NULL,
  `route_id` int(10) unsigned NOT NULL,
  `departure_time` datetime NOT NULL,
  `arrival_time` datetime NOT NULL,
  `fare` decimal(10,2) NOT NULL,
  `status` enum('SCHEDULED','COMPLETED','CANCELLED') NOT NULL DEFAULT 'SCHEDULED',
  PRIMARY KEY (`trip_id`),
  KEY `idx_trips_departure` (`departure_time`),
  KEY `idx_trips_bus` (`bus_id`),
  KEY `idx_trips_driver` (`driver_id`),
  KEY `idx_trips_route` (`route_id`),
  CONSTRAINT `fk_trips_bus` FOREIGN KEY (`bus_id`) REFERENCES `buses` (`bus_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_trips_driver` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`driver_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_trips_route` FOREIGN KEY (`route_id`) REFERENCES `routes` (`route_id`) ON UPDATE CASCADE,
  CONSTRAINT `chk_trips_times` CHECK (`arrival_time` > `departure_time`),
  CONSTRAINT `chk_trips_fare` CHECK (`fare` >= 0)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `trips`
--

LOCK TABLES `trips` WRITE;
/*!40000 ALTER TABLE `trips` DISABLE KEYS */;
INSERT INTO `trips` VALUES (1,1,1,1,'2026-11-22 06:00:00','2026-11-22 11:30:00',750.00,'SCHEDULED'),(2,2,2,1,'2026-11-22 13:00:00','2026-11-22 18:30:00',850.00,'SCHEDULED'),(3,1,1,2,'2026-11-23 07:00:00','2026-11-23 12:30:00',750.00,'SCHEDULED'),(4,2,2,2,'2026-11-23 14:00:00','2026-11-23 19:30:00',850.00,'SCHEDULED'),(5,3,3,3,'2026-11-22 07:00:00','2026-11-22 10:30:00',450.00,'SCHEDULED'),(6,5,5,3,'2026-11-22 14:00:00','2026-11-22 17:30:00',450.00,'SCHEDULED'),(7,3,3,4,'2026-11-23 08:00:00','2026-11-23 11:30:00',450.00,'SCHEDULED'),(8,4,4,5,'2026-11-22 06:30:00','2026-11-22 11:30:00',650.00,'SCHEDULED'),(9,6,6,5,'2026-11-22 13:30:00','2026-11-22 18:30:00',700.00,'SCHEDULED'),(10,4,4,6,'2026-11-23 07:00:00','2026-11-23 12:00:00',650.00,'SCHEDULED'),(11,5,5,7,'2026-11-23 06:00:00','2026-11-23 12:00:00',600.00,'SCHEDULED'),(12,5,5,8,'2026-11-24 07:00:00','2026-11-24 13:00:00',600.00,'SCHEDULED'),(13,6,6,9,'2026-11-23 06:00:00','2026-11-23 08:30:00',350.00,'SCHEDULED'),(14,6,6,10,'2026-11-23 11:00:00','2026-11-23 13:30:00',350.00,'SCHEDULED');
/*!40000 ALTER TABLE `trips` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_trips_no_overlap_insert
BEFORE INSERT ON trips
FOR EACH ROW
BEGIN
    IF NEW.status <> 'CANCELLED' AND EXISTS (
        SELECT 1 FROM trips t
        WHERE t.status <> 'CANCELLED'
          AND (t.bus_id = NEW.bus_id OR t.driver_id = NEW.driver_id)
          AND NEW.departure_time < t.arrival_time
          AND NEW.arrival_time   > t.departure_time
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Schedule conflict: bus or driver already has a trip at that time.';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_trips_no_overlap_update
BEFORE UPDATE ON trips
FOR EACH ROW
BEGIN
    IF NEW.status <> 'CANCELLED' AND EXISTS (
        SELECT 1 FROM trips t
        WHERE t.trip_id <> NEW.trip_id
          AND t.status <> 'CANCELLED'
          AND (t.bus_id = NEW.bus_id OR t.driver_id = NEW.driver_id)
          AND NEW.departure_time < t.arrival_time
          AND NEW.arrival_time   > t.departure_time
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Schedule conflict: bus or driver already has a trip at that time.';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `user_otps`
--

DROP TABLE IF EXISTS `user_otps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_otps` (
  `otp_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `otp_code` varchar(10) NOT NULL,
  `purpose` enum('LOGIN','PAYMENT') NOT NULL,
  `reference_id` int(10) unsigned DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `is_used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`otp_id`),
  KEY `idx_user_otps_lookup` (`email`,`purpose`,`is_used`,`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_otps`
--

LOCK TABLES `user_otps` WRITE;
/*!40000 ALTER TABLE `user_otps` DISABLE KEYS */;
INSERT INTO `user_otps` VALUES (1,2,'velardejane67@gmail.com','969271','LOGIN',NULL,'2026-11-21 11:55:54',1,'2026-11-21 03:45:54'),(2,1,'admin@busticket.ph','306910','LOGIN',NULL,'2026-11-21 11:59:02',1,'2026-11-21 03:49:02');
/*!40000 ALTER TABLE `user_otps` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `user_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone_number` varchar(20) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('PASSENGER','ADMIN','SUPER_ADMIN') NOT NULL DEFAULT 'PASSENGER',
  `account_status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'System','Administrator','admin@busticket.ph','09170000001','$2y$10$UeFd4fQXrHvcI/6tuETWKeUdmJ.NvSQVWwNBgEqVYwHuExb4Mze4m','SUPER_ADMIN','ACTIVE','2026-11-21 03:42:44'),(2,'mimai','mayormita','velardejane67@gmail.com','09940138423','$2y$10$5vpmaIhDNDrTwJOVISm8eu5cm72lY4XRNIfBS3swqqLUTdhqBN6cG','PASSENGER','ACTIVE','2026-11-21 03:43:25');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-21 11:49:17
