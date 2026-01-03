<template>
    <div>
        <div style="margin-bottom: 20px;">
            <el-button @click="handleBack">返回列表</el-button>
        </div>
        
        <h2>创建打包产品</h2>
        <el-card>
            <el-form
                ref="formRef"
                :model="form"
                :rules="rules"
                label-width="140px"
                style="max-width: 800px;"
            >
                <el-form-item label="所属景区" prop="scenic_spot_id">
                    <el-select
                        v-model="form.scenic_spot_id"
                        placeholder="请选择景区"
                        style="width: 100%;"
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

                <el-form-item label="产品名称" prop="name">
                    <el-input v-model="form.name" placeholder="请输入产品名称" />
                    <div style="margin-top: 5px; color: #909399; font-size: 12px;">
                        建议格式：门票名称 + 酒店名称 + 房型名称
                    </div>
                </el-form-item>

                <el-form-item label="产品编码" prop="code">
                    <el-input v-model="form.code" placeholder="留空自动生成" />
                    <div style="margin-top: 5px; color: #909399; font-size: 12px;">
                        留空将自动生成唯一编码
                    </div>
                </el-form-item>

                <el-form-item label="外部产品编码" prop="external_code">
                    <el-input v-model="form.external_code" placeholder="可选，用于和景区系统对接" />
                </el-form-item>

                <el-form-item label="门票产品" prop="ticket_product_id">
                    <el-select
                        v-model="form.ticket_product_id"
                        placeholder="请先选择景区，然后选择门票产品"
                        style="width: 100%;"
                        filterable
                        :disabled="!form.scenic_spot_id"
                        @change="handleTicketProductChange"
                    >
                        <el-option
                            v-for="product in ticketProducts"
                            :key="product.id"
                            :label="`${product.name} (${product.code})`"
                            :value="product.id"
                        />
                    </el-select>
                    <div style="margin-top: 5px; color: #909399; font-size: 12px;">
                        选择要打包的门票产品
                    </div>
                </el-form-item>

                <el-form-item label="酒店产品" prop="hotel_product_id">
                    <el-select
                        v-model="form.hotel_product_id"
                        placeholder="请选择酒店产品"
                        style="width: 100%;"
                        filterable
                        :disabled="!form.scenic_spot_id"
                        @change="handleHotelProductChange"
                    >
                        <el-option
                            v-for="product in hotelProducts"
                            :key="product.id"
                            :label="`${product.name} (${product.code})`"
                            :value="product.id"
                        />
                    </el-select>
                    <div style="margin-top: 5px; color: #909399; font-size: 12px;">
                        选择要打包的酒店产品
                    </div>
                </el-form-item>

                <el-form-item label="酒店" prop="hotel_id">
                    <el-select
                        v-model="form.hotel_id"
                        placeholder="请先选择酒店产品，然后选择酒店"
                        style="width: 100%;"
                        filterable
                        :disabled="!form.hotel_product_id"
                        @change="handleHotelChange"
                    >
                        <el-option
                            v-for="hotel in hotels"
                            :key="hotel.id"
                            :label="hotel.name"
                            :value="hotel.id"
                        />
                    </el-select>
                </el-form-item>

                <el-form-item label="房型" prop="room_type_id">
                    <el-select
                        v-model="form.room_type_id"
                        placeholder="请先选择酒店，然后选择房型"
                        style="width: 100%;"
                        filterable
                        :disabled="!form.hotel_id"
                        @change="handleRoomTypeChange"
                    >
                        <el-option
                            v-for="roomType in roomTypes"
                            :key="roomType.id"
                            :label="roomType.name"
                            :value="roomType.id"
                        />
                    </el-select>
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
                    <el-button type="primary" @click="handleSubmit" :loading="submitting">创建</el-button>
                    <el-button @click="handleBack">取消</el-button>
                </el-form-item>
            </el-form>
        </el-card>
    </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { ElMessage } from 'element-plus';
import axios from '../../utils/axios';

const router = useRouter();
const formRef = ref(null);
const submitting = ref(false);

const form = ref({
    scenic_spot_id: null,
    name: '',
    code: '',
    external_code: '',
    description: '',
    ticket_product_id: null,
    hotel_product_id: null,
    hotel_id: null,
    room_type_id: null,
    resource_service_type: '',
    stay_days: null,
    is_active: true,
});

const scenicSpots = ref([]);
const ticketProducts = ref([]);
const hotelProducts = ref([]);
const hotels = ref([]);
const roomTypes = ref([]);

const rules = {
    scenic_spot_id: [
        { required: true, message: '请选择景区', trigger: 'change' },
    ],
    name: [
        { required: true, message: '请输入产品名称', trigger: 'blur' },
    ],
    ticket_product_id: [
        { required: true, message: '请选择门票产品', trigger: 'change' },
    ],
    hotel_product_id: [
        { required: true, message: '请选择酒店产品', trigger: 'change' },
    ],
    hotel_id: [
        { required: true, message: '请选择酒店', trigger: 'change' },
    ],
    room_type_id: [
        { required: true, message: '请选择房型', trigger: 'change' },
    ],
};

