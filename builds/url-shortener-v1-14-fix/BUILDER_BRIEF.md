# QUICK FIX — SURGICAL CHANGE

**Change requested:** can you add the tex "hi how are you" below the time

## Workflow (FOLLOW IN ORDER)
1. **Read PRIOR_PATCHES.md** (if it exists) to understand what previous Quick Fixes did.
2. **Identify the target.** Use `grep -rn` to find the EXACT element/style/function
   that the user is referring to. If ambiguous, prefer the LAST thing prior patches
   added — that's almost always what the user means.
3. **Read ONLY the file(s) you need to edit.** Do NOT read the whole codebase.
4. **Edit the minimum.** Touching extra files = wasted tokens AND risk of breaking
   things that aren't yours to change.
5. Write a SHORT BUILDER_REPORT.md — max 5 lines, just list files changed.

## ABSOLUTE RULES
- **DO NOT DELETE** any existing element, function, or style. Only modify.
- **DO NOT** refactor. **DO NOT** improve. **DO NOT** add features beyond the request.
- **DO NOT** touch README.md, .htaccess, config.php, or any docs unless the change
  is explicitly about them.
- If the change request is ambiguous, pick the interpretation that requires the
  FEWEST file changes.

## Prior Quick Fixes on this project
Read `PRIOR_PATCHES.md` to see what previous patches added. The user may be
referring to one of those when they say 'the date/time' or 'the button'.
NEVER DELETE existing functionality unless the change request explicitly says
to remove it. If a prior patch added a feature, treat it as part of the spec.

Existing code is in ./output/. Start with `ls output/` then `grep` for relevant terms.