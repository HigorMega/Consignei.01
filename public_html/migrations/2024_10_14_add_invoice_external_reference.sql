ALTER TABLE invoices
    ADD COLUMN external_reference VARCHAR(120) NULL,
    ADD INDEX idx_invoices_external_reference (external_reference);
