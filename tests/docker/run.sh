#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
compose=(docker compose -f "$script_dir/compose.yaml")

"${compose[@]}" up -d db wordpress

for attempt in $(seq 1 60); do
    if curl -fsS -o /dev/null http://127.0.0.1:18081/; then
        break
    fi
    if [[ "$attempt" -eq 60 ]]; then
        echo "WordPress did not become ready" >&2
        exit 1
    fi
    sleep 2
done

if ! "${compose[@]}" run --rm cli wp core is-installed >/dev/null 2>&1; then
    "${compose[@]}" run --rm cli wp core install \
        --url=http://localhost:18081 \
        --title='Khalti Docker E2E' \
        --admin_user=admin \
        --admin_password=local-test-only \
        --admin_email=admin@example.test \
        --skip-email
fi

if ! "${compose[@]}" run --rm cli wp plugin is-installed woocommerce >/dev/null 2>&1; then
    "${compose[@]}" run --rm cli wp plugin install woocommerce --version=10.4.0 --activate
else
    "${compose[@]}" run --rm cli wp plugin activate woocommerce
fi
"${compose[@]}" run --rm cli wp plugin activate woo-multi-currency integrate-khalti-in-wc-store

if [[ "${1:-}" == "--hpos" ]]; then
    hpos_status="$("${compose[@]}" run --rm cli wp wc hpos status)"
    if [[ "$hpos_status" != *"HPOS enabled?: yes"* ]]; then
        "${compose[@]}" run --rm cli wp wc hpos sync
        "${compose[@]}" run --rm cli wp wc hpos enable
    fi
elif [[ $# -ne 0 ]]; then
    echo "Usage: bash tests/docker/run.sh [--hpos]" >&2
    exit 2
fi

setup_output="$("${compose[@]}" run --rm cli wp eval-file wp-content/plugins/integrate-khalti-in-wc-store/tests/docker/setup.php)"
setup_json="$(printf '%s\n' "$setup_output" | tail -n 1)"
read -r cheap_id expensive_id checkout_id < <(
    python3 -c 'import json,sys; d=json.loads(sys.argv[1]); print(d["cheap_product_id"], d["expensive_product_id"], d["checkout_page_id"])' "$setup_json"
)

result_json="$(python3 "$script_dir/e2e.py" "$cheap_id" "$expensive_id" "$checkout_id")"
printf '%s\n' "$result_json"
"${compose[@]}" run --rm -e "KHALTI_E2E_RESULT=$result_json" cli \
    wp eval-file wp-content/plugins/integrate-khalti-in-wc-store/tests/docker/inspect.php

echo "Khalti Docker E2E passed"
