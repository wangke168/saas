<template>
    <div>
        <div style="margin-bottom: 16px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <el-select
                v-model="queryForm.hotel_id"
                placeholder="筛选酒店"
                clearable
                style="width: 220px;"
                @change="onQueryHotelChange"
            >
                <el-option
                    v-for="hotel in hotels"
                    :key="hotel.id"
                    :label="hotel.name"
                    :value="hotel.id"
                />
            </el-select>
            <el-select
                v-model="queryForm.room_type_id"
                placeholder="筛选房型"
                clearable
                style="width: 220px;"
                :disabled="queryRoomTypes.length === 0"
            >
                <el-option
                    v-for="roomType in queryRoomTypes"
                    :key="roomType.id"
                    :label="roomType.name"
                    :value="roomType.id"
                />
            </el-select>
            <el-date-picker
                v-model="queryDateRange"
                type="daterange"
                range-separator="至"
                start-placeholder="开始日期"
                end-placeholder="结束日期"
                value-format="YYYY-MM-DD"
                style="width: 260px;"
            />
            <el-button type="primary" @click="fetchControls">查询</el-button>
            <el-button @click="resetQuery">重置</el-button>
        </div>

        <el-card shadow="never" style="margin-bottom: 16px;">
            <template #header>
                <span>批量开关</span>
            </template>
            <el-form :model="actionForm" label-width="90px" inline>
                <el-form-item label="酒店">
                    <el-select
                        v-model="actionForm.hotel_id"
                        placeholder="请选择酒店"
                        style="width: 220px;"
                        @change="onActionHotelChange"
                    >
                        <el-option
                            v-for="hotel in hotels"
                            :key="hotel.id"
                            :label="hotel.name"
                            :value="hotel.id"
                        />
                    </el-select>
                </el-form-item>
                <el-form-item label="房型">
                    <el-select
                        v-model="actionForm.room_type_ids"
                        multiple
                        collapse-tags
                        collapse-tags-tooltip
                        placeholder="请选择房型"
                        style="width: 280px;"
                        :disabled="actionRoomTypes.length === 0"
                    >
                        <el-option
                            v-for="roomType in actionRoomTypes"
                            :key="roomType.id"
                            :label="roomType.name"
                            :value="roomType.id"
                        />
                    </el-select>
                </el-form-item>
                <el-form-item label="日期区间">
                    <el-date-picker
                        v-model="actionDateRange"
                        type="daterange"
                        range-separator="至"
                        start-placeholder="开始日期"
                        end-placeholder="结束日期"
                        value-format="YYYY-MM-DD"
                        style="width: 260px;"
                    />
                </el-form-item>
                <el-form-item label="备注">
                    <el-input
                        v-model="actionForm.note"
                        maxlength="500"
                        show-word-limit
                        placeholder="可选，最多500字"
                        style="width: 260px;"
                    />
                </el-form-item>
                <el-form-item>
                    <el-button type="danger" :loading="batchSubmitting" @click="submitBatchClose">批量关闭</el-button>
                    <el-button type="success" :loading="batchSubmitting" @click="submitBatchOpen">批量开启</el-button>
                </el-form-item>
            </el-form>
        </el-card>

        <el-table :data="controls" border v-loading="tableLoading">
            <el-table-column label="酒店" min-width="160">
                <template #default="{ row }">
                    {{ row.hotel?.name || '-' }}
                </template>
            </el-table-column>
            <el-table-column label="房型" min-width="140">
                <template #default="{ row }">
                    {{ row.room_type?.name || '-' }}
                </template>
            </el-table-column>
            <el-table-column label="日期" width="130">
                <template #default="{ row }">
                    {{ formatDateOnly(row.date) }}
                </template>
            </el-table-column>
            <el-table-column label="状态" width="100">
                <template #default>
                    <el-tag type="danger">关闭</el-tag>
                </template>
            </el-table-column>
            <el-table-column label="备注" min-width="180">
                <template #default="{ row }">
                    {{ row.note || '-' }}
                </template>
            </el-table-column>
            <el-table-column label="更新时间" width="180">
                <template #default="{ row }">
                    {{ formatDateTime(row.updated_at) }}
                </template>
            </el-table-column>
        </el-table>

        <div style="margin-top: 12px; display: flex; justify-content: flex-end;">
            <el-pagination
                v-model:current-page="pagination.current_page"
                v-model:page-size="pagination.per_page"
                :page-sizes="[20, 50, 100]"
                :total="pagination.total"
                layout="total, sizes, prev, pager, next"
                @current-change="fetchControls"
                @size-change="handlePageSizeChange"
            />
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import axios from '../../../utils/axios';
import { ElMessage, ElMessageBox } from 'element-plus';

const props = defineProps({
    productId: {
        type: Number,
        required: true,
    },
    scenicSpotId: {
        type: Number,
        default: null,
    },
});

const hotels = ref([]);
const roomTypes = ref([]);
const controls = ref([]);
const tableLoading = ref(false);
const batchSubmitting = ref(false);
const availableHotelIds = ref(new Set());
const availableRoomTypeIds = ref(new Set());

const queryForm = ref({
    hotel_id: null,
    room_type_id: null,
});
const queryDateRange = ref(null);

const actionForm = ref({
    hotel_id: null,
    room_type_ids: [],
    note: '',
});
const actionDateRange = ref(null);

const pagination = ref({
    current_page: 1,
    per_page: 20,
    total: 0,
});

