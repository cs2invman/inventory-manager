-- Initial MySQL setup for CS2 Inventory Application

-- Create database if it doesn't exist
CREATE DATABASE IF NOT EXISTS cs2inventory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Use the database
USE cs2inventory;

-- Grant privileges to the application user
GRANT ALL PRIVILEGES ON cs2inventory.* TO 'cs2inventory'@'%';
FLUSH PRIVILEGES;

-- Set timezone to UTC
SET time_zone = '+00:00';