'use strict';
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('POS', {
  saveApiConfig: (base_url, token) => ipcRenderer.invoke('auth:save-api-config', { base_url, token }),
  testApiConfig: (base_url, token) => ipcRenderer.invoke('auth:test-api-config', { base_url, token }),
  getApiConfig: () => ipcRenderer.invoke('auth:get-api-config'),
  bootstrapSession: () => ipcRenderer.invoke('auth:bootstrap'),
  loginUser: (username, password) => ipcRenderer.invoke('auth:login-user', { username, password }),
  navigate: (page) => ipcRenderer.send('navigate:' + page),
});
