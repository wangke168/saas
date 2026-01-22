<template>
    <div>
        <el-page-header @back="goBack" title="返回异常订单列表">
            <template #content>
                <span>异常订单详情</span>
            </template>
        </el-page-header>

        <el-card v-loading="loading" style="margin-top: 20px;">
            <div v-if="exceptionOrder">
                <!-- 异常订单基本信息 -->
                <el-descriptions title="异常订单信息" :column="2" border style="margin-bottom: 20px;">
                    <el-descriptions-item label="订单号">
                        {{ exceptionOrder.order?.order_no || '-' }}
                    </el-descriptions-item>
                    <el-descriptions-item label="OTA订单号">
                        {{ exceptionOrder.order?.ota_order_no || '-' }}
                    </el-descriptions-item>
                    <el-descriptions-item label="异常类型">
                        <el-tag type="danger">{{ getExceptionTypeLabel(exceptionOrder.exception_type) }}</el-tag>
                    </el-descriptions-item>
                    <el-descriptions-item label="处理状态">
                        <el-tag :type="getExceptionStatusType(exceptionOrder.status)">
                            {{ getExceptionStatusLabel(exceptionOrder.status) }}
                        </el-tag>
                    </el-descriptions-item>
                    <el-descriptions-item label="异常信息" :span="2">
                        {{ exceptionOrder.exception_message || '-' }}
                    </el-descriptions-item>
                    <el-descriptions-item label="处理人">
                        {{ exceptionOrder.handler?.name || '-' }}
                    </el-descriptions-item>
                    <el-descriptions-item label="解决时间">
                        {{ exceptionOrder.resolved_at ? formatDateTime(exceptionOrder.resolved_at) : '-' }}
                    </el-descriptions-item>
                    <el-descriptions-item label="创建时间">
                        {{ formatDateTime(exceptionOrder.created_at) }}
                    </el-descriptions-item>
                    <el-descriptions-item label="备注" :span="2">
                        {{ exceptionOrder.remark || '-' }}
                    </el-descriptions-item>
                </el-descriptions>

                <!-- 异常数据 -->
                <div v-if="exceptionOrder.exception_data" class="section">
                    <h3>异常数据</h3>
                    <el-card>
                        <pre style="white-space: pre-wrap; word-wrap: break-word;">{{ JSON.stringify(exceptionOrder.exception_data, null, 2) }}</pre>
                    </el-card>
                </div>

                <!-- 关联订单信息 -->
                <div v-if="exceptionOrder.order" class="section" style="margin-top: 20px;">
                    <h3>关联订单信息</h3>
                    <el-descriptions :column="2" border>
                        <el-descriptions-item label="产品名称">
                            {{ exceptionOrder.order.sales_product?.product_name || '-' }}
                        </el-descriptions-item>
                        <el-descriptions-item label="所属景区">
                            {{ exceptionOrder.order.sales_product?.scenic_spot?.name || '-' }}
                        </el-descriptions-item>
                        <el-descriptions-item label="入住日期">
                            {{ formatDate(exceptionOrder.order.check_in_date) }}
                        </el-descriptions-item>
                        <el-descriptions-item label="离店日期">
                            {{ formatDate(exceptionOrder.order.check_out_date) }}
                        </el-descriptions-item>
                        <el-descriptions-item label="订单金额">
                            ¥{{ formatPrice(exceptionOrder.order.total_amount) }}
                        </el-descriptions-item>
                        <el-descriptions-item label="订单状态">
                            <el-tag :type="getOrderStatusType(exceptionOrder.order.status)">
                                {{ getOrderStatusLabel(exceptionOrder.order.status) }}
                            </el-tag>
                        </el-descriptions-item>
                    </el-descriptions>
                </div>

                <!-- 拆单明细 -->
                <div v-if="exceptionOrder.order?.order_items" class="section" style="margin-top: 20px;">
                    <h3>拆单明细</h3>
                    <el-table :data="exceptionOrder.order.order_items" border>
                        <el-table-column prop="item_type" label="类型" width="100">
                            <template #default="{ row }">
                                <el-tag :type="row.item_type === 'TICKET' ? 'success' : 'warning'">
                                    {{ row.item_type === 'TICKET' ? '门票' : '酒店' }}
                                </el-tag>
                            </template>
                        </el-table-column>
                        <el-table-column prop="resource_name" label="资源名称" width="200" />
                        <el-table-column prop="quantity" label="数量" width="100" />
                        <el-table-column prop="status" label="状态" width="120">
                            <template #default="{ row }">
                                <el-tag :type="getItemStatusType(row.status)">
                                    {{ getItemStatusLabel(row.status) }}
                                </el-tag>
                            </template>
                        </el-table-column>
                        <el-table-column prop="error_message" label="错误信息" show-overflow-tooltip>
                            <template #default="{ row }">
                                {{ row.error_message || '-' }}
                            </template>
                        </el-table-column>
                    </el-table>
                </div>

                <!-- 操作按钮 -->
                <div style="margin-top: 20px;" v-if="exceptionOrder.status === 'PENDING'">
                    <el-button type="primary" @click="handleStartProcessing">开始处理</el-button>
                </div>
                <div style="margin-top: 20px;" v-if="exceptionOrder.status === 'PROCESSING'">
                    <el-button type="success" @click="handleResolve">解决异常</el-button>
                </div>
            </div>
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
                <el-form-item label="异常类型">
                    <el-tag type="danger">
                        {{ getExceptionTypeLabel(exceptionOrder?.exception_type) }}
                    </el-tag>
                </el-form-item>
                <el-form-item label="异常信息">
                    <div style="color: #606266;">{{ exceptionOrder?.exception_message || '-' }}</div>
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
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ElMessage } from 'element-plus';
import { systemPkgExceptionOrdersApi } from '../../api/systemPkg';

