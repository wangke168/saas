<template>
    <div>
        <div style="margin-bottom: 20px;">
            <el-button @click="handleBack">返回列表</el-button>
        </div>
        
        <h2>编辑打包产品</h2>
        <el-card>
            <el-skeleton v-if="loading" :rows="10" animated />
            
            <el-form
                v-else-if="form"
                ref="formRef"
                :model="form"
                :rules="rules"
                label-width="140px"
                style="max-width: 800px;"
            >
                <el-form-item label="所属景区">
                    <el-select
                        v-model="form.scenic_spot_id"
                        placeholder="请选择景区"
                        style="width: 100%;"
                        disabled
                    >
                        <el-option
                            v-for="spot in scenicSpots"
                            :key="spot.id"
                            :label="spot.name"
                            :value="spot.id"
                        />
                    </el-select>
                    <div style="margin-top: 5px; color: #909399; font-size: 12px;">
                        景区不可修改
                    </div>
                </el-form-item>

                <el-form-item label="产品名称" prop="name">
                    <el-input v-model="form.name" placeholder="请输入产品名称" />
                </el-form-item>

                <el-form-item label="产品编码">
                    <el-input v-model="form.code" disabled />
                    <div style="margin-top: 5px; color: #909399; font-size: 12px;">
                        产品编码不可修改
                    </div>
                </el-form-item>

                <el-form-item label="外部产品编码" prop="external_code">
                    <el-input v-model="form.external_code" placeholder="可选，用于和景区系统对接" />
                </el-form-item>

                <el-form-item label="门票产品">
                    <el-select
                        v-model="form.ticket_product_id"
                        placeholder="请选择门票产品"
                        style="width: 100%;"
                        disabled
                    >
                        <el-option
                            v-for="product in ticketProducts"
                            :key="product.id"
                            :label="`${product.name} (${product.code})`"
                            :value="product.id"
                        />
                    </el-select>
                    <div style="margin-top: 5px; color: #909399; font-size: 12px;">
                        门票产品不可修改
                    </div>
                </el-form-item>

                <el-form-item label="酒店产品">
                    <el-select
                        v-model="form.hotel_product_id"
                        placeholder="请选择酒店产品"
                        style="width: 100%;"
                        disabled
                    >
                        <el-option
                            v-for="product in hotelProducts"
                            :key="product.id"
                            :label="`${product.name} (${product.code})`"
                            :value="product.id"
                        />
                    </el-select>
                    <div style="margin-top: 5px; color: #909399; font-size: 12px;">
                        酒店产品不可修改
                    </div>
                </el-form-item>

                <el-form-item label="酒店">
                    <el-select
                        v-model="form.hotel_id"
                        placeholder="请选择酒店"
                        style="width: 100%;"
                        disabled
                    >
                        <el-option
                            v-for="hotel in hotels"
                            :key="hotel.id"
                            :label="hotel.name"
                            :value="hotel.id"
                        />
                    </el-select>
                    <div style="margin-top: 5px; color: #909399; font-size: 12px;">
                        酒店不可修改
                    </div>
                </el-form-item>

                <el-form-item label="房型">
                    <el-select
                        v-model="form.room_type_id"
                        placeholder="请选择房型"
                        style="width: 100%;"
                        disabled
                    >
                        <el-option
                            v-for="roomType in roomTypes"
                            :key="roomType.id"
                            :label="roomType.name"
                            :value="roomType.id"
                        />
                    </el-select>
                    <div style="margin-top: 5px; color: #909399; font-size: 12px;">
                        房型不可修改
                    </div>
                </el-form-item>

                <el-form-item label="入住天数" prop="stay_days">
                    <el-input-number
                        v-model="form.stay_days"
                        :min="1"
                        :max="30"
                        placeholder="留空则使用酒店产品的入住天数"
                    />
                    <div style="margin-left: 10px; color: #909399; font-size: 12px;">
                        留空则自动使用酒店产品的入住天数
                    </div>
                </el-form-item>

                <el-form-item label="资源服务类型" prop="resource_service_type">
                    <el-input v-model="form.resource_service_type" placeholder="可选，用于标识酒店对接的系统类型" />
                </el-form-item>

                <el-form-item label="产品描述" prop="description">
                    <el-input
                        v-model="form.description"
                        type="textarea"
                        :rows="4"
                        placeholder="请输入产品描述"
                    />
                </el-form-item>

                <el-form-item label="状态" prop="is_active">
                    <el-switch v-model="form.is_active" />
                </el-form-item>

                <el-form-item>
                    <el-button type="primary" @click="handleSubmit" :loading="submitting">保存</el-button>
                    <el-button @click="handleBack">取消</el-button>
                </el-form-item>
            </el-form>

            <el-empty v-else description="产品不存在" />
        </el-card>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { ElMessage } from 'element-plus';
