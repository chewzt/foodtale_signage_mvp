import 'dart:async';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:open_filex/open_filex.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:path_provider/path_provider.dart';
import '../services/api_service.dart';
import '../services/media_cache.dart';

class AppUpdateHost extends StatefulWidget {
  final ApiService api;
  final Widget child;

  const AppUpdateHost({
    super.key,
    required this.api,
    required this.child,
  });

  @override
  State<AppUpdateHost> createState() => _AppUpdateHostState();
}

class _AppUpdateHostState extends State<AppUpdateHost> {
  Timer? _timer;
  AppVersion? _update;
  int _localCode = 0;
  bool _busy = false;
  double? _progress;
  String? _error;

  @override
  void initState() {
    super.initState();
    unawaited(_boot());
  }

  Future<void> _boot() async {
    await MediaCache.purgeApks();
    try {
      final info = await PackageInfo.fromPlatform();
      _localCode = int.tryParse(info.buildNumber) ?? 0;
    } catch (_) {
      return;
    }
    await _check();
    _timer = Timer.periodic(const Duration(seconds: 30), (_) {
      unawaited(_check());
    });
  }

  Future<void> _check() async {
    if (_busy || widget.api.baseUrl.isEmpty) return;
    try {
      final remote = await widget.api.getAppVersion();
      if (!mounted) return;
      final newer = remote != null &&
          remote.versionCode > 0 &&
          remote.versionCode > _localCode;
      setState(() {
        _update = newer ? remote : null;
        if (newer) _error = null;
      });
    } catch (_) {
      // keep playing; try again next tick
    }
  }

  Future<void> _install() async {
    final remote = _update;
    if (remote == null || _busy) return;
    setState(() {
      _busy = true;
      _progress = 0;
      _error = null;
    });
    try {
      await MediaCache.purgeApks();
      final dir = await getTemporaryDirectory();
      final file = File('${dir.path}/foodtale-player-${remote.versionCode}.apk');
      if (await file.exists()) {
        await file.delete();
      }
      final client = http.Client();
      try {
        final request = http.Request('GET', Uri.parse(remote.apkUrl));
        final response = await client.send(request);
        if (response.statusCode >= 300) {
          throw Exception('Download failed (${response.statusCode})');
        }
        final total = response.contentLength ?? 0;
        final sink = file.openWrite();
        var got = 0;
        await for (final chunk in response.stream) {
          sink.add(chunk);
          got += chunk.length;
          if (mounted && total > 0) {
            setState(() => _progress = got / total);
          }
        }
        await sink.close();
      } finally {
        client.close();
      }
      final result = await OpenFilex.open(
        file.path,
        type: 'application/vnd.android.package-archive',
      );
      if (result.type != ResultType.done && mounted) {
        setState(() {
          _error = result.message;
        });
      } else {
        unawaited(Future<void>.delayed(const Duration(seconds: 8), () {
          unawaited(MediaCache.purgeApks());
        }));
      }
    } catch (e) {
      if (mounted) {
        setState(() => _error = e.toString());
      }
    } finally {
      if (mounted) {
        setState(() {
          _busy = false;
          _progress = null;
        });
      }
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final update = _update;
    return Stack(
      fit: StackFit.expand,
      children: [
        widget.child,
        if (update != null)
          Positioned(
            top: 0,
            left: 0,
            right: 0,
            child: SafeArea(
              child: Material(
                color: const Color(0xE61B5E20),
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(12, 8, 8, 8),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: Text(
                              _busy
                                  ? 'Downloading ${update.version}…'
                                  : 'New APK ${update.version} is on the server',
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: 14,
                              ),
                            ),
                          ),
                          FilledButton(
                            onPressed: _busy ? null : () => unawaited(_install()),
                            style: FilledButton.styleFrom(
                              backgroundColor: Colors.white,
                              foregroundColor: const Color(0xFF1B5E20),
                            ),
                            child: Text(_busy ? 'Wait' : 'Install'),
                          ),
                        ],
                      ),
                      if (_progress != null) ...[
                        const SizedBox(height: 6),
                        LinearProgressIndicator(value: _progress),
                      ],
                      if (_error != null) ...[
                        const SizedBox(height: 6),
                        Text(
                          _error!,
                          style: const TextStyle(color: Color(0xFFFFCDD2), fontSize: 12),
                        ),
                      ],
                    ],
                  ),
                ),
              ),
            ),
          ),
      ],
    );
  }
}
