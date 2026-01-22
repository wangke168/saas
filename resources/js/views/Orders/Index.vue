<template>
    <div class="order-management-container">
        <h2>订单管理</h2>
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
                <el-input
                    v-model="filters.contact_name"
                    placeholder="客人姓名"
                    clearable
                    style="width: 150px;"
                    @clear="handleFilter"
                    @keyup.enter="handleFilter"
                />
                <el-select
                    v-model="filters.ota_platform_id"
                    placeholder="渠道"
                    clearable
                    style="width: 150px;"
                    @change="handleFilter"
                >
                    <el-option
                        v-for="platform in otaPlatforms"
                        :key="platform.id"
                        :label="platform.name"
                        :value="platform.id"
                    />
                </el-select>
                <el-select
                    v-model="filters.scenic_spot_id"
                    placeholder="景区"
                    clearable
                    style="width: 150px;"
                    @change="handleFilter"
                >
                    <el-option
                        v-for="spot in scenicSpots"
                        :key="spot.id"
                        :label="spot.name"
                        :value="spot.id"
                    />
                </el-select>
                <el-date-picker
                    v-model="filters.check_in_date_range"
                    type="daterange"
                    range-separator="至"
                    start-placeholder="入住日期开始"
                    end-placeholder="入住日期结束"
                    format="YYYY-MM-DD"
                    value-format="YYYY-MM-DD"
                    style="width: 240px;"
                    @change="handleFilter"
                />
                <el-date-picker
                    v-model="filters.created_at_range"
                    type="daterange"
                    range-separator="至"
                    start-placeholder="预定日期开始"
                    end-placeholder="预定日期结束"
                    format="YYYY-MM-DD"
                    value-format="YYYY-MM-DD"
                    style="width: 240px;"
                    @change="handleFilter"
                />
                <el-button @click="handleFilter">筛选</el-button>
                <el-button @click="resetFilter">重置</el-button>
            </div>

            <!-- 订单列表 -->
            <div v-loading="loading" class="order-list">
                <div
                    v-for="order in orders"
                    :key="order.id"
                    class="order-item"
                >
                    <!-- 顶部信息条 -->
                    <div class="order-header">
                        <span class="header-item">
                            <span class="label">系统订单号：</span>
                            <span class="value">{{ order.order_no }}</span>
                        </span>
                        <span class="header-item">
                            <span class="label">渠道订单号：</span>
                            <span class="value">{{ order.ota_order_no || '-' }}</span>
                        </span>
                        <span class="header-item">
                            <span class="label">下单时间：</span>
                            <span class="value">{{ formatDateTime(order.created_at) }}</span>
                        </span>
                        <span class="header-item">
                            <span class="label">来源：</span>
                            <span class="value">{{ order.ota_platform?.name || '-' }}</span>
                        </span>
                    </div>

                    <!-- 主体内容区 -->
                    <div class="order-body">
                        <!-- 第一列：选择框 -->
                        <div class="body-col col-checkbox">
                            <el-checkbox
                                :model-value="selectedOrders.includes(order.id)"
                                @update:model-value="toggleOrderSelection(order.id, $event)"
                            />
                        </div>

                        <!-- 第二列：产品名称及景区标签 -->
                        <div class="body-col col-product">
                            <div class="product-name">{{ order.product?.name || '-' }}</div>
                        <el-tag
                                v-if="order.product?.scenic_spot?.name"
                            size="small"
                                type="info"
                                class="scenic-tag"
                        >
                                {{ order.product.scenic_spot.name }}
                        </el-tag>
                        </div>

                        <!-- 第三列：酒店信息 -->
                        <div class="body-col col-hotel">
                            <div class="hotel-name">{{ order.hotel?.name || '-' }}</div>
                            <div class="hotel-details">
                                <div v-if="order.room_type?.name" class="room-type">{{ order.room_type.name }}</div>
                                <div class="date-range">
                                    <template v-if="order.check_in_date || order.check_out_date">
                                        {{ formatDateRange(order.check_in_date, order.check_out_date, order.room_count) }}
                    </template>
                                    <template v-else>-</template>
                                </div>
                            </div>
                        </div>

                        <!-- 第四列：入住人及联系电话 -->
                        <div class="body-col col-guest">
                            <div class="guest-name">{{ order.contact_name || '-' }}</div>
                            <div class="guest-phone">{{ order.contact_phone || '-' }}</div>
                        </div>

                        <!-- 第五列：金额信息 -->
                        <div class="body-col col-amount">
                            <div class="amount-item">
                                <span class="amount-label">销售金额：</span>
                                <span class="amount-value">¥{{ formatPrice(order.total_amount) }}</span>
                            </div>
                            <div class="amount-item">
                                <span class="amount-label">结算金额：</span>
                                <span class="amount-value">¥{{ formatPrice(order.settlement_amount) }}</span>
                            </div>
                            <div class="amount-item">
                                <span class="amount-label">预估收入：</span>
                                <span class="amount-value estimated">¥{{ formatPrice(calculateEstimatedRevenue(order)) }}</span>
                            </div>
                        </div>

                        <!-- 第六列：订单状态 -->
                        <div class="body-col col-status">
                            <el-tag
                                :type="getStatusType(order.status)"
                                size="small"
                                class="status-tag"
                            >
                                {{ getStatusLabel(order.status) }}
                            </el-tag>
                        </div>

                        <!-- 操作列 -->
                        <div class="body-col col-actions">
                            <el-button
                                size="small"
                                text
                                type="primary"
                                @click="viewDetail(order)"
                                class="action-btn-text"
                            >
                                详情
                            </el-button>

                        <!-- 接单按钮（待确认或确认中状态） -->
                        <el-button
                                v-if="['paid_pending', 'confirming'].includes(order.status)"
                            size="small"
                            type="success"
                                @click="handleConfirmOrder(order)"
                                :loading="operating[order.id] === 'confirm'"
                                class="action-btn-primary"
                        >
                            接单
                        </el-button>

                        <!-- 拒单按钮（待确认或确认中状态） -->
                        <el-button
                                v-if="['paid_pending', 'confirming'].includes(order.status)"
                            size="small"
                            type="danger"
                                @click="handleRejectOrder(order)"
                                :loading="operating[order.id] === 'reject'"
                                class="action-btn-primary"
                        >
                            拒单
                        </el-button>

                        <!-- 核销按钮（已确认状态） -->
                        <el-button
                                v-if="order.status === 'confirmed'"
                            size="small"
                            type="primary"
                                @click="handleVerifyOrder(order)"
                                :loading="operating[order.id] === 'verify'"
                                class="action-btn-primary"
                        >
                            核销
                        </el-button>

                        <!-- 同意取消按钮（申请取消中状态） -->
                        <el-button
                                v-if="order.status === 'cancel_requested' || order.status?.value === 'cancel_requested'"
                            size="small"
                            type="success"
                                @click="handleApproveCancel(order)"
                                :loading="operating[order.id] === 'approveCancel'"
                                class="action-btn-primary"
                        >
                            同意取消
                        </el-button>

                        <!-- 拒绝取消按钮（申请取消中状态） -->
                        <el-button
                                v-if="order.status === 'cancel_requested' || order.status?.value === 'cancel_requested'"
                            size="small"
                            type="danger"
                                @click="handleRejectCancel(order)"
                                :loading="operating[order.id] === 'rejectCancel'"
                                class="action-btn-primary"
                        >
                            拒绝取消
                        </el-button>
                        </div>
                    </div>
                </div>
            </div>

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
import { ElMessage, ElMessageBox } from 'element-plus';

