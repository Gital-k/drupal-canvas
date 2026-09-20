/**
 * @file
 * The capability gates for the pull codemod: the connected Drupal site must
 * advertise context-hook support, and the project's installed `drupal-canvas`
 * package must export both hooks as runtime values from its root entry. The
 * package's code is never executed: its root entry is resolved under the
 * `import` export conditions and parsed statically, following relative
 * re-exports and local aliases inside the package. When either gate cannot be
 * verified, getter calls stay unchanged and the limitation is reported.
 */

import fs from 'node:fs/promises';
import { createRequire } from 'node:module';
import path from 'node:path';
import axios from 'axios';
import { parse } from '@babel/parser';

import type { File, Node, Statement } from '@babel/types';

export interface ContextHookSupport {
  /** Whether both gates passed. */
  supported: boolean;
  /** Why conversion is disabled, one entry per failed gate. */
  reasons: string[];
}

export interface ContextHookSupportOptions {
  siteUrl: string;
  projectRoot: string;
  /** Injectable for tests. */
  fetchSiteData?: (siteUrl: string) => Promise<unknown>;
}

async function fetchSiteData(siteUrl: string): Promise<unknown> {
  const response = await axios.get(
    `${siteUrl.replace(/\/+$/, '')}/canvas/api/v0/site-data`,
    {
      headers: { Accept: 'application/json', 'X-Canvas-CLI': '1' },
      timeout: 10000,
    },
  );
  return response.data;
}

async function readManifestName(candidate: string): Promise<string | null> {
  try {
    const manifest = JSON.parse(await fs.readFile(candidate, 'utf-8')) as {
      name?: string;
    };
    return manifest.name ?? null;
  } catch {
    return null;
  }
}

/**
 * Locates the `package.json` of the `drupal-canvas` package a project
 * resolves: through Node's resolution first (the exports map hides
 * `package.json`, so the entry is resolved and the package root found from
 * it), then by walking the project's `node_modules` ancestors.
 */
async function locatePackageJson(projectRoot: string): Promise<string | null> {
  try {
    const require = createRequire(path.join(projectRoot, 'package.json'));
    let directory = path.dirname(require.resolve('drupal-canvas'));
    while (directory !== path.dirname(directory)) {
      const candidate = path.join(directory, 'package.json');
      if ((await readManifestName(candidate)) === 'drupal-canvas') {
        return candidate;
      }
      directory = path.dirname(directory);
    }
  } catch {
    // Fall through to the directory walk (e.g. an exports map without a
    // condition Node's CommonJS resolver accepts).
  }
  let directory = path.resolve(projectRoot);
  while (true) {
    const candidate = path.join(
      directory,
      'node_modules',
      'drupal-canvas',
      'package.json',
    );
    if ((await readManifestName(candidate)) === 'drupal-canvas') {
      return candidate;
    }
    const parent = path.dirname(directory);
    if (parent === directory) {
      return null;
    }
    directory = parent;
  }
}

type ExportConditions = string | { [condition: string]: ExportConditions };

/**
 * Resolves the root entry of a package manifest the way an `import` of the
 * package does: the `.` export under the `import` condition (then `default`),
 * nested conditions included, else `module`/`main`. Null when the manifest
 * gives no usable entry.
 */
export function resolveRuntimeEntry(manifest: {
  exports?: ExportConditions | { [key: string]: ExportConditions };
  module?: string;
  main?: string;
}): string | null {
  const pick = (value: ExportConditions | undefined): string | null => {
    if (typeof value === 'string') {
      return value;
    }
    if (!value || typeof value !== 'object') {
      return null;
    }
    for (const condition of ['import', 'default']) {
      if (condition in value) {
        const resolved = pick(value[condition]);
        if (resolved !== null) {
          return resolved;
        }
      }
    }
    return null;
  };
  const exports = manifest.exports;
  if (exports !== undefined) {
    if (typeof exports === 'string') {
      return exports;
    }
    const subpaths = exports as { [key: string]: ExportConditions };
    const rootEntry =
      '.' in subpaths
        ? pick(subpaths['.'])
        : pick(subpaths as ExportConditions);
    return rootEntry;
  }
  return manifest.module ?? manifest.main ?? null;
}

