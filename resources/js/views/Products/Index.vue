<template>
    <div>
        <h2>产品管理</h2>
        <el-card>
            <div style="margin-bottom: 20px;">
                <el-button type="primary" @click="handleCreate">创建产品</el-button>
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
                    placeholder="搜索产品名称或编码"
                    style="width: 300px; margin-left: 10px;"
                    clearable
                    @input="handleSearch"
                >
                    <template #prefix>
                        <el-icon><Search /></el-icon>
                    </template>
                </el-input>
            </div>
            
            <el-table :data="products" v-loading="loading" border>
                <el-table-column prop="name" label="产品名称" width="200" />
                <el-table-column prop="code" label="产品编码" width="150" />
                <el-table-column label="所属景区" width="150">
                    <template #default="{ row }">
                        {{ row.scenic_spot?.name || '-' }}
                    </template>
                </el-table-column>
                <el-table-column prop="description" label="描述" show-overflow-tooltip />
                <el-table-column prop="price_source" label="价格来源" width="120">
                    <template #default="{ row }">
                        <el-tag :type="row.price_source === 'manual' ? 'primary' : 'success'">
                            {{ row.price_source === 'manual' ? '人工维护' : '接口推送' }}
                        </el-tag>
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
                <el-table-column label="操作" width="350" fixed="right">
                    <template #default="{ row }">
                        <el-button size="small" @click="handleViewDetail(row)">详情</el-button>
                        <el-button size="small" @click="handleEdit(row)">编辑</el-button>
                        <el-button size="small" type="success" @click="handleExport(row)" :loading="exporting[row.id]">
                            <el-icon><Download /></el-icon>
                            导出
                        </el-button>
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
                @size-change="fetchProducts"
                @current-change="fetchProducts"
            />
        </el-card>

        <!-- 创建/编辑产品对话框 -->
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
                    >
                        <el-option
                            v-for="spot in scenicSpots"
                            :key="spot.id"
                            :label="spot.name"
                            :value="spot.id"
                        />
                    </el-select>
                </el-form-item>
                <el-form-item label="产品名称" prop="name">
                    <el-input v-model="form.name" placeholder="请输入产品名称" />
                </el-form-item>
                <el-form-item label="产品编码" prop="code">
                    <el-input v-model="form.code" placeholder="请输入产品编码（唯一）" :disabled="isEdit" />
                </el-form-item>
                <el-form-item label="描述" prop="description">
                    <el-input
                        v-model="form.description"
                        type="textarea"
                        :rows="4"
                        placeholder="请输入产品描述"
                    />
                </el-form-item>
                <el-form-item label="价格来源" prop="price_source">
                    <el-select
                        v-model="form.price_source"
                        placeholder="请选择价格来源"
                        style="width: 100%"
                    >
                        <el-option label="人工维护" value="manual" />
                        <el-option label="接口推送" value="api" />
                    </el-select>
                    <span style="margin-left: 10px; color: #909399; font-size: 12px;">
                        选择接口推送后，价格将通过资源方接口自动更新
                    </span>
                </el-form-item>
                <el-form-item label="入住天数" prop="stay_days">
                    <el-input-number
                        v-model="form.stay_days"
                        :min="1"
                        :max="30"
                        placeholder="请输入入住天数（可为空）"
                        style="width: 100%"
                    />
                    <span style="margin-left: 10px; color: #909399; font-size: 12px;">
                        产品需要连续入住的天数，为空或1表示单晚产品
                    </span>
                </el-form-item>
                <el-form-item label="销售开始日期" prop="sale_start_date">
                    <el-date-picker
                        v-model="form.sale_start_date"
                        type="date"
                        placeholder="选择销售开始日期"
                        format="YYYY-MM-DD"
                        value-format="YYYY-MM-DD"
                        style="width: 100%"
                        :disabled-date="(date) => form.sale_end_date && date > new Date(form.sale_end_date)"
                    />
                    <span style="margin-left: 10px; color: #909399; font-size: 12px;">
                        产品开始销售的日期（必填）
                    </span>
                </el-form-item>
                <el-form-item label="销售结束日期" prop="sale_end_date">
                    <el-date-picker
                        v-model="form.sale_end_date"
                        type="date"
                        placeholder="选择销售结束日期"
                        format="YYYY-MM-DD"
                        value-format="YYYY-MM-DD"
                        style="width: 100%"
                        :disabled-date="(date) => form.sale_start_date && date < new Date(form.sale_start_date)"
                    />
                    <span style="margin-left: 10px; color: #909399; font-size: 12px;">
                        产品结束销售的日期（必填），不能早于开始日期
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
import { useRouter } from 'vue-router';
import axios from '../../utils/axios';
import { ElMessage, ElMessageBox } from 'element-plus';
import { Search, Download } from '@element-plus/icons-vue';
import { useAuthStore } from '../../stores/auth';

