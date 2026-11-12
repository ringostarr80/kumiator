#!/bin/sh
# Meldet phpcs-Funde direkt nach dem Edit, damit sie nicht erst in der Verifikations-Pipeline auffallen.

cd "$CLAUDE_PROJECT_DIR" || exit 0
file=$(jq -r '.tool_input.file_path // empty')

for dir in $(sed -n 's|^ *<file>\(.*\)</file>$|\1|p' phpcs.xml); do
    case "$file" in
        "$CLAUDE_PROJECT_DIR/$dir"/*.php)
            # Diese Fixer entfernen, worauf der nächste Edit oft noch aufbaut, etwa einen Import vor seiner Verwendung.
            skip=SlevomatCodingStandard.Functions.RequireArrowFunction
            skip=$skip,SlevomatCodingStandard.Functions.UnusedInheritedVariablePassedToClosure
            skip=$skip,SlevomatCodingStandard.Namespaces.UnusedUses
            skip=$skip,SlevomatCodingStandard.Variables.UselessVariable
            vendor/bin/phpcbf -q --exclude="$skip" "$file" >/dev/null 2>&1
            vendor/bin/phpcs -q --no-colors --report=emacs "$file" >&2 || exit 2
            ;;
    esac
done
