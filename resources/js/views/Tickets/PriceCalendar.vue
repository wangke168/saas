<template>
    <div>
        <div style="margin-bottom: 20px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <el-button type="primary" @click="handleBatchSetPrice">批量设置价格</el-button>
            <el-date-picker
                v-model="dateRange"
                type="daterange"
                range-separator="至"
                start-placeholder="开始日期"
                end-placeholder="结束日期"
                format="YYYY-MM-DD"
                value-format="YYYY-MM-DD"
                style="width: 240px;"
                @change="handleDateRangeChange"
            />
            <el-button @click="resetFilter" size="small">重置筛选</el-button>
            <el-alert
                title="提示"
                type="info"
                description="如果某日期有特殊价格，将覆盖默认价格；删除特殊价格后，该日期将恢复使用默认价格"
                :closable="false"
                style="flex: 1;"
            />
        </div>

        <div v-loading="pricesLoading">
            <el-table :data="prices" border>
                <el-table-column prop="date" label="日期" width="150" />
                <el-table-column label="门市价（元）" width="150">
                    <template #default="{ row }">
                        <span :class="{ 'custom-price': row.is_custom }">
                            ¥{{ formatPrice(row.market_price) }}
                        </span>
                        <el-tag v-if="row.is_custom" size="small" type="warning" style="margin-left: 5px;">
                            特殊
                        </el-tag>
                        <el-tag v-else size="small" type="info" style="margin-left: 5px;">
                            默认
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="销售价（元）" width="150">
                    <template #default="{ row }">
                        <span :class="{ 'custom-price': row.is_custom }">
                            ¥{{ formatPrice(row.sale_price) }}
                        </span>
                    </template>
                </el-table-column>
                <el-table-column label="结算价（元）" width="150">
                    <template #default="{ row }">
                        <span :class="{ 'custom-price': row.is_custom }">
                            ¥{{ formatPrice(row.settlement_price) }}
                        </span>
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="200" fixed="right">
                    <template #default="{ row }">
                        <el-button size="small" @click="handleEditPrice(row)">编辑</el-button>
                        <el-button
                            v-if="row.is_custom"
                            size="small"
                            type="danger"
                            @click="handleDeletePrice(row)"
                        >
                            删除
                        </el-button>
                    </template>
                </el-table-column>
            </el-table>

            <el-empty v-if="prices.length === 0" description="暂无价格数据" />
        </div>

        <!-- 批量设置价格对话框 -->
        <el-dialog
            v-model="batchDialogVisible"
            title="批量设置价格"
            width="600px"
            @close="resetBatchForm"
        >
            <el-form
                ref="batchFormRef"
                :model="batchForm"
                :rules="batchFormRules"
                label-width="120px"
            >
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
                <el-form-item label="门市价（元）" prop="market_price">
                    <el-input-number
                        v-model="batchForm.market_price"
                        :min="0"
                        :precision="2"
                        :step="1"
                        style="width: 100%"
                    />
                </el-form-item>
                <el-form-item label="销售价（元）" prop="sale_price">
                    <el-input-number
                        v-model="batchForm.sale_price"
                        :min="0"
                        :precision="2"
                        :step="1"
                        style="width: 100%"
                    />
                    <div style="font-size: 12px; color: #909399; margin-top: 5px;">
                        销售价不能大于门市价
                    </div>
                </el-form-item>
                <el-form-item label="结算价（元）" prop="settlement_price">
                    <el-input-number
                        v-model="batchForm.settlement_price"
                        :min="0"
                        :precision="2"
                        :step="1"
                        style="width: 100%"
                    />
                    <div style="font-size: 12px; color: #909399; margin-top: 5px;">
                        结算价不能大于销售价
                    </div>
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="batchDialogVisible = false">取消</el-button>
                <el-button type="primary" @click="handleBatchSubmit" :loading="batchSubmitting">确定</el-button>
            </template>
        </el-dialog>

        <!-- 编辑单个价格对话框 -->
        <el-dialog
            v-model="editDialogVisible"
            title="编辑价格"
            width="500px"
            @close="resetEditForm"
        >
            <el-form
                ref="editFormRef"
                :model="editForm"
                :rules="editFormRules"
                label-width="120px"
            >
                <el-form-item label="日期">
                    <el-input :value="editForm.date" disabled />
                </el-form-item>
                <el-form-item label="门市价（元）" prop="market_price">
                    <el-input-number
                        v-model="editForm.market_price"
                        :min="0"
                        :precision="2"
                        :step="1"
                        style="width: 100%"
                    />
                </el-form-item>
                <el-form-item label="销售价（元）" prop="sale_price">
                    <el-input-number
                        v-model="editForm.sale_price"
                        :min="0"
                        :precision="2"
                        :step="1"
                        style="width: 100%"
                    />
                </el-form-item>
                <el-form-item label="结算价（元）" prop="settlement_price">
                    <el-input-number
                        v-model="editForm.settlement_price"
                        :min="0"
                        :precision="2"
                        :step="1"
                        style="width: 100%"
                    />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="editDialogVisible = false">取消</el-button>
                <el-button type="primary" @click="handleEditSubmit" :loading="editSubmitting">确定</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup>
