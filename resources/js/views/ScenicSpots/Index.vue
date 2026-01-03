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
                <el-table-column label="软件服务商" width="200">
                    <template #default="{ row }">
                        <el-tag
                            v-for="provider in row.software_providers || []"
                            :key="provider.id"
                            style="margin-right: 5px; margin-bottom: 5px;"
                        >
                            {{ provider.name }}
                        </el-tag>
                        <span v-if="!row.software_providers || row.software_providers.length === 0">-</span>
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
                <el-form-item label="景区编码" prop="code" v-if="isEdit">
                    <el-input v-model="form.code" placeholder="系统自动生成" disabled />
                    <span style="margin-left: 10px; color: #909399; font-size: 12px;">
                        景区编码由系统自动生成，不可修改
                    </span>
                </el-form-item>
                <el-form-item v-else>
                    <template #label>
                        <span>景区编码</span>
                    </template>
                    <el-input value="系统自动生成" disabled />
                    <span style="margin-left: 10px; color: #909399; font-size: 12px;">
                        景区编码将在创建时由系统自动生成
                    </span>
                </el-form-item>
                <el-form-item label="地址" prop="address">
                    <el-input v-model="form.address" placeholder="请输入景区地址" />
                </el-form-item>
                <el-form-item label="联系电话" prop="contact_phone">
                    <el-input v-model="form.contact_phone" placeholder="请输入联系电话" />
                </el-form-item>
                <el-form-item label="软件服务商" prop="software_provider_ids">
                    <el-select
                        v-model="form.software_provider_ids"
                        placeholder="请选择软件服务商（可多选）"
                        multiple
                        clearable
                        style="width: 100%"
                    >
                        <el-option
                            v-for="provider in softwareProviders"
                            :key="provider.id"
                            :label="`${provider.name} (${provider.api_type || '无类型'})`"
                            :value="provider.id"
                        />
                    </el-select>
                    <div style="font-size: 12px; color: #909399; margin-top: 5px;">
                        一个景区可以选择多个软件服务商，不同产品可以使用不同的服务商
                    </div>
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
            width="900px"
            @close="resetResourceConfigForm"
        >
            <el-alert
                title="重要提示"
                type="warning"
                description="一个景区可以有多个软件服务商，请为每个服务商单独配置参数。不同OTA平台的订单需要使用不同的用户名和密码。"
                :closable="false"
                style="margin-bottom: 20px;"
            />
            
            <!-- 服务商选择器（如果景区有多个服务商） -->
            <div v-if="currentScenicSpotProviders && currentScenicSpotProviders.length > 1" style="margin-bottom: 20px;">
                <el-select
                    v-model="selectedProviderId"
                    placeholder="请选择要配置的服务商"
                    style="width: 100%"
                    @change="handleProviderChange"
                >
                    <el-option
                        v-for="provider in currentScenicSpotProviders"
                        :key="provider.id"
                        :label="`${provider.name} (${provider.api_type || '无类型'})`"
                        :value="provider.id"
                    />
                </el-select>
            </div>
            
            <!-- 如果只有一个服务商，直接显示配置 -->
            <div v-else-if="currentScenicSpotProviders && currentScenicSpotProviders.length === 1" style="margin-bottom: 20px;">
                <el-alert
                    :title="`正在配置服务商：${currentScenicSpotProviders[0].name}`"
                    type="info"
                    :closable="false"
                />
            </div>
            
            <!-- 如果景区没有服务商，提示先添加 -->
            <el-alert
                v-else
                title="该景区尚未配置软件服务商"
                type="warning"
                description="请先在景区编辑页面添加软件服务商，然后再配置参数。"
                :closable="false"
                style="margin-bottom: 20px;"
            />
            
            <el-form
                ref="resourceConfigFormRef"
                :model="resourceConfigForm"
                :rules="resourceConfigRules"
                label-width="140px"
            >
                <el-form-item label="认证类型" prop="auth.type">
                    <el-select v-model="resourceConfigForm.auth.type" style="width: 100%" @change="handleAuthTypeChange">
                        <el-option label="用户名密码" value="username_password" />
                        <el-option label="AppKey/Secret" value="appkey_secret" />
                        <el-option label="Token" value="token" />
                        <el-option label="自定义参数" value="custom" />
                    </el-select>
                    <div style="font-size: 12px; color: #909399; margin-top: 5px;">
                        选择认证方式，如果接口使用非标准参数名，请选择"自定义参数"
                    </div>
                </el-form-item>

                <!-- 用户名密码认证 -->
                <template v-if="resourceConfigForm.auth.type === 'username_password'">
                    <el-form-item label="默认用户名" prop="username">
                        <el-input v-model="resourceConfigForm.username" placeholder="用于库存推送订阅等非订单场景" />
                    </el-form-item>
                    <el-form-item label="默认密码" prop="password">
                        <el-input v-model="resourceConfigForm.password" type="password" show-password placeholder="用于库存推送订阅等非订单场景" />
                    </el-form-item>
                </template>

                <!-- AppKey/Secret认证 -->
                <template v-if="resourceConfigForm.auth.type === 'appkey_secret'">
                    <el-form-item label="AppKey" prop="auth.appkey">
                        <el-input v-model="resourceConfigForm.auth.appkey" placeholder="请输入AppKey" />
                    </el-form-item>
                    <el-form-item label="AppSecret" prop="auth.appsecret">
                        <el-input v-model="resourceConfigForm.auth.appsecret" type="password" show-password placeholder="请输入AppSecret" />
                    </el-form-item>
                </template>

                <!-- Token认证 -->
                <template v-if="resourceConfigForm.auth.type === 'token'">
                    <el-form-item label="Token" prop="auth.token">
                        <el-input v-model="resourceConfigForm.auth.token" type="password" show-password placeholder="请输入Token" />
                    </el-form-item>
                </template>

                <!-- 自定义参数认证 -->
                <template v-if="resourceConfigForm.auth.type === 'custom'">
                    <el-form-item label="参数模板">
                        <el-select v-model="selectedParamTemplate" placeholder="选择参数模板（可选）" clearable style="width: 100%" @change="handleTemplateChange">
                            <el-option label="用户名密码（自定义参数名）" value="username_password_custom" />
                            <el-option label="AppKey/Secret（自定义参数名）" value="appkey_secret_custom" />
                            <el-option label="Token（自定义参数名）" value="token_custom" />
                            <el-option label="空模板" value="empty" />
                        </el-select>
                        <div style="font-size: 12px; color: #909399; margin-top: 5px;">
                            选择模板可以快速填充常用参数，也可以手动添加参数
                        </div>
                    </el-form-item>
                    <el-form-item label="自定义参数">
                        <el-table :data="resourceConfigForm.auth.params" border style="width: 100%">
                            <el-table-column label="参数名" width="200">
                                <template #default="{ row, $index }">
                                    <el-input v-model="row.key" placeholder="参数名" />
                                </template>
                            </el-table-column>
                            <el-table-column label="参数值" min-width="300">
                                <template #default="{ row, $index }">
                                    <el-input 
                                        v-model="row.value" 
                                        :type="isSensitiveParam(row.key) ? 'password' : 'text'"
                                        :show-password="isSensitiveParam(row.key)"
                                        placeholder="参数值"
                                    />
                                </template>
                            </el-table-column>
                            <el-table-column label="操作" width="100" fixed="right">
                                <template #default="{ $index }">
                                    <el-button size="small" type="danger" @click="removeCustomParam($index)">删除</el-button>
                                </template>
                            </el-table-column>
                        </el-table>
                        <el-button type="primary" size="small" style="margin-top: 10px;" @click="addCustomParam">添加参数</el-button>
                        <div style="font-size: 12px; color: #909399; margin-top: 5px;">
                            敏感参数（包含password、secret、key、token等关键词）将自动加密存储
                        </div>
                    </el-form-item>
                </template>

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
const currentScenicSpotProviders = ref([]);
const selectedProviderId = ref(null);
const selectedParamTemplate = ref(null);
const resourceConfigForm = ref({
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
    auth: {
        type: 'username_password',
        appkey: '',
        appsecret: '',
        app_id: '',
        token: '',
        access_token: '',
        params: [], // 自定义参数数组，格式：[{key: 'param_name', value: 'param_value'}]
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
    software_provider_ids: [],
    is_active: true,
});

const rules = {
    name: [
        { required: true, message: '请输入景区名称', trigger: 'blur' },
        { max: 255, message: '景区名称不能超过255个字符', trigger: 'blur' }
    ],
    code: [
        // 编辑时验证格式（如果用户通过其他方式修改了编码）
        { pattern: /^[a-zA-Z0-9_-]+$/, message: '景区编码只能包含字母、数字、下划线和连字符', trigger: 'blur' }
    ],
    contact_phone: [
        { pattern: /^1[3-9]\d{9}$|^0\d{2,3}-?\d{7,8}$/, message: '请输入正确的电话号码', trigger: 'blur' }
    ],
};

const resourceConfigRules = {
    'auth.type': [
        { required: true, message: '请选择认证类型', trigger: 'change' }
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
        software_provider_ids: row.software_providers ? row.software_providers.map(p => p.id) : [],
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
                    // 创建时，不发送 code 字段，让后端自动生成
                    const { code, ...createData } = form.value;
                    await axios.post('/scenic-spots', createData);
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
        code: '', // 创建时保持为空，后端会自动生成
        address: '',
        contact_phone: '',
        description: '',
        software_provider_ids: [],
        is_active: true,
    };
    formRef.value?.clearValidate();
};

const handleConfigResource = async (row) => {
    currentScenicSpotId.value = row.id;
    // 先重置表单，确保没有残留数据
    resetResourceConfigForm();
    
    // 获取景区的服务商列表
    try {
        const scenicSpotResponse = await axios.get(`/scenic-spots/${row.id}`);
        const scenicSpot = scenicSpotResponse.data.data;
        currentScenicSpotProviders.value = scenicSpot.software_providers || [];
        
        // 如果只有一个服务商，自动选择
        if (currentScenicSpotProviders.value.length === 1) {
            selectedProviderId.value = currentScenicSpotProviders.value[0].id;
        } else if (currentScenicSpotProviders.value.length > 1) {
            // 如果有多个服务商，默认选择第一个
            selectedProviderId.value = currentScenicSpotProviders.value[0].id;
        } else {
            // 没有服务商，提示用户先添加
            selectedProviderId.value = null;
        }
    } catch (error) {
        ElMessage.error('获取景区信息失败');
        console.error(error);
        return;
    }
    
    resourceConfigDialogVisible.value = true;
    
    // 如果有选中的服务商，加载配置
    if (selectedProviderId.value) {
        await loadResourceConfig(selectedProviderId.value);
    }
};

const handleProviderChange = async (providerId) => {
    if (providerId) {
        await loadResourceConfig(providerId);
    } else {
        resetResourceConfigForm();
    }
};

const loadResourceConfig = async (providerId) => {
    try {
        // 获取现有配置（传递 service_provider_id 参数）
        const response = await axios.get(`/scenic-spots/${currentScenicSpotId.value}/resource-config`, {
            params: {
                software_provider_id: providerId
            }
        });
        if (response.data.success && response.data.data) {
            const config = response.data.data;
            const authConfig = config.extra_config?.auth || {};
            
            // 处理自定义参数：如果是加密的，显示为已存在标记
            let customParams = [];
            if (authConfig.type === 'custom' && authConfig.params) {
                customParams = Object.entries(authConfig.params).map(([key, value]) => {
                    // 如果值是加密的（以encrypted:开头），显示为已存在标记
                    if (typeof value === 'string' && value.startsWith('encrypted:')) {
                        return { key, value: '***EXISTS***' };
                    }
                    return { key, value };
                });
            }
            
            resourceConfigForm.value = {
                username: config.username || '',
                // 如果后端返回 '***EXISTS***'，表示密码已存在，保持为空（用户不修改则不更新）
                // 如果为空字符串，表示没有密码
                password: config.password === '***EXISTS***' ? '' : (config.password || ''),
                environment: config.environment || 'production',
                sync_mode: config.extra_config?.sync_mode || {
                    inventory: 'manual',
                    price: 'manual',
                    order: 'manual',
                },
                order_provider: config.extra_config?.order_provider || null,
                // 确保 credentials 正确初始化，保留现有的用户名（即使密码被隐藏）
                credentials: {
                    ctrip: {
                        username: config.extra_config?.credentials?.ctrip?.username || '',
                        password: config.extra_config?.credentials?.ctrip?.password === '***EXISTS***' 
                            ? '' 
                            : (config.extra_config?.credentials?.ctrip?.password || ''),
                    },
                    meituan: {
                        username: config.extra_config?.credentials?.meituan?.username || '',
                        password: config.extra_config?.credentials?.meituan?.password === '***EXISTS***' 
                            ? '' 
                            : (config.extra_config?.credentials?.meituan?.password || ''),
                    },
                    fliggy: {
                        username: config.extra_config?.credentials?.fliggy?.username || '',
                        password: config.extra_config?.credentials?.fliggy?.password === '***EXISTS***' 
                            ? '' 
                            : (config.extra_config?.credentials?.fliggy?.password || ''),
                    },
                },
                auth: {
                    type: authConfig.type || 'username_password',
                    appkey: authConfig.appkey || '',
                    appsecret: authConfig.appsecret === '***EXISTS***' ? '' : (authConfig.appsecret || ''),
                    app_id: authConfig.app_id || '',
                    token: authConfig.token === '***EXISTS***' ? '' : (authConfig.token || ''),
                    access_token: authConfig.access_token === '***EXISTS***' ? '' : (authConfig.access_token || ''),
                    params: Array.isArray(customParams) ? customParams : [],
                },
                is_active: config.is_active ?? true,
            };
        } else {
            // 没有配置，表单已重置为空
        }
    } catch (error) {
        // 404或其他错误表示没有配置，表单已重置为空
        if (error.response?.status !== 404) {
            ElMessage.error('获取资源配置失败');
            console.error(error);
        }
        // 表单已在开始时重置，这里不需要再次重置
    }
};

const handleSubmitResourceConfig = async () => {
    if (!resourceConfigFormRef.value) return;
    
    // 验证自定义参数
    if (resourceConfigForm.value.auth.type === 'custom') {
        const params = resourceConfigForm.value.auth.params || [];
        for (let i = 0; i < params.length; i++) {
            const param = params[i];
            if (!param.key || param.key.trim() === '') {
                ElMessage.warning(`第 ${i + 1} 个参数的参数名不能为空`);
                return;
            }
            if (!param.value || param.value.trim() === '' || param.value === '***EXISTS***') {
                // 允许空值或已存在标记，跳过验证
                continue;
            }
        }
    }
    
    await resourceConfigFormRef.value.validate(async (valid) => {
        if (valid) {
            resourceConfigSubmitting.value = true;
            try {
                // 确保 credentials 对象正确格式化
                // 重要：即使密码为空，如果用户名存在，也要发送（后端会保留现有密码）
                const credentials = {};
                for (const [platform, cred] of Object.entries(resourceConfigForm.value.credentials || {})) {
                    if (cred) {
                        // 如果用户名或密码有值，就发送（即使密码为空，也要发送，让后端知道要保留现有密码）
                        if (cred.username || cred.password) {
                            credentials[platform] = {
                                username: cred.username || '',
                                // 如果密码为空字符串，后端会保留现有密码
                                password: cred.password || '',
                            };
                        }
                    }
                }
                
                // 验证是否选择了服务商
                if (!selectedProviderId.value) {
                    ElMessage.warning('请先选择要配置的服务商');
                    return;
                }
                
                // 处理自定义参数：转换为键值对对象
                let authConfig = { ...resourceConfigForm.value.auth };
                if (authConfig.type === 'custom' && authConfig.params) {
                    // 将数组格式转换为对象格式，过滤掉空值
                    const paramsObj = {};
                    authConfig.params.forEach(param => {
                        if (param.key && param.value && param.value !== '***EXISTS***') {
                            paramsObj[param.key] = param.value;
                        }
                    });
                    authConfig.params = paramsObj;
                } else {
                    // 非自定义类型，移除params
                    delete authConfig.params;
                }
                
                const submitData = {
                    software_provider_id: selectedProviderId.value,
                    username: resourceConfigForm.value.username,
                    // 如果密码是 '***EXISTS***' 或空，不发送 password 字段，让后端从现有配置中获取
                    // 否则确保是字符串类型
                    ...(resourceConfigForm.value.password && resourceConfigForm.value.password !== '***EXISTS***' 
                        ? { password: String(resourceConfigForm.value.password) } 
                        : {}),
                    environment: resourceConfigForm.value.environment,
                    is_active: resourceConfigForm.value.is_active,
                    sync_mode: resourceConfigForm.value.sync_mode,
                    order_provider: resourceConfigForm.value.order_provider || null,
                    // 即使 credentials 为空对象，也要发送，确保后端知道要保留现有值
                    credentials: Object.keys(credentials).length > 0 ? credentials : {},
                    // 认证配置
                    auth: authConfig,
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
        username: '',
        password: '',
        environment: 'production', // 只有一个选项，保持默认值
        sync_mode: {
            inventory: '', // 改为空，让用户必须选择
            price: '', // 改为空，让用户必须选择
            order: '', // 改为空，让用户必须选择
        },
        order_provider: null,
        credentials: {
            ctrip: { username: '', password: '' },
            meituan: { username: '', password: '' },
            fliggy: { username: '', password: '' },
        },
        auth: {
            type: 'username_password',
            appkey: '',
            appsecret: '',
            app_id: '',
            token: '',
            access_token: '',
            params: [],
        },
        is_active: true, // 保持默认启用状态
    };
    selectedProviderId.value = null;
    selectedParamTemplate.value = null;
    currentScenicSpotProviders.value = [];
    resourceConfigFormRef.value?.clearValidate();
};

// 自定义参数相关方法
const addCustomParam = () => {
    resourceConfigForm.value.auth.params.push({ key: '', value: '' });
};

const removeCustomParam = (index) => {
    resourceConfigForm.value.auth.params.splice(index, 1);
};

const isSensitiveParam = (paramName) => {
    if (!paramName) return false;
    const sensitiveKeywords = ['password', 'pwd', 'secret', 'key', 'token', 'auth'];
    const paramNameLower = paramName.toLowerCase();
    return sensitiveKeywords.some(keyword => paramNameLower.includes(keyword));
};

const handleAuthTypeChange = (authType) => {
    // 切换认证类型时，清空自定义参数
    if (authType !== 'custom') {
        resourceConfigForm.value.auth.params = [];
        selectedParamTemplate.value = null;
    }
};

const handleTemplateChange = (template) => {
    if (!template) return;
    
    // 清空现有参数
    resourceConfigForm.value.auth.params = [];
    
    // 根据模板填充参数
    switch (template) {
        case 'username_password_custom':
            resourceConfigForm.value.auth.params = [
                { key: 'user', value: '' },
                { key: 'pwd', value: '' },
            ];
            break;
        case 'appkey_secret_custom':
            resourceConfigForm.value.auth.params = [
                { key: 'api_key', value: '' },
                { key: 'api_secret', value: '' },
            ];
            break;
        case 'token_custom':
            resourceConfigForm.value.auth.params = [
                { key: 'access_token', value: '' },
            ];
            break;
        case 'empty':
            resourceConfigForm.value.auth.params = [];
            break;
    }
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
