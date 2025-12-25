import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    base: '/manage/',
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            detectTls: process.env.VITE_APP_URL || false, // 检测 TLS（HTTPS）
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
    server: {
        host: '0.0.0.0', // 监听所有网络接口
        port: 5174,
        strictPort: false, // 如果端口被占用，自动尝试下一个端口
        cors: true, // 启用 CORS
        origin: process.env.VITE_APP_URL || 'http://localhost:5174', // 允许的源
        hmr: {
            host: process.env.VITE_HMR_HOST || 'localhost',
            protocol: process.env.VITE_HMR_PROTOCOL || 'ws',
            port: 5174,
        },
    },
});
