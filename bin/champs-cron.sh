#!/usr/bin/env bash
# Executa um job do cron com trava, log e registro no painel do champs-cron-monitor.
#
# Uso (crontab; as linhas prontas saem do `bin/console champs:cron:crontab`):
#   bash <projeto>/vendor/betocampoy/champs-cron-monitor/bin/champs-cron.sh <nome> <comando do console> [opções...]
#   bash <projeto>/vendor/betocampoy/champs-cron-monitor/bin/champs-cron.sh <nome> php <script.php> [args...]
#
# - Um lock (flock) por <nome>: se a execução anterior do mesmo job ainda está rodando, esta sai sem
#   fazer nada e fica registrada como "pulada" no painel (é o sinal de sobreposição).
# - Log em var/log/cron/<nome>.log (início, saída, fim com o código de saída); passou de 5 MB, vira
#   <nome>.log.1 (guarda só um anterior).
# - Comando do console: exporta CHAMPS_CRON_NAME=<nome> e o ConsoleExecutionListener do bundle grava
#   início/fim/duração/memória no banco.
# - Script PHP solto (legado): roda como está e, no fim, o wrapper grava a execução pelo
#   champs:cron:record (início, fim, código de saída e o fim da saída quando falha).
#
# Variáveis de ambiente (todas opcionais):
#   CHAMPS_CRON_PHP          binário do PHP (padrão: php do PATH, que no cron nem sempre é o certo)
#   CHAMPS_CRON_PROJECT_DIR  pasta do projeto (padrão: sobe a partir deste script até achar bin/console)
#   CHAMPS_CRON_LOG_DIR      pasta dos logs (padrão: <projeto>/var/log/cron; precisa bater com
#                            champs_cron_monitor.log_dir)

set -u

if [ $# -lt 2 ]; then
    echo "uso: $0 <nome> <comando do console> [opções...] | $0 <nome> php <script.php> [args...]" >&2
    exit 2
fi

NOME="$1"
shift

if ! [[ "$NOME" =~ ^[a-z0-9][a-z0-9-]{0,59}$ ]]; then
    echo "nome inválido: $NOME (minúsculas, números e hífen, até 60)" >&2
    exit 2
fi

DIR="${CHAMPS_CRON_PROJECT_DIR:-}"
if [ -z "$DIR" ]; then
    # pwd lógico (sem -P): com o pacote instalado por symlink (repositório path), sobe pelo vendor do
    # projeto, não pela pasta real do pacote.
    DIR="$(cd "$(dirname "$0")" && pwd)"
    while [ "$DIR" != "/" ] && [ ! -f "$DIR/bin/console" ]; do
        DIR="$(dirname "$DIR")"
    done
    if [ ! -f "$DIR/bin/console" ]; then
        echo "não achei o bin/console subindo a partir de $0; defina CHAMPS_CRON_PROJECT_DIR" >&2
        exit 2
    fi
fi

PHP="${CHAMPS_CRON_PHP:-php}"
LOG_DIR="${CHAMPS_CRON_LOG_DIR:-$DIR/var/log/cron}"
LOCK_DIR="$DIR/var/lock"

mkdir -p "$LOG_DIR" "$LOCK_DIR"
LOG="$LOG_DIR/$NOME.log"

if [ -f "$LOG" ] && [ "$(stat -c %s "$LOG")" -gt 5242880 ]; then
    mv -f "$LOG" "$LOG.1"
fi

# Grava no painel uma execução que não passou pelo listener do console (pulada ou script solto).
# Nunca derruba nada: falha aqui só vai pro log.
registrar() {
    "$PHP" "$DIR/bin/console" champs:cron:record "$NOME" "$@" --no-interaction >> "$LOG" 2>&1 \
        || echo "$(date '+%F %T') [$NOME] aviso: não foi possível gravar a execução no painel" >> "$LOG"
}

exec 9>"$LOCK_DIR/cron-$NOME.lock"
if ! flock -n 9; then
    echo "$(date '+%F %T') [$NOME] execução anterior ainda rodando, pulando" >> "$LOG"
    # o filho não herda o lock: fecha o descritor antes de chamar o PHP
    exec 9>&-
    registrar --skipped --command="$*"
    exit 0
fi

cd "$DIR" || exit 1
echo "$(date '+%F %T') [$NOME] início: $*" >> "$LOG"

if [ "$1" = "php" ]; then
    shift
    INICIO_MS=$(date +%s%3N)
    SAIDA="$LOCK_DIR/cron-$NOME.saida"
    "$PHP" "$@" > "$SAIDA" 2>&1
    RC=$?
    FIM_MS=$(date +%s%3N)
    cat "$SAIDA" >> "$LOG"
    echo "$(date '+%F %T') [$NOME] fim (rc=$RC)" >> "$LOG"
    if [ $RC -ne 0 ]; then
        registrar --start-ms="$INICIO_MS" --end-ms="$FIM_MS" --exit-code="$RC" --command="php $*" --error="$(tail -n 20 "$SAIDA")"
    else
        registrar --start-ms="$INICIO_MS" --end-ms="$FIM_MS" --exit-code="$RC" --command="php $*"
    fi
    rm -f "$SAIDA"
    exit $RC
fi

# Lido pelo ConsoleExecutionListener do bundle: grava a execução no painel.
export CHAMPS_CRON_NAME="$NOME"
"$PHP" bin/console "$@" --no-interaction >> "$LOG" 2>&1
RC=$?
echo "$(date '+%F %T') [$NOME] fim (rc=$RC)" >> "$LOG"
exit $RC
