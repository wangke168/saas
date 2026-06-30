<template>
    <div class="operation-report-container">
        <div class="page-header">
            <h2>运营快报</h2>
            <el-button type="success" :loading="exporting" @click="handleExport">
                导出报表
            </el-button>
        </div>
        <el-card>
            <div class="filter-row">
                <el-select
                    v-model="selectedScenicSpotId"
                    placeholder="选择景区（默认全部）"
                    clearable
                    filterable
                    style="width: 260px"
                    @change="handleFilterChange"
                >
                    <el-option
                        v-for="spot in scenicSpotOptions"
                        :key="spot.id"
                        :label="spot.name"
                        :value="spot.id"
                    />
                </el-select>

                <el-select
                    v-model="selectedChannelId"
                    placeholder="选择渠道（默认全部）"
                    clearable
                    filterable
                    style="width: 220px"
                    @change="handleFilterChange"
                >
                    <el-option
                        v-for="channel in channelOptions"
                        :key="channel.id"
                        :label="channel.name"
                        :value="channel.id"
                    />
                </el-select>

                <el-radio-group v-model="dateType" @change="handleFilterChange">
                    <el-radio-button label="booking">预定日期</el-radio-button>
                    <el-radio-button label="arrival">预达日期</el-radio-button>
                </el-radio-group>

                <el-select
                    v-model="amountScope"
                    placeholder="统计口径"
                    style="width: 180px"
                >
                    <el-option
                        v-for="scope in metricScopes"
                        :key="scope.key"
                        :label="scope.label"
                        :value="scope.key"
                    />
                </el-select>
            </div>
            <div class="date-type-tip">
                <el-text v-if="dateType === 'arrival'" type="warning">
                    当前按预达日期（check_in_date）统计，反映游客到达节奏而非下单节奏。
                </el-text>
                <el-text v-else type="info">
                    当前按预定日期（created_at）统计，反映平台下单节奏。
                </el-text>
            </div>

            <div v-if="currentScopeDescription" class="scope-tip">
                <el-text type="info">{{ currentScopeDescription }}</el-text>
            </div>

            <!-- 时间周期切换 -->
            <div class="period-tabs">
                <el-radio-group v-model="selectedPeriod" @change="handlePeriodChange">
                    <el-radio-button label="realtime">实时（今日）</el-radio-button>
                    <el-radio-button label="day">昨日</el-radio-button>
                    <el-radio-button label="week">近7日</el-radio-button>
                    <el-radio-button label="month">近30日</el-radio-button>
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

            <div v-if="reportData.start_date" class="stats-range-tip">
                <el-text type="info">
                    统计区间：{{ reportData.start_date }} ~ {{ reportData.end_date }}
                </el-text>
                <el-text v-if="reportData.comparison?.label" type="info" class="comparison-range-tip">
                    对比区间（{{ reportData.comparison.label }}）：{{ reportData.comparison.previous_start_date }} ~ {{ reportData.comparison.previous_end_date }}
                </el-text>
            </div>

            <div v-loading="loading" class="report-content">
                <div class="stats-section-title">
                    {{ currentScopeLabel }}指标
                    <el-tooltip :content="currentScopeDescription" placement="top">
                        <el-icon class="metric-help-icon"><QuestionFilled /></el-icon>
                    </el-tooltip>
                </div>
                <div class="stats-cards">
                    <el-card class="stat-card stat-card-primary">
                        <div class="stat-title-row">
                            <span>{{ scopeFieldLabels.orderCount }}</span>
                            <el-tooltip :content="metricTips.orderCount" placement="top">
                                <el-icon class="metric-help-icon"><QuestionFilled /></el-icon>
                            </el-tooltip>
                        </div>
                        <el-statistic :value="scopePrimaryStats.orderCount">
                            <template #suffix>
                                <span class="stat-unit">单</span>
                            </template>
                        </el-statistic>
                        <div class="stat-detail">
                            <span>{{ scopeFieldLabels.secondaryOrderCount }}: {{ scopeSecondaryStats.orderCount }} 单</span>
                        </div>
                        <div v-if="scopeComparison?.orderCount" class="stat-compare">
                            <span :class="getCompareClass(scopeComparison.orderCount.change_percent)">
                                {{ formatCompareText(scopeComparison.orderCount.change_percent, reportData.comparison?.label) }}
                            </span>
                        </div>
                    </el-card>

                    <el-card class="stat-card stat-card-primary">
                        <div class="stat-title-row">
                            <span>{{ scopeFieldLabels.amount }}</span>
                            <el-tooltip :content="metricTips.amount" placement="top">
                                <el-icon class="metric-help-icon"><QuestionFilled /></el-icon>
                            </el-tooltip>
                        </div>
                        <el-statistic :value="scopePrimaryStats.amount" :precision="2">
                            <template #prefix>
                                <span class="stat-unit">¥</span>
                            </template>
                        </el-statistic>
                        <div class="stat-detail">
                            <span>{{ scopeFieldLabels.secondaryAmount }}: ¥{{ formatPrice(scopeSecondaryStats.amount) }}</span>
                        </div>
                        <div v-if="scopeComparison?.amount" class="stat-compare">
                            <span :class="getCompareClass(scopeComparison.amount.change_percent)">
                                {{ formatCompareText(scopeComparison.amount.change_percent, reportData.comparison?.label) }}
                            </span>
                        </div>
                    </el-card>

                    <el-card v-if="scopeFieldLabels.settlement" class="stat-card stat-card-primary">
                        <div class="stat-title-row">
                            <span>{{ scopeFieldLabels.settlement }}</span>
                            <el-tooltip :content="metricTips.settlement" placement="top">
                                <el-icon class="metric-help-icon"><QuestionFilled /></el-icon>
                            </el-tooltip>
                        </div>
                        <el-statistic :value="scopePrimaryStats.settlement" :precision="2">
                            <template #prefix>
                                <span class="stat-unit">¥</span>
                            </template>
                        </el-statistic>
                        <div class="stat-detail">
                            <span>{{ scopeFieldLabels.secondarySettlement }}: ¥{{ formatPrice(scopeSecondaryStats.settlement) }}</span>
                        </div>
                        <div v-if="scopeComparison?.settlement" class="stat-compare">
                            <span :class="getCompareClass(scopeComparison.settlement.change_percent)">
                                {{ formatCompareText(scopeComparison.settlement.change_percent, reportData.comparison?.label) }}
                            </span>
                        </div>
                    </el-card>

                    <el-card v-if="amountScope !== 'fulfilled'" class="stat-card stat-card-primary">
                        <div class="stat-title-row">
                            <span>核销金额</span>
                            <el-tooltip content="已核销订单的销售额，反映实际履约。" placement="top">
                                <el-icon class="metric-help-icon"><QuestionFilled /></el-icon>
                            </el-tooltip>
                        </div>
                        <el-statistic
                            :value="reportData.stats?.verified_total_amount || 0"
                            :precision="2"
                        >
                            <template #prefix>
                                <span class="stat-unit">¥</span>
                            </template>
                        </el-statistic>
                        <div class="stat-detail">
                            <span>已核销 {{ reportData.stats?.verified_orders || 0 }} 单</span>
                        </div>
                    </el-card>
                </div>

                <div class="stats-section-title">转化与取消</div>
                <div class="stats-cards">
                    <el-card class="stat-card">
                        <div class="stat-title-row">
                            <span>预订成功订单</span>
                            <el-tooltip content="状态为「预订成功」或「已核销」的订单，含 confirmed + verified。" placement="top">
                                <el-icon class="metric-help-icon"><QuestionFilled /></el-icon>
                            </el-tooltip>
                        </div>
                        <el-statistic
                            :value="reportData.stats?.successful_orders || 0"
                        >
                            <template #suffix>
                                <span class="stat-unit">单</span>
                            </template>
                        </el-statistic>
                        <div class="stat-detail">
                            <span>成功率: {{ getSuccessRate() }}%（成功/有效）</span>
                        </div>
                    </el-card>

                    <el-card class="stat-card">
                        <div class="stat-title-row">
                            <span>已核销订单</span>
                            <el-tooltip content="已完成核销的订单数。核销率 = 已核销 / 预订成功。" placement="top">
                                <el-icon class="metric-help-icon"><QuestionFilled /></el-icon>
                            </el-tooltip>
                        </div>
                        <el-statistic
                            :value="reportData.stats?.verified_orders || 0"
                        >
                            <template #suffix>
                                <span class="stat-unit">单</span>
                            </template>
                        </el-statistic>
                        <div class="stat-detail">
                            <span>核销率: {{ getVerifyRate() }}%（核销/成功）</span>
                        </div>
                    </el-card>

                    <el-card class="stat-card">
                        <div class="stat-title-row">
                            <span>已取消订单</span>
                            <el-tooltip content="取消率按下单总量计算；取消金额为已取消订单的销售总额。" placement="top">
                                <el-icon class="metric-help-icon"><QuestionFilled /></el-icon>
                            </el-tooltip>
                        </div>
                        <el-statistic
                            :value="reportData.stats?.cancelled_orders || 0"
                        >
                            <template #suffix>
                                <span class="stat-unit">单</span>
                            </template>
                        </el-statistic>
                        <div class="stat-detail">
                            <span>取消率: {{ getCancelRate() }}% | 取消金额: ¥{{ formatPrice(reportData.stats?.cancelled_amount) }}</span>
                        </div>
                    </el-card>
                </div>

                <!-- 图表可视化 -->
                <div class="charts-grid">
                    <el-card class="chart-card" v-if="reportData.time_trend?.length > 0">
                        <template #header>
                            <div class="card-header">
                                <span>时间趋势图</span>
                            </div>
                        </template>
                        <div ref="trendChartRef" class="chart-container"></div>
                    </el-card>

                    <el-card class="chart-card" v-if="reportData.platform_distribution?.length > 0">
                        <template #header>
                            <div class="card-header">
                                <span>渠道销售额分布</span>
                            </div>
                        </template>
                        <div ref="platformChartRef" class="chart-container"></div>
                    </el-card>

                    <el-card class="chart-card" v-if="statusDistributionUnified.length > 0">
                        <template #header>
                            <div class="card-header">
                                <span>订单状态占比</span>
                            </div>
                        </template>
                        <div ref="statusChartRef" class="chart-container"></div>
                    </el-card>
                </div>

                <!-- 订单状态分布 -->
                <el-card
                    class="chart-card"
                    v-if="hasStatusDistribution"
                >
                    <template #header>
                        <div class="card-header">
                            <span>订单状态分布</span>
                        </div>
                    </template>
                    <el-tabs v-model="statusDistributionTab">
                        <el-tab-pane label="归并视图" name="unified">
                            <div class="status-distribution">
                                <div
                                    v-for="item in statusDistributionUnified"
                                    :key="`unified-${item.status}`"
                                    class="status-item"
                                >
                                    <div class="status-info">
                                        <el-tag size="small" :type="getUnifiedStatusTagType(item.status)">
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
                        </el-tab-pane>
                        <el-tab-pane label="普通订单" name="orders">
                            <div class="status-distribution">
                                <div
                                    v-for="item in statusDistributionOrders"
                                    :key="`order-${item.status}`"
                                    class="status-item"
                                >
                                    <div class="status-info">
                                        <el-tag size="small" :type="getStatusTagType(item.status, 'order')">
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
                        </el-tab-pane>
                        <el-tab-pane label="打包订单" name="pkg_orders">
                            <div class="status-distribution">
                                <div
                                    v-for="item in statusDistributionPkgOrders"
                                    :key="`pkg-${item.status}`"
                                    class="status-item"
                                >
                                    <div class="status-info">
                                        <el-tag size="small" :type="getStatusTagType(item.status, 'pkg_order')">
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
                        </el-tab-pane>
                    </el-tabs>
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
                        <el-table-column :label="scopeFieldLabels.orderCount" width="130" align="right">
                            <template #default="{ row }">
                                {{ row[scopedCountField] }} 单
                            </template>
                        </el-table-column>
                        <el-table-column prop="percentage" label="占比（下单）" width="120" align="right">
                            <template #default="{ row }">
                                {{ row.percentage }}%
                            </template>
                        </el-table-column>
                        <el-table-column :label="scopeFieldLabels.amount" align="right">
                            <template #default="{ row }">
                                ¥{{ formatPrice(row[scopedAmountField]) }}
                            </template>
                        </el-table-column>
                        <el-table-column
                            v-if="scopedSettlementField"
                            :label="scopeFieldLabels.settlement"
                            align="right"
                        >
                            <template #default="{ row }">
                                ¥{{ formatPrice(row[scopedSettlementField]) }}
                            </template>
                        </el-table-column>
                        <el-table-column
                            v-if="amountScope !== 'gross'"
                            label="下单金额（含取消）"
                            align="right"
                        >
                            <template #default="{ row }">
                                ¥{{ formatPrice(row.total_amount) }}
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
                        <el-table-column :label="scopeFieldLabels.orderCount" width="130" align="right">
                            <template #default="{ row }">
                                {{ row[scopedCountField] }} 单
                            </template>
                        </el-table-column>
                        <el-table-column :label="scopeFieldLabels.amount" align="right">
                            <template #default="{ row }">
                                ¥{{ formatPrice(row[scopedAmountField]) }}
                            </template>
                        </el-table-column>
                        <el-table-column
                            v-if="amountScope !== 'gross'"
                            label="下单金额（含取消）"
                            align="right"
                        >
                            <template #default="{ row }">
                                ¥{{ formatPrice(row.total_amount) }}
                            </template>
                        </el-table-column>
                    </el-table>
                </el-card>

                <!-- 销售TOP10产品 -->
                <el-card class="chart-card" v-if="reportData.top_products?.length > 0">
                    <template #header>
                        <div class="card-header">
                            <span>销售TOP10产品（按{{ scopeFieldLabels.amount }}）</span>
                        </div>
                    </template>
                    <el-table :data="displayTopProducts" border>
                        <el-table-column type="index" label="排名" width="80" align="center" />
                        <el-table-column prop="product_name" label="产品名称" min-width="260" />
                        <el-table-column prop="product_type" label="产品类型" width="120" align="center">
                            <template #default="{ row }">
                                <el-tag size="small" :type="row.product_type === 'order' ? 'primary' : 'success'">
                                    {{ row.product_type === 'order' ? '普通产品' : '打包产品' }}
                                </el-tag>
                            </template>
                        </el-table-column>
                        <el-table-column :label="scopeFieldLabels.orderCount" width="130" align="right">
                            <template #default="{ row }">
                                {{ row[scopedProductCountField] }} 单
                            </template>
                        </el-table-column>
                        <el-table-column :label="scopeFieldLabels.amount" width="160" align="right">
                            <template #default="{ row }">
                                ¥{{ formatPrice(row[scopedProductAmountField]) }}
                            </template>
                        </el-table-column>
                        <el-table-column
                            v-if="scopedProductSettlementField"
                            :label="scopeFieldLabels.settlement"
                            width="160"
                            align="right"
                        >
                            <template #default="{ row }">
                                ¥{{ formatPrice(row[scopedProductSettlementField]) }}
                            </template>
                        </el-table-column>
                        <el-table-column
                            v-if="amountScope !== 'gross'"
                            label="下单金额（含取消）"
                            width="160"
                            align="right"
                        >
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
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue';
import axios from '../../utils/axios';
import { ElMessage } from 'element-plus';
import { QuestionFilled } from '@element-plus/icons-vue';
import * as echarts from 'echarts';

