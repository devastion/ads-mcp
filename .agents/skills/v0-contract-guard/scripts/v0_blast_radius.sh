#!/usr/bin/env bash
# Lists changes since a base ref that can reach v0, and checks the v0 contract test is untouched.
# Usage: v0_blast_radius.sh [base-ref]   (default: HEAD)
set -euo pipefail

base="${1:-HEAD}"
root="$(git rev-parse --show-toplevel)"
cd "$root"

contract_test='tests/Feature/Http/Controllers/V0/AdControllerTest.php'

shared_paths=(
  routes/api.php
  bootstrap/app.php
  app/Http/Controllers/V0
  app/Actions/AdSearchPredicates.php
  app/Actions
  app/Http/Resources
  app/Http/Middleware
  app/Http/Exceptions
  app/DTO
  app/Models
  app/Enums
  database/migrations
  config
)

echo "== changed files since ${base} that can reach v0 =="
git diff --name-only "$base" -- "${shared_paths[@]}" | sort -u || true

echo
echo "== v0 contract test vs ${base} =="
if git diff --quiet "$base" -- "$contract_test"; then
  echo "contract test unchanged"
else
  echo "CONTRACT TEST CHANGED - stop and report to the user"
  git diff --stat "$base" -- "$contract_test"
  exit 1
fi
