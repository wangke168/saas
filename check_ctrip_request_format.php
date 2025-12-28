<?php

// 检查携程订单确认接口的请求格式
$docPath = __DIR__ . '/storage/docs/trip-order.txt';
$content = file_get_contents($docPath);
$data = json_decode($content, true);

if (!$data) {
    echo "无法解析JSON文档\n";
    exit(1);
}

$paths = $data['paths'] ?? [];
$schemas = $data['components']['schemas'] ?? [];

// 查找 CreateOrderConfirm 接口
echo "=== CreateOrderConfirm 接口详情 ===\n\n";

foreach ($paths as $path => $methods) {
    foreach ($methods as $method => $details) {
        $operationId = $details['operationId'] ?? '';
        if ($operationId === 'CreateOrderConfirm') {
            echo "路径: $path\n";
            echo "方法: " . strtoupper($method) . "\n";
            echo "摘要: " . ($details['summary'] ?? '') . "\n";
            echo "描述: " . ($details['description'] ?? '') . "\n\n";
            
            // 检查请求体
            $requestBody = $details['requestBody'] ?? [];
            if (!empty($requestBody)) {
                echo "请求体定义:\n";
                $content = $requestBody['content'] ?? [];
                foreach ($content as $contentType => $schema) {
                    echo "  Content-Type: $contentType\n";
                    $schemaObj = $schema['schema'] ?? [];
                    if (isset($schemaObj['$ref'])) {
                        $ref = $schemaObj['$ref'];
                        echo "  引用: $ref\n";
                        // 解析引用的schema
                        $refParts = explode('/', $ref);
                        $schemaName = end($refParts);
                        if (isset($schemas[$schemaName])) {
                            echo "\n  Schema: $schemaName\n";
                            $schemaDef = $schemas[$schemaName];
                            echo json_encode($schemaDef, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
                        }
                    }
                }
            }
            
            // 检查请求参数
            $parameters = $details['parameters'] ?? [];
            if (!empty($parameters)) {
                echo "\n请求参数:\n";
                foreach ($parameters as $param) {
                    echo "  - " . ($param['name'] ?? '') . " (" . ($param['in'] ?? '') . "): " . ($param['description'] ?? '') . "\n";
                }
            }
        }
    }
}

// 查找 CreateOrderConfirmRequest 的定义
echo "\n=== CreateOrderConfirmRequest Schema ===\n";
if (isset($schemas['CreateOrderConfirmRequest'])) {
    $schema = $schemas['CreateOrderConfirmRequest'];
    echo json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} else {
    echo "未找到 CreateOrderConfirmRequest\n";
}

// 查找所有包含 Confirm 的 Schema
echo "\n=== 所有包含 Confirm 的 Schema ===\n";
foreach ($schemas as $schemaName => $schema) {
    if (stripos($schemaName, 'Confirm') !== false) {
        echo "$schemaName:\n";
        $properties = $schema['properties'] ?? [];
        if (!empty($properties)) {
            echo "  属性:\n";
            foreach ($properties as $propName => $propDef) {
                echo "    - $propName: " . ($propDef['type'] ?? '') . " - " . ($propDef['description'] ?? '') . "\n";
            }
        }
        echo "\n";
    }
}