const loading = ref(false);
const exporting = ref(false);
const selectedPeriod = ref('realtime');
const customDateRange = ref(null);
const selectedScenicSpotId = ref(null);
const selectedChannelId = ref(null);
const scenicSpotOptions = ref([]);
const channelOptions = ref([]);
const dateType = ref('booking');
const amountScope = ref('valid');
const statusDistributionTab = ref('unified');
const trendChartRef = ref(null);
const platformChartRef = ref(null);
const statusChartRef = ref(null);

let trendChart = null;
let platformChart = null;
let statusChart = null;

const defaultMetricScopes = [
    {
        key: 'valid',
        label: '有效销售',
        description: '排除已取消、拒单、失败订单，反映真实销售规模。',
    },
    {
        key: 'gross',
        label: '下单总量',
        description: '统计周期内全部下单记录，包含后续取消的订单。',
    },
    {
        key: 'fulfilled',
        label: '核销履约',
        description: '仅统计已核销订单，反映实际到场/履约金额。',
    },
];

const scopeFieldMap = {
    valid: {
        orderCount: '有效订单数',
        amount: '有效销售额',
        settlement: '有效结算额',
        secondaryOrderCount: '下单总量',
        secondaryAmount: '下单金额',
        secondarySettlement: '下单结算',
        stats: {
            orderCount: 'valid_orders',
            amount: 'valid_amount',
            settlement: 'valid_settlement_amount',
        },
        secondary: {
            orderCount: 'total_orders',
            amount: 'total_amount',
            settlement: 'total_settlement_amount',
        },
        platform: { count: 'valid_count', amount: 'valid_amount', settlement: 'valid_settlement_amount' },
        trend: { count: 'valid_count', amount: 'valid_amount' },
        product: { count: 'valid_order_count', amount: 'valid_amount', settlement: 'valid_settlement_amount' },
    },
    gross: {
        orderCount: '下单总量',
        amount: '下单金额',
        settlement: '下单结算额',
        secondaryOrderCount: '有效订单数',
        secondaryAmount: '有效销售额',
        secondarySettlement: '有效结算额',
        stats: {
            orderCount: 'total_orders',
            amount: 'total_amount',
            settlement: 'total_settlement_amount',
        },
        secondary: {
            orderCount: 'valid_orders',
            amount: 'valid_amount',
            settlement: 'valid_settlement_amount',
        },
        platform: { count: 'count', amount: 'total_amount', settlement: 'settlement_amount' },
        trend: { count: 'count', amount: 'total_amount' },
        product: { count: 'order_count', amount: 'total_amount', settlement: 'settlement_amount' },
    },
    fulfilled: {
        orderCount: '核销订单数',
        amount: '核销金额',
        settlement: null,
        secondaryOrderCount: '预订成功',
        secondaryAmount: '有效销售额',
        secondarySettlement: null,
        stats: {
            orderCount: 'verified_orders',
            amount: 'verified_total_amount',
            settlement: null,
        },
        secondary: {
            orderCount: 'successful_orders',
            amount: 'valid_amount',
            settlement: null,
        },
        platform: { count: 'verified_count', amount: 'verified_amount', settlement: null },
        trend: { count: 'verified_count', amount: 'verified_amount' },
        product: { count: 'verified_order_count', amount: 'verified_amount', settlement: null },
    },
};

