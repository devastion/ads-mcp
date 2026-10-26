"""Offline tests for the frozen-task evaluators. No model, server or API key."""

import json

from mcp_eval.evaluators.base import EvaluatorContext
from mcp_eval.metrics import TestMetrics as Metrics, ToolCall

from evaluators import BodyPagedToEnd, FirstDataCall, FirstDataTool, NoCorrection


def call(name: str, arguments: dict, result: dict | None = None, is_error: bool = False) -> ToolCall:
    content = {"content": [{"type": "text", "text": json.dumps(result or {})}]}
    return ToolCall(name=name, arguments=arguments, result=content, start_time=0, end_time=0, is_error=is_error)


def context(*calls: ToolCall) -> EvaluatorContext:
    return EvaluatorContext(inputs=None, output=None, expected_output=None, metadata=None, metrics=Metrics(tool_calls=list(calls)))


TASK_1 = FirstDataCall(
    "search_ads",
    required={"niche": ["skincare"], "format": "video", "status": "active", "started_after": "2026-09-25"},
    defaults={"sort": "newest", "limit": 5},
)


def test_first_data_call_accepts_required_arguments_with_defaults_omitted():
    assert TASK_1.evaluate_sync(context(call("search_ads", {"niche": ["skincare"], "format": "video", "status": "active", "started_after": "2026-09-25"}))).passed


def test_first_data_call_accepts_explicit_defaults_after_niche_discovery():
    result = TASK_1.evaluate_sync(context(
        call("list_niches", {}),
        call("search_ads", {"niche": ["skincare"], "format": "video", "status": "active", "started_after": "2026-09-25", "sort": "newest", "limit": 5}),
    ))

    assert result.passed


def test_first_data_call_rejects_an_inclusive_date():
    assert not TASK_1.evaluate_sync(context(call("search_ads", {"niche": ["skincare"], "format": "video", "status": "active", "started_after": "2026-09-24"}))).passed


def test_first_data_call_rejects_a_non_default_value_for_an_optional_argument():
    assert not TASK_1.evaluate_sync(context(call("search_ads", {"niche": ["skincare"], "format": "video", "status": "active", "started_after": "2026-09-25", "sort": "impressions"}))).passed


def test_first_data_call_rejects_an_invented_argument():
    assert not TASK_1.evaluate_sync(context(call("search_ads", {"niche": ["skincare"], "format": "video", "status": "active", "started_after": "2026-09-25", "q": "serum"}))).passed


def test_first_data_call_rejects_the_wrong_tool_and_no_call():
    assert not TASK_1.evaluate_sync(context(call("get_ad", {"id": 1}))).passed
    assert not TASK_1.evaluate_sync(context(call("list_niches", {}))).passed


def test_first_data_call_without_discovery_tools_counts_list_niches_as_the_data_call():
    evaluator = FirstDataCall("list_niches", required={}, discovery_tools=())

    assert evaluator.evaluate_sync(context(call("list_niches", {}))).passed
    assert not evaluator.evaluate_sync(context(call("search_ads", {"niche": ["kitchen"]}), call("list_niches", {}))).passed


def test_first_data_tool_ignores_discovery_and_checks_the_next_tool():
    assert FirstDataTool("search_ads").evaluate_sync(context(call("list_niches", {}), call("search_ads", {}), call("get_ad", {"id": 1}))).passed
    assert not FirstDataTool("get_ad").evaluate_sync(context(call("search_ads", {}), call("get_ad", {"id": 1}))).passed


def test_first_data_tool_none_expects_restraint():
    assert FirstDataTool(None).evaluate_sync(context()).passed
    assert FirstDataTool(None).evaluate_sync(context(call("list_niches", {}))).passed
    assert not FirstDataTool(None).evaluate_sync(context(call("search_ads", {"sort": "impressions"}))).passed


def test_no_correction_accepts_discovery_and_legitimate_continuation():
    result = NoCorrection().evaluate_sync(context(
        call("list_niches", {}),
        call("search_ads", {"niche": ["skincare"]}),
        call("search_ads", {"niche": ["skincare"], "cursor": "abc"}),
        call("get_ad", {"id": 7}),
        call("get_ad", {"id": 7, "body_offset": 1000}),
    ))

    assert result.passed


def test_no_correction_rejects_a_failed_call():
    assert not NoCorrection().evaluate_sync(context(call("search_ads", {"niche": ["skin-care"]}, is_error=True))).passed


def test_no_correction_rejects_a_repeated_call_without_a_continuation_argument():
    assert not NoCorrection().evaluate_sync(context(call("search_ads", {"niche": ["skincare"]}), call("search_ads", {"niche": ["skincare"], "status": "active"}))).passed
    assert not NoCorrection().evaluate_sync(context(call("list_niches", {}), call("list_niches", {}))).passed


def page(offset: int, has_more: bool, next_offset: int | None) -> dict:
    return {"data": {"id": 20002}, "body_offset": offset, "body_has_more": has_more, "body_next_offset": next_offset}


def test_body_paged_to_end_follows_next_offset_until_the_body_is_complete():
    result = BodyPagedToEnd(20002).evaluate_sync(context(
        call("get_ad", {"id": 20002}, page(0, True, 1000)),
        call("get_ad", {"id": 20002, "body_offset": 1000}, page(1000, True, 2000)),
        call("get_ad", {"id": 20002, "body_offset": 2000}, page(2000, False, None)),
    ))

    assert result.passed


def test_body_paged_to_end_rejects_a_gap_or_a_stop_before_the_end():
    gap = context(
        call("get_ad", {"id": 20002}, page(0, True, 1000)),
        call("get_ad", {"id": 20002, "body_offset": 2000}, page(2000, False, None)),
    )
    stopped = context(call("get_ad", {"id": 20002}, page(0, True, 1000)))

    assert not BodyPagedToEnd(20002).evaluate_sync(gap).passed
    assert not BodyPagedToEnd(20002).evaluate_sync(stopped).passed


def test_body_paged_to_end_rejects_another_ad():
    assert not BodyPagedToEnd(20002).evaluate_sync(context(call("get_ad", {"id": 20001}, page(0, False, None)))).passed
