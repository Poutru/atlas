<?php
declare(strict_types=1);
namespace Atlas;
require_once __DIR__.'/Protocol.php';
final class App {
    public \PDO $db;
    public array $config;
    public string $data;
    private $fetcher;
    public function __construct(?callable $fetcher=null){
        $this->fetcher=$fetcher ?? __NAMESPACE__."\\fetchManifest";
        $this->data=getenv('ATLAS_DATA')?:'/var/lib/atlas';
        $file=$this->data.'/config.json';if(!is_file($file))throw new Problem('Сервис ещё не настроен.',503);
        $this->config=json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
        $this->db=new \PDO('sqlite:'.$this->data.'/atlas.sqlite',null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL;');
    }
    public function migrate():void {
        $this->db->exec('CREATE TABLE IF NOT EXISTS services(id TEXT PRIMARY KEY, operator TEXT NOT NULL, seat TEXT NOT NULL, url TEXT NOT NULL, digest TEXT NOT NULL, raw TEXT NOT NULL, profiles TEXT NOT NULL, name TEXT NOT NULL, description TEXT NOT NULL DEFAULT "", category TEXT NOT NULL DEFAULT "", state TEXT NOT NULL DEFAULT "active", note TEXT NOT NULL DEFAULT "", relationship TEXT NOT NULL DEFAULT "none", listed_at TEXT NOT NULL, checked_at TEXT NOT NULL, valid_until TEXT, pending_raw TEXT, last_error TEXT, blocked INTEGER NOT NULL DEFAULT 0);
        CREATE TABLE IF NOT EXISTS events(id INTEGER PRIMARY KEY AUTOINCREMENT, component TEXT, action TEXT NOT NULL, message TEXT NOT NULL, at TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS limits(key TEXT PRIMARY KEY, count INTEGER NOT NULL, expires INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS meta(key TEXT PRIMARY KEY, value TEXT NOT NULL);');
    }
    public function query(string $sql,array $args=[]):\PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    public function lock(callable $fn):mixed{$f=fopen($this->data.'/write.lock','c');if(!flock($f,LOCK_EX))throw new Problem('Каталог занят.',503);try{return $fn();}finally{flock($f,LOCK_UN);fclose($f);}}
    public function event(string $id,string $action,string $message):void{$this->query('INSERT INTO events(component,action,message,at) VALUES(?,?,?,?)',[$id,$action,$message,gmdate('Y-m-d\TH:i:s\Z')]);}
    public function limit(string $bucket,int $max,int $seconds):void{
        $key=hash_hmac('sha256',$bucket,$this->config['rateSecret']);$now=time();
        $this->query('INSERT INTO limits(key,count,expires) VALUES(?,1,?) ON CONFLICT(key) DO UPDATE SET count=CASE WHEN expires<? THEN 1 ELSE count+1 END, expires=CASE WHEN expires<? THEN ? ELSE expires END',[$key,$now+$seconds,$now,$now,$now+$seconds]);
        if((int)$this->query('SELECT count FROM limits WHERE key=?',[$key])->fetchColumn()>$max)throw new Problem('Слишком много попыток. Попробуйте позже.',429);
    }
    public function all(bool $admin=false):array{
        $publication=json_decode($this->query('SELECT value FROM meta WHERE key="published"')->fetchColumn()?:'null',true);
        $rows=$this->query('SELECT * FROM services '.($admin?'':'WHERE blocked=0 AND state!="disabled" ').'ORDER BY listed_at,id')->fetchAll();
        foreach($rows as &$r){$doc=json_decode($r['raw']);$r['profiles']=json_decode($r['profiles']);$r['endpoints']=$doc->endpoints??[];$r['offers']=$doc->offers??[];$r['limits']=$doc->limits??new \stdClass();$r['hasPending']=!empty($r['pending_raw']);$r['published']=isset($publication['digests'][$r['id']])&&$publication['digests'][$r['id']]===$r['digest']&&strtotime($publication['expiresAt'])>time();$r['fresh']=$r['published']&&$r['state']==='active'&&(!$r['valid_until']||strtotime($r['valid_until'])>time());
            if($admin&&$r['pending_raw']){$p=json_decode($r['pending_raw']);$r['pendingDocument']=$p;$r['currentDocument']=$doc;}
            unset($r['raw'],$r['pending_raw']);if(!$admin)unset($r['last_error']);
        }return $rows;
    }
    public function submit(string $url):array{
        validUri($url);$raw=($this->fetcher)($url);$v=verifyService($raw);
        return $this->lock(function()use($url,$raw,$v){
            $existing=$this->query('SELECT * FROM services WHERE id=?',[$v['componentId']])->fetch();
            // Block by both identity and owner: an owner cannot evade exclusion with a new componentId.
            if($this->query('SELECT 1 FROM services WHERE blocked=1 AND (id=? OR operator=?) LIMIT 1',[$v['componentId'],$v['operator']])->fetchColumn())throw new Problem('Добавление этого сервиса отключено владельцем каталога.',403);
            if($existing){
                if($existing['url']!==$url || $existing['operator']!==$v['operator'])throw new Problem('Этот идентификатор уже закреплён. Изменение адреса или ключа возможно только через владельца каталога.',409);
                if($existing['digest']===$v['digest'])return ['message'=>'Этот сервис уже добавлен.','id'=>$v['componentId'],'state'=>$existing['state']];
                $this->query('UPDATE services SET pending_raw=?,state="review",last_error=NULL,checked_at=? WHERE id=?',[$raw,gmdate('Y-m-d\TH:i:s\Z'),$v['componentId']]);
                $this->event($v['componentId'],'review','Получен изменённый манифест. Ожидает решения владельца.');$this->publish();
                return ['message'=>'Изменения отправлены владельцу на проверку.','id'=>$v['componentId'],'state'=>'review'];
            }
            if((int)$this->query('SELECT COUNT(*) FROM services WHERE blocked=0')->fetchColumn()>=500)throw new Problem('Достигнут лимит каталога. Обратитесь к владельцу.',409);
            $now=gmdate('Y-m-d\TH:i:s\Z');$this->query('INSERT INTO services(id,operator,seat,url,digest,raw,profiles,name,listed_at,checked_at,valid_until) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$v['componentId'],$v['operator'],$v['seat'],$url,$v['digest'],$raw,json_encode($v['profiles']),$v['name'],$now,$now,$v['validUntil']]);
            $this->event($v['componentId'],'added','Автоматически добавлен после проверки подписи и базового формата.');$this->publish();
            return ['message'=>'Сервис проверен и опубликован в каталоге.','id'=>$v['componentId'],'state'=>'active'];
        });
    }
    public function edit(array $input):void{
        $id=(string)($input['id']??'');$action=(string)($input['action']??'edit');
        // Network fetch before acquiring the publication lock.
        $newRaw=null;if(in_array($action,['restore','accept','refresh'],true)){$row=$this->query('SELECT * FROM services WHERE id=?',[$id])->fetch();if(!$row)throw new Problem('Сервис не найден.',404);$newRaw=($this->fetcher)($row['url']);verifyService($newRaw);}
        $this->lock(function()use($id,$action,$input,$newRaw){
            $row=$this->query('SELECT * FROM services WHERE id=?',[$id])->fetch();if(!$row)throw new Problem('Сервис не найден.',404);
            $note=trim((string)($input['note']??''));if(mb_strlen($note)>1000)throw new Problem('Комментарий длиннее 1000 символов.');
            if($action==='disable'){$this->query('UPDATE services SET state="disabled",blocked=1,note=? WHERE id=?',[$note,$id]);$this->event($id,'disabled',$note?:'Отключён владельцем каталога.');}
            elseif(in_array($action,['restore','accept','refresh'],true)){
                $v=verifyService($newRaw);if($v['componentId']!==$id||$v['operator']!==$row['operator'])throw new Problem('Изменён идентификатор или ключ. Требуется отдельная проверка владельца; автоматическая замена запрещена.');
                if($action==='refresh' && $v['digest']!==$row['digest']){$this->query('UPDATE services SET pending_raw=?,state="review",checked_at=? WHERE id=?',[$newRaw,gmdate('Y-m-d\TH:i:s\Z'),$id]);$this->event($id,'review','При проверке обнаружены новые данные.');}
                else{if($action==='accept'&&(!$row['pending_raw']||digest($row['pending_raw'])!==$v['digest']))throw new Problem('Манифест снова изменился. Сначала обновите сведения и просмотрите изменения.',409);
                    $this->query('UPDATE services SET raw=?,digest=?,profiles=?,valid_until=?,seat=?,state="active",blocked=0,pending_raw=NULL,last_error=NULL,checked_at=? WHERE id=?',[$newRaw,$v['digest'],json_encode($v['profiles']),$v['validUntil'],$v['seat'],gmdate('Y-m-d\TH:i:s\Z'),$id]);$this->event($id,$action,'Манифест проверен. Запись активна.');}
            }elseif($action==='warning'){$this->query('UPDATE services SET note=?,state="warning" WHERE id=?',[$note,$id]);$this->event($id,'warning',$note?:'Предупреждение владельца.');}
            elseif($action==='edit'){
                $name=trim((string)($input['name']??''));$description=trim((string)($input['description']??''));$category=trim((string)($input['category']??''));$rel=$input['relationship']??'none';
                if(!$name||mb_strlen($name)>100||mb_strlen($description)>2000||mb_strlen($category)>80||!in_array($rel,['none','common-owner'],true))throw new Problem('Проверьте длину текста и раскрытие связи.');
                $this->query('UPDATE services SET name=?,description=?,category=?,note=?,relationship=? WHERE id=?',[$name,$description,$category,$note,$rel,$id]);$this->event($id,'edited','Обновлена редакционная карточка. Подписанные данные оператора не изменены.');
            }else throw new Problem('Неизвестное действие.');$this->publish();
        });
    }
    public function runCli(array $args):string {
        $proc=proc_open(array_merge([$this->config['cli']],$args),[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($proc))throw new Problem('Не удалось запустить публикацию.',503);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);
        if($code!==0){error_log('Atlas CLI: '.$err);throw new Problem('Запись сохранена, но публикация требует внимания администратора.',503);}return $out;
    }
    public function publish():void {
        $dir=$this->data.'/published';if(!is_dir($dir))mkdir($dir,0700,true);
        $stage=$this->data.'/stage-'.bin2hex(random_bytes(6));mkdir($stage,0700);$base=$this->config['baseUrl'];
        try{
            $policy=file_get_contents(__DIR__.'/../policy.md');$privacy=file_get_contents(__DIR__.'/../privacy.md');
            $rows=$this->query('SELECT * FROM services WHERE blocked=0 AND state!="disabled" ORDER BY listed_at,id')->fetchAll();$entries=[];$accepted=[];$digests=[];
            foreach($rows as $row){if($row['valid_until']&&strtotime($row['valid_until'])<=time())continue;
                // Changed/unreachable destinations are excluded until reviewed; old digests never silently follow new bytes.
                if(in_array($row['state'],['review','unavailable'],true))continue;
                $e=['componentId'=>$row['id'],'seatType'=>$row['seat'],'manifest'=>['uri'=>$row['url'],'digest'=>$row['digest']],'operator'=>$row['operator'],'profiles'=>json_decode($row['profiles']),'listedAt'=>$row['listed_at'],'reviewedAt'=>$row['checked_at'],'relationship'=>$row['relationship'],'placement'=>'policy-ranked'];
                if($row['state']==='warning')$e['status']=['state'=>'warning','uri'=>$base.'/service/'.rawurlencode($row['id'])];$entries[]=$e;$accepted[]=$row['id'];$digests[$row['id']]=$row['digest'];
            }
            $config=['catalogId'=>'public-services','providerId'=>'onym:component:atlas','policyDigest'=>digest($policy),'expiryDays'=>7,'entries'=>$entries];
            file_put_contents($stage.'/config.json',json_encode($config,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
            $manifest=['version'=>1,'implementationProfileId'=>'onym:discovery-implementation:static-ed25519-v1','providerId'=>'onym:component:atlas','operator'=>$this->config['operator'],'seat'=>'discovery','catalogs'=>[['catalogId'=>'public-services','snapshot'=>$base.'/catalogs/public-services.json','audience'=>'public','seatTypes'=>['*'],'policy'=>digest($policy),'policyUri'=>$base.'/policy.md']],'capabilities'=>['signed-snapshot-v1','local-filtering-v1'],'privacyProfile'=>digest($privacy),'privacyProfileUri'=>$base.'/privacy.md','offers'=>[],'validUntil'=>gmdate('Y-m-d\TH:i:s\Z',time()+365*86400)];
            file_put_contents($stage.'/source.json',json_encode($manifest));$seed=$this->data.'/operator.seed';
            $this->runCli(['sign-manifest','--seed',$seed,$stage.'/source.json','--out',$stage.'/manifest.json']);
            $args=['build-snapshot','--seed',$seed,'--config',$stage.'/config.json','--out',$stage.'/public-services.json'];$previous=$dir.'/catalogs/public-services.json';
            if(is_file($previous))$args=array_merge($args,['--previous',$previous]);$this->runCli($args);
            $this->runCli(['verify','manifest',$stage.'/manifest.json','--sig',$stage.'/manifest.json.sig']);
            $args=['verify','snapshot',$stage.'/public-services.json','--manifest',$stage.'/manifest.json','--sig',$stage.'/public-services.json.sig'];if(is_file($previous))$args=array_merge($args,['--previous',$previous]);$this->runCli($args);
            $s=json_decode(file_get_contents($stage.'/public-services.json'),true);$sequence=$s['sequence'];
            if(!is_dir($dir.'/catalogs'))mkdir($dir.'/catalogs',0700);
            foreach(['public-services-'.$sequence.'.json','public-services-'.$sequence.'.json.sig'] as $f){$dest=$dir.'/catalogs/'.$f;if(is_file($dest)&&file_get_contents($dest)!==file_get_contents($stage.'/'.$f))throw new Problem('Конфликт истории каталога.',503);if(!is_file($dest))rename($stage.'/'.$f,$dest);}
            // Manifest first per profile policy-transition rule. Atomic rename for each artifact.
            foreach(['policy.md'=>$policy,'privacy.md'=>$privacy] as $f=>$content){file_put_contents($stage.'/'.$f,$content);rename($stage.'/'.$f,$dir.'/'.$f);}
            foreach(['manifest.json','manifest.json.sig'] as $f)rename($stage.'/'.$f,$dir.'/'.$f);
            foreach(['public-services.json','public-services.json.sig'] as $f)rename($stage.'/'.$f,$dir.'/catalogs/'.$f);
            $this->query('INSERT INTO meta(key,value) VALUES("published",?) ON CONFLICT(key) DO UPDATE SET value=excluded.value',[json_encode(['sequence'=>$sequence,'generatedAt'=>$s['generatedAt'],'expiresAt'=>$s['expiresAt'],'digest'=>digest(file_get_contents($previous)),'ids'=>$accepted,'digests'=>$digests])]);
        }finally{foreach(glob($stage.'/*')?:[] as $f)unlink($f);rmdir($stage);}
    }
    public function refresh():array {
        $rows=$this->query('SELECT id,url,digest,operator FROM services WHERE blocked=0')->fetchAll();$result=[];
        foreach($rows as $r){try{$raw=($this->fetcher)($r['url']);$v=verifyService($raw);$error=null;}catch(\Throwable $e){$raw=null;$v=null;$error=$e->getMessage();}
            $this->lock(function()use($r,$raw,$v,$error,&$result){$current=$this->query('SELECT * FROM services WHERE id=?',[$r['id']])->fetch();if(!$current||$current['blocked']||$current['digest']!==$r['digest'])return;
                $now=gmdate('Y-m-d\TH:i:s\Z');
                if($error){$this->query('UPDATE services SET state="unavailable",last_error=?,checked_at=? WHERE id=?',[$error,$now,$r['id']]);if($current['state']!=='unavailable')$this->event($r['id'],'unavailable','Сервис временно исключён: манифест не прошёл проверку.');}
                elseif($v['componentId']!==$r['id']||$v['operator']!==$r['operator']){$this->query('UPDATE services SET state="review",last_error="Изменён ключ или идентификатор",checked_at=? WHERE id=?',[$now,$r['id']]);}
                elseif($v['digest']!==$r['digest']){$this->query('UPDATE services SET state="review",pending_raw=?,checked_at=? WHERE id=?',[$raw,$now,$r['id']]);if($current['pending_raw']!==$raw)$this->event($r['id'],'review','Обнаружено изменение подписанного манифеста.');}
                else{$state=$current['state']==='warning'?'warning':'active';$this->query('UPDATE services SET state=?,last_error=NULL,pending_raw=NULL,checked_at=? WHERE id=?',[$state,$now,$r['id']]);}
                $result[$r['id']]=$error?:'checked';
            });
        }$this->lock(fn()=>$this->publish());$this->query('DELETE FROM limits WHERE expires<?',[time()-86400]);
        $backup=$this->data.'/backups';if(!is_dir($backup))mkdir($backup,0700);$file=$backup.'/catalog-'.gmdate('Y-m-d').'.sqlite';if(!is_file($file))$this->db->exec('VACUUM INTO '.$this->db->quote($file));foreach(glob($backup.'/*.sqlite')?:[] as $old)if(filemtime($old)<time()-14*86400)unlink($old);return $result;
    }
}
