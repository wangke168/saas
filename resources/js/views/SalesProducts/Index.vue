<template>
    <div>
        <h2>系统打包产品管理</h2>
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
                    <el-option label="上架" :value="1" />
                    <el-option label="下架" :value="0" />
                </el-select>
                <el-input
                    v-model="searchKeyword"
                    placeholder="搜索产品名称或OTA编码"
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
                <el-table-column prop="product_name" label="产品名称" width="200" />
                <el-table-column prop="ota_product_code" label="OTA产品编码" width="200">
                    <template #default="{ row }">
                        <el-tag type="info">{{ row.ota_product_code }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="所属景区" width="150">
                    <template #default="{ row }">
                        {{ row.scenic_spot?.name || '-' }}
                    </template>
                </el-table-column>
                <el-table-column prop="stay_days" label="入住天数" width="100">
                    <template #default="{ row }">
                        {{ row.stay_days || 1 }} 晚
                    </template>
                </el-table-column>
                <el-table-column prop="description" label="描述" show-overflow-tooltip />
                <el-table-column prop="status" label="状态" width="100">
                    <template #default="{ row }">
                        <el-tag :type="row.status === 1 ? 'success' : 'danger'">
                            {{ row.status === 1 ? '上架' : '下架' }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="created_at" label="创建时间" width="180">
                    <template #default="{ row }">
                        {{ formatDate(row.created_at) }}
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="300" fixed="right">
                    <template #default="{ row }">
                        <el-button size="small" @click="handleViewDetail(row)">详情</el-button>
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
                <el-form-item label="OTA产品编码" prop="ota_product_code">
                    <el-input 
                        v-model="form.ota_product_code" 
                        placeholder="请输入OTA产品编码（必须以PKG_开头）"
                        :disabled="isEdit"
                    />
                    <div style="font-size: 12px; color: #909399; margin-top: 5px;">
                        必须以 PKG_ 开头，例如：PKG_SHANGHAI_001
                    </div>
                </el-form-item>
                <el-form-item label="产品名称" prop="product_name">
                    <el-input v-model="form.product_name" placeholder="请输入产品名称" />
                </el-form-item>
                <el-form-item label="入住天数" prop="stay_days">
                    <el-input-number 
                        v-model="form.stay_days" 
                        :min="1" 
                        :max="30"
                        style="width: 100%"
                    />
                    <div style="font-size: 12px; color: #909399; margin-top: 5px;">
                        默认1晚，可根据实际需求设置
                    </div>
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
                <el-form-item label="描述" prop="description">
                    <el-input
                        v-model="form.description"
                        type="textarea"
                        :rows="3"
                        placeholder="请输入产品描述"
                    />
                </el-form-item>
                <el-form-item label="状态" prop="status">
                    <el-radio-group v-model="form.status">
                        <el-radio :value="1">上架</el-radio>
                        <el-radio :value="0">下架</el-radio>
                    </el-radio-group>
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
import { ref, reactive, onMounted, computed } from 'vue';
import { useRouter } from 'vue-router';
import { ElMessage, ElMessageBox } from 'element-plus';
import { Search } from '@element-plus/icons-vue';
import { salesProductsApi } from '../../api/systemPkg';
import axios from '../../utils/axios';
import { useAuthStore } from '../../stores/auth';

const router = useRouter();
const authStore = useAuthStore();

// 数据
const products = ref([]);
const scenicSpots = ref([]);
const loading = ref(false);
const currentPage = ref(1);
const pageSize = ref(15);
const total = ref(0);

// 筛选条件
const filterScenicSpotId = ref(null);
const filterStatus = ref(null);
const searchKeyword = ref('');

// 对话框
const dialogVisible = ref(false);
const dialogTitle = ref('创建产品');
const isEdit = ref(false);
const editingId = ref(null);
const submitting = ref(false);
const formRef = ref(null);

// 表单数据
const form = reactive({
    scenic_spot_id: null,
    ota_product_code: '',
    product_name: '',
    stay_days: 1,
    sale_start_date: null,
    sale_end_date: null,
    description: '',
    status: 1,
});

// 表单验证规则
const rules = {
    scenic_spot_id: [
        { 
            required: true, 
            message: '请选择所属景区', 
            trigger: 'change',
            type: 'number',
            validator: (rule, value, callback) => {
                if (!value && value !== 0) {
                    callback(new Error('请选择所属景区'));
                } else {
                    callback();
                }
            }
        }
    ],
    ota_product_code: [
        { required: true, message: '请输入OTA产品编码', trigger: 'blur' },
        { 
            pattern: /^PKG_/,
            message: 'OTA产品编码必须以PKG_开头',
            trigger: 'blur'
        }
    ],
    product_name: [{ required: true, message: '请输入产品名称', trigger: 'blur' }],
    stay_days: [{ required: true, message: '请输入入住天数', trigger: 'blur' }],
    sale_start_date: [
        { required: true, message: '请选择销售开始日期', trigger: 'change' }
    ],
    sale_end_date: [
        { required: true, message: '请选择销售结束日期', trigger: 'change' },
        {
            validator: (rule, value, callback) => {
                if (!value) {
                    callback(new Error('请选择销售结束日期'));
                } else if (form.sale_start_date && value < form.sale_start_date) {
                    callback(new Error('销售结束日期不能早于开始日期'));
                } else {
                    callback();
                }
            },
            trigger: 'change'
        }
    ],
};

// 获取景区列表
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
            try {
                const response = await axios.get('/scenic-spots', {
                    params: { per_page: 1000 }
                });
                scenicSpots.value = response.data.data || [];
            } catch (apiError) {
                // 如果API调用失败（可能是401），尝试使用用户信息中的景区
                if (authStore.user?.scenic_spots && authStore.user.scenic_spots.length > 0) {
                    scenicSpots.value = authStore.user.scenic_spots;
                } else {
                    throw apiError;
                }
            }
        }
    } catch (error) {
        // 401错误由axios拦截器处理，不需要显示错误消息
        if (error.response?.status !== 401) {
            console.error('获取景区列表失败:', error);
        }
        
        // 如果API失败，尝试使用用户绑定的景区
        if (authStore.user?.scenic_spots && authStore.user.scenic_spots.length > 0) {
            scenicSpots.value = authStore.user.scenic_spots;
        }
    }
};

