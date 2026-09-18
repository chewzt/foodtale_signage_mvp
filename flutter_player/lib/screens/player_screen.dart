import 'dart:async';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:path_provider/path_provider.dart';
import 'package:video_player/video_player.dart';
import '../models/player_manifest.dart';
import '../services/api_service.dart';
import '../services/sync_clock_service.dart';

/// Monday 0e9ba5a playback: shared `start_at`, countdown, swap standby at UTC
/// cuts, play from 0, do not seek mid-clip. Clock source can be SNTP.
class PlayerScreen extends StatefulWidget {
  final ApiService api;
  final SyncClockService clock;
  final String token;
  final VoidCallback onChangeServer;

  const PlayerScreen({
    super.key,
    required this.api,
    required this.clock,
    required this.token,
    required this.onChangeServer,
  });

  @override
  State<PlayerScreen> createState() => _PlayerScreenState();
}

class _PlayerScreenState extends State<PlayerScreen> {
  PlayerManifest? manifest;
  VideoPlayerController? controller;
  VideoPlayerController? _standbyVideo;
  File? imageFile;
  File? _standbyImage;
  Timer? loopTimer;
  Timer? manifestTimer;
  Timer? _cutTimer;
  int currentIndex = -1;
  int _waitingMs = 0;
  int? _originMs;
  int? _preparedItemId;
  int? _standbyItemId;
  bool loading = true;
  bool _tickBusy = false;
  bool _refreshBusy = false;
  bool _standbyBusy = false;
  bool _cutBusy = false;
  String? error;
  String? _contentKey;
  String? _manifestEtag;

  @override
  void initState() {
    super.initState();
    SystemChrome.setPreferredOrientations(const [
      DeviceOrientation.portraitUp,
      DeviceOrientation.portraitDown,
      DeviceOrientation.landscapeLeft,
      DeviceOrientation.landscapeRight,
    ]);
    SystemChrome.setEnabledSystemUIMode(SystemUiMode.immersiveSticky);
    load();
  }

  Future<void> load() async {
    try {
      await widget.clock.start();
      widget.clock.addListener(_onClock);
      await _refreshManifest(force: true);
      loopTimer = Timer.periodic(const Duration(milliseconds: 250), (_) {
        unawaited(_tick());
      });
      manifestTimer = Timer.periodic(const Duration(seconds: 5), (_) {
        unawaited(_refreshManifest());
      });
      if (mounted) setState(() => loading = false);
    } catch (e) {
      if (mounted) {
        setState(() {
          error = e.toString();
          loading = false;
        });
      }
    }
  }

  void _onClock() {
    _scheduleAbsoluteCut();
    if (mounted) {
      setState(() {});
    }
  }

  Future<void> _refreshManifest({bool force = false}) async {
    if (_refreshBusy || _tickBusy) return;
    _refreshBusy = true;
    try {
      final fetch = await widget.api.getManifest(
        widget.token,
        clockOffsetMs: widget.clock.offsetMs,
        clockRttMs: widget.clock.rttMs,
        etag: force ? null : _manifestEtag,
      );
      if (fetch.unchanged) {
        return;
      }
      final next = fetch.manifest;
      if (next == null) return;
      _manifestEtag = fetch.etag;
      final contentKey =
          '${next.playlistName}|${next.items.map((i) => '${i.id}:${i.url}:${i.durationMs}').join(',')}';
      final sameContent = contentKey == _contentKey;
      manifest = next;
      final originMs = next.startAtUtc.millisecondsSinceEpoch;
      final originChanged = originMs != _originMs;
      _originMs = originMs;
      if (!force && sameContent) {
        if (originChanged) _scheduleAbsoluteCut();
        return;
      }
      _contentKey = contentKey;
      await _prefetchAll(next);
      await _prepareCurrent();
      _scheduleAbsoluteCut();
      if (mounted) {
        setState(() {
          error = null;
          loading = false;
        });
      }
    } catch (e) {
      if (manifest == null && mounted) {
        setState(() {
          error = e.toString();
          loading = false;
        });
      }
    } finally {
      _refreshBusy = false;
    }
  }