const router = useRouter();

const orders = ref([]);
const loading = ref(false);
const currentPage = ref(1);
const pageSize = ref(15);
const total = ref(0);
const operating = ref({});
const selectedOrders = ref([]);
const otaPlatforms = ref([]);
const scenicSpots = ref([]);

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
    contact_name: '',
    ota_platform_id: null,
    scenic_spot_id: null,
    check_in_date_range: null,
    created_at_range: null,
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
        if (filters.value.contact_name) {
            params.contact_name = filters.value.contact_name;
        }
        if (filters.value.ota_platform_id) {
            params.ota_platform_id = filters.value.ota_platform_id;
        }
        if (filters.value.scenic_spot_id) {
            params.scenic_spot_id = filters.value.scenic_spot_id;
        }
        // 入住日期范围
        if (filters.value.check_in_date_range && filters.value.check_in_date_range.length === 2) {
            params.check_in_date_start = filters.value.check_in_date_range[0];
            params.check_in_date_end = filters.value.check_in_date_range[1];
        }
        // 预定日期范围
        if (filters.value.created_at_range && filters.value.created_at_range.length === 2) {
            params.created_at_start = filters.value.created_at_range[0];
            params.created_at_end = filters.value.created_at_range[1];
        }

        const response = await axios.get('/orders', { params });
        orders.value = response.data.data;
        total.value = response.data.total;

        // 调试：检查订单日期数据
        if (orders.value.length > 0) {
            const firstOrder = orders.value[0];
            console.log('订单日期数据示例:', {
                check_in_date: firstOrder.check_in_date,
                check_out_date: firstOrder.check_out_date,
                check_in_date_type: typeof firstOrder.check_in_date,
                check_out_date_type: typeof firstOrder.check_out_date,
            });
        }
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
        contact_name: '',
        ota_platform_id: null,
        scenic_spot_id: null,
        check_in_date_range: null,
        created_at_range: null,
    };
    handleFilter();
};

