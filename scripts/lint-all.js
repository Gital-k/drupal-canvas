#!/usr/bin/env node
/**
 * Runs all linters in parallel via lint-quiet.js so that passing checks
 * produce a single summary line while failures still show full output.
 */

import { concurrently } from "concurrently";
import { fileURLToPath } from "node:url";
import path from "node:path";

const root = path.resolve(fileURLToPath(import.meta.url), "../..");
const quiet = `node ${JSON.stringify(path.join(root, "scripts/lint-quiet.js"))}`;

const linters = [
  { label: "eslint",   cmd: "eslint" },
  { label: "openapi",  cmd: "redocly lint openapi.yml" },
  { label: "prettier", cmd: "prettier tests/src/Playwright playwright.config.ts packages ui eslint.config.mjs --check" },
  { label: "stylelint", cmd: 'stylelint "**/*.css"' },
  { label: "tsc",      cmd: "npm run type-check --workspaces" },
  { label: "cspell",   cmd: "cspell . --no-progress" },
  { label: "yaml",     cmd: "yamllint '**/*.(yaml|yml)' --ignore='.gitlab/**' --ignore='.gitlab-ci.yml' --ignore='node_modules/**'" },
];

const commands = linters.map(({ label, cmd }) => ({
  command: `${quiet} ${label} ${cmd}`,
  name: label,
}));

const { result } = concurrently(commands, { group: true });

result.catch(() => process.exit(1));
