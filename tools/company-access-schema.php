<?php
declare(strict_types=1);
// Additive extension; existing recruiter membership semantics remain unchanged.
function companyAccessStatements(PDO $db): array {
    $types=[];
    foreach (['users','people','companies'] as $table) {
        $q=$db->query("SHOW COLUMNS FROM `$table` LIKE 'id'"); $column=$q->fetch(PDO::FETCH_ASSOC);
        $type=strtolower($column['Type']??'');
        if (!preg_match('/^int(?:\([0-9]+\))?(?: unsigned)?$/D',$type)) throw new RuntimeException('Canonical INT identifiers required');
        $types[$table]=$type;
    }
    $u=$types['users'];$p=$types['people'];$c=$types['companies'];
    return [
        "CREATE TABLE IF NOT EXISTS company_proposals (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, requester_id $u NOT NULL,
            name VARCHAR(150) NOT NULL, email VARCHAR(150) NOT NULL, website VARCHAR(255) NULL,
            registration_number VARCHAR(50) NULL, evidence TEXT NOT NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            company_id $c NULL, decided_by $u NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            decided_at DATETIME NULL, INDEX idx_proposal_requester(requester_id,status), INDEX idx_proposal_status(status,id),
            FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
            FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS company_join_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id $c NOT NULL, person_id $p NOT NULL,
            message VARCHAR(2000) NOT NULL, status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            decided_by $u NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, decided_at DATETIME NULL,
            UNIQUE KEY unique_company_join(company_id,person_id), INDEX idx_join_person(person_id),
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
            FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
            FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS company_managers (
            company_id $c PRIMARY KEY, person_id $p NOT NULL, appointed_by $u NULL,
            appointed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (company_id,person_id) REFERENCES company_members(company_id,person_id) ON DELETE CASCADE,
            FOREIGN KEY (appointed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
}
