<template>
    <div class="pkg-order-detail-container">
        <el-card>
            <template #header>
                <div class="card-header">
                    <span>打包订单详情</span>
                    <el-button @click="goBack">返回列表</el-button>
                </div>
            </template>
            
            <div v-loading="loading" class="order-detail">
                <div v-if="order" class="detail-content">
                    <!-- 订单基本信息 -->
                    <el-card class="info-card">
                        <template #header>
                            <h3>订单基本信息</h3>
                        </template>
                        <el-descriptions :column="2" border>
                            <el-descriptions-item label="订单号">
                                {{ order.order_no }}
                            </el-descriptions-item>
                            <el-descriptions-item label="OTA订单号">
                                {{ order.ota_order_no || '-' }}
                            </el-descriptions-item>
                            <el-descriptions-item label="订单状态">
                                <el-tag :type="getStatusTagType(order.status)">
                                    {{ getStatusLabel(order.status) }}
                                </el-tag>
                            </el-descriptions-item>
                            <el-descriptions-item label="OTA平台">
                                {{ order.ota_platform?.name || '-' }}
                            </el-descriptions-item>
                            <el-descriptions-item label="打包产品">
                                {{ order.product?.product_name || '-' }}
                            </el-descriptions-item>
                            <el-descriptions-item label="景区">
                                {{ order.product?.scenic_spot?.name || '-' }}
                            </el-descriptions-item>
                            <el-descriptions-item label="订单总额">
                                ¥{{ formatPrice(order.total_amount) }}
                            </el-descriptions-item>
                            <el-descriptions-item label="结算金额">
                                ¥{{ formatPrice(order.settlement_amount) }}
                            </el-descriptions-item>
                            <el-descriptions-item label="下单时间">
                                {{ formatDateTime(order.created_at) }}
                            </el-descriptions-item>
                            <el-descriptions-item label="支付时间">
                                {{ formatDateTime(order.paid_at) }}
                            </el-descriptions-item>
                            <el-descriptions-item label="确认时间">
                                {{ formatDateTime(order.confirmed_at) }}
                            </el-descriptions-item>
                        </el-descriptions>
                    </el-card>
                    
                    <!-- 酒店信息 -->
                    <el-card class="info-card">
                        <template #header>
                            <h3>酒店信息</h3>
                        </template>
                        <el-descriptions :column="2" border>
                            <el-descriptions-item label="酒店名称">
                                {{ order.hotel?.name || '-' }}
                            </el-descriptions-item>
                            <el-descriptions-item label="房型名称">
                                {{ order.room_type?.name || '-' }}
                            </el-descriptions-item>
                            <el-descriptions-item label="入住日期">
                                {{ formatDate(order.check_in_date) }}
                            </el-descriptions-item>
                            <el-descriptions-item label="离店日期">
                                {{ formatDate(order.check_out_date) }}
                            </el-descriptions-item>
                            <el-descriptions-item label="入住天数">
                                {{ order.stay_days }} 天
                            </el-descriptions-item>
                        </el-descriptions>
                    </el-card>
                    
                    <!-- 联系人信息 -->
                    <el-card class="info-card">
                        <template #header>
                            <h3>联系人信息</h3>
                        </template>
                        <el-descriptions :column="2" border>
                            <el-descriptions-item label="联系人姓名">
                                {{ order.contact_name || '-' }}
                            </el-descriptions-item>
                            <el-descriptions-item label="联系电话">
                                {{ order.contact_phone || '-' }}
                            </el-descriptions-item>
                            <el-descriptions-item label="联系邮箱">
                                {{ order.contact_email || '-' }}
                            </el-descriptions-item>
                        </el-descriptions>
                    </el-card>
                    
                    <!-- 订单项列表（树状结构） -->
                    <el-card class="info-card">
                        <template #header>
                            <h3>订单项列表</h3>
                        </template>
                        <el-table
                            :data="order.items"
                            style="width: 100%"
                            border
                        >
                            <el-table-column prop="id" label="ID" width="80" />
                            <el-table-column label="类型" width="100">
                                <template #default="{ row }">
                                    <el-tag :type="getItemTypeTagType(row.item_type)">
                                        {{ getItemTypeLabel(row.item_type) }}
                                    </el-tag>
                                </template>
                            </el-table-column>
                            <el-table-column prop="resource_name" label="资源名称" min-width="200" />
                            <el-table-column prop="quantity" label="数量" width="80" />
                            <el-table-column label="单价" width="120" align="right">
                                <template #default="{ row }">
                                    ¥{{ formatPrice(row.unit_price) }}
                                </template>
                            </el-table-column>
                            <el-table-column label="总价" width="120" align="right">
                                <template #default="{ row }">
                                    ¥{{ formatPrice(row.total_price) }}
                                </template>
                            </el-table-column>
                            <el-table-column label="状态" width="120">
                                <template #default="{ row }">
                                    <el-tag :type="getItemStatusTagType(row.status)">
                                        {{ getItemStatusLabel(row.status) }}
                                    </el-tag>
                                </template>
                            </el-table-column>
                            <el-table-column prop="resource_order_no" label="资源方订单号" min-width="180" />
                            <el-table-column prop="error_message" label="错误信息" min-width="200" show-overflow-tooltip />
                            <el-table-column prop="processed_at" label="处理时间" width="180">
                                <template #default="{ row }">
                                    {{ formatDateTime(row.processed_at) }}
                                </template>
                            </el-table-column>
                        </el-table>
                    </el-card>
                </div>
            </div>
        </el-card>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import axios from '../../utils/axios';
