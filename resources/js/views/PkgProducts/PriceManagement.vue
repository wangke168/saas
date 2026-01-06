<template>
    <div>
        <el-page-header @back="goBack" title="返回打包产品列表">
            <template #content>
                <span>价格管理 - {{ product?.product_name || '加载中...' }}</span>
            </template>
        </el-page-header>

        <el-card v-loading="loading" style="margin-top: 20px;">
            <div v-if="product">
                <el-descriptions title="产品基本信息" :column="2" border style="margin-bottom: 20px;">
                    <el-descriptions-item label="产品名称">{{ product.product_name }}</el-descriptions-item>
                    <el-descriptions-item label="产品编码">{{ product.product_code }}</el-descriptions-item>
                    <el-descriptions-item label="所属景区">{{ product.scenic_spot?.name || '-' }}</el-descriptions-item>
                    <el-descriptions-item label="入住天数">{{ product.stay_days }} 天</el-descriptions-item>
                    <el-descriptions-item label="状态">
                        <el-tag :type="product.status === 1 ? 'success' : 'danger'">
                            {{ product.status === 1 ? '启用' : '禁用' }}
                        </el-tag>
                    </el-descriptions-item>
                </el-descriptions>

                <el-divider>价格管理操作</el-divider>

                <div style="margin-bottom: 20px;">
                    <el-button type="primary" @click="handleCalculate" :loading="calculating">
                        <el-icon><Refresh /></el-icon>
                        预计算价格（未来60天）
                    </el-button>
                    <el-button type="success" @click="handleRecalculate" :loading="recalculating">
                        <el-icon><RefreshRight /></el-icon>
                        重新计算价格
                    </el-button>
                    <el-button type="warning" @click="handleSyncToOta" :loading="syncing">
                        <el-icon><Upload /></el-icon>
                        推送价格到OTA
                    </el-button>
                    <el-alert
                        title="提示"
                        type="info"
                        :closable="false"
                        style="margin-top: 10px;"
                    >
                        <template #default>
                            <div>
                                <p>• 预计算价格：为产品计算未来60天的价格日历</p>
                                <p>• 重新计算价格：当门票或酒店价格变更后，重新计算产品价格</p>
                                <p>• 价格公式：酒店价格 + Σ(门票价格 × 张数)</p>
                            </div>
                        </template>
                    </el-alert>
                </div>

                <el-divider>价格日历</el-divider>

                <div style="margin-bottom: 20px;">
                    <el-date-picker
                        v-model="dateRange"
                        type="daterange"
                        range-separator="至"
                        start-placeholder="开始日期"
                        end-placeholder="结束日期"
                        format="YYYY-MM-DD"
                        value-format="YYYY-MM-DD"
                        style="margin-right: 10px;"
                        @change="fetchPriceCalendar"
                    />
                    <el-select
                        v-model="filterHotelId"
                        placeholder="筛选酒店"
                        clearable
                        style="width: 200px; margin-right: 10px;"
                        @change="fetchPriceCalendar"
                    >
                        <el-option
                            v-for="hotel in hotels"
                            :key="hotel.id"
                            :label="hotel.name"
                            :value="hotel.id"
                        />
                    </el-select>
                    <el-select
                        v-model="filterRoomTypeId"
                        placeholder="筛选房型"
                        clearable
                        style="width: 200px; margin-right: 10px;"
                        @change="fetchPriceCalendar"
                    >
                        <el-option
                            v-for="roomType in roomTypes"
                            :key="roomType.id"
                            :label="roomType.name"
                            :value="roomType.id"
                        />
                    </el-select>
                    <el-button @click="resetFilter">重置筛选</el-button>
                </div>

                <el-table
                    :data="priceCalendar"
                    v-loading="calendarLoading"
                    border
                    style="width: 100%"
                    max-height="600"
                >
                    <el-table-column prop="biz_date" label="日期" width="120" fixed="left">
                        <template #default="{ row }">
                            {{ formatDateOnly(row.biz_date) }}
                        </template>
                    </el-table-column>
                    <el-table-column prop="hotel.name" label="酒店" width="150" />
                    <el-table-column prop="room_type.name" label="房型" width="150" />
                    <el-table-column prop="composite_code" label="OTA编码" width="250" show-overflow-tooltip>
                        <template #default="{ row }">
                            <el-tag size="small">{{ row.composite_code }}</el-tag>
                        </template>
                    </el-table-column>
                    <el-table-column prop="sale_price" label="销售价" width="120" align="right">
                        <template #default="{ row }">
                            ¥{{ formatPrice(row.sale_price) }}
                        </template>
                    </el-table-column>
                    <el-table-column prop="cost_price" label="成本价" width="120" align="right">
                        <template #default="{ row }">
                            ¥{{ formatPrice(row.cost_price) }}
                        </template>
                    </el-table-column>
                    <el-table-column prop="last_updated_at" label="更新时间" width="180">
                        <template #default="{ row }">
                            {{ formatDate(row.last_updated_at) }}
                        </template>
                    </el-table-column>
                </el-table>

                <el-empty v-if="!calendarLoading && priceCalendar.length === 0" description="暂无价格数据，请先预计算价格" />
            </div>
            <el-empty v-else-if="!loading" description="产品信息加载失败或不存在" />
        </el-card>
    </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import axios from '../../utils/axios';
