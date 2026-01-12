<template>
    <div>
        <h2>价格库存管理 - {{ hotelName }} - {{ roomTypeName }}</h2>
        <el-card>
            <div style="margin-bottom: 20px;">
                <el-button type="primary" @click="handleBatchUpdate">批量更新</el-button>
                <el-button @click="handleBack">返回酒店列表</el-button>
                <el-date-picker
                    v-model="dateRange"
                    type="daterange"
                    range-separator="至"
                    start-placeholder="开始日期"
                    end-placeholder="结束日期"
                    format="YYYY-MM-DD"
                    value-format="YYYY-MM-DD"
                    style="margin-left: 10px;"
                    @change="handleDateRangeChange"
                />
            </div>
            
            <el-table :data="stocks" v-loading="loading" border>
                <el-table-column prop="biz_date" label="日期" width="120">
                    <template #default="{ row }">
                        {{ formatDate(row.biz_date) }}
                    </template>
                </el-table-column>
                <el-table-column prop="cost_price" label="结算价(元)" width="120">
                    <template #default="{ row }">
                        {{ row.cost_price || '-' }}
                    </template>
                </el-table-column>
                <el-table-column prop="sale_price" label="销售价(元)" width="120">
                    <template #default="{ row }">
                        {{ row.sale_price || '-' }}
                    </template>
                </el-table-column>
                <el-table-column prop="stock_total" label="总库存" width="100" />
                <el-table-column prop="stock_sold" label="已售库存" width="100" />
                <el-table-column prop="stock_available" label="可用库存" width="100">
                    <template #default="{ row }">
                        {{ row.stock_available || (row.stock_total - row.stock_sold) }}
                    </template>
                </el-table-column>
                <el-table-column prop="version" label="版本号" width="100">
                    <template #default="{ row }">
                        {{ row.version || 0 }}
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="200" fixed="right">
                    <template #default="{ row }">
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
                @size-change="fetchStocks"
                @current-change="fetchStocks"
            />
        </el-card>

        <!-- 批量更新对话框 -->
        <el-dialog
            v-model="batchDialogVisible"
            title="批量更新价格库存"
            width="800px"
            @close="resetBatchForm"
        >
            <el-form
                ref="batchFormRef"
                :model="batchForm"
                :rules="batchRules"
                label-width="120px"
            >
                <el-form-item label="房型" prop="room_type_id">
                    <el-select
                        v-model="batchForm.room_type_id"
                        placeholder="请选择房型"
                        style="width: 100%"
                    >
                        <el-option
                            v-for="roomType in roomTypes"
                            :key="roomType.id"
                            :label="roomType.name"
                            :value="roomType.id"
                        />
                    </el-select>
                </el-form-item>
                <el-form-item label="日期范围" prop="dateRange">
                    <el-date-picker
                        v-model="batchForm.dateRange"
                        type="daterange"
                        range-separator="至"
                        start-placeholder="开始日期"
                        end-placeholder="结束日期"
                        format="YYYY-MM-DD"
                        value-format="YYYY-MM-DD"
                        style="width: 100%"
                    />
                </el-form-item>
                <el-form-item label="结算价(元)" prop="cost_price">
                    <el-input-number 
                        v-model="batchForm.cost_price" 
                        :min="0" 
                        :precision="2"
                        style="width: 100%" 
                    />
                </el-form-item>
                <el-form-item label="销售价(元)" prop="sale_price">
                    <el-input-number 
                        v-model="batchForm.sale_price" 
                        :min="0" 
                        :precision="2"
                        style="width: 100%" 
                    />
                </el-form-item>
                <el-form-item label="总库存" prop="stock_total">
                    <el-input-number 
                        v-model="batchForm.stock_total" 
                        :min="0" 
                        style="width: 100%" 
                    />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="batchDialogVisible = false">取消</el-button>
                <el-button type="primary" @click="handleSubmitBatch" :loading="batchSubmitting">确定</el-button>
            </template>
        </el-dialog>

        <!-- 编辑单日价格库存对话框 -->
        <el-dialog
            v-model="editDialogVisible"
            title="编辑价格库存"
            width="600px"
            @close="resetEditForm"
        >
            <el-form
                ref="editFormRef"
                :model="editForm"
                :rules="editRules"
                label-width="120px"
            >
                <el-form-item label="日期">
                    <el-date-picker
                        v-model="editForm.biz_date"
                        type="date"
                        placeholder="选择日期"
                        format="YYYY-MM-DD"
                        value-format="YYYY-MM-DD"
                        style="width: 100%"
                        disabled
                    />
                </el-form-item>
                <el-form-item label="结算价(元)" prop="cost_price">
                    <el-input-number 
                        v-model="editForm.cost_price" 
                        :min="0" 
                        :precision="2"
                        style="width: 100%" 
                    />
                </el-form-item>
                <el-form-item label="销售价(元)" prop="sale_price">
                    <el-input-number 
                        v-model="editForm.sale_price" 
                        :min="0" 
                        :precision="2"
                        style="width: 100%" 
                    />
                </el-form-item>
                <el-form-item label="总库存" prop="stock_total">
                    <el-input-number 
                        v-model="editForm.stock_total" 
                        :min="0" 
                        style="width: 100%" 
                    />
                </el-form-item>
                <el-form-item label="已售库存" prop="stock_sold">
                    <el-input-number 
                        v-model="editForm.stock_sold" 
                        :min="0" 
                        style="width: 100%" 
                    />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="editDialogVisible = false">取消</el-button>
                <el-button type="primary" @click="handleSubmitEdit" :loading="editSubmitting">确定</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup>
