# Keep Android Javascript bridge entry points used by WebView.
-keepclassmembers class * {
    @android.webkit.JavascriptInterface <methods>;
}

# Keep bridge/result classes stable for WebView callbacks and release minification.
-keep class id.my.hopenoodles.hopepos.ui.WebAppBridge { *; }
-keep class id.my.hopenoodles.hopepos.ui.WebAppBridge$BridgeResult { *; }

# Keep receipt models stable. They are parsed from web payloads and used by printer formatting.
-keep class id.my.hopenoodles.hopepos.data.model.** { *; }

# Keep bluetooth/printer classes unobfuscated to avoid device-specific reflection/regression risk.
-keep class id.my.hopenoodles.hopepos.bluetooth.** { *; }
