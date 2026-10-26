#!/usr/bin/env bash
# Prepares and runs the MCP evaluation described in
# docs/superpowers/eval/2026-10-25-mcp-frozen-tasks.md.
#
# Eval mode points the existing ads-api service at the ads_eval database
# (POSTGRES_DB=ads_eval), so it serves http://127.0.0.1:8000 on the eval data.
# Stop eval mode to return to the dev database. A separate container on another
# port does not work: MCP tool calls from the host time out there.
#
#   scripts/mcp-eval.sh prepare   create and seed ads_eval as of 2026-10-25, add fixture 20002, save the snapshot
#   scripts/mcp-eval.sh serve     enter eval mode (stops the queue worker, restarts ads-api on ads_eval)
#   scripts/mcp-eval.sh stop      leave eval mode (ads-api back on the dev database, queue restarted)
#   scripts/mcp-eval.sh reset     leave eval mode and restore ads_eval from the snapshot
#   scripts/mcp-eval.sh key       create a search:read key for this run (secret printed once)
#   scripts/mcp-eval.sh check     call tools/list with ADS_KEY and list the tool names
#   scripts/mcp-eval.sh run LABEL replay the five frozen tasks with mcp-eval (needs ADS_KEY, ANTHROPIC_API_KEY, uv)
#   scripts/mcp-eval.sh diff A B  compare two runs (labels or run directories) as a Markdown table
#   scripts/mcp-eval.sh selftest  run the offline tests for the suite's evaluators and diff (no server, no model)
#
# The suite (mcp-eval config, tasks, evaluators) lives in scripts/mcp-eval/. Reports and per-task
# traces go to storage/eval/reports/<UTC time>-<LABEL>/. First run: prepare, serve, key, check, run before.
# Every later run: reset, serve, key (reset drops earlier keys), run LABEL, then diff before LABEL.
set -euo pipefail

cd "$(dirname "$0")/.."

readonly DB_NAME="ads_eval"
readonly SNAPSHOT="storage/eval/ads_eval.dump"
readonly EVAL_URL="http://127.0.0.1:8000"
readonly MCP_URL="${EVAL_URL}/api/mcp"
readonly FIXTURE_ID=20002
readonly SUITE_DIR="scripts/mcp-eval"
readonly REPORTS_DIR="storage/eval/reports"
# The frozen eval is dated 2026-10-25 (UTC). AdSeeder derives dates from today, so seed as of that date.
readonly EVAL_DATE="2026-10-25"

log() { printf '==> %s\n' "$*"; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }

artisan() {
    docker compose exec -T -e DB_DATABASE="$DB_NAME" -e APP_BOOTSTRAP=0 ads-api php artisan "$@"
}

psql_eval() {
    docker compose exec -T postgres psql -U ads -d "$DB_NAME" -tAc "$1"
}

# Refuse to run any destructive step unless the connection really points at ads_eval.
assert_eval_database() {
    local actual
    actual=$(docker compose exec -T -e DB_DATABASE="$DB_NAME" ads-api php artisan tinker --execute 'echo DB::connection()->getDatabaseName();' | tr -d '[:space:]')
    [[ "$actual" == "$DB_NAME" ]] || die "refusing to continue: connection points at '${actual}', not '${DB_NAME}'"
}

ensure_database() {
    if ! docker compose exec -T postgres psql -U ads -d postgres -tAc "select 1 from pg_database where datname='${DB_NAME}'" | grep -q 1; then
        log "creating database ${DB_NAME}"
        docker compose exec -T postgres createdb -U ads "$DB_NAME"
    fi
}

wait_healthy() {
    local status=""
    for _ in $(seq 1 60); do
        status=$(docker inspect -f '{{.State.Health.Status}}' "$(docker compose ps -q ads-api)" 2>/dev/null || true)
        [[ "$status" == "healthy" ]] && return 0
        sleep 2
    done
    die "ads-api did not become healthy (last status: ${status:-unknown}); check 'docker compose logs ads-api'"
}

