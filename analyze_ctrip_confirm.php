<?php

// 分析携程订单确认接口的请求格式
$docPath = __DIR__ . '/storage/docs/trip-order.txt';
$content = file_get_contents($docPath);
$data = json_decode($content, true);

if (!$data) {
    echo "无法解析JSON文档\n";
    exit(1);
}

$schemas = $data['components']['schemas'] ?? [];

// 查找所有包含 Request 的 Schema
echo "=== 所有 Request Schema ===\n";
$requestSchemas = [];
foreach ($schemas as $schemaName => $schema) {
    if (stripos($schemaName, 'Request') !== false && stripos($schemaName, 'Confirm') !== false) {
        $requestSchemas[$schemaName] = $schema;
        echo "\n$schemaName:\n";
        $properties = $schema['properties'] ?? [];
        if (!empty($properties)) {
            echo "  属性:\n";
            foreach ($properties as $propName => $propDef) {
                $type = $propDef['type'] ?? '';
                $desc = $propDef['description'] ?? '';
                $required = in_array($propName, $schema['required'] ?? []);
                echo "    - $propName" . ($required ? ' (必填)' : '') . ": $type - $desc\n";
                if (isset($propDef['properties'])) {
                    foreach ($propDef['properties'] as $subProp => $subDef) {
                        echo "        - $subProp: " . ($subDef['type'] ?? '') . "\n";
                    }
                }
            }
        }
        echo "\n完整Schema:\n";
        echo json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    }
}

// 特别查找 PayPreOrderConfirmRequest（可能有类似格式）
echo "\n=== PayPreOrderConfirmRequest ===\n";
if (isset($schemas['PayPreOrderConfirmRequest'])) {
    echo json_encode($schemas['PayPreOrderConfirmRequest'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

// 查找所有包含 body 的请求示例
echo "\n=== 搜索文档中的请求示例 ===\n";
$jsonString = file_get_contents($docPath);
// 搜索包含 orderId 和 confirmNo 的示例
if (preg_match_all('/"orderId"[^}]*"confirmNo"/', $jsonString, $matches)) {
    echo "找到包含 orderId 和 confirmNo 的示例\n";
    foreach ($matches[0] as $match) {
        echo "  $match\n";
    }
}