import { ref, onMounted, watch, computed } from 'vue';
import axios from '../../utils/axios';
import { ElMessage, ElMessageBox } from 'element-plus';

const props = defineProps({
    ticket: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['refresh']);

const pricesLoading = ref(false);
const prices = ref([]);
const dateRange = ref(null);
const batchDialogVisible = ref(false);
const batchSubmitting = ref(false);
const batchFormRef = ref(null);
const editDialogVisible = ref(false);
const editSubmitting = ref(false);
const editFormRef = ref(null);
const editingPrice = ref(null);

const batchForm = ref({
    dateRange: null,
    market_price: 0,
    sale_price: 0,
    settlement_price: 0,
});

const editForm = ref({
    date: '',
    market_price: 0,
    sale_price: 0,
    settlement_price: 0,
});

const validateSalePrice = (rule, value, callback) => {
    if (value === null || value === undefined) {
        callback(new Error('请输入销售价'));
    } else if (value < 0) {
        callback(new Error('销售价不能小于0'));
    } else if (batchForm.value.market_price && value > batchForm.value.market_price) {
        callback(new Error('销售价不能大于门市价'));
    } else {
        callback();
    }
};

const validateSettlementPrice = (rule, value, callback) => {
    if (value === null || value === undefined) {
        callback(new Error('请输入结算价'));
    } else if (value < 0) {
        callback(new Error('结算价不能小于0'));
    } else if (batchForm.value.sale_price && value > batchForm.value.sale_price) {
        callback(new Error('结算价不能大于销售价'));
    } else {
        callback();
    }
};

const validateEditSalePrice = (rule, value, callback) => {
    if (value === null || value === undefined) {
        callback(new Error('请输入销售价'));
    } else if (value < 0) {
        callback(new Error('销售价不能小于0'));
    } else if (editForm.value.market_price && value > editForm.value.market_price) {
        callback(new Error('销售价不能大于门市价'));
    } else {
        callback();
    }
};

const validateEditSettlementPrice = (rule, value, callback) => {
    if (value === null || value === undefined) {
        callback(new Error('请输入结算价'));
    } else if (value < 0) {
        callback(new Error('结算价不能小于0'));
    } else if (editForm.value.sale_price && value > editForm.value.sale_price) {
        callback(new Error('结算价不能大于销售价'));
    } else {
        callback();
    }
};

const batchFormRules = {
    dateRange: [
        { required: true, message: '请选择日期范围', trigger: 'change' }
    ],
    market_price: [
        { required: true, message: '请输入门市价', trigger: 'blur' },
        { type: 'number', min: 0, message: '门市价不能小于0', trigger: 'blur' }
    ],
    sale_price: [
        { validator: validateSalePrice, trigger: 'blur' }
    ],
    settlement_price: [
        { validator: validateSettlementPrice, trigger: 'blur' }
    ],
};

const editFormRules = {
    market_price: [
        { required: true, message: '请输入门市价', trigger: 'blur' },
        { type: 'number', min: 0, message: '门市价不能小于0', trigger: 'blur' }
    ],
    sale_price: [
        { validator: validateEditSalePrice, trigger: 'blur' }
    ],
    settlement_price: [
        { validator: validateEditSettlementPrice, trigger: 'blur' }
    ],
};

const fetchPrices = async () => {
    if (!props.ticket || !props.ticket.id) return;
    
    pricesLoading.value = true;
    try {
        const params = {};
        
        if (dateRange.value && dateRange.value.length === 2) {
            params.start_date = dateRange.value[0];
            params.end_date = dateRange.value[1];
        } else if (props.ticket.sale_start_date && props.ticket.sale_end_date) {
            // 如果没有选择日期范围，使用门票的销售日期范围
            params.start_date = props.ticket.sale_start_date;
            params.end_date = props.ticket.sale_end_date;
        }
        
        // 请求所有价格数据（不分页）
        params.all = true;
        const response = await axios.get(`/tickets/${props.ticket.id}/prices`, { params });
        
        // 获取所有日期范围内的价格数据（数据库存储为分，转换为元）
        const priceMap = {};
        if (response.data && response.data.data) {
            response.data.data.forEach(price => {
                priceMap[price.date] = {
                    ...price,
                    market_price: parseFloat(price.market_price) || 0,
                    sale_price: parseFloat(price.sale_price) || 0,
                    settlement_price: parseFloat(price.settlement_price) || 0,
                    is_custom: true,
                };
            });
        }
        
        // 生成日期范围内的所有日期，如果没有特殊价格，使用默认价格
        const startDate = params.start_date || props.ticket.sale_start_date;
        const endDate = params.end_date || props.ticket.sale_end_date;
        
        if (startDate && endDate) {
            const start = new Date(startDate);
            const end = new Date(endDate);
            const result = [];
            
            for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
                const dateStr = d.toISOString().split('T')[0];
                if (priceMap[dateStr]) {
                    result.push(priceMap[dateStr]);
                } else {
                    // 默认价格（单位：元）
                    result.push({
                        date: dateStr,
                        market_price: parseFloat(props.ticket.market_price) || 0,
                        sale_price: parseFloat(props.ticket.sale_price) || 0,
                        settlement_price: parseFloat(props.ticket.settlement_price) || 0,
                        is_custom: false,
                    });
                }
            }
            
            prices.value = result;
        } else {
            prices.value = Object.values(priceMap);
        }
    } catch (error) {
        ElMessage.error('获取价格列表失败');
        console.error(error);
    } finally {
        pricesLoading.value = false;
    }
};

