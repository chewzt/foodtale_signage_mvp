import 'dart:async';
import 'dart:convert';
import 'package:web_socket_channel/web_socket_channel.dart';

/// CMS kick pipe when UDP broadcast cannot reach the phone (other VLAN / later cloud).
/// Same JSON as UDP v2; player dedupes by kick_id.
class KickWsService {
  static const int defaultPort = 8080;

  final String Function() baseUrl;
  final String Function() token;
  final void Function(Map<String, dynamic> kick) onKick;

  WebSocketChannel? _channel;
  StreamSubscription? _sub;
  Timer? _reconnect;
  Timer? _ping;
  bool _disposed = false;
  int _attempt = 0;

  KickWsService({
    required this.baseUrl,
    required this.token,
    required this.onKick,
  });

  Uri? get _uri {
    final raw = baseUrl().trim();
    final tok = token().trim();
    if (raw.isEmpty || tok.isEmpty) return null;
    final http = Uri.tryParse(raw);
    if (http == null || http.host.isEmpty) return null;
    final scheme = http.scheme == 'https' ? 'wss' : 'ws';
    return Uri(
      scheme: scheme,
      host: http.host,
      port: defaultPort,
      path: '/device',
      queryParameters: {'token': tok},
    );
  }

  void start() {
    _disposed = false;
    unawaited(_connect());
  }

  void notifyPlaylist(int? playlistId) {
    final ch = _channel;
    if (ch == null) return;
    try {
      ch.sink.add(jsonEncode({
        'kind': 'playlist',
        'playlist_id': playlistId,
      }));
    } catch (_) {}
  }

  Future<void> _connect() async {
    if (_disposed) return;
    _teardownSocket();
    final uri = _uri;
    if (uri == null) {
      _scheduleReconnect();
      return;
    }
    try {
      final channel = WebSocketChannel.connect(uri);
      _channel = channel;
      await channel.ready;
      _attempt = 0;
      _sub = channel.stream.listen(
        _onMessage,
        onError: (_) => _onDrop(),
        onDone: _onDrop,
        cancelOnError: true,
      );
      _ping?.cancel();
      _ping = Timer.periodic(const Duration(seconds: 20), (_) {
        try {
          _channel?.sink.add(jsonEncode({'kind': 'ping'}));
        } catch (_) {
          _onDrop();
        }
      });
    } catch (_) {
      _onDrop();
    }
  }

  void _onMessage(dynamic raw) {
    try {
      final text = raw is String ? raw : utf8.decode(raw as List<int>);
      final json = jsonDecode(text) as Map<String, dynamic>;
      if (json['v'] != 2) return;
      final kind = json['kind'] as String?;
      if (kind == 'hello' || kind == 'pong' || kind == 'ping') return;
      onKick(json);
    } catch (_) {}
  }

  void _onDrop() {
    _teardownSocket();
    _scheduleReconnect();
  }

  void _scheduleReconnect() {
    if (_disposed) return;
    _reconnect?.cancel();
    final delayMs = (500 * (1 << _attempt.clamp(0, 5))).clamp(500, 15000);
    _attempt += 1;
    _reconnect = Timer(Duration(milliseconds: delayMs), () {
      unawaited(_connect());
    });
  }

  void _teardownSocket() {
    _ping?.cancel();
    _ping = null;
    _sub?.cancel();
    _sub = null;
    try {
      _channel?.sink.close();
    } catch (_) {}
    _channel = null;
  }

  void dispose() {
    _disposed = true;
    _reconnect?.cancel();
    _teardownSocket();
  }
}
