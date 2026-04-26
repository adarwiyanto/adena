const { BrowserWindow } = require('electron');

async function printReceipt({ html, printerName, silent = true }) {
  const win = new BrowserWindow({ show: false, webPreferences: { sandbox: true } });
  await win.loadURL(`data:text/html;charset=utf-8,${encodeURIComponent(html)}`);
  return new Promise((resolve, reject) => {
    win.webContents.print(
      {
        silent,
        deviceName: printerName || undefined,
        printBackground: true,
        margins: { marginType: 'custom', top: 2, bottom: 2, left: 2, right: 2 },
        pageSize: { width: 58000, height: 297000 }
      },
      (success, failureReason) => {
        win.close();
        if (!success) reject(new Error(failureReason || 'Print failed'));
        else resolve({ ok: true });
      }
    );
  });
}

module.exports = { printReceipt };
