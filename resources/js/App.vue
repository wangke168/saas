<template>
    <el-container>
        <el-header v-if="isAuthenticated" class="app-header">
            <div class="header-content">
                <div class="brand">
                    <div class="brand-logo-ring" aria-hidden="true">
                        <img src="/logo.png" alt="" class="brand-logo-img" />
                    </div>
                    <h1 class="brand-title">酒速通</h1>
                </div>
                <div class="user-info">
                    <span class="user-name">{{ user?.display_name || user?.name }}</span>
                    <el-button text @click="handleLogout">退出</el-button>
                </div>
            </div>
        </el-header>
        <el-container v-if="isAuthenticated">
            <el-aside width="200px">
                <el-menu
                    :default-active="activeMenu"
                    router
                    mode="vertical"
                >
                    <el-menu-item index="/operation-report">
                        <el-icon><DataBoard /></el-icon>
                        <span>运营快报</span>
                    </el-menu-item>
                    <el-menu-item index="/orders">
                        <el-icon><Document /></el-icon>
                        <span>订单管理</span>
                    </el-menu-item>
                    <el-menu-item index="/pkg-orders">
                        <el-icon><Document /></el-icon>
                        <span>打包订单</span>
                    </el-menu-item>
                    <el-menu-item index="/exception-orders">
                        <el-icon><Warning /></el-icon>
                        <span>异常订单</span>
                    </el-menu-item>

                    <el-menu-item index="/products">
                        <el-icon><Box /></el-icon>
                        <span>产品管理</span>
                    </el-menu-item>
                    <el-menu-item index="/hotels">
                        <el-icon><House /></el-icon>
                        <span>酒店管理</span>
                    </el-menu-item>
                    <el-sub-menu v-if="isAdmin" index="pkg-settings">
                        <template #title>
                            <el-icon><Goods /></el-icon>
                            <span>打包产品</span>
                        </template>
                    <el-menu-item index="/pkg-products">
                        <el-icon><Goods /></el-icon>
                        <span>打包产品管理</span>
                    </el-menu-item>
                    <el-menu-item index="/tickets">
                        <el-icon><Ticket /></el-icon>
                        <span>门票管理</span>
                    </el-menu-item>
                    <el-menu-item index="/res-hotels">
                        <el-icon><OfficeBuilding /></el-icon>
                        <span>打包酒店管理</span>
                    </el-menu-item>
                    </el-sub-menu>
                    <el-sub-menu v-if="isAdmin" index="other-settings">
                        <template #title>
                            <el-icon><Tools /></el-icon>
                            <span>其他设置</span>
                        </template>
                        <el-menu-item index="/resource-providers">
                            <el-icon><Connection /></el-icon>
                            <span>资源方管理</span>
                        </el-menu-item>
                        <el-menu-item index="/scenic-spots">
                            <el-icon><Location /></el-icon>
                            <span>景区管理</span>
                        </el-menu-item>
                        <el-menu-item index="/software-providers">
                            <el-icon><Connection /></el-icon>
                            <span>软件服务商管理</span>
                        </el-menu-item>
                        <el-menu-item index="/ota-platforms">
                            <el-icon><DataLine /></el-icon>
                            <span>OTA平台管理</span>
                        </el-menu-item>
                        <el-menu-item index="/users">
                            <el-icon><User /></el-icon>
                            <span>用户管理</span>
                        </el-menu-item>
                    </el-sub-menu>
                    <el-menu-item index="/profile">
                        <el-icon><Setting /></el-icon>
                        <span>账号设置</span>
                    </el-menu-item>
                </el-menu>
            </el-aside>
            <el-main>
                <router-view />
            </el-main>
        </el-container>
        <el-main v-else>
            <router-view />
        </el-main>
    </el-container>
</template>

<script setup>
import { computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from './stores/auth';
import { Document, Warning, Box, House, Location, User, Setting, Connection, DataLine, Ticket, OfficeBuilding, Goods, Tools, DataBoard } from '@element-plus/icons-vue';

const router = useRouter();
const authStore = useAuthStore();

// 登录后自动获取用户信息
onMounted(async () => {
    if (authStore.isAuthenticated && !authStore.user) {
        try {
            await authStore.fetchUser();
        } catch (error) {
            console.error('Failed to fetch user info:', error);
        }
    }
});

const isAuthenticated = computed(() => authStore.isAuthenticated);
const user = computed(() => authStore.user);
const isAdmin = computed(() => {
    const role = user.value?.role;
    // 兼容不同的 role 格式：可能是字符串 'admin'，也可能是对象 { value: 'admin' }
    const result = role === 'admin' || role?.value === 'admin' || role === 'ADMIN';
    return result;
});
const activeMenu = computed(() => router.currentRoute.value.path);

const handleLogout = async () => {
    await authStore.logout();
    router.push('/login');
};
</script>

<style scoped>
.app-header {
    --app-header-bg-top: #2c3f5c;
    --app-header-bg-bottom: #1a2332;
    background: linear-gradient(180deg, var(--app-header-bg-top) 0%, var(--app-header-bg-bottom) 100%);
    color: #e8edf4;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.18);
}

.app-header :deep(.el-button.is-text) {
    color: #c5d0e0;
}

.app-header :deep(.el-button.is-text:hover) {
    color: #fff;
    background-color: rgba(255, 255, 255, 0.1);
}

.header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    height: 100%;
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

/* 圆形浅色底，衬托深色 logo */
.brand-logo-ring {
    flex-shrink: 0;
    width: 35px;
    height: 35px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: radial-gradient(circle at 30% 25%, #ffffff 0%, #e8eef5 55%, #d8e0ea 100%);
    box-shadow:
        0 1px 2px rgba(0, 0, 0, 0.2),
        inset 0 1px 0 rgba(255, 255, 255, 0.85);
    overflow: hidden;
}

.brand-logo-img {
    width: 28px;
    height: 28px;
    object-fit: contain;
    display: block;
}

.brand-title {
    margin: 0;
    font-size: 1.2rem;
    font-weight: 600;
    color: #f4f7fb;
    letter-spacing: 0.03em;
    white-space: nowrap;
}

.user-info {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.user-name {
    color: #d1dae8;
    font-size: 14px;
    max-width: min(420px, 50vw);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
</style>

