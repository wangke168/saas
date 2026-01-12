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

                <el-tabs v-model="activeTab" style="margin-top: 20px;">
                    <!-- 价格日历标签页 -->
                    <el-tab-pane label="价格日历" name="priceCalendar">
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
                    </el-tab-pane>

                    <!-- OTA推送管理标签页 -->
                    <el-tab-pane label="OTA推送管理" name="otaProducts">
                        <div style="margin-bottom: 20px;">
                            <el-button type="primary" @click="handleBindOta">绑定OTA平台</el-button>
                        </div>
                        <el-table :data="pkgOtaProducts" border v-loading="otaProductsLoading">
                            <el-table-column label="OTA平台" width="150">
                                <template #default="{ row }">
                                    {{ row.ota_platform?.name || '-' }}
                                </template>
                            </el-table-column>
                            <el-table-column label="OTA产品ID" width="200">
                                <template #default="{ row }">
                                    <span v-if="row.ota_product_id">{{ row.ota_product_id }}</span>
                                    <span v-else style="color: #909399;">-</span>
                                </template>
                            </el-table-column>
                            <el-table-column prop="is_active" label="状态" width="100">
                                <template #default="{ row }">
                                    <el-tag :type="row.is_active ? 'success' : 'danger'">
                                        {{ row.is_active ? '启用' : '禁用' }}
                                    </el-tag>
                                </template>
                            </el-table-column>
                            <el-table-column label="推送状态" width="150">
                                <template #default="{ row }">
                                    <el-tag 
                                        v-if="row.push_status === 'processing'"
                                        type="warning"
                                    >
                                        推送中...
                                    </el-tag>
                                    <el-tag 
                                        v-else-if="row.push_status === 'success'"
                                        type="success"
                                    >
                                        推送成功
                                    </el-tag>
                                    <el-tag 
                                        v-else-if="row.push_status === 'failed'"
                                        type="danger"
                                    >
                                        推送失败
                                    </el-tag>
                                    <el-tag 
                                        v-else-if="row.pushed_at"
                                        type="success"
                                    >
                                        已推送
                                    </el-tag>
                                    <el-tag 
                                        v-else
                                        type="info"
                                    >
                                        未推送
                                    </el-tag>
                                </template>
                            </el-table-column>
                            <el-table-column prop="pushed_at" label="推送时间" width="180">
                                <template #default="{ row }">
                                    {{ row.pushed_at ? formatDate(row.pushed_at) : '-' }}
                                </template>
                            </el-table-column>
                            <el-table-column label="操作" width="250" fixed="right">
                                <template #default="{ row }">
                                    <el-button size="small" @click="handleEditOtaProduct(row)">编辑</el-button>
                                    <el-button size="small" type="danger" @click="handleDeleteOtaProduct(row)">删除</el-button>
                                    <el-button 
                                        size="small" 
                                        type="primary" 
                                        @click="handlePushOtaProduct(row)"
                                        :disabled="!row.is_active || row.push_status === 'processing'"
                                        :loading="row.push_status === 'processing'"
                                    >
                                        {{ row.push_status === 'processing' ? '推送中...' : (row.pushed_at ? '重新推送' : '推送') }}
                                    </el-button>
                                </template>
                            </el-table-column>
                        </el-table>
                        
                        <!-- OTA绑定对话框 -->
                        <el-dialog
                            v-model="otaBindDialogVisible"
                            title="绑定OTA平台"
                            width="500px"
                        >
                            <el-form :model="otaBindForm" label-width="120px">
                                <el-form-item label="选择OTA平台" required>
                                    <el-select
                                        v-model="otaBindForm.ota_platform_id"
                                        placeholder="请选择要绑定的OTA平台"
                                        style="width: 100%"
                                    >
                                        <el-option
                                            v-for="platform in otaPlatforms"
                                            :key="platform.id"
                                            :label="platform.name"
                                            :value="platform.id"
                                        />
                                    </el-select>
                                </el-form-item>
                            </el-form>
                            <template #footer>
                                <el-button @click="otaBindDialogVisible = false">取消</el-button>
                                <el-button type="primary" @click="handleSubmitBindOta" :loading="otaBindSubmitting">确定</el-button>
                            </template>
                        </el-dialog>
                    </el-tab-pane>
                </el-tabs>
            </div>
            <el-empty v-else-if="!loading" description="产品信息加载失败或不存在" />
        </el-card>
    </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue';
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
const activeTab = ref('priceCalendar');

// 获取关联的酒店和房型列表（用于筛选）
const hotels = ref([]);
const roomTypes = ref([]);

// OTA推送管理相关
const pkgOtaProducts = ref([]);
const otaProductsLoading = ref(false);
const otaPlatforms = ref([]);
const otaBindDialogVisible = ref(false);
const otaBindSubmitting = ref(false);
const otaBindForm = ref({
    ota_platform_id: null,
});
const pollingIntervals = ref({});

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
        
        // 加载OTA平台列表
        await fetchOtaPlatforms();
        
        // 加载OTA产品绑定列表
        await fetchOtaProducts();
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
    ElMessage.warning('请先绑定OTA平台，然后在"OTA推送管理"标签页中进行推送操作');
    activeTab.value = 'otaProducts';
};

