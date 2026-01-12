<template>
    <div>
        <el-page-header @back="goBack" title="返回门票列表">
            <template #content>
                <span>门票详情</span>
            </template>
        </el-page-header>

        <el-card v-loading="loading" style="margin-top: 20px;">
            <div v-if="ticket">
                <el-descriptions title="门票基本信息" :column="2" border style="margin-bottom: 20px;">
                    <el-descriptions-item label="门票名称">{{ ticket.name }}</el-descriptions-item>
                    <el-descriptions-item label="门票编码">{{ ticket.code }}</el-descriptions-item>
                    <el-descriptions-item label="所属景区">{{ ticket.scenic_spot?.name || '-' }}</el-descriptions-item>
                    <el-descriptions-item label="软件服务商">{{ ticket.software_provider?.name || '-' }}</el-descriptions-item>
                    <el-descriptions-item label="外部门票编号">{{ ticket.external_ticket_id || '-' }}</el-descriptions-item>
                    <el-descriptions-item label="状态">
                        <el-tag :type="ticket.is_active ? 'success' : 'danger'">
                            {{ ticket.is_active ? '启用' : '禁用' }}
                        </el-tag>
                    </el-descriptions-item>
                    <el-descriptions-item label="描述" :span="2">
                        {{ ticket.description || '-' }}
                    </el-descriptions-item>
                </el-descriptions>

                <el-tabs v-model="activeTab" style="margin-top: 20px;">
                    <!-- 价库管理标签页 -->
                    <el-tab-pane label="价库管理" name="prices">
                        <div style="margin-bottom: 20px;">
                            <el-button type="primary" @click="handleBatchSetPrice">批量设置价库</el-button>
                            <el-date-picker
                                v-model="priceDateRange"
                                type="daterange"
                                range-separator="至"
                                start-placeholder="开始日期"
                                end-placeholder="结束日期"
                                format="YYYY-MM-DD"
                                value-format="YYYY-MM-DD"
                                style="margin-left: 20px;"
                                @change="fetchPrices"
                            />
                            <el-button style="margin-left: 10px;" @click="resetPriceFilter">重置筛选</el-button>
                        </div>

                        <el-table :data="prices" v-loading="priceLoading" border>
                            <el-table-column prop="date" label="日期" width="120" align="right">
                            <template #default="{ row }">
                                {{ formatDateOnly(row.date) }}
                            </template>
                            </el-table-column>
                            <el-table-column prop="cost_price" label="成本价" width="120" align="right">
                                <template #default="{ row }">
                                    ¥{{ formatPrice(row.cost_price) }}
                                </template>
                            </el-table-column>
                            <el-table-column prop="sale_price" label="销售价" width="120" align="right">
                                <template #default="{ row }">
                                    ¥{{ formatPrice(row.sale_price) }}
                                </template>
                            </el-table-column>
                            <el-table-column prop="stock_available" label="可用库存" width="120" align="right" />
                            <el-table-column label="操作" width="150" fixed="right">
                                <template #default="{ row }">
                                    <el-button size="small" @click="handleEditPrice(row)">编辑</el-button>
                                    <el-button size="small" type="danger" @click="handleDeletePrice(row)">删除</el-button>
                                </template>
                            </el-table-column>
                        </el-table>

                        <el-pagination
                            v-if="pricePagination.total > 0"
                            v-model:current-page="pricePagination.current_page"
                            v-model:page-size="pricePagination.per_page"
                            :page-sizes="[10, 20, 50, 100]"
                            :total="pricePagination.total"
                            layout="total, sizes, prev, pager, next, jumper"
                            style="margin-top: 20px; justify-content: flex-end;"
                            @size-change="handlePriceSizeChange"
                            @current-change="handlePricePageChange"
                        />

                        <el-empty v-if="!priceLoading && prices.length === 0" description="暂无价格数据" />

                        <!-- 批量设置价库对话框 -->
                        <el-dialog
                            v-model="batchPriceDialogVisible"
                            title="批量设置价库"
                            width="600px"
                            append-to-body
                            @close="resetBatchPriceForm"
                        >
                            <el-form
                                ref="batchPriceFormRef"
                                :model="batchPriceForm"
                                :rules="batchPriceRules"
                                label-width="120px"
                            >
                                <el-form-item label="日期范围" prop="dateRange">
                                    <el-date-picker
                                        v-model="batchPriceForm.dateRange"
                                        type="daterange"
                                        range-separator="至"
                                        start-placeholder="开始日期"
                                        end-placeholder="结束日期"
                                        format="YYYY-MM-DD"
                                        value-format="YYYY-MM-DD"
                                        style="width: 100%"
                                    />
                                </el-form-item>
                                <el-form-item label="成本价" prop="cost_price">
                                    <el-input-number v-model="batchPriceForm.cost_price" :min="0" :precision="2" :step="0.01" style="width: 100%;" />
                                </el-form-item>
                                <el-form-item label="销售价" prop="sale_price">
                                    <el-input-number v-model="batchPriceForm.sale_price" :min="0" :precision="2" :step="0.01" style="width: 100%;" />
                                </el-form-item>
                                <el-form-item label="可用库存" prop="stock_available">
                                    <el-input-number v-model="batchPriceForm.stock_available" :min="0" style="width: 100%;" />
                                </el-form-item>
                            </el-form>
                            <template #footer>
                                <el-button @click="batchPriceDialogVisible = false">取消</el-button>
                                <el-button type="primary" @click="handleSubmitBatchPrice" :loading="batchPriceSubmitting">确定</el-button>
                            </template>
                        </el-dialog>

                        <!-- 编辑价库对话框 -->
                        <el-dialog
                            v-model="priceFormDialogVisible"
                            title="编辑价库"
                            width="500px"
                            append-to-body
                            @close="resetPriceForm"
                        >
                            <el-form
                                ref="priceFormRef"
                                :model="priceForm"
                                :rules="priceRules"
                                label-width="120px"
                            >
                                <el-form-item label="日期">
                                    <el-date-picker
                                        v-model="priceForm.date"
                                        type="date"
                                        placeholder="选择日期"
                                        format="YYYY-MM-DD"
                                        value-format="YYYY-MM-DD"
                                        style="width: 100%"
                                        disabled
                                    />
                                </el-form-item>
                                <el-form-item label="成本价" prop="cost_price">
                                    <el-input-number v-model="priceForm.cost_price" :min="0" :precision="2" :step="0.01" style="width: 100%;" />
                                </el-form-item>
                                <el-form-item label="销售价" prop="sale_price">
                                    <el-input-number v-model="priceForm.sale_price" :min="0" :precision="2" :step="0.01" style="width: 100%;" />
                                </el-form-item>
                                <el-form-item label="可用库存" prop="stock_available">
                                    <el-input-number v-model="priceForm.stock_available" :min="0" style="width: 100%;" />
                                </el-form-item>
                            </el-form>
                            <template #footer>
                                <el-button @click="priceFormDialogVisible = false">取消</el-button>
                                <el-button type="primary" @click="handleSubmitPrice" :loading="priceSubmitting">确定</el-button>
                            </template>
                        </el-dialog>
                    </el-tab-pane>
                </el-tabs>
            </div>
            <el-empty v-else-if="!loading" description="门票信息加载失败或不存在" />
        </el-card>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import axios from '../../utils/axios';