const handleDateRangeChange = () => {
    fetchPrices();
};

const resetFilter = () => {
    dateRange.value = null;
    fetchPrices();
};

const handleBatchSetPrice = () => {
    if (!props.ticket.sale_start_date || !props.ticket.sale_end_date) {
        ElMessage.warning('请先设置门票的销售开始日期和结束日期');
        return;
    }
    
    batchForm.value = {
        dateRange: dateRange.value || [props.ticket.sale_start_date, props.ticket.sale_end_date],
        market_price: parseFloat(props.ticket.market_price) || 0, // 单位：元
        sale_price: parseFloat(props.ticket.sale_price) || 0, // 单位：元
        settlement_price: parseFloat(props.ticket.settlement_price) || 0, // 单位：元
    };
    batchDialogVisible.value = true;
};

const handleBatchSubmit = async () => {
    if (!batchFormRef.value) return;
    
    batchFormRef.value.validate(async (valid) => {
        if (valid) {
            batchSubmitting.value = true;
            try {
                const [startDate, endDate] = batchForm.value.dateRange;
                const start = new Date(startDate);
                const end = new Date(endDate);
                const priceList = [];
                
                for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
                    priceList.push({
                        date: d.toISOString().split('T')[0],
                        market_price: batchForm.value.market_price,
                        sale_price: batchForm.value.sale_price,
                        settlement_price: batchForm.value.settlement_price,
                    });
                }
                
                await axios.post(`/tickets/${props.ticket.id}/prices`, {
                    prices: priceList,
                });
                
                ElMessage.success('批量设置价格成功');
                batchDialogVisible.value = false;
                resetBatchForm();
                fetchPrices();
            } catch (error) {
                const message = error.response?.data?.message || '批量设置价格失败';
                ElMessage.error(message);
            } finally {
                batchSubmitting.value = false;
            }
        }
    });
};

