from __future__ import annotations

import re
from datetime import date
from pathlib import Path

from gerar_importacao_ponto_pdf import (
    DATE_RE,
    daily_blocks,
    page_employee,
    parse_records,
    split_pages,
)


ROOT = Path(__file__).resolve().parents[1]
TEXT_PATH = ROOT / "downloads" / "pdf_ponto_junho_extraido.txt"
OUTPUT_SQL = ROOT / "downloads" / "importar_saldo_banco_junho_2026.sql"
OUTPUT_CSV = ROOT / "downloads" / "resumo_saldo_banco_junho_2026.csv"
AJUSTE_DATA = "2026-06-01"
PERIODO_INICIO = "2026-06-01"
PERIODO_FIM = "2026-06-13"


def sql_quote(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "''") + "'"


def seconds_from_hhmm(value: str) -> int:
    sign = -1 if value.startswith("-") else 1
    value = value.lstrip("+-")
    hours, minutes = value.split(":", 1)
    return sign * (int(hours) * 3600 + int(minutes) * 60)


def format_hhmm(seconds: int) -> str:
    sign = "-" if seconds < 0 else ""
    seconds = abs(seconds)
    hours = seconds // 3600
    minutes = (seconds % 3600) // 60
    return f"{sign}{hours:02d}:{minutes:02d}"


def is_intern(cargo: str) -> bool:
    return "estagi" in cargo.lower()


def expected_seconds(day: str, cargo: str) -> int:
    if day == "2026-06-04":
        return 0

    weekday = date.fromisoformat(day).isoweekday()
    if is_intern(cargo):
        return 18000 if 1 <= weekday <= 5 else 0

    if 1 <= weekday <= 4:
        return 31500
    if weekday == 5:
        return 27900
    return 0


def seconds_between(start: str | None, end: str | None) -> int:
    if not start or not end:
        return 0
    start_h, start_m = map(int, start[11:16].split(":"))
    end_h, end_m = map(int, end[11:16].split(":"))
    start_seconds = start_h * 3600 + start_m * 60
    end_seconds = end_h * 3600 + end_m * 60
    return max(0, end_seconds - start_seconds)


def worked_seconds(day_records: dict[str, str], cargo: str) -> int:
    if is_intern(cargo):
        if "saida_lanche" not in day_records and "volta_lanche" not in day_records:
            return seconds_between(day_records.get("chegada"), day_records.get("saida_escritorio"))

        return seconds_between(day_records.get("chegada"), day_records.get("saida_lanche")) + seconds_between(
            day_records.get("volta_lanche"), day_records.get("saida_escritorio")
        )

    return (
        seconds_between(day_records.get("chegada"), day_records.get("saida_almoco"))
        + seconds_between(day_records.get("volta_escritorio"), day_records.get("saida_lanche"))
        + seconds_between(day_records.get("volta_lanche"), day_records.get("saida_escritorio"))
    )


def parse_pdf_final_balances(text: str) -> dict[str, dict[str, str | int]]:
    balances: dict[str, dict[str, str | int]] = {}
    time_re = re.compile(r"^-?\d{1,4}:\d{2}$")

    for page in split_pages(text):
        lines = page.splitlines()
        employee = page_employee(lines)
        cpf = employee["cpf"]
        if not cpf:
            continue

        blocks = daily_blocks(lines)
        if not blocks:
            continue

        dated_blocks = []
        for block in blocks:
            match = DATE_RE.match(block[0])
            if not match:
                continue
            day, month, year = match.groups()
            dated_blocks.append((f"{year}-{month}-{day}", block))

        if not dated_blocks:
            continue

        _, last_block = max(dated_blocks, key=lambda item: item[0])
        time_values = [line.strip() for line in last_block if time_re.match(line.strip())]
        if not time_values:
            continue

        final_balance = seconds_from_hhmm(time_values[-1])
        balances[cpf] = {
            "cpf": cpf,
            "nome": employee["nome"],
            "cargo": employee["cargo"],
            "saldo_pdf": final_balance,
        }

    return balances


def calculate_site_period_balances(records: list[dict[str, str]], balances: dict[str, dict[str, str | int]]) -> dict[str, int]:
    grouped: dict[str, dict[str, dict[str, str]]] = {}
    for record in records:
        grouped.setdefault(record["cpf"], {}).setdefault(record["date"], {})[record["tipo"]] = record["marked_at"]

    result: dict[str, int] = {}
    for cpf, days in grouped.items():
        cargo = str(balances.get(cpf, {}).get("cargo", ""))
        total = 0
        for day, day_records in days.items():
            if day < PERIODO_INICIO or day > PERIODO_FIM:
                continue
            total += worked_seconds(day_records, cargo) - expected_seconds(day, cargo)
        result[cpf] = total

    return result


