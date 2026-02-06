<template>
    <div>
        <h2>系统打包异常订单管理</h2>
        <el-card>
            <!-- 筛选条件 -->
            <div class="filter-bar" style="margin-bottom: 20px;">
                <el-select
                    v-model="filters.status"
                    placeholder="处理状态"
                    clearable
                    style="width: 150px;"
                    @change="handleFilter"
                >
                    <el-option label="待处理" value="PENDING" />
                    <el-option label="处理中" value="PROCESSING" />
                    <el-option label="已解决" value="RESOLVED" />
                </el-select>
                <el-select
                    v-model="filters.exception_type"
                    placeholder="异常类型"
                    clearable
                    style="width: 200px; margin-left: 10px;"
                    @change="handleFilter"
                >
                    <el-option label="拆单处理失败" value="SPLIT_ORDER_FAILED" />
                    <el-option label="门票订单失败" value="TICKET_ORDER_FAILED" />
                    <el-option label="酒店订单失败" value="HOTEL_ORDER_FAILED" />
                    <el-option label="库存不足" value="INVENTORY_INSUFFICIENT" />
                    <el-option label="价格不匹配" value="PRICE_MISMATCH" />
                    <el-option label="API接口错误" value="API_ERROR" />
                    <el-option label="超时" value="TIMEOUT" />
                </el-select>
                <el-input
                    v-model="filters.search"
                    placeholder="订单号"
                    clearable
                    style="width: 200px; margin-left: 10px;"
                    @clear="handleFilter"
                    @keyup.enter="handleFilter"
                >
                    <template #prefix>
                        <el-icon><Search /></el-icon>
                    </template>
                </el-input>
                <el-button @click="handleFilter" style="margin-left: 10px;">筛选</el-button>
                <el-button @click="resetFilter">重置</el-button>
            </div>
            
            <!-- 异常订单列表 -->
            <el-table :data="exceptionOrders" v-loading="loading" border>
                <el-table-column prop="order.order_no" label="订单号" width="180">
                    <template #default="{ row }">
                        {{ row.order?.order_no || '-' }}
                    </template>
                </el-table-column>
                <el-table-column prop="order.ota_order_no" label="OTA订单号" width="180">
                    <template #default="{ row }">
                        {{ row.order?.ota_order_no || '-' }}
                    </template>
                </el-table-column>
                <el-table-column prop="exception_type" label="异常类型" width="150">
                    <template #default="{ row }">
                        <el-tag type="danger">{{ getExceptionTypeLabel(row.exception_type) }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="exception_message" label="异常信息" show-overflow-tooltip />
                <el-table-column prop="status" label="处理状态" width="120">
                    <template #default="{ row }">
                        <el-tag :type="getExceptionStatusType(row.status)">
                            {{ getExceptionStatusLabel(row.status) }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="handler.name" label="处理人" width="120">
                    <template #default="{ row }">
                        {{ row.handler?.name || '-' }}
                    </template>
                </el-table-column>
                <el-table-column prop="resolved_at" label="解决时间" width="180">
                    <template #default="{ row }">
                        {{ row.resolved_at ? formatDateTime(row.resolved_at) : '-' }}
                    </template>
                </el-table-column>
                <el-table-column prop="created_at" label="创建时间" width="180">
                    <template #default="{ row }">
                        {{ formatDateTime(row.created_at) }}
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="300" fixed="right">
                    <template #default="{ row }">
                        <el-button 
                            v-if="row.status === 'PENDING'" 
                            size="small" 
                            type="primary"
                            @click="handleStartProcessing(row)"
                        >
                            开始处理
                        </el-button>
                        <el-button 
                            v-if="row.status === 'PROCESSING'" 
                            size="small" 
                            type="success"
                            @click="handleResolve(row)"
                        >
                            解决异常
                        </el-button>
                        <el-button size="small" @click="handleViewDetail(row)">详情</el-button>
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
                @size-change="fetchExceptionOrders"
                @current-change="fetchExceptionOrders"
            />
        </el-card>

        <!-- 解决异常对话框 -->
        <el-dialog
            v-model="resolveDialogVisible"
            title="解决异常订单"
            width="600px"
            @close="resetResolveForm"
        >
            <el-form
                ref="resolveFormRef"
                :model="resolveForm"
                label-width="120px"
            >
                <el-form-item label="订单号">
                    <span>{{ currentExceptionOrder?.order?.order_no || '-' }}</span>
                </el-form-item>
                <el-form-item label="异常类型">
                    <el-tag type="danger">
                        {{ currentExceptionOrder ? getExceptionTypeLabel(currentExceptionOrder.exception_type) : '-' }}
                    </el-tag>
                </el-form-item>
                <el-form-item label="异常信息">
                    <div style="color: #606266;">{{ currentExceptionOrder?.exception_message || '-' }}</div>
                </el-form-item>
                <el-form-item label="处理备注" prop="remark">
                    <el-input
                        v-model="resolveForm.remark"
                        type="textarea"
                        :rows="4"
                        placeholder="请输入处理备注（可选）"
                    />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="resolveDialogVisible = false">取消</el-button>
                <el-button type="primary" @click="handleSubmitResolve" :loading="resolving">确定</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { ElMessage, ElMessageBox } from 'element-plus';
import { Search } from '@element-plus/icons-vue';
import { systemPkgExceptionOrdersApi } from '../../api/systemPkg';

const router = useRouter();

// 数据
const exceptionOrders = ref([]);
const loading = ref(false);
const currentPage = ref(1);
const pageSize = ref(15);
const total = ref(0);

// 筛选条件
const filters = reactive({
    status: null,
    exception_type: null,
    search: '',
});

// 解决异常对话框
const resolveDialogVisible = ref(false);
const resolving = ref(false);
const resolveFormRef = ref(null);
const currentExceptionOrder = ref(null);
const resolveForm = reactive({
    remark: '',
});

// 获取异常订单列表
const fetchExceptionOrders = async () => {
    loading.value = true;
    try {
        const params = {
            page: currentPage.value,
            per_page: pageSize.value,
        };
        
        if (filters.status) {
            params.status = filters.status;
        }
        
        if (filters.exception_type) {
            params.exception_type = filters.exception_type;
        }
        
        if (filters.search) {
            params.search = filters.search;
        }
        
        const response = await systemPkgExceptionOrdersApi.list(params);
        exceptionOrders.value = response.data.data || [];
        total.value = response.data.total || 0;
    } catch (error) {
        console.error('获取异常订单列表失败:', error);
        ElMessage.error('获取异常订单列表失败');
    } finally {
        loading.value = false;
    }
};

// 开始处理
const handleStartProcessing = async (row) => {
    try {
        await systemPkgExceptionOrdersApi.startProcessing(row.id);
        ElMessage.success('已开始处理异常订单');
        fetchExceptionOrders();
    } catch (error) {
        console.error('开始处理失败:', error);
        ElMessage.error(error.response?.data?.message || '开始处理失败');
    }
};

// 解决异常
const handleResolve = (row) => {
    currentExceptionOrder.value = row;
    resetResolveForm();
    resolveDialogVisible.value = true;
};

// 提交解决
const handleSubmitResolve = async () => {
    resolving.value = true;
    try {
        await systemPkgExceptionOrdersApi.resolve(currentExceptionOrder.value.id, {
            remark: resolveForm.remark,
        });
        ElMessage.success('异常订单已解决');
        resolveDialogVisible.value = false;
        fetchExceptionOrders();
    } catch (error) {
        console.error('解决异常失败:', error);
        ElMessage.error(error.response?.data?.message || '解决异常失败');
    } finally {
        resolving.value = false;
    }
};

// 查看详情
const handleViewDetail = (row) => {
    router.push(`/system-pkg-exception-orders/${row.id}/detail`);
};

// 筛选
const handleFilter = () => {
    currentPage.value = 1;
    fetchExceptionOrders();
};

// 重置筛选
const resetFilter = () => {
    Object.assign(filters, {
        status: null,
        exception_type: null,
        search: '',
    });
    currentPage.value = 1;
    fetchExceptionOrders();
};

// 重置解决表单
const resetResolveForm = () => {
    resolveForm.remark = '';
    if (resolveFormRef.value) {
        resolveFormRef.value.clearValidate();
    }
};

// 获取异常类型标签
const getExceptionTypeLabel = (type) => {
    const typeMap = {
        'SPLIT_ORDER_FAILED': '拆单处理失败',
        'TICKET_ORDER_FAILED': '门票订单失败',
        'HOTEL_ORDER_FAILED': '酒店订单失败',
        'INVENTORY_INSUFFICIENT': '库存不足',
        'PRICE_MISMATCH': '价格不匹配',
        'API_ERROR': 'API接口错误',
        'TIMEOUT': '超时',
    };
    return typeMap[type] || type;
};

// 获取异常状态标签
const getExceptionStatusLabel = (status) => {
    const statusMap = {
        'PENDING': '待处理',
        'PROCESSING': '处理中',
        'RESOLVED': '已解决',
    };
    return statusMap[status] || status;
};

// 获取异常状态类型
const getExceptionStatusType = (status) => {
    const typeMap = {
        'PENDING': 'warning',
        'PROCESSING': 'info',
        'RESOLVED': 'success',
    };
    return typeMap[status] || '';
};

// 格式化日期时间
const formatDateTime = (date) => {
    if (!date) return '-';
    return new Date(date).toLocaleString('zh-CN');
};

// 初始化
onMounted(() => {
    fetchExceptionOrders();
});
</script>

<style scoped>
.filter-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
}
</style>



