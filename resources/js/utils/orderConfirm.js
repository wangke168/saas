import { ElMessageBox } from 'element-plus';

/**
 * @param {object} order
 * @returns {boolean}
 */
export function needsResourceOrderNoInput(order) {
    return order?.requires_resource_order_no_input === true;
}

/**
 * 弹出接单确认框；需填资源方单号时要求输入。
 *
 * @param {object} order
 * @returns {Promise<{ remark: string, resource_order_no?: string }|null>}
 */
export async function promptConfirmOrder(order) {
    if (needsResourceOrderNoInput(order)) {
        try {
            const { value } = await ElMessageBox.prompt(
                '请先在资源方系统完成下单，再填写资源方订单号并确认接单。',
                '接单确认',
                {
                    type: 'info',
                    confirmButtonText: '确认接单',
                    cancelButtonText: '取消',
                    inputPlaceholder: '请输入资源方订单号',
                    inputValidator: (value) => {
                        if (!value || value.trim().length === 0) {
                            return '资源方订单号不能为空';
                        }
                        if (value.trim().length > 100) {
                            return '资源方订单号不能超过100个字符';
                        }
                        return true;
                    },
                }
            );

            return {
                remark: '人工接单：已补录资源方订单号',
                resource_order_no: value.trim(),
            };
        } catch (error) {
            return null;
        }
    }

    const isSystemConnected = order.hotel?.scenic_spot?.is_system_connected
        ?? order.product?.scenic_spot?.is_system_connected;

    try {
        await ElMessageBox.confirm(
            isSystemConnected
                ? '确定要接单吗？系统将自动调用资源方接口确认订单。'
                : '确定要接单吗？',
            '接单确认',
            {
                type: 'info',
                confirmButtonText: '确定',
                cancelButtonText: '取消',
            }
        );

        return { remark: '' };
    } catch (error) {
        return null;
    }
}