/** The runtime value exports of a module, statically determined. */
export interface RuntimeExports {
  /** Names exported as runtime values (not types). */
  names: Set<string>;
  /** Why some exports could not be determined; empty when fully verified. */
  unverifiable: string[];
}

function isNode(value: unknown): value is Node {
  return (
    typeof value === 'object' &&
    value !== null &&
    typeof (value as Node).type === 'string'
  );
}

/** Identifier names bound by a declaration pattern. */
function patternNames(node: Node, names: string[] = []): string[] {
  switch (node.type) {
    case 'Identifier':
      names.push(node.name);
      break;
    case 'ObjectPattern':
      for (const property of node.properties) {
        patternNames(
          property.type === 'RestElement' ? property.argument : property.value,
          names,
        );
      }
      break;
    case 'ArrayPattern':
      for (const element of node.elements) {
        if (element) {
          patternNames(element, names);
        }
      }
      break;
    case 'RestElement':
      patternNames(node.argument, names);
      break;
    case 'AssignmentPattern':
      patternNames(node.left, names);
      break;
    default:
      break;
  }
  return names;
}

/** Whether a module-level statement contains CommonJS-style export writes. */
function hasDynamicExports(statement: Statement): boolean {
  let found = false;
  const visit = (node: Node) => {
    if (found) {
      return;
    }
    if (
      node.type === 'MemberExpression' &&
      !node.computed &&
      node.object.type === 'Identifier' &&
      ((node.object.name === 'module' &&
        node.property.type === 'Identifier' &&
        node.property.name === 'exports') ||
        node.object.name === 'exports')
    ) {
      found = true;
      return;
    }
    for (const [key, value] of Object.entries(node)) {
      if (key === 'loc' || key.endsWith('Comments')) {
        continue;
      }
      if (Array.isArray(value)) {
        value.forEach((item) => isNode(item) && visit(item));
      } else if (isNode(value)) {
        visit(value);
      }
    }
  };
  visit(statement);
  return found;
}

async function resolveRelativeModule(
  from: string,
  specifier: string,
  packageRoot: string,
): Promise<string | null> {
  const base = path.resolve(path.dirname(from), specifier);
  for (const candidate of [
    base,
    `${base}.js`,
    `${base}.mjs`,
    `${base}.ts`,
    path.join(base, 'index.js'),
    path.join(base, 'index.ts'),
  ]) {
    const relative = path.relative(packageRoot, candidate);
    if (relative.startsWith('..') || path.isAbsolute(relative)) {
      return null;
    }
    try {
      if ((await fs.stat(candidate)).isFile()) {
        return candidate;
      }
    } catch {
      // Try the next candidate.
    }
  }
  return null;
}

/**
 * Determines the runtime value exports of an ES module file without running
 * it: declarations exported in place, local bindings exported by name or
 * alias (including bindings imported from relative modules of the same
 * package), and relative re-exports (`export { a as b } from`, `export *
 * from`). Type-only exports are ignored. Re-exports from other packages,
 * CommonJS export writes, and unresolvable modules are reported as
 * unverifiable rather than guessed.
 */
