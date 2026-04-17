(function () {
  const root = document.querySelector('[data-receipt-bridge="1"]');
  if (!root) return;

  const bridgeName = root.getAttribute('data-android-bridge-name') || 'AndroidBridge';
  const appBtn = root.querySelector('[data-print-via-app]');
  const browserBtn = root.querySelector('[data-print-window]');
  const settingsBtn = root.querySelector('[data-open-printer-settings]');
  const notice = root.querySelector('[data-receipt-bridge-notice]');

  const hasBridgeMarker = () =>
    !!(window.HopeAndroidBridgeInfo && window.HopeAndroidBridgeInfo.ready);

  const isAndroidApp =
    root.getAttribute('data-is-android-app') === '1' ||
    /HOPePOSAndroidWebView/i.test(navigator.userAgent || '') ||
    hasBridgeMarker();

  const showNotice = (message, type) => {
    if (!notice) return;
    notice.hidden = false;
    notice.className = 'receipt-notice no-print ' + (type ? `is-${type}` : '');
    notice.textContent = message;
  };

  const parseBridgeResult = (raw) => {
    if (raw === true || raw === 1) return { ok: true };
    if (raw === false || raw === 0) return { ok: false, message: 'Bridge Android menolak perintah cetak.' };
    if (typeof raw === 'string') {
      const normalized = raw.trim().toLowerCase();
      if (normalized === 'true' || normalized === '1') return { ok: true };
      if (normalized === 'false' || normalized === '0') {
        return { ok: false, message: 'Bridge Android menolak perintah cetak.' };
      }
      try {
        const parsed = JSON.parse(raw);
        if (typeof parsed === 'boolean') return { ok: parsed };
        if (typeof parsed === 'number') return { ok: parsed !== 0 };
        if (parsed && typeof parsed === 'object') {
          return {
            ok: parsed.ok !== false,
            message: parsed.message || '',
            code: parsed.code || '',
          };
        }
      } catch (_) {
        return { ok: true };
      }
      return { ok: true };
    }

    if (raw && typeof raw === 'object') {
      return {
        ok: raw.ok !== false,
        message: raw.message || '',
        code: raw.code || '',
      };
    }

    return { ok: true };
  };

  const parseBridgeStatus = (raw) => {
    const parsed = parseBridgeResult(raw);
    return {
      ready: parsed.ok !== false,
      raw,
      parsed,
    };
  };

  const getAndroidBridge = () => {
    const bridge = window[bridgeName];
    if (!bridge) return null;
    return bridge;
  };

  const hasAndroidBridge = () => {
    const bridge = getAndroidBridge();
    return !!(bridge && typeof bridge.printReceipt === 'function');
  };

  const getBridgeReadiness = () => {
    const bridge = getAndroidBridge();
    if (!bridge || typeof bridge.printReceipt !== 'function') {
      return { canUse: false, readyRaw: null, parsedReady: false };
    }
    if (typeof bridge.isReady !== 'function') {
      return { canUse: true, readyRaw: null, parsedReady: true };
    }
    try {
      const readyRaw = bridge.isReady();
      const status = parseBridgeStatus(readyRaw);
      return { canUse: status.ready, readyRaw, parsedReady: status.ready };
    } catch (_) {
      return { canUse: false, readyRaw: null, parsedReady: false };
    }
  };

  const canUseAndroidNativePrint = () => getBridgeReadiness().canUse;

  const loadPayloadJson = () => {
    const payloadNode = document.getElementById('receipt-bridge-payload');
    if (!payloadNode) return null;
    const raw = (payloadNode.textContent || '').trim();
    if (!raw) return null;
    try {
      const parsed = JSON.parse(raw);
      return JSON.stringify(parsed);
    } catch (_) {
      return null;
    }
  };

  const openBrowserPrint = () => window.print();

  const openPrinterSettings = () => {
    const bridge = getAndroidBridge();
    if (bridge && typeof bridge.openPrinterSettings === 'function') {
      bridge.openPrinterSettings();
      return;
    }
    showNotice('Halaman pengaturan printer hanya tersedia di APK Android.', 'warn');
  };

  const syncBridgeUi = () => {
    const readiness = getBridgeReadiness();
    const bridgeAvailable = readiness.canUse;
    if (settingsBtn) settingsBtn.hidden = !bridgeAvailable;
    if (appBtn) appBtn.disabled = isAndroidApp && !bridgeAvailable;
    if (browserBtn) browserBtn.hidden = isAndroidApp;
    if (isAndroidApp && !bridgeAvailable) {
      showNotice('Bridge Android tidak tersedia. Buka receipt dari dalam APK HOPe POS.', 'warn');
    }
    console.info('[HOPe POS] receipt bridge state', {
      isAndroidApp,
      hasBridgeMarker: hasBridgeMarker(),
      hasAndroidBridge: hasAndroidBridge(),
      readyRaw: readiness.readyRaw,
      parsedReady: readiness.parsedReady,
      canUseAndroidNativePrint: bridgeAvailable,
    });
  };

  const tryNativePrint = () => {
    const payloadJson = loadPayloadJson();
    if (!payloadJson) {
      showNotice('Gagal memproses data receipt.', 'warn');
      return;
    }

    if (!isAndroidApp) {
      showNotice('Mode browser biasa terdeteksi. Gunakan Print Browser.', 'warn');
      openBrowserPrint();
      return;
    }

    const bridge = getAndroidBridge();
    if (!bridge || typeof bridge.printReceipt !== 'function') {
      showNotice('Bridge Android tidak tersedia. Silakan buka dari APK HOPe POS.', 'warn');
      return;
    }

    const readiness = getBridgeReadiness();
    if (!readiness.canUse) {
      showNotice('Bridge Android tidak tersedia. Silakan buka dari APK HOPe POS.', 'warn');
      return;
    }

    try {
      const rawResult = bridge.printReceipt(payloadJson);
      console.info('[HOPe POS] AndroidBridge.printReceipt response', rawResult);
      const result = parseBridgeResult(rawResult);
      if (!result.ok) {
        showNotice(result.message || 'Gagal mengirim data ke printer Android.', 'warn');
        if (result.code === 'PRINTER_NOT_SELECTED') {
          openPrinterSettings();
        }
        return;
      }
      showNotice(result.message || 'Data receipt dikirim ke printer Android...', 'ok');
    } catch (err) {
      showNotice('Bridge Android error: ' + (err && err.message ? err.message : 'unknown error'), 'warn');
    }
  };

  window.HopePosBridge = {
    isAndroidApp,
    hasAndroidBridge,
    canPrintNative: canUseAndroidNativePrint,
    printReceipt: tryNativePrint,
    openPrinterSettings,
  };

  syncBridgeUi();
  if (browserBtn) browserBtn.addEventListener('click', openBrowserPrint);
  if (appBtn) appBtn.addEventListener('click', tryNativePrint);
  if (settingsBtn) settingsBtn.addEventListener('click', openPrinterSettings);
})();
