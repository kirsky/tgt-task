'use strict';

/**
 * ESLint (flat config).
 *
 * - Browser assets (ES modules, strict by definition) get type-aware linting through
 *   typescript-eslint, so deprecated DOM APIs (e.g. document.execCommand) are reported.
 * - Node build/config files are CommonJS and must declare 'use strict'.
 * - Formatting is left to Prettier (eslint-config-prettier turns off conflicting rules).
 */
const js = require('@eslint/js');
const globals = require('globals');
const tseslint = require('typescript-eslint');
const prettier = require('eslint-config-prettier');

module.exports = [
  { ignores: ['vendor/**', 'node_modules/**', 'public/build/**', 'var/**', 'docs/**'] },
  js.configs.recommended,
  {
    files: ['assets/**/*.js'],
    languageOptions: {
      sourceType: 'module',
      globals: globals.browser,
      parser: tseslint.parser,
      parserOptions: { projectService: true, tsconfigRootDir: __dirname },
    },
    plugins: { '@typescript-eslint': tseslint.plugin },
    rules: {
      '@typescript-eslint/no-deprecated': 'error',
      'no-implicit-globals': 'error',
      eqeqeq: 'error',
      'prefer-const': 'error',
    },
  },
  {
    files: ['*.config.js', 'eslint.config.js'],
    languageOptions: { sourceType: 'commonjs', globals: globals.node },
    rules: { strict: ['error', 'global'] },
  },
  prettier,
];