// 获取产品列表
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
            params.status = filterStatus.value;
        }
        
        if (searchKeyword.value) {
            params.search = searchKeyword.value;
        }
        
        const response = await salesProductsApi.list(params);
        products.value = response.data.data || [];
        total.value = response.data.total || 0;
    } catch (error) {
        console.error('获取产品列表失败:', error);
        ElMessage.error('获取产品列表失败');
    } finally {
        loading.value = false;
    }
};

// 创建产品
const handleCreate = () => {
    isEdit.value = false;
    editingId.value = null;
    dialogTitle.value = '创建产品';
    resetForm();
    dialogVisible.value = true;
};

// 编辑产品
const handleEdit = async (row) => {
    isEdit.value = true;
    editingId.value = row.id;
    dialogTitle.value = '编辑产品';
    try {
        const response = await salesProductsApi.get(row.id);
        const data = response.data.data;
        Object.assign(form, {
            scenic_spot_id: data.scenic_spot_id,
            ota_product_code: data.ota_product_code,
            product_name: data.product_name,
            stay_days: data.stay_days || 1,
            sale_start_date: data.sale_start_date || null,
            sale_end_date: data.sale_end_date || null,
            description: data.description || '',
            status: data.status,
        });
        dialogVisible.value = true;
    } catch (error) {
        console.error('获取产品详情失败:', error);
        ElMessage.error('获取产品详情失败');
    }
};

// 查看详情
const handleViewDetail = (row) => {
    router.push(`/sales-products/${row.id}/detail`);
};

// 提交表单
const handleSubmit = async () => {
    if (!formRef.value) return;
    
    await formRef.value.validate(async (valid) => {
        if (!valid) return;
        
        submitting.value = true;
        try {
            if (isEdit.value) {
                // 编辑时不允许修改 ota_product_code
                const { ota_product_code, ...updateData } = form;
                await salesProductsApi.update(editingId.value, updateData);
                ElMessage.success('产品更新成功');
            } else {
                await salesProductsApi.create(form);
                ElMessage.success('产品创建成功');
            }
            dialogVisible.value = false;
            fetchProducts();
        } catch (error) {
            console.error('保存产品失败:', error);
            ElMessage.error(error.response?.data?.message || '保存产品失败');
        } finally {
            submitting.value = false;
        }
    });
};

// 删除产品
const handleDelete = async (row) => {
    try {
        await ElMessageBox.confirm('确定要删除该产品吗？', '提示', {
            confirmButtonText: '确定',
            cancelButtonText: '取消',
            type: 'warning',
        });
        
        await salesProductsApi.delete(row.id);
        ElMessage.success('产品删除成功');
        fetchProducts();
    } catch (error) {
        if (error !== 'cancel') {
            console.error('删除产品失败:', error);
            ElMessage.error(error.response?.data?.message || '删除产品失败');
        }
    }
};

// 筛选
const handleFilter = () => {
    currentPage.value = 1;
    fetchProducts();
};

// 搜索
const handleSearch = () => {
    currentPage.value = 1;
    fetchProducts();
};

// 重置表单
const resetForm = () => {
    Object.assign(form, {
        scenic_spot_id: null,
        ota_product_code: '',
        product_name: '',
        stay_days: 1,
        sale_start_date: null,
        sale_end_date: null,
        description: '',
        status: 1,
    });
    if (formRef.value) {
        formRef.value.clearValidate();
    }
};

// 格式化日期
const formatDate = (date) => {
    if (!date) return '-';
    return new Date(date).toLocaleString('zh-CN');
};

// 初始化
onMounted(async () => {
    // 确保用户信息已加载
    if (!authStore.user) {
        await authStore.fetchUser();
    }
    await fetchScenicSpots();
    fetchProducts();
});
</script>

<style scoped>
</style>

