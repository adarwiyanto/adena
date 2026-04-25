'use strict';
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('SettingsAPI', {
  getPrinters: () => ipcRenderer.invoke('settings:getPrinters'),
  getSettings: () => ipcRenderer.invoke('settings:get'),
  saveSettings: (data) => ipcRenderer.invoke('settings:save', data),
  testPrint: (printerName) => ipcRenderer.invoke('settings:testPrint', printerName),
});
