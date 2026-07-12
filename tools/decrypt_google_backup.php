<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
function usage(): never { fwrite(STDERR,"Usage: php decrypt_google_backup.php INPUT.enc [OUTPUT] [--key=/path/recovery-key.txt]\n"); exit(2); }
$input=$argv[1]??''; if($input===''||!is_file($input)) usage();
$output=$argv[2]??preg_replace('/\.enc$/i','',$input); if($output===$input) $output=$input.'.decrypted';
$keyPath=''; foreach($argv as $a){if(str_starts_with($a,'--key='))$keyPath=substr($a,6);} if($keyPath==='') $keyPath=dirname(__DIR__).'/storage/private_backup/encryption.key';
if(!is_file($keyPath)){fwrite(STDERR,"Key file not found: $keyPath\n");exit(3);} $txt=trim((string)file_get_contents($keyPath));
if(preg_match('/key_base64=([A-Za-z0-9+\/=]+)/',$txt,$m))$b64=$m[1];else$b64=preg_replace('/\s+/','',$txt);
$key=base64_decode($b64,true);if($key===false||strlen($key)!==32){fwrite(STDERR,"Invalid recovery key.\n");exit(4);} if(!function_exists('openssl_decrypt')){fwrite(STDERR,"PHP OpenSSL extension is required.\n");exit(5);}
$in=fopen($input,'rb');$out=fopen($output,'wb');if(!$in||!$out){fwrite(STDERR,"Cannot open input/output.\n");exit(6);} $head=fread($in,16);if(strlen($head)!==16||substr($head,0,4)!=='ABK2'){fwrite(STDERR,"Unsupported backup format.\n");exit(7);} $ctx=hash_init('sha256',HASH_HMAC,$key);
while(true){$lenRaw=fread($in,4);if(strlen($lenRaw)!==4){fwrite(STDERR,"Truncated backup.\n");@unlink($output);exit(8);}if($lenRaw==='END!'){ $expected=fread($in,32);$actual=hash_final($ctx,true);if(strlen($expected)!==32||!hash_equals($expected,$actual)){fwrite(STDERR,"Integrity check failed.\n");@unlink($output);exit(9);}break;} $len=unpack('N',$lenRaw)[1];if($len<0||$len>32*1024*1024){fwrite(STDERR,"Invalid record length.\n");@unlink($output);exit(10);} $nonce=fread($in,12);$tag=fread($in,16);$cipher='';while(strlen($cipher)<$len&&!feof($in)){$chunk=fread($in,$len-strlen($cipher));if($chunk===false||$chunk==='')break;$cipher.=$chunk;}if(strlen($nonce)!==12||strlen($tag)!==16||strlen($cipher)!==$len){fwrite(STDERR,"Truncated record.\n");@unlink($output);exit(11);} $record=$lenRaw.$nonce.$tag.$cipher;hash_update($ctx,$record);$plain=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,'ABK2');if($plain===false){fwrite(STDERR,"Decryption failed.\n");@unlink($output);exit(12);}fwrite($out,$plain);}
fclose($in);fclose($out);echo "Decrypted and verified: $output\n";
