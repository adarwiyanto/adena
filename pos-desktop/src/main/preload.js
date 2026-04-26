const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('desktopAPI', {
  getSettings: () => ipcRenderer.invoke('settings:get'),
  setSettings: (patch) => ipcRenderer.invoke('settings:set', patch),
  testConnection: () => ipcRenderer.invoke('api:test'),
  login: (payload) => ipcRenderer.invoke('auth:login', payload),
  getSession: () => ipcRenderer.invoke('auth:session'),
  syncMaster: () => ipcRenderer.invoke('sync:master'),
  syncPending: () => ipcRenderer.invoke('sync:pending'),
  saveSaleLocal: (payload) => ipcRenderer.invoke('sale:saveLocal', payload),
  getPosState: () => ipcRenderer.invoke('pos:state'),
  printReceipt: (payload) => ipcRenderer.invoke('print:receipt', payload)
});
