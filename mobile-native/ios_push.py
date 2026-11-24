#!/usr/bin/env python3
"""iOS build — push notifications in the Capacitor project (run in ios/App).                                   GAS-CP-R97-197
Writes App/App.entitlements (aps-environment), adds to AppDelegate.swift the two callbacks through which the push plug-in
receives the device token (Capacitor documentation), and marks the build in capacitor.config.json so the app pages know
that this build can register. Idempotent; holds no key material."""
import json, os, sys

open("App/App.entitlements", "w").write('<?xml version="1.0" encoding="UTF-8"?>\n<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">\n<plist version="1.0"><dict>\n  <key>aps-environment</key><string>production</string>\n' + ('  <key>com.apple.developer.associated-domains</key><array><string>webcredentials:goldensas.com</string><string>webcredentials:www.goldensas.com</string></array>\n' if os.environ.get("ASSOC") == "1" else '') + '</dict></plist>\n')   # R97-218: passkeys — iOS accepts a passkey for goldensas.com only from an app that declares the domain
print("associated domains:", "yes" if os.environ.get("ASSOC") == "1" else "no (profile without the capability)")

p = "App/AppDelegate.swift"; s = open(p).read()
if "didRegisterForRemoteNotificationsWithDeviceToken" not in s:
    add = """
    func application(_ application: UIApplication, didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data) {
        NotificationCenter.default.post(name: .capacitorDidRegisterForRemoteNotifications, object: deviceToken)
    }

    func application(_ application: UIApplication, didFailToRegisterForRemoteNotificationsWithError error: Error) {
        NotificationCenter.default.post(name: .capacitorDidFailToRegisterForRemoteNotifications, object: error)
    }
"""
    i = s.rstrip().rfind("}")
    if i < 0: sys.exit("AppDelegate.swift: closing brace of the class not found")
    s = s[:i] + add + s[i:]; open(p, "w").write(s)
print("AppDelegate callbacks:", s.count("RemoteNotificationsWith"))

f = "App/capacitor.config.json"; c = json.load(open(f)); c["appendUserAgent"] = " GASPush/1"; json.dump(c, open(f, "w"), indent=2)
print("build mark:", c["appendUserAgent"].strip())
