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
      return { ok: false, message: 'Protocol salah. Gunakan https://adena.co.id', detail: parsed.protocol, status: 422 };
    }
  } catch (err) {
    return { ok: false, message: 'Base URL API tidak valid. Gunakan http:// atau https://', detail: err.message, status: 422 };
  }
  return { ok: true, value: baseURL };
}

function client() {
  const baseURLResult = sanitizeBaseUrl(store.get('apiBaseUrl'));
  if (!baseURLResult.ok) return baseURLResult;
  const token = String(store.get('apiToken') || '').trim();
  if (!token) {
    return { ok: false, message: 'Token API belum disetting', detail: 'apiToken empty', status: 422 };
  }

  return axios.create({
    baseURL: baseURLResult.value,
    timeout: 15000,
    headers: {
      Authorization: `Bearer ${token}`,
      'Content-Type': 'application/json'
    }
  });
}

function mapAxiosError(err) {
  const status = err.response?.status || 0;
  const apiMessage = err.response?.data?.message;
  if (status === 404) return { ok: false, message: 'Endpoint login tidak ditemukan', detail: err.message, status };
  if (status === 401 && String(apiMessage || '').toLowerCase().includes('token')) {
    return { ok: false, message: 'Token tidak valid', detail: apiMessage, status };
  }
  if (status === 401) return { ok: false, message: 'Username/password salah', detail: apiMessage || err.message, status };
  if (!status) return { ok: false, message: 'Server tidak dapat dihubungi', detail: err.message, status: 0 };
  return { ok: false, message: apiMessage || 'Request API gagal', detail: err.message, status };
}

async function testConnection() {
  const apiClient = client();
  if (!apiClient.get) return apiClient;
  try {
    const res = await apiClient.get('/api/auth.php');
    return res.data;
  } catch (err) {
    return mapAxiosError(err);
  }
}

async function login(username, password) {
  const apiClient = client();
  if (!apiClient.post) return apiClient;
  const endpoint = '/api/auth.php';
  console.log('[login] request start', endpoint, apiClient.defaults.baseURL);
  try {
    const res = await apiClient.post(endpoint, { username, password });
    const user = res?.data?.user || res?.data?.data?.user || null;
    if (!res?.data?.ok || !user) {
      return { ok: false, message: res?.data?.message || 'Login gagal', detail: 'invalid login response', status: res?.status || 500 };
    }
    console.log('[login] success', res.status);
    return { ok: true, user, session: res?.data?.session || null };
  } catch (err) {
    const mapped = mapAxiosError(err);
    console.log('[login] failed', mapped.status, mapped.message);
    return mapped;
  }
}

async function pullMaster(since = null) {
  const q = since ? `?since=${encodeURIComponent(since)}` : '';
  const apiClient = client();
  if (!apiClient.get) throw new Error(apiClient.message || 'Config API belum valid');
  const res = await apiClient.get(`/api/sync/pull.php${q}`);
  return res.data;
}

async function pushTransactions(payload) {
  const apiClient = client();
  if (!apiClient.post) throw new Error(apiClient.message || 'Config API belum valid');
  const res = await apiClient.post('/api/sync/push.php', payload);
  return res.data;
}

module.exports = { testConnection, login, pullMaster, pushTransactions };
