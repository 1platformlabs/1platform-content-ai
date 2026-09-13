#!/usr/bin/env python3
"""The version a PROD merge publishes: derived from tags, stamped into the package, never pushed.

`main` is protected with `enforce_admins`, so the old `Auto Version Bump` job —
which committed the new version to `main` and pushed it — was rejected with
GH006 on every merge from 2026-08-16 on, and `release` and `svn-deploy` never
ran: WordPress.org stayed on 3.2.0 while `main` moved on (issue #208).

The fix keeps every published artifact carrying the right version without
writing to `main`:

* the previous version is the latest stable tag (``vX.Y.Z``), never a file;
* the bump is the strongest conventional-commit signal among the first-parent
  commits since that tag (a run that failed leaves its merge in the range, so
  the next release still classifies it);
* the version is stamped into the COPY that ships — the plugin header and the
  readme `Stable tag:` plus its `== Changelog ==` section, which is what
  WordPress.org renders. `CONTAI_VERSION` is read from that header at runtime,
  so stamping the header is enough (issue #209 b);
* the release's changelog lines travel in the annotation of its tag, and every
  later stamp restores them, so the published readme keeps its history even
  though none of it is committed.

Subcommands (all read the git checkout in the current directory):

  resolve                         key=value lines for $GITHUB_OUTPUT
  stamp --version X --out-dir D   stamp the workspace; write notes.md and tag-message.txt to D
  verify --version X              assert the stamped workspace has the published shape
"""

from __future__ import annotations

import argparse
import re
import subprocess
import sys
from pathlib import Path

PLUGIN_FILE = "1platform-content-ai.php"
README_FILE = "readme.txt"

STABLE_TAG = re.compile(r"^v(\d+)\.(\d+)\.(\d+)$")
SEMVER = re.compile(r"^(\d+)\.(\d+)\.(\d+)$")
# Composed at runtime on purpose: written literally, this file would itself read
# as a breaking-change trailer to anything that greps the repository for it.
BREAKING_TRAILER = re.compile(r"^" + "BREAKING" + r"[ -]CHANGE:", re.MULTILINE)
CONVENTIONAL_BANG = re.compile(r"^[a-z][a-z0-9]*(\([^)]*\))?!:")
CONVENTIONAL_SUBJECT = re.compile(
    r"^(?P<type>[a-z][a-z0-9]*)(\((?P<scope>[^)]*)\))?(?P<bang>!)?:\s*(?P<desc>.+)$"
)
FEAT = re.compile(r"^feat(\([^)]*\))?!?:")
LEGACY_BUMP_COMMIT = re.compile(r"^chore: bump version to ")
HEADER_VERSION = re.compile(r"^([ \t/*#@]*Version:[ \t]*)(\S+)", re.MULTILINE)
STABLE_TAG_LINE = re.compile(r"^(Stable tag:[ \t]*)(\S+)", re.MULTILINE)
HEADING = re.compile(r"^= (?P<name>.+?) =[ \t]*$")

# Keep a Changelog sections, as the 49 entries already in readme.txt use them.
SECTION_FOR_TYPE = {"feat": "Added", "fix": "Fixed"}
# Changes a site owner cannot observe. They still ship (every merge publishes),
# but they do not get a line in what WordPress.org shows to users.
NOT_USER_FACING = {"build", "chore", "ci", "docs", "style", "test"}
MAINTENANCE_LINE = "* Changed: Maintenance release."


class ReleaseError(Exception):
    pass


def git(*args: str) -> str:
    return subprocess.run(["git", *args], check=True, capture_output=True, text=True).stdout


def parse_version(value: str) -> tuple[int, int, int]:
    match = SEMVER.match(value)
    if not match:
        raise ReleaseError(f"not an X.Y.Z version: {value!r}")
    return (int(match.group(1)), int(match.group(2)), int(match.group(3)))


def stable_tags() -> list[tuple[tuple[int, int, int], str]]:
    tags = []
    for name in git("tag", "--list", "v*").split():
        match = STABLE_TAG.match(name)
        if match:
            tags.append(((int(match.group(1)), int(match.group(2)), int(match.group(3))), name))
    return sorted(tags, reverse=True)


def commits_since(tag: str | None) -> list[tuple[str, str, str]]:
    """(sha, subject, body) of the first-parent commits after `tag`, oldest first."""
    rev_range = f"{tag}..HEAD" if tag else "HEAD"
    raw = git("log", "--first-parent", "--reverse", "--format=%H%x1f%s%x1f%b%x1e", rev_range)
    commits = []
    for record in raw.split("\x1e"):
        record = record.strip("\n")
        if not record:
            continue
        sha, subject, body = (record.split("\x1f") + ["", ""])[:3]
        if LEGACY_BUMP_COMMIT.match(subject):
            continue
        commits.append((sha, subject.strip(), body))
    return commits if tag else commits[-1:]


