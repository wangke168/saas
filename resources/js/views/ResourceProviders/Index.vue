<template>
    <div>
        <h2>资源方管理</h2>
        <el-card>
            <div style="margin-bottom: 20px;">
                <el-button type="primary" @click="handleCreate">创建资源方</el-button>
                <el-input
                    v-model="searchKeyword"
                    placeholder="搜索资源方名称或编码"
                    style="width: 300px; margin-left: 10px;"
                    clearable
                    @input="handleSearch"
                >
                    <template #prefix>
                        <el-icon><Search /></el-icon>
                    </template>
                </el-input>
            </div>
            
            <el-table :data="resourceProviders" v-loading="loading" border>
                <el-table-column prop="name" label="资源方名称" width="200" />
                <el-table-column prop="code" label="资源方编码" width="150" />
                <el-table-column prop="description" label="描述" show-overflow-tooltip />
                <el-table-column prop="contact_name" label="联系人" width="120" />
                <el-table-column prop="contact_phone" label="联系电话" width="150" />
                <el-table-column prop="contact_email" label="联系邮箱" width="200" />
                <el-table-column label="绑定景区" width="200">
                    <template #default="{ row }">
                        <el-tag
                            v-for="spot in (row.scenic_spots || [])"
                            :key="spot.id"
                            size="small"
                            style="margin-right: 5px; margin-bottom: 5px;"
                        >
                            {{ spot.name }}
                        </el-tag>
                        <span v-if="!row.scenic_spots || row.scenic_spots.length === 0" style="color: #909399;">
                            未绑定
                        </span>
                    </template>
                </el-table-column>
                <el-table-column label="绑定用户" width="150">
                    <template #default="{ row }">
                        <el-tag
                            v-for="user in (row.users || [])"
                            :key="user.id"
                            size="small"
                            style="margin-right: 5px; margin-bottom: 5px;"
                        >
                            {{ user.name }}
                        </el-tag>
                        <span v-if="!row.users || row.users.length === 0" style="color: #909399;">
                            未绑定
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
                <el-table-column label="操作" width="200" fixed="right">
                    <template #default="{ row }">
                        <el-button size="small" @click="handleEdit(row)">编辑</el-button>
                        <el-button size="small" type="info" @click="handleManageScenicSpots(row)">管理景区</el-button>
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
                @size-change="fetchResourceProviders"
                @current-change="fetchResourceProviders"
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
                <el-form-item label="资源方名称" prop="name">
                    <el-input v-model="form.name" placeholder="请输入资源方名称" />
                </el-form-item>
                <el-form-item label="资源方编码" prop="code">
                    <el-input v-model="form.code" placeholder="请输入资源方编码（唯一，留空自动生成）" :disabled="isEdit" />
                </el-form-item>
                <el-form-item label="描述" prop="description">
                    <el-input
                        v-model="form.description"
                        type="textarea"
                        :rows="4"
                        placeholder="请输入资源方描述"
                    />
                </el-form-item>
                <el-form-item label="联系人" prop="contact_name">
                    <el-input v-model="form.contact_name" placeholder="请输入联系人姓名" />
                </el-form-item>
                <el-form-item label="联系电话" prop="contact_phone">
                    <el-input v-model="form.contact_phone" placeholder="请输入联系电话" />
                </el-form-item>
                <el-form-item label="联系邮箱" prop="contact_email">
                    <el-input v-model="form.contact_email" placeholder="请输入联系邮箱" />
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

        <!-- 管理景区对话框 -->
        <el-dialog
            v-model="scenicSpotsDialogVisible"
            title="管理景区"
            width="600px"
            @close="resetScenicSpotsForm"
        >
            <el-form
                ref="scenicSpotsFormRef"
                :model="scenicSpotsForm"
                label-width="120px"
            >
                <el-form-item label="绑定景区">
                    <el-select
                        v-model="scenicSpotsForm.scenic_spot_ids"
                        placeholder="请选择要绑定的景区（可多选）"
                        multiple
                        style="width: 100%"
                    >
                        <el-option
                            v-for="spot in allScenicSpots"
                            :key="spot.id"
                            :label="spot.name"
                            :value="spot.id"
                        />
                    </el-select>
                    <div style="font-size: 12px; color: #909399; margin-top: 5px;">
                        选择该资源方可以管理的景区
                    </div>
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="scenicSpotsDialogVisible = false">取消</el-button>
                <el-button type="primary" @click="handleSubmitScenicSpots" :loading="scenicSpotsSubmitting">确定</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';
import axios from '../../utils/axios';
import { ElMessage, ElMessageBox } from 'element-plus';
import { Search } from '@element-plus/icons-vue';

const resourceProviders = ref([]);
const allScenicSpots = ref([]);
const loading = ref(false);
const submitting = ref(false);
const dialogVisible = ref(false);
const formRef = ref(null);
const searchKeyword = ref('');
const currentPage = ref(1);
const pageSize = ref(15);
const total = ref(0);
const editingId = ref(null);

