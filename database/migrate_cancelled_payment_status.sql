-- Add a system-managed payment status for unpaid payments on cancelled reservations.
-- REFUNDED is retained only so existing historical rows remain valid.
ALTER TABLE payments
    MODIFY payment_status ENUM('PENDING','PAID','FAILED','REFUNDED','CANCELLED')
        NOT NULL DEFAULT 'PENDING';
