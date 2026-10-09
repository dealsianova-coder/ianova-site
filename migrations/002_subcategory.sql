-- Adds an optional sub-category (e.g. Men, Women) to products. Safe to re-run.
ALTER TABLE products ADD COLUMN IF NOT EXISTS subcategory VARCHAR(60) NOT NULL DEFAULT '' AFTER category_id;
