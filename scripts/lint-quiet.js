#!/usr/bin/env node
/**
 * Runs a lint command and suppresses its output when it passes.
 *
 * Usage: node scripts/lint-quiet.mjs <label> <command> [args...]
 *
 * On success: prints a single "✔ <label>" line.
 * On failure: prints the full captured stdout/stderr followed by an error line.
 */

import { spawn } from "node:child_process";

const [, , label, ...cmd] = process.argv;

if (!label || cmd.length === 0) {
  process.stderr.write(
    "Usage: lint-quiet.mjs <label> <command> [args...]\n",
  );
  process.exit(1);
}

const child = spawn(cmd[0], cmd.slice(1), {
  stdio: ["inherit", "pipe", "pipe"],
  shell: false,
});

let stdout = "";
let stderr = "";

child.stdout.on("data", (chunk) => {
  stdout += chunk;
});
child.stderr.on("data", (chunk) => {
  stderr += chunk;
});

child.on("close", (code) => {
  if (code === 0) {
    process.stdout.write(`\u2714 ${label}\n`);
  } else {
    const combined = stdout + stderr;
    if (combined.trim()) {
      process.stdout.write(combined.endsWith("\n") ? combined : combined + "\n");
    }
    process.stderr.write(`\u2718 ${label} failed (exit ${code})\n`);
    process.exit(code ?? 1);
  }
});
