<template>
    <div>
        <el-page-header @back="goBack" title="返回订单列表">
            <template #content>
                <span>订单详情 - {{ order.order_no }}</span>
                <el-tag
                    v-if="order.is_presale_fulfillment_child"
                    type="success"
                    size="small"
                    style="margin-left: 8px;"
                >
                    预售子单
                </el-tag>
                <el-tag
                    v-else-if="order.is_presale_parent"
                    type="warning"
                    size="small"
                    style="margin-left: 8px;"
                >
                    预售父单
                </el-tag>
            </template>
        </el-page-header>

        <el-card v-loading="loading" style="margin-top: 20px;">
            <div v-if="order.id">
                <!-- 订单基本信息 -->
                <el-descriptions title="订单信息" :column="2" border style="margin-bottom: 20px;">
                    <el-descriptions-item label="订单号">{{ order.order_no }}</el-descriptions-item>
                    <el-descriptions-item label="OTA订单号">{{ order.ota_order_no || '-' }}</el-descriptions-item>
                    <el-descriptions-item label="OTA平台">{{ order.ota_platform?.name || '-' }}</el-descriptions-item>
                    <el-descriptions-item label="订单状态">
                        <el-tag :type="getStatusType(statusValue)">
                            {{ getStatusLabel(statusValue) }}
                        </el-tag>
                    </el-descriptions-item>
                    <el-descriptions-item label="产品名称">{{ order.product?.name || '-' }}</el-descriptions-item>
                    <el-descriptions-item label="所属景区">{{ order.product?.scenic_spot?.name || '-' }}</el-descriptions-item>
                    <el-descriptions-item label="酒店">{{ order.hotel?.name || '-' }}</el-descriptions-item>
                    <el-descriptions-item label="房型">{{ order.room_type?.name || '-' }}</el-descriptions-item>
                    <el-descriptions-item label="入住日期">{{ formatDate(order.check_in_date) }}</el-descriptions-item>
                    <el-descriptions-item label="离店日期">{{ formatDate(order.check_out_date) }}</el-descriptions-item>
                    <el-descriptions-item label="房间数">{{ order.room_count || 1 }} 间</el-descriptions-item>
                    <el-descriptions-item label="订单金额">¥{{ formatPrice(order.total_amount) }}</el-descriptions-item>
                    <el-descriptions-item label="结算金额">¥{{ formatPrice(order.settlement_amount) }}</el-descriptions-item>
                    <el-descriptions-item label="资源方订单号">
                        <span>{{ order.resource_order_no || '-' }}</span>
                        <el-button
                            v-if="canBackfillResourceOrderNo"
                            type="primary"
                            link
                            size="small"
                            style="margin-left: 8px;"
                            :loading="backfilling"
                            @click="handleBackfillResourceOrderNo"
                        >
                            补录
                        </el-button>
                    </el-descriptions-item>
                    <el-descriptions-item label="创建时间">{{ formatDateTime(order.created_at) }}</el-descriptions-item>
                    <el-descriptions-item label="支付时间">{{ order.paid_at ? formatDateTime(order.paid_at) : '-' }}</el-descriptions-item>
                </el-descriptions>

                <!-- 预售履约子单 -->
                <div v-if="order.is_presale_fulfillment_child" class="section">
                    <h3>预售子单说明</h3>
                    <el-alert
                        type="success"
                        :closable="false"
                        show-icon
                        style="margin-bottom: 12px;"
                        title="本单为小程序预约后生成的履约子单，资源方接单与钉钉通知均针对此单；OTA 购票信息在预售父单。"
                    />
                    <el-descriptions v-if="order.presale_parent" :column="2" border style="margin-bottom: 12px;">
                        <el-descriptions-item label="预售父单号">
                            <router-link :to="`/orders/${order.presale_parent.id}`">
                                {{ order.presale_parent.order_no }}
                            </router-link>
                        </el-descriptions-item>
                        <el-descriptions-item label="父单 OTA 号">{{ order.presale_parent.ota_order_no || '—' }}</el-descriptions-item>
                        <el-descriptions-item label="预约单号">{{ order.source_booking?.booking_no || '—' }}</el-descriptions-item>
                        <el-descriptions-item label="套餐售价">
                            ¥{{ formatPrice(order.source_booking?.package_sale_price ?? order.total_amount) }}
                            （基础 ¥{{ formatPrice(order.source_booking?.base_price) }} + 补差 ¥{{ formatPrice(order.source_booking?.surcharge_amount) }}）
                        </el-descriptions-item>
                    </el-descriptions>
                </div>

                <!-- 预售父单：权益与小程序预约单 -->
                <div v-if="order.is_presale_parent" class="section">
                    <h3>预售父单说明</h3>
                    <el-alert
                        type="info"
                        :closable="false"
                        show-icon
                        style="margin-bottom: 12px;"
                        title="本页为 OTA 购买产生的预售父单。客人每次在小程序预约会生成独立的履约子单（列表标「预售子单」）及预约单号（B 开头），见下表。"
                    />
                    <el-table v-if="presaleEntitlements.length > 0" :data="presaleEntitlements" border>
                        <el-table-column prop="entitlement_no" label="权益编号" min-width="160" />
                        <el-table-column label="权益状态" width="110">
                            <template #default="{ row }">{{ row.status }}</template>
                        </el-table-column>
                        <el-table-column label="预约单号" min-width="180">
                            <template #default="{ row }">
                                {{ row.booking?.booking_no || '—' }}
                            </template>
                        </el-table-column>
                        <el-table-column label="预约状态" width="110">
                            <template #default="{ row }">
                                {{ row.booking?.status || '未预约' }}
                            </template>
                        </el-table-column>
                        <el-table-column label="入住日期" width="120">
                            <template #default="{ row }">
                                {{ row.booking?.check_in_date ? formatDate(row.booking.check_in_date) : '—' }}
                            </template>
                        </el-table-column>
                        <el-table-column label="酒店/房型" min-width="200">
                            <template #default="{ row }">
                                <span v-if="row.booking">
                                    {{ row.booking.hotel_name || '—' }} · {{ row.booking.room_type_name || '—' }}
                                </span>
                                <span v-else>—</span>
                            </template>
                        </el-table-column>
                        <el-table-column label="补差(元)" width="100">
                            <template #default="{ row }">
                                {{ row.booking ? formatPrice(row.booking.surcharge_amount) : '—' }}
                            </template>
                        </el-table-column>
                    </el-table>
                    <el-empty v-else description="暂无权益记录" :image-size="80" />
                </div>

                <!-- 联系人信息 -->
                <el-descriptions title="联系人信息" :column="2" border style="margin-bottom: 20px;">
                    <el-descriptions-item label="联系人姓名">{{ order.contact_name || '-' }}</el-descriptions-item>
                    <el-descriptions-item label="联系电话">{{ order.contact_phone || '-' }}</el-descriptions-item>
                    <el-descriptions-item label="联系邮箱">{{ order.contact_email || '-' }}</el-descriptions-item>
                    <el-descriptions-item label="入住人数">{{ order.guest_count || 1 }} 人</el-descriptions-item>
                </el-descriptions>

                <!-- 入住人/游客列表 -->
                <div class="section">
                    <h3>入住人/游客列表</h3>
                    <el-table v-if="guestList.length > 0" :data="guestList" border>
                        <el-table-column type="index" label="序号" width="70" :index="(i) => i + 1" />
                        <el-table-column prop="name" label="姓名" min-width="120" />
                        <el-table-column prop="idCode" label="证件号" min-width="200" show-overflow-tooltip />
                        <el-table-column prop="credentialType" label="证件类型" width="100">
                            <template #default="{ row }">
                                {{ getCredentialTypeLabel(row.credentialType) }}
                            </template>
                        </el-table-column>
                    </el-table>
                    <template v-else-if="order.card_no">
                        <el-descriptions :column="1" border>
                            <el-descriptions-item label="证件号">{{ order.card_no }}</el-descriptions-item>
                        </el-descriptions>
                    </template>
                    <el-empty v-else description="暂无入住人信息" :image-size="80" />
                </div>

                <!-- 订单明细 -->
                <div v-if="order.items && order.items.length > 0" class="section">
                    <h3>订单明细</h3>
                    <el-table :data="order.items" border>
                        <el-table-column type="index" label="序号" width="70" :index="(i) => i + 1" />
                        <el-table-column prop="date" label="日期" width="140">
                            <template #default="{ row }">{{ formatDate(row.date) }}</template>
                        </el-table-column>
                        <el-table-column prop="quantity" label="数量" width="100" />
                        <el-table-column prop="unit_price" label="单价(元)" width="120">
                            <template #default="{ row }">{{ row.unit_price != null ? formatPrice(row.unit_price) : '-' }}</template>
                        </el-table-column>
                        <el-table-column prop="total_price" label="总价(元)" width="120">
                            <template #default="{ row }">{{ row.total_price != null ? formatPrice(row.total_price) : '-' }}</template>
                        </el-table-column>
                    </el-table>
                </div>

                <!-- 异常信息 -->
                <div v-if="exceptionOrders.length > 0" class="section">
                    <h3>异常信息</h3>
                    <el-alert
                        v-for="ex in exceptionOrders"
                        :key="ex.id"
                        :title="ex.exception_message || '异常'"
                        type="error"
                        show-icon
                        style="margin-bottom: 10px;"
                    />
                </div>
            </div>
        </el-card>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ElMessage, ElMessageBox } from 'element-plus';