const route = useRoute();
const router = useRouter();

const exceptionOrder = ref({});
const loading = ref(false);
const exceptionOrderId = computed(() => parseInt(route.params.id));

// 解决异常对话框
const resolveDialogVisible = ref(false);
const resolving = ref(false);
const resolveFormRef = ref(null);
const resolveForm = reactive({
    remark: '',
});

// 获取异常订单详情
const fetchExceptionOrder = async () => {
    loading.value = true;
    try {
        const response = await systemPkgExceptionOrdersApi.get(exceptionOrderId.value);
        exceptionOrder.value = response.data.data || {};
    } catch (error) {
        console.error('获取异常订单详情失败:', error);
        ElMessage.error('获取异常订单详情失败');
    } finally {
        loading.value = false;
    }
};

// 开始处理
const handleStartProcessing = async () => {
    try {
        await systemPkgExceptionOrdersApi.startProcessing(exceptionOrderId.value);
        ElMessage.success('已开始处理异常订单');
        fetchExceptionOrder();
    } catch (error) {
        console.error('开始处理失败:', error);
        ElMessage.error(error.response?.data?.message || '开始处理失败');
    }
};

// 解决异常
const handleResolve = () => {
    resetResolveForm();
    resolveDialogVisible.value = true;
};

// 提交解决
const handleSubmitResolve = async () => {
    resolving.value = true;
    try {
        await systemPkgExceptionOrdersApi.resolve(exceptionOrderId.value, {
            remark: resolveForm.remark,
        });
        ElMessage.success('异常订单已解决');
        resolveDialogVisible.value = false;
        fetchExceptionOrder();
    } catch (error) {
        console.error('解决异常失败:', error);
        ElMessage.error(error.response?.data?.message || '解决异常失败');
    } finally {
        resolving.value = false;
    }
};

// 返回
const goBack = () => {
    router.push('/system-pkg-exception-orders');
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

// 获取订单状态标签
const getOrderStatusLabel = (status) => {
    const statusMap = {
        'PAID_PENDING': '已支付/待确认',
        'CONFIRMING': '确认中',
        'CONFIRMED': '预订成功',
        'REJECTED': '预订失败/拒单',
        'EXCEPTION': '异常订单',
        'CANCEL_REQUESTED': '申请取消中',
        'CANCEL_REJECTED': '取消拒绝',
        'CANCEL_APPROVED': '取消通过',
    };
    return statusMap[status] || status;
};

// 获取订单状态类型
const getOrderStatusType = (status) => {
    const typeMap = {
        'PAID_PENDING': 'warning',
        'CONFIRMING': 'info',
        'CONFIRMED': 'success',
        'REJECTED': 'danger',
        'EXCEPTION': 'danger',
        'CANCEL_REQUESTED': 'warning',
        'CANCEL_REJECTED': 'danger',
        'CANCEL_APPROVED': 'info',
    };
    return typeMap[status] || '';
};

// 获取订单项状态标签
const getItemStatusLabel = (status) => {
    const statusMap = {
        'PENDING': '待处理',
        'PROCESSING': '处理中',
        'SUCCESS': '成功',
        'FAILED': '失败',
    };
    return statusMap[status] || status;
};

// 获取订单项状态类型
const getItemStatusType = (status) => {
    const typeMap = {
        'PENDING': 'warning',
        'PROCESSING': 'info',
        'SUCCESS': 'success',
        'FAILED': 'danger',
    };
    return typeMap[status] || '';
};

// 格式化日期
const formatDate = (date) => {
    if (!date) return '-';
    return new Date(date).toISOString().split('T')[0];
};

// 格式化日期时间
const formatDateTime = (date) => {
    if (!date) return '-';
    return new Date(date).toLocaleString('zh-CN');
};

// 格式化价格
const formatPrice = (price) => {
    if (!price) return '0.00';
    // 价格单位：数据库存储已经是元，直接格式化
    return parseFloat(price).toFixed(2);
};

// 初始化
onMounted(() => {
    fetchExceptionOrder();
});
</script>

<style scoped>
.section {
    margin-top: 20px;
}

.section h3 {
    margin-bottom: 10px;
    font-size: 16px;
    font-weight: 500;
}
</style>

