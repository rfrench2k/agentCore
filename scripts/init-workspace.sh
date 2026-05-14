#!/bin/bash
# AgentCore — Initialize a new workspace from templates/workspace/
#
# Usage: ./scripts/init-workspace.sh /path/to/your/workspace
#
# Copies the example workspace skeleton to the target directory, renaming
# *.example.md files to *.md and .env.example to .env. Existing files at the
# destination are NOT overwritten — re-running the script is safe.

set -euo pipefail

if [ $# -ne 1 ]; then
    echo "Usage: $0 /path/to/your/workspace" >&2
    exit 1
fi

DEST="$1"
SRC="$(cd "$(dirname "$0")/.." && pwd)/templates/workspace"

if [ ! -d "$SRC" ]; then
    echo "Template directory not found: $SRC" >&2
    exit 1
fi

mkdir -p "$DEST"
echo "Initializing workspace at: $DEST"
echo "Source: $SRC"
echo

copy_if_missing() {
    local src_file="$1"
    local dst_file="$2"
    if [ -e "$dst_file" ]; then
        echo "  skip (exists): $(basename "$dst_file")"
    else
        mkdir -p "$(dirname "$dst_file")"
        cp "$src_file" "$dst_file"
        echo "  created:       $(basename "$dst_file")"
    fi
}

# Top-level workspace files — rename .example to drop the suffix
for src_file in "$SRC"/*.example.md "$SRC"/.env.example; do
    [ -e "$src_file" ] || continue
    name=$(basename "$src_file")
    dst_name="${name/.example/}"
    copy_if_missing "$src_file" "$DEST/$dst_name"
done

# Subdirectories — copy as-is (skills/, memory/, logs/)
for sub in skills memory logs; do
    if [ -d "$SRC/$sub" ]; then
        mkdir -p "$DEST/$sub"
        while IFS= read -r -d '' f; do
            rel="${f#"$SRC/"}"
            copy_if_missing "$f" "$DEST/$rel"
        done < <(find "$SRC/$sub" -type f -print0)
    fi
done

echo
echo "Done."
echo
echo "Next steps:"
echo "  1. Fill in $DEST/USER.md, SOUL.md, MEMORY.md, TOOLS.md, TELEGRAM_INSTRUCTIONS.md"
echo "  2. Add credentials to $DEST/.env"
echo "  3. In AgentCore's config/config.local.php set paths.project_root = '$DEST'"
echo "  4. Run: php migrations/migrate.php"