import axios from '../../utils/axios';

const router = useRouter();
const route = useRoute();
const formRef = ref(null);
const submitting = ref(false);
const loading = ref(false);

const form = ref(null);

const scenicSpots = ref([]);
const ticketProducts = ref([]);
const hotelProducts = ref([]);
const hotels = ref([]);
const roomTypes = ref([]);

const rules = {
    name: [
        { required: true, message: '请输入产品名称', trigger: 'blur' },
    ],
};

// 获取景区列表
const fetchScenicSpots = async () => {
    try {
        const response = await axios.get('/scenic-spots');
        scenicSpots.value = response.data?.data || response.data || [];
    } catch (error) {
        console.error('获取景区列表失败', error);
    }
};

// 获取打包产品详情
const fetchPackageProduct = async () => {
    loading.value = true;
    try {
        const response = await axios.get(`/package-products/${route.params.id}`);
        const product = response.data?.data || response.data;
        
        if (!product) {
            ElMessage.error('产品不存在');
            router.push('/package-products');
            return;
        }

        // 构建表单数据
        const packageConfig = product.package_product;
        form.value = {
            scenic_spot_id: product.scenic_spot_id,
            name: product.name,
            code: product.code,
            external_code: product.external_code || '',
            description: product.description || '',
            ticket_product_id: packageConfig?.ticket_product_id || null,
            hotel_product_id: packageConfig?.hotel_product_id || null,
            hotel_id: packageConfig?.hotel_id || null,
            room_type_id: packageConfig?.room_type_id || null,
            resource_service_type: packageConfig?.resource_service_type || '',
            stay_days: product.stay_days,
            is_active: product.is_active,
        };

        // 加载关联数据
        if (form.value.scenic_spot_id) {
            await Promise.all([
                fetchTicketProducts(),
                fetchHotelProducts(),
                fetchHotels(),
            ]);
        }

        if (form.value.hotel_id) {
            await fetchRoomTypes();
        }
    } catch (error) {
        ElMessage.error('获取产品信息失败');
        console.error(error);
        router.push('/package-products');
    } finally {
        loading.value = false;
    }
};

// 获取门票产品列表
const fetchTicketProducts = async () => {
    if (!form.value?.scenic_spot_id) return;

    try {
        const response = await axios.get('/products', {
            params: {
                scenic_spot_id: form.value.scenic_spot_id,
                product_type: 'ticket',
                per_page: 1000,
            },
        });
        ticketProducts.value = response.data?.data || response.data || [];
    } catch (error) {
        console.error('获取门票产品列表失败', error);
    }
};

// 获取酒店产品列表
const fetchHotelProducts = async () => {
    if (!form.value?.scenic_spot_id) return;

    try {
        const response = await axios.get('/products', {
            params: {
                scenic_spot_id: form.value.scenic_spot_id,
                product_type: 'hotel',
                per_page: 1000,
            },
        });
        hotelProducts.value = response.data?.data || response.data || [];
    } catch (error) {
        console.error('获取酒店产品列表失败', error);
    }
};

// 获取酒店列表
const fetchHotels = async () => {
    if (!form.value?.scenic_spot_id) return;

    try {
        const response = await axios.get('/hotels', {
            params: {
                scenic_spot_id: form.value.scenic_spot_id,
            },
        });
        hotels.value = response.data?.data || response.data || [];
    } catch (error) {
        console.error('获取酒店列表失败', error);
    }
};

// 获取房型列表
const fetchRoomTypes = async () => {
    if (!form.value?.hotel_id) return;

    try {
        const response = await axios.get('/room-types', {
            params: {
                hotel_id: form.value.hotel_id,
            },
        });
        roomTypes.value = response.data?.data || response.data || [];
    } catch (error) {
        console.error('获取房型列表失败', error);
    }
};

const handleSubmit = async () => {
    if (!formRef.value) {
        return;
    }

    try {
        await formRef.value.validate();
    } catch (error) {
        return;
    }

    submitting.value = true;
    try {
        await axios.put(`/package-products/${route.params.id}`, form.value);
        ElMessage.success('更新成功');
        router.push('/package-products');
    } catch (error) {
        const message = error.response?.data?.message || '更新失败';
        ElMessage.error(message);
        console.error(error);
    } finally {
        submitting.value = false;
    }
};

const handleBack = () => {
    router.back();
};

onMounted(() => {
    fetchScenicSpots();
    fetchPackageProduct();
});
</script>

<style scoped>
</style>
