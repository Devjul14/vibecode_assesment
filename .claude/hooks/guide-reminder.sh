#!/bin/bash
# PostToolUse (Bash): after a passing test run, remind Claude to update the ServOps user guide.
# PostToolUse only fires when the command succeeded, so a match here means tests passed.
cmd=$(php -r '$i = json_decode(stream_get_contents(STDIN), true); echo $i["tool_input"]["command"] ?? "";')
if printf '%s' "$cmd" | grep -Eq '(artisan[[:space:]]+test|phpunit|pest)'; then
  cat <<'EOF'
{"hookSpecificOutput":{"hookEventName":"PostToolUse","additionalContext":"Tests passed. User wants the ServOps user guide doc (https://claude.ai/code/artifact/2290df7d-52d6-4635-abea-4468518ec94d) updated ONCE at the end, not per feature: only update it when the work is finished (Fase 7 Polish, or the user says the app is done) and the final test run is green. Then read it with the docs tools (sinceRev), sync every section with the real app, and remove the 'Catatan status' caveat. Otherwise ignore this reminder."}}
EOF
fi
exit 0
