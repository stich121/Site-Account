from __future__ import annotations

import re
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
EXTRACTED_TEXT = ROOT / "downloads" / "pdf_ponto_extraido.txt"
OUTPUT_SQL = ROOT / "downloads" / "importar_ponto_maio_2026.sql"
OUTPUT_CSV = ROOT / "downloads" / "resumo_importacao_ponto_maio_2026.csv"
PERIODO_LABEL = "2026-05-01 a 2026-05-31"
IMPORT_LABEL = "maio/2026"

TIPOS_REGULAR = [
    "chegada",
    "saida_almoco",
    "volta_escritorio",
    "saida_lanche",
    "volta_lanche",
    "saida_escritorio",
]

TIPOS_ESTAGIARIO = [
    "chegada",
    "saida_lanche",
    "volta_lanche",
    "saida_escritorio",
]

DATE_RE = re.compile(r"^(\d{2})/(\d{2})/(\d{4}) - ")
TIME_RE = re.compile(r"\b\d{2}:\d{2}\b")


def sql_quote(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "''") + "'"


def only_digits(value: str) -> str:
    return re.sub(r"\D+", "", value)


def split_pages(text: str) -> list[str]:
    return [page.strip() for page in re.split(r"^--- page \d+ ---$", text, flags=re.M) if page.strip()]


def value_after_label(lines: list[str], label: str) -> str:
    for index, line in enumerate(lines):
        if line.strip().rstrip(":") == label:
            for candidate in lines[index + 1 :]:
                candidate = candidate.strip()
                if candidate:
                    return candidate
    return ""


def page_employee(lines: list[str]) -> dict[str, str]:
    cpf = only_digits(value_after_label(lines, "CPF"))
    pis = only_digits(value_after_label(lines, "PIS/PASEP"))
    cargo = value_after_label(lines, "Função")
    empresa = value_after_label(lines, "Empresa")
    nome = ""

    for index, line in enumerate(lines):
        if " - " in line and "Período:" in (lines[index + 1] if index + 1 < len(lines) else ""):
            nome = line.split(" - ", 1)[0].strip()
            break

    if not nome:
        for line in lines[:6]:
            if line.strip() and line.strip() not in {"Colaborador"}:
                nome = line.strip()
                break

    return {
        "cpf": cpf,
        "pis": pis,
        "cargo": cargo,
        "empresa": empresa,
        "nome": nome,
    }


def is_work_time_line(line: str) -> bool:
    clean = line.strip()
    if not clean:
        return False
    if "Folga" in clean or "Feriado" in clean:
        return False
    if re.match(r"^-\d{2}:\d{2}$", clean):
        return False
    return bool(re.match(r"^-?\s*\d{2}:\d{2}\s*$", clean))


def punch_pairs_for_block(block: list[str]) -> list[tuple[str, str]]:
    pairs: list[tuple[str, str]] = []
    pending_entry = ""

    for line in block[1:]:
        if not is_work_time_line(line):
            continue

        clean = line.strip()
        time_match = TIME_RE.search(clean)
        if not time_match:
            continue

        time_value = time_match.group(0)
        if clean.startswith("- "):
            if pending_entry:
                pairs.append((pending_entry, time_value))
                pending_entry = ""
            continue

        if pending_entry:
            # A second plain time means the row has moved into totals, not punches.
            break

        pending_entry = time_value

    return pairs


def records_from_pairs(date_iso: str, pairs: list[tuple[str, str]], is_intern: bool) -> list[tuple[str, str]]:
    if is_intern:
        if len(pairs) >= 2:
            mapping = [
                ("chegada", pairs[0][0]),
                ("saida_lanche", pairs[0][1]),
                ("volta_lanche", pairs[1][0]),
                ("saida_escritorio", pairs[1][1]),
            ]
        elif len(pairs) == 1:
            mapping = [
                ("chegada", pairs[0][0]),
                ("saida_escritorio", pairs[0][1]),
            ]
        else:
            mapping = []
    else:
        mapping = []
        if len(pairs) >= 1:
            mapping.extend([("chegada", pairs[0][0]), ("saida_almoco", pairs[0][1])])
        if len(pairs) >= 2:
            mapping.extend([("volta_escritorio", pairs[1][0]), ("saida_lanche", pairs[1][1])])
        if len(pairs) >= 3:
            mapping.extend([("volta_lanche", pairs[2][0]), ("saida_escritorio", pairs[2][1])])

    return [(tipo, f"{date_iso} {time_value}:00") for tipo, time_value in mapping]


def daily_blocks(lines: list[str]) -> list[list[str]]:
    blocks: list[list[str]] = []
    current: list[str] = []
    in_table = False

    for line in lines:
        if DATE_RE.match(line.strip()):
            if current:
                blocks.append(current)
            current = [line.strip()]
            in_table = True
            continue

        if in_table:
            if line.strip() in {"Resumo", "Banco Crédito:", "Banco Credito:", "Banco Saldo:", "Saldo do Banco:"}:
                if current:
                    blocks.append(current)
                    current = []
                in_table = False
                continue
            if current:
                current.append(line)

    if current:
        blocks.append(current)

    return blocks


