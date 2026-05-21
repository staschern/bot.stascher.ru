-- v0.7.1: цветовые метки на сделках (UX, не влияет на логику).
-- 6 значений: '' (без метки), 'yellow', 'green', 'red', 'blue', 'purple'.

ALTER TABLE trades ADD COLUMN color_label TEXT NOT NULL DEFAULT '';
