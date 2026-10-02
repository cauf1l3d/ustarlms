#!/usr/bin/env python3
"""Read-only inventory of direct USTAR person references in the XMLDB schema.

This is a coverage aid for the Moodle Privacy API implementation, not a
replacement for its export/deletion provider or a retention policy.
"""
from pathlib import Path
import argparse
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[2]
SCHEMA = ROOT / "moodle/local/ustar/db/install.xml"
OUTPUT = ROOT / "context/roadmap/USTAR_PRIVACY_INVENTORY_20260928_RU.md"

USER_KEYS = {
    "actorid", "archivedby", "authorid", "assigneeid", "assignerid", "approvedby",
    "createdby", "decidedby", "decisionby", "employeeid", "managerid", "ownerid",
    "recordedby", "reportedby", "reporterid", "requestedby", "resolvedby",
    "reviewedby", "reviewerid", "submittedby", "usermodified",
}


def user_reference(field: str) -> bool:
    return field in USER_KEYS or field.endswith("userid")


def render() -> str:
    tables = ET.parse(SCHEMA).getroot().find("TABLES")
    rows = []
    for table in tables:
        fields = [field.attrib["NAME"] for field in table.find("FIELDS")]
        direct = [field for field in fields if user_reference(field)]
        if direct:
            rows.append(f"| `{table.attrib['NAME']}` | " +
                ", ".join(f"`{field}`" for field in direct) + " |")
    return "\n".join([
        "# USTAR: реестр прямых ссылок на людей (черновик privacy)",
        "",
        "Источник — `moodle/local/ustar/db/install.xml`. Это механически проверяемый",
        "перечень явных колонок с пользователями; он **не является** полной картой",
        "персональных данных и не запускает удаление. Переименования и новые таблицы",
        "должны обновлять этот перечень через `scripts/ci/privacy_inventory.py`.",
        "",
        "| Таблица | Прямые поля Moodle user ID |",
        "|---|---|",
        *rows,
        "",
        "## Связанные записи и файлы, которые нельзя потерять при реализации",
        "",
        "- Косвенные связи: `check_answers → check_runs`, `test_answers → test_attempts`,",
        "  `learning_task_events → learning_tasks`, `notify_delivery → notifications`,",
        "  `feed_audience/comments/reactions/events/reports → feed_posts`,",
        "  `comp_score_events/results → comp_participants`. Для экспорта нужны обе стороны связи.",
        "- Moodle File API: `feed_attachment`, `task_attachment`, `task_result`,",
        "  материалы, изображения/версии контента, SCORM и доказательства;",
        "  ownership файла и право на удаление выводятся из исходного объекта.",
        "- JSON и свободный текст в структуре, заданиях, уведомлениях, оценках и",
        "  публикациях могут содержать имена и другие персональные сведения без явного user ID.",
        "- Неизменяемые учебные/кадровые факты, события аудита и сведения о других",
        "  авторах требуют отдельной политики хранения и редактирования перед удалением.",
        "",
        "## Незакрытые условия",
        "",
        "1. Сроки Ленты приняты владельцем 28.09.2026; автоматическое удаление выключено.",
        "   Основания/исключения и сроки остальных доменов остаются открытыми;",
        "2. Реализовать `classes/privacy/provider.php`: metadata, полный export,",
        "   context/userlist discovery и deletion для всех доменов/файлов;",
        "3. Покрыть точным Moodle DB тестом экспорт/удаление пользователя и чужих данных;",
        "4. Отдельно проверить, что системные роли и закрытый блокнот не открывают данные HR.",
        "",
    ])


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    content = render()
    if args.check:
        if not OUTPUT.exists() or OUTPUT.read_text() != content:
            raise SystemExit("privacy inventory is stale")
        print("PRIVACY_INVENTORY_OK")
    else:
        OUTPUT.write_text(content)
        print(f"updated {OUTPUT.relative_to(ROOT)}")
