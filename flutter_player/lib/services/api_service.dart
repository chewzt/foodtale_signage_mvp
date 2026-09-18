import 'dart:convert';
import 'package:http/http.dart' as http;
import '../models/player_manifest.dart';

class ApiService {
  String baseUrl;
  ApiService(this.baseUrl);

  void useBaseUrl(String url) {
    baseUrl = _normalize(url);
  }

  String? get sntpHost {
    final host = Uri.tryParse(baseUrl)?.host;
    if (host == null || host.isEmpty) return null;
    return host;
  }

  static String normalize(String raw) {
    return _normalize(raw);
  }

  static String _normalize(String raw) {
    var url = raw.trim();
    if (url.isEmpty) return url;
    if (!url.startsWith('http://') && !url.startsWith('https://')) {
      url = 'http://$url';
    }
    if (url.endsWith('/')) {
      url = url.substring(0, url.length - 1);
    }
    return url;
  }

  Future<String> pairDevice(String pairingCode, String deviceName) async {
    final response = await http.post(
      Uri.parse('$baseUrl/api/pair'),
      headers: {'Content-Type': 'application/json'},
      body: jsonEncode({
        'pairing_code': pairingCode,
        'device_name': deviceName,
      }),
    );
    if (response.statusCode >= 300) {
      throw Exception('Pairing failed: ${response.body}');
    }
    return jsonDecode(response.body)['device_token'];
  }

  Future<AppVersion?> getAppVersion() async {
    if (baseUrl.isEmpty) return null;
    final response = await http.get(
      Uri.parse('$baseUrl/api/app/version'),
      headers: {'Accept': 'application/json'},
    );
    if (response.statusCode >= 300) {
      return null;
    }
    final json = jsonDecode(response.body) as Map<String, dynamic>;
    if (json['available'] != true) return null;
    final url = json['apk_url'] as String?;
    if (url == null || url.isEmpty) return null;
    return AppVersion(
      version: json['version'] as String? ?? '',
      versionCode: (json['version_code'] as num?)?.toInt() ?? 0,
      apkUrl: url,
    );
  }

  Future<NtpSample> probeNtp() async {
    final t0 = DateTime.now().microsecondsSinceEpoch;
    final uri = Uri.parse('$baseUrl/api/time').replace(
      queryParameters: {'t0': '$t0'},
    );
    final response = await http.get(uri);
    final t3 = DateTime.now().microsecondsSinceEpoch;
    if (response.statusCode >= 300) {
      throw Exception('Could not get server time');
    }
    final json = jsonDecode(response.body) as Map<String, dynamic>;
    return NtpSample.parse(json, t0: t0, t3: t3);
  }

  Future<ManifestFetch> getManifest(
    String token, {
    required int clockOffsetMs,
    required int clockRttMs,
    String? etag,
  }) async {
    final uri = Uri.parse('$baseUrl/api/device/manifest').replace(
      queryParameters: {
        'clock_offset_ms': '$clockOffsetMs',
        'clock_rtt_ms': '$clockRttMs',
      },
    );
    final headers = <String, String>{
      'Authorization': 'Bearer $token',
      'Accept': 'application/json',
    };
    if (etag != null && etag.isNotEmpty) {
      headers['If-None-Match'] = etag;
    }
    final response = await http.get(uri, headers: headers);
    if (response.statusCode == 304) {
      return ManifestFetch(unchanged: true, etag: etag);
    }
    if (response.statusCode >= 300) {
      throw Exception('Manifest error: ${response.body}');
    }
    return ManifestFetch(
      unchanged: false,
      etag: response.headers['etag'],
      manifest: PlayerManifest.fromJson(jsonDecode(response.body)),
    );
  }

