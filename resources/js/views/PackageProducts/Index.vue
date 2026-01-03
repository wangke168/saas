<template>
    <div>
        <h2>打包产品管理</h2>
        <el-card>
            <div style="margin-bottom: 20px;">
                <el-button type="primary" @click="handleCreate">创建打包产品</el-button>
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
                <el-select
                    v-model="filterStatus"
                    placeholder="筛选状态"
                    clearable
                    style="width: 150px; margin-left: 10px;"
                    @change="handleFilter"
                >
                    <el-option label="启用" :value="true" />
                    <el-option label="禁用" :value="false" />
                </el-select>
                <el-input
                    v-model="searchKeyword"
                    placeholder="搜索产品名称或编码"
                    style="width: 300px; margin-left: 10px;"
                    clearable
                    @input="handleSearch"
                >
                    <template #prefix>
                        <el-icon><Search /></el-icon>
                    </template>
                </el-input>
            </div>
            
            <el-table :data="packageProducts" v-loading="loading" border>
                <el-table-column prop="id" label="ID" width="80" />
                <el-table-column prop="name" label="产品名称" min-width="200" show-overflow-tooltip />
                <el-table-column prop="code" label="产品编码" width="150" />
                <el-table-column label="所属景区" width="150">
                    <template #default="{ row }">
                        {{ row.scenic_spot?.name || '-' }}
                    </template>
                </el-table-column>
                <el-table-column label="门票产品" width="180" show-overflow-tooltip>
                    <template #default="{ row }">
                        {{ row.package_product?.ticket_product?.name || '-' }}
                    </template>
                </el-table-column>
                <el-table-column label="酒店产品" width="180" show-overflow-tooltip>
                    <template #default="{ row }">
                        {{ row.package_product?.hotel_product?.name || '-' }}
                    </template>
                </el-table-column>
                <el-table-column label="酒店" width="150" show-overflow-tooltip>
                    <template #default="{ row }">
                        {{ row.package_product?.hotel?.name || '-' }}
                    </template>
                </el-table-column>
                <el-table-column label="房型" width="120" show-overflow-tooltip>
                    <template #default="{ row }">
                        {{ row.package_product?.room_type?.name || '-' }}
                    </template>
                </el-table-column>
                <el-table-column label="入住天数" width="100">
                    <template #default="{ row }">
                        {{ row.stay_days }}晚
                    </template>
                </el-table-column>
                <el-table-column prop="is_active" label="状态" width="100">
                    <template #default="{ row }">
                        <el-tag :type="row.is_active ? 'success' : 'danger'">
                            {{ row.is_active ? '启用' : '禁用' }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="created_at" label="创建时间" width="180">
                    <template #default="{ row }">
                        {{ formatDate(row.created_at) }}
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="250" fixed="right">
                    <template #default="{ row }">
                        <el-button link type="primary" @click="handleDetail(row.id)">详情</el-button>
                        <el-button link type="primary" @click="handleEdit(row.id)">编辑</el-button>
                        <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
                    </template>
                </el-table-column>
            </el-table>
            
            <el-pagination
                v-model:current-page="currentPage"
                v-model:page-size="pageSize"
                :total="total"
                :page-sizes="[10, 20, 50, 100]"
                layout="total, sizes, prev, pager, next, jumper"
                style="margin-top: 20px;"
                @size-change="fetchPackageProducts"
                @current-change="fetchPackageProducts"
            />
        </el-card>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { ElMessage, ElMessageBox } from 'element-plus';
import { Search } from '@element-plus/icons-vue';
import axios from '../../utils/axios';

const router = useRouter();

const loading = ref(false);
const packageProducts = ref([]);
const scenicSpots = ref([]);
const filterScenicSpotId = ref(null);
const filterStatus = ref(null);
const searchKeyword = ref('');
const currentPage = ref(1);
const pageSize = ref(15);
const total = ref(0);

const fetchPackageProducts = async () => {
    loading.value = true;
    try {
        const params = {
            page: currentPage.value,
            per_page: pageSize.value,
            product_type: 'package', // 只查询打包产品
        };
        
        if (filterScenicSpotId.value) {
            params.scenic_spot_id = filterScenicSpotId.value;
        }
        
        if (filterStatus.value !== null) {
            params.is_active = filterStatus.value;
        }
        
        if (searchKeyword.value) {
            params.search = searchKeyword.value;
        }
        
        const response = await axios.get('/package-products', { params });
        // Laravel 分页器返回的数据结构：{ data: [...], total: ..., per_page: ..., current_page: ... }
        if (response.data && response.data.data) {
            packageProducts.value = response.data.data || [];
            total.value = response.data.total || 0;
        } else {
            packageProducts.value = response.data || [];
            total.value = packageProducts.value.length;
        }
    } catch (error) {
        ElMessage.error('获取打包产品列表失败');
        console.error(error);
    } finally {
        loading.value = false;
    }
};

const fetchScenicSpots = async () => {
    try {
        const response = await axios.get('/scenic-spots');
        scenicSpots.value = response.data || [];
    } catch (error) {
        console.error('获取景区列表失败', error);
    }
};

const handleCreate = () => {
    router.push('/package-products/create');
};

const handleDetail = (id) => {
    router.push(`/package-products/${id}/detail`);
};

const handleEdit = (id) => {
    router.push(`/package-products/${id}/edit`);
};

const handleDelete = async (row) => {
    try {
        await ElMessageBox.confirm(
            `确定要删除打包产品"${row.name}"吗？`,
            '确认删除',
            {
                confirmButtonText: '确定',
                cancelButtonText: '取消',
                type: 'warning',
            }
        );
        
        await axios.delete(`/package-products/${row.id}`);
        ElMessage.success('删除成功');
        fetchPackageProducts();
    } catch (error) {
        if (error !== 'cancel') {
            ElMessage.error('删除失败');
            console.error(error);
        }
    }
};

const handleFilter = () => {
    currentPage.value = 1;
    fetchPackageProducts();
};

const handleSearch = () => {
    currentPage.value = 1;
    fetchPackageProducts();
};

const formatDate = (date) => {
    if (!date) return '-';
    return new Date(date).toLocaleString('zh-CN');
};

onMounted(() => {
    fetchScenicSpots();
    fetchPackageProducts();
});
</script>

<style scoped>
</style>

