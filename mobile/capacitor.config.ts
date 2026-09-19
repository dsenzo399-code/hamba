import type { CapacitorConfig } from '@capacitor/cli';

// Build-time server address. Codemagic injects HAMBA_URL (see codemagic.yaml).
// LAN testing default: this PC on the local network (cleartext HTTP allowed below).
const serverUrl = process.env.HAMBA_URL || 'http://10.240.99.94/hamba';

const config: CapacitorConfig = {
  appId: 'com.hamba.app',
  appName: 'Hamba',
  webDir: 'www',
  server: {
    url: serverUrl,
    cleartext: true,
  },
  android: {
    allowMixedContent: true,
  },
  plugins: {
    Geolocation: {
      androidForegroundService: true,
    },
  },
};

export default config;