import { ElMessage } from 'element-plus';

const route = useRoute();
const router = useRouter();

const order = ref(null);
const loading = ref(false);

const fetchOrderDetail = async () => {
    loading.value = true;
    try {
        const response = await axios.get(`/pkg-orders/${route.params.id}`);
        order.value = response.data;
    } catch (error) {
        ElMessage.error('获取打包订单详情失败');
    } finally {
        loading.value = false;
    }
};

const goBack = () => {
    router.push('/pkg-orders');
};

// 格式化价格
const formatPrice = (price) => {
    if (!price) return '0.00';
    return (parseFloat(price) / 100).toFixed(2);
};

// 格式化日期
const formatDate = (date) => {
    if (!date) return '';
    if (typeof date === 'string') {
        return date.split('T')[0];
    }
    return date;
};

// 格式化日期时间
const formatDateTime = (dateTime) => {
    if (!dateTime) return '';
    const date = new Date(dateTime);
    return date.toLocaleString('zh-CN', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
};

// 获取订单状态标签类型
const getStatusTagType = (status) => {
    const statusMap = {
        'paid': 'warning',
        'confirmed': 'success',
        'failed': 'danger',
        'cancelled': 'info',
    };
    return statusMap[status] || '';
};

// 获取订单状态标签文本
const getStatusLabel = (status) => {
    const statusMap = {
        'paid': '已支付',
        'confirmed': '已确认',
        'failed': '失败',
        'cancelled': '已取消',
    };
    return statusMap[status] || status;
};

// 获取订单项类型标签类型
const getItemTypeTagType = (itemType) => {
    const typeMap = {
        'TICKET': 'primary',
        'HOTEL': 'success',
    };
    return typeMap[itemType] || '';
};

// 获取订单项类型标签文本
const getItemTypeLabel = (itemType) => {
    const typeMap = {
        'TICKET': '门票',
        'HOTEL': '酒店',
    };
    return typeMap[itemType] || itemType;
};

// 获取订单项状态标签类型
const getItemStatusTagType = (status) => {
    const statusMap = {
        'pending': 'info',
        'processing': 'warning',
        'success': 'success',
        'failed': 'danger',
        'pending_manual': 'warning',
    };
    return statusMap[status] || '';
};

// 获取订单项状态标签文本
const getItemStatusLabel = (status) => {
    const statusMap = {
        'pending': '待处理',
        'processing': '处理中',
        'success': '成功',
        'failed': '失败',
        'pending_manual': '待人工处理',
    };
    return statusMap[status] || status;
};

onMounted(() => {
    fetchOrderDetail();
});
</script>

<style scoped>
.pkg-order-detail-container {
    padding: 20px;
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.order-detail {
    min-height: 400px;
}

.detail-content {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.info-card {
    margin-bottom: 20px;
}

.info-card h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
}
</style>

