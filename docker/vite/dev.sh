#!/bin/sh
# Starts the Vite dev server for the "vite" service in compose.yaml.
#
# When the container stops, `vp` exits without letting its Vite child process
# clean up, which leaves a stale public/hot behind and makes Laravel keep
# pointing at a dev server that is gone. So we remove it ourselves.
set -e

npm install --no-audit --no-fund

node_modules/.bin/vp dev &
vite_pid=$!

trap 'kill -TERM "$vite_pid" 2>/dev/null; rm -f public/hot; exit 0' TERM INT

wait "$vite_pid"
