import 'dart:convert';
import 'package:shared_preferences/shared_preferences.dart';
import '../models/player_manifest.dart';

class ManifestCache {
  static const _key = 'signage_cached_manifest_v1';

  static Future<void> save(PlayerManifest manifest) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_key, jsonEncode(manifest.toJson()));
  }

  static Future<PlayerManifest?> load() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_key);
    if (raw == null || raw.isEmpty) return null;
    try {
      return PlayerManifest.fromJson(jsonDecode(raw) as Map<String, dynamic>);
    } catch (_) {
      return null;
    }
  }

  static Future<void> clear() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_key);
  }
}
