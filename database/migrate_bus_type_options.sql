-- Expand bus type labels to accommodate the full bus classification options.
ALTER TABLE buses
    MODIFY bus_type VARCHAR(80) NOT NULL;
