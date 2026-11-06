-- Upgrade an existing BusTicket database without dropping operational data.
-- Promotes the current initial administrator to SUPER_ADMIN.
ALTER TABLE users
    MODIFY role ENUM('PASSENGER','ADMIN','SUPER_ADMIN') NOT NULL DEFAULT 'PASSENGER';

UPDATE users
SET role = 'SUPER_ADMIN'
WHERE email = 'admin@busticket.ph' AND role = 'ADMIN';