  int _totalDuration(PlayerManifest m) =>
      m.items.fold(0, (sum, item) => sum + item.durationMs);

  ({int index, int positionMs, int waitingMs}) _timeline(PlayerManifest m) {
    if (m.items.isEmpty) {
      return (index: -1, positionMs: 0, waitingMs: 0);
    }
    final total = _totalDuration(m);
    final elapsed = widget.clock.nowUtc.difference(m.startAtUtc).inMilliseconds;
    if (elapsed < 0) {
      return (index: -1, positionMs: 0, waitingMs: -elapsed);
    }
    final mod = ((elapsed % total) + total) % total;
    int cursor = 0;
    for (var i = 0; i < m.items.length; i++) {
      final end = cursor + m.items[i].durationMs;
      if (mod < end) {
        return (index: i, positionMs: mod - cursor, waitingMs: 0);
      }
      cursor = end;
    }
    return (index: 0, positionMs: 0, waitingMs: 0);
  }

  DateTime? _nextCutAt(PlayerManifest m) {
    if (m.items.isEmpty) return null;
    final origin = m.startAtUtc.toUtc();
    final now = widget.clock.nowUtc;
    if (now.isBefore(origin)) return origin;
    final total = _totalDuration(m);
    if (total <= 0) return null;
    final elapsed = now.difference(origin).inMilliseconds;
    final cycleStart = elapsed - (elapsed % total);
    final mod = elapsed % total;
    var cursor = 0;
    for (final item in m.items) {
      cursor += item.durationMs;
      if (mod < cursor) {
        return origin.add(Duration(milliseconds: cycleStart + cursor));
      }
    }
    return origin.add(Duration(milliseconds: cycleStart + total));
  }

  void _scheduleAbsoluteCut() {
    _cutTimer?.cancel();
    final m = manifest;
    if (m == null || m.items.isEmpty) return;
    final cutAt = _nextCutAt(m);
    if (cutAt == null) return;
    final delay = cutAt.difference(widget.clock.nowUtc);
    if (delay <= Duration.zero) {
      _cutTimer = Timer(Duration.zero, () => unawaited(_onAbsoluteCut()));
      return;
    }
    if (delay > const Duration(milliseconds: 32)) {
      _cutTimer = Timer(
        delay - const Duration(milliseconds: 12),
        _scheduleAbsoluteCut,
      );
      return;
    }
    _cutTimer = Timer(const Duration(milliseconds: 4), () {
      if (!widget.clock.nowUtc.isBefore(cutAt)) {
        unawaited(_onAbsoluteCut());
      } else {
        _scheduleAbsoluteCut();
      }
    });
  }

  Future<void> _onAbsoluteCut() async {
    if (_cutBusy) return;
    _cutBusy = true;
    try {
      final m = manifest;
      if (m == null || m.items.isEmpty) return;

      final live = _timeline(m);
      if (live.waitingMs > 0) {
        _waitingMs = live.waitingMs;
        currentIndex = -1;
        if (mounted) setState(() {});
        return;
      }

      _waitingMs = 0;
      // Same-index wrap (1-clip playlist) still has to restart from 0,
      // or a non-looping file freezes on the last frame.
      final wrapped = live.index == currentIndex && live.positionMs < 80;
      if (live.index != currentIndex || wrapped) {
        currentIndex = live.index;
        _revealItem(m.items[live.index]);
      }
    } finally {
      _cutBusy = false;
      _scheduleAbsoluteCut();
    }
  }

  MediaItem? _itemAt(PlayerManifest m, int index) {
    if (index < 0 || index >= m.items.length) return null;
    return m.items[index];
  }

