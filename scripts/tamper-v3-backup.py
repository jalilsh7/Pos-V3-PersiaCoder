#!/usr/bin/env python3
"""Create a structurally valid gzip archive with a deliberately stale HMAC."""
import gzip
import json
import sys

if len(sys.argv) != 3:
    raise SystemExit("usage: tamper-v3-backup.py INPUT.jsonl.gz OUTPUT.jsonl.gz")

with gzip.open(sys.argv[1], "rt", encoding="utf-8") as source:
    lines = source.readlines()

if len(lines) < 2:
    raise SystemExit("backup archive is too short")

header = json.loads(lines[0])
if header.get("type") != "header":
    raise SystemExit("backup header is invalid")

header["created_at_utc"] = "2000-01-01T00:00:00+00:00"
lines[0] = json.dumps(header, ensure_ascii=False, separators=(",", ":")) + "\n"

with gzip.open(sys.argv[2], "wt", encoding="utf-8", newline="") as destination:
    destination.writelines(lines)
