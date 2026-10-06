#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
backup_file=${1:-}
if [ -z "$backup_file" ] || [ ! -f "$backup_file" ]; then
  echo 'Dùng: sh scripts/restore-drill.sh storage/app/private/backups/<file>.sql' >&2
  exit 1
fi
if grep -Eiq '^[[:space:]]*(USE[[:space:]]|CREATE[[:space:]]+DATABASE)' "$backup_file"; then
  echo 'Từ chối dump có lệnh chuyển/tạo database. Dùng file từ restaurant:backup.' >&2
  exit 1
fi
restore_database="restaurant_restore_$(date +%Y%m%d_%H%M%S)_$$"
# Each drill uses a new database; CREATE intentionally fails if its name exists.
docker compose exec -T -e RESTORE_DATABASE="$restore_database" mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "CREATE DATABASE $RESTORE_DATABASE CHARACTER SET utf8mb4"'
docker compose exec -T -e RESTORE_DATABASE="$restore_database" mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$RESTORE_DATABASE"' < "$backup_file"
mkdir -p storage/app/private
result_file="storage/app/private/restore-check-$(date +%Y%m%d-%H%M%S).txt"
{
  echo "Backup: $backup_file"
  echo "Restored database: $restore_database"
  docker compose exec -T -e RESTORE_DATABASE="$restore_database" mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$RESTORE_DATABASE" -e "SELECT COUNT(*) AS restored_orders, COALESCE(SUM(total),0) AS order_total_vnd FROM orders; SELECT COUNT(*) AS restored_items, COALESCE(SUM(quantity*unit_price),0) AS item_total_vnd FROM order_items; SELECT COUNT(*) AS restored_dishes FROM dishes; SELECT COUNT(*) AS restored_users FROM users; SELECT COUNT(*) AS restored_payments, COALESCE(SUM(amount),0) AS payment_total_vnd FROM payments; SELECT COUNT(*) AS restored_histories FROM order_status_histories;"'
} > "$result_file"
cat "$result_file"
echo "Kết quả: $result_file. Database ứng dụng không bị thay đổi."
