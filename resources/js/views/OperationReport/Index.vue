<template>
    <div class="operation-report-container">
        <h2>运营快报</h2>
        <el-card>
            <!-- 时间周期切换 -->
            <div class="period-tabs">
                <el-radio-group v-model="selectedPeriod" @change="handlePeriodChange">
                    <el-radio-button label="realtime">实时</el-radio-button>
                    <el-radio-button label="day">过去一天</el-radio-button>
                    <el-radio-button label="week">过去一周</el-radio-button>
                    <el-radio-button label="month">过去一月</el-radio-button>
                    <el-radio-button label="custom">自定义日期</el-radio-button>
                </el-radio-group>
            </div>
            
            <!-- 自定义日期范围选择 -->
            <div v-if="selectedPeriod === 'custom'" class="custom-date-range">
                <el-date-picker
                    v-model="customDateRange"
                    type="daterange"
                    range-separator="至"
                    start-placeholder="开始日期"
                    end-placeholder="结束日期"
                    format="YYYY-MM-DD"
                    value-format="YYYY-MM-DD"
                    @change="handleCustomDateChange"
                />
            </div>

            <div v-loading="loading" class="report-content">
                <!-- 关键指标卡片 -->
                <div class="stats-cards">
                    <el-card class="stat-card">
                        <el-statistic
                            title="订单总数"
                            :value="reportData.stats?.total_orders || 0"
                        >
                            <template #suffix>
                                <span class="stat-unit">单</span>
                            </template>
                        </el-statistic>
                        <div class="stat-detail">
                            <span>普通订单: {{ reportData.order_stats?.total_orders || 0 }}</span>
                            <span>打包订单: {{ reportData.pkg_order_stats?.total_orders || 0 }}</span>
                        </div>
                    </el-card>

                    <el-card class="stat-card">
                        <el-statistic
                            title="订单总金额"
                            :value="reportData.stats?.total_amount || 0"
                            :precision="2"
                        >
                            <template #prefix>
                                <span class="stat-unit">¥</span>
                            </template>
                        </el-statistic>
                        <div class="stat-detail">
                            <span>销售总额</span>
                        </div>
                    </el-card>

                    <el-card class="stat-card">
                        <el-statistic
                            title="结算总金额"
                            :value="reportData.stats?.total_settlement_amount || 0"
                            :precision="2"
                        >
                            <template #prefix>
                                <span class="stat-unit">¥</span>
                            </template>
                        </el-statistic>
                        <div class="stat-detail">
                            <span>结算总额</span>
                        </div>
                    </el-card>

                    <el-card class="stat-card">
                        <el-statistic
                            title="已确认订单"
                            :value="reportData.stats?.confirmed_orders || 0"
                        >
                            <template #suffix>
                                <span class="stat-unit">单</span>
                            </template>
                        </el-statistic>
                        <div class="stat-detail">
                            <span>确认率: {{ getConfirmRate() }}%</span>
                        </div>
                    </el-card>

                    <el-card class="stat-card">
                        <el-statistic
                            title="已核销订单"
                            :value="reportData.stats?.verified_orders || 0"
                        >
                            <template #suffix>
                                <span class="stat-unit">单</span>
                            </template>
                        </el-statistic>
                        <div class="stat-detail">
                            <span>核销率: {{ getVerifyRate() }}%</span>
                        </div>
                    </el-card>

                    <el-card class="stat-card">
                        <el-statistic
                            title="已取消订单"
                            :value="reportData.stats?.cancelled_orders || 0"
                        >
                            <template #suffix>
                                <span class="stat-unit">单</span>
                            </template>
                        </el-statistic>
                        <div class="stat-detail">
                            <span>取消率: {{ getCancelRate() }}%</span>
                        </div>
                    </el-card>
                </div>

                <!-- 订单状态分布 -->
                <el-card class="chart-card" v-if="reportData.status_distribution?.length > 0">
                    <template #header>
                        <div class="card-header">
                            <span>订单状态分布</span>
                        </div>
                    </template>
                    <div class="status-distribution">
                        <div
                            v-for="item in reportData.status_distribution"
                            :key="`${item.type}-${item.status}`"
                            class="status-item"
                        >
                            <div class="status-info">
                                <el-tag size="small" :type="getStatusTagType(item.status, item.type)">
                                    {{ item.label }}
                                </el-tag>
                                <span class="status-count">{{ item.count }} 单</span>
                                <span class="status-percentage">({{ item.percentage }}%)</span>
                            </div>
                            <div class="status-amount">
                                ¥{{ formatPrice(item.total_amount) }}
                            </div>
                        </div>
                    </div>
                </el-card>

                <!-- OTA平台分布 -->
                <el-card class="chart-card" v-if="reportData.platform_distribution?.length > 0">
                    <template #header>
                        <div class="card-header">
                            <span>OTA平台分布</span>
                        </div>
                    </template>
                    <el-table :data="reportData.platform_distribution" border>
                        <el-table-column prop="name" label="平台名称" width="150" />
                        <el-table-column prop="count" label="订单数" width="120" align="right">
                            <template #default="{ row }">
                                {{ row.count }} 单
                            </template>
                        </el-table-column>
                        <el-table-column prop="percentage" label="占比" width="120" align="right">
                            <template #default="{ row }">
                                {{ row.percentage }}%
                            </template>
                        </el-table-column>
                        <el-table-column prop="total_amount" label="订单金额" align="right">
                            <template #default="{ row }">
                                ¥{{ formatPrice(row.total_amount) }}
                            </template>
                        </el-table-column>
                        <el-table-column prop="settlement_amount" label="结算金额" align="right">
                            <template #default="{ row }">
                                ¥{{ formatPrice(row.settlement_amount) }}
                            </template>
                        </el-table-column>
                    </el-table>
                </el-card>

                <!-- 时间趋势 -->
                <el-card class="chart-card" v-if="reportData.time_trend?.length > 0">
                    <template #header>
                        <div class="card-header">
                            <span>时间趋势</span>
                        </div>
                    </template>
                    <el-table :data="reportData.time_trend" border>
                        <el-table-column prop="date" label="日期" width="150" />
                        <el-table-column prop="count" label="订单数" width="120" align="right">
                            <template #default="{ row }">
                                {{ row.count }} 单
                            </template>
                        </el-table-column>
                        <el-table-column prop="total_amount" label="订单金额" align="right">
                            <template #default="{ row }">
                                ¥{{ formatPrice(row.total_amount) }}
                            </template>
                        </el-table-column>
                    </el-table>
                </el-card>
            </div>
        </el-card>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from '../../utils/axios';
