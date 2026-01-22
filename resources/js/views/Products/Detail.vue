<template>
    <div>
        <el-page-header @back="goBack" title="返回产品列表">
            <template #content>
                <span>产品详情</span>
            </template>
        </el-page-header>

        <el-card v-loading="loading" style="margin-top: 20px;">
            <div v-if="product">
                <el-descriptions title="产品基本信息" :column="2" border>
                    <el-descriptions-item label="产品名称">{{ product.name }}</el-descriptions-item>
                    <el-descriptions-item label="产品编码">{{ product.code }}</el-descriptions-item>
                    <el-descriptions-item label="所属景区">{{ product.scenic_spot?.name || '-' }}</el-descriptions-item>
                    <el-descriptions-item label="价格来源">
                        <el-tag :type="product.price_source === 'manual' ? 'primary' : 'success'">
                            {{ product.price_source === 'manual' ? '人工维护' : '接口推送' }}
                        </el-tag>
                    </el-descriptions-item>
                    <el-descriptions-item label="状态">
                        <el-tag :type="product.is_active ? 'success' : 'danger'">
                            {{ product.is_active ? '启用' : '禁用' }}
                        </el-tag>
                    </el-descriptions-item>
                    <el-descriptions-item label="入住天数">
                        {{ product.stay_days ? `${product.stay_days} 晚` : '单晚' }}
                    </el-descriptions-item>
                    <el-descriptions-item label="创建时间">{{ formatDate(product.created_at) }}</el-descriptions-item>
                    <el-descriptions-item label="销售开始日期">
                        {{ product.sale_start_date ? formatDateOnly(product.sale_start_date) : '不限制' }}
                    </el-descriptions-item>
                    <el-descriptions-item label="销售结束日期">
                        {{ product.sale_end_date ? formatDateOnly(product.sale_end_date) : '不限制' }}
                    </el-descriptions-item>
                    <el-descriptions-item label="产品描述" :span="2">
                        {{ product.description || '-' }}
                    </el-descriptions-item>
                </el-descriptions>

                <el-tabs v-model="activeTab" style="margin-top: 20px;">
                    <!-- 价格管理标签页 -->
                    <el-tab-pane label="价格管理" name="prices">
                        <div style="margin-bottom: 20px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                            <el-button type="primary" @click="handleAddPrice" v-if="product.price_source === 'manual'">
                                添加价格
                            </el-button>
                            <el-select
                                v-model="priceFilterRoomTypeId"
                                placeholder="筛选房型"
                                clearable
                                style="width: 200px;"
                                @change="handlePriceFilter"
                            >
                                <el-option
                                    v-for="roomType in roomTypes"
                                    :key="roomType.id"
                                    :label="`${roomType.hotel?.name || ''} - ${roomType.name}`"
                                    :value="roomType.id"
                                />
                            </el-select>
                            <el-date-picker
                                v-model="priceFilterDateRange"
                                type="daterange"
                                range-separator="至"
                                start-placeholder="开始日期"
                                end-placeholder="结束日期"
                                format="YYYY-MM-DD"
                                value-format="YYYY-MM-DD"
                                style="width: 240px;"
                                @change="handlePriceFilter"
                            />
                            <el-button @click="resetPriceFilter" size="small">重置筛选</el-button>
                            <el-alert
                                v-if="product.price_source !== 'manual'"
                                title="提示"
                                type="info"
                                description="该产品的价格由接口推送，无法手动管理"
                                :closable="false"
                                style="flex: 1;"
                            />
                        </div>

                        <!-- 按房型分组展示 -->
                        <div v-loading="pricesLoading">
                            <el-collapse v-model="expandedRoomTypes" v-if="groupedPrices.length > 0">
                                <el-collapse-item
                                    v-for="group in groupedPrices"
                                    :key="group.roomTypeId"
                                    :name="group.roomTypeId"
                                >
                                    <template #title>
                                        <div style="display: flex; align-items: center; gap: 10px; width: 100%;">
                                            <span style="font-weight: bold;">
                                                {{ group.hotelName }} - {{ group.roomTypeName }}
                                            </span>
                                            <el-tag size="small" type="info">
                                                {{ group.prices.length }} 条价格记录
                                            </el-tag>
                                            <span style="margin-left: auto; color: #909399; font-size: 12px;">
                                                日期范围: {{ group.dateRange }}
                                            </span>
                                        </div>
                                    </template>
                                    <!-- 按日期范围展示价格，更紧凑 -->
                                    <el-table :data="group.dateRanges" border size="small" style="margin-top: 10px;">
                                        <el-table-column label="日期范围" width="200">
                                            <template #default="{ row }">
                                                <span v-if="row.start === row.end">{{ formatDateOnly(row.start) }}</span>
                                                <span v-else>{{ formatDateOnly(row.start) }} 至 {{ formatDateOnly(row.end) }}</span>
                                            </template>
                                        </el-table-column>
                                        <el-table-column prop="market_price" label="门市价（元）" width="120">
                                            <template #default="{ row }">
                                                {{ formatPrice(row.market_price) }}
                                            </template>
                                        </el-table-column>
                                        <el-table-column prop="settlement_price" label="结算价（元）" width="120">
                                            <template #default="{ row }">
                                                {{ formatPrice(row.settlement_price) }}
                                            </template>
                                        </el-table-column>
                                        <el-table-column prop="sale_price" label="销售价（元）" width="120">
                                            <template #default="{ row }">
                                                {{ formatPrice(row.sale_price) }}
                                            </template>
                                        </el-table-column>
                                        <el-table-column prop="source" label="来源" width="100">
                                            <template #default="{ row }">
                                                <el-tag :type="row.source === 'manual' ? 'primary' : 'success'" size="small">
                                                    {{ row.source === 'manual' ? '人工' : '接口' }}
                                                </el-tag>
                                            </template>
                                        </el-table-column>
                                        <el-table-column label="操作" width="200" v-if="product.price_source === 'manual'">
                                            <template #default="{ row }">
                                                <el-button size="small" @click="handleEditPriceRange(group, row)">编辑</el-button>
                                                <el-button size="small" type="danger" @click="handleDeletePriceRange(group, row)">删除</el-button>
                                            </template>
                                        </el-table-column>
                                    </el-table>
                                    <!-- 如果需要查看详细日期，可以展开查看 -->
                                    <el-collapse v-if="group.dateRanges.length > 0" style="margin-top: 10px;">
                                        <el-collapse-item title="查看详细日期列表" :name="`detail-${group.roomTypeId}`">
                                            <el-table :data="group.prices" border size="small">
                                                <el-table-column prop="date" label="日期" width="120" />
                                                <el-table-column prop="market_price" label="门市价（元）" width="120">
                                                    <template #default="{ row }">
                                                        {{ formatPrice(row.market_price) }}
                                                    </template>
                                                </el-table-column>
                                                <el-table-column prop="settlement_price" label="结算价（元）" width="120">
                                                    <template #default="{ row }">
                                                        {{ formatPrice(row.settlement_price) }}
                                                    </template>
                                                </el-table-column>
                                                <el-table-column prop="sale_price" label="销售价（元）" width="120">
                                                    <template #default="{ row }">
                                                        {{ formatPrice(row.sale_price) }}
                                                    </template>
                                                </el-table-column>
                                                <el-table-column prop="source" label="来源" width="100">
                                                    <template #default="{ row }">
                                                        <el-tag :type="row.source === 'manual' ? 'primary' : 'success'" size="small">
                                                            {{ row.source === 'manual' ? '人工' : '接口' }}
                                                        </el-tag>
                                                    </template>
                                                </el-table-column>
                                                <el-table-column label="操作" width="150" v-if="product.price_source === 'manual'">
                                                    <template #default="{ row }">
                                                        <el-button size="small" @click="handleEditPrice(row)">编辑</el-button>
                                                        <el-button size="small" type="danger" @click="handleDeletePrice(row)">删除</el-button>
                                                    </template>
                                                </el-table-column>
                                            </el-table>
                                        </el-collapse-item>
                                    </el-collapse>
                                </el-collapse-item>
                            </el-collapse>
                            <el-empty v-else description="暂无价格数据" />
                        </div>

                        <!-- 价格管理对话框 -->
                        <el-dialog
                            v-model="priceDialogVisible"
                            :title="priceDialogTitle"
                            width="600px"
                            @close="resetPriceForm"
                        >
                            <el-form
                                ref="priceFormRef"
                                :model="priceForm"
                                :rules="priceFormRules"
                                label-width="120px"
                            >
                                <el-form-item label="选择房型" prop="room_type_ids">
                                    <el-select
                                        v-model="priceForm.room_type_ids"
                                        :multiple="!editingPriceId"
                                        :disabled="!!editingPriceId"
                                        :placeholder="editingPriceId ? '编辑模式下不可修改房型' : '请选择房型（可多选）'"
                                        style="width: 100%"
                                    >
                                        <el-option
                                            v-for="roomType in roomTypes"
                                            :key="roomType.id"
                                            :label="`${roomType.hotel?.name} - ${roomType.name}`"
                                            :value="roomType.id"
                                        />
                                    </el-select>
                                    <span style="margin-left: 10px; color: #909399; font-size: 12px;">
                                        <span v-if="editingPriceId">编辑模式下只能修改价格，不能修改房型</span>
                                        <span v-else>可同时为多个房型设置价格</span>
                                    </span>
                                </el-form-item>
                                <el-form-item label="日期范围">
                                    <el-alert
                                        v-if="product.sale_start_date || product.sale_end_date"
                                        type="info"
                                        :closable="false"
                                    >
                                        <template #title>
                                            <span>将使用产品的销售日期范围：</span>
                                            <span v-if="product.sale_start_date">{{ formatDateOnly(product.sale_start_date) }}</span>
                                            <span v-else>不限制</span>
                                            <span> 至 </span>
                                            <span v-if="product.sale_end_date">{{ formatDateOnly(product.sale_end_date) }}</span>
                                            <span v-else>不限制</span>
                                        </template>
                                    </el-alert>
                                    <el-alert
                                        v-else
                                        type="warning"
                                        :closable="false"
                                    >
                                        <template #title>
                                            <span>产品未设置销售日期范围，请先在产品编辑页面设置销售开始日期和结束日期</span>
                                        </template>
                                    </el-alert>
                                </el-form-item>
                                <el-form-item label="门市价（元）" prop="market_price">
                                    <el-input-number
                                        v-model="priceForm.market_price"
                                        :min="0"
                                        :precision="2"
                                        style="width: 100%"
                                    />
                                </el-form-item>
                                <el-form-item label="结算价（元）" prop="settlement_price">
                                    <el-input-number
                                        v-model="priceForm.settlement_price"
                                        :min="0"
                                        :precision="2"
                                        style="width: 100%"
                                    />
                                </el-form-item>
                                <el-form-item label="销售价（元）" prop="sale_price">
                                    <el-input-number
                                        v-model="priceForm.sale_price"
                                        :min="0"
                                        :precision="2"
                                        style="width: 100%"
                                    />
                                </el-form-item>
                            </el-form>
                            <template #footer>
                                <el-button @click="priceDialogVisible = false">取消</el-button>
                                <el-button type="primary" @click="handleSubmitPrice" :loading="priceSubmitting">确定</el-button>
                            </template>
                        </el-dialog>
                    </el-tab-pane>

                    <!-- 加价规则管理标签页 -->
                    <el-tab-pane label="加价规则" name="priceRules">
                        <div style="margin-bottom: 20px;">
                            <el-button type="primary" @click="handleAddPriceRule" v-if="product.price_source === 'manual'">
                                添加加价规则
                            </el-button>
                            <el-alert
                                v-else
                                title="提示"
                                type="info"
                                description="该产品的价格由接口推送，无法设置加价规则"
                                :closable="false"
                                style="margin-bottom: 20px;"
                            />
                        </div>
                        <el-table :data="priceRules" border v-loading="priceRulesLoading">
                            <el-table-column prop="name" label="规则名称" width="150" />
                            <el-table-column prop="type" label="规则类型" width="120">
                                <template #default="{ row }">
                                    <el-tag>{{ row.type === 'weekday' ? '周几规则' : '日期区间规则' }}</el-tag>
                                </template>
                            </el-table-column>
                            <el-table-column label="规则内容" width="200">
                                <template #default="{ row }">
                                    <span v-if="row.type === 'weekday'">
                                        周{{ formatWeekdays(row.weekdays) }}
                                    </span>
                                    <span v-else>
                                        {{ formatDateOnly(row.start_date) }} 至 {{ formatDateOnly(row.end_date) }}
                                    </span>
                                </template>
                            </el-table-column>
                            <el-table-column label="价格调整" width="300">
                                <template #default="{ row }">
                                    <div>
                                        <span>门市价: {{ row.market_price_adjustment >= 0 ? '+' : '' }}{{ formatPrice(row.market_price_adjustment) }}</span><br>
                                        <span>结算价: {{ row.settlement_price_adjustment >= 0 ? '+' : '' }}{{ formatPrice(row.settlement_price_adjustment) }}</span><br>
                                        <span>销售价: {{ row.sale_price_adjustment >= 0 ? '+' : '' }}{{ formatPrice(row.sale_price_adjustment) }}</span>
                                    </div>
                                </template>
                            </el-table-column>
                            <el-table-column prop="is_active" label="状态" width="100">
                                <template #default="{ row }">
                                    <el-tag :type="row.is_active ? 'success' : 'danger'">
                                        {{ row.is_active ? '启用' : '禁用' }}
                                    </el-tag>
                                </template>
                            </el-table-column>
                            <el-table-column label="操作" width="200" v-if="product.price_source === 'manual'">
                                <template #default="{ row }">
                                    <el-button size="small" @click="handleEditPriceRule(row)">编辑</el-button>
                                    <el-button size="small" type="danger" @click="handleDeletePriceRule(row)">删除</el-button>
                                </template>
                            </el-table-column>
                        </el-table>
                                            <!-- 加价规则管理对话框 -->
                        <el-dialog
                            v-model="priceRuleDialogVisible"
                            :title="priceRuleDialogTitle"
                            width="700px"
                            @close="resetPriceRuleForm"
                        >
                            <el-form
                                ref="priceRuleFormRef"
                                :model="priceRuleForm"
                                :rules="priceRuleFormRules"
                                label-width="140px"
                            >
                                <el-form-item label="规则名称" prop="name">
                                    <el-input v-model="priceRuleForm.name" placeholder="请输入规则名称" />
                                </el-form-item>
                                <el-form-item label="规则类型" prop="type">
                                    <el-radio-group v-model="priceRuleForm.type">
                                        <el-radio label="weekday">周几规则</el-radio>
                                        <el-radio label="date_range">日期区间规则</el-radio>
                                    </el-radio-group>
                                </el-form-item>
                                <el-form-item
                                    v-if="priceRuleForm.type === 'weekday'"
                                    label="选择周几"
                                    prop="weekdays"
                                >
                                    <el-checkbox-group v-model="priceRuleForm.weekdays">
                                        <el-checkbox label="1">周一</el-checkbox>
                                        <el-checkbox label="2">周二</el-checkbox>
                                        <el-checkbox label="3">周三</el-checkbox>
                                        <el-checkbox label="4">周四</el-checkbox>
                                        <el-checkbox label="5">周五</el-checkbox>
                                        <el-checkbox label="6">周六</el-checkbox>
                                        <el-checkbox label="7">周日</el-checkbox>
                                    </el-checkbox-group>
                                </el-form-item>
                                <el-form-item
                                    v-if="priceRuleForm.type === 'date_range'"
                                    label="日期区间"
                                    prop="dateRange"
                                >
                                    <el-date-picker
                                        v-model="priceRuleForm.dateRange"
                                        type="daterange"
                                        range-separator="至"
                                        start-placeholder="开始日期"
                                        end-placeholder="结束日期"
                                        style="width: 100%"
                                        format="YYYY-MM-DD"
                                        value-format="YYYY-MM-DD"
                                    />
                                </el-form-item>
                                <el-form-item label="门市价调整（元）" prop="market_price_adjustment">
                                    <el-input-number
                                        v-model="priceRuleForm.market_price_adjustment"
                                        :precision="2"
                                        style="width: 100%"
                                    />
                                    <span style="margin-left: 10px; color: #909399; font-size: 12px;">
                                        正数表示加价，负数表示减价
                                    </span>
                                </el-form-item>
                                <el-form-item label="结算价调整（元）" prop="settlement_price_adjustment">
                                    <el-input-number
                                        v-model="priceRuleForm.settlement_price_adjustment"
                                        :precision="2"
                                        style="width: 100%"
                                    />
                                </el-form-item>
                                <el-form-item label="销售价调整（元）" prop="sale_price_adjustment">
                                    <el-input-number
                                        v-model="priceRuleForm.sale_price_adjustment"
                                        :precision="2"
                                        style="width: 100%"
                                    />
                                </el-form-item>
                                <el-form-item label="适用房型" prop="items">
                                    <el-table :data="priceRuleForm.items" border style="width: 100%">
                                        <el-table-column label="酒店" width="200">
                                            <template #default="{ row, $index }">
                                                <el-select
                                                    v-model="row.hotel_id"
                                                    placeholder="请选择酒店"
                                                    style="width: 100%"
                                                    @change="handlePriceRuleHotelChange($index)"
                                                >
                                                    <el-option
                                                        v-for="hotel in availableHotelsForPriceRule"
                                                        :key="hotel.id"
                                                        :label="hotel.name"
                                                        :value="hotel.id"
                                                    />
                                                </el-select>
                                            </template>
                                        </el-table-column>
                                        <el-table-column label="房型" width="200">
                                            <template #default="{ row, $index }">
                                                <el-select
                                                    v-model="row.room_type_id"
                                                    placeholder="请选择房型"
                                                    style="width: 100%"
                                                >
                                                    <el-option
                                                        v-for="roomType in getAvailableRoomTypesForPriceRule(row.hotel_id)"
                                                        :key="roomType.id"
                                                        :label="roomType.name"
                                                        :value="roomType.id"
                                                    />
                                                </el-select>
                                            </template>
                                        </el-table-column>
                                        <el-table-column label="操作" width="100">
                                            <template #default="{ $index }">
                                                <el-button
                                                    size="small"
                                                    type="danger"
                                                    @click="removePriceRuleItem($index)"
                                                >
                                                    删除
                                                </el-button>
                                            </template>
                                        </el-table-column>
                                    </el-table>
                                    <el-button
                                        type="primary"
                                        size="small"
                                        style="margin-top: 10px;"
                                        @click="addPriceRuleItem"
                                    >
                                        添加房型
                                    </el-button>
                                </el-form-item>
                                <el-form-item label="状态" prop="is_active">
                                    <el-switch v-model="priceRuleForm.is_active" />
                                </el-form-item>
                            </el-form>
                            <template #footer>
                                <el-button @click="priceRuleDialogVisible = false">取消</el-button>
                                <el-button type="primary" @click="handleSubmitPriceRule" :loading="priceRuleSubmitting">确定</el-button>
                            </template>
                        </el-dialog>
