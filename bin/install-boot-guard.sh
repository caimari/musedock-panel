#!/bin/bash
# Instala el guardián de arranque (anti split-brain) como unidad systemd. Como root.
#   bin/install-boot-guard.sh            instala y habilita
#   bin/install-boot-guard.sh --dry-run  solo enseña la unidad
#   bin/install-boot-guard.sh --remove   lo quita
exec php "$(dirname "$(readlink -f "$0")")/install-boot-guard.php" "$@"
