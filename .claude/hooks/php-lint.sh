#!/bin/sh
# Meldet phpcs-Funde direkt nach dem Edit, damit sie nicht erst in der Verifikations-Pipeline auffallen.

cd "$CLAUDE_PROJECT_DIR" || exit 0
file=$(jq -r '.tool_input.file_path // empty')

for dir in $(sed -n 's|^ *<file>\(.*\)</file>$|\1|p' phpcs.xml); do
    case "$file" in
        "$CLAUDE_PROJECT_DIR/$dir"/*.php)
            # Import und Verwendung entstehen oft in getrennten Edits; phpcbf würde den Import dazwischen löschen.
            vendor/bin/phpcbf -q --exclude=SlevomatCodingStandard.Namespaces.UnusedUses "$file" >/dev/null 2>&1
            vendor/bin/phpcs -q --no-colors --report=emacs "$file" >&2 || exit 2
            ;;
    esac
done
