import 'dart:io';
import 'package:http/http.dart' as http;
import 'package:path_provider/path_provider.dart';
import '../models/player_manifest.dart';

class MediaCache {
  static const int keepFreeBytes = 300 * 1024 * 1024;

  static Future<Directory> _docs() => getApplicationDocumentsDirectory();

  static Future<Directory> _temp() => getTemporaryDirectory();

  static String _ext(MediaItem item) {
    final last = Uri.parse(item.url).pathSegments.isEmpty
        ? item.type
        : Uri.parse(item.url).pathSegments.last;
    final ext = last.contains('.') ? last.split('.').last : item.type;
    return ext.toLowerCase();
  }

  static Future<File> fileFor(MediaItem item) async {
    final dir = await _docs();
    return File('${dir.path}/media_${item.id}.${_ext(item)}');
  }

  static Future<File> ensure(
    MediaItem item, {
    void Function(int got, int total)? onBytes,
  }) async {
    final file = await fileFor(item);
    if (await file.exists() && await file.length() > 0) {
      return file;
    }
    final part = File('${file.path}.part');
    if (await part.exists()) {
      await part.delete();
    }
    final client = http.Client();
    try {
      final request = http.Request('GET', Uri.parse(item.url));
      final response = await client.send(request).timeout(
            const Duration(minutes: 10),
          );
      if (response.statusCode >= 300) {
        throw Exception('Download failed (${response.statusCode})');
      }
      final total = response.contentLength ?? 0;
      final sink = part.openWrite();
      var got = 0;
      await for (final chunk in response.stream) {
        sink.add(chunk);
        got += chunk.length;
        onBytes?.call(got, total);
      }
      await sink.close();
      if (await file.exists()) {
        await file.delete();
      }
      await part.rename(file.path);
    } finally {
      client.close();
      if (await part.exists()) {
        await part.delete();
      }
    }
    return file;
  }

  static Future<void> retainOnly(Iterable<int> keepIds) async {
    final keep = keepIds.toSet();
    final dir = await _docs();
    await for (final entity in dir.list()) {
      if (entity is! File) continue;
      final name = entity.uri.pathSegments.last;
      if (!name.startsWith('media_')) continue;
      final idPart = name.substring(6).split('.').first;
      final id = int.tryParse(idPart);
      if (id == null || !keep.contains(id)) {
        try {
          await entity.delete();
        } catch (_) {}
      }
    }
  }

  static Future<void> purgeApks() async {
    for (final dir in [await _temp(), await _docs()]) {
      await for (final entity in dir.list()) {
        if (entity is! File) continue;
        if (!entity.path.toLowerCase().endsWith('.apk')) continue;
        try {
          await entity.delete();
        } catch (_) {}
      }
    }
  }

  static Future<void> reclaimIfLow(Set<int> keepIds) async {
    final dir = await _docs();
    var used = 0;
    final files = <File>[];
    await for (final entity in dir.list()) {
      if (entity is! File) continue;
      final name = entity.uri.pathSegments.last;
      if (!name.startsWith('media_')) continue;
      final id = int.tryParse(name.substring(6).split('.').first);
      if (id != null && keepIds.contains(id)) continue;
      files.add(entity);
      used += await entity.length();
    }
    if (used < keepFreeBytes) return;
    files.sort((a, b) => a.lastModifiedSync().compareTo(b.lastModifiedSync()));
    var extra = used - keepFreeBytes;
    for (final file in files) {
      if (extra <= 0) break;
      try {
        extra -= await file.length();
        await file.delete();
      } catch (_) {}
    }
  }
}
