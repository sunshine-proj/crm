import { defineConfig } from 'vitest/config';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
  plugins: [
    VitePWA({
      registerType: 'prompt', // never auto-reload mid-run
      manifest: false, // we ship public/manifest.webmanifest
      includeAssets: ['icons/*.png', 'offline.html'],
      workbox: {
        globPatterns: ['**/*.{js,css,html,png,svg,webmanifest}'],
        cleanupOutdatedCaches: true,
        navigateFallback: 'index.html',
      },
    }),
  ],
  test: { include: ['tests/unit/**/*.test.ts'] },
});
