<template>
    <div>
        <h2>房型管理 - {{ hotelName }}</h2>
        <el-card>
            <div style="margin-bottom: 20px;">
                <el-button type="primary" @click="handleCreate">创建房型</el-button>
                <el-button @click="handleBack">返回酒店列表</el-button>
                <el-input
                    v-model="searchKeyword"
                    placeholder="搜索房型名称或外部ID"
                    style="width: 300px; margin-left: 10px;"
                    clearable
                    @input="handleSearch"
                >
                    <template #prefix>
                        <el-icon><Search /></el-icon>
                    </template>
                </el-input>
            </div>
            
            <el-table :data="roomTypes" v-loading="loading" border>
                <el-table-column prop="code" label="房型编码" width="120" />
                <el-table-column prop="name" label="房型名称" width="200" />
                <el-table-column prop="external_room_id" label="第三方房型ID" width="150">
                    <template #default="{ row }">
                        {{ row.external_room_id || '-' }}
                    </template>
                </el-table-column>
                <el-table-column prop="max_occupancy" label="最大入住人数" width="120" />
                <el-table-column prop="bed_type" label="床型" width="120" />
                <el-table-column prop="room_area" label="房间面积(㎡)" width="120">
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
                <el-table-column label="操作" width="250" fixed="right">
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
                @size-change="fetchRoomTypes"
                @current-change="fetchRoomTypes"
            />
        </el-card>

        <!-- 创建/编辑房型对话框 -->
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
                <el-form-item label="房型名称" prop="name">
                    <el-input v-model="form.name" placeholder="请输入房型名称" />
                </el-form-item>
                <el-form-item 
                    label="第三方房型ID" 
                    prop="external_room_id"
                    :rules="hotelHasProvider ? [{ required: true, message: '该酒店关联了软件服务商，必须填写第三方房型ID', trigger: 'blur' }] : []"
                >
                    <el-input 
                        v-model="form.external_room_id" 
                        placeholder="用于API对接时标识第三方房型（酒店关联软件服务商时必填）" 
                    />
                    <div style="font-size: 12px; color: #909399; margin-top: 5px;">
                        {{ hotelHasProvider ? '该酒店关联了软件服务商，此字段必填' : '可选，用于API对接' }}
                    </div>
                </el-form-item>
                <el-form-item label="最大入住人数" prop="max_occupancy">
                    <el-input-number v-model="form.max_occupancy" :min="1" :max="10" />
                </el-form-item>
                <el-form-item label="床型" prop="bed_type">
                    <el-input v-model="form.bed_type" placeholder="如：双床、大床等" />
                </el-form-item>
                <el-form-item label="房间面积(㎡)" prop="room_area">
                    <el-input-number v-model="form.room_area" :min="0" :precision="2" />
                </el-form-item>
                <el-form-item label="描述" prop="description">
                    <el-input
                        v-model="form.description"
                        type="textarea"
                        :rows="3"
                        placeholder="请输入房型描述"
                    />
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
    </div>
</template>

<script setup>
import { ref, reactive, onMounted, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ElMessage, ElMessageBox } from 'element-plus';
import { Search } from '@element-plus/icons-vue';
import { resRoomTypesApi, resHotelsApi } from '../../api/systemPkg';

const route = useRoute();
const router = useRouter();

const hotelId = computed(() => parseInt(route.params.id));
const hotelName = ref('');
const hotelHasProvider = ref(false);

// 数据
const roomTypes = ref([]);
const loading = ref(false);
const currentPage = ref(1);
const pageSize = ref(15);
const total = ref(0);
const searchKeyword = ref('');

// 对话框
const dialogVisible = ref(false);
const dialogTitle = ref('创建房型');
const isEdit = ref(false);
const editingId = ref(null);
const submitting = ref(false);
const formRef = ref(null);

// 表单数据
const form = reactive({
    hotel_id: null,
    name: '',
    external_room_id: '',
    max_occupancy: 2,
    bed_type: '',
    room_area: null,
    description: '',
    is_active: true,
});

// 表单验证规则
const rules = {
    name: [{ required: true, message: '请输入房型名称', trigger: 'blur' }],
};