const reportData = ref({
    period: 'day',
    date_type: 'booking',
    scenic_spot_id: null,
    ota_platform_id: null,
    start_date: '',
    end_date: '',
    available_scenic_spots: [],
    available_channels: [],
    metric_scopes: defaultMetricScopes,
    stats: {},
    order_stats: {},
    pkg_order_stats: {},
    status_distribution: {
        orders: [],
        pkg_orders: [],
        unified: [],
    },
    platform_distribution: [],
    time_trend: [],
    top_products: [],
    comparison: null,
});

const metricScopes = computed(() => reportData.value.metric_scopes?.length
    ? reportData.value.metric_scopes
    : defaultMetricScopes);

const currentScope = computed(() => scopeFieldMap[amountScope.value] || scopeFieldMap.valid);

const currentScopeLabel = computed(() => {
    const scope = metricScopes.value.find((item) => item.key === amountScope.value);
    return scope?.label || '有效销售';
});

const currentScopeDescription = computed(() => {
    const scope = metricScopes.value.find((item) => item.key === amountScope.value);
    return scope?.description || defaultMetricScopes[0].description;
});

const scopeFieldLabels = computed(() => currentScope.value);

const scopePrimaryStats = computed(() => {
    const stats = reportData.value.stats || {};
    const fields = currentScope.value.stats;

    return {
        orderCount: stats[fields.orderCount] || 0,
        amount: stats[fields.amount] || 0,
        settlement: fields.settlement ? (stats[fields.settlement] || 0) : 0,
    };
});

