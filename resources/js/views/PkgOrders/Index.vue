<template>
    <div class="pkg-order-management-container">
        <h2>打包订单管理</h2>
        <el-card>
            <!-- 筛选条件 -->
            <div class="filter-bar">
                <el-select
                    v-model="filters.status"
                    placeholder="订单状态"
                    clearable
                    style="width: 150px;"
                    @change="handleFilter"
                >
                    <el-option label="已支付" value="paid" />
                    <el-option label="已确认" value="confirmed" />
                    <el-option label="失败" value="failed" />
                    <el-option label="已取消" value="cancelled" />
                </el-select>
                <el-input
                    v-model="filters.order_no"
                    placeholder="订单号"
                    clearable
                    style="width: 200px;"
                    @clear="handleFilter"
                    @keyup.enter="handleFilter"
                />
                <el-input
                    v-model="filters.ota_order_no"
                    placeholder="OTA订单号"
                    clearable
                    style="width: 200px;"
                    @clear="handleFilter"
                    @keyup.enter="handleFilter"
                />
                <el-date-picker
                    v-model="filters.check_in_date"
                    type="date"
                    placeholder="入住日期"
                    format="YYYY-MM-DD"
                    value-format="YYYY-MM-DD"
                    style="width: 150px;"
                    @change="handleFilter"
                />
                <el-button @click="handleFilter">筛选</el-button>
                <el-button @click="resetFilter">重置</el-button>
            </div>
            
            <!-- 打包订单列表 -->
            <div v-loading="loading" class="pkg-order-list">
                <el-table
                    :data="orders"
                    style="width: 100%"
                    :expand-row-keys="expandedRows"
                    row-key="id"
                    @expand-change="handleExpandChange"
                >
                    <!-- 展开列 -->
                    <el-table-column type="expand">
                        <template #default="{ row }">
                            <div class="order-items-container">
                                <h4 class="items-title">订单项列表</h4>
                                <el-table
                                    :data="row.items"
                                    style="width: 100%"
                                    border
                                >
                                    <el-table-column prop="id" label="ID" width="80" />
                                    <el-table-column label="类型" width="100">
                                        <template #default="{ row: item }">
                                            <el-tag :type="getItemTypeTagType(item.item_type)">
                                                {{ getItemTypeLabel(item.item_type) }}
                                            </el-tag>
                                        </template>
                                    </el-table-column>
                                    <el-table-column prop="resource_name" label="资源名称" />
                                    <el-table-column prop="quantity" label="数量" width="80" />
                                    <el-table-column label="状态" width="120">
                                        <template #default="{ row: item }">
                                            <el-tag :type="getItemStatusTagType(item.status)">
                                                {{ getItemStatusLabel(item.status) }}
                                            </el-tag>
                                        </template>
                                    </el-table-column>
                                    <el-table-column prop="resource_order_no" label="资源方订单号" />
                                    <el-table-column prop="error_message" label="错误信息" show-overflow-tooltip />
                                    <el-table-column prop="processed_at" label="处理时间" width="180">
                                        <template #default="{ row: item }">
                                            {{ formatDateTime(item.processed_at) }}
                                        </template>
                                    </el-table-column>
                                </el-table>
                            </div>
                        </template>
                    </el-table-column>
                    
                    <!-- 订单号 -->
                    <el-table-column prop="order_no" label="订单号" width="180" />
                    
                    <!-- OTA订单号 -->
                    <el-table-column prop="ota_order_no" label="OTA订单号" width="200" />
                    
                    <!-- 打包产品 -->
                    <el-table-column label="打包产品" min-width="200">
                        <template #default="{ row }">
                            <div>
                                <div class="product-name">{{ row.product?.product_name || '-' }}</div>
                                <el-tag 
                                    v-if="row.product?.scenic_spot?.name" 
                                    size="small"
                                    type="info"
                                    class="scenic-tag"
                                >
                                    {{ row.product.scenic_spot.name }}
                                </el-tag>
                            </div>
                        </template>
                    </el-table-column>
                    
                    <!-- 酒店信息 -->
                    <el-table-column label="酒店/房型" width="200">
                        <template #default="{ row }">
                            <div>
                                <div>{{ row.hotel?.name || '-' }}</div>
                                <div class="room-type">{{ row.room_type?.name || '-' }}</div>
                            </div>
                        </template>
                    </el-table-column>
                    
                    <!-- 入住日期 -->
                    <el-table-column label="入住日期" width="120">
                        <template #default="{ row }">
                            {{ formatDate(row.check_in_date) }}
                        </template>
                    </el-table-column>
                    
                    <!-- 入住天数 -->
                    <el-table-column prop="stay_days" label="入住天数" width="100" />
                    
                    <!-- 订单状态 -->
                    <el-table-column label="状态" width="120">
                        <template #default="{ row }">
                            <el-tag :type="getStatusTagType(row.status)">
                                {{ getStatusLabel(row.status) }}
                            </el-tag>
                        </template>
                    </el-table-column>
                    
                    <!-- 订单总额 -->
                    <el-table-column label="订单总额" width="120" align="right">
                        <template #default="{ row }">
                            ¥{{ formatPrice(row.total_amount) }}
                        </template>
                    </el-table-column>
                    
                    <!-- 下单时间 -->
                    <el-table-column label="下单时间" width="180">
                        <template #default="{ row }">
                            {{ formatDateTime(row.created_at) }}
                        </template>
                    </el-table-column>
                    
                    <!-- 操作 -->
                    <el-table-column label="操作" width="120" fixed="right">
                        <template #default="{ row }">
                            <el-button
                                type="primary"
                                link
                                size="small"
                                @click="viewDetail(row)"
                            >
                                查看详情
                            </el-button>
                        </template>
                    </el-table-column>
                </el-table>
            </div>
            
            <!-- 分页 -->
            <el-pagination
                v-model:current-page="currentPage"
                v-model:page-size="pageSize"
                :total="total"
                @current-change="fetchOrders"
                @size-change="fetchOrders"
                class="pagination-container"
                :layout="paginationLayout"
            />
        </el-card>
    </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { useRouter } from 'vue-router';