export async function collectRuntimeExports(
  file: string,
  packageRoot: string,
  visited = new Map<string, Promise<RuntimeExports>>(),
): Promise<RuntimeExports> {
  const cached = visited.get(file);
  if (cached) {
    return cached;
  }
  const promise = (async (): Promise<RuntimeExports> => {
    const result: RuntimeExports = { names: new Set(), unverifiable: [] };
    let ast: File;
    try {
      // Published entries are JavaScript; a package exporting TypeScript
      // source is parsed as such so type-only exports are recognized.
      ast = parse(await fs.readFile(file, 'utf-8'), {
        sourceType: 'module',
        sourceFilename: file,
        plugins: /\.[cm]?tsx?$/.test(file) ? ['jsx', 'typescript'] : ['jsx'],
        errorRecovery: false,
      });
    } catch (error) {
      result.unverifiable.push(
        `${path.relative(packageRoot, file)} could not be parsed as an ES module (${
          error instanceof Error ? error.message : String(error)
        })`,
      );
      return result;
    }
    /** Module-level runtime bindings declared in this file. */
    const local = new Set<string>();
    /** Local bindings imported from relative modules: local → [file, name]. */
    const imported = new Map<string, { source: string; name: string }>();
    /** Local bindings imported from other packages (runtime values). */
    const external = new Set<string>();
    for (const statement of ast.program.body) {
      switch (statement.type) {
        case 'FunctionDeclaration':
        case 'ClassDeclaration':
          if (statement.id) {
            local.add(statement.id.name);
          }
          break;
        case 'VariableDeclaration':
          for (const declarator of statement.declarations) {
            patternNames(declarator.id).forEach((name) => local.add(name));
          }
          break;
        case 'ImportDeclaration': {
          if (statement.importKind === 'type') {
            break;
          }
          const source = statement.source.value;
          for (const specifier of statement.specifiers) {
            if (
              specifier.type === 'ImportSpecifier' &&
              specifier.importKind === 'type'
            ) {
              continue;
            }
            if (source.startsWith('.')) {
              const name =
                specifier.type === 'ImportSpecifier'
                  ? specifier.imported.type === 'Identifier'
                    ? specifier.imported.name
                    : specifier.imported.value
                  : specifier.type === 'ImportDefaultSpecifier'
                    ? 'default'
                    : '*';
              imported.set(specifier.local.name, { source, name });
            } else {
              external.add(specifier.local.name);
            }
          }
          break;
        }
        default:
          if (hasDynamicExports(statement)) {
            result.unverifiable.push(
              `${path.relative(packageRoot, file)} assigns exports dynamically (CommonJS)`,
            );
          }
          break;
      }
    }
    const resolveLocal = async (
      name: string,
      exported: string,
    ): Promise<void> => {
      if (local.has(name)) {
        result.names.add(exported);
        return;
      }
      const source = imported.get(name);
      if (source) {
        if (source.name === '*') {
          result.names.add(exported);
          return;
        }
        const target = await resolveRelativeModule(
          file,
          source.source,
          packageRoot,
        );
        if (target === null) {
          result.unverifiable.push(
            `${path.relative(packageRoot, file)} imports \`${name}\` from an unresolvable module (${source.source})`,
          );
          return;
        }
        const exports = await collectRuntimeExports(
          target,
          packageRoot,
          visited,
        );
        result.unverifiable.push(...exports.unverifiable);
        if (exports.names.has(source.name)) {
          result.names.add(exported);
        }
        return;
      }
      if (external.has(name)) {
        result.unverifiable.push(
          `${path.relative(packageRoot, file)} re-exports \`${exported}\` from another package`,
        );
        return;
      }
      result.unverifiable.push(
        `${path.relative(packageRoot, file)} exports \`${exported}\` from an unknown binding \`${name}\``,
      );
    };
    for (const statement of ast.program.body) {
      if (statement.type === 'ExportDefaultDeclaration') {
        result.names.add('default');
        continue;
      }
      if (statement.type === 'ExportAllDeclaration') {
        if (statement.exportKind === 'type') {
          continue;
        }
        const target = statement.source.value.startsWith('.')
          ? await resolveRelativeModule(
              file,
              statement.source.value,
              packageRoot,
            )
          : null;
        if (target === null) {
          result.unverifiable.push(
            `${path.relative(packageRoot, file)} re-exports everything from ${statement.source.value}`,
          );
          continue;
        }
        const exports = await collectRuntimeExports(
          target,
          packageRoot,
          visited,
        );
        result.unverifiable.push(...exports.unverifiable);
        for (const name of exports.names) {
          if (name !== 'default') {
            result.names.add(name);
          }
        }
        continue;
      }
      if (statement.type !== 'ExportNamedDeclaration') {
        continue;
      }
      if (statement.exportKind === 'type') {
        continue;
      }
      const declaration = statement.declaration;
      if (declaration) {
        if (
          (declaration.type === 'FunctionDeclaration' ||
            declaration.type === 'ClassDeclaration') &&
          declaration.id
        ) {
          result.names.add(declaration.id.name);
        } else if (declaration.type === 'VariableDeclaration') {
          for (const declarator of declaration.declarations) {
            patternNames(declarator.id).forEach((name) =>
              result.names.add(name),
            );
          }
        }
        continue;
      }
      const source = statement.source?.value;
      let sourceExports: RuntimeExports | null = null;
      if (source !== undefined) {
        const target = source.startsWith('.')
          ? await resolveRelativeModule(file, source, packageRoot)
          : null;
        if (target === null) {
          result.unverifiable.push(
            `${path.relative(packageRoot, file)} re-exports from ${source}`,
          );
          continue;
        }
        sourceExports = await collectRuntimeExports(
          target,
          packageRoot,
          visited,
        );
        result.unverifiable.push(...sourceExports.unverifiable);
      }
      for (const specifier of statement.specifiers) {
        if (specifier.type === 'ExportNamespaceSpecifier') {
          result.names.add(specifier.exported.name);
          continue;
        }
        if (specifier.type !== 'ExportSpecifier') {
          continue;
        }
        if (specifier.exportKind === 'type') {
          continue;
        }
        const exported =
          specifier.exported.type === 'Identifier'
            ? specifier.exported.name
            : specifier.exported.value;
        if (sourceExports) {
          if (sourceExports.names.has(specifier.local.name)) {
            result.names.add(exported);
          }
        } else {
          await resolveLocal(specifier.local.name, exported);
        }
      }
    }
    return result;
  })();
  visited.set(file, promise);
  return promise;
}