def parse_records(text: str) -> tuple[list[dict[str, str]], list[dict[str, str]]]:
    records: list[dict[str, str]] = []
    summaries: list[dict[str, str]] = []

    for page in split_pages(text):
        lines = page.splitlines()
        employee = page_employee(lines)
        if not employee["cpf"]:
            continue

        is_intern = "estagi" in employee["cargo"].lower() or "Jornada de Est" in page
        imported_days = 0
        imported_punches = 0

        for block in daily_blocks(lines):
            match = DATE_RE.match(block[0])
            if not match:
                continue

            day, month, year = match.groups()
            date_iso = f"{year}-{month}-{day}"
            day_records = records_from_pairs(date_iso, punch_pairs_for_block(block), is_intern)
            if not day_records:
                continue

            imported_days += 1
            for tipo, marked_at in day_records:
                records.append(
                    {
                        "cpf": employee["cpf"],
                        "date": date_iso,
                        "tipo": tipo,
                        "marked_at": marked_at,
                        "nome": employee["nome"],
                    }
                )
                imported_punches += 1

        summaries.append(
            {
                "cpf": employee["cpf"],
                "nome": employee["nome"],
                "cargo": employee["cargo"],
                "dias": str(imported_days),
                "batidas": str(imported_punches),
            }
        )

    deduped_records: dict[tuple[str, str, str], dict[str, str]] = {}
    for record in records:
        key = (record["cpf"], record["date"], record["tipo"])
        deduped_records[key] = record

    deduped_summaries: dict[str, dict[str, str]] = {}
    for record in deduped_records.values():
        cpf = record["cpf"]
        if cpf not in deduped_summaries:
            original = next(summary for summary in summaries if summary["cpf"] == cpf)
            deduped_summaries[cpf] = {
                "cpf": cpf,
                "nome": original["nome"],
                "cargo": original["cargo"],
                "dias": "0",
                "batidas": "0",
                "_dias_set": set(),
            }
        deduped_summaries[cpf]["_dias_set"].add(record["date"])  # type: ignore[index]
        deduped_summaries[cpf]["batidas"] = str(int(deduped_summaries[cpf]["batidas"]) + 1)

    final_summaries: list[dict[str, str]] = []
    for summary in deduped_summaries.values():
        dias_set = summary.pop("_dias_set")  # type: ignore[arg-type]
        summary["dias"] = str(len(dias_set))
        final_summaries.append(summary)  # type: ignore[arg-type]

    return list(deduped_records.values()), final_summaries


def render_sql(records: list[dict[str, str]], summaries: list[dict[str, str]]) -> str:
    lines = [
        "-- Importacao de ponto extraida dos PDFs de apuracao mensal.",
        f"-- Periodo: {PERIODO_LABEL}.",
        "-- Importar no phpMyAdmin com o banco do site selecionado.",
        "-- O comando atualiza a batida se ja existir para o mesmo funcionario, dia e tipo.",
        "",
        "START TRANSACTION;",
        "",
    ]

    for summary in summaries:
        lines.append(
            "-- "
            + summary["cpf"]
            + " | "
            + summary["nome"]
            + " | dias="
            + summary["dias"]
            + " | batidas="
            + summary["batidas"]
        )

    lines.append("")

    for record in records:
        user_agent = f"Importacao PDF apuracao mensal {IMPORT_LABEL}"
        lines.extend(
            [
                "INSERT INTO registros_ponto (funcionario_id, tipo, data_referencia, marcado_em, timezone, ip, user_agent)",
                "SELECT id, "
                + sql_quote(record["tipo"])
                + ", "
                + sql_quote(record["date"])
                + ", "
                + sql_quote(record["marked_at"])
                + ", 'America/Sao_Paulo', NULL, "
                + sql_quote(user_agent),
                "FROM funcionarios",
                "WHERE REPLACE(REPLACE(cpf, '.', ''), '-', '') = "
                + sql_quote(record["cpf"])
                + " LIMIT 1",
                "ON DUPLICATE KEY UPDATE",
                "    marcado_em = VALUES(marcado_em),",
                "    timezone = VALUES(timezone),",
                "    ip = VALUES(ip),",
                "    user_agent = VALUES(user_agent);",
                "",
            ]
        )

    lines.extend(["COMMIT;", ""])
    return "\n".join(lines)


def render_csv(summaries: list[dict[str, str]]) -> str:
    rows = ["cpf;nome;cargo;dias_importados;batidas_importadas"]
    for summary in summaries:
        rows.append(
            ";".join(
                [
                    summary["cpf"],
                    summary["nome"],
                    summary["cargo"],
                    summary["dias"],
                    summary["batidas"],
                ]
            )
        )
    return "\n".join(rows) + "\n"


def main() -> None:
    global EXTRACTED_TEXT, OUTPUT_SQL, OUTPUT_CSV, PERIODO_LABEL, IMPORT_LABEL

    if len(sys.argv) > 1:
        EXTRACTED_TEXT = Path(sys.argv[1])
    if len(sys.argv) > 2:
        OUTPUT_SQL = Path(sys.argv[2])
    if len(sys.argv) > 3:
        OUTPUT_CSV = Path(sys.argv[3])
    if len(sys.argv) > 4:
        PERIODO_LABEL = sys.argv[4]
    if len(sys.argv) > 5:
        IMPORT_LABEL = sys.argv[5]

    text = EXTRACTED_TEXT.read_text(encoding="utf-8")
    records, summaries = parse_records(text)
    OUTPUT_SQL.write_text(render_sql(records, summaries), encoding="utf-8")
    OUTPUT_CSV.write_text(render_csv(summaries), encoding="utf-8")
    print(f"records={len(records)}")
    print(f"employees={len(summaries)}")
    print(OUTPUT_SQL)
    print(OUTPUT_CSV)


if __name__ == "__main__":
    main()
