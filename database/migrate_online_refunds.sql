-- Store refund destinations and outgoing online transfer references.
ALTER TABLE payments
    ADD COLUMN refund_payment_option ENUM('GCASH','MAYA','MARIBANK','BANK_TRANSFER') NULL DEFAULT NULL AFTER transfer_submitted_at,
    ADD COLUMN refund_account_name VARCHAR(120) NULL AFTER refund_payment_option,
    ADD COLUMN refund_account_number VARCHAR(120) NULL AFTER refund_account_name,
    ADD COLUMN refund_transfer_reference VARCHAR(100) NULL AFTER refund_account_number,
    ADD COLUMN refund_processed_at DATETIME NULL AFTER refund_transfer_reference;