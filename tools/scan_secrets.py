"""Fallback secret scanner using regex patterns."""
from __future__ import annotations

from dataclasses import dataclass
import re
from pathlib import Path


@dataclass
class Finding:
    path: str
    kind: str
    start: str


def scan_file(path: Path) -> list[Finding]:
    text = Path(path).read_text(encoding="utf-8", errors="ignore")
    findings = []
    
    # AWS Access Key
    for m in re.finditer(r"AKIA[0-9A-Z]{16}", text):
        findings.append(Finding(str(path), "aws_access_key", m.group(0)))
        
    # GitHub Token
    for m in re.finditer(r"ghp_[0-9A-Za-z]{36}", text):
        findings.append(Finding(str(path), "github_token", m.group(0)))

    return findings


def scan_tree(root: Path) -> list[Finding]:
    findings = []
    for p in Path(root).rglob("*"):
        if p.is_file() and not p.name.endswith((".db", ".pyc", ".tmp")):
            findings.extend(scan_file(p))
    return findings
