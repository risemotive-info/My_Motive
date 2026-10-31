-- Public announcements: the platform admin writes them, every person who uses MY MOTIVE sees them.
-- The tables live in the PLATFORM database, so one announcement reaches every business.
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS announcements (
  id              INT NOT NULL AUTO_INCREMENT,
  title           VARCHAR(160) NOT NULL,
  body            TEXT NOT NULL,
  level           ENUM('info','success','warning','important') NOT NULL DEFAULT 'info',
  audience        ENUM('everyone','admins') NOT NULL DEFAULT 'everyone',   -- admins = the Admin of each business only
  link_url        VARCHAR(300) NULL,
  link_label      VARCHAR(40)  NULL,
  status          ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  starts_at       DATETIME NULL,                                           -- NULL = as soon as it is published
  ends_at         DATETIME NULL,                                           -- NULL = until it is archived
  published_at    DATETIME NULL,
  created_by      INT NULL,
  created_by_name VARCHAR(150) NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_announcement_live (status, starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- who has read which announcement. A user id is only unique INSIDE one business, so the business is part of the key.
CREATE TABLE IF NOT EXISTS announcement_reads (
  announcement_id INT NOT NULL,
  company_id      INT NOT NULL,
  user_id         INT NOT NULL,
  read_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (announcement_id, company_id, user_id),
  KEY idx_announcement_reads_user (company_id, user_id),
  CONSTRAINT announcement_reads_fk FOREIGN KEY (announcement_id) REFERENCES announcements (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
