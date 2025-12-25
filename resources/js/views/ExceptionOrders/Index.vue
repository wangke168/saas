<template>
    <div>
        <h2>异常订单处理</h2>
        <el-alert type="warning" :closable="false" style="margin-bottom: 20px">
            所有接口报错、超时、库存不匹配的订单都会显示在这里，请及时处理
        </el-alert>
        <el-card>
            <el-table :data="exceptions" v-loading="loading" border>
                <el-table-column prop="order.order_no" label="订单号" width="180" />
                <el-table-column prop="order.ota_order_no" label="OTA订单号" width="180" />
                <el-table-column prop="exception_type" label="异常类型" width="150">
                    <template #default="{ row }">
                        <el-tag type="danger">{{ getExceptionTypeLabel(row.exception_type) }}</el-tag>
                        <el-tag v-if="isTimeout(row)" type="warning" style="margin-left: 5px;">超时</el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="exception_message" label="异常信息" min-width="200" />
                <el-table-column label="景区方反馈" width="200">
                    <template #default="{ row }">
                        <div v-if="getResourceResponse(row)">
                            <el-tag :type="getResourceResponse(row).success ? 'success' : 'danger'" size="small">
                                {{ getResourceResponse(row).success ? '成功' : '失败' }}
                            </el-tag>
                            <div v-if="getResourceResponse(row).message" style="margin-top: 5px; font-size: 12px; color: #666;">
                                {{ getResourceResponse(row).message }}
                            </div>
                        </div>
                        <span v-else style="color: #999;">-</span>
                    </template>
                </el-table-column>
                <el-table-column prop="status" label="处理状态" width="120">
                    <template #default="{ row }">
                        <el-tag :type="getStatusType(row.status)">{{ getStatusLabel(row.status) }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="created_at" label="创建时间" width="180">
                    <template #default="{ row }">
                        {{ formatDate(row.created_at) }}
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="300" fixed="right">
                    <template #default="{ row }">
                        <!-- 接单失败/超时的处理按钮 -->
                        <template v-if="row.status === 'pending' && getOperation(row) === 'confirm'">
                            <el-button 
                                size="small" 
                                type="success" 
                                @click="handleConfirmOrder(row)"
                                :loading="processing[row.id] === 'confirm'"
                            >
                                接单
                            </el-button>
                            <el-button 
                                size="small" 
                                type="danger" 
                                @click="handleRejectOrder(row)"
                                :loading="processing[row.id] === 'reject'"
                            >
                                拒单
                            </el-button>
                        </template>
                        
                        <!-- 取消失败/超时的处理按钮 -->
                        <template v-if="row.status === 'pending' && getOperation(row) === 'cancel'">
                            <el-button 
                                size="small" 
                                type="success" 
                                @click="handleApproveCancel(row)"
                                :loading="processing[row.id] === 'approve'"
                            >
                                同意取消
                            </el-button>
                            <el-button 
                                size="small" 
                                type="danger" 
                                @click="handleRejectCancel(row)"
                                :loading="processing[row.id] === 'reject'"
                            >
                                拒绝取消
                            </el-button>
                        </template>
                        
                        <!-- 其他异常类型的处理按钮 -->
                        <template v-if="row.status === 'pending' && !getOperation(row)">
                            <el-button size="small" type="primary" @click="startProcessing(row)">
                                开始处理
                            </el-button>
                        </template>
                        
                        <el-button v-if="row.status === 'processing'" size="small" type="success" @click="resolve(row)">
                            已解决
                        </el-button>
                    </template>
                </el-table-column>
            </el-table>
        </el-card>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from '../../utils/axios';
import { ElMessage, ElMessageBox } from 'element-plus';

const exceptions = ref([]);
const loading = ref(false);
const processing = ref({}); // 记录正在处理中的异常订单 { exceptionId: 'confirm'|'reject'|'approve' }

const fetchExceptions = async () => {
    loading.value = true;
    try {
        const response = await axios.get('/exception-orders');
        exceptions.value = response.data.data;
    } catch (error) {
        ElMessage.error('获取异常订单列表失败');
    } finally {
        loading.value = false;
    }
};

const getExceptionTypeLabel = (type) => {
    const labels = {
        'api_error': '接口报错',
        'timeout': '超时',
        'inventory_mismatch': '库存不匹配',
        'price_mismatch': '价格不匹配'
    };
    return labels[type] || type;
};

const getStatusLabel = (status) => {
    const labels = {
        'pending': '待处理',
        'processing': '处理中',
        'resolved': '已解决'
    };
    return labels[status] || status;
};

const getStatusType = (status) => {
    const types = {
        'pending': 'danger',
        'processing': 'warning',
        'resolved': 'success'
    };
    return types[status] || '';
};

const startProcessing = async (row) => {
    try {
        await axios.post(`/exception-orders/${row.id}/start-processing`);
        ElMessage.success('已开始处理');
        fetchExceptions();
    } catch (error) {
        ElMessage.error('操作失败');
    }
};

const resolve = async (row) => {
    try {
        await axios.post(`/exception-orders/${row.id}/resolve`);
        ElMessage.success('已标记为已解决');
        fetchExceptions();
    } catch (error) {
        ElMessage.error('操作失败');
    }
};

// 获取操作类型（confirm 或 cancel）
const getOperation = (row) => {
    return row.exception_data?.operation || null;
};

// 判断是否超时
const isTimeout = (row) => {
    return row.exception_data?.timeout === true;
};

// 获取景区方反馈信息
const getResourceResponse = (row) => {
    return row.exception_data?.resource_response || null;
};

// 格式化日期
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

// 处理接单
const handleConfirmOrder = async (row) => {
    try {
        await ElMessageBox.confirm(
            '确定要接单吗？系统将调用景区方接口确认订单。',
            '接单确认',
            {
                type: 'info',
                confirmButtonText: '确定',
                cancelButtonText: '取消',
            }
        );

        processing.value[row.id] = 'confirm';
        const response = await axios.post(`/orders/${row.order_id}/confirm`, {
            remark: '异常订单人工处理：接单',
        });

        if (response.data.success) {
            ElMessage.success(response.data.message || '接单成功');
            fetchExceptions();
        } else {
            ElMessage.error(response.data.message || '接单失败');
        }
    } catch (error) {
        if (error !== 'cancel') {
            const message = error.response?.data?.message || '接单失败';
            ElMessage.error(message);
        }
    } finally {
        processing.value[row.id] = null;
    }
};

// 处理拒单
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

        processing.value[row.id] = 'reject';
        const response = await axios.post(`/orders/${row.order_id}/reject`, {
            reason: reason.trim(),
        });

        if (response.data.success) {
            ElMessage.success(response.data.message || '拒单成功');
            fetchExceptions();
        } else {
            ElMessage.error(response.data.message || '拒单失败');
        }
    } catch (error) {
        if (error !== 'cancel') {
            const message = error.response?.data?.message || '拒单失败';
            ElMessage.error(message);
        }
    } finally {
        processing.value[row.id] = null;
    }
};

