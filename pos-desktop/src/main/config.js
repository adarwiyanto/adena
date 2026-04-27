const ElectronStore = require('electron-store');
const Store = ElectronStore.default || ElectronStore;

const DEFAULT_SETTINGS = {
  apiBaseUrl: '',
  apiToken: '',
  deviceCode: '',
  printerName: '',
  receiptWidthMm: 58,
  receiptMarginMm: 2,
  lastSyncAt: null
};

const store = new Store({
  name: 'settings',
  defaults: DEFAULT_SETTINGS
});

function getApiConfig() {
  return {
    apiBaseUrl: String(store.get('apiBaseUrl') || '').trim(),
    apiToken: String(store.get('apiToken') || '').trim()
  };
}

module.exports = { store, DEFAULT_SETTINGS, getApiConfig };
