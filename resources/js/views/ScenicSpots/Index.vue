<template>
    <div>
        <h2>景区管理</h2>
        <el-card>
            <div style="margin-bottom: 20px;">
                <el-button type="primary" @click="handleCreate">创建景区</el-button>
                <el-input
                    v-model="searchKeyword"
                    placeholder="搜索景区名称或编码"
                    style="width: 300px; margin-left: 10px;"
                    clearable
                    @input="handleSearch"
                >
                    <template #prefix>
                        <el-icon><Search /></el-icon>
                    </template>
                </el-input>
            </div>
            
            <el-table :data="scenicSpots" v-loading="loading" border>
                <el-table-column prop="name" label="景区名称" width="200" />
                <el-table-column prop="code" label="景区编码" width="150" />
                <el-table-column prop="address" label="地址" show-overflow-tooltip />
                <el-table-column prop="contact_phone" label="联系电话" width="150" />
                <el-table-column label="软件服务商" width="150">
                    <template #default="{ row }">
                        {{ row.software_provider?.name || '-' }}
                    </template>
                </el-table-column>
                <el-table-column prop="is_active" label="状态" width="100">
                    <template #default="{ row }">
                        <el-tag :type="row.is_active ? 'success' : 'danger'">
                            {{ row.is_active ? '启用' : '禁用' }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="280" fixed="right">
                    <template #default="{ row }">
                        <el-button size="small" @click="handleEdit(row)">编辑</el-button>
                        <el-button size="small" type="info" @click="handleConfigResource(row)">配置资源方</el-button>
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
                @size-change="fetchScenicSpots"
                @current-change="fetchScenicSpots"
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
                <el-form-item label="景区名称" prop="name">
                    <el-input v-model="form.name" placeholder="请输入景区名称" />
                </el-form-item>
                <el-form-item label="景区编码" prop="code">
                    <el-input v-model="form.code" placeholder="请输入景区编码（唯一）" :disabled="isEdit" />
                </el-form-item>
                <el-form-item label="地址" prop="address">
                    <el-input v-model="form.address" placeholder="请输入景区地址" />
                </el-form-item>
                <el-form-item label="联系电话" prop="contact_phone">
                    <el-input v-model="form.contact_phone" placeholder="请输入联系电话" />
                </el-form-item>
                <el-form-item label="软件服务商" prop="software_provider_id">
                    <el-select
                        v-model="form.software_provider_id"
                        placeholder="请选择软件服务商"
                        clearable
                        style="width: 100%"
                    >
                        <el-option
                            v-for="provider in softwareProviders"
                            :key="provider.id"
                            :label="provider.name"
                            :value="provider.id"
                        />
                    </el-select>
                </el-form-item>
                <el-form-item label="描述" prop="description">
                    <el-input
                        v-model="form.description"
                        type="textarea"
                        :rows="4"
                        placeholder="请输入景区描述"
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

        <!-- 资源配置对话框 -->
        <el-dialog
            v-model="resourceConfigDialogVisible"
            title="资源配置"
            width="800px"
            @close="resetResourceConfigForm"
        >
            <el-alert
                title="重要提示"
                type="warning"
                description="不同OTA平台的订单需要使用不同的用户名和密码。请为每个OTA平台配置对应的认证信息。"
                :closable="false"
                style="margin-bottom: 20px;"
            />
            <el-form
                ref="resourceConfigFormRef"
                :model="resourceConfigForm"
                :rules="resourceConfigRules"
                label-width="140px"
            >
                <el-form-item label="接口地址" prop="api_url">
                    <el-input v-model="resourceConfigForm.api_url" placeholder="例如：https://e.hengdianworld.com/Interface/hotel_order.aspx" />
                </el-form-item>
                <el-form-item label="默认用户名" prop="username">
                    <el-input v-model="resourceConfigForm.username" placeholder="用于库存推送订阅等非订单场景" />
                </el-form-item>
                <el-form-item label="默认密码" prop="password">
                    <el-input v-model="resourceConfigForm.password" type="password" show-password placeholder="用于库存推送订阅等非订单场景" />
                </el-form-item>
                <el-form-item label="环境" prop="environment">
                    <el-select v-model="resourceConfigForm.environment" style="width: 100%">
                        <el-option label="生产环境" value="production" />
                    </el-select>
                </el-form-item>

                <el-divider>同步方式配置</el-divider>

                <el-form-item label="库存同步方式" prop="sync_mode.inventory">
                    <el-select v-model="resourceConfigForm.sync_mode.inventory" style="width: 100%">
                        <el-option label="资源方推送" value="push" />
                        <el-option label="手工维护" value="manual" />
                    </el-select>
                    <div style="font-size: 12px; color: #909399; margin-top: 5px;">
                        选择"资源方推送"后，系统将自动接收资源方推送的库存信息
                    </div>
                </el-form-item>

                <el-form-item label="价格同步方式" prop="sync_mode.price">
                    <el-select v-model="resourceConfigForm.sync_mode.price" style="width: 100%">
                        <el-option label="资源方推送" value="push" />
                        <el-option label="手工维护" value="manual" />
                    </el-select>
                    <div style="font-size: 12px; color: #909399; margin-top: 5px;">
                        选择"资源方推送"后，系统将自动接收资源方推送的价格信息
                    </div>
                </el-form-item>

                <el-form-item label="订单处理方式" prop="sync_mode.order">
                    <el-select v-model="resourceConfigForm.sync_mode.order" style="width: 100%">
                        <el-option label="系统直连" value="auto" />
                        <el-option label="手工操作" value="manual" />
                        <el-option label="其他系统" value="other" />
                    </el-select>
                    <div style="font-size: 12px; color: #909399; margin-top: 5px;">
                        选择"系统直连"后，订单将自动调用资源方接口；选择"其他系统"需要指定系统服务商
                    </div>
                </el-form-item>

                <el-form-item 
                    label="订单系统服务商" 
                    prop="order_provider"
                    v-if="resourceConfigForm.sync_mode.order === 'other'"
                >
                    <el-select v-model="resourceConfigForm.order_provider" placeholder="请选择系统服务商" style="width: 100%">
                        <el-option 
                            v-for="provider in softwareProviders" 
                            :key="provider.id" 
                            :label="provider.name" 
                            :value="provider.id" 
                        />
                    </el-select>
                </el-form-item>

                <el-divider>OTA平台认证信息</el-divider>

                <el-form-item 
                    v-for="otaPlatform in otaPlatforms" 
                    :key="otaPlatform.id"
                    :label="`${otaPlatform.name}认证信息`"
                >
                    <div style="display: flex; gap: 10px; width: 100%;">
                        <el-input
                            v-model="resourceConfigForm.credentials[otaPlatform.code].username"
                            placeholder="用户名"
                            style="flex: 1;"
                        />
                        <el-input
                            v-model="resourceConfigForm.credentials[otaPlatform.code].password"
                            type="password"
                            placeholder="密码"
                            style="flex: 1;"
                            show-password
                        />
                    </div>
                    <div style="font-size: 12px; color: #909399; margin-top: 5px;">
                        该平台订单将使用此认证信息调用资源方接口
                    </div>
                </el-form-item>

                <el-form-item label="状态" prop="is_active">
                    <el-switch v-model="resourceConfigForm.is_active" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="resourceConfigDialogVisible = false">取消</el-button>
                <el-button type="primary" @click="handleSubmitResourceConfig" :loading="resourceConfigSubmitting">保存</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';
import axios from '../../utils/axios';
import { ElMessage, ElMessageBox } from 'element-plus';
import { Search } from '@element-plus/icons-vue';

const scenicSpots = ref([]);
const softwareProviders = ref([]);
const otaPlatforms = ref([]);
const loading = ref(false);
const submitting = ref(false);
const dialogVisible = ref(false);
const formRef = ref(null);
const searchKeyword = ref('');
const currentPage = ref(1);
const pageSize = ref(15);
const total = ref(0);
const editingId = ref(null);

// 资源配置相关
const resourceConfigDialogVisible = ref(false);
const resourceConfigFormRef = ref(null);
const resourceConfigSubmitting = ref(false);
const currentScenicSpotId = ref(null);
const resourceConfigForm = ref({
    api_url: '',
    username: '',
    password: '',
    environment: 'production',
    sync_mode: {
        inventory: 'manual',
        price: 'manual',
        order: 'manual',
    },
    order_provider: null,
    credentials: {
        ctrip: { username: '', password: '' },
        meituan: { username: '', password: '' },
        fliggy: { username: '', password: '' },
    },
    is_active: true,
});

const isEdit = computed(() => editingId.value !== null);
const dialogTitle = computed(() => isEdit.value ? '编辑景区' : '创建景区');

const form = ref({
    name: '',
    code: '',
    address: '',
    contact_phone: '',
    description: '',
    software_provider_id: null,
    is_active: true,
});

const rules = {
    name: [
        { required: true, message: '请输入景区名称', trigger: 'blur' },
        { max: 255, message: '景区名称不能超过255个字符', trigger: 'blur' }
    ],
    code: [
        { required: true, message: '请输入景区编码', trigger: 'blur' },
        { pattern: /^[a-zA-Z0-9_-]+$/, message: '景区编码只能包含字母、数字、下划线和连字符', trigger: 'blur' }
    ],
    contact_phone: [
        { pattern: /^1[3-9]\d{9}$|^0\d{2,3}-?\d{7,8}$/, message: '请输入正确的电话号码', trigger: 'blur' }
    ],
};

const resourceConfigRules = {
    api_url: [
        { required: true, message: '请输入接口地址', trigger: 'blur' },
        { type: 'url', message: '请输入有效的URL', trigger: ['blur', 'change'] }
    ],
    username: [
        { required: true, message: '请输入默认用户名', trigger: 'blur' }
    ],
    password: [
        // 密码可以为空（编辑时如果不修改密码，可以不填）
        { min: 0, max: 255, message: '密码长度不能超过255个字符', trigger: 'blur' }
    ],
    environment: [
        { required: true, message: '请选择环境', trigger: 'change' }
    ],
    'sync_mode.inventory': [
        { required: true, message: '请选择库存同步方式', trigger: 'change' }
    ],
    'sync_mode.price': [
        { required: true, message: '请选择价格同步方式', trigger: 'change' }
    ],
    'sync_mode.order': [
        { required: true, message: '请选择订单处理方式', trigger: 'change' }
    ],
};

const fetchScenicSpots = async () => {
    loading.value = true;
    try {
        const params = {
            page: currentPage.value,
            per_page: pageSize.value,
        };
        
        if (searchKeyword.value) {
            // 如果后端支持搜索，可以添加 search 参数
            // params.search = searchKeyword.value;
        }
        
        const response = await axios.get('/scenic-spots', { params });
        scenicSpots.value = response.data.data || [];
        total.value = response.data.total || 0;
    } catch (error) {
        ElMessage.error('获取景区列表失败');
        console.error(error);
    } finally {
        loading.value = false;
    }
};

const fetchSoftwareProviders = async () => {
    try {
        const response = await axios.get('/software-providers');
        softwareProviders.value = response.data.data || [];
    } catch (error) {
        console.error('获取软件服务商列表失败', error);
    }
};

const fetchOtaPlatforms = async () => {
    try {
        const response = await axios.get('/ota-platforms');
        otaPlatforms.value = response.data.data || [];
    } catch (error) {
        console.error('获取OTA平台列表失败', error);
    }
};

const handleSearch = () => {
    currentPage.value = 1;
    fetchScenicSpots();
};

const handleCreate = () => {
    editingId.value = null;
    resetForm();
    dialogVisible.value = true;
};

const handleEdit = (row) => {
    editingId.value = row.id;
    form.value = {
        name: row.name,
        code: row.code,
        address: row.address || '',
        contact_phone: row.contact_phone || '',
        description: row.description || '',
        software_provider_id: row.software_provider_id,
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
                if (isEdit.value) {
                    await axios.put(`/scenic-spots/${editingId.value}`, form.value);
                    ElMessage.success('景区更新成功');
                } else {
                    await axios.post('/scenic-spots', form.value);
                    ElMessage.success('景区创建成功');
                }
                dialogVisible.value = false;
                fetchScenicSpots();
            } catch (error) {
                const message = error.response?.data?.message || error.response?.data?.errors?.code?.[0] || '操作失败';
                ElMessage.error(message);
            } finally {
                submitting.value = false;
            }
        }
    });
};