const scopeSecondaryStats = computed(() => {
    const stats = reportData.value.stats || {};
    const fields = currentScope.value.secondary;

    return {
        orderCount: stats[fields.orderCount] || 0,
        amount: stats[fields.amount] || 0,
        settlement: fields.settlement ? (stats[fields.settlement] || 0) : 0,
    };
});

const scopeComparison = computed(() => {
    const changes = reportData.value.comparison?.changes;
    const fields = currentScope.value.stats;

    if (!changes) {
        return null;
    }

    return {
        orderCount: changes[fields.orderCount] || null,
        amount: changes[fields.amount] || null,
        settlement: fields.settlement ? (changes[fields.settlement] || null) : null,
    };
});

const metricTips = computed(() => ({
    orderCount: `${currentScopeLabel.value}口径下的订单数量。`,
    amount: `${currentScopeLabel.value}口径下的销售金额。`,
    settlement: currentScope.value.settlement
        ? `${currentScopeLabel.value}口径下的结算金额。`
        : '',
}));

const scopedCountField = computed(() => currentScope.value.platform.count);
const scopedAmountField = computed(() => currentScope.value.platform.amount);
const scopedSettlementField = computed(() => currentScope.value.platform.settlement);

const scopedProductCountField = computed(() => currentScope.value.product.count);
const scopedProductAmountField = computed(() => currentScope.value.product.amount);
const scopedProductSettlementField = computed(() => currentScope.value.product.settlement);

