<?php
declare(strict_types=1);
// Brings an existing database (historical, partially migrated, older main/Kanan shape) to the canonical schema.
// Default: dry run — detects the shape, runs every check and prints the plan without writing anything.
//   php tools/migrate.php
//   php tools/migrate.php --apply --database=<name> [--confirm-backup]
// --confirm-backup is required for any database whose name does not end with "_test": it states that a fresh
// backup of that database exists and was restored successfully elsewhere. Run with setup credentials (DDL).
// Output never contains e-mails, names, phones, passwords or hashes; only profile ids and counts.
require_once dirname(__DIR__).'/api/config/database.php';
require_once __DIR__.'/migration-lib.php';
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }

$args=array_slice($argv,1);
$apply=in_array('--apply',$args,true);
$confirmBackup=in_array('--confirm-backup',$args,true);
$target=null; foreach ($args as $a) if (str_starts_with($a,'--database=')) $target=substr($a,11);
foreach ($args as $a) if (!in_array($a,['--apply','--confirm-backup'],true) && !str_starts_with($a,'--database=')) { fwrite(STDERR,"Unknown option: $a\n"); exit(1); }

function report(array $plan, array $facts): void {
    echo 'Shape: '.$plan['shape']."\n";
    if ($plan['blockers']) { echo "Checks: BLOCKED — nothing will be changed\n"; foreach ($plan['blockers'] as $b) echo "  - $b\n"; }
    else echo "Checks: OK\n";
    if ($facts['account_candidates']) echo "Accounts: {$facts['account_candidates']} profile(s) with a legacy password; {$facts['bcrypt']} bcrypt hash(es) kept; ".count($facts['locked_ids'])." unrecognised value(s) not imported, account locked until reset".($facts['locked_ids']?' (profile ids '.implode(', ',$facts['locked_ids']).')':'')."\n";
    echo 'Plan: '.count($plan['steps'])." step(s)\n";
    foreach ($plan['steps'] as $i=>$s) { printf("  %02d [%-4s] %s — %s\n",$i+1,$s['kind'],$s['id'],$s['title']); foreach ($s['sql'] as $q) echo "         $q\n"; }
    if ($plan['deferred']) { echo "Deferred (reported only, never executed by this tool):\n"; foreach ($plan['deferred'] as $d) echo "  - $d\n"; }
}

try {
    $db=database();
    $db->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    $name=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    echo ($apply?'APPLY':'DRY RUN (no change is made)')." — database $name\n";
    $snapshot=schemaSnapshot($db);
    $facts=detectShape($snapshot)['shape']==='unknown'||!$snapshot['tables']?['account_candidates'=>0,'bcrypt'=>0,'locked_ids'=>[],'blockers'=>[]]:preflightData($db,$snapshot);
    $plan=planMigration($snapshot,$facts);
    report($plan,$facts);
    if ($plan['blockers']) exit(2);
    if (!$apply) { echo $plan['steps']?"To apply: php tools/migrate.php --apply --database=$name".(str_ends_with($name,'_test')?'':' --confirm-backup')."\n":"Nothing to do: the schema already follows the canonical contract.\n"; exit(0); }

    // Apply guards: the named database must be the one connected to, and a real database needs a tested backup.
    if ($target!==$name) { fwrite(STDERR,"Refused: --database must name the connected database ($name).\n"); exit(2); }
    if (!str_ends_with($name,'_test') && !$confirmBackup) { fwrite(STDERR,"Refused: --confirm-backup is required for $name (fresh backup taken and restore-tested).\n"); exit(2); }
    $stopAfter=str_ends_with($name,'_test')?getenv('MIGRATION_STOP_AFTER'):false; // test hook: simulated interruption
    $before=dataFingerprints($db,$snapshot);
    $journal=function(string $step,string $kind) use ($db): void { if (hasTable(schemaSnapshot($db),'schema_migrations')) $db->prepare('INSERT IGNORE INTO schema_migrations (name, kind) VALUES (?, ?)')->execute([$step,$kind]); };
    $done=[];
    // Recompute the plan from the real database before every step: an interrupted run resumes where it stopped.
    while (true) {
        $snapshot=schemaSnapshot($db); $facts=preflightData($db,$snapshot); $plan=planMigration($snapshot,$facts);
        if ($plan['blockers']) { echo "Stopped: new blocker(s)\n"; foreach ($plan['blockers'] as $b) echo "  - $b\n"; exit(2); }
        if (!$plan['steps']) break;
        $step=$plan['steps'][0];
        if (isset($done[$step['id']])) throw new RuntimeException("Step {$step['id']} did not take effect; stopped for review");
        echo "Applying {$step['id']}…\n";
        if ($step['kind']==='data') { $r=migrateAccounts($db); echo "  accounts created: ".($r['bcrypt']+count($r['locked_ids']))." ({$r['bcrypt']} bcrypt kept, ".count($r['locked_ids'])." locked".($r['locked_ids']?': profile ids '.implode(', ',$r['locked_ids']):'').")\n"; }
        else foreach ($step['sql'] as $q) $db->exec($q);
        $journal($step['id'],$step['kind']); $done[$step['id']]=true;
        if ($stopAfter===$step['id']) { echo "Interrupted after {$step['id']} (MIGRATION_STOP_AFTER)\n"; exit(3); }
    }
    $snapshot=schemaSnapshot($db);
    if ($problems=verifyCanonical($snapshot)) { echo "Not canonical after migration:\n"; foreach ($problems as $p) echo "  - $p\n"; exit(1); }
    $after=dataFingerprints($db,$snapshot); $diff=[];
    foreach ($before as $t=>$f) foreach (['count','ids','rows','max_id','auto_increment'] as $k) if (($after[$t][$k]??null)!==$f[$k]) $diff[]="$t.$k";
    if ($diff) { echo 'Data check FAILED ('.implode(', ',$diff).'): restore the backup and investigate.'."\n"; exit(1); }
    $journal(MIGRATION_CANONICAL,'record');
    echo "Done: canonical schema; counts, ids, row contents and AUTO_INCREMENT of ".implode(', ',array_keys($before))." unchanged.\n";
    if ($plan['deferred']) { echo "Still deferred:\n"; foreach ($plan['deferred'] as $d) echo "  - $d\n"; }
} catch (PDOException $e) {
    $hint=in_array($e->errorInfo[1]??null,[1044,1142,1227],true)?' (use setup credentials allowed to CREATE/ALTER/INDEX/REFERENCES)':'';
    fwrite(STDERR,'Migration failed: SQLSTATE '.$e->getCode().$hint."\n"); exit(1);
} catch (Throwable $e) { fwrite(STDERR,'Migration failed: '.$e->getMessage()."\n"); exit(1); }
