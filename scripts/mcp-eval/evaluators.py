"""Evaluators for the scoring rules in docs/superpowers/eval/2026-10-25-mcp-frozen-tasks.md.

The built-in Expect.tools.called_with matches any call and needs every listed
argument, so it cannot express "the first data call, defaults may be omitted,
nothing invented". These evaluators read the same OTEL-derived tool calls.
"""

import json
from typing import Any

from mcp_eval.evaluators.base import EvaluatorContext, SyncEvaluator
from mcp_eval.evaluators.shared import EvaluatorResult
from mcp_eval.metrics import ToolCall

# Arguments that make a repeated call a continuation rather than a retry.
CONTINUATION_ARGUMENTS = {"search_ads": "cursor", "get_ad": "body_offset"}


def _normalize(value: Any) -> Any:
    """Trace attributes may carry arrays as tuples; compare them as lists."""
    if isinstance(value, (list, tuple)):
        return [_normalize(item) for item in value]
    return value


def _payload(tool_call: ToolCall) -> dict[str, Any]:
    """Decode the single JSON text block every ads tool returns."""
    try:
        text = tool_call.result["content"][0]["text"]
        decoded = json.loads(text)
    except (KeyError, IndexError, TypeError, ValueError):
        return {}
    return decoded if isinstance(decoded, dict) else {}


class FirstDataTool(SyncEvaluator):
    """The first call after optional discovery uses the expected tool; None expects no data call."""

    requires_final_metrics = True

    def __init__(self, tool_name: str | None, discovery_tools: tuple[str, ...] = ("list_niches",)):
        self.tool_name = tool_name
        self.discovery_tools = discovery_tools

    def evaluate_sync(self, ctx: EvaluatorContext) -> EvaluatorResult:
        data_calls = [call.name for call in ctx.tool_calls if call.name not in self.discovery_tools]
        first = data_calls[0] if data_calls else None

        return EvaluatorResult(
            passed=first == self.tool_name,
            expected=self.tool_name or "no data call",
            actual=first or "no data call",
            details={"tool_sequence": [call.name for call in ctx.tool_calls]},
        )

    def to_dict(self) -> dict[str, Any]:
        return {"tool_name": self.tool_name, "discovery_tools": list(self.discovery_tools)}


class FirstDataCall(SyncEvaluator):
    """The first call after optional discovery uses the expected tool and arguments.

    Every required argument must match. Any other argument must be an omitted-able
    default with its default value; anything else counts as invented.
    """

    requires_final_metrics = True

    def __init__(
        self,
        tool_name: str,
        required: dict[str, Any],
        defaults: dict[str, Any] | None = None,
        discovery_tools: tuple[str, ...] = ("list_niches",),
    ):
        self.tool_name = tool_name
        self.required = required
        self.defaults = defaults or {}
        self.discovery_tools = discovery_tools

    def evaluate_sync(self, ctx: EvaluatorContext) -> EvaluatorResult:
        expected = {"tool": self.tool_name, "required": self.required, "omittable_defaults": self.defaults}
        data_calls = [call for call in ctx.tool_calls if call.name not in self.discovery_tools]
        if not data_calls:
            return EvaluatorResult(passed=False, expected=expected, actual="no data call")

        first = data_calls[0]
        arguments = {key: _normalize(value) for key, value in first.arguments.items()}
        problems = []
        if first.name != self.tool_name:
            problems.append(f"called {first.name}")
        for key, value in self.required.items():
            if arguments.get(key) != _normalize(value):
                problems.append(f"{key}={arguments.get(key)!r}, expected {value!r}")
        for key, value in arguments.items():
            if key in self.required:
                continue
            if key not in self.defaults:
                problems.append(f"unexpected argument {key}={value!r}")
            elif value != _normalize(self.defaults[key]):
                problems.append(f"{key}={value!r}, expected default {self.defaults[key]!r} or omitted")

        return EvaluatorResult(
            passed=not problems,
            expected=expected,
            actual={"tool": first.name, "arguments": arguments},
            details={"problems": problems},
        )

    def to_dict(self) -> dict[str, Any]:
        return {"tool_name": self.tool_name, "required": self.required, "defaults": self.defaults, "discovery_tools": list(self.discovery_tools)}


class NoCorrection(SyncEvaluator):
    """No tool call failed and no tool was repeated except to continue a page."""

    requires_final_metrics = True

    def evaluate_sync(self, ctx: EvaluatorContext) -> EvaluatorResult:
        problems = []
        seen: set[str] = set()
        for index, call in enumerate(ctx.tool_calls):
            if call.is_error:
                problems.append(f"call {index} ({call.name}) returned an error")
            continuation = CONTINUATION_ARGUMENTS.get(call.name)
            if call.name in seen and (continuation is None or continuation not in call.arguments):
                problems.append(f"call {index} repeats {call.name} without {continuation or 'a reason'}")
            seen.add(call.name)

        return EvaluatorResult(
            passed=not problems,
            expected="no failed call and no retry",
            actual=[{"tool": call.name, "arguments": call.arguments, "is_error": call.is_error} for call in ctx.tool_calls],
            details={"problems": problems},
        )


class BodyPagedToEnd(SyncEvaluator):
    """get_ad pages for one ad follow body_next_offset without gaps until body_has_more is false."""

    requires_final_metrics = True

    def __init__(self, ad_id: int):
        self.ad_id = ad_id

    def evaluate_sync(self, ctx: EvaluatorContext) -> EvaluatorResult:
        pages = [call for call in ctx.tool_calls if call.name == "get_ad"]
        problems = []
        expected_offset = 0
        complete = False
        for call in pages:
            if call.arguments.get("id") != self.ad_id:
                problems.append(f"get_ad id {call.arguments.get('id')!r}, expected {self.ad_id}")
                continue
            offset = call.arguments.get("body_offset", 0)
            if offset != expected_offset:
                problems.append(f"body_offset {offset}, expected {expected_offset}")
            payload = _payload(call)
            if call.is_error or "body_has_more" not in payload:
                problems.append(f"get_ad at body_offset {offset} returned no body page")
                break
            if not payload["body_has_more"]:
                complete = True
                break
            expected_offset = payload.get("body_next_offset")
        if not complete:
            problems.append("body_has_more was never false")

        return EvaluatorResult(
            passed=not problems,
            expected=f"get_ad id {self.ad_id} from offset 0 along body_next_offset to the end",
            actual=[call.arguments for call in pages],
            details={"problems": problems},
        )

    def to_dict(self) -> dict[str, Any]:
        return {"ad_id": self.ad_id}
