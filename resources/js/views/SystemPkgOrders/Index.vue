<template>
    <div>
        <h2>系统打包订单管理</h2>
        <el-card>
            <!-- 筛选条件 -->
            <div class="filter-bar" style="margin-bottom: 20px;">
                <el-select
                    v-model="filters.status"
                    placeholder="订单状态"
                    clearable
                    style="width: 150px;"
                    @change="handleFilter"
                >
                    <el-option label="已支付/待确认" value="PAID_PENDING" />
                    <el-option label="确认中" value="CONFIRMING" />
                    <el-option label="预订成功" value="CONFIRMED" />
                    <el-option label="预订失败/拒单" value="REJECTED" />
                    <el-option label="异常订单" value="EXCEPTION" />
                    <el-option label="申请取消中" value="CANCEL_REQUESTED" />
                    <el-option label="取消拒绝" value="CANCEL_REJECTED" />
                    <el-option label="取消通过" value="CANCEL_APPROVED" />
                </el-select>
                <el-select
                    v-model="filters.scenic_spot_id"
                    placeholder="筛选景区"
                    clearable
                    style="width: 150px; margin-left: 10px;"
                    @change="handleFilter"
                >
                    <el-option
                        v-for="spot in scenicSpots"
                        :key="spot.id"
                        :label="spot.name"
                        :value="spot.id"
                    />
                </el-select>
                <el-input
                    v-model="filters.search"
                    placeholder="订单号或OTA订单号"
                    clearable
                    style="width: 200px; margin-left: 10px;"
                    @clear="handleFilter"
                    @keyup.enter="handleFilter"
                >
                    <template #prefix>
                        <el-icon><Search /></el-icon>
                    </template>
                </el-input>
                <el-date-picker
                    v-model="filters.check_in_date_from"
                    type="date"
                    placeholder="入住日期开始"
                    format="YYYY-MM-DD"
                    value-format="YYYY-MM-DD"
                    style="width: 150px; margin-left: 10px;"
                    @change="handleFilter"
                />
                <el-date-picker
                    v-model="filters.check_in_date_to"
                    type="date"
                    placeholder="入住日期结束"
                    format="YYYY-MM-DD"
                    value-format="YYYY-MM-DD"
                    style="width: 150px; margin-left: 10px;"
                    @change="handleFilter"
                />
                <el-button @click="handleFilter" style="margin-left: 10px;">筛选</el-button>
                <el-button @click="resetFilter">重置</el-button>
            </div>
            
            <!-- 订单列表 -->
            <el-table :data="orders" v-loading="loading" border>
                <el-table-column prop="order_no" label="订单号" width="180" />
                <el-table-column prop="ota_order_no" label="OTA订单号" width="180">
                    <template #default="{ row }">
                        {{ row.ota_order_no || '-' }}
                    </template>
                </el-table-column>
                <el-table-column label="产品信息" width="200">
                    <template #default="{ row }">
                        <div>{{ row.sales_product?.product_name || '-' }}</div>
                        <el-tag 
                            v-if="row.sales_product?.scenic_spot?.name" 
                            size="small"
                            type="info"
                            style="margin-top: 5px;"
                        >
                            {{ row.sales_product.scenic_spot.name }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="OTA平台" width="120">
                    <template #default="{ row }">
                        {{ row.ota_platform?.name || '-' }}
                    </template>
                </el-table-column>
                <el-table-column prop="check_in_date" label="入住日期" width="120">
                    <template #default="{ row }">
                        {{ formatDate(row.check_in_date) }}
                    </template>
                </el-table-column>
                <el-table-column prop="check_out_date" label="离店日期" width="120">
                    <template #default="{ row }">
                        {{ formatDate(row.check_out_date) }}
                    </template>
                </el-table-column>
                <el-table-column prop="stay_days" label="入住天数" width="100">
                    <template #default="{ row }">
                        {{ row.stay_days || 1 }} 晚
                    </template>
                </el-table-column>
                <el-table-column prop="total_amount" label="订单金额" width="120">
                    <template #default="{ row }">
                        ¥{{ formatPrice(row.total_amount) }}
                    </template>
                </el-table-column>
                <el-table-column prop="status" label="订单状态" width="120">
                    <template #default="{ row }">
                        <el-tag :type="getStatusType(row.status)">
                            {{ getStatusLabel(row.status) }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="created_at" label="创建时间" width="180">
                    <template #default="{ row }">
                        {{ formatDateTime(row.created_at) }}
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="150" fixed="right">
                    <template #default="{ row }">
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
                @size-change="fetchOrders"
                @current-change="fetchOrders"
            />
        </el-card>
    </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { ElMessage } from 'element-plus';
import { Search } from '@element-plus/icons-vue';
import { systemPkgOrdersApi } from '../../api/systemPkg';
import axios from 'axios';

const router = useRouter();

// 数据
const orders = ref([]);
const scenicSpots = ref([]);
const loading = ref(false);
const currentPage = ref(1);
const pageSize = ref(15);
const total = ref(0);

// 筛选条件
const filters = reactive({
    status: null,
    scenic_spot_id: null,
    search: '',
    check_in_date_from: null,
    check_in_date_to: null,
});

// 获取景区列表
const fetchScenicSpots = async () => {
    try {
        const response = await axios.get('/api/scenic-spots');
        scenicSpots.value = response.data.data || [];
    } catch (error) {
        console.error('获取景区列表失败:', error);
    }
};

// 获取订单列表
const fetchOrders = async () => {
    loading.value = true;
    try {
        const params = {
            page: currentPage.value,
            per_page: pageSize.value,
        };
        
        if (filters.status) {
            params.status = filters.status;
        }
        
        if (filters.scenic_spot_id) {
            params.scenic_spot_id = filters.scenic_spot_id;
        }
        
        if (filters.search) {
            params.search = filters.search;
        }
        
        if (filters.check_in_date_from) {
            params.check_in_date_from = filters.check_in_date_from;
        }
        
        if (filters.check_in_date_to) {
            params.check_in_date_to = filters.check_in_date_to;
        }
        
        const response = await systemPkgOrdersApi.list(params);
        orders.value = response.data.data || [];
        total.value = response.data.total || 0;
    } catch (error) {
        console.error('获取订单列表失败:', error);
        ElMessage.error('获取订单列表失败');
    } finally {
        loading.value = false;
    }
};

// 查看详情
const handleViewDetail = (row) => {
    router.push(`/system-pkg-orders/${row.id}/detail`);
};

// 筛选
const handleFilter = () => {
    currentPage.value = 1;
    fetchOrders();
};

// 重置筛选
const resetFilter = () => {
    Object.assign(filters, {
        status: null,
        scenic_spot_id: null,
        search: '',
        check_in_date_from: null,
        check_in_date_to: null,
    });
    currentPage.value = 1;
    fetchOrders();
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
    fetchScenicSpots();
    fetchOrders();
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


