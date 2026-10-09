-- Optional color and size per product. Upload the same product name again with a
-- different color or size and customers can choose between them. Safe to re-run.
ALTER TABLE products ADD COLUMN IF NOT EXISTS color VARCHAR(40) NOT NULL DEFAULT '';
ALTER TABLE products ADD COLUMN IF NOT EXISTS size VARCHAR(30) NOT NULL DEFAULT '';
