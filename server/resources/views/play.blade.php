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
        #tap {
            display: none; position: absolute; inset: 0; z-index: 4;
            align-items: center; justify-content: center;
            background: rgba(0,0,0,.55); font-size: 28px; cursor: pointer;
        }
        #hud {
            position: absolute; left: 12px; bottom: 8px; z-index: 3;
            font-size: 12px; opacity: .55; pointer-events: none;
            max-width: 78%;
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
        <p>Pairing puts this browser on the same playlist as the phones. It cannot join the Android UDP group — the server roster is the peer list.</p>
        <input id="name" value="TV Browser">
        <input id="code" placeholder="Pairing code" autocomplete="off">
        <div class="error" id="error"></div>
        <button id="pairBtn">Pair device</button>
    </div>
</div>
<div id="stage">
    <div id="wait">No playlist assigned</div>
    <img id="photo" alt="">
    <video id="live" muted playsinline></video>
    <video id="standby" muted playsinline></video>
    <div id="hud"></div>
    <div id="tap">Tap to start sync</div>
    <button id="forget">Unpair</button>
</div>
<script>
const TOKEN_KEY = 'foodtale_device_token';
let liveEl = document.getElementById('live');
let standEl = document.getElementById('standby');
const photo = document.getElementById('photo');
const hud = document.getElementById('hud');
const params = new URLSearchParams(location.search);
const presetCode = (params.get('code') || '').trim();
if (presetCode) document.getElementById('code').value = presetCode;

let token = localStorage.getItem(TOKEN_KEY);
let clockOffsetMs = 0;
let clockRttMs = 0;
let playlist = { playlist_name: '', start_at: null, items: [], peers: [], peer_count: 1 };
let playlistKey = '';
let playlistEtag = '';
let liveUrl = '';
let standbyUrl = '';
let imageUrl = '';
let currentIndex = -1;
let cutTimer = 0;
let cutting = false;
const tap = document.getElementById('tap');

function muteVideos() {
    liveEl.muted = true;
    standEl.muted = true;
    liveEl.playsInline = true;
    standEl.playsInline = true;
    liveEl.setAttribute('playsinline', '');
    standEl.setAttribute('playsinline', '');
}

function unlockMedia() {
    muteVideos();
    tap.style.display = 'none';
    const probe = liveEl.play();
    if (probe && probe.then) {
        probe.then(() => {
            if (currentIndex < 0) liveEl.pause();
        }).catch(() => {});
    }
}

function tryPlay(el) {
    muteVideos();
    const p = el.play();
    if (!p || !p.then) return;
    p.then(() => { tap.style.display = 'none'; }).catch(() => {
        tap.style.display = 'flex';
    });
}

function serverNow() {
    return Date.now() + clockOffsetMs;
}

function peerLine() {
    const names = (playlist.peers || []).map((peer) => peer.name).filter(Boolean);
    const n = playlist.peer_count || names.length || 1;
    return 'peers ' + n + (names.length ? ' (' + names.join(', ') + ')' : '');
}

function paintHud() {
    const name = playlist.playlist_name || 'no playlist';
    hud.textContent = name + ' · ' + peerLine()
        + ' · offset ' + Math.round(clockOffsetMs) + 'ms · rtt ' + Math.round(clockRttMs) + 'ms';
}

function shouldLoop(item, fileMs) {
    const fit = item.fit || 'loop';
    if (fit === 'once' || fit === 'cut') return false;
    if (fit === 'loop') return fileMs > 0;
    const slotMs = item.duration_ms || 0;
    return fileMs > 0 && slotMs > fileMs + 80;
}

function fileDurationMs(video) {
    return Number.isFinite(video.duration) && video.duration > 0
        ? video.duration * 1000
        : 0;
}

