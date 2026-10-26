"""Offline tests for compare.py against reports serialized the way `mcp-eval run --json` writes them."""

import json
from dataclasses import asdict
from pathlib import Path

from mcp_eval.evaluators.shared import EvaluationRecord, EvaluatorResult
from mcp_eval.metrics import TestMetrics as Metrics, ToolCall

from compare import load_run, render


def record(name: str, passed: bool) -> EvaluationRecord:
    return EvaluationRecord(name=name, result=EvaluatorResult(passed=passed, actual="a ) passed=True error=None"), passed=passed)


def write_run(path: Path, tests: list[dict]) -> Path:
    """Mirror runner._run_async: TestResult.__dict__ dumped with default=str."""
    report = {"decorator_tests": tests, "dataset_reports": [], "summary": {}}
    path.write_text(json.dumps(report, default=str))
    return path


def task_result(name: str, checks: dict[str, bool], tools: list[str], tokens: int, cost: float) -> dict:
    metrics = Metrics(tool_calls=[ToolCall(name=tool, arguments={}, result={}, start_time=0, end_time=0) for tool in tools], iteration_count=len(tools) + 1)
    metrics.llm_metrics.total_tokens = tokens
    metrics.cost_estimate = cost
    serialized = {**metrics.__dict__, "tool_calls": [asdict(call) for call in metrics.tool_calls], "llm_metrics": asdict(metrics.llm_metrics)}
    return {
        "test_name": name,
        "passed": all(checks.values()),
        "evaluation_results": [record(check, passed) for check, passed in checks.items()],
        "metrics": serialized,
        "duration_ms": 1500.0,
    }


def test_load_run_reads_stringified_check_records(tmp_path):
    path = write_run(tmp_path / "results.json", [task_result("test_task_1", {"tool_selection": True, "first_call_arguments": False}, ["list_niches", "search_ads"], 900, 0.01)])

    run = load_run(path)

    assert run["test_task_1"]["checks"] == {"tool_selection": True, "first_call_arguments": False}
    assert run["test_task_1"]["tools"] == ["list_niches", "search_ads"]
    assert run["test_task_1"]["tokens"] == 900


def test_render_marks_fixed_and_regressed_checks_and_totals(tmp_path):
    before = load_run(write_run(tmp_path / "before.json", [
        task_result("test_task_1", {"tool_selection": True, "first_call_arguments": False}, ["search_ads", "search_ads"], 1200, 0.02),
        task_result("test_task_2", {"tool_selection": True}, [], 300, 0.005),
    ]))
    after = load_run(write_run(tmp_path / "after.json", [
        task_result("test_task_1", {"tool_selection": True, "first_call_arguments": True}, ["search_ads"], 800, 0.01),
        task_result("test_task_2", {"tool_selection": False}, ["search_ads"], 500, 0.007),
    ]))

    table = render(before, after, "before", "after")

    assert "Checks passed: 2/3 → 2/3" in table
    assert "| test_task_1 | first_call_arguments | ✗ | ✓ | fixed |" in table
    assert "| test_task_2 | tool_selection | ✓ | ✗ | regressed |" in table
    assert "| test_task_1 | tool_selection | ✓ | ✓ |  |" in table
    assert "| test_task_1 | search_ads → search_ads | search_ads | 1200 → 800 (-400) |" in table


def test_render_shows_a_check_missing_from_one_run(tmp_path):
    before = load_run(write_run(tmp_path / "before.json", [task_result("test_task_3", {"tool_selection": True}, ["list_niches"], 100, 0.0)]))
    after = load_run(write_run(tmp_path / "after.json", [task_result("test_task_3", {"tool_selection": True, "no_ad_query": True}, ["list_niches"], 100, 0.0)]))

    assert "| test_task_3 | no_ad_query | — | ✓ | new |" in render(before, after, "before", "after")
