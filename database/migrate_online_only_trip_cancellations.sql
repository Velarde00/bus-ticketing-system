-- Track passenger choices when a trip is cancelled and support refund requests.
ALTER TABLE bookings
    ADD COLUMN cancellation_choice ENUM('REFUND_REQUESTED','REBOOK_REQUESTED') NULL DEFAULT NULL
        AFTER status;

ALTER TABLE payments
    MODIFY payment_status ENUM('PENDING','PAID','FAILED','REFUND_REQUESTED','REFUNDED','CANCELLED')
        NOT NULL DEFAULT 'PENDING';