import { ElMessage } from 'element-plus';

const loading = ref(false);
const selectedPeriod = ref('realtime');
const customDateRange = ref(null);
const reportData = ref({
    period: 'day',
    start_date: '',
    end_date: '',
    stats: {},
    order_stats: {},
    pkg_order_stats: {},
    status_distribution: [],
    platform_distribution: [],
    time_trend: [],
});

const fetchReport = async () => {
    loading.value = true;
    try {
        const params = {
            period: selectedPeriod.value,
        };
        
        // 如果是自定义日期范围，传递日期参数
        if (selectedPeriod.value === 'custom' && customDateRange.value && customDateRange.value.length === 2) {
            params.start_date = customDateRange.value[0];
            params.end_date = customDateRange.value[1];
        }
        
        const response = await axios.get('/operation-report', { params });
        reportData.value = response.data;
    } catch (error) {
        console.error('获取运营快报数据失败:', error);
        ElMessage.error('获取运营快报数据失败');
    } finally {
        loading.value = false;
    }
};

const handlePeriodChange = () => {
    // 切换到非自定义日期时，清空日期范围
    if (selectedPeriod.value !== 'custom') {
        customDateRange.value = null;
    }
    fetchReport();
};

const handleCustomDateChange = () => {
    // 自定义日期改变时，如果选择了完整日期范围，自动刷新数据
    if (customDateRange.value && customDateRange.value.length === 2) {
        fetchReport();
    }
};

const formatPrice = (price) => {
    if (!price) return '0.00';
    // 价格单位：元
    return Number(price).toLocaleString('zh-CN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

const getConfirmRate = () => {
    const total = reportData.value.stats?.total_orders || 0;
    const confirmed = reportData.value.stats?.confirmed_orders || 0;
    if (total === 0) return '0.00';
    return ((confirmed / total) * 100).toFixed(2);
};

const getVerifyRate = () => {
    const total = reportData.value.stats?.total_orders || 0;
    const verified = reportData.value.stats?.verified_orders || 0;
    if (total === 0) return '0.00';
    return ((verified / total) * 100).toFixed(2);
};

const getCancelRate = () => {
    const total = reportData.value.stats?.total_orders || 0;
    const cancelled = reportData.value.stats?.cancelled_orders || 0;
    if (total === 0) return '0.00';
    return ((cancelled / total) * 100).toFixed(2);
};

const getStatusTagType = (status, type) => {
    // 普通订单状态
    if (type === 'order') {
        if (status === 'confirmed') return 'success';
        if (status === 'verified') return 'success';
        if (status === 'cancel_approved') return 'danger';
        if (status === 'rejected') return 'danger';
        if (status === 'paid_pending' || status === 'confirming') return 'warning';
        return 'info';
    }
    // 打包订单状态
    if (type === 'pkg_order') {
        if (status === 'confirmed') return 'success';
        if (status === 'cancelled') return 'danger';
        if (status === 'failed') return 'danger';
        if (status === 'paid') return 'warning';
        return 'info';
    }
    return 'info';
};

onMounted(() => {
    fetchReport();
});
</script>

<style scoped>
.operation-report-container {
    padding: 20px;
}

.operation-report-container h2 {
    margin: 0 0 20px 0;
    font-size: 24px;
    font-weight: 600;
}

.period-tabs {
    margin-bottom: 20px;
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 10px;
}

.custom-date-range {
    margin-top: 15px;
    display: flex;
    justify-content: center;
}

.report-content {
    margin-top: 20px;
}

.stats-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    text-align: center;
}

.stat-card :deep(.el-statistic__head) {
    font-size: 14px;
    color: #606266;
    margin-bottom: 10px;
}

.stat-card :deep(.el-statistic__number) {
    font-size: 28px;
    font-weight: 600;
    color: #303133;
}

.stat-unit {
    font-size: 14px;
    margin-left: 4px;
    color: #909399;
}

.stat-detail {
    margin-top: 10px;
    font-size: 12px;
    color: #909399;
    display: flex;
    justify-content: space-around;
}

.chart-card {
    margin-bottom: 20px;
}

.card-header {
    font-weight: 600;
    font-size: 16px;
}

.status-distribution {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.status-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px;
    background: #f5f7fa;
    border-radius: 4px;
}

.status-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.status-count {
    font-weight: 600;
    color: #303133;
}

.status-percentage {
    color: #909399;
    font-size: 14px;
}

.status-amount {
    font-weight: 600;
    color: #409eff;
    font-size: 16px;
}

@media (max-width: 768px) {
    .stats-cards {
        grid-template-columns: 1fr;
    }

    .status-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
}
</style>

