<template>
    <div>
        <el-card>
            <template #header>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h2>打包产品详情</h2>
                    <el-button @click="goBack">返回列表</el-button>
                </div>
            </template>

            <div v-loading="loading">
                <el-descriptions :column="2" border v-if="product">
                    <el-descriptions-item label="产品名称">{{ product.product_name }}</el-descriptions-item>
                    <el-descriptions-item label="产品编码">{{ product.product_code }}</el-descriptions-item>
                    <el-descriptions-item label="所属景区">{{ product.scenic_spot?.name || '-' }}</el-descriptions-item>
                    <el-descriptions-item label="入住天数">{{ product.stay_days || 1 }}</el-descriptions-item>
                    <el-descriptions-item label="状态">
                        <el-tag :type="product.status === 1 ? 'success' : 'danger'">
                            {{ product.status === 1 ? '启用' : '禁用' }}
                        </el-tag>
                    </el-descriptions-item>
                    <el-descriptions-item label="销售开始日期">
                        {{ product.sale_start_date || '不限制' }}
                    </el-descriptions-item>
                    <el-descriptions-item label="销售结束日期">
                        {{ product.sale_end_date || '不限制' }}
                    </el-descriptions-item>
                    <el-descriptions-item label="创建时间">{{ formatDate(product.created_at) }}</el-descriptions-item>
                    <el-descriptions-item label="描述" :span="2">
                        {{ product.description || '-' }}
                    </el-descriptions-item>
                </el-descriptions>

                <el-divider>关联门票</el-divider>
                <el-table :data="product?.bundle_items || []" border style="margin-bottom: 20px;">
                    <el-table-column prop="ticket.name" label="门票名称" />
                    <el-table-column prop="ticket.code" label="门票编码" />
                    <el-table-column prop="quantity" label="数量" width="100" />
                </el-table>

                <el-divider>关联酒店房型</el-divider>
                <el-table :data="product?.hotel_room_types || []" border>
                    <el-table-column label="酒店名称">
                        <template #default="{ row }">
                            {{ row.hotel?.name || '-' }}
                        </template>
                    </el-table-column>
                    <el-table-column label="房型名称">
                        <template #default="{ row }">
                            {{ row.room_type?.name || '-' }}
                        </template>
                    </el-table-column>
                </el-table>
            </div>
        </el-card>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import axios from '../../utils/axios';
import { ElMessage } from 'element-plus';

const route = useRoute();
const router = useRouter();

const loading = ref(false);
const product = ref(null);

const fetchProduct = async () => {
    loading.value = true;
    try {
        const response = await axios.get(`/pkg-products/${route.params.id}`);
        product.value = response.data.data;
    } catch (error) {
        const errorMessage = error.response?.data?.message || error.message || '获取产品详情失败';
        ElMessage.error(errorMessage);
        console.error('获取产品详情失败:', error);
    } finally {
        loading.value = false;
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

const goBack = () => {
    router.push('/pkg-products');
};

onMounted(() => {
    fetchProduct();
});
</script>

<style scoped>
h2 {
    margin: 0;
}
</style>

