import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../services/api_service.dart';

class PairingScreen extends StatefulWidget {
  final ApiService api;
  final VoidCallback onPaired;

  const PairingScreen({super.key, required this.api, required this.onPaired});

  @override
  State<PairingScreen> createState() => _PairingScreenState();
}

class _PairingScreenState extends State<PairingScreen> {
  final server = TextEditingController();
  final code = TextEditingController();
  final name = TextEditingController(text: 'TV Box');
  bool loading = false;
  String? error;

  @override
  void initState() {
    super.initState();
    _loadSavedServer();
  }

  Future<void> _loadSavedServer() async {
    final prefs = await SharedPreferences.getInstance();
    final saved = prefs.getString('api_base_url');
    if (saved != null && saved.isNotEmpty && mounted) {
      server.text = saved;
    } else if (widget.api.baseUrl.isNotEmpty) {
      server.text = widget.api.baseUrl;
    }
  }

  Future<void> pair() async {
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final base = ApiService.normalize(server.text);
      if (base.isEmpty) {
        throw Exception('Enter the Laravel URL from admin, e.g. http://192.168.0.10:8000');
      }
      widget.api.useBaseUrl(base);
      final token =
          await widget.api.pairDevice(code.text.trim(), name.text.trim());
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString('api_base_url', base);
      await prefs.setString('device_token', token);
      widget.onPaired();
    } catch (e) {
      setState(() => error = e.toString());
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  @override
  void dispose() {
    server.dispose();
    code.dispose();
    name.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final maxW = MediaQuery.sizeOf(context).width;
    return Scaffold(
      backgroundColor: Colors.black,
      body: SafeArea(
        child: LayoutBuilder(
          builder: (context, constraints) {
            return SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: ConstrainedBox(
                constraints: BoxConstraints(minHeight: constraints.maxHeight - 32),
                child: Center(
                  child: ConstrainedBox(
                    constraints: BoxConstraints(
                      maxWidth: maxW >= 600 ? 560 : maxW,
                    ),
                    child: Card(
                      child: Padding(
                        padding: const EdgeInsets.all(24),
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const Text('Foodtale Signage',
                                style: TextStyle(
                                    fontSize: 26, fontWeight: FontWeight.bold)),
                            const SizedBox(height: 8),
                            const Text(
                              'Type the server URL from admin, then the pairing code.',
                              textAlign: TextAlign.center,
                            ),
                            const SizedBox(height: 22),
                            TextField(
                              controller: server,
                              keyboardType: TextInputType.url,
                              decoration: const InputDecoration(
                                labelText: 'Server URL',
                                hintText: 'http://192.168.0.10:8000',
                              ),
                            ),
                            const SizedBox(height: 14),
                            TextField(
                              controller: name,
                              decoration: const InputDecoration(
                                  labelText: 'Device name'),
                            ),
                            const SizedBox(height: 14),
                            TextField(
                              controller: code,
                              decoration: const InputDecoration(
                                  labelText: 'Pairing code'),
                            ),
                            if (error != null) ...[
                              const SizedBox(height: 12),
                              Text(error!,
                                  style: const TextStyle(color: Colors.red)),
                            ],
                            const SizedBox(height: 22),
                            SizedBox(
                              width: double.infinity,
                              child: FilledButton(
                                onPressed: loading ? null : pair,
                                child: Text(
                                    loading ? 'Pairing...' : 'Pair device'),
                              ),
                            )
                          ],
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            );
          },
        ),
      ),
    );
  }
}
