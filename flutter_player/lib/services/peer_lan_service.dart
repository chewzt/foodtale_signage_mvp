import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'sync_clock_service.dart';

class PeerLanService {
  static const int port = 48721;
  static const Duration _staleAfter = Duration(seconds: 2);
  static const Duration _joinCap = Duration(seconds: 2);
  static const Duration _joinSettle = Duration(milliseconds: 600);

  final SyncClockService clock;
  final void Function() onUpdate;
  final void Function(Map<String, dynamic> kick)? onKick;
  final void Function(int id, DateTime startAt, DateTime remoteNow, int seq)? onPeerAxis;

  RawDatagramSocket? _sock;
  Timer? _announce;
  Timer? _joinCapTimer;
  Timer? _joinSettleTimer;
  StreamSubscription<RawSocketEvent>? _sub;
  final Map<int, _Peer> _peers = {};

  int deviceId = 0;
  String group = '';
  int peerCount = 1;
  DateTime axisStartAt = DateTime.fromMillisecondsSinceEpoch(0, isUtc: true);
  int _seq = 0;
  bool ready = false;
  bool _started = false;
  bool _joining = false;
  bool _joinCapFired = false;
  bool _sawLiveWall = false;

  int liveIndex = -1;
  int livePositionMs = 0;
  int liveWaitingMs = 0;
  bool wantsResync = false;
  int? cutIndex;
  int? cutAtMs;
  int? cutIntervalMs;

  PeerLanService({
    required this.clock,
    required this.onUpdate,
    this.onKick,
    this.onPeerAxis,
  });

  int get seenCount {
    _prune();
    return 1 + _peers.length;
  }

  int get readyCount {
    _prune();
    var n = ready ? 1 : 0;
    for (final peer in _peers.values) {
      if (peer.ready) n++;
    }
    return n;
  }

  int get leaderId {
    var id = deviceId;
    for (final peerId in _peers.keys) {
      if (peerId < id) id = peerId;
    }
    return id;
  }

  bool get isLeader => deviceId != 0 && leaderId == deviceId;

  /// Clock to follow: during join, the live neighbor with lowest id
  /// (the acting leader). After join, the elected leader if that is not us.
  int? get clockSourceId {
    _prune();
    if (_joining) {
      int? best;
      for (final id in _peers.keys) {
        if (best == null || id < best) best = id;
      }
      return best;
    }
    if (deviceId != 0 && leaderId != deviceId) return leaderId;
    return null;
  }

  bool get joining => _joining;

  /// A non-joining neighbor was already on the wall while we listened.
  bool get sawLiveWall => _sawLiveWall;

  /// Prefetch done, and (if a wall) the roster handshake finished.
  bool get released => (ready || peerCount <= 1) && !_joining;

  String get statusLine {
    if (_joining) {
      return 'peers $peerCount · joining $seenCount';
    }
    final role = isLeader ? 'leader' : 'peer';
    return 'peers $peerCount · lan $role $seenCount';
  }

  void bind({
    required int deviceId,
    required String group,
    required int peerCount,
    required DateTime axisStartAt,
  }) {
    final groupChanged = this.group != group;
    this.deviceId = deviceId;
    this.group = group;
    this.peerCount = peerCount < 1 ? 1 : peerCount;
    this.axisStartAt = axisStartAt.toUtc();
    if (groupChanged) {
      _peers.clear();
      clock.resetGroupSlew();
      _seq = 0;
      _beginJoin();
    }
  }

  void setAxisStartAt(DateTime startAt, {bool bumpSeq = false}) {
    axisStartAt = startAt.toUtc();
    if (bumpSeq) {
      _seq += 1;
    }
  }

  void adoptSeq(int seq) {
    if (seq > _seq) {
      _seq = seq;
    }
  }

  int get seq => _seq;