</el-tab-pane>

                    <!-- OTA推送管理标签页 -->
                    <el-tab-pane label="OTA推送" name="otaProducts">
                        <div style="margin-bottom: 20px;">
                            <el-button type="primary" @click="handleBindOta">绑定OTA平台</el-button>
                        </div>
                        <el-table :data="otaProducts" border v-loading="otaProductsLoading">
                            <el-table-column label="OTA平台" width="150">
                                <template #default="{ row }">
                                    {{ row.ota_platform?.name || '-' }}
                                </template>
                            </el-table-column>
                            <el-table-column label="OTA产品ID" width="200">
                                <template #default="{ row }">
                                    <span v-if="row.ota_product_id">{{ row.ota_product_id }}</span>
                                    <span v-else style="color: #909399;">-</span>
                                </template>
                            </el-table-column>
                            <el-table-column prop="is_active" label="状态" width="100">
                                <template #default="{ row }">
                                    <el-tag :type="row.is_active ? 'success' : 'danger'">
                                        {{ row.is_active ? '启用' : '禁用' }}
                                    </el-tag>
                                </template>
                            </el-table-column>
                            <el-table-column label="推送状态" width="150">
                                <template #default="{ row }">
                                    <el-tag
                                        v-if="row.push_status === 'processing'"
                                        type="warning"
                                    >
                                        推送中...
                                    </el-tag>
                                    <el-tag
                                        v-else-if="row.push_status === 'success'"
                                        type="success"
                                    >
                                        推送成功
                                    </el-tag>
                                    <el-tag
                                        v-else-if="row.push_status === 'failed'"
                                        type="danger"
                                    >
                                        推送失败
                                    </el-tag>
                                    <el-tag
                                        v-else-if="row.pushed_at"
                                        type="success"
                                    >
                                        已推送
                                    </el-tag>
                                    <el-tag
                                        v-else
                                        type="info"
                                    >
                                        未推送
                                    </el-tag>
                                </template>
                            </el-table-column>
                            <el-table-column prop="pushed_at" label="推送时间" width="180">
                                <template #default="{ row }">
                                    {{ row.pushed_at ? formatDate(row.pushed_at) : '-' }}
                                </template>
                            </el-table-column>
                            <el-table-column label="操作" width="250" fixed="right">
                                <template #default="{ row }">
                                    <el-button size="small" @click="handleEditOtaProduct(row)">编辑</el-button>
                                    <el-button size="small" type="danger" @click="handleDeleteOtaProduct(row)">删除</el-button>
                                    <el-button
                                        size="small"
                                        type="primary"
                                        @click="handlePushOtaProduct(row)"
                                        :disabled="!row.is_active || row.push_status === 'processing'"
                                        :loading="row.push_status === 'processing'"
                                    >
                                        {{ row.push_status === 'processing' ? '推送中...' : (row.pushed_at ? '重新推送' : '推送') }}
                                    </el-button>
                                </template>
                            </el-table-column>
                        </el-table>

                        <!-- OTA绑定对话框 -->
                        <el-dialog
                            v-model="otaBindDialogVisible"
                            :title="'绑定OTA平台'"
                            width="500px"
                        >
                            <el-form :model="otaBindForm" label-width="120px">
                                <el-form-item label="选择OTA平台" required>
                                    <el-select
                                        v-model="otaBindForm.ota_platform_id"
                                        placeholder="请选择要绑定的OTA平台"
                                        style="width: 100%"
                                    >
                                        <el-option
                                            v-for="platform in otaPlatforms"
                                            :key="platform.id"
                                            :label="platform.name"
                                            :value="platform.id"
                                        />
                                    </el-select>
                                </el-form-item>
                            </el-form>
                            <template #footer>
                                <el-button @click="otaBindDialogVisible = false">取消</el-button>
                                <el-button type="primary" @click="handleSubmitBindOta" :loading="otaBindSubmitting">确定</el-button>
                            </template>
                        </el-dialog>
                    </el-tab-pane>
                </el-tabs>
            </div>
        </el-card>
    </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import axios from '../../utils/axios';
