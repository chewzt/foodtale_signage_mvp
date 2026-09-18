import 'dart:async';
import 'api_service.dart';
import 'sntp_client.dart';

/// Local answer: `DateTime.now() + offset ≈ server UTC`.
/// Prefers LAN SNTP (UDP 8123 next to `signage:serve`) so `/api/time` is not
/// queued behind PHP video uploads.
class SyncClockService {
  final ApiService api;
  Duration _ntpOffset = Duration.zero;
  Duration _groupSlew = Duration.zero;
  int _rttMs = 0;
  String source = 'none';
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
    final lan = api.sntpHost;
    if (lan != null) {
      final ok = await _collect(samples, () => SntpClient.probe(lan));
      if (ok) {
        source = 'sntp';
        _notify();
        return;
      }
    }
    final ok = await _collect(samples, api.probeNtp);
    if (ok) {
      source = 'http';
      _notify();
    }
  }

  Future<bool> _collect(
    int samples,
    Future<NtpSample> Function() probe,
  ) async {
    final collected = <({int delayUs, int offsetUs})>[];
    for (var i = 0; i < samples; i++) {
      try {
        final sample = await probe();
        if (sample.delayUs < 0) continue;
        collected.add((delayUs: sample.delayUs, offsetUs: sample.offsetUs));
      } catch (_) {
        // try remaining samples / HTTP fallback
      }
    }
    if (collected.isEmpty) return false;
    collected.sort((a, b) => a.delayUs.compareTo(b.delayUs));
    final best = collected.take(3).toList()
      ..sort((a, b) => a.offsetUs.compareTo(b.offsetUs));
    final chosen = best[best.length ~/ 2];
    _ntpOffset = Duration(microseconds: chosen.offsetUs);
    _rttMs = (chosen.delayUs / 1000).round();
    return true;
  }

  void applyNtpSample(NtpSample sample) {
    if (source == 'sntp') return;
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
