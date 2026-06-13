<template>
    <div>
        <h2>产品管理</h2>
        <el-card>
            <div style="margin-bottom: 20px;">
                <el-button type="primary" @click="handleCreate">创建产品</el-button>
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
                <el-select
                    v-model="filterFulfillmentMode"
                    placeholder="履约模式"
                    clearable
                    style="width: 180px; margin-left: 10px;"
                    @change="handleFilter"
                >
                    <el-option label="落单即履约" value="immediate" />
                    <el-option label="小程序预约后履约" value="deferred" />
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
            
            <el-table :data="products" v-loading="loading" border>
                <el-table-column prop="name" label="产品名称" width="200" />
                <el-table-column prop="code" label="产品编码" width="150" />
                <el-table-column prop="external_code" label="外部产品编码" width="150" />
                <el-table-column label="所属景区" width="150">
                    <template #default="{ row }">
                        {{ row.scenic_spot?.name || '-' }}
                    </template>
                </el-table-column>
                <el-table-column prop="description" label="描述" show-overflow-tooltip />
                <el-table-column prop="fulfillment_mode" label="履约模式" width="150">
                    <template #default="{ row }">
                        <el-tag :type="row.fulfillment_mode === 'deferred' ? 'warning' : 'info'">
                            {{ fulfillmentModeLabel(row.fulfillment_mode) }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="price_source" label="价格来源" width="120">
                    <template #default="{ row }">
                        <el-tag :type="row.price_source === 'manual' ? 'primary' : 'success'">
                            {{ row.price_source === 'manual' ? '人工维护' : '接口推送' }}
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
                <el-table-column prop="created_at" label="创建时间" width="180">
                    <template #default="{ row }">
                        {{ formatDate(row.created_at) }}
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="420" fixed="right">
                    <template #default="{ row }">
                        <el-button size="small" @click="handleViewDetail(row)">详情</el-button>
                        <el-button size="small" @click="handleEdit(row)">编辑</el-button>
                        <el-button size="small" type="primary" plain @click="handleDuplicate(row)">复制</el-button>
                        <el-button size="small" type="success" @click="handleExport(row)" :loading="exporting[row.id]">
                            <el-icon><Download /></el-icon>
                            导出
                        </el-button>
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
                @size-change="fetchProducts"
                @current-change="fetchProducts"
            />
        </el-card>

    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import axios from '../../utils/axios';
import { ElMessage, ElMessageBox } from 'element-plus';
import { Search, Download } from '@element-plus/icons-vue';
import { useAuthStore } from '../../stores/auth';

const authStore = useAuthStore();
const router = useRouter();

const products = ref([]);
const scenicSpots = ref([]);
const loading = ref(false);
const exporting = ref({}); // 改为对象，记录每个产品的导出状态
const searchKeyword = ref('');
const filterScenicSpotId = ref(null);
const filterStatus = ref(null);
const filterFulfillmentMode = ref(null);
const currentPage = ref(1);
const pageSize = ref(15);
const total = ref(0);

const fetchProducts = async () => {
    loading.value = true;
    try {
        const params = {
            page: currentPage.value,
            per_page: pageSize.value,
        };
        
        if (filterScenicSpotId.value) {
            params.scenic_spot_id = filterScenicSpotId.value;
        }
        
        if (filterStatus.value !== null) {
            params.is_active = filterStatus.value;
        }

        if (filterFulfillmentMode.value) {
            params.fulfillment_mode = filterFulfillmentMode.value;
        }
        
        if (searchKeyword.value) {
            params.search = searchKeyword.value;
        }
        
        const response = await axios.get('/products', { params });
        // Laravel 分页器返回的数据结构：{ data: [...], total: ..., per_page: ..., current_page: ... }
        if (response.data && response.data.data) {
            products.value = response.data.data || [];
            total.value = response.data.total || 0;
        } else {
            // 兼容不同的返回格式
            products.value = response.data || [];
            total.value = products.value.length;
        }
    } catch (error) {
        ElMessage.error('获取产品列表失败');
        console.error(error);
    } finally {
        loading.value = false;
    }
};

const fetchScenicSpots = async () => {
    try {
        // 如果是运营，直接使用用户绑定的景区
        if (authStore.user?.role !== 'admin') {
            // 确保用户信息已加载
            if (!authStore.user || !authStore.user.scenic_spots || authStore.user.scenic_spots.length === 0) {
                await authStore.fetchUser();
            }
            scenicSpots.value = authStore.user?.scenic_spots || [];
            
            // 如果还是没有景区，提示用户
            if (scenicSpots.value.length === 0) {
                console.warn('运营用户未绑定任何景区');
            }
        } else {
            // 超级管理员获取所有景区
            const response = await axios.get('/scenic-spots');
            scenicSpots.value = response.data.data || [];
        }
    } catch (error) {
        console.error('获取景区列表失败', error);
        // 如果API失败，尝试使用用户绑定的景区
        if (authStore.user?.scenic_spots && authStore.user.scenic_spots.length > 0) {
            scenicSpots.value = authStore.user.scenic_spots;
        } else {
            // 如果还是没有，尝试重新获取用户信息
            try {
                await authStore.fetchUser();
                scenicSpots.value = authStore.user?.scenic_spots || [];
            } catch (e) {
                console.error('获取用户信息失败', e);
            }
        }
    }
};

const handleSearch = () => {
    currentPage.value = 1;
    fetchProducts();
};

const handleFilter = () => {
    currentPage.value = 1;
    fetchProducts();
};

const handleCreate = async () => {
    if (scenicSpots.value.length === 0) {
        await fetchScenicSpots();
    }
    if (authStore.user?.role !== 'admin' && scenicSpots.value.length === 0) {
        ElMessage.warning('您未绑定任何景区，请联系管理员为您分配景区');
        return;
    }
    router.push('/products/create');
};

const handleViewDetail = (row) => {
    // 使用路由跳转到产品详情页面
    router.push(`/products/${row.id}/detail`);
};

const handleEdit = (row) => {
    router.push(`/products/${row.id}/edit`);
};

const handleDuplicate = (row) => {
    router.push({ path: '/products/create', query: { duplicate_from: row.id } });
};

const fulfillmentModeLabel = (mode) => {
    if (mode === 'deferred') {
        return '预约后履约';
    }
    return '落单即履约';
};

const handleDelete = async (row) => {
    try {
        await ElMessageBox.confirm(
            `确定要删除产品"${row.name}"吗？删除后无法恢复！`,
            '提示',
            {
                type: 'warning',
                confirmButtonText: '确定删除',
                cancelButtonText: '取消'
            }
        );
        
        await axios.delete(`/products/${row.id}`);
        ElMessage.success('删除成功');
        fetchProducts();
    } catch (error) {
        if (error !== 'cancel') {
            const message = error.response?.data?.message || '删除失败';
            ElMessage.error(message);
        }
    }
};

const formatDate = (date) => {
    if (!date) return '';
    return new Date(date).toLocaleString('zh-CN', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const handleExport = async (product) => {
    exporting.value[product.id] = true;
    try {
        const response = await axios.get(`/products/${product.id}/export`, {
            responseType: 'blob',
            // 处理错误响应（可能是 JSON 格式）
            validateStatus: (status) => status < 500, // 允许 4xx 状态码
        });
        
        // 检查响应类型
        const contentType = response.headers['content-type'] || '';
        
        // 如果是 JSON 格式，说明是错误响应
        if (contentType.includes('application/json') || response.status >= 400) {
            // 尝试解析 JSON 错误信息
            const text = await response.data.text();
            let errorMessage = '导出失败';
            
            try {
                const errorData = JSON.parse(text);
                errorMessage = errorData.message || errorMessage;
            } catch (e) {
                // 如果解析失败，使用默认消息
                errorMessage = text || errorMessage;
            }
            
            ElMessage.error(errorMessage);
            console.error('导出失败', errorMessage);
            return;
        }
        
        // 创建下载链接
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `product_${product.code}_${new Date().toISOString().slice(0, 10)}.csv`);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
        
        ElMessage.success('导出成功');
    } catch (error) {
        // 处理网络错误或其他异常
        let message = '导出失败';
        
        if (error.response) {
            // 尝试获取错误消息
            if (error.response.data) {
                if (typeof error.response.data === 'string') {
                    try {
                        const errorData = JSON.parse(error.response.data);
                        message = errorData.message || message;
                    } catch (e) {
                        message = error.response.data || message;
                    }
                } else if (error.response.data.message) {
                    message = error.response.data.message;
                }
            }
        } else if (error.message) {
            message = error.message;
        }
        
        ElMessage.error(message);
        console.error('导出失败', error);
    } finally {
        exporting.value[product.id] = false;
    }
};

onMounted(() => {
    fetchProducts();
    fetchScenicSpots();
});
</script>

<style scoped>
h2 {
    margin-bottom: 20px;
}
</style>
