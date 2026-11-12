/**
 * Builds the HR panel assets: Bootstrap 5 (SCSS + JS bundle) and the panel's own styles
 * into public/build/app.css and public/build/app.js.
 *
 *   npm ci && npm run build      # production
 *   npm run watch                # development
 */
const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');

module.exports = {
  entry: { app: './assets/app.js' },
  output: {
    path: path.resolve(__dirname, 'public/build'),
    filename: '[name].js',
    clean: true,
  },
  module: {
    rules: [
      {
        test: /\.s?css$/,
        use: [
          MiniCssExtractPlugin.loader,
          'css-loader',
          { loader: 'sass-loader', options: { sassOptions: { quietDeps: true, silenceDeprecations: ['import', 'global-builtin', 'color-functions'] } } },
        ],
      },
    ],
  },
  plugins: [new MiniCssExtractPlugin({ filename: '[name].css' })],
  performance: { hints: false },
  devtool: false,
};
