enum PlaybackFit {
  once,
  loop,
  cut,
}

class PlayerManifest {
  final String playlistName;
  final DateTime startAtUtc;
  final List<MediaItem> items;

  PlayerManifest({
    required this.playlistName,
    required this.startAtUtc,
    required this.items,
  });

  factory PlayerManifest.fromJson(Map<String, dynamic> json) {
    return PlayerManifest(
      playlistName: json['playlist_name'] ?? 'Playlist',
      startAtUtc: DateTime.parse(json['start_at']).toUtc(),
      items: (json['items'] as List<dynamic>? ?? [])
          .map((e) => MediaItem.fromJson(e as Map<String, dynamic>))
          .toList(),
    );
  }
}

class MediaItem {
  final int id;
  final String type;
  final String url;
  final int durationMs;
  final PlaybackFit fit;

  MediaItem({
    required this.id,
    required this.type,
    required this.url,
    required this.durationMs,
    required this.fit,
  });

  factory MediaItem.fromJson(Map<String, dynamic> json) {
    return MediaItem(
      id: json['id'],
      type: json['type'],
      url: json['url'],
      durationMs: json['duration_ms'] ?? 10000,
      fit: _parseFit(json['fit'] as String?),
    );
  }

  bool shouldLoop(int fileMs) {
    switch (fit) {
      case PlaybackFit.once:
      case PlaybackFit.cut:
        return false;
      case PlaybackFit.loop:
        return fileMs > 0;
    }
  }

  static PlaybackFit _parseFit(String? raw) {
    switch (raw) {
      case 'once':
        return PlaybackFit.once;
      case 'cut':
        return PlaybackFit.cut;
      case 'loop':
        return PlaybackFit.loop;
      default:
        return PlaybackFit.loop;
    }
  }
}
