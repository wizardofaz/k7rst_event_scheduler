CREATE TABLE IF NOT EXISTS events (
  event_name      VARCHAR(100) PRIMARY KEY,  
  description     VARCHAR(512) DEFAULT NULL, 
  developer_flag  VARCHAR(20) DEFAULT NULL,
  db_name         VARCHAR(100) NOT NULL,
  db_host         VARCHAR(100) NOT NULL,
  db_user         VARCHAR(100) NOT NULL,
  db_pass         VARCHAR(100) NOT NULL, 
  db_admin_user   VARCHAR(100) NOT NULL,
  db_admin_pass   VARCHAR(100) NOT NULL, 
  create_sql      MEDIUMTEXT             
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS authorized_users (
  db_user VARCHAR(100) PRIMARY KEY,
  db_pass VARCHAR(100)
);

CREATE TABLE IF NOT EXISTS default_schema (
  id INT PRIMARY KEY CHECK (id = 1),
  create_sql TEXT
);
