const axios = require('axios');
const { store } = require('./config');

function sanitizeBaseUrl(baseUrlRaw) {
  const baseURL = String(baseUrlRaw || '').trim().replace(/\/$/, '');
  if (!baseURL) {
    return { ok: false, message: 'Base URL API belum disetting', detail: 'apiBaseUrl empty', status: 422 };
  }
  try {
    const parsed = new URL(baseURL);
    if (!['http:', 'https:'].includes(parsed.protocol)) {
      return { ok: false, message: 'Protocol salah. Gunakan http:// atau https://', detail: parsed.protocol, status: 422 };
    }
  } catch (err) {
    return { ok: false, message: 'Base URL API tidak valid. Gunakan http:// atau https://', detail: err.message, status: 422 };
  }
  return { ok: true, value: baseURL };
}

function tokenPreview(token) {
  const t = String(token || '').trim();
  if (!t) return '(empty)';
  if (t.length <= 8) return `${t.slice(0, 2)}***`;
  return `${t.slice(0, 4)}***${t.slice(-2)}`;
}

function mapAxiosError(err, context = 'Request API') {
  const status = err.response?.status || 0;
  const apiMessage = err.response?.data?.message || err.response?.data?.error;

  if (!status) {
    return { ok: false, message: `${context} gagal: server tidak dapat dihubungi`, detail: err.message, status: 0 };
  }
  if (status === 401 || status === 403) {
    return { ok: false, message: 'Token invalid atau tidak memiliki akses', detail: apiMessage || err.message, status };
  }
  if (status === 404) {
    return { ok: false, message: `${context} gagal: endpoint tidak ditemukan`, detail: apiMessage || err.message, status };
  }
  if (status >= 500) {
    const endpoint = err.config?.url || '';
    if (String(endpoint).includes('/api/sync/pull.php')) {
      return { ok: false, message: 'Sync gagal: server error di api/sync/pull.php', detail: apiMessage || err.message, status, endpoint };
    }
    return { ok: false, message: 'Server error', detail: apiMessage || err.message, status, endpoint };
  }
  return { ok: false, message: apiMessage || `${context} gagal`, detail: err.message, status };
}

function client(options = {}) {
  const baseURLResult = sanitizeBaseUrl(options.baseURL ?? store.get('apiBaseUrl'));
  if (!baseURLResult.ok) return baseURLResult;

  const token = String((options.token ?? store.get('apiToken')) || '').trim();
  if (!token) {
    return { ok: false, message: 'Token API belum disetting', detail: 'apiToken empty', status: 422 };
  }

  const instance = axios.create({
    baseURL: baseURLResult.value,
    timeout: 15000,
    headers: {
      Authorization: `Bearer ${token}`,
      'Content-Type': 'application/json'
    }
  });

  instance.interceptors.request.use((config) => {
    const endpoint = `${config.baseURL || ''}${config.url || ''}`;
    console.log('[api:req]', config.method?.toUpperCase(), endpoint, 'token', tokenPreview(token));
    return config;
  });

  instance.interceptors.response.use(
    (res) => {
      console.log('[api:res]', res.status, `${res.config.baseURL || ''}${res.config.url || ''}`);
      return res;
    },
    (err) => {
      const endpoint = `${err.config?.baseURL || ''}${err.config?.url || ''}`;
      console.log('[api:res]', err.response?.status || 0, endpoint);
      return Promise.reject(err);
    }
  );

  return instance;
}

async function testConnection(overrides = {}) {
  const apiClient = client(overrides);
  if (!apiClient.get) return apiClient;
  try {
    const res = await apiClient.get('/api/auth.php');
    return res.data;
  } catch (err) {
    return mapAxiosError(err, 'Test connection');
  }
}

async function login(username, password) {
  const apiClient = client();
  if (!apiClient.post) return apiClient;
  try {
    const res = await apiClient.post('/api/auth.php', { username, password });
    const user = res?.data?.user || res?.data?.data?.user || null;
    if (!res?.data?.ok || !user) {
      return { ok: false, message: res?.data?.message || 'Login gagal', detail: 'invalid login response', status: res?.status || 500 };
    }
    return { ok: true, user, session: res?.data?.session || null };
  } catch (err) {
    const mapped = mapAxiosError(err, 'Login');
    if (mapped.status === 401) {
      return { ...mapped, message: 'Username/password salah atau token invalid' };
    }
    return mapped;
  }
}

async function pullMaster(since = null) {
  try {
    const q = since ? `?since=${encodeURIComponent(since)}` : '';
    const apiClient = client();
    if (!apiClient.get) return apiClient;
    const res = await apiClient.get(`/api/sync/pull.php${q}`);
    return res.data;
  } catch (err) {
    return mapAxiosError(err, 'Sync master');
  }
}

async function pushTransactions(payload) {
  try {
    const apiClient = client();
    if (!apiClient.post) return apiClient;
    const res = await apiClient.post('/api/sync/push.php', payload);
    return res.data;
  } catch (err) {
    return mapAxiosError(err, 'Sync transaksi');
  }
}

async function shiftAction(action, payload = {}) {
  const apiClient = client();
  if (!apiClient.post) return apiClient;
  try {
    const res = await apiClient.post('/api/sync/shift.php', { action, ...payload });
    return res.data;
  } catch (err) {
    return mapAxiosError(err, `Sync shift (${action})`);
  }
}

module.exports = { testConnection, login, pullMaster, pushTransactions, shiftAction, sanitizeBaseUrl };
