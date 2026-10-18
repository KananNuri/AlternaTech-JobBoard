CREATE TABLE IF NOT EXISTS auth_rate_limits (
    bucket CHAR(64) CHARACTER SET ascii PRIMARY KEY,
    attempts INT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    INDEX (expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS account_tokens (
    token_hash CHAR(64) CHARACTER SET ascii PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    purpose ENUM('reset','verify') NOT NULL,
    email VARCHAR(150) NOT NULL,
    expires_at DATETIME NOT NULL,
    INDEX (user_id,purpose),
    CONSTRAINT fk_account_token_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mail_outbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient VARCHAR(150) NOT NULL,
    subject VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    INDEX (available_at,expires_at)
) ENGINE=InnoDB;
