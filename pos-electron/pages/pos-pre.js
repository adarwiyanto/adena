'use strict';
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('POS', {
  // Auth
  currentUser:    ()     => ipcRenderer.invoke('auth:current'),
  navigate:       (page) => ipcRenderer.send('navigate:' + page),
  logout:         async () => {
    await ipcRenderer.invoke('auth:logout-full');
    ipcRenderer.send('navigate:login');
  },

  // Data
  getProducts:    ()     => ipcRenderer.invoke('data:products'),
  getCategories:  ()     => ipcRenderer.invoke('data:categories'),
  getCustomers:   ()     => ipcRenderer.invoke('data:customers'),
  getPayMethods:  ()     => ipcRenderer.invoke('data:payment-methods'),
  getBanks:       ()     => ipcRenderer.invoke('data:banks'),
  getGuides:      ()     => ipcRenderer.invoke('data:guides'),
  getLandingOrders:()    => ipcRenderer.invoke('data:landing-orders'),
  loadLandingOrder:(data)=> ipcRenderer.invoke('landing:load-order', data),
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
  syncManual:     ()     => ipcRenderer.invoke('sync:run', { full: true }),
  syncStatus:     ()     => ipcRenderer.invoke('sync:status'),
  resetLocalLogin:()     => ipcRenderer.invoke('session:reset-local'),

  // Events dari main
  onSyncStart:    (cb)   => ipcRenderer.on('sync:start', cb),
  onSyncDone:     (cb)   => ipcRenderer.on('sync:done', (_, r) => cb(r)),
  onSyncWarning:  (cb)   => ipcRenderer.on('sync:warning', (_, msg) => cb(msg)),
  onReset:        (cb)   => ipcRenderer.on('pos:reset', cb),
  onCheckoutFinished: (cb) => ipcRenderer.on('pos:checkout-finished', (_, payload) => cb(payload)),
});