// OTA推送管理相关函数
const fetchOtaPlatforms = async () => {
    try {
        const response = await axios.get('/ota-platforms', {
            params: {
                active_only: true,
            },
        });
        otaPlatforms.value = response.data.data || [];
    } catch (error) {
        console.error('获取OTA平台列表失败', error);
    }
};

const fetchOtaProducts = async () => {
    if (!product.value) return;
    
    otaProductsLoading.value = true;
    try {
        // 从产品数据中获取OTA产品绑定列表
        pkgOtaProducts.value = product.value.ota_products || [];
    } catch (error) {
        console.error('获取OTA产品绑定列表失败', error);
    } finally {
        otaProductsLoading.value = false;
    }
};

// 只获取OTA产品列表（不刷新整个产品详情）
const fetchOtaProductsOnly = async () => {
    if (!product.value) return;
    
    try {
        const response = await axios.get('/pkg-ota-products', {
            params: {
                pkg_product_id: route.params.id,
            },
        });
        pkgOtaProducts.value = response.data.data || [];
        
        // 更新产品数据中的 ota_products
        if (product.value) {
            product.value.ota_products = pkgOtaProducts.value;
        }
    } catch (error) {
        console.error('获取OTA产品绑定列表失败', error);
    }
};

const handleBindOta = () => {
    otaBindForm.value = {
        ota_platform_id: null,
    };
    otaBindDialogVisible.value = true;
};

const handleSubmitBindOta = async () => {
    if (!otaBindForm.value.ota_platform_id) {
        ElMessage.warning('请选择OTA平台');
        return;
    }

    otaBindSubmitting.value = true;
    try {
        const response = await axios.post(`/pkg-products/${route.params.id}/bind-ota`, {
            ota_platform_id: otaBindForm.value.ota_platform_id,
        });

        if (response.data.success) {
            ElMessage.success('绑定成功');
            otaBindDialogVisible.value = false;
            // 只刷新OTA产品列表，不刷新整个产品详情
            if (response.data.data?.ota_product) {
                pkgOtaProducts.value.push(response.data.data.ota_product);
                // 同时更新产品数据中的 ota_products
                if (product.value) {
                    if (!product.value.ota_products) {
                        product.value.ota_products = [];
                    }
                    product.value.ota_products.push(response.data.data.ota_product);
                }
            } else {
                await fetchOtaProductsOnly();
            }
        } else {
            ElMessage.error(response.data.message || '绑定失败');
        }
    } catch (error) {
        const message = error.response?.data?.message || '绑定失败';
        ElMessage.error(message);
    } finally {
        otaBindSubmitting.value = false;
    }
};

const handlePushOtaProduct = async (row) => {
    try {
        await ElMessageBox.confirm(
            `确定要推送产品价格到 ${row.ota_platform?.name} 吗？`,
            '确认推送',
            {
                type: 'warning',
                confirmButtonText: '确定推送',
                cancelButtonText: '取消'
            }
        );
        
        const response = await axios.post(`/pkg-ota-products/${row.id}/push`);
        
        if (response.data.success) {
            if (response.data.message && response.data.message.includes('后台处理')) {
                ElMessage.success('推送任务已提交，正在后台处理中');
            } else {
                ElMessage.success('推送成功');
            }
            
            // 更新本地数据中的推送状态
            if (response.data.data) {
                row.push_status = response.data.data.push_status;
                row.push_started_at = response.data.data.push_started_at;
            }
            
            // 如果是异步推送，开始轮询状态
            if (response.data.data?.push_status === 'processing') {
                startPollingPushStatus(row.id);
            } else {
                // 同步推送成功，只更新当前行的状态
                if (response.data.data) {
                    Object.assign(row, response.data.data);
                }
            }
        } else {
            ElMessage.error(response.data.message || '推送失败');
        }
    } catch (error) {
        if (error !== 'cancel') {
            const message = error.response?.data?.message || '推送失败';
            ElMessage.error(message);
        }
    }
};

