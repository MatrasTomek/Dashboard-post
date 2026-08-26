CREATE TABLE connected_social_accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  fb_page_id VARCHAR(64) NOT NULL,
  fb_page_name VARCHAR(255) NULL,
  fb_page_access_token_encrypted TEXT NOT NULL,
  fb_token_expires_at DATETIME NULL,
  ig_business_account_id VARCHAR(64) NULL,
  ig_username VARCHAR(255) NULL,
  meta_user_id VARCHAR(64) NULL,
  connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_refreshed_at DATETIME NULL,
  status ENUM('active','expired','error') NOT NULL DEFAULT 'active',
  last_error TEXT NULL,
  CONSTRAINT fk_social_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
