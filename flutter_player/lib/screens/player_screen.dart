import 'dart:async';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:http/http.dart' as http;
import 'package:path_provider/path_provider.dart';
import 'package:video_player/video_player.dart';
import '../models/player_manifest.dart';
import '../services/api_service.dart';
import '../services/kick_ws_service.dart';
import '../services/manifest_cache.dart';
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

class _PlayerScreenState extends State<PlayerScreen>
    with WidgetsBindingObserver {
  PlayerManifest? manifest;
  VideoPlayerController? controller;
  VideoPlayerController? _standbyVideo;
  File? imageFile;
  File? _standbyImage;
  Timer? loopTimer;
  Timer? _cutTimer;
  int currentIndex = -1;
  int _waitingMs = 0;
  DateTime? _axisStartAt;
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
  late final KickWsService _kicks;
  bool _wasReleased = false;
  bool _backgrounded = false;
  bool _kickRefreshQueued = false;
  final Set<String> _seenKickIds = {};
  int? _prevCutLocalMs;
  int? _lastCutIntervalMs;
  DateTime? _gateUntil;
  bool _holdingCut = false;
  int? _preRolledItemId;
  int _playLeadMs = 80;

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
    _peers = PeerLanService(
      clock: widget.clock,
      onUpdate: _onPeers,
      onKick: _onKick,
    );
    _kicks = KickWsService(
      baseUrl: () => widget.api.baseUrl,
      token: () => widget.token,
      onKick: _onKick,
    );
    WidgetsBinding.instance.addObserver(this);
    load();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    switch (state) {
      case AppLifecycleState.paused:
      case AppLifecycleState.inactive:
      case AppLifecycleState.hidden:
        _backgrounded = true;
      case AppLifecycleState.resumed:
        if (!_backgrounded) return;
        _backgrounded = false;
        unawaited(_prepareCurrent());
        _scheduleAbsoluteCut();
      case AppLifecycleState.detached:
        break;
    }
  }

  Future<void> load() async {
    try {
      try {
        await widget.clock.start();
      } catch (_) {
        // Server may be down; play from cache with last NTP offset.
      }
      widget.clock.addListener(_onClock);
      await _peers.start();
      _kicks.start();
      final cached = await ManifestCache.load();
      if (cached != null) {
        await _applyManifest(cached, fromCache: true);
      }
      await _refreshManifest(force: true, takeServerOrigin: true);
      loopTimer = Timer.periodic(const Duration(milliseconds: 250), (_) {
        unawaited(_tick());
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

  void _onKick(Map<String, dynamic> json) {
    final kickId = json['kick_id'] as String?;
    if (kickId != null && kickId.isNotEmpty) {
      if (_seenKickIds.contains(kickId)) return;
      _seenKickIds.add(kickId);
      if (_seenKickIds.length > 64) {
        _seenKickIds.remove(_seenKickIds.first);
      }
    }

    final kind = json['kind'] as String?;
    final playlistId = (json['playlist_id'] as num?)?.toInt();
    final deviceId = (json['device_id'] as num?)?.toInt();
    final mine = manifest?.playlistId ?? 0;
    final forMe = deviceId != null && deviceId == (manifest?.deviceId ?? 0);

    if (kind == 'content' || kind == 'wall' || kind == 'origin') {
      if (playlistId != null && playlistId != mine && mine != 0 && !forMe) {
        return;
      }
      unawaited(_onAdminKick(json));
    }
  }

  Future<void> _onAdminKick(Map<String, dynamic> json) async {
    final kind = json['kind'] as String?;
    final raw = json['start_at'] as String?;
    final parsed = raw == null ? null : DateTime.tryParse(raw)?.toUtc();

    try {
      await widget.clock.sync(samples: 2);
    } catch (_) {}

    if (kind == 'content' || kind == 'wall') {
      await _refreshManifest(force: true, takeServerOrigin: true);
    }
    if (parsed != null) {
      _applyAxisOrigin(parsed, bumpSeq: true);
    }
    await _prepareCurrent();
    _scheduleAbsoluteCut();
    if (mounted) setState(() {});
  }

  void _applyAxisOrigin(DateTime startAt, {required bool bumpSeq}) {
    _axisStartAt = startAt.toUtc();
    _peers.setAxisStartAt(_axisStartAt!, bumpSeq: bumpSeq);
    _preRolledItemId = null;
    if (startAt.toUtc().isAfter(widget.clock.nowUtc)) {
      _gateUntil = startAt.toUtc();
    }
    _resetCutStamps();
    final m = manifest;
    if (m != null) {
      unawaited(ManifestCache.save(m.copyWith(startAtUtc: _axisStartAt)));
    }
  }

  void _resetCutStamps() {
    _prevCutLocalMs = null;
    _lastCutIntervalMs = null;
    _peers.clearCutStamp();
  }

  void _bindPeers(PlayerManifest next) {
    final groupChanged = next.groupKey != _peers.group;
    final origin = _axisStartAt ?? next.startAtUtc;
    _peers.bind(
      deviceId: next.deviceId,
      group: next.groupKey,
      peerCount: next.peerCount,
      axisStartAt: origin,
    );
    _kicks.notifyPlaylist(next.playlistId == 0 ? null : next.playlistId);
    if (groupChanged) {
      _wasReleased = false;
    }
  }

  Future<void> _refreshManifest({
    bool force = false,
    bool takeServerOrigin = false,
  }) async {
    if (_refreshBusy) {
      if (force) _kickRefreshQueued = true;
      return;
    }
    _refreshBusy = true;
    try {
      ManifestFetch? fetch;
      try {
        fetch = await widget.api.getManifest(
          widget.token,
          clockOffsetMs: widget.clock.offsetMs,
          clockRttMs: widget.clock.rttMs,
          etag: force ? null : _manifestEtag,
        );
      } catch (e) {
        if (manifest == null) {
          final cached = await ManifestCache.load();
          if (cached != null) {
            await _applyManifest(cached, fromCache: true);
            return;
          }
          rethrow;
        }
        return;
      }
      if (fetch.unchanged || fetch.manifest == null) {
        if (fetch.etag != null) _manifestEtag = fetch.etag;
        return;
      }
      if (fetch.etag != null) _manifestEtag = fetch.etag;
      await _applyManifest(
        fetch.manifest!,
        fromCache: false,
        takeServerOrigin: takeServerOrigin,
      );
    } catch (e) {
      if (manifest == null && mounted) {
        setState(() {
          error = e.toString();
          loading = false;
        });
      }
    } finally {
      _refreshBusy = false;
      if (_kickRefreshQueued) {
        _kickRefreshQueued = false;
        unawaited(_refreshManifest(force: true, takeServerOrigin: true));
      }
    }
  }

  Future<void> _applyManifest(
    PlayerManifest next, {
    required bool fromCache,
    bool takeServerOrigin = false,
  }) async {
    final contentKey =
        '${next.playlistName}|${next.items.map((i) => '${i.id}:${i.url}:${i.durationMs}').join(',')}';
    final sameContent = contentKey == _contentKey;
    manifest = next;

    if (takeServerOrigin || _axisStartAt == null) {
      _applyAxisOrigin(
        next.startAtUtc,
        bumpSeq: !fromCache && takeServerOrigin,
      );
    }

    _bindPeers(next);
    if (!fromCache) {
      unawaited(ManifestCache.save(next.copyWith(startAtUtc: _axisStartAt ?? next.startAtUtc)));
    }

    if (sameContent) {
      _scheduleAbsoluteCut();
      if (mounted) {
        setState(() {
          error = null;
          loading = false;
        });
      }
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
  }

  DateTime _axisOrigin(PlayerManifest m) =>
      (_axisStartAt ?? m.startAtUtc).toUtc();

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
    if (_holdingCut) return;
    _cutTimer?.cancel();
    final m = manifest;
    if (m == null || m.items.isEmpty) return;
    if (!_peers.released && m.peerCount > 1) return;
    final cutAt = _nextCutAt(m);
    if (cutAt == null) return;
    final armed = cutAt;
    final delay = armed.difference(widget.clock.nowUtc);
    if (delay <= Duration.zero) {
      unawaited(_onAbsoluteCut());
      return;
    }
    final lead = Duration(milliseconds: _playLeadMs);
    if (delay > lead) {
      _cutTimer = Timer(delay - lead, () {
        _beginPreRoll(armed);
        _scheduleAbsoluteCut();
      });
      return;
    }
    _beginPreRoll(armed);
    if (delay > const Duration(milliseconds: 8)) {
      _cutTimer = Timer(const Duration(milliseconds: 3), _scheduleAbsoluteCut);
      return;
    }
    unawaited(_busyWaitCut(armed));
  }

  Future<void> _busyWaitCut(DateTime armed) async {
    if (_holdingCut) return;
    _holdingCut = true;
    _cutTimer?.cancel();
    try {
      final spinCap = DateTime.now().add(const Duration(milliseconds: 12));
      while (mounted &&
          widget.clock.nowUtc.isBefore(armed) &&
          DateTime.now().isBefore(spinCap)) {}
    } finally {
      _holdingCut = false;
    }
    await _onAbsoluteCut();
  }

  void _beginPreRoll(DateTime armed) {
    final m = manifest;
    if (m == null || m.items.isEmpty) return;
    final item = _itemAtTime(m, armed);
    if (item == null) return;
    _gateUntil = armed;
    if (_preRolledItemId == item.id &&
        (item.type != 'video' || controller != null)) {
      if (mounted) setState(() {});
      return;
    }
    _preRolledItemId = item.id;
    _revealItem(item, playNow: true);
  }

  MediaItem? _itemAtTime(PlayerManifest m, DateTime when) {
    if (m.items.isEmpty) return null;
    final elapsed = when.difference(_axisOrigin(m)).inMilliseconds;
    if (elapsed <= 0) return m.items.first;
    final total = _totalDuration(m);
    if (total <= 0) return m.items.first;
    final mod = ((elapsed % total) + total) % total;
    var cursor = 0;
    for (final item in m.items) {
      final end = cursor + item.durationMs;
      if (mod < end) return item;
      cursor = end;
    }
    return m.items.first;
  }

  Future<void> _onAbsoluteCut() async {
    if (_cutBusy) return;
    _cutBusy = true;
    try {
      final m = manifest;
      if (m == null || m.items.isEmpty) return;

      final live = _timeline(m);
      if (live.waitingMs > 32) {
        _waitingMs = live.waitingMs;
        currentIndex = -1;
        if (mounted) setState(() {});
        return;
      }

      _waitingMs = 0;
      _gateUntil = null;
      final item = m.items[live.index];
      if (live.index != currentIndex) {
        currentIndex = live.index;
        _markActualCut(live.index);
        if (_preRolledItemId != item.id ||
            (item.type == 'video' && controller == null) ||
            (item.type == 'image' && imageFile == null)) {
          _revealItem(item, playNow: true);
        } else if (mounted) {
          setState(() {});
          _primeNext();
        }
      } else if (mounted) {
        setState(() {});
      }
    } finally {
      _cutBusy = false;
      _scheduleAbsoluteCut();
    }
  }

  void _markActualCut(int index) {
    final now = DateTime.now().millisecondsSinceEpoch;
    if (_prevCutLocalMs != null) {
      _lastCutIntervalMs = now - _prevCutLocalMs!;
    } else {
      _lastCutIntervalMs = null;
    }
    _prevCutLocalMs = now;
    _peers.publishCut(
      index: index,
      atMs: now,
      intervalMs: _lastCutIntervalMs,
    );
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
      final response = await http
          .get(Uri.parse(item.url))
          .timeout(const Duration(seconds: 8));
      if (response.statusCode >= 300) throw Exception('Download failed');
      await file.writeAsBytes(response.bodyBytes);
    }
    return file;
  }

  Future<void> _prefetchAll(PlayerManifest m) async {
    for (final item in m.items) {
      try {
        await _cachedFile(item);
      } catch (_) {}
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

  void _revealItem(MediaItem item, {required bool playNow}) {
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
      if (playNow && item.type == 'video') {
        unawaited(_playAndMeasure(controller));
      }
      unawaited(old?.dispose());
      _primeNext();
      return;
    }
    unawaited(_showItemSlow(item, playNow: playNow));
  }

  Future<void> _playAndMeasure(VideoPlayerController? c) async {
    if (c == null) return;
    final t0 = widget.clock.nowUtc;
    var done = false;
    void listener() {
      if (done) return;
      if (!c.value.isPlaying || c.value.position.inMilliseconds < 1) return;
      done = true;
      c.removeListener(listener);
      final ms = widget.clock.nowUtc.difference(t0).inMilliseconds;
      if (ms >= 12 && ms <= 160) {
        _playLeadMs = ms;
      }
    }

    c.addListener(listener);
    await c.play();
    Timer(const Duration(milliseconds: 400), () {
      if (!done) {
        c.removeListener(listener);
      }
    });
  }

  Future<void> _showItemSlow(MediaItem item, {required bool playNow}) async {
    await controller?.dispose();
    controller = null;
    imageFile = null;
    _preparedItemId = item.id;

    if (item.type == 'video') {
      final c = await _openVideo(item);
      controller = c;
      if (playNow) {
        await _playAndMeasure(c);
      }
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

    // Mid-slot: wait for the next boundary instead of playing this clip from 0.
    if (live.positionMs > 250) {
      currentIndex = -1;
      final next = _itemAt(m, (live.index + 1) % m.items.length);
      if (next != null) await _prepareStandby(next);
      _scheduleAbsoluteCut();
      if (mounted) setState(() {});
      return;
    }

    currentIndex = live.index;
    final item = m.items[currentIndex];
    if (_preparedItemId == item.id) {
      _primeNext();
      return;
    }
    await _showItemSlow(item, playNow: true);
  }

  Future<void> _tick() async {
    if (_tickBusy || _refreshBusy || _cutBusy) return;
    _tickBusy = true;
    try {
      final m = manifest;
      if (m == null || m.items.isEmpty) return;

      final live = _timeline(m);
      _watchdog(m, live);

      if (!_peers.released && m.peerCount > 1) return;

      if (_gateUntil != null &&
          !widget.clock.nowUtc.isBefore(_gateUntil!)) {
        _gateUntil = null;
      }

      if (live.waitingMs > 0) {
        final changed = _waitingMs == 0 ||
            (live.waitingMs / 1000).ceil() != (_waitingMs / 1000).ceil();
        _waitingMs = live.waitingMs;
        currentIndex = -1;
        unawaited(_prepareStandby(m.items.first));
        if (changed && mounted) setState(() {});
        return;
      }

      _waitingMs = 0;
      if (currentIndex < 0 && live.positionMs <= 250) {
        unawaited(_prepareCurrent());
        return;
      }

      if (currentIndex >= 0) _primeNext();
    } finally {
      _tickBusy = false;
    }
  }

  void _watchdog(
    PlayerManifest m,
    ({int index, int positionMs, int waitingMs}) live,
  ) {
    _peers.publishLive(
      index: live.index,
      positionMs: live.positionMs,
      waitingMs: live.waitingMs,
      wantsResync: false,
    );
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    loopTimer?.cancel();
    _cutTimer?.cancel();
    widget.clock.removeListener(_onClock);
    _kicks.dispose();
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
                  Text(
                    _peers.joining
                        ? 'Joining wall — copying origin from neighbors'
                        : 'Cold start: waiting for this phone to finish prefetch',
                    textAlign: TextAlign.center,
                    style: const TextStyle(color: Colors.white54, fontSize: 14),
                  ),
                  _changeServerButton(),
                ],
              ),
            ),
          ),
        ),
      );
    }
    if (_waitingMs > 0 && controller == null && imageFile == null) {
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

    final showing = controller != null || imageFile != null;
    if (m.items.isEmpty || (!showing && currentIndex < 0)) {
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

    final itemIndex = currentIndex >= 0 ? currentIndex : 0;
    final item = m.items[itemIndex];
    final orientation = MediaQuery.orientationOf(context);
    final gated = _gateUntil != null &&
        widget.clock.nowUtc.isBefore(_gateUntil!);

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
          if (gated)
            const Positioned.fill(
              child: ColoredBox(color: Colors.black),
            ),
          if (gated && _waitingMs > 800)
            Center(
              child: Text(
                'Sync starts in ${(_waitingMs / 1000).ceil()}s',
                textAlign: TextAlign.center,
                style: const TextStyle(color: Colors.white, fontSize: 28),
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
                    if (widget.clock.hasServerLock) 'server-clock',
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