  Future<SyncConfirm> confirmSync(
    String token, {
    required int clockOffsetMs,
    required int clockRttMs,
  }) async {
    final t0 = DateTime.now().microsecondsSinceEpoch;
    final uri = Uri.parse('$baseUrl/api/device/sync-confirm').replace(
      queryParameters: {
        't0': '$t0',
        'clock_offset_ms': '$clockOffsetMs',
        'clock_rtt_ms': '$clockRttMs',
      },
    );
    final response = await http.get(
      uri,
      headers: {'Authorization': 'Bearer $token'},
    );
    final t3 = DateTime.now().microsecondsSinceEpoch;
    if (response.statusCode >= 300) {
      throw Exception('Sync confirm error: ${response.body}');
    }
    final json = jsonDecode(response.body) as Map<String, dynamic>;
    final remaining = (json['remaining_ms'] as num?)?.toInt() ?? 0;
    return SyncConfirm(
      sample: NtpSample.parse(json, t0: t0, t3: t3),
      nextCutAt: DateTime.parse(json['next_cut_at'] as String).toUtc(),
      remainingMs: remaining,
      confirmId: json['confirm_id'] as String? ?? json['next_cut_at'] as String,
    );
  }

  Future<HeartbeatVerdict> postHeartbeat(
    String token, {
    required int clockOffsetMs,
    required int clockRttMs,
    required int index,
    required int itemId,
    required int decoderMs,
    required int fileMs,
    required bool looping,
    required bool playing,
    required bool ready,
    required int waitingMs,
  }) async {
    final response = await http.post(
      Uri.parse('$baseUrl/api/device/heartbeat'),
      headers: {
        'Authorization': 'Bearer $token',
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      },
      body: jsonEncode({
        'clock_offset_ms': clockOffsetMs,
        'clock_rtt_ms': clockRttMs,
        'index': index,
        'item_id': itemId,
        'decoder_ms': decoderMs,
        'file_ms': fileMs,
        'looping': looping,
        'playing': playing,
        'ready': ready,
        'waiting_ms': waitingMs,
      }),
    );
    if (response.statusCode >= 300) {
      throw Exception('Heartbeat error: ${response.body}');
    }
    final json = jsonDecode(response.body) as Map<String, dynamic>;
    return HeartbeatVerdict(
      action: json['action'] as String? ?? 'none',
      index: (json['index'] as num?)?.toInt() ?? -1,
      positionMs: (json['position_ms'] as num?)?.toInt() ?? 0,
      lagMs: (json['lag_ms'] as num?)?.toInt() ?? 0,
    );
  }
}

class AppVersion {
  final String version;
  final int versionCode;
  final String apkUrl;

  const AppVersion({
    required this.version,
    required this.versionCode,
    required this.apkUrl,
  });
}

class NtpSample {
  final int t0;
  final int t1;
  final int t2;
  final int t3;

  const NtpSample({
    required this.t0,
    required this.t1,
    required this.t2,
    required this.t3,
  });

  factory NtpSample.parse(
    Map<String, dynamic> json, {
    required int t0,
    required int t3,
  }) {
    final echoed = json['t0'];
    final t1 = json['t1'];
    final t2 = json['t2'];
    if (t1 is num && t2 is num) {
      return NtpSample(
        t0: echoed is num ? echoed.toInt() : t0,
        t1: t1.toInt(),
        t2: t2.toInt(),
        t3: t3,
      );
    }

    final utc = json['utc'];
    final serverUs = utc is String
        ? DateTime.parse(utc).toUtc().microsecondsSinceEpoch
        : t0 + ((t3 - t0) ~/ 2);
    return NtpSample(t0: t0, t1: serverUs, t2: serverUs, t3: t3);
  }

  int get delayUs => (t3 - t0) - (t2 - t1);

  int get offsetUs => ((t1 - t0) + (t2 - t3)) ~/ 2;
}

class SyncConfirm {
  final NtpSample sample;
  final DateTime nextCutAt;
  final int remainingMs;
  final String confirmId;

  const SyncConfirm({
    required this.sample,
    required this.nextCutAt,
    required this.remainingMs,
    required this.confirmId,
  });
}

class ManifestFetch {
  final bool unchanged;
  final String? etag;
  final PlayerManifest? manifest;

  const ManifestFetch({
    required this.unchanged,
    this.etag,
    this.manifest,
  });
}

class HeartbeatVerdict {
  final String action;
  final int index;
  final int positionMs;
  final int lagMs;

  const HeartbeatVerdict({
    required this.action,
    required this.index,
    required this.positionMs,
    required this.lagMs,
  });
}

