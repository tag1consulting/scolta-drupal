// GENERATED FILE. DO NOT EDIT.
//
// The per-package version coherence check, bundled from scolta-fleet:
//   src/coherence.ts + src/package-check.ts
//
// It is vendored rather than checked out because this repository is public
// and scolta-fleet is private, so a checkout would need a personal access
// token stored here as a secret. Edit the TypeScript source in scolta-fleet
// and regenerate every copy with `npm run vendor:coherence`.
//
// sources-sha256: c409a1cfbf9d459d
// src/package-check.ts
import { existsSync as existsSync2 } from "node:fs";
import { basename as basename2, resolve } from "node:path";

// src/coherence.ts
import { existsSync, readFileSync, readdirSync } from "node:fs";
import { basename, join } from "node:path";
function versionLine(raw) {
  const m = /^v?(\d+)\.(\d+)\.(?:\d+|x)/.exec(raw.trim());
  return m ? `${m[1]}.${m[2]}` : null;
}
function readJson(path) {
  if (!existsSync(path)) return null;
  try {
    return JSON.parse(readFileSync(path, "utf8"));
  } catch {
    return null;
  }
}
function collectStamps(packageDir) {
  const stamps = [];
  const push = (source, raw) => {
    if (typeof raw !== "string") return;
    const line = versionLine(raw);
    if (line) stamps.push({ source, raw, line });
  };
  const composer = readJson(join(packageDir, "composer.json"));
  if (composer) {
    push("composer.json version", composer.version);
    push("composer.json extra.branch-alias.dev-main", composer.extra?.["branch-alias"]?.["dev-main"]);
  }
  for (const entry of existsSync(packageDir) ? readdirSync(packageDir) : []) {
    const path = join(packageDir, entry);
    if (entry.endsWith(".info.yml")) {
      const m = /^version:\s*['"]?([^'"\n]+)/m.exec(readFileSync(path, "utf8"));
      push(entry, m?.[1]);
    } else if (entry.endsWith(".php")) {
      const head = readFileSync(path, "utf8").slice(0, 2e3);
      if (/^\s*\*\s*Plugin Name:/m.test(head)) {
        const m = /^\s*\*\s*Version:\s*(\S+)/m.exec(head);
        push(`${entry} plugin header`, m?.[1]);
      }
    }
  }
  return stamps;
}
function checkPackage(packageDir) {
  const name = basename(packageDir);
  const stamps = collectStamps(packageDir);
  if (stamps.length < 2) return [];
  const lines = [...new Set(stamps.map((s) => s.line))];
  if (lines.length === 1) return [];
  const shown = stamps.map((s) => `${s.source} says ${s.raw} (line ${s.line})`).join("; ");
  return [{
    severity: "error",
    subject: name,
    detail: `states ${lines.length} different dev lines about itself: ${shown}`
  }];
}
function formatFindings(findings) {
  if (findings.length === 0) return "coherence: every package agrees with itself and no demo pins a package it does not own.";
  return findings.map((f) => `  ${f.severity.toUpperCase().padEnd(5)} ${f.subject}: ${f.detail}`).join("\n");
}

// src/package-check.ts
function main(argv) {
  if (argv.includes("--help") || argv.includes("-h")) {
    process.stdout.write(
      "Check one Scolta package for version coherence.\n\n  node check-coherence.mjs [DIR]   (default: the current directory)\n\nFails when a package states more than one development line about itself\nacross composer.json version, composer.json extra.branch-alias.dev-main,\na Drupal .info.yml, and a WordPress plugin header.\n"
    );
    return 0;
  }
  const dir = resolve(argv[0] ?? ".");
  if (!existsSync2(dir)) {
    process.stderr.write(`No such directory: ${dir}
`);
    return 2;
  }
  const findings = checkPackage(dir);
  if (findings.length === 0) {
    process.stdout.write(`coherence: ${basename2(dir)} states one development line about itself.
`);
    return 0;
  }
  process.stdout.write(formatFindings(findings) + "\n");
  return findings.some((f) => f.severity === "error") ? 1 : 0;
}
process.exit(main(process.argv.slice(2)));
