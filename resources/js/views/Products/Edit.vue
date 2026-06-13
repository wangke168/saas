<template>
    <div class="product-edit-page">
        <el-page-header @back="goBack" title="返回产品列表">
            <template #content>
                <span>{{ pageTitle }}</span>
            </template>
        </el-page-header>

        <el-card v-loading="loading" style="margin-top: 20px;">
            <el-form
                ref="formRef"
                :model="form"
                :rules="rules"
                label-width="130px"
                class="product-edit-form"
            >
                <el-tabs v-model="activeTab" class="product-edit-tabs">
                    <el-tab-pane label="基本信息" name="basic">
                        <el-row :gutter="24">
                            <el-col :span="12">
                                <el-form-item label="所属景区" prop="scenic_spot_id">
                                    <el-select
                                        v-model="form.scenic_spot_id"
                                        placeholder="请选择景区"
                                        style="width: 100%;"
                                        :disabled="isEdit"
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
                            </el-col>
                            <el-col :span="12">
                                <el-form-item label="软件服务商" prop="software_provider_id">
                                    <el-select
                                        v-model="form.software_provider_id"
                                        placeholder="请先选择景区"
                                        style="width: 100%;"
                                        :disabled="!form.scenic_spot_id"
                                    >
                                        <el-option
                                            v-for="provider in availableSoftwareProviders"
                                            :key="provider.id"
                                            :label="`${provider.name} (${provider.api_type || '无类型'})`"
                                            :value="provider.id"
                                        />
                                    </el-select>
                                </el-form-item>
                            </el-col>
                        </el-row>

                        <el-form-item label="产品名称" prop="name">
                            <el-input v-model="form.name" placeholder="请输入产品名称" />
                        </el-form-item>

                        <el-row :gutter="24">
                            <el-col v-if="isEdit" :span="12">
                                <el-form-item label="产品编码">
                                    <el-input v-model="form.code" disabled />
                                </el-form-item>
                            </el-col>
                            <el-col :span="isEdit ? 12 : 24">
                                <el-form-item label="外部产品编码" prop="external_code">
                                    <el-input v-model="form.external_code" placeholder="可选，用于景区系统对接" />
                                </el-form-item>
                            </el-col>
                        </el-row>

                        <el-form-item label="描述" prop="description">
                            <el-input
                                v-model="form.description"
                                type="textarea"
                                :rows="4"
                                placeholder="后台备注/简要说明（不直接展示在小程序详情）"
                            />
                        </el-form-item>

                        <el-row :gutter="24">
                            <el-col :span="12">
                                <el-form-item label="状态" prop="is_active">
                                    <el-switch v-model="form.is_active" />
                                </el-form-item>
                            </el-col>
                            <el-col :span="12">
                                <el-form-item label="是否实名制" prop="is_realname">
                                    <el-switch
                                        v-model="form.is_realname"
                                        @change="form.is_realname_touched = true"
                                    />
                                </el-form-item>
                            </el-col>
                        </el-row>
                    </el-tab-pane>

                    <el-tab-pane label="销售与库存" name="sales">
                        <el-row :gutter="24">
                            <el-col :span="12">
                                <el-form-item label="价格来源" prop="price_source">
                                    <el-select v-model="form.price_source" style="width: 100%;">
                                        <el-option label="人工维护" value="manual" />
                                        <el-option label="接口推送" value="api" />
                                    </el-select>
                                </el-form-item>
                            </el-col>
                            <el-col :span="12">
                                <el-form-item label="入住天数" prop="stay_days">
                                    <el-input-number
                                        v-model="form.stay_days"
                                        :min="1"
                                        :max="30"
                                        style="width: 100%;"
                                    />
                                </el-form-item>
                            </el-col>
                        </el-row>

                        <el-row :gutter="24">
                            <el-col :span="12">
                                <el-form-item label="销售开始日期" prop="sale_start_date">
                                    <el-date-picker
                                        v-model="form.sale_start_date"
                                        type="date"
                                        format="YYYY-MM-DD"
                                        value-format="YYYY-MM-DD"
                                        style="width: 100%;"
                                        :disabled-date="(date) => form.sale_end_date && date > new Date(form.sale_end_date)"
                                    />
                                </el-form-item>
                            </el-col>
                            <el-col :span="12">
                                <el-form-item label="销售结束日期" prop="sale_end_date">
                                    <el-date-picker
                                        v-model="form.sale_end_date"
                                        type="date"
                                        format="YYYY-MM-DD"
                                        value-format="YYYY-MM-DD"
                                        style="width: 100%;"
                                        :disabled-date="(date) => form.sale_start_date && date < new Date(form.sale_start_date)"
                                    />
                                </el-form-item>
                            </el-col>
                        </el-row>

                        <el-form-item label="不可订时段">
                            <div class="full-width-block">
                                <div
                                    v-for="(row, idx) in form.unavailable_periods"
                                    :key="idx"
                                    class="period-row"
                                >
                                    <el-date-picker
                                        v-model="row.start_date"
                                        type="date"
                                        placeholder="开始"
                                        format="YYYY-MM-DD"
                                        value-format="YYYY-MM-DD"
                                        style="width: 150px;"
                                    />
                                    <span class="text-muted">至</span>
                                    <el-date-picker
                                        v-model="row.end_date"
                                        type="date"
                                        placeholder="结束"
                                        format="YYYY-MM-DD"
                                        value-format="YYYY-MM-DD"
                                        style="width: 150px;"
                                    />
                                    <el-input
                                        v-model="row.note"
                                        placeholder="备注（可选）"
                                        style="width: 180px;"
                                        maxlength="500"
                                    />
                                    <el-button type="danger" link @click="removeUnavailablePeriod(idx)">删除</el-button>
                                </div>
                                <el-button type="primary" link @click="addUnavailablePeriod">+ 添加不可订时段</el-button>
                                <div class="field-hint">
                                    与库存日历「房晚」日期一致（含首尾）。多晚产品若任一晚落在不可订区间内，则该入住日不向 OTA 推库存/价。
                                </div>
                            </div>
                        </el-form-item>
                    </el-tab-pane>

                    <el-tab-pane label="履约配置" name="fulfillment">
                        <el-form-item label="履约模式" prop="fulfillment_mode">
                            <el-select v-model="form.fulfillment_mode" style="width: 100%; max-width: 480px;">
                                <el-option label="落单即履约（常规）" value="immediate" />
                                <el-option label="小程序预约后履约（OTA 预售）" value="deferred" />
                            </el-select>
                            <div class="field-hint">
                                预售型：OTA 下单不占酒店库存；客人须在小程序选日期/酒店/房型后再占房。
                            </div>
                            <el-alert
                                v-if="form.fulfillment_mode === 'deferred'"
                                type="warning"
                                :closable="false"
                                show-icon
                                style="margin-top: 8px; max-width: 640px;"
                                title="请确保已在「打包产品」中维护该产品（门票）的可选酒店房型，并生成对应酒景套餐。"
                            />
                        </el-form-item>

                        <el-form-item v-if="form.fulfillment_mode === 'deferred'" label="预约提前量">
                            <div class="inline-flex-wrap">
                                <el-switch v-model="form.booking_advance_enabled" />
                                <span>限制最早入住日（相对今天）</span>
                                <template v-if="form.booking_advance_enabled">
                                    <span>须提前</span>
                                    <el-input-number
                                        v-model="form.booking_advance_days"
                                        :min="1"
                                        :max="90"
                                        controls-position="right"
                                        style="width: 120px;"
                                    />
                                    <span>天预约</span>
                                </template>
                            </div>
                        </el-form-item>

                        <el-row :gutter="24">
                            <el-col :span="12">
                                <el-form-item label="订单处理方式" prop="order_mode">
                                    <el-select v-model="form.order_mode" clearable placeholder="使用景区配置" style="width: 100%;">
                                        <el-option label="使用景区配置（默认）" :value="null" />
                                        <el-option label="系统直连" value="auto" />
                                        <el-option label="手工接单" value="manual" />
                                        <el-option label="其他系统" value="other" />
                                    </el-select>
                                </el-form-item>
                            </el-col>
                            <el-col v-if="form.order_mode === 'other'" :span="12">
                                <el-form-item label="订单下发服务商" prop="order_provider_id">
                                    <el-select
                                        v-model="form.order_provider_id"
                                        placeholder="请选择"
                                        clearable
                                        style="width: 100%;"
                                        :disabled="!form.scenic_spot_id"
                                    >
                                        <el-option
                                            v-for="provider in availableSoftwareProviders"
                                            :key="provider.id"
                                            :label="`${provider.name} (${provider.api_type || '无类型'})`"
                                            :value="provider.id"
                                        />
                                    </el-select>
                                </el-form-item>
                            </el-col>
                        </el-row>
                    </el-tab-pane>

                    <el-tab-pane label="地区限制" name="region">
                        <el-form-item label="启用地区限制">
                            <el-switch v-model="form.id_region_restriction_enabled" />
                            <div class="field-hint" style="margin-top: 8px;">
                                开启后，OTA 预下单时将校验出行人身份证号前几位；不匹配则无法下单。
                            </div>
                        </el-form-item>

                        <el-form-item v-if="form.id_region_restriction_enabled" label="身份证前几位">
                            <div class="full-width-block">
                                <div
                                    v-for="(prefix, idx) in form.id_region_prefixes"
                                    :key="idx"
                                    class="rule-row"
                                >
                                    <el-input
                                        v-model="form.id_region_prefixes[idx]"
                                        placeholder="如 3301（2-6位数字）"
                                        maxlength="6"
                                        style="max-width: 280px;"
                                    />
                                    <el-button type="danger" link @click="removeIdRegionPrefix(idx)">删除</el-button>
                                </div>
                                <el-button type="primary" link @click="addIdRegionPrefix">+ 添加前缀</el-button>
                                <div class="field-hint">
                                    出行人身份证号须以任一配置前缀开头；可配置多个地区，如 3301、3302。
                                </div>
                            </div>
                        </el-form-item>
                    </el-tab-pane>

                    <el-tab-pane label="小程序展示" name="mp">
                        <el-alert
                            type="info"
                            :closable="false"
                            show-icon
                            style="margin-bottom: 16px; max-width: 720px;"
                            title="以下内容展示在小程序「产品详情」页；留空时预约规则/费用说明使用系统默认文案。"
                        />

                        <el-form-item label="产品头图">
                            <PublicImageUpload
                                mode="cover"
                                directory="product-media"
                                :cover-path="form.cover_image"
                                :cover-preview-url="form.cover_image_url"
                                hint="建议横图，支持 jpg/png/webp，单张不超过 5MB"
                                @update:cover-path="form.cover_image = $event"
                                @update:cover-preview-url="form.cover_image_url = $event"
                            />
                        </el-form-item>

                        <el-form-item label="预约规则">
                            <div class="full-width-block">
                                <div v-for="(rule, idx) in form.booking_rules" :key="idx" class="rule-row">
                                    <el-input
                                        v-model="form.booking_rules[idx]"
                                        placeholder="请输入一条预约规则"
                                        maxlength="500"
                                    />
                                    <el-button type="danger" link @click="removeBookingRule(idx)">删除</el-button>
                                </div>
                                <el-button type="primary" link @click="addBookingRule">+ 添加规则</el-button>
                            </div>
                        </el-form-item>

                        <el-form-item label="费用说明">
                            <el-input
                                v-model="form.fee_note"
                                type="textarea"
                                :rows="3"
                                maxlength="500"
                                show-word-limit
                                placeholder="例：已付金额为预售基础价；所选日期房型高于基础价需在线补差价。"
                            />
                        </el-form-item>

                        <el-form-item label="产品内容">
                            <el-input
                                v-model="form.mp_content"
                                type="textarea"
                                :rows="10"
                                placeholder="套餐包含、使用说明、注意事项等，展示在小程序详情页"
                            />
                        </el-form-item>
                    </el-tab-pane>
                </el-tabs>

                <div class="form-actions">
                    <el-button @click="goBack">取消</el-button>
                    <el-button type="primary" :loading="submitting" @click="handleSubmit">保存</el-button>
                </div>
            </el-form>
        </el-card>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import axios from '../../utils/axios';
