<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Foodtale Signage Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        body { font-family: Arial, sans-serif; background:#f6f6f6; margin:0; padding:28px; }
        .wrap { max-width:1100px; margin:auto; }
        .card { background:white; padding:20px; border-radius:14px; margin-bottom:18px; }
        input, select, button { padding:10px; margin:4px; }
        table { width:100%; border-collapse:collapse; }
        th,td { padding:10px; border-bottom:1px solid #ddd; text-align:left; }
        .online { color:green; font-weight:bold; }
        .waiting { color:#b07000; font-weight:bold; }
        .clock-ok { color:#1b7a3d; font-weight:bold; }
        .clock-weak { color:#b07000; font-weight:bold; }
        .clock-bad, .clock-stale { color:#c0392b; font-weight:bold; }
        .clock-unknown { color:#777; }
        .hint { color:#666; font-size:13px; }
        .danger { background:#c0392b; color:white; border:0; cursor:pointer; }
        button:disabled { opacity:0.55; cursor:wait; }
        .flash { max-width:1100px; margin:0 auto 14px; padding:12px 16px; border-radius:10px; background:#e8f5e9; color:#1b5e20; }
        .flash.error { background:#fdecea; color:#c0392b; }
    </style>
</head>
<body>
<div id="admin-flash" class="flash" hidden></div>
<div class="wrap" id="admin-root">
    <h1>Foodtale Signage MVP</h1>
    <p>
        Server URL for the APK:
        <strong>{{ $serverUrl }}</strong>
        · browser player: <a href="{{ $serverUrl }}/play">{{ $serverUrl }}/play</a>
    </p>
    <p>
        Install APK (same URL every build):
        <a href="{{ $apkUrl }}">{{ $apkUrl }}</a>
        · currently <strong>{{ $apkVersion }}</strong>
        · phones already on 0.1.19+ get an Install banner instead of this link
    </p>
    <p>
        <a href="/demo">Open 4-TV demo wall</a>
        · <a href="/play">TV browser player</a>
        · type the server URL into the Android app, then a pairing code
        · max upload <strong>{{ $uploadMaxMb }} MB</strong> per file
    </p>
    @if ($errors->any())
        <div class="card" style="border:1px solid #c0392b;color:#c0392b">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="card">
        <h2>Create playlist</h2>
        <form method="post" action="/admin/playlists">
            @csrf
            <input name="name" placeholder="Breakfast Menu" required>
            <button>Create</button>
        </form>
    </div>

    @foreach($playlists as $playlist)
        <div class="card">
            <h2>{{ $playlist->name }}</h2>
            <p>Items: {{ $playlist->items->count() }} · Sync start: {{ $playlist->start_at }}</p>
            <form method="post" action="/admin/playlists/{{ $playlist->id }}/restart" style="display:inline">
                @csrf
                <button>Restart sync (align to next 5s UTC)</button>
            </form>
            <form method="post"
                  action="/admin/playlists/{{ $playlist->id }}/delete"
                  style="display:inline"
                  data-confirm="Delete playlist “{{ $playlist->name }}”? TVs assigned to it will be unassigned.">
                @csrf
                <button class="danger">Delete playlist</button>
            </form>
            <form method="post"
                  enctype="multipart/form-data"
                  action="/admin/playlists/{{ $playlist->id }}/items"
                  class="upload">
                @csrf
                <input type="file" name="media" accept=".mp4,.jpg,.jpeg,.png,.webp,video/mp4,video/quicktime,image/jpeg,image/png,image/webp" required>
                <select name="fit" class="fit">
                    <option value="once">Play once (slot = video length)</option>
                    <option value="loop" selected>Loop until N seconds</option>
                    <option value="cut">Cut after N seconds</option>
                </select>
                <label class="slot">
                    N seconds
                    <input type="number" name="slot_seconds" min="1" max="600" value="10">
                </label>
                <button>Upload media</button>
                <span>max {{ $uploadMaxMb }} MB · images always use N seconds</span>
            </form>
            <ol>
                @foreach($playlist->items as $item)
                    <li>
                        <form method="post"
                              action="/admin/playlists/{{ $playlist->id }}/items/{{ $item->id }}"
                              class="item-edit"
                              style="display:inline">
                            @csrf
                            {{ $item->type }}
                            @if($item->file_duration_ms)
                                · file {{ number_format($item->file_duration_ms / 1000, 1) }}s
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
                            <button>Save</button>
                        </form>
                        <form method="post"
                              action="/admin/playlists/{{ $playlist->id }}/items/{{ $item->id }}/delete"
                              style="display:inline"
                              data-confirm="Remove this media?">
                            @csrf
                            <button class="danger">Remove</button>
                        </form>
                    </li>
                @endforeach
            </ol>
        </div>
    @endforeach

    <div class="card">
        <h2>Create carousel (video wall)</h2>
        <p class="hint">
            Pick how many TVs. Each panel gets its own MP4. Same sync clock —
            assign each phone to a part. Panel count is locked at create.
        </p>
        <form method="post" action="/admin/carousels">
            @csrf
            <input name="name" placeholder="Lobby wall" required>
            <label>
                TVs in a row
                <input type="number" name="panel_count" min="2" max="8" value="3" required>
            </label>
            <button>Create carousel</button>
        </form>
    </div>

    @foreach($carousels as $carousel)
        <div class="card">
            <h2>{{ $carousel->name }} <span class="hint">· carousel · {{ $carousel->panel_count }} panels</span></h2>
            <p>
                Videos: {{ $carousel->items->count() }}/{{ $carousel->panel_count }}
                · Sync start: {{ $carousel->start_at }}
            </p>
            <form method="post" action="/admin/playlists/{{ $carousel->id }}/restart" style="display:inline">
                @csrf
                <button>Restart sync (align to next 5s UTC)</button>
            </form>
            <form method="post"
                  action="/admin/playlists/{{ $carousel->id }}/delete"
                  style="display:inline"
                  data-confirm="Delete carousel “{{ $carousel->name }}”? TVs assigned to it will be unassigned.">
                @csrf
                <button class="danger">Delete carousel</button>
            </form>
            @php($byPanel = $carousel->items->keyBy('panel_index'))
            @for($i = 0; $i < $carousel->panel_count; $i++)
                @php($item = $byPanel->get($i))
                <div style="border-top:1px solid #eee;margin-top:12px;padding-top:12px">
                    <strong>Part {{ $i + 1 }} of {{ $carousel->panel_count }}{{ $i === 0 ? ' (left)' : ($i === $carousel->panel_count - 1 ? ' (right)' : '') }}</strong>
                    @if($item)
                        <p class="hint" style="margin:6px 0">
                            Uploaded
                            @if($item->file_duration_ms)
                                · file {{ number_format($item->file_duration_ms / 1000, 1) }}s
                            @endif
                            · slot {{ number_format($item->duration_ms / 1000, 1) }}s
                            · fit {{ $item->fit }}
                        </p>
                        <form method="post"
                              action="/admin/playlists/{{ $carousel->id }}/items/{{ $item->id }}"
                              class="item-edit"
                              style="display:inline">
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
                            <button>Save</button>
                        </form>
                        <form method="post"
                              action="/admin/playlists/{{ $carousel->id }}/items/{{ $item->id }}/delete"
                              style="display:inline"
                              data-confirm="Remove this panel video?">
                            @csrf
                            <button class="danger">Remove</button>
                        </form>
                    @else
                        <p class="hint">No video on this panel yet.</p>
                    @endif
                    <form method="post"
                          enctype="multipart/form-data"
                          action="/admin/playlists/{{ $carousel->id }}/items"
                          class="upload">
                        @csrf
                        <input type="hidden" name="panel_index" value="{{ $i }}">
                        <input type="file" name="media" accept=".mp4,video/mp4,video/quicktime" required>
                        <select name="fit" class="fit">
                            <option value="once">Play once (slot = video length)</option>
                            <option value="loop" selected>Loop until N seconds</option>
                            <option value="cut">Cut after N seconds</option>
                        </select>
                        <label class="slot">
                            N seconds
                            <input type="number" name="slot_seconds" min="1" max="600" value="10">
                        </label>
                        <button>{{ $item ? 'Replace video' : 'Upload video' }}</button>
                        <span>max {{ $uploadMaxMb }} MB · MP4</span>
                    </form>
                </div>
            @endfor
        </div>
    @endforeach

    <div class="card">
        <h2>TV devices</h2>
        <p class="hint">
            Assign a normal playlist for fullscreen, or a carousel plus which part (1 = left).
            Each carousel part is a different video. Same start_at on all phones.
        </p>
        <form method="post" action="/admin/devices">
            @csrf
            <button>Create pairing code</button>
        </form>
        <table>
            <tr>
                <th>Name</th><th>Pairing Code</th><th>Status</th><th>Seen</th><th>Clock</th><th>Assigned</th><th>Assign</th>
            </tr>
            @foreach($devices as $device)
            @php($clock = \App\Support\SignageDeviceClock::summary($device))
            <tr>
                <td>{{ $device->name }}</td>
                <td><strong>{{ $device->pairing_code }}</strong></td>
                <td class="{{ $clock['status'] }}" data-status-for="{{ $device->id }}">{{ $clock['status'] }}</td>
                <td data-seen-for="{{ $device->id }}">{{ $clock['seen'] }}</td>
                <td class="{{ $clock['class'] }}" data-clock-for="{{ $device->id }}">{{ $clock['label'] }}</td>
                <td>
                    @if($device->playlist)
                        {{ $device->playlist->name }}
                        @if($device->playlist->isCarousel())
                            · part {{ $device->panel_index + 1 }}/{{ $device->playlist->panel_count }}
                        @endif
                    @else
                        -
                    @endif
                </td>
                <td>
                    <form method="post" action="/admin/devices/{{ $device->id }}/assign" class="assign" style="display:inline">
                        @csrf
                        <select name="playlist_id" class="assign-target"
                                data-selected="{{ $device->playlist_id }}">
                            @foreach($assignTargets as $target)
                                <option value="{{ $target->id }}"
                                        data-kind="{{ $target->kind }}"
                                        data-panels="{{ $target->panel_count }}"
                                        @selected($device->playlist_id === $target->id)>
                                    @if($target->isCarousel())
                                        [carousel {{ $target->panel_count }}] {{ $target->name }}
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
                    @if($device->playlist_id)
                        <form method="post" action="/admin/devices/{{ $device->id }}/unassign" style="display:inline">
                            @csrf
                            <button>Unassign</button>
                        </form>
                    @endif
                    @if($device->device_token)
                        <form method="post" action="/admin/devices/{{ $device->id }}/reset" style="display:inline" data-confirm="Clear pairing so this code can be used again?">
                            @csrf
                            <button class="danger">Reset pairing</button>
                        </form>
                    @endif
                </td>
            </tr>
            @endforeach
        </table>
    </div>
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

    const bindAdmin = (root) => {
        bindFitToggles(root);
        bindAssignForms(root);
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
                status.className = device.status;
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
