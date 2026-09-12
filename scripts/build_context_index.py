#!/usr/bin/env python3

import argparse
from pathlib import Path
import hashlib
import json
from datetime import datetime, timezone


ROOT = Path(__file__).resolve().parents[1]
CONTEXT = ROOT / "context"
INDEX_DIR = CONTEXT / "index"
INDEX_FILE = INDEX_DIR / "context_index.json"


IGNORE = {
    ".git",
    ".venv",
    "__pycache__"
}


def sha256(path):
    h = hashlib.sha256()

    with open(path, "rb") as f:
        while chunk := f.read(1024 * 1024):
            h.update(chunk)

    return h.hexdigest()


def classify(path):
    rel = path.relative_to(CONTEXT)

    parts = rel.parts

    if len(parts) > 1:
        return parts[0]

    return "root"


def main():
    global ROOT, CONTEXT, INDEX_DIR, INDEX_FILE
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", type=Path, default=ROOT)
    args = parser.parse_args()
    ROOT = args.root.resolve()
    CONTEXT = ROOT / "context"
    INDEX_DIR = CONTEXT / "index"
    INDEX_FILE = INDEX_DIR / "context_index.json"
    if any(p.is_symlink() for p in [CONTEXT, INDEX_DIR, INDEX_FILE]):
        raise ValueError("symlink_output_forbidden")

    INDEX_DIR.mkdir(
        parents=True,
        exist_ok=True
    )

    files = []

    for path in CONTEXT.rglob("*"):

        if path == INDEX_FILE or any(p.is_symlink() for p in [path, *path.parents]) or not path.is_file():
            continue

        if path.suffix.lower() not in {".md", ".txt", ".json", ".yaml", ".yml"} or path.stat().st_size > 512 * 1024:
            continue

        if any(
            x in IGNORE or x.startswith(".")
            for x in path.relative_to(CONTEXT).parts
        ):
            continue

        stat = path.stat()

        files.append(
            {
                "path":
                    str(
                        path.relative_to(ROOT)
                    ),

                "domain":
                    classify(path),

                "size":
                    stat.st_size,

                "modified":
                    datetime.fromtimestamp(
                        stat.st_mtime,
                        timezone.utc
                    ).isoformat(),

                "sha256":
                    sha256(path)
            }
        )


    result = {

        "generated":
            datetime.now(
                timezone.utc
            ).isoformat(),

        "root":
            str(CONTEXT),

        "files":
            sorted(
                files,
                key=lambda x: x["path"]
            )
    }


    INDEX_FILE.write_text(
        json.dumps(
            result,
            indent=2,
            ensure_ascii=False
        ),
        encoding="utf-8"
    )


    print(
        f"Context index generated: {len(files)} files"
    )


if __name__ == "__main__":
    main()
