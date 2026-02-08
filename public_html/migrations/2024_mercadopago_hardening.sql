-- Mercado Pago hardening migration

ALTER TABLE invoices
    ADD COLUMN mp_preapproval_id VARCHAR(120) NULL AFTER mp_payment_id;

CREATE INDEX idx_invoices_mp_preapproval_id ON invoices (mp_preapproval_id);
CREATE UNIQUE INDEX uniq_invoices_mp_payment_id ON invoices (mp_payment_id);
CREATE UNIQUE INDEX uniq_invoices_mp_preapproval_id ON invoices (mp_preapproval_id);

CREATE TABLE IF NOT EXISTS webhook_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(120) NOT NULL,
    event_type VARCHAR(60) NOT NULL,
    action VARCHAR(120) NULL,
    resource_id VARCHAR(120) NOT NULL,
    payload JSON NULL,
    headers JSON NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_webhook_event (event_id, event_type),
    INDEX idx_webhook_resource (resource_id),
    INDEX idx_webhook_type (event_type)
);
