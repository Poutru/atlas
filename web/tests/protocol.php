<?php
declare(strict_types=1);
require __DIR__.'/../src/Protocol.php';
use Atlas\StrictJson; use Atlas\Problem;
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function rejects(callable $fn,string $label):void{try{$fn();}catch(Problem){check(true,$label);return;}throw new RuntimeException('Accepted invalid input: '.$label);}
$fixtures=__DIR__.'/../../tests/fixtures/';
foreach(['canonical','canonical-case','canonical-escaping'] as $name){$input=file_get_contents($fixtures.$name.'-input.json');$v=StrictJson::decode($input);unset($v->signature);check(Atlas\canonical($v)===file_get_contents($fixtures.$name.'-bytes.bin'),$name.' bytes match upstream');}
foreach(['{"a":1,"a":2}','{"a":{"x":1,"x":2}}','{"n":1e2}','{"n":1.0}','{"n":-1}','{"n":9007199254740992}','{"a":1}garbage','{"a": "\uD800"}'] as $raw)rejects(fn()=>StrictJson::decode($raw),'strict JSON '.substr($raw,0,50));
foreach(['http://example.com/x','https://127.0.0.1/x','https://example.com:443/x','https://u@example.com/x','https://example.com/x?q=1','https://example.com/x#f','https://2130706433/x','https://[::1]/x'] as $url)rejects(fn()=>Atlas\validUri($url),'unsafe URI '.$url);
$kp=sodium_crypto_sign_seed_keypair(str_repeat('a',32));$sk=sodium_crypto_sign_secretkey($kp);$v=(object)['version'=>1,'componentId'=>'onym:component:example','operator'=>'onym:key:'.bin2hex(sodium_crypto_sign_publickey($kp)),'seat'=>'transport.message','validUntil'=>gmdate('Y-m-d\TH:i:s\Z',time()+86400),'implementationProfileId'=>'onym:message-implementation:nostr-courier-v1'];
$v->signature=base64_encode(sodium_crypto_sign_detached(Atlas\canonical($v),$sk));$raw=Atlas\canonical($v);check(Atlas\verifyService($raw)['componentId']===$v->componentId,'valid Ed25519 manifest');
$v->componentId='onym:component:tampered';rejects(fn()=>Atlas\verifyService(Atlas\canonical($v)),'tampered manifest');
echo "Protocol checks complete.\n";

// Node-generated BSN registry fixture exercises cross-language domain separation.
$bsnRaw=file_get_contents($fixtures.'bsn-naming-manifest.json');
$checked=Atlas\verifyService($bsnRaw);
check($checked['seat']==='naming.association' && $checked['validUntil']===null,'BSN registry profile without manifest expiry');
check(in_array('onym:naming-profile:qualified-association-v1',$checked['profiles'],true),'naming profile preserved');
function bsnSigned(object $v,string $prefix="onym-bsn-np-v1:manifest\n"):string {
    unset($v->signature);
    $sk=sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(str_repeat(chr(41),32)));
    $v->signature=base64_encode(sodium_crypto_sign_detached($prefix.Atlas\canonical($v),$sk));
    return Atlas\canonical($v);
}
$bsn=json_decode($bsnRaw);
rejects(fn()=>Atlas\verifyService(bsnSigned(clone $bsn,'')),'BSN signature cannot use generic signature scheme');
rejects(fn()=>Atlas\verifyService(bsnSigned(clone $bsn,"onym-bsn-np-v1:record\n")),'BSN signature cannot use record domain');
$t=clone $bsn;$t->namespace='tampered.example';rejects(fn()=>Atlas\verifyService(Atlas\canonical($t)),'tampered BSN manifest');
$t=clone $bsn;$t->trustRoot=clone $t->trustRoot;$t->trustRoot->publicKey=str_repeat('0',64);rejects(fn()=>Atlas\verifyService(bsnSigned($t)),'BSN trust root must match operator');
$t=clone $bsn;$t->seat='transport.message';rejects(fn()=>Atlas\verifyService(bsnSigned($t)),'BSN profile cannot exempt another seat');
$t=clone $bsn;$t->implementationProfileId='onym:naming-implementation:unknown';rejects(fn()=>Atlas\verifyService(bsnSigned($t)),'unknown profile cannot use BSN domain');
rejects(fn()=>Atlas\verifyService(bsnSigned($t,'')),'unknown profile must retain expiry requirement');
$t=clone $bsn;$t->validUntil='2000-01-01T00:00:00Z';rejects(fn()=>Atlas\verifyService(bsnSigned($t)),'BSN expiry checked when present');