import { ref, reactive, onMounted, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ElMessage, ElMessageBox } from 'element-plus';
import { resHotelDailyStocksApi, resHotelsApi, resRoomTypesApi } from '../../api/systemPkg';

const route = useRoute();
const router = useRouter();

const hotelId = computed(() => parseInt(route.params.id));
const hotelName = ref('');
const roomTypeName = ref('');
const roomTypes = ref([]);

// 数据
const stocks = ref([]);
const loading = ref(false);
const currentPage = ref(1);
const pageSize = ref(15);
const total = ref(0);
const dateRange = ref(null);

// 批量更新对话框
const batchDialogVisible = ref(false);
const batchSubmitting = ref(false);
const batchFormRef = ref(null);
const batchForm = reactive({
    room_type_id: null,
    dateRange: null,
    cost_price: 0,
    sale_price: 0,
    stock_total: 0,
});

const batchRules = {
    room_type_id: [{ required: true, message: '请选择房型', trigger: 'change' }],
    dateRange: [{ required: true, message: '请选择日期范围', trigger: 'change' }],
    cost_price: [{ required: true, message: '请输入结算价', trigger: 'blur' }],
    sale_price: [{ required: true, message: '请输入销售价', trigger: 'blur' }],
    stock_total: [{ required: true, message: '请输入总库存', trigger: 'blur' }],
};

// 编辑对话框
const editDialogVisible = ref(false);
const editSubmitting = ref(false);
const editFormRef = ref(null);
const editingId = ref(null);
const editForm = reactive({
    biz_date: null,
    cost_price: 0,
    sale_price: 0,
    stock_total: 0,
    stock_sold: 0,
});

const editRules = {
    cost_price: [{ required: true, message: '请输入结算价', trigger: 'blur' }],
    sale_price: [{ required: true, message: '请输入销售价', trigger: 'blur' }],
    stock_total: [{ required: true, message: '请输入总库存', trigger: 'blur' }],
    stock_sold: [{ required: true, message: '请输入已售库存', trigger: 'blur' }],
};

// 获取酒店信息
const fetchHotel = async () => {
    try {
        const response = await resHotelsApi.get(hotelId.value);
        const hotel = response.data.data;
        hotelName.value = hotel.name;
        
        // 验证是否为自控库存
        if (hotel.software_provider_id) {
            ElMessage.warning('该酒店为第三方库存，不能在此管理价格库存');
            router.push('/res-hotels');
            return;
        }
        
        // 获取房型列表
        const roomTypesResponse = await resRoomTypesApi.list({ hotel_id: hotelId.value });
        roomTypes.value = roomTypesResponse.data.data || [];
        
        // 如果只有一个房型，自动选择
        if (roomTypes.value.length === 1) {
            batchForm.room_type_id = roomTypes.value[0].id;
            roomTypeName.value = roomTypes.value[0].name;
        }
    } catch (error) {
        console.error('获取酒店信息失败:', error);
        ElMessage.error('获取酒店信息失败');
    }
};

// 获取价格库存列表
const fetchStocks = async () => {
    loading.value = true;
    try {
        const params = {
            page: currentPage.value,
            per_page: pageSize.value,
        };
        
        // 如果选择了房型，添加房型筛选
        if (batchForm.room_type_id) {
            params.room_type_id = batchForm.room_type_id;
        }
        
        // 如果选择了日期范围，添加日期筛选
        if (dateRange.value && dateRange.value.length === 2) {
            params.date_from = dateRange.value[0];
            params.date_to = dateRange.value[1];
        } else {
            // 默认显示未来60天
            const today = new Date();
            const future60Days = new Date(today);
            future60Days.setDate(today.getDate() + 60);
            params.date_from = today.toISOString().split('T')[0];
            params.date_to = future60Days.toISOString().split('T')[0];
        }
        
        const response = await resHotelDailyStocksApi.list(params);
        stocks.value = response.data.data || [];
        total.value = response.data.total || 0;
    } catch (error) {
        console.error('获取价格库存列表失败:', error);
        ElMessage.error('获取价格库存列表失败');
    } finally {
        loading.value = false;
    }
};