const REQUIRED_HOOKS = ['usePageContext', 'useSiteContext'] as const;

/**
 * Checks whether the installed `drupal-canvas` package of a project exports
 * both context hooks as runtime values from its root entry, whatever version
 * it reports. Fails closed: an entry that cannot be resolved or parsed, or
 * exports that cannot be determined statically, count as unsupported.
 */
export async function checkInstalledPackage(
  projectRoot: string,
): Promise<{ ok: boolean; reason?: string }> {
  const packageJsonPath = await locatePackageJson(projectRoot);
  if (packageJsonPath === null) {
    return {
      ok: false,
      reason:
        'the `drupal-canvas` package is not installed in this project (run `npm install drupal-canvas@latest`)',
    };
  }
  const packageRoot = path.dirname(packageJsonPath);
  let entry: string | null;
  try {
    entry = resolveRuntimeEntry(
      JSON.parse(await fs.readFile(packageJsonPath, 'utf-8')) as Parameters<
        typeof resolveRuntimeEntry
      >[0],
    );
  } catch {
    return {
      ok: false,
      reason: 'the installed `drupal-canvas` package could not be read',
    };
  }
  if (entry === null) {
    return {
      ok: false,
      reason:
        'the installed `drupal-canvas` package declares no root entry to import',
    };
  }
  const exports = await collectRuntimeExports(
    path.resolve(packageRoot, entry),
    packageRoot,
  );
  const missing = REQUIRED_HOOKS.filter((hook) => !exports.names.has(hook));
  if (missing.length === 0) {
    return { ok: true };
  }
  if (exports.unverifiable.length > 0) {
    return {
      ok: false,
      reason: `the installed \`drupal-canvas\` package's exports could not be verified (${[...new Set(exports.unverifiable)].join('; ')}); run \`npm install drupal-canvas@latest\` and pull again`,
    };
  }
  return {
    ok: false,
    reason: `the installed \`drupal-canvas\` package does not export ${missing
      .map((hook) => `\`${hook}()\``)
      .join(
        ' and ',
      )} as runtime values (run \`npm install drupal-canvas@latest\`)`,
  };
}

/**
 * Evaluates both capability gates.
 */
export async function evaluateContextHookSupport(
  options: ContextHookSupportOptions,
): Promise<ContextHookSupport> {
  const reasons: string[] = [];
  try {
    const data = (await (options.fetchSiteData ?? fetchSiteData)(
      options.siteUrl,
    )) as { capabilities?: { contextHooks?: unknown } } | null;
    if (data?.capabilities?.contextHooks !== true) {
      reasons.push(
        'the site does not advertise context-hook support at /canvas/api/v0/site-data (update the Drupal Canvas module)',
      );
    }
  } catch (error) {
    reasons.push(
      `context-hook support could not be verified at /canvas/api/v0/site-data (${
        error instanceof Error ? error.message : String(error)
      })`,
    );
  }
  const installed = await checkInstalledPackage(options.projectRoot);
  if (!installed.ok && installed.reason) {
    reasons.push(installed.reason);
  }
  return { supported: reasons.length === 0, reasons };
}