import axios from '../../utils/axios';

const route = useRoute();
const router = useRouter();

const order = ref({});
const loading = ref(false);
const backfilling = ref(false);

const orderId = computed(() => route.params.id);

const statusValue = computed(() => {
    const s = order.value.status;
    return s?.value ?? s ?? '';
});

const exceptionOrders = computed(() => order.value.exception_order ?? order.value.exceptionOrder ?? []);

const presaleEntitlements = computed(() => order.value.presale_entitlements ?? []);

const canBackfillResourceOrderNo = computed(() => order.value.can_backfill_resource_order_no === true);

const guestList = computed(() => {
    const raw = order.value.guest_info;
    if (!Array.isArray(raw) || raw.length === 0) return [];
    return raw.map((guest) => ({
        name: guest.name ?? guest.Name ?? '-',
        idCode: guest.idCode ?? guest.cardNo ?? guest.credentialNo ?? guest.IdCode ?? guest.id_code ?? '-',
        credentialType: guest.credentialType ?? guest.credential_type ?? 0,
    }));
});

const fetchOrder = async () => {
    loading.value = true;
    try {
        const response = await axios.get(`/orders/${orderId.value}`);
        order.value = response.data ?? {};
    } catch (error) {
        console.error('获取订单详情失败:', error);
        ElMessage.error(error.response?.data?.message || '获取订单详情失败');
    } finally {
        loading.value = false;
    }
};

