#!/bin/sh
# Meldet phpcs-Funde direkt nach dem Edit, damit sie nicht erst in der Verifikations-Pipeline auffallen.

file=$(jq -r '.tool_input.file_path // empty')
# Auch Dateien in Worktrees prüfen, mit phpcs.xml und vendor/ des Checkouts, zu dem die Datei gehört.
# Verglichen wird relativ zum Checkout, weil Git Symlinks im Pfad auflöst (/tmp → /private/tmp).
cd "${file%/*}" 2>/dev/null || exit 0
path=$(git rev-parse --show-prefix 2>/dev/null)${file##*/} || exit 0
cd "$(git rev-parse --show-toplevel)" || exit 0

for dir in $(sed -n 's|^ *<file>\(.*\)</file>$|\1|p' phpcs.xml); do
    case "$path" in
        "$dir"/*.php)
            # Diese Fixer entfernen, worauf der nächste Edit oft noch aufbaut, etwa einen Import vor seiner Verwendung.
            skip=SlevomatCodingStandard.Functions.RequireArrowFunction
            skip=$skip,SlevomatCodingStandard.Functions.UnusedInheritedVariablePassedToClosure
            skip=$skip,SlevomatCodingStandard.Namespaces.UnusedUses
            skip=$skip,SlevomatCodingStandard.Variables.UselessVariable
            vendor/bin/phpcbf -q --parallel=1 --exclude="$skip" "$file" >/dev/null 2>&1
            vendor/bin/phpcs -q --parallel=1 --no-colors --report=emacs "$file" >&2
            # Nur Lint-Funde gehen an Claude; Prozessfehler wie ein fehlendes vendor/ kann es in der Datei nicht beheben.
            case $? in
                0) ;;
                1|2|3) exit 2 ;;
                *) exit 1 ;;
            esac
            ;;
    esac
done
