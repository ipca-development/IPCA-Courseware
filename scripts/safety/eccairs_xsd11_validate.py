#!/usr/bin/env python3
"""Validate ECCAIRS XML against the official XSD 1.1 taxonomy package."""

from __future__ import annotations

import argparse
import json
import pathlib
import sys
import tempfile
import zipfile

try:
    import xmlschema
except ImportError:
    print(
        json.dumps(
            {
                "ok": False,
                "code": "xsd11_validator_dependency_missing",
                "message": "Install the Python xmlschema package for XSD 1.1 validation.",
            }
        ),
        file=sys.stderr,
    )
    raise SystemExit(3)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--archive", required=True)
    parser.add_argument("--xml", required=True)
    arguments = parser.parse_args()
    archive = pathlib.Path(arguments.archive)
    xml_path = pathlib.Path(arguments.xml)
    if not archive.is_file() or not xml_path.is_file():
        raise ValueError("The taxonomy archive and XML document must be readable files.")

    with tempfile.TemporaryDirectory(prefix="ipca_eccairs_xsd11_") as temporary:
        schema_directory = pathlib.Path(temporary)
        with zipfile.ZipFile(archive) as package:
            for member in package.infolist():
                path = pathlib.PurePosixPath(member.filename)
                if (
                    len(path.parts) != 2
                    or path.parts[0] != "schema"
                    or path.suffix.lower() != ".xsd"
                ):
                    continue
                (schema_directory / path.name).write_bytes(package.read(member))
        schema_path = schema_directory / "Schema.xsd"
        if not schema_path.is_file():
            raise ValueError("The taxonomy archive does not contain schema/Schema.xsd.")
        schema = xmlschema.XMLSchema11(str(schema_path))
        errors = list(schema.iter_errors(str(xml_path)))
        if errors:
            first = errors[0]
            print(
                json.dumps(
                    {
                        "ok": False,
                        "code": "xsd11_validation_failed",
                        "message": "The XML document does not conform to the taxonomy schema.",
                        "path": str(first.path or ""),
                        "error_count": len(errors),
                    }
                )
            )
            return 2
    print(json.dumps({"ok": True, "validator": "xmlschema.XMLSchema11"}))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as error:
        print(
            json.dumps(
                {
                    "ok": False,
                    "code": "xsd11_validator_error",
                    "message": str(error)[:500],
                }
            ),
            file=sys.stderr,
        )
        raise SystemExit(1)