// 处理同意取消
const handleApproveCancel = async (row) => {
    try {
        await ElMessageBox.confirm(
            '确定要同意取消订单吗？系统将调用景区方接口取消订单。',
            '同意取消确认',
            {
                type: 'info',
                confirmButtonText: '确定',
                cancelButtonText: '取消',
            }
        );

        processing.value[row.id] = 'approve';
        const response = await axios.post(`/orders/${row.order_id}/approve-cancel`, {
            reason: '异常订单人工处理：同意取消',
        });

        if (response.data.success) {
            ElMessage.success(response.data.message || '同意取消成功');
            fetchExceptions();
        } else {
            ElMessage.error(response.data.message || '同意取消失败');
        }
    } catch (error) {
        if (error !== 'cancel') {
            const message = error.response?.data?.message || '同意取消失败';
            ElMessage.error(message);
        }
    } finally {
        processing.value[row.id] = null;
    }
};

// 处理拒绝取消
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
                        return '拒绝原因不能为空';
                    }
                    return true;
                },
            }
        );

        processing.value[row.id] = 'reject';
        const response = await axios.post(`/orders/${row.order_id}/reject-cancel`, {
            reason: reason.trim(),
        });

        if (response.data.success) {
            ElMessage.success(response.data.message || '拒绝取消成功');
            fetchExceptions();
        } else {
            ElMessage.error(response.data.message || '拒绝取消失败');
        }
    } catch (error) {
        if (error !== 'cancel') {
            const message = error.response?.data?.message || '拒绝取消失败';
            ElMessage.error(message);
        }
    } finally {
        processing.value[row.id] = null;
    }
};

onMounted(() => {
    fetchExceptions();
});
</script>