import { ElMessage, ElMessageBox } from 'element-plus';

const route = useRoute();
const router = useRouter();

const loading = ref(false);
const pricesLoading = ref(false);
const priceRulesLoading = ref(false);
const otaProductsLoading = ref(false);
const product = ref(null);
const prices = ref([]);
const priceRules = ref([]);
const otaProducts = ref([]);
const activeTab = ref('prices');

// 价格管理相关
const priceDialogVisible = ref(false);
const priceSubmitting = ref(false);
const priceFormRef = ref(null);
const priceForm = ref({
    room_type_ids: [],
    market_price: 0,
    settlement_price: 0,
    sale_price: 0,
});
const editingPriceId = ref(null);
const priceFilterRoomTypeId = ref(null);
const priceFilterDateRange = ref(null);
const expandedRoomTypes = ref([]); // 展开的房型ID列表

// 加价规则管理相关
const priceRuleDialogVisible = ref(false);
const priceRuleSubmitting = ref(false);
const priceRuleFormRef = ref(null);
const priceRuleForm = ref({
    name: '',
    type: 'weekday',
    weekdays: [],
    dateRange: null,
    market_price_adjustment: 0,
    settlement_price_adjustment: 0,
    sale_price_adjustment: 0,
    is_active: true,
    items: [{ hotel_id: null, room_type_id: null }],
});
const editingPriceRuleId = ref(null);

