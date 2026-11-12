/**
 * ESLint (flat config): recommended rules for browser assets and Node build files.
 * Formatting is left to Prettier (eslint-config-prettier turns off conflicting rules).
 */
const js = require('@eslint/js');
const globals = require('globals');
const prettier = require('eslint-config-prettier');

module.exports = [
  { ignores: ['vendor/**', 'node_modules/**', 'public/build/**', 'var/**', 'docs/**'] },
  js.configs.recommended,
  {
    files: ['assets/**/*.js'],
    languageOptions: { sourceType: 'module', globals: globals.browser },
  },
  {
    files: ['*.config.js', 'eslint.config.js'],
    languageOptions: { sourceType: 'commonjs', globals: globals.node },
  },
  prettier,
];
