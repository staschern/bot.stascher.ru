-- v0.7.0 Block B+: safety-зазор для расчёта qty (§5.4).
-- Также фиксирует исправление формулы movement_coef в §6.3 (изменения только в коде).

INSERT OR IGNORE INTO settings (key, value, updated_at)
VALUES ('qty_safety_margin_pct', '10.0', CURRENT_TIMESTAMP);
