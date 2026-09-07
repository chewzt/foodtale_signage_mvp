import 'dart:async';
import 'api_service.dart';

/// Local answer: `DateTime.now() + offset ≈ server UTC`.
/// Offsets differ per phone; the shared axis is still server `start_at`.
/// After boot, clock refresh comes from sync-confirm — not a 20s /api/time burst.
class SyncClockService {
  final ApiService api;
  Duration _ntpOffset = Duration.zero;
  Duration _groupSlew = Duration.zero;
  int _rttMs = 0;
  final List<void Function()> _listeners = [];

  SyncClockService(this.api);

  Duration get offset => _ntpOffset + _groupSlew;

  int get offsetMs => offset.inMilliseconds;

  int get rttMs => _rttMs;

  DateTime get nowUtc => DateTime.now().toUtc().add(_ntpOffset).add(_groupSlew);

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
      final sample = await api.probeNtp();
      if (sample.delayUs < 0) {
        continue;
      }
      collected.add((delayUs: sample.delayUs, offsetUs: sample.offsetUs));
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
    _notify();
  }

  void applyNtpSample(NtpSample sample) {
    if (sample.delayUs < 0 || sample.delayUs > 150000) {
      return;
    }
    _ntpOffset = Duration(microseconds: sample.offsetUs);
    _rttMs = (sample.delayUs / 1000).round();
    _notify();
  }

  void resetGroupSlew() {
    _groupSlew = Duration.zero;
  }

  void slewToward(DateTime remoteNow) {
    final errMs = remoteNow.difference(nowUtc).inMilliseconds;
    if (errMs.abs() < 3) {
      return;
    }
    final step = (errMs * 0.35).round().clamp(-40, 40);
    var next = _groupSlew.inMilliseconds + step;
    if (next > 250) next = 250;
    if (next < -250) next = -250;
    _groupSlew = Duration(milliseconds: next);
    if (step.abs() >= 8) {
      _notify();
    }
  }

  Future<void> start() async {
    await sync(samples: 3);
  }

  void dispose() {
    _listeners.clear();
  }
}
