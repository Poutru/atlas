<?php
declare(strict_types=1);
require __DIR__.'/../src/App.php';
use Atlas\App;use Atlas\Problem;
function ok(bool $b,string $s):void{if(!$b)throw new RuntimeException('FAIL '.$s);echo "PASS $s\n";}
function denied(callable $f,string $s):void{try{$f();}catch(Problem){ok(true,$s);return;}throw new RuntimeException('FAIL '.$s);}
$dir=sys_get_temp_dir().'/atlas-test-'.bin2hex(random_bytes(6));mkdir($dir,0700);putenv('ATLAS_DATA='.$dir);
$seed=random_bytes(32);file_put_contents($dir.'/operator.seed',bin2hex($seed));$operator='onym:key:'.bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed)));
file_put_contents($dir.'/config.json',json_encode(['baseUrl'=>'https://catalog.example.com','operator'=>$operator,'cli'=>getenv('ATLAS_CLI')?:'/opt/atlas/bin/onym-discovery','adminHash'=>password_hash('test-password-not-used',PASSWORD_DEFAULT),'rateSecret'=>bin2hex(random_bytes(32))]));
$kp=sodium_crypto_sign_seed_keypair(str_repeat('b',32));$sk=sodium_crypto_sign_secretkey($kp);
function signed(object $v,string $sk):string{unset($v->signature);$v->signature=base64_encode(sodium_crypto_sign_detached(Atlas\canonical($v),$sk));return Atlas\canonical($v);}
$v=(object)['version'=>1,'componentId'=>'onym:component:test-courier','operator'=>'onym:key:'.bin2hex(sodium_crypto_sign_publickey($kp)),'seat'=>'transport.message','name'=>'Test courier','validUntil'=>gmdate('Y-m-d\TH:i:s\Z',time()+86400),'implementationProfileId'=>'onym:message-implementation:nostr-courier-v1'];$raw=signed($v,$sk);$online=true;
$a=new App(function($url)use(&$raw,&$online){if(!$online)throw new Problem('offline');return $raw;});$a->migrate();$a->lock(fn()=>$a->publish());
$a->submit('https://courier.example.com/manifest.json');$snap=json_decode(file_get_contents($dir.'/published/catalogs/public-services.json'));ok(count($snap->entries)===1,'valid submission auto publishes');ok($snap->sequence===2,'sequence advances');
$a->submit('https://courier.example.com/manifest.json');ok((int)$a->query('SELECT COUNT(*) FROM services')->fetchColumn()===1,'duplicate submission idempotent');
$v->name='Changed conditions';$raw=signed($v,$sk);$a->submit('https://courier.example.com/manifest.json');ok($a->all(true)[0]['hasPending'],'changed bytes held for review');$snap=json_decode(file_get_contents($dir.'/published/catalogs/public-services.json'));ok(count($snap->entries)===0,'changed digest not silently included');
$a->edit(['id'=>$v->componentId,'action'=>'accept']);ok($a->all(true)[0]['digest']===Atlas\digest($raw),'owner accepts exact reviewed bytes');
$a->edit(['id'=>$v->componentId,'action'=>'edit','name'=>'Editorial title','description'=>'Editorial text','category'=>'Storage','relationship'=>'none']);ok($a->query('SELECT raw FROM services')->fetchColumn()===$raw,'editorial edit preserves signed bytes');
$a->edit(['id'=>$v->componentId,'action'=>'disable','note'=>'Test exclusion']);denied(fn()=>$a->submit('https://courier.example.com/manifest.json'),'disabled component cannot auto reappear');
$v->componentId='onym:component:another-id';$raw=signed($v,$sk);denied(fn()=>$a->submit('https://other.example.com/manifest.json'),'blocked owner cannot rename to bypass');
$v->componentId='onym:component:test-courier';$raw=signed($v,$sk);$a->edit(['id'=>$v->componentId,'action'=>'restore']);ok(!$a->all(true)[0]['blocked'],'owner can restore after validation');
$online=false;$a->refresh();ok($a->all(true)[0]['state']==='unavailable','failed fetch visibly unavailable');$snap=json_decode(file_get_contents($dir.'/published/catalogs/public-services.json'));ok(count($snap->entries)===0,'unavailable excluded from current snapshot');
$online=true;$a->refresh();ok($a->all(true)[0]['state']==='active','recovery revalidates pinned bytes');
for($i=0;$i<3;$i++)$a->limit('test',3,60);denied(fn()=>$a->limit('test',3,60),'rate limit enforced');
$files=glob($dir.'/published/catalogs/public-services-*.json');ok(count($files)>=8,'immutable history retained');
$prev=null;foreach($files as $f){$s=json_decode(file_get_contents($f));$map[$s->sequence]=$f;}ksort($map);foreach($map as $f){$args=['verify','snapshot',$f,'--manifest',$dir.'/published/manifest.json'];if($prev)$args=array_merge($args,['--previous',$prev]);$a->runCli($args);$prev=$f;}ok(true,'all published snapshots verify with upstream CLI');
echo "Integration data: $dir\n";

$raw=file_get_contents(__DIR__.'/../../tests/fixtures/bsn-naming-manifest.json');
$bsnResult=$a->submit('https://names.example.com/manifest.json');
ok($bsnResult['state']==='active','BSN profile auto publishes');
$bsnSnapshot=json_decode(file_get_contents($dir.'/published/catalogs/public-services.json'));
$bsnEntries=array_values(array_filter($bsnSnapshot->entries,fn($e)=>$e->componentId==='onym:component:atlas-bsn-np'));
ok(count($bsnEntries)===1 && $bsnEntries[0]->manifest->digest===Atlas\digest($raw),'BSN snapshot pins original signed bytes');
$a->submit('https://names.example.com/manifest.json');
ok((int)$a->query('SELECT COUNT(*) FROM services WHERE id=?',['onym:component:atlas-bsn-np'])->fetchColumn()===1,'BSN duplicate submission idempotent');
$a->runCli(['verify','snapshot',$dir.'/published/catalogs/public-services.json','--manifest',$dir.'/published/manifest.json','--previous',$prev]);
ok(true,'BSN listing snapshot verifies with upstream CLI');
$bad=json_decode($raw);$bad->namespace='tampered.example';$raw=json_encode($bad);
denied(fn()=>$a->submit('https://names.example.com/manifest.json'),'tampered BSN cannot update listing');
