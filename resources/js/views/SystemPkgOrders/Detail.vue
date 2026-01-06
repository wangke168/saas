<template>
    <div>
        <el-page-header @back="goBack" title="返回订单列表">
            <template #content>
                <span>订单详情 - {{ order.order_no }}</span>
            </template>
        </el-page-header>

        <el-card v-loading="loading" style="margin-top: 20px;">
            <div v-if="order">
                <!-- 订单基本信息 -->
                <el-descriptions title="订单信息" :column="2" border style="margin-bottom: 20px;">
                    <el-descriptions-item label="订单号">{{ order.order_no }}</el-descriptions-item>
                    <el-descriptions-item label="OTA订单号">{{ order.ota_order_no || '-' }}</el-descriptions-item>
                    <el-descriptions-item label="OTA平台">{{ order.ota_platform?.name || '-' }}</el-descriptions-item>
                    <el-descriptions-item label="订单状态">
                        <el-tag :type="getStatusType(order.status)">
                            {{ getStatusLabel(order.status) }}
                        </el-tag>
                    </el-descriptions-item>
                    <el-descriptions-item label="产品名称">{{ order.sales_product?.product_name || '-' }}</el-descriptions-item>
                    <el-descriptions-item label="所属景区">{{ order.sales_product?.scenic_spot?.name || '-' }}</el-descriptions-item>
                    <el-descriptions-item label="入住日期">{{ formatDate(order.check_in_date) }}</el-descriptions-item>
                    <el-descriptions-item label="离店日期">{{ formatDate(order.check_out_date) }}</el-descriptions-item>
                    <el-descriptions-item label="入住天数">{{ order.stay_days || 1 }} 晚</el-descriptions-item>
                    <el-descriptions-item label="订单金额">¥{{ formatPrice(order.total_amount) }}</el-descriptions-item>
                    <el-descriptions-item label="结算金额">¥{{ formatPrice(order.settlement_amount) }}</el-descriptions-item>
                    <el-descriptions-item label="资源方订单号">{{ order.resource_order_no || '-' }}</el-descriptions-item>
                    <el-descriptions-item label="创建时间">{{ formatDateTime(order.created_at) }}</el-descriptions-item>
                </el-descriptions>

                <!-- 联系人信息 -->
                <el-descriptions title="联系人信息" :column="2" border style="margin-bottom: 20px;">
                    <el-descriptions-item label="联系人姓名">{{ order.contact_name || '-' }}</el-descriptions-item>
                    <el-descriptions-item label="联系电话">{{ order.contact_phone || '-' }}</el-descriptions-item>
                    <el-descriptions-item label="联系邮箱">{{ order.contact_email || '-' }}</el-descriptions-item>
                    <el-descriptions-item label="入住人数">{{ order.guest_count || 2 }} 人</el-descriptions-item>
                </el-descriptions>

                <!-- 拆单明细 -->
                <div class="section">
                    <h3>拆单明细</h3>
                    <el-tabs v-model="activeTab">
                        <el-tab-pane label="门票订单" name="tickets">
                            <el-table :data="ticketItems" border>
                                <el-table-column prop="resource_name" label="门票名称" width="200" />
                                <el-table-column prop="quantity" label="数量" width="100" />
                                <el-table-column prop="unit_price" label="单价(元)" width="120">
                                    <template #default="{ row }">
                                        {{ row.unit_price ? formatPrice(row.unit_price) : '-' }}
                                    </template>
                                </el-table-column>
                                <el-table-column prop="total_price" label="总价(元)" width="120">
                                    <template #default="{ row }">
                                        {{ row.total_price ? formatPrice(row.total_price) : '-' }}
                                    </template>
                                </el-table-column>
                                <el-table-column prop="status" label="状态" width="120">
                                    <template #default="{ row }">
                                        <el-tag :type="getItemStatusType(row.status)">
                                            {{ getItemStatusLabel(row.status) }}
                                        </el-tag>
                                    </template>
                                </el-table-column>
                                <el-table-column prop="resource_order_no" label="资源方订单号" width="180">
                                    <template #default="{ row }">
                                        {{ row.resource_order_no || '-' }}
                                    </template>
                                </el-table-column>
                                <el-table-column prop="processed_at" label="处理时间" width="180">
                                    <template #default="{ row }">
                                        {{ row.processed_at ? formatDateTime(row.processed_at) : '-' }}
                                    </template>
                                </el-table-column>
                                <el-table-column prop="error_message" label="错误信息" show-overflow-tooltip>
                                    <template #default="{ row }">
                                        {{ row.error_message || '-' }}
                                    </template>
                                </el-table-column>
                            </el-table>
                        </el-tab-pane>
                        <el-tab-pane label="酒店订单" name="hotels">
                            <el-table :data="hotelItems" border>
                                <el-table-column prop="resource_name" label="房型名称" width="200" />
                                <el-table-column prop="quantity" label="数量" width="100" />
                                <el-table-column prop="unit_price" label="单价(元)" width="120">
                                    <template #default="{ row }">
                                        {{ row.unit_price ? formatPrice(row.unit_price) : '-' }}
                                    </template>
                                </el-table-column>
                                <el-table-column prop="total_price" label="总价(元)" width="120">
                                    <template #default="{ row }">
                                        {{ row.total_price ? formatPrice(row.total_price) : '-' }}
                                    </template>
                                </el-table-column>
                                <el-table-column prop="status" label="状态" width="120">
                                    <template #default="{ row }">
                                        <el-tag :type="getItemStatusType(row.status)">
                                            {{ getItemStatusLabel(row.status) }}
                                        </el-tag>
                                    </template>
                                </el-table-column>
                                <el-table-column prop="retry_count" label="重试次数" width="100">
                                    <template #default="{ row }">
                                        {{ row.retry_count || 0 }} / {{ row.max_retries || 3 }}
                                    </template>
                                </el-table-column>
                                <el-table-column prop="resource_order_no" label="资源方订单号" width="180">
                                    <template #default="{ row }">
                                        {{ row.resource_order_no || '-' }}
                                    </template>
                                </el-table-column>
                                <el-table-column prop="processed_at" label="处理时间" width="180">
                                    <template #default="{ row }">
                                        {{ row.processed_at ? formatDateTime(row.processed_at) : '-' }}
                                    </template>
                                </el-table-column>
                                <el-table-column prop="error_message" label="错误信息" show-overflow-tooltip>
                                    <template #default="{ row }">
                                        {{ row.error_message || '-' }}
                                    </template>
                                </el-table-column>
                            </el-table>
                        </el-tab-pane>
                    </el-tabs>
                </div>

                <!-- 异常订单信息 -->
                <div v-if="exceptionOrders && exceptionOrders.length > 0" class="section" style="margin-top: 20px;">
                    <h3>异常信息</h3>
                    <el-alert
                        v-for="exception in exceptionOrders"
                        :key="exception.id"
                        :title="getExceptionTypeLabel(exception.exception_type)"
                        :description="exception.exception_message"
                        type="error"
                        show-icon
                        style="margin-bottom: 10px;"
                    >
                        <template #default>
                            <div>
                                <div style="margin-bottom: 10px;">{{ exception.exception_message }}</div>
                                <div v-if="exception.exception_data" style="font-size: 12px; color: #909399;">
                                    <pre>{{ JSON.stringify(exception.exception_data, null, 2) }}</pre>
                                </div>
                                <div style="margin-top: 10px;">
                                    <el-tag :type="getExceptionStatusType(exception.status)" size="small">
                                        {{ getExceptionStatusLabel(exception.status) }}
                                    </el-tag>
                                    <span v-if="exception.handler" style="margin-left: 10px;">
                                        处理人：{{ exception.handler?.name || '-' }}
                                    </span>
                                    <span v-if="exception.resolved_at" style="margin-left: 10px;">
                                        解决时间：{{ formatDateTime(exception.resolved_at) }}
                                    </span>
                                </div>
                                <div v-if="exception.remark" style="margin-top: 10px; color: #606266;">
                                    备注：{{ exception.remark }}
                                </div>
                            </div>
                        </template>
                    </el-alert>
                </div>
            </div>
        </el-card>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ElMessage } from 'element-plus';
