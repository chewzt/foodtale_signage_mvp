# MVP Architecture

```text
Restaurant Admin
      |
      v
Laravel Web Admin
      |
      +------ MySQL
      |
      +------ Public Storage / CDN
      |
      +------ REST API
                 |
        -----------------------
        |        |       |    |
      Box 1    Box 2   Box 3 Box 4
      Flutter  Flutter Flutter Flutter
        |        |       |    |
       TV1      TV2     TV3  TV4
```

Synchronization rule:

```text
expected_position =
    (server_utc_now - playlist_start_at) % total_playlist_duration
```

Each Android box periodically checks its local media position against the expected position and corrects if drift is too large.