import { ElMessage } from 'element-plus';
import { useAuthStore } from '../../stores/auth';
import PublicImageUpload from '../../components/PublicImageUpload.vue';

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();

const loading = ref(false);
const submitting = ref(false);
const formRef = ref(null);
const activeTab = ref('basic');
const scenicSpots = ref([]);
const availableSoftwareProviders = ref([]);

const productId = computed(() => {
    const id = route.params.id;
    return id ? Number(id) : null;
});

const duplicateSourceId = computed(() => {
    const raw = route.query.duplicate_from;
    return raw ? Number(raw) : null;
});

const isEdit = computed(() => productId.value !== null);
const isDuplicate = computed(() => !isEdit.value && duplicateSourceId.value !== null);

const pageTitle = computed(() => {
    if (isEdit.value) return '编辑产品';
    if (isDuplicate.value) return '复制产品';
    return '创建产品';
});

const defaultForm = () => ({
    scenic_spot_id: null,
    software_provider_id: null,
    name: '',
    code: '',
    external_code: '',
    description: '',
    cover_image: '',
    cover_image_url: '',
    booking_rules: [],
    mp_content: '',
    fee_note: '',
    price_source: 'manual',
    stay_days: 1,
    sale_start_date: null,
    sale_end_date: null,
    fulfillment_mode: 'immediate',
    booking_advance_enabled: false,
    booking_advance_days: 1,
    order_mode: null,
    order_provider_id: null,
    is_active: true,
    is_realname: false,
    is_realname_touched: false,
    _is_realname_original_null: false,
    unavailable_periods: [],
    id_region_restriction_enabled: false,
    id_region_prefixes: [],
});

