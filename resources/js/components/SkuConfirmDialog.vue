<template>
    <el-dialog
        v-model="visible"
        title="确认酒店房型"
        width="640px"
        :close-on-click-modal="false"
        @closed="onClosed"
    >
        <el-alert type="warning" :closable="false" style="margin-bottom: 16px">
            {{ skuMessage || '美团未传 SKU 主键，请选择客人实际购买的酒店房型后再接单。' }}
        </el-alert>
        <p v-if="levelIdsText" style="margin: 0 0 8px;">levelIds：{{ levelIdsText }}</p>
        <p v-if="unitPrice != null" style="margin: 0 0 16px;">美团单价：{{ unitPrice }}</p>
        <el-radio-group v-model="selectedKey" style="display: flex; flex-direction: column; gap: 8px;">
            <el-radio
                v-for="item in candidates"
                :key="itemKey(item)"
                :value="itemKey(item)"
                :label="itemKey(item)"
            >
                {{ item.hotel_name }} / {{ item.room_type_name }}　¥{{ item.sale_price }}
            </el-radio>
        </el-radio-group>
        <template #footer>
            <el-button @click="cancel">取消</el-button>
            <el-button type="primary" :disabled="!selectedKey" @click="confirm">确定接单</el-button>
        </template>
    </el-dialog>
</template>

<script setup>
import { ref } from 'vue';
import { ElMessage } from 'element-plus';

const visible = ref(false);
const skuMessage = ref('');
const levelIdsText = ref('');
const unitPrice = ref(null);
const candidates = ref([]);
const selectedKey = ref('');

let resolveFn = null;

const itemKey = (item) => `${item.hotel_id}-${item.room_type_id}`;

const reset = () => {
    skuMessage.value = '';
    levelIdsText.value = '';
    unitPrice.value = null;
    candidates.value = [];
    selectedKey.value = '';
};

const open = (sku) => {
    reset();
    skuMessage.value = sku?.message || '';
    const levelIds = sku?.level_ids;
    levelIdsText.value = Array.isArray(levelIds) ? levelIds.join(',') : (levelIds ? String(levelIds) : '');
    unitPrice.value = sku?.unit_price ?? null;
    candidates.value = Array.isArray(sku?.candidates) ? sku.candidates : [];
    if (candidates.value.length === 1) {
        selectedKey.value = itemKey(candidates.value[0]);
    }
    visible.value = true;

    return new Promise((resolve) => {
        resolveFn = resolve;
    });
};

const finish = (payload) => {
    visible.value = false;
    if (resolveFn) {
        resolveFn(payload);
        resolveFn = null;
    }
};

const cancel = () => finish(null);

const confirm = () => {
    const item = candidates.value.find((row) => itemKey(row) === selectedKey.value);
    if (!item) {
        ElMessage.warning('请选择酒店房型');
        return;
    }
    finish({
        hotel_id: item.hotel_id,
        room_type_id: item.room_type_id,
        remark: '人工确认美团 SKU',
    });
};

const onClosed = () => {
    if (resolveFn) {
        resolveFn(null);
        resolveFn = null;
    }
};

defineExpose({ open });
</script>