// 批量更新
const handleBatchUpdate = () => {
    if (roomTypes.value.length === 0) {
        ElMessage.warning('该酒店没有房型，请先创建房型');
        return;
    }
    resetBatchForm();
    batchDialogVisible.value = true;
};

// 提交批量更新
const handleSubmitBatch = async () => {
    if (!batchFormRef.value) return;
    
    await batchFormRef.value.validate(async (valid) => {
        if (!valid) return;
        
        // 验证销售价 >= 结算价
        if (batchForm.sale_price < batchForm.cost_price) {
            ElMessage.warning('销售价不能小于结算价');
            return;
        }
        
        batchSubmitting.value = true;
        try {
            // 生成日期数组
            const startDate = new Date(batchForm.dateRange[0]);
            const endDate = new Date(batchForm.dateRange[1]);
            const stocks = [];
            
            for (let d = new Date(startDate); d <= endDate; d.setDate(d.getDate() + 1)) {
                stocks.push({
                    date: d.toISOString().split('T')[0],
                    cost_price: batchForm.cost_price,
                    sale_price: batchForm.sale_price,
                    stock_total: batchForm.stock_total,
                });
            }
            
            await resHotelDailyStocksApi.batchStore({
                room_type_id: batchForm.room_type_id,
                stocks: stocks,
            });
            
            ElMessage.success('批量更新成功');
            batchDialogVisible.value = false;
            fetchStocks();
        } catch (error) {
            console.error('批量更新失败:', error);
            ElMessage.error(error.response?.data?.message || '批量更新失败');
        } finally {
            batchSubmitting.value = false;
        }
    });
};

// 编辑单日价格库存
const handleEdit = (row) => {
    editingId.value = row.id;
    Object.assign(editForm, {
        biz_date: row.biz_date,
        cost_price: parseFloat(row.cost_price) || 0,
        sale_price: parseFloat(row.sale_price) || 0,
        stock_total: row.stock_total || 0,
        stock_sold: row.stock_sold || 0,
    });
    editDialogVisible.value = true;
};

// 提交编辑
const handleSubmitEdit = async () => {
    if (!editFormRef.value) return;
    
    await editFormRef.value.validate(async (valid) => {
        if (!valid) return;
        
        // 验证销售价 >= 结算价
        if (editForm.sale_price < editForm.cost_price) {
            ElMessage.warning('销售价不能小于结算价');
            return;
        }
        
        // 验证已售库存 <= 总库存
        if (editForm.stock_sold > editForm.stock_total) {
            ElMessage.warning('已售库存不能超过总库存');
            return;
        }
        
        editSubmitting.value = true;
        try {
            await resHotelDailyStocksApi.update(editingId.value, {
                cost_price: editForm.cost_price,
                sale_price: editForm.sale_price,
                stock_total: editForm.stock_total,
                stock_sold: editForm.stock_sold,
            });
            
            ElMessage.success('更新成功');
            editDialogVisible.value = false;
            fetchStocks();
        } catch (error) {
            console.error('更新失败:', error);
            ElMessage.error(error.response?.data?.message || '更新失败');
        } finally {
            editSubmitting.value = false;
        }
    });
};

// 删除价格库存
const handleDelete = async (row) => {
    try {
        await ElMessageBox.confirm('确定要删除该日期的价格库存吗？', '提示', {
            confirmButtonText: '确定',
            cancelButtonText: '取消',
            type: 'warning',
        });
        
        await resHotelDailyStocksApi.delete(row.id);
        ElMessage.success('删除成功');
        fetchStocks();
    } catch (error) {
        if (error !== 'cancel') {
            console.error('删除失败:', error);
            ElMessage.error(error.response?.data?.message || '删除失败');
        }
    }
};

// 返回
const handleBack = () => {
    router.push('/res-hotels');
};

// 日期范围变化
const handleDateRangeChange = () => {
    currentPage.value = 1;
    fetchStocks();
};

// 重置批量表单
const resetBatchForm = () => {
    Object.assign(batchForm, {
        room_type_id: roomTypes.value.length === 1 ? roomTypes.value[0].id : null,
        dateRange: null,
        cost_price: 0,
        sale_price: 0,
        stock_total: 0,
    });
    if (batchFormRef.value) {
        batchFormRef.value.clearValidate();
    }
};

// 重置编辑表单
const resetEditForm = () => {
    Object.assign(editForm, {
        biz_date: null,
        cost_price: 0,
        sale_price: 0,
        stock_total: 0,
        stock_sold: 0,
    });
    editingId.value = null;
    if (editFormRef.value) {
        editFormRef.value.clearValidate();
    }
};

// 格式化日期
const formatDate = (date) => {
    if (!date) return '-';
    return new Date(date).toISOString().split('T')[0];
};

// 初始化
onMounted(() => {
    fetchHotel();
    fetchStocks();
});
</script>

<style scoped>
</style>


