<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/auth/google/functions.php';
function checkGoogle(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function fixtureToken(array $claims): string {return 'synthetic.'.rtrim(strtr(base64_encode(json_encode($claims,JSON_THROW_ON_ERROR)),'+/','-_'),'=').'.test';}
$now=1700000000;$valid=['iss'=>'https://accounts.google.com','aud'=>'test-client','exp'=>$now+300,'iat'=>$now,'nonce'=>'test-nonce','sub'=>'123456','email'=>'google@example.invalid','email_verified'=>true];
checkGoogle(googleClaimsFromExchange(fixtureToken($valid),'test-client','test-nonce',$now)['sub']==='123456','trusted-exchange claims');
foreach(['issuer'=>['iss'=>'https://attacker.invalid'],'audience'=>['aud'=>'wrong'],'presenter'=>['azp'=>'wrong'],'expired'=>['exp'=>$now],'future'=>['iat'=>$now+120],'nonce'=>['nonce'=>'wrong'],'unverified'=>['email_verified'=>false],'missing subject'=>['sub'=>''],'invalid email'=>['email'=>'not-email']] as $label=>$change){
 $rejected=false;try{googleClaimsFromExchange(fixtureToken(array_replace($valid,$change)),'test-client','test-nonce',$now);}catch(Throwable){$rejected=true;}checkGoogle($rejected,"reject $label");
}
foreach(['invalid','a.%%%25.b',str_repeat('a',16385)] as $token){$rejected=false;try{googleClaimsFromExchange($token,'test-client','test-nonce',$now);}catch(Throwable){$rejected=true;}checkGoogle($rejected,'reject malformed/oversized token');}
$pending=['state'=>'test-state','created'=>$now];
checkGoogle(googleState($pending,'test-state',$now+599),'valid state');
checkGoogle(!googleState($pending,'wrong',$now),'wrong state');
checkGoogle(!googleState($pending,['test-state'],$now),'non-string state');
checkGoogle(!googleState($pending,'test-state',$now+600),'expired state');
checkGoogle(!googleState($pending,'test-state',$now-1),'future state');
checkGoogle(!googleState([],'test-state',$now),'absent/replayed state');
putenv('GOOGLE_CLIENT_ID=test-client');putenv('GOOGLE_CLIENT_SECRET=test-only');
foreach(['https://example.invalid/api/auth/google/callback'=>true,'http://127.0.0.1:8080/api/auth/google/callback'=>true,'http://example.invalid/api/auth/google/callback'=>false,'https://user@example.invalid/api/auth/google/callback'=>false,'https://example.invalid/other'=>false] as $uri=>$validUri){putenv('GOOGLE_REDIRECT_URI='.$uri);checkGoogle(googleConfig()['enabled']===($validUri&&extension_loaded('curl')),'redirect config validation');}
