<template>
    <el-container>
        <el-header v-if="isAuthenticated">
            <div class="header-content">
                <h1>OTA酒景套餐分销系统</h1>
                <div class="user-info">
                    <span>{{ user?.display_name || user?.name }}</span>
                    <el-button type="text" @click="handleLogout">退出</el-button>
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
                    <el-menu-item index="/orders">
                        <el-icon><Document /></el-icon>
                        <span>订单管理</span>
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
                    <el-menu-item v-if="isAdmin" index="/resource-providers">
                        <el-icon><Connection /></el-icon>
                        <span>资源方管理</span>
                    </el-menu-item>
                    <el-menu-item v-if="isAdmin" index="/scenic-spots">
                        <el-icon><Location /></el-icon>
                        <span>景区管理</span>
                    </el-menu-item>
                    <el-menu-item v-if="isAdmin" index="/users">
                        <el-icon><User /></el-icon>
                        <span>用户管理</span>
                    </el-menu-item>
                    <el-menu-item v-if="isAdmin" index="/software-providers">
                        <el-icon><Connection /></el-icon>
                        <span>软件服务商</span>
                    </el-menu-item>
                    <el-menu-item v-if="isAdmin" index="/ota-platforms">
                        <el-icon><DataLine /></el-icon>
                        <span>OTA平台管理</span>
                    </el-menu-item>
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
import { Document, Warning, Box, House, Location, User, Setting, Connection, DataLine } from '@element-plus/icons-vue';

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
.header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    height: 100%;
}

.user-info {
    display: flex;
    align-items: center;
    gap: 10px;
}
</style>

