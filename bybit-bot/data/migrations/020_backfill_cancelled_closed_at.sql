-- Бэкфилл closed_at для уже существующих CANCELLED/CLOSED трейдов без даты закрытия.
-- Используем created_at как аппроксимацию (лучше чем NULL).
UPDATE trades
SET closed_at = created_at
WHERE closed_at IS NULL
  AND status IN ('CANCELLED', 'CLOSED_PROFIT', 'CLOSED_LOSS');
