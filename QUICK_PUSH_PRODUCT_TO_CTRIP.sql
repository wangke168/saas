-- 快速推送产品到携程的SQL脚本
-- 使用方法：将 @product_id 替换为实际的产品ID，然后执行

-- 方式1：如果知道产品ID
SET @product_id = 1;  -- 替换为实际的产品ID
SET @platform_id = (SELECT id FROM ota_platforms WHERE code = 'ctrip' LIMIT 1);

INSERT INTO ota_products (product_id, ota_platform_id, is_active, pushed_at, created_at, updated_at)
SELECT 
    @product_id,
    @platform_id,
    true,
    NOW(),
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM ota_products 
    WHERE product_id = @product_id AND ota_platform_id = @platform_id
);

-- 方式2：如果知道产品编码（推荐，更安全）
SET @product_code = '0001';  -- 替换为实际的产品编码
SET @platform_id = (SELECT id FROM ota_platforms WHERE code = 'ctrip' LIMIT 1);

INSERT INTO ota_products (product_id, ota_platform_id, is_active, pushed_at, created_at, updated_at)
SELECT 
    p.id,
    @platform_id,
    true,
    NOW(),
    NOW(),
    NOW()
FROM products p
WHERE p.code = @product_code
  AND NOT EXISTS (
      SELECT 1 FROM ota_products op 
      WHERE op.product_id = p.id AND op.ota_platform_id = @platform_id
  );

-- 验证：查看已推送的产品
SELECT 
    p.id AS product_id,
    p.code AS product_code,
    p.name AS product_name,
    op.id AS ota_product_id,
    op.is_active,
    op.pushed_at
FROM products p
INNER JOIN ota_products op ON op.product_id = p.id
INNER JOIN ota_platforms oap ON op.ota_platform_id = oap.id
WHERE oap.code = 'ctrip'
ORDER BY op.created_at DESC;

