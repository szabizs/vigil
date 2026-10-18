#!/bin/sh

# Forward TERM/INT to the relay so it stops accepting, rotates the open segment and ships the spool before exiting.
shutdown() {
    echo "Received signal, shutting down..."
    if [ -n "$child" ]; then
        kill -TERM "$child" 2>/dev/null
        wait "$child"
    fi
    exit 0
}

trap shutdown TERM INT

php agent/build/agent.phar &
child=$!

wait "$child"
