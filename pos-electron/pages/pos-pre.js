'use strict';
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('POS', {
  // Auth
  currentUser:    ()     => ipcRenderer.invoke('auth:current'),
  logout:         ()     => ipcRenderer.invoke('auth:logout').then(() => ipcRenderer.send('navigate:login')),

  // Data
  getProducts:    ()     => ipcRenderer.invoke('data:products'),
  getCategories:  ()     => ipcRenderer.invoke('data:categories'),
  getCustomers:   ()     => ipcRenderer.invoke('data:customers'),
  getPayMethods:  ()     => ipcRenderer.invoke('data:payment-methods'),
  getGuides:      ()     => ipcRenderer.invoke('data:guides'),
  getLoyaltyRew:  ()     => ipcRenderer.invoke('data:loyalty-rewards'),
  getSettings:    ()     => ipcRenderer.invoke('data:server-settings'),

  // Shift
  getActiveShift: ()     => ipcRenderer.invoke('shift:active'),
  openShift:      (data) => ipcRenderer.invoke('shift:open', data),
  closeShift:     (data) => ipcRenderer.invoke('shift:close', data),
  shiftSummary:   ()     => ipcRenderer.invoke('shift:summary'),

  // Cash
  addCash:        (data) => ipcRenderer.invoke('cash:add', data),

  // Checkout
  setPendingCart: (data) => ipcRenderer.invoke('checkout:set-pending', data),
  openPayment:    ()     => ipcRenderer.send('open:payment'),

  // Sync
  syncManual:     ()     => ipcRenderer.invoke('sync:manual'),
  syncStatus:     ()     => ipcRenderer.invoke('sync:status'),

  // Events dari main
  onSyncStart:    (cb)   => ipcRenderer.on('sync:start', cb),
  onSyncDone:     (cb)   => ipcRenderer.on('sync:done', (_, r) => cb(r)),
  onReset:        (cb)   => ipcRenderer.on('pos:reset', cb),
});
