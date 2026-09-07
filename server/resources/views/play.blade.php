<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Foodtale Signage Player</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; height: 100%; background: #000; color: #fff; font-family: Arial, sans-serif; }
        #pair {
            min-height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card { width: 560px; max-width: 92vw; background: #fff; color: #111; padding: 32px; border-radius: 16px; }
        .card h1 { margin: 0 0 8px; font-size: 28px; }
        .card p { margin: 0 0 18px; color: #555; }
        input, button { width: 100%; font-size: 22px; padding: 14px; margin: 8px 0; }
        button { background: #5b4bff; color: #fff; border: 0; border-radius: 10px; font-weight: bold; }
        .error { color: #c0392b; min-height: 24px; }
        #stage { display: none; position: relative; height: 100%; background: #000; }
        #stage img, #stage video {
            position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; display: none;
        }
        #wait {
            display: none; height: 100%; align-items: center; justify-content: center;
            font-size: 32px; color: #f3c14a;
        }
        #forget {
            position: absolute; right: 12px; bottom: 12px; z-index: 3;
            width: auto; font-size: 12px; padding: 6px 10px; opacity: .25; background: #333;
        }
    </style>
</head>
<body>
<div id="pair">
    <div class="card">
        <h1>Foodtale Signage</h1>
        <p>Open this URL on each TV. Enter the pairing code from admin.</p>
        <input id="name" value="TV Browser">
        <input id="code" placeholder="Pairing code" autocomplete="off">
        <div class="error" id="error"></div>
        <button id="pairBtn">Pair device</button>
    </div>
</div>
<div id="stage">
    <div id="wait">No playlist assigned</div>
    <img id="photo" alt="">
    <video id="master" muted playsinline autoplay></video>
    <button id="forget">Unpair</button>
</div>
<script>
const TOKEN_KEY = 'foodtale_device_token';
const master = document.getElementById('master');
master.preload = 'auto';
const photo = document.getElementById('photo');
const params = new URLSearchParams(location.search);
const presetCode = (params.get('code') || '').trim();
if (presetCode) document.getElementById('code').value = presetCode;

let token = localStorage.getItem(TOKEN_KEY);
let clockOffsetMs = 0;
let playlist = { playlist_name: '', start_at: null, items: [] };
let playlistKey = '';
let masterUrl = '';
let imageUrl = '';
let cutTimer = 0;

function serverNow() {
    return Date.now() + clockOffsetMs;
}

function shouldLoop(item, fileMs) {
    const fit = item.fit || 'loop';
    if (fit === 'once' || fit === 'cut') return false;
    if (fit === 'loop') return fileMs > 0;
    const slotMs = item.duration_ms || 0;
    return fileMs > 0 && slotMs > fileMs + 80;
}

function fileDurationMs() {
    return Number.isFinite(master.duration) && master.duration > 0
        ? master.duration * 1000
        : 0;
}

function timeline(nowMs) {
    const items = playlist.items || [];
    if (!items.length || !playlist.start_at) return null;
    const total = items.reduce((sum, item) => sum + item.duration_ms, 0);
    if (total <= 0) return null;
    const start = Date.parse(playlist.start_at);
    const elapsed = nowMs - start;
    if (elapsed < 0) return { waitingMs: -elapsed };
    const mod = ((elapsed % total) + total) % total;
    let cursor = 0;
    for (let i = 0; i < items.length; i++) {
        const end = cursor + items[i].duration_ms;
        if (mod < end) return { index: i, positionMs: mod - cursor, item: items[i] };
        cursor = end;
    }
    return { index: 0, positionMs: 0, item: items[0] };
}

function msUntilCut(pos) {
    if (!pos) return 800;
    if (pos.waitingMs) return Math.max(1, pos.waitingMs);
    return Math.max(1, pos.item.duration_ms - pos.positionMs);
}

function armCut() {
    clearTimeout(cutTimer);
    cutTimer = setTimeout(() => {
        tick();
        armCut();
    }, msUntilCut(timeline(serverNow())));
}

async function ntpOffsetMs() {
    const offsets = [];
    for (let i = 0; i < 6; i++) {
        const t0 = Date.now() * 1000;
        const res = await fetch('/api/time?t0=' + t0, { cache: 'no-store' });
        const t3 = Date.now() * 1000;
        const data = await res.json();
        let offsetUs;
        let delayUs;
        if (Number.isFinite(data.t1) && Number.isFinite(data.t2)) {
            delayUs = (t3 - t0) - (data.t2 - data.t1);
            offsetUs = ((data.t1 - t0) + (data.t2 - t3)) / 2;
        } else {
            delayUs = t3 - t0;
            offsetUs = (Date.parse(data.utc) * 1000) - (t0 + delayUs / 2);
        }
        if (delayUs < 0) continue;
        offsets.push({ delayUs, offsetUs });
    }
    offsets.sort((a, b) => a.delayUs - b.delayUs);
    const best = offsets.slice(0, 3).sort((a, b) => a.offsetUs - b.offsetUs);
    const chosen = best[Math.floor(best.length / 2)] || offsets[0];
    return chosen ? chosen.offsetUs / 1000 : 0;
}

async function syncClock() {
    clockOffsetMs = await ntpOffsetMs();
}

async function pair() {
    const error = document.getElementById('error');
    error.textContent = '';
    const res = await fetch('/api/pair', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({
            pairing_code: document.getElementById('code').value.trim(),
            device_name: document.getElementById('name').value.trim() || 'TV Browser',
        }),
    });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) {
        error.textContent = body.message || 'Pairing failed';
        throw new Error('pair');
    }
    token = body.device_token;
    localStorage.setItem(TOKEN_KEY, token);
}

