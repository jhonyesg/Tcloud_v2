#!/usr/bin/env bash
#
# Re-fija la propiedad de los logs de Laravel al usuario del pool FPM (www),
# para que ninguna request (p. ej. los tabs del módulo API Transcriptor o
# Mis Archivos) falle con HTTP 500 por no poder abrir el log en modo append.
#
# Por qué existe: el scheduler de Laravel corre como root (cron de root) porque
# `transcription:tune --apply` necesita root para systemctl. Los comandos que
# arrancan ahí escriben logs con propietario root; si luego el pool FPM (www)
# intenta escribir en `laravel.log` en una request, el fopen falla con
# "Permission denied" y Laravel devuelve HTTP 500 {"message":"Server Error"}.
#
# Este cron (ejecutado como root, porque solo root puede cambiar el dueño)
# restaura `www` sobre los logs activos. Corre cada 5 min; si una request se
# adelanta, el peor caso es un 500 muy puntual (la ventana de 5 min).
#
# Solo toca archivos `.log` y `.log.marker` dentro de storage/logs que tengan
# propietario distinto de www — no toca el directorio ni archivos no-log.
#
set -euo pipefail

LOG_DIR="/www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app/storage/logs"
OWNER="www:www"

if [[ ! -d "$LOG_DIR" ]]; then
    echo "fix_log_ownership: $LOG_DIR no existe" >&2
    exit 0
fi

changed=0
while IFS= read -r -d '' f; do
    # Solo re-fija si el propietario no es ya www (evita tocar inodos innecesariamente).
    if [[ "$(stat -c %U:%G "$f" 2>/dev/null)" != "$OWNER" ]]; then
        chown "$OWNER" "$f" 2>/dev/null && changed=$((changed + 1)) || true
    fi
done < <(find "$LOG_DIR" -maxdepth 1 \( -name '*.log' -o -name '*.log.marker' \) -print0)

# No logueamos ruido por corrida: solo si hubo cambios recientes.
if (( changed > 0 )); then
    echo "fix_log_ownership: corregidos $changed archivo(s) a $OWNER"
fi
