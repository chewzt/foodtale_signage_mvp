import 'dart:async';
import 'api_service.dart';

/// Local answer: `DateTime.now() + NTP offset ≈ CMS UTC`.
/// Every box Cristian-samples `/api/time`. Cuts are local vs server `start_at`.
class SyncClockService {
  final ApiService api;
  Duration _ntpOffset = Duration.zero;
  int _rttMs = 0;
  DateTime? _lastSyncAt;
  Timer? _repeat;
  final List<void Function()> _listeners = [];

  SyncClockService(this.api);

  Duration get offset => _ntpOffset;

  int get offsetMs => offset.inMilliseconds;

  int get rttMs => _rttMs;

  bool get hasServerLock {
    final at = _lastSyncAt;
    if (at == null) return false;
    return DateTime.now().difference(at) < const Duration(seconds: 8);
  }

  DateTime get nowUtc => DateTime.now().toUtc().add(_ntpOffset);

  void addListener(void Function() listener) => _listeners.add(listener);

  void removeListener(void Function() listener) => _listeners.remove(listener);

  void _notify() {
    for (final listener in List<void Function()>.from(_listeners)) {
      listener();
    }
  }

  Future<void> sync({int samples = 3}) async {
    final collected = <({int delayUs, int offsetUs})>[];

    for (var i = 0; i < samples; i++) {
      try {
        final sample = await api.probeNtp();
        if (sample.delayUs < 0) {
          continue;
        }
        collected.add((delayUs: sample.delayUs, offsetUs: sample.offsetUs));
      } catch (_) {}
    }

    if (collected.isEmpty) {
      return;
    }

    collected.sort((a, b) => a.delayUs.compareTo(b.delayUs));
    final best = collected.take(3).toList()
      ..sort((a, b) => a.offsetUs.compareTo(b.offsetUs));
    final chosen = best[best.length ~/ 2];
    _ntpOffset = Duration(microseconds: chosen.offsetUs);
    _rttMs = (chosen.delayUs / 1000).round();
    _lastSyncAt = DateTime.now();
    _notify();
  }

  void applyNtpSample(NtpSample sample) {
    if (sample.delayUs < 0 || sample.delayUs > 150000) {
      return;
    }
    _ntpOffset = Duration(microseconds: sample.offsetUs);
    _rttMs = (sample.delayUs / 1000).round();
    _lastSyncAt = DateTime.now();
    _notify();
  }

  void resetGroupSlew() {}

  Future<void> start() async {
    await sync(samples: 3);
    _repeat?.cancel();
    _repeat = Timer.periodic(const Duration(seconds: 2), (_) {
      unawaited(sync(samples: 1));
    });
  }

  void dispose() {
    _repeat?.cancel();
    _listeners.clear();
  }
}
