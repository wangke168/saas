<?php

namespace App\Helpers;

/**
 * 携程错误码帮助类
 * 根据 storage/docs/ctrip_code.md 文档定义
 */
class CtripErrorCodeHelper
{
    /**
     * 错误码映射表
     */
    protected static array $errorMessages = [
        // 成功
        '0000' => '操作成功',
        
        // 账户/系统相关
        '0001' => '供应商账户为空',
        '0002' => '签名不正确',
        '0003' => '报文解析失败',
        '0004' => '请求方法为空',
        '0005' => '系统处理异常',
        '0006' => '请求数据异常',
        '0007' => '提交数据超载',
        '0008' => '提交频率过快',
        
        // 资源价格同步相关
        '1001' => '携程资源编号不存在/错误',
        '1002' => '供应商 PLU 不存在/错误',
        '1003' => '数据参数不合法（价格数值或日期格式错误）',
        
        // 资源库存同步相关
        '2001' => '携程资源编号不存在/错误',
        '2002' => '供应商 PLU 不存在/错误',
        '2003' => '数据参数不合法（库存数值或日期格式错误）',
    ];

    /**
     * 判断是否成功
     */
    public static function isSuccess(?string $resultCode): bool
    {
        return $resultCode === '0000';
    }

    /**
     * 获取错误信息
     */
    public static function getErrorMessage(?string $resultCode, ?string $resultMessage = null): string
    {
        if (self::isSuccess($resultCode)) {
            return '操作成功';
        }

        $message = self::$errorMessages[$resultCode] ?? '未知错误';
        
        // 如果有 resultMessage，优先使用（可能包含更详细的错误信息）
        if ($resultMessage && $resultMessage !== 'success') {
            // 清理 resultMessage 中的换行符和多余空格
            $resultMessage = trim(str_replace(["\r\n", "\n", "\r"], ' ', $resultMessage));
            return "{$message}：{$resultMessage}";
        }

        return $message;
    }

    /**
     * 获取错误分类
     */
    public static function getErrorCategory(?string $resultCode): string
    {
        if (self::isSuccess($resultCode)) {
            return '成功';
        }

        if (str_starts_with($resultCode ?? '', '00')) {
            return '账户/系统相关';
        }

        if (str_starts_with($resultCode ?? '', '10')) {
            return '资源价格同步相关';
        }

        if (str_starts_with($resultCode ?? '', '20')) {
            return '资源库存同步相关';
        }

        return '未知分类';
    }
}
