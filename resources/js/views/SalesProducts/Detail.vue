<template>
    <div>
        <h2>产品详情 - {{ product.product_name }}</h2>
        <el-card>
            <!-- 基本信息 -->
            <el-descriptions title="基本信息" :column="2" border style="margin-bottom: 20px;">
                <el-descriptions-item label="产品名称">{{ product.product_name }}</el-descriptions-item>
                <el-descriptions-item label="OTA产品编码">
                    <el-tag type="info">{{ product.ota_product_code }}</el-tag>
                </el-descriptions-item>
                <el-descriptions-item label="所属景区">{{ product.scenic_spot?.name || '-' }}</el-descriptions-item>
                <el-descriptions-item label="入住天数">{{ product.stay_days || 1 }} 晚</el-descriptions-item>
                <el-descriptions-item label="销售开始日期">{{ product.sale_start_date || '-' }}</el-descriptions-item>
                <el-descriptions-item label="销售结束日期">{{ product.sale_end_date || '-' }}</el-descriptions-item>
                <el-descriptions-item label="状态">
                    <el-tag :type="product.status === 1 ? 'success' : 'danger'">
                        {{ product.status === 1 ? '上架' : '下架' }}
                    </el-tag>
                </el-descriptions-item>
                <el-descriptions-item label="创建时间">{{ formatDate(product.created_at) }}</el-descriptions-item>
                <el-descriptions-item label="描述" :span="2">
                    {{ product.description || '-' }}
                </el-descriptions-item>
            </el-descriptions>
            
            <!-- 打包清单 -->
            <div class="section">
                <div class="section-header">
                    <h3>打包清单</h3>
                    <el-button type="primary" @click="handleAddBundleItem">添加打包项</el-button>
                </div>
                <el-table :data="bundleItems" v-loading="bundleItemsLoading" border style="margin-top: 10px;">
                    <el-table-column prop="resource_type" label="资源类型" width="120">
                        <template #default="{ row }">
                            <el-tag :type="row.resource_type === 'TICKET' ? 'success' : 'warning'">
                                {{ row.resource_type === 'TICKET' ? '门票' : '酒店房型' }}
                            </el-tag>
                        </template>
                    </el-table-column>
                    <el-table-column prop="resource_name" label="资源名称" width="200">
                        <template #default="{ row }">
                            {{ row.resource_name || '-' }}
                        </template>
                    </el-table-column>
                    <el-table-column prop="quantity" label="数量" width="100" />
                    <el-table-column prop="sort_order" label="排序" width="100" />
                    <el-table-column label="操作" width="250" fixed="right">
                        <template #default="{ row }">
                            <el-button size="small" @click="handleEditBundleItem(row)">编辑</el-button>
                            <el-button size="small" type="danger" @click="handleDeleteBundleItem(row)">删除</el-button>
                        </template>
                    </el-table-column>
                </el-table>
            </div>
            
            <!-- 价格日历 -->
            <div class="section" style="margin-top: 30px;">
                <div class="section-header">
                    <h3>价格日历</h3>
                    <div>
                        <el-date-picker
                            v-model="priceDateRange"
                            type="daterange"
                            range-separator="至"
                            start-placeholder="开始日期"
                            end-placeholder="结束日期"
                            format="YYYY-MM-DD"
                            value-format="YYYY-MM-DD"
                            style="margin-right: 10px;"
                            @change="handlePriceDateRangeChange"
                        />
                        <el-button type="primary" @click="handleUpdatePriceCalendar" :loading="updatingPrice">
                            更新价格日历
                        </el-button>
                    </div>
                </div>
                <el-table :data="prices" v-loading="pricesLoading" border style="margin-top: 10px;">
                    <el-table-column prop="date" label="日期" width="120">
                        <template #default="{ row }">
                            {{ formatDate(row.date) }}
                        </template>
                    </el-table-column>
                    <el-table-column prop="sale_price" label="销售价(元)" width="120">
                        <template #default="{ row }">
                            {{ row.sale_price || '-' }}
                        </template>
                    </el-table-column>
                    <el-table-column prop="settlement_price" label="结算价(元)" width="120">
                        <template #default="{ row }">
                            {{ row.settlement_price || '-' }}
                        </template>
                    </el-table-column>
                    <el-table-column prop="price_breakdown" label="价格明细" show-overflow-tooltip>
                        <template #default="{ row }">
                            <el-popover
                                v-if="row.price_breakdown"
                                placement="top"
                                width="400"
                                trigger="hover"
                            >
                                <template #reference>
                                    <el-button text type="primary">查看明细</el-button>
                                </template>
                                <div>
                                    <div v-for="(item, index) in row.price_breakdown" :key="index" style="margin-bottom: 10px;">
                                        <strong>{{ item.type === 'TICKET' ? '门票' : '酒店' }}：</strong>
                                        {{ item.name }} × {{ item.quantity }}
                                        <span v-if="item.multiplier"> × {{ item.multiplier }}</span>
                                        <span v-if="item.stay_days"> × {{ item.stay_days }}晚</span>
                                        = {{ item.total_price }}元
                                    </div>
                                </div>
                            </el-popover>
                            <span v-else>-</span>
                        </template>
                    </el-table-column>
                    <el-table-column prop="stock_available" label="可用库存" width="100">
                        <template #default="{ row }">
                            {{ row.stock_available !== null ? row.stock_available : '-' }}
                        </template>
                    </el-table-column>
                </el-table>
                
                <el-pagination
                    v-model:current-page="priceCurrentPage"
                    v-model:page-size="pricePageSize"
                    :page-sizes="[10, 20, 50, 100]"
                    :total="priceTotal"
                    layout="total, sizes, prev, pager, next, jumper"
                    style="margin-top: 20px;"
                    @size-change="fetchPrices"
                    @current-change="fetchPrices"
                />
            </div>
        </el-card>

        <!-- 添加/编辑打包项对话框 -->
        <el-dialog
            v-model="bundleItemDialogVisible"
            :title="bundleItemDialogTitle"
            width="600px"
            @close="resetBundleItemForm"
        >
            <el-form
                ref="bundleItemFormRef"
                :model="bundleItemForm"
                :rules="bundleItemRules"
                label-width="120px"
            >
                <el-form-item label="资源类型" prop="resource_type">
                    <el-radio-group v-model="bundleItemForm.resource_type" @change="handleResourceTypeChange">
                        <el-radio value="TICKET">门票</el-radio>
                        <el-radio value="HOTEL">酒店房型</el-radio>
                    </el-radio-group>
                </el-form-item>
                <el-form-item label="资源" prop="resource_id">
                    <el-select
                        v-model="bundleItemForm.resource_id"
                        placeholder="请选择资源"
                        style="width: 100%"
                        :loading="resourcesLoading"
                    >
                        <el-option
                            v-for="resource in availableResources"
                            :key="resource.id"
                            :label="resource.hotel ? `${resource.hotel.name} - ${resource.name}` : resource.name"
                            :value="resource.id"
                        />
                    </el-select>
                </el-form-item>
                <el-form-item label="数量" prop="quantity">
                    <el-input-number 
                        v-model="bundleItemForm.quantity" 
                        :min="1" 
                        style="width: 100%"
                    />
                </el-form-item>
                <el-form-item label="排序" prop="sort_order">
                    <el-input-number 
                        v-model="bundleItemForm.sort_order" 
                        :min="0" 
                        style="width: 100%"
                    />
                    <div style="font-size: 12px; color: #909399; margin-top: 5px;">
                        数字越小越靠前，默认为0
                    </div>
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="bundleItemDialogVisible = false">取消</el-button>
                <el-button type="primary" @click="handleSubmitBundleItem" :loading="bundleItemSubmitting">确定</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup>
