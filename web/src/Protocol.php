<?php
declare(strict_types=1);
namespace Atlas;

final class Problem extends \RuntimeException { public function __construct(string $message, public int $status=422) { parent::__construct($message); } }

// Parse BEFORE json_decode: reject duplicate keys, floats, exponent notation and oversized integers.
final class StrictJson {
    private int $i=0;
    public function __construct(private string $raw) {}
    public static function decode(string $raw): object {
        if (strlen($raw)>262144 || !preg_match('//u',$raw)) throw new Problem('Манифест слишком большой или не в UTF-8.');
        $p=new self($raw); $p->value(0); $p->ws();
        if($p->i!==strlen($raw)) throw new Problem('Лишние данные после JSON.');
        try {$v=json_decode($raw,false,64,JSON_THROW_ON_ERROR);} catch(\Throwable){throw new Problem('Некорректный JSON.');}
        if(!is_object($v)) throw new Problem('Манифест должен быть JSON-объектом.');
        return $v;
    }
    private function ws():void {while(isset($this->raw[$this->i]) && str_contains(" \r\n\t",$this->raw[$this->i]))$this->i++;}
    private function string():string {
        $start=$this->i++;$escaped=false;
        while(isset($this->raw[$this->i])) { $c=$this->raw[$this->i++]; if($escaped){$escaped=false;continue;} if($c==='\\'){$escaped=true;continue;} if($c==='"'){try{return json_decode(substr($this->raw,$start,$this->i-$start),true,8,JSON_THROW_ON_ERROR);}catch(\Throwable){break;}} }
        throw new Problem('Некорректная JSON-строка.');
    }
    private function value(int $depth):void {
        if($depth>48)throw new Problem('Слишком глубокая вложенность JSON.'); $this->ws();$c=$this->raw[$this->i]??'';
        if($c==='"'){$this->string();return;}
        if($c==='{'||$c==='['){$object=$c==='{';$end=$object?'}':']';$this->i++;$this->ws();$keys=[];
            if(($this->raw[$this->i]??'')===$end){$this->i++;return;}
            while(true){$this->ws();if($object){if(($this->raw[$this->i]??'')!=='"')throw new Problem('Ожидается ключ JSON.');$key=$this->string();if(isset($keys[$key]))throw new Problem('Повторяющийся ключ JSON: '.$key);$keys[$key]=true;$this->ws();if(($this->raw[$this->i++]??'')!==':')throw new Problem('Ожидается двоеточие.');}
                $this->value($depth+1);$this->ws();$next=$this->raw[$this->i++]??'';if($next===$end)return;if($next!==',')throw new Problem('Некорректный JSON.');}
        }
        if(preg_match('/\G(?:true|false|null|0|[1-9][0-9]*)/A',$this->raw,$m,0,$this->i)){$this->i+=strlen($m[0]);if(ctype_digit($m[0]) && (strlen($m[0])>16 || (strlen($m[0])===16 && strcmp($m[0],'9007199254740991')>0)))throw new Problem('Число превышает допустимый диапазон.');return;}
        throw new Problem('Некорректный JSON или неподдерживаемое число.');
    }
}
function canonical(mixed $v):string {
    if(is_object($v)){$fields=get_object_vars($v);uksort($fields,'strcmp');$parts=[];foreach($fields as $k=>$val)$parts[]=canonical((string)$k).':'.canonical($val);return '{'.implode(',',$parts).'}';}
    if(is_array($v))return '['.implode(',',array_map(__NAMESPACE__.'\\canonical',$v)).']';
    return json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_LINE_TERMINATORS|JSON_THROW_ON_ERROR);
}
function digest(string $bytes):string{return 'sha256:'.hash('sha256',$bytes);}
function validUri(string $url):array {
    $p=parse_url($url);
    if(strlen($url)>2048 || !$p || ($p['scheme']??'')!=='https' || empty($p['host']) || isset($p['port']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z][a-z0-9-]*$/i',$p['host']))throw new Problem('Нужен HTTPS-адрес с доменом, без порта, параметров и фрагмента.');
    return $p;
}
function fetchManifest(string $url):string {
    for($hop=0;$hop<=3;$hop++){
        $p=validUri($url);$host=$p['host'];$ips=gethostbynamel($host)?:[];
        if(!$ips)throw new Problem('Домен сервиса не разрешается в IPv4.');
        foreach($ips as $ip)if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE) || str_starts_with($ip,'100.64.') || (ip2long($ip)>=ip2long('100.64.0.0') && ip2long($ip)<=ip2long('100.127.255.255')))throw new Problem('Адрес ведёт в непубличную сеть.');
        $body='';$location='';$large=false;$ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$host.':443:'.$ips[0]],CURLOPT_USERAGENT=>'Atlas-Manifest-Checker/1.0',CURLOPT_HTTPHEADER=>['Accept: application/json','Accept-Encoding: identity'],CURLOPT_WRITEFUNCTION=>function($ch,$chunk)use(&$body,&$large){if(strlen($body)+strlen($chunk)>262144){$large=true;return 0;}$body.=$chunk;return strlen($chunk);},CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$location){if(stripos($line,'Location:')===0)$location=trim(substr($line,9));return strlen($line);}]);
        $ok=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
        if($large)throw new Problem('Манифест превышает 256 КиБ.');if($ok===false)throw new Problem('Не удалось получить манифест по защищённому соединению.');
        if(in_array($code,[301,302,303,307,308],true)){
            if($hop===3)throw new Problem('Слишком много перенаправлений.');
            if(str_starts_with($location,'/')&&!str_starts_with($location,'//'))$location='https://'.$host.$location;
            validUri($location);$url=$location;continue;
        }
        if($code!==200)throw new Problem('Сервис вернул HTTP '.$code.'.');return $body;
    }throw new Problem('Манифест недоступен.');
}
function verifyService(string $raw):array {
    $v=StrictJson::decode($raw);
    foreach(['componentId','operator','seat','signature'] as $f)if(!isset($v->$f)||!is_string($v->$f))throw new Problem('Не заполнено поле '.$f.'.');
    if(($v->version??null)!==1)throw new Problem('Поддерживается version: 1.');
    if(!preg_match('/^onym:component:[a-z0-9-]{1,64}$/D',$v->componentId))throw new Problem('Некорректный componentId.');
    if(!preg_match('/^onym:key:([a-f0-9]{64})$/D',$v->operator,$key))throw new Problem('Некорректный ключ оператора.');
    if(!preg_match('/^[a-z0-9.-]{1,64}$/D',$v->seat))throw new Problem('Некорректная роль.');
    $sig=base64_decode($v->signature,true);$unsigned=clone $v;unset($unsigned->signature);
    if($sig===false||strlen($sig)!==64||!sodium_crypto_sign_verify_detached($sig,canonical($unsigned),hex2bin($key[1])))throw new Problem('Подпись манифеста не прошла проверку.');
    if(isset($v->validUntil)){
        if(!is_string($v->validUntil)||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D',$v->validUntil)||strtotime($v->validUntil)===false||strtotime($v->validUntil)<=time())throw new Problem('Манифест просрочен или срок записан некорректно.');
    }elseif($v->seat!=='storage.backup')throw new Problem('Не указан срок validUntil.');
    $profiles=[];
    foreach(['implementationProfileId','moderationProfileId','backupProfileId'] as $f)if(isset($v->$f)&&is_string($v->$f))$profiles[]=$v->$f;
    foreach(['profiles','supportedProfiles','implementationProfiles'] as $f)if(isset($v->$f)&&is_array($v->$f))foreach($v->$f as $p){if(is_string($p))$profiles[]=$p;elseif(is_object($p)&&isset($p->implementationProfileId))$profiles[]=$p->implementationProfileId;}
    foreach($profiles as $profile)if(!is_string($profile)||strlen($profile)>200)throw new Problem('Некорректный профиль.');
    if(count($profiles)>32)throw new Problem('Слишком много профилей.');
    if(isset($v->offers)&&!is_array($v->offers))throw new Problem('offers должен быть массивом.');
    // Discovery describes declarations, not a certification of destination behavior.
    if(isset($v->endpoints)&&(!is_array($v->endpoints)||count($v->endpoints)>32))throw new Problem('Некорректный список endpoints.');
    return ['componentId'=>$v->componentId,'operator'=>$v->operator,'seat'=>$v->seat,'profiles'=>array_values(array_unique($profiles)),'digest'=>digest($raw),'validUntil'=>$v->validUntil??null,'name'=>is_string($v->name??null)?mb_substr($v->name,0,100):str_replace('onym:component:','',$v->componentId),'document'=>$v];
}
