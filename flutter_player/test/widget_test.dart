import 'package:flutter_test/flutter_test.dart';
import 'package:foodtale_signage_player/main.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  testWidgets('shows pairing screen when no device token', (tester) async {
    SharedPreferences.setMockInitialValues({});
    await tester.pumpWidget(const SignageApp());
    await tester.pump();
    await tester.pump();
    expect(find.text('Foodtale Signage'), findsOneWidget);
    expect(find.text('Pair device'), findsOneWidget);
  });
}
