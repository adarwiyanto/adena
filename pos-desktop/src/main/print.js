const { BrowserWindow, app, nativeImage } = require('electron');
const { store } = require('./config');
const fs = require('fs');
const path = require('path');
const os = require('os');
const { spawn } = require('child_process');
const axios = require('axios');

function clampNumber(value, fallback, min, max) {
  const n = Number(value);
  if (!Number.isFinite(n)) return fallback;
  return Math.min(max, Math.max(min, n));
}
function normalizePrintOptions(payload = {}) {
  const widthMm = clampNumber(payload.receiptWidthMm ?? store.get('receiptWidthMm'), 80, 35, 120);
  const marginMm = clampNumber(payload.receiptMarginMm ?? store.get('receiptMarginMm'), 2, 0, 15);
  const logoSizeMm = clampNumber(payload.receiptLogoSizeMm ?? store.get('receiptLogoSizeMm'), 22, 8, 60);
  const logoVisible = payload.receiptLogoVisible ?? store.get('receiptLogoVisible') ?? true;
  const printMode = String(payload.receiptPrintMode || store.get('receiptPrintMode') || 'auto').toLowerCase();
  const autoCut = payload.receiptAutoCut ?? store.get('receiptAutoCut') ?? true;
  const feedBeforeCutLines = clampNumber(payload.receiptFeedBeforeCutLines ?? store.get('receiptFeedBeforeCutLines'), 3, 0, 10);
  return { widthMm, marginMm, logoSizeMm, logoVisible: !!logoVisible, printMode, autoCut: !!autoCut, feedBeforeCutLines };
}
function delay(ms) { return new Promise((resolve) => setTimeout(resolve, ms)); }
function mmToMicrons(mm) { return Math.round(mm * 1000); }
function mmToPx(mm) { return Math.max(1, Math.round((Number(mm) || 0) * 96 / 25.4)); }
function mimeFromFile(filePath = '') {
  const ext = path.extname(String(filePath).toLowerCase()).replace('.', '');
  if (ext === 'jpg' || ext === 'jpeg') return 'image/jpeg';
  if (ext === 'png') return 'image/png';
  if (ext === 'gif') return 'image/gif';
  if (ext === 'webp') return 'image/webp';
  if (ext === 'svg') return 'image/svg+xml';
  return 'image/png';
}
function builtInLogoPath() {
  const candidates = [
    path.join(__dirname, '..', '..', 'assets', 'adena-logo.jpg'),
    path.join(process.resourcesPath || '', 'assets', 'adena-logo.jpg'),
    path.join(app.getAppPath(), 'assets', 'adena-logo.jpg'),
    path.join(app.getAppPath(), 'pos-desktop', 'assets', 'adena-logo.jpg')
  ];
  return candidates.find((candidate) => candidate && fs.existsSync(candidate)) || '';
}
function fileToDataUri(filePath) {
  const normalized = String(filePath || '').replace(/^file:\/\//i, '');
  const decoded = decodeURIComponent(normalized);
  if (!decoded || !fs.existsSync(decoded)) return '';
  const buffer = fs.readFileSync(decoded);
  return `data:${mimeFromFile(decoded)};base64,${buffer.toString('base64')}`;
}
async function remoteImageToDataUri(src) {
  const apiToken = String(store.get('apiToken') || '').trim();
  const response = await axios.get(src, { responseType: 'arraybuffer', timeout: 12000, headers: apiToken ? { Authorization: `Bearer ${apiToken}` } : {} });
  const contentType = response.headers?.['content-type'] || mimeFromFile(new URL(src).pathname);
  return `data:${contentType};base64,${Buffer.from(response.data).toString('base64')}`;
}
async function imageSrcToDataUri(src) {
  const raw = String(src || '').trim();
  if (!raw) return '';
  if (/^data:image\//i.test(raw)) return raw;
  if (/^file:\/\//i.test(raw)) return fileToDataUri(raw);
  if (/^[a-zA-Z]:[\\/]/.test(raw) || raw.startsWith('/')) return fileToDataUri(raw);
  if (/^https?:\/\//i.test(raw)) return remoteImageToDataUri(raw);
  return '';
}
async function inlineReceiptImages(rawHtml) {
  let html = String(rawHtml || '');
  const imgTags = [...html.matchAll(/<img\b[^>]*\bsrc=(["'])(.*?)\1[^>]*>/gi)];
  if (!imgTags.length) return html;
  let fallbackDataUri = '';
  try { const p = builtInLogoPath(); if (p) fallbackDataUri = fileToDataUri(p); } catch (_) {}
  for (const match of imgTags) {
    const fullTag = match[0];
    let dataUri = '';
    try { dataUri = await imageSrcToDataUri(match[2]); } catch (_) {}
    if (!dataUri && fallbackDataUri && /receipt-logo|brand-logo|adena/i.test(fullTag)) dataUri = fallbackDataUri;
    if (dataUri) html = html.replace(fullTag, fullTag.replace(/\bsrc=(["'])(.*?)\1/i, `src="${dataUri.replace(/&/g, '&amp;').replace(/"/g, '&quot;')}"`));
    else html = html.replace(fullTag, '<div class="receipt-logo-text">ADENA</div>');
  }
  return html;
}
function ensurePrintableHtml(html, options) {
  const raw = String(html || '');
  const style = `<style>
    @page { size: ${options.widthMm}mm auto; margin: 0; }
    html,body{margin:0;padding:0;background:#fff;color:#111;width:${options.widthMm}mm;min-height:20mm;}
    body{width:${options.widthMm}mm;max-width:${options.widthMm}mm;padding:${options.marginMm}mm;font-family:"Courier New",monospace;font-size:10px;line-height:1.28;overflow:visible;}
    *{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact;} button,.no-print{display:none!important;} img{max-width:100%;height:auto;}
    .receipt-logo{display:${options.logoVisible ? 'block' : 'none'};width:auto;max-width:${options.logoSizeMm}mm;max-height:${options.logoSizeMm}mm;object-fit:contain;margin:0 auto .4mm;padding:0;vertical-align:bottom;}
    .receipt-logo-text{display:${options.logoVisible ? 'block' : 'none'};text-align:center;font-weight:bold;font-size:14px;margin-bottom:.4mm;line-height:1.05;}
    .receipt-header{text-align:center;font-weight:700;line-height:1.12}.receipt-address{text-align:center;font-size:9px;line-height:1.15;margin:.2mm 0 1mm;white-space:normal;}
    .cart-row,.receipt-line,.row{display:flex;justify-content:space-between;gap:4px}.cart-row span:last-child,.receipt-line strong,.row span:last-child{text-align:right;margin-left:auto;}
    .cart-total,.receipt-total{font-weight:700;border-top:1px dashed #111;margin-top:2mm;padding-top:1mm}hr{border:0;border-top:1px dashed #111;margin:2mm 0;}
  </style>`;
  if (/<!doctype|<html[\s>]/i.test(raw)) return raw.includes('</head>') ? raw.replace('</head>', `${style}</head>`) : raw.replace(/<body[^>]*>/i, (m) => `${m}${style}`);
  return `<!doctype html><html><head><meta charset="utf-8">${style}</head><body>${raw}</body></html>`;
}
async function waitForReceiptReady(win) {
  return await win.webContents.executeJavaScript(`(async()=>{try{if(document.fonts&&document.fonts.ready)await document.fonts.ready;const imgs=Array.from(document.images||[]);await Promise.all(imgs.map((img)=>{if(img.complete&&img.naturalWidth>0)return Promise.resolve();if(img.decode)return img.decode().catch(()=>undefined);return new Promise((r)=>{img.onload=r;img.onerror=r;});}));await new Promise((r)=>requestAnimationFrame(()=>requestAnimationFrame(r)));}catch(_){}const b=document.body||{},d=document.documentElement||{};return{heightPx:Math.max(b.scrollHeight||0,b.offsetHeight||0,b.clientHeight||0,d.scrollHeight||0,d.offsetHeight||0,d.clientHeight||0),widthPx:Math.max(b.scrollWidth||0,b.offsetWidth||0,b.clientWidth||0,d.scrollWidth||0,d.offsetWidth||0,d.clientWidth||0)}})();`, true);
}
function stripForThermal(value = '') {
  return String(value).normalize('NFKD').replace(/[\u0300-\u036f]/g, '').replace(/[–—]/g, '-').replace(/[“”]/g, '"').replace(/[‘’]/g, "'").replace(/[^\x09\x0A\x0D\x20-\x7E]/g, '');
}
function thermalColumns(widthMm) { return Number(widthMm) >= 76 ? 48 : 32; }
function centerText(text, cols) { const t = stripForThermal(text).trim(); if (!t) return ''; if (t.length >= cols) return t.slice(0, cols); return ' '.repeat(Math.floor((cols - t.length) / 2)) + t; }
function lineText(left, right, cols) { const l = stripForThermal(left).trim(); const r = stripForThermal(right).trim(); if (!r) return l.slice(0, cols); const maxLeft = Math.max(1, cols - r.length - 1); const leftCut = l.length > maxLeft ? l.slice(0, maxLeft - 1) + '.' : l; return `${leftCut}${' '.repeat(Math.max(1, cols - leftCut.length - r.length))}${r}`; }
function wrapText(text, cols) { const words = stripForThermal(text).replace(/\s+/g, ' ').trim().split(' ').filter(Boolean); const lines = []; let line = ''; for (const word of words) { if (!line) line = word; else if ((line + ' ' + word).length <= cols) line += ' ' + word; else { lines.push(line); line = word; } } if (line) lines.push(line); return lines; }
function textToEscpos(text) { return Buffer.concat([Buffer.from([0x1b, 0x74, 0x00]), Buffer.from(stripForThermal(text), 'ascii')]); }
function dataUriToTempFile(dataUri) { const m = /^data:.*?;base64,(.+)$/i.exec(String(dataUri || '')); if (!m) return ''; const f = path.join(os.tmpdir(), `adena-logo-${Date.now()}-${Math.random().toString(16).slice(2)}.img`); fs.writeFileSync(f, Buffer.from(m[1], 'base64')); return f; }
async function resolveLogoFile(payload = {}) {
  const receipt = payload.rawReceipt || {};
  const candidate = receipt.logo || receipt.logoSrc || payload.logoSrc || '';
  if (candidate) {
    try { if (/^data:image\//i.test(candidate)) return dataUriToTempFile(candidate); const d = await imageSrcToDataUri(candidate); if (d) return dataUriToTempFile(d); } catch (_) {}
  }
  return builtInLogoPath();
}
function buildRasterLogoBuffer(logoPath, options) {
  if (!logoPath || !fs.existsSync(logoPath) || !options.logoVisible) return Buffer.alloc(0);
  try {
    let img = nativeImage.createFromPath(logoPath);
    if (img.isEmpty()) return Buffer.alloc(0);
    const maxDots = Number(options.widthMm) >= 76 ? 360 : 240;
    const target = Math.min(maxDots, Math.max(96, Math.round(Number(options.logoSizeMm || 22) * 8)));
    const width = Math.max(8, Math.floor(target / 8) * 8);
    img = img.resize({ width, quality: 'best' });
    const size = img.getSize(); const bitmap = img.toBitmap(); const rowBytes = Math.ceil(size.width / 8); const data = Buffer.alloc(rowBytes * size.height);
    for (let y = 0; y < size.height; y += 1) for (let x = 0; x < size.width; x += 1) {
      const idx = (y * size.width + x) * 4; const b = bitmap[idx] || 255; const g = bitmap[idx + 1] || 255; const r = bitmap[idx + 2] || 255; const a = bitmap[idx + 3] ?? 255; const lum = 0.299 * r + 0.587 * g + 0.114 * b;
      if (a > 32 && lum < 180) data[y * rowBytes + (x >> 3)] |= (0x80 >> (x & 7));
    }
    return Buffer.concat([Buffer.from([0x1b, 0x61, 0x01]), Buffer.from([0x1d, 0x76, 0x30, 0x00, rowBytes & 255, (rowBytes >> 8) & 255, size.height & 255, (size.height >> 8) & 255]), data, Buffer.from([0x0a, 0x1b, 0x61, 0x00])]);
  } catch (error) { console.warn('[print:thermal:logo:skip]', error.message || error); return Buffer.alloc(0); }
}

async function buildShiftCloseThermalRawBuffer(payload = {}, options) {
  const r = payload.rawReceipt || {};
  const cols = thermalColumns(options.widthMm);
  const sep = '-'.repeat(cols);
  const chunks = [Buffer.from([0x1b, 0x40, 0x1b, 0x21, 0x00])];
  chunks.push(buildRasterLogoBuffer(await resolveLogoFile(payload), options));
  const lines = [];
  lines.push(centerText(r.storeName || 'Adena', cols));
  if (r.storeAddress) lines.push(...wrapText(r.storeAddress, cols).map((x) => centerText(x, cols)));
  lines.push(sep);
  lines.push(centerText(r.title || 'PENUTUPAN SHIFT', cols));
  lines.push(sep);
  lines.push(lineText('Dicetak', r.printedAt || '-', cols));
  lines.push(lineText('Kasir', r.cashierName || '-', cols));
  lines.push(lineText('Shift', r.shiftCode || '-', cols));
  lines.push(lineText('Mulai', r.openedAt || '-', cols));
  lines.push(lineText('Akhir', r.closedAt || '-', cols));
  lines.push(sep);
  lines.push(lineText('Transaksi', String(r.transactionCount ?? 0), cols));
  lines.push(lineText('Item', String(r.itemQty ?? 0), cols));
  lines.push(lineText('Total Penjualan', r.totalSalesText || '-', cols));
  lines.push(sep);
  lines.push(lineText('Kas Awal', r.openingCashText || '-', cols));
  lines.push(lineText('Penjualan Tunai', r.cashSalesText || '-', cols));
  lines.push(lineText('Non Tunai', r.nonCashSalesText || '-', cols));
  lines.push(lineText('Kas Masuk-Keluar', r.cashInOutText || '-', cols));
  lines.push(lineText('Kas Diharapkan', r.expectedCashText || '-', cols));
  lines.push(lineText('Kas Aktual', r.countedCashText || '-', cols));
  lines.push(lineText('Selisih Kas', r.cashDifferenceText || '-', cols));
  lines.push(sep);
  const payments = Array.isArray(r.payments) ? r.payments : [];
  if (payments.length) {
    lines.push(centerText('RINCIAN PEMBAYARAN', cols));
    for (const p of payments) lines.push(lineText(p.label || '-', p.totalText || '-', cols));
    lines.push(sep);
  }
  lines.push(lineText('Total Diharapkan', r.totalExpectedText || '-', cols));
  lines.push(lineText('Total Aktual', r.totalActualText || '-', cols));
  lines.push(lineText('Total Selisih', r.totalDifferenceText || '-', cols));
  const feedLines = '\n'.repeat(Math.max(0, Number(options.feedBeforeCutLines ?? 3)));
  lines.push(sep, centerText(r.appFooter || 'Adena POS Desktop ver 1.4.2', cols));
  chunks.push(textToEscpos(lines.join('\n') + '\n' + feedLines));
  if (options.autoCut) chunks.push(Buffer.from([0x1d, 0x56, 0x42, 0x00]));
  return Buffer.concat(chunks);
}

async function buildThermalRawBuffer(payload = {}, options) {
  const r = payload.rawReceipt || {}; const cols = thermalColumns(options.widthMm); const sep = '-'.repeat(cols); const chunks = [Buffer.from([0x1b, 0x40, 0x1b, 0x21, 0x00])];
  chunks.push(buildRasterLogoBuffer(await resolveLogoFile(payload), options));
  const lines = [centerText(r.storeName || 'Adena', cols)];
  if (r.storeAddress) lines.push(...wrapText(r.storeAddress, cols).map((x) => centerText(x, cols)));
  lines.push(sep, lineText('Receipt', r.transactionCode || '-', cols), lineText('Waktu', r.soldAt || '-', cols), lineText('Kasir', r.cashierName || '-', cols), lineText('Guide', r.guideName || '-', cols), lineText('Metode', r.paymentMethod || '-', cols));
  if (r.paymentBank) lines.push(lineText('Bank', r.paymentBank, cols));
  lines.push(sep);
  for (const item of Array.isArray(r.items) ? r.items : []) {
    const label = `${item.name || 'Item'} x${Number(item.qty || 0)}`; const total = item.totalText || item.total || '';
    if (stripForThermal(label).length + stripForThermal(total).length + 1 <= cols) lines.push(lineText(label, total, cols)); else { lines.push(...wrapText(label, cols)); lines.push(lineText('', total, cols)); }
  }
  lines.push(sep);
  chunks.push(textToEscpos(lines.join('\n') + '\n'));
  chunks.push(Buffer.from([0x1b, 0x45, 0x01]), textToEscpos(lineText('TOTAL', r.totalText || r.total || '-', cols) + '\n'), Buffer.from([0x1b, 0x45, 0x00]));
  if (r.cashReceivedText) chunks.push(textToEscpos(lineText('Diterima', r.cashReceivedText, cols) + '\n'));
  if (r.cashChangeText) chunks.push(textToEscpos(lineText('Kembalian', r.cashChangeText, cols) + '\n'));
  const feedLines = '\n'.repeat(Math.max(0, Number(options.feedBeforeCutLines ?? 3)));
  chunks.push(textToEscpos(`${sep}\n${centerText('Terima kasih', cols)}\n${centerText(r.appFooter || 'Adena POS ver 1.4.2', cols)}\n${feedLines}`));
  if (options.autoCut) {
    // GS V B 0 = partial cut. Dipakai hanya pada mode Raw Thermal.
    chunks.push(Buffer.from([0x1d, 0x56, 0x42, 0x00]));
  }
  return Buffer.concat(chunks);
}
function psSingleQuote(value) {
  return `'${String(value).replace(/'/g, "''")}'`;
}
function rawPrintWithPowerShell(printerName, rawBuffer) {
  return new Promise((resolve, reject) => {
    if (process.platform !== 'win32') return reject(new Error('Raw thermal print hanya tersedia di Windows.'));
    const tempFile = path.join(os.tmpdir(), `adena-raw-print-${Date.now()}-${Math.random().toString(16).slice(2)}.bin`);
    fs.writeFileSync(tempFile, rawBuffer);
    const psScript = `$ErrorActionPreference='Stop'
$printer=${psSingleQuote(printerName)}
$file=${psSingleQuote(tempFile)}
Add-Type -TypeDefinition @"
using System;
using System.IO;
using System.Runtime.InteropServices;
public class R{
  [StructLayout(LayoutKind.Sequential,CharSet=CharSet.Ansi)]
  public class D{
    [MarshalAs(UnmanagedType.LPStr)] public string pDocName;
    [MarshalAs(UnmanagedType.LPStr)] public string pOutputFile;
    [MarshalAs(UnmanagedType.LPStr)] public string pDataType;
  }
  [DllImport("winspool.Drv",EntryPoint="OpenPrinterA",SetLastError=true,CharSet=CharSet.Ansi,ExactSpelling=true,CallingConvention=CallingConvention.StdCall)]
  public static extern bool OpenPrinter(string n,out IntPtr h,IntPtr p);
  [DllImport("winspool.Drv",SetLastError=true)] public static extern bool ClosePrinter(IntPtr h);
  [DllImport("winspool.Drv",EntryPoint="StartDocPrinterA",SetLastError=true,CharSet=CharSet.Ansi,ExactSpelling=true,CallingConvention=CallingConvention.StdCall)]
  public static extern bool StartDocPrinter(IntPtr h,Int32 l,[In,MarshalAs(UnmanagedType.LPStruct)]D d);
  [DllImport("winspool.Drv",SetLastError=true)] public static extern bool EndDocPrinter(IntPtr h);
  [DllImport("winspool.Drv",SetLastError=true)] public static extern bool StartPagePrinter(IntPtr h);
  [DllImport("winspool.Drv",SetLastError=true)] public static extern bool EndPagePrinter(IntPtr h);
  [DllImport("winspool.Drv",SetLastError=true)] public static extern bool WritePrinter(IntPtr h,IntPtr b,Int32 c,out Int32 w);
  public static void Send(string p,string f){
    byte[] bytes=File.ReadAllBytes(f);
    IntPtr h;
    if(!OpenPrinter(p,out h,IntPtr.Zero))throw new Exception("OpenPrinter "+Marshal.GetLastWin32Error());
    D d=new D();
    d.pDocName="Adena POS Raw Thermal Receipt";
    d.pDataType="RAW";
    IntPtr u=IntPtr.Zero;
    try{
      if(!StartDocPrinter(h,1,d))throw new Exception("StartDocPrinter "+Marshal.GetLastWin32Error());
      if(!StartPagePrinter(h))throw new Exception("StartPagePrinter "+Marshal.GetLastWin32Error());
      u=Marshal.AllocCoTaskMem(bytes.Length);
      Marshal.Copy(bytes,0,u,bytes.Length);
      int w;
      if(!WritePrinter(h,u,bytes.Length,out w))throw new Exception("WritePrinter "+Marshal.GetLastWin32Error());
      EndPagePrinter(h);
      EndDocPrinter(h);
    }finally{
      if(u!=IntPtr.Zero)Marshal.FreeCoTaskMem(u);
      ClosePrinter(h);
    }
  }
}
"@
[R]::Send($printer,$file)`;
    const encodedCommand = Buffer.from(psScript, 'utf16le').toString('base64');
    const child = spawn('powershell.exe', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', encodedCommand], { windowsHide: true });
    let stderr = ''; let stdout = '';
    child.stdout.on('data', (d) => { stdout += d.toString(); });
    child.stderr.on('data', (d) => { stderr += d.toString(); });
    child.on('error', (e) => { try { fs.unlinkSync(tempFile); } catch (_) {} reject(e); });
    child.on('close', (code) => {
      try { fs.unlinkSync(tempFile); } catch (_) {}
      if (code === 0) resolve({ ok: true, stdout });
      else reject(new Error(stderr.trim() || stdout.trim() || `Raw print failed with code ${code}`));
    });
  });
}
function shouldUseRawThermal(payload, options, printerName) { const mode = String(options.printMode || 'auto').toLowerCase(); if (mode === 'html') return false; if (mode === 'thermal_raw') return true; return !!payload.rawReceipt && /pos|zj|thermal|receipt|80|58/i.test(String(printerName || '')); }
async function printHtmlReceipt(payload, options, selectedPrinter) {
  const html = ensurePrintableHtml(await inlineReceiptImages(payload.html), options);
  const win = new BrowserWindow({ show: false, width: mmToPx(options.widthMm), height: mmToPx(297), useContentSize: true, webPreferences: { sandbox: false, webSecurity: false } });
  try {
    await win.loadURL(`data:text/html;charset=utf-8,${encodeURIComponent(html)}`); await waitForReceiptReady(win); await delay(650);
    return await new Promise((resolve, reject) => {
      const printOptions = { silent: payload.silent !== false, deviceName: selectedPrinter || undefined, printBackground: true, preferCSSPageSize: true, margins: { marginType: 'none' }, pageSize: { width: mmToMicrons(options.widthMm), height: mmToMicrons(297) } };
      console.log('[print:receipt:req]', { mode: 'html', printer: selectedPrinter || '(default)', widthMm: options.widthMm, pageHeightMm: 297, silent: printOptions.silent, noAppCutCommand: true });
      win.webContents.print(printOptions, async (success, failureReason) => { console.log('[print:receipt:res]', { mode: 'html', success, failureReason: failureReason || '', printer: selectedPrinter || '(default)' }); await delay(1000); if (!win.isDestroyed()) win.close(); if (!success) reject(new Error(failureReason || 'Print failed')); else resolve({ ok: true, mode: 'html' }); });
    });
  } catch (error) { if (!win.isDestroyed()) win.close(); throw error; }
}
async function printRawThermalReceipt(payload, options, selectedPrinter) {
  const printerName = selectedPrinter || ''; if (!printerName) throw new Error('Pilih printer Windows terlebih dahulu untuk mode Raw Thermal.');
  const raw = payload?.rawReceipt?.type === 'shift_close' ? await buildShiftCloseThermalRawBuffer(payload, options) : await buildThermalRawBuffer(payload, options); console.log('[print:receipt:req]', { mode: 'thermal_raw', printer: printerName, widthMm: options.widthMm, bytes: raw.length, autoCut: options.autoCut, feedBeforeCutLines: options.feedBeforeCutLines, logo: options.logoVisible });
  await rawPrintWithPowerShell(printerName, raw); console.log('[print:receipt:res]', { mode: 'thermal_raw', success: true, printer: printerName });
  return { ok: true, mode: 'thermal_raw' };
}
async function printReceipt(payload = {}) {
  const options = normalizePrintOptions(payload); const selectedPrinter = payload.printerName || store.get('printerName') || '';
  if (shouldUseRawThermal(payload, options, selectedPrinter)) {
    try { return await printRawThermalReceipt(payload, options, selectedPrinter); } catch (error) { console.error('[print:thermal:error]', error.message || error); if (options.printMode === 'thermal_raw') throw error; }
  }
  return printHtmlReceipt(payload, options, selectedPrinter);
}
module.exports = { printReceipt };