const handleDelete = async (row) => {
    try {
        await ElMessageBox.confirm(
            `确定要删除景区"${row.name}"吗？删除后无法恢复！`,
            '提示',
            {
                type: 'warning',
                confirmButtonText: '确定删除',
                cancelButtonText: '取消'
            }
        );
        
        await axios.delete(`/scenic-spots/${row.id}`);
        ElMessage.success('删除成功');
        fetchScenicSpots();
    } catch (error) {
        if (error !== 'cancel') {
            ElMessage.error('删除失败');
            console.error(error);
        }
    }
};

const resetForm = () => {
    form.value = {
        name: '',
        code: '',
        address: '',
        contact_phone: '',
        description: '',
        software_provider_id: null,
        is_active: true,
    };
    formRef.value?.clearValidate();
};

const handleConfigResource = async (row) => {
    currentScenicSpotId.value = row.id;
    resourceConfigDialogVisible.value = true;
    
    try {
        // 获取现有配置
        const response = await axios.get(`/scenic-spots/${row.id}/resource-config`);
        if (response.data.success && response.data.data) {
            const config = response.data.data;
            resourceConfigForm.value = {
                api_url: config.api_url || '',
                username: config.username || '',
                password: '', // 密码不返回，需要重新输入
                environment: config.environment || 'production',
                sync_mode: config.extra_config?.sync_mode || {
                    inventory: 'manual',
                    price: 'manual',
                    order: 'manual',
                },
                order_provider: config.extra_config?.order_provider || null,
                credentials: config.extra_config?.credentials || {
                    ctrip: { username: '', password: '' },
                    meituan: { username: '', password: '' },
                    fliggy: { username: '', password: '' },
                },
                is_active: config.is_active ?? true,
            };
        } else {
            // 没有配置，使用默认值
            resetResourceConfigForm();
        }
    } catch (error) {
        // 404表示没有配置，使用默认值
        if (error.response?.status !== 404) {
            ElMessage.error('获取资源配置失败');
            console.error(error);
        }
        resetResourceConfigForm();
    }
};

