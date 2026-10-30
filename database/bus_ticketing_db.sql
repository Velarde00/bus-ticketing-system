-- =====================================================================
-- BusTicket: Bus Ticketing and Reservation System
-- Phase 1: Database Schema + Sample Data
-- Database : bus_ticketing_db      Engine: MySQL 8.0+ (InnoDB)
-- Sample logins:
--   Admin     : admin@busticket.ph        / Admin@123
--   Passengers: (any passenger email)     / Password123
-- =====================================================================

DROP DATABASE IF EXISTS bus_ticketing_db;
CREATE DATABASE bus_ticketing_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE bus_ticketing_db;

-- ---------------------------------------------------------------------
-- TABLE 1: users
-- ---------------------------------------------------------------------
CREATE TABLE users (
    user_id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    first_name     VARCHAR(50)  NOT NULL,
    last_name      VARCHAR(50)  NOT NULL,
    email          VARCHAR(100) NOT NULL,
    phone_number   VARCHAR(20)  NOT NULL,
    password_hash  VARCHAR(255) NOT NULL,
    role           ENUM('PASSENGER','ADMIN')  NOT NULL DEFAULT 'PASSENGER',
    account_status ENUM('ACTIVE','INACTIVE')  NOT NULL DEFAULT 'ACTIVE',
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- TABLE 2: buses
-- ---------------------------------------------------------------------
CREATE TABLE buses (
    bus_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    bus_number  VARCHAR(20)  NOT NULL,
    bus_type    VARCHAR(30)  NOT NULL,
    total_seats SMALLINT UNSIGNED NOT NULL,
    status      ENUM('ACTIVE','MAINTENANCE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    PRIMARY KEY (bus_id),
    UNIQUE KEY uq_buses_number (bus_number),
    CONSTRAINT chk_buses_seats CHECK (total_seats BETWEEN 1 AND 80)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- TABLE 3: drivers
-- ---------------------------------------------------------------------
CREATE TABLE drivers (
    driver_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    first_name     VARCHAR(50)  NOT NULL,
    last_name      VARCHAR(50)  NOT NULL,
    license_number VARCHAR(30)  NOT NULL,
    phone_number   VARCHAR(20)  NOT NULL,
    license_expiry DATE         NOT NULL,
    status         ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    PRIMARY KEY (driver_id),
    UNIQUE KEY uq_drivers_license (license_number)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- TABLE 4: routes
-- ---------------------------------------------------------------------
CREATE TABLE routes (
    route_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    origin      VARCHAR(60)  NOT NULL,
    destination VARCHAR(60)  NOT NULL,
    distance_km DECIMAL(7,2) NOT NULL,
    status      ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    PRIMARY KEY (route_id),
    UNIQUE KEY uq_routes_origin_dest (origin, destination),
    CONSTRAINT chk_routes_distance CHECK (distance_km > 0),
    CONSTRAINT chk_routes_different CHECK (origin <> destination)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- TABLE 5: trips
-- ---------------------------------------------------------------------
CREATE TABLE trips (
    trip_id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    bus_id         INT UNSIGNED NOT NULL,
    driver_id      INT UNSIGNED NOT NULL,
    route_id       INT UNSIGNED NOT NULL,
    departure_time DATETIME     NOT NULL,
    arrival_time   DATETIME     NOT NULL,
    fare           DECIMAL(10,2) NOT NULL,
    status         ENUM('SCHEDULED','COMPLETED','CANCELLED') NOT NULL DEFAULT 'SCHEDULED',
    PRIMARY KEY (trip_id),
    KEY idx_trips_departure (departure_time),
    KEY idx_trips_bus (bus_id),
    KEY idx_trips_driver (driver_id),
    KEY idx_trips_route (route_id),
    CONSTRAINT fk_trips_bus    FOREIGN KEY (bus_id)    REFERENCES buses(bus_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_trips_driver FOREIGN KEY (driver_id) REFERENCES drivers(driver_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_trips_route  FOREIGN KEY (route_id)  REFERENCES routes(route_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_trips_times CHECK (arrival_time > departure_time),
    CONSTRAINT chk_trips_fare  CHECK (fare >= 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- TABLE 6: bookings
-- ---------------------------------------------------------------------
CREATE TABLE bookings (
    booking_id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id           INT UNSIGNED NOT NULL,
    trip_id           INT UNSIGNED NOT NULL,
    booking_reference VARCHAR(20)  NOT NULL,
    booking_date      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status            ENUM('PENDING','CONFIRMED','CANCELLED') NOT NULL DEFAULT 'PENDING',
    PRIMARY KEY (booking_id),
    UNIQUE KEY uq_bookings_reference (booking_reference),
    -- Helper key so booking_seats can prove its trip_id matches the booking's trip_id
    UNIQUE KEY uq_bookings_id_trip (booking_id, trip_id),
    KEY idx_bookings_user (user_id),
    KEY idx_bookings_trip (trip_id),
    CONSTRAINT fk_bookings_user FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_bookings_trip FOREIGN KEY (trip_id) REFERENCES trips(trip_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- TABLE 7: booking_seats
-- active_seat is an automatic (generated) column:
--   = seat_number while the seat is BOOKED, NULL once it is CANCELLED.
-- UNIQUE(trip_id, active_seat) therefore blocks double booking of a seat,
-- but lets a cancelled seat be booked again (MySQL allows many NULLs).
-- ---------------------------------------------------------------------
CREATE TABLE booking_seats (
    booking_seat_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id      INT UNSIGNED NOT NULL,
    trip_id         INT UNSIGNED NOT NULL,
    seat_number     SMALLINT UNSIGNED NOT NULL,
    price           DECIMAL(10,2) NOT NULL,
    seat_status     ENUM('BOOKED','CANCELLED') NOT NULL DEFAULT 'BOOKED',
    active_seat     SMALLINT UNSIGNED
        GENERATED ALWAYS AS (CASE WHEN seat_status = 'BOOKED' THEN seat_number ELSE NULL END) STORED,
    PRIMARY KEY (booking_seat_id),
    UNIQUE KEY uq_trip_active_seat (trip_id, active_seat),
    KEY idx_bs_booking (booking_id),
    CONSTRAINT fk_bs_booking FOREIGN KEY (booking_id, trip_id)
        REFERENCES bookings(booking_id, trip_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_bs_seat  CHECK (seat_number >= 1),
    CONSTRAINT chk_bs_price CHECK (price >= 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- TABLE 8: payments
-- ---------------------------------------------------------------------
CREATE TABLE payments (
    payment_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id     INT UNSIGNED NOT NULL,
    amount         DECIMAL(10,2) NOT NULL,
    payment_method ENUM('CASH','E_WALLET','CARD') NOT NULL,
    payment_status ENUM('PENDING','PAID','FAILED','REFUNDED') NOT NULL DEFAULT 'PENDING',
    payment_date   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (payment_id),
    KEY idx_payments_booking (booking_id),
    KEY idx_payments_date (payment_date),
    CONSTRAINT fk_payments_booking FOREIGN KEY (booking_id) REFERENCES bookings(booking_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_payments_amount CHECK (amount >= 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- TABLE 9: tickets  (one ticket per booked seat -> UNIQUE booking_seat_id)
-- ---------------------------------------------------------------------
CREATE TABLE tickets (
    ticket_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_seat_id INT UNSIGNED NOT NULL,
    ticket_number   VARCHAR(20)  NOT NULL,
    issued_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ticket_id),
    UNIQUE KEY uq_tickets_seat (booking_seat_id),
    UNIQUE KEY uq_tickets_number (ticket_number),
    CONSTRAINT fk_tickets_seat FOREIGN KEY (booking_seat_id) REFERENCES booking_seats(booking_seat_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- =====================================================================
-- TRIGGERS (business rules MySQL cannot express with plain constraints)
-- =====================================================================
DELIMITER $$

-- Rule: a bus or driver cannot have overlapping (non-cancelled) trips
CREATE TRIGGER trg_trips_no_overlap_insert
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
END$$

CREATE TRIGGER trg_trips_no_overlap_update
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
END$$

-- Rule: seat number cannot exceed the bus capacity
CREATE TRIGGER trg_booking_seats_capacity_insert
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
END$$

CREATE TRIGGER trg_booking_seats_capacity_update
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
END$$

-- Rule: tickets are issued only for CONFIRMED bookings and BOOKED seats
CREATE TRIGGER trg_tickets_confirmed_only
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
END$$

DELIMITER ;

-- =====================================================================
-- SAMPLE DATA
-- =====================================================================

-- users: 1 admin (Admin@123) + 5 passengers (Password123)
INSERT INTO users (user_id, first_name, last_name, email, phone_number, password_hash, role, account_status) VALUES
(1, 'System',  'Administrator', 'admin@busticket.ph',    '09170000001', '$2y$10$1.vCs.yuqWNRrcSQ.KwP6ektzjVUvhbBqR2g4p4dEcBmiKcpalYCS', 'ADMIN',     'ACTIVE'),
(2, 'Juan',    'Dela Cruz',     'juan.delacruz@email.com',  '09171234501', '$2y$10$lJ5ziHGuqRSw0i8aAyZBq.v3AVlxB/T95tFndcpePfRP40VUk.uAK', 'PASSENGER', 'ACTIVE'),
(3, 'Maria',   'Santos',        'maria.santos@email.com',   '09171234502', '$2y$10$lJ5ziHGuqRSw0i8aAyZBq.v3AVlxB/T95tFndcpePfRP40VUk.uAK', 'PASSENGER', 'ACTIVE'),
(4, 'Jose',    'Reyes',         'jose.reyes@email.com',     '09171234503', '$2y$10$lJ5ziHGuqRSw0i8aAyZBq.v3AVlxB/T95tFndcpePfRP40VUk.uAK', 'PASSENGER', 'ACTIVE'),
(5, 'Ana',     'Garcia',        'ana.garcia@email.com',     '09171234504', '$2y$10$lJ5ziHGuqRSw0i8aAyZBq.v3AVlxB/T95tFndcpePfRP40VUk.uAK', 'PASSENGER', 'ACTIVE'),
(6, 'Mark',    'Villanueva',    'mark.villanueva@email.com','09171234505', '$2y$10$lJ5ziHGuqRSw0i8aAyZBq.v3AVlxB/T95tFndcpePfRP40VUk.uAK', 'PASSENGER', 'ACTIVE');

-- buses
INSERT INTO buses (bus_id, bus_number, bus_type, total_seats, status) VALUES
(1, 'ZBL-1001', 'Deluxe',        40, 'ACTIVE'),
(2, 'ZBL-1002', 'Airconditioned',45, 'ACTIVE'),
(3, 'DVO-2001', 'Deluxe',        40, 'ACTIVE'),
(4, 'DVO-2002', 'Ordinary',      50, 'ACTIVE'),
(5, 'MNL-3001', 'Premium Deluxe',36, 'ACTIVE');

-- drivers (driver 4's license expires soon -> good for the expiry check)
INSERT INTO drivers (driver_id, first_name, last_name, license_number, phone_number, license_expiry, status) VALUES
(1, 'Ramon',    'Aquino',    'N02-12-345678', '09181111001', '2028-05-20', 'ACTIVE'),
(2, 'Eduardo',  'Mendoza',   'N03-14-456789', '09181111002', '2027-09-15', 'ACTIVE'),
(3, 'Roberto',  'Castillo',  'N04-15-567890', '09181111003', '2029-01-10', 'ACTIVE'),
(4, 'Antonio',  'Bautista',  'N05-16-678901', '09181111004', '2026-12-15', 'ACTIVE'),
(5, 'Fernando', 'Ramirez',   'N06-17-789012', '09181111005', '2030-03-30', 'ACTIVE');

-- routes
INSERT INTO routes (route_id, origin, destination, distance_km, status) VALUES
(1, 'Zamboanga City', 'Pagadian City',          190.00, 'ACTIVE'),
(2, 'Zamboanga City', 'Dipolog City',           280.00, 'ACTIVE'),
(3, 'Davao City',     'Cagayan de Oro City',    270.00, 'ACTIVE'),
(4, 'Davao City',     'General Santos City',    150.00, 'ACTIVE'),
(5, 'Manila',         'Baguio City',            250.00, 'ACTIVE');

-- trips (10, no bus/driver overlaps)
INSERT INTO trips (trip_id, bus_id, driver_id, route_id, departure_time, arrival_time, fare, status) VALUES
(1,  1, 1, 1, '2026-11-02 06:00:00', '2026-11-02 11:00:00', 450.00, 'SCHEDULED'),
(2,  2, 2, 2, '2026-11-02 07:00:00', '2026-11-02 14:00:00', 650.00, 'SCHEDULED'),
(3,  3, 3, 3, '2026-11-03 06:00:00', '2026-11-03 13:00:00', 700.00, 'SCHEDULED'),
(4,  4, 4, 4, '2026-11-03 08:00:00', '2026-11-03 11:30:00', 320.00, 'SCHEDULED'),
(5,  5, 5, 5, '2026-11-03 22:00:00', '2026-11-04 04:00:00', 800.00, 'SCHEDULED'),
(6,  1, 1, 1, '2026-11-04 06:00:00', '2026-11-04 11:00:00', 450.00, 'SCHEDULED'),
(7,  2, 2, 2, '2026-11-05 07:00:00', '2026-11-05 14:00:00', 650.00, 'SCHEDULED'),
(8,  3, 3, 3, '2026-11-05 06:00:00', '2026-11-05 13:00:00', 700.00, 'SCHEDULED'),
(9,  4, 4, 4, '2026-11-06 08:00:00', '2026-11-06 11:30:00', 320.00, 'SCHEDULED'),
(10, 5, 5, 5, '2026-11-06 22:00:00', '2026-11-07 04:00:00', 800.00, 'SCHEDULED');

-- bookings
INSERT INTO bookings (booking_id, user_id, trip_id, booking_reference, booking_date, status) VALUES
(1, 2, 1, 'BT-2026-000001', '2026-10-25 09:15:00', 'CONFIRMED'),
(2, 3, 1, 'BT-2026-000002', '2026-10-26 10:30:00', 'PENDING'),
(3, 4, 3, 'BT-2026-000003', '2026-10-26 14:05:00', 'CONFIRMED'),
(4, 5, 4, 'BT-2026-000004', '2026-10-27 08:45:00', 'CANCELLED'),
(5, 6, 5, 'BT-2026-000005', '2026-10-27 16:20:00', 'CONFIRMED'),
(6, 2, 2, 'BT-2026-000006', '2026-10-28 11:00:00', 'PENDING'),
(7, 2, 4, 'BT-2026-000007', '2026-10-28 13:40:00', 'CONFIRMED');

-- booking_seats (seat 12 on trip 4 was cancelled, then re-booked by booking 7)
INSERT INTO booking_seats (booking_seat_id, booking_id, trip_id, seat_number, price, seat_status) VALUES
(1,  1, 1,  5, 450.00, 'BOOKED'),
(2,  1, 1,  6, 450.00, 'BOOKED'),
(3,  2, 1, 10, 450.00, 'BOOKED'),
(4,  3, 3,  1, 700.00, 'BOOKED'),
(5,  4, 4, 12, 320.00, 'CANCELLED'),
(6,  5, 5,  3, 800.00, 'BOOKED'),
(7,  5, 5,  4, 800.00, 'BOOKED'),
(8,  6, 2, 20, 650.00, 'BOOKED'),
(9,  7, 4, 12, 320.00, 'BOOKED');

-- payments
INSERT INTO payments (payment_id, booking_id, amount, payment_method, payment_status, payment_date) VALUES
(1, 1,  900.00, 'CASH',     'PAID',     '2026-10-25 09:30:00'),
(2, 2,  450.00, 'E_WALLET', 'PENDING',  '2026-10-26 10:35:00'),
(3, 3,  700.00, 'CARD',     'PAID',     '2026-10-26 14:10:00'),
(4, 4,  320.00, 'E_WALLET', 'REFUNDED', '2026-10-27 09:30:00'),
(5, 5, 1600.00, 'E_WALLET', 'PAID',     '2026-10-27 16:30:00'),
(6, 6,  650.00, 'CASH',     'PENDING',  '2026-10-28 11:05:00'),
(7, 7,  320.00, 'CASH',     'PAID',     '2026-10-28 13:50:00');

-- tickets (only for confirmed bookings)
INSERT INTO tickets (ticket_id, booking_seat_id, ticket_number, issued_at) VALUES
(1, 1, 'TKT-2026-000001', '2026-10-25 09:35:00'),
(2, 2, 'TKT-2026-000002', '2026-10-25 09:35:00'),
(3, 4, 'TKT-2026-000003', '2026-10-26 14:15:00'),
(4, 6, 'TKT-2026-000004', '2026-10-27 16:35:00'),
(5, 7, 'TKT-2026-000005', '2026-10-27 16:35:00'),
(6, 9, 'TKT-2026-000006', '2026-10-28 13:55:00');
