-- Canonical AlternaTech schema (canonical-v1), for an EMPTY database only.
-- tools/setup.php runs this file after checking the database holds no table; existing databases are
-- brought to the same contract by tools/migrate.php (see docs/database-migration.md), never by this file.
-- Plain CREATE TABLE on purpose: it fails instead of silently skipping a table that already exists.
CREATE DATABASE IF NOT EXISTS alternatech
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE alternatech;

-- =========================
-- COMPANIES
-- Several companies may share a name (homonyms); the import looks companies up by name.
-- =========================

CREATE TABLE companies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    email VARCHAR(150) NULL,
    phone VARCHAR(30) NULL,
    website VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_companies_name (name)
);

-- =========================
-- CATEGORIES
-- =========================

CREATE TABLE categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
);

-- =========================
-- USERS
-- Login / authentication; the e-mail is the unique sign-in identifier
-- =========================

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firstname VARCHAR(100) NOT NULL,
    lastname VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(30) NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
);

-- =========================
-- PEOPLE
-- Applicant and recruiter profiles. user_id is NULL for guest applicants;
-- the e-mail is not unique: each guest application has its own profile and
-- profiles are never linked to an account by e-mail.
-- =========================

CREATE TABLE people (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    firstname VARCHAR(100) NOT NULL,
    lastname VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY unique_person_account (user_id),
    INDEX idx_people_email (email),

    CONSTRAINT fk_people_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE SET NULL
);

-- =========================
-- ADVERTISEMENTS
-- A company with advertisements cannot be deleted (RESTRICT): its offers and
-- their applications are never removed as a side effect.
-- =========================

CREATE TABLE advertisements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NULL,

    title VARCHAR(200) NOT NULL,
    short_description VARCHAR(500) NOT NULL,
    description TEXT NOT NULL,

    salary VARCHAR(100) NULL,
    location VARCHAR(150) NOT NULL,
    working_time VARCHAR(100) NULL,
    contract_type VARCHAR(100) NULL,

    source ENUM('LOCAL', 'FRANCE_TRAVAIL')
        NOT NULL DEFAULT 'LOCAL',

    external_id VARCHAR(100) NULL,
    source_url VARCHAR(500) NULL,
    created_by INT UNSIGNED NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_advertisement_company
        FOREIGN KEY (company_id)
        REFERENCES companies(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_advertisement_category
        FOREIGN KEY (category_id)
        REFERENCES categories(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_ad_creator
        FOREIGN KEY (created_by)
        REFERENCES people(id)
        ON DELETE SET NULL,

    UNIQUE KEY unique_external_job (source, external_id)
);

-- =========================
-- APPLICATIONS
-- application_date is the stored date (DATETIME: no time-zone conversion, no 2038 limit);
-- created_at is its generated alias, read by the integration layer. application_date is
-- declared NULL with CHECK (application_date IS NOT NULL): with NOT NULL, MySQL computes the
-- alias before applying the default and rejects INSERTs that omit the date. One application per
-- (person, advertisement); deleting the advertisement deletes its applications.
-- =========================

CREATE TABLE applications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    advertisement_id INT UNSIGNED NOT NULL,
    person_id INT UNSIGNED NOT NULL,

    message TEXT NULL,

    status ENUM(
        'pending',
        'reviewed',
        'accepted',
        'rejected'
    ) NOT NULL DEFAULT 'pending',

    application_date DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME GENERATED ALWAYS AS (application_date) VIRTUAL,

    UNIQUE KEY unique_person_ad (person_id, advertisement_id),

    CONSTRAINT chk_application_date CHECK (application_date IS NOT NULL),

    CONSTRAINT fk_application_advertisement
        FOREIGN KEY (advertisement_id)
        REFERENCES advertisements(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_application_person
        FOREIGN KEY (person_id)
        REFERENCES people(id)
        ON DELETE CASCADE
);

-- =========================
-- COMPANY MEMBERS
-- Recruiter authorization, by profile id; several recruiters per company.
-- =========================

CREATE TABLE company_members (
    company_id INT UNSIGNED NOT NULL,
    person_id INT UNSIGNED NOT NULL,
    role ENUM('recruiter') NOT NULL DEFAULT 'recruiter',
    PRIMARY KEY (company_id, person_id),
    CONSTRAINT fk_member_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_member_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =========================
-- GOOGLE IDENTITIES
-- Created only by a Google sign-in for a new e-mail; never linked to an existing account automatically.
-- =========================

CREATE TABLE google_identities (
    google_sub VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_google_identity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =========================
-- MIGRATION JOURNAL
-- =========================

CREATE TABLE schema_migrations (
    name VARCHAR(100) NOT NULL PRIMARY KEY,
    kind VARCHAR(10) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO schema_migrations (name, kind) VALUES ('canonical-v1', 'record');
