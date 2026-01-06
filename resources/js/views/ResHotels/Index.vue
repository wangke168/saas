<template>
    <div>
        <h2>打包酒店管理</h2>
        <el-card>
            <div style="margin-bottom: 20px;">
                <el-button type="primary" @click="handleCreate">创建打包酒店</el-button>
                <el-select
                    v-model="filterScenicSpotId"
                    placeholder="筛选景区"
                    clearable
                    style="width: 200px; margin-left: 10px;"
                    @change="handleFilter"
                >
                    <el-option
                        v-for="spot in scenicSpots"
                        :key="spot.id"
                        :label="spot.name"
                        :value="spot.id"
                    />
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
                    placeholder="搜索酒店名称或外部编号"
                    style="width: 300px; margin-left: 10px;"
                    clearable
                    @input="handleSearch"
                >
                    <template #prefix>
                        <el-icon><Search /></el-icon>
                    </template>
                </el-input>
            </div>
            
            <el-table :data="hotels" v-loading="loading" border>
                <el-table-column prop="name" label="酒店名称" width="200" />
                <el-table-column prop="code" label="酒店编码" width="150" />
                <el-table-column prop="external_hotel_id" label="外部酒店编号" width="150">
                    <template #default="{ row }">
                        {{ row.external_hotel_id || '-' }}
                    </template>
                </el-table-column>
                <el-table-column label="所属景区" width="150">
                    <template #default="{ row }">
                        {{ row.scenic_spot?.name || '-' }}
                    </template>
                </el-table-column>
                <el-table-column label="软件服务商" width="150">
                    <template #default="{ row }">
                        {{ row.software_provider?.name || '-' }}
                    </template>
                </el-table-column>
                <el-table-column prop="address" label="地址" show-overflow-tooltip />
                <el-table-column prop="contact_phone" label="联系电话" width="150" />
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
                        <el-button size="small" type="primary" @click="handleViewDetail(row)">查看详情</el-button>
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
                @size-change="fetchHotels"
                @current-change="fetchHotels"
            />
        </el-card>

        <!-- 创建/编辑打包酒店对话框 -->
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
                <el-form-item label="所属景区" prop="scenic_spot_id">
                    <el-select
                        v-model="form.scenic_spot_id"
                        placeholder="请选择景区"
                        style="width: 100%"
                        :disabled="isEdit"
                        @change="handleScenicSpotChange"
                    >
                        <el-option
                            v-for="spot in scenicSpots"
                            :key="spot.id"
                            :label="spot.name"
                            :value="spot.id"
                        />
                    </el-select>
                </el-form-item>
                <el-form-item label="酒店名称" prop="name">
                    <el-input v-model="form.name" placeholder="请输入酒店名称" />
                </el-form-item>
                <el-form-item label="酒店编码" prop="code">
                    <el-input v-model="form.code" placeholder="系统自动生成" :disabled="true" />
                    <div style="font-size: 12px; color: #909399; margin-top: 5px;">
                        酒店编码由系统自动生成，格式：H + 5位数字（如 H00001）
                    </div>
                </el-form-item>
                <el-form-item label="外部酒店编号" prop="external_hotel_id">
                    <el-input v-model="form.external_hotel_id" placeholder="用于资源方系统对接的酒店编号（可选）" />
                </el-form-item>
                <el-form-item label="软件服务商" prop="software_provider_id">
                    <el-select
                        v-model="form.software_provider_id"
                        placeholder="请选择软件服务商（可选）"
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
                <el-form-item label="地址" prop="address">
                    <el-input v-model="form.address" placeholder="请输入酒店地址" />
                </el-form-item>
                <el-form-item label="联系电话" prop="contact_phone">
                    <el-input v-model="form.contact_phone" placeholder="请输入联系电话" />
                </el-form-item>
                <el-form-item label="描述" prop="description">
                    <el-input
                        v-model="form.description"
                        type="textarea"
                        :rows="3"
                        placeholder="请输入描述"
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
    </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';
import { useRouter } from 'vue-router';
import axios from '../../utils/axios';
import { ElMessage, ElMessageBox } from 'element-plus';
import { Search } from '@element-plus/icons-vue';
import { useAuthStore } from '../../stores/auth';

const router = useRouter();

const authStore = useAuthStore();

const hotels = ref([]);
const scenicSpots = ref([]);
const softwareProviders = ref([]);
const loading = ref(false);
const submitting = ref(false);
const dialogVisible = ref(false);
const formRef = ref(null);
const searchKeyword = ref('');
const filterScenicSpotId = ref(null);
const filterStatus = ref(null);
const currentPage = ref(1);
const pageSize = ref(15);
const total = ref(0);
const editingId = ref(null);

const isEdit = computed(() => editingId.value !== null);
const dialogTitle = computed(() => isEdit.value ? '编辑打包酒店' : '创建打包酒店');

const form = ref({
    scenic_spot_id: null,
    software_provider_id: null,
    name: '',
    code: '',
    external_hotel_id: '',
    address: '',
    contact_phone: '',
    description: '',
    is_active: true,
});