import { ElMessage, ElMessageBox } from 'element-plus';

const route = useRoute();
const router = useRouter();

const loading = ref(false);
const ticket = ref(null);
const activeTab = ref('prices'); // 默认激活价库管理

// 价库管理相关
const prices = ref([]);
const priceLoading = ref(false);
const priceDateRange = ref([]);
const pricePagination = ref({
    current_page: 1,
    per_page: 50,
    total: 0,
    last_page: 1,
});

const batchPriceDialogVisible = ref(false);
const batchPriceFormRef = ref(null);
const batchPriceSubmitting = ref(false);
const batchPriceForm = ref({
    dateRange: null,
    cost_price: 0,
    sale_price: 0,
    stock_available: 0,
});

const batchPriceRules = {
    dateRange: [{ required: true, message: '请选择日期范围', trigger: 'change' }],
    cost_price: [{ required: true, message: '请输入成本价', trigger: 'blur' }],
    sale_price: [
        { required: true, message: '请输入销售价', trigger: 'blur' },
        {
            validator: (rule, value, callback) => {
                if (value < batchPriceForm.value.cost_price) {
                    callback(new Error('销售价不能低于成本价'));
                } else {
                    callback();
                }
            },
            trigger: 'blur',
        },
    ],
    stock_available: [{ required: true, message: '请输入可用库存', trigger: 'blur' }],
};

const priceFormDialogVisible = ref(false);
const priceFormRef = ref(null);
const priceSubmitting = ref(false);
const editingPriceId = ref(null);
const priceForm = ref({
    date: null,
    cost_price: 0,
    sale_price: 0,
    stock_available: 0,
});

const priceRules = {
    cost_price: [{ required: true, message: '请输入成本价', trigger: 'blur' }],
    sale_price: [
        { required: true, message: '请输入销售价', trigger: 'blur' },
        {
            validator: (rule, value, callback) => {
                if (value < priceForm.value.cost_price) {
                    callback(new Error('销售价不能低于成本价'));
                } else {
                    callback();
                }
            },
            trigger: 'blur',
        },
    ],
    stock_available: [{ required: true, message: '请输入可用库存', trigger: 'blur' }],
};

