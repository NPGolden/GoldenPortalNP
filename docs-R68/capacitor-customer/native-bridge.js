/* native-bridge.js — included in the store build only (prepare-web.js injects it after lib/native.js).
   Exposes Capacitor plug-ins under window.Capacitor.Plugins with the names lib/native.js expects, wires push registration and deep links. */
import { Capacitor } from '@capacitor/core';
import { App } from '@capacitor/app';
import { Device } from '@capacitor/device';
import { Camera } from '@capacitor/camera';
import { PushNotifications } from '@capacitor/push-notifications';
import { StatusBar, Style } from '@capacitor/status-bar';
import { SplashScreen } from '@capacitor/splash-screen';
import { NativeBiometric } from 'capacitor-native-biometric';
import { SecureStorage } from '@aparajita/capacitor-secure-storage';

window.Capacitor = Capacitor;
Capacitor.Plugins = Object.assign(Capacitor.Plugins || {}, { App, Device, Camera, PushNotifications, NativeBiometric, SecureStorage: {
  get: ({ key }) => SecureStorage.get(key).then(value => ({ value })), set: ({ key, value }) => SecureStorage.set(key, value), remove: ({ key }) => SecureStorage.remove(key) } });

StatusBar.setStyle({ style: Style.Dark }).catch(() => {}); StatusBar.setBackgroundColor({ color: '#0A1B2E' }).catch(() => {});
window.addEventListener('load', () => setTimeout(() => SplashScreen.hide(), 300));

// Push: token → API; tap → deep link
PushNotifications.addListener('registration', t => { window.Api && Api.call('POST', '/push/register', { token: t.value, provider: Capacitor.getPlatform() === 'ios' ? 'apns' : 'fcm' }).catch(() => {}); });
PushNotifications.addListener('pushNotificationActionPerformed', n => { const url = n.notification.data && n.notification.data.url; if (url) location.hash = url.replace(/^.*#/, '#'); });
PushNotifications.addListener('pushNotificationReceived', () => { window.App && App.refreshBadges && window.App.refreshBadges(); });
// Universal links https://goldensas.com/customer/app/#/… and goldenas://…
App.addListener('appUrlOpen', e => { const m = (e.url || '').match(/#(\/.*)$/); if (m) location.hash = '#' + m[1]; });
App.addListener('backButton', ({ canGoBack }) => { if (canGoBack) history.back(); else App.exitApp(); });
