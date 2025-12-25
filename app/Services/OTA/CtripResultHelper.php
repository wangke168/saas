<?php

namespace App\Services\OTA;

class CtripResultHelper
{
    /**
     * 根据携程返回码判断是否成功
     * 
     * @param array $result 携程API返回的数据
     * @return bool
     */
    public static function isSuccess(array $result): bool
    {
        return isset($result['header']['resultCode']) && $result['header']['resultCode'] === '0000';
    }

    /**
     * 获取携程返回的错误信息（带错误码说明）
     * 
     * @param array $result 携程API返回的数据
     * @return string
     */
    public static function getErrorMessage(array $result): string
    {
        $resultCode = $result['header']['resultCode'] ?? 'unknown';
        $resultMessage = $result['header']['resultMessage'] ?? '未知错误';
        
        // 根据错误码提供更详细的说明
        $codeDescriptions = [
            '0001' => '供应商账户为空（账户信息缺失）',
            '0002' => '签名不正确（安全校验失败）',
            '0003' => '报文解析失败（XML/JSON格式错误）',
            '0004' => '请求方法为空（API Method参数缺失）',
            '0005' => '系统处理异常（携程服务端内部错误）',
            '0006' => '请求数据异常（必填字段缺失或逻辑错误）',
            '0007' => '提交数据超载（数据量过大导致接收失败）',
            '0008' => '提交频率过快（触发限流，请稍后重试）',
            '1001' => '携程资源编号不存在/错误（对应的携程酒店/房型ID错误）',
            '1002' => '供应商PLU不存在/错误（映射的供应商侧代码无效）',
            '1003' => '数据参数不合法（价格数值或日期格式错误）',
            '2001' => '携程资源编号不存在/错误（对应的携程酒店/房型ID错误）',
            '2002' => '供应商PLU不存在/错误（映射的供应商侧代码无效）',
            '2003' => '数据参数不合法（库存数值或日期格式错误）',
        ];
        
        $description = $codeDescriptions[$resultCode] ?? null;
        
        if ($description) {
            return "错误码 {$resultCode}：{$description}。详情：{$resultMessage}";
        }
        
        return "错误码 {$resultCode}：{$resultMessage}";
    }

    /**
     * 获取携程返回的成功信息
     * 
     * @param array $result 携程API返回的数据
     * @return string
     */
    public static function getSuccessMessage(array $result): string
    {
        return $result['header']['resultMessage'] ?? '操作成功';
    }
}
