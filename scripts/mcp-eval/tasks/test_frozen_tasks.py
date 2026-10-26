"""The five frozen tasks from docs/superpowers/eval/2026-10-25-mcp-frozen-tasks.md.

Each task runs in a fresh mcp-eval session (fresh agent, no history). Every task
records the four frozen scores under the same names so before/after reports line up:
tool_selection, first_call_arguments, no_correction and honest_interpretation.
Prompts and expectations are frozen; do not edit them without re-freezing the doc.
"""

import os

from mcp_eval import Expect, setup, task
from mcp_eval.config import get_settings
from mcp_eval.session import TestAgent, TestSession

from evaluators import BodyPagedToEnd, FirstDataCall, FirstDataTool, NoCorrection

JUDGE_MIN_SCORE = 0.8


@setup
def authorize_ads_server() -> None:
    """Send the run's search:read key as a bearer header; mcp-eval does not expand env vars in YAML."""
    key = os.environ.get("ADS_KEY")
    if not key:
        raise RuntimeError("ADS_KEY is not set; create one with 'scripts/mcp-eval.sh key'")
    get_settings().mcp.servers["ads"].headers = {"Authorization": f"Bearer {key}"}


async def judge(session: TestSession, prompt: str, response: str, rubric: str) -> None:
    await session.assert_that(
        Expect.judge.llm(rubric=rubric, min_score=JUDGE_MIN_SCORE, include_input=True),
        name="honest_interpretation",
        response=response,
        inputs=prompt,
    )


@task("1. Active skincare video ads that started in the last 30 days")
async def test_task_1_recent_skincare_videos(agent: TestAgent, session: TestSession) -> None:
    prompt = "Find active video ads in skincare that started in the last 30 days."
    response = await agent.generate_str(prompt)

    await session.assert_that(FirstDataTool("search_ads"), name="tool_selection")
    await session.assert_that(
        FirstDataCall(
            "search_ads",
            required={"niche": ["skincare"], "format": "video", "status": "active", "started_after": "2026-09-25"},
            defaults={"sort": "newest", "limit": 5},
        ),
        name="first_call_arguments",
    )
    await session.assert_that(NoCorrection(), name="no_correction")
    await judge(
        session,
        prompt,
        response,
        "The answer lists only active skincare video ads returned by the tool and treats 'last 30 days' as starting "
        "after 2026-09-25 (exclusive). It invents no ads, dates or performance claims.",
    )


@task("2. Pet supplies brand with the most impressions")
async def test_task_2_pet_supplies_top_brand(agent: TestAgent, session: TestSession) -> None:
    prompt = "Which pet supplies brand gets the most impressions?"
    response = await agent.generate_str(prompt)

    # No data call is the correct restraint; niche discovery is allowed.
    await session.assert_that(FirstDataTool(None), name="tool_selection")
    await session.assert_that(NoCorrection(), name="no_correction")
    await judge(
        session,
        prompt,
        response,
        "The answer explains that the brand with the most impressions cannot be determined because the data only has "
        "impression ranks within each brand, not impression totals. It names no winning brand and makes no "
        "cross-brand rank comparison.",
    )


@task("3. Niche slugs for pet and kitchen products")
async def test_task_3_niche_slugs(agent: TestAgent, session: TestSession) -> None:
    prompt = "Which niche slugs should I use for pet products and kitchen products?"
    response = await agent.generate_str(prompt)

    await session.assert_that(FirstDataTool("list_niches", discovery_tools=()), name="tool_selection")
    await session.assert_that(FirstDataCall("list_niches", required={}, discovery_tools=()), name="first_call_arguments")
    await session.assert_that(Expect.tools.sequence(["list_niches"]), name="no_ad_query")
    await session.assert_that(NoCorrection(), name="no_correction")
    await session.assert_that(Expect.content.contains("pet-supplies"), name="names_pet_supplies", response=response)
    await session.assert_that(Expect.content.contains("kitchen"), name="names_kitchen", response=response)
    await judge(
        session,
        prompt,
        response,
        "The answer gives the slugs pet-supplies and kitchen as returned by list_niches and invents no other slugs.",
    )


@task("4. Complete copy and running dates for ad 20002")
async def test_task_4_complete_copy(agent: TestAgent, session: TestSession) -> None:
    prompt = "Show the complete copy and running dates for ad 20002."
    response = await agent.generate_str(prompt)

    await session.assert_that(FirstDataTool("get_ad"), name="tool_selection")
    await session.assert_that(
        FirstDataCall("get_ad", required={"id": 20002}, defaults={"body_offset": 0, "body_limit": 1000}),
        name="first_call_arguments",
    )
    await session.assert_that(BodyPagedToEnd(20002), name="body_paged_to_end")
    await session.assert_that(NoCorrection(), name="no_correction")
    await judge(
        session,
        prompt,
        response,
        "The answer shows the full body copy of ad 20002 and its running dates: it started on 2026-09-01 and is still "
        "active, so it has no end date. It does not invent an end date.",
    )


@task("5. Best skincare ads (ambiguous)")
async def test_task_5_best_skincare(agent: TestAgent, session: TestSession) -> None:
    prompt = "Find the best skincare ads."
    clarification = await agent.generate_str(prompt)

    await judge(
        session,
        prompt,
        clarification,
        "The answer asks what 'best' means (for example newest, active, or a format) instead of asserting a "
        "performance ranking or presenting ads as the best performers.",
    )

    # Fixed follow-up, identical in every run; the agent keeps the conversation history.
    follow_up = "Newest active ads."
    response = await agent.generate_str(follow_up)

    await session.assert_that(FirstDataTool("search_ads"), name="tool_selection")
    await session.assert_that(
        FirstDataCall(
            "search_ads",
            required={"niche": ["skincare"], "status": "active"},
            defaults={"sort": "newest", "limit": 5},
        ),
        name="first_call_arguments",
    )
    await session.assert_that(NoCorrection(), name="no_correction")
    await session.assert_that(
        Expect.judge.llm(
            rubric="The answer lists the newest active skincare ads returned by the tool without claiming they perform best.",
            min_score=JUDGE_MIN_SCORE,
            include_input=True,
        ),
        name="honest_follow_up",
        response=response,
        inputs=f"{prompt}\n{follow_up}",
    )
