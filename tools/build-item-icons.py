#!/usr/bin/env python3
"""Build a small FileDataID -> icon-name map for the bundled client exports.

Usage: python3 tools/build-item-icons.py /path/to/community-listfile.csv
Listfile source: https://github.com/wowdev/wow-listfile (semicolon-separated).
Only metadata is copied, not game textures. No network access is performed.
"""
import csv
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def build(listfile: Path) -> dict:
    wanted = set()
    for pattern, column in [("Item.*.csv", "IconFileDataID"),
                            ("ItemAppearance.*.csv", "DefaultIconFileDataID")]:
        for path in (ROOT / "db2").glob(pattern):
            with path.open(encoding="utf-8-sig", newline="") as source:
                wanted.update(int(row[column]) for row in csv.DictReader(source)
                              if row.get(column, "0").isdigit() and int(row[column]) > 0)
    icons = {}
    with listfile.open(encoding="utf-8-sig", newline="") as source:
        for row in csv.reader(source, delimiter=";"):
            if len(row) != 2 or not row[0].isdigit() or int(row[0]) not in wanted:
                continue
            path = row[1].lower().replace("\\", "/")
            match = re.fullmatch(r"interface/icons/([a-z0-9_&. -]+)\.blp", path)
            if match:
                icons[int(row[0])] = match[1]
    return dict(sorted(icons.items()))


if __name__ == "__main__":
    if len(sys.argv) != 2:
        raise SystemExit(__doc__)
    icons = build(Path(sys.argv[1]))
    if not icons:
        raise SystemExit("No matching icon names found; the existing map was not changed.")
    output = ROOT / "data" / "item-icon-names.json"
    output.write_text(json.dumps(icons, indent=2) + "\n", encoding="utf-8")
    print(f"Wrote {len(icons)} icon names to {output.relative_to(ROOT)}")
