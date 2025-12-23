<template>
    <div>
        <h2>账号设置</h2>
        <el-card>
            <el-form :model="form" :rules="rules" ref="formRef" label-width="120px">
                <el-form-item label="当前密码" prop="old_password">
                    <el-input v-model="form.old_password" type="password" show-password />
                </el-form-item>
                <el-form-item label="新密码" prop="new_password">
                    <el-input v-model="form.new_password" type="password" show-password />
                </el-form-item>
                <el-form-item label="确认新密码" prop="new_password_confirmation">
                    <el-input v-model="form.new_password_confirmation" type="password" show-password />
                </el-form-item>
                <el-form-item>
                    <el-button type="primary" :loading="loading" @click="handleChangePassword">
                        修改密码
                    </el-button>
                </el-form-item>
            </el-form>
        </el-card>
    </div>
</template>

<script setup>
import { ref, reactive } from 'vue';
import axios from '../../utils/axios';
import { ElMessage } from 'element-plus';

const formRef = ref(null);
const loading = ref(false);

const form = reactive({
    old_password: '',
    new_password: '',
    new_password_confirmation: ''
});

const rules = {
    old_password: [
        { required: true, message: '请输入当前密码', trigger: 'blur' }
    ],
    new_password: [
        { required: true, message: '请输入新密码', trigger: 'blur' },
        { min: 8, message: '密码长度至少8位', trigger: 'blur' }
    ],
    new_password_confirmation: [
        { required: true, message: '请确认新密码', trigger: 'blur' },
        {
            validator: (rule, value, callback) => {
                if (value !== form.new_password) {
                    callback(new Error('两次输入的密码不一致'));
                } else {
                    callback();
                }
            },
            trigger: 'blur'
        }
    ]
};

const handleChangePassword = async () => {
    if (!formRef.value) return;
    
    await formRef.value.validate(async (valid) => {
        if (valid) {
            loading.value = true;
            try {
                await axios.post('/auth/change-password', form);
                ElMessage.success('密码修改成功');
                form.old_password = '';
                form.new_password = '';
                form.new_password_confirmation = '';
            } catch (error) {
                ElMessage.error(error.response?.data?.message || '密码修改失败');
            } finally {
                loading.value = false;
            }
        }
    });
};
</script>

