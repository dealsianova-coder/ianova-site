-- Banners are also created automatically the first time admin/banners.php opens.
CREATE TABLE IF NOT EXISTS banners (
  id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(120) NOT NULL, subtitle VARCHAR(160) NOT NULL DEFAULT '',
  button_label VARCHAR(40) NOT NULL DEFAULT 'Shop now', image VARCHAR(255) NOT NULL DEFAULT '',
  link_type VARCHAR(20) NOT NULL DEFAULT 'none', link_value VARCHAR(255) NOT NULL DEFAULT '',
  color VARCHAR(20) NOT NULL DEFAULT 'blush', sort_order INT NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