const authStore = useAuthStore();
const router = useRouter();

const products = ref([]);
const scenicSpots = ref([]);
const loading = ref(false);
const submitting = ref(false);
const exporting = ref({}); // 改为对象，记录每个产品的导出状态
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
const dialogTitle = computed(() => isEdit.value ? '编辑产品' : '创建产品');

const form = ref({
    scenic_spot_id: null,
    name: '',
    code: '',
    description: '',
    price_source: 'manual',
    stay_days: null,
    sale_start_date: null,
    sale_end_date: null,
    is_active: true,
});

const validateSaleEndDate = (rule, value, callback) => {
    if (!value) {
        callback(new Error('请选择销售结束日期'));
    } else if (form.value.sale_start_date && value < form.value.sale_start_date) {
        callback(new Error('销售结束日期不能早于开始日期'));
    } else {
        callback();
    }
};

const rules = {
    scenic_spot_id: [
        { required: true, message: '请选择所属景区', trigger: 'change' }
    ],
    name: [
        { required: true, message: '请输入产品名称', trigger: 'blur' },
        { max: 255, message: '产品名称不能超过255个字符', trigger: 'blur' }
    ],
    code: [
        { required: true, message: '请输入产品编码', trigger: 'blur' },
        { pattern: /^[a-zA-Z0-9_-]+$/, message: '产品编码只能包含字母、数字、下划线和连字符', trigger: 'blur' }
    ],
    price_source: [
        { required: true, message: '请选择价格来源', trigger: 'change' }
    ],
    sale_start_date: [
        { required: true, message: '请选择销售开始日期', trigger: 'change' }
    ],
    sale_end_date: [
        { validator: validateSaleEndDate, trigger: 'change' }
    ],
};

const fetchProducts = async () => {
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
        
        const response = await axios.get('/products', { params });
        // Laravel 分页器返回的数据结构：{ data: [...], total: ..., per_page: ..., current_page: ... }
        if (response.data && response.data.data) {
            products.value = response.data.data || [];
            total.value = response.data.total || 0;
        } else {
            // 兼容不同的返回格式
            products.value = response.data || [];
            total.value = products.value.length;
        }
    } catch (error) {
        ElMessage.error('获取产品列表失败');
        console.error(error);
    } finally {
        loading.value = false;
    }
};

const fetchScenicSpots = async () => {
    try {
        // 如果是运营，直接使用用户绑定的景区
        if (authStore.user?.role !== 'admin') {
            // 确保用户信息已加载
            if (!authStore.user || !authStore.user.scenic_spots || authStore.user.scenic_spots.length === 0) {
                await authStore.fetchUser();
            }
            scenicSpots.value = authStore.user?.scenic_spots || [];
            
            // 如果还是没有景区，提示用户
            if (scenicSpots.value.length === 0) {
                console.warn('运营用户未绑定任何景区');
            }
        } else {
            // 超级管理员获取所有景区
            const response = await axios.get('/scenic-spots');
            scenicSpots.value = response.data.data || [];
        }
    } catch (error) {
        console.error('获取景区列表失败', error);
        // 如果API失败，尝试使用用户绑定的景区
        if (authStore.user?.scenic_spots && authStore.user.scenic_spots.length > 0) {
            scenicSpots.value = authStore.user.scenic_spots;
        } else {
            // 如果还是没有，尝试重新获取用户信息
            try {
                await authStore.fetchUser();
                scenicSpots.value = authStore.user?.scenic_spots || [];
            } catch (e) {
                console.error('获取用户信息失败', e);
            }
        }
    }
};

const handleSearch = () => {
    currentPage.value = 1;
    fetchProducts();
};

const handleFilter = () => {
    currentPage.value = 1;
    fetchProducts();
};

