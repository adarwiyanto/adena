const ElectronStore = require('electron-store');
const Store = ElectronStore.default || ElectronStore;

const DEFAULT_SETTINGS = {
  apiBaseUrl: '',
  apiToken: '',
  deviceCode: '',
  printerName: '',
  receiptWidthMm: 80,
  receiptMarginMm: 2,
  receiptLogoVisible: true,
  receiptLogoSizeMm: 22,
  receiptPrintMode: 'auto',
  receiptAutoCut: true,
  receiptFeedBeforeCutLines: 3,
  receiptUseFixedPageHeight: true,
  receiptPageHeightMm: 220,
  receiptBottomFeedMm: 18,
  debugMode: false,
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