// 获取酒店信息
const fetchHotel = async () => {
    try {
        const response = await resHotelsApi.get(hotelId.value);
        const hotel = response.data.data;
        hotelName.value = hotel.name;
        hotelHasProvider.value = !!hotel.software_provider_id;
    } catch (error) {
        console.error('获取酒店信息失败:', error);
        ElMessage.error('获取酒店信息失败');
    }
};

// 获取房型列表
const fetchRoomTypes = async () => {
    loading.value = true;
    try {
        const params = {
            hotel_id: hotelId.value,
            page: currentPage.value,
            per_page: pageSize.value,
        };
        
        if (searchKeyword.value) {
            params.search = searchKeyword.value;
        }
        
        const response = await resRoomTypesApi.list(params);
        roomTypes.value = response.data.data || [];
        total.value = response.data.total || 0;
    } catch (error) {
        console.error('获取房型列表失败:', error);
        ElMessage.error('获取房型列表失败');
    } finally {
        loading.value = false;
    }
};

// 创建房型
const handleCreate = () => {
    isEdit.value = false;
    editingId.value = null;
    dialogTitle.value = '创建房型';
    resetForm();
    form.hotel_id = hotelId.value;
    dialogVisible.value = true;
};

// 编辑房型
const handleEdit = async (row) => {
    isEdit.value = true;
    editingId.value = row.id;
    dialogTitle.value = '编辑房型';
    try {
        const response = await resRoomTypesApi.get(row.id);
        const data = response.data.data;
        Object.assign(form, {
            hotel_id: data.hotel_id,
            name: data.name,
            external_room_id: data.external_room_id || '',
            max_occupancy: data.max_occupancy || 2,
            bed_type: data.bed_type || '',
            room_area: data.room_area || null,
            description: data.description || '',
            is_active: data.is_active,
        });
        dialogVisible.value = true;
    } catch (error) {
        console.error('获取房型详情失败:', error);
        ElMessage.error('获取房型详情失败');
    }
};

// 提交表单
const handleSubmit = async () => {
    if (!formRef.value) return;
    
    await formRef.value.validate(async (valid) => {
        if (!valid) return;
        
        // 如果酒店关联了软件服务商，验证第三方房型ID
        if (hotelHasProvider.value && !form.external_room_id) {
            ElMessage.warning('该酒店关联了软件服务商，必须填写第三方房型ID');
            return;
        }
        
        submitting.value = true;
        try {
            if (isEdit.value) {
                await resRoomTypesApi.update(editingId.value, form);
                ElMessage.success('房型更新成功');
            } else {
                await resRoomTypesApi.create(form);
                ElMessage.success('房型创建成功');
            }
            dialogVisible.value = false;
            fetchRoomTypes();
        } catch (error) {
            console.error('保存房型失败:', error);
            ElMessage.error(error.response?.data?.message || '保存房型失败');
        } finally {
            submitting.value = false;
        }
    });
};

// 删除房型
const handleDelete = async (row) => {
    try {
        await ElMessageBox.confirm('确定要删除该房型吗？', '提示', {
            confirmButtonText: '确定',
            cancelButtonText: '取消',
            type: 'warning',
        });
        
        await resRoomTypesApi.delete(row.id);
        ElMessage.success('房型删除成功');
        fetchRoomTypes();
    } catch (error) {
        if (error !== 'cancel') {
            console.error('删除房型失败:', error);
            ElMessage.error(error.response?.data?.message || '删除房型失败');
        }
    }
};

// 返回
const handleBack = () => {
    router.push('/res-hotels');
};

// 搜索
const handleSearch = () => {
    currentPage.value = 1;
    fetchRoomTypes();
};

// 重置表单
const resetForm = () => {
    Object.assign(form, {
        hotel_id: null,
        name: '',
        external_room_id: '',
        max_occupancy: 2,
        bed_type: '',
        room_area: null,
        description: '',
        is_active: true,
    });
    if (formRef.value) {
        formRef.value.clearValidate();
    }
};

// 初始化
onMounted(() => {
    fetchHotel();
    fetchRoomTypes();
});
</script>

<style scoped>
</style>

