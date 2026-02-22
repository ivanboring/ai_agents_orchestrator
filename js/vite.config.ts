import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'path';

export default defineConfig({
  plugins: [react()],
  build: {
    outDir: 'dist',
    lib: {
      entry: resolve(__dirname, 'src/index.tsx'),
      name: 'AiAgentsOrchestrator',
      fileName: 'orchestrator-app',
      formats: ['iife'],
    },
    rollupOptions: {
      output: {
        assetFileNames: 'orchestrator-app.[ext]',
      },
    },
    sourcemap: false,
    minify: 'terser',
    terserOptions: {
      compress: {
        drop_console: true,
        drop_debugger: true,
      },
    },
  },
  server: {
    port: 5174,
    host: true,
    cors: true,
    hmr: {
      host: 'localhost',
      port: 5174,
      protocol: 'ws',
    },
  },
  define: {
    'process.env.NODE_ENV': JSON.stringify(process.env.NODE_ENV || 'development'),
  },
});
