#!/usr/bin/env python3
"""Build the bundled CA/AZ/NV airport/runway catalog from FAA NASR CSV data."""

from __future__ import annotations

import argparse
import csv
import io
import json
import urllib.request
import zipfile
from collections import defaultdict
from pathlib import Path

DEFAULT_SOURCE_URL = (
    "https://nfdc.faa.gov/webContent/28DaySub/extra/06_Aug_2026_APT_CSV.zip"
)
DEFAULT_EFFECTIVE_DATE = "2026-08-06"
DEFAULT_STATES = ("CA", "AZ", "NV")


def optional_float(value: str | None) -> float | None:
    value = (value or "").strip()
    try:
        return float(value) if value else None
    except ValueError:
        return None


def optional_int(value: str | None) -> int | None:
    number = optional_float(value)
    return int(round(number)) if number is not None else None


def read_csv(archive: zipfile.ZipFile, name: str) -> list[dict[str, str]]:
    with archive.open(name) as source:
        stream = io.TextIOWrapper(source, encoding="utf-8-sig", newline="")
        return list(csv.DictReader(stream))


def build_catalog(archive_bytes: bytes, states: set[str], source_url: str, effective_date: str) -> dict:
    with zipfile.ZipFile(io.BytesIO(archive_bytes)) as archive:
        base_rows = read_csv(archive, "APT_BASE.csv")
        runway_rows = read_csv(archive, "APT_RWY.csv")
        end_rows = read_csv(archive, "APT_RWY_END.csv")

    runway_ends: dict[tuple[str, str], list[dict]] = defaultdict(list)
    for row in end_rows:
        if row["STATE_CODE"] not in states:
            continue
        latitude = optional_float(row.get("LAT_DECIMAL"))
        longitude = optional_float(row.get("LONG_DECIMAL"))
        if latitude is None or longitude is None:
            continue
        runway_ends[(row["SITE_NO"], row["RWY_ID"])].append(
            {
                "identifier": row["RWY_END_ID"].strip(),
                "latitude_deg": latitude,
                "longitude_deg": longitude,
                "elevation_ft": optional_int(row.get("RWY_END_ELEV")),
                "true_heading_deg": optional_float(row.get("TRUE_ALIGNMENT")),
            }
        )

    runways: dict[str, list[dict]] = defaultdict(list)
    for row in runway_rows:
        if row["STATE_CODE"] not in states:
            continue
        runways[row["SITE_NO"]].append(
            {
                "identifier": row["RWY_ID"].strip(),
                "length_ft": optional_int(row.get("RWY_LEN")),
                "width_ft": optional_int(row.get("RWY_WIDTH")),
                "surface": row.get("SURFACE_TYPE_CODE", "").strip(),
                "ends": sorted(
                    runway_ends.get((row["SITE_NO"], row["RWY_ID"]), []),
                    key=lambda item: item["identifier"],
                ),
            }
        )

    airports: list[dict] = []
    for row in base_rows:
        if (
            row["STATE_CODE"] not in states
            or row["SITE_TYPE_CODE"] != "A"
            or row["ARPT_STATUS"] != "O"
        ):
            continue
        latitude = optional_float(row.get("LAT_DECIMAL"))
        longitude = optional_float(row.get("LONG_DECIMAL"))
        if latitude is None or longitude is None:
            continue
        faa_identifier = row["ARPT_ID"].strip().upper()
        icao_identifier = row.get("ICAO_ID", "").strip().upper()
        identifier = icao_identifier or faa_identifier
        if not identifier:
            continue
        airports.append(
            {
                "identifier": identifier,
                "icao_identifier": icao_identifier or None,
                "faa_identifier": faa_identifier or None,
                "name": row["ARPT_NAME"].strip(),
                "city": row["CITY"].strip(),
                "state": row["STATE_CODE"],
                "latitude_deg": latitude,
                "longitude_deg": longitude,
                "elevation_ft": optional_int(row.get("ELEV")),
                "facility_use": row.get("FACILITY_USE_CODE", "").strip(),
                "is_towered": bool(row.get("TWR_TYPE_CODE", "").strip()),
                "runways": sorted(
                    runways.get(row["SITE_NO"], []),
                    key=lambda item: item["identifier"],
                ),
            }
        )

    airports.sort(key=lambda item: item["identifier"])
    return {
        "schema_version": 1,
        "source": "FAA NASR 28-Day Subscription",
        "source_url": source_url,
        "effective_date": effective_date,
        "states": sorted(states),
        "airport_count": len(airports),
        "airports": airports,
    }


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--source-url", default=DEFAULT_SOURCE_URL)
    parser.add_argument("--effective-date", default=DEFAULT_EFFECTIVE_DATE)
    parser.add_argument("--states", default=",".join(DEFAULT_STATES))
    parser.add_argument("--output", required=True)
    args = parser.parse_args()

    states = {value.strip().upper() for value in args.states.split(",") if value.strip()}
    request = urllib.request.Request(
        args.source_url,
        headers={"User-Agent": "IPCA-Courseware-AirportSeed/1.0"},
    )
    with urllib.request.urlopen(request, timeout=60) as response:
        archive_bytes = response.read()

    catalog = build_catalog(archive_bytes, states, args.source_url, args.effective_date)
    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(
        json.dumps(catalog, ensure_ascii=False, separators=(",", ":")) + "\n",
        encoding="utf-8",
    )
    print(f"Wrote {catalog['airport_count']} airports to {output}")


if __name__ == "__main__":
    main()
