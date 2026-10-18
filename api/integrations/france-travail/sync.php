<?php
declare(strict_types=1);
// Optional CLI importer. This file is outside the public document root.
require_once dirname(__DIR__, 2).'/config/database.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function requestJson(string $url, array $headers, ?array $form = null, bool $rangeEnd = false): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>30,
        CURLOPT_HTTPHEADER=>$headers, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
    if ($form !== null) curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($form)]);
    $body=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
    if ($rangeEnd && $code===416) return ['resultats'=>[]];
    if ($body === false || !in_array($code,[200,206],true)) throw new RuntimeException('France Travail request failed (HTTP '.$code.')');
    $data=json_decode($body,true,64,JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new RuntimeException('Unexpected API response');
    return $data;
}
try {
    $client=envValue('FRANCE_TRAVAIL_CLIENT_ID'); $secret=envValue('FRANCE_TRAVAIL_CLIENT_SECRET');
    if ($client === '' || $secret === '') { fwrite(STDERR,"France Travail credentials missing. Seed data works independently.\n"); exit(2); }
    if (!extension_loaded('curl') || !extension_loaded('mbstring')) throw new RuntimeException('Enable curl and mbstring for this optional importer');
    $token=requestJson('https://entreprise.francetravail.fr/connexion/oauth2/access_token?realm=/partenaire',['Content-Type: application/x-www-form-urlencoded'],[
        'grant_type'=>'client_credentials','client_id'=>$client,'client_secret'=>$secret,'scope'=>envValue('FRANCE_TRAVAIL_SCOPE','api_offresdemploiv2 o2dsoffre')]);
    if (empty($token['access_token'])) throw new RuntimeException('No access token received');
    require __DIR__.'/import.php';
    $maximum=envValue('FRANCE_TRAVAIL_MAX_OFFERS','1000');
    if (!ctype_digit($maximum)) throw new RuntimeException('Invalid import limit');
    $result=syncFranceTravail(database(),static function(int $offset,int $end) use ($token,$argv): array {
        return requestJson('https://api.francetravail.io/partenaire/offresdemploi/v2/offres/search?'.http_build_query(['motsCles'=>$argv[1] ?? 'informatique','range'=>$offset.'-'.$end]),['Accept: application/json','Authorization: Bearer '.$token['access_token']],null,true);
    },(int)$maximum);
    echo 'Synced '.$result['count']." offers.\n";
    if (!$result['complete']) fwrite(STDERR,"Configured import limit reached; narrow the query or increase FRANCE_TRAVAIL_MAX_OFFERS (maximum 3000).\n");

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    // Never print OAuth responses, secrets, DSNs or database exception messages.
    fwrite(STDERR,"Import failed. Check credentials, extensions, database and provider availability.\n"); exit(1);
}