const queryRoomTypes = computed(() => {
    if (!queryForm.value.hotel_id) {
        return roomTypes.value;
    }
    return roomTypes.value.filter((roomType) => roomType.hotel_id === queryForm.value.hotel_id);
});

const actionRoomTypes = computed(() => {
    if (!actionForm.value.hotel_id) {
        return [];
    }
    return roomTypes.value.filter((roomType) => roomType.hotel_id === actionForm.value.hotel_id);
});

const fetchProductRelations = async () => {
    const response = await axios.get(`/products/${props.productId}`, {
        params: { include_prices: true },
    });
    const productData = response.data?.data || {};
    const prices = productData.prices || [];
    const hotelIds = new Set();
    const roomTypeIds = new Set();
    const roomTypeMap = new Map();
    const hotelMap = new Map();

    prices.forEach((price) => {
        const roomType = price.room_type;
        if (!roomType || !roomType.id) {
            return;
        }
        roomTypeIds.add(roomType.id);
        roomTypeMap.set(roomType.id, roomType);

        const hotel = roomType.hotel;
        if (!hotel || !hotel.id) {
            return;
        }
        hotelIds.add(hotel.id);
        hotelMap.set(hotel.id, hotel);
    });

    availableHotelIds.value = hotelIds;
    availableRoomTypeIds.value = roomTypeIds;
    hotels.value = Array.from(hotelMap.values());
    roomTypes.value = Array.from(roomTypeMap.values());
};

const fetchRoomTypes = async () => {
    const response = await axios.get('/room-types', {
        params: { per_page: 1000 },
    });
    const allRoomTypes = response.data.data || [];
    roomTypes.value = allRoomTypes.filter((roomType) => availableRoomTypeIds.value.has(roomType.id));
};

const fetchControls = async () => {
    tableLoading.value = true;
    try {
        const params = {
            per_page: pagination.value.per_page,
            page: pagination.value.current_page,
        };
        if (queryForm.value.hotel_id) {
            params.hotel_id = queryForm.value.hotel_id;
        }
        if (queryForm.value.room_type_id) {
            params.room_type_id = queryForm.value.room_type_id;
        }
        if (queryDateRange.value?.length === 2) {
            params.start_date = queryDateRange.value[0];
            params.end_date = queryDateRange.value[1];
        }
        const response = await axios.get(`/products/${props.productId}/inventory-controls`, { params });
        controls.value = response.data.data || [];
        pagination.value.total = response.data.total || 0;
        pagination.value.current_page = response.data.current_page || pagination.value.current_page;
        pagination.value.per_page = response.data.per_page || pagination.value.per_page;
    } catch (error) {
        ElMessage.error(error.response?.data?.message || '获取库存开关列表失败');
    } finally {
        tableLoading.value = false;
    }
};

const buildBatchPayload = () => {
    if (!actionForm.value.hotel_id) {
        ElMessage.warning('请选择酒店');
        return null;
    }
    if (!actionForm.value.room_type_ids.length) {
        ElMessage.warning('请选择至少一个房型');
        return null;
    }
    if (!actionDateRange.value || actionDateRange.value.length !== 2) {
        ElMessage.warning('请选择日期区间');
        return null;
    }

    return {
        hotel_id: actionForm.value.hotel_id,
        room_type_ids: actionForm.value.room_type_ids,
        start_date: actionDateRange.value[0],
        end_date: actionDateRange.value[1],
        note: actionForm.value.note || null,
    };
};

const submitBatchClose = async () => {
    const payload = buildBatchPayload();
    if (!payload) return;
    try {
        await ElMessageBox.confirm('确认批量关闭所选房型库存吗？', '提示', { type: 'warning' });
        batchSubmitting.value = true;
        await axios.post(`/products/${props.productId}/inventory-controls/batch-close`, payload);
        ElMessage.success('批量关闭成功');
        await fetchControls();
    } catch (error) {
        if (error !== 'cancel') {
            ElMessage.error(error.response?.data?.message || '批量关闭失败');
        }
    } finally {
        batchSubmitting.value = false;
    }
};

const submitBatchOpen = async () => {
    const payload = buildBatchPayload();
    if (!payload) return;
    try {
        await ElMessageBox.confirm('确认批量开启所选房型库存吗？', '提示', { type: 'warning' });
        batchSubmitting.value = true;
        await axios.post(`/products/${props.productId}/inventory-controls/batch-open`, payload);
        ElMessage.success('批量开启成功');
        await fetchControls();
    } catch (error) {
        if (error !== 'cancel') {
            ElMessage.error(error.response?.data?.message || '批量开启失败');
        }
    } finally {
        batchSubmitting.value = false;
    }
};

const onQueryHotelChange = () => {
    queryForm.value.room_type_id = null;
};

const onActionHotelChange = () => {
    actionForm.value.room_type_ids = [];
};

const resetQuery = () => {
    queryForm.value.hotel_id = null;
    queryForm.value.room_type_id = null;
    queryDateRange.value = null;
    pagination.value.current_page = 1;
    fetchControls();
};

const handlePageSizeChange = () => {
    pagination.value.current_page = 1;
    fetchControls();
};

const formatDateOnly = (date) => {
    if (!date) return '-';
    return String(date).slice(0, 10);
};

const formatDateTime = (dateTime) => {
    if (!dateTime) return '-';
    return new Date(dateTime).toLocaleString('zh-CN', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
};

onMounted(async () => {
    try {
        await fetchProductRelations();
        await fetchRoomTypes();
        await fetchControls();
    } catch (error) {
        ElMessage.error(error.response?.data?.message || '初始化库存开关数据失败');
    }
});
</script>
