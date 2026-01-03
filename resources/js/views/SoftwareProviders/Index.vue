<template>
    <div>
        <h2>软件服务商管理</h2>
        <el-card>
            <div style="margin-bottom: 20px;">
                <el-button type="primary" @click="handleCreate">创建软件服务商</el-button>
            </div>
            
            <el-table :data="softwareProviders" v-loading="loading" border>
                <el-table-column prop="name" label="服务商名称" width="200" />
                <el-table-column prop="code" label="服务商编码" width="150" />
                <el-table-column prop="description" label="描述" show-overflow-tooltip />
                <el-table-column prop="api_type" label="API类型" width="120" />
                <el-table-column prop="api_url" label="API地址" width="200" show-overflow-tooltip />
                <el-table-column label="关联景区数量" width="120">
                    <template #default="{ row }">
                        {{ row.scenic_spots_count || 0 }}
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
                <el-table-column label="操作" width="150" fixed="right">
                    <template #default="{ row }">
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
                @size-change="fetchSoftwareProviders"
                @current-change="fetchSoftwareProviders"
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
                <el-form-item label="服务商名称" prop="name">
                    <el-input v-model="form.name" placeholder="请输入服务商名称" />
                </el-form-item>
                <el-form-item label="服务商编码" prop="code">
                    <el-input v-model="form.code" placeholder="请输入服务商编码（唯一）" :disabled="isEdit" />
                </el-form-item>
                <el-form-item label="描述" prop="description">
                    <el-input
                        v-model="form.description"
                        type="textarea"
                        :rows="4"
                        placeholder="请输入服务商描述"
                    />
                </el-form-item>
                <el-form-item label="API类型" prop="api_type">
                    <el-input v-model="form.api_type" placeholder="请输入API类型（可选）" />
                </el-form-item>
                <el-form-item label="API地址" prop="api_url">
                    <el-input v-model="form.api_url" placeholder="请输入服务商API地址（必填）" />
                    <div style="font-size: 12px; color: #909399; margin-top: 5px;">
                        每个服务商的API地址不同，请填写完整的URL
                    </div>
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

const softwareProviders = ref([]);
const loading = ref(false);
const submitting = ref(false);
const dialogVisible = ref(false);
const formRef = ref(null);
const currentPage = ref(1);
const pageSize = ref(15);
const total = ref(0);
const editingId = ref(null);

const isEdit = computed(() => editingId.value !== null);
const dialogTitle = computed(() => isEdit.value ? '编辑软件服务商' : '创建软件服务商');

const form = ref({
    name: '',
    code: '',
    description: '',
    api_type: '',
    api_url: '',
    is_active: true,
});

const rules = {
    name: [
        { required: true, message: '请输入服务商名称', trigger: 'blur' },
        { max: 255, message: '服务商名称不能超过255个字符', trigger: 'blur' }
    ],
    code: [
        { required: true, message: '请输入服务商编码', trigger: 'blur' },
        { max: 255, message: '服务商编码不能超过255个字符', trigger: 'blur' },
        { pattern: /^[a-zA-Z0-9_-]+$/, message: '服务商编码只能包含字母、数字、下划线和连字符', trigger: 'blur' }
    ],
    api_url: [
        { required: true, message: '请输入API地址', trigger: 'blur' },
        { type: 'url', message: '请输入有效的URL', trigger: ['blur', 'change'] },
        { max: 255, message: 'API地址不能超过255个字符', trigger: 'blur' }
    ],
};

const fetchSoftwareProviders = async () => {
    loading.value = true;
    try {
        const params = {
            page: currentPage.value,
            per_page: pageSize.value,
        };
        
        const response = await axios.get('/software-providers', { params });
        softwareProviders.value = response.data.data || [];
        total.value = response.data.total || 0;
    } catch (error) {
        ElMessage.error('获取软件服务商列表失败');
        console.error(error);
    } finally {
        loading.value = false;
    }
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
        api_type: row.api_type || '',
        api_url: row.api_url || '',
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
                    await axios.put(`/software-providers/${editingId.value}`, form.value);
                    ElMessage.success('软件服务商更新成功');
                } else {
                    await axios.post('/software-providers', form.value);
                    ElMessage.success('软件服务商创建成功');
                }
                dialogVisible.value = false;
                fetchSoftwareProviders();
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
            `确定要删除软件服务商"${row.name}"吗？删除后无法恢复！`,
            '提示',
            {
                type: 'warning',
                confirmButtonText: '确定删除',
                cancelButtonText: '取消'
            }
        );
        
        await axios.delete(`/software-providers/${row.id}`);
        ElMessage.success('删除成功');
        fetchSoftwareProviders();
    } catch (error) {
        if (error !== 'cancel') {
            const message = error.response?.data?.message || '删除失败';
            ElMessage.error(message);
            console.error(error);
        }
    }
};

const resetForm = () => {
    form.value = {
        name: '',
        code: '',
        description: '',
        api_type: '',
        api_url: '',
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
    fetchSoftwareProviders();
});
</script>

<style scoped>
h2 {
    margin-bottom: 20px;
}
</style>

