<?php
declare(strict_types=1);
require __DIR__.'/../src/App.php';
use Atlas\{App,Problem};
header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD'];
function respond(mixed $data,int $status=200):never{http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;}
try{
    $app=new App();
    if($path==='/health'){respond(['ok'=>true,'service'=>'Atlas']);}
    if(preg_match('~^/(manifest\.json(?:\.sig)?|policy\.md|privacy\.md|catalogs/public-services(?:-[0-9]+)?\.json(?:\.sig)?)$~D',$path,$m)){
        $file=$app->data.'/published/'.$m[1];if(!is_file($file))throw new Problem('Каталог ещё не опубликован.',404);
        header('Access-Control-Allow-Origin: *');header('Cache-Control: public, max-age=60');header('Content-Type: '.(str_ends_with($path,'.json')?'application/json':'text/plain').'; charset=utf-8');readfile($file);exit;
    }
    if($path==='/api/catalog'&&$method==='GET'){
        $meta=json_decode($app->query('SELECT value FROM meta WHERE key="published"')->fetchColumn()?:'null',true);
        respond(['services'=>$app->all(),'publication'=>$meta,'manifestUrl'=>$app->config['baseUrl'].'/manifest.json','operator'=>$app->config['operator'],'fingerprint'=>implode(' ',str_split(substr(str_replace('onym:key:','',$app->config['operator']),0,16),4))]);
    }
    if($path==='/api/history'&&$method==='GET')respond(['events'=>$app->query('SELECT component,action,message,at FROM events ORDER BY id DESC LIMIT 100')->fetchAll()]);
    if(str_starts_with($path,'/api/')){
        $secure=str_starts_with($app->config['baseUrl'],'https://');
        session_name('atlas_session');session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Strict']);ini_set('session.use_strict_mode','1');session_start();
        if(isset($_SESSION['admin'])&&($_SESSION['expires']??0)<time()){$_SESSION=[];session_regenerate_id(true);}
        $_SESSION['csrf']??=bin2hex(random_bytes(32));
        if($path==='/api/session'&&$method==='GET')respond(['csrf'=>$_SESSION['csrf'],'admin'=>!empty($_SESSION['admin'])]);
        if($method!=='POST'&&$method!=='GET')throw new Problem('Метод не поддерживается.',405);
        $body=[];if($method==='POST'){
            if(($_SERVER['HTTP_ORIGIN']??$app->config['baseUrl'])!==$app->config['baseUrl'])throw new Problem('Недопустимый источник запроса.',403);
            if(!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))throw new Problem('Обновите страницу и повторите действие.',403);
            if((int)($_SERVER['CONTENT_LENGTH']??0)>16384)throw new Problem('Запрос слишком большой.',413);
            $raw=file_get_contents('php://input',false,null,0,16385);if(strlen($raw)>16384)throw new Problem('Запрос слишком большой.',413);
            $body=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($body))throw new Problem('Некорректный запрос.');
        }
        $ip=$_SERVER['REMOTE_ADDR']??'unknown';
        if($path==='/api/login'&&$method==='POST'){
            $app->limit('login:'.$ip,8,900);
            if(!password_verify((string)($body['password']??''),$app->config['adminHash']))throw new Problem('Неверный пароль.',401);
            session_regenerate_id(true);$_SESSION['admin']=true;$_SESSION['expires']=time()+3600;respond(['ok'=>true]);
        }
        if($path==='/api/logout'&&$method==='POST'){$_SESSION=[];session_destroy();respond(['ok'=>true]);}
        if($path==='/api/submit'&&$method==='POST'){
            $app->limit('submit:'.$ip,10,3600);$app->limit('submit:global',120,3600);session_write_close();respond($app->submit((string)($body['url']??'')));
        }
        if(str_starts_with($path,'/api/admin/')){
            if(empty($_SESSION['admin']))throw new Problem('Войдите в панель владельца.',401);session_write_close();
            if($path==='/api/admin/services'&&$method==='GET')respond(['services'=>$app->all(true)]);
            if($path==='/api/admin/edit'&&$method==='POST'){$app->edit($body);respond(['ok'=>true]);}
            if($path==='/api/admin/publish'&&$method==='POST'){$app->lock(fn()=>$app->publish());respond(['ok'=>true]);}
            if($path==='/api/admin/refresh'&&$method==='POST'){respond(['results'=>$app->refresh()]);}
        }
        throw new Problem('Не найдено.',404);
    }
    if($method!=='GET')throw new Problem('Метод не поддерживается.',405);
    if($path!=='/'&&$path!=='/admin'&&!str_starts_with($path,'/service/'))throw new Problem('Не найдено.',404);
    header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-cache');readfile(__DIR__.'/shell.html');
}catch(Problem $e){respond(['error'=>$e->getMessage()],$e->status);}catch(Throwable $e){error_log('Atlas: '.$e);respond(['error'=>'Не удалось выполнить действие. Попробуйте позже.'],500);}
