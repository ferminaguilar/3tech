// rollup.config.js
import resolve from '@rollup/plugin-node-resolve';
import { terser } from 'rollup-plugin-terser';
import fs from 'fs';

const config = [];

const sourceDirectories = ['./'];

sourceDirectories.forEach((directory) => {
  const sourceJsPath = `${directory}source/js`;
  const jsFiles = fs
    .readdirSync(sourceJsPath)
    .filter((file) => fs.statSync(`${sourceJsPath}/${file}`).isFile())
    .filter((file) => file.endsWith('.js'));

  jsFiles.forEach((file) => {
    const globalName = file.replace(/\.js$/, '').replace(/[^a-zA-Z0-9]/g, '_');
    // Do whatever you want to do with the file
    config.push({
      input: `${directory}source/js/${file}`,
      external: ['Drupal', 'jQuery', 'drupalSettings'],
      plugins: [
        // Resolve bare module specifiers to relative paths
        resolve(),
      ],
      context: 'window',
      output: [
        {
          file: `${directory}build/js/${file}`,
          format: 'iife',
          name: globalName,
          globals: {
            Drupal: 'Drupal',
            drupalSettings: 'drupalSettings',
            jQuery: '$',
          },
        },
        {
          file: `${directory}build/js/${file.replace(/\.js$/, '.min.js')}`,
          format: 'iife',
          name: globalName,
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