async function refreshManifest() {
    const res = await fetch('/api/device/manifest', {
        headers: { Authorization: 'Bearer ' + token, Accept: 'application/json' },
        cache: 'no-store',
    });
    if (res.status === 401) {
        localStorage.removeItem(TOKEN_KEY);
        location.reload();
        return;
    }
    const next = await res.json();
    const key = [
        next.playlist_name,
        next.start_at,
        ...(next.items || []).map((item) => `${item.id}:${item.url}:${item.duration_ms}`),
    ].join('|');
    if (key === playlistKey) return;
    playlistKey = key;
    playlist = next;
}

async function heartbeat() {
    await fetch('/api/device/heartbeat', {
        method: 'POST',
        headers: { Authorization: 'Bearer ' + token, Accept: 'application/json' },
    });
}

function show(mode) {
    document.getElementById('wait').style.display = mode === 'wait' ? 'flex' : 'none';
    photo.style.display = mode === 'image' ? 'block' : 'none';
    master.style.display = mode === 'video' ? 'block' : 'none';
}

function parkVideo(item) {
    const slotMs = item.duration_ms || 0;
    if (masterUrl === item.url) return;
    masterUrl = item.url;
    master.loop = false;
    master.pause();
    master.src = item.url;
    master.addEventListener('loadeddata', function onReady() {
        master.removeEventListener('loadeddata', onReady);
        const fileMs = fileDurationMs();
        master.loop = shouldLoop(item, fileMs);
        master.playbackRate = 1;
        master.currentTime = 0;
        master.pause();
    }, { once: true });
}

function alignVideo(item) {
    const slotMs = item.duration_ms || 0;
    if (masterUrl !== item.url) {
        masterUrl = item.url;
        master.loop = false;
        master.src = item.url;
        master.addEventListener('loadeddata', function onReady() {
            master.removeEventListener('loadeddata', onReady);
            const fileMs = fileDurationMs();
            master.loop = shouldLoop(item, fileMs);
            master.playbackRate = 1;
            master.currentTime = 0;
            master.play().catch(() => {});
        }, { once: true });
        return;
    }
    const fileMs = fileDurationMs();
    if (fileMs > 0) master.loop = shouldLoop(item, fileMs);
    if (master.paused) master.play().catch(() => {});
}

function remainingMs(pos) {
    return pos.item.duration_ms - pos.positionMs;
}

function nextItem(pos) {
    const items = playlist.items || [];
    if (!items.length) return null;
    return items[(pos.index + 1) % items.length];
}

function tick() {
    const pos = timeline(serverNow());
    const wait = document.getElementById('wait');
    if (!pos) {
        wait.textContent = playlist.playlist_name ? 'No playlist assigned' : 'Waiting for playlist';
        show('wait');
        master.pause();
        return;
    }
    if (pos.waitingMs) {
        wait.textContent = 'Sync starts in ' + Math.ceil(pos.waitingMs / 1000) + 's';
        show('wait');
        const first = playlist.items[0];
        if (first && first.type === 'video') {
            parkVideo(first);
        }
        return;
    }
    if (pos.item.type === 'image') {
        master.loop = false;
        if (!master.paused) master.pause();
        if (imageUrl !== pos.item.url) {
            imageUrl = pos.item.url;
            photo.src = pos.item.url;
        }
        show('image');
        const upcoming = nextItem(pos);
        if (upcoming && upcoming.type === 'video' && remainingMs(pos) < 1800) {
            parkVideo(upcoming);
        }
        return;
    }
    show('video');
    alignVideo(pos.item);
}

async function startPlayer() {
    document.getElementById('pair').style.display = 'none';
    document.getElementById('stage').style.display = 'block';
    await syncClock();
    await refreshManifest();
    tick();
    armCut();
    setInterval(tick, 400);
    setInterval(syncClock, 30000);
    setInterval(refreshManifest, 5000);
    setInterval(heartbeat, 20000);
}

document.getElementById('pairBtn').addEventListener('click', async () => {
    try {
        await pair();
        await startPlayer();
    } catch (e) {
        if (e.message !== 'pair') {
            document.getElementById('error').textContent = String(e);
        }
    }
});

document.getElementById('forget').addEventListener('click', () => {
    localStorage.removeItem(TOKEN_KEY);
    location.reload();
});

if (token) {
    startPlayer().catch((e) => {
        document.getElementById('error').textContent = String(e);
        document.getElementById('pair').style.display = 'flex';
        document.getElementById('stage').style.display = 'none';
    });
}
</script>
</body>
</html>