import { ref, reactive, onMounted, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ElMessage, ElMessageBox } from 'element-plus';
import { 
    salesProductsApi, 
    productBundleItemsApi, 
    salesProductPricesApi 
} from '../../api/systemPkg';
import axios from '../../utils/axios';

const route = useRoute();
const router = useRouter();

const productId = computed(() => parseInt(route.params.id));

// 产品数据
const product = ref({});
const loading = ref(false);

// 打包清单
const bundleItems = ref([]);
const bundleItemsLoading = ref(false);

// 价格日历
const prices = ref([]);
const pricesLoading = ref(false);
const priceCurrentPage = ref(1);
const pricePageSize = ref(30);
const priceTotal = ref(0);
const priceDateRange = ref(null);
const updatingPrice = ref(false);

// 打包项对话框
const bundleItemDialogVisible = ref(false);
const bundleItemDialogTitle = ref('添加打包项');
const bundleItemSubmitting = ref(false);
const bundleItemFormRef = ref(null);
const editingBundleItemId = ref(null);
const bundleItemForm = reactive({
    sales_product_id: null,
    resource_type: 'TICKET',
    resource_id: null,
    quantity: 1,
    sort_order: 0,
});

const bundleItemRules = {
    resource_type: [{ required: true, message: '请选择资源类型', trigger: 'change' }],
    resource_id: [{ required: true, message: '请选择资源', trigger: 'change' }],
    quantity: [{ required: true, message: '请输入数量', trigger: 'blur' }],
};

