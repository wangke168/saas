<template>
    <div>
        <h2>用户管理</h2>
        <el-card>
            <div style="margin-bottom: 20px;">
                <el-button type="primary" @click="handleCreate">创建用户</el-button>
                <el-select
                    v-model="filterRole"
                    placeholder="筛选角色"
                    clearable
                    style="width: 150px; margin-left: 10px;"
                    @change="handleFilter"
                >
                    <el-option label="超级管理员" value="admin" />
                    <el-option label="运营" value="operator" />
                </el-select>
                <el-select
                    v-model="filterStatus"
                    placeholder="筛选状态"
                    clearable
                    style="width: 150px; margin-left: 10px;"
                    @change="handleFilter"
                >
                    <el-option label="启用" :value="true" />
                    <el-option label="禁用" :value="false" />
                </el-select>
                <el-input
                    v-model="searchKeyword"
                    placeholder="搜索姓名或邮箱"
                    style="width: 300px; margin-left: 10px;"
                    clearable
                    @input="handleSearch"
                >
                    <template #prefix>
                        <el-icon><Search /></el-icon>
                    </template>
                </el-input>
            </div>
            
            <el-table :data="users" v-loading="loading" border>
                <el-table-column prop="name" label="姓名" width="150" />
                <el-table-column prop="email" label="邮箱" width="200" />
                <el-table-column prop="role" label="角色" width="120">
                    <template #default="{ row }">
                        <el-tag :type="row.role === 'admin' ? 'danger' : 'primary'">
                            {{ row.role === 'admin' ? '超级管理员' : '运营' }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="绑定资源方" width="200">
                    <template #default="{ row }">
                        <el-tag
                            v-for="rp in (row.resource_providers || [])"
                            :key="rp.id"
                            size="small"
                            style="margin-right: 5px; margin-bottom: 5px;"
                        >
                            {{ rp.name }}
                        </el-tag>
                        <span v-if="!row.resource_providers || row.resource_providers.length === 0" style="color: #909399;">
                            {{ row.role === 'admin' ? '全部资源方' : '未绑定' }}
                        </span>
                    </template>
                </el-table-column>
                <el-table-column prop="is_active" label="状态" width="100">
                    <template #default="{ row }">
                        <el-tag :type="row.is_active ? 'success' : 'danger'">
                            {{ row.is_active ? '启用' : '禁用' }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="created_at" label="创建时间" width="180">
                    <template #default="{ row }">
                        {{ formatDate(row.created_at) }}
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="250" fixed="right">
                    <template #default="{ row }">
                        <el-button size="small" @click="handleEdit(row)">编辑</el-button>
                        <el-button 
                            v-if="row.is_active && row.role !== 'admin'" 
                            size="small" 
                            type="warning" 
                            @click="handleDisable(row)"
                        >
                            禁用
                        </el-button>
                        <el-button 
                            v-else-if="!row.is_active" 
                            size="small" 
                            type="success" 
                            @click="handleEnable(row)"
                        >
                            启用
                        </el-button>
                    </template>
                </el-table-column>
            </el-table>

            <el-pagination
                v-model:current-page="currentPage"
                v-model:page-size="pageSize"
                :page-sizes="[10, 20, 50, 100]"
                :total="total"
                layout="total, sizes, prev, pager, next, jumper"
                style="margin-top: 20px;"
                @size-change="fetchUsers"
                @current-change="fetchUsers"
            />
        </el-card>

        <!-- 创建/编辑用户对话框 -->
        <el-dialog
            v-model="dialogVisible"
            :title="dialogTitle"
            width="600px"
            @close="resetForm"
        >
            <el-form
                ref="formRef"
                :model="form"
                :rules="rules"
                label-width="120px"
            >
                <el-form-item label="姓名" prop="name">
                    <el-input v-model="form.name" placeholder="请输入姓名" />
                </el-form-item>
                <el-form-item label="邮箱" prop="email">
                    <el-input v-model="form.email" placeholder="请输入邮箱" :disabled="isEdit" />
                </el-form-item>
                <el-form-item label="密码" :prop="isEdit ? '' : 'password'">
                    <el-input
                        v-model="form.password"
                        type="password"
                        :placeholder="isEdit ? '留空则不修改密码' : '请输入密码（至少8位）'"
                        show-password
                    />
                </el-form-item>
                <el-form-item label="角色" prop="role">
                    <el-select
                        v-model="form.role"
                        placeholder="请选择角色"
                        style="width: 100%"
                        :disabled="isEdit && form.role === 'admin'"
                    >
                        <el-option label="超级管理员" value="admin" />
                        <el-option label="运营" value="operator" />
                    </el-select>
                    <span v-if="isEdit && form.role === 'admin'" style="margin-left: 10px; color: #909399; font-size: 12px;">
                        不能修改超级管理员角色
                    </span>
                </el-form-item>
                <el-form-item 
                    v-if="form.role === 'operator'"
                    label="绑定资源方" 
                    prop="resource_provider_ids"
                >
                    <el-select
                        v-model="form.resource_provider_ids"
                        placeholder="请选择绑定的资源方（可多选）"
                        multiple
                        style="width: 100%"
                    >
                        <el-option
                            v-for="rp in resourceProviders"
                            :key="rp.id"
                            :label="rp.name"
                            :value="rp.id"
                        />
                    </el-select>
                    <span style="margin-left: 10px; color: #909399; font-size: 12px;">
                        运营用户必须绑定至少一个资源方
                    </span>
                </el-form-item>
                <el-form-item label="状态" prop="is_active">
                    <el-switch v-model="form.is_active" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="dialogVisible = false">取消</el-button>
                <el-button type="primary" @click="handleSubmit" :loading="submitting">确定</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';
import axios from '../../utils/axios';
import { ElMessage, ElMessageBox } from 'element-plus';
import { Search } from '@element-plus/icons-vue';

const users = ref([]);
const resourceProviders = ref([]);
const loading = ref(false);
const submitting = ref(false);
const dialogVisible = ref(false);
const formRef = ref(null);
const searchKeyword = ref('');
const filterRole = ref(null);
const filterStatus = ref(null);
const currentPage = ref(1);
const pageSize = ref(15);
const total = ref(0);
const editingId = ref(null);
const editingUserRole = ref(null);

const isEdit = computed(() => editingId.value !== null);
const dialogTitle = computed(() => isEdit.value ? '编辑用户' : '创建用户');

const form = ref({
    name: '',
    email: '',
    password: '',
    role: 'operator',
    resource_provider_ids: [],
    is_active: true,
});

const rules = {
    name: [
        { required: true, message: '请输入姓名', trigger: 'blur' },
        { max: 255, message: '姓名不能超过255个字符', trigger: 'blur' }
    ],
    email: [
        { required: true, message: '请输入邮箱', trigger: 'blur' },
        { type: 'email', message: '请输入正确的邮箱格式', trigger: 'blur' }
    ],
    password: [
        { required: true, message: '请输入密码', trigger: 'blur' },
        { min: 8, message: '密码至少8位', trigger: 'blur' }
    ],
    role: [
        { required: true, message: '请选择角色', trigger: 'change' }
    ],
    resource_provider_ids: [
        {
            validator: (rule, value, callback) => {
                if (form.value.role === 'operator' && (!value || value.length === 0)) {
                    callback(new Error('运营用户必须绑定至少一个资源方'));
                } else {
                    callback();
                }
            },
            trigger: 'change'
        }
    ],
};

const fetchUsers = async () => {
    loading.value = true;
    try {
        const params = {
            page: currentPage.value,
            per_page: pageSize.value,
        };
        
        if (filterRole.value) {
            params.role = filterRole.value;
        }
        
        if (filterStatus.value !== null) {
            params.is_active = filterStatus.value;
        }
        
        if (searchKeyword.value) {
            params.search = searchKeyword.value;
        }
        
        const response = await axios.get('/users', { params });
        // Laravel 分页器返回的数据结构：{ data: [...], total: ..., per_page: ..., current_page: ... }
        users.value = response.data.data || [];
        total.value = response.data.total || 0;
        
        // 调试日志：检查资源方数据（开发环境）
        // 注释掉环境变量检查，避免在浏览器中出错
        // if (users.value.length > 0) {
        //     console.log('用户列表数据示例:', users.value[0]);
        //     console.log('用户资源方数据:', users.value[0]?.resource_providers);
        // }
    } catch (error) {
        ElMessage.error('获取用户列表失败');
        console.error(error);
    } finally {
        loading.value = false;
    }
};

const fetchResourceProviders = async () => {
    try {
        // 获取所有资源方（不分页），用于下拉选择
        const response = await axios.get('/resource-providers', {
            params: {
                per_page: 1000, // 获取所有资源方
                is_active: true, // 只获取启用的资源方
            }
        });
        // Laravel 分页器返回的数据结构：{ data: [...], total: ..., per_page: ..., current_page: ... }
        resourceProviders.value = response.data.data || [];
    } catch (error) {
        console.error('获取资源方列表失败', error);
        ElMessage.error('获取资源方列表失败');
    }
};

const handleSearch = () => {
    currentPage.value = 1;
    fetchUsers();
};

const handleFilter = () => {
    currentPage.value = 1;
    fetchUsers();
};

const handleCreate = () => {
    editingId.value = null;
    resetForm();
    dialogVisible.value = true;
};

const handleEdit = (row) => {
    editingId.value = row.id;
    editingUserRole.value = row.role;
    form.value = {
        name: row.name,
        email: row.email,
        password: '',
        role: row.role,
        resource_provider_ids: row.resource_providers?.map(rp => rp.id) || [],
        is_active: row.is_active,
    };
    dialogVisible.value = true;
};

const handleSubmit = async () => {
    if (!formRef.value) return;
    
    await formRef.value.validate(async (valid) => {
        if (valid) {
            submitting.value = true;
            try {
                const data = {
                    ...form.value,
                };
                
                // 编辑时，如果密码为空则不传密码字段
                if (isEdit.value && !data.password) {
                    delete data.password;
                }
                
                // 超级管理员不需要绑定资源方
                if (data.role === 'admin') {
                    data.resource_provider_ids = [];
                } else {
                    // 确保运营用户传递 resource_provider_ids（即使是空数组也要传递）
                    if (!data.resource_provider_ids) {
                        data.resource_provider_ids = [];
                    }
                }
                
                // 调试日志
                console.log('提交用户数据:', data);
                
                if (isEdit.value) {
                    await axios.put(`/users/${editingId.value}`, data);
                    ElMessage.success('用户更新成功');
                } else {
                    await axios.post('/users', data);
                    ElMessage.success('用户创建成功');
                }
                dialogVisible.value = false;
                fetchUsers();
            } catch (error) {
                const message = error.response?.data?.message || error.response?.data?.errors?.email?.[0] || '操作失败';
                ElMessage.error(message);
            } finally {
                submitting.value = false;
            }
        }
    });
};

const handleDisable = async (row) => {
    try {
        await ElMessageBox.confirm(
            `确定要禁用用户"${row.name}"吗？禁用后该用户将无法登录系统。`,
            '提示',
            {
                type: 'warning',
                confirmButtonText: '确定禁用',
                cancelButtonText: '取消'
            }
        );
        
        await axios.post(`/users/${row.id}/disable`);
        ElMessage.success('用户已禁用');
        fetchUsers();
    } catch (error) {
        if (error !== 'cancel') {
            const message = error.response?.data?.message || '操作失败';
            ElMessage.error(message);
        }
    }
};

const handleEnable = async (row) => {
    try {
        await axios.post(`/users/${row.id}/enable`);
        ElMessage.success('用户已启用');
        fetchUsers();
    } catch (error) {
        ElMessage.error('操作失败');
        console.error(error);
    }
};

const resetForm = () => {
    editingId.value = null;
    editingUserRole.value = null;
    form.value = {
        name: '',
        email: '',
        password: '',
        role: 'operator',
        resource_provider_ids: [], // 改为 resource_provider_ids
        is_active: true,
    };
    formRef.value?.clearValidate();
};

const formatDate = (date) => {
    if (!date) return '';
    return new Date(date).toLocaleString('zh-CN', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
};

onMounted(() => {
    fetchUsers();
    fetchResourceProviders();
});
</script>

<style scoped>
h2 {
    margin-bottom: 20px;
}
</style>


const formatDate = (date) => {
    if (!date) return '';
    return new Date(date).toLocaleString('zh-CN', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
};

onMounted(() => {
    fetchUsers();
    fetchResourceProviders();
});
</script>

<style scoped>
h2 {
    margin-bottom: 20px;
}
</style>


const formatDate = (date) => {
    if (!date) return '';
    return new Date(date).toLocaleString('zh-CN', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
};

onMounted(() => {
    fetchUsers();
    fetchResourceProviders();
});
</script>

<style scoped>
h2 {
    margin-bottom: 20px;
}
</style>


const formatDate = (date) => {
    if (!date) return '';
    return new Date(date).toLocaleString('zh-CN', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
};

onMounted(() => {
    fetchUsers();
    fetchResourceProviders();
});
</script>

<style scoped>
h2 {
    margin-bottom: 20px;
}
</style>