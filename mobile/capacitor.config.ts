import type { CapacitorConfig } from '@capacitor/cli';

// The app opens the engine in Termux on the same phone (see mobile/www/index.html).
const config: CapacitorConfig = {
  appId: 'com.hamba.app',
  appName: 'Hamba',
  webDir: 'www',
  android: {
    allowMixedContent: true,
  },
};

export default config;
