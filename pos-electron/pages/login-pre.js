'use strict';
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('POS', {
  login:    (username, password) => ipcRenderer.invoke('auth:login', { username, password }),
  navigate: (page) => ipcRenderer.send('navigate:' + page),
});