const handleEditOtaProduct = async (row) => {
    try {
        // 如果已推送，只能修改状态；如果未推送，可以修改平台和状态
        const canChangePlatform = !row.pushed_at;
        
        if (canChangePlatform) {
            // 未推送：可以修改平台和状态
            await ElMessageBox.prompt(
                '请选择OTA平台',
                '编辑OTA产品',
                {
                    confirmButtonText: '确定',
                    cancelButtonText: '取消',
                    inputType: 'select',
                    inputOptions: otaPlatforms.value.reduce((acc, platform) => {
                        acc[platform.id] = platform.name;
                        return acc;
                    }, {}),
                    inputValue: row.ota_platform_id?.toString(),
                }
            ).then(async ({ value }) => {
                const platformId = parseInt(value);
                const response = await axios.put(`/pkg-ota-products/${row.id}`, {
                    ota_platform_id: platformId,
                    is_active: row.is_active,
                });
                ElMessage.success('更新成功');
                // 只更新OTA产品列表，不刷新整个产品详情
                if (response.data.success && response.data.data) {
                    Object.assign(row, response.data.data);
                    // 同步更新产品数据中的 ota_products
                    if (product.value?.ota_products) {
                        const index = product.value.ota_products.findIndex(op => op.id === row.id);
                        if (index !== -1) {
                            Object.assign(product.value.ota_products[index], response.data.data);
                        }
                    }
                } else {
                    await fetchOtaProductsOnly();
                }
            });
        } else {
            // 已推送：只能修改状态
            await ElMessageBox.prompt('请选择状态', '编辑OTA产品', {
                confirmButtonText: '确定',
                cancelButtonText: '取消',
                inputType: 'select',
                inputOptions: {
                    true: '启用',
                    false: '禁用',
                },
                inputValue: row.is_active ? 'true' : 'false',
            }).then(async ({ value }) => {
                const isActive = value === 'true';
                const response = await axios.put(`/pkg-ota-products/${row.id}`, {
                    is_active: isActive,
                });
                ElMessage.success('更新成功');
                // 只更新OTA产品列表，不刷新整个产品详情
                if (response.data.success && response.data.data) {
                    Object.assign(row, response.data.data);
                    // 同步更新产品数据中的 ota_products
                    if (product.value?.ota_products) {
                        const index = product.value.ota_products.findIndex(op => op.id === row.id);
                        if (index !== -1) {
                            Object.assign(product.value.ota_products[index], response.data.data);
                        }
                    }
                } else {
                    await fetchOtaProductsOnly();
                }
            });
        }
    } catch (error) {
        if (error !== 'cancel') {
            console.error('编辑OTA产品失败', error);
        }
    }
};

const handleDeleteOtaProduct = async (row) => {
    try {
        await ElMessageBox.confirm('确定要删除该OTA推送记录吗？', '提示', {
            type: 'warning',
            confirmButtonText: '确定删除',
            cancelButtonText: '取消'
        });
        
        await axios.delete(`/pkg-ota-products/${row.id}`);
        ElMessage.success('删除成功');
        // 只更新OTA产品列表，不刷新整个产品详情
        const index = pkgOtaProducts.value.findIndex(op => op.id === row.id);
        if (index !== -1) {
            pkgOtaProducts.value.splice(index, 1);
        }
        // 同步更新产品数据中的 ota_products
        if (product.value?.ota_products) {
            const productIndex = product.value.ota_products.findIndex(op => op.id === row.id);
            if (productIndex !== -1) {
                product.value.ota_products.splice(productIndex, 1);
            }
        }
    } catch (error) {
        if (error !== 'cancel') {
            const message = error.response?.data?.message || '删除失败';
            ElMessage.error(message);
        }
    }
};

const startPollingPushStatus = (pkgOtaProductId) => {
    // 清除之前的轮询
    if (pollingIntervals.value[pkgOtaProductId]) {
        clearInterval(pollingIntervals.value[pkgOtaProductId]);
        delete pollingIntervals.value[pkgOtaProductId];
    }

    // 开始轮询
    pollingIntervals.value[pkgOtaProductId] = setInterval(async () => {
        try {
            // 只获取OTA产品列表，不刷新整个产品详情
            await fetchOtaProductsOnly();
            const updatedOtaProduct = pkgOtaProducts.value.find(op => op.id === pkgOtaProductId);
            
            if (updatedOtaProduct) {
                if (updatedOtaProduct.push_status !== 'processing') {
                    clearInterval(pollingIntervals.value[pkgOtaProductId]);
                    delete pollingIntervals.value[pkgOtaProductId];
                    
                    if (updatedOtaProduct.push_status === 'success') {
                        ElMessage.success(`产品价格推送到 ${updatedOtaProduct.ota_platform?.name} 成功`);
                    } else if (updatedOtaProduct.push_status === 'failed') {
                        ElMessage.error(`产品价格推送到 ${updatedOtaProduct.ota_platform?.name} 失败：${updatedOtaProduct.push_message || '未知错误'}`);
                    }
                }
            } else {
                // 如果找不到该 otaProduct，可能已被删除，停止轮询
                clearInterval(pollingIntervals.value[pkgOtaProductId]);
                delete pollingIntervals.value[pkgOtaProductId];
            }
        } catch (error) {
            console.error('轮询推送状态失败', error);
            clearInterval(pollingIntervals.value[pkgOtaProductId]);
            delete pollingIntervals.value[pkgOtaProductId];
        }
    }, 3000); // 每3秒轮询一次
};

onMounted(() => {
    fetchProductDetail();
});

// 组件卸载时清除轮询
onUnmounted(() => {
    Object.keys(pollingIntervals.value).forEach(id => {
        clearInterval(pollingIntervals.value[id]);
    });
    pollingIntervals.value = {};
});
</script>

<style scoped>
h2 {
    margin-bottom: 20px;
}
</style>

