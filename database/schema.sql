CREATE DATABASE IF NOT EXISTS alternatech
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE alternatech;

-- =========================
-- COMPANIES
-- =========================

CREATE TABLE IF NOT EXISTS companies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    email VARCHAR(150) NULL,
    phone VARCHAR(30) NULL,
    website VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =========================
-- CATEGORIES
-- =========================

CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
);

-- =========================
-- USERS
-- Login / authentication
-- =========================

CREATE TABLE IF NOT EXISTS users (
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
-- Applicant information
-- user_id is NULL for guest applicants
-- =========================

CREATE TABLE IF NOT EXISTS people (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    firstname VARCHAR(100) NOT NULL,
    lastname VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_people_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE SET NULL
);

-- =========================
-- ADVERTISEMENTS
-- =========================

CREATE TABLE IF NOT EXISTS advertisements (
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

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_advertisement_company
        FOREIGN KEY (company_id)
        REFERENCES companies(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_advertisement_category
        FOREIGN KEY (category_id)
        REFERENCES categories(id)
        ON DELETE SET NULL,

    UNIQUE KEY unique_external_job (source, external_id)
);

-- =========================
-- APPLICATIONS
-- =========================

CREATE TABLE IF NOT EXISTS applications (
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

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_application_advertisement
        FOREIGN KEY (advertisement_id)
        REFERENCES advertisements(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_application_person
        FOREIGN KEY (person_id)
        REFERENCES people(id)
        ON DELETE CASCADE
);
