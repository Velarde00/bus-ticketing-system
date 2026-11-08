-- Store the passenger's selected manual transfer channel.
ALTER TABLE payments
    ADD COLUMN online_payment_option ENUM('GCASH','MAYA','MARIBANK','BANK_TRANSFER') NULL DEFAULT NULL
        AFTER payment_method;