def render_sql(rows: list[dict[str, str | int]]) -> str:
    lines = [
        "-- Importacao de ajustes de saldo inicial do banco de horas.",
        "-- Origem: espelhos de ponto Account/Bookkeep de 2026-06-01 a 2026-06-13.",
        "-- Data do ajuste: 2026-06-01.",
        "",
        "CREATE TABLE IF NOT EXISTS saldos_iniciais_banco_horas (",
        "    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,",
        "    funcionario_id INT UNSIGNED NOT NULL,",
        "    data_referencia DATE NOT NULL,",
        "    saldo_segundos INT NOT NULL,",
        "    observacoes VARCHAR(255) NULL,",
        "    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,",
        "    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,",
        "    PRIMARY KEY (id),",
        "    UNIQUE KEY uq_saldos_iniciais_funcionario_data (funcionario_id, data_referencia),",
        "    KEY idx_saldos_iniciais_data (data_referencia),",
        "    CONSTRAINT fk_saldos_iniciais_funcionario",
        "        FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id)",
        "        ON DELETE CASCADE",
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
        "",
        "START TRANSACTION;",
        "",
    ]

    for row in rows:
        lines.append(
            "-- "
            + str(row["cpf"])
            + " | "
            + str(row["nome"])
            + " | ajuste="
            + str(row["ajuste_horas"])
            + " | saldo_pdf="
            + str(row["saldo_pdf_horas"])
        )
        lines.extend(
            [
                "INSERT INTO saldos_iniciais_banco_horas (funcionario_id, data_referencia, saldo_segundos, observacoes)",
                "SELECT id, "
                + sql_quote(AJUSTE_DATA)
                + ", "
                + str(row["ajuste_segundos"])
                + ", "
                + sql_quote("Ajuste saldo banco conforme espelho PDF 01/06/2026 a 13/06/2026"),
                "FROM funcionarios",
                "WHERE REPLACE(REPLACE(cpf, '.', ''), '-', '') = " + sql_quote(str(row["cpf"])) + " LIMIT 1",
                "ON DUPLICATE KEY UPDATE",
                "    saldo_segundos = VALUES(saldo_segundos),",
                "    observacoes = VALUES(observacoes);",
                "",
            ]
        )

    lines.extend(["COMMIT;", ""])
    return "\n".join(lines)


def render_csv(rows: list[dict[str, str | int]]) -> str:
    header = "cpf;nome;cargo;saldo_pdf;saldo_site_sem_ajuste;ajuste_importado"
    body = [
        ";".join(
            [
                str(row["cpf"]),
                str(row["nome"]),
                str(row["cargo"]),
                str(row["saldo_pdf_horas"]),
                str(row["saldo_site_horas"]),
                str(row["ajuste_horas"]),
            ]
        )
        for row in rows
    ]
    return "\n".join([header, *body]) + "\n"


def main() -> None:
    text = TEXT_PATH.read_text(encoding="utf-8")
    records, _ = parse_records(text)
    balances = parse_pdf_final_balances(text)
    site_balances = calculate_site_period_balances(records, balances)

    rows: list[dict[str, str | int]] = []
    for cpf, balance in balances.items():
        pdf_seconds = int(balance["saldo_pdf"])
        site_seconds = site_balances.get(cpf, 0)
        adjustment = pdf_seconds - site_seconds
        rows.append(
            {
                "cpf": cpf,
                "nome": str(balance["nome"]),
                "cargo": str(balance["cargo"]),
                "saldo_pdf_segundos": pdf_seconds,
                "saldo_site_segundos": site_seconds,
                "ajuste_segundos": adjustment,
                "saldo_pdf_horas": format_hhmm(pdf_seconds),
                "saldo_site_horas": format_hhmm(site_seconds),
                "ajuste_horas": format_hhmm(adjustment),
            }
        )

    OUTPUT_SQL.write_text(render_sql(rows), encoding="utf-8")
    OUTPUT_CSV.write_text(render_csv(rows), encoding="utf-8")
    print(f"employees={len(rows)}")
    print(OUTPUT_SQL)
    print(OUTPUT_CSV)


if __name__ == "__main__":
    main()
