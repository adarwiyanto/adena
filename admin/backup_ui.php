<?php
function backup_h($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function backup_bytes($n): string { $n=(float)$n; foreach(['B','KB','MB','GB','TB'] as $u){ if($n<1024) return number_format($n,$n<10?2:1,',','.').' '.$u; $n/=1024; } return number_format($n,1,',','.').' PB'; }
function backup_default_php_cli_path(): string {
 $candidates=['/opt/cpanel/ea-php84/root/usr/bin/php','/opt/alt/php84/usr/bin/php','/usr/local/bin/php','/usr/bin/php'];
 foreach($candidates as $candidate){ if(@is_file($candidate) && @is_executable($candidate)) return $candidate; }
 return '/opt/cpanel/ea-php84/root/usr/bin/php';
}
function backup_shell_quote($value): string {
 return "'".str_replace("'", "'\"'\"'", (string)$value)."'";
}
function backup_build_cron_command($svc,string $cronFile): string {
 $phpCli=trim((string)$svc->get('php_cli_path',''));
 if($phpCli==='') $phpCli=backup_default_php_cli_path();
 return backup_shell_quote($phpCli).' -q '.backup_shell_quote($cronFile).' >/dev/null 2>&1';
}
function backup_render_settings($svc,string $callbackUri,string $cronCommand,string $cronUrl,string $csrfHtml,string $postAction='',string $connectUrl=''): void {
 $connected=$svc->isConnected(); $jobs=$svc->recentJobs(30); $secretSaved=(string)$svc->get('oauth_client_secret','')!==''; $bootstrapError=$svc->bootstrapError(); $diagnostics=$svc->diagnostics();
 ?>
 <style>
 .backup-settings{width:100%;max-width:1500px;margin:0 auto;color:#0f172a;box-sizing:border-box}
 .backup-settings *{box-sizing:border-box}
 .backup-settings .backup-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;align-items:start}
 .backup-settings .backup-card{min-width:0;border:1px solid #e5e7eb;border-radius:14px;padding:18px;background:#fff}
 .backup-settings .backup-card h3{margin:0 0 14px;font-size:17px;line-height:1.3}
 .backup-settings .backup-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 16px}
 .backup-settings .backup-field{display:block;min-width:0;font-size:13px;font-weight:700;line-height:1.35}
 .backup-settings .backup-field>input[type=text],.backup-settings .backup-field>input[type=email],.backup-settings .backup-field>input[type=password],.backup-settings .backup-field>input[type=number]{display:block;width:100%!important;min-width:0;height:42px;margin:6px 0 0!important;padding:9px 11px!important;border-radius:9px!important}
 .backup-settings input[type=checkbox]{appearance:auto!important;-webkit-appearance:checkbox!important;width:18px!important;height:18px!important;min-width:18px!important;max-width:18px!important;padding:0!important;margin:0!important;border:0!important;border-radius:3px!important;box-shadow:none!important;vertical-align:middle;accent-color:#1689d8;cursor:pointer}
 .backup-settings .backup-toggle{grid-column:1/-1;display:flex;align-items:center;gap:10px;min-height:42px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;background:#f8fafc;font-size:13px;font-weight:800;cursor:pointer}
 .backup-settings .backup-toggle span{line-height:1.25}
 .backup-settings .backup-code{display:block;width:100%;max-width:100%;overflow-x:auto;white-space:pre;word-break:normal;background:#0f172a;color:#e2e8f0;padding:11px 12px;border-radius:9px;font:12px/1.45 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
 .backup-settings .backup-status{display:inline-block;padding:5px 9px;border-radius:999px;font-size:12px;font-weight:800}
 .backup-settings .backup-status.ok{background:#dcfce7;color:#166534}.backup-settings .backup-status.bad{background:#fee2e2;color:#991b1b}.backup-settings .backup-status.run{background:#fef3c7;color:#92400e}
 .backup-settings .backup-table{width:100%;border-collapse:collapse}.backup-settings .backup-table th,.backup-settings .backup-table td{padding:9px 8px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}
 .backup-settings .backup-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.backup-settings .backup-actions form{margin:0}
 .backup-settings .backup-muted{display:block;color:#64748b;font-size:12.5px;font-weight:500;line-height:1.45;margin-top:5px}
 .backup-settings .backup-checks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
 .backup-settings .backup-schedule{display:block;border:1px solid #e5e7eb;border-radius:11px;padding:13px;background:#fff;cursor:pointer}
 .backup-settings .backup-schedule-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:11px;font-size:14px;font-weight:800;line-height:1.25}
 .backup-settings .backup-retention{display:flex;align-items:center;gap:7px;color:#475569;font-size:12px;font-weight:700}
 .backup-settings .backup-retention input[type=number]{width:76px!important;height:36px!important;margin:0!important;padding:7px 9px!important;border-radius:8px!important}
 .backup-settings .backup-alert{padding:12px 14px;border-radius:10px;margin-bottom:14px;background:#eff6ff;color:#1e40af;line-height:1.45}.backup-settings .backup-alert.err{background:#fee2e2;color:#991b1b}
 .backup-settings p{max-width:none}.backup-settings button.btn{min-height:38px}

 .backup-settings .backup-compact-actions{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap}.backup-settings .backup-inline-field{display:flex;align-items:center;gap:9px;font-weight:800;margin:0}.backup-settings .backup-inline-field select{width:190px!important;height:40px!important;margin:0!important}.backup-dialog{width:min(620px,calc(100% - 28px));border:0;border-radius:16px;padding:22px;box-shadow:0 24px 70px rgba(15,23,42,.3)}.backup-dialog::backdrop{background:rgba(15,23,42,.55)}.backup-dialog-close{float:right;border:0;background:transparent;font-size:28px;cursor:pointer}.backup-dialog select{width:100%;min-height:42px}.backup-dialog input{width:100%;min-height:42px}
 @media(max-width:680px){.backup-settings .backup-compact-actions{align-items:stretch}.backup-settings .backup-inline-field{width:100%;justify-content:space-between}.backup-settings .backup-inline-field select{flex:1;width:auto!important}.backup-settings .backup-compact-actions .btn{flex:1}}
 @media(max-width:900px){.backup-settings .backup-grid,.backup-settings .backup-form-grid,.backup-settings .backup-checks{grid-template-columns:1fr}.backup-settings .backup-toggle{grid-column:auto}}
 @media(max-width:560px){.backup-settings .backup-card{padding:14px}.backup-settings .backup-grid{gap:12px}.backup-settings .backup-schedule-head{align-items:flex-start}}
 </style>
 <div class="backup-settings">
 <div class="backup-alert"><b>Backup terenkripsi ke Google Drive.</b> PHP membutuhkan ekstensi cURL dan OpenSSL; snapshot mingguan/bulanan membutuhkan ZipArchive atau PharData. Database dibuat setiap 6 jam dan harian; snapshot database + file aplikasi dibuat mingguan dan bulanan. Hanya owner yang dapat membuka atau menjalankan fitur ini.</div>
 <?php if($bootstrapError!==''): ?><div class="backup-alert err"><b>Infrastruktur backup belum siap.</b><br><?=backup_h($bootstrapError)?><br><small>Halaman tetap dibuka agar penyebabnya dapat diperbaiki tanpa HTTP 500.</small></div><?php endif; ?>
 <div class="backup-grid">
  <div class="backup-card">
   <h3>Konfigurasi Google Drive</h3>
   <form method="post" action="<?=backup_h($postAction)?>"><?=$csrfHtml?><input type="hidden" name="backup_action" value="save_config">
    <div class="backup-form-grid">
     <label class="backup-toggle"><input type="checkbox" name="enabled" value="1" <?=$svc->get('enabled','1')==='1'?'checked':''?>><span>Aktifkan backup otomatis</span></label>
     <label class="backup-field">Akun Google tujuan<input type="email" name="google_email" value="<?=backup_h($svc->get('google_email','adarwiyanto@gmail.com'))?>" required></label>
     <label class="backup-field">Kode instalasi<input type="text" name="site_code" value="<?=backup_h($svc->get('site_code',$svc->appKey()))?>" required></label>
     <label class="backup-field">Folder utama Drive<input type="text" name="drive_root" value="<?=backup_h($svc->get('drive_root','ADENA_AUTOMATED_BACKUP'))?>" required></label>
     <label class="backup-field">Path PHP CLI cPanel<input type="text" name="php_cli_path" value="<?=backup_h($svc->get('php_cli_path',backup_default_php_cli_path()))?>" required><small class="backup-muted">PHP 8.4 cPanel umumnya: /opt/cpanel/ea-php84/root/usr/bin/php</small></label>
     <label class="backup-field">Google OAuth Client ID<input type="text" name="oauth_client_id" value="<?=backup_h($svc->get('oauth_client_id',''))?>" autocomplete="off"></label>
     <label class="backup-field">Google OAuth Client Secret<input type="password" name="oauth_client_secret" value="" placeholder="<?=$secretSaved?'Tersimpan — kosongkan bila tidak diubah':'Masukkan Client Secret'?>" autocomplete="new-password"></label>
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
    <?php if(!$connected): ?><?php if($connectUrl!==''): ?><a class="btn" href="<?=backup_h($connectUrl)?>" id="backup-google-connect-link">Hubungkan Google Drive</a><?php else: ?><form method="post" action="<?=backup_h($postAction)?>"><?=$csrfHtml?><input type="hidden" name="backup_action" value="connect"><button class="btn" type="submit">Hubungkan Google Drive</button></form><?php endif; ?><?php endif; ?>
    <?php if($connected): ?><form method="post" action="<?=backup_h($postAction)?>"><?=$csrfHtml?><input type="hidden" name="backup_action" value="test"><button class="btn light" type="submit">Tes Koneksi</button></form><form method="post" action="<?=backup_h($postAction)?>"><?=$csrfHtml?><input type="hidden" name="backup_action" value="download_key"><button class="btn light" type="submit">Unduh Kunci Pemulihan</button></form><form method="post" action="<?=backup_h($postAction)?>" onsubmit="return confirm('Putuskan koneksi Google Drive?')"><?=$csrfHtml?><input type="hidden" name="backup_action" value="disconnect"><button class="btn danger" type="submit">Putuskan</button></form><?php endif; ?>
   </div>
   <p class="backup-muted">Client Secret dan refresh token disimpan terenkripsi. Password akun Google tidak pernah disimpan. <b>Unduh Kunci Pemulihan dan simpan di luar server</b>; tanpa kunci tersebut file .enc tidak dapat dipulihkan bila hosting hilang.</p>
  </div>
  <div class="backup-card">
   <h3>Jadwal dan Retensi</h3>
   <form method="post" action="<?=backup_h($postAction)?>"><?=$csrfHtml?><input type="hidden" name="backup_action" value="save_config">
    <?php foreach(['google_email','site_code','drive_root','php_cli_path','oauth_client_id'] as $hidden): ?><input type="hidden" name="<?=$hidden?>" value="<?=backup_h($svc->get($hidden,''))?>"><?php endforeach; ?><?php if($svc->get('enabled','1')==='1'): ?><input type="hidden" name="enabled" value="1"><?php endif; ?>
    <div class="backup-checks">
    <?php foreach(['6hourly'=>'Setiap 6 jam','daily'=>'Harian','weekly'=>'Mingguan','monthly'=>'Bulanan'] as $k=>$label): ?>
     <label class="backup-schedule"><span class="backup-schedule-head"><span><?=$label?></span><input type="checkbox" name="schedule_<?=$k?>" value="1" <?=$svc->get('schedule_'.$k,'1')==='1'?'checked':''?>></span><span class="backup-retention"><span>Retensi</span><input type="number" min="1" max="3650" name="retention_<?=$k?>_days" value="<?=backup_h($svc->get('retention_'.$k.'_days','30'))?>"><span>hari</span></span></label>
    <?php endforeach; ?>
    </div><p><button class="btn" type="submit">Simpan Jadwal</button></p>
   </form>
  </div>
  <div class="backup-card">
   <h3>Cron cPanel</h3><p class="backup-muted">Pasang salah satu cron setiap 15 menit. Runner akan menentukan backup yang jatuh tempo dan mencegah proses ganda.</p>
   <b>CLI — disarankan</b><code class="backup-code"><?=backup_h($cronCommand)?></code><p class="backup-muted">Path PHP CLI dapat diubah pada konfigurasi. Command di atas hanya ditampilkan sebagai teks dan tidak menjalankan fungsi shell dari halaman web.</p>
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
   <h3>Backup & Restore</h3>
   <form method="post" action="<?=backup_h($postAction)?>" id="backup-restore-form"><?=$csrfHtml?>
    <div class="backup-compact-actions">
     <label class="backup-inline-field"><span>Timeframe</span><select name="backup_type" id="backup-timeframe"><option value="6hourly">Setiap 6 Jam</option><option value="daily">Harian</option><option value="weekly">Mingguan</option><option value="monthly">Bulanan</option></select></label>
     <input type="hidden" name="backup_job_id" id="backup-job-id" value="">
     <button class="btn" type="submit" name="backup_action" value="run" <?=$connected?'':'disabled'?>>Backup Sekarang</button>
     <button class="btn light" type="submit" name="backup_action" value="test_restore" <?=$connected?'':'disabled'?>>Tes Restore</button>
     <button class="btn danger" type="button" id="backup-restore-open" <?=$connected?'':'disabled'?>>Restore</button>
    </div>
   </form>
   <p class="backup-muted">Backup 6 jam/harian berisi database. Mingguan/bulanan mencakup database, file aplikasi, gambar dan dokumen pada <code>private_uploads</code>. Restore hanya dapat dijalankan setelah backup lulus Tes Restore.</p>
  </div>
  <dialog class="backup-dialog" id="backup-select-dialog"><form method="dialog"><button class="backup-dialog-close" value="cancel" aria-label="Tutup">×</button></form><h3 id="backup-dialog-title">Pilih Backup</h3><form method="post" action="<?=backup_h($postAction)?>" id="backup-dialog-form"><?=$csrfHtml?><input type="hidden" name="backup_action" id="backup-dialog-action"><label class="backup-field">File backup<select name="backup_job_id" id="backup-file-select" required></select></label><div id="backup-confirm-wrap" hidden><label class="backup-field">Konfirmasi restore<input type="text" name="restore_confirm" placeholder="RESTORE <?=backup_h($svc->get('site_code',$svc->appKey()))?>"></label><small class="backup-muted">Ketik persis: <b>RESTORE <?=backup_h($svc->get('site_code',$svc->appKey()))?></b></small></div><p><button class="btn" type="submit" id="backup-dialog-submit">Lanjutkan</button></p></form></dialog>
  <script type="application/json" id="backup-list-json"><?php $all=[]; foreach(['6hourly','daily','weekly','monthly'] as $t){$all[$t]=$svc->successfulBackups($t,100);} echo json_encode($all,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?></script>
  <div class="backup-card" style="grid-column:1/-1"><h3>Riwayat Backup</h3><div style="overflow:auto"><table class="backup-table"><thead><tr><th>Waktu</th><th>Jenis</th><th>Status</th><th>File</th><th>Ukuran</th><th>Metode</th><th>Pesan</th></tr></thead><tbody>
  <?php foreach($jobs as $j): $cls=$j['status']==='success'?'ok':($j['status']==='failed'?'bad':'run'); ?><tr><td><?=backup_h($j['started_at']?:$j['created_at'])?></td><td><?=backup_h($j['backup_type'])?></td><td><span class="backup-status <?=$cls?>"><?=backup_h($j['status'])?></span></td><td><?=backup_h($j['filename']?:'-')?></td><td><?=backup_h(backup_bytes($j['bytes_size']??0))?></td><td><?=backup_h($j['dump_method']?:'-')?></td><td><?=backup_h($j['message']?:'-')?></td></tr><?php endforeach; ?>
  <?php if(!$jobs): ?><tr><td colspan="7">Belum ada riwayat backup.</td></tr><?php endif; ?></tbody></table></div></div>
 </div>
 </div>
 <script>
 (()=>{const dataEl=document.getElementById('backup-list-json');if(!dataEl)return;let data={};try{data=JSON.parse(dataEl.textContent||'{}')}catch(e){}const tf=document.getElementById('backup-timeframe'),dlg=document.getElementById('backup-select-dialog'),sel=document.getElementById('backup-file-select'),act=document.getElementById('backup-dialog-action'),title=document.getElementById('backup-dialog-title'),confirmWrap=document.getElementById('backup-confirm-wrap'),submit=document.getElementById('backup-dialog-submit');function open(kind){const type=tf.value,rows=data[type]||[];sel.innerHTML='';rows.forEach(r=>{const o=document.createElement('option');o.value=r.id;o.textContent=(r.started_at||r.created_at)+' — '+(r.filename||'backup')+' ('+Math.round((Number(r.bytes_size)||0)/1048576*10)/10+' MB)';sel.appendChild(o)});if(!rows.length){const o=document.createElement('option');o.value='';o.textContent='Belum ada backup berhasil pada timeframe ini';sel.appendChild(o)}act.value=kind;const restoring=kind==='restore';title.textContent=restoring?'Restore Backup':'Tes Restore';confirmWrap.hidden=!restoring;submit.textContent=restoring?'Restore Sekarang':'Jalankan Tes Restore';submit.disabled=!rows.length;if(typeof dlg.showModal==='function')dlg.showModal();else dlg.setAttribute('open','open')}document.getElementById('backup-restore-open')?.addEventListener('click',()=>open('restore'));})();
 </script>

 <?php
}