const goBack = () => router.push('/orders');

const handleBackfillResourceOrderNo = async () => {
    try {
        const { value } = await ElMessageBox.prompt(
            '请输入资源方订单号（补录后不会重新通知 OTA）',
            '补录资源方订单号',
            {
                confirmButtonText: '保存',
                cancelButtonText: '取消',
                inputPlaceholder: '请输入资源方订单号',
                inputValidator: (val) => {
                    if (!val || val.trim().length === 0) {
                        return '资源方订单号不能为空';
                    }
                    if (val.trim().length > 100) {
                        return '资源方订单号不能超过100个字符';
                    }
                    return true;
                },
            }
        );

        backfilling.value = true;
        const response = await axios.patch(`/orders/${orderId.value}/resource-order-no`, {
            resource_order_no: value.trim(),
        });

        if (response.data.success) {
            ElMessage.success(response.data.message || '补录成功');
            await fetchOrder();
        } else {
            ElMessage.error(response.data.message || '补录失败');
        }
    } catch (error) {
        if (error !== 'cancel') {
            ElMessage.error(error.response?.data?.message || '补录失败');
        }
    } finally {
        backfilling.value = false;
    }
};

const getStatusLabel = (status) => {
    const labels = {
        'paid_pending': '已支付/待确认',
        'confirming': '确认中',
        'confirmed': '预订成功',
        'rejected': '预订失败/拒单',
        'cancel_requested': '申请取消中',
        'cancel_rejected': '取消拒绝',
        'cancel_approved': '取消通过',
        'verified': '核销订单',
    };
    return labels[status] ?? status;
};

const getStatusType = (status) => {
    const types = {
        'paid_pending': 'warning',
        'confirming': 'info',
        'confirmed': 'success',
        'rejected': 'danger',
        'cancel_requested': 'warning',
        'cancel_rejected': 'info',
        'cancel_approved': 'info',
        'verified': 'success',
    };
    return types[status] ?? '';
};

const getCredentialTypeLabel = (type) => {
    const map = { 0: '身份证', 1: '护照', 2: '港澳通行证', 3: '台湾通行证', 4: '其他' };
    return map[type] ?? (type ? `类型${type}` : '-');
};

const formatDate = (date) => {
    if (!date) return '-';
    const d = typeof date === 'string' ? new Date(date) : date;
    return d.toISOString ? d.toISOString().split('T')[0] : String(date).split('T')[0];
};

const formatDateTime = (date) => {
    if (!date) return '-';
    return new Date(date).toLocaleString('zh-CN');
};

const formatPrice = (price) => {
    if (price == null) return '0.00';
    return parseFloat(price).toFixed(2);
};

onMounted(() => fetchOrder());
</script>

<style scoped>
.section {
    margin-top: 20px;
}
.section h3 {
    margin-bottom: 10px;
    font-size: 16px;
    font-weight: 500;
}
</style>