cmd_prepare() {
    docker compose up -d postgres >/dev/null
    ensure_database
    assert_eval_database

    log "migrating and seeding ${DB_NAME} as of ${EVAL_DATE} (wipes existing data in that database only)"
    artisan tinker --execute "
        Illuminate\\Support\\Carbon::setTestNow('${EVAL_DATE} 12:00:00', 'UTC');
        Illuminate\\Support\\Facades\\Artisan::call('migrate:fresh', ['--force' => true]);
        Illuminate\\Support\\Facades\\Artisan::call('db:seed', ['--class' => Database\\\\Seeders\\\\AdSeeder::class, '--force' => true]);
    "

    log "adding fixture ${FIXTURE_ID} (long copy, brand 301)"
    artisan tinker --execute "
        \$body = substr(str_repeat('Our serum combines hyaluronic acid, niacinamide and ceramides for all-day hydration. ', 40), 0, 2100);
        App\\Models\\Ad::query()->where('brand_id', 301)->delete();
        \$fixture = new App\\Models\\Ad;
        \$fixture->forceFill([
            'id' => ${FIXTURE_ID},
            'brand_id' => 301,
            'brand_name' => 'Eval Long Copy Brand',
            'headline' => 'Hydration that lasts all day',
            'body_text' => \$body,
            'niche' => App\\Enums\\Niche::Skincare,
            'format' => App\\Enums\\AdFormat::Video,
            'status' => App\\Enums\\AdStatus::Active,
            'start_date' => '2026-09-01',
            'end_date' => null,
            'impression_rank' => 1,
            'countries' => ['US'],
            'discovered_at' => '2026-09-01',
        ])->save();
        DB::statement(\"select setval(pg_get_serial_sequence('ads', 'id'), (select max(id) from ads))\");
    "

    local count
    count=$(psql_eval "select count(*) from ads")
    [[ "$count" -eq 20001 ]] || die "expected 20001 ads after seeding, found ${count}"
    [[ "$(psql_eval "select char_length(body_text) from ads where id=${FIXTURE_ID}")" -eq 2100 ]] \
        || die "fixture ${FIXTURE_ID} body is not 2100 characters"

    log "saving snapshot to ${SNAPSHOT}"
    mkdir -p "$(dirname "$SNAPSHOT")"
    docker compose exec -T postgres pg_dump -U ads -Fc --no-owner "$DB_NAME" > "$SNAPSHOT"
    log "prepared. Next: 'scripts/mcp-eval.sh serve'"
}

cmd_serve() {
    [[ "$(psql_eval "select count(*) from ads")" -gt 0 ]] || die "${DB_NAME} is empty; run 'prepare' or 'reset' first"

    log "stopping the queue worker so no dev jobs touch ${DB_NAME}"
    docker compose stop queue >/dev/null 2>&1 || true

    log "starting ads-api on ${DB_NAME}"
    POSTGRES_DB="$DB_NAME" docker compose up -d --force-recreate --no-deps ads-api >/dev/null
    wait_healthy
    log "eval API is up at ${EVAL_URL} (MCP endpoint ${MCP_URL})"
}

cmd_stop() {
    log "restarting ads-api on the dev database and the queue worker"
    docker compose up -d --force-recreate --no-deps ads-api >/dev/null
    wait_healthy
    docker compose up -d queue >/dev/null
    log "back on the dev database"
}

cmd_reset() {
    [[ -s "$SNAPSHOT" ]] || die "no snapshot at ${SNAPSHOT}; run 'prepare' first"
    cmd_stop

    ensure_database
    log "restoring ${DB_NAME} from ${SNAPSHOT}"
    docker compose exec -T postgres dropdb -U ads --if-exists "$DB_NAME"
    docker compose exec -T postgres createdb -U ads "$DB_NAME"
    docker compose exec -T postgres pg_restore -U ads -d "$DB_NAME" --no-owner < "$SNAPSHOT"
    assert_eval_database
    log "restored. Next: 'scripts/mcp-eval.sh serve'"
}

cmd_key() {
    log "creating a search:read key in ${DB_NAME}. The secret is printed once; copy it now."
    artisan api-key:create "mcp-eval-$(date -u +%Y%m%dT%H%M%SZ)" --scope=search:read --no-interaction
    echo "Export it for this shell: export ADS_KEY='<secret>'"
}

cmd_check() {
    [[ -n "${ADS_KEY:-}" ]] || die "export ADS_KEY first (see 'key')"
    local body
    body=$(curl -fsS -X POST "$MCP_URL" \
        -H "Authorization: Bearer ${ADS_KEY}" \
        -H 'Content-Type: application/json' \
        -H 'Accept: application/json, text/event-stream' \
        -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}') || die "tools/list failed; is 'serve' running?"

    local names
    names=$(printf '%s' "$body" | grep -o '"name":"[a-z_]*"' | sed 's/"name":"//; s/"$//' | sort | tr '\n' ' ')
    log "tools: ${names}"
    for expected in search_ads get_ad list_niches; do
        [[ "$names" == *"$expected"* ]] || die "expected tool '${expected}' is missing"
    done
    log "all three tools are present"

    # A real call proves the REST forwarding works, not just the tool list.
    local call
    call=$(curl -fsS -m 30 -X POST "$MCP_URL" \
        -H "Authorization: Bearer ${ADS_KEY}" \
        -H 'Content-Type: application/json' \
        -H 'Accept: application/json, text/event-stream' \
        -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"list_niches","arguments":{}}}') \
        || die "list_niches call failed or timed out"
    [[ "$call" == *skincare* ]] || die "list_niches did not return niche data; response: ${call:0:200}"
    log "list_niches returned niche data"
}

