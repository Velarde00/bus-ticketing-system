ALTER TABLE payments
    MODIFY payment_method ENUM('CASH','E_WALLET','CARD','ONLINE') NOT NULL,
    ADD COLUMN paymongo_checkout_id VARCHAR(100) NULL AFTER payment_date,
    ADD COLUMN paymongo_checkout_url VARCHAR(1000) NULL AFTER paymongo_checkout_id;
