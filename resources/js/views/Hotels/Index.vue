<template>
    <div>
        <h2>酒店管理</h2>
        <el-card>
            <div style="margin-bottom: 20px;">
                <el-button type="primary" @click="handleCreate">创建酒店</el-button>
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
                <el-input
                    v-model="searchKeyword"
                    placeholder="搜索酒店名称、编码或外部编号"
                    style="width: 300px; margin-left: 10px;"
                    clearable
                    @input="handleSearch"
                >
                    <template #prefix>
                        <el-icon><Search /></el-icon>
                    </template>
                </el-input>
            </div>
            
            <el-table :data="hotels" v-loading="loading" border>
                <el-table-column prop="name" label="酒店名称" width="200" />
                <el-table-column prop="code" label="酒店编码" width="150" />
                <el-table-column prop="external_code" label="外部酒店编号" width="150">
                    <template #default="{ row }">
                        {{ row.external_code || '-' }}
                    </template>
                </el-table-column>
                <el-table-column label="所属景区" width="150">
                    <template #default="{ row }">
                        {{ row.scenic_spot?.name || '-' }}
                    </template>
                </el-table-column>
                <el-table-column prop="address" label="地址" show-overflow-tooltip />
                <el-table-column prop="contact_phone" label="联系电话" width="150" />
                <el-table-column label="系统服务商" width="150">
                    <template #default="{ row }">
                        {{ row.scenic_spot?.software_provider?.name || '-' }}
                    </template>
                </el-table-column>
                <el-table-column prop="is_connected" label="是否直连" width="100">
                    <template #default="{ row }">
                        <el-tag :type="row.is_connected ? 'success' : 'info'">
                            {{ row.is_connected ? '是' : '否' }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="is_active" label="状态" width="100">
                    <template #default="{ row }">
                        <el-tag :type="row.is_active ? 'success' : 'danger'">
                            {{ row.is_active ? '启用' : '禁用' }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="300" fixed="right">
                    <template #default="{ row }">
                        <el-button size="small" @click="handleManageRoomTypes(row)">管理房型</el-button>
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
                @size-change="fetchHotels"
                @current-change="fetchHotels"
            />
        </el-card>

        <!-- 创建/编辑酒店对话框 -->
        <el-dialog
            v-model="dialogVisible"
            :title="dialogTitle"
            width="600px"
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
                    >
                        <el-option
                            v-for="spot in scenicSpots"
                            :key="spot.id"
                            :label="spot.name"
                            :value="spot.id"
                        />
                    </el-select>
                </el-form-item>
                <el-form-item label="酒店名称" prop="name">
                    <el-input v-model="form.name" placeholder="请输入酒店名称" />
                </el-form-item>
                <el-form-item label="酒店编码" prop="code">
                    <el-input v-model="form.code" placeholder="请输入酒店编码" :disabled="isEdit" />
                </el-form-item>
                <el-form-item label="外部酒店编号" prop="external_code">
                    <el-input v-model="form.external_code" placeholder="用于资源方系统对接的酒店编号（可选）" />
                    <div style="font-size: 12px; color: #909399; margin-top: 5px;">
                        如果配置了外部编号，系统将优先使用外部编号与资源方系统对接；如果为空，则使用酒店编码
                    </div>
                </el-form-item>
                <el-form-item label="地址" prop="address">
                    <el-input v-model="form.address" placeholder="请输入酒店地址" />
                </el-form-item>
                <el-form-item label="联系电话" prop="contact_phone">
                    <el-input v-model="form.contact_phone" placeholder="请输入联系电话" />
                </el-form-item>
                <el-form-item label="是否直连" prop="is_connected">
                    <el-switch v-model="form.is_connected" />
                    <span style="margin-left: 10px; color: #909399; font-size: 12px;">
                        开启后，系统将自动处理该酒店的订单
                    </span>
                </el-form-item>
                <el-form-item label="状态" prop="is_active">
                    <el-switch v-model="form.is_active" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="dialogVisible = false">取消</el-button>
                <el-button type="primary" @click="handleSubmit" :loading="submitting">确定</el-button>
            </template>
        </el-dialog>

        <!-- 房型管理对话框 -->
        <el-dialog
            v-model="roomTypeDialogVisible"
            title="房型管理"
            width="1200px"
            @close="resetRoomTypeDialog"
        >
            <div style="margin-bottom: 20px;">
                <el-button type="primary" @click="handleCreateRoomType">添加房型</el-button>
                <span style="margin-left: 20px; color: #909399;">
                    酒店：{{ currentHotel?.name }}
                </span>
            </div>

            <el-table :data="roomTypes" v-loading="roomTypeLoading" border>
                <el-table-column prop="name" label="房型名称" width="150" />
                <el-table-column prop="code" label="房型编码" width="150" />
                <el-table-column prop="max_occupancy" label="最大入住人数" width="120" />
                <el-table-column prop="description" label="描述" show-overflow-tooltip />
                <el-table-column prop="is_active" label="状态" width="100">
                    <template #default="{ row }">
                        <el-tag :type="row.is_active ? 'success' : 'danger'">
                            {{ row.is_active ? '启用' : '禁用' }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="250" fixed="right">
                    <template #default="{ row }">
                        <el-button size="small" @click="handleManageInventory(row)">管理库存</el-button>
                        <el-button size="small" @click="handleEditRoomType(row)">编辑</el-button>
                        <el-button size="small" type="danger" @click="handleDeleteRoomType(row)">删除</el-button>
                    </template>
                </el-table-column>
            </el-table>

            <!-- 创建/编辑房型对话框 -->
            <el-dialog
                v-model="roomTypeFormDialogVisible"
                :title="roomTypeFormTitle"
                width="500px"
                append-to-body
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
                        <el-input v-model="roomTypeForm.code" placeholder="请输入房型编码" :disabled="isEditRoomType" />
                    </el-form-item>
                    <el-form-item label="外部房型标识" prop="external_code">
                        <el-input v-model="roomTypeForm.external_code" placeholder="用于资源方系统对接的房型标识（可选）" />
                        <div style="font-size: 12px; color: #909399; margin-top: 5px;">
                            如果配置了外部标识，系统将优先使用外部标识与资源方系统对接；如果为空，则使用房型名称。注意：必须与资源方系统中的房型名称完全一致
                        </div>
                    </el-form-item>
                    <el-form-item label="最大入住人数" prop="max_occupancy">
                        <el-input-number v-model="roomTypeForm.max_occupancy" :min="1" :max="10" />
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

            <!-- 库存管理对话框 -->
            <el-dialog
                v-model="inventoryDialogVisible"
                title="库存管理"
                width="1000px"
                append-to-body
                @close="resetInventoryDialog"
            >
                <div style="margin-bottom: 20px;">
                    <span style="margin-right: 20px;">房型：{{ currentRoomType?.name }}</span>
                    <el-button type="primary" @click="handleBatchAddInventory">批量添加库存</el-button>
                    <el-date-picker
                        v-model="inventoryDateRange"
                        type="daterange"
                        range-separator="至"
                        start-placeholder="开始日期"
                        end-placeholder="结束日期"
                        style="margin-left: 20px;"
                        @change="handleInventoryDateRangeChange"
                    />
                </div>

                <el-table :data="inventories" v-loading="inventoryLoading" border>
                    <el-table-column prop="date" label="日期" width="120">
                        <template #default="{ row }">
                            {{ formatDate(row.date) }}
                        </template>
                    </el-table-column>
                    <el-table-column prop="total_quantity" label="总库存" width="100" />
                    <el-table-column prop="available_quantity" label="可用库存" width="100" />
                    <el-table-column prop="locked_quantity" label="锁定库存" width="100" />
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
                    <el-table-column label="操作" width="300" fixed="right">
                        <template #default="{ row }">
                            <el-button size="small" @click="handleEditInventory(row)">编辑</el-button>
                            <el-button 
                                size="small" 
                                :type="row.is_closed ? 'success' : 'warning'"
                                @click="handleToggleInventoryStatus(row)"
                            >
                                {{ row.is_closed ? '开启' : '关闭' }}
                            </el-button>
                            <el-button 
                                size="small" 
                                type="primary"
                                @click="handlePushInventoryToOta(row)"
                                :loading="pushingInventories[row.id]"
                            >
                                推送到OTA
                            </el-button>
                        </template>
                    </el-table-column>
                </el-table>

                <!-- 批量添加库存对话框 -->
                <el-dialog
                    v-model="batchInventoryDialogVisible"
                    title="批量添加库存"
                    width="600px"
                    append-to-body
                    @close="resetBatchInventoryForm"
                >
                    <el-form
                        ref="batchInventoryFormRef"
                        :model="batchInventoryForm"
                        :rules="batchInventoryRules"
                        label-width="120px"
                    >
                        <el-form-item label="日期范围" prop="dateRange">
                            <el-date-picker
                                v-model="batchInventoryForm.dateRange"
                                type="daterange"
                                range-separator="至"
                                start-placeholder="开始日期"
                                end-placeholder="结束日期"
                                style="width: 100%"
                            />
                        </el-form-item>
                        <el-form-item label="总库存" prop="total_quantity">
                            <el-input-number v-model="batchInventoryForm.total_quantity" :min="0" style="width: 100%" />
                        </el-form-item>
                        <el-form-item label="可用库存" prop="available_quantity">
                            <el-input-number v-model="batchInventoryForm.available_quantity" :min="0" style="width: 100%" />
                        </el-form-item>
                    </el-form>
                    <template #footer>
                        <el-button @click="batchInventoryDialogVisible = false">取消</el-button>
                        <el-button type="primary" @click="handleSubmitBatchInventory" :loading="batchInventorySubmitting">确定</el-button>
                    </template>
                </el-dialog>

                <!-- 编辑库存对话框 -->
                <el-dialog
                    v-model="inventoryFormDialogVisible"
                    title="编辑库存"
                    width="500px"
                    append-to-body
                    @close="resetInventoryForm"
                >
                    <el-form
                        ref="inventoryFormRef"
                        :model="inventoryForm"
                        :rules="inventoryRules"
                        label-width="120px"
                    >
                        <el-form-item label="日期">
                            <el-date-picker
                                v-model="inventoryForm.date"
                                type="date"
                                placeholder="选择日期"
                                style="width: 100%"
                                disabled
                            />
                        </el-form-item>
                        <el-form-item label="总库存" prop="total_quantity">
                            <el-input-number v-model="inventoryForm.total_quantity" :min="0" style="width: 100%" />
                        </el-form-item>
                        <el-form-item label="可用库存" prop="available_quantity">
                            <el-input-number v-model="inventoryForm.available_quantity" :min="0" style="width: 100%" />
                        </el-form-item>
                        <el-form-item label="来源">
                            <el-tag :type="inventoryForm.source === 'manual' ? 'primary' : 'success'">
                                {{ inventoryForm.source === 'manual' ? '人工维护' : '接口推送' }}
                            </el-tag>
                            <span style="margin-left: 10px; color: #909399; font-size: 12px;">
                                {{ inventoryForm.source === 'manual' ? '（可编辑）' : '（接口推送，不可编辑）' }}
                            </span>
                        </el-form-item>
                    </el-form>
                    <template #footer>
                        <el-button @click="inventoryFormDialogVisible = false">取消</el-button>
                        <el-button 
                            type="primary" 
                            @click="handleSubmitInventory" 
                            :loading="inventorySubmitting"
                            :disabled="inventoryForm.source === 'api'"
                        >
                            确定
                        </el-button>
                    </template>
                </el-dialog>
            </el-dialog>
        </el-dialog>
    </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';
import axios from '../../utils/axios';
import { ElMessage, ElMessageBox } from 'element-plus';
import { Search } from '@element-plus/icons-vue';
import { useAuthStore } from '../../stores/auth';

const authStore = useAuthStore();

const hotels = ref([]);
const scenicSpots = ref([]);
const loading = ref(false);
const submitting = ref(false);
const dialogVisible = ref(false);
const formRef = ref(null);
const searchKeyword = ref('');
const filterScenicSpotId = ref(null);
const currentPage = ref(1);
const pageSize = ref(15);
const total = ref(0);
const editingId = ref(null);

// 房型管理相关
const roomTypeDialogVisible = ref(false);
const roomTypes = ref([]);
const roomTypeLoading = ref(false);
const currentHotel = ref(null);
const roomTypeFormDialogVisible = ref(false);
const roomTypeFormRef = ref(null);
const roomTypeSubmitting = ref(false);
const editingRoomTypeId = ref(null);

// 库存管理相关
const inventoryDialogVisible = ref(false);
const inventories = ref([]);
const inventoryLoading = ref(false);
const currentRoomType = ref(null);
const inventoryDateRange = ref(null);
const batchInventoryDialogVisible = ref(false);
const batchInventoryFormRef = ref(null);
const batchInventorySubmitting = ref(false);
const inventoryFormDialogVisible = ref(false);
const inventoryFormRef = ref(null);
const inventorySubmitting = ref(false);
const editingInventoryId = ref(null);
const pushingInventories = ref({});

const isEdit = computed(() => editingId.value !== null);
const dialogTitle = computed(() => isEdit.value ? '编辑酒店' : '创建酒店');
const isEditRoomType = computed(() => editingRoomTypeId.value !== null);
const roomTypeFormTitle = computed(() => isEditRoomType.value ? '编辑房型' : '添加房型');

const form = ref({
    scenic_spot_id: null,
    name: '',
    code: '',
    external_code: '',
    address: '',
    contact_phone: '',
    is_connected: false,
    is_active: true,
});

const roomTypeForm = ref({
    name: '',
    code: '',
    external_code: '',
    max_occupancy: 2,
    description: '',
    is_active: true,
});

const batchInventoryForm = ref({
    dateRange: null,
    total_quantity: 0,
    available_quantity: 0,
});

const inventoryForm = ref({
    date: null,
    total_quantity: 0,
    available_quantity: 0,
    source: 'manual',
});

const rules = {
    scenic_spot_id: [
        { required: true, message: '请选择所属景区', trigger: 'change' }
    ],
    name: [
        { required: true, message: '请输入酒店名称', trigger: 'blur' },
        { max: 255, message: '酒店名称不能超过255个字符', trigger: 'blur' }
    ],
    code: [
        { required: true, message: '请输入酒店编码', trigger: 'blur' },
        { pattern: /^[a-zA-Z0-9_-]+$/, message: '酒店编码只能包含字母、数字、下划线和连字符', trigger: 'blur' }
    ],
    contact_phone: [
        { pattern: /^1[3-9]\d{9}$|^0\d{2,3}-?\d{7,8}$/, message: '请输入正确的电话号码', trigger: 'blur' }
    ],
};

const roomTypeRules = {
    name: [
        { required: true, message: '请输入房型名称', trigger: 'blur' }
    ],
    code: [
        { required: true, message: '请输入房型编码', trigger: 'blur' },
        { pattern: /^[a-zA-Z0-9_-]+$/, message: '房型编码只能包含字母、数字、下划线和连字符', trigger: 'blur' }
    ],
    max_occupancy: [
        { required: true, message: '请输入最大入住人数', trigger: 'blur' },
        { type: 'number', min: 1, message: '最大入住人数至少为1', trigger: 'blur' }
    ],
};

const batchInventoryRules = {
    dateRange: [
        { required: true, message: '请选择日期范围', trigger: 'change' }
    ],
    total_quantity: [
        { required: true, message: '请输入总库存', trigger: 'blur' },
        { type: 'number', min: 0, message: '总库存不能小于0', trigger: 'blur' }
    ],
    available_quantity: [
        { required: true, message: '请输入可用库存', trigger: 'blur' },
        { type: 'number', min: 0, message: '可用库存不能小于0', trigger: 'blur' }
    ],
};

const inventoryRules = {
    total_quantity: [
        { required: true, message: '请输入总库存', trigger: 'blur' },
        { type: 'number', min: 0, message: '总库存不能小于0', trigger: 'blur' }
    ],
    available_quantity: [
        { required: true, message: '请输入可用库存', trigger: 'blur' },
        { type: 'number', min: 0, message: '可用库存不能小于0', trigger: 'blur' }
    ],
};

const fetchHotels = async () => {
    loading.value = true;
    try {
        const params = {
            page: currentPage.value,
            per_page: pageSize.value,
        };
        
        if (filterScenicSpotId.value) {
            params.scenic_spot_id = filterScenicSpotId.value;
        }
        
        if (searchKeyword.value) {
            params.search = searchKeyword.value;
        }
        
        const response = await axios.get('/hotels', { params });
        hotels.value = response.data.data || [];
        total.value = response.data.total || 0;
    } catch (error) {
        ElMessage.error('获取酒店列表失败');
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
    fetchHotels();
};

const handleFilter = () => {
    currentPage.value = 1;
    fetchHotels();
};

const handleCreate = () => {
    editingId.value = null;
    resetForm();
    dialogVisible.value = true;
};

const handleEdit = (row) => {
    editingId.value = row.id;
    form.value = {
        scenic_spot_id: row.scenic_spot_id,
        name: row.name,
        code: row.code,
        external_code: row.external_code || '',
        address: row.address || '',
        contact_phone: row.contact_phone || '',
        is_connected: row.is_connected || false,
        is_active: row.is_active,
    };
    dialogVisible.value = true;
};

const handleSubmit = async () => {
    if (!formRef.value) return;
    
    await formRef.value.validate(async (valid) => {
        if (valid) {
            submitting.value = true;
            try {
                if (isEdit.value) {
                    await axios.put(`/hotels/${editingId.value}`, form.value);
                    ElMessage.success('酒店更新成功');
                } else {
                    await axios.post('/hotels', form.value);
                    ElMessage.success('酒店创建成功');
                }
                dialogVisible.value = false;
                fetchHotels();
            } catch (error) {
                const message = error.response?.data?.message || error.response?.data?.errors?.code?.[0] || '操作失败';
                ElMessage.error(message);
            } finally {
                submitting.value = false;
            }
        }
    });
};

const handleDelete = async (row) => {
    try {
        await ElMessageBox.confirm(
            `确定要删除酒店"${row.name}"吗？删除后无法恢复！`,
            '提示',
            {
                type: 'warning',
                confirmButtonText: '确定删除',
                cancelButtonText: '取消'
            }
        );
        
        await axios.delete(`/hotels/${row.id}`);
        ElMessage.success('删除成功');
        fetchHotels();
    } catch (error) {
        if (error !== 'cancel') {
            ElMessage.error('删除失败');
            console.error(error);
        }
    }
};

const resetForm = () => {
    form.value = {
        scenic_spot_id: null,
        name: '',
        code: '',
        external_code: '',
        address: '',
        contact_phone: '',
        is_connected: false,
        is_active: true,
    };
    formRef.value?.clearValidate();
};

// 房型管理
const handleManageRoomTypes = async (hotel) => {
    currentHotel.value = hotel;
    roomTypeDialogVisible.value = true;
    await fetchRoomTypes(hotel.id);
};

const fetchRoomTypes = async (hotelId) => {
    roomTypeLoading.value = true;
    try {
        const response = await axios.get('/room-types', { params: { hotel_id: hotelId } });
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
        external_code: row.external_code || '',
        max_occupancy: row.max_occupancy || 2,
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
                    hotel_id: currentHotel.value.id,
                };
                
                if (isEditRoomType.value) {
                    await axios.put(`/room-types/${editingRoomTypeId.value}`, data);
                    ElMessage.success('房型更新成功');
                } else {
                    await axios.post('/room-types', data);
                    ElMessage.success('房型创建成功');
                }
                roomTypeFormDialogVisible.value = false;
                await fetchRoomTypes(currentHotel.value.id);
            } catch (error) {
                const message = error.response?.data?.message || error.response?.data?.errors?.code?.[0] || '操作失败';
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
        
        await axios.delete(`/room-types/${row.id}`);
        ElMessage.success('删除成功');
        await fetchRoomTypes(currentHotel.value.id);
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
        external_code: '',
        max_occupancy: 2,
        description: '',
        is_active: true,
    };
    roomTypeFormRef.value?.clearValidate();
};

const resetRoomTypeDialog = () => {
    currentHotel.value = null;
    roomTypes.value = [];
    editingRoomTypeId.value = null;
};

// 库存管理
const handleManageInventory = async (roomType) => {
    currentRoomType.value = roomType;
    inventoryDialogVisible.value = true;
    await fetchInventories(roomType.id);
};

const fetchInventories = async (roomTypeId) => {
    inventoryLoading.value = true;
    try {
        const params = { room_type_id: roomTypeId };
        
        if (inventoryDateRange.value && inventoryDateRange.value.length === 2) {
            params.start_date = formatDate(inventoryDateRange.value[0]);
            params.end_date = formatDate(inventoryDateRange.value[1]);
        }
        
        const response = await axios.get('/inventories', { params });
        inventories.value = response.data.data || [];
    } catch (error) {
        ElMessage.error('获取库存列表失败');
        console.error(error);
    } finally {
        inventoryLoading.value = false;
    }
};

const handleInventoryDateRangeChange = () => {
    if (currentRoomType.value) {
        fetchInventories(currentRoomType.value.id);
    }
};

const handleBatchAddInventory = () => {
    resetBatchInventoryForm();
    batchInventoryDialogVisible.value = true;
};

const handleSubmitBatchInventory = async () => {
    if (!batchInventoryFormRef.value) return;
    
    await batchInventoryFormRef.value.validate(async (valid) => {
        if (valid) {
            batchInventorySubmitting.value = true;
            try {
                const [startDate, endDate] = batchInventoryForm.value.dateRange;
                const inventories = [];
                
                // 生成日期范围内的所有日期
                const currentDate = new Date(startDate);
                const end = new Date(endDate);
                
                while (currentDate <= end) {
                    inventories.push({
                        date: formatDate(currentDate),
                        total_quantity: batchInventoryForm.value.total_quantity,
                        available_quantity: batchInventoryForm.value.available_quantity,
                    });
                    currentDate.setDate(currentDate.getDate() + 1);
                }
                
                await axios.post('/inventories', {
                    room_type_id: currentRoomType.value.id,
                    inventories: inventories,
                });
                
                ElMessage.success('批量添加库存成功');
                batchInventoryDialogVisible.value = false;
                await fetchInventories(currentRoomType.value.id);
            } catch (error) {
                const message = error.response?.data?.message || '操作失败';
                ElMessage.error(message);
            } finally {
                batchInventorySubmitting.value = false;
            }
        }
    });
};

const handleEditInventory = (row) => {
    // 接口推送的库存不可编辑
    if (row.source === 'api') {
        ElMessage.warning('接口推送的库存不可编辑');
        return;
    }
    
    editingInventoryId.value = row.id;
    inventoryForm.value = {
        date: row.date,
        total_quantity: row.total_quantity,
        available_quantity: row.available_quantity,
        source: row.source,
    };
    inventoryFormDialogVisible.value = true;
};

const handleSubmitInventory = async () => {
    if (!inventoryFormRef.value) return;
    
    await inventoryFormRef.value.validate(async (valid) => {
        if (valid) {
            inventorySubmitting.value = true;
            try {
                await axios.put(`/inventories/${editingInventoryId.value}`, {
                    total_quantity: inventoryForm.value.total_quantity,
                    available_quantity: inventoryForm.value.available_quantity,
                });
                ElMessage.success('库存更新成功');
                inventoryFormDialogVisible.value = false;
                await fetchInventories(currentRoomType.value.id);
            } catch (error) {
                const message = error.response?.data?.message || '操作失败';
                ElMessage.error(message);
            } finally {
                inventorySubmitting.value = false;
            }
        }
    });
};

const handleToggleInventoryStatus = async (row) => {
    try {
        const action = row.is_closed ? 'open' : 'close';
        await axios.post(`/inventories/${row.id}/${action}`);
        ElMessage.success(row.is_closed ? '库存已开启' : '库存已关闭');
        await fetchInventories(currentRoomType.value.id);
    } catch (error) {
        ElMessage.error('操作失败');
        console.error(error);
    }
};

const handlePushInventoryToOta = async (row) => {
    try {
        pushingInventories.value[row.id] = true;
        
        const response = await axios.post(`/inventories/${row.id}/push-to-ota`);
        
        if (response.data.success) {
            ElMessage.success('推送任务已提交，正在后台处理中');
        } else {
            ElMessage.error(response.data.message || '推送失败');
        }
    } catch (error) {
        const message = error.response?.data?.message || '推送失败';
        ElMessage.error(message);
        console.error(error);
    } finally {
        pushingInventories.value[row.id] = false;
    }
};

const resetBatchInventoryForm = () => {
    batchInventoryForm.value = {
        dateRange: null,
        total_quantity: 0,
        available_quantity: 0,
    };
    batchInventoryFormRef.value?.clearValidate();
};

const resetInventoryForm = () => {
    inventoryForm.value = {
        date: null,
        total_quantity: 0,
        available_quantity: 0,
        source: 'manual',
    };
    inventoryFormRef.value?.clearValidate();
    editingInventoryId.value = null;
};

const resetInventoryDialog = () => {
    currentRoomType.value = null;
    inventories.value = [];
    inventoryDateRange.value = null;
    editingInventoryId.value = null;
};

const formatDate = (date) => {
    if (!date) return '';
    const d = new Date(date);
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
};

onMounted(() => {
    fetchHotels();
    fetchScenicSpots();
});
</script>

<style scoped>
h2 {
    margin-bottom: 20px;
}
</style>
