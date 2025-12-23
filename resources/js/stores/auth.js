import { defineStore } from 'pinia';
import { ref } from 'vue';
import axios from '../utils/axios';

export const useAuthStore = defineStore('auth', () => {
    const user = ref(null);
    const token = ref(localStorage.getItem('token'));

    const isAuthenticated = ref(!!token.value);

    // 设置axios默认token
    if (token.value) {
        axios.defaults.headers.common['Authorization'] = `Bearer ${token.value}`;
    }

    const login = async (email, password) => {
        try {
            const response = await axios.post('/auth/login', { email, password });
            token.value = response.data.token;
            user.value = response.data.user;
            isAuthenticated.value = true;
            localStorage.setItem('token', token.value);
            axios.defaults.headers.common['Authorization'] = `Bearer ${token.value}`;
            return response.data;
        } catch (error) {
            throw error;
        }
    };

    const logout = async () => {
        try {
            await axios.post('/auth/logout');
        } catch (error) {
            console.error('Logout error:', error);
        } finally {
            token.value = null;
            user.value = null;
            isAuthenticated.value = false;
            localStorage.removeItem('token');
            delete axios.defaults.headers.common['Authorization'];
        }
    };

    const fetchUser = async () => {
        try {
            const response = await axios.get('/auth/me');
            user.value = response.data.user;
            return response.data;
        } catch (error) {
            await logout();
            throw error;
        }
    };

    return {
        user,
        token,
        isAuthenticated,
        login,
        logout,
        fetchUser
    };
});

