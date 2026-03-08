#!/usr/bin/env python3
"""CLI entry point for stamping signatures onto PDFs without the HTTP service."""

import json
import sys
from pathlib import Path

# Add the project root so imports resolve
sys.path.insert(0, str(Path(__file__).parent))

from api.services.documents.signature_stamper import SignatureStamperService


def main() -> None:
    if len(sys.argv) < 3:
        print(
            "Usage: stamp_signatures_cli.py <pdf_path> <output_path> "
            "[placements_json] [signature_data] [signature_type] [user_name]",
            file=sys.stderr,
        )
        sys.exit(1)

    pdf_path = sys.argv[1]
    output_path = sys.argv[2]
    placements_json = sys.argv[3] if len(sys.argv) > 3 else "[]"
    signature_data = sys.argv[4] if len(sys.argv) > 4 else None
    signature_type = sys.argv[5] if len(sys.argv) > 5 else None
    user_name = sys.argv[6] if len(sys.argv) > 6 else None

    try:
        placements = json.loads(placements_json)
    except json.JSONDecodeError as e:
        print(f"Invalid placements JSON: {e}", file=sys.stderr)
        sys.exit(1)

    pdf_bytes = Path(pdf_path).read_bytes()

    stamper = SignatureStamperService()
    result = stamper.apply_signatures(
        pdf_bytes,
        placements,
        signature_data=signature_data if signature_data else None,
        signature_type=signature_type if signature_type else None,
        user_name=user_name if user_name else None,
    )

    Path(output_path).write_bytes(result)


if __name__ == "__main__":
    main()
