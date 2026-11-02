-- =====================================================================
-- GrazeX — Feeds & Ranch
-- install.sql  (schema + seed data)
--
-- HOW TO USE (Xneelo / phpMyAdmin):
--   1. Create an EMPTY database in the control panel (Manage MySQL > Add).
--   2. Open phpMyAdmin > select that database > SQL tab.
--   3. Paste this whole file and click "Go".
--   4. Browse to https://<your-domain>/setup/ to create the owner account.
--
-- Target: MariaDB 10.4+ (Xneelo runs 10.11) and MySQL 8.0.16+.
-- Money is stored as INTEGER CENTS (R 123.45 = 12345). Prices are VAT-inclusive.
-- VAT rates are stored in basis points (15% = 1500).
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+02:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. CORE
-- ---------------------------------------------------------------------

CREATE TABLE divisions (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code                VARCHAR(20)  NOT NULL,
  name                VARCHAR(100) NOT NULL,
  tagline             VARCHAR(255) NULL,
  legal_name          VARCHAR(191) NULL,
  registration_no     VARCHAR(50)  NULL,
  vat_registered      TINYINT(1)   NOT NULL DEFAULT 0,
  vat_no              VARCHAR(20)  NULL,
  address             TEXT         NULL,
  phone               VARCHAR(30)  NULL,
  email               VARCHAR(191) NULL,
  whatsapp            VARCHAR(30)  NULL,
  bank_name           VARCHAR(100) NULL,
  bank_account_name   VARCHAR(150) NULL,
  bank_account_no     VARCHAR(40)  NULL,
  bank_branch_code    VARCHAR(20)  NULL,
  bank_account_type   VARCHAR(40)  NULL,
  order_prefix        VARCHAR(10)  NOT NULL,
  invoice_prefix      VARCHAR(15)  NOT NULL,
  credit_prefix       VARCHAR(15)  NOT NULL,
  receipt_prefix      VARCHAR(15)  NOT NULL,
  logo_path           VARCHAR(255) NULL,
  document_footer     TEXT         NULL,
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_divisions_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
  setting_key    VARCHAR(100) NOT NULL,
  setting_value  LONGTEXT     NULL,
  value_type     ENUM('string','int','bool','text','json') NOT NULL DEFAULT 'string',
  group_name     VARCHAR(50)  NOT NULL DEFAULT 'general',
  label          VARCHAR(150) NULL,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key),
  KEY idx_settings_group (group_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vat_rates (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  rate_bp         INT UNSIGNED NOT NULL,
  effective_from  DATE         NOT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vat_rates_from (effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  bucket         CHAR(64)     NOT NULL,
  action         VARCHAR(50)  NOT NULL,
  hits           INT UNSIGNED NOT NULL DEFAULT 0,
  window_start   DATETIME     NOT NULL,
  blocked_until  DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rate_limits_bucket (bucket),
  KEY idx_rate_limits_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_type   ENUM('admin','customer','system') NOT NULL,
  actor_id     INT UNSIGNED NULL,
  action       VARCHAR(100) NOT NULL,
  entity_type  VARCHAR(50)  NULL,
  entity_id    INT UNSIGNED NULL,
  details      LONGTEXT     NULL,
  ip           VARCHAR(45)  NULL,
  user_agent   VARCHAR(255) NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_entity (entity_type, entity_id),
  KEY idx_audit_actor (actor_type, actor_id),
  KEY idx_audit_created (created_at),
  CONSTRAINT chk_audit_details CHECK (details IS NULL OR JSON_VALID(details))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_templates (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  template_key  VARCHAR(60)  NOT NULL,
  name          VARCHAR(150) NOT NULL,
  subject       VARCHAR(255) NOT NULL,
  body          TEXT         NOT NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_email_templates_key (template_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_queue (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  division_id  INT UNSIGNED NULL,
  to_email     VARCHAR(191) NOT NULL,
  to_name      VARCHAR(150) NULL,
  subject      VARCHAR(255) NOT NULL,
  body_html    MEDIUMTEXT   NOT NULL,
  body_text    MEDIUMTEXT   NULL,
  attachments  LONGTEXT     NULL,
  status       ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error   VARCHAR(500) NULL,
  send_after   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at      DATETIME     NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_email_queue_status (status, send_after),
  KEY idx_email_queue_sent (sent_at),
  CONSTRAINT fk_email_queue_division FOREIGN KEY (division_id) REFERENCES divisions (id) ON DELETE SET NULL,
  CONSTRAINT chk_email_queue_attachments CHECK (attachments IS NULL OR JSON_VALID(attachments))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_notifications (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  division_id  INT UNSIGNED NULL,
  type         VARCHAR(50)  NOT NULL,
  title        VARCHAR(191) NOT NULL,
  body         VARCHAR(500) NULL,
  link         VARCHAR(255) NULL,
  is_read      TINYINT(1)   NOT NULL DEFAULT 0,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_admin_notifications_read (is_read, created_at),
  CONSTRAINT fk_admin_notifications_division FOREIGN KEY (division_id) REFERENCES divisions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which database/migrations/*.sql files are already applied (install.sql includes them all)
CREATE TABLE schema_migrations (
  version     VARCHAR(100) NOT NULL,
  applied_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version) VALUES ('001_install'), ('002_schema_migrations'), ('003_money_fixes'), ('004_security'), ('005_checkout');

-- ---------------------------------------------------------------------
-- 2. ACCOUNTS (customers + admin users are separate on purpose)
-- ---------------------------------------------------------------------

CREATE TABLE customers (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email                  VARCHAR(191) NOT NULL,
  password_hash          VARCHAR(255) NOT NULL,
  first_name             VARCHAR(100) NOT NULL,
  last_name              VARCHAR(100) NOT NULL,
  phone                  VARCHAR(30)  NULL,
  farm_name              VARCHAR(150) NULL,
  company_name           VARCHAR(150) NULL,
  vat_no                 VARCHAR(20)  NULL,
  vat_farmer_status      ENUM('none','pending','verified','rejected') NOT NULL DEFAULT 'none',
  vat_farmer_doc_path    VARCHAR(255) NULL,
  vat_farmer_verified_at DATETIME     NULL,
  email_verified_at      DATETIME     NULL,
  marketing_opt_in       TINYINT(1)   NOT NULL DEFAULT 0,
  marketing_opt_in_at    DATETIME     NULL,
  status                 ENUM('active','blocked') NOT NULL DEFAULT 'active',
  admin_notes            TEXT         NULL,
  session_version        INT UNSIGNED NOT NULL DEFAULT 1,
  last_login_at          DATETIME     NULL,
  deletion_requested_at  DATETIME     NULL,
  created_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customers_email (email),
  KEY idx_customers_name (last_name, first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_addresses (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id     INT UNSIGNED NOT NULL,
  label           VARCHAR(60)  NULL,
  recipient_name  VARCHAR(150) NOT NULL,
  phone           VARCHAR(30)  NULL,
  farm_name       VARCHAR(150) NULL,
  line1           VARCHAR(191) NOT NULL,
  line2           VARCHAR(191) NULL,
  town            VARCHAR(120) NOT NULL,
  province        VARCHAR(60)  NULL,
  postal_code     VARCHAR(10)  NULL,
  directions      TEXT         NULL,
  gps_lat         DECIMAL(10,7) NULL,
  gps_lng         DECIMAL(10,7) NULL,
  is_default      TINYINT(1)   NOT NULL DEFAULT 0,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_customer_addresses_customer (customer_id),
  CONSTRAINT fk_customer_addresses_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_tokens (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id  INT UNSIGNED NOT NULL,
  purpose      ENUM('verify_email','reset_password') NOT NULL,
  token_hash   CHAR(64)     NOT NULL,
  expires_at   DATETIME     NOT NULL,
  used_at      DATETIME     NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_tokens_hash (token_hash),
  KEY idx_customer_tokens_customer (customer_id, purpose),
  CONSTRAINT fk_customer_tokens_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE roles (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug         VARCHAR(50)  NOT NULL,
  name         VARCHAR(100) NOT NULL,
  description  VARCHAR(255) NULL,
  is_system    TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug        VARCHAR(80)  NOT NULL,
  name        VARCHAR(150) NOT NULL,
  group_name  VARCHAR(50)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
  role_id        INT UNSIGNED NOT NULL,
  permission_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  KEY idx_role_permissions_perm (permission_id),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_perm FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_users (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  role_id          INT UNSIGNED NOT NULL,
  email            VARCHAR(191) NOT NULL,
  password_hash    VARCHAR(255) NOT NULL,
  name             VARCHAR(150) NOT NULL,
  phone            VARCHAR(30)  NULL,
  division_scope   ENUM('all','feeds','ranch') NOT NULL DEFAULT 'all',
  totp_secret      VARCHAR(255) NULL,
  totp_enabled     TINYINT(1)   NOT NULL DEFAULT 0,
  totp_last_step   INT UNSIGNED NULL,
  totp_recovery    TEXT         NULL,
  status           ENUM('active','disabled') NOT NULL DEFAULT 'active',
  session_version  INT UNSIGNED NOT NULL DEFAULT 1,
  last_login_at    DATETIME     NULL,
  last_login_ip    VARCHAR(45)  NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_users_email (email),
  KEY idx_admin_users_role (role_id),
  CONSTRAINT fk_admin_users_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. CMS / CONTENT
-- ---------------------------------------------------------------------

CREATE TABLE media (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  base_path      VARCHAR(191) NOT NULL,
  original_name  VARCHAR(255) NULL,
  mime           VARCHAR(50)  NOT NULL,
  width          INT UNSIGNED NOT NULL DEFAULT 0,
  height         INT UNSIGNED NOT NULL DEFAULT 0,
  size_bytes     INT UNSIGNED NOT NULL DEFAULT 0,
  focal_x        DECIMAL(5,4) NOT NULL DEFAULT 0.5000,
  focal_y        DECIMAL(5,4) NOT NULL DEFAULT 0.5000,
  variants       LONGTEXT     NULL,
  alt_text       VARCHAR(255) NULL,
  created_by     INT UNSIGNED NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_media_base_path (base_path),
  KEY idx_media_created (created_at),
  CONSTRAINT fk_media_admin FOREIGN KEY (created_by) REFERENCES admin_users (id) ON DELETE SET NULL,
  CONSTRAINT chk_media_variants CHECK (variants IS NULL OR JSON_VALID(variants))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE content_blocks (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope       VARCHAR(30)  NOT NULL,
  block_key   VARCHAR(100) NOT NULL,
  type        ENUM('text','textarea','markdown','image','link','json') NOT NULL DEFAULT 'text',
  label       VARCHAR(150) NOT NULL,
  value       LONGTEXT     NULL,
  media_id    INT UNSIGNED NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_content_blocks_scope_key (scope, block_key),
  KEY idx_content_blocks_media (media_id),
  CONSTRAINT fk_content_blocks_media FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pages (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  division_id       INT UNSIGNED NULL,
  slug              VARCHAR(120) NOT NULL,
  title             VARCHAR(191) NOT NULL,
  body_md           MEDIUMTEXT   NULL,
  meta_title        VARCHAR(191) NULL,
  meta_description  VARCHAR(300) NULL,
  is_published      TINYINT(1)   NOT NULL DEFAULT 1,
  show_in_footer    TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order        INT          NOT NULL DEFAULT 0,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pages_slug (slug),
  CONSTRAINT fk_pages_division FOREIGN KEY (division_id) REFERENCES divisions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE faqs (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  division_id  INT UNSIGNED NULL,
  question     VARCHAR(255) NOT NULL,
  answer       TEXT         NOT NULL,
  sort_order   INT          NOT NULL DEFAULT 0,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_faqs_division (division_id, is_active, sort_order),
  CONSTRAINT fk_faqs_division FOREIGN KEY (division_id) REFERENCES divisions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE testimonials (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  division_id  INT UNSIGNED NULL,
  name         VARCHAR(150) NOT NULL,
  location     VARCHAR(150) NULL,
  quote        TEXT         NOT NULL,
  rating       TINYINT UNSIGNED NULL,
  media_id     INT UNSIGNED NULL,
  sort_order   INT          NOT NULL DEFAULT 0,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_testimonials_division (division_id, is_active),
  CONSTRAINT fk_testimonials_division FOREIGN KEY (division_id) REFERENCES divisions (id) ON DELETE SET NULL,
  CONSTRAINT fk_testimonials_media FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE SET NULL,
  CONSTRAINT chk_testimonials_rating CHECK (rating IS NULL OR rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gallery_items (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  division_id  INT UNSIGNED NULL,
  media_id     INT UNSIGNED NOT NULL,
  caption      VARCHAR(255) NULL,
  sort_order   INT          NOT NULL DEFAULT 0,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_gallery_division (division_id, is_active, sort_order),
  CONSTRAINT fk_gallery_division FOREIGN KEY (division_id) REFERENCES divisions (id) ON DELETE SET NULL,
  CONSTRAINT fk_gallery_media FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_items (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  location    VARCHAR(30)  NOT NULL,
  label       VARCHAR(100) NOT NULL,
  url         VARCHAR(255) NOT NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_menu_items_location (location, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. FEEDS CATALOGUE
-- ---------------------------------------------------------------------

CREATE TABLE categories (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_id    INT UNSIGNED NULL,
  slug         VARCHAR(120) NOT NULL,
  name         VARCHAR(150) NOT NULL,
  description  TEXT         NULL,
  icon         VARCHAR(30)  NULL,
  media_id     INT UNSIGNED NULL,
  sort_order   INT          NOT NULL DEFAULT 0,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_slug (slug),
  KEY idx_categories_parent (parent_id),
  CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE SET NULL,
  CONSTRAINT fk_categories_media FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE species (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug        VARCHAR(60)  NOT NULL,
  name        VARCHAR(100) NOT NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_species_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE brands (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug       VARCHAR(120) NOT NULL,
  name       VARCHAR(150) NOT NULL,
  media_id   INT UNSIGNED NULL,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_brands_slug (slug),
  CONSTRAINT fk_brands_media FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id         INT UNSIGNED NOT NULL,
  brand_id            INT UNSIGNED NULL,
  slug                VARCHAR(150) NOT NULL,
  name                VARCHAR(191) NOT NULL,
  short_description   VARCHAR(500) NULL,
  description_md      MEDIUMTEXT   NULL,
  feeding_guide_md    MEDIUMTEXT   NULL,
  reg_no              VARCHAR(50)  NULL,
  zero_rate_eligible  TINYINT(1)   NOT NULL DEFAULT 0,
  spec_sheet_path     VARCHAR(255) NULL,
  is_featured         TINYINT(1)   NOT NULL DEFAULT 0,
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  meta_title          VARCHAR(191) NULL,
  meta_description    VARCHAR(300) NULL,
  sort_order          INT          NOT NULL DEFAULT 0,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_slug (slug),
  KEY idx_products_category (category_id, is_active),
  KEY idx_products_brand (brand_id),
  KEY idx_products_featured (is_featured, is_active),
  FULLTEXT KEY ft_products_search (name, short_description),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE RESTRICT,
  CONSTRAINT fk_products_brand FOREIGN KEY (brand_id) REFERENCES brands (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_species (
  product_id  INT UNSIGNED NOT NULL,
  species_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (product_id, species_id),
  KEY idx_product_species_species (species_id),
  CONSTRAINT fk_product_species_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT fk_product_species_species FOREIGN KEY (species_id) REFERENCES species (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_variants (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id           INT UNSIGNED NOT NULL,
  sku                  VARCHAR(60)  NOT NULL,
  name                 VARCHAR(100) NOT NULL,
  weight_grams         INT UNSIGNED NOT NULL DEFAULT 0,
  price_cents          INT UNSIGNED NOT NULL,
  compare_at_cents     INT UNSIGNED NULL,
  special_price_cents  INT UNSIGNED NULL,
  special_from         DATETIME     NULL,
  special_to           DATETIME     NULL,
  cost_cents           INT UNSIGNED NULL,
  stock_qty            INT          NOT NULL DEFAULT 0,
  low_stock_threshold  INT UNSIGNED NOT NULL DEFAULT 5,
  is_active            TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order           INT          NOT NULL DEFAULT 0,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_product_variants_sku (sku),
  KEY idx_product_variants_product (product_id, is_active, sort_order),
  KEY idx_product_variants_stock (stock_qty),
  CONSTRAINT fk_product_variants_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT chk_product_variants_stock CHECK (stock_qty >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE price_tiers (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  variant_id   INT UNSIGNED NOT NULL,
  min_qty      INT UNSIGNED NOT NULL,
  price_cents  INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_price_tiers_variant_qty (variant_id, min_qty),
  CONSTRAINT fk_price_tiers_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE CASCADE,
  CONSTRAINT chk_price_tiers_min CHECK (min_qty >= 2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_media (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id  INT UNSIGNED NOT NULL,
  media_id    INT UNSIGNED NOT NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_product_media (product_id, media_id),
  KEY idx_product_media_media (media_id),
  CONSTRAINT fk_product_media_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT fk_product_media_media FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_specs (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id  INT UNSIGNED NOT NULL,
  label       VARCHAR(100) NOT NULL,
  value       VARCHAR(100) NOT NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_product_specs_product (product_id, sort_order),
  CONSTRAINT fk_product_specs_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE calculator_rules (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id            INT UNSIGNED NOT NULL,
  species_id            INT UNSIGNED NOT NULL,
  animal_class          VARCHAR(100) NOT NULL,
  intake_grams_per_day  INT UNSIGNED NOT NULL,
  notes                 VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_calculator_rules (product_id, species_id, animal_class),
  KEY idx_calculator_rules_species (species_id),
  CONSTRAINT fk_calculator_rules_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT fk_calculator_rules_species FOREIGN KEY (species_id) REFERENCES species (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. RANCH (LIVESTOCK)
-- ---------------------------------------------------------------------

CREATE TABLE breeds (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug         VARCHAR(80)  NOT NULL,
  name         VARCHAR(100) NOT NULL,
  description  TEXT         NULL,
  sort_order   INT          NOT NULL DEFAULT 0,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_breeds_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE livestock_categories (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug         VARCHAR(80)  NOT NULL,
  name         VARCHAR(100) NOT NULL,
  description  TEXT         NULL,
  sort_order   INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_livestock_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lots (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  lot_no             VARCHAR(30)  NOT NULL,
  slug               VARCHAR(150) NOT NULL,
  title              VARCHAR(191) NOT NULL,
  breed_id           INT UNSIGNED NOT NULL,
  category_id        INT UNSIGNED NOT NULL,
  sex                ENUM('ewe','ram','wether','mixed') NOT NULL DEFAULT 'mixed',
  age_months_min     SMALLINT UNSIGNED NULL,
  age_months_max     SMALLINT UNSIGNED NULL,
  tag_no             VARCHAR(50)  NULL,
  qty_total          INT UNSIGNED NOT NULL DEFAULT 0,
  qty_available      INT          NOT NULL DEFAULT 0,
  avg_weight_kg      DECIMAL(7,2) NULL,
  pricing_mode       ENUM('per_head','per_kg') NOT NULL DEFAULT 'per_head',
  price_cents        INT UNSIGNED NOT NULL,
  deposit_pct        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  cost_cents         INT UNSIGNED NULL,
  available_from     DATE         NULL,
  location           VARCHAR(150) NULL,
  short_description  VARCHAR(500) NULL,
  description_md     MEDIUMTEXT   NULL,
  health_summary     TEXT         NULL,
  video_url          VARCHAR(255) NULL,
  status             ENUM('draft','coming_soon','available','reserved','sold_out','archived') NOT NULL DEFAULT 'draft',
  is_featured        TINYINT(1)   NOT NULL DEFAULT 0,
  meta_title         VARCHAR(191) NULL,
  meta_description   VARCHAR(300) NULL,
  published_at       DATETIME     NULL,
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lots_lot_no (lot_no),
  UNIQUE KEY uq_lots_slug (slug),
  KEY idx_lots_status (status, available_from),
  KEY idx_lots_breed (breed_id),
  KEY idx_lots_category (category_id),
  CONSTRAINT fk_lots_breed FOREIGN KEY (breed_id) REFERENCES breeds (id) ON DELETE RESTRICT,
  CONSTRAINT fk_lots_category FOREIGN KEY (category_id) REFERENCES livestock_categories (id) ON DELETE RESTRICT,
  CONSTRAINT chk_lots_qty CHECK (qty_available >= 0),
  CONSTRAINT chk_lots_deposit CHECK (deposit_pct <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lot_media (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  lot_id      INT UNSIGNED NOT NULL,
  media_id    INT UNSIGNED NOT NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lot_media (lot_id, media_id),
  KEY idx_lot_media_media (media_id),
  CONSTRAINT fk_lot_media_lot FOREIGN KEY (lot_id) REFERENCES lots (id) ON DELETE CASCADE,
  CONSTRAINT fk_lot_media_media FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lot_health_records (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  lot_id            INT UNSIGNED NOT NULL,
  record_date       DATE         NOT NULL,
  type              ENUM('vaccination','dosing','treatment','inspection','other') NOT NULL,
  product_used      VARCHAR(150) NULL,
  notes             VARCHAR(500) NULL,
  withdrawal_until  DATE         NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_lot_health_lot (lot_id, record_date),
  CONSTRAINT fk_lot_health_lot FOREIGN KEY (lot_id) REFERENCES lots (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE weigh_sessions (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  lot_id           INT UNSIGNED NOT NULL,
  weighed_on       DATE         NOT NULL,
  head_count       INT UNSIGNED NOT NULL,
  avg_weight_kg    DECIMAL(7,2) NOT NULL,
  total_weight_kg  DECIMAL(10,2) NULL,
  notes            VARCHAR(255) NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_weigh_sessions_lot (lot_id, weighed_on),
  CONSTRAINT fk_weigh_sessions_lot FOREIGN KEY (lot_id) REFERENCES lots (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lot_weights (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  weigh_session_id   INT UNSIGNED NOT NULL,
  tag_no             VARCHAR(50)  NULL,
  weight_kg          DECIMAL(7,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_lot_weights_session (weigh_session_id),
  CONSTRAINT fk_lot_weights_session FOREIGN KEY (weigh_session_id) REFERENCES weigh_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE livestock_alerts (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id        INT UNSIGNED NULL,
  email              VARCHAR(191) NOT NULL,
  breed_id           INT UNSIGNED NULL,
  category_id        INT UNSIGNED NULL,
  is_active          TINYINT(1)   NOT NULL DEFAULT 1,
  unsubscribe_token  CHAR(64)     NOT NULL,
  last_notified_at   DATETIME     NULL,
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_livestock_alerts_token (unsubscribe_token),
  KEY idx_livestock_alerts_email (email),
  KEY idx_livestock_alerts_match (is_active, breed_id, category_id),
  CONSTRAINT fk_livestock_alerts_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE,
  CONSTRAINT fk_livestock_alerts_breed FOREIGN KEY (breed_id) REFERENCES breeds (id) ON DELETE CASCADE,
  CONSTRAINT fk_livestock_alerts_category FOREIGN KEY (category_id) REFERENCES livestock_categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. COMMERCE: carts, coupons, delivery, collection
-- ---------------------------------------------------------------------

CREATE TABLE coupons (
  id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code                    VARCHAR(40)  NOT NULL,
  division_id             INT UNSIGNED NULL,
  type                    ENUM('percent','fixed','free_delivery') NOT NULL,
  value                   INT UNSIGNED NOT NULL DEFAULT 0,
  min_spend_cents         INT UNSIGNED NOT NULL DEFAULT 0,
  max_uses                INT UNSIGNED NULL,
  max_uses_per_customer   INT UNSIGNED NULL,
  used_count              INT UNSIGNED NOT NULL DEFAULT 0,
  starts_at               DATETIME     NULL,
  ends_at                 DATETIME     NULL,
  is_active               TINYINT(1)   NOT NULL DEFAULT 1,
  created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_coupons_code (code),
  CONSTRAINT fk_coupons_division FOREIGN KEY (division_id) REFERENCES divisions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE carts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  division_id  INT UNSIGNED NOT NULL,
  customer_id  INT UNSIGNED NULL,
  token        CHAR(64)     NOT NULL,
  coupon_id    INT UNSIGNED NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_carts_token (token),
  UNIQUE KEY uq_carts_customer_division (customer_id, division_id),
  KEY idx_carts_updated (updated_at),
  CONSTRAINT fk_carts_division FOREIGN KEY (division_id) REFERENCES divisions (id) ON DELETE CASCADE,
  CONSTRAINT fk_carts_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE,
  CONSTRAINT fk_carts_coupon FOREIGN KEY (coupon_id) REFERENCES coupons (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cart_items (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cart_id     INT UNSIGNED NOT NULL,
  variant_id  INT UNSIGNED NULL,
  lot_id      INT UNSIGNED NULL,
  qty         INT UNSIGNED NOT NULL DEFAULT 1,
  added_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cart_items_variant (cart_id, variant_id),
  UNIQUE KEY uq_cart_items_lot (cart_id, lot_id),
  KEY idx_cart_items_variant (variant_id),
  KEY idx_cart_items_lot (lot_id),
  CONSTRAINT fk_cart_items_cart FOREIGN KEY (cart_id) REFERENCES carts (id) ON DELETE CASCADE,
  CONSTRAINT fk_cart_items_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE CASCADE,
  CONSTRAINT fk_cart_items_lot FOREIGN KEY (lot_id) REFERENCES lots (id) ON DELETE CASCADE,
  CONSTRAINT chk_cart_items_target CHECK (variant_id IS NOT NULL OR lot_id IS NOT NULL),
  CONSTRAINT chk_cart_items_qty CHECK (qty >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE delivery_zones (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name             VARCHAR(100) NOT NULL,
  description      VARCHAR(255) NULL,
  fee_cents        INT UNSIGNED NOT NULL DEFAULT 0,
  free_over_cents  INT UNSIGNED NULL,
  min_order_cents  INT UNSIGNED NOT NULL DEFAULT 0,
  delivery_days    VARCHAR(20)  NOT NULL DEFAULT '1,2,3,4,5',
  lead_days        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order       INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE delivery_zone_towns (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  zone_id      INT UNSIGNED NOT NULL,
  town         VARCHAR(120) NOT NULL,
  postal_code  VARCHAR(10)  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_delivery_zone_towns_town (town),
  KEY idx_delivery_zone_towns_zone (zone_id),
  CONSTRAINT fk_delivery_zone_towns_zone FOREIGN KEY (zone_id) REFERENCES delivery_zones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE collection_points (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  division_id  INT UNSIGNED NOT NULL,
  name         VARCHAR(150) NOT NULL,
  address      TEXT         NULL,
  gps_lat      DECIMAL(10,7) NULL,
  gps_lng      DECIMAL(10,7) NULL,
  hours_text   VARCHAR(255) NULL,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order   INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_collection_points_division (division_id, is_active),
  CONSTRAINT fk_collection_points_division FOREIGN KEY (division_id) REFERENCES divisions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE collection_slots (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  collection_point_id  INT UNSIGNED NOT NULL,
  weekday              TINYINT UNSIGNED NOT NULL,
  start_time           TIME         NOT NULL,
  end_time             TIME         NOT NULL,
  capacity             INT UNSIGNED NOT NULL DEFAULT 10,
  is_active            TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_collection_slots_point (collection_point_id, weekday),
  CONSTRAINT fk_collection_slots_point FOREIGN KEY (collection_point_id) REFERENCES collection_points (id) ON DELETE CASCADE,
  CONSTRAINT chk_collection_slots_weekday CHECK (weekday BETWEEN 1 AND 7)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE blackout_dates (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  division_id  INT UNSIGNED NULL,
  blackout_on  DATE         NOT NULL,
  reason       VARCHAR(150) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_blackout_dates (division_id, blackout_on),
  KEY idx_blackout_dates_date (blackout_on),
  CONSTRAINT fk_blackout_dates_division FOREIGN KEY (division_id) REFERENCES divisions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. ORDERS, PAYMENTS, DOCUMENTS
-- ---------------------------------------------------------------------

CREATE TABLE orders (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  division_id           INT UNSIGNED NOT NULL,
  order_no              VARCHAR(30)  NOT NULL,
  customer_id           INT UNSIGNED NOT NULL,
  status                ENUM('pending_payment','awaiting_collection','reserved','paid','deposit_paid','processing',
                             'finalised','ready_for_collection','out_for_delivery','collected','completed',
                             'cancelled','expired','refunded') NOT NULL,
  payment_status        ENUM('unpaid','partial','paid','refunded') NOT NULL DEFAULT 'unpaid',
  payment_method        ENUM('yoco','eft','cash_on_collection') NOT NULL,
  fulfilment            ENUM('collection','delivery') NOT NULL DEFAULT 'collection',
  collection_point_id   INT UNSIGNED NULL,
  collection_slot_id    INT UNSIGNED NULL,
  collection_date       DATE         NULL,
  delivery_zone_id      INT UNSIGNED NULL,
  delivery_date         DATE         NULL,
  ship_name             VARCHAR(150) NULL,
  ship_phone            VARCHAR(30)  NULL,
  ship_farm_name        VARCHAR(150) NULL,
  ship_line1            VARCHAR(191) NULL,
  ship_line2            VARCHAR(191) NULL,
  ship_town             VARCHAR(120) NULL,
  ship_province         VARCHAR(60)  NULL,
  ship_postal_code      VARCHAR(10)  NULL,
  ship_directions       TEXT         NULL,
  ship_gps_lat          DECIMAL(10,7) NULL,
  ship_gps_lng          DECIMAL(10,7) NULL,
  billing_name          VARCHAR(150) NOT NULL,
  billing_email         VARCHAR(191) NOT NULL,
  billing_phone         VARCHAR(30)  NULL,
  billing_company       VARCHAR(150) NULL,
  billing_vat_no        VARCHAR(20)  NULL,
  billing_address       TEXT         NULL,
  buyer_id_number       VARCHAR(20)  NULL,
  buyer_brand_mark      VARCHAR(50)  NULL,
  transport_note        VARCHAR(500) NULL,
  vat_rate_bp           INT UNSIGNED NOT NULL DEFAULT 1500,
  zero_rated            TINYINT(1)   NOT NULL DEFAULT 0,
  subtotal_cents        INT          NOT NULL DEFAULT 0,
  discount_cents        INT          NOT NULL DEFAULT 0,
  delivery_cents        INT          NOT NULL DEFAULT 0,
  vat_cents             INT          NOT NULL DEFAULT 0,
  total_cents           INT          NOT NULL DEFAULT 0,
  deposit_due_cents     INT          NOT NULL DEFAULT 0,
  amount_paid_cents     INT          NOT NULL DEFAULT 0,
  coupon_id             INT UNSIGNED NULL,
  customer_note         VARCHAR(1000) NULL,
  admin_note            TEXT         NULL,
  hold_expires_at       DATETIME     NULL,
  reminder_sent_at      DATETIME     NULL,
  pay_token             CHAR(64)     NULL,
  placed_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at               DATETIME     NULL,
  finalised_at          DATETIME     NULL,
  completed_at          DATETIME     NULL,
  cancelled_at          DATETIME     NULL,
  cancel_reason         VARCHAR(255) NULL,
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_order_no (order_no),
  UNIQUE KEY uq_orders_pay_token (pay_token),
  KEY idx_orders_division_status (division_id, status),
  KEY idx_orders_customer (customer_id, placed_at),
  KEY idx_orders_hold (status, hold_expires_at),
  KEY idx_orders_collection (collection_date, collection_slot_id),
  KEY idx_orders_placed (placed_at),
  CONSTRAINT fk_orders_division FOREIGN KEY (division_id) REFERENCES divisions (id) ON DELETE RESTRICT,
  CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE RESTRICT,
  CONSTRAINT fk_orders_collection_point FOREIGN KEY (collection_point_id) REFERENCES collection_points (id) ON DELETE SET NULL,
  CONSTRAINT fk_orders_collection_slot FOREIGN KEY (collection_slot_id) REFERENCES collection_slots (id) ON DELETE SET NULL,
  CONSTRAINT fk_orders_delivery_zone FOREIGN KEY (delivery_zone_id) REFERENCES delivery_zones (id) ON DELETE SET NULL,
  CONSTRAINT fk_orders_coupon FOREIGN KEY (coupon_id) REFERENCES coupons (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_items (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id           INT UNSIGNED NOT NULL,
  variant_id         INT UNSIGNED NULL,
  lot_id             INT UNSIGNED NULL,
  name               VARCHAR(191) NOT NULL,
  sku                VARCHAR(60)  NULL,
  variant_name       VARCHAR(200) NULL,
  qty                INT UNSIGNED NOT NULL,
  pricing_mode       ENUM('unit','per_head','per_kg') NOT NULL DEFAULT 'unit',
  unit_price_cents   INT UNSIGNED NOT NULL,
  est_weight_kg      DECIMAL(10,2) NULL,
  actual_weight_kg   DECIMAL(10,2) NULL,
  line_total_cents   INT          NOT NULL,
  vat_rate_bp        INT UNSIGNED NOT NULL DEFAULT 1500,
  zero_rated         TINYINT(1)   NOT NULL DEFAULT 0,
  unit_cost_cents    INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_order_items_order (order_id),
  KEY idx_order_items_variant (variant_id),
  KEY idx_order_items_lot (lot_id),
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_order_items_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE SET NULL,
  CONSTRAINT fk_order_items_lot FOREIGN KEY (lot_id) REFERENCES lots (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_status_history (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id     INT UNSIGNED NOT NULL,
  from_status  VARCHAR(30)  NULL,
  to_status    VARCHAR(30)  NOT NULL,
  note         VARCHAR(500) NULL,
  actor_type   ENUM('admin','customer','system') NOT NULL DEFAULT 'system',
  actor_id     INT UNSIGNED NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_order_status_history_order (order_id, created_at),
  CONSTRAINT fk_order_status_history_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coupon_redemptions (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  coupon_id       INT UNSIGNED NOT NULL,
  order_id        INT UNSIGNED NOT NULL,
  customer_id     INT UNSIGNED NOT NULL,
  discount_cents  INT UNSIGNED NOT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_coupon_redemptions_order (order_id),
  KEY idx_coupon_redemptions_coupon (coupon_id, customer_id),
  CONSTRAINT fk_coupon_redemptions_coupon FOREIGN KEY (coupon_id) REFERENCES coupons (id) ON DELETE CASCADE,
  CONSTRAINT fk_coupon_redemptions_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_coupon_redemptions_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id              INT UNSIGNED NOT NULL,
  division_id           INT UNSIGNED NOT NULL,
  method                ENUM('yoco','eft','cash','card') NOT NULL,
  purpose               ENUM('full','deposit','balance','other') NOT NULL DEFAULT 'full',
  amount_cents          INT          NOT NULL,
  status                ENUM('pending','awaiting_verification','succeeded','failed','rejected','refunded','cancelled') NOT NULL DEFAULT 'pending',
  provider_checkout_id  VARCHAR(64)  NULL,
  provider_payment_id   VARCHAR(64)  NULL,
  provider_mode         ENUM('test','live') NULL,
  redirect_url          VARCHAR(500) NULL,
  idempotency_key       CHAR(36)     NULL,
  pop_path              VARCHAR(255) NULL,
  pop_original_name     VARCHAR(255) NULL,
  pop_uploaded_at       DATETIME     NULL,
  reference             VARCHAR(100) NULL,
  verified_by           INT UNSIGNED NULL,
  verified_at           DATETIME     NULL,
  rejection_reason      VARCHAR(255) NULL,
  notes                 VARCHAR(500) NULL,
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payments_checkout (provider_checkout_id),
  UNIQUE KEY uq_payments_provider_payment (provider_payment_id),
  KEY idx_payments_order (order_id),
  KEY idx_payments_status (status, method),
  CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payments_division FOREIGN KEY (division_id) REFERENCES divisions (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payments_verified_by FOREIGN KEY (verified_by) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_webhook_events (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider      VARCHAR(20)  NOT NULL DEFAULT 'yoco',
  event_id      VARCHAR(100) NOT NULL,
  event_type    VARCHAR(50)  NOT NULL,
  checkout_id   VARCHAR(64)  NULL,
  payload       LONGTEXT     NOT NULL,
  result        VARCHAR(255) NULL,
  processed_at  DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_webhook_events (provider, event_id),
  KEY idx_webhook_events_checkout (checkout_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE document_sequences (
  division_id  INT UNSIGNED NOT NULL,
  doc_type     VARCHAR(30)  NOT NULL,
  seq_year     SMALLINT UNSIGNED NOT NULL,
  next_no      INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (division_id, doc_type, seq_year),
  CONSTRAINT fk_document_sequences_division FOREIGN KEY (division_id) REFERENCES divisions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE documents (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  division_id          INT UNSIGNED NOT NULL,
  order_id             INT UNSIGNED NOT NULL,
  customer_id          INT UNSIGNED NOT NULL,
  payment_id           INT UNSIGNED NULL,
  related_document_id  INT UNSIGNED NULL,
  doc_type             ENUM('order_confirmation','tax_invoice','invoice','receipt','credit_note','debit_note',
                            'pick_list','delivery_note','collection_slip','doc_identification','statement') NOT NULL,
  doc_no               VARCHAR(40)  NOT NULL,
  issued_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  subtotal_excl_cents  INT          NOT NULL DEFAULT 0,
  vat_cents            INT          NOT NULL DEFAULT 0,
  total_cents          INT          NOT NULL DEFAULT 0,
  reason_code          ENUM('cancel','return','price_adjust','weight_adjust','error','refund','other') NULL,
  explanation          VARCHAR(500) NULL,
  snapshot             LONGTEXT     NULL,
  pdf_path             VARCHAR(255) NULL,
  print_count          INT UNSIGNED NOT NULL DEFAULT 0,
  is_void              TINYINT(1)   NOT NULL DEFAULT 0,
  voided_at            DATETIME     NULL,
  void_reason          VARCHAR(255) NULL,
  created_by           INT UNSIGNED NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_documents_type_no (doc_type, doc_no),
  KEY idx_documents_order (order_id),
  KEY idx_documents_customer (customer_id, issued_at),
  KEY idx_documents_division_type (division_id, doc_type, issued_at),
  CONSTRAINT fk_documents_division FOREIGN KEY (division_id) REFERENCES divisions (id) ON DELETE RESTRICT,
  CONSTRAINT fk_documents_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT,
  CONSTRAINT fk_documents_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE RESTRICT,
  CONSTRAINT fk_documents_payment FOREIGN KEY (payment_id) REFERENCES payments (id) ON DELETE SET NULL,
  CONSTRAINT fk_documents_related FOREIGN KEY (related_document_id) REFERENCES documents (id) ON DELETE RESTRICT,
  CONSTRAINT fk_documents_created_by FOREIGN KEY (created_by) REFERENCES admin_users (id) ON DELETE SET NULL,
  CONSTRAINT chk_documents_snapshot CHECK (snapshot IS NULL OR JSON_VALID(snapshot))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE document_lines (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_id            INT UNSIGNED NOT NULL,
  order_item_id          INT UNSIGNED NULL,
  description            VARCHAR(255) NOT NULL,
  qty                    DECIMAL(10,2) NOT NULL,
  unit                   VARCHAR(20)  NULL,
  unit_price_incl_cents  INT          NOT NULL,
  line_excl_cents        INT          NOT NULL,
  line_vat_cents         INT          NOT NULL,
  line_incl_cents        INT          NOT NULL,
  vat_rate_bp            INT UNSIGNED NOT NULL,
  zero_rated             TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order             INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_document_lines_document (document_id),
  KEY idx_document_lines_item (order_item_id),
  CONSTRAINT fk_document_lines_document FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE,
  CONSTRAINT fk_document_lines_item FOREIGN KEY (order_item_id) REFERENCES order_items (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. STOCK LEDGER
-- ---------------------------------------------------------------------

CREATE TABLE stock_movements (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  division_id      INT UNSIGNED NOT NULL,
  variant_id       INT UNSIGNED NULL,
  lot_id           INT UNSIGNED NULL,
  type             ENUM('initial','sale','cancel_return','expiry_return','receive','adjustment','stocktake','write_off','weigh_adjust','late_payment') NOT NULL,
  qty_change       INT          NOT NULL,
  balance_after    INT          NOT NULL,
  unit_cost_cents  INT UNSIGNED NULL,
  order_id         INT UNSIGNED NULL,
  reference        VARCHAR(100) NULL,
  note             VARCHAR(255) NULL,
  admin_id         INT UNSIGNED NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_stock_movements_variant (variant_id, created_at),
  KEY idx_stock_movements_lot (lot_id, created_at),
  KEY idx_stock_movements_order (order_id),
  KEY idx_stock_movements_type (type, created_at),
  CONSTRAINT fk_stock_movements_division FOREIGN KEY (division_id) REFERENCES divisions (id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_movements_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE SET NULL,
  CONSTRAINT fk_stock_movements_lot FOREIGN KEY (lot_id) REFERENCES lots (id) ON DELETE SET NULL,
  CONSTRAINT fk_stock_movements_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL,
  CONSTRAINT fk_stock_movements_admin FOREIGN KEY (admin_id) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_receipts (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  division_id          INT UNSIGNED NOT NULL,
  supplier_name        VARCHAR(150) NULL,
  supplier_invoice_no  VARCHAR(60)  NULL,
  received_on          DATE         NOT NULL,
  notes                VARCHAR(500) NULL,
  admin_id             INT UNSIGNED NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_stock_receipts_date (received_on),
  CONSTRAINT fk_stock_receipts_division FOREIGN KEY (division_id) REFERENCES divisions (id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_receipts_admin FOREIGN KEY (admin_id) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_receipt_items (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  receipt_id       INT UNSIGNED NOT NULL,
  variant_id       INT UNSIGNED NOT NULL,
  qty              INT UNSIGNED NOT NULL,
  unit_cost_cents  INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_stock_receipt_items_receipt (receipt_id),
  KEY idx_stock_receipt_items_variant (variant_id),
  CONSTRAINT fk_stock_receipt_items_receipt FOREIGN KEY (receipt_id) REFERENCES stock_receipts (id) ON DELETE CASCADE,
  CONSTRAINT fk_stock_receipt_items_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_takes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  division_id   INT UNSIGNED NOT NULL,
  status        ENUM('open','completed','cancelled') NOT NULL DEFAULT 'open',
  notes         VARCHAR(500) NULL,
  started_by    INT UNSIGNED NULL,
  completed_at  DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_stock_takes_division FOREIGN KEY (division_id) REFERENCES divisions (id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_takes_admin FOREIGN KEY (started_by) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_take_items (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  stock_take_id  INT UNSIGNED NOT NULL,
  variant_id     INT UNSIGNED NOT NULL,
  system_qty     INT          NOT NULL,
  counted_qty    INT          NULL,
  counted_at     DATETIME     NULL,
  counted_mark   BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_stock_take_items (stock_take_id, variant_id),
  KEY idx_stock_take_items_variant (variant_id),
  CONSTRAINT fk_stock_take_items_take FOREIGN KEY (stock_take_id) REFERENCES stock_takes (id) ON DELETE CASCADE,
  CONSTRAINT fk_stock_take_items_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. ENQUIRIES, WISHLISTS, PUSH DEVICES
-- ---------------------------------------------------------------------

CREATE TABLE enquiries (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  division_id  INT UNSIGNED NULL,
  type         ENUM('contact','lot','product') NOT NULL DEFAULT 'contact',
  lot_id       INT UNSIGNED NULL,
  product_id   INT UNSIGNED NULL,
  customer_id  INT UNSIGNED NULL,
  name         VARCHAR(150) NOT NULL,
  email        VARCHAR(191) NOT NULL,
  phone        VARCHAR(30)  NULL,
  message      TEXT         NOT NULL,
  status       ENUM('new','read','replied','archived') NOT NULL DEFAULT 'new',
  admin_reply  TEXT         NULL,
  replied_at   DATETIME     NULL,
  ip           VARCHAR(45)  NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_enquiries_status (status, created_at),
  CONSTRAINT fk_enquiries_division FOREIGN KEY (division_id) REFERENCES divisions (id) ON DELETE SET NULL,
  CONSTRAINT fk_enquiries_lot FOREIGN KEY (lot_id) REFERENCES lots (id) ON DELETE SET NULL,
  CONSTRAINT fk_enquiries_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL,
  CONSTRAINT fk_enquiries_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wishlists (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id  INT UNSIGNED NOT NULL,
  product_id   INT UNSIGNED NULL,
  lot_id       INT UNSIGNED NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wishlists_product (customer_id, product_id),
  UNIQUE KEY uq_wishlists_lot (customer_id, lot_id),
  CONSTRAINT fk_wishlists_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE,
  CONSTRAINT fk_wishlists_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT fk_wishlists_lot FOREIGN KEY (lot_id) REFERENCES lots (id) ON DELETE CASCADE,
  CONSTRAINT chk_wishlists_target CHECK (product_id IS NOT NULL OR lot_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE push_devices (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id    INT UNSIGNED NULL,
  admin_user_id  INT UNSIGNED NULL,
  platform       ENUM('android','ios','web') NOT NULL DEFAULT 'android',
  token_hash     CHAR(64)     NOT NULL,
  token          TEXT         NOT NULL,
  last_seen_at   DATETIME     NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_push_devices_token (token_hash),
  CONSTRAINT fk_push_devices_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE,
  CONSTRAINT fk_push_devices_admin FOREIGN KEY (admin_user_id) REFERENCES admin_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- SEED DATA
-- =====================================================================

INSERT INTO divisions (id, code, name, tagline, legal_name, vat_registered, order_prefix, invoice_prefix, credit_prefix, receipt_prefix, logo_path, document_footer) VALUES
(1, 'feeds', 'GrazeX Feeds', 'Feed that earns.', 'GrazeX Feeds', 0, 'GXF', 'GXF-INV', 'GXF-CN', 'GXF-RC', '/assets/img/brand/feeds-logo.webp', 'Thank you for buying from GrazeX Feeds.'),
(2, 'ranch', 'GrazeX Ranch', 'Raise. Rotate. Finish.', 'GrazeX Ranch', 0, 'GXR', 'GXR-INV', 'GXR-CN', 'GXR-RC', '/assets/img/brand/ranch-logo.webp', 'Thank you for buying from GrazeX Ranch.');

INSERT INTO vat_rates (rate_bp, effective_from) VALUES (1500, '2018-04-01');

INSERT INTO settings (setting_key, setting_value, value_type, group_name, label) VALUES
('site_name',                'GrazeX',                         'string', 'general',  'Site name'),
('site_tagline',             'Ranch & Feeds',                  'string', 'general',  'Site tagline'),
('contact_email',            'info@grazex.co.za',              'string', 'general',  'Public contact email'),
('contact_phone',            '',                               'string', 'general',  'Public phone number'),
('whatsapp_number',          '',                               'string', 'general',  'WhatsApp number (international format, e.g. 27821234567)'),
('physical_address',         '',                               'text',   'general',  'Physical address'),
('map_url',                  '',                               'string', 'general',  'Google Maps link for directions'),
('business_hours',           'Mon–Fri 08:00–17:00 · Sat 08:00–12:00', 'string', 'general', 'Business hours'),
('social_facebook',          '',                               'string', 'social',   'Facebook URL'),
('social_instagram',         '',                               'string', 'social',   'Instagram URL'),
('social_tiktok',            '',                               'string', 'social',   'TikTok URL'),
('announcement_enabled',     '0',                              'bool',   'content',  'Show announcement bar'),
('announcement_text',        'Free delivery in local zones on orders over R2 500',  'string', 'content', 'Announcement bar text'),
('announcement_link',        '/feeds/specials.php',            'string', 'content',  'Announcement bar link'),
('maintenance_mode',         '0',                              'bool',   'system',   'Maintenance mode (only admins can browse)'),
('eft_hold_hours',           '48',                             'int',    'orders',   'Hours an unpaid EFT order holds stock'),
('eft_reminder_hours',       '24',                             'int',    'orders',   'Send EFT payment reminder after (hours)'),
('yoco_hold_minutes',        '60',                             'int',    'orders',   'Minutes an unpaid card order holds stock'),
('cash_hold_days',           '3',                              'int',    'orders',   'Days after collection date before unpaid cash orders expire'),
('pop_reupload_hours',       '24',                             'int',    'orders',   'Extra hours to upload a new proof of payment after one is rejected'),
('ranch_deposit_hold_hours', '48',                             'int',    'orders',   'Hours a livestock reservation waits for its deposit'),
('max_collection_days_ahead','30',                             'int',    'orders',   'How many days ahead customers can book collection'),
('payment_yoco_enabled',     '1',                              'bool',   'payments', 'Yoco card payments enabled'),
('payment_eft_enabled',      '1',                              'bool',   'payments', 'EFT payments enabled'),
('payment_cash_enabled',     '1',                              'bool',   'payments', 'Cash/card on collection enabled'),
('zero_rating_enabled',      '0',                              'bool',   'tax',      'Zero-rate eligible feed for verified VAT-registered farmers (confirm with accountant first)'),
('low_stock_email',          '',                               'string', 'notifications', 'Email for low stock & new order alerts'),
('email_hourly_cap',         '300',                            'int',    'notifications', 'Max emails sent per hour (Xneelo limit is 500)'),
('ranch_brand_mark',         '',                               'string', 'ranch',    'Registered brand / tattoo mark of the ranch (printed on documents of identification)'),
('site_language',            'en',                             'string', 'general',  'Site language (en / af)'),
('meta_description',         'GrazeX Ranch & Feeds — quality livestock feed and finished lambs from productive South African pasture.', 'text', 'seo', 'Default meta description');

INSERT INTO roles (id, slug, name, description, is_system) VALUES
(1, 'owner',   'Owner',   'Full access to everything, including users and settings', 1),
(2, 'manager', 'Manager', 'Runs the business day-to-day; cannot manage admin users', 1),
(3, 'staff',   'Staff',   'Packs and fulfils orders, verifies payments, adjusts stock', 1);

INSERT INTO permissions (id, slug, name, group_name) VALUES
(1,  'dashboard.view',     'View dashboard',                'Dashboard'),
(2,  'orders.view',        'View orders',                   'Orders'),
(3,  'orders.manage',      'Update & cancel orders',        'Orders'),
(4,  'payments.verify',    'Verify EFT / record payments',  'Payments'),
(5,  'payments.refund',    'Refunds & credit notes',        'Payments'),
(6,  'products.manage',    'Manage feed products',          'Catalogue'),
(7,  'lots.manage',        'Manage livestock lots',         'Catalogue'),
(8,  'inventory.manage',   'Stock adjustments & receiving', 'Inventory'),
(9,  'customers.view',     'View customers',                'Customers'),
(10, 'customers.manage',   'Edit & block customers',        'Customers'),
(11, 'documents.view',     'View invoices & documents',     'Documents'),
(12, 'documents.void',     'Void documents',                'Documents'),
(13, 'delivery.manage',    'Delivery zones & collection',   'Settings'),
(14, 'promotions.manage',  'Coupons & specials',            'Marketing'),
(15, 'content.manage',     'Edit site content & pages',     'Content'),
(16, 'media.manage',       'Upload & manage media',         'Content'),
(17, 'messages.manage',    'Read & reply to enquiries',     'Messages'),
(18, 'reports.view',       'View reports',                  'Reports'),
(19, 'settings.manage',    'Business settings',             'Settings'),
(20, 'users.manage',       'Manage admin users & roles',    'Settings'),
(21, 'audit.view',         'View audit log',                'Settings');

INSERT INTO role_permissions (role_id, permission_id)
SELECT 1, id FROM permissions;

INSERT INTO role_permissions (role_id, permission_id)
SELECT 2, id FROM permissions WHERE slug NOT IN ('users.manage');

INSERT INTO role_permissions (role_id, permission_id)
SELECT 3, id FROM permissions WHERE slug IN ('dashboard.view','orders.view','orders.manage','payments.verify','inventory.manage','customers.view','documents.view','messages.manage');

INSERT INTO document_sequences (division_id, doc_type, seq_year, next_no) VALUES
(1, 'order', 2026, 1), (1, 'tax_invoice', 2026, 1), (1, 'receipt', 2026, 1), (1, 'credit_note', 2026, 1), (1, 'debit_note', 2026, 1),
(2, 'order', 2026, 1), (2, 'tax_invoice', 2026, 1), (2, 'receipt', 2026, 1), (2, 'credit_note', 2026, 1), (2, 'debit_note', 2026, 1), (2, 'doc_identification', 2026, 1);

INSERT INTO species (id, slug, name, sort_order) VALUES
(1, 'sheep', 'Sheep', 1), (2, 'cattle', 'Cattle', 2), (3, 'goats', 'Goats', 3),
(4, 'game', 'Game', 4), (5, 'horses', 'Horses', 5), (6, 'poultry', 'Poultry', 6);

INSERT INTO categories (id, slug, name, description, icon, sort_order) VALUES
(1, 'licks',            'Licks',                 'Summer, winter and production licks.',                 'lick',   1),
(2, 'pellets-rations',  'Pellets & Rations',     'Complete feeds, finishing and maintenance rations.',   'pellet', 2),
(3, 'supplements',      'Supplements & Minerals','Vitamins, minerals, salt and performance additives.',  'spark',  3),
(4, 'lucerne-hay',      'Lucerne & Hay',         'Lucerne, teff and grass bales.',                       'bale',   4),
(5, 'creep-feed',       'Creep Feed',            'Starter feeds for lambs and calves.',                  'sprout', 5),
(6, 'farm-essentials',  'Farm Essentials',       'Feeders, troughs, tags and everyday farm supplies.',   'tool',   6);

INSERT INTO breeds (slug, name, sort_order) VALUES
('dorper', 'Dorper', 1), ('white-dorper', 'White Dorper', 2), ('meatmaster', 'Meatmaster', 3),
('merino', 'Merino', 4), ('dohne-merino', 'Dohne Merino', 5), ('sa-mutton-merino', 'SA Mutton Merino', 6),
('van-rooy', 'Van Rooy', 7), ('damara', 'Damara', 8), ('ile-de-france', 'Ile de France', 9), ('crossbred', 'Crossbred', 10);

INSERT INTO livestock_categories (slug, name, description, sort_order) VALUES
('weaner-lambs',    'Weaner lambs',    'Weaned lambs ready for finishing.',             1),
('slaughter-lambs', 'Slaughter lambs', 'Finished lambs at target weight.',              2),
('ewe-lambs',       'Ewe lambs',       'Young ewes for flock replacement.',             3),
('ewes',            'Ewes',            'Breeding ewes.',                                4),
('rams',            'Rams',            'Breeding rams.',                                5),
('wethers',         'Wethers',         'Castrated males for finishing.',                6);

INSERT INTO collection_points (id, division_id, name, address, hours_text, sort_order) VALUES
(1, 1, 'GrazeX Feeds Depot', 'Update this address in Admin > Delivery', 'Mon–Fri 08:00–17:00 · Sat 08:00–12:00', 1),
(2, 2, 'GrazeX Ranch (farm)', 'Update this address in Admin > Delivery', 'By appointment', 1);

INSERT INTO collection_slots (collection_point_id, weekday, start_time, end_time, capacity) VALUES
(1, 1, '08:00:00', '12:00:00', 15), (1, 1, '13:00:00', '17:00:00', 15),
(1, 2, '08:00:00', '12:00:00', 15), (1, 2, '13:00:00', '17:00:00', 15),
(1, 3, '08:00:00', '12:00:00', 15), (1, 3, '13:00:00', '17:00:00', 15),
(1, 4, '08:00:00', '12:00:00', 15), (1, 4, '13:00:00', '17:00:00', 15),
(1, 5, '08:00:00', '12:00:00', 15), (1, 5, '13:00:00', '17:00:00', 15),
(1, 6, '08:00:00', '12:00:00', 10),
(2, 1, '07:00:00', '10:00:00', 3), (2, 3, '07:00:00', '10:00:00', 3), (2, 5, '07:00:00', '10:00:00', 3);

INSERT INTO delivery_zones (id, name, description, fee_cents, free_over_cents, min_order_cents, delivery_days, lead_days, is_active, sort_order) VALUES
(1, 'Local (example)', 'Edit or replace this zone in Admin > Delivery', 25000, 250000, 50000, '2,4', 1, 0, 1);

INSERT INTO menu_items (location, label, url, sort_order) VALUES
('footer', 'About GrazeX',      '/pages/page.php?slug=about',    1),
('footer', 'Delivery & collection', '/pages/page.php?slug=delivery', 2),
('footer', 'Returns',           '/pages/page.php?slug=returns',  3),
('footer', 'Terms & conditions','/pages/page.php?slug=terms',    4),
('footer', 'Privacy policy',    '/pages/page.php?slug=privacy',  5),
('footer', 'Contact',           '/pages/contact.php',            6);

INSERT INTO pages (slug, title, body_md, meta_description, sort_order) VALUES
('about', 'About GrazeX', '## Built on the land\n\nGrazeX is a modern livestock and feed business built around productive pasture, disciplined finishing and practical farm supply.\n\n**GrazeX Ranch** buys healthy weaned lambs, moves them through irrigated camps with controlled recovery periods and sells finished groups on target.\n\n**GrazeX Feeds** turns that experience into a feed store farmers can trust: the right licks, rations and supplements, in stock and ready to collect or deliver.', 'About GrazeX Ranch & Feeds.', 1),
('delivery', 'Delivery & collection', '## Collection\n\nChoose a collection date and time slot at checkout. We will notify you when your order is ready.\n\n## Delivery\n\nWe deliver to selected towns on fixed days. Your delivery fee, minimum order and delivery day are shown at checkout.\n\n## Livestock\n\nLivestock is collected at the farm. A document of identification is issued with every sale.', 'Delivery zones, fees and collection.', 2),
('returns', 'Returns', '## Returns\n\n_Draft — have this reviewed by your attorney before going live._\n\nUnopened feed in its original packaging may be returned within 7 days of collection or delivery with proof of purchase. Livestock sales are final once collected.', 'Returns policy.', 3),
('terms', 'Terms & conditions', '## Terms & conditions\n\n_Draft — have this reviewed by your attorney before going live._\n\nAll prices are in South African Rand and include VAT where applicable. Orders are subject to stock availability. Unpaid EFT orders are cancelled automatically after the payment window shown at checkout.', 'Terms and conditions.', 4),
('privacy', 'Privacy policy', '## Privacy policy (POPIA)\n\n_Draft — have this reviewed by your attorney before going live._\n\nWe collect only the information we need to process your orders and, if you opt in, to send you news and specials. You can download your data or ask us to delete your account from your profile at any time.', 'How GrazeX handles your personal information.', 5);

INSERT INTO faqs (division_id, question, answer, sort_order) VALUES
(1, 'Can I collect my order?', 'Yes. Pick a date and time slot at checkout and we will let you know when it is ready.', 1),
(1, 'Do you deliver?', 'We deliver to selected towns on fixed days. Use the delivery checker to see if we deliver to you.', 2),
(1, 'How do I pay by EFT?', 'Choose EFT at checkout. Use your order number as the payment reference and upload your proof of payment in your account.', 3),
(2, 'How does buying livestock work?', 'Reserve the animals online and pay the deposit. We confirm final weights before collection and you pay the balance at the farm.', 1),
(2, 'Do I get documents with my sheep?', 'Yes. Every sale includes a tax invoice and a document of identification for transport.', 2),
(2, 'Can you arrange transport?', 'Let us know at checkout and we will put you in touch with a local transporter.', 3);

INSERT INTO email_templates (template_key, name, subject, body) VALUES
('order_placed',        'Order placed',                 'Order {{order_no}} received — thank you!',  'Hi {{first_name}},\n\nThanks for your order **{{order_no}}**. Your order confirmation is attached.\n\n{{payment_instructions_md}}'),
('payment_received',    'Payment received',             'Payment received for {{order_no}}',           'Hi {{first_name}},\n\nWe received your payment of **{{amount}}** for order {{order_no}}. Your invoice is attached.'),
('payment_reminder',    'EFT payment reminder',         'Reminder: order {{order_no}} is waiting for payment', 'Hi {{first_name}},\n\nYour order {{order_no}} is reserved until **{{expires_at}}**. Please pay {{amount_due}} using {{order_no}} as reference and upload your proof of payment.\n\n{{bank_details_md}}'),
('pop_rejected',        'Proof of payment rejected',    'We could not verify your payment for {{order_no}}', 'Hi {{first_name}},\n\nWe could not match your proof of payment for {{order_no}}: {{reason}}. Please upload a new one or contact us.'),
('order_ready',         'Order ready for collection',   'Order {{order_no}} is ready',                 'Hi {{first_name}},\n\nGood news — order {{order_no}} is ready for collection on {{collection_date}}.'),
('order_out_for_delivery','Order out for delivery',     'Order {{order_no}} is on its way',             'Hi {{first_name}},\n\nOrder {{order_no}} is out for delivery today.'),
('order_finalised',     'Livestock order finalised',    'Final weights for {{order_no}}',              'Hi {{first_name}},\n\nYour animals have been weighed. Your final invoice is attached. Balance due at collection: **{{balance_due}}**.'),
('order_completed',     'Order completed',              'Thank you — order {{order_no}} completed',    'Hi {{first_name}},\n\nThank you for buying from GrazeX. We look forward to seeing you again.'),
('order_cancelled',     'Order cancelled',              'Order {{order_no}} cancelled',                'Hi {{first_name}},\n\nOrder {{order_no}} has been cancelled. {{reason}}'),
('order_refunded',      'Order refunded',               'Order {{order_no}} refunded',                 'Hi {{first_name}},\n\nWe have refunded order **{{order_no}}**. {{reason}}\n\nYour credit note is attached. The money is paid back the same way you paid — please allow a few working days for it to reflect.'),
('order_expired',       'Order expired',                'Order {{order_no}} expired',                  'Hi {{first_name}},\n\nWe did not receive payment for order {{order_no}} in time, so the reserved stock has been released. You are welcome to order again.'),
('verify_email',        'Verify email',                 'Confirm your GrazeX account',                 'Hi {{first_name}},\n\nPlease confirm your email address: {{link}}'),
('reset_password',      'Reset password',               'Reset your GrazeX password',                  'Hi {{first_name}},\n\nUse this link to choose a new password (valid for 1 hour): {{link}}\n\nIf you did not ask for this, ignore this email.'),
('livestock_alert',     'New livestock available',      'New on GrazeX Ranch: {{lot_title}}',          'Hi,\n\nA new lot matching your alert is available: **{{lot_title}}**.\n\n{{link}}'),
('enquiry_reply',       'Reply to enquiry',             'Re: your enquiry to GrazeX',                  '{{reply}}'),
('admin_new_order',     'Admin: new order',             'New {{division}} order {{order_no}} ({{total}})', 'A new order was placed: {{order_no}} — {{total}} — {{payment_method}}.\n\n{{link}}'),
('admin_pop_uploaded',  'Admin: POP uploaded',          'POP uploaded for {{order_no}}',               'A proof of payment was uploaded for {{order_no}}. Verify it here: {{link}}'),
('admin_low_stock',     'Admin: low stock digest',      'Low stock: {{count}} items',                  'These items are at or below their low-stock level:\n\n{{items}}');

-- Content blocks (everything on the gateway and storefront heroes is editable in Admin > Content)
INSERT INTO content_blocks (scope, block_key, type, label, value, sort_order) VALUES
('gateway', 'hero_eyebrow',   'text',     'Hero eyebrow',          'Pasture. Livestock. Feed.', 1),
('gateway', 'hero_title_1',   'text',     'Hero title line 1',     'Built on', 2),
('gateway', 'hero_title_2',   'text',     'Hero title line 2',     'the land.', 3),
('gateway', 'hero_text',      'textarea', 'Hero text',             'GrazeX is a modern livestock and feed brand built around productive pasture, disciplined finishing and practical farm supply. Less fluff. More kilograms, better land use, faster stock movement.', 4),
('gateway', 'marquee',        'textarea', 'Marquee items (one per line)', 'GrazeX Ranch\nGrazeX Feeds\nRotational grazing\nLamb finishing\nPasture performance', 5),
('gateway', 'split_kicker',   'text',     'Divisions kicker',      'One brand. Two engines.', 6),
('gateway', 'split_title',    'text',     'Divisions title',       'Ranch meets retail.', 7),
('gateway', 'split_text',     'textarea', 'Divisions text',        'What GrazeX produces and what GrazeX supplies — two businesses, one standard. The ranch creates the proof. The feed store puts it in your hands.', 8),
('gateway', 'ranch_card_title','text',    'Ranch card title',      'Raise. Rotate. Finish.', 9),
('gateway', 'ranch_card_text', 'textarea','Ranch card text',       'Intensive irrigated pasture, disciplined camp rotation and lamb finishing designed around turnover. Reserve finished and weaner lambs online.', 10),
('gateway', 'feeds_card_title','text',    'Feeds card title',      'Feed that earns.', 11),
('gateway', 'feeds_card_text', 'textarea','Feeds card text',       'Licks, rations, supplements and farm essentials — in stock, priced clearly and ready to collect or deliver.', 12),
('gateway', 'model_kicker',   'text',     'Model kicker',          'The GrazeX system', 13),
('gateway', 'model_title',    'text',     'Model title',           'Not just sheep. Throughput.', 14),
('gateway', 'model_text',     'textarea', 'Model text',            'Buy well, graze hard, finish efficiently, sell on target.', 15),
('gateway', 'step_1',         'text',     'Step 1 (Buy)',          'Select healthy weaned lambs at the right weight and price.', 16),
('gateway', 'step_2',         'text',     'Step 2 (Graze)',        'Move stock through irrigated camps with controlled recovery periods.', 17),
('gateway', 'step_3',         'text',     'Step 3 (Finish)',       'Turn grass into saleable liveweight while controlling cost per kilogram gained.', 18),
('gateway', 'step_4',         'text',     'Step 4 (Sell)',         'Move finished groups regularly and keep capital cycling.', 19),
('gateway', 'cta_title',      'text',     'Closing CTA title',     'A farm brand that works like a business.', 20),
('gateway', 'cta_text',       'textarea', 'Closing CTA text',      'Order feed for collection or delivery, reserve livestock online and keep every invoice in one place.', 21),
('feeds',   'hero_kicker',    'text',     'Feeds hero kicker',     'GrazeX Feeds', 1),
('feeds',   'hero_title',     'text',     'Feeds hero title',      'Feed that earns.', 2),
('feeds',   'hero_text',      'textarea', 'Feeds hero text',       'Livestock feed, licks, supplements and practical farm essentials. Order online — collect at the depot or get it delivered.', 3),
('feeds',   'usp_1',          'text',     'USP 1',                 'In stock & priced clearly', 4),
('feeds',   'usp_2',          'text',     'USP 2',                 'Collect or local delivery', 5),
('feeds',   'usp_3',          'text',     'USP 3',                 'Bulk pricing on 10+ bags', 6),
('ranch',   'hero_kicker',    'text',     'Ranch hero kicker',     'GrazeX Ranch', 1),
('ranch',   'hero_title',     'text',     'Ranch hero title',      'Raise. Rotate. Finish.', 2),
('ranch',   'hero_text',      'textarea', 'Ranch hero text',       'Healthy, well-grown sheep from intensive irrigated pasture. Browse what is available now, reserve online and collect at the farm.', 3),
('ranch',   'usp_1',          'text',     'USP 1',                 'Weighed & health-recorded', 4),
('ranch',   'usp_2',          'text',     'USP 2',                 'Reserve with a deposit', 5),
('ranch',   'usp_3',          'text',     'USP 3',                 'Documents with every sale', 6);

-- Optional starter delivery town (zone is inactive until you edit it)
INSERT INTO delivery_zone_towns (zone_id, town) VALUES (1, 'Example Town');

-- =====================================================================
-- Done. Next: open https://<your-domain>/setup/ to create the owner account.
-- =====================================================================