const rules = {
    scenic_spot_id: [
        { required: true, message: '请选择所属景区', trigger: 'change' }
    ],
    name: [
        { required: true, message: '请输入酒店名称', trigger: 'blur' },
        { max: 255, message: '酒店名称不能超过255个字符', trigger: 'blur' }
    ],
};

const fetchHotels = async () => {
    loading.value = true;
    try {
        const params = {
            page: currentPage.value,
            per_page: pageSize.value,
        };
        
        if (filterScenicSpotId.value) {
            params.scenic_spot_id = filterScenicSpotId.value;
        }
        
        if (filterStatus.value !== null) {
            params.is_active = filterStatus.value;
        }
        
        if (searchKeyword.value) {
            params.search = searchKeyword.value;
        }
        
        const response = await axios.get('/res-hotels', { params });
        hotels.value = response.data.data || [];
        total.value = response.data.total || 0;
    } catch (error) {
        ElMessage.error('获取打包酒店列表失败');
        console.error(error);
    } finally {
        loading.value = false;
    }
};

const fetchScenicSpots = async () => {
    try {
        if (authStore.user?.role !== 'admin') {
            scenicSpots.value = authStore.user?.scenic_spots || [];
        } else {
            const response = await axios.get('/scenic-spots');
            scenicSpots.value = response.data.data || [];
        }
    } catch (error) {
        console.error('获取景区列表失败', error);
        if (authStore.user?.scenic_spots) {
            scenicSpots.value = authStore.user.scenic_spots;
        }
    }
};

const fetchSoftwareProviders = async () => {
    // 不再初始化时加载所有服务商，改为根据选择的景区动态加载
    softwareProviders.value = [];
};

const handleScenicSpotChange = async (scenicSpotId) => {
    // 清空当前选择的软件服务商
    form.value.software_provider_id = null;
    
    if (!scenicSpotId) {
        softwareProviders.value = [];
        return;
    }
    
    try {
        // 获取景区详情，包含关联的软件服务商
        const response = await axios.get(`/scenic-spots/${scenicSpotId}`);
        const scenicSpot = response.data.data;
        
        // 使用景区关联的软件服务商列表
        softwareProviders.value = scenicSpot.software_providers || [];
    } catch (error) {
        console.error('获取景区服务商列表失败', error);
        softwareProviders.value = [];
    }
};

const handleSearch = () => {
    currentPage.value = 1;
    fetchHotels();
};

const handleFilter = () => {
    currentPage.value = 1;
    fetchHotels();
};

const handleCreate = () => {
    editingId.value = null;
    resetForm();
    dialogVisible.value = true;
};

const handleViewDetail = (row) => {
    router.push(`/res-hotels/${row.id}`);
};

const handleEdit = async (row) => {
    editingId.value = row.id;
    
    // 先加载该景区关联的服务商
    if (row.scenic_spot_id) {
        await handleScenicSpotChange(row.scenic_spot_id);
    }
    
    // 然后设置表单数据（在加载服务商列表之后，这样不会清空已选择的服务商）
    form.value = {
        scenic_spot_id: row.scenic_spot_id,
        software_provider_id: row.software_provider_id || null,
        name: row.name,
        code: row.code,
        external_hotel_id: row.external_hotel_id || '',
        address: row.address || '',
        contact_phone: row.contact_phone || '',
        description: row.description || '',
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
                    await axios.put(`/res-hotels/${editingId.value}`, form.value);
                    ElMessage.success('打包酒店更新成功');
                } else {
                    await axios.post('/res-hotels', form.value);
                    ElMessage.success('打包酒店创建成功');
                }
                dialogVisible.value = false;
                fetchHotels();
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
            `确定要删除打包酒店"${row.name}"吗？删除后无法恢复！`,
            '提示',
            {
                type: 'warning',
                confirmButtonText: '确定删除',
                cancelButtonText: '取消'
            }
        );
        
        await axios.delete(`/res-hotels/${row.id}`);
        ElMessage.success('删除成功');
        fetchHotels();
    } catch (error) {
        if (error !== 'cancel') {
            ElMessage.error('删除失败');
            console.error(error);
        }
    }
};

const resetForm = () => {
    form.value = {
        scenic_spot_id: null,
        software_provider_id: null,
        name: '',
        code: '',
        external_hotel_id: '',
        address: '',
        contact_phone: '',
        description: '',
        is_active: true,
    };
    formRef.value?.clearValidate();
};

const formatDate = (date) => {
    if (!date) return '';
    const d = new Date(date);
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    const hours = String(d.getHours()).padStart(2, '0');
    const minutes = String(d.getMinutes()).padStart(2, '0');
    return `${year}-${month}-${day} ${hours}:${minutes}`;
};

onMounted(() => {
    fetchHotels();
    fetchScenicSpots();
    fetchSoftwareProviders();
});
</script>

<style scoped>
h2 {
    margin-bottom: 20px;
}
</style>