// 管理景区相关
const scenicSpotsDialogVisible = ref(false);
const scenicSpotsFormRef = ref(null);
const scenicSpotsSubmitting = ref(false);
const currentResourceProviderId = ref(null);
const scenicSpotsForm = ref({
    scenic_spot_ids: [],
});

const isEdit = computed(() => editingId.value !== null);
const dialogTitle = computed(() => isEdit.value ? '编辑资源方' : '创建资源方');

const form = ref({
    name: '',
    code: '',
    description: '',
    contact_name: '',
    contact_phone: '',
    contact_email: '',
    is_active: true,
});

const rules = {
    name: [
        { required: true, message: '请输入资源方名称', trigger: 'blur' },
        { max: 255, message: '资源方名称不能超过255个字符', trigger: 'blur' }
    ],
    code: [
        { pattern: /^[a-zA-Z0-9_-]+$/, message: '资源方编码只能包含字母、数字、下划线和连字符', trigger: 'blur' }
    ],
    contact_email: [
        { type: 'email', message: '请输入正确的邮箱地址', trigger: ['blur', 'change'] }
    ],
    contact_phone: [
        { pattern: /^1[3-9]\d{9}$|^0\d{2,3}-?\d{7,8}$/, message: '请输入正确的电话号码', trigger: 'blur' }
    ],
};

const fetchResourceProviders = async () => {
    loading.value = true;
    try {
        const params = {
            page: currentPage.value,
            per_page: pageSize.value,
        };
        
        if (searchKeyword.value) {
            params.search = searchKeyword.value;
        }
        
        const response = await axios.get('/resource-providers', { params });
        resourceProviders.value = response.data.data || [];
        total.value = response.data.total || 0;
    } catch (error) {
        ElMessage.error('获取资源方列表失败');
        console.error(error);
    } finally {
        loading.value = false;
    }
};

const fetchAllScenicSpots = async () => {
    try {
        const response = await axios.get('/scenic-spots', {
            params: {
                per_page: 1000, // 获取所有景区
            }
        });
        allScenicSpots.value = response.data.data || [];
    } catch (error) {
        console.error('获取景区列表失败', error);
    }
};

const handleSearch = () => {
    currentPage.value = 1;
    fetchResourceProviders();
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
        description: row.description || '',
        contact_name: row.contact_name || '',
        contact_phone: row.contact_phone || '',
        contact_email: row.contact_email || '',
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
                    await axios.put(`/resource-providers/${editingId.value}`, form.value);
                    ElMessage.success('资源方更新成功');
                } else {
                    await axios.post('/resource-providers', form.value);
                    ElMessage.success('资源方创建成功');
                }
                dialogVisible.value = false;
                fetchResourceProviders();
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
            `确定要删除资源方"${row.name}"吗？删除后无法恢复！`,
            '提示',
            {
                type: 'warning',
                confirmButtonText: '确定删除',
                cancelButtonText: '取消'
            }
        );
        
        await axios.delete(`/resource-providers/${row.id}`);
        ElMessage.success('删除成功');
        fetchResourceProviders();
    } catch (error) {
        if (error !== 'cancel') {
            ElMessage.error('删除失败');
            console.error(error);
        }
    }
};

const handleManageScenicSpots = async (row) => {
    currentResourceProviderId.value = row.id;
    scenicSpotsDialogVisible.value = true;
    
    try {
        // 获取当前资源方绑定的景区
        const response = await axios.get(`/resource-providers/${row.id}`);
        const scenicSpots = response.data.data?.scenic_spots || [];
        scenicSpotsForm.value = {
            scenic_spot_ids: scenicSpots.map(spot => spot.id),
        };
    } catch (error) {
        ElMessage.error('获取景区信息失败');
        console.error(error);
        scenicSpotsForm.value = {
            scenic_spot_ids: [],
        };
    }
};

const handleSubmitScenicSpots = async () => {
    scenicSpotsSubmitting.value = true;
    try {
        await axios.post(`/resource-providers/${currentResourceProviderId.value}/scenic-spots`, {
            scenic_spot_ids: scenicSpotsForm.value.scenic_spot_ids,
        });
        ElMessage.success('景区绑定成功');
        scenicSpotsDialogVisible.value = false;
        fetchResourceProviders();
    } catch (error) {
        const message = error.response?.data?.message || '操作失败';
        ElMessage.error(message);
    } finally {
        scenicSpotsSubmitting.value = false;
    }
};

const resetForm = () => {
    form.value = {
        name: '',
        code: '',
        description: '',
        contact_name: '',
        contact_phone: '',
        contact_email: '',
        is_active: true,
    };
    formRef.value?.clearValidate();
};

const resetScenicSpotsForm = () => {
    scenicSpotsForm.value = {
        scenic_spot_ids: [],
    };
    scenicSpotsFormRef.value?.clearValidate();
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
    fetchResourceProviders();
    fetchAllScenicSpots();
});
</script>

<style scoped>
h2 {
    margin-bottom: 20px;
}
</style>


