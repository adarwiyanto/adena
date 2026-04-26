const axios = require('axios');
const { store } = require('./config');

function client() {
  const baseURL = store.get('apiBaseUrl').replace(/\/$/, '');
  const token = store.get('apiToken');
  return axios.create({
    baseURL,
    timeout: 15000,
    headers: {
      Authorization: `Bearer ${token}`,
      'Content-Type': 'application/json'
    }
  });
}

async function testConnection() {
  const res = await client().get('/api/auth.php');
  return res.data;
}

async function login(username, password) {
  const res = await client().post('/api/auth.php', { username, password });
  return res.data;
}

async function pullMaster(since = null) {
  const q = since ? `?since=${encodeURIComponent(since)}` : '';
  const res = await client().get(`/api/sync/pull.php${q}`);
  return res.data;
}

async function pushTransactions(payload) {
  const res = await client().post('/api/sync/push.php', payload);
  return res.data;
}

module.exports = { testConnection, login, pullMaster, pushTransactions };