const form = ref(defaultForm());

const validateSaleEndDate = (rule, value, callback) => {
    if (!value) {
        callback(new Error('请选择销售结束日期'));
    } else if (form.value.sale_start_date && value < form.value.sale_start_date) {
        callback(new Error('销售结束日期不能早于开始日期'));
    } else {
        callback();
    }
};

const rules = {
    scenic_spot_id: [{ required: true, message: '请选择所属景区', trigger: 'change' }],
    software_provider_id: [{ required: true, message: '请选择软件服务商', trigger: 'change' }],
    name: [
        { required: true, message: '请输入产品名称', trigger: 'blur' },
        { max: 255, message: '产品名称不能超过255个字符', trigger: 'blur' },
    ],
    price_source: [{ required: true, message: '请选择价格来源', trigger: 'change' }],
    stay_days: [
        { required: true, message: '请输入入住天数', trigger: 'blur' },
        { type: 'number', min: 1, max: 30, message: '入住天数必须在1-30之间', trigger: 'blur' },
    ],
    sale_start_date: [{ required: true, message: '请选择销售开始日期', trigger: 'change' }],
    sale_end_date: [{ validator: validateSaleEndDate, trigger: 'change' }],
};

const formatDateForPicker = (dateString) => {
    if (!dateString) return null;
    if (typeof dateString === 'string' && dateString.includes('T')) {
        return dateString.split('T')[0];
    }
    if (typeof dateString === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(dateString)) {
        return dateString;
    }
    return dateString;
};