import axios from '../../utils/axios';
import { ElMessage } from 'element-plus';

const router = useRouter();

const orders = ref([]);
const loading = ref(false);
const currentPage = ref(1);
const pageSize = ref(15);
const total = ref(0);
const expandedRows = ref([]);

// 响应式分页布局
const paginationLayout = ref('total, sizes, prev, pager, next, jumper');

// 监听窗口大小变化，调整分页布局
const updatePaginationLayout = () => {
    if (window.innerWidth <= 768) {
        paginationLayout.value = 'prev, pager, next';
    } else if (window.innerWidth <= 1200) {
        paginationLayout.value = 'total, prev, pager, next';
    } else {
        paginationLayout.value = 'total, sizes, prev, pager, next, jumper';
    }
};

const filters = ref({
    status: null,
    order_no: '',
    ota_order_no: '',
    check_in_date: null,
});

const fetchOrders = async () => {
    loading.value = true;
    try {
        const params = {
            page: currentPage.value,
            per_page: pageSize.value,
        };
        
        // 添加筛选条件
        if (filters.value.status) {
            params.status = filters.value.status;
        }
        if (filters.value.order_no) {
            params.order_no = filters.value.order_no;
        }
        if (filters.value.ota_order_no) {
            params.ota_order_no = filters.value.ota_order_no;
        }
        if (filters.value.check_in_date) {
            params.check_in_date = filters.value.check_in_date;
        }
        
        const response = await axios.get('/pkg-orders', { params });
        orders.value = response.data.data;
        total.value = response.data.total;
    } catch (error) {
        ElMessage.error('获取打包订单列表失败');
    } finally {
        loading.value = false;
    }
};

const handleFilter = () => {
    currentPage.value = 1;
    fetchOrders();
};

const resetFilter = () => {
    filters.value = {
        status: null,
        order_no: '',
        ota_order_no: '',
        check_in_date: null,
    };
    handleFilter();
};

const handleExpandChange = (row, expandedRows) => {
    // 可以在这里处理展开/收起逻辑
};

const viewDetail = (order) => {
    router.push(`/pkg-orders/${order.id}`);
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
    fetchOrders();
    updatePaginationLayout();
    window.addEventListener('resize', updatePaginationLayout);
});

onUnmounted(() => {
    window.removeEventListener('resize', updatePaginationLayout);
});
</script>

<style scoped>
.pkg-order-management-container {
    padding: 20px;
}

.filter-bar {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.pkg-order-list {
    margin-bottom: 20px;
}

.order-items-container {
    padding: 20px;
    background-color: #f5f7fa;
}

.items-title {
    margin-bottom: 15px;
    font-size: 16px;
    font-weight: 600;
    color: #303133;
}

.product-name {
    font-weight: 500;
    margin-bottom: 5px;
}

.scenic-tag {
    margin-top: 5px;
}

.room-type {
    font-size: 12px;
    color: #909399;
    margin-top: 5px;
}

.pagination-container {
    margin-top: 20px;
    display: flex;
    justify-content: flex-end;
}
</style>

