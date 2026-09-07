import 'dart:async';
import 'api_service.dart';

/// Local answer: `DateTime.now() + offset ≈ server UTC`.
/// Offsets differ per phone; the shared axis is still server `start_at`.
class SyncClockService {
  final ApiService api;
  Duration _offset = Duration.zero;
  int _rttMs = 0;
  Timer? _timer;
  final List<void Function()> _listeners = [];

  SyncClockService(this.api);

  Duration get offset => _offset;

  int get offsetMs => _offset.inMilliseconds;

  int get rttMs => _rttMs;

  DateTime get nowUtc => DateTime.now().toUtc().add(_offset);

  void addListener(void Function() listener) => _listeners.add(listener);

  void removeListener(void Function() listener) => _listeners.remove(listener);

  void _notify() {
    for (final listener in List<void Function()>.from(_listeners)) {
      listener();
    }
  }

  Future<void> sync() async {
    final samples = <({int delayUs, int offsetUs})>[];

    for (var i = 0; i < 8; i++) {
      final sample = await api.probeNtp();
      if (sample.delayUs < 0) {
        continue;
      }
      samples.add((delayUs: sample.delayUs, offsetUs: sample.offsetUs));
    }

    if (samples.isEmpty) {
      return;
    }

    samples.sort((a, b) => a.delayUs.compareTo(b.delayUs));
    final best = samples.take(3).toList()
      ..sort((a, b) => a.offsetUs.compareTo(b.offsetUs));
    final chosen = best[best.length ~/ 2];
    _offset = Duration(microseconds: chosen.offsetUs);
    _rttMs = (chosen.delayUs / 1000).round();
    _notify();
  }

  Future<void> start() async {
    await sync();
    _timer?.cancel();
    _timer = Timer.periodic(const Duration(seconds: 20), (_) {
      unawaited(sync());
    });
  }

  void dispose() {
    _timer?.cancel();
    _listeners.clear();
  }
}
