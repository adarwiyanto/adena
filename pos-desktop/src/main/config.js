const ElectronStore = require('electron-store');
const Store = ElectronStore.default || ElectronStore;

const store = new Store({
  name: 'settings',
  defaults: {
    apiBaseUrl: '',
    apiToken: '',
    deviceId: 'desktop-default',
    printerName: '',
    receiptWidthMm: 58,
    receiptMarginMm: 2,
    lastSyncAt: null
  }
});

module.exports = { store };
