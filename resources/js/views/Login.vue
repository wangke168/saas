<template>
    <div class="login-container">
        <el-card class="login-card">
            <h2>OTA酒景套餐分销系统</h2>
            <el-form :model="form" :rules="rules" ref="formRef" @submit.prevent="handleLogin">
                <el-form-item label="邮箱" prop="email">
                    <el-input v-model="form.email" type="email" />
                </el-form-item>
                <el-form-item label="密码" prop="password">
                    <el-input v-model="form.password" type="password" show-password />
                </el-form-item>
                <el-form-item>
                    <el-button type="primary" :loading="loading" @click="handleLogin" style="width: 100%">
                        登录
                    </el-button>
                </el-form-item>
            </el-form>
        </el-card>
    </div>
</template>

<script setup>
import { ref, reactive } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { ElMessage } from 'element-plus';

const router = useRouter();
const authStore = useAuthStore();

const formRef = ref(null);
const loading = ref(false);

const form = reactive({
    email: '',
    password: ''
});

const rules = {
    email: [
        { required: true, message: '请输入邮箱', trigger: 'blur' },
        { type: 'email', message: '请输入正确的邮箱格式', trigger: 'blur' }
    ],
    password: [
        { required: true, message: '请输入密码', trigger: 'blur' },
        { min: 8, message: '密码长度至少8位', trigger: 'blur' }
    ]
};

const handleLogin = () => {
    if (!formRef.value) return;
    
    formRef.value.validate(async (valid) => {
        if (!valid) return;
        
        loading.value = true;
        try {
            await authStore.login(form.email, form.password);
            ElMessage.success('登录成功');
            router.push('/');
        } catch (error) {
            ElMessage.error(error.response?.data?.message || '登录失败');
        } finally {
            loading.value = false;
        }
    });
};
</script>

<style scoped>
.login-container {
    display: flex;
    justify-content: center;
    align-items: center;
    height: 100vh;
    background: #f5f5f5;
}

.login-card {
    width: 400px;
}

h2 {
    text-align: center;
    margin-bottom: 30px;
}
</style>

