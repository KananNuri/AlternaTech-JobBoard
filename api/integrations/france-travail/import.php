<?php
declare(strict_types=1);
function clip(string $value, int $limit): string { return mb_substr($value,0,$limit,'UTF-8'); }

/** Fetch bounded pages, committing each page so a later provider outage is safely retryable. */
function syncFranceTravail(PDO $db, callable $fetchPage, int $maximum=1000): array {
    if ($maximum<50 || $maximum>3000 || $maximum%50!==0) throw new InvalidArgumentException('Use a limit from 50 to 3000 in multiples of 50');
    if ((int)$db->query("SELECT GET_LOCK('alternatech_france_travail_sync',0)")->fetchColumn()!==1) throw new RuntimeException('An import is already running');
    try {
        $find=$db->prepare('SELECT id FROM companies WHERE name=? ORDER BY id LIMIT 1');
        $company=$db->prepare('INSERT INTO companies (name,description) VALUES (?,?)');
        $upsert=$db->prepare("INSERT INTO advertisements (company_id,title,short_description,description,location,contract_type,salary,working_time,source,external_id,source_url) VALUES (?,?,?,?,?,?,?,?,'FRANCE_TRAVAIL',?,?) ON DUPLICATE KEY UPDATE company_id=VALUES(company_id),title=VALUES(title),short_description=VALUES(short_description),description=VALUES(description),location=VALUES(location),contract_type=VALUES(contract_type),salary=VALUES(salary),working_time=VALUES(working_time),source_url=VALUES(source_url)");
        $count=0;
        for ($offset=0;$offset<$maximum;$offset+=50) {
            $payload=$fetchPage($offset,$offset+49); $offers=$payload['resultats']??null;
            if (!is_array($offers) || !array_is_list($offers) || count($offers)>50) throw new RuntimeException('Unexpected resultats response');
            $db->beginTransaction();
            foreach ($offers as $offer) {
                if (!is_array($offer) || !is_string($offer['id']??null) || $offer['id']==='' || strlen($offer['id'])>100 || !is_string($offer['intitule']??null) || trim($offer['intitule'])==='' || !is_string($offer['description']??null) || trim($offer['description'])==='') continue;
                $name=clip($offer['entreprise']['nom']??'France Travail — undisclosed employer',150);
                $find->execute([$name]); $companyId=$find->fetchColumn();
                if (!$companyId) { $company->execute([$name,'Employer from France Travail']); $companyId=$db->lastInsertId(); }
                $url=$offer['origineOffre']['urlOrigine']??null;
                if (!is_string($url) || strlen($url)>500 || !filter_var($url,FILTER_VALIDATE_URL) || !in_array(parse_url($url,PHP_URL_SCHEME),['http','https'],true)) $url=null;
                $upsert->execute([$companyId,clip($offer['intitule'],200),clip($offer['description'],500),clip($offer['description'],16000),clip($offer['lieuTravail']['libelle']??'France',150),clip($offer['typeContratLibelle']??$offer['typeContrat']??'',100),clip($offer['salaire']['libelle']??'',100),clip($offer['dureeTravailLibelle']??'',100),$offer['id'],$url]); $count++;
            }
            $db->commit();
            if (count($offers)<50) return ['count'=>$count,'complete'=>true];
        }
        return ['count'=>$count,'complete'=>false];
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    finally { $db->query("SELECT RELEASE_LOCK('alternatech_france_travail_sync')"); }
}