const hotels = ref([]);
const roomTypes = ref([]);
const allRoomTypes = ref([]);
const otaPlatforms = ref([]);

// OTA推送相关
const otaBindDialogVisible = ref(false);
const otaBindSubmitting = ref(false);
const otaBindForm = ref({
    ota_platform_id: null,
});

// 轮询相关
const pollingIntervals = ref({}); // 存储每个 ota_product_id 的轮询定时器

// 表单验证规则
const priceFormRules = {
    room_type_ids: [
        {
            required: true,
            message: '请至少选择一个房型',
            trigger: 'change',
            validator: (rule, value, callback) => {
                if (!value || value.length === 0) {
                    callback(new Error('请至少选择一个房型'));
                } else {
                    callback();
                }
            }
        }
    ],
    market_price: [{ required: true, message: '请输入门市价', trigger: 'blur' }],
    settlement_price: [{ required: true, message: '请输入结算价', trigger: 'blur' }],
    sale_price: [{ required: true, message: '请输入销售价', trigger: 'blur' }],
};

const priceRuleFormRules = {
    name: [{ required: true, message: '请输入规则名称', trigger: 'blur' }],
    type: [{ required: true, message: '请选择规则类型', trigger: 'change' }],
    weekdays: [
        {
            validator: (rule, value, callback) => {
                if (priceRuleForm.value.type === 'weekday' && (!value || value.length === 0)) {
                    callback(new Error('请至少选择一个周几'));
                } else {
                    callback();
                }
            },
            trigger: 'change',
        },
    ],
    dateRange: [
        {
            validator: (rule, value, callback) => {
                if (priceRuleForm.value.type === 'date_range' && (!value || value.length !== 2)) {
                    callback(new Error('请选择日期区间'));
                } else {
                    callback();
                }
            },
            trigger: 'change',
        },
    ],
    market_price_adjustment: [{ required: true, message: '请输入门市价调整', trigger: 'blur' }],
    settlement_price_adjustment: [{ required: true, message: '请输入结算价调整', trigger: 'blur' }],
    sale_price_adjustment: [{ required: true, message: '请输入销售价调整', trigger: 'blur' }],
    items: [
        {
            validator: (rule, value, callback) => {
                if (!value || value.length === 0) {
                    callback(new Error('请至少添加一个适用房型'));
                } else {
                    const hasInvalid = value.some(item => !item.hotel_id || !item.room_type_id);
                    if (hasInvalid) {
                        callback(new Error('请完善所有房型信息'));
                    } else {
                        callback();
                    }
                }
            },
            trigger: 'change',
        },
    ],
};

