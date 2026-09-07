import 'dart:async';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:http/http.dart' as http;
import 'package:path_provider/path_provider.dart';
import 'package:video_player/video_player.dart';
import '../models/player_manifest.dart';
import '../services/api_service.dart';
import '../services/peer_lan_service.dart';
import '../services/sync_clock_service.dart';

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
  late final PeerLanService _peers;
  bool _wasReleased = false;
  DateTime? _confirmedCutAt;
  String? _confirmedId;
  bool _confirmBusy = false;

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
    _peers = PeerLanService(clock: widget.clock, onUpdate: _onPeers);
    load();
  }

  Future<void> load() async {
    try {
      await widget.clock.start();
      widget.clock.addListener(_onClock);
      await _peers.start();
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
    if (_peers.released || (manifest?.peerCount ?? 1) <= 1) {
      _scheduleAbsoluteCut();
    }
    if (mounted) {
      setState(() {});
    }
  }

  void _onPeers() {
    if (mounted) setState(() {});
    if (!_peers.released) return;
    if (!_wasReleased) {
      _wasReleased = true;
      unawaited(_prepareCurrent());
    }
    _scheduleAbsoluteCut();
  }

  void _bindPeers(PlayerManifest next) {
    final groupChanged = next.groupKey != _peers.group;
    _peers.bind(
      deviceId: next.deviceId,
      group: next.groupKey,
      peerCount: next.peerCount,
      serverStartAt: next.startAtUtc,
    );
    if (groupChanged) {
      _wasReleased = false;
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
      if (fetch.unchanged || fetch.manifest == null) {
        if (fetch.etag != null) _manifestEtag = fetch.etag;
        return;
      }
      final next = fetch.manifest!;
      if (fetch.etag != null) _manifestEtag = fetch.etag;
      final contentKey =
          '${next.playlistName}|${next.items.map((i) => '${i.id}:${i.url}:${i.durationMs}').join(',')}';
      final sameContent = contentKey == _contentKey;
      manifest = next;
      _bindPeers(next);
      final originMs = _axisOrigin(next).millisecondsSinceEpoch;
      final originChanged = originMs != _originMs;
      _originMs = originMs;
      if (!force && sameContent) {
        if (originChanged) _scheduleAbsoluteCut();
        if (mounted) setState(() {});
        return;
      }
      _contentKey = contentKey;
      await _prefetchAll(next);
      _peers.markReady();
      if (_peers.released || next.peerCount <= 1) {
        await _prepareCurrent();
        _scheduleAbsoluteCut();
      }
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

  DateTime _axisOrigin(PlayerManifest m) => m.startAtUtc;

  int _totalDuration(PlayerManifest m) =>
      m.items.fold(0, (sum, item) => sum + item.durationMs);

  ({int index, int positionMs, int waitingMs}) _timeline(PlayerManifest m) {
    if (m.items.isEmpty) {
      return (index: -1, positionMs: 0, waitingMs: 0);
    }
    final total = _totalDuration(m);
    final elapsed = widget.clock.nowUtc.difference(_axisOrigin(m)).inMilliseconds;
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
    final origin = _axisOrigin(m);
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
    if (!_peers.released && m.peerCount > 1) return;
    var cutAt = _nextCutAt(m);
    final confirmed = _confirmedCutAt;
    if (confirmed != null) {
      final now = widget.clock.nowUtc;
      if (!confirmed.isBefore(now) &&
          (cutAt == null ||
              confirmed.difference(cutAt).inMilliseconds.abs() < 8000)) {
        cutAt = confirmed;
      }
    }
    if (cutAt == null) return;
    final armed = cutAt;
    final delay = armed.difference(widget.clock.nowUtc);
    if (delay <= Duration.zero) {
      _cutTimer = Timer(Duration.zero, () => unawaited(_onAbsoluteCut()));
      return;
    }
    if (delay > const Duration(milliseconds: 32)) {
      _cutTimer = Timer(delay - const Duration(milliseconds: 12), _scheduleAbsoluteCut);
      return;
    }
    _cutTimer = Timer(const Duration(milliseconds: 4), () {
      if (!widget.clock.nowUtc.isBefore(armed)) {
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
      if (live.index != currentIndex) {
        currentIndex = live.index;
        _revealItem(m.items[live.index]);
      }
      final confirmed = _confirmedCutAt;
      if (confirmed != null && !widget.clock.nowUtc.isBefore(confirmed)) {
        _confirmedCutAt = null;
        _confirmedId = null;
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
    final ext = Uri.parse(item.url).pathSegments.last.split('.').last;
    final file = File('${dir.path}/media_${item.id}.$ext');
    if (!await file.exists()) {
      final response = await http.get(Uri.parse(item.url));
      if (response.statusCode >= 300) throw Exception('Download failed');
      await file.writeAsBytes(response.bodyBytes);
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

    if (!_peers.released && m.peerCount > 1) {
      currentIndex = -1;
      _waitingMs = 0;
      await _prepareStandby(m.items.first);
      if (mounted) setState(() {});
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
      if (!_peers.released && m.peerCount > 1) return;

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
      unawaited(_confirmUpcomingCut(m, live));
    } finally {
      _tickBusy = false;
    }
  }

  Future<void> _confirmUpcomingCut(
    PlayerManifest m,
    ({int index, int positionMs, int waitingMs}) live,
  ) async {
    if (_confirmBusy || live.waitingMs > 0 || live.index < 0) return;
    final remaining = m.items[live.index].durationMs - live.positionMs;
    if (remaining > 5000 || remaining < 200) return;
    if (_confirmedCutAt != null &&
        _confirmedCutAt!.isAfter(widget.clock.nowUtc)) {
      return;
    }

    _confirmBusy = true;
    try {
      final conf = await widget.api.confirmSync(
        widget.token,
        clockOffsetMs: widget.clock.offsetMs,
        clockRttMs: widget.clock.rttMs,
      );
      if (_confirmedId == conf.confirmId) return;
      _confirmedId = conf.confirmId;
      _confirmedCutAt = conf.nextCutAt;
      widget.clock.applyNtpSample(conf.sample);
      _scheduleAbsoluteCut();
    } catch (_) {
      // keep the local cut timer; try again next 5s window
    } finally {
      _confirmBusy = false;
    }
  }

  @override
  void dispose() {
    loopTimer?.cancel();
    manifestTimer?.cancel();
    _cutTimer?.cancel();
    widget.clock.removeListener(_onClock);
    _peers.dispose();
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
        body: SafeArea(child: Center(child: CircularProgressIndicator())),
      );
    }
    if (error != null) {
      return Scaffold(
        backgroundColor: Colors.black,
        body: SafeArea(
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 520),
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(error!,
                        textAlign: TextAlign.center,
                        style: const TextStyle(color: Colors.white)),
                    const SizedBox(height: 20),
                    FilledButton(
                      onPressed: () => unawaited(_refreshManifest(force: true)),
                      child: const Text('Retry'),
                    ),
                    _changeServerButton(),
                  ],
                ),
              ),
            ),
          ),
        ),
      );
    }

    final m = manifest!;
    if (!_peers.released && m.peerCount > 1) {
      return Scaffold(
        backgroundColor: Colors.black,
        body: SafeArea(
          child: Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    _peers.statusLine,
                    textAlign: TextAlign.center,
                    style: const TextStyle(color: Colors.white, fontSize: 22),
                  ),
                  const SizedBox(height: 12),
                  const Text(
                    'Cold start: waiting for this phone to finish prefetch',
                    textAlign: TextAlign.center,
                    style: TextStyle(color: Colors.white54, fontSize: 14),
                  ),
                  _changeServerButton(),
                ],
              ),
            ),
          ),
        ),
      );
    }
    if (_waitingMs > 0) {
      return Scaffold(
        backgroundColor: Colors.black,
        body: SafeArea(
          child: Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Text(
                'Sync starts in ${(_waitingMs / 1000).ceil()}s',
                textAlign: TextAlign.center,
                style: const TextStyle(color: Colors.white, fontSize: 28),
              ),
            ),
          ),
        ),
      );
    }

    if (m.items.isEmpty || currentIndex < 0) {
      final unassigned =
          m.playlistName.isEmpty || m.playlistName == 'Unassigned';
      return Scaffold(
        backgroundColor: Colors.black,
        body: SafeArea(
          child: Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    unassigned
                        ? 'Waiting for playlist…'
                        : 'Playlist "${m.playlistName}" has no media',
                    textAlign: TextAlign.center,
                    style: const TextStyle(color: Colors.white, fontSize: 22),
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
          ),
        ),
      );
    }

    final item = m.items[currentIndex];
    final orientation = MediaQuery.orientationOf(context);

    return Scaffold(
      backgroundColor: Colors.black,
      resizeToAvoidBottomInset: false,
      body: Stack(
        fit: StackFit.expand,
        children: [
          Positioned.fill(
            child: KeyedSubtree(
              key: ValueKey(
                '${item.id}-${item.type}-$orientation-'
                '${MediaQuery.sizeOf(context).width.round()}x'
                '${MediaQuery.sizeOf(context).height.round()}',
              ),
              child: item.type == 'video' ? _videoWidget() : _imageWidget(),
            ),
          ),
          Positioned(
            left: 12,
            bottom: 8,
            right: 88,
            child: SafeArea(
              child: Opacity(
                opacity: 0.4,
                child: Text(
                  [
                    'offset ${widget.clock.offsetMs}ms',
                    'rtt ${widget.clock.rttMs}ms',
                    _peers.statusLine,
                    if (m.isCarousel) 'part ${m.panelIndex + 1}/${m.panelCount}',
                  ].join(' · '),
                  style: const TextStyle(color: Colors.white, fontSize: 12),
                ),
              ),
            ),
          ),
          Positioned(
            right: 12,
            bottom: 8,
            child: SafeArea(
              child: Opacity(
                opacity: 0.35,
                child: _changeServerButton(),
              ),
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
    return _coverFrame(
      Image.file(file, fit: BoxFit.fill, filterQuality: FilterQuality.medium),
      null,
    );
  }

  Widget _videoWidget() {
    final c = controller;
    if (c == null || !c.value.isInitialized) {
      return const Center(child: CircularProgressIndicator());
    }
    return _coverFrame(VideoPlayer(c), c.value.size);
  }

  Widget _coverFrame(Widget child, Size? source) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final screen = Size(constraints.maxWidth, constraints.maxHeight);
        if (screen.width <= 0 || screen.height <= 0) {
          return const SizedBox.expand();
        }
        final src = source ?? screen;
        if (src.width <= 0 || src.height <= 0) {
          return const Center(child: CircularProgressIndicator());
        }
        return SizedBox.expand(
          child: FittedBox(
            fit: BoxFit.cover,
            clipBehavior: Clip.hardEdge,
            child: SizedBox(
              width: src.width,
              height: src.height,
              child: child,
            ),
          ),
        );
      },
    );
  }
}
