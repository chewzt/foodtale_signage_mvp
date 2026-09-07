<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Foodtale Signage Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; background:#f6f6f6; margin:0; padding:28px; }
        .wrap { max-width:1100px; margin:auto; }
        .card { background:white; padding:20px; border-radius:14px; margin-bottom:18px; }
        input, select, button { padding:10px; margin:4px; }
        table { width:100%; border-collapse:collapse; }
        th,td { padding:10px; border-bottom:1px solid #ddd; text-align:left; }
        .online { color:green; font-weight:bold; }
        .waiting { color:#b07000; font-weight:bold; }
        .danger { background:#c0392b; color:white; border:0; cursor:pointer; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Foodtale Signage MVP</h1>

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
            <form method="post"
                  enctype="multipart/form-data"
                  action="/admin/playlists/{{ $playlist->id }}/items">
                @csrf
                <input type="file" name="media" required>
                <input type="number" name="duration_ms" value="10000">
                <button>Upload media</button>
            </form>
            <ol>
                @foreach($playlist->items as $item)
                    <li>
                        {{ $item->type }} — {{ $item->path }} — {{ $item->duration_ms }} ms
                        <form method="post"
                              action="/admin/playlists/{{ $playlist->id }}/items/{{ $item->id }}/delete"
                              style="display:inline"
                              onsubmit="return confirm('Remove this media?')">
                            @csrf
                            <button class="danger">Remove</button>
                        </form>
                    </li>
                @endforeach
            </ol>
        </div>
    @endforeach

    <div class="card">
        <h2>TV devices</h2>
        <form method="post" action="/admin/devices">
            @csrf
            <button>Create pairing code</button>
        </form>
        <table>
            <tr>
                <th>Name</th><th>Pairing Code</th><th>Status</th><th>Playlist</th><th>Assign</th>
            </tr>
            @foreach($devices as $device)
            <tr>
                <td>{{ $device->name }}</td>
                <td><strong>{{ $device->pairing_code }}</strong></td>
                <td class="{{ $device->status }}">{{ $device->status }}</td>
                <td>{{ $device->playlist?->name ?? '-' }}</td>
                <td>
                    <form method="post" action="/admin/devices/{{ $device->id }}/assign">
                        @csrf
                        <select name="playlist_id">
                            @foreach($playlists as $playlist)
                                <option value="{{ $playlist->id }}">{{ $playlist->name }}</option>
                            @endforeach
                        </select>
                        <button>Assign</button>
                    </form>
                </td>
            </tr>
            @endforeach
        </table>
    </div>
</div>
</body>
</html>
