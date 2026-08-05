// rollup.config.js
import resolve from '@rollup/plugin-node-resolve';
import { terser } from 'rollup-plugin-terser';
import fs from 'fs';

const config = [];

const sourceDirectories = [
  './components/component-outline/',
  './components/component-icon-select/',
];

sourceDirectories.forEach((directory) => {
  const jsFiles = fs.readdirSync(`${directory}src`)
    .filter(file => file.endsWith('.js'));

  jsFiles.forEach((file) => {
    // Do whatever you want to do with the file
    config.push({
      input: `${directory}src/${file}`,
      external: ['Drupal', 'jQuery', 'drupalSettings'],
      plugins: [
        // Resolve bare module specifiers to relative paths
        resolve(),
      ],
      context: 'window',
      output: [
        {
          file: `${directory}${file}`,
          format: 'iife',
          globals: {
            Drupal: 'Drupal',
            drupalSettings: 'drupalSettings',
            jQuery: '$',
          },
        },
        {
          file: `${directory}${file.replace(/\.js$/, '.min.js')}`,
          format: 'iife',
          plugins: [
            // Minify
            terser(),
          ],
          globals: {
            Drupal: 'Drupal',
            drupalSettings: 'drupalSettings',
            jQuery: '$',
          },
        },
      ],
    });
  });
});

export default config;