const goBack = () => {
    router.push('/tickets');
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

const formatPrice = (price) => {
    if (!price) return '0.00';
    return parseFloat(price).toFixed(2);
};

const fetchTicketDetail = async () => {
    loading.value = true;
    try {
        const response = await axios.get(`/tickets/${route.params.id}`);
        ticket.value = response.data.data;
        if (ticket.value) {
            await fetchPrices(); // 获取价库列表
        }
    } catch (error) {
        ElMessage.error('获取门票详情失败');
        console.error(error);
    } finally {
        loading.value = false;
    }
};

const fetchPrices = async () => {
    if (!ticket.value) return;

    priceLoading.value = true;
    try {
        const params = {
            ticket_id: ticket.value.id,
            page: pricePagination.value.current_page,
            per_page: pricePagination.value.per_page,
        };
        if (priceDateRange.value && priceDateRange.value.length === 2) {
            params.start_date = priceDateRange.value[0];
            params.end_date = priceDateRange.value[1];
        }
        const response = await axios.get(`/ticket-prices`, { params });
        prices.value = response.data.data || [];
        // 更新分页信息
        if (response.data.current_page !== undefined) {
            pricePagination.value = {
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
        priceLoading.value = false;
    }
};

const resetPriceFilter = () => {
    priceDateRange.value = [];
    pricePagination.value.current_page = 1;
    fetchPrices();
};

const handlePricePageChange = (page) => {
    pricePagination.value.current_page = page;
    fetchPrices();
};

const handlePriceSizeChange = (size) => {
    pricePagination.value.per_page = size;
    pricePagination.value.current_page = 1;
    fetchPrices();
};

const handleBatchSetPrice = () => {
    resetBatchPriceForm();
    batchPriceDialogVisible.value = true;
};

const handleSubmitBatchPrice = async () => {
    if (!batchPriceFormRef.value) return;
    await batchPriceFormRef.value.validate(async (valid) => {
        if (valid) {
            batchPriceSubmitting.value = true;
            try {
                const data = {
                    ticket_id: ticket.value.id,
                    start_date: batchPriceForm.value.dateRange[0],
                    end_date: batchPriceForm.value.dateRange[1],
                    cost_price: batchPriceForm.value.cost_price,
                    sale_price: batchPriceForm.value.sale_price,
                    stock_available: batchPriceForm.value.stock_available,
                };
                await axios.post(`/ticket-prices/batch`, data);
                ElMessage.success('批量设置价库成功');
                batchPriceDialogVisible.value = false;
                pricePagination.value.current_page = 1;
                await fetchPrices();
            } catch (error) {
                const message = error.response?.data?.message || error.message || '操作失败';
                ElMessage.error(message);
                console.error(error);
            } finally {
                batchPriceSubmitting.value = false;
            }
        }
    });
};

const handleEditPrice = (row) => {
    editingPriceId.value = row.id;
    priceForm.value = {
        date: row.date,
        cost_price: parseFloat(row.cost_price),
        sale_price: parseFloat(row.sale_price),
        stock_available: row.stock_available,
    };
    priceFormDialogVisible.value = true;
};

const handleSubmitPrice = async () => {
    if (!priceFormRef.value) return;
    await priceFormRef.value.validate(async (valid) => {
        if (valid) {
            priceSubmitting.value = true;
            try {
                const data = {
                    cost_price: priceForm.value.cost_price,
                    sale_price: priceForm.value.sale_price,
                    stock_available: priceForm.value.stock_available,
                };
                await axios.put(`/ticket-prices/${editingPriceId.value}`, data);
                ElMessage.success('价库更新成功');
                priceFormDialogVisible.value = false;
                // 编辑后保持在当前页
                await fetchPrices();
            } catch (error) {
                const message = error.response?.data?.message || error.message || '操作失败';
                ElMessage.error(message);
                console.error(error);
            } finally {
                priceSubmitting.value = false;
            }
        }
    });
};

const handleDeletePrice = async (row) => {
    try {
        await ElMessageBox.confirm(`确定要删除日期为"${row.date}"的价库记录吗？`, '提示', {
            type: 'warning',
        });
        await axios.delete(`/ticket-prices/${row.id}`);
        ElMessage.success('删除成功');
        // 如果当前页没有数据了，回到上一页
        if (prices.value.length === 1 && pricePagination.value.current_page > 1) {
            pricePagination.value.current_page--;
        }
        await fetchPrices();
    } catch (error) {
        if (error !== 'cancel') {
            ElMessage.error('删除失败');
            console.error(error);
        }
    }
};

const resetBatchPriceForm = () => {
    batchPriceFormRef.value?.resetFields();
    batchPriceForm.value = {
        dateRange: null,
        cost_price: 0,
        sale_price: 0,
        stock_available: 0,
    };
};

const resetPriceForm = () => {
    priceFormRef.value?.resetFields();
    editingPriceId.value = null;
    priceForm.value = {
        date: null,
        cost_price: 0,
        sale_price: 0,
        stock_available: 0,
    };
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
    fetchTicketDetail();
});
</script>

<style scoped>
h2 {
    margin-bottom: 20px;
}
</style>