const priceDialogTitle = computed(() => editingPriceId.value ? '编辑价格' : '添加价格');
const priceRuleDialogTitle = computed(() => editingPriceRuleId.value ? '编辑加价规则' : '添加加价规则');

// 获取已添加价格的酒店和房型列表（用于加价规则）
const availableHotelsForPriceRule = computed(() => {
    if (!prices.value || prices.value.length === 0) {
        return [];
    }

    // 获取所有已添加价格的房型ID
    const roomTypeIds = [...new Set(prices.value.map(p => p.room_type_id))];

    // 获取这些房型对应的酒店
    const hotelIds = new Set();
    roomTypeIds.forEach(roomTypeId => {
        const roomType = allRoomTypes.value.find(rt => rt.id === roomTypeId);
        if (roomType && roomType.hotel_id) {
            hotelIds.add(roomType.hotel_id);
        }
    });

    // 返回这些酒店
    return hotels.value.filter(h => hotelIds.has(h.id));
});

// 获取指定酒店下已添加价格的房型列表
const getAvailableRoomTypesForPriceRule = (hotelId) => {
    if (!hotelId) return [];

    // 获取所有已添加价格的房型ID
    const roomTypeIdsWithPrice = new Set(prices.value.map(p => p.room_type_id));

    // 返回该酒店下已添加价格的房型
    return allRoomTypes.value.filter(rt =>
        rt.hotel_id === hotelId && roomTypeIdsWithPrice.has(rt.id)
    );
};

// 价格筛选和分组
const filteredPrices = computed(() => {
    let result = prices.value || [];

    // 按房型筛选
    if (priceFilterRoomTypeId.value) {
        result = result.filter(p => p.room_type_id === priceFilterRoomTypeId.value);
    }

    // 按日期范围筛选
    if (priceFilterDateRange.value && priceFilterDateRange.value.length === 2) {
        const [startDate, endDate] = priceFilterDateRange.value;
        result = result.filter(p => {
            const priceDate = p.date;
            return priceDate >= startDate && priceDate <= endDate;
        });
    }

    return result;
});

// 按房型分组价格，并合并连续日期
const groupedPrices = computed(() => {
    const groups = {};

    filteredPrices.value.forEach(price => {
        const roomTypeId = price.room_type_id;
        if (!groups[roomTypeId]) {
            // 查找房型和酒店信息
            const roomType = allRoomTypes.value.find(rt => rt.id === roomTypeId);
            const hotel = hotels.value.find(h => h.id === roomType?.hotel_id);

            groups[roomTypeId] = {
                roomTypeId,
                hotelId: roomType?.hotel_id,
                hotelName: hotel?.name || '未知酒店',
                roomTypeName: roomType?.name || '未知房型',
                prices: [],
                dateRanges: [], // 合并后的日期范围
            };
        }
        groups[roomTypeId].prices.push(price);
    });

    // 对每个分组的价格按日期排序，并合并连续日期
    Object.values(groups).forEach(group => {
        group.prices.sort((a, b) => new Date(a.date) - new Date(b.date));

        // 按价格值分组（相同价格的连续日期合并为一个范围）
        const priceGroups = {};
        group.prices.forEach(price => {
            const key = `${price.market_price}_${price.settlement_price}_${price.sale_price}_${price.source}`;
            if (!priceGroups[key]) {
                priceGroups[key] = {
                    market_price: price.market_price,
                    settlement_price: price.settlement_price,
                    sale_price: price.sale_price,
                    source: price.source,
                    dates: [],
                };
            }
            priceGroups[key].dates.push(price.date);
        });

        // 将日期数组转换为日期范围
        group.dateRanges = Object.values(priceGroups).map(pg => {
            const sortedDates = pg.dates.sort();
            let ranges = [];
            let start = sortedDates[0];
            let end = sortedDates[0];

            for (let i = 1; i < sortedDates.length; i++) {
                const current = new Date(sortedDates[i]);
                const prev = new Date(sortedDates[i - 1]);
                const diffDays = (current - prev) / (1000 * 60 * 60 * 24);

                if (diffDays === 1) {
                    // 连续日期，扩展范围
                    end = sortedDates[i];
                } else {
                    // 不连续，保存当前范围，开始新范围
                    ranges.push({ start, end, ...pg });
                    start = sortedDates[i];
                    end = sortedDates[i];
                }
            }
            ranges.push({ start, end, ...pg });

            return ranges;
        }).flat();
    });

    // 计算每个分组的日期范围显示
    Object.values(groups).forEach(group => {
        if (group.prices.length > 0) {
            const dates = group.prices.map(p => p.date).sort();
            group.dateRange = `${formatDateOnly(dates[0])} 至 ${formatDateOnly(dates[dates.length - 1])}`;
        } else {
            group.dateRange = '-';
        }
    });

    return Object.values(groups);
});

const goBack = () => {
    router.push('/products');
};

