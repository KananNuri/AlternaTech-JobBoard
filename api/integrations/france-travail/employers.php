<?php
declare(strict_types=1);

/** Call inside the import transaction and under the importer advisory lock. */
function importedEmployer(PDO $db, string $externalId, string $name): int {
    $find=$db->prepare("SELECT company_id FROM advertisements WHERE source='FRANCE_TRAVAIL' AND external_id=?");
    $find->execute([$externalId]); $companyId=(int)$find->fetchColumn();
    if ($companyId) {
        $lock=$db->prepare('SELECT id FROM companies WHERE id=? FOR UPDATE'); $lock->execute([$companyId]); $lock->fetchColumn();
        $unsafe=$db->prepare("SELECT (SELECT COUNT(*) FROM company_members WHERE company_id=?) + (SELECT COUNT(*) FROM advertisements WHERE company_id=? AND source='LOCAL')");
        $unsafe->execute([$companyId,$companyId]);
        if ((int)$unsafe->fetchColumn()>0) throw new RuntimeException('Imported employer is shared with local recruiters/offers');
        return $companyId;
    }
    $insert=$db->prepare('INSERT INTO companies (name,description) VALUES (?,?)');
    $insert->execute([$name,'Employer from France Travail']);
    return (int)$db->lastInsertId();
}
