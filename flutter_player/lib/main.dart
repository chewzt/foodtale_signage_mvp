import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'screens/pairing_screen.dart';
import 'screens/player_screen.dart';
import 'services/api_service.dart';
import 'services/sync_clock_service.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const SignageApp());
}

class SignageApp extends StatefulWidget {
  const SignageApp({super.key});

  @override
  State<SignageApp> createState() => _SignageAppState();
}

class _SignageAppState extends State<SignageApp> {
  String? token;
  bool loading = true;
  final api = ApiService('');

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final prefs = await SharedPreferences.getInstance();
    final savedUrl = prefs.getString('api_base_url') ?? '';
    api.useBaseUrl(savedUrl);
    setState(() {
      token = prefs.getString('device_token');
      loading = false;
    });
  }

  Future<void> _changeServer() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('device_token');
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      theme: ThemeData(useMaterial3: true),
      home: loading
          ? const Scaffold(body: Center(child: CircularProgressIndicator()))
          : token == null
              ? PairingScreen(api: api, onPaired: _load)
              : PlayerScreen(
                  api: api,
                  clock: SyncClockService(api),
                  token: token!,
                  onChangeServer: _changeServer,
                ),
    );
  }
}
