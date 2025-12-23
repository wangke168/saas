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
                <el-table-column label="操作" width="200" fixed="right">
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
    </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';
import axios from '../../utils/axios';
import { ElMessage, ElMessageBox } from 'element-plus';
import { Search } from '@element-plus/icons-vue';

const scenicSpots = ref([]);
const softwareProviders = ref([]);
const loading = ref(false);
const submitting = ref(false);
const dialogVisible = ref(false);
const formRef = ref(null);
const searchKeyword = ref('');
const currentPage = ref(1);
const pageSize = ref(15);
const total = ref(0);
const editingId = ref(null);

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

onMounted(() => {
    fetchScenicSpots();
    fetchSoftwareProviders();
});
</script>

<style scoped>
h2 {
    margin-bottom: 20px;
}
</style>
