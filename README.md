介绍：
这个系统主要作用是把资源方的酒店加景区门票的打包产品推送到美团携程和飞猪等平台销售，同时把平台的订单推送回景区侧。因此我把他分成两个大块，一块的面向运营人员的带操作界面的管理系统，另一块是底层的和OTA以及景区系统接口的模块。
主要和三个平台对接
1. 携程
接口文档见storage/doc/trip-order.txt和sotrage/doc/trip-product.txt
沙箱平台测试环境的回调通知接口地址为
订单对接接口： https://ttdopen.ctrip.com/api/order/notice.do
价格同步接口： https://ttdopen.ctrip.com/api/product/price.do
库存同步接口： https://ttdopen.ctrip.com/api/product/stock.do
沙箱测试环境
   接口帐号：a774ec3ee5b649bb
  接口密钥：a0ed6ce96975da24a1aa2d4b1ecd50d5
   AES 加密密钥：9676244592c0d748
   AES 加密初始向量：a1e1d0a8f0888b08
  供应商接口地址：https://www.laidoulail.online/api/webbook/agent/ctrip
订单的商家接口地址是https://www.laidoulaile.online/api/webhooks/ctrip
2. 美团
      待开发，等对方给文档
3. 飞猪
接口文档见storage/docs/fliggy_api.txt,storage/docs/fliggy_order.txt,storage/docs/fliggy_qm.txt,storage/docs/fliggy_ts.txt。
分销商编码:40414224
分销商识别码：MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQCYfXtDc9PX7uYvTI4/S5GC/ecfL+VllN7Cro/TdBeT79jCdztKJtpD5oLtqeb5amThR5VzJdk1/q1QPS70wmbOO0Y+US2dRz+1stXUm4zLQ4JtWS2bzur3GOp1PUMos9K9RUtahoh0DWC8/vh0FJn63kCHcHdRSl+HKg8SfRrOTTwLC+QlxxxsskVopVxFx4K1CT8miOi8DHAmVZ2XEDIYvGqFzn4YyS71Vi/Kr2+1CEQyVD6LDhsLpEllwX+n2Vjte+LgtKxe7SHFOIVxWINVcOhPF/ZyBJSxgiv/VZ+vB0tdl5L98H5ZZSaJVFJbDhCbw3LY3eyCMvmS/xBgFd5JAgMBAAECggEAUzefbicmw9+fBM79jfM6fMb9O1rTEIWWr5295cKkH1qz6yRQWd4sHQQODY87+n8igIKlP4q3KC1M/c318yKoIgHdCqMYho1calcnNHiS9FZqNiyFpFLejWyufr6pCOxhpGLNhaCHlPW8BkgK5KZdhbeGdaNdqiIxUb0VLpzRZSWLdaIv4vk3Yn5u9rgfZu0nmDCGK0ySQ5J/+o2S477VgAnQ1MO4xMF7XqEwe9tHwF1xxige5Og2+TCyLaHXJfPXFf7XJDLoRts2f7nGLFX4B6Lh1wnVhXikQSoFk4k1MKkoZw8Wnmf5Rk45mSKakz1ZVUQ0WMT/WSmjlGp2Be3oJQKBgQDvM/9x0VVlYIW4dfMHYbvne8ae/qXd/uE8PLyNP388jjjc7RU800QE0Oh18PXj8tNg9t7NQATMG/lnRo1YmoaVUo6i+KbZJ+bMDiYQYW6CqFDECw4rjJBSY+C5wRuugp0e8tQIUVT440p3EJcRVoJiazjwopiPcdgaDPlX/BsnjwKBgQCjMrM4b6aAYp7ZzPW6mnHJsnEKpr8/rWFZDdsFJu5EVd917bnlNhJfniEZvI0XZ0PPiz2h7i8ja7q/5AlzUf0OTjd9XtotiMGudVqjOrsx504ZRZuvzTzuTrPNAfHQhTgycUCiZEt4avLho2mwNW1R6AkVWxahEcA3MxAPaafwpwKBgDFPYMtPwfDiEu7RscXFyfyQMYO5LuwyjK/kPWJIwqvzDZnNbeFaE92CS0l04NuaxSRp/8gD+HkzExjNHHo1cKT7ndfOtmZxqUxLZmFeFG/uzqd7N/KWSXISkNL6EgDJgCZPHJKSYZflEwa0bs/uK3aqb9R7UEPRziCgqA4RNG/VAoGAfa8GJ2iVKjrJa6NVe6iGCXfLZxCUKU41sofhLU6WITqhJgk3KTdDXzBA/bkgT+3PY38wsAzncLf+0tmkDZQO9311brAmBvtTbjAi5aLNl9kzZHMfO30sd7tU7YWZ3aU7al2eEXJ8TPjQpMVdF7+NuY6hsDi+bL1m8xv4OmZ8V/ECgYAuNVAMKLAGlkkiDBPDRolqsrZ6jgRcfVheHdi05LSbNR6YuqVRx6AI98wuaeYkTLHQWAIY82T63EgKH0wPjXDMSVvZaBRG//0kCJLQsSZ6C99cCul12qzTpUuMyDayB0cuZl/vqA26qTEfafId61DEL8YGxkRsQXGpQIUsEsuXIw==
产品变更通知： https://www.laidoulaile.online/api/webhooks/fliggy/product-change
   交易主动通知：https://www.laidoulaile.online/api/webhooks/fliggy/order-status

