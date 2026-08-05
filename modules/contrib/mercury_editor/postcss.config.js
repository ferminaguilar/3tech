const postcssPresetEnv = require('postcss-preset-env');
const pxtorem = require('postcss-pxtorem');
const postcssInlineSvg = require('postcss-inline-svg');

function encode(code) {
  return code
    .replace(/</g, '%3C')
    .replace(/>/g, '%3E')
    .replace(/\s/g, '%20')
    .replace(/!/g, '%21')
    .replace(/\*/g, '%2A')
    .replace(/'/g, '%27')
    .replace(/\(/g, '%28')
    .replace(/\)/g, '%29')
    .replace(/;/g, '%3B')
    .replace(/:/g, '%3A')
    .replace(/@/g, '%40')
    .replace(/&/g, '%26')
    .replace(/=/g, '%3D')
    .replace(/\+/g, '%2B')
    .replace(/,/g, '%2C')
    .replace(/\//g, '%2F')
    .replace(/\?/g, '%3F')
    .replace(/#/g, '%23')
    .replace(/\[/g, '%5B')
    .replace(/\]/g, '%5D');
}

module.exports = {
  plugins: [
    pxtorem({
      propList: ['--font*', 'font', 'font*'],
    }),
    postcssInlineSvg({
      // Other additional options can be defined here for PostCSS Inline SVG
      encode,
      paths: ['./images/icons'],
    }),
    postcssPresetEnv({
      stage: 1,
      features: {
        // Custom properties get polyfill for IE so no need to process them.
        'custom-properties': false,
        // Disable focus-within polyfill to prevent duplicate rules
        'focus-within-pseudo-class': false,
      },
    }),
  ],
};
