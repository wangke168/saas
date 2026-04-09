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
                    <el-descriptions-item label="不可订时段" :span="2">
                        <template v-if="product.unavailable_periods && product.unavailable_periods.length">
                            <div
                                v-for="(p, i) in product.unavailable_periods"
                                :key="i"
                                style="margin-bottom: 4px;"
                            >
                                {{ formatDateOnly(p.start_date) }} 至 {{ formatDateOnly(p.end_date) }}
                                <span v-if="p.note" style="color: #909399; margin-left: 8px;">（{{ p.note }}）</span>
                            </div>
                        </template>
                        <span v-else style="color: #909399;">未设置</span>
                    </el-descriptions-item>
                    <el-descriptions-item label="外部产品编码（默认）" :span="2">
                        {{ product.external_code || '未设置' }}
                        <span style="margin-left: 10px; color: #909399; font-size: 12px;">
                            （当没有时间段映射时使用此编码）
                        </span>
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
                            <div
                                v-if="priceGroupPagination.total > 0"
                                style="margin-top: 16px; display: flex; justify-content: flex-end;"
                            >
                                <el-pagination
                                    v-model:current-page="priceGroupPagination.current_page"
                                    v-model:page-size="priceGroupPagination.per_page"
                                    :total="priceGroupPagination.total"
                                    :page-sizes="[10, 20, 50]"
                                    layout="total, sizes, prev, pager, next"
                                    @current-change="handlePriceGroupPageChange"
                                    @size-change="handlePriceGroupSizeChange"
                                />
                            </div>
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
                                    <el-tag v-if="row.type === 'weekday'">周几规则</el-tag>
                                    <el-tag v-else-if="row.type === 'date_range'">日期区间规则</el-tag>
                                    <el-tag v-else type="success">统一规则</el-tag>
                                </template>
                            </el-table-column>
                            <el-table-column label="规则内容" width="300">
                                <template #default="{ row }">
                                    <div>
                                        <span v-if="row.start_date && row.end_date">
                                            {{ formatDateOnly(row.start_date) }} 至 {{ formatDateOnly(row.end_date) }}
                                        </span>
                                        <span v-else style="color: #909399;">全时段</span>
                                        <span v-if="row.weekdays" style="margin-left: 10px;">
                                            周{{ formatWeekdays(row.weekdays) }}
                                        </span>
                                        <span v-else-if="row.start_date || row.end_date" style="margin-left: 10px; color: #909399;">
                                            （所有日期）
                                        </span>
                                    </div>
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
                            <el-table-column label="适用房型" min-width="260">
                                <template #default="{ row }">
                                    <div v-if="row.items && row.items.length > 0">
                                        <div
                                            v-for="item in row.items"
                                            :key="`${row.id}-${item.hotel_id}-${item.room_type_id}`"
                                            style="line-height: 1.8;"
                                        >
                                            {{ item.hotel?.name || '未知酒店' }} - {{ item.room_type?.name || '未知房型' }}
                                        </div>
                                    </div>
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
                            <el-table-column label="操作" width="200" v-if="product.price_source === 'manual'">
                                <template #default="{ row }">
                                    <el-button size="small" @click="handleEditPriceRule(row)">编辑</el-button>
                                    <el-button size="small" type="danger" @click="handleDeletePriceRule(row)">删除</el-button>
                                </template>
                            </el-table-column>
                        </el-table>
                        <div
                            v-if="priceRulePagination.total > 0"
                            style="margin-top: 16px; display: flex; justify-content: flex-end;"
                        >
                            <el-pagination
                                v-model:current-page="priceRulePagination.current_page"
                                v-model:page-size="priceRulePagination.per_page"
                                :total="priceRulePagination.total"
                                :page-sizes="[10, 20, 50]"
                                layout="total, sizes, prev, pager, next"
                                @current-change="handlePriceRulePageChange"
                                @size-change="handlePriceRuleSizeChange"
                            />
                        </div>
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
                                <el-form-item label="日期范围（可选）" prop="dateRange">
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
                                    <span style="margin-left: 10px; color: #909399; font-size: 12px;">
                                        不选择表示全时段生效
                                    </span>
                                </el-form-item>
                                <el-form-item label="选择周几（可选）" prop="weekdays">
                                    <el-checkbox-group v-model="priceRuleForm.weekdays">
                                        <el-checkbox label="1">周一</el-checkbox>
                                        <el-checkbox label="2">周二</el-checkbox>
                                        <el-checkbox label="3">周三</el-checkbox>
                                        <el-checkbox label="4">周四</el-checkbox>
                                        <el-checkbox label="5">周五</el-checkbox>
                                        <el-checkbox label="6">周六</el-checkbox>
                                        <el-checkbox label="7">周日</el-checkbox>
                                    </el-checkbox-group>
                                    <span style="margin-left: 10px; color: #909399; font-size: 12px;">
                                        不选择表示日期范围内的所有日期都生效
                                    </span>
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
                                                <div>
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
                                                    <el-button
                                                        type="primary"
                                                        text
                                                        size="small"
                                                        style="padding: 0; margin-top: 6px;"
                                                        :disabled="!row.hotel_id"
                                                        @click="selectAllRoomTypesForHotel($index)"
                                                    >
                                                        选择该酒店全部房型
                                                    </el-button>
                                                </div>
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

                    <!-- 外部编码时间段映射管理标签页 -->
                    <el-tab-pane label="外部编码映射" name="externalCodeMappings">
                        <div style="margin-bottom: 20px;">
                            <el-button type="primary" @click="handleAddExternalCodeMapping">添加时间段映射</el-button>
                            <el-alert
                                type="info"
                                :closable="false"
                                style="margin-top: 10px;"
                            >
                                <template #title>
                                    <span>说明：同一产品在不同时间段可以对应不同的横店系统产品编码。时间段不能重叠。</span>
                                </template>
                            </el-alert>
                        </div>
                        <el-table :data="externalCodeMappings" border v-loading="externalCodeMappingsLoading">
                            <el-table-column prop="external_code" label="横店产品编码" width="200" />
                            <el-table-column label="生效时间段" width="300">
                                <template #default="{ row }">
                                    {{ formatDateOnly(row.start_date) }} 至 {{ formatDateOnly(row.end_date) }}
                                </template>
                            </el-table-column>
                            <el-table-column prop="is_active" label="状态" width="100">
                                <template #default="{ row }">
                                    <el-tag :type="row.is_active ? 'success' : 'danger'">
                                        {{ row.is_active ? '启用' : '禁用' }}
                                    </el-tag>
                                </template>
                            </el-table-column>
                            <el-table-column prop="sort_order" label="排序" width="100" />
                            <el-table-column prop="created_at" label="创建时间" width="180">
                                <template #default="{ row }">
                                    {{ formatDate(row.created_at) }}
                                </template>
                            </el-table-column>
                            <el-table-column label="操作" width="200" fixed="right">
                                <template #default="{ row }">
                                    <el-button size="small" @click="handleEditExternalCodeMapping(row)">编辑</el-button>
                                    <el-button size="small" type="danger" @click="handleDeleteExternalCodeMapping(row)">删除</el-button>
                                </template>
                            </el-table-column>
                        </el-table>

                        <!-- 外部编码映射对话框 -->
                        <el-dialog
                            v-model="externalCodeMappingDialogVisible"
                            :title="externalCodeMappingDialogTitle"
                            width="600px"
                            @close="resetExternalCodeMappingForm"
                        >
                            <el-form
                                ref="externalCodeMappingFormRef"
                                :model="externalCodeMappingForm"
                                :rules="externalCodeMappingFormRules"
                                label-width="140px"
                            >
                                <el-form-item label="横店产品编码" prop="external_code">
                                    <el-input
                                        v-model="externalCodeMappingForm.external_code"
                                        placeholder="请输入横店系统产品编码"
                                    />
                                </el-form-item>
                                <el-form-item label="开始日期" prop="start_date">
                                    <el-date-picker
                                        v-model="externalCodeMappingForm.start_date"
                                        type="date"
                                        placeholder="选择开始日期"
                                        format="YYYY-MM-DD"
                                        value-format="YYYY-MM-DD"
                                        style="width: 100%"
                                    />
                                </el-form-item>
                                <el-form-item label="结束日期" prop="end_date">
                                    <el-date-picker
                                        v-model="externalCodeMappingForm.end_date"
                                        type="date"
                                        placeholder="选择结束日期"
                                        format="YYYY-MM-DD"
                                        value-format="YYYY-MM-DD"
                                        style="width: 100%"
                                    />
                                </el-form-item>
                                <el-form-item label="状态" prop="is_active">
                                    <el-switch v-model="externalCodeMappingForm.is_active" />
                                </el-form-item>
                                <el-form-item label="排序" prop="sort_order">
                                    <el-input-number
                                        v-model="externalCodeMappingForm.sort_order"
                                        :min="0"
                                        style="width: 100%"
                                    />
                                    <span style="margin-left: 10px; color: #909399; font-size: 12px;">
                                        数字越小越优先（当时间段重叠时）
                                    </span>
                                </el-form-item>
                            </el-form>
                            <template #footer>
                                <el-button @click="externalCodeMappingDialogVisible = false">取消</el-button>
                                <el-button type="primary" @click="handleSubmitExternalCodeMapping" :loading="externalCodeMappingSubmitting">确定</el-button>
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
import { ref, onMounted, onUnmounted, computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import axios from '../../utils/axios';
import { ElMessage, ElMessageBox } from 'element-plus';

const route = useRoute();
const router = useRouter();

const loading = ref(false);
const pricesLoading = ref(false);
const priceRulesLoading = ref(false);
const otaProductsLoading = ref(false);
const externalCodeMappingsLoading = ref(false);
const product = ref(null);
const groupedPrices = ref([]);
const priceRules = ref([]);
const otaProducts = ref([]);
const externalCodeMappings = ref([]);
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
const priceGroupPagination = ref({
    current_page: 1,
    per_page: 10,
    total: 0,
    last_page: 0,
});
const priceRoomTypeIds = ref([]);

// 加价规则管理相关
const priceRuleDialogVisible = ref(false);
const priceRuleSubmitting = ref(false);
const priceRuleFormRef = ref(null);
const priceRulePagination = ref({
    current_page: 1,
    per_page: 10,
    total: 0,
    last_page: 0,
});
const priceRuleForm = ref({
    name: '',
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

// 外部编码映射相关
const externalCodeMappingDialogVisible = ref(false);
const externalCodeMappingSubmitting = ref(false);
const externalCodeMappingFormRef = ref(null);
const editingExternalCodeMappingId = ref(null);
const externalCodeMappingForm = ref({
    external_code: '',
    start_date: '',
    end_date: '',
    is_active: true,
    sort_order: 0,
});

const externalCodeMappingFormRules = {
    external_code: [{ required: true, message: '请输入横店产品编码', trigger: 'blur' }],
    start_date: [{ required: true, message: '请选择开始日期', trigger: 'change' }],
    end_date: [
        { required: true, message: '请选择结束日期', trigger: 'change' },
        {
            validator: (rule, value, callback) => {
                if (externalCodeMappingForm.value.start_date && value) {
                    if (new Date(value) < new Date(externalCodeMappingForm.value.start_date)) {
                        callback(new Error('结束日期不能早于开始日期'));
                    } else {
                        callback();
                    }
                } else {
                    callback();
                }
            },
            trigger: 'change',
        },
    ],
};

const externalCodeMappingDialogTitle = computed(() => 
    editingExternalCodeMappingId.value ? '编辑时间段映射' : '添加时间段映射'
);

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
    weekdays: [
        {
            validator: (rule, value, callback) => {
                // 周几可选，不需要验证
                callback();
            },
            trigger: 'change',
        },
    ],
    dateRange: [
        {
            validator: (rule, value, callback) => {
                // 日期范围可选，但如果选择了必须完整
                if (value && value.length !== 2) {
                    callback(new Error('请选择完整的日期区间'));
                } else {
                    // 至少要有日期范围或周几
                    if (!value && (!priceRuleForm.value.weekdays || priceRuleForm.value.weekdays.length === 0)) {
                        callback(new Error('请至少设置日期范围或周几'));
                    } else {
                        callback();
                    }
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
    if (!priceRoomTypeIds.value.length) {
        return [];
    }

    // 获取这些房型对应的酒店
    const hotelIds = new Set();
    priceRoomTypeIds.value.forEach(roomTypeId => {
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
    const roomTypeIdsWithPrice = new Set(priceRoomTypeIds.value);

    // 返回该酒店下已添加价格的房型
    return allRoomTypes.value.filter(rt =>
        rt.hotel_id === hotelId && roomTypeIdsWithPrice.has(rt.id)
    );
};

const processPriceGroups = (groups = []) => {
    return groups.map(group => {
        const normalizedGroup = {
            roomTypeId: group.room_type_id,
            hotelId: group.hotel_id,
            hotelName: group.hotel_name || '未知酒店',
            roomTypeName: group.room_type_name || '未知房型',
            prices: group.prices || [],
            dateRanges: [],
            dateRange: '-',
        };

        normalizedGroup.prices.sort((a, b) => new Date(a.date) - new Date(b.date));
        const priceGroups = {};
        normalizedGroup.prices.forEach(price => {
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

        normalizedGroup.dateRanges = Object.values(priceGroups).map(pg => {
            const sortedDates = pg.dates.sort();
            let ranges = [];
            let start = sortedDates[0];
            let end = sortedDates[0];

            for (let i = 1; i < sortedDates.length; i++) {
                const current = new Date(sortedDates[i]);
                const prev = new Date(sortedDates[i - 1]);
                const diffDays = (current - prev) / (1000 * 60 * 60 * 24);

                if (diffDays === 1) {
                    end = sortedDates[i];
                } else {
                    ranges.push({ start, end, ...pg });
                    start = sortedDates[i];
                    end = sortedDates[i];
                }
            }

            if (start) {
                ranges.push({ start, end, ...pg });
            }

            return ranges;
        }).flat();

        if (normalizedGroup.prices.length > 0) {
            const dates = normalizedGroup.prices.map(p => p.date).sort();
            normalizedGroup.dateRange = `${formatDateOnly(dates[0])} 至 ${formatDateOnly(dates[dates.length - 1])}`;
        }

        return normalizedGroup;
    });
};

const goBack = () => {
    router.push('/products');
};

const fetchProduct = async () => {
    loading.value = true;
    try {
        const response = await axios.get(`/products/${route.params.id}`, {
            params: {
                include_prices: false,
            },
        });

        // 检查响应数据格式
        if (!response.data || !response.data.data) {
            throw new Error('返回数据格式错误');
        }

        product.value = response.data.data;
        otaProducts.value = product.value.ota_products || [];
        
        // 获取外部编码映射列表
        await fetchExternalCodeMappings();

        // 如果产品已加载，获取酒店和房型列表
        if (product.value && product.value.scenic_spot_id) {
            await fetchHotels();
            await fetchRoomTypes();
        }
        await fetchPriceGroups();
        await fetchPriceRules();
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

const fetchPriceRules = async () => {
    priceRulesLoading.value = true;
    try {
        const response = await axios.get('/price-rules', {
            params: {
                product_id: route.params.id,
                page: priceRulePagination.value.current_page,
                per_page: priceRulePagination.value.per_page,
            },
        });

        priceRules.value = response.data?.data || [];
        priceRulePagination.value = {
            ...priceRulePagination.value,
            current_page: response.data?.current_page ?? priceRulePagination.value.current_page,
            per_page: response.data?.per_page ?? priceRulePagination.value.per_page,
            total: response.data?.total ?? 0,
            last_page: response.data?.last_page ?? 0,
        };

        if (priceRulePagination.value.total > 0 && priceRulePagination.value.current_page > priceRulePagination.value.last_page) {
            priceRulePagination.value.current_page = priceRulePagination.value.last_page;
            await fetchPriceRules();
        }
    } catch (error) {
        ElMessage.error(error.response?.data?.message || '获取加价规则失败');
    } finally {
        priceRulesLoading.value = false;
    }
};

const fetchPriceGroups = async () => {
    pricesLoading.value = true;
    try {
        const params = {
            page: priceGroupPagination.value.current_page,
            per_page: priceGroupPagination.value.per_page,
        };

        if (priceFilterRoomTypeId.value) {
            params.room_type_id = priceFilterRoomTypeId.value;
        }

        if (priceFilterDateRange.value && priceFilterDateRange.value.length === 2) {
            params.start_date = priceFilterDateRange.value[0];
            params.end_date = priceFilterDateRange.value[1];
        }

        const response = await axios.get(`/products/${route.params.id}/price-groups`, { params });
        groupedPrices.value = processPriceGroups(response.data?.data || []);
        priceRoomTypeIds.value = response.data?.available_room_type_ids || [];
        priceGroupPagination.value = {
            ...priceGroupPagination.value,
            ...(response.data?.meta || {}),
        };
    } catch (error) {
        ElMessage.error(error.response?.data?.message || '获取价格数据失败');
    } finally {
        pricesLoading.value = false;
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
    priceGroupPagination.value.current_page = 1;
    expandedRoomTypes.value = [];
    fetchPriceGroups();
};

// 重置价格筛选
const resetPriceFilter = () => {
    priceFilterRoomTypeId.value = null;
    priceFilterDateRange.value = null;
    priceGroupPagination.value.current_page = 1;
    expandedRoomTypes.value = [];
    fetchPriceGroups();
};

const handlePriceGroupPageChange = (page) => {
    priceGroupPagination.value.current_page = page;
    expandedRoomTypes.value = [];
    fetchPriceGroups();
};

const handlePriceGroupSizeChange = (size) => {
    priceGroupPagination.value.per_page = size;
    priceGroupPagination.value.current_page = 1;
    expandedRoomTypes.value = [];
    fetchPriceGroups();
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

        // 兼容旧数据：根据 type 自动填充表单
        let dateRange = null;
        let weekdays = [];
        
        if (rule.type === 'weekday') {
            // 旧格式：只有周几，日期范围为空（全时段）
            weekdays = rule.weekdays ? rule.weekdays.split(',') : [];
            dateRange = null;
        } else if (rule.type === 'date_range') {
            // 旧格式：只有日期范围，周几为空（范围内所有日期）
            dateRange = rule.start_date && rule.end_date 
                ? [rule.start_date, rule.end_date] 
                : null;
            weekdays = [];
        } else {
            // 新格式：同时有日期范围和周几
            dateRange = rule.start_date && rule.end_date 
                ? [rule.start_date, rule.end_date] 
                : null;
            weekdays = rule.weekdays ? rule.weekdays.split(',') : [];
        }

        priceRuleForm.value = {
            name: rule.name || '',
            weekdays: weekdays,
            dateRange: dateRange,
            market_price_adjustment: parseFloat(rule.market_price_adjustment) || 0, // 单位：元
            settlement_price_adjustment: parseFloat(rule.settlement_price_adjustment) || 0, // 单位：元
            sale_price_adjustment: parseFloat(rule.sale_price_adjustment) || 0, // 单位：元
            is_active: rule.is_active ?? true,
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
        await fetchPriceRules();
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

const hasPriceRuleItem = (hotelId, roomTypeId) => {
    return priceRuleForm.value.items.some(
        (item) => item.hotel_id === hotelId && item.room_type_id === roomTypeId
    );
};

const selectAllRoomTypesForHotel = (index) => {
    const currentItem = priceRuleForm.value.items[index];
    if (!currentItem?.hotel_id) {
        ElMessage.warning('请先选择酒店');
        return;
    }

    const roomTypesForHotel = getAvailableRoomTypesForPriceRule(currentItem.hotel_id);
    if (!roomTypesForHotel.length) {
        ElMessage.warning('该酒店暂无可选房型');
        return;
    }

    const itemsToAppend = roomTypesForHotel
        .map((roomType) => ({
            hotel_id: currentItem.hotel_id,
            room_type_id: roomType.id,
        }))
        .filter((item) => !hasPriceRuleItem(item.hotel_id, item.room_type_id));

    if (!itemsToAppend.length) {
        ElMessage.info('该酒店房型已全部添加');
        return;
    }

    if (!currentItem.room_type_id) {
        const [firstItem, ...restItems] = itemsToAppend;
        currentItem.room_type_id = firstItem.room_type_id;
        priceRuleForm.value.items.push(...restItems);
    } else {
        priceRuleForm.value.items.push(...itemsToAppend);
    }

    const duplicateCount = roomTypesForHotel.length - itemsToAppend.length;
    if (duplicateCount > 0) {
        ElMessage.success(`已新增 ${itemsToAppend.length} 个房型，跳过 ${duplicateCount} 个重复项`);
        return;
    }

    ElMessage.success(`已新增 ${itemsToAppend.length} 个房型`);
};

const handlePriceRuleHotelChange = (index) => {
    priceRuleForm.value.items[index].room_type_id = null;
};

const handlePriceRulePageChange = (page) => {
    priceRulePagination.value.current_page = page;
    fetchPriceRules();
};

const handlePriceRuleSizeChange = (size) => {
    priceRulePagination.value.per_page = size;
    priceRulePagination.value.current_page = 1;
    fetchPriceRules();
};

const handleSubmitPriceRule = async () => {
    if (!priceRuleFormRef.value) return;

    await priceRuleFormRef.value.validate(async (valid) => {
        if (valid) {
            // 验证：至少要有日期范围或周几
            if (!priceRuleForm.value.dateRange && (!priceRuleForm.value.weekdays || priceRuleForm.value.weekdays.length === 0)) {
                ElMessage.error('请至少设置日期范围或周几');
                return;
            }

            priceRuleSubmitting.value = true;
            try {
                const data = {
                    product_id: product.value.id,
                    name: priceRuleForm.value.name,
                    type: 'combined', // 统一使用新格式
                    market_price_adjustment: priceRuleForm.value.market_price_adjustment,
                    settlement_price_adjustment: priceRuleForm.value.settlement_price_adjustment,
                    sale_price_adjustment: priceRuleForm.value.sale_price_adjustment,
                    is_active: priceRuleForm.value.is_active,
                    items: priceRuleForm.value.items,
                };

                // 日期范围（可选）
                if (priceRuleForm.value.dateRange && priceRuleForm.value.dateRange.length === 2) {
                    data.start_date = priceRuleForm.value.dateRange[0];
                    data.end_date = priceRuleForm.value.dateRange[1];
                } else {
                    // 显式清空：避免编辑时后端不更新旧的日期范围
                    data.start_date = null;
                    data.end_date = null;
                }

                // 周几（可选）
                const weekdays = priceRuleForm.value.weekdays || [];
                if (weekdays.length > 0) {
                    data.weekdays = weekdays.join(',');
                } else {
                    // 显式清空：避免编辑时后端不更新旧的周几选择
                    data.weekdays = null;
                }

                if (editingPriceRuleId.value) {
                    await axios.put(`/price-rules/${editingPriceRuleId.value}`, data);
                    ElMessage.success('加价规则更新成功');
                } else {
                    await axios.post('/price-rules', data);
                    ElMessage.success('加价规则创建成功');
                    priceRulePagination.value.current_page = 1;
                }

                priceRuleDialogVisible.value = false;
                resetPriceRuleForm();
                await fetchPriceRules();
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
});

watch(activeTab, (tabName) => {
    if (tabName === 'prices') {
        fetchPriceGroups();
    } else if (tabName === 'priceRules') {
        fetchPriceRules();
    }
});

// 获取外部编码映射列表
const fetchExternalCodeMappings = async () => {
    externalCodeMappingsLoading.value = true;
    try {
        const response = await axios.get(`/products/${route.params.id}/external-code-mappings`);
        externalCodeMappings.value = response.data.data || [];
    } catch (error) {
        console.error('获取外部编码映射列表失败', error);
        ElMessage.error('获取外部编码映射列表失败');
    } finally {
        externalCodeMappingsLoading.value = false;
    }
};

// 添加外部编码映射
const handleAddExternalCodeMapping = () => {
    editingExternalCodeMappingId.value = null;
    resetExternalCodeMappingForm();
    externalCodeMappingDialogVisible.value = true;
};

// 编辑外部编码映射
const handleEditExternalCodeMapping = (row) => {
    editingExternalCodeMappingId.value = row.id;
    externalCodeMappingForm.value = {
        external_code: row.external_code,
        start_date: row.start_date,
        end_date: row.end_date,
        is_active: row.is_active,
        sort_order: row.sort_order,
    };
    externalCodeMappingDialogVisible.value = true;
};

// 删除外部编码映射
const handleDeleteExternalCodeMapping = async (row) => {
    try {
        await ElMessageBox.confirm('确定要删除该时间段映射吗？', '提示', {
            type: 'warning',
        });
        await axios.delete(`/products/${route.params.id}/external-code-mappings/${row.id}`);
        ElMessage.success('删除成功');
        await fetchExternalCodeMappings();
    } catch (error) {
        if (error !== 'cancel') {
            const message = error.response?.data?.message || '删除失败';
            ElMessage.error(message);
        }
    }
};

// 提交外部编码映射
const handleSubmitExternalCodeMapping = async () => {
    if (!externalCodeMappingFormRef.value) return;

    await externalCodeMappingFormRef.value.validate(async (valid) => {
        if (valid) {
            externalCodeMappingSubmitting.value = true;
            try {
                const data = {
                    external_code: externalCodeMappingForm.value.external_code,
                    start_date: externalCodeMappingForm.value.start_date,
                    end_date: externalCodeMappingForm.value.end_date,
                    is_active: externalCodeMappingForm.value.is_active,
                    sort_order: externalCodeMappingForm.value.sort_order,
                };

                if (editingExternalCodeMappingId.value) {
                    await axios.put(
                        `/products/${route.params.id}/external-code-mappings/${editingExternalCodeMappingId.value}`,
                        data
                    );
                    ElMessage.success('时间段映射更新成功');
                } else {
                    await axios.post(
                        `/products/${route.params.id}/external-code-mappings`,
                        data
                    );
                    ElMessage.success('时间段映射创建成功');
                }

                externalCodeMappingDialogVisible.value = false;
                resetExternalCodeMappingForm();
                await fetchExternalCodeMappings();
            } catch (error) {
                const message = error.response?.data?.message || '操作失败';
                ElMessage.error(message);
            } finally {
                externalCodeMappingSubmitting.value = false;
            }
        }
    });
};

// 重置外部编码映射表单
const resetExternalCodeMappingForm = () => {
    externalCodeMappingForm.value = {
        external_code: '',
        start_date: '',
        end_date: '',
        is_active: true,
        sort_order: 0,
    };
    editingExternalCodeMappingId.value = null;
    externalCodeMappingFormRef.value?.clearValidate();
};

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
