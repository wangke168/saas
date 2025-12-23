<template>
    <div>
        <h2>异常订单处理</h2>
        <el-alert type="warning" :closable="false" style="margin-bottom: 20px">
            所有接口报错、超时、库存不匹配的订单都会显示在这里，请及时处理
        </el-alert>
        <el-card>
            <el-table :data="exceptions" v-loading="loading" border>
                <el-table-column prop="order.order_no" label="订单号" width="180" />
                <el-table-column prop="exception_type" label="异常类型" width="150">
                    <template #default="{ row }">
                        <el-tag type="danger">{{ getExceptionTypeLabel(row.exception_type) }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="exception_message" label="异常信息" />
                <el-table-column prop="status" label="处理状态" width="120">
                    <template #default="{ row }">
                        <el-tag :type="getStatusType(row.status)">{{ getStatusLabel(row.status) }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="created_at" label="创建时间" width="180" />
                <el-table-column label="操作" width="200" fixed="right">
                    <template #default="{ row }">
                        <el-button v-if="row.status === 'pending'" size="small" type="primary" @click="startProcessing(row)">
                            开始处理
                        </el-button>
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
import { ElMessage } from 'element-plus';

const exceptions = ref([]);
const loading = ref(false);

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

onMounted(() => {
    fetchExceptions();
});
</script>

