-- Support tickets: a business raises a ticket from its dashboard, the platform admin answers it from the admin portal.
-- The tables live in the PLATFORM database, so the admin sees the tickets of every business in one place.
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS support_tickets (
  id                INT NOT NULL AUTO_INCREMENT,
  reference         VARCHAR(20)  NOT NULL,                       -- what people quote: TK-000012
  company_id        INT NOT NULL,
  company_code      VARCHAR(30)  NOT NULL,                       -- copied, so the ticket still says who wrote it if a business is deleted
  company_name      VARCHAR(190) NOT NULL,
  company_user_id   INT NOT NULL,                                -- the person's id in THEIR business's own database
  user_name         VARCHAR(150) NOT NULL,
  user_role         VARCHAR(30)  NULL,
  contact_email     VARCHAR(190) NULL,
  contact_phone     VARCHAR(30)  NULL,
  category          VARCHAR(30)  NOT NULL DEFAULT 'other',
  priority          ENUM('normal','high','urgent') NOT NULL DEFAULT 'normal',
  subject           VARCHAR(160) NOT NULL,
  page_hint         VARCHAR(200) NULL,                           -- "which page were you on?"
  status            ENUM('open','in_progress','waiting_customer','resolved','closed') NOT NULL DEFAULT 'open',
  assigned_admin_id INT NULL,
  company_unread    TINYINT(1) NOT NULL DEFAULT 0,               -- the platform answered and the business has not read it yet
  platform_unread   TINYINT(1) NOT NULL DEFAULT 1,               -- the business wrote and no admin has read it yet
  last_activity_at  DATETIME NOT NULL,
  resolved_at       DATETIME NULL,
  closed_at         DATETIME NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_support_reference (reference),
  KEY idx_support_company (company_id, last_activity_at),
  KEY idx_support_status (status, last_activity_at),
  KEY idx_support_unread (platform_unread, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS support_ticket_messages (
  id          INT NOT NULL AUTO_INCREMENT,
  ticket_id   INT NOT NULL,
  author_type ENUM('company','platform') NOT NULL,
  author_id   INT NULL,
  author_name VARCHAR(150) NOT NULL,
  body        TEXT NOT NULL,
  is_internal TINYINT(1) NOT NULL DEFAULT 0,                     -- a note that only platform admins can see
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_support_msg_ticket (ticket_id, id),
  CONSTRAINT support_msg_ticket_fk FOREIGN KEY (ticket_id) REFERENCES support_tickets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
