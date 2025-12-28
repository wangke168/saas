<template>
    <div>
        <h2>OTA平台管理</h2>
        <el-card>
            <div style="margin-bottom: 20px;">
                <el-button type="primary" @click="handleCreate">创建OTA平台</el-button>
                <el-input
                    v-model="searchKeyword"
                    placeholder="搜索平台名称或编码"
                    style="width: 300px; margin-left: 10px;"
                    clearable
                    @input="handleSearch"
                >
                    <template #prefix>
                        <el-icon><Search /></el-icon>
                    </template>
                </el-input>
                <el-select
                    v-model="statusFilter"
                    placeholder="状态筛选"
                    clearable
                    style="width: 150px; margin-left: 10px;"
                    @change="fetchOtaPlatforms"
                >
                    <el-option label="全部" value="" />
                    <el-option label="启用" :value="true" />
                    <el-option label="禁用" :value="false" />
                </el-select>
            </div>
            
            <el-table :data="otaPlatforms" v-loading="loading" border>
                <el-table-column prop="name" label="平台名称" width="150" />
                <el-table-column prop="code" label="平台编码" width="150" />
                <el-table-column prop="description" label="描述" show-overflow-tooltip />
                <el-table-column prop="is_active" label="状态" width="100">
                    <template #default="{ row }">
                        <el-tag :type="row.is_active ? 'success' : 'danger'">
                            {{ row.is_active ? '启用' : '禁用' }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="配置状态" width="120">
                    <template #default="{ row }">
                        <el-tag v-if="row.config" type="success">已配置</el-tag>
                        <el-tag v-else type="warning">未配置</el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="环境" width="100">
                    <template #default="{ row }">
                        <el-tag v-if="row.config" :type="row.config.environment === 'production' ? 'success' : 'info'">
                            {{ row.config.environment === 'production' ? '生产' : '测试' }}
                        </el-tag>
                        <span v-else>-</span>
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="300" fixed="right">
                    <template #default="{ row }">
                        <el-button size="small" @click="handleConfig(row)">配置</el-button>
                        <el-button size="small" @click="handleEdit(row)">编辑</el-button>
                        <el-button size="small" type="danger" @click="handleDelete(row)">删除</el-button>
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
                @size-change="fetchOtaPlatforms"
                @current-change="fetchOtaPlatforms"
            />
        </el-card>

        <!-- 创建/编辑对话框 -->
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
                <el-form-item label="平台名称" prop="name">
                    <el-input v-model="form.name" placeholder="请输入平台名称" />
                </el-form-item>
                <el-form-item label="平台编码" prop="code">
                    <el-select
                        v-model="form.code"
                        placeholder="请选择平台编码"
                        style="width: 100%"
                        :disabled="isEdit"
                    >
                        <el-option label="携程" value="ctrip" />
                        <el-option label="飞猪" value="fliggy" />
                        <el-option label="美团" value="meituan" />
                    </el-select>
                </el-form-item>
                <el-form-item label="描述" prop="description">
                    <el-input
                        v-model="form.description"
                        type="textarea"
                        :rows="4"
                        placeholder="请输入平台描述"
                    />
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

        <!-- 配置对话框 -->
        <el-dialog
            v-model="configDialogVisible"
            title="OTA平台配置"
            width="700px"
            @close="resetConfigForm"
        >
            <el-form
                ref="configFormRef"
                :model="configForm"
                :rules="configRules"
                label-width="140px"
            >
                <el-form-item label="Account/AppKey" prop="account">
                    <el-input v-model="configForm.account" placeholder="请输入Account或AppKey" />
                </el-form-item>
                <el-form-item label="Secret Key/PartnerId" prop="secret_key">
                    <el-input v-model="configForm.secret_key" type="password" show-password placeholder="请输入Secret Key或PartnerId" />
                </el-form-item>
                <el-form-item label="AES Key" prop="aes_key">
                    <el-input v-model="configForm.aes_key" type="password" show-password placeholder="请输入AES Key（16字节）" />
                </el-form-item>
                <el-form-item label="AES IV" prop="aes_iv">
                    <el-input v-model="configForm.aes_iv" placeholder="请输入AES IV（可选，携程需要）" />
                </el-form-item>
                <el-form-item label="API URL" prop="api_url">
                    <el-input v-model="configForm.api_url" placeholder="请输入API地址" />
                </el-form-item>
                <el-form-item label="Callback URL" prop="callback_url">
                    <el-input v-model="configForm.callback_url" placeholder="请输入回调地址" />
                </el-form-item>
                <el-form-item label="环境" prop="environment">
                    <el-radio-group v-model="configForm.environment">
                        <el-radio label="sandbox">测试环境</el-radio>
                        <el-radio label="production">生产环境</el-radio>
                    </el-radio-group>
                </el-form-item>
                <el-form-item label="状态" prop="is_active">
                    <el-switch v-model="configForm.is_active" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="configDialogVisible = false">取消</el-button>
                <el-button type="primary" @click="handleSubmitConfig" :loading="configSubmitting">确定</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { Search } from '@element-plus/icons-vue';
import axios from '../../utils/axios';

const loading = ref(false);
const otaPlatforms = ref([]);
const searchKeyword = ref('');
const statusFilter = ref(null);
const currentPage = ref(1);
const pageSize = ref(15);
const total = ref(0);

// 创建/编辑相关
const dialogVisible = ref(false);
const dialogTitle = ref('创建OTA平台');
const submitting = ref(false);
const formRef = ref(null);
const isEdit = ref(false);
const editingId = ref(null);
const form = ref({
    name: '',
    code: '',
    description: '',
    is_active: true,
});

// 配置相关
const configDialogVisible = ref(false);
const configSubmitting = ref(false);
const configFormRef = ref(null);
const currentPlatform = ref(null);
const configForm = ref({
    account: '',
    secret_key: '',
    aes_key: '',
    aes_iv: '',
    rsa_private_key: '',
    rsa_public_key: '',
    api_url: '',
    callback_url: '',
    environment: 'sandbox',
    is_active: true,
});

const rules = {
    name: [{ required: true, message: '请输入平台名称', trigger: 'blur' }],
    code: [{ required: true, message: '请选择平台编码', trigger: 'change' }],
};

const configRules = {
    account: [{ required: true, message: '请输入Account/AppKey', trigger: 'blur' }],
    secret_key: [{ required: true, message: '请输入Secret Key/PartnerId', trigger: 'blur' }],
    api_url: [{ required: true, message: '请输入API URL', trigger: 'blur' }],
    environment: [{ required: true, message: '请选择环境', trigger: 'change' }],
};

const fetchOtaPlatforms = async () => {
    loading.value = true;
    try {
        const params = {
            page: currentPage.value,
            per_page: pageSize.value,
        };
        if (searchKeyword.value) {
            params.search = searchKeyword.value;
        }
        if (statusFilter.value !== null) {
            params.is_active = statusFilter.value;
        }
        const response = await axios.get('/admin/ota-platforms', { params });
        otaPlatforms.value = response.data.data || [];
        total.value = response.data.meta?.total || 0;
    } catch (error) {
        console.error('获取OTA平台列表失败:', error);
        const errorMessage = error.response?.data?.message || error.message || '获取OTA平台列表失败';
        ElMessage.error(errorMessage);
    } finally {
        loading.value = false;
    }
};

const handleSearch = () => {
    currentPage.value = 1;
    fetchOtaPlatforms();
};

const handleCreate = () => {
    isEdit.value = false;
    editingId.value = null;
    dialogTitle.value = '创建OTA平台';
    dialogVisible.value = true;
};

const handleEdit = (row) => {
    isEdit.value = true;
    editingId.value = row.id;
    dialogTitle.value = '编辑OTA平台';
    form.value = {
        name: row.name,
        code: row.code,
        description: row.description || '',
        is_active: row.is_active,
    };
    dialogVisible.value = true;
};

const handleDelete = async (row) => {
    try {
        await ElMessageBox.confirm(
            `确定要删除平台"${row.name}"吗？`,
            '删除确认',
            {
                type: 'warning',
            }
        );
        await axios.delete(`/admin/ota-platforms/${row.id}`);
        ElMessage.success('删除成功');
        fetchOtaPlatforms();
    } catch (error) {
        if (error !== 'cancel') {
            ElMessage.error(error.response?.data?.message || '删除失败');
        }
    }
};

const handleSubmit = async () => {
    if (!formRef.value) return;
    await formRef.value.validate(async (valid) => {
        if (!valid) return;
        submitting.value = true;
        try {
            if (isEdit.value) {
                await axios.put(`/admin/ota-platforms/${editingId.value}`, form.value);
                ElMessage.success('更新成功');
            } else {
                await axios.post('/admin/ota-platforms', form.value);
                ElMessage.success('创建成功');
            }
            dialogVisible.value = false;
            fetchOtaPlatforms();
        } catch (error) {
            ElMessage.error(error.response?.data?.message || '操作失败');
        } finally {
            submitting.value = false;
        }
    });
};

const handleConfig = async (row) => {
    currentPlatform.value = row;
    try {
        // 获取现有配置
        const response = await axios.get(`/admin/ota-platforms/${row.id}/config`);
        if (response.data.data) {
            const config = response.data.data;
            configForm.value = {
                account: config.account || '',
                secret_key: config.secret_key || '',
                aes_key: config.aes_key || '',
                aes_iv: config.aes_iv || '',
                rsa_private_key: config.rsa_private_key || '',
                rsa_public_key: config.rsa_public_key || '',
                api_url: config.api_url || '',
                callback_url: config.callback_url || '',
                environment: config.environment || 'sandbox',
                is_active: config.is_active ?? true,
            };
        } else {
            // 没有配置，使用默认值
            resetConfigForm();
        }
        configDialogVisible.value = true;
    } catch (error) {
        // 如果没有配置，也显示对话框
        resetConfigForm();
        configDialogVisible.value = true;
    }
};

const handleSubmitConfig = async () => {
    if (!configFormRef.value) return;
    await configFormRef.value.validate(async (valid) => {
        if (!valid) return;
        configSubmitting.value = true;
        try {
            await axios.post(`/admin/ota-platforms/${currentPlatform.value.id}/config`, configForm.value);
            ElMessage.success('配置保存成功');
            configDialogVisible.value = false;
            fetchOtaPlatforms();
        } catch (error) {
            ElMessage.error(error.response?.data?.message || '保存配置失败');
        } finally {
            configSubmitting.value = false;
        }
    });
};

const resetForm = () => {
    form.value = {
        name: '',
        code: '',
        description: '',
        is_active: true,
    };
    formRef.value?.clearValidate();
};

const resetConfigForm = () => {
    configForm.value = {
        account: '',
        secret_key: '',
        aes_key: '',
        aes_iv: '',
        rsa_private_key: '',
        rsa_public_key: '',
        api_url: '',
        callback_url: '',
        environment: 'sandbox',
        is_active: true,
    };
    configFormRef.value?.clearValidate();
};

onMounted(() => {
    fetchOtaPlatforms();
});
</script>