const handleCreate = async () => {
    editingId.value = null;
    resetForm();
    // 确保景区列表已加载（特别是运营用户）
    if (scenicSpots.value.length === 0) {
        await fetchScenicSpots();
    }
    // 如果是运营用户且没有景区，提示用户
    if (authStore.user?.role !== 'admin' && scenicSpots.value.length === 0) {
        ElMessage.warning('您未绑定任何景区，请联系管理员为您分配景区');
        return;
    }
    dialogVisible.value = true;
};

const handleViewDetail = (row) => {
    // 使用路由跳转到产品详情页面
    router.push(`/products/${row.id}/detail`);
};

const handleEdit = (row) => {
    editingId.value = row.id;
    form.value = {
        scenic_spot_id: row.scenic_spot_id,
        name: row.name,
        code: row.code,
        description: row.description || '',
        price_source: row.price_source || 'manual',
        stay_days: row.stay_days || null,
        sale_start_date: row.sale_start_date || null,
        sale_end_date: row.sale_end_date || null,
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
                // 准备提交数据，确保空值转换为 null
                const submitData = {
                    ...form.value,
                    stay_days: form.value.stay_days || null,
                    sale_start_date: form.value.sale_start_date || null,
                    sale_end_date: form.value.sale_end_date || null,
                };
                
                if (isEdit.value) {
                    await axios.put(`/products/${editingId.value}`, submitData);
                    ElMessage.success('产品更新成功');
                } else {
                    await axios.post('/products', submitData);
                    ElMessage.success('产品创建成功');
                }
                dialogVisible.value = false;
                resetForm();
                // 延迟一下再刷新列表，确保后端数据已更新
                setTimeout(() => {
                    fetchProducts();
                }, 100);
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
            `确定要删除产品"${row.name}"吗？删除后无法恢复！`,
            '提示',
            {
                type: 'warning',
                confirmButtonText: '确定删除',
                cancelButtonText: '取消'
            }
        );
        
        await axios.delete(`/products/${row.id}`);
        ElMessage.success('删除成功');
        fetchProducts();
    } catch (error) {
        if (error !== 'cancel') {
            const message = error.response?.data?.message || '删除失败';
            ElMessage.error(message);
        }
    }
};

const resetForm = () => {
    form.value = {
        scenic_spot_id: null,
        name: '',
        code: '',
        description: '',
        price_source: 'manual',
        stay_days: null,
        sale_start_date: null,
        sale_end_date: null,
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

const handleExport = async (product) => {
    exporting.value[product.id] = true;
    try {
        const response = await axios.get(`/products/${product.id}/export`, {
            responseType: 'blob',
            // 处理错误响应（可能是 JSON 格式）
            validateStatus: (status) => status < 500, // 允许 4xx 状态码
        });
        
        // 检查响应类型
        const contentType = response.headers['content-type'] || '';
        
        // 如果是 JSON 格式，说明是错误响应
        if (contentType.includes('application/json') || response.status >= 400) {
            // 尝试解析 JSON 错误信息
            const text = await response.data.text();
            let errorMessage = '导出失败';
            
            try {
                const errorData = JSON.parse(text);
                errorMessage = errorData.message || errorMessage;
            } catch (e) {
                // 如果解析失败，使用默认消息
                errorMessage = text || errorMessage;
            }
            
            ElMessage.error(errorMessage);
            console.error('导出失败', errorMessage);
            return;
        }
        
        // 创建下载链接
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `product_${product.code}_${new Date().toISOString().slice(0, 10)}.csv`);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
        
        ElMessage.success('导出成功');
    } catch (error) {
        // 处理网络错误或其他异常
        let message = '导出失败';
        
        if (error.response) {
            // 尝试获取错误消息
            if (error.response.data) {
                if (typeof error.response.data === 'string') {
                    try {
                        const errorData = JSON.parse(error.response.data);
                        message = errorData.message || message;
                    } catch (e) {
                        message = error.response.data || message;
                    }
                } else if (error.response.data.message) {
                    message = error.response.data.message;
                }
            }
        } else if (error.message) {
            message = error.message;
        }
        
        ElMessage.error(message);
        console.error('导出失败', error);
    } finally {
        exporting.value[product.id] = false;
    }
};

onMounted(() => {
    fetchProducts();
    fetchScenicSpots();
});
</script>

<style scoped>
h2 {
    margin-bottom: 20px;
}
</style>
