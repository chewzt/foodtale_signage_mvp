import 'dart:async';
import 'dart:io';
import 'dart:typed_data';
import 'api_service.dart';

class SntpClient {
  static const int port = 8123;
  static const int _unixToNtp = 2208988800;

  static Future<NtpSample> probe(String host, {int port = SntpClient.port}) async {
    final sock = await RawDatagramSocket.bind(InternetAddress.anyIPv4, 0);
    sock.readEventsEnabled = true;
    final done = Completer<Datagram?>();
    final sub = sock.listen((event) {
      if (event == RawSocketEvent.read && !done.isCompleted) {
        done.complete(sock.receive());
      }
    });
    try {
      final t0 = DateTime.now().microsecondsSinceEpoch;
      final sent = sock.send(
        _clientPacket(t0),
        await _resolve(host),
        port,
      );
      if (sent <= 0) {
        throw Exception('SNTP send failed');
      }
      final packet = await done.future.timeout(const Duration(milliseconds: 800));
      final t3 = DateTime.now().microsecondsSinceEpoch;
      if (packet == null || packet.data.length < 48) {
        throw Exception('empty SNTP');
      }
      final data = ByteData.sublistView(Uint8List.fromList(packet.data));
      final t1 = _timestampUs(data, 32);
      final t2 = _timestampUs(data, 40);
      return NtpSample(t0: t0, t1: t1, t2: t2, t3: t3);
    } finally {
      await sub.cancel();
      sock.close();
    }
  }

  static Future<InternetAddress> _resolve(String host) async {
    final parsed = InternetAddress.tryParse(host);
    if (parsed != null) return parsed;
    final found = await InternetAddress.lookup(host);
    if (found.isEmpty) {
      throw Exception('SNTP lookup failed');
    }
    return found.first;
  }

  static Uint8List _clientPacket(int t0Us) {
    final bytes = Uint8List(48);
    bytes[0] = 0x23;
    _writeTimestamp(ByteData.sublistView(bytes), 40, t0Us);
    return bytes;
  }

  static void _writeTimestamp(ByteData data, int offset, int unixUs) {
    final unix = unixUs / 1000000.0 + _unixToNtp;
    var seconds = unix.floor();
    var frac = ((unix - seconds) * 4294967296).round();
    if (frac >= 4294967296) {
      seconds += 1;
      frac = 0;
    }
    data.setUint32(offset, seconds);
    data.setUint32(offset + 4, frac);
  }

  static int _timestampUs(ByteData data, int offset) {
    final sec = data.getUint32(offset);
    final frac = data.getUint32(offset + 4);
    final unix = (sec - _unixToNtp) + frac / 4294967296.0;
    return (unix * 1000000).round();
  }
}