def bump_type(commits: list[tuple[str, str, str]]) -> str:
    """Strongest signal wins: `!`/trailer → major, `feat` → minor, anything else → patch."""
    if any(CONVENTIONAL_BANG.match(subject) or BREAKING_TRAILER.search(body) for _, subject, body in commits):
        return "major"
    if any(FEAT.match(subject) for _, subject, _ in commits):
        return "minor"
    return "patch"


def bumped(previous: tuple[int, int, int], kind: str) -> str:
    major, minor, patch = previous
    if kind == "major":
        return f"{major + 1}.0.0"
    if kind == "minor":
        return f"{major}.{minor + 1}.0"
    return f"{major}.{minor}.{patch + 1}"


def resolve() -> dict[str, str]:
    tags = stable_tags()
    head_tags = [name for name in git("tag", "--points-at", "HEAD").split() if STABLE_TAG.match(name)]
    if head_tags:
        # A re-run of a run that already published: minting another version for
        # the same commit would publish the same code twice under two numbers.
        return {
            "already_released": "true",
            "new_version": head_tags[0][1:],
            "bump_type": "none",
            "prev_tag": head_tags[0],
        }
    prev_tag = tags[0][1] if tags else ""
    commits = commits_since(prev_tag or None)
    if not commits:
        raise ReleaseError(f"HEAD has no commits after {prev_tag} — nothing to publish")
    kind = bump_type(commits)
    return {
        "already_released": "false",
        "new_version": bumped(tags[0][0] if tags else (0, 0, 0), kind),
        "bump_type": kind,
        "prev_tag": prev_tag,
    }


def entry_line(subject: str) -> str:
    """One readme line, in the `* <Section>: <text> (#N)` shape the readme already uses."""
    parsed = CONVENTIONAL_SUBJECT.match(subject)
    if not parsed:
        return f"* Changed: {subject}"
    section = SECTION_FOR_TYPE.get(parsed["type"], "Changed")
    desc = parsed["desc"].strip()
    if parsed["bang"]:
        section, desc = "Changed", f"**BREAKING** {desc}"
    # The scope stays out: 0 of the 49 existing entries repeat a second colon.
    return f"* {section}: {desc}"


def wrote_its_own_entry(sha: str) -> bool:
    """A merge that already added a changelog line to readme.txt documented itself."""
    diff = git("show", "--format=", "--unified=0", sha, "--", README_FILE)
    return any(line.startswith("+* ") for line in diff.splitlines())


def generated_lines(prev_tag: str | None) -> list[str]:
    lines = []
    for sha, subject, _ in commits_since(prev_tag):
        parsed = CONVENTIONAL_SUBJECT.match(subject)
        if parsed and parsed["type"] in NOT_USER_FACING and not parsed["bang"]:
            continue
        if wrote_its_own_entry(sha):
            continue
        lines.append(entry_line(subject))
    return lines


def tag_changelog_lines(tag: str) -> list[str]:
    message = git("for-each-ref", f"refs/tags/{tag}", "--format=%(contents)")
    return [line.rstrip() for line in message.splitlines() if line.startswith("* ")]


class Changelog:
    """The `== Changelog ==` section of readme.txt, edited as lines."""

    def __init__(self, readme: str) -> None:
        lines = readme.split("\n")
        try:
            self.start = next(i for i, line in enumerate(lines) if line.strip() == "== Changelog ==") + 1
        except StopIteration:
            raise ReleaseError("readme.txt has no == Changelog == section") from None
        self.end = next(
            (i for i in range(self.start, len(lines)) if lines[i].startswith("== ")), len(lines)
        )
        self.lines = lines

    def text(self) -> str:
        return "\n".join(self.lines)

    def _headings(self) -> list[tuple[int, str]]:
        found = []
        for i in range(self.start, self.end):
            match = HEADING.match(self.lines[i])
            if match:
                found.append((i, match.group("name")))
        return found

    def block(self, version: str) -> tuple[int, int] | None:
        headings = self._headings()
        for position, (index, name) in enumerate(headings):
            if name == version:
                stop = headings[position + 1][0] if position + 1 < len(headings) else self.end
                return index, stop
        return None

    def bullets(self, version: str) -> list[str]:
        span = self.block(version)
        if span is None:
            return []
        return [line.rstrip() for line in self.lines[span[0] + 1 : span[1]] if line.startswith("* ")]

    def count_headings(self, version: str) -> int:
        return sum(1 for _, name in self._headings() if name == version)

    def _insert(self, index: int, new_lines: list[str]) -> None:
        self.lines[index:index] = new_lines
        self.end += len(new_lines)

    def ensure(self, version: str, wanted: list[str]) -> None:
        span = self.block(version)
        if span is None:
            if not wanted:
                return
            target = parse_version(version)
            before = next(
                (
                    index
                    for index, name in self._headings()
                    if SEMVER.match(name) and parse_version(name) < target
                ),
                None,
            )
            if before is None:
                at = self.end
                while at > self.start and self.lines[at - 1].strip() == "":
                    at -= 1
                self._insert(at, ["", f"= {version} =", *wanted])
            else:
                self._insert(before, [f"= {version} =", *wanted, ""])
            return
        present = set(self.bullets(version))
        missing = [line for line in wanted if line not in present]
        if not missing:
            return
        heading, stop = span
        last_bullet = max(
            (i for i in range(heading + 1, stop) if self.lines[i].startswith("* ")), default=heading
        )
        self._insert(last_bullet + 1, missing)