const handleSubmitResourceConfig = async () => {
    if (!resourceConfigFormRef.value) return;
    
    await resourceConfigFormRef.value.validate(async (valid) => {
        if (valid) {
            resourceConfigSubmitting.value = true;
            try {
                // 确保 credentials 对象正确格式化
                const submitData = {
                    ...resourceConfigForm.value,
                    credentials: resourceConfigForm.value.credentials || {},
                };
                
                await axios.post(`/scenic-spots/${currentScenicSpotId.value}/resource-config`, submitData);
                ElMessage.success('资源配置保存成功');
                resourceConfigDialogVisible.value = false;
                fetchScenicSpots();
            } catch (error) {
                // 显示更详细的错误信息
                let message = '保存失败';
                if (error.response?.data?.message) {
                    message = error.response.data.message;
                } else if (error.response?.data?.errors) {
                    const errors = error.response.data.errors;
                    const firstError = Object.values(errors)[0];
                    message = Array.isArray(firstError) ? firstError[0] : firstError;
                }
                ElMessage.error(message);
                console.error('保存资源配置失败:', error);
            } finally {
                resourceConfigSubmitting.value = false;
            }
        }
    });
};

const resetResourceConfigForm = () => {
    resourceConfigForm.value = {
        api_url: '',
        username: '',
        password: '',
        environment: 'production',
        sync_mode: {
            inventory: 'manual',
            price: 'manual',
            order: 'manual',
        },
        order_provider: null,
        credentials: {
            ctrip: { username: '', password: '' },
            meituan: { username: '', password: '' },
            fliggy: { username: '', password: '' },
        },
        is_active: true,
    };
    resourceConfigFormRef.value?.clearValidate();
};

onMounted(() => {
    fetchScenicSpots();
    fetchSoftwareProviders();
    fetchOtaPlatforms();
});
</script>

<style scoped>
h2 {
    margin-bottom: 20px;
}
</style>
