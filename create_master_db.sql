CREATE TABLE IF NOT EXISTS events (
  event_name VARCHAR(100) PRIMARY KEY,
  db_name VARCHAR(100),
  db_host VARCHAR(100),
  db_user VARCHAR(100),
  db_pass VARCHAR(100),
  db_admin_user VARCHAR(100),
  db_admin_pass VARCHAR(100),
  create_sql TEXT
);

CREATE TABLE IF NOT EXISTS authorized_users (
  db_user VARCHAR(100) PRIMARY KEY,
  db_pass VARCHAR(100)
);

CREATE TABLE IF NOT EXISTS default_schema (
  id INT PRIMARY KEY CHECK (id = 1),
  create_sql TEXT
);
