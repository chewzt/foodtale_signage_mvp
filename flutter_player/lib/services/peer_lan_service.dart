import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'sync_clock_service.dart';

class PeerLanService {
  static const int port = 48721;
  static const Duration _staleAfter = Duration(seconds: 2);

  final SyncClockService clock;
  final void Function() onUpdate;

  RawDatagramSocket? _sock;
  Timer? _announce;
  StreamSubscription<RawSocketEvent>? _sub;
  final Map<int, _Peer> _peers = {};

  int deviceId = 0;
  String group = '';
  int peerCount = 1;
  DateTime serverStartAt = DateTime.fromMillisecondsSinceEpoch(0, isUtc: true);
  bool ready = false;
  bool _started = false;

  PeerLanService({required this.clock, required this.onUpdate});

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

  bool get released => ready || peerCount <= 1;

  String get statusLine {
    final role = isLeader ? 'leader' : 'peer';
    return 'peers $peerCount · lan $role $seenCount';
  }

  void bind({
    required int deviceId,
    required String group,
    required int peerCount,
    required DateTime serverStartAt,
  }) {
    final groupChanged = this.group != group;
    this.deviceId = deviceId;
    this.group = group;
    this.peerCount = peerCount < 1 ? 1 : peerCount;
    this.serverStartAt = serverStartAt.toUtc();
    if (groupChanged) {
      _peers.clear();
      clock.resetGroupSlew();
    }
  }

  void markReady() {
    if (!ready) {
      ready = true;
      onUpdate();
    }
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
      if (json['v'] != 1) return;
      if (json['g'] != group || group.isEmpty) return;
      final id = (json['id'] as num?)?.toInt() ?? 0;
      if (id == 0 || id == deviceId) return;
      final readyFlag = json['r'] == 1 || json['r'] == true;
      final tMs = (json['t'] as num?)?.toInt();
      _peers[id] = _Peer(
        id: id,
        ready: readyFlag,
        tMs: tMs,
        heardAt: DateTime.now(),
      );
      onUpdate();
    } catch (_) {
      // ignore truncated UDP noise
    }
  }

  void _send() {
    final sock = _sock;
    if (sock == null || deviceId == 0 || group.isEmpty) return;
    final body = jsonEncode({
      'v': 1,
      'g': group,
      'id': deviceId,
      't': clock.nowUtc.millisecondsSinceEpoch,
      'r': ready ? 1 : 0,
    });
    sock.send(utf8.encode(body), InternetAddress('255.255.255.255'), port);
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
  final DateTime heardAt;

  _Peer({
    required this.id,
    required this.ready,
    required this.tMs,
    required this.heardAt,
  });
}
