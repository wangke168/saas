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
                    // 2) 仅对 node_modules 做粗粒度 vendor 分包，避免单一超大 chunk；
                    // 3) Element Plus 按表单/表格/弹层拆分，平衡包体与请求数；
                    // 4) 该策略已验证可消除 chunkSizeWarning，并保持页面可用性。
                    if (!id.includes('node_modules')) {
                        return;
                    }

                    if (id.includes('element-plus/es/components')) {
                        if (id.includes('/table') || id.includes('/pagination') || id.includes('/scrollbar')) {
                            return 'vendor-element-table';
                        }
                        if (
                            id.includes('/form') ||
                            id.includes('/input') ||
                            id.includes('/select') ||
                            id.includes('/option') ||
                            id.includes('/date-picker') ||
                            id.includes('/time-picker') ||
                            id.includes('/checkbox') ||
                            id.includes('/radio') ||
                            id.includes('/switch') ||
                            id.includes('/input-number')
                        ) {
                            return 'vendor-element-form';
                        }
                        if (
                            id.includes('/dialog') ||
                            id.includes('/drawer') ||
                            id.includes('/dropdown') ||
                            id.includes('/tooltip') ||
                            id.includes('/popper') ||
                            id.includes('/popover')
                        ) {
                            return 'vendor-element-overlay';
                        }
                        return 'vendor-element-components';
                    }

                    if (id.includes('element-plus')) {
                        return 'vendor-element-core';
                    }

                    if (id.includes('vue') || id.includes('pinia') || id.includes('vue-router')) {
                        return 'vendor-vue-core';
                    }

                    if (id.includes('axios')) {
                        return 'vendor-axios';
                    }

                    if (id.includes('@element-plus/icons-vue')) {
                        return 'vendor-element-icons';
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
