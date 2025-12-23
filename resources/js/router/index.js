import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const routes = [
    {
        path: '/login',
        name: 'Login',
        component: () => import('../views/Login.vue'),
        meta: { requiresAuth: false }
    },
    {
        path: '/',
        redirect: '/orders'
    },
    {
        path: '/orders',
        name: 'Orders',
        component: () => import('../views/Orders/Index.vue'),
        meta: { requiresAuth: true }
    },
    {
        path: '/exception-orders',
        name: 'ExceptionOrders',
        component: () => import('../views/ExceptionOrders/Index.vue'),
        meta: { requiresAuth: true }
    },
    {
        path: '/products',
        name: 'Products',
        component: () => import('../views/Products/Index.vue'),
        meta: { requiresAuth: true }
    },
    {
        path: '/products/:id/detail',
        name: 'ProductDetail',
        component: () => import('../views/Products/Detail.vue'),
        meta: { requiresAuth: true }
    },
    {
        path: '/hotels',
        name: 'Hotels',
        component: () => import('../views/Hotels/Index.vue'),
        meta: { requiresAuth: true }
    },
    {
        path: '/scenic-spots',
        name: 'ScenicSpots',
        component: () => import('../views/ScenicSpots/Index.vue'),
        meta: { requiresAuth: true, requiresAdmin: true }
    },
    {
        path: '/users',
        name: 'Users',
        component: () => import('../views/Users/Index.vue'),
        meta: { requiresAuth: true, requiresAdmin: true }
    },
    {
        path: '/profile',
        name: 'Profile',
        component: () => import('../views/Profile/Index.vue'),
        meta: { requiresAuth: true }
    }
];

const router = createRouter({
    history: createWebHistory(),
    routes
});

router.beforeEach(async (to, from, next) => {
    const authStore = useAuthStore();
    
    // 如果已登录但没有用户信息，先获取用户信息
    if (authStore.isAuthenticated && !authStore.user) {
        try {
            await authStore.fetchUser();
        } catch (error) {
            console.error('Failed to fetch user info:', error);
        }
    }
    
    if (to.meta.requiresAuth && !authStore.isAuthenticated) {
        next('/login');
    } else if (to.meta.requiresAdmin) {
        const role = authStore.user?.role;
        const isAdmin = role === 'admin' || role?.value === 'admin' || role === 'ADMIN';
        if (!isAdmin) {
            next('/');
        } else {
            next();
        }
    } else {
        next();
    }
});

export default router;

