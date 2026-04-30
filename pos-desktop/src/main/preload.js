const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('desktopAPI', {
  getSettings: () => ipcRenderer.invoke('settings:get'),
  setSettings: (patch) => ipcRenderer.invoke('settings:set', patch),
  saveApiSettings: (payload) => ipcRenderer.invoke('settings:saveApi', payload),
  getPrinters: () => ipcRenderer.invoke('settings:printers'),
  testConnection: (overrides) => ipcRenderer.invoke('api:test', overrides),
  login: (payload) => ipcRenderer.invoke('auth:login', payload),
  logout: () => ipcRenderer.invoke('auth:logout'),
  logoutWithPrompt: () => ipcRenderer.invoke('auth:logoutWithPrompt'),
  syncMaster: (options) => ipcRenderer.invoke('sync:master', options),
  syncPending: () => ipcRenderer.invoke('sync:pending'),
  cacheProductImage: (payload) => ipcRenderer.invoke('image:cacheProduct', payload),
  saveSaleLocal: (payload) => ipcRenderer.invoke('sale:saveLocal', payload),
  getPosState: () => ipcRenderer.invoke('pos:state'),
  getHistory: (filters) => ipcRenderer.invoke('history:list', filters),
  getHistoryDetail: (transactionGroupId) => ipcRenderer.invoke('history:detail', transactionGroupId),
  getHistoryRecap: (filters) => ipcRenderer.invoke('history:recap', filters),
  getOrders: () => ipcRenderer.invoke('orders:list'),
  printReceipt: (payload) => ipcRenderer.invoke('print:receipt', payload),
  shiftStatus: () => ipcRenderer.invoke('shift:status'),
  getShiftCloseReport: (payload) => ipcRenderer.invoke('shift:closeReport', payload),
  openShift: (payload) => ipcRenderer.invoke('shift:open', payload),
  closeShift: (payload) => ipcRenderer.invoke('shift:close', payload),
  retryPendingShift: () => ipcRenderer.invoke('shift:retryPending'),
  resetAllAppData: () => ipcRenderer.invoke('app:reset-all'),
  getAdenaLogoDataUrl: () => ipcRenderer.invoke('asset:adena-logo-data-url')
});

contextBridge.exposeInMainWorld('apiConfig', {
  get: () => ipcRenderer.invoke('config:getApi'),
  set: (data) => ipcRenderer.invoke('config:setApi', data)
});
