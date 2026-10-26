"""Compare two frozen-task runs and print a Markdown diff of checks and metrics.

mcp-eval 0.1.10 has no run-to-run comparison, and `mcp-eval run --json` writes each
check record as its Python repr (json.dump with default=str), so this reads both forms.

    uv run python compare.py BEFORE/results.json AFTER/results.json [BEFORE_LABEL AFTER_LABEL]
"""

import json
import re
import sys
from pathlib import Path
from typing import Any

# A record repr ends with ") passed=<bool> error=<repr>"; the last match is the record's own flag.
RECORD_NAME = re.compile(r"^name='([^']*)'")
RECORD_PASSED = re.compile(r"\) passed=(True|False) error=")


def parse_record(record: Any) -> tuple[str, bool]:
    if isinstance(record, dict):
        return record["name"], bool(record["passed"])
    name = RECORD_NAME.match(record)
    passed = RECORD_PASSED.findall(record)
    if name is None or not passed:
        raise ValueError(f"unrecognised check record: {record[:120]}")
    return name.group(1), passed[-1] == "True"


def load_run(path: Path) -> dict[str, dict[str, Any]]:
    report = json.loads(Path(path).read_text(encoding="utf-8"))
    run = {}
    for test in report["decorator_tests"]:
        metrics = test.get("metrics") or {}
        llm = metrics.get("llm_metrics") or {}
        run[test["test_name"]] = {
            "checks": dict(parse_record(record) for record in test["evaluation_results"]),
            "tools": [call["name"] for call in metrics.get("tool_calls", [])],
            "tokens": llm.get("total_tokens", 0),
            "cost": metrics.get("cost_estimate", 0.0),
            "duration_ms": test.get("duration_ms", 0.0),
        }
    return run


def _mark(passed: bool | None) -> str:
    return "—" if passed is None else ("✓" if passed else "✗")


def _change(before: bool | None, after: bool | None) -> str:
    if before is None:
        return "new"
    if after is None:
        return "removed"
    if before == after:
        return ""
    return "fixed" if after else "regressed"


def _tools(tools: list[str]) -> str:
    return " → ".join(tools) or "(none)"


def render(before: dict, after: dict, before_label: str, after_label: str) -> str:
    tasks = sorted(before.keys() | after.keys())
    empty = {"checks": {}, "tools": [], "tokens": 0, "cost": 0.0, "duration_ms": 0.0}

    def passed_total(run: dict) -> str:
        checks = [passed for test in run.values() for passed in test["checks"].values()]
        return f"{sum(checks)}/{len(checks)}"

    lines = [
        f"## Frozen tasks: {before_label} → {after_label}",
        "",
        f"Checks passed: {passed_total(before)} → {passed_total(after)}",
        "",
        f"| Task | Check | {before_label} | {after_label} | Change |",
        "| --- | --- | :-: | :-: | --- |",
    ]
    for task in tasks:
        old, new = before.get(task, empty)["checks"], after.get(task, empty)["checks"]
        for check in list(dict.fromkeys([*old, *new])):
            lines.append(f"| {task} | {check} | {_mark(old.get(check))} | {_mark(new.get(check))} | {_change(old.get(check), new.get(check))} |")

    lines += [
        "",
        f"| Task | Tool calls ({before_label}) | Tool calls ({after_label}) | Tokens | Cost (USD) | Duration (s) |",
        "| --- | --- | --- | --- | --- | --- |",
    ]
    for task in tasks:
        old, new = before.get(task, empty), after.get(task, empty)
        lines.append(
            f"| {task} | {_tools(old['tools'])} | {_tools(new['tools'])} "
            f"| {old['tokens']} → {new['tokens']} ({new['tokens'] - old['tokens']:+d}) "
            f"| {old['cost']:.4f} → {new['cost']:.4f} "
            f"| {old['duration_ms'] / 1000:.1f} → {new['duration_ms'] / 1000:.1f} |"
        )
    return "\n".join(lines) + "\n"


if __name__ == "__main__":
    if len(sys.argv) not in (3, 5):
        sys.exit(__doc__)
    before_path, after_path = Path(sys.argv[1]), Path(sys.argv[2])
    labels = sys.argv[3:5] or [before_path.parent.name, after_path.parent.name]
    print(render(load_run(before_path), load_run(after_path), *labels), end="")
