'use strict';
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('POS', {
  login:    (username, password) => ipcRenderer.invoke('auth:login', { username, password }),
  bootstrapSession: () => ipcRenderer.invoke('auth:bootstrap'),
  navigate: (page) => ipcRenderer.send('navigate:' + page),
});
