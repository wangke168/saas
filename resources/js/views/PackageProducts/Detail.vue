<template>
    <div>
        <div style="margin-bottom: 20px;">
            <el-button @click="handleBack">返回列表</el-button>
            <el-button type="primary" @click="handleEdit" style="margin-left: 10px;">编辑</el-button>
        </div>
        
        <h2>打包产品详情</h2>
        <el-card>
            <el-skeleton v-if="loading" :rows="10" animated />
            
            <template v-else-if="packageProduct">
                <el-descriptions :column="2" border>
                    <el-descriptions-item label="产品ID">{{ packageProduct.id }}</el-descriptions-item>
                    <el-descriptions-item label="产品编码">{{ packageProduct.code }}</el-descriptions-item>
                    <el-descriptions-item label="产品名称" :span="2">{{ packageProduct.name }}</el-descriptions-item>
                    <el-descriptions-item label="所属景区">{{ packageProduct.scenic_spot?.name }}</el-descriptions-item>
                    <el-descriptions-item label="入住天数">{{ packageProduct.stay_days }}晚</el-descriptions-item>
                    <el-descriptions-item label="状态">
                        <el-tag :type="packageProduct.is_active ? 'success' : 'danger'">
                            {{ packageProduct.is_active ? '启用' : '禁用' }}
                        </el-tag>
                    </el-descriptions-item>
                    <el-descriptions-item label="创建时间">{{ formatDate(packageProduct.created_at) }}</el-descriptions-item>
                    <el-descriptions-item label="外部产品编码" :span="2">
                        {{ packageProduct.external_code || '无' }}
                    </el-descriptions-item>
                    <el-descriptions-item label="产品描述" :span="2">
                        {{ packageProduct.description || '无' }}
                    </el-descriptions-item>
                </el-descriptions>

                <el-divider>打包配置</el-divider>

                <el-descriptions v-if="packageProduct.package_product" :column="2" border>
                    <el-descriptions-item label="门票产品" :span="2">
                        {{ packageProduct.package_product.ticket_product?.name }}
                        <span style="color: #909399; margin-left: 10px;">
                            ({{ packageProduct.package_product.ticket_product?.code }})
                        </span>
                    </el-descriptions-item>
                    <el-descriptions-item label="酒店产品" :span="2">
                        {{ packageProduct.package_product.hotel_product?.name }}
                        <span style="color: #909399; margin-left: 10px;">
                            ({{ packageProduct.package_product.hotel_product?.code }})
                        </span>
                    </el-descriptions-item>
                    <el-descriptions-item label="酒店">
                        {{ packageProduct.package_product.hotel?.name }}
                    </el-descriptions-item>
                    <el-descriptions-item label="房型">
                        {{ packageProduct.package_product.room_type?.name }}
                    </el-descriptions-item>
                    <el-descriptions-item label="资源服务类型" :span="2">
                        {{ packageProduct.package_product.resource_service_type || '未设置' }}
                    </el-descriptions-item>
                </el-descriptions>

                <el-divider>价格信息</el-divider>

                <el-alert
                    title="价格信息"
                    type="info"
                    :closable="false"
                    style="margin-bottom: 20px;"
                >
                    <template #default>
                        打包产品价格 = 门票价格 + 酒店价格<br>
                        打包产品库存 = 酒店库存<br>
                        价格和库存会根据门票和酒店产品的变化自动计算
                    </template>
                </el-alert>
            </template>
            
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

const packageProduct = ref(null);
const loading = ref(false);

const fetchPackageProduct = async () => {
    loading.value = true;
    try {
        const response = await axios.get(`/package-products/${route.params.id}`);
        packageProduct.value = response.data?.data || response.data;
        
        if (!packageProduct.value) {
            ElMessage.error('产品不存在');
            router.push('/package-products');
        }
    } catch (error) {
        ElMessage.error('获取产品详情失败');
        console.error(error);
        router.push('/package-products');
    } finally {
        loading.value = false;
    }
};

const formatDate = (date) => {
    if (!date) return '-';
    return new Date(date).toLocaleString('zh-CN');
};

const handleBack = () => {
    router.push('/package-products');
};

const handleEdit = () => {
    router.push(`/package-products/${route.params.id}/edit`);
};

onMounted(() => {
    fetchPackageProduct();
});
</script>

<style scoped>
</style>
