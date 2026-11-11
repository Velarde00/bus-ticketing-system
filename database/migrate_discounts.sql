-- database/migrate_discounts.sql
-- Adds support for Student, Senior Citizen, and PWD 20% discounts

ALTER TABLE booking_seats 
    ADD COLUMN passenger_type ENUM('REGULAR','STUDENT','SENIOR','PWD') NOT NULL DEFAULT 'REGULAR' AFTER seat_number,
    ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER price,
    ADD COLUMN id_number VARCHAR(50) NULL DEFAULT NULL AFTER discount_amount;

ALTER TABLE bookings
    ADD COLUMN total_discount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER booking_date;