const formatPrice = (price) => {
    if (!price) return '0.00';
    // 价格单位：数据库存储已经是元，直接格式化
    return parseFloat(price).toFixed(2);
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

const formatDateTime = (date) => {
    if (!date) return '';
    const d = new Date(date);
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    const hour = String(d.getHours()).padStart(2, '0');
    const minute = String(d.getMinutes()).padStart(2, '0');
    return `${year}-${month}-${day} ${hour}:${minute}`;
};

const formatDateRange = (checkIn, checkOut, roomCount) => {
    try {
        // 如果两个日期都不存在，返回空
        if (!checkIn && !checkOut) return '';

        // 处理日期字符串，提取日期部分（处理可能带时间的情况）
        const parseDate = (dateValue) => {
            if (!dateValue) return null;

            // 如果是字符串，提取日期部分
            if (typeof dateValue === 'string') {
                // 处理 YYYY-MM-DD 格式
                const dateMatch = dateValue.match(/^(\d{4}-\d{2}-\d{2})/);
                if (dateMatch) {
                    return dateMatch[1];
                }
                // 处理其他格式
                return dateValue.split(' ')[0].split('T')[0];
            }

            // 如果是日期对象，转换为字符串
            if (dateValue instanceof Date) {
                const year = dateValue.getFullYear();
                const month = String(dateValue.getMonth() + 1).padStart(2, '0');
                const day = String(dateValue.getDate()).padStart(2, '0');
                return `${year}-${month}-${day}`;
            }

            return String(dateValue);
        };

        const checkInStr = parseDate(checkIn);
        const checkOutStr = parseDate(checkOut);

        // 如果只有一个日期，只显示该日期
        if (!checkInStr && checkOutStr) {
            return `离店：${checkOutStr.replace(/-/g, '/')}`;
        }
        if (checkInStr && !checkOutStr) {
            return `入住：${checkInStr.replace(/-/g, '/')}`;
        }

        // 如果两个日期都存在，计算日期范围
        if (checkInStr && checkOutStr) {
            const checkInDate = new Date(checkInStr + 'T00:00:00');
            const checkOutDate = new Date(checkOutStr + 'T00:00:00');

            // 验证日期是否有效
            if (isNaN(checkInDate.getTime()) || isNaN(checkOutDate.getTime())) {
                return `${checkInStr.replace(/-/g, '/')}~${checkOutStr.replace(/-/g, '/')}`;
            }

            const nights = Math.ceil((checkOutDate - checkInDate) / (1000 * 60 * 60 * 24));
            const nightsText = nights > 0 ? `${nights}晚` : '1晚';

            const formatDate = (date) => {
                const d = new Date(date);
                const year = d.getFullYear();
                const month = String(d.getMonth() + 1).padStart(2, '0');
                const day = String(d.getDate()).padStart(2, '0');
                return `${year}/${month}/${day}`;
            };

            return `${formatDate(checkInDate)}~${formatDate(checkOutDate)} ${nightsText} ${roomCount || 1}间`;
        }

        return '';
    } catch (error) {
        console.error('格式化日期范围错误:', error, { checkIn, checkOut, roomCount });
        return '';
    }
};

const toggleOrderSelection = (orderId, checked) => {
    if (checked) {
        if (!selectedOrders.value.includes(orderId)) {
            selectedOrders.value.push(orderId);
        }
    } else {
        selectedOrders.value = selectedOrders.value.filter(id => id !== orderId);
    }
};

const calculateEstimatedRevenue = (order) => {
    if (!order.total_amount || !order.settlement_amount) return 0;
    return (parseFloat(order.total_amount) - parseFloat(order.settlement_amount));
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

const handleConfirmOrder = async (row) => {
    try {
        await ElMessageBox.confirm(
            row.hotel?.scenic_spot?.is_system_connected
                ? '确定要接单吗？系统将自动调用资源方接口确认订单。'
                : '确定要接单吗？',
            '接单确认',
            {
                type: 'info',
                confirmButtonText: '确定',
                cancelButtonText: '取消',
            }
        );

        operating.value[row.id] = 'confirm';
        const response = await axios.post(`/orders/${row.id}/confirm`, {
            remark: '',
        });

        if (response.data.success) {
            ElMessage.success(response.data.message || '接单成功');
            fetchOrders();
        } else {
            ElMessage.error(response.data.message || '接单失败');
        }
    } catch (error) {
        if (error !== 'cancel') {
            const message = error.response?.data?.message || '接单失败';
            ElMessage.error(message);
        }
    } finally {
        operating.value[row.id] = null;
    }
};

const handleRejectOrder = async (row) => {
    try {
        const { value: reason } = await ElMessageBox.prompt(
            '请输入拒单原因',
            '拒单',
            {
                confirmButtonText: '确定',
                cancelButtonText: '取消',
                inputType: 'textarea',
                inputPlaceholder: '请输入拒单原因',
                inputValidator: (value) => {
                    if (!value || value.trim().length === 0) {
                        return '拒单原因不能为空';
                    }
                    return true;
                },
            }
        );

        operating.value[row.id] = 'reject';
        const response = await axios.post(`/orders/${row.id}/reject`, {
            reason: reason.trim(),
        });

        if (response.data.success) {
            ElMessage.success(response.data.message || '拒单成功');
            fetchOrders();
        } else {
            ElMessage.error(response.data.message || '拒单失败');
        }
    } catch (error) {
        if (error !== 'cancel') {
            const message = error.response?.data?.message || '拒单失败';
            ElMessage.error(message);
        }
    } finally {
        operating.value[row.id] = null;
    }
};

const handleVerifyOrder = async (row) => {
    try {
        const verifyData = {
            use_start_date: row.check_in_date,
            use_end_date: row.check_out_date,
            use_quantity: row.room_count,
            passengers: [],
            vouchers: [],
        };

        await ElMessageBox.confirm(
            row.hotel?.scenic_spot?.is_system_connected
                ? '确定要核销订单吗？系统将自动调用资源方接口核销订单。'
                : '确定要核销订单吗？',
            '核销确认',
            {
                type: 'info',
                confirmButtonText: '确定',
                cancelButtonText: '取消',
            }
        );

        operating.value[row.id] = 'verify';
        const response = await axios.post(`/orders/${row.id}/verify`, verifyData);

        if (response.data.success) {
            ElMessage.success(response.data.message || '核销成功');
            fetchOrders();
        } else {
            ElMessage.error(response.data.message || '核销失败');
        }
    } catch (error) {
        if (error !== 'cancel') {
            const message = error.response?.data?.message || '核销失败';
            ElMessage.error(message);
        }
    } finally {
        operating.value[row.id] = null;
    }
};

const handleApproveCancel = async (row) => {
    try {
        await ElMessageBox.confirm(
            '确定要同意取消订单吗？同意后将释放库存并通知OTA平台。',
            '同意取消确认',
            {
                type: 'warning',
                confirmButtonText: '确定',
                cancelButtonText: '取消',
            }
        );

        operating.value[row.id] = 'approveCancel';
        const response = await axios.post(`/orders/${row.id}/approve-cancel`, {
            reason: '人工同意取消',
        });

        if (response.data.success) {
            ElMessage.success(response.data.message || '同意取消成功');
            fetchOrders();
        } else {
            ElMessage.error(response.data.message || '同意取消失败');
        }
    } catch (error) {
        if (error !== 'cancel') {
            const message = error.response?.data?.message || '同意取消失败';
            ElMessage.error(message);
        }
    } finally {
        operating.value[row.id] = null;
    }
};

const handleRejectCancel = async (row) => {
    try {
        const { value: reason } = await ElMessageBox.prompt(
            '请输入拒绝取消的原因',
            '拒绝取消',
            {
                confirmButtonText: '确定',
                cancelButtonText: '取消',
                inputType: 'textarea',
                inputPlaceholder: '请输入拒绝取消的原因',
                inputValidator: (value) => {
                    if (!value || value.trim().length === 0) {
                        return '拒绝取消的原因不能为空';
                    }
                    return true;
                },
            }
        );

        operating.value[row.id] = 'rejectCancel';
        const response = await axios.post(`/orders/${row.id}/reject-cancel`, {
            reason: reason.trim(),
        });

        if (response.data.success) {
            ElMessage.success(response.data.message || '拒绝取消成功');
            fetchOrders();
        } else {
            ElMessage.error(response.data.message || '拒绝取消失败');
        }
    } catch (error) {
        if (error !== 'cancel') {
            const message = error.response?.data?.message || '拒绝取消失败';
            ElMessage.error(message);
        }
    } finally {
        operating.value[row.id] = null;
    }
};

const fetchOtaPlatforms = async () => {
    try {
        const response = await axios.get('/ota-platforms', {
            params: {
                active_only: true,
            },
        });
        otaPlatforms.value = response.data.data || [];
    } catch (error) {
        console.error('获取渠道列表失败', error);
    }
};

const fetchScenicSpots = async () => {
    try {
        const response = await axios.get('/scenic-spots', {
            params: {
                per_page: 1000, // 获取所有景区
            },
        });
        scenicSpots.value = response.data.data || [];
    } catch (error) {
        console.error('获取景区列表失败', error);
        // 如果用户没有权限，忽略错误
    }
};

onMounted(() => {
    fetchOrders();
    fetchOtaPlatforms();
    fetchScenicSpots();
    updatePaginationLayout();
    window.addEventListener('resize', updatePaginationLayout);
});

// 组件卸载时移除事件监听
onUnmounted(() => {
    window.removeEventListener('resize', updatePaginationLayout);
});
</script>

<style scoped>
/* 订单管理容器 */
.order-management-container {
    width: 100%;
    overflow-x: auto;
}

/* PC端容器优化 */
@media (min-width: 1201px) {
    .order-management-container {
        overflow-x: visible;
    }
}

.order-list {
    border: 1px solid #dcdfe6;
    border-radius: 4px;
    overflow: hidden;
    width: 100%;
}

/* PC端确保列表容器宽度，允许横向滚动 */
@media (min-width: 1201px) {
    .order-list {
        min-width: 1400px; /* 确保PC端有足够宽度显示所有列 */
    }

    .order-body {
        overflow-x: visible;
        grid-template-columns: 50px 15% 20% 15% 20% 15% auto;
        min-width: 1400px;
    }
}

/* 移动端确保不出现横向滚动 */
@media (max-width: 1200px) {
    .order-list {
        min-width: auto;
    }

    .order-body {
        overflow-x: visible;
    }
}

.order-item {
    border-bottom: 1px solid #dcdfe6;
    transition: background-color 0.2s ease;
}

.order-item:last-child {
    border-bottom: none;
}

/* Hover效果 */
.order-item:hover {
    background-color: #f5f7fa;
}

.order-item:hover .order-body {
    background-color: transparent;
}

/* 顶部信息条 */
.order-header {
    background-color: #f5f7fa;
    padding: 10px 16px;
    display: flex;
    gap: 24px;
    align-items: center;
    font-size: 12px;
    flex-wrap: wrap;
    margin: 0;
}

/* PC端顶部信息条不换行，与主体内容对齐 */
@media (min-width: 1201px) {
    .order-header {
        flex-wrap: nowrap;
        overflow-x: auto;
        padding-left: 16px;
        padding-right: 16px;
    }
}

.header-item {
    display: flex;
    align-items: center;
}

.header-item .label {
    color: #909399;
    font-weight: 400;
    font-size: 12px;
    margin-right: 4px;
}

.header-item .value {
    color: #606266;
    font-weight: 500;
    font-size: 12px;
}

/* 主体内容区 - 使用Grid布局 */
.order-body {
    display: grid;
    grid-template-columns: 50px 15% 20% 15% 20% 15% auto;
    align-items: center;
    padding: 14px 16px;
    min-height: 90px;
    gap: 0;
}

/* PC端保持Grid布局 */
@media (min-width: 1201px) {
    .order-body {
        grid-template-columns: 50px 15% 20% 15% 20% 15% auto;
    }
}

.body-col {
    padding: 0 12px;
    display: flex;
    align-items: center;
    border-right: 1px solid #f0f0f0;
    font-size: 13px;
    min-height: 60px;
}

.body-col:first-child {
    border-right: 1px solid #f0f0f0;
    padding: 0 8px;
}

.body-col:last-child {
    border-right: none;
    padding-right: 16px;
}

/* 第一列：选择框 */
.col-checkbox {
    justify-content: center;
    padding: 0 8px;
}

/* 第二列：产品名称 */
.col-product {
    flex-direction: column;
    align-items: flex-start;
    gap: 6px;
    justify-content: center;
}

.product-name {
    color: #303133;
    font-size: 13px;
    line-height: 1.5;
    font-weight: 500;
}

.scenic-tag {
    font-size: 11px;
    margin-top: 2px;
}

/* 第三列：酒店信息 */
.col-hotel {
    flex-direction: column;
    align-items: flex-start;
    gap: 6px;
    justify-content: center;
}

.hotel-name {
    color: #303133;
    font-size: 13px;
    font-weight: 500;
    line-height: 1.5;
}

.hotel-details {
    color: #909399;
    font-size: 12px;
    line-height: 1.5;
    font-weight: 400;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.room-type {
    margin-bottom: 2px;
}

.date-range {
    display: block;
    margin-top: 2px;
    color: #606266;
}

/* 第四列：入住人信息 */
.col-guest {
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
    justify-content: center;
}

.guest-name {
    color: #303133;
    font-size: 13px;
    line-height: 1.5;
}

.guest-phone {
    color: #909399;
    font-size: 12px;
    line-height: 1.5;
    font-weight: 400;
}

/* 第五列：金额信息 */
.col-amount {
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
    justify-content: center;
}

.amount-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    font-size: 12px;
    line-height: 1.5;
    min-height: 20px;
}

.amount-label {
    color: #909399;
    font-size: 12px;
    font-weight: 400;
    flex-shrink: 0;
    min-width: 70px;
}

.amount-value {
    color: #303133;
    font-weight: 500;
    font-size: 13px;
    text-align: right;
    flex: 1;
    margin-left: 12px;
    min-width: 80px;
    font-variant-numeric: tabular-nums; /* 等宽数字，确保对齐 */
}

.amount-value.estimated {
    color: #67c23a;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
}

/* 第六列：订单状态 */
.col-status {
    justify-content: center;
    align-items: center;
}

.status-tag {
    min-width: 80px;
    text-align: center;
    border-radius: 4px;
    padding: 4px 12px;
    font-size: 12px;
    font-weight: 500;
}

/* 操作列 */
.col-actions {
    justify-content: flex-end;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    padding-right: 16px;
}

/* PC端操作按钮不换行 */
@media (min-width: 1201px) {
    .col-actions {
        flex-wrap: nowrap;
    }
}

/* 操作按钮样式 */
.action-btn-text {
    color: #409eff;
    padding: 5px 8px;
}

.action-btn-text:hover {
    background-color: #ecf5ff;
}

.action-btn-primary {
    padding: 5px 12px;
    font-weight: 500;
}

/* 响应式调整 */

/* 平板端（768px - 1200px）：保持Grid布局但调整比例 */
@media (max-width: 1200px) and (min-width: 768px) {
    .order-body {
        grid-template-columns: 50px 14% 18% 14% 18% 14% auto;
    }

    .col-actions {
        flex-wrap: wrap;
        gap: 6px;
    }

    .col-actions .el-button {
        font-size: 12px;
        padding: 5px 10px;
    }
}

/* 移动端（< 768px）：改为单列布局 */
@media (max-width: 767px) {
    .order-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
        padding: 12px;
    }

    .header-item {
        width: 100%;
        margin-bottom: 4px;
    }

    .order-body {
        display: flex;
        flex-direction: column;
        align-items: stretch;
        padding: 12px;
        gap: 12px;
        grid-template-columns: 1fr;
    }

    .body-col {
        width: 100% !important;
        border-right: none;
        border-bottom: 1px solid #f0f0f0;
        padding: 10px 0;
        justify-content: flex-start;
        min-height: auto;
    }

    .body-col:last-child {
        border-bottom: none;
    }

    .col-checkbox {
        justify-content: flex-start;
        padding: 8px 0;
    }

    .col-product,
    .col-hotel,
    .col-guest,
    .col-amount,
    .col-status {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }

    .col-actions {
        flex-direction: column;
        gap: 8px;
        align-items: stretch;
    }

    .col-actions .el-button {
        width: 100%;
        margin: 0;
    }

    /* 移动端金额信息横向排列 */
    .col-amount {
        flex-direction: row;
        flex-wrap: wrap;
        gap: 12px;
    }

    .amount-item {
        flex: 0 0 auto;
        min-width: calc(50% - 6px);
    }
}

/* 筛选条件区域 */
.filter-bar {
    margin-bottom: 20px;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}

/* 移动端筛选条件优化 */
@media (max-width: 767px) {
    .filter-bar {
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
    }

    .filter-bar .el-select,
    .filter-bar .el-input,
    .filter-bar .el-date-picker {
        width: 100% !important;
    }

    .filter-bar .el-button {
        width: 100%;
    }
}

/* 超小屏幕（< 480px）：进一步优化 */
@media (max-width: 480px) {
    .order-header {
        font-size: 11px;
        padding: 10px;
    }

    .header-item .label,
    .header-item .value {
        font-size: 11px;
    }

    .order-body {
        padding: 10px;
    }

    .product-name,
    .hotel-name,
    .guest-name {
        font-size: 12px;
    }

    .hotel-details,
    .guest-phone,
    .amount-label,
    .amount-value {
        font-size: 11px;
    }

    .col-amount {
        flex-direction: column;
        align-items: flex-start;
    }

    .amount-item {
        width: 100%;
        min-width: 100%;
    }

    .filter-bar {
        gap: 10px;
    }
}

/* 分页组件响应式 */
.pagination-container {
    margin-top: 20px;
    display: flex;
    justify-content: center;
}

@media (max-width: 768px) {
    .pagination-container {
        margin-top: 15px;
    }

    .pagination-container :deep(.el-pagination) {
        justify-content: center;
    }

    .pagination-container :deep(.el-pagination .el-pager li),
    .pagination-container :deep(.el-pagination .btn-prev),
    .pagination-container :deep(.el-pagination .btn-next) {
        min-width: 32px;
        height: 32px;
        line-height: 32px;
        font-size: 12px;
    }
}
</style>