function timeline(nowMs) {
    const items = playlist.items || [];
    if (!items.length || !playlist.start_at) return null;
    const total = items.reduce((sum, item) => sum + item.duration_ms, 0);
    if (total <= 0) return null;
    const start = Date.parse(playlist.start_at);
    if (!Number.isFinite(start)) return { waitingMs: 1000 };
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

function nextCutAtMs(nowMs) {
    const items = playlist.items || [];
    if (!items.length || !playlist.start_at) return nowMs + 800;
    const origin = Date.parse(playlist.start_at);
    if (!Number.isFinite(origin)) return nowMs + 800;
    if (nowMs < origin) return origin;
    const total = items.reduce((sum, item) => sum + item.duration_ms, 0);
    if (total <= 0) return nowMs + 800;
    const elapsed = nowMs - origin;
    const cycleStart = elapsed - (elapsed % total);
    const mod = elapsed % total;
    let cursor = 0;
    for (const item of items) {
        cursor += item.duration_ms;
        if (mod < cursor) return origin + cycleStart + cursor;
    }
    return origin + cycleStart + total;
}

function show(mode) {
    document.getElementById('wait').style.display = mode === 'wait' ? 'flex' : 'none';
    photo.style.display = mode === 'image' ? 'block' : 'none';
    liveEl.style.display = mode === 'video' ? 'block' : 'none';
    if (mode !== 'video') standEl.style.display = 'none';
}

function renderWait(pos) {
    const wait = document.getElementById('wait');
    if (!pos) {
        const unassigned = !playlist.playlist_name || playlist.playlist_name === 'Unassigned';
        wait.textContent = unassigned
            ? 'Paired — assign this device to the playlist in admin'
            : 'Playlist has no media';
        show('wait');
        liveEl.pause();
        return true;
    }
    if (pos.waitingMs) {
        wait.textContent = 'Sync starts in ' + Math.ceil(pos.waitingMs / 1000) + 's · ' + peerLine();
        show('wait');
        const first = playlist.items[0];
        if (first && first.type === 'video') parkStandby(first);
        return true;
    }
    return false;
}

function armCut() {
    clearTimeout(cutTimer);
    const now = serverNow();
    const cutAt = nextCutAtMs(now);
    const delay = cutAt - now;
    if (delay <= 0) {
        cutTimer = setTimeout(() => { onCut(); armCut(); }, 0);
        return;
    }
    if (delay > 32) {
        cutTimer = setTimeout(armCut, delay - 12);
        return;
    }
    cutTimer = setTimeout(() => {
        if (serverNow() >= cutAt) onCut();
        armCut();
    }, 4);
}

function onCut() {
    if (cutting) return;
    cutting = true;
    try {
        const pos = timeline(serverNow());
        if (renderWait(pos)) {
            currentIndex = -1;
            return;
        }
        if (pos.index !== currentIndex) {
            currentIndex = pos.index;
            reveal(pos.item);
        }
        primeNext(pos);
    } finally {
        cutting = false;
    }
}

function nextItem(pos) {
    const items = playlist.items || [];
    if (!items.length) return null;
    return items[(pos.index + 1) % items.length];
}

function parkStandby(item) {
    if (item.type !== 'video' || standbyUrl === item.url) return;
    standbyUrl = item.url;
    standEl.pause();
    standEl.loop = false;
    standEl.src = item.url;
    standEl.addEventListener('loadeddata', function onReady() {
        standEl.removeEventListener('loadeddata', onReady);
        standEl.loop = shouldLoop(item, fileDurationMs(standEl));
        standEl.currentTime = 0;
        standEl.pause();
    }, { once: true });
}

function primeNext(pos) {
    const upcoming = nextItem(pos);
    if (upcoming) parkStandby(upcoming);
}

function reveal(item) {
    if (item.type === 'image') {
        liveEl.pause();
        if (imageUrl !== item.url) {
            imageUrl = item.url;
            photo.src = item.url;
        }
        show('image');
        return;
    }
    if (standbyUrl === item.url) {
        const prev = liveEl;
        liveEl = standEl;
        standEl = prev;
        liveUrl = item.url;
        standbyUrl = '';
        liveEl.currentTime = 0;
        liveEl.loop = shouldLoop(item, fileDurationMs(liveEl));
        liveEl.style.display = 'block';
        standEl.style.display = 'none';
        standEl.pause();
        liveEl.play().catch(() => tryPlay(liveEl));
        show('video');
        return;
    }
    liveUrl = item.url;
    liveEl.pause();
    liveEl.loop = false;
    liveEl.src = item.url;
    liveEl.addEventListener('loadeddata', function onReady() {
        liveEl.removeEventListener('loadeddata', onReady);
        liveEl.loop = shouldLoop(item, fileDurationMs(liveEl));
        liveEl.currentTime = 0;
        liveEl.play().catch(() => tryPlay(liveEl));
    }, { once: true });
    show('video');
}

async function ntpOffsetMs() {
    const offsets = [];
    for (let i = 0; i < 3; i++) {
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
    clockRttMs = chosen ? chosen.delayUs / 1000 : 0;
    return chosen ? chosen.offsetUs / 1000 : 0;
}

async function syncClock() {
    clockOffsetMs = await ntpOffsetMs();
    paintHud();
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
    const qs = new URLSearchParams({
        clock_offset_ms: String(Math.round(clockOffsetMs)),
        clock_rtt_ms: String(Math.max(0, Math.round(clockRttMs))),
    });
    const headers = {
        Authorization: 'Bearer ' + token,
        Accept: 'application/json',
    };
    if (playlistEtag) headers['If-None-Match'] = playlistEtag;
    const res = await fetch('/api/device/manifest?' + qs.toString(), {
        headers,
        cache: 'no-store',
    });
    if (res.status === 401) {
        localStorage.removeItem(TOKEN_KEY);
        location.reload();
        return;
    }
    if (res.status === 304) {
        const etag = res.headers.get('ETag');
        if (etag) playlistEtag = etag;
        return;
    }
    const etag = res.headers.get('ETag');
    if (etag) playlistEtag = etag;
    const next = await res.json();
    const key = [
        next.playlist_name,
        next.start_at,
        ...(next.items || []).map((item) => `${item.id}:${item.url}:${item.duration_ms}`),
    ].join('|');
    playlist.peers = next.peers || [];
    playlist.peer_count = next.peer_count || 1;
    playlist.playlist_name = next.playlist_name;
    paintHud();
    if (key === playlistKey) return;
    playlistKey = key;
    playlist = next;
    playlist.peers = next.peers || [];
    playlist.peer_count = next.peer_count || 1;
    currentIndex = -1;
    liveUrl = '';
    standbyUrl = '';
    const pos = timeline(serverNow());
    if (!renderWait(pos) && pos) {
        currentIndex = pos.index;
        reveal(pos.item);
        primeNext(pos);
    }
    armCut();
}

function kick() {
    paintHud();
    const pos = timeline(serverNow());
    if (renderWait(pos)) {
        currentIndex = -1;
        return;
    }
    if (!pos) return;
    if (pos.index !== currentIndex) {
        currentIndex = pos.index;
        reveal(pos.item);
        primeNext(pos);
        return;
    }
    if (pos.item.type === 'video' && liveEl.paused) {
        tryPlay(liveEl);
    }
}

async function startPlayer() {
    muteVideos();
    document.getElementById('pair').style.display = 'none';
    document.getElementById('stage').style.display = 'block';
    await syncClock();
    await refreshManifest();
    armCut();
    kick();
    setInterval(kick, 250);
    setInterval(refreshManifest, 5000);
}

document.getElementById('pairBtn').addEventListener('click', async () => {
    try {
        unlockMedia();
        await pair();
        await startPlayer();
    } catch (e) {
        if (e.message !== 'pair') {
            document.getElementById('error').textContent = String(e);
        }
    }
});

tap.addEventListener('click', () => {
    unlockMedia();
    kick();
    armCut();
});

document.getElementById('forget').addEventListener('click', () => {
    localStorage.removeItem(TOKEN_KEY);
    location.reload();
});

if (token) {
    tap.style.display = 'flex';
    tap.textContent = 'Tap to join playlist sync';
    startPlayer().catch((e) => {
        document.getElementById('error').textContent = String(e);
        document.getElementById('pair').style.display = 'flex';
        document.getElementById('stage').style.display = 'none';
    });
}
</script>
</body>
</html>