const statusDistributionOrders = computed(() => reportData.value.status_distribution?.orders || []);
const statusDistributionPkgOrders = computed(() => reportData.value.status_distribution?.pkg_orders || []);
const statusDistributionUnified = computed(() => reportData.value.status_distribution?.unified || []);

const hasStatusDistribution = computed(() => (
    statusDistributionUnified.value.length > 0
    || statusDistributionOrders.value.length > 0
    || statusDistributionPkgOrders.value.length > 0
));

const displayTopProducts = computed(() => {
    const products = [...(reportData.value.top_products || [])];
    const amountField = scopedProductAmountField.value;

    return products
        .sort((a, b) => (b[amountField] || 0) - (a[amountField] || 0))
        .slice(0, 10);
});

const buildReportParams = () => {
    const params = {
        period: selectedPeriod.value,
        date_type: dateType.value,
    };

    if (selectedScenicSpotId.value) {
        params.scenic_spot_id = selectedScenicSpotId.value;
    }
    if (selectedChannelId.value) {
        params.ota_platform_id = selectedChannelId.value;
    }
    if (selectedPeriod.value === 'custom' && customDateRange.value && customDateRange.value.length === 2) {
        params.start_date = customDateRange.value[0];
        params.end_date = customDateRange.value[1];
    }

    return params;
};

