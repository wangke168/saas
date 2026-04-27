import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    base: '/manage/',
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },
    build: {
        chunkSizeWarningLimit: 800,
        rollupOptions: {
            output: {
                manualChunks(id) {
                    // 分包策略说明：
                    // 1) 业务代码保持按路由/页面自然拆分；
                    // 2) 仅对 node_modules 做保守分包，优先保证线上稳定；
                    // 3) 避免对 Element Plus 做激进手动拆分（曾引发线上运行时兼容问题）。
                    if (!id.includes('node_modules')) {
                        return;
                    }

                    if (id.includes('vue') || id.includes('pinia') || id.includes('vue-router')) {
                        return 'vendor-vue-core';
                    }

                    if (id.includes('axios')) {
                        return 'vendor-axios';
                    }
                },
            },
        },
    },
    server: {
        host: '0.0.0.0', // 监听所有网络接口
        port: 5174,
        strictPort: false, // 如果端口被占用，自动尝试下一个端口
        cors: true, // 启用 CORS
        hmr: {
            host: process.env.VITE_HMR_HOST || 'localhost',
            protocol: process.env.VITE_HMR_PROTOCOL || 'ws',
            // 不指定端口，让 Vite 自动使用 server.port
        },
    },
});
