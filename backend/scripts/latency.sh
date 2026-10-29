#!/usr/bin/env bash
# API latency probe: median of N runs per endpoint, plus server-side DB time/query count.
#   scripts/latency.sh http://127.0.0.1:8001 owner@demo.local DemoPass123 [runs]
BASE=${1:-http://127.0.0.1:8000}
LOGIN=${2:-owner@demo.local}
PASS=${3:-DemoPass123}
RUNS=${4:-7}

TOKEN=$(curl -s -X POST "$BASE/api/auth/login" -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"login\":\"$LOGIN\",\"password\":\"$PASS\",\"device_name\":\"latency-probe\"}" | sed -E 's/.*"token":"([^"]+)".*/\1/')

probe() {
  local path=$1 auth=$2 times=() timing=""
  for _ in $(seq "$RUNS"); do
    out=$(curl -s -o /dev/null -D - -w 'T=%{time_total}' ${auth:+-H "Authorization: Bearer $TOKEN"} -H 'Accept: application/json' "$BASE$path")
    times+=("$(echo "$out" | sed -n 's/.*T=\([0-9.]*\).*/\1/p')")
    timing=$(echo "$out" | tr -d '\r' | sed -n 's/^[Ss]erver-[Tt]iming: //p')
  done
  median=$(printf '%s\n' "${times[@]}" | sort -n | sed -n "$(( (RUNS + 1) / 2 ))p")
  printf '%-55s %6.0f ms   %s\n' "$path" "$(echo "$median * 1000" | bc -l 2>/dev/null || awk "BEGIN{print $median*1000}")" "$timing"
}

echo "Endpoint                                                median    server timing (last run)"
probe "/api/shop/home"
probe "/api/shop/products?q=laptop%20pro&per_page=24"
probe "/api/shop/products?sort=price_asc&page=50&per_page=24"
probe "/api/shop/products?category=perf-category-7&in_stock=1"
probe "/api/shop/products/complete-laptop-package-hp-250"
probe "/api/admin/products?q=charger&per_page=25" auth
probe "/api/admin/products?stock=low&per_page=25" auth
probe "/api/admin/pos/bootstrap" auth
probe "/api/admin/products/lookup?code=7000000004321" auth
probe "/api/admin/sales?per_page=25" auth
probe "/api/admin/sales?q=S0045&per_page=25" auth
probe "/api/admin/customers?owing=1" auth
probe "/api/admin/dashboard" auth
probe "/api/admin/reports/profit_loss?from=2025-10-22&to=2026-10-22" auth
probe "/api/admin/reports/top_products?from=2025-10-22&to=2026-10-22" auth
probe "/api/admin/reports/slow_moving" auth
probe "/api/admin/reports/inventory" auth
probe "/api/admin/reports/reconcile" auth
