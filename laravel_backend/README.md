# Laravel backend integration

This folder contains the MVP application files to copy into a fresh Laravel project.

## Setup

```bash
composer create-project laravel/laravel foodtale-signage
cd foodtale-signage
```

Copy this folder's `app/`, `database/`, `routes/`, and `resources/` into the Laravel project.

Configure `.env` with your database, then:

```bash
php artisan migrate
php artisan storage:link
php artisan serve --host=0.0.0.0
```

Open:

```text
http://YOUR_SERVER_IP:8000/admin
```

MVP flow:

1. Create a playlist.
2. Upload images or MP4 videos.
3. Create four pairing codes.
4. Install the Flutter player on four Android TV boxes.
5. Enter each code into one TV box.
6. Assign the same playlist to all four devices.
7. All devices use the playlist `start_at` timestamp and server-clock offset to calculate the same playback position.

For a four-screen panoramic video, create four playlists with matching durations and `start_at` values, then assign one screen-specific playlist to each box.