require_uv() {
    command -v uv >/dev/null || die "uv is required to run the mcp-eval suite: https://docs.astral.sh/uv/"
}

cmd_run() {
    local label="${1:-}"
    [[ "$label" =~ ^[a-z0-9][a-z0-9-]*$ ]] || die "give the run a label of lowercase letters, digits and dashes, e.g. 'run before'"
    [[ -n "${ADS_KEY:-}" ]] || die "export ADS_KEY first (see 'key')"
    [[ -n "${ANTHROPIC_API_KEY:-}" ]] || die "export ANTHROPIC_API_KEY first; the model and the judge run on it"
    require_uv

    local out
    out="${REPORTS_DIR}/$(date -u +%Y%m%dT%H%M%SZ)-${label}"
    mkdir -p "$out"
    log "replaying the frozen tasks (${label}) against ${MCP_URL}"

    # mcp-eval imports the task files by path, so the suite directory must be importable for evaluators.py.
    local status=0
    (cd "$SUITE_DIR" && MCP_EVAL_RUN_DIR="${PWD}/../../${out}" PYTHONPATH=. uv run --frozen mcp-eval run tasks/ \
        --json "../../${out}/results.json" \
        --markdown "../../${out}/results.md" \
        --html "../../${out}/results.html") || status=$?

    log "reports in ${out} (results.json keeps every tool call and its arguments)"
    [[ "$status" -eq 0 ]] || log "at least one frozen check failed; read the reports before changing any description"
    return "$status"
}

# Resolves a run label to its newest report directory; a directory path is used as given.
resolve_run() {
    local ref="$1" dir=""
    if [[ -d "$ref" ]]; then
        dir="$ref"
    else
        for candidate in "${REPORTS_DIR}"/*Z-"${ref}"; do
            [[ -d "$candidate" ]] && dir="$candidate"
        done
    fi
    [[ -n "$dir" && -s "${dir}/results.json" ]] || die "no run '${ref}' with a results.json under ${REPORTS_DIR}"
    realpath "$dir"
}

cmd_diff() {
    [[ $# -eq 2 ]] || die "usage: scripts/mcp-eval.sh diff BEFORE AFTER (run labels or run directories)"
    require_uv
    local before after
    before=$(resolve_run "$1")
    after=$(resolve_run "$2")
    (cd "$SUITE_DIR" && uv run --frozen python compare.py \
        "${before}/results.json" "${after}/results.json" "$(basename "$before")" "$(basename "$after")")
}

cmd_selftest() {
    require_uv
    (cd "$SUITE_DIR" && uv run --frozen pytest -q)
}

case "${1:-}" in
    prepare)  cmd_prepare ;;
    serve)    cmd_serve ;;
    stop)     cmd_stop ;;
    reset)    cmd_reset ;;
    key)      cmd_key ;;
    check)    cmd_check ;;
    run)      cmd_run "${2:-}" ;;
    diff)     shift; cmd_diff "$@" ;;
    selftest) cmd_selftest ;;
    *)
        sed -n '2,22p' "$0" | sed 's/^# \{0,1\}//'
        exit 1
        ;;
esac