const fetchReport = async () => {
    loading.value = true;
    try {
        const response = await axios.get('/operation-report', { params: buildReportParams() });
        reportData.value = response.data;
        scenicSpotOptions.value = response.data.available_scenic_spots || [];
        channelOptions.value = response.data.available_channels || [];
        await nextTick();
        renderCharts();
    } catch (error) {
        console.error('获取运营快报数据失败:', error);
        ElMessage.error('获取运营快报数据失败');
    } finally {
        loading.value = false;
    }
};

const handleExport = async () => {
    exporting.value = true;
    try {
        const response = await axios.get('/operation-report/export', {
            params: buildReportParams(),
            responseType: 'blob',
            validateStatus: (status) => status < 500,
        });

        const contentType = response.headers['content-type'] || '';
        if (contentType.includes('application/json') || response.status >= 400) {
            const text = await response.data.text();
            let errorMessage = '导出失败';
            try {
                const errorData = JSON.parse(text);
                errorMessage = errorData.message || errorMessage;
            } catch {
                errorMessage = text || errorMessage;
            }
            ElMessage.error(errorMessage);
            return;
        }

        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `operation_report_${new Date().toISOString().slice(0, 10)}.csv`);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
        ElMessage.success('导出成功');
    } catch (error) {
        ElMessage.error('导出失败');
    } finally {
        exporting.value = false;
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

const handleFilterChange = () => {
    fetchReport();
};

const formatPrice = (price) => {
    if (!price) return '0.00';
    return Number(price).toLocaleString('zh-CN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

const formatCompareText = (changePercent, label = '上一周期') => {
    if (changePercent === null || changePercent === undefined) {
        return `较${label} 新增`;
    }

    const value = Number(changePercent || 0);
    const prefix = value > 0 ? '+' : '';
    return `较${label} ${prefix}${value.toFixed(2)}%`;
};

const getCompareClass = (changePercent) => {
    if (changePercent === null || changePercent === undefined) {
        return 'compare-new';
    }

    const value = Number(changePercent || 0);
    if (value > 0) return 'compare-up';
    if (value < 0) return 'compare-down';
    return 'compare-flat';
};

const disposeCharts = () => {
    trendChart?.dispose();
    platformChart?.dispose();
    statusChart?.dispose();
    trendChart = null;
    platformChart = null;
    statusChart = null;
};

const renderCharts = () => {
    disposeCharts();
    renderTrendChart();
    renderPlatformChart();
    renderStatusChart();
};

const renderTrendChart = () => {
    if (!trendChartRef.value || !reportData.value.time_trend?.length) {
        return;
    }

    trendChart = echarts.init(trendChartRef.value);
    const countField = scopedCountField.value;
    const amountField = scopedAmountField.value;
    const dates = reportData.value.time_trend.map((item) => item.date);
    const counts = reportData.value.time_trend.map((item) => item[countField] || 0);
    const amounts = reportData.value.time_trend.map((item) => item[amountField] || 0);

    trendChart.setOption({
        tooltip: { trigger: 'axis' },
        legend: { data: [scopeFieldLabels.value.orderCount, scopeFieldLabels.value.amount] },
        grid: { left: 50, right: 50, bottom: 30, top: 40 },
        xAxis: { type: 'category', data: dates },
        yAxis: [
            { type: 'value', name: '订单数' },
            { type: 'value', name: '金额(元)', splitLine: { show: false } },
        ],
        series: [
            {
                name: scopeFieldLabels.value.orderCount,
                type: 'bar',
                data: counts,
                itemStyle: { color: '#409eff' },
            },
            {
                name: scopeFieldLabels.value.amount,
                type: 'line',
                yAxisIndex: 1,
                smooth: true,
                data: amounts,
                itemStyle: { color: '#67c23a' },
            },
        ],
    });
};

const renderPlatformChart = () => {
    if (!platformChartRef.value || !reportData.value.platform_distribution?.length) {
        return;
    }

    platformChart = echarts.init(platformChartRef.value);
    const amountField = scopedAmountField.value;
    const platforms = [...reportData.value.platform_distribution]
        .sort((a, b) => (b[amountField] || 0) - (a[amountField] || 0))
        .slice(0, 8);

    platformChart.setOption({
        tooltip: { trigger: 'axis' },
        grid: { left: 100, right: 30, bottom: 30, top: 20 },
        xAxis: { type: 'value' },
        yAxis: {
            type: 'category',
            data: platforms.map((item) => item.name),
            inverse: true,
        },
        series: [
            {
                type: 'bar',
                data: platforms.map((item) => item[amountField] || 0),
                itemStyle: { color: '#409eff' },
            },
        ],
    });
};

const renderStatusChart = () => {
    if (!statusChartRef.value || !statusDistributionUnified.value.length) {
        return;
    }

    statusChart = echarts.init(statusChartRef.value);

    statusChart.setOption({
        tooltip: { trigger: 'item' },
        legend: { bottom: 0 },
        series: [
            {
                type: 'pie',
                radius: ['35%', '65%'],
                data: statusDistributionUnified.value.map((item) => ({
                    name: item.label,
                    value: item.count,
                })),
            },
        ],
    });
};

const handleResize = () => {
    trendChart?.resize();
    platformChart?.resize();
    statusChart?.resize();
};

const getSuccessRate = () => {
    const valid = reportData.value.stats?.valid_orders || 0;
    const successful = reportData.value.stats?.successful_orders || 0;
    if (valid === 0) return '0.00';
    return ((successful / valid) * 100).toFixed(2);
};

const getVerifyRate = () => {
    const successful = reportData.value.stats?.successful_orders || 0;
    const verified = reportData.value.stats?.verified_orders || 0;
    if (successful === 0) return '0.00';
    return ((verified / successful) * 100).toFixed(2);
};

const getCancelRate = () => {
    const total = reportData.value.stats?.total_orders || 0;
    const cancelled = reportData.value.stats?.cancelled_orders || 0;
    if (total === 0) return '0.00';
    return ((cancelled / total) * 100).toFixed(2);
};

const getUnifiedStatusTagType = (status) => {
    if (status === 'confirmed' || status === 'verified') return 'success';
    if (status === 'cancelled' || status === 'failed') return 'danger';
    if (status === 'pending' || status === 'cancel_rejected') return 'warning';
    return 'info';
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
    window.addEventListener('resize', handleResize);
});

onUnmounted(() => {
    window.removeEventListener('resize', handleResize);
    disposeCharts();
});

watch(amountScope, async () => {
    await nextTick();
    renderCharts();
});
</script>

<style scoped>
.operation-report-container {
    padding: 20px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    gap: 12px;
}

.page-header h2 {
    margin: 0;
    font-size: 24px;
    font-weight: 600;
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

.filter-row {
    margin-bottom: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.date-type-tip {
    margin-bottom: 12px;
}

.stats-range-tip {
    margin-bottom: 12px;
    text-align: center;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.comparison-range-tip {
    display: block;
}

.stat-compare {
    margin-top: 8px;
    font-size: 12px;
    font-weight: 600;
}

.compare-up {
    color: #67c23a;
}

.compare-down {
    color: #f56c6c;
}

.compare-flat {
    color: #909399;
}

.compare-new {
    color: #e6a23c;
}

.charts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.chart-container {
    width: 100%;
    height: 320px;
}

.stats-section-title {
    margin: 0 0 12px 0;
    font-size: 14px;
    font-weight: 600;
    color: #606266;
    display: flex;
    align-items: center;
    gap: 6px;
}

.scope-tip {
    margin-bottom: 12px;
}

.stat-title-row {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    font-size: 14px;
    color: #606266;
    margin-bottom: 10px;
}

.metric-help-icon {
    font-size: 14px;
    color: #909399;
    cursor: help;
}

.stat-card-primary :deep(.el-statistic__number) {
    color: #409eff;
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
    .filter-row {
        flex-direction: column;
        align-items: stretch;
    }

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

