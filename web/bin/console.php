<?php
declare(strict_types=1);
require __DIR__.'/../src/App.php';
use Atlas\App;
$cmd=$argv[1]??'help';$data=getenv('ATLAS_DATA')?:'/var/lib/atlas';
if($cmd==='init'){
    if(is_file($data.'/config.json')){fwrite(STDERR,"Already initialized.\n");exit(1);}
    umask(0077);if(!is_dir($data))mkdir($data,0700,true);
    $cli=getenv('ATLAS_CLI')?:'/opt/atlas/bin/onym-discovery';$base=getenv('ATLAS_URL')?:'https://atlas.predhit.com';
    $seed=random_bytes(32);file_put_contents($data.'/operator.seed',bin2hex($seed)."\n");
    $operator='onym:key:'.bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed)));
    $password=bin2hex(random_bytes(16));
    $config=['baseUrl'=>rtrim($base,'/'),'operator'=>$operator,'cli'=>$cli,'adminHash'=>password_hash($password,PASSWORD_ARGON2ID),'rateSecret'=>bin2hex(random_bytes(32))];
    file_put_contents($data.'/config.json',json_encode($config,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    file_put_contents($data.'/initial-admin-password.txt',$password."\n");
    $a=new App();$a->migrate();$a->lock(fn()=>$a->publish());echo "Initialized. Initial password stored privately in data directory.\n";
}elseif($cmd==='refresh'){$a=new App();echo json_encode($a->refresh(),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";}
elseif($cmd==='publish'){$a=new App();$a->lock(fn()=>$a->publish());echo "Published.\n";}
elseif($cmd==='add'){$a=new App();echo json_encode($a->submit($argv[2]??''),JSON_UNESCAPED_UNICODE)."\n";}
elseif($cmd==='password'){$a=new App();$password=trim(stream_get_contents(STDIN));if(strlen($password)<16)throw new RuntimeException('Password must have at least 16 characters.');$a->config['adminHash']=password_hash($password,PASSWORD_ARGON2ID);file_put_contents($data.'/config.json',json_encode($a->config,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));echo "Password changed.\n";}
else echo "Atlas: init | refresh | publish | add <https-manifest-url> | password (stdin)\n";