  List<PeerLive> get lives {
    _prune();
    return _peers.values
        .map(
          (peer) => PeerLive(
            id: peer.id,
            ready: peer.ready,
            index: peer.index,
            positionMs: peer.positionMs,
            waitingMs: peer.waitingMs,
            wantsResync: peer.wantsResync,
            cutIndex: peer.cutIndex,
            cutAtMs: peer.cutAtMs,
            cutIntervalMs: peer.cutIntervalMs,
            startAt: peer.startAt,
            tMs: peer.tMs,
            seq: peer.seq,
          ),
        )
        .toList();
  }

  void publishLive({
    required int index,
    required int positionMs,
    required int waitingMs,
    required bool wantsResync,
  }) {
    liveIndex = index;
    livePositionMs = positionMs;
    liveWaitingMs = waitingMs;
    this.wantsResync = wantsResync;
  }

  void publishCut({
    required int index,
    required int atMs,
    int? intervalMs,
  }) {
    cutIndex = index;
    cutAtMs = atMs;
    cutIntervalMs = intervalMs;
  }

  void clearCutStamp() {
    cutIndex = null;
    cutAtMs = null;
    cutIntervalMs = null;
  }

  void markReady() {
    if (!ready) {
      ready = true;
      if (_joinCapFired || peerCount <= 1) {
        endJoin();
      } else {
        _joinSettleTimer?.cancel();
        _joinSettleTimer = Timer(_joinSettle, endJoin);
      }
      onUpdate();
    }
  }

  void endJoin() {
    if (!_joining) return;
    _joining = false;
    _joinCapTimer?.cancel();
    _joinSettleTimer?.cancel();
    onUpdate();
  }

  void noteHeardAxis() {
    if (!_joining || !ready) return;
    _joinSettleTimer?.cancel();
    _joinSettleTimer = Timer(_joinSettle, endJoin);
  }

  void _maybeSettleJoin() {
    noteHeardAxis();
  }

  void _beginJoin() {
    _joinCapTimer?.cancel();
    _joinSettleTimer?.cancel();
    _joinCapFired = false;
    _sawLiveWall = false;
    if (peerCount <= 1) {
      _joining = false;
      return;
    }
    _joining = true;
    _joinCapTimer = Timer(_joinCap, () {
      _joinCapFired = true;
      if (ready) endJoin();
    });
  }

  Future<void> start() async {
    if (_started) return;
    _started = true;
    try {
      final sock = await RawDatagramSocket.bind(InternetAddress.anyIPv4, port);
      sock.broadcastEnabled = true;
      sock.readEventsEnabled = true;
      _sock = sock;
      _sub = sock.listen(_onEvent);
      _announce = Timer.periodic(const Duration(milliseconds: 150), (_) {
        _send();
      });
    } on SocketException {
      _started = true;
    }
  }

  void dispose() {
    _announce?.cancel();
    _joinCapTimer?.cancel();
    _joinSettleTimer?.cancel();
    _sub?.cancel();
    _sock?.close();
    _peers.clear();
  }

  void _onEvent(RawSocketEvent event) {
    final sock = _sock;
    if (sock == null || event != RawSocketEvent.read) return;
    final packet = sock.receive();
    if (packet == null) return;
    _handle(packet.data);
  }

