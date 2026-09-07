enum PlaybackFit {
  once,
  loop,
  cut,
}

class PlayerManifest {
  final String playlistName;
  final int playlistId;
  final int deviceId;
  final String kind;
  final int panelCount;
  final int panelIndex;
  final int peerCount;
  final DateTime startAtUtc;
  final List<MediaItem> items;

  PlayerManifest({
    required this.playlistName,
    required this.playlistId,
    required this.deviceId,
    required this.kind,
    required this.panelCount,
    required this.panelIndex,
    required this.peerCount,
    required this.startAtUtc,
    required this.items,
  });

  bool get isCarousel => kind == 'carousel' && panelCount > 1;

  String get groupKey => 'p$playlistId|${startAtUtc.millisecondsSinceEpoch}';

  factory PlayerManifest.fromJson(Map<String, dynamic> json) {
    final panelCount = (json['panel_count'] as num?)?.toInt() ?? 1;
    final rawIndex = (json['panel_index'] as num?)?.toInt() ?? 0;
    final panels = panelCount < 1 ? 1 : panelCount;
    final index = rawIndex.clamp(0, panels - 1);

    return PlayerManifest(
      playlistName: json['playlist_name'] ?? 'Playlist',
      playlistId: (json['playlist_id'] as num?)?.toInt() ?? 0,
      deviceId: (json['device_id'] as num?)?.toInt() ?? 0,
      kind: json['kind'] as String? ?? 'playlist',
      panelCount: panels,
      panelIndex: index,
      peerCount: (json['peer_count'] as num?)?.toInt() ?? 1,
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
