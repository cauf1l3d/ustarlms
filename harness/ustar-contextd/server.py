"""USTAR context MCP. Local stdio only; no listener or startup service."""
from pathlib import Path
import yaml
from mcp.server.fastmcp import FastMCP
from safe_context import ContextReader, ContextDenied

ROOT = Path(__file__).resolve().parents[2]
reader = ContextReader(ROOT)
server = FastMCP('ustar-contextd')


def read_text(path):
    return reader.read(path)


@server.tool()
def get_context_file(path: str):
    """Read a UTF-8 allowlisted file using a repository-relative context/... path."""
    try:
        return {'path': path, 'content': read_text(path)}
    except ContextDenied as exc:
        return {'error': str(exc)}


@server.tool()
def get_project_context():
    return yaml.safe_load(read_text('context/project.yaml')) or {}


@server.tool()
def get_runtime_state():
    return {Path(p).name: text for p, text in reader.collect('runtime').items()}


@server.tool()
def get_architecture():
    return {Path(p).name: text for p, text in reader.collect('architecture').items()}


@server.tool()
def get_code_map():
    return {p.removeprefix('context/'): text for p, text in reader.collect('code_map').items()}


@server.tool()
def get_context_index():
    return read_text('context/index/context_index.json')


@server.tool()
def get_active_tasks():
    return read_text('context/tasks/ACTIVE.md')


@server.tool()
def get_decisions():
    return {Path(p).name: text for p, text in reader.collect('decisions').items()}


@server.tool()
def search_context(query: str):
    if not query or len(query) > 256:
        return []
    return [p for p, text in reader.collect().items() if query.casefold() in text.casefold()][:100]


@server.tool()
def get_health():
    return {'service': 'ustar-contextd', 'transport': 'stdio', 'scope': 'context/',
            'context_present': (ROOT / 'context').is_dir() and not (ROOT / 'context').is_symlink()}


@server.tool()
def get_recent_context_changes():
    return read_text('context/memory/decisions.log.md')


if __name__ == '__main__':
    server.run(transport='stdio')