资源方：
目前获得横店影视城系统的酒景套餐接口文档，后续会陆续增加其他系统，相关参数如下
1.横店影视城
接口文档见storage/docs/hengdian.txt
用户名：mpbxczl_common
密码：mpbxczl20250820!@#$
注意：
1.在OTA往我方系统推送订单后的处理分两类，一类是直接和资源方直连对接，另一类是酒店没有系统直连，订单需要手工处理。
2.系统直连到资源方的订单，但资源方没有库存时，不要直接拒单，订单留着运营人工处理。
管理系统设计如下
1. 用户管理功能
  1. 用户权限分两种，一是运营角色，只能管理绑定的景区以及对应的产品，酒店，房型、库存、加价规则等，二是超级管理员是能管理所有的景区已经对应的产品，酒店，房型、库存、加价规则等
  2. 超级管理员可以增加、修改运营的账号，不能删除运营账号，但是可以禁用。
2. 景区管理
  1. 该功能只能有超级管理员，可以添加，修改，删除景区信息
3. 软件商管理
  1. 因为产品一方面需要和OTA的系统直连，一方面需要和景区及酒店侧直连。但是不同的景区可能会用相同的软件服务商。
  2. 该功能也只有超级管理员可以添加修改删除。
4. 酒店管理
  1. 添加、修改、删除酒店信息
  2. 添加、修改、删除每个酒店下有对应的房型
  3. 添加、修改、每个酒店下有对应的房型的库存，库存的维护分两种，一种是人工维护，另一种是资源方通过系统接口推送过来。
  4. 直连和非直连的库存都可以手工关闭
5. 产品管理（重点板块）
  1. 产品由景区门票和酒店组成，因为门票是无限库存，而且大多数时候是资源方已经打包好，因此我们可以看成是一个产品，但在添加产品时需要在该景区下属酒店中选择酒店，在该酒店下属房型中选择需要的房型。
  2. 产品的价格在不同的日期会有变化，设置时分几种情况，一种是人工维护，人工维护时可以先设置一个门市价，和平台的结算价，在平台的销售价，然后设置加价规则，设置加价规则时分两种情况，一是通过设置周几来做有规律的批量设置，二是同时设置日期区域的方式设置，设置的时候需选择要调整价格（包含销售价，结算价和门市价）的酒店及房型。两种情况会同时存在。而且每种情况都不一定只有一条加价规则。另一种是景区侧会和库存一起推送价格过来，这种就不需要我们单独设置。
  3. 产品按需推送到各OTA平台，可能推送到一个平台，也可能推送到多个平台
  4. 值得注意的是，OTA的平台较少，接口也固定，而和景区测的对接存在几种情况，一是纯手工操作，也就是前面提到的库存和酒店都人工配置，一是直接和景区方直连，也就是景区会把门票和酒店打包好把完整的价格和库存推送过来，后续可能还会有还门票和酒店分两个系统推送。
6. 订单管理
  1. 订单列表中会显示OTA平台推送过来的的订单信息。
  2. 订单的状态会有以下几种，
  已支付/待确认（这是关键，此时你需要去锁库存）
  确认中（正在请求景区接口）
  预订成功（景区返回确认号，给OTA发确认）
  预订失败/拒单（无房或价格变动，需触发退款）
  申请取消中（用户在OTA点取消，OTA推申请过来）
  取消拒绝/取消通过
  核销订单
  等等。
  3. 需要有个异常订单处理板块，所有接口报错、超时、库存对不上的订单，全部丢到这里，强提醒运营人工处理。
7. 账号管理
  1. 密码修改

