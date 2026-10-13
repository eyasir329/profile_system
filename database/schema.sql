CREATE DATABASE IF NOT EXISTS profile_system
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE profile_system;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('user', 'admin') NOT NULL DEFAULT 'user', -- legacy; backfilled into user_roles by 004, dropped by 016
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS profiles (
    user_id INT PRIMARY KEY,
    phone VARCHAR(20) NULL,
    address TEXT NULL,
    profession VARCHAR(100) NULL,
    bio TEXT NULL,
    photo VARCHAR(255) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_profile_user FOREIGN KEY (user_id)
      REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
