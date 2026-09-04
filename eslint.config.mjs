import prettier from 'eslint-config-prettier';
import pluginChaiFriendly from 'eslint-plugin-chai-friendly';
import cypress from 'eslint-plugin-cypress';
import mochaPlugin from 'eslint-plugin-mocha';
import playwright from 'eslint-plugin-playwright';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';
import { defineConfig } from 'eslint/config';
import globals from 'globals';
import tseslint from 'typescript-eslint';
import js from '@eslint/js';
import vitest from '@vitest/eslint-plugin';

// cspell:ignore quasis

const drupalGlobals = {
  Drupal: true,
  drupalSettings: true,
};

const isolatedPerTestPlugin = {
  rules: {
    'require-isolated-per-test-import': {
      meta: {
        type: 'problem',
        docs: {
          description:
            "Require `import { isolatedPerTest as test } from '../../fixtures/test.js'` in isolatedPerTest spec files",
        },
        schema: [],
        messages: {
          missingImport:
            "isolatedPerTest spec files must import `{ isolatedPerTest as test }` from '../../fixtures/test.js'.",
        },
      },
      create(context) {
        let found = false;
        return {
          ImportDeclaration(node) {
            if (node.source.value !== '../../fixtures/test.js') return;
            const hasSpecifier = node.specifiers.some(
              (s) =>
                s.type === 'ImportSpecifier' &&
                s.imported.name === 'isolatedPerTest' &&
                s.local.name === 'test',
            );
            if (hasSpecifier) found = true;
          },
          'Program:exit'(node) {
            if (!found) {
              context.report({ node, messageId: 'missingImport' });
            }
          },
        };
      },
    },
  },
};

const translationPlugin = {
  rules: {
    'require-translated-user-facing-strings': {
      meta: {
        type: 'problem',
        docs: {
          description:
            'Require user-facing strings in UI source to be wrapped in translation helpers.',
        },
        schema: [],
        messages: {
          untranslated:
            'Wrap user-facing strings in translate()/translatePlural() (or Drupal.t()/Drupal.formatPlural()).',
        },
      },
      create(context) {
        const translatableAttributeNames = new Set([
          'alt',
          'aria-description',
          'aria-label',
          'aria-placeholder',
          'aria-roledescription',
          'label',
          'placeholder',
          'title',
        ]);

        const isMeaningfulString = (value) =>
          typeof value === 'string' &&
          value.trim().length > 0 &&
          /[A-Za-z]/.test(value);

        const isAllowedTranslationCallee = (callee) => {
          if (callee.type === 'Identifier') {
            return (
              callee.name === 'translate' || callee.name === 'translatePlural'
            );
          }
          if (
            callee.type === 'MemberExpression' &&
            !callee.computed &&
            callee.object.type === 'Identifier' &&
            callee.object.name === 'Drupal' &&
            callee.property.type === 'Identifier'
          ) {
            return (
              callee.property.name === 't' ||
              callee.property.name === 'formatPlural'
            );
          }
          return false;
        };

        const isArgumentOfAllowedTranslationCall = (node) => {
          const parent = node.parent;
          return (
            parent?.type === 'CallExpression' &&
            parent.arguments.includes(node) &&
            isAllowedTranslationCallee(parent.callee)
          );
        };

        const isUserFacingLiteralContext = (node) => {
          const parent = node.parent;
          const grandparent = parent?.parent;

          if (
            parent?.type === 'JSXAttribute' &&
            parent.name?.type === 'JSXIdentifier' &&
            translatableAttributeNames.has(parent.name.name)
          ) {
            return true;
          }

          if (
            parent?.type === 'JSXExpressionContainer' &&
            (grandparent?.type === 'JSXElement' ||
              grandparent?.type === 'JSXFragment')
          ) {
            return true;
          }

          if (
            parent?.type === 'CallExpression' &&
            parent.arguments.includes(node) &&
            parent.callee.type === 'Identifier' &&
            ['alert', 'confirm', 'prompt'].includes(parent.callee.name)
          ) {
            return true;
          }

          return false;
        };

        return {
          JSXText(node) {
            if (isMeaningfulString(node.value)) {
              context.report({ node, messageId: 'untranslated' });
            }
          },
          Literal(node) {
            if (!isMeaningfulString(node.value)) return;
            if (isArgumentOfAllowedTranslationCall(node)) return;
            if (!isUserFacingLiteralContext(node)) return;
            context.report({ node, messageId: 'untranslated' });
          },
          TemplateLiteral(node) {
            if (node.expressions.length > 0) return;
            const text = node.quasis.map((q) => q.value.cooked ?? '').join('');
            if (!isMeaningfulString(text)) return;
            if (!isUserFacingLiteralContext(node)) return;
            context.report({ node, messageId: 'untranslated' });
          },
        };
      },
    },
  },
};

