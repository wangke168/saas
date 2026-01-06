<template>
    <div>
        <h2>打包产品管理</h2>
        <el-card>
            <div style="margin-bottom: 20px;">
                <el-button type="primary" @click="handleCreate">创建打包产品</el-button>
                <el-select
                    v-model="filterScenicSpotId"
                    placeholder="筛选景区"
                    clearable
                    style="width: 200px; margin-left: 10px;"
                    @change="handleFilter"
                >
                    <el-option
                        v-for="spot in scenicSpots"
                        :key="spot.id"
                        :label="spot.name"
                        :value="spot.id"
                    />
                </el-select>
                <el-select
                    v-model="filterStatus"
                    placeholder="筛选状态"
                    clearable
                    style="width: 150px; margin-left: 10px;"
                    @change="handleFilter"
                >
                    <el-option label="启用" :value="1" />
                    <el-option label="禁用" :value="0" />
                </el-select>
                <el-input
                    v-model="searchKeyword"
                    placeholder="搜索产品名称或编码"
                    style="width: 300px; margin-left: 10px;"
                    clearable
                    @input="handleSearch"
                >
                    <template #prefix>
                        <el-icon><Search /></el-icon>
                    </template>
                </el-input>
            </div>
            
            <el-table :data="products" v-loading="loading" border>
                <el-table-column prop="product_name" label="产品名称" width="200" />
                <el-table-column prop="product_code" label="产品编码" width="150" />
                <el-table-column label="所属景区" width="150">
                    <template #default="{ row }">
                        {{ row.scenic_spot?.name || '-' }}
                    </template>
                </el-table-column>
                <el-table-column label="关联门票" width="200">
                    <template #default="{ row }">
                        <div v-if="row.bundle_items && row.bundle_items.length > 0">
                            <el-tag
                                v-for="(item, index) in row.bundle_items"
                                :key="index"
                                size="small"
                                style="margin-right: 5px;"
                            >
                                {{ item.ticket?.name || '未知' }} x{{ item.quantity || 1 }}
                            </el-tag>
                        </div>
                        <span v-else>-</span>
                    </template>
                </el-table-column>
                <el-table-column label="关联酒店房型" width="200">
                    <template #default="{ row }">
                        <div v-if="row.hotel_room_types && row.hotel_room_types.length > 0">
                            <el-tag
                                v-for="(hrt, index) in row.hotel_room_types.slice(0, 2)"
                                :key="index"
                                size="small"
                                style="margin-right: 5px;"
                            >
                                {{ hrt.hotel?.name || '未知' }}-{{ hrt.room_type?.name || '未知' }}
                            </el-tag>
                            <span v-if="row.hotel_room_types.length > 2">...</span>
                        </div>
                        <span v-else>-</span>
                    </template>
                </el-table-column>
                <el-table-column prop="stay_days" label="入住天数" width="100" />
                <el-table-column prop="description" label="描述" show-overflow-tooltip />
                <el-table-column prop="status" label="状态" width="100">
                    <template #default="{ row }">
                        <el-tag :type="row.status === 1 ? 'success' : 'danger'">
                            {{ row.status === 1 ? '启用' : '禁用' }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="created_at" label="创建时间" width="180">
                    <template #default="{ row }">
                        {{ formatDate(row.created_at) }}
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="280" fixed="right">
                    <template #default="{ row }">
                        <el-button size="small" @click="handleViewDetail(row)">详情</el-button>
                        <el-button size="small" type="primary" @click="handlePriceManagement(row)">价格管理</el-button>
                        <el-button size="small" @click="handleEdit(row)">编辑</el-button>
                        <el-button size="small" type="danger" @click="handleDelete(row)">删除</el-button>
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
                @size-change="fetchProducts"
                @current-change="fetchProducts"
            />
        </el-card>

        <!-- 创建/编辑打包产品对话框 -->
        <el-dialog
            v-model="dialogVisible"
            :title="dialogTitle"
            width="900px"
            @close="resetForm"
        >
            <el-form
                ref="formRef"
                :model="form"
                :rules="rules"
                label-width="120px"
            >
                <el-form-item label="所属景区" prop="scenic_spot_id">
                    <el-select
                        v-model="form.scenic_spot_id"
                        placeholder="请选择景区"
                        style="width: 100%"
                        :disabled="isEdit"
                        @change="handleScenicSpotChange"
                    >
                        <el-option
                            v-for="spot in scenicSpots"
                            :key="spot.id"
                            :label="spot.name"
                            :value="spot.id"
                        />
                    </el-select>
                </el-form-item>
                <el-form-item label="产品名称" prop="product_name">
                    <el-input v-model="form.product_name" placeholder="请输入产品名称" />
                </el-form-item>
                <el-form-item label="入住天数" prop="stay_days">
                    <el-input-number
                        v-model="form.stay_days"
                        :min="1"
                        :max="30"
                        style="width: 100%"
                        placeholder="请输入入住天数"
                    />
                </el-form-item>
                <el-form-item label="描述" prop="description">
                    <el-input
                        v-model="form.description"
                        type="textarea"
                        :rows="3"
                        placeholder="请输入描述"
                    />
                </el-form-item>
                <el-form-item label="状态" prop="status">
                    <el-radio-group v-model="form.status">
                        <el-radio :label="1">启用</el-radio>
                        <el-radio :label="0">禁用</el-radio>
                    </el-radio-group>
                </el-form-item>
                <el-form-item label="销售开始日期" prop="sale_start_date">
                    <el-date-picker
                        v-model="form.sale_start_date"
                        type="date"
                        placeholder="选择销售开始日期（可选）"
                        format="YYYY-MM-DD"
                        value-format="YYYY-MM-DD"
                        style="width: 100%"
                    />
                    <div style="font-size: 12px; color: #909399; margin-top: 5px;">
                        不设置表示不限制开始日期
                    </div>
                </el-form-item>
                <el-form-item label="销售结束日期" prop="sale_end_date">
                    <el-date-picker
                        v-model="form.sale_end_date"
                        type="date"
                        placeholder="选择销售结束日期（可选）"
                        format="YYYY-MM-DD"
                        value-format="YYYY-MM-DD"
                        :disabled-date="(date) => form.sale_start_date && date < new Date(form.sale_start_date)"
                        style="width: 100%"
                    />
                    <div style="font-size: 12px; color: #909399; margin-top: 5px;">
                        不设置表示不限制结束日期
                    </div>
                </el-form-item>

                <!-- 关联门票 -->
                <el-form-item label="关联门票" prop="bundle_items">
                    <div style="width: 100%;">
                        <div
                            v-for="(item, index) in form.bundle_items"
                            :key="index"
                            style="display: flex; gap: 10px; margin-bottom: 10px; align-items: center;"
                        >
                            <el-select
                                v-model="item.ticket_id"
                                placeholder="选择门票"
                                style="flex: 1;"
                                filterable
                                @change="validateBundleItems"
                            >
                                <el-option
                                    v-for="ticket in availableTickets"
                                    :key="ticket.id"
                                    :label="`${ticket.name} (${ticket.code})`"
                                    :value="ticket.id"
                                />
                            </el-select>
                            <el-input-number
                                v-model="item.quantity"
                                :min="1"
                                :max="10"
                                placeholder="数量"
                                style="width: 120px;"
                                @change="validateBundleItems"
                            />
                            <el-button
                                type="danger"
                                :icon="Delete"
                                circle
                                @click="removeBundleItem(index)"
                            />
                        </div>
                        <el-button
                            type="primary"
                            :icon="Plus"
                            @click="addBundleItem"
                            :disabled="!form.scenic_spot_id"
                        >
                            添加门票
                        </el-button>
                    </div>
                </el-form-item>

                <!-- 关联酒店房型 -->
                <el-form-item label="关联酒店房型" prop="hotel_room_types">
                    <div style="width: 100%;">
                        <div
                            v-for="(hrt, index) in form.hotel_room_types"
                            :key="index"
                            style="display: flex; gap: 10px; margin-bottom: 10px; align-items: center;"
                        >
                            <el-select
                                v-model="hrt.hotel_id"
                                placeholder="选择酒店"
                                style="flex: 1;"
                                filterable
                                @change="handleHotelChange(index)"
                            >
                                <el-option
                                    v-for="hotel in availableHotels"
                                    :key="hotel.id"
                                    :label="hotel.name"
                                    :value="hotel.id"
                                />
                            </el-select>
                            <el-select
                                v-model="hrt.room_type_id"
                                placeholder="选择房型"
                                style="flex: 1;"
                                filterable
                                :disabled="!hrt.hotel_id"
                                @change="validateHotelRoomTypes"
                            >
                                <el-option
                                    v-for="roomType in getRoomTypesByHotel(hrt.hotel_id)"
                                    :key="roomType.id"
                                    :label="roomType.name"
                                    :value="roomType.id"
                                />
                            </el-select>
                            <el-button
                                type="danger"
                                :icon="Delete"
                                circle
                                @click="removeHotelRoomType(index)"
                            />
                        </div>
                        <el-button
                            type="primary"
                            :icon="Plus"
                            @click="addHotelRoomType"
                            :disabled="!form.scenic_spot_id"
                        >
                            添加酒店房型
                        </el-button>
                    </div>
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="dialogVisible = false">取消</el-button>
                <el-button type="primary" @click="handleSubmit" :loading="submitting">确定</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';
import { useRouter } from 'vue-router';
import axios from '../../utils/axios';
import { ElMessage, ElMessageBox } from 'element-plus';
import { Search, Plus, Delete } from '@element-plus/icons-vue';
import { useAuthStore } from '../../stores/auth';

const authStore = useAuthStore();
const router = useRouter();

const products = ref([]);
const scenicSpots = ref([]);
const loading = ref(false);
const searchKeyword = ref('');
const filterScenicSpotId = ref(null);
const filterStatus = ref(null);
const currentPage = ref(1);
const pageSize = ref(15);
const total = ref(0);

// 对话框相关
const dialogVisible = ref(false);
const submitting = ref(false);
const formRef = ref(null);
const editingId = ref(null);

// 表单数据
const form = ref({
    scenic_spot_id: null,
    product_name: '',
    stay_days: 1,
    description: '',
    status: 1,
    sale_start_date: null,
    sale_end_date: null,
    bundle_items: [],
    hotel_room_types: [],
});

// 可选项数据
const availableTickets = ref([]);
const availableHotels = ref([]);
const allRoomTypes = ref([]);

const isEdit = computed(() => editingId.value !== null);
const dialogTitle = computed(() => isEdit.value ? '编辑打包产品' : '创建打包产品');

const rules = {
    scenic_spot_id: [
        { required: true, message: '请选择所属景区', trigger: 'change' }
    ],
    product_name: [
        { required: true, message: '请输入产品名称', trigger: 'blur' },
        { max: 100, message: '产品名称不能超过100个字符', trigger: 'blur' }
    ],
    stay_days: [
        { required: true, message: '请输入入住天数', trigger: 'blur' },
        { type: 'number', min: 1, max: 30, message: '入住天数必须在1-30天之间', trigger: 'blur' }
    ],
    bundle_items: [
        { 
            validator: (rule, value, callback) => {
                if (!value || value.length === 0) {
                    callback(new Error('请至少添加一个关联门票'));
                } else {
                    // 检查是否有重复的门票
                    const ticketIds = value.map(item => item.ticket_id);
                    const uniqueTicketIds = [...new Set(ticketIds)];
                    if (ticketIds.length !== uniqueTicketIds.length) {
                        callback(new Error('不能添加重复的门票'));
                    } else {
                        callback();
                    }
                }
            },
            trigger: 'change'
        }
    ],
    hotel_room_types: [
        {
            validator: (rule, value, callback) => {
                if (!value || value.length === 0) {
                    callback(new Error('请至少添加一个关联酒店房型'));
                } else {
                    // 检查是否有重复的酒店房型组合
                    const keys = value.map(item => `${item.hotel_id}_${item.room_type_id}`);
                    const uniqueKeys = [...new Set(keys)];
                    if (keys.length !== uniqueKeys.length) {
                        callback(new Error('不能添加重复的酒店房型组合'));
                    } else {
                        callback();
                    }
                }
            },
            trigger: 'change'
        }
    ],
    sale_start_date: [
        { type: 'date', message: '请选择有效的日期', trigger: 'change' }
    ],
    sale_end_date: [
        { type: 'date', message: '请选择有效的日期', trigger: 'change' },
        {
            validator: (rule, value, callback) => {
                if (value && form.value.sale_start_date) {
                    if (new Date(value) < new Date(form.value.sale_start_date)) {
                        callback(new Error('销售结束日期不能早于销售开始日期'));
                    } else {
                        callback();
                    }
                } else {
                    callback();
                }
            },
            trigger: 'change'
        }
    ],
};

const fetchProducts = async () => {
    loading.value = true;
    try {
        const params = {
            page: currentPage.value,
            per_page: pageSize.value,
        };
        
        if (filterScenicSpotId.value) {
            params.scenic_spot_id = filterScenicSpotId.value;
        }
        
        if (filterStatus.value !== null) {
            params.status = filterStatus.value;
        }
        
        if (searchKeyword.value) {
            params.search = searchKeyword.value;
        }
        
        const response = await axios.get('/pkg-products', { params });
        products.value = response.data.data || [];
        total.value = response.data.total || 0;
    } catch (error) {
        ElMessage.error('获取打包产品列表失败');
        console.error(error);
    } finally {
        loading.value = false;
    }
};

const fetchScenicSpots = async () => {
    try {
        if (authStore.user?.role !== 'admin') {
            scenicSpots.value = authStore.user?.scenic_spots || [];
        } else {
            const response = await axios.get('/scenic-spots');
            scenicSpots.value = response.data.data || [];
        }
    } catch (error) {
        console.error('获取景区列表失败', error);
        if (authStore.user?.scenic_spots) {
            scenicSpots.value = authStore.user.scenic_spots;
        }
    }
};

const handleSearch = () => {
    currentPage.value = 1;
    fetchProducts();
};

const handleFilter = () => {
    currentPage.value = 1;
    fetchProducts();
};

const handleCreate = () => {
    editingId.value = null;
    resetForm();
    dialogVisible.value = true;
};

const handleViewDetail = (row) => {
    router.push(`/pkg-products/${row.id}/detail`);
};

const handlePriceManagement = (row) => {
    router.push(`/pkg-products/${row.id}/price-management`);
};

const handleEdit = async (row) => {
    editingId.value = row.id;
    try {
        const response = await axios.get(`/pkg-products/${row.id}`);
        const product = response.data.data;
        
        form.value = {
            scenic_spot_id: product.scenic_spot_id,
            product_name: product.product_name,
            stay_days: product.stay_days || 1,
            description: product.description || '',
            status: product.status,
            sale_start_date: product.sale_start_date || null,
            sale_end_date: product.sale_end_date || null,
            bundle_items: (product.bundle_items || []).map(item => ({
                ticket_id: item.ticket_id,
                quantity: item.quantity || 1,
            })),
            hotel_room_types: (product.hotel_room_types || []).map(hrt => ({
                hotel_id: hrt.hotel_id,
                room_type_id: hrt.room_type_id,
            })),
        };
        
        // 加载相关的数据
        if (form.value.scenic_spot_id) {
            await fetchTickets(form.value.scenic_spot_id);
            await fetchHotels(form.value.scenic_spot_id);
            await fetchRoomTypes();
        }
        
        dialogVisible.value = true;
    } catch (error) {
        ElMessage.error('获取产品信息失败');
        console.error(error);
    }
};

const handleDelete = async (row) => {
    try {
        await ElMessageBox.confirm(
            `确定要删除打包产品"${row.product_name}"吗？删除后无法恢复！`,
            '提示',
            {
                type: 'warning',
                confirmButtonText: '确定删除',
                cancelButtonText: '取消'
            }
        );
        
        await axios.delete(`/pkg-products/${row.id}`);
        ElMessage.success('删除成功');
        fetchProducts();
    } catch (error) {
        if (error !== 'cancel') {
            const message = error.response?.data?.message || '删除失败';
            ElMessage.error(message);
        }
    }
};

const formatDate = (date) => {
    if (!date) return '';
    const d = new Date(date);
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    const hours = String(d.getHours()).padStart(2, '0');
    const minutes = String(d.getMinutes()).padStart(2, '0');
    return `${year}-${month}-${day} ${hours}:${minutes}`;
};

// 门票管理
const addBundleItem = () => {
    form.value.bundle_items.push({
        ticket_id: null,
        quantity: 1,
    });
};

const removeBundleItem = (index) => {
    form.value.bundle_items.splice(index, 1);
    validateBundleItems();
};

const validateBundleItems = () => {
    formRef.value?.validateField('bundle_items');
};

// 酒店房型管理
const addHotelRoomType = () => {
    form.value.hotel_room_types.push({
        hotel_id: null,
        room_type_id: null,
    });
};

const removeHotelRoomType = (index) => {
    form.value.hotel_room_types.splice(index, 1);
    validateHotelRoomTypes();
};

const handleHotelChange = (index) => {
    // 清空该行的房型选择
    form.value.hotel_room_types[index].room_type_id = null;
    validateHotelRoomTypes();
};

const validateHotelRoomTypes = () => {
    formRef.value?.validateField('hotel_room_types');
};

const getRoomTypesByHotel = (hotelId) => {
    if (!hotelId) return [];
    return allRoomTypes.value.filter(rt => rt.hotel_id === hotelId && rt.is_active);
};

// 景区变化时加载相关数据
const handleScenicSpotChange = async (scenicSpotId) => {
    if (scenicSpotId) {
        await fetchTickets(scenicSpotId);
        await fetchHotels(scenicSpotId);
        await fetchRoomTypes();
    } else {
        availableTickets.value = [];
        availableHotels.value = [];
        allRoomTypes.value = [];
    }
};

// 获取门票列表
const fetchTickets = async (scenicSpotId) => {
    try {
        const response = await axios.get('/tickets', {
            params: {
                scenic_spot_id: scenicSpotId,
                is_active: true,
                per_page: 1000,
            },
        });
        availableTickets.value = response.data.data || [];
    } catch (error) {
        console.error('获取门票列表失败', error);
        availableTickets.value = [];
    }
};

// 获取酒店列表
const fetchHotels = async (scenicSpotId) => {
    try {
        const response = await axios.get('/res-hotels', {
            params: {
                scenic_spot_id: scenicSpotId,
                is_active: true,
                per_page: 1000,
            },
        });
        availableHotels.value = response.data.data || [];
    } catch (error) {
        console.error('获取酒店列表失败', error);
        availableHotels.value = [];
    }
};

// 获取房型列表
const fetchRoomTypes = async () => {
    try {
        const response = await axios.get('/res-room-types', {
            params: {
                is_active: true,
                per_page: 1000,
            },
        });
        allRoomTypes.value = response.data.data || [];
    } catch (error) {
        console.error('获取房型列表失败', error);
        allRoomTypes.value = [];
    }
};

// 表单提交
const handleSubmit = async () => {
    try {
        await formRef.value.validate();
        
        submitting.value = true;
        
        const payload = {
            scenic_spot_id: form.value.scenic_spot_id,
            product_name: form.value.product_name,
            stay_days: form.value.stay_days,
            description: form.value.description || '',
            status: form.value.status,
            sale_start_date: form.value.sale_start_date || null,
            sale_end_date: form.value.sale_end_date || null,
            bundle_items: form.value.bundle_items.map(item => ({
                ticket_id: item.ticket_id,
                quantity: item.quantity || 1,
            })),
            hotel_room_types: form.value.hotel_room_types.map(hrt => ({
                hotel_id: hrt.hotel_id,
                room_type_id: hrt.room_type_id,
            })),
        };
        
        if (isEdit.value) {
            await axios.put(`/pkg-products/${editingId.value}`, payload);
            ElMessage.success('更新成功');
        } else {
            await axios.post('/pkg-products', payload);
            ElMessage.success('创建成功');
        }
        
        dialogVisible.value = false;
        fetchProducts();
    } catch (error) {
        if (error !== false) { // 验证失败会返回 false
            const message = error.response?.data?.message || (isEdit.value ? '更新失败' : '创建失败');
            ElMessage.error(message);
            console.error(error);
        }
    } finally {
        submitting.value = false;
    }
};

// 重置表单
const resetForm = () => {
    form.value = {
        scenic_spot_id: null,
        product_name: '',
        stay_days: 1,
        description: '',
        status: 1,
        sale_start_date: null,
        sale_end_date: null,
        bundle_items: [],
        hotel_room_types: [],
    };
    availableTickets.value = [];
    availableHotels.value = [];
    allRoomTypes.value = [];
    formRef.value?.clearValidate();
};

onMounted(() => {
    fetchProducts();
    fetchScenicSpots();
});
</script>

<style scoped>
h2 {
    margin-bottom: 20px;
}
</style>

