<?php

declare(strict_types=1);

use App\Support\WechatEnv;
use Yansongda\Pay\Pay;

// 注意：pay.php 先于 wechat.php 加载，不可在此引用 config('wechat.*')

return [
    'alipay' => [
        'default' => [
            // 必填-支付宝分配的 app_id
            'app_id' => '',
            // 必填-应用私钥 字符串或路径
            'app_secret_cert' => '',
            // 必填-应用公钥证书 路径
            'app_public_cert_path' => '',
            // 必填-支付宝公钥证书 路径
            'alipay_public_cert_path' => '',
            // 必填-支付宝根证书 路径
            'alipay_root_cert_path' => '',
            'return_url' => '',
            'notify_url' => '',
            // 选填-服务商模式下的服务商 id，当 mode 为 Pay::MODE_SERVICE 时使用该参数
            'service_provider_id' => '',
            // 选填-默认为正常模式。可选为： MODE_NORMAL, MODE_SANDBOX, MODE_SERVICE
            'mode' => Pay::MODE_NORMAL,
        ],
    ],
    'wechat' => [
        'default' => [
            // 必填-商户号，服务商模式下为服务商商户号
            'mch_id' => WechatEnv::value('WECHAT_PAY_MCH_ID'),
            // 选填-v2商户私钥
            'mch_secret_key_v2' => '',
            // 必填-商户秘钥（APIv3）
            'mch_secret_key' => WechatEnv::value('WECHAT_PAY_API_V3_KEY'),
            // 必填-商户私钥 字符串或路径（apiclient_key.pem）
            'mch_secret_cert' => WechatEnv::path('WECHAT_PAY_PRIVATE_KEY_PATH'),
            // 必填-商户公钥证书路径（apiclient_cert.pem）
            'mch_public_cert_path' => WechatEnv::path(
                'WECHAT_PAY_MCH_CERT_PATH',
                WechatEnv::value('WECHAT_PAY_PRIVATE_KEY_PATH'),
            ),
            // 必填
            'notify_url' => WechatEnv::value('WECHAT_PAY_NOTIFY_URL'),
            // 选填-公众号 的 app_id
            'mp_app_id' => '',
            // 选填-小程序 的 app_id
            'mini_app_id' => WechatEnv::value('WECHAT_MINI_APP_ID'),
            // 选填-app 的 app_id
            'app_id' => '',
            // 选填-服务商模式下，子公众号 的 app_id
            'sub_mp_app_id' => '',
            // 选填-服务商模式下，子 app 的 app_id
            'sub_app_id' => '',
            // 选填-服务商模式下，子小程序 的 app_id
            'sub_mini_app_id' => '',
            // 选填-服务商模式下，子商户id
            'sub_mch_id' => '',
            // 微信支付公钥（新商户）：商户平台 API 安全下载的 pub_key.pem + 公钥 ID
            'wechat_public_cert_path' => WechatEnv::wechatPublicCertMap(),
            // 选填-默认为正常模式。可选为： MODE_NORMAL, MODE_SERVICE
            'mode' => Pay::MODE_NORMAL,
        ],
    ],
    'unipay' => [
        'default' => [
            // 必填-商户号
            'mch_id' => '',
            // 选填-商户密钥：为银联条码支付综合前置平台配置：https://up.95516.com/open/openapi?code=unionpay
            'mch_secret_key' => '979da4cfccbae7923641daa5dd7047c2',
            // 必填-商户公私钥
            'mch_cert_path' => '',
            // 必填-商户公私钥密码
            'mch_cert_password' => '000000',
            // 必填-银联公钥证书路径
            'unipay_public_cert_path' => '',
            // 必填
            'return_url' => '',
            // 必填
            'notify_url' => '',
        ],
    ],
	'douyin' => [
		'default' => [
			// 选填-商户号
			// 抖音开放平台 --> 应用详情 --> 支付信息 --> 产品管理 --> 商户号
			'mch_id' => '',
			// 必填-支付 Token，用于支付回调签名
			// 抖音开放平台 --> 应用详情 --> 支付信息 --> 支付设置 --> Token(令牌)
			'mch_secret_token' => '',
			// 必填-支付 SALT，用于支付签名
			// 抖音开放平台 --> 应用详情 --> 支付信息 --> 支付设置 --> SALT
			'mch_secret_salt' => '',
			// 必填-小程序 app_id
			// 抖音开放平台 --> 应用详情 --> 支付信息 --> 支付设置 --> 小程序appid
			'mini_app_id' => '',
			// 选填-抖音开放平台服务商id
			'thirdparty_id' => '',
			// 选填-抖音支付回调地址
			'notify_url' => '',
		],
	],
	'jsb' => [
		'default' => [
			// 服务代码
			'svr_code' => '',
			// 必填-合作商ID
			'partner_id' => '',
			// 必填-公私钥对编号
			'public_key_code' => '00',
			// 必填-商户私钥(加密签名)
			'mch_secret_cert_path' => '',
			// 必填-商户公钥证书路径(提供江苏银行进行验证签名用)
			'mch_public_cert_path' => '',
			// 必填-江苏银行的公钥(用于解密江苏银行返回的数据)
			'jsb_public_cert_path' => '',
			//支付通知地址
			'notify_url'            => '',
			// 选填-默认为正常模式。可选为： MODE_NORMAL:正式环境, MODE_SANDBOX:测试环境
			'mode' => Pay::MODE_NORMAL,
		]
	],
    'http' => [ // optional
        'timeout' => 5.0,
        'connect_timeout' => 5.0,
        // 更多配置项请参考 [Guzzle](https://guzzle-cn.readthedocs.io/zh_CN/latest/request-options.html)
    ],
    // optional，默认 warning；日志路径为：sys_get_temp_dir().'/logs/yansongda.pay.log'
    'logger' => [
        'enable' => env('WECHAT_PAY_LOG', env('APP_DEBUG', false)),
        'file' => storage_path('logs/yansongda-pay.log'),
        'level' => 'debug',
        'type' => 'single', // optional, 可选 daily.
        'max_file' => 30,
    ],
];
