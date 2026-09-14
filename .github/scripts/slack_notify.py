"""Shared Slack notification helper for CI/CD pipelines.

Usage:
  python3 .github/scripts/slack_notify.py <template> [--extra-field KEY VALUE]

Templates: ready_to_prod, tests_failed, quality_gate_failed

Environment variables required:
  PR_NUMBER, PR_TITLE, PR_AUTHOR, PR_BRANCH, PR_URL, RUN_URL
  VERSION (only for ready_to_prod)
  TEST_SUMMARY_FILE (only for tests_failed, optional)
  SONAR_RESULT, APPROVE_RESULT, QA_RELEASE_RESULT, READY_TO_PROD_RESULT
    (only for quality_gate_failed)
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


def ready_to_prod_status() -> str:
    """El anuncio dice sólo lo que efectivamente corrió.

    El pipeline ya no tiene revisor automatizado, así que el anuncio nombra los
    gates que sí existen —PHPUnit y SonarCloud— en lugar de un code review.
    """
    return "Tests aprobados. PR listo para merge a producción."


def build_ready_to_prod() -> dict:
    fields = base_fields()
    fields.append({"type": "mrkdwn", "text": f"*Version:*\n`{env('VERSION', 'unknown')}`"})

    return {
        "blocks": [
            {"type": "header", "text": {"type": "plain_text", "text": "\U0001f50c Plugin | Ready to PROD", "emoji": True}},
            {"type": "section", "fields": fields},
            {"type": "section", "text": {"type": "mrkdwn", "text": ready_to_prod_status()}},
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


def build_quality_gate_failed() -> dict:
    """Aviso de las compuertas del PR que NO son los tests unitarios.

    El mensaje NOMBRA la compuerta caída. Un aviso que dijera sólo "el PR está
    rojo" obliga a abrir el run para saber cuál de las cuatro fue, que es
    exactamente el paso que este job existe para ahorrar.
    """
    # El orden es el del pipeline, y los nombres son los del job tal como
    # aparecen en la lista de checks del PR — para que el texto del aviso se
    # pueda buscar literal en la pestaña de checks.
    gates = [
        ("SonarCloud Analysis", env("SONAR_RESULT")),
        ("Approve & Label", env("APPROVE_RESULT")),
        ("QA Release (.zip)", env("QA_RELEASE_RESULT")),
        ("Ready to PROD", env("READY_TO_PROD_RESULT")),
    ]
    failed = [name for name, result in gates if result == "failure"]
    # Nunca vacío en la práctica (el paso que llama a esta plantilla sólo corre
    # si alguna cayó), pero un mensaje sin detalle es peor que uno genérico.
    detalle = "\n".join(f"• {sanitize_slack(name)}" for name in failed) or "• (sin detalle)"

    return {
        "blocks": [
            {"type": "header", "text": {"type": "plain_text", "text": "\U0001f50c Plugin | QA Quality Gate Failed", "emoji": True}},
            {"type": "section", "fields": base_fields()},
            {"type": "section", "text": {"type": "mrkdwn", "text": f"*Compuertas caídas:*\n{detalle}"}},
            action_buttons(),
        ]
    }


TEMPLATES = {
    "ready_to_prod": build_ready_to_prod,
    "tests_failed": build_tests_failed,
    "quality_gate_failed": build_quality_gate_failed,
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
