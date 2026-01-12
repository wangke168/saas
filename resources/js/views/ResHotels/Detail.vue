<template>
    <div>
        <el-page-header @back="goBack" title="返回打包酒店列表">
            <template #content>
                <span>打包酒店详情</span>
            </template>
        </el-page-header>

        <el-card v-loading="loading" style="margin-top: 20px;">
            <div v-if="hotel">
                <!-- 酒店基本信息 -->
                <el-descriptions title="酒店基本信息" :column="2" border>
                    <el-descriptions-item label="酒店名称">{{ hotel.name }}</el-descriptions-item>
                    <el-descriptions-item label="酒店编码">{{ hotel.code }}</el-descriptions-item>
                    <el-descriptions-item label="所属景区">{{ hotel.scenic_spot?.name || '-' }}</el-descriptions-item>
                    <el-descriptions-item label="软件服务商">{{ hotel.software_provider?.name || '-' }}</el-descriptions-item>
                    <el-descriptions-item label="外部酒店编号">{{ hotel.external_hotel_id || '-' }}</el-descriptions-item>
                    <el-descriptions-item label="状态">
                        <el-tag :type="hotel.is_active ? 'success' : 'danger'">
                            {{ hotel.is_active ? '启用' : '禁用' }}
                        </el-tag>
                    </el-descriptions-item>
                    <el-descriptions-item label="地址">{{ hotel.address || '-' }}</el-descriptions-item>
                    <el-descriptions-item label="联系电话">{{ hotel.contact_phone || '-' }}</el-descriptions-item>
                    <el-descriptions-item label="创建时间">{{ formatDate(hotel.created_at) }}</el-descriptions-item>
                    <el-descriptions-item label="酒店描述" :span="2">
                        {{ hotel.description || '-' }}
                    </el-descriptions-item>
                </el-descriptions>

                <!-- 房型管理区域 -->
                <el-divider content-position="left">房型管理</el-divider>
                <div style="margin-bottom: 20px;">
                    <el-button type="primary" @click="handleCreateRoomType">添加房型</el-button>
                </div>

                <el-table :data="roomTypes" v-loading="roomTypeLoading" border>
                    <el-table-column prop="name" label="房型名称" width="150" />
                    <el-table-column prop="code" label="房型编码" width="150" />
                    <el-table-column prop="external_room_id" label="外部房型ID" width="150">
                        <template #default="{ row }">
                            {{ row.external_room_id || '-' }}
                        </template>
                    </el-table-column>
                    <el-table-column prop="max_occupancy" label="最大入住人数" width="120" />
                    <el-table-column prop="bed_type" label="床型" width="100" />
                    <el-table-column prop="room_area" label="房间面积（㎡）" width="120">
                        <template #default="{ row }">
                            {{ row.room_area || '-' }}
                        </template>
                    </el-table-column>
                    <el-table-column prop="description" label="描述" show-overflow-tooltip />
                    <el-table-column prop="is_active" label="状态" width="100">
                        <template #default="{ row }">
                            <el-tag :type="row.is_active ? 'success' : 'danger'">
                                {{ row.is_active ? '启用' : '禁用' }}
                            </el-tag>
                        </template>
                    </el-table-column>
                    <el-table-column label="操作" width="200" fixed="right">
                        <template #default="{ row }">
                            <el-button size="small" type="primary" @click="handleManageStock(row)">管理价库</el-button>
                            <el-button size="small" @click="handleEditRoomType(row)">编辑</el-button>
                            <el-button size="small" type="danger" @click="handleDeleteRoomType(row)">删除</el-button>
                        </template>
                    </el-table-column>
                </el-table>
            </div>
        </el-card>

        <!-- 创建/编辑房型对话框 -->
        <el-dialog
            v-model="roomTypeFormDialogVisible"
            :title="roomTypeFormTitle"
            width="600px"
            @close="resetRoomTypeForm"
        >
            <el-form
                ref="roomTypeFormRef"
                :model="roomTypeForm"
                :rules="roomTypeRules"
                label-width="120px"
            >
                <el-form-item label="房型名称" prop="name">
                    <el-input v-model="roomTypeForm.name" placeholder="请输入房型名称" />
                </el-form-item>
                <el-form-item label="房型编码" prop="code">
                    <el-input v-model="roomTypeForm.code" placeholder="系统自动生成" :disabled="true" />
                    <div style="font-size: 12px; color: #909399; margin-top: 5px;">
                        房型编码由系统自动生成，格式：R + 5位数字（如 R00001）
                    </div>
                </el-form-item>
                <el-form-item label="外部房型ID" prop="external_room_id">
                    <el-input v-model="roomTypeForm.external_room_id" placeholder="用于API对接的第三方房型ID（可选）" />
                </el-form-item>
                <el-form-item label="最大入住人数" prop="max_occupancy">
                    <el-input-number v-model="roomTypeForm.max_occupancy" :min="1" :max="10" />
                </el-form-item>
                <el-form-item label="床型" prop="bed_type">
                    <el-input v-model="roomTypeForm.bed_type" placeholder="如：双床、大床等" />
                </el-form-item>
                <el-form-item label="房间面积（㎡）" prop="room_area">
                    <el-input-number v-model="roomTypeForm.room_area" :min="0" :precision="2" />
                </el-form-item>
                <el-form-item label="描述" prop="description">
                    <el-input
                        v-model="roomTypeForm.description"
                        type="textarea"
                        :rows="3"
                        placeholder="请输入房型描述"
                    />
                </el-form-item>
                <el-form-item label="状态" prop="is_active">
                    <el-switch v-model="roomTypeForm.is_active" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="roomTypeFormDialogVisible = false">取消</el-button>
                <el-button type="primary" @click="handleSubmitRoomType" :loading="roomTypeSubmitting">确定</el-button>
            </template>
        </el-dialog>

        <!-- 价库管理对话框 -->
        <el-dialog
            v-model="stockDialogVisible"
            title="价库管理"
            width="1200px"
            @close="resetStockDialog"
        >
            <div style="margin-bottom: 20px;">
                <span style="margin-right: 20px;">房型：{{ currentRoomType?.name }}</span>
                <el-button type="primary" @click="handleBatchAddStock">批量设置</el-button>
                <el-button type="primary" @click="handleAddStock">添加价库</el-button>
                <el-date-picker
                    v-model="stockDateRange"
                    type="daterange"
                    range-separator="至"
                    start-placeholder="开始日期"
                    end-placeholder="结束日期"
                    style="margin-left: 20px;"
                    value-format="YYYY-MM-DD"
                    @change="handleStockDateRangeChange"
                />
            </div>

            <el-table :data="stocks" v-loading="stockLoading" border>
                <el-table-column prop="biz_date" label="日期" width="120">
                    <template #default="{ row }">
                        {{ formatDateOnly(row.biz_date) }}
                    </template>
                </el-table-column>
                <el-table-column prop="cost_price" label="成本价" width="100">
                    <template #default="{ row }">
                        ¥{{ row.cost_price }}
                    </template>
                </el-table-column>
                <el-table-column prop="sale_price" label="售价" width="100">
                    <template #default="{ row }">
                        ¥{{ row.sale_price }}
                    </template>
                </el-table-column>
                <el-table-column prop="stock_total" label="总库存" width="100" />
                <el-table-column prop="stock_sold" label="已售" width="100" />
                <el-table-column prop="stock_available" label="可用库存" width="100" />
                <el-table-column prop="source" label="来源" width="100">
                    <template #default="{ row }">
                        <el-tag :type="row.source === 'manual' ? 'primary' : 'success'">
                            {{ row.source === 'manual' ? '人工维护' : '接口推送' }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="is_closed" label="状态" width="100">
                    <template #default="{ row }">
                        <el-tag :type="row.is_closed ? 'danger' : 'success'">
                            {{ row.is_closed ? '已关闭' : '正常' }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="350" fixed="right">
                    <template #default="{ row }">
                        <el-button
                            size="small"
                            @click="handleEditStock(row)"
                            :disabled="row.source === 'api'"
                        >
                            编辑
                        </el-button>
                        <el-button
                            size="small"
                            :type="row.is_closed ? 'success' : 'warning'"
                            @click="handleToggleStockStatus(row)"
                            :disabled="row.source === 'api'"
                        >
                            {{ row.is_closed ? '开启' : '关房' }}
                        </el-button>
                        <el-button
                            size="small"
                            type="primary"
                            @click="handlePushStockToOta(row)"
                            :loading="pushingStocks[row.id]"
                        >
                            推送到OTA
                        </el-button>
                        <el-button
                            size="small"
                            type="danger"
                            @click="handleDeleteStock(row)"
                            :disabled="row.source === 'api'"
                        >
                            删除
                        </el-button>
                    </template>
                </el-table-column>
            </el-table>

            <el-pagination
                v-if="stockPagination.total > 0"
                v-model:current-page="stockPagination.current_page"
                v-model:page-size="stockPagination.per_page"
                :page-sizes="[10, 20, 50, 100]"
                :total="stockPagination.total"
                layout="total, sizes, prev, pager, next, jumper"
                style="margin-top: 20px; justify-content: flex-end;"
                @size-change="handleStockSizeChange"
                @current-change="handleStockPageChange"
            />

            <!-- 批量设置价库对话框 -->
            <el-dialog
                v-model="batchStockDialogVisible"
                title="批量设置价库"
                width="600px"
                append-to-body
                @close="resetBatchStockForm"
            >
                <el-form
                    ref="batchStockFormRef"
                    :model="batchStockForm"
                    :rules="batchStockRules"
                    label-width="120px"
                >
                    <el-form-item label="日期范围" prop="dateRange">
                        <el-date-picker
                            v-model="batchStockForm.dateRange"
                            type="daterange"
                            range-separator="至"
                            start-placeholder="开始日期"
                            end-placeholder="结束日期"
                            style="width: 100%"
                            value-format="YYYY-MM-DD"
                        />
                    </el-form-item>
                    <el-form-item label="成本价" prop="cost_price">
                        <el-input-number v-model="batchStockForm.cost_price" :min="0" :precision="2" style="width: 100%" />
                    </el-form-item>
                    <el-form-item label="售价" prop="sale_price">
                        <el-input-number v-model="batchStockForm.sale_price" :min="0" :precision="2" style="width: 100%" />
                    </el-form-item>
                    <el-form-item label="总库存" prop="stock_total">
                        <el-input-number 
                            v-model="batchStockForm.stock_total" 
                            :min="0" 
                            style="width: 100%"
                            @change="handleBatchStockTotalChange"
                        />
                    </el-form-item>
                    <el-form-item label="已售库存" prop="stock_sold">
                        <el-input-number 
                            v-model="batchStockForm.stock_sold" 
                            :min="0" 
                            style="width: 100%"
                            @change="handleBatchStockSoldChange"
                        />
                    </el-form-item>
                    <el-form-item label="可用库存" prop="stock_available">
                        <el-input-number 
                            v-model="batchStockForm.stock_available" 
                            :min="0" 
                            style="width: 100%"
                            @change="handleBatchStockAvailableChange"
                        />
                        <div style="font-size: 12px; color: #909399; margin-top: 5px;">
                            可用库存可人工编辑，推送到OTA时使用此字段。如果未设置，系统将自动计算（总库存 - 已售库存）
                        </div>
                    </el-form-item>
                </el-form>
                <template #footer>
                    <el-button @click="batchStockDialogVisible = false">取消</el-button>
                    <el-button type="primary" @click="handleSubmitBatchStock" :loading="batchStockSubmitting">确定</el-button>
                </template>
            </el-dialog>

            <!-- 添加/编辑价库对话框 -->
            <el-dialog
                v-model="stockFormDialogVisible"
                :title="stockFormTitle"
                width="600px"
                append-to-body
                @close="resetStockForm"
            >
                <el-form
                    ref="stockFormRef"
                    :model="stockForm"
                    :rules="stockRules"
                    label-width="120px"
                >
                    <el-form-item label="日期" prop="biz_date">
                        <el-date-picker
                            v-model="stockForm.biz_date"
                            type="date"
                            placeholder="选择日期"
                            style="width: 100%"
                            value-format="YYYY-MM-DD"
                            :disabled="isEditStock"
                        />
                    </el-form-item>
                    <el-form-item label="成本价" prop="cost_price">
                        <el-input-number v-model="stockForm.cost_price" :min="0" :precision="2" style="width: 100%" />
                    </el-form-item>
                    <el-form-item label="售价" prop="sale_price">
                        <el-input-number v-model="stockForm.sale_price" :min="0" :precision="2" style="width: 100%" />
                    </el-form-item>
                    <el-form-item label="总库存" prop="stock_total">
                        <el-input-number 
                            v-model="stockForm.stock_total" 
                            :min="0" 
                            style="width: 100%"
                            @change="handleStockTotalChange"
                        />
                    </el-form-item>
                    <el-form-item label="已售库存" prop="stock_sold">
                        <el-input-number 
                            v-model="stockForm.stock_sold" 
                            :min="0" 
                            style="width: 100%"
                            @change="handleStockSoldChange"
                        />
                    </el-form-item>
                    <el-form-item label="可用库存" prop="stock_available">
                        <el-input-number 
                            v-model="stockForm.stock_available" 
                            :min="0" 
                            style="width: 100%"
                            @change="handleStockAvailableChange"
                            :disabled="false"
                        />
                        <div style="font-size: 12px; color: #909399; margin-top: 5px;">
                            可用库存可人工编辑，推送到OTA时使用此字段。如果未设置，系统将自动计算（总库存 - 已售库存）
                        </div>
                    </el-form-item>
                    <el-form-item label="来源" v-if="isEditStock">
                        <el-tag :type="stockForm.source === 'manual' ? 'primary' : 'success'">
                            {{ stockForm.source === 'manual' ? '人工维护' : '接口推送' }}
                        </el-tag>
                        <span style="margin-left: 10px; color: #909399; font-size: 12px;">
                            {{ stockForm.source === 'manual' ? '（可编辑）' : '（接口推送，不可编辑）' }}
                        </span>
                    </el-form-item>
                </el-form>
                <template #footer>
                    <el-button @click="stockFormDialogVisible = false">取消</el-button>
                    <el-button
                        type="primary"
                        @click="handleSubmitStock"
                        :loading="stockSubmitting"
                        :disabled="stockForm.source === 'api'"
                    >
                        确定
                    </el-button>
                </template>
            </el-dialog>
        </el-dialog>
    </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import axios from '../../utils/axios';
import { ElMessage, ElMessageBox } from 'element-plus';

const route = useRoute();
const router = useRouter();

const hotel = ref(null);
const loading = ref(false);
const roomTypes = ref([]);
const roomTypeLoading = ref(false);
const roomTypeFormDialogVisible = ref(false);
const roomTypeFormRef = ref(null);
const roomTypeSubmitting = ref(false);
const editingRoomTypeId = ref(null);

// 价库管理相关
const stockDialogVisible = ref(false);
const stocks = ref([]);
const stockLoading = ref(false);
const currentRoomType = ref(null);
const stockDateRange = ref(null);
const stockPagination = ref({
    current_page: 1,
    per_page: 15,
    total: 0,
    last_page: 1,
});
const batchStockDialogVisible = ref(false);
const batchStockFormRef = ref(null);
const batchStockSubmitting = ref(false);
const stockFormDialogVisible = ref(false);
const stockFormRef = ref(null);
const stockSubmitting = ref(false);
const editingStockId = ref(null);
const pushingStocks = ref({});

const isEditRoomType = computed(() => editingRoomTypeId.value !== null);
const roomTypeFormTitle = computed(() => isEditRoomType.value ? '编辑房型' : '添加房型');

const isEditStock = computed(() => editingStockId.value !== null);
const stockFormTitle = computed(() => isEditStock.value ? '编辑价库' : '添加价库');

const roomTypeForm = ref({
    name: '',
    code: '',
    external_room_id: '',
    max_occupancy: 2,
    bed_type: '',
    room_area: null,
    description: '',
    is_active: true,
});

const batchStockForm = ref({
    dateRange: null,
    cost_price: 0,
    sale_price: 0,
    stock_total: 0,
    stock_sold: 0,
    stock_available: 0,
});

const stockForm = ref({
    biz_date: null,
    cost_price: 0,
    sale_price: 0,
    stock_total: 0,
    stock_sold: 0,
    stock_available: 0,
    source: 'manual',
});

// 标记用户是否手动设置过可用库存
const userSetStockAvailable = ref(false);

const roomTypeRules = {
    name: [
        { required: true, message: '请输入房型名称', trigger: 'blur' }
    ],
    max_occupancy: [
        { required: true, message: '请输入最大入住人数', trigger: 'blur' },
        { type: 'number', min: 1, message: '最大入住人数至少为1', trigger: 'blur' }
    ],
};

const batchStockRules = {
    dateRange: [
        { required: true, message: '请选择日期范围', trigger: 'change' }
    ],
    cost_price: [
        { required: true, message: '请输入成本价', trigger: 'blur' },
        { type: 'number', min: 0, message: '成本价不能小于0', trigger: 'blur' }
    ],
    sale_price: [
        { required: true, message: '请输入售价', trigger: 'blur' },
        { type: 'number', min: 0, message: '售价不能小于0', trigger: 'blur' }
    ],
    stock_total: [
        { required: true, message: '请输入总库存', trigger: 'blur' },
        { type: 'number', min: 0, message: '总库存不能小于0', trigger: 'blur' }
    ],
};

const stockRules = {
    biz_date: [
        { required: true, message: '请选择日期', trigger: 'change' }
    ],
    cost_price: [
        { required: true, message: '请输入成本价', trigger: 'blur' },
        { type: 'number', min: 0, message: '成本价不能小于0', trigger: 'blur' }
    ],
    sale_price: [
        { required: true, message: '请输入售价', trigger: 'blur' },
        { type: 'number', min: 0, message: '售价不能小于0', trigger: 'blur' }
    ],
    stock_total: [
        { required: true, message: '请输入总库存', trigger: 'blur' },
        { type: 'number', min: 0, message: '总库存不能小于0', trigger: 'blur' }
    ],
};

const goBack = () => {
    router.push('/res-hotels');
};

const fetchHotel = async () => {
    loading.value = true;
    try {
        const response = await axios.get(`/res-hotels/${route.params.id}`);
        hotel.value = response.data.data;
        await fetchRoomTypes();
    } catch (error) {
        ElMessage.error('获取酒店详情失败');
        console.error(error);
        router.push('/res-hotels');
    } finally {
        loading.value = false;
    }
};

const fetchRoomTypes = async () => {
    roomTypeLoading.value = true;
    try {
        const response = await axios.get('/res-room-types', {
            params: { hotel_id: route.params.id }
        });
        roomTypes.value = response.data.data || [];
    } catch (error) {
        ElMessage.error('获取房型列表失败');
        console.error(error);
    } finally {
        roomTypeLoading.value = false;
    }
};

const handleCreateRoomType = () => {
    editingRoomTypeId.value = null;
    resetRoomTypeForm();
    roomTypeFormDialogVisible.value = true;
};

const handleEditRoomType = (row) => {
    editingRoomTypeId.value = row.id;
    roomTypeForm.value = {
        name: row.name,
        code: row.code,
        external_room_id: row.external_room_id || '',
        max_occupancy: row.max_occupancy || 2,
        bed_type: row.bed_type || '',
        room_area: row.room_area || null,
        description: row.description || '',
        is_active: row.is_active,
    };
    roomTypeFormDialogVisible.value = true;
};

const handleSubmitRoomType = async () => {
    if (!roomTypeFormRef.value) return;

    await roomTypeFormRef.value.validate(async (valid) => {
        if (valid) {
            roomTypeSubmitting.value = true;
            try {
                const data = {
                    ...roomTypeForm.value,
                    hotel_id: route.params.id,
                };

                if (isEditRoomType.value) {
                    await axios.put(`/res-room-types/${editingRoomTypeId.value}`, data);
                    ElMessage.success('房型更新成功');
                } else {
                    await axios.post('/res-room-types', data);
                    ElMessage.success('房型创建成功');
                }
                roomTypeFormDialogVisible.value = false;
                await fetchRoomTypes();
            } catch (error) {
                const message = error.response?.data?.message || '操作失败';
                ElMessage.error(message);
            } finally {
                roomTypeSubmitting.value = false;
            }
        }
    });
};

const handleDeleteRoomType = async (row) => {
    try {
        await ElMessageBox.confirm(
            `确定要删除房型"${row.name}"吗？删除后无法恢复！`,
            '提示',
            {
                type: 'warning',
                confirmButtonText: '确定删除',
                cancelButtonText: '取消'
            }
        );

        await axios.delete(`/res-room-types/${row.id}`);
        ElMessage.success('删除成功');
        await fetchRoomTypes();
    } catch (error) {
        if (error !== 'cancel') {
            ElMessage.error('删除失败');
            console.error(error);
        }
    }
};

const resetRoomTypeForm = () => {
    roomTypeForm.value = {
        name: '',
        code: '',
        external_room_id: '',
        max_occupancy: 2,
        bed_type: '',
        room_area: null,
        description: '',
        is_active: true,
    };
    roomTypeFormRef.value?.clearValidate();
};

// 价库管理
const handleManageStock = async (roomType) => {
    currentRoomType.value = roomType;
    stockDialogVisible.value = true;
    await fetchStocks(roomType.id);
};

const fetchStocks = async (roomTypeId) => {
    stockLoading.value = true;
    try {
        const params = {
            hotel_id: route.params.id,
            room_type_id: roomTypeId,
            page: stockPagination.value.current_page,
            per_page: stockPagination.value.per_page,
        };

        if (stockDateRange.value && stockDateRange.value.length === 2) {
            params.start_date = stockDateRange.value[0];
            params.end_date = stockDateRange.value[1];
        }

        const response = await axios.get('/res-hotel-daily-stocks', { params });
        stocks.value = response.data.data || [];
        // 更新分页信息
        if (response.data.current_page !== undefined) {
            stockPagination.value = {
                current_page: response.data.current_page,
                per_page: response.data.per_page,
                total: response.data.total,
                last_page: response.data.last_page,
            };
        }
    } catch (error) {
        ElMessage.error('获取价库列表失败');
        console.error(error);
    } finally {
        stockLoading.value = false;
    }
};

const handleStockDateRangeChange = () => {
    if (currentRoomType.value) {
        stockPagination.value.current_page = 1;
        fetchStocks(currentRoomType.value.id);
    }
};

const handleStockPageChange = (page) => {
    stockPagination.value.current_page = page;
    if (currentRoomType.value) {
        fetchStocks(currentRoomType.value.id);
    }
};

const handleStockSizeChange = (size) => {
    stockPagination.value.per_page = size;
    stockPagination.value.current_page = 1;
    if (currentRoomType.value) {
        fetchStocks(currentRoomType.value.id);
    }
};

const handleBatchAddStock = () => {
    resetBatchStockForm();
    batchStockDialogVisible.value = true;
};

const handleSubmitBatchStock = async () => {
    if (!batchStockFormRef.value) return;

    await batchStockFormRef.value.validate(async (valid) => {
        if (valid) {
            // 验证售价不能低于成本价
            if (batchStockForm.value.sale_price < batchStockForm.value.cost_price) {
                ElMessage.error('售价不能低于成本价');
                return;
            }

            // 验证已售库存不能超过总库存
            if (batchStockForm.value.stock_sold > batchStockForm.value.stock_total) {
                ElMessage.error('已售库存不能超过总库存');
                return;
            }

            // 验证可用库存不能超过总库存
            if (batchStockForm.value.stock_available > batchStockForm.value.stock_total) {
                ElMessage.error('可用库存不能超过总库存');
                return;
            }

            batchStockSubmitting.value = true;
            try {
                await axios.post('/res-hotel-daily-stocks/batch', {
                    hotel_id: route.params.id,
                    room_type_id: currentRoomType.value.id,
                    start_date: batchStockForm.value.dateRange[0],
                    end_date: batchStockForm.value.dateRange[1],
                    cost_price: batchStockForm.value.cost_price,
                    sale_price: batchStockForm.value.sale_price,
                    stock_total: batchStockForm.value.stock_total,
                    stock_sold: batchStockForm.value.stock_sold || 0,
                    stock_available: batchStockForm.value.stock_available,
                });

                ElMessage.success('批量设置成功');
                batchStockDialogVisible.value = false;
                stockPagination.value.current_page = 1;
                await fetchStocks(currentRoomType.value.id);
            } catch (error) {
                const message = error.response?.data?.message || '操作失败';
                ElMessage.error(message);
            } finally {
                batchStockSubmitting.value = false;
            }
        }
    });
};

const handleAddStock = () => {
    editingStockId.value = null;
    userSetStockAvailable.value = false;
    resetStockForm();
    stockFormDialogVisible.value = true;
};

const handleEditStock = (row) => {
    if (row.source === 'api') {
        ElMessage.warning('接口推送的价库数据不可编辑');
        return;
    }

    editingStockId.value = row.id;
    userSetStockAvailable.value = row.stock_available !== null && row.stock_available !== undefined;
    stockForm.value = {
        biz_date: row.biz_date,
        cost_price: row.cost_price,
        sale_price: row.sale_price,
        stock_total: row.stock_total,
        stock_sold: row.stock_sold,
        stock_available: row.stock_available ?? (row.stock_total - row.stock_sold),
        source: row.source,
    };
    stockFormDialogVisible.value = true;
};

const handleSubmitStock = async () => {
    if (!stockFormRef.value) return;

    await stockFormRef.value.validate(async (valid) => {
        if (valid) {
            // 验证售价不能低于成本价
            if (stockForm.value.sale_price < stockForm.value.cost_price) {
                ElMessage.error('售价不能低于成本价');
                return;
            }

            // 验证已售库存不能超过总库存
            if (stockForm.value.stock_sold > stockForm.value.stock_total) {
                ElMessage.error('已售库存不能超过总库存');
                return;
            }

            // 验证可用库存不能超过总库存
            if (stockForm.value.stock_available > stockForm.value.stock_total) {
                ElMessage.error('可用库存不能超过总库存');
                return;
            }

            stockSubmitting.value = true;
            try {
                const data = {
                    hotel_id: route.params.id,
                    room_type_id: currentRoomType.value.id,
                    ...stockForm.value,
                };

                if (isEditStock.value) {
                    await axios.put(`/res-hotel-daily-stocks/${editingStockId.value}`, data);
                    ElMessage.success('价库更新成功');
                } else {
                    await axios.post('/res-hotel-daily-stocks', data);
                    ElMessage.success('价库创建成功');
                    // 新建后跳转到最后一页
                    if (stockPagination.value.last_page > 0) {
                        stockPagination.value.current_page = stockPagination.value.last_page;
                    }
                }
                stockFormDialogVisible.value = false;
                await fetchStocks(currentRoomType.value.id);
            } catch (error) {
                const message = error.response?.data?.message || '操作失败';
                ElMessage.error(message);
            } finally {
                stockSubmitting.value = false;
            }
        }
    });
};

const handleToggleStockStatus = async (row) => {
    if (row.source === 'api') {
        ElMessage.warning('接口推送的价库数据不可关闭/开启');
        return;
    }

    try {
        const action = row.is_closed ? 'open' : 'close';
        await axios.post(`/res-hotel-daily-stocks/${row.id}/${action}`);
        ElMessage.success(row.is_closed ? '价库已开启' : '价库已关闭');
        await fetchStocks(currentRoomType.value.id);
    } catch (error) {
        const message = error.response?.data?.message || '操作失败';
        ElMessage.error(message);
        console.error(error);
    }
};

const handlePushStockToOta = async (row) => {
    try {
        pushingStocks.value[row.id] = true;

        const response = await axios.post(`/res-hotel-daily-stocks/${row.id}/push-to-ota`);

        if (response.data.success) {
            ElMessage.success(response.data.message || '推送任务已提交，正在后台处理中');
        } else {
            ElMessage.error(response.data.message || '推送失败');
        }
    } catch (error) {
        const message = error.response?.data?.message || '推送失败';
        ElMessage.error(message);
        console.error(error);
    } finally {
        pushingStocks.value[row.id] = false;
    }
};

const handleDeleteStock = async (row) => {
    if (row.source === 'api') {
        ElMessage.warning('接口推送的价库数据不可删除');
        return;
    }

    try {
        await ElMessageBox.confirm(
            `确定要删除日期 ${formatDateOnly(row.biz_date)} 的价库记录吗？`,
            '提示',
            {
                type: 'warning',
                confirmButtonText: '确定删除',
                cancelButtonText: '取消'
            }
        );

        await axios.delete(`/res-hotel-daily-stocks/${row.id}`);
        ElMessage.success('删除成功');
        // 如果当前页没有数据了，回到上一页
        if (stocks.value.length === 1 && stockPagination.value.current_page > 1) {
            stockPagination.value.current_page--;
        }
        await fetchStocks(currentRoomType.value.id);
    } catch (error) {
        if (error !== 'cancel') {
            ElMessage.error('删除失败');
            console.error(error);
        }
    }
};

// 字段联动函数：批量设置表单
const handleBatchStockTotalChange = () => {
    // 如果用户未手动设置可用库存，自动计算
    batchStockForm.value.stock_available = Math.max(0, batchStockForm.value.stock_total - batchStockForm.value.stock_sold);
};

const handleBatchStockSoldChange = () => {
    // 如果用户未手动设置可用库存，自动计算
    batchStockForm.value.stock_available = Math.max(0, batchStockForm.value.stock_total - batchStockForm.value.stock_sold);
};

const handleBatchStockAvailableChange = () => {
    // 用户手动设置可用库存，自动计算已售库存
    batchStockForm.value.stock_sold = Math.max(0, batchStockForm.value.stock_total - batchStockForm.value.stock_available);
};

// 字段联动函数：单个编辑表单
const handleStockTotalChange = () => {
    // 如果用户未手动设置可用库存，自动计算
    if (!userSetStockAvailable.value) {
        stockForm.value.stock_available = Math.max(0, stockForm.value.stock_total - stockForm.value.stock_sold);
    } else {
        // 如果用户已手动设置过，重新计算已售库存
        stockForm.value.stock_sold = Math.max(0, stockForm.value.stock_total - stockForm.value.stock_available);
    }
};

const handleStockSoldChange = () => {
    // 如果用户未手动设置可用库存，自动计算
    if (!userSetStockAvailable.value) {
        stockForm.value.stock_available = Math.max(0, stockForm.value.stock_total - stockForm.value.stock_sold);
    }
};

const handleStockAvailableChange = () => {
    // 用户手动设置可用库存，标记并自动计算已售库存
    userSetStockAvailable.value = true;
    stockForm.value.stock_sold = Math.max(0, stockForm.value.stock_total - stockForm.value.stock_available);
};

const resetBatchStockForm = () => {
    batchStockForm.value = {
        dateRange: null,
        cost_price: 0,
        sale_price: 0,
        stock_total: 0,
        stock_sold: 0,
        stock_available: 0,
    };
    batchStockFormRef.value?.clearValidate();
};

const resetStockForm = () => {
    stockForm.value = {
        biz_date: null,
        cost_price: 0,
        sale_price: 0,
        stock_total: 0,
        stock_sold: 0,
        stock_available: 0,
        source: 'manual',
    };
    userSetStockAvailable.value = false;
    stockFormRef.value?.clearValidate();
    editingStockId.value = null;
};

const resetStockDialog = () => {
    currentRoomType.value = null;
    stocks.value = [];
    stockDateRange.value = null;
    editingStockId.value = null;
    stockPagination.value = {
        current_page: 1,
        per_page: 15,
        total: 0,
        last_page: 1,
    };
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

const formatDateOnly = (date) => {
    if (!date) return '';
    const d = new Date(date);
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
};

onMounted(() => {
    fetchHotel();
});
</script>

<style scoped>
</style>

