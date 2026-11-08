-- Replace provider checkout with manually verified transfer details.
ALTER TABLE payments
    MODIFY payment_method ENUM('CASH','E_WALLET','CARD','ONLINE','MANUAL_TRANSFER') NOT NULL,
    ADD COLUMN transfer_reference VARCHAR(100) NULL AFTER payment_date,
    ADD COLUMN transfer_submitted_at DATETIME NULL AFTER transfer_reference;

CREATE TABLE manual_payment_instructions (
    payment_option ENUM('GCASH','MAYA','MARIBANK','BANK_TRANSFER') NOT NULL,
    account_name VARCHAR(120) NOT NULL DEFAULT '',
    account_number VARCHAR(120) NOT NULL DEFAULT '',
    instructions TEXT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (payment_option)
) ENGINE=InnoDB;