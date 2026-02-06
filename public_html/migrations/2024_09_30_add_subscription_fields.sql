-- Adiciona campos mínimos para assinatura
ALTER TABLE lojas
    ADD COLUMN IF NOT EXISTS subscription_status VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS subscription_id VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS trial_until DATETIME NULL,
    ADD COLUMN IF NOT EXISTS paid_until DATETIME NULL;
