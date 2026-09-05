#!/usr/bin/env bash
# WorktreeCreate hook. Defining this hook replaces Claude Code's built-in
# worktree creation, so it must create the worktree itself and print the
# worktree path as the last line of stdout.
set -euo pipefail
export PATH="$PATH:/opt/homebrew/bin:/usr/local/bin"

input=$(cat)
name=$(printf '%s' "$input" | jq -r '.name')
repo_root=$(printf '%s' "$input" | jq -r '.cwd')
repo_root=$(git -C "$repo_root" rev-parse --path-format=absolute --git-common-dir | sed 's|/\.git$||')

path="$repo_root/.claude/worktrees/$name"
branch="claude/$name"

base=$(git -C "$repo_root" symbolic-ref --quiet --short refs/remotes/origin/HEAD || echo HEAD)
mkdir -p "$repo_root/.claude/worktrees"
git -C "$repo_root" worktree add -b "$branch" "$path" "$base" 1>&2

# Build the worktree's own DDEV project (no committed lock; vendor comes from
# poser). Skipped for subagent isolation worktrees (named agent-<id>), which
# are short-lived and would each cost a ddev start + poser. Best effort: a
# failure here should hand over the worktree anyway.
case "$name" in
  agent-*) ;;
  *)
    if ! (cd "$path" && ddev start -y && ddev poser && ddev symlink-project) 1>&2; then
      echo "warning: ddev setup failed; run 'ddev start && ddev poser && ddev symlink-project' in $path" >&2
    fi
    ;;
esac

echo "$path"
