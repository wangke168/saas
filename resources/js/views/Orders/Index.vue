<template>
    <div>
        <h2>订单管理</h2>
        <el-card>
            <!-- 筛选条件 -->
            <div style="margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
                <el-select
                    v-model="filters.status"
                    placeholder="订单状态"
                    clearable
                    style="width: 150px;"
                    @change="handleFilter"
                >
                    <el-option label="已支付/待确认" value="paid_pending" />
                    <el-option label="确认中" value="confirming" />
                    <el-option label="预订成功" value="confirmed" />
                    <el-option label="预订失败/拒单" value="rejected" />
                    <el-option label="申请取消中" value="cancel_requested" />
                    <el-option label="取消拒绝" value="cancel_rejected" />
                    <el-option label="取消通过" value="cancel_approved" />
                    <el-option label="核销订单" value="verified" />
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
            
            <el-table :data="orders" v-loading="loading" border>
                <el-table-column prop="order_no" label="订单号" width="180" />
                <el-table-column prop="ota_order_no" label="OTA订单号" width="180" />
                <el-table-column label="OTA平台" width="120">
                    <template #default="{ row }">
                        {{ row.ota_platform?.name || '-' }}
                    </template>
                </el-table-column>
                <el-table-column label="产品" width="150">
                    <template #default="{ row }">
                        {{ row.product?.name || '-' }}
                    </template>
                </el-table-column>
                <el-table-column label="酒店" width="150">
                    <template #default="{ row }">
                        {{ row.hotel?.name || '-' }}
                    </template>
                </el-table-column>
                <el-table-column prop="status" label="状态" width="120">
                    <template #default="{ row }">
                        <el-tag :type="getStatusType(row.status)">{{ getStatusLabel(row.status) }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="check_in_date" label="入住日期" width="120" />
                <el-table-column prop="check_out_date" label="离店日期" width="120" />
                <el-table-column prop="room_count" label="房间数" width="80" />
                <el-table-column prop="total_amount" label="订单金额" width="120">
                    <template #default="{ row }">
                        ¥{{ formatPrice(row.total_amount) }}
                    </template>
                </el-table-column>
                <el-table-column prop="created_at" label="创建时间" width="180">
                    <template #default="{ row }">
                        {{ formatDate(row.created_at) }}
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="250" fixed="right">
                    <template #default="{ row }">
                        <el-button size="small" @click="viewDetail(row)">详情</el-button>
                        <el-button 
                            v-if="row.status === 'paid_pending'" 
                            size="small" 
                            type="primary" 
                            @click="handleUpdateStatus(row, 'confirming')"
                        >
                            确认
                        </el-button>
                        <el-dropdown v-else @command="(cmd) => handleUpdateStatus(row, cmd)">
                            <el-button size="small">
                                更新状态<el-icon class="el-icon--right"><arrow-down /></el-icon>
                            </el-button>
                            <template #dropdown>
                                <el-dropdown-menu>
                                    <el-dropdown-item 
                                        v-if="row.status === 'confirming'"
                                        command="confirmed"
                                    >
                                        标记为预订成功
                                    </el-dropdown-item>
                                    <el-dropdown-item 
                                        v-if="['paid_pending', 'confirming'].includes(row.status)"
                                        command="rejected"
                                    >
                                        拒单
                                    </el-dropdown-item>
                                    <el-dropdown-item 
                                        v-if="row.status === 'confirmed'"
                                        command="verified"
                                    >
                                        核销
                                    </el-dropdown-item>
                                </el-dropdown-menu>
                            </template>
                        </el-dropdown>
                    </template>
                </el-table-column>
            </el-table>
            <el-pagination
                v-model:current-page="currentPage"
                v-model:page-size="pageSize"
                :total="total"
                @current-change="fetchOrders"
                @size-change="fetchOrders"
                style="margin-top: 20px"
            />
        </el-card>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import axios from '../../utils/axios';
import { ElMessage, ElMessageBox } from 'element-plus';
import { ArrowDown } from '@element-plus/icons-vue';

const router = useRouter();

const orders = ref([]);
const loading = ref(false);
const currentPage = ref(1);
const pageSize = ref(15);
const total = ref(0);

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
        
        const response = await axios.get('/orders', { params });
        orders.value = response.data.data;
        total.value = response.data.total;
    } catch (error) {
        ElMessage.error('获取订单列表失败');
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

const formatPrice = (price) => {
    if (!price) return '0.00';
    return (parseFloat(price) / 100).toFixed(2);
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

const getStatusLabel = (status) => {
    const labels = {
        'paid_pending': '已支付/待确认',
        'confirming': '确认中',
        'confirmed': '预订成功',
        'rejected': '预订失败/拒单',
        'cancel_requested': '申请取消中',
        'cancel_rejected': '取消拒绝',
        'cancel_approved': '取消通过',
        'verified': '核销订单'
    };
    return labels[status] || status;
};

const getStatusType = (status) => {
    const types = {
        'paid_pending': 'warning',
        'confirming': 'info',
        'confirmed': 'success',
        'rejected': 'danger',
        'cancel_requested': 'warning',
        'cancel_rejected': 'info',
        'cancel_approved': 'info',
        'verified': 'success'
    };
    return types[status] || '';
};

const viewDetail = (row) => {
    // 跳转到订单详情页面（如果后续有详情页）
    // 目前先显示订单信息
    ElMessageBox.alert(
        `
        <div style="text-align: left;">
            <p><strong>订单号：</strong>${row.order_no}</p>
            <p><strong>OTA订单号：</strong>${row.ota_order_no || '-'}</p>
            <p><strong>状态：</strong>${getStatusLabel(row.status)}</p>
            <p><strong>入住日期：</strong>${row.check_in_date}</p>
            <p><strong>离店日期：</strong>${row.check_out_date}</p>
            <p><strong>房间数：</strong>${row.room_count}</p>
            <p><strong>订单金额：</strong>¥${formatPrice(row.total_amount)}</p>
            <p><strong>联系人：</strong>${row.contact_name}</p>
            <p><strong>联系电话：</strong>${row.contact_phone}</p>
        </div>
        `,
        '订单详情',
        {
            dangerouslyUseHTMLString: true,
        }
    );
};

const handleUpdateStatus = async (row, status) => {
    try {
        let remark = '';
        
        if (status === 'rejected') {
            const { value } = await ElMessageBox.prompt('请输入拒单原因', '拒单', {
                confirmButtonText: '确定',
                cancelButtonText: '取消',
                inputType: 'textarea',
            });
            remark = value;
        }
        
        await axios.post(`/orders/${row.id}/update-status`, {
            status,
            remark,
        });
        ElMessage.success('订单状态更新成功');
        fetchOrders();
    } catch (error) {
        if (error !== 'cancel') {
            const message = error.response?.data?.message || '更新订单状态失败';
            ElMessage.error(message);
        }
    }
};

onMounted(() => {
    fetchOrders();
});
</script>

