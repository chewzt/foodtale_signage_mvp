# Foodtale Signage MVP

This MVP contains:

- `flutter_player/` — Android TV / Android box playback app.
- `laravel_backend/` — API, database models/migrations, basic admin page.

## What works in this MVP

- Restaurant/admin creates playlists.
- Upload JPG/PNG/WebP/MP4 content.
- Generate pairing codes for Android TV boxes.
- Pair each TV box once and store a device token.
- Assign a playlist to each device.
- Devices fetch the same server time and playlist start timestamp.
- Video files are cached locally on each box.
- Playback position is derived from the shared timeline.
- Player automatically seeks if drift exceeds ~180 ms.
- Images and videos can be mixed in one looping playlist.

## Recommended MVP test

Use 4 Android boxes connected to the same router.

1. Upload one 30-second MP4.
2. Pair four boxes.
3. Assign the same playlist to all four.
4. Reassign the playlist to force a new `start_at` roughly 10 seconds in the future.
5. Watch all TVs begin from the same timeline.
6. Leave it running for 10–20 minutes and observe drift correction.

## Important production upgrades

Before commercial rollout, add:
- authenticated merchant accounts and restaurant separation
- signed CDN URLs
- proper media processing / FFmpeg normalization
- WebSocket or MQTT for instant playlist refresh
- device health telemetry and screenshots
- boot auto-start / kiosk mode
- offline manifest persistence
- automatic stale-cache cleanup
- NTP-grade time sync for tighter synchronization
- native Android Media3/ExoPlayer bridge if sub-100ms sync is required
- multi-screen campaign grouping
- scheduling by daypart
- POS/menu stock integration
