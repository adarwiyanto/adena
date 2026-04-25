'use strict';
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('PAY', {
  getCart:        ()     => ipcRenderer.invoke('checkout:get-pending'),
  getBanks:       ()     => ipcRenderer.invoke('data:banks'),
  getPayMethods:  ()     => ipcRenderer.invoke('data:payment-methods'),
  confirm:        (data) => ipcRenderer.invoke('checkout:confirm', data),
  cancel:         ()     => ipcRenderer.send('close:payment'),
  done:           (receipt) => ipcRenderer.send('checkout:done', receipt),
});
