/**
 * Builds the HR panel assets into public/build: Bootstrap 5 (SCSS + JS), Bootstrap Icons,
 * the self-hosted Onest font and the panel's own styles and scripts.
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
    publicPath: '/build/',
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
          {
            loader: 'sass-loader',
            options: {
              sassOptions: {
                quietDeps: true,
                silenceDeprecations: ['import', 'global-builtin', 'color-functions'],
              },
            },
          },
        ],
      },
      {
        // fonts from @fontsource-variable/onest and bootstrap-icons, served from this server
        test: /\.(woff2?|ttf|eot)$/,
        type: 'asset/resource',
        generator: { filename: 'fonts/[name][ext]' },
      },
    ],
  },
  plugins: [new MiniCssExtractPlugin({ filename: '[name].css' })],
  performance: { hints: false },
  devtool: false,
};
