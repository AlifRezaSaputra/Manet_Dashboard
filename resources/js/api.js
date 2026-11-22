// resources/js/api.js
// Lapisan data: bicara dengan Laravel API dan menerjemahkan hasilnya
// ke bentuk yang dipakai Dashboard.

const API_BASE = '/api/dashboard';

let authToken = null; // Token hanya di memori: hilang saat refresh/tutup tab (sesuai spesifikasi)
let onUnauthorized = () => {};

export function setUnauthorizedHandler(fn) {
    onUnauthorized = fn;
}

export function logout() {
    authToken = null;
}

export async function login(password) {
    const res = await fetch('/api/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ password }),
    });
    const json = await res.json().catch(() => ({}));

    if (res.status === 401) throw new Error('Password salah');
    if (res.status === 429) throw new Error('Terlalu banyak percobaan. Tunggu sebentar lalu coba lagi.');
    if (res.status === 503) throw new Error('Login belum dikonfigurasi di server');
    if (!res.ok || !json.token) throw new Error(`Gagal login (HTTP ${res.status})`);

    authToken = json.token;
}

function authHeaders(extra = {}) {
    return { Accept: 'application/json', ...extra, Authorization: `Bearer ${authToken}` };
}

// Ubah string/angka menjadi angka, atau null kalau tidak valid.
// Penting: 0 tetap 0, bukan null.
function toNumber(value) {
    if (value === null || value === undefined || value === '') return null;
    const n = Number(value);
    return Number.isFinite(n) ? n : null;
}

// is_active dari tinyint bisa datang sebagai 1, true, atau "1".
function isSosActive(sos) {
    if (!sos) return false;            // null berarti belum pernah ada SOS
    return sos.is_active === true || sos.is_active === 1 || sos.is_active === '1';
}

// Terjemahan: format server -> format papan tulis.
// `detail` adalah isi `data` dari GET /nodes/{id}, atau null kalau gagal dibaca.
export function mapNode(listItem, detail) {
    const location = detail?.location ?? null;
    const battery = detail?.battery ?? null;
    const sos = detail?.sos ?? null;

    const lat = toNumber(location?.latitude);
    const lng = toNumber(location?.longitude);

    return {
        id: listItem.node_id,                 // 'NODE02'
        dbId: listItem.id,                    // 2, dipakai untuk memanggil API
        name: listItem.node_name,
        lat,
        lng,
        hasLocation: lat !== null && lng !== null,
        battery: toNumber(battery?.battery_percent), // 0 valid, null = tidak ada data
        isSos: isSosActive(sos),
        sosTriggeredAt: sos?.triggered_at ?? null,
        lastSeenAt: listItem.last_seen_at,
        telemetryFailed: detail === null,     // true kalau request detail gagal
    };
}

async function getJson(url) {
    const res = await fetch(url, { headers: authHeaders() });
    if (res.status === 401) {
        authToken = null;
        onUnauthorized();
        throw new Error('Sesi berakhir');
    }
    if (!res.ok) throw new Error(`HTTP ${res.status} untuk ${url}`);
    return res.json();
}

export async function fetchDashboardNodes() {
    // 1) Daftar node (array polos)
    const list = await getJson(`${API_BASE}/nodes`);

    // 2) Hanya pendaki; BASE tidak ikut
    const hikers = list.filter((n) => n.node_type === 'pendaki');

    // 3) Detail tiap node secara paralel; satu gagal tidak membatalkan yang lain
    const results = await Promise.allSettled(
        hikers.map((n) => getJson(`${API_BASE}/nodes/${n.id}`))
    );

    return hikers.map((n, i) => {
        const r = results[i];
        const detail = r.status === 'fulfilled' ? r.value.data : null;
        return mapNode(n, detail);
    });
}

export async function resolveSos(dbId) {
    const res = await fetch(`${API_BASE}/nodes/${dbId}/resolve`, {
        method: 'POST',
        headers: authHeaders(),
    });
    if (res.status === 401) {
        authToken = null;
        onUnauthorized();
        throw new Error('Sesi berakhir');
    }
    // 409 = sudah tidak ada SOS aktif: anggap beres
    if (!res.ok && res.status !== 409) throw new Error(`HTTP ${res.status}`);
}

export async function fetchSosHistory({ order = 'desc', node = '' } = {}) {
    const params = new URLSearchParams({ order });
    if (node) params.set('node', node);
    const json = await getJson(`${API_BASE}/sos-history?${params}`);
    return json.data;
}

export async function fetchQosEnabled() {
    try {
        const res = await fetch('/api/qos/status', { headers: { Accept: 'application/json' } });
        return res.ok;
    } catch {
        return false;
    }
}

export async function fetchQosTests() {
    const json = await getJson('/api/qos/tests');
    return json.data;
}

export async function createQosTest(payload) {
    const res = await fetch('/api/qos/tests', {
        method: 'POST',
        headers: authHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify(payload),
    });
    if (res.status === 401) {
        authToken = null;
        onUnauthorized();
        throw new Error('Sesi berakhir');
    }
    const json = await res.json().catch(() => ({}));
    if (res.status === 422) {
        const first = json.errors ? Object.values(json.errors).flat()[0] : null;
        throw new Error(first || 'Data tidak valid');
    }
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return json.data;
}

async function qosRequest(url, method = 'GET') {
    const res = await fetch(url, { method, headers: authHeaders() });
    if (res.status === 401) {
        authToken = null;
        onUnauthorized();
        throw new Error('Sesi berakhir');
    }
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.message || `HTTP ${res.status}`);
    return json;
}

export async function fetchQosTest(id) {
    return (await qosRequest(`/api/qos/tests/${id}`)).data;
}

export async function startQosTest(id) {
    return (await qosRequest(`/api/qos/tests/${id}/start`, 'POST')).data;
}

export async function stopQosTest(id) {
    return (await qosRequest(`/api/qos/tests/${id}/stop`, 'POST')).data;
}

export async function fetchQosEvents(id, limit = 50) {
    return (await qosRequest(`/api/qos/tests/${id}/events?limit=${limit}`)).data;
}

export async function fetchQosResults(id) {
    return (await qosRequest(`/api/qos/tests/${id}/results`)).data;
}

export async function recalculateQos(id) {
    return (await qosRequest(`/api/qos/tests/${id}/calculate`, 'POST')).data;
}

export async function downloadQosReport(id, filename) {
    const res = await fetch(`/api/qos/tests/${id}/report/pdf`, { headers: authHeaders() });
    if (res.status === 401) {
        authToken = null;
        onUnauthorized();
        throw new Error('Sesi berakhir');
    }
    if (!res.ok) {
        const json = await res.json().catch(() => ({}));
        throw new Error(json.message || `HTTP ${res.status}`);
    }
    const url = URL.createObjectURL(await res.blob());
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}