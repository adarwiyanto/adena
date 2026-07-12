<?php
function backup_h($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function backup_bytes($n): string { $n=(float)$n; foreach(['B','KB','MB','GB','TB'] as $u){ if($n<1024) return number_format($n,$n<10?2:1,',','.').' '.$u; $n/=1024; } return number_format($n,1,',','.').' PB'; }
function backup_render_settings(GoogleDriveBackupService $svc,string $callbackUri,string $cronCommand,string $cronUrl,string $csrfHtml,string $postAction=''): void {
 $connected=$svc->isConnected(); $jobs=$svc->recentJobs(30); $secretSaved=(string)$svc->get('oauth_client_secret','')!==''; $bootstrapError=$svc->bootstrapError(); $diagnostics=$svc->diagnostics();
 ?>
 <style>
 .backup-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.backup-card{border:1px solid #e5e7eb;border-radius:14px;padding:16px;background:#fff}.backup-card h3{margin:0 0 12px}.backup-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.backup-form-grid label{display:block;font-size:13px;font-weight:700}.backup-form-grid input[type=text],.backup-form-grid input[type=email],.backup-form-grid input[type=password],.backup-form-grid input[type=number]{width:100%;box-sizing:border-box;margin-top:5px}.backup-code{display:block;white-space:pre-wrap;word-break:break-all;background:#0f172a;color:#e2e8f0;padding:10px;border-radius:9px;font-size:12px}.backup-status{display:inline-block;padding:5px 9px;border-radius:999px;font-size:12px;font-weight:800}.backup-status.ok{background:#dcfce7;color:#166534}.backup-status.bad{background:#fee2e2;color:#991b1b}.backup-status.run{background:#fef3c7;color:#92400e}.backup-table{width:100%;border-collapse:collapse}.backup-table th,.backup-table td{padding:8px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}.backup-actions{display:flex;gap:8px;flex-wrap:wrap}.backup-muted{color:#64748b;font-size:13px}.backup-checks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.backup-checks label{border:1px solid #e5e7eb;border-radius:10px;padding:10px;font-weight:700}.backup-alert{padding:11px 13px;border-radius:10px;margin-bottom:12px;background:#eff6ff;color:#1e40af}.backup-alert.err{background:#fee2e2;color:#991b1b}@media(max-width:800px){.backup-grid,.backup-form-grid,.backup-checks{grid-template-columns:1fr}}
 </style>
 <div class="backup-alert"><b>Backup terenkripsi ke Google Drive.</b> PHP membutuhkan ekstensi cURL dan OpenSSL; snapshot mingguan/bulanan membutuhkan ZipArchive atau PharData. Database dibuat setiap 6 jam dan harian; snapshot database + file aplikasi dibuat mingguan dan bulanan. Hanya owner yang dapat membuka atau menjalankan fitur ini.</div>
 <?php if($bootstrapError!==''): ?><div class="backup-alert err"><b>Infrastruktur backup belum siap.</b><br><?=backup_h($bootstrapError)?><br><small>Halaman tetap dibuka agar penyebabnya dapat diperbaiki tanpa HTTP 500.</small></div><?php endif; ?>
 <div class="backup-grid">
  <div class="backup-card">
   <h3>Konfigurasi Google Drive</h3>
   <form method="post" action="<?=backup_h($postAction)?>"><?=$csrfHtml?><input type="hidden" name="backup_action" value="save_config">
    <div class="backup-form-grid">
     <label>Aktifkan backup otomatis<br><input type="checkbox" name="enabled" value="1" <?=$svc->get('enabled','1')==='1'?'checked':''?>></label>
     <label>Akun Google tujuan<input type="email" name="google_email" value="<?=backup_h($svc->get('google_email','adarwiyanto@gmail.com'))?>" required></label>
     <label>Kode instalasi<input type="text" name="site_code" value="<?=backup_h($svc->get('site_code',$svc->appKey()))?>" required></label>
     <label>Folder utama Drive<input type="text" name="drive_root" value="<?=backup_h($svc->get('drive_root','ADENA_AUTOMATED_BACKUP'))?>" required></label>
     <label>Google OAuth Client ID<input type="text" name="oauth_client_id" value="<?=backup_h($svc->get('oauth_client_id',''))?>" autocomplete="off"></label>
     <label>Google OAuth Client Secret<input type="password" name="oauth_client_secret" value="" placeholder="<?=$secretSaved?'Tersimpan — kosongkan bila tidak diubah':'Masukkan Client Secret'?>" autocomplete="new-password"></label>
    </div>
    <p class="backup-muted">Authorized redirect URI yang harus dimasukkan di Google Cloud:</p><code class="backup-code"><?=backup_h($callbackUri)?></code>
    <?php foreach(['6hourly','daily','weekly','monthly'] as $sk): if($svc->get('schedule_'.$sk,'1')==='1'): ?><input type="hidden" name="schedule_<?=$sk?>" value="1"><?php endif; ?><input type="hidden" name="retention_<?=$sk?>_days" value="<?=backup_h($svc->get('retention_'.$sk.'_days','30'))?>"><?php endforeach; ?>
    <p><button class="btn" type="submit">Simpan Konfigurasi</button></p>
   </form>
  </div>
  <div class="backup-card">
   <h3>Status Koneksi</h3>
   <p><?=$connected?'<span class="backup-status ok">Terhubung</span>':'<span class="backup-status bad">Belum terhubung</span>'?></p>
   <p><b>Akun aktif:</b> <?=backup_h($svc->connectedEmail()?:'-')?></p>
   <div class="backup-actions">
    <?php if(!$connected): ?><form method="post" action="<?=backup_h($postAction)?>"><?=$csrfHtml?><input type="hidden" name="backup_action" value="connect"><button class="btn" type="submit">Hubungkan Google Drive</button></form><?php endif; ?>
    <?php if($connected): ?><form method="post" action="<?=backup_h($postAction)?>"><?=$csrfHtml?><input type="hidden" name="backup_action" value="test"><button class="btn light" type="submit">Tes Koneksi</button></form><form method="post" action="<?=backup_h($postAction)?>"><?=$csrfHtml?><input type="hidden" name="backup_action" value="download_key"><button class="btn light" type="submit">Unduh Kunci Pemulihan</button></form><form method="post" action="<?=backup_h($postAction)?>" onsubmit="return confirm('Putuskan koneksi Google Drive?')"><?=$csrfHtml?><input type="hidden" name="backup_action" value="disconnect"><button class="btn danger" type="submit">Putuskan</button></form><?php endif; ?>
   </div>
   <p class="backup-muted">Client Secret dan refresh token disimpan terenkripsi. Password akun Google tidak pernah disimpan. <b>Unduh Kunci Pemulihan dan simpan di luar server</b>; tanpa kunci tersebut file .enc tidak dapat dipulihkan bila hosting hilang.</p>
  </div>
  <div class="backup-card">
   <h3>Jadwal dan Retensi</h3>
   <form method="post" action="<?=backup_h($postAction)?>"><?=$csrfHtml?><input type="hidden" name="backup_action" value="save_config">
    <?php foreach(['google_email','site_code','drive_root','oauth_client_id'] as $hidden): ?><input type="hidden" name="<?=$hidden?>" value="<?=backup_h($svc->get($hidden,''))?>"><?php endforeach; ?><?php if($svc->get('enabled','1')==='1'): ?><input type="hidden" name="enabled" value="1"><?php endif; ?>
    <div class="backup-checks">
    <?php foreach(['6hourly'=>'Setiap 6 jam','daily'=>'Harian','weekly'=>'Mingguan','monthly'=>'Bulanan'] as $k=>$label): ?>
     <label><input type="checkbox" name="schedule_<?=$k?>" value="1" <?=$svc->get('schedule_'.$k,'1')==='1'?'checked':''?>> <?=$label?><br><small>Retensi <input style="width:75px" type="number" min="1" max="3650" name="retention_<?=$k?>_days" value="<?=backup_h($svc->get('retention_'.$k.'_days','30'))?>"> hari</small></label>
    <?php endforeach; ?>
    </div><p><button class="btn" type="submit">Simpan Jadwal</button></p>
   </form>
  </div>
  <div class="backup-card">
   <h3>Cron cPanel</h3><p class="backup-muted">Pasang salah satu cron setiap 15 menit. Runner akan menentukan backup yang jatuh tempo dan mencegah proses ganda.</p>
   <b>CLI — disarankan</b><code class="backup-code"><?=backup_h($cronCommand)?></code>
   <b>URL alternatif</b><code class="backup-code"><?=backup_h($cronUrl)?></code>
  </div>
  <div class="backup-card" style="grid-column:1/-1">
   <h3>Pemeriksaan Server</h3>
   <div style="overflow:auto"><table class="backup-table"><thead><tr><th>Komponen</th><th>Status</th><th>Detail</th></tr></thead><tbody>
   <?php foreach($diagnostics as $d): ?><tr><td><?=backup_h($d['label'])?></td><td><span class="backup-status <?=$d['ok']?'ok':'bad'?>"><?=$d['ok']?'Siap':'Bermasalah'?></span></td><td><?=backup_h($d['detail'])?></td></tr><?php endforeach; ?>
   </tbody></table></div>
   <form method="post" action="<?=backup_h($postAction)?>" style="margin-top:12px"><?=$csrfHtml?><input type="hidden" name="backup_action" value="repair"><button class="btn light" type="submit">Perbaiki Struktur Backup</button></form>
   <p class="backup-muted">Tombol ini membuat ulang folder privat, tabel log, dan setting awal. Tidak menghapus backup atau data aplikasi.</p>
  </div>
  <div class="backup-card" style="grid-column:1/-1">
   <h3>Backup Sekarang</h3><div class="backup-actions">
   <?php foreach(['6hourly'=>'Database 6 Jam','daily'=>'Database Harian','weekly'=>'Snapshot Mingguan','monthly'=>'Snapshot Bulanan'] as $k=>$label): ?><form method="post" action="<?=backup_h($postAction)?>"><?=$csrfHtml?><input type="hidden" name="backup_action" value="run"><input type="hidden" name="backup_type" value="<?=$k?>"><button class="btn light" type="submit" <?=$connected?'':'disabled'?>><?=$label?></button></form><?php endforeach; ?>
   </div><p class="backup-muted">Snapshot mingguan/bulanan mencakup database dan file aplikasi, tetapi mengecualikan .git, cache, build Android, node_modules, dan folder sementara.</p>
  </div>
  <div class="backup-card" style="grid-column:1/-1"><h3>Riwayat Backup</h3><div style="overflow:auto"><table class="backup-table"><thead><tr><th>Waktu</th><th>Jenis</th><th>Status</th><th>File</th><th>Ukuran</th><th>Metode</th><th>Pesan</th></tr></thead><tbody>
  <?php foreach($jobs as $j): $cls=$j['status']==='success'?'ok':($j['status']==='failed'?'bad':'run'); ?><tr><td><?=backup_h($j['started_at']?:$j['created_at'])?></td><td><?=backup_h($j['backup_type'])?></td><td><span class="backup-status <?=$cls?>"><?=backup_h($j['status'])?></span></td><td><?=backup_h($j['filename']?:'-')?></td><td><?=backup_h(backup_bytes($j['bytes_size']??0))?></td><td><?=backup_h($j['dump_method']?:'-')?></td><td><?=backup_h($j['message']?:'-')?></td></tr><?php endforeach; ?>
  <?php if(!$jobs): ?><tr><td colspan="7">Belum ada riwayat backup.</td></tr><?php endif; ?></tbody></table></div></div>
 </div>
 <?php
}
