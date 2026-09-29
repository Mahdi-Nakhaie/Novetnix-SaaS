#!/usr/bin/env bash
# Regenerate the static site that GitHub Pages serves.
set -euo pipefail
cd "$(dirname "$0")/.."
python3 tools/check_catalog.py
python3 tools/gen.py
python3 tools/verify.py
