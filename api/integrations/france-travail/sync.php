<?php
declare(strict_types=1);
// Optional CLI importer. This file is outside the public document root.
require_once dirname(__DIR__, 2).'/config/database.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function requestJson(string $url, array $headers, ?array $form = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>30,
        CURLOPT_HTTPHEADER=>$headers, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
    if ($form !== null) curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($form)]);
    $body=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
    if ($body === false || !in_array($code,[200,206],true)) throw new RuntimeException('France Travail request failed (HTTP '.$code.')');
    $data=json_decode($body,true,64,JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new RuntimeException('Unexpected API response');
    return $data;
}
function clip(string $value, int $limit): string { return mb_substr($value,0,$limit,'UTF-8'); }
try {
    $client=envValue('FRANCE_TRAVAIL_CLIENT_ID'); $secret=envValue('FRANCE_TRAVAIL_CLIENT_SECRET');
    if ($client === '' || $secret === '') { fwrite(STDERR,"France Travail credentials missing. Seed data works independently.\n"); exit(2); }
    if (!extension_loaded('curl') || !extension_loaded('mbstring')) throw new RuntimeException('Enable curl and mbstring for this optional importer');
    $token=requestJson('https://entreprise.francetravail.fr/connexion/oauth2/access_token?realm=/partenaire',['Content-Type: application/x-www-form-urlencoded'],[
        'grant_type'=>'client_credentials','client_id'=>$client,'client_secret'=>$secret,'scope'=>envValue('FRANCE_TRAVAIL_SCOPE','api_offresdemploiv2 o2dsoffre')]);
    if (empty($token['access_token'])) throw new RuntimeException('No access token received');
    // One bounded batch only; pagination remains Charles's scope.
    $payload=requestJson('https://api.francetravail.io/partenaire/offresdemploi/v2/offres/search?'.http_build_query(['motsCles'=>$argv[1] ?? 'informatique','range'=>'0-49']),['Accept: application/json','Authorization: Bearer '.$token['access_token']]);
    $offers=$payload['resultats'] ?? null;
    if (!is_array($offers)) throw new RuntimeException('Missing resultats in API response');
    $db=database(); $count=0; $db->beginTransaction();
    $find=$db->prepare('SELECT id FROM companies WHERE name=? ORDER BY id LIMIT 1');
    $company=$db->prepare('INSERT INTO companies (name,description) VALUES (?,?)');
    $upsert=$db->prepare("INSERT INTO advertisements (company_id,title,short_description,description,location,contract_type,salary,working_time,source,external_id,source_url) VALUES (?,?,?,?,?,?,?,?,'FRANCE_TRAVAIL',?,?) ON DUPLICATE KEY UPDATE company_id=VALUES(company_id),title=VALUES(title),short_description=VALUES(short_description),description=VALUES(description),location=VALUES(location),contract_type=VALUES(contract_type),salary=VALUES(salary),working_time=VALUES(working_time),source_url=VALUES(source_url)");
    foreach ($offers as $offer) {
        if (!is_array($offer) || !is_string($offer['id'] ?? null) || $offer['id']==='' || strlen($offer['id'])>100 || empty($offer['intitule']) || empty($offer['description'])) continue;
        // Existing imported company is reused. Companies API remains untouched.
        $name=clip($offer['entreprise']['nom'] ?? 'France Travail — undisclosed employer',150);
        $find->execute([$name]); $companyId=$find->fetchColumn();
        if (!$companyId) { $company->execute([$name,'Employer from France Travail']); $companyId=$db->lastInsertId(); }
        $url=$offer['origineOffre']['urlOrigine'] ?? null;
        if (!is_string($url) || strlen($url)>500 || !filter_var($url,FILTER_VALIDATE_URL) || !in_array(parse_url($url,PHP_URL_SCHEME),['http','https'],true)) $url=null;
        $upsert->execute([$companyId,clip($offer['intitule'],200),clip($offer['description'],500),clip($offer['description'],16000),clip($offer['lieuTravail']['libelle'] ?? 'France',150),clip($offer['typeContratLibelle'] ?? $offer['typeContrat'] ?? '',100),clip($offer['salaire']['libelle'] ?? '',100),clip($offer['dureeTravailLibelle'] ?? '',100),$offer['id'],$url]); $count++;
    }
    $db->commit(); echo "Synced $count offers.\n";
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    // Never print OAuth responses, secrets, DSNs or database exception messages.
    fwrite(STDERR,"Import failed. Check credentials, extensions, database and provider availability.\n"); exit(1);
}
