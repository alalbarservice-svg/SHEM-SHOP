CREATE DATABASE IF NOT EXISTS wp_shem CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS wp_shimi CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL ON wp_shem.* TO 'wp'@'%'; GRANT ALL ON wp_shimi.* TO 'wp'@'%';
