<?php
/** Isolated store-to-store pairing, independent from Back Office pairing. */
require_once __DIR__.'/api_pairing.php';
require_once __DIR__.'/api_web_products.php';
function sp_schema(): void {
  ensure_api_pairing_schema(); ensure_web_product_api_schema();
  db()->exec("CREATE TABLE IF NOT EXISTS store_pairing_codes (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, code_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX (expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function sp_url(string $url): string {
  $url=pairing_normalize_url($url); $parts=parse_url($url);
  if (!$parts || strtolower($parts['scheme']??'')!=='https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['port']) || isset($parts['path']) && !in_array($parts['path'],['','/'],true)) throw new RuntimeException('Harus URL domain HTTPS tanpa path, port, atau kredensial.');
  $host=strtolower($parts['host']);
  if ($host==='localhost' || !preg_match('/^[a-z0-9.-]+$/',$host) || !str_contains($host,'.')) throw new RuntimeException('Domain tujuan tidak valid.');
  $ips=gethostbynamel($host);
  if (!$ips) throw new RuntimeException('DNS domain tujuan tidak dapat ditemukan.');
  foreach ($ips as $ip) if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) throw new RuntimeException('Alamat IP tujuan tidak diizinkan.');
  return 'https://'.$host;
}
function sp_call(string $url,string $endpoint,array $payload): array {
  $base=sp_url($url); $host=parse_url($base,PHP_URL_HOST); $ips=gethostbynamel($host);
  if(!$ips) throw new RuntimeException('Tidak dapat memverifikasi DNS tujuan.');
  foreach($ips as $ip) if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) throw new RuntimeException('IP tujuan terlarang.');
  $ch=curl_init($base.'/'.$endpoint);
  curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>16,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_RESOLVE=>[$host.':443:'.$ips[0]]]);
  $raw=curl_exec($ch); $error=curl_error($ch); $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
  $json=is_string($raw)?json_decode($raw,true):null;
  if(!is_array($json)||$http!==200||empty($json['ok'])) throw new RuntimeException('Remote HTTP '.$http.': '.(is_array($json)?($json['message']??'Kesalahan remote'):$error));
  return $json;
}
function sp_local_url(): string { return sp_url((string)(app_config()['app']['base_url']??'')); }
function sp_label(): string { return (string)setting('store_name','Adena Store'); }
function sp_connect(string $code,string $name,string $url,string $token): void {
  $url=sp_url($url); $hash=hash('sha256',$token); $pdo=db();
  $sel=$pdo->prepare("SELECT id FROM api_connections WHERE connection_type='store' AND paired_from_request_code=? LIMIT 1"); $sel->execute([$code]); $id=$sel->fetchColumn();
  if($id) { $pdo->prepare("UPDATE api_connections SET status='active',token_hash=?,token_encrypted=?,remote_base_url=? WHERE id=?")->execute([$hash,pairing_encrypt_secret($token),$url,$id]); }
  else { $pdo->prepare("INSERT INTO api_connections(connection_name,connection_type,remote_system_type,remote_base_url,access_scope,token_hash,token_encrypted,status,paired_from_request_code,paired_at) VALUES(?,'store','adena_store',?,'store_product_readonly',?,?,'active',?,NOW())")->execute([$name,$url,$hash,pairing_encrypt_secret($token),$code]); }
  $id=$id ?: (int)$pdo->lastInsertId();
  $sel=$pdo->prepare('SELECT id FROM api_remote_connections WHERE token=? LIMIT 1'); $sel->execute(['pairing:'.$id]); $remoteId=$sel->fetchColumn();
  if(!$remoteId) $pdo->prepare('INSERT INTO api_remote_connections(name,base_url,token,permissions,is_active) VALUES(?,?,?, ?,1)')->execute([$name,$url,'pairing:'.$id,json_encode(['products.read','categories.read','product_images.read'])]);
  else $pdo->prepare('UPDATE api_remote_connections SET name=?,base_url=?,is_active=1 WHERE id=?')->execute([$name,$url,$remoteId]);
}
function sp_resolve_token(string $token): string {
  if(!preg_match('/^pairing:(\d+)$/',$token,$m)) return $token;
  $st=db()->prepare("SELECT token_encrypted FROM api_connections WHERE id=? AND connection_type='store' AND status='active'"); $st->execute([(int)$m[1]]);
  $encrypted=$st->fetchColumn(); $plain=pairing_decrypt_secret($encrypted?:'');
  if($plain==='') throw new RuntimeException('Pairing nonaktif atau token tidak dapat dibaca.');
  return $plain;
}
