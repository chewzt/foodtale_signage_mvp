<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Foodtale Signage Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        :root {
            --bg: #f4f1eb;
            --card: #fff;
            --ink: #161513;
            --muted: #6f6a63;
            --line: #e4dfd6;
            --accent: #1b5e20;
            --danger: #b42318;
            --warn: #9a6700;
        }
        * { box-sizing: border-box; }
        body {
            font-family: "Segoe UI", system-ui, sans-serif;
            background: var(--bg);
            color: var(--ink);
            margin: 0;
            line-height: 1.4;
        }
        a { color: var(--accent); }
        .flash {
            position: sticky;
            top: 0;
            z-index: 40;
            margin: 0;
            padding: 12px 20px;
            background: #e8f5e9;
            color: #1b5e20;
            text-align: center;
        }
        .flash.error { background: #fdecea; color: var(--danger); }
        .wrap { max-width: 1180px; margin: 0 auto; padding: 24px 20px 64px; }
        .hero {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-end;
            margin-bottom: 18px;
        }
        .hero h1 { font-size: 26px; margin: 0 0 6px; letter-spacing: -0.03em; }
        .hero p { margin: 0; color: var(--muted); font-size: 14px; }
        .meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 10px;
            margin-bottom: 18px;
        }
        .meta-item {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px 14px;
        }
        .meta-item span {
            display: block;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--muted);
            margin-bottom: 4px;
        }
        .meta-item strong, .meta-item a {
            font-size: 13px;
            word-break: break-all;
        }
        .row-actions { display: flex; gap: 8px; align-items: center; margin-top: 8px; }
        nav.jump {
            position: sticky;
            top: 0;
            z-index: 20;
            display: flex;
            gap: 8px;
            padding: 10px 0 14px;
            background: linear-gradient(var(--bg) 70%, transparent);
        }
        nav.jump a {
            text-decoration: none;
            color: var(--ink);
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 7px 14px;
            font-size: 13px;
            font-weight: 600;
        }
        .section { margin-bottom: 28px; }
        .section > h2 {
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
            margin: 0 0 10px;
        }
        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 18px;
            margin-bottom: 12px;
        }
        .card-head {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 10px;
            align-items: flex-start;
            margin-bottom: 12px;
        }
        .card-head h3 { margin: 0; font-size: 18px; }
        .muted, .hint { color: var(--muted); font-size: 13px; }
        .toolbar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .stack { display: flex; flex-direction: column; gap: 10px; }
        .inline { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        input, select, button {
            font: inherit;
            padding: 8px 10px;
            border-radius: 8px;
            border: 1px solid var(--line);
            background: #fff;
        }
        input[type="file"] { max-width: 100%; }
        button { cursor: pointer; background: var(--ink); color: #fff; border-color: var(--ink); }
        button.ghost { background: #fff; color: var(--ink); }
        button.danger { background: var(--danger); border-color: var(--danger); color: #fff; }
        button.copy { background: #fff; color: var(--ink); }
        button:disabled { opacity: 0.55; cursor: wait; }
        label.slot, label.field { display: inline-flex; gap: 6px; align-items: center; font-size: 13px; color: var(--muted); }
        .pill {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 3px 8px;
            border-radius: 999px;
            background: #eee;
        }
        .online { color: var(--accent); }
        .waiting { color: var(--warn); }
        .clock-ok { color: var(--accent); font-weight: 600; }
        .clock-weak { color: var(--warn); font-weight: 600; }
        .clock-bad, .clock-stale { color: var(--danger); font-weight: 600; }
        .clock-unknown { color: var(--muted); }
        .pill.online { background: #e8f5e9; color: var(--accent); }
        .pill.waiting { background: #fff4d6; color: var(--warn); }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 10px 8px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
        th { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); }
        .code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 16px; letter-spacing: 0.08em; }
        .table-wrap { overflow-x: auto; }
        .panel-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 10px;
        }
        .panel {
            border: 1px dashed var(--line);
            border-radius: 12px;
            padding: 12px;
            background: #faf8f4;
        }
        .panel.has-media { border-style: solid; background: #fff; }
        .item-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
        .item-list li {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px;
            display: grid;
            grid-template-columns: minmax(160px, 220px) 1fr;
            gap: 12px;
            align-items: start;
        }
        .preview {
            position: relative;
            background: #111;
            border-radius: 10px;
            overflow: hidden;
        }
        .preview video,
        .preview img {
            display: block;
            width: 100%;
            height: 160px;
            object-fit: cover;
            background: #111;
        }
        .preview video {
            pointer-events: none;
        }
        .preview-wait {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            background: rgba(0, 0, 0, 0.55);
            font-size: 14px;
            font-weight: 600;
        }
        .panel .preview { margin: 8px 0; }
        .item-body { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; }
        .empty { color: var(--muted); padding: 18px; text-align: center; }
        .errors { border-color: var(--danger); color: var(--danger); }
    </style>
</head>
<body>
<div id="admin-flash" class="flash" hidden></div>
<div class="wrap" id="admin-root">
    <div class="hero">
        <div>
            <h1>Foodtale Signage</h1>
            <p>Pair phones, assign a wall or playlist, then restart sync so every screen shares one clock.</p>
        </div>
        <div class="toolbar">
            <a href="/play">Browser player</a>
            <a href="/demo">4-TV demo</a>
        </div>
    </div>

    <div class="meta">
        <div class="meta-item">
            <span>Server URL</span>
            <strong>{{ $serverUrl }}</strong>
            <div class="row-actions">
                <button type="button" class="copy" data-copy="{{ $serverUrl }}">Copy</button>
            </div>
        </div>
        <div class="meta-item">
            <span>Install APK</span>
            <a href="{{ $apkUrl }}">{{ $apkUrl }}</a>
            <div class="row-actions">
                <button type="button" class="copy" data-copy="{{ $apkUrl }}">Copy</button>
                <span class="hint">v{{ $apkVersion }} · max upload {{ $uploadMaxMb }} MB</span>
            </div>
        </div>
    </div>

    @if ($errors->any())
        <div class="card errors">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <nav class="jump">
        <a href="#devices">Devices ({{ $devices->count() }})</a>
        <a href="#walls">Walls ({{ $carousels->count() }})</a>
        <a href="#playlists">Playlists ({{ $playlists->count() }})</a>
    </nav>

    <section class="section" id="devices">
        <h2>1. TV devices</h2>
        <div class="card">
            <div class="card-head">
                <div>
                    <h3>Phones and boxes</h3>
                    <p class="hint">Assign a playlist for one screen, or a wall plus which part (1 = left).</p>
                </div>
                <form method="post" action="/admin/devices">
                    @csrf
                    <button>Create pairing code</button>
                </form>
            </div>
            @if($devices->isEmpty())
                <p class="empty">No devices yet. Create a pairing code, then type the server URL into the APK.</p>
            @else
                <div class="table-wrap">
                    <table>
                        <tr>
                            <th>Name</th><th>Code</th><th>Status</th><th>Seen</th><th>Clock</th><th>Assigned</th><th>Assign</th>
                        </tr>
                        @foreach($devices as $device)
                        @php($clock = \App\Support\SignageDeviceClock::summary($device))
                        <tr>
                            <td>{{ $device->name }}</td>
                            <td class="code">{{ $device->pairing_code }}</td>
                            <td><span class="pill {{ $clock['status'] }}" data-status-for="{{ $device->id }}">{{ $clock['status'] }}</span></td>
                            <td class="hint" data-seen-for="{{ $device->id }}">{{ $clock['seen'] }}</td>
                            <td class="{{ $clock['class'] }}" data-clock-for="{{ $device->id }}">{{ $clock['label'] }}</td>
                            <td>
                                @if($device->playlist)
                                    {{ $device->playlist->name }}
                                    @if($device->playlist->isCarousel())
                                        <div class="hint">part {{ $device->panel_index + 1 }}/{{ $device->playlist->panel_count }}</div>
                                    @endif
                                @else
                                    <span class="hint">Unassigned</span>
                                @endif
                            </td>
                            <td>
                                <form method="post" action="/admin/devices/{{ $device->id }}/assign" class="assign inline">
                                    @csrf
                                    <select name="playlist_id" class="assign-target"
                                            data-selected="{{ $device->playlist_id }}">
                                        @foreach($assignTargets as $target)
                                            <option value="{{ $target->id }}"
                                                    data-kind="{{ $target->kind }}"
                                                    data-panels="{{ $target->panel_count }}"
                                                    @selected($device->playlist_id === $target->id)>
                                                @if($target->isCarousel())
                                                    [wall {{ $target->panel_count }}] {{ $target->name }}
                                                @else
                                                    {{ $target->name }}
                                                @endif
                                            </option>
                                        @endforeach
                                    </select>
                                    <select name="panel_index" class="assign-part"
                                            data-selected="{{ $device->panel_index }}">
                                        @if($device->playlist?->isCarousel())
                                            @for($i = 0; $i < $device->playlist->panel_count; $i++)
                                                <option value="{{ $i }}" @selected($device->panel_index === $i)>
                                                    Part {{ $i + 1 }} of {{ $device->playlist->panel_count }}{{ $i === 0 ? ' (left)' : ($i === $device->playlist->panel_count - 1 ? ' (right)' : '') }}
                                                </option>
                                            @endfor
                                        @else
                                            <option value="0">Fullscreen</option>
                                        @endif
                                    </select>
                                    <button>Assign</button>
                                </form>
                                <div class="inline" style="margin-top:6px">
                                    @if($device->playlist_id)
                                        <form method="post" action="/admin/devices/{{ $device->id }}/unassign">
                                            @csrf
                                            <button type="submit" class="ghost">Unassign</button>
                                        </form>
                                    @endif
                                    @if($device->device_token)
                                        <form method="post" action="/admin/devices/{{ $device->id }}/reset" data-confirm="Clear pairing so this code can be used again?">
                                            @csrf
                                            <button class="ghost">Reset pairing</button>
                                        </form>
                                    @endif
                                    <form method="post"
                                          action="/admin/devices/{{ $device->id }}/delete"
                                          data-confirm="Delete TV “{{ $device->name }}” ({{ $device->pairing_code }})? The phone must pair again with a new code.">
                                        @csrf
                                        <button class="danger">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </table>
                </div>
            @endif
        </div>
    </section>

    <section class="section" id="walls">
        <h2>2. Video walls</h2>
        <div class="card">
            <div class="card-head">
                <div>
                    <h3>New wall</h3>
                    <p class="hint">Each TV gets its own MP4. Same start_at. Panel count is locked after create.</p>
                </div>
            </div>
            <form method="post" action="/admin/carousels" class="inline">
                @csrf
                <input name="name" placeholder="Lobby wall" required>
                <label class="field">
                    TVs in a row
                    <input type="number" name="panel_count" min="2" max="8" value="4" required>
                </label>
                <button>Create wall</button>
            </form>
        </div>

        @forelse($carousels as $carousel)
            <div class="card">
                <div class="card-head">
                    <div>
                        <h3>{{ $carousel->name }}</h3>
                        <p class="hint">
                            <span class="pill">wall</span>
                            {{ $carousel->items->count() }}/{{ $carousel->panel_count }} videos
                            · sync {{ $carousel->start_at }}
                        </p>
                    </div>
                    <div class="toolbar">
                        <form method="post" action="/admin/playlists/{{ $carousel->id }}/restart">
                            @csrf
                            <button class="ghost">Restart sync</button>
                        </form>
                        <form method="post"
                              action="/admin/playlists/{{ $carousel->id }}/delete"
                              data-confirm="Delete wall “{{ $carousel->name }}”? TVs assigned to it will be unassigned.">
                            @csrf
                            <button class="danger">Delete wall</button>
                        </form>
                    </div>
                </div>
                @php($byPanel = $carousel->items->keyBy('panel_index'))
                <div class="panel-grid">
                    @for($i = 0; $i < $carousel->panel_count; $i++)
                        @php($item = $byPanel->get($i))
                        <div class="panel {{ $item ? 'has-media' : '' }}">
                            <strong>Part {{ $i + 1 }}{{ $i === 0 ? ' · left' : ($i === $carousel->panel_count - 1 ? ' · right' : '') }}</strong>
                            @if($item)
                                @include('partials.media-preview', [
                                    'item' => $item,
                                    'startAt' => ($carousel->start_at ?? now())->utc()->toIso8601String(),
                                ])
                                <p class="hint" style="margin:6px 0 10px">
                                    Ready
                                    @if($item->file_duration_ms)
                                        · file {{ number_format($item->file_duration_ms / 1000, 1) }}s
                                    @endif
                                    · slot {{ number_format($item->duration_ms / 1000, 1) }}s
                                    · {{ $item->fit }}
                                </p>
                                <form method="post"
                                      action="/admin/playlists/{{ $carousel->id }}/items/{{ $item->id }}"
                                      class="item-edit inline">
                                    @csrf
                                    <select name="fit" class="fit">
                                        <option value="once" @selected($item->fit === 'once')>Play once</option>
                                        <option value="loop" @selected($item->fit === 'loop')>Loop until N seconds</option>
                                        <option value="cut" @selected($item->fit === 'cut')>Cut after N seconds</option>
                                    </select>
                                    <label class="slot">
                                        N seconds
                                        <input type="number" name="slot_seconds" min="1" max="600"
                                               value="{{ max(1, (int) round($item->duration_ms / 1000)) }}">
                                    </label>
                                    <button class="ghost">Save</button>
                                </form>
                                <form method="post"
                                      action="/admin/playlists/{{ $carousel->id }}/items/{{ $item->id }}/delete"
                                      data-confirm="Remove this panel video?"
                                      style="margin:8px 0">
                                    @csrf
                                    <button class="danger">Remove</button>
                                </form>
                            @else
                                <p class="hint">No video on this panel yet.</p>
                            @endif
                            <form method="post"
                                  enctype="multipart/form-data"
                                  action="/admin/playlists/{{ $carousel->id }}/items"
                                  class="upload stack">
                                @csrf
                                <input type="hidden" name="panel_index" value="{{ $i }}">
                                <input type="file" name="media" accept=".mp4,video/mp4,video/quicktime" required>
                                <div class="inline">
                                    <select name="fit" class="fit">
                                        <option value="once">Play once</option>
                                        <option value="loop" selected>Loop until N seconds</option>
                                        <option value="cut">Cut after N seconds</option>
                                    </select>
                                    <label class="slot">
                                        N seconds
                                        <input type="number" name="slot_seconds" min="1" max="600" value="10">
                                    </label>
                                </div>
                                <button>{{ $item ? 'Replace video' : 'Upload video' }}</button>
                            </form>
                        </div>
                    @endfor
                </div>
            </div>
        @empty
            <p class="empty">No walls yet. Create one, then assign each phone to a part.</p>
        @endforelse
    </section>

    <section class="section" id="playlists">
        <h2>3. Playlists</h2>
        <div class="card">
            <div class="card-head">
                <div>
                    <h3>New playlist</h3>
                    <p class="hint">One screen, mixed photos and video, looping on the shared clock.</p>
                </div>
            </div>
            <form method="post" action="/admin/playlists" class="inline">
                @csrf
                <input name="name" placeholder="Breakfast Menu" required>
                <button>Create playlist</button>
            </form>
        </div>

        @forelse($playlists as $playlist)
            <div class="card">
                <div class="card-head">
                    <div>
                        <h3>{{ $playlist->name }}</h3>
                        <p class="hint">{{ $playlist->items->count() }} items · sync {{ $playlist->start_at }}</p>
                    </div>
                    <div class="toolbar">
                        <form method="post" action="/admin/playlists/{{ $playlist->id }}/restart">
                            @csrf
                            <button class="ghost">Restart sync</button>
                        </form>
                        <form method="post"
                              action="/admin/playlists/{{ $playlist->id }}/delete"
                              data-confirm="Delete playlist “{{ $playlist->name }}”? TVs assigned to it will be unassigned.">
                            @csrf
                            <button class="danger">Delete playlist</button>
                        </form>
                    </div>
                </div>
                <form method="post"
                      enctype="multipart/form-data"
                      action="/admin/playlists/{{ $playlist->id }}/items"
                      class="upload inline">
                    @csrf
                    <input type="file" name="media" accept=".mp4,.jpg,.jpeg,.png,.webp,video/mp4,video/quicktime,image/jpeg,image/png,image/webp" required>
                    <select name="fit" class="fit">
                        <option value="once">Play once</option>
                        <option value="loop" selected>Loop until N seconds</option>
                        <option value="cut">Cut after N seconds</option>
                    </select>
                    <label class="slot">
                        N seconds
                        <input type="number" name="slot_seconds" min="1" max="600" value="10">
                    </label>
                    <button>Upload media</button>
                    <span class="hint">images always use N seconds</span>
                </form>
                @if($playlist->items->isEmpty())
                    <p class="empty">No media in this playlist.</p>
                @else
                    <ol class="item-list" style="margin-top:12px">
                        @foreach($playlist->items as $item)
                            <li>
                                @include('partials.media-preview', [
                                    'item' => $item,
                                    'startAt' => ($playlist->start_at ?? now())->utc()->toIso8601String(),
                                ])
                                <div class="item-body">
                                <form method="post"
                                      action="/admin/playlists/{{ $playlist->id }}/items/{{ $item->id }}"
                                      class="item-edit inline">
                                    @csrf
                                    <strong>{{ $item->type }}</strong>
                                    @if($item->file_duration_ms)
                                        <span class="hint">file {{ number_format($item->file_duration_ms / 1000, 1) }}s</span>
                                    @endif
                                    @if($item->type === 'video')
                                        <select name="fit" class="fit">
                                            <option value="once" @selected($item->fit === 'once')>Play once</option>
                                            <option value="loop" @selected($item->fit === 'loop')>Loop until N seconds</option>
                                            <option value="cut" @selected($item->fit === 'cut')>Cut after N seconds</option>
                                        </select>
                                    @else
                                        <input type="hidden" name="fit" value="once">
                                    @endif
                                    <label class="slot">
                                        N seconds
                                        <input type="number" name="slot_seconds" min="1" max="600"
                                               value="{{ max(1, (int) round($item->duration_ms / 1000)) }}">
                                    </label>
                                    <button class="ghost">Save</button>
                                </form>
                                <form method="post"
                                      action="/admin/playlists/{{ $playlist->id }}/items/{{ $item->id }}/delete"
                                      data-confirm="Remove this media?">
                                    @csrf
                                    <button class="danger">Remove</button>
                                </form>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>
        @empty
            <p class="empty">No playlists yet.</p>
        @endforelse
    </section>
</div>
<script>
(() => {
    const flashEl = document.getElementById('admin-flash');
    const csrf = () => document.querySelector('meta[name="csrf-token"]').content;

    const flash = (message, isError = false) => {
        flashEl.hidden = !message;
        flashEl.className = isError ? 'flash error' : 'flash';
        flashEl.textContent = message;
    };

    const fillParts = (form) => {
        const target = form.querySelector('.assign-target');
        const part = form.querySelector('.assign-part');
        if (!target || !part) return;
        const option = target.selectedOptions[0];
        const kind = option?.dataset.kind || 'playlist';
        const panels = Math.max(1, parseInt(option?.dataset.panels || '1', 10));
        const selected = parseInt(part.dataset.selected || '0', 10);
        part.innerHTML = '';
        if (kind === 'carousel' && panels > 1) {
            for (let i = 0; i < panels; i++) {
                const opt = document.createElement('option');
                opt.value = String(i);
                let label = 'Part ' + (i + 1) + ' of ' + panels;
                if (i === 0) label += ' (left)';
                if (i === panels - 1) label += ' (right)';
                opt.textContent = label;
                if (i === selected) opt.selected = true;
                part.appendChild(opt);
            }
            part.style.display = '';
        } else {
            const opt = document.createElement('option');
            opt.value = '0';
            opt.textContent = 'Fullscreen';
            part.appendChild(opt);
            part.style.display = 'none';
        }
    };

    const bindAssignForms = (root) => {
        root.querySelectorAll('form.assign').forEach((form) => {
            const target = form.querySelector('.assign-target');
            if (!target) return;
            target.addEventListener('change', () => {
                form.querySelector('.assign-part').dataset.selected = '0';
                fillParts(form);
            });
            fillParts(form);
        });
    };

    const bindFitToggles = (root) => {
        root.querySelectorAll('form.upload').forEach((form) => {
            const fit = form.querySelector('.fit');
            const slot = form.querySelector('.slot');
            const file = form.querySelector('input[name="media"]');
            const sync = () => {
                const name = (file.files[0]?.name || '').toLowerCase();
                const image = /\.(jpg|jpeg|png|webp)$/.test(name);
                fit.style.display = image ? 'none' : '';
                slot.style.display = image || fit.value !== 'once' ? '' : 'none';
            };
            fit.addEventListener('change', sync);
            file.addEventListener('change', sync);
            sync();
        });
        root.querySelectorAll('form.item-edit').forEach((form) => {
            const fit = form.querySelector('.fit');
            const slot = form.querySelector('.slot');
            if (!fit || !slot) return;
            const sync = () => {
                slot.style.display = fit.value !== 'once' ? '' : 'none';
            };
            fit.addEventListener('change', sync);
            sync();
        });
    };

    const bindCopy = (root) => {
        root.querySelectorAll('[data-copy]').forEach((btn) => {
            btn.addEventListener('click', async () => {
                try {
                    await navigator.clipboard.writeText(btn.dataset.copy);
                    flash('Copied');
                } catch (_error) {
                    flash('Copy failed', true);
                }
            });
        });
    };

    const previewTimers = [];
    let previewOffsetMs = 0;

    const clearPreviewTimers = () => {
        previewTimers.splice(0).forEach((id) => clearTimeout(id));
    };

    const later = (ms, fn) => {
        const id = setTimeout(fn, Math.max(4, ms));
        previewTimers.push(id);
        return id;
    };

    const serverNow = () => Date.now() + previewOffsetMs;

    const syncPreviewClock = async () => {
        const t0 = Date.now() * 1000;
        const res = await fetch('/api/time?t0=' + t0);
        const t3 = Date.now() * 1000;
        if (!res.ok) return;
        const json = await res.json();
        const t1 = Number(json.t1);
        const t2 = Number(json.t2);
        if (!Number.isFinite(t1) || !Number.isFinite(t2)) return;
        previewOffsetMs = ((t1 - t0) + (t2 - t3)) / 2000;
    };

    const kickVideo = (video) => {
        const go = () => {
            try {
                video.currentTime = 0;
            } catch (_error) {
                // some engines reject seek while HAVE_NOTHING
            }
            video.muted = true;
            const play = video.play();
            if (play && typeof play.catch === 'function') {
                play.catch(() => {});
            }
        };
        if (video.readyState >= 1) {
            go();
            return;
        }
        video.addEventListener('loadeddata', go, { once: true });
        video.load();
    };

    const armPreview = (video) => {
        const origin = Date.parse(video.dataset.startAt || '');
        const slot = Math.max(1, parseInt(video.dataset.durationMs || '10000', 10));
        const waitEl = video.parentElement?.querySelector('.preview-wait');
        if (!Number.isFinite(origin)) {
            kickVideo(video);
            return;
        }

        let live = false;

        const showWait = (ms) => {
            if (!waitEl) return;
            if (ms <= 0) {
                waitEl.hidden = true;
                waitEl.textContent = '';
                return;
            }
            waitEl.hidden = false;
            waitEl.textContent = 'Sync in ' + Math.max(1, Math.ceil(ms / 1000)) + 's';
        };

        const nextCutAt = () => {
            const now = serverNow();
            if (now < origin) return origin;
            const elapsed = now - origin;
            return origin + elapsed - (elapsed % slot) + slot;
        };

        const tick = () => {
            if (!video.isConnected) return;
            const now = serverNow();
            if (now < origin) {
                live = false;
                video.pause();
                showWait(origin - now);
                later(Math.min(250, origin - now), tick);
                return;
            }
            showWait(0);
            if (!live) {
                live = true;
                kickVideo(video);
            }
            const delay = nextCutAt() - now;
            if (delay > 32) {
                later(delay - 12, tick);
                return;
            }
            later(Math.max(4, delay), () => {
                kickVideo(video);
                live = true;
                later(8, tick);
            });
        };

        tick();
    };

    const bindPreviews = (root) => {
        clearPreviewTimers();
        const videos = [...root.querySelectorAll('video[data-preview]')];
        if (!videos.length) return;
        syncPreviewClock().finally(() => {
            if (!root.isConnected) return;
            videos.forEach((video) => {
                if (video.isConnected) armPreview(video);
            });
        });
    };

    const bindAdmin = (root) => {
        bindFitToggles(root);
        bindAssignForms(root);
        bindCopy(root);
        bindPreviews(root);
    };

    const refreshAdmin = async () => {
        const res = await fetch('/admin', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!res.ok) {
            throw new Error('Could not reload admin');
        }
        const html = await res.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const next = doc.querySelector('#admin-root');
        const cur = document.querySelector('#admin-root');
        const token = doc.querySelector('meta[name="csrf-token"]')?.content;
        if (token) {
            document.querySelector('meta[name="csrf-token"]').content = token;
        }
        const y = window.scrollY;
        cur.replaceWith(next);
        window.scrollTo(0, y);
        bindAdmin(next);
    };

    const errorMessage = async (res) => {
        const data = await res.json().catch(() => null);
        if (data?.errors) {
            return Object.values(data.errors).flat().join(' ');
        }
        if (data?.message) {
            return data.message;
        }
        if (res.status === 419) {
            return 'Session expired. Refresh the page.';
        }
        return 'Request failed (' + res.status + ')';
    };

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.closest('#admin-root')) {
            return;
        }
        event.preventDefault();
        if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
            return;
        }

        const buttons = [...form.querySelectorAll('button')];
        buttons.forEach((button) => { button.disabled = true; });
        flash(form.querySelector('input[type="file"]') ? 'Uploading…' : 'Saving…');

        try {
            const res = await fetch(form.action, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: new FormData(form),
            });
            if (!res.ok) {
                flash(await errorMessage(res), true);
                return;
            }
            await refreshAdmin();
            flash('Saved');
        } catch (error) {
            flash(error instanceof Error ? error.message : 'Network error', true);
        } finally {
            buttons.forEach((button) => { button.disabled = false; });
        }
    });

    bindAdmin(document.querySelector('#admin-root'));

    const paintClocks = (devices) => {
        devices.forEach((device) => {
            const clock = document.querySelector('[data-clock-for="' + device.id + '"]');
            if (clock) {
                clock.className = device.class;
                clock.textContent = device.label;
            }
            const seen = document.querySelector('[data-seen-for="' + device.id + '"]');
            if (seen) {
                seen.textContent = device.seen;
            }
            const status = document.querySelector('[data-status-for="' + device.id + '"]');
            if (status) {
                status.className = 'pill ' + device.status;
                status.textContent = device.status;
            }
        });
    };

    const pollClocks = async () => {
        try {
            const res = await fetch('/admin/device-clocks', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) return;
            const data = await res.json();
            paintClocks(data.devices || []);
        } catch (_error) {
            // keep last labels if the poll fails
        }
    };

    setInterval(pollClocks, 10000);
    pollClocks();
})();
</script>
</body>
</html>