  Future<File> _cachedFile(MediaItem item) async {
    final dir = await getApplicationDocumentsDirectory();
    final name = Uri.parse(item.url).pathSegments.last;
    final safe = name.replaceAll(RegExp(r'[^A-Za-z0-9._-]'), '_');
    final file = File('${dir.path}/media_${item.id}_$safe');
    if (await file.exists() && await file.length() > 0) {
      return file;
    }
    final tmp = File('${file.path}.part');
    final client = HttpClient();
    try {
      final req = await client.getUrl(Uri.parse(item.url));
      final res = await req.close();
      if (res.statusCode >= 300) {
        throw Exception('Download failed ${res.statusCode}');
      }
      await res.pipe(tmp.openWrite());
      await tmp.rename(file.path);
    } finally {
      client.close(force: true);
    }
    return file;
  }

  Future<void> _prefetchAll(PlayerManifest m) async {
    for (final item in m.items) {
      await _cachedFile(item);
    }
  }

  Future<void> _disposeStandby() async {
    await _standbyVideo?.dispose();
    _standbyVideo = null;
    _standbyImage = null;
    _standbyItemId = null;
  }

  Future<VideoPlayerController> _openVideo(MediaItem item) async {
    final file = await _cachedFile(item);
    final c = VideoPlayerController.file(file);
    await c.initialize();
    final durationMs = c.value.duration.inMilliseconds;
    await c.setLooping(item.shouldLoop(durationMs));
    return c;
  }

  Future<void> _prepareStandby(MediaItem item) async {
    if (_standbyBusy || _standbyItemId == item.id) return;
    _standbyBusy = true;
    try {
      await _disposeStandby();
      _standbyItemId = item.id;
      if (item.type == 'video') {
        final c = await _openVideo(item);
        await c.pause();
        await c.seekTo(Duration.zero);
        if (_standbyItemId != item.id) {
          await c.dispose();
          return;
        }
        _standbyVideo = c;
      } else {
        _standbyImage = await _cachedFile(item);
      }
    } finally {
      _standbyBusy = false;
    }
  }

  void _primeNext() {
    final m = manifest;
    if (m == null || m.items.isEmpty || currentIndex < 0) return;
    final next = _itemAt(m, (currentIndex + 1) % m.items.length);
    if (next != null) unawaited(_prepareStandby(next));
  }

  void _revealItem(MediaItem item) {
    if (_standbyItemId == item.id &&
        (item.type != 'video' || _standbyVideo != null)) {
      final old = controller;
      controller = _standbyVideo;
      imageFile = item.type == 'image' ? _standbyImage : null;
      _standbyVideo = null;
      _standbyImage = null;
      _standbyItemId = null;
      _preparedItemId = item.id;
      if (mounted) setState(() {});
      unawaited(controller?.play());
      unawaited(old?.dispose());
      _primeNext();
      return;
    }
    unawaited(_showItemSlow(item));
  }

  Future<void> _showItemSlow(MediaItem item) async {
    await controller?.dispose();
    controller = null;
    imageFile = null;
    _preparedItemId = item.id;

    if (item.type == 'video') {
      final c = await _openVideo(item);
      controller = c;
      await c.play();
    } else {
      imageFile = await _cachedFile(item);
    }
    if (mounted) setState(() {});
    _primeNext();
  }

  Future<void> _prepareCurrent() async {
    final m = manifest;
    if (m == null || m.items.isEmpty) {
      currentIndex = -1;
      _waitingMs = 0;
      _preparedItemId = null;
      imageFile = null;
      await controller?.dispose();
      controller = null;
      await _disposeStandby();
      return;
    }

    final live = _timeline(m);
    _waitingMs = live.waitingMs;
    if (live.waitingMs > 0) {
      currentIndex = -1;
      await _prepareStandby(m.items.first);
      if (mounted) setState(() {});
      return;
    }

    currentIndex = live.index;
    final item = m.items[currentIndex];
    if (_preparedItemId == item.id) {
      _primeNext();
      return;
    }
    await _showItemSlow(item);
  }

