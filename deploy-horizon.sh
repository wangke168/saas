#!/bin/bash

# Laravel Horizon 快速部署脚本
# 使用方法: sudo bash deploy-horizon.sh

set -e

echo "========================================="
echo "Laravel Horizon 部署脚本"
echo "========================================="

# 获取项目路径（默认 /var/www/html，可根据实际情况修改）
PROJECT_PATH=${1:-/var/www/html}
WEB_USER=${2:-www-data}

echo "项目路径: $PROJECT_PATH"
echo "Web用户: $WEB_USER"

# 检查项目路径是否存在
if [ ! -d "$PROJECT_PATH" ]; then
    echo "错误: 项目路径不存在: $PROJECT_PATH"
    exit 1
fi

cd "$PROJECT_PATH"

# 1. 安装 Horizon
echo ""
echo "步骤 1: 安装 Laravel Horizon..."
composer require laravel/horizon

# 2. 发布 Horizon 配置
echo ""
echo "步骤 2: 发布 Horizon 配置..."
php artisan horizon:install

# 3. 运行迁移
echo ""
echo "步骤 3: 运行数据库迁移..."
php artisan migrate --force

# 4. 创建 Supervisor 配置文件
echo ""
echo "步骤 4: 创建 Supervisor 配置文件..."
SUPERVISOR_CONF="/etc/supervisor/conf.d/laravel-horizon.conf"

cat > "$SUPERVISOR_CONF" << EOF
[program:laravel-horizon]
process_name=%(program_name)s
command=php $PROJECT_PATH/artisan horizon
autostart=true
autorestart=true
user=$WEB_USER
redirect_stderr=true
stdout_logfile=$PROJECT_PATH/storage/logs/horizon.log
stopwaitsecs=3600
EOF

echo "Supervisor 配置文件已创建: $SUPERVISOR_CONF"

# 5. 确保日志目录存在且有权限
echo ""
echo "步骤 5: 设置日志目录权限..."
mkdir -p "$PROJECT_PATH/storage/logs"
chown -R $WEB_USER:$WEB_USER "$PROJECT_PATH/storage"
chmod -R 775 "$PROJECT_PATH/storage"

# 6. 重新加载 Supervisor
echo ""
echo "步骤 6: 重新加载 Supervisor 配置..."
supervisorctl reread
supervisorctl update

# 7. 启动 Horizon
echo ""
echo "步骤 7: 启动 Horizon..."
supervisorctl start laravel-horizon

# 8. 清除缓存
echo ""
echo "步骤 8: 清除 Laravel 缓存..."
php artisan config:clear
php artisan cache:clear

# 9. 检查状态
echo ""
echo "步骤 9: 检查 Horizon 状态..."
sleep 2
supervisorctl status laravel-horizon

echo ""
echo "========================================="
echo "部署完成！"
echo "========================================="
echo ""
echo "下一步:"
echo "1. 确保 .env 文件中设置了 QUEUE_CONNECTION=redis"
echo "2. 访问 Horizon 仪表板: https://your-domain.com/horizon"
echo "3. 查看日志: tail -f $PROJECT_PATH/storage/logs/horizon.log"
echo ""
echo "常用命令:"
echo "  - 查看状态: supervisorctl status laravel-horizon"
echo "  - 重启: supervisorctl restart laravel-horizon"
echo "  - 停止: supervisorctl stop laravel-horizon"
echo "  - 启动: supervisorctl start laravel-horizon"
echo ""


