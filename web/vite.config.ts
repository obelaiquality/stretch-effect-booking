import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// GitHub Pages serves the site at /<repo>/ ; Afrihost serves it at /.
export default defineConfig({
  base: process.env.VITE_BASE ?? '/',
  plugins: [react()],
  server: {
    port: 5180,
    proxy: {
      '/api': 'http://127.0.0.1:8090',
    },
  },
  test: {
    environment: 'node',
    testTimeout: 120_000, // fuzz properties can run long with FC_RUNS overrides
  },
});