export default defineConfig([
  js.configs.recommended,
  tseslint.configs.recommended,
  prettier,
  react.configs.flat.recommended,
  react.configs.flat['jsx-runtime'],
  reactHooks.configs.flat.recommended,
  {
    files: ['**/*.test.*'],
    plugins: {
      vitest,
    },
    rules: {
      ...vitest.configs.recommended.rules,
      'vitest/valid-expect': 'off', // https://github.com/vitest-dev/eslint-plugin-vitest/issues/675'
      'vitest/no-conditional-expect': 'off',
    },
  },
  {
    files: ['**/*.cy.*', 'ui/tests/e2e/entity-form-fields/*'],
    plugins: {
      mocha: mochaPlugin,
      cypress: cypress,
      ['chai-friendly']: pluginChaiFriendly,
    },
    rules: {
      ...mochaPlugin.configs.recommended.rules,
      ...cypress.configs.recommended.rules,
      ...pluginChaiFriendly.configs.recommended.rules,
      'mocha/no-mocha-arrows': 'off',
      'mocha/no-top-level-hooks': 'off',
      'mocha/max-top-level-suites': 'off',
      'mocha/no-exclusive-tests': 'error',
    },
  },
  {
    rules: {
      '@typescript-eslint/no-explicit-any': 'off',
      '@typescript-eslint/no-unsafe-function-type': 'off',
      '@typescript-eslint/ban-ts-comment': 'off',
      '@typescript-eslint/no-empty-object-type': 'off',
      '@typescript-eslint/no-unused-expressions': 'off',
      '@typescript-eslint/no-unnecessary-type-constraint': 'off',
      '@typescript-eslint/consistent-type-imports': [
        2,
        {
          fixStyle: 'separate-type-imports',
        },
      ],
      '@typescript-eslint/no-restricted-imports': [
        2,
        {
          paths: [
            {
              name: 'react-redux',
              importNames: ['useSelector', 'useStore', 'useDispatch'],
              message:
                'Please use pre-typed versions from `src/app/hooks.ts` instead.',
            },
          ],
        },
      ],
      'react-hooks/immutability': 'off',
      'react-hooks/set-state-in-effect': 'off',
      'react-hooks/refs': 'off',
      'react-hooks/static-components': 'off',
      'react-hooks/globals': 'off',
      'react-hooks/rules-of-hooks': 'off',
      'jsx-no-undef': 'off',
      'react/prop-types': 'off',
      'react/no-unescaped-entities': 'off',
      'react/display-name': 'off',
      'no-shadow': 'off',
      'no-unused-vars': 'off',
      '@typescript-eslint/no-unused-vars': [
        'error',
        { args: 'none', caughtErrors: 'none' },
      ],
      'no-redeclare': ['error', { builtinGlobals: false }],
    },
  },
  {
    files: ['**/*.{mjs,cjs,js,jsx}'],
    rules: {
      '@typescript-eslint/no-unused-expressions': 'off',
      '@typescript-eslint/no-unused-vars': 'off',
    },
  },
  {
    languageOptions: {
      parserOptions: {
        ecmaFeatures: {
          jsx: true,
        },
      },
      globals: {
        ...globals.browser,
        ...globals.node,
        ...vitest.environments.env.globals,
        ...drupalGlobals,
        ...mochaPlugin.configs.recommended.languageOptions.globals,
        once: true,
        cy: true,
        Cypress: true,
        JSX: true,
        NodeJS: true,
        React: true,
        jQuery: true,
      },
    },
    settings: {
      react: {
        version: '18.2',
      },
    },
  },
  {
    files: ['tests/src/Playwright/**/*.ts'],
    extends: [playwright.configs['flat/recommended']],
  },
  {
    files: ['**/tests/isolatedPerTest/**/*.spec.ts'],
    plugins: {
      'isolated-per-test': isolatedPerTestPlugin,
    },
    rules: {
      'isolated-per-test/require-isolated-per-test-import': 'error',
    },
  },
  {
    files: ['ui/src/**/*.{ts,tsx,js,jsx}'],
    ignores: ['ui/src/**/*.test.*', 'ui/src/**/*.stories.*'],
    plugins: {
      translation: translationPlugin,
    },
    rules: {
      'translation/require-translated-user-facing-strings': 'error',
    },
  },
  {
    ignores: [
      '.cache',
      '**/dist',
      '**/.astro',
      'js/astro-bundles/*',
      'js/assets/**/*',
      'ui/src/local_packages',
      '.cache/**',
    ],
  },
]);