const fetchProduct = async () => {
    loading.value = true;
    try {
        const response = await axios.get(`/products/${route.params.id}`);

        // 检查响应数据格式
        if (!response.data || !response.data.data) {
            throw new Error('返回数据格式错误');
        }

        product.value = response.data.data;
        prices.value = product.value.prices || [];
        priceRules.value = product.value.price_rules || [];
        otaProducts.value = product.value.ota_products || [];

        // 如果产品已加载，获取酒店和房型列表
        if (product.value && product.value.scenic_spot_id) {
            await fetchHotels();
            await fetchRoomTypes();
        }
    } catch (error) {
        const errorMessage = error.response?.data?.message || error.response?.data?.error || error.message || '获取产品详情失败';
        ElMessage.error(errorMessage);
        console.error('获取产品详情失败:', {
            error,
            response: error.response,
            status: error.response?.status,
            data: error.response?.data,
        });
        // 如果是权限错误，返回产品列表
        if (error.response?.status === 403) {
            setTimeout(() => {
                router.push('/products');
            }, 2000);
        }
    } finally {
        loading.value = false;
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


const formatDateOnly = (date) => {
    if (!date) return '';
    const d = new Date(date);
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
};


const formatPrice = (price) => {
    if (!price) return '0.00';
    return parseFloat(price).toFixed(2);
};

const formatWeekdays = (weekdays) => {
    if (!weekdays) return '';
    const weekdayMap = {
        '1': '一',
        '2': '二',
        '3': '三',
        '4': '四',
        '5': '五',
        '6': '六',
        '7': '日',
    };
    return weekdays.split(',').map(w => weekdayMap[w] || w).join('、');
};

const handleAddPrice = () => {
    // 检查产品是否有销售日期范围
    if (!product.value.sale_start_date || !product.value.sale_end_date) {
        ElMessage.warning('请先在产品编辑页面设置销售开始日期和结束日期');
        return;
    }

    editingPriceId.value = null;
    resetPriceForm();
    priceDialogVisible.value = true;
};

const handleEditPrice = (row) => {
    editingPriceId.value = row.id;
    priceForm.value = {
        room_type_ids: [row.room_type_id], // 编辑时只显示当前房型
        market_price: parseFloat(row.market_price),
        settlement_price: parseFloat(row.settlement_price),
        sale_price: parseFloat(row.sale_price),
    };
    priceDialogVisible.value = true;
};

const handleDeletePrice = async (row) => {
    try {
        await ElMessageBox.confirm('确定要删除该价格记录吗？', '提示', {
            type: 'warning',
        });
        await axios.delete(`/prices/${row.id}`);
        ElMessage.success('删除成功');
        fetchProduct();
    } catch (error) {
        if (error !== 'cancel') {
            ElMessage.error('删除失败');
        }
    }
};

// 编辑日期范围价格
const handleEditPriceRange = (group, range) => {
    // 找到该日期范围内的所有价格记录
    const pricesInRange = group.prices.filter(p => {
        const priceDate = p.date;
        return priceDate >= range.start && priceDate <= range.end;
    });

    if (pricesInRange.length === 0) return;

    // 如果只有一个日期，直接编辑
    if (pricesInRange.length === 1) {
        handleEditPrice(pricesInRange[0]);
        return;
    }

    // 多个日期，提示用户选择编辑方式
    ElMessageBox.confirm(
        `该日期范围包含 ${pricesInRange.length} 天的价格记录。是否批量编辑为相同价格？`,
        '批量编辑',
        {
            confirmButtonText: '批量编辑',
            cancelButtonText: '取消',
            type: 'info',
        }
    ).then(() => {
        // 批量编辑：使用第一个价格作为初始值
        const firstPrice = pricesInRange[0];
        editingPriceId.value = null; // 批量编辑时，创建新的价格记录
        priceForm.value = {
            room_type_ids: [group.roomTypeId], // 批量编辑时只显示当前房型
            market_price: parseFloat(firstPrice.market_price),
            settlement_price: parseFloat(firstPrice.settlement_price),
            sale_price: parseFloat(firstPrice.sale_price),
        };
        priceDialogVisible.value = true;
    }).catch(() => {
        // 用户取消
    });
};

// 删除日期范围价格
const handleDeletePriceRange = async (group, range) => {
    try {
        const pricesInRange = group.prices.filter(p => {
            const priceDate = p.date;
            return priceDate >= range.start && priceDate <= range.end;
        });

        if (pricesInRange.length === 0) return;

        await ElMessageBox.confirm(
            `确定要删除该日期范围（${formatDateOnly(range.start)} 至 ${formatDateOnly(range.end)}）的 ${pricesInRange.length} 条价格记录吗？`,
            '提示',
            {
                type: 'warning',
            }
        );

        // 批量删除
        await Promise.all(pricesInRange.map(p => axios.delete(`/prices/${p.id}`)));
        ElMessage.success('删除成功');
        fetchProduct();
    } catch (error) {
        if (error !== 'cancel') {
            ElMessage.error('删除失败');
        }
    }
};

const fetchHotels = async () => {
    try {
        const response = await axios.get('/hotels', {
            params: {
                scenic_spot_id: product.value.scenic_spot_id,
                per_page: 1000,
            },
        });
        hotels.value = response.data.data || [];
    } catch (error) {
        console.error('获取酒店列表失败', error);
    }
};

const fetchRoomTypes = async () => {
    try {
        const response = await axios.get('/room-types', {
            params: {
                per_page: 1000,
            },
        });
        allRoomTypes.value = response.data.data || [];
        roomTypes.value = allRoomTypes.value.filter(rt => {
            return hotels.value.some(h => h.id === rt.hotel_id);
        });
    } catch (error) {
        console.error('获取房型列表失败', error);
    }
};

const getRoomTypesByHotel = (hotelId) => {
    if (!hotelId) return [];
    return allRoomTypes.value.filter(rt => rt.hotel_id === hotelId);
};

const handleRoomTypeChange = () => {
    // 房型改变时的处理
};

// 价格筛选处理
const handlePriceFilter = () => {
    // 筛选逻辑已在 computed 中实现，这里可以添加其他逻辑
    // 比如自动展开匹配的房型
    if (priceFilterRoomTypeId.value && !expandedRoomTypes.value.includes(priceFilterRoomTypeId.value)) {
        expandedRoomTypes.value.push(priceFilterRoomTypeId.value);
    }
};

// 重置价格筛选
const resetPriceFilter = () => {
    priceFilterRoomTypeId.value = null;
    priceFilterDateRange.value = null;
};

const handleSubmitPrice = async () => {
    if (!priceFormRef.value) return;

    await priceFormRef.value.validate(async (valid) => {
        if (valid) {
            priceSubmitting.value = true;
            try {
                if (editingPriceId.value) {
                    // 编辑模式：只更新单个价格记录的价格值
                    // 注意：编辑模式下，room_type_ids 只包含一个房型，但后端只需要价格值
                    await axios.put(`/prices/${editingPriceId.value}`, {
                        market_price: priceForm.value.market_price,
                        settlement_price: priceForm.value.settlement_price,
                        sale_price: priceForm.value.sale_price,
                    });
                    ElMessage.success('价格更新成功');
                } else {
                    // 检查产品是否有销售日期范围
                    if (!product.value.sale_start_date || !product.value.sale_end_date) {
                        ElMessage.warning('请先在产品编辑页面设置销售开始日期和结束日期');
                        priceSubmitting.value = false;
                        return;
                    }

                    // 使用产品的销售日期范围
                    const startDate = product.value.sale_start_date;
                    const endDate = product.value.sale_end_date;
                    const prices = [];
                    const start = new Date(startDate);
                    const end = new Date(endDate);

                    for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
                        prices.push({
                            date: d.toISOString().split('T')[0],
                            market_price: priceForm.value.market_price,
                            settlement_price: priceForm.value.settlement_price,
                            sale_price: priceForm.value.sale_price,
                        });
                    }

                    // 为每个选中的房型批量创建价格
                    const promises = priceForm.value.room_type_ids.map(roomTypeId => {
                        return axios.post('/prices', {
                            product_id: product.value.id,
                            room_type_id: roomTypeId,
                            prices: prices,
                        });
                    });

                    await Promise.all(promises);
                    ElMessage.success(`成功为 ${priceForm.value.room_type_ids.length} 个房型创建价格`);
                }
                priceDialogVisible.value = false;
                resetPriceForm();
                fetchProduct();
            } catch (error) {
                const message = error.response?.data?.message || '操作失败';
                ElMessage.error(message);
            } finally {
                priceSubmitting.value = false;
            }
        }
    });
};

const resetPriceForm = () => {
    priceForm.value = {
        room_type_ids: [],
        market_price: 0,
        settlement_price: 0,
        sale_price: 0,
    };
    editingPriceId.value = null;
    priceFormRef.value?.clearValidate();
};

const handleAddPriceRule = () => {
    editingPriceRuleId.value = null;
    resetPriceRuleForm();
    priceRuleDialogVisible.value = true;
};

const handleEditPriceRule = async (row) => {
    editingPriceRuleId.value = row.id;
    try {
        // 如果 row 已经包含 items，直接使用，否则从 API 获取
        const rule = row.items ? row : (await axios.get(`/price-rules/${row.id}`)).data.data || row;

        priceRuleForm.value = {
            name: rule.name,
            type: rule.type,
            weekdays: rule.weekdays ? rule.weekdays.split(',') : [],
            dateRange: rule.start_date && rule.end_date ? [rule.start_date, rule.end_date] : null,
            market_price_adjustment: parseFloat(rule.market_price_adjustment) / 100,
            settlement_price_adjustment: parseFloat(rule.settlement_price_adjustment) / 100,
            sale_price_adjustment: parseFloat(rule.sale_price_adjustment) / 100,
            is_active: rule.is_active,
            items: rule.items && rule.items.length > 0
                ? rule.items.map(item => ({
                    hotel_id: item.hotel_id,
                    room_type_id: item.room_type_id,
                }))
                : [{ hotel_id: null, room_type_id: null }],
        };
        priceRuleDialogVisible.value = true;
    } catch (error) {
        ElMessage.error('获取加价规则详情失败');
    }
};

const handleDeletePriceRule = async (row) => {
    try {
        await ElMessageBox.confirm('确定要删除该加价规则吗？', '提示', {
            type: 'warning',
        });
        await axios.delete(`/price-rules/${row.id}`);
        ElMessage.success('删除成功');
        fetchProduct();
    } catch (error) {
        if (error !== 'cancel') {
            ElMessage.error('删除失败');
        }
    }
};

const addPriceRuleItem = () => {
    priceRuleForm.value.items.push({ hotel_id: null, room_type_id: null });
};

const removePriceRuleItem = (index) => {
    priceRuleForm.value.items.splice(index, 1);
};

const handlePriceRuleHotelChange = (index) => {
    priceRuleForm.value.items[index].room_type_id = null;
};

const handleSubmitPriceRule = async () => {
    if (!priceRuleFormRef.value) return;

    await priceRuleFormRef.value.validate(async (valid) => {
        if (valid) {
            priceRuleSubmitting.value = true;
            try {
                const data = {
                    product_id: product.value.id,
                    name: priceRuleForm.value.name,
                    type: priceRuleForm.value.type,
                    market_price_adjustment: priceRuleForm.value.market_price_adjustment,
                    settlement_price_adjustment: priceRuleForm.value.settlement_price_adjustment,
                    sale_price_adjustment: priceRuleForm.value.sale_price_adjustment,
                    is_active: priceRuleForm.value.is_active,
                    items: priceRuleForm.value.items,
                };

                if (priceRuleForm.value.type === 'weekday') {
                    data.weekdays = priceRuleForm.value.weekdays.join(',');
                } else {
                    const [startDate, endDate] = priceRuleForm.value.dateRange;
                    data.start_date = startDate;
                    data.end_date = endDate;
                }

                if (editingPriceRuleId.value) {
                    await axios.put(`/price-rules/${editingPriceRuleId.value}`, data);
                    ElMessage.success('加价规则更新成功');
                } else {
                    await axios.post('/price-rules', data);
                    ElMessage.success('加价规则创建成功');
                }

                priceRuleDialogVisible.value = false;
                resetPriceRuleForm();
                fetchProduct();
            } catch (error) {
                const message = error.response?.data?.message || '操作失败';
                ElMessage.error(message);
            } finally {
                priceRuleSubmitting.value = false;
            }
        }
    });
};

const resetPriceRuleForm = () => {
    priceRuleForm.value = {
        name: '',
        type: 'weekday',
        weekdays: [],
        dateRange: null,
        market_price_adjustment: 0,
        settlement_price_adjustment: 0,
        sale_price_adjustment: 0,
        is_active: true,
        items: [{ hotel_id: null, room_type_id: null }],
    };
    editingPriceRuleId.value = null;
    priceRuleFormRef.value?.clearValidate();
};

const fetchOtaPlatforms = async () => {
    try {
        const response = await axios.get('/ota-platforms', {
            params: {
                active_only: true,
            },
        });
        otaPlatforms.value = response.data.data || [];
    } catch (error) {
        console.error('获取OTA平台列表失败', error);
    }
};

const handleBindOta = () => {
    otaBindForm.value = {
        ota_platform_id: null,
    };
    otaBindDialogVisible.value = true;
};

const handleSubmitBindOta = async () => {
    if (!otaBindForm.value.ota_platform_id) {
        ElMessage.warning('请选择OTA平台');
        return;
    }

    otaBindSubmitting.value = true;
    try {
        const response = await axios.post(`/products/${route.params.id}/bind-ota`, {
            ota_platform_id: otaBindForm.value.ota_platform_id,
        });

        if (response.data.success) {
            ElMessage.success('绑定成功');
            otaBindDialogVisible.value = false;
            fetchProduct();
        } else {
            ElMessage.error(response.data.message || '绑定失败');
        }
    } catch (error) {
        const message = error.response?.data?.message || '绑定失败';
        ElMessage.error(message);
    } finally {
        otaBindSubmitting.value = false;
    }
};

const handlePushOtaProduct = async (row) => {
    try {
        await ElMessageBox.confirm(
            `确定要推送产品到 ${row.ota_platform?.name} 吗？`,
            '确认推送',
            {
                type: 'warning',
                confirmButtonText: '确定推送',
                cancelButtonText: '取消'
            }
        );

        const response = await axios.post(`/ota-products/${row.id}/push`);

        if (response.data.success) {
            // 如果是异步推送，提示用户
            if (response.data.message && response.data.message.includes('后台处理')) {
                ElMessage.success('推送任务已提交，正在后台处理中');
            } else {
                ElMessage.success('推送成功');
            }

            // 更新本地数据中的推送状态
            if (response.data.data) {
                row.push_status = response.data.data.push_status;
                row.push_started_at = response.data.data.push_started_at;
            }

            // 如果是异步推送，开始轮询状态
            if (response.data.data?.push_status === 'processing') {
                startPollingPushStatus(row.id);
            } else {
                // 同步推送成功，刷新完整数据
                fetchProduct();
            }
        } else {
            ElMessage.error(response.data.message || '推送失败');
        }
    } catch (error) {
        if (error !== 'cancel') {
            const message = error.response?.data?.message || '推送失败';
            ElMessage.error(message);
        }
    }
};

const handleEditOtaProduct = async (row) => {
    try {
        // 如果已推送，只能修改状态；如果未推送，可以修改平台和状态
        const canChangePlatform = !row.pushed_at;

        if (canChangePlatform) {
            // 未推送：可以修改平台和状态
            await ElMessageBox.prompt(
                '请选择OTA平台',
                '编辑OTA产品',
                {
                    confirmButtonText: '确定',
                    cancelButtonText: '取消',
                    inputType: 'select',
                    inputOptions: otaPlatforms.value.reduce((acc, platform) => {
                        acc[platform.id] = platform.name;
                        return acc;
                    }, {}),
                    inputValue: row.ota_platform_id?.toString(),
                }
            ).then(async ({ value }) => {
                const platformId = parseInt(value);
                await axios.put(`/ota-products/${row.id}`, {
                    ota_platform_id: platformId,
                    is_active: row.is_active,
                });
                ElMessage.success('更新成功');
                fetchProduct();
            });
        } else {
            // 已推送：只能修改状态
            await ElMessageBox.prompt('请选择状态', '编辑OTA产品', {
                confirmButtonText: '确定',
                cancelButtonText: '取消',
                inputType: 'select',
                inputOptions: {
                    true: '启用',
                    false: '禁用',
                },
                inputValue: row.is_active ? 'true' : 'false',
            }).then(async ({ value }) => {
                const isActive = value === 'true';
                await axios.put(`/ota-products/${row.id}`, {
                    is_active: isActive,
                });
                ElMessage.success('更新成功');
                fetchProduct();
            });
        }
    } catch (error) {
        if (error !== 'cancel') {
            const message = error.response?.data?.message || '更新失败';
            ElMessage.error(message);
        }
    }
};

const handleDeleteOtaProduct = async (row) => {
    try {
        await ElMessageBox.confirm('确定要删除该OTA推送记录吗？', '提示', {
            type: 'warning',
        });
        await axios.delete(`/ota-products/${row.id}`);
        ElMessage.success('删除成功');
        fetchProduct();
    } catch (error) {
        if (error !== 'cancel') {
            const message = error.response?.data?.message || '删除失败';
            ElMessage.error(message);
        }
    }
};

// 开始轮询推送状态
const startPollingPushStatus = (otaProductId) => {
    // 清除旧的定时器（如果存在）
    if (pollingIntervals.value[otaProductId]) {
        clearInterval(pollingIntervals.value[otaProductId]);
    }

    pollingIntervals.value[otaProductId] = setInterval(async () => {
        try {
            const response = await axios.get(`/products/${route.params.id}`);
            const updatedOtaProduct = response.data.data?.ota_products?.find(op => op.id === otaProductId);

            if (updatedOtaProduct) {
                // 更新本地数据中的推送状态
                const localOtaProduct = otaProducts.value.find(op => op.id === otaProductId);
                if (localOtaProduct) {
                    localOtaProduct.push_status = updatedOtaProduct.push_status;
                    localOtaProduct.push_message = updatedOtaProduct.push_message;
                    localOtaProduct.push_completed_at = updatedOtaProduct.push_completed_at;
                }

                // 如果状态不再是处理中，停止轮询
                if (updatedOtaProduct.push_status !== 'processing') {
                    clearInterval(pollingIntervals.value[otaProductId]);
                    delete pollingIntervals.value[otaProductId];

                    // 显示完成消息
                    if (updatedOtaProduct.push_status === 'success') {
                        ElMessage.success(`产品 ${updatedOtaProduct.ota_platform?.name} 推送成功`);
                    } else if (updatedOtaProduct.push_status === 'failed') {
                        ElMessage.error(`产品 ${updatedOtaProduct.ota_platform?.name} 推送失败：${updatedOtaProduct.push_message || '未知错误'}`);
                    }
                }
            } else {
                // 如果找不到该 otaProduct，可能已被删除，停止轮询
                clearInterval(pollingIntervals.value[otaProductId]);
                delete pollingIntervals.value[otaProductId];
            }
        } catch (error) {
            console.error('轮询推送状态失败', error);
            // 出错时也停止轮询，避免无限重试
            clearInterval(pollingIntervals.value[otaProductId]);
            delete pollingIntervals.value[otaProductId];
        }
    }, 3000); // 每3秒轮询一次
};

// 清理所有轮询
const clearAllPolling = () => {
    Object.values(pollingIntervals.value).forEach(interval => {
        clearInterval(interval);
    });
    pollingIntervals.value = {};
};

onMounted(async () => {
    await fetchProduct();
    await fetchOtaPlatforms();
    if (product.value) {
        await fetchHotels();
        await fetchRoomTypes();
    }
});

onUnmounted(() => {
    // 组件卸载时清理所有轮询
    clearAllPolling();
});


</script>

<style scoped>
.el-page-header {
    margin-bottom: 20px;
}
</style>
