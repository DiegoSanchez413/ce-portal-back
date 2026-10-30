#!/usr/bin/env bash
# Copia el esquema del Soft (tablas, SP y triggers, sin datos) de la base local a mysql-test.
set -euo pipefail
cd "$(dirname "$0")/../.."

env_value() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

export MYSQL_PWD="$(env_value DB_PASSWORD)"
mysqldump -h "$(env_value DB_HOST)" -P "$(env_value DB_PORT)" -u "$(env_value DB_USERNAME)" \
    --no-data --routines --triggers --skip-lock-tables --set-gtid-purged=OFF --column-statistics=0 \
    "$(env_value DB_DATABASE)" \
  | sed -E 's/DEFINER=`[^`]+`@`[^`]+`//g' > /tmp/ce-portal-soft-schema.sql

MYSQL_PWD=secret mysql -h 127.0.0.1 -P 3307 -u root amg_test < /tmp/ce-portal-soft-schema.sql
rm -f /tmp/ce-portal-soft-schema.sql
echo "Esquema del Soft cargado en mysql-test (127.0.0.1:3307/amg_test)."