import { ElMessage, ElMessageBox } from 'element-plus';
import { Refresh, RefreshRight, Upload } from '@element-plus/icons-vue';

const route = useRoute();
const router = useRouter();

const loading = ref(false);
const product = ref(null);
const calculating = ref(false);
const recalculating = ref(false);
const syncing = ref(false);
const calendarLoading = ref(false);
const priceCalendar = ref([]);
const dateRange = ref([]);
const filterHotelId = ref(null);
const filterRoomTypeId = ref(null);

// 获取关联的酒店和房型列表（用于筛选）
const hotels = ref([]);
const roomTypes = ref([]);

// 计算属性：根据筛选条件过滤房型
const filteredRoomTypes = computed(() => {
    if (!filterHotelId.value) {
        return roomTypes.value;
    }
    return roomTypes.value.filter(rt => rt.hotel_id === filterHotelId.value);
});

const goBack = () => {
    router.push('/pkg-products');
};

const formatDate = (dateString) => {
    if (!dateString) return '';
    const date = new Date(dateString);
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return `${year}-${month}-${day} ${hours}:${minutes}`;
};

const formatDateOnly = (dateString) => {
    if (!dateString) return '';
    const date = new Date(dateString);
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
};

const formatPrice = (price) => {
    if (!price) return '0.00';
    return parseFloat(price).toFixed(2);
};

const fetchProductDetail = async () => {
    loading.value = true;
    try {
        const response = await axios.get(`/pkg-products/${route.params.id}`);
        product.value = response.data.data;
        
        // 提取关联的酒店和房型
        if (product.value.hotel_room_types) {
            const hotelMap = new Map();
            const roomTypeMap = new Map();
            
            product.value.hotel_room_types.forEach(hrt => {
                if (hrt.hotel && !hotelMap.has(hrt.hotel.id)) {
                    hotelMap.set(hrt.hotel.id, hrt.hotel);
                }
                if (hrt.room_type && !roomTypeMap.has(hrt.room_type.id)) {
                    roomTypeMap.set(hrt.room_type.id, {
                        ...hrt.room_type,
                        hotel_id: hrt.hotel_id,
                    });
                }
            });
            
            hotels.value = Array.from(hotelMap.values());
            roomTypes.value = Array.from(roomTypeMap.values());
        }
        
        // 设置默认日期范围（未来30天）
        const today = new Date();
        const endDate = new Date();
        endDate.setDate(today.getDate() + 30);
        dateRange.value = [
            today.toISOString().split('T')[0],
            endDate.toISOString().split('T')[0],
        ];
        
        // 加载价格日历
        await fetchPriceCalendar();
    } catch (error) {
        ElMessage.error('获取产品详情失败');
        console.error(error);
    } finally {
        loading.value = false;
    }
};

