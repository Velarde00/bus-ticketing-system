-- =====================================================================
-- BusTicket: Bus Ticketing and Reservation System
-- Database schema and initial administrator account
-- Database : bus_ticketing_db      Engine: MySQL 8.0+ (InnoDB)
-- Initial administrator: admin@busticket.ph / Admin@123
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
    role           ENUM('PASSENGER','ADMIN','SUPER_ADMIN') NOT NULL DEFAULT 'PASSENGER',
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
    bus_type    VARCHAR(80)  NOT NULL,
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
    payment_method ENUM('CASH','E_WALLET','CARD','ONLINE') NOT NULL,
    payment_status ENUM('PENDING','PAID','FAILED','CANCELLED') NOT NULL DEFAULT 'PENDING',
    payment_date   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paymongo_checkout_id  VARCHAR(100) NULL,
    paymongo_checkout_url VARCHAR(1000) NULL,
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

-- INITIAL ADMINISTRATOR
INSERT INTO users (user_id, first_name, last_name, email, phone_number, password_hash, role, account_status) VALUES
(1, 'System', 'Administrator', 'admin@busticket.ph', '09170000001', '$2y$10$UeFd4fQXrHvcI/6tuETWKeUdmJ.NvSQVWwNBgEqVYwHuExb4Mze4m', 'SUPER_ADMIN', 'ACTIVE');