// 可用资源
const availableResources = ref([]);
const resourcesLoading = ref(false);

// 获取产品详情
const fetchProduct = async () => {
    loading.value = true;
    try {
        const response = await salesProductsApi.get(productId.value);
        product.value = response.data.data || {};
        
        // 设置价格日历的默认日期范围
        if (product.value.sale_start_date && product.value.sale_end_date) {
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const saleStartDate = new Date(product.value.sale_start_date);
            const saleEndDate = new Date(product.value.sale_end_date);
            
            // 计算开始日期：取今天和销售开始日期的较大值
            const startDate = saleStartDate > today ? saleStartDate : today;
            
            // 计算结束日期：取销售结束日期和开始日期+60天的较小值
            const maxEndDate = new Date(startDate);
            maxEndDate.setDate(maxEndDate.getDate() + 60);
            const endDate = saleEndDate < maxEndDate ? saleEndDate : maxEndDate;
            
            // 确保不超过60天
            const daysDiff = Math.floor((endDate - startDate) / (1000 * 60 * 60 * 24));
            if (daysDiff > 60) {
                const finalEndDate = new Date(startDate);
                finalEndDate.setDate(finalEndDate.getDate() + 60);
                priceDateRange.value = [
                    startDate.toISOString().split('T')[0],
                    finalEndDate.toISOString().split('T')[0]
                ];
            } else {
                priceDateRange.value = [
                    startDate.toISOString().split('T')[0],
                    endDate.toISOString().split('T')[0]
                ];
            }
        }
        
        fetchBundleItems();
        fetchPrices();
    } catch (error) {
        console.error('获取产品详情失败:', error);
        ElMessage.error('获取产品详情失败');
    } finally {
        loading.value = false;
    }
};

// 获取打包清单
const fetchBundleItems = async () => {
    bundleItemsLoading.value = true;
    try {
        const response = await productBundleItemsApi.list({
            sales_product_id: productId.value,
        });
        bundleItems.value = response.data.data || [];
    } catch (error) {
        console.error('获取打包清单失败:', error);
        ElMessage.error('获取打包清单失败');
    } finally {
        bundleItemsLoading.value = false;
    }
};

// 获取价格日历
const fetchPrices = async () => {
    pricesLoading.value = true;
    try {
        const params = {
            sales_product_id: productId.value,
            page: priceCurrentPage.value,
            per_page: pricePageSize.value,
        };
        
        if (priceDateRange.value && priceDateRange.value.length === 2) {
            params.date_from = priceDateRange.value[0];
            params.date_to = priceDateRange.value[1];
        }
        
        const response = await salesProductPricesApi.list(params);
        prices.value = response.data.data || [];
        priceTotal.value = response.data.total || 0;
    } catch (error) {
        console.error('获取价格日历失败:', error);
        ElMessage.error('获取价格日历失败');
    } finally {
        pricesLoading.value = false;
    }
};

// 获取可用资源（门票或房型）
const fetchAvailableResources = async (resourceType) => {
    resourcesLoading.value = true;
    try {
        if (resourceType === 'TICKET') {
            // 获取门票列表
            const response = await axios.get('/tickets', {
                params: {
                    scenic_spot_id: product.value.scenic_spot_id,
                    is_active: true,
                }
            });
            availableResources.value = response.data.data || [];
        } else if (resourceType === 'HOTEL') {
            // 获取房型列表
            const response = await axios.get('/res-room-types', {
                params: {
                    scenic_spot_id: product.value.scenic_spot_id,
                    is_active: true,
                }
            });
            availableResources.value = response.data.data || [];
        }
    } catch (error) {
        // 401错误由axios拦截器处理，不需要显示错误消息
        if (error.response?.status !== 401) {
            console.error('获取资源列表失败:', error);
            ElMessage.error('获取资源列表失败');
        }
    } finally {
        resourcesLoading.value = false;
    }
};

