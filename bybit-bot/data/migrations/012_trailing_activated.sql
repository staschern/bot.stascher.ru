-- v0.8.0.10: отметка момента активации trailing-стопа.
--
-- trailing_activated_at заполняется реконсайлером (paper и live) в момент,
-- когда цена впервые пересекает trailing_trigger_price позиции.
-- После активации pos.sl_price ползёт вслед за ценой (существующая логика),
-- и UI использует sl_price как «текущий уровень trailing-стопа».
--
-- Семантика: NULL = trailing ещё не активирован (цена не дошла до trigger);
-- не-NULL = trailing активен (sl_price = trailing stop level).

ALTER TABLE trades ADD COLUMN trailing_activated_at TEXT NULL;
