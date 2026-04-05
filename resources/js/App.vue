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
                    <el-dropdown
                        trigger="hover"
                        :show-timeout="80"
                        :hide-timeout="200"
                        popper-class="app-header-user-dropdown"
                        @command="onUserMenuCommand"
                    >
                        <span
                            class="user-summary-trigger"
                            tabindex="0"
                            :title="headerUserSummary"
                        >
                            {{ headerUserSummary }}
                        </span>
                        <template #dropdown>
                            <el-dropdown-menu>
                                <el-dropdown-item command="logout">退出</el-dropdown-item>
                            </el-dropdown-menu>
                        </template>
                    </el-dropdown>
                </div>
            </div>
        </el-header>
        <el-container v-if="isAuthenticated">
            <el-aside width="200px">
                <el-menu
                    :default-active="activeMenu"
                    router
                    mode="vertical">
                    <el-menu-item index="/operation-report">
                        <el-icon><DataBoard /></el-icon>
                        <span>运营快报</span>
                    </el-menu-item>
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
                    <el-sub-menu v-if="isAdmin" index="pkg-settings">
                        <template #title>
                            <el-icon><Goods /></el-icon>
                            <span>打包产品</span>
                        </template>
                    <el-menu-item index="/pkg-orders">
                        <el-icon><Document /></el-icon>
                        <span>打包订单</span>
                    </el-menu-item>    
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

// 登录后拉取用户信息；无 user 或缺少 resource_providers（旧会话）时补全
onMounted(async () => {
    if (!authStore.isAuthenticated) {
        return;
    }
    const u = authStore.user;
    const needFetch = !u || u.resource_providers === undefined;
    if (!needFetch) {
        return;
    }
    try {
        await authStore.fetchUser();
    } catch (error) {
        console.error('Failed to fetch user info:', error);
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

/** 顶栏：账号姓名 | 绑定资源方（与用户管理列表语义一致） */
const headerUserSummary = computed(() => {
    const u = user.value;
    if (!u) {
        return '';
    }
    const accountName = u.name || '—';
    let resourcePart = '';
    if (isAdmin.value) {
        resourcePart = '全部资源方';
    } else {
        const rps = u.resource_providers || [];
        resourcePart =
            rps.length > 0 ? rps.map((rp) => rp.name).filter(Boolean).join('、') : '未绑定资源方';
    }
    return `${accountName} | ${resourcePart}`;
});

const activeMenu = computed(() => router.currentRoute.value.path);

const handleLogout = async () => {
    await authStore.logout();
    router.push('/login');
};

const onUserMenuCommand = (command) => {
    if (command === 'logout') {
        handleLogout();
    }
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
    min-width: 0;
}

.app-header :deep(.user-info .el-dropdown) {
    vertical-align: middle;
}

.user-summary-trigger {
    display: inline-block;
    max-width: min(520px, 56vw);
    padding: 6px 10px;
    margin: -6px -10px;
    border-radius: 6px;
    color: #d1dae8;
    font-size: 14px;
    line-height: 1.4;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    cursor: pointer;
    outline: none;
}

.user-summary-trigger:hover,
.user-summary-trigger:focus-visible {
    color: #f4f7fb;
    background-color: rgba(255, 255, 255, 0.08);
}
</style>

<style>
/* 下拉层挂到 body，需非 scoped */
.app-header-user-dropdown.el-popper {
    margin-top: 4px !important;
}
</style>

