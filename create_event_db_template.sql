-- Event Scheduler Database Template

-- Table: event_config
CREATE TABLE IF NOT EXISTS event_config (
    name VARCHAR(50) PRIMARY KEY,
    value TEXT NOT NULL
);

-- Table: operator_passwords
CREATE TABLE IF NOT EXISTS operator_passwords (
    id INT AUTO_INCREMENT PRIMARY KEY,
    op_call VARCHAR(20) NOT NULL UNIQUE,
    op_password VARCHAR(20) NOT NULL
);

-- Table: schedule
CREATE TABLE IF NOT EXISTS schedule (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date DATE NOT NULL,
    time TIME NOT NULL,
    op_call VARCHAR(20) NOT NULL,
    op_name VARCHAR(50) NOT NULL,
    band VARCHAR(10) NOT NULL,
    mode VARCHAR(10) NOT NULL,
    club_station VARCHAR(50) DEFAULT '',
    assigned_call VARCHAR(20) DEFAULT '',
    notes VARCHAR(200) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_operator_per_hour (date, time, op_call)
);