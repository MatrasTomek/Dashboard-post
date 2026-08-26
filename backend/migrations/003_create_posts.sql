CREATE TABLE posts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  product_description TEXT NOT NULL,
  generated_caption TEXT NULL,
  final_caption TEXT NULL,
  media_filename VARCHAR(255) NULL,
  media_type ENUM('image','video') NULL,
  media_mime VARCHAR(100) NULL,
  status ENUM('draft','publishing','published','failed','partially_published') NOT NULL DEFAULT 'draft',

  fb_status ENUM('not_attempted','pending','success','failed') NOT NULL DEFAULT 'not_attempted',
  fb_post_id VARCHAR(128) NULL,
  fb_error TEXT NULL,
  fb_published_at DATETIME NULL,

  ig_status ENUM('not_attempted','pending','success','failed') NOT NULL DEFAULT 'not_attempted',
  ig_media_id VARCHAR(128) NULL,
  ig_container_id VARCHAR(128) NULL,
  ig_error TEXT NULL,
  ig_published_at DATETIME NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_posts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_posts_created_at (created_at),
  INDEX idx_posts_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
