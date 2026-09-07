from pathlib import Path
import yaml

from mcp.server.mcpserver import MCPServer


ROOT = Path("/opt/ustar/git/ustarlms")
CONTEXT = ROOT / "context"


server = MCPServer(
    "ustar-contextd"
)


def read_yaml(path):
    if not path.exists():
        return {}

    with open(path, "r", encoding="utf-8") as f:
        return yaml.safe_load(f) or {}


def read_text(path):
    if not path.exists():
        return ""

    return path.read_text(
        encoding="utf-8"
    )


@server.tool()
def get_project_context():
    return read_yaml(
        CONTEXT / "project.yaml"
    )


@server.tool()
def get_runtime_state():
    result = {}

    for f in (CONTEXT / "runtime").glob("*"):
        result[f.name] = read_text(f)

    return result


@server.tool()
def get_architecture():
    result = {}

    for f in (CONTEXT / "architecture").glob("*"):
        result[f.name] = read_text(f)

    return result


@server.tool()
def get_code_map():
    result = {}

    for f in (CONTEXT / "code_map").rglob("*"):
        if f.is_file():
            result[str(f.relative_to(CONTEXT))] = read_text(f)

    return result


@server.tool()
def get_active_tasks():
    return read_text(
        CONTEXT / "tasks" / "ACTIVE.md"
    )


@server.tool()
def get_decisions():
    result = {}

    for f in (CONTEXT / "decisions").glob("*.md"):
        result[f.name] = read_text(f)

    return result


@server.tool()
def search_context(query: str):
    hits = []

    for f in CONTEXT.rglob("*"):
        if not f.is_file():
            continue

        try:
            text = read_text(f)

            if query.lower() in text.lower():
                hits.append(
                    str(f.relative_to(ROOT))
                )

        except Exception:
            pass

    return hits


if __name__ == "__main__":
    server.run()