def rewrite_one(pattern: re.Pattern[str], text: str, value: str, what: str) -> str:
    new_text, count = pattern.subn(lambda m: m.group(1) + value, text, count=1)
    if count != 1:
        raise ReleaseError(f"no {what} line to stamp")
    return new_text


def read_text(path: str) -> tuple[str, str]:
    raw = Path(path).read_bytes().decode("utf-8")
    eol = "\r\n" if "\r\n" in raw else "\n"
    return raw.replace("\r\n", "\n"), eol


def write_text(path: str, text: str, eol: str) -> None:
    Path(path).write_bytes(text.replace("\n", eol).encode("utf-8"))


def stamp(version: str, out_dir: Path) -> None:
    target = parse_version(version)
    tags = [(v, name) for v, name in stable_tags() if v < target]
    prev_tag = tags[0][1] if tags else None

    plugin, plugin_eol = read_text(PLUGIN_FILE)
    write_text(PLUGIN_FILE, rewrite_one(HEADER_VERSION, plugin, version, "plugin header Version"), plugin_eol)

    readme, readme_eol = read_text(README_FILE)
    changelog = Changelog(rewrite_one(STABLE_TAG_LINE, readme, version, "readme Stable tag"))
    # History first, oldest last-published release down to the oldest: every
    # released line lives in its tag, not in `main`.
    for v, name in tags:
        changelog.ensure(".".join(map(str, v)), tag_changelog_lines(name))
    changelog.ensure(version, generated_lines(prev_tag))
    if not changelog.bullets(version):
        changelog.ensure(version, [MAINTENANCE_LINE])
    write_text(README_FILE, changelog.text(), readme_eol)

    lines = changelog.bullets(version)
    out_dir.mkdir(parents=True, exist_ok=True)
    (out_dir / "notes.md").write_text("\n".join(lines) + "\n", encoding="utf-8")
    (out_dir / "tag-message.txt").write_text(
        f"Release v{version}\n\n" + "\n".join(lines) + "\n", encoding="utf-8"
    )


def verify(version: str) -> None:
    parse_version(version)
    changed = set(git("diff", "--name-only").split())
    unexpected = changed - {PLUGIN_FILE, README_FILE}
    if unexpected:
        raise ReleaseError(f"stamping touched files it does not own: {sorted(unexpected)}")

    def diff_lines(path: str, sign: str) -> list[str]:
        return [
            line[1:]
            for line in git("diff", "--unified=0", "--", path).splitlines()
            if line.startswith(sign) and not line.startswith(sign * 3)
        ]

    for sign in "+-":
        plugin_lines = diff_lines(PLUGIN_FILE, sign)
        if len(plugin_lines) > 1 or any(not HEADER_VERSION.match(line) for line in plugin_lines):
            raise ReleaseError(f"{PLUGIN_FILE} may change only its Version header line: {plugin_lines}")
    removed = diff_lines(README_FILE, "-")
    if len(removed) > 1 or any(not STABLE_TAG_LINE.match(line) for line in removed):
        raise ReleaseError(f"{README_FILE} may only replace its Stable tag line, never lose lines: {removed}")

    plugin, _ = read_text(PLUGIN_FILE)
    header = HEADER_VERSION.search(plugin)
    if not header or header.group(2) != version:
        raise ReleaseError(f"plugin header carries {header.group(2) if header else None}, expected {version}")
    readme, _ = read_text(README_FILE)
    stable = STABLE_TAG_LINE.search(readme)
    if not stable or stable.group(2) != version:
        raise ReleaseError(f"readme Stable tag carries {stable.group(2) if stable else None}, expected {version}")
    changelog = Changelog(readme)
    if changelog.count_headings(version) != 1:
        raise ReleaseError(f"readme must carry exactly one = {version} = heading — that is what WordPress.org renders (#180)")
    if not changelog.bullets(version):
        raise ReleaseError(f"= {version} = has no changelog line")


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("resolve")
    stamp_parser = sub.add_parser("stamp")
    stamp_parser.add_argument("--version", required=True)
    stamp_parser.add_argument("--out-dir", required=True, type=Path)
    verify_parser = sub.add_parser("verify")
    verify_parser.add_argument("--version", required=True)
    args = parser.parse_args(argv)
    try:
        if args.command == "resolve":
            for key, value in resolve().items():
                print(f"{key}={value}")
        elif args.command == "stamp":
            stamp(args.version, args.out_dir)
        else:
            verify(args.version)
    except (ReleaseError, subprocess.CalledProcessError) as exc:
        print(f"::error::{exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
