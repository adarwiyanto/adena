const Store = require('electron-store');

const store = new Store({
  name: 'settings',
  defaults: {
    apiBaseUrl: 'http://localhost/adena',
    apiToken: '',
    deviceId: 'desktop-default',
    printerName: '',
    receiptWidthMm: 58,
    receiptMarginMm: 2,
    lastSyncAt: null
  }
});

module.exports = { store };