// 添加打包项
const handleAddBundleItem = async () => {
    editingBundleItemId.value = null;
    bundleItemDialogTitle.value = '添加打包项';
    resetBundleItemForm();
    bundleItemForm.sales_product_id = productId.value;
    bundleItemForm.resource_type = 'TICKET';
    await fetchAvailableResources('TICKET');
    bundleItemDialogVisible.value = true;
};

// 编辑打包项
const handleEditBundleItem = async (row) => {
    editingBundleItemId.value = row.id;
    bundleItemDialogTitle.value = '编辑打包项';
    Object.assign(bundleItemForm, {
        sales_product_id: productId.value,
        resource_type: row.resource_type,
        resource_id: row.resource_id,
        quantity: row.quantity,
        sort_order: row.sort_order || 0,
    });
    await fetchAvailableResources(row.resource_type);
    bundleItemDialogVisible.value = true;
};

// 资源类型变化
const handleResourceTypeChange = async (value) => {
    bundleItemForm.resource_id = null;
    await fetchAvailableResources(value);
};

// 提交打包项
const handleSubmitBundleItem = async () => {
    if (!bundleItemFormRef.value) return;
    
    await bundleItemFormRef.value.validate(async (valid) => {
        if (!valid) return;
        
        // 检查是否已存在
        const exists = bundleItems.value.some(item => 
            item.id !== editingBundleItemId.value &&
            item.resource_type === bundleItemForm.resource_type &&
            item.resource_id === bundleItemForm.resource_id
        );
        
        if (exists) {
            ElMessage.warning('该资源已存在于打包清单中');
            return;
        }
        
        bundleItemSubmitting.value = true;
        try {
            if (editingBundleItemId.value) {
                await productBundleItemsApi.update(editingBundleItemId.value, {
                    quantity: bundleItemForm.quantity,
                    sort_order: bundleItemForm.sort_order,
                });
                ElMessage.success('打包项更新成功');
            } else {
                await productBundleItemsApi.create(bundleItemForm);
                ElMessage.success('打包项添加成功');
            }
            bundleItemDialogVisible.value = false;
            fetchBundleItems();
            // 触发价格更新
            handleUpdatePriceCalendar();
        } catch (error) {
            console.error('保存打包项失败:', error);
            ElMessage.error(error.response?.data?.message || '保存打包项失败');
        } finally {
            bundleItemSubmitting.value = false;
        }
    });
};

// 删除打包项
const handleDeleteBundleItem = async (row) => {
    try {
        await ElMessageBox.confirm('确定要删除该打包项吗？', '提示', {
            confirmButtonText: '确定',
            cancelButtonText: '取消',
            type: 'warning',
        });
        
        await productBundleItemsApi.delete(row.id);
        ElMessage.success('打包项删除成功');
        fetchBundleItems();
        // 触发价格更新
        handleUpdatePriceCalendar();
    } catch (error) {
        if (error !== 'cancel') {
            console.error('删除打包项失败:', error);
            ElMessage.error(error.response?.data?.message || '删除打包项失败');
        }
    }
};

// 更新价格日历
const handleUpdatePriceCalendar = async () => {
    updatingPrice.value = true;
    try {
        await salesProductPricesApi.updateCalendar({
            sales_product_id: productId.value,
        });
        ElMessage.success('价格日历更新任务已提交，将在后台处理');
        // 等待一下再刷新，给后台处理时间
        setTimeout(() => {
            fetchPrices();
        }, 2000);
    } catch (error) {
        console.error('更新价格日历失败:', error);
        ElMessage.error(error.response?.data?.message || '更新价格日历失败');
    } finally {
        updatingPrice.value = false;
    }
};

// 价格日期范围变化
const handlePriceDateRangeChange = () => {
    priceCurrentPage.value = 1;
    fetchPrices();
};

// 重置打包项表单
const resetBundleItemForm = () => {
    Object.assign(bundleItemForm, {
        sales_product_id: null,
        resource_type: 'TICKET',
        resource_id: null,
        quantity: 1,
        sort_order: 0,
    });
    editingBundleItemId.value = null;
    availableResources.value = [];
    if (bundleItemFormRef.value) {
        bundleItemFormRef.value.clearValidate();
    }
};

// 格式化日期
const formatDate = (date) => {
    if (!date) return '-';
    return new Date(date).toISOString().split('T')[0];
};

// 初始化
onMounted(() => {
    fetchProduct();
});
</script>

<style scoped>
.section {
    margin-top: 20px;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.section-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 500;
}
</style>