const fetchPriceCalendar = async () => {
    if (!product.value) return;
    
    calendarLoading.value = true;
    try {
        const params = {};
        
        if (dateRange.value && dateRange.value.length === 2) {
            params.start_date = dateRange.value[0];
            params.end_date = dateRange.value[1];
        }
        
        if (filterHotelId.value) {
            params.hotel_id = filterHotelId.value;
        }
        
        if (filterRoomTypeId.value) {
            params.room_type_id = filterRoomTypeId.value;
        }
        
        const response = await axios.get(`/pkg-products/${route.params.id}/prices/calendar`, { params });
        priceCalendar.value = response.data.data || [];
    } catch (error) {
        ElMessage.error('获取价格日历失败');
        console.error(error);
    } finally {
        calendarLoading.value = false;
    }
};

const resetFilter = () => {
    dateRange.value = [];
    filterHotelId.value = null;
    filterRoomTypeId.value = null;
    fetchPriceCalendar();
};

const handleCalculate = async () => {
    try {
        await ElMessageBox.confirm(
            '确定要预计算未来60天的价格吗？这可能需要一些时间，将在后台异步处理。',
            '提示',
            {
                type: 'info',
            }
        );
        
        calculating.value = true;
        try {
            await axios.post(`/pkg-products/${route.params.id}/prices/calculate`);
            ElMessage.success('价格预计算任务已提交，正在后台处理，请稍后刷新查看结果');
        } catch (error) {
            const message = error.response?.data?.message || error.message || '操作失败';
            ElMessage.error(message);
            console.error(error);
        } finally {
            calculating.value = false;
        }
    } catch (error) {
        // 用户取消
    }
};

const handleRecalculate = async () => {
    try {
        await ElMessageBox.confirm(
            '确定要重新计算价格吗？这将重新计算所有关联的门票和酒店价格，可能需要一些时间。',
            '提示',
            {
                type: 'warning',
            }
        );
        
        recalculating.value = true;
        try {
            await axios.post(`/pkg-products/${route.params.id}/prices/recalculate`);
            ElMessage.success('价格重新计算任务已提交，正在后台处理，请稍后刷新查看结果');
        } catch (error) {
            const message = error.response?.data?.message || error.message || '操作失败';
            ElMessage.error(message);
            console.error(error);
        } finally {
            recalculating.value = false;
        }
    } catch (error) {
        // 用户取消
    }
};

const handleSyncToOta = async () => {
    try {
        const { value: platformCode } = await ElMessageBox.prompt(
            '请选择要推送的OTA平台',
            '推送价格到OTA',
            {
                confirmButtonText: '确定',
                cancelButtonText: '取消',
                inputType: 'select',
                inputOptions: {
                    'ctrip': '携程',
                    'meituan': '美团',
                },
                inputPlaceholder: '请选择OTA平台',
            }
        );

        if (!platformCode) {
            return;
        }

        syncing.value = true;
        try {
            await axios.post(`/pkg-products/${route.params.id}/prices/sync-to-ota`, {
                platform_code: platformCode,
            });
            ElMessage.success('价格推送到OTA任务已提交，正在后台处理，请稍后查看结果');
        } catch (error) {
            const message = error.response?.data?.message || error.message || '操作失败';
            ElMessage.error(message);
            console.error(error);
        } finally {
            syncing.value = false;
        }
    } catch (error) {
        // 用户取消
    }
};

onMounted(() => {
    fetchProductDetail();
});
</script>

<style scoped>
h2 {
    margin-bottom: 20px;
}
</style>

