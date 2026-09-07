<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Thongkee 4-TV Demo</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #0b0b0b; color: #eee; font-family: Arial, sans-serif; }
        header { display: flex; justify-content: space-between; align-items: center; padding: 12px 18px; }
        a, button { color: #111; background: #f3c14a; border: 0; padding: 8px 12px; border-radius: 8px; text-decoration: none; font-weight: bold; cursor: pointer; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; height: calc(100vh - 58px); gap: 8px; padding: 0 8px 8px; }
        .tv { position: relative; background: #111; overflow: hidden; border: 3px solid #333; border-radius: 10px; }
        .tv img, .tv canvas { width: 100%; height: 100%; object-fit: cover; display: none; }
        .tv .label { position: absolute; top: 10px; left: 10px; background: rgba(0,0,0,.65); padding: 6px 10px; border-radius: 6px; font-size: 13px; z-index: 2; }
        .countdown { text-align: center; padding-top: 28vh; font-size: 28px; color: #f3c14a; }
        #master { display: none; }
    </style>
</head>
<body>
<header>
    <div>
        <strong>Thongkee downstairs · 4 display sync</strong>
        <span id="status">loading…</span>
    </div>
    <div>
        <a href="/admin">Admin</a>
        @if($playlist)
            <form method="post" action="/admin/playlists/{{ $playlist->id }}/restart" style="display:inline">
                @csrf
                <button>Restart all TVs in 10s</button>
            </form>
        @endif
    </div>
</header>
<video id="master" muted playsinline preload="auto"></video>
<div class="grid">
    @for($i = 1; $i <= 4; $i++)
        <div class="tv" data-tv="{{ $i }}">
            <div class="label">TV {{ $i }} · <span class="clock">--</span></div>
            <div class="countdown">Waiting for playlist</div>
            <img alt="">
            <canvas></canvas>
        </div>
    @endfor
</div>
<script>
const SEEK_THRESHOLD_MS = 1000;
const SEEK_COOLDOWN_MS = 2000;
const master = document.getElementById('master');

const tvs = [...document.querySelectorAll('.tv')].map((el) => ({
    el,
    img: el.querySelector('img'),
    canvas: el.querySelector('canvas'),
    countdown: el.querySelector('.countdown'),
    clock: el.querySelector('.clock'),
    imageUrl: '',
}));

let clockOffsetMs = 0;
let playlist = { playlist_name: '', start_at: null, items: [] };
let playlistKey = '';
let masterUrl = '';
let seekAfter = 0;
let painting = false;

function serverNow() {
    return Date.now() + clockOffsetMs;
}

function timeline(nowMs) {
    const items = playlist.items;
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

async function refreshPlaylist() {
    const res = await fetch('/api/demo/playlist');
    const next = await res.json();
    const key = [
        next.playlist_name,
        next.start_at,
        ...(next.items || []).map((item) => `${item.id}:${item.url}:${item.duration_ms}`),
    ].join('|');
    if (key === playlistKey) return;
    playlistKey = key;
    playlist = next;
    document.getElementById('status').textContent =
        ` · ${playlist.playlist_name || 'none'} · start ${playlist.start_at || '-'}`;
}

function show(tv, type) {
    tv.countdown.style.display = type === 'wait' ? 'block' : 'none';
    tv.img.style.display = type === 'image' ? 'block' : 'none';
    tv.canvas.style.display = type === 'video' ? 'block' : 'none';
}

function drawCover(ctx, video, width, height) {
    const vw = video.videoWidth || width;
    const vh = video.videoHeight || height;
    if (!vw || !vh) return;
    const scale = Math.max(width / vw, height / vh);
    const dw = vw * scale;
    const dh = vh * scale;
    ctx.drawImage(video, (width - dw) / 2, (height - dh) / 2, dw, dh);
}

function paint() {
    painting = true;
    const pos = timeline(serverNow());
    if (pos && !pos.waitingMs && pos.item && pos.item.type === 'video' && master.readyState >= 2) {
        tvs.forEach((tv) => {
            const canvas = tv.canvas;
            const w = tv.el.clientWidth;
            const h = tv.el.clientHeight;
            if (canvas.width !== w) canvas.width = w;
            if (canvas.height !== h) canvas.height = h;
            drawCover(canvas.getContext('2d'), master, w, h);
        });
    }
    requestAnimationFrame(paint);
}

function loopedMediaMs(positionMs, fileMs) {
    if (!(fileMs > 0)) return Math.max(0, positionMs);
    return ((positionMs % fileMs) + fileMs) % fileMs;
}

function fileDurationMs() {
    return Number.isFinite(master.duration) && master.duration > 0
        ? master.duration * 1000
        : 0;
}

function shouldLoop(item, fileMs) {
    const fit = item.fit || 'loop';
    if (fit === 'once' || fit === 'cut') return false;
    if (fit === 'loop') return fileMs > 0;
    const slotMs = item.duration_ms || 0;
    return fileMs > 0 && slotMs > fileMs + 80;
}

function ensureImage(tv, url) {
    if (tv.imageUrl === url) return;
    tv.imageUrl = url;
    tv.img.src = url;
}

function alignMaster(item, positionMs) {
    const slotMs = item.duration_ms || 0;
    if (masterUrl !== item.url) {
        masterUrl = item.url;
        seekAfter = Date.now() + 400;
        master.loop = false;
        master.src = item.url;
        master.addEventListener('loadeddata', function onReady() {
            master.removeEventListener('loadeddata', onReady);
            const fileMs = fileDurationMs();
            master.loop = shouldLoop(item, fileMs);
            const latest = timeline(serverNow());
            const posMs = latest && latest.item && latest.item.url === item.url
                ? latest.positionMs
                : positionMs;
            master.currentTime = loopedMediaMs(posMs, fileMs) / 1000;
            seekAfter = Date.now() + 400;
            master.play().catch(() => {});
        }, { once: true });
        return;
    }
    if (master.readyState < 2) return;
    const fileMs = fileDurationMs();
    if (fileMs <= 0) return;
    master.loop = shouldLoop(item, fileMs);
    const targetMs = loopedMediaMs(positionMs, fileMs);
    const actual = master.currentTime * 1000;
    const wrap = actual > fileMs - 280 && targetMs < 280;
    if (master.ended || wrap) {
        master.currentTime = targetMs / 1000;
        seekAfter = Date.now() + 250;
        master.play().catch(() => {});
        return;
    }
    if (master.seeking || Date.now() < seekAfter) return;
    const drift = Math.abs(actual - targetMs);
    if (drift > SEEK_THRESHOLD_MS) {
        seekAfter = Date.now() + SEEK_COOLDOWN_MS;
        master.currentTime = targetMs / 1000;
        master.play().catch(() => {});
        return;
    }
    if (master.paused) master.play().catch(() => {});
}

function tick() {
    const now = serverNow();
    const pos = timeline(now);
    const clock = new Date(now).toISOString().slice(11, 23);

    tvs.forEach((tv) => {
        tv.clock.textContent = clock;
    });

    if (!pos) {
        tvs.forEach((tv) => {
            tv.countdown.textContent = 'No playlist';
            show(tv, 'wait');
        });
        return;
    }
    if (pos.waitingMs) {
        tvs.forEach((tv) => {
            tv.countdown.textContent = `Sync starts in ${Math.ceil(pos.waitingMs / 1000)}s`;
            show(tv, 'wait');
        });
        if (!master.paused) master.pause();
        return;
    }
    if (pos.item.type === 'image') {
        master.loop = false;
        if (!master.paused) master.pause();
        tvs.forEach((tv) => {
            ensureImage(tv, pos.item.url);
            show(tv, 'image');
        });
        return;
    }
    tvs.forEach((tv) => show(tv, 'video'));
    alignMaster(pos.item, pos.positionMs);
}

async function boot() {
    await syncClock();
    await refreshPlaylist();
    tick();
    if (!painting) requestAnimationFrame(paint);
    setInterval(tick, 400);
    setInterval(syncClock, 60000);
    setInterval(refreshPlaylist, 5000);
}
boot();
</script>
</body>
</html>