// 获取景区列表
const fetchScenicSpots = async () => {
    try {
        const response = await axios.get('/scenic-spots');
        scenicSpots.value = response.data?.data || response.data || [];
    } catch (error) {
        console.error('获取景区列表失败', error);
        ElMessage.error('获取景区列表失败');
    }
};

// 获取门票产品列表
const fetchTicketProducts = async () => {
    if (!form.value.scenic_spot_id) {
        ticketProducts.value = [];
        return;
    }

    try {
        const response = await axios.get('/products', {
            params: {
                scenic_spot_id: form.value.scenic_spot_id,
                product_type: 'ticket',
                is_active: true,
                per_page: 1000, // 获取所有门票产品
            },
        });
        ticketProducts.value = response.data?.data || response.data || [];
    } catch (error) {
        console.error('获取门票产品列表失败', error);
        ElMessage.error('获取门票产品列表失败');
    }
};

// 获取酒店产品列表
const fetchHotelProducts = async () => {
    if (!form.value.scenic_spot_id) {
        hotelProducts.value = [];
        return;
    }

    try {
        const response = await axios.get('/products', {
            params: {
                scenic_spot_id: form.value.scenic_spot_id,
                product_type: 'hotel',
                is_active: true,
                per_page: 1000, // 获取所有酒店产品
            },
        });
        hotelProducts.value = response.data?.data || response.data || [];
    } catch (error) {
        console.error('获取酒店产品列表失败', error);
        ElMessage.error('获取酒店产品列表失败');
    }
};

// 获取酒店列表
const fetchHotels = async () => {
    if (!form.value.scenic_spot_id) {
        hotels.value = [];
        return;
    }

    try {
        const response = await axios.get('/hotels', {
            params: {
                scenic_spot_id: form.value.scenic_spot_id,
            },
        });
        hotels.value = response.data?.data || response.data || [];
    } catch (error) {
        console.error('获取酒店列表失败', error);
        ElMessage.error('获取酒店列表失败');
    }
};

// 获取房型列表
const fetchRoomTypes = async () => {
    if (!form.value.hotel_id) {
        roomTypes.value = [];
        return;
    }

    try {
        const response = await axios.get('/room-types', {
            params: {
                hotel_id: form.value.hotel_id,
            },
        });
        roomTypes.value = response.data?.data || response.data || [];
    } catch (error) {
        console.error('获取房型列表失败', error);
        ElMessage.error('获取房型列表失败');
    }
};

// 景区变化时，重新加载产品列表
const handleScenicSpotChange = () => {
    form.value.ticket_product_id = null;
    form.value.hotel_product_id = null;
    form.value.hotel_id = null;
    form.value.room_type_id = null;
    fetchTicketProducts();
    fetchHotelProducts();
    fetchHotels();
};

// 酒店产品变化时，自动设置默认的stay_days
const handleHotelProductChange = async () => {
    if (form.value.hotel_product_id && !form.value.stay_days) {
        try {
            const response = await axios.get(`/products/${form.value.hotel_product_id}`);
            const hotelProduct = response.data?.data || response.data;
            if (hotelProduct?.stay_days) {
                form.value.stay_days = hotelProduct.stay_days;
            }
        } catch (error) {
            console.error('获取酒店产品详情失败', error);
        }
    }
};

// 酒店变化时，重新加载房型列表
const handleHotelChange = () => {
    form.value.room_type_id = null;
    fetchRoomTypes();
};

// 门票产品、酒店、房型变化时，自动生成产品名称
watch([
    () => form.value.ticket_product_id,
    () => form.value.hotel_product_id,
    () => form.value.hotel_id,
    () => form.value.room_type_id,
], async ([ticketId, hotelProductId, hotelId, roomTypeId]) => {
    if (!form.value.name && ticketId && hotelProductId && hotelId && roomTypeId) {
        try {
            // 获取各个产品的名称
            const [ticketRes, hotelProductRes, hotelRes, roomTypeRes] = await Promise.all([
                axios.get(`/products/${ticketId}`),
                axios.get(`/products/${hotelProductId}`),
                axios.get(`/hotels/${hotelId}`),
                axios.get(`/room-types/${roomTypeId}`),
            ]);

            const ticketName = ticketRes.data?.data?.name || ticketRes.data?.name || '';
            const hotelProductName = hotelProductRes.data?.data?.name || hotelProductRes.data?.name || '';
            const hotelName = hotelRes.data?.data?.name || hotelRes.data?.name || '';
            const roomTypeName = roomTypeRes.data?.data?.name || roomTypeRes.data?.name || '';

            // 生成产品名称
            form.value.name = `${ticketName} + ${hotelProductName} (${hotelName} - ${roomTypeName})`;
        } catch (error) {
            console.error('获取产品信息失败', error);
        }
    }
});

const handleTicketProductChange = () => {
    // 门票产品变化时的处理
};

const handleRoomTypeChange = () => {
    // 房型变化时的处理
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
        await axios.post('/package-products', form.value);
        ElMessage.success('创建成功');
        router.push('/package-products');
    } catch (error) {
        const message = error.response?.data?.message || '创建失败';
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
});
</script>

<style scoped>
</style>