  void _handle(List<int> data) {
    try {
      final json = jsonDecode(utf8.decode(data)) as Map<String, dynamic>;
      final v = json['v'];
      if (v == 2) {
        final kind = json['kind'] as String?;
        if (kind == 'ping' || kind == 'pong') return;
        onKick?.call(json);
        return;
      }
      if (v != 1) return;
      if (json['g'] != group || group.isEmpty) return;
      final id = (json['id'] as num?)?.toInt() ?? 0;
      if (id == 0 || id == deviceId) return;
      final readyFlag = json['r'] == 1 || json['r'] == true;
      final joiningFlag = json['j'] == 1 || json['j'] == true;
      final tMs = (json['t'] as num?)?.toInt();
      final startRaw = json['start_at'] as String?;
      final seq = (json['seq'] as num?)?.toInt() ?? 0;
      DateTime? peerStart;
      if (startRaw != null && startRaw.isNotEmpty) {
        peerStart = DateTime.tryParse(startRaw)?.toUtc();
      }
      final index = (json['i'] as num?)?.toInt() ?? -1;
      final waitingMs = (json['w'] as num?)?.toInt() ?? 0;
      final wantsResync = json['d'] == 1 || json['d'] == true;
      if (_joining && !joiningFlag && readyFlag) {
        if (index >= 0 || waitingMs > 0 || wantsResync) {
          _sawLiveWall = true;
        }
      }
      _peers[id] = _Peer(
        id: id,
        ready: readyFlag,
        tMs: tMs,
        startAt: peerStart,
        seq: seq,
        index: index,
        positionMs: (json['p'] as num?)?.toInt() ?? 0,
        waitingMs: waitingMs,
        wantsResync: wantsResync,
        cutIndex: (json['ci'] as num?)?.toInt(),
        cutAtMs: (json['c'] as num?)?.toInt(),
        cutIntervalMs: (json['iv'] as num?)?.toInt(),
        heardAt: DateTime.now(),
      );

      _maybeSettleJoin();
      onUpdate();
    } catch (_) {
      // ignore truncated UDP noise
    }
  }

  void _send() {
    final sock = _sock;
    if (sock == null || deviceId == 0 || group.isEmpty) return;
    final body = <String, dynamic>{
      'v': 1,
      'g': group,
      'id': deviceId,
      't': clock.nowUtc.millisecondsSinceEpoch,
      'r': ready ? 1 : 0,
      'j': _joining ? 1 : 0,
    };
    body['i'] = liveIndex;
    body['p'] = livePositionMs;
    body['w'] = liveWaitingMs;
    body['d'] = wantsResync ? 1 : 0;
    if (cutIndex != null) body['ci'] = cutIndex;
    if (cutAtMs != null) body['c'] = cutAtMs;
    if (cutIntervalMs != null) body['iv'] = cutIntervalMs;
    sock.send(utf8.encode(jsonEncode(body)), InternetAddress('255.255.255.255'), port);
  }

  void broadcastKick(Map<String, dynamic> payload) {
    final sock = _sock;
    if (sock == null) return;
    sock.send(utf8.encode(jsonEncode(payload)), InternetAddress('255.255.255.255'), port);
  }

  void _prune() {
    final cutoff = DateTime.now().subtract(_staleAfter);
    _peers.removeWhere((_, peer) => peer.heardAt.isBefore(cutoff));
  }
}

class _Peer {
  final int id;
  final bool ready;
  final int? tMs;
  final DateTime? startAt;
  final int seq;
  final int index;
  final int positionMs;
  final int waitingMs;
  final bool wantsResync;
  final int? cutIndex;
  final int? cutAtMs;
  final int? cutIntervalMs;
  final DateTime heardAt;

  _Peer({
    required this.id,
    required this.ready,
    required this.tMs,
    required this.startAt,
    required this.seq,
    required this.index,
    required this.positionMs,
    required this.waitingMs,
    required this.wantsResync,
    required this.cutIndex,
    required this.cutAtMs,
    required this.cutIntervalMs,
    required this.heardAt,
  });
}

class PeerLive {
  final int id;
  final bool ready;
  final int index;
  final int positionMs;
  final int waitingMs;
  final bool wantsResync;
  final int? cutIndex;
  final int? cutAtMs;
  final int? cutIntervalMs;
  final DateTime? startAt;
  final int? tMs;
  final int seq;

  PeerLive({
    required this.id,
    required this.ready,
    required this.index,
    required this.positionMs,
    required this.waitingMs,
    required this.wantsResync,
    required this.cutIndex,
    required this.cutAtMs,
    required this.cutIntervalMs,
    required this.startAt,
    required this.tMs,
    required this.seq,
  });
}