const publicMediaUrl = (path) => {
    if (!path) return '';
    if (/^https?:\/\//i.test(path)) return path;
    const base = import.meta.env.VITE_APP_URL || window.location.origin;
    return `${base.replace(/\/$/, '')}/storage/${String(path).replace(/^\//, '')}`;
};

const mapUnavailablePeriodsFromApi = (list) => {
    if (!Array.isArray(list) || list.length === 0) return [];
    return list.map((p) => ({
        start_date: formatDateForPicker(p.start_date),
        end_date: formatDateForPicker(p.end_date),
        note: p.note || '',
    }));
};

const mapBookingRulesFromApi = (list) => {
    if (!Array.isArray(list)) return [];
    return list.map((rule) => String(rule || '').trim()).filter((rule) => rule !== '');
};

const applyProductToForm = (product, options = {}) => {
    const { duplicateName = false } = options;
    form.value = {
        ...defaultForm(),
        scenic_spot_id: product.scenic_spot_id,
        software_provider_id: product.software_provider_id || null,
        name: duplicateName ? `${product.name}-副本` : product.name,
        code: duplicateName ? '' : (product.code || ''),
        external_code: product.external_code || '',
        description: product.description || '',
        cover_image: product.cover_image || '',
        cover_image_url: product.cover_image_url || publicMediaUrl(product.cover_image),
        booking_rules: mapBookingRulesFromApi(product.booking_rules),
        mp_content: product.mp_content || '',
        fee_note: product.fee_note || '',
        price_source: product.price_source || 'manual',
        stay_days: product.stay_days || 1,
        sale_start_date: formatDateForPicker(product.sale_start_date),
        sale_end_date: formatDateForPicker(product.sale_end_date),
        fulfillment_mode: product.fulfillment_mode || 'immediate',
        booking_advance_enabled: Number(product.booking_advance_days || 0) > 0,
        booking_advance_days: Number(product.booking_advance_days || 0) > 0 ? Number(product.booking_advance_days) : 1,
        order_mode: product.order_mode || null,
        order_provider_id: product.order_provider_id || null,
        is_active: product.is_active ?? true,
        is_realname: Number(product.is_realname) === 1,
        is_realname_touched: false,
        _is_realname_original_null: product.is_realname === null || product.is_realname === undefined,
        unavailable_periods: mapUnavailablePeriodsFromApi(product.unavailable_periods),
        id_region_restriction_enabled: Boolean(product.id_region_restriction_enabled),
        id_region_prefixes: Array.isArray(product.id_region_prefixes)
            ? product.id_region_prefixes.map((prefix) => String(prefix || ''))
            : [],
    };
};

const fetchScenicSpots = async () => {
    if (authStore.user?.role !== 'admin') {
        if (!authStore.user?.scenic_spots?.length) {
            await authStore.fetchUser();
        }
        scenicSpots.value = authStore.user?.scenic_spots || [];
        return;
    }
    const response = await axios.get('/scenic-spots');
    scenicSpots.value = response.data.data || [];
};

const handleScenicSpotChange = async (scenicSpotId, preserveProviderId = false) => {
    const currentProviderId = preserveProviderId ? form.value.software_provider_id : null;
    if (!preserveProviderId) {
        form.value.software_provider_id = null;
    }
    availableSoftwareProviders.value = [];

    if (!scenicSpotId) return;

    try {
        const response = await axios.get(`/scenic-spots/${scenicSpotId}`);
        const scenicSpot = response.data.data;
        availableSoftwareProviders.value = scenicSpot.software_providers || [];

        if (preserveProviderId && currentProviderId) {
            const exists = availableSoftwareProviders.value.some((p) => p.id === currentProviderId);
            form.value.software_provider_id = exists ? currentProviderId : null;
            if (!exists) {
                ElMessage.warning('该产品配置的服务商不属于当前景区的服务商列表，请重新选择');
            }
        }
    } catch (error) {
        ElMessage.error('获取景区服务商列表失败');
        console.error(error);
    }
};

const loadProduct = async () => {
    loading.value = true;
    try {
        await fetchScenicSpots();

        if (isEdit.value) {
            const res = await axios.get(`/products/${productId.value}`, { params: { include_prices: false } });
            const product = res.data?.data;
            if (!product) throw new Error('产品不存在');
            applyProductToForm(product);
            if (product.scenic_spot_id) {
                await handleScenicSpotChange(product.scenic_spot_id, true);
            }
            return;
        }

        if (isDuplicate.value) {
            const res = await axios.get(`/products/${duplicateSourceId.value}`, { params: { include_prices: false } });
            const product = res.data?.data;
            if (!product) throw new Error('源产品不存在');
            applyProductToForm(product, { duplicateName: true });
            if (product.scenic_spot_id) {
                await handleScenicSpotChange(product.scenic_spot_id, true);
            }
        }
    } catch (error) {
        ElMessage.error(error.response?.data?.message || '加载产品失败');
        console.error(error);
    } finally {
        loading.value = false;
    }
};

const addUnavailablePeriod = () => {
    form.value.unavailable_periods.push({ start_date: null, end_date: null, note: '' });
};

const removeUnavailablePeriod = (idx) => {
    form.value.unavailable_periods.splice(idx, 1);
};

const addBookingRule = () => {
    form.value.booking_rules.push('');
};

const removeBookingRule = (idx) => {
    form.value.booking_rules.splice(idx, 1);
};

const addIdRegionPrefix = () => {
    form.value.id_region_prefixes.push('');
};

const removeIdRegionPrefix = (idx) => {
    form.value.id_region_prefixes.splice(idx, 1);
};

const buildSubmitData = () => {
    const submitData = {
        ...form.value,
        stay_days: form.value.stay_days || 1,
        sale_start_date: form.value.sale_start_date || null,
        sale_end_date: form.value.sale_end_date || null,
    };

    if (form.value._is_realname_original_null && !form.value.is_realname_touched) {
        submitData.is_realname = null;
    }

    delete submitData.is_realname_touched;
    delete submitData._is_realname_original_null;
    delete submitData.code;
    delete submitData.cover_image_url;

    if (submitData.external_code === '') submitData.external_code = null;
    if (submitData.order_mode === '') submitData.order_mode = null;
    if (submitData.order_provider_id === '') submitData.order_provider_id = null;
    if (submitData.order_mode !== 'other') submitData.order_provider_id = null;
    if (submitData.cover_image === '') submitData.cover_image = null;

    if (submitData.fulfillment_mode === 'deferred' && form.value.booking_advance_enabled) {
        submitData.booking_advance_days = Math.max(1, Number(form.value.booking_advance_days) || 1);
    } else {
        submitData.booking_advance_days = 0;
    }
    delete submitData.booking_advance_enabled;

    submitData.unavailable_periods = (form.value.unavailable_periods || [])
        .filter((p) => p.start_date && p.end_date)
        .map((p) => ({
            start_date: p.start_date,
            end_date: p.end_date,
            note: p.note && String(p.note).trim() !== '' ? String(p.note).trim() : null,
        }));

    submitData.booking_rules = (form.value.booking_rules || [])
        .map((rule) => String(rule || '').trim())
        .filter((rule) => rule !== '');

    if (submitData.booking_rules.length === 0) {
        submitData.booking_rules = null;
    }

    if (submitData.fee_note === '') submitData.fee_note = null;
    if (submitData.mp_content === '') submitData.mp_content = null;

    submitData.id_region_prefixes = (form.value.id_region_prefixes || [])
        .map((prefix) => String(prefix || '').replace(/\D/g, ''))
        .filter((prefix) => prefix !== '');

    if (!form.value.id_region_restriction_enabled) {
        submitData.id_region_restriction_enabled = false;
    }

    return submitData;
};

const handleSubmit = async () => {
    if (!formRef.value) return;

    await formRef.value.validate(async (valid) => {
        if (!valid) {
            ElMessage.warning('请检查表单必填项');
            return;
        }

        if (form.value.id_region_restriction_enabled) {
            const validPrefixes = (form.value.id_region_prefixes || [])
                .map((prefix) => String(prefix || '').replace(/\D/g, ''))
                .filter((prefix) => prefix.length >= 2 && prefix.length <= 6);
            if (validPrefixes.length === 0) {
                ElMessage.warning('启用地区限制时请至少配置一个有效的身份证前几位（2-6位数字）');
                activeTab.value = 'region';
                return;
            }
        }

        submitting.value = true;
        try {
            const submitData = buildSubmitData();

            if (isEdit.value) {
                await axios.put(`/products/${productId.value}`, submitData);
                ElMessage.success('产品更新成功');
                router.push(`/products/${productId.value}/detail`);
                return;
            }

            if (isDuplicate.value) {
                const res = await axios.post(`/products/${duplicateSourceId.value}/duplicate`, submitData);
                const newId = res.data?.data?.id;
                ElMessage.success('产品复制成功');
                router.push(newId ? `/products/${newId}/detail` : '/products');
                return;
            }

            const res = await axios.post('/products', submitData);
            const newId = res.data?.data?.id;
            ElMessage.success('产品创建成功');
            router.push(newId ? `/products/${newId}/detail` : '/products');
        } catch (error) {
            const message = error.response?.data?.message
                || error.response?.data?.errors?.code?.[0]
                || '保存失败';
            ElMessage.error(message);
        } finally {
            submitting.value = false;
        }
    });
};

const goBack = () => {
    router.push('/products');
};

onMounted(() => {
    loadProduct();
});
</script>

<style scoped>
.product-edit-page {
    max-width: 960px;
}

.product-edit-tabs {
    min-height: 420px;
}

.product-edit-tabs :deep(.el-tabs__content) {
    padding-top: 8px;
}

.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 24px;
    padding-top: 16px;
    border-top: 1px solid #ebeef5;
}

.full-width-block {
    width: 100%;
}

.period-row,
.rule-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    margin-bottom: 8px;
}

.rule-row .el-input {
    flex: 1;
    min-width: 280px;
}

.inline-flex-wrap {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.field-hint {
    font-size: 12px;
    color: #909399;
    margin-top: 6px;
    line-height: 1.5;
}

.text-muted {
    color: #909399;
}
</style>