const handleEditPrice = (row) => {
    editingPrice.value = row;
    editForm.value = {
        date: row.date,
        market_price: parseFloat(row.market_price) || 0, // 单位：元
        sale_price: parseFloat(row.sale_price) || 0, // 单位：元
        settlement_price: parseFloat(row.settlement_price) || 0, // 单位：元
    };
    editDialogVisible.value = true;
};

const handleEditSubmit = async () => {
    if (!editFormRef.value) return;
    
    editFormRef.value.validate(async (valid) => {
        if (valid) {
            editSubmitting.value = true;
            try {
                // 检查是否已存在该日期的价格
                const existingPrice = prices.value.find(p => p.date === editForm.value.date && p.is_custom);
                
                if (existingPrice && existingPrice.id) {
                    // 更新现有价格（元转分）
                    await axios.put(`/ticket-prices/${existingPrice.id}`, {
                        market_price: editForm.value.market_price,
                        sale_price: editForm.value.sale_price,
                        settlement_price: editForm.value.settlement_price,
                    });
                    ElMessage.success('价格更新成功');
                } else {
                    // 创建新价格（元转分）
                    await axios.post(`/tickets/${props.ticket.id}/prices`, {
                        prices: [{
                            date: editForm.value.date,
                            market_price: Math.round(editForm.value.market_price * 100),
                            sale_price: Math.round(editForm.value.sale_price * 100),
                            settlement_price: Math.round(editForm.value.settlement_price * 100),
                        }],
                    });
                    ElMessage.success('价格创建成功');
                }
                
                editDialogVisible.value = false;
                resetEditForm();
                fetchPrices();
            } catch (error) {
                const message = error.response?.data?.message || '操作失败';
                ElMessage.error(message);
            } finally {
                editSubmitting.value = false;
            }
        }
    });
};

const handleDeletePrice = async (row) => {
    if (!row.id) {
        ElMessage.warning('该价格是默认价格，无法删除');
        return;
    }
    
    try {
        await ElMessageBox.confirm(
            `确定要删除 ${row.date} 的特殊价格吗？删除后将恢复使用默认价格。`,
            '提示',
            {
                type: 'warning',
                confirmButtonText: '确定删除',
                cancelButtonText: '取消'
            }
        );
        
        await axios.delete(`/ticket-prices/${row.id}`);
        ElMessage.success('删除成功');
        fetchPrices();
    } catch (error) {
        if (error !== 'cancel') {
            const message = error.response?.data?.message || '删除失败';
            ElMessage.error(message);
        }
    }
};

const resetBatchForm = () => {
    batchForm.value = {
        dateRange: null,
        market_price: 0,
        sale_price: 0,
        settlement_price: 0,
    };
    if (batchFormRef.value) {
        batchFormRef.value.clearValidate();
    }
};

const resetEditForm = () => {
    editingPrice.value = null;
    editForm.value = {
        date: '',
        market_price: 0,
        sale_price: 0,
        settlement_price: 0,
    };
    if (editFormRef.value) {
        editFormRef.value.clearValidate();
    }
};

const formatPrice = (price) => {
    if (!price) return '0.00';
    // 价格存储为分，转换为元显示
    return parseFloat(price).toFixed(2);
};

watch(() => props.ticket, () => {
    if (props.ticket && props.ticket.id) {
        fetchPrices();
    }
}, { immediate: true });

onMounted(() => {
    if (props.ticket && props.ticket.id) {
        fetchPrices();
    }
});
</script>

<style scoped>
.custom-price {
    font-weight: bold;
    color: #409eff;
}
</style>