import { systemPkgOrdersApi } from '../../api/systemPkg';

const route = useRoute();
const router = useRouter();

const order = ref({});
const loading = ref(false);
const activeTab = ref('tickets');

const orderId = computed(() => parseInt(route.params.id));

// 计算属性：门票订单项和酒店订单项
const ticketItems = computed(() => {
    return order.value.order_items?.filter(item => item.item_type === 'TICKET') || [];
});

const hotelItems = computed(() => {
    return order.value.order_items?.filter(item => item.item_type === 'HOTEL') || [];
});

const exceptionOrders = computed(() => {
    return order.value.exception_orders || [];
});

// 获取订单详情
const fetchOrder = async () => {
    loading.value = true;
    try {
        const response = await systemPkgOrdersApi.get(orderId.value);
        order.value = response.data.data || {};
    } catch (error) {
        console.error('获取订单详情失败:', error);
        ElMessage.error('获取订单详情失败');
    } finally {
        loading.value = false;
    }
};

// 返回
const goBack = () => {
    router.push('/system-pkg-orders');
};

// 获取状态标签
const getStatusLabel = (status) => {
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

// 获取状态类型
const getStatusType = (status) => {
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
    return (price / 100).toFixed(2);
};

// 初始化
onMounted(() => {
    fetchOrder();
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

