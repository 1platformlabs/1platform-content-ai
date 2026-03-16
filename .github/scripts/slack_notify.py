"""Shared Slack notification helper for CI/CD pipelines.

Usage:
  python3 .github/scripts/slack_notify.py <template> [--extra-field KEY VALUE]

Templates: ready_to_prod, tests_failed, review_failed

Environment variables required:
  PR_NUMBER, PR_TITLE, PR_AUTHOR, PR_BRANCH, PR_URL, RUN_URL
  VERSION (only for ready_to_prod)
  TEST_SUMMARY_FILE (only for tests_failed, optional)
"""

import argparse
import json
import os
import sys
from pathlib import Path


def sanitize_slack(text: str) -> str:
    """Escape special characters for Slack mrkdwn."""
    text = text.replace("&", "&amp;")
    text = text.replace("<", "&lt;")
    text = text.replace(">", "&gt;")
    return text


def env(key: str, default: str = "") -> str:
    return os.environ.get(key, default)


def base_fields() -> list:
    return [
        {"type": "mrkdwn", "text": f"*PR:*\n#{env('PR_NUMBER')} {sanitize_slack(env('PR_TITLE'))}"},
        {"type": "mrkdwn", "text": f"*Author:*\n{sanitize_slack(env('PR_AUTHOR'))}"},
        {"type": "mrkdwn", "text": f"*Branch:*\n`{sanitize_slack(env('PR_BRANCH'))}`"},
    ]


def action_buttons(extra_buttons: list | None = None) -> dict:
    buttons = [
        {"type": "button", "text": {"type": "plain_text", "text": "Ver PR"}, "url": env("PR_URL")},
        {"type": "button", "text": {"type": "plain_text", "text": "Ver Logs"}, "url": env("RUN_URL")},
    ]
    if extra_buttons:
        buttons.extend(extra_buttons)
    return {"type": "actions", "elements": buttons}


def build_ready_to_prod() -> dict:
    fields = base_fields()
    fields.append({"type": "mrkdwn", "text": f"*Version:*\n`{env('VERSION', 'unknown')}`"})

    return {
        "blocks": [
            {"type": "header", "text": {"type": "plain_text", "text": "\U0001f50c Plugin | Ready to PROD", "emoji": True}},
            {"type": "section", "fields": fields},
            {"type": "section", "text": {"type": "mrkdwn", "text": "Tests y code review aprobados. PR listo para merge a producción."}},
            {
                "type": "actions",
                "elements": [
                    {"type": "button", "text": {"type": "plain_text", "text": "Ver PR"}, "url": env("PR_URL"), "style": "primary"},
                    {"type": "button", "text": {"type": "plain_text", "text": "Ver Pipeline"}, "url": env("RUN_URL")},
                ],
            },
        ]
    }


def build_tests_failed() -> dict:
    error_text = "No test summary available"
    summary_file = env("TEST_SUMMARY_FILE", "test_summary.txt")
    p = Path(summary_file)
    if p.exists():
        error_text = sanitize_slack(p.read_text(encoding="utf-8", errors="replace")[:1500])

    return {
        "blocks": [
            {"type": "header", "text": {"type": "plain_text", "text": "\U0001f50c Plugin | QA Tests Failed", "emoji": True}},
            {"type": "section", "fields": base_fields()},
            {"type": "section", "text": {"type": "mrkdwn", "text": f"*Error:*\n```{error_text}```"}},
            action_buttons(),
        ]
    }


def build_review_failed() -> dict:
    return {
        "blocks": [
            {"type": "header", "text": {"type": "plain_text", "text": "\U0001f50c Plugin | Code Review: Cambios Requeridos", "emoji": True}},
            {"type": "section", "fields": base_fields()},
            {"type": "section", "text": {"type": "mrkdwn", "text": "Claude AI detectó hallazgos críticos en el code review. Revisión manual requerida."}},
            action_buttons(),
        ]
    }


TEMPLATES = {
    "ready_to_prod": build_ready_to_prod,
    "tests_failed": build_tests_failed,
    "review_failed": build_review_failed,
}


def main():
    parser = argparse.ArgumentParser(description="Generate Slack notification payload")
    parser.add_argument("template", choices=TEMPLATES.keys())
    parser.add_argument("-o", "--output", default="slack_payload.json")
    args = parser.parse_args()

    payload = TEMPLATES[args.template]()
    Path(args.output).write_text(json.dumps(payload), encoding="utf-8")
    print(f"Slack payload written to {args.output}")


if __name__ == "__main__":
    main()
