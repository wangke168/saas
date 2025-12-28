-- 快速创建美团平台和配置的SQL脚本
-- 使用方法：执行此脚本即可创建美团平台记录和配置
-- 注意：配置参数会从环境变量读取，如果.env中没有配置，需要手动修改下面的值

-- 1. 创建美团平台记录（如果不存在）
INSERT INTO ota_platforms (name, code, description, is_active, created_at, updated_at)
SELECT 
    '美团',
    'meituan',
    '美团平台',
    true,
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM ota_platforms WHERE code = 'meituan'
);

-- 2. 获取美团平台ID
SET @meituan_platform_id = (SELECT id FROM ota_platforms WHERE code = 'meituan' LIMIT 1);

-- 3. 创建美团配置（如果不存在）
-- 注意：以下配置值需要根据实际情况修改，或从.env文件中读取
INSERT INTO ota_configs (
    ota_platform_id,
    account,              -- 对应 MEITUAN_APP_KEY
    secret_key,           -- 对应 MEITUAN_PARTNER_ID
    aes_key,              -- 对应 MEITUAN_AES_KEY
    api_url,              -- 对应 MEITUAN_API_URL
    callback_url,         -- 对应 MEITUAN_WEBHOOK_URL
    environment,          -- 'sandbox' 或 'production'
    is_active,
    created_at,
    updated_at
)
SELECT 
    @meituan_platform_id,
    'YOUR_MEITUAN_APP_KEY',           -- 替换为实际的 AppKey
    'YOUR_MEITUAN_PARTNER_ID',        -- 替换为实际的 PartnerId
    'YOUR_MEITUAN_AES_KEY',           -- 替换为实际的 AES Key（16字节）
    'https://openapi.meituan.com',    -- 测试环境或生产环境URL
    'https://your-domain.com/api/webhooks/meituan',  -- 替换为实际的Webhook URL
    'sandbox',                         -- 或 'production'
    true,
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM ota_configs WHERE ota_platform_id = @meituan_platform_id
);

-- 4. 验证：查看创建的平台和配置
SELECT 
    op.id AS platform_id,
    op.name AS platform_name,
    op.code AS platform_code,
    op.is_active AS platform_active,
    oc.id AS config_id,
    oc.account,
    oc.environment,
    oc.is_active AS config_active,
    oc.api_url,
    oc.callback_url
FROM ota_platforms op
LEFT JOIN ota_configs oc ON oc.ota_platform_id = op.id
WHERE op.code = 'meituan';

-- 5. 如果需要更新配置，可以使用以下SQL（替换相应的值）
-- UPDATE ota_configs 
-- SET 
--     account = 'YOUR_MEITUAN_APP_KEY',
--     secret_key = 'YOUR_MEITUAN_PARTNER_ID',
--     aes_key = 'YOUR_MEITUAN_AES_KEY',
--     api_url = 'https://openapi.meituan.com',
--     callback_url = 'https://your-domain.com/api/webhooks/meituan',
--     environment = 'sandbox',
--     updated_at = NOW()
-- WHERE ota_platform_id = @meituan_platform_id;

