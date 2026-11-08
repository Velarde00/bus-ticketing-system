-- Store an admin-uploaded payment QR image for each transfer destination.
ALTER TABLE manual_payment_instructions
    ADD COLUMN qr_image_path VARCHAR(255) NULL AFTER instructions;