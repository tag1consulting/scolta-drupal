#!/usr/bin/env bash
# WorktreeRemove hook: tear down the per-worktree DDEV project before Claude
# Code removes the worktree directory.
set -uo pipefail
export PATH="$PATH:/opt/homebrew/bin:/usr/local/bin"

worktree_path=$(jq -r '.worktree_path')
case "$(basename "$worktree_path")" in agent-*) exit 0 ;; esac
if [ -d "$worktree_path/.ddev" ]; then
  (cd "$worktree_path" && ddev delete --omit-snapshot --yes) 1>&2 || true
fi