  Future<void> _tick() async {
    if (_tickBusy || _refreshBusy || _cutBusy) return;
    _tickBusy = true;
    try {
      final m = manifest;
      if (m == null || m.items.isEmpty) return;

      final live = _timeline(m);
      if (live.waitingMs > 0) {
        final changed = _waitingMs == 0 ||
            (live.waitingMs / 1000).ceil() != (_waitingMs / 1000).ceil();
        _waitingMs = live.waitingMs;
        currentIndex = -1;
        unawaited(_prepareStandby(m.items.first));
        if (changed && mounted) setState(() {});
        return;
      }

      if (_waitingMs > 0) return;

      if (currentIndex >= 0) _primeNext();
    } finally {
      _tickBusy = false;
    }
  }

  @override
  void dispose() {
    loopTimer?.cancel();
    manifestTimer?.cancel();
    _cutTimer?.cancel();
    widget.clock.removeListener(_onClock);
    controller?.dispose();
    _standbyVideo?.dispose();
    widget.clock.dispose();
    SystemChrome.setEnabledSystemUIMode(SystemUiMode.edgeToEdge);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (loading) {
      return const Scaffold(
        backgroundColor: Colors.black,
        body: Center(child: CircularProgressIndicator()),
      );
    }
    if (error != null) {
      return Scaffold(
        backgroundColor: Colors.black,
        body: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 32),
                child: Text(error!,
                    textAlign: TextAlign.center,
                    style: const TextStyle(color: Colors.white)),
              ),
              const SizedBox(height: 20),
              FilledButton(
                onPressed: () => unawaited(_refreshManifest(force: true)),
                child: const Text('Retry'),
              ),
              _changeServerButton(),
            ],
          ),
        ),
      );
    }

    final m = manifest!;
    if (_waitingMs > 0) {
      return Scaffold(
        backgroundColor: Colors.black,
        body: Center(
          child: Text(
            'Sync starts in ${(_waitingMs / 1000).ceil()}s',
            style: const TextStyle(color: Colors.white, fontSize: 32),
          ),
        ),
      );
    }

    if (m.items.isEmpty || currentIndex < 0) {
      final unassigned =
          m.playlistName.isEmpty || m.playlistName == 'Unassigned';
      return Scaffold(
        backgroundColor: Colors.black,
        body: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                unassigned
                    ? 'Waiting for playlist…'
                    : 'Playlist "${m.playlistName}" has no media',
                textAlign: TextAlign.center,
                style: const TextStyle(color: Colors.white, fontSize: 28),
              ),
              const SizedBox(height: 20),
              FilledButton(
                onPressed: () => unawaited(_refreshManifest(force: true)),
                child: const Text('Refresh now'),
              ),
              _changeServerButton(),
            ],
          ),
        ),
      );
    }

    final item = m.items[currentIndex];

    return Scaffold(
      backgroundColor: Colors.black,
      body: Stack(
        children: [
          Positioned.fill(
            child: item.type == 'video' ? _videoWidget() : _imageWidget(),
          ),
          Positioned(
            left: 12,
            bottom: 8,
            child: Opacity(
              opacity: 0.4,
              child: Text(
                [
                  widget.clock.source,
                  'offset ${widget.clock.offsetMs}ms',
                  'rtt ${widget.clock.rttMs}ms',
                ].join(' · '),
                style: const TextStyle(color: Colors.white, fontSize: 12),
              ),
            ),
          ),
          Positioned(
            right: 12,
            bottom: 8,
            child: Opacity(
              opacity: 0.35,
              child: _changeServerButton(),
            ),
          ),
        ],
      ),
    );
  }

  Widget _changeServerButton() {
    return TextButton(
      onPressed: widget.onChangeServer,
      child: const Text('Change server'),
    );
  }

  Widget _imageWidget() {
    final file = imageFile;
    if (file == null) {
      return const Center(child: CircularProgressIndicator());
    }
    return Image.file(file, fit: BoxFit.cover);
  }

  Widget _videoWidget() {
    final c = controller;
    if (c == null || !c.value.isInitialized) {
      return const Center(child: CircularProgressIndicator());
    }
    return FittedBox(
      fit: BoxFit.cover,
      child: SizedBox(
        width: c.value.size.width,
        height: c.value.size.height,
        child: VideoPlayer(c),
      ),
    );
  }
}
