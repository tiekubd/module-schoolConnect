#!/bin/bash
# call.sh PERSON [version]: one PHP test server per person, each on its own port
T=/tmp/claude-0/-home-claude/ed44e25e-8ea3-50fe-9e70-472e23db17dc/scratchpad/site
PORT=$((8100 + $1))
if ! curl -s -o /dev/null "http://127.0.0.1:$PORT/"; then
  (cd "$T" && TEST_PERSON=$1 exec php -S 127.0.0.1:$PORT >/dev/null 2>&1) & echo $! >> /tmp/claude-0/phpsrv.pids; sleep 1
fi
curl -s --compressed -D /tmp/claude-0/hdr.txt -X POST -H "Content-Type: application/json" --data "{\"version\":\"$2\"}" "http://127.0.0.1:$PORT/modules/School%20Connect/api/i18n.php"
