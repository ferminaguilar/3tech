/* gulpfile.js */

const uswds = require('@uswds/compile');

/**
 * USWDS version
 */
uswds.settings.version = 3;

/**
 * Path settings
 */
uswds.paths.dist.css = './css';
uswds.paths.dist.theme = './sass';
uswds.paths.dist.img = './assets/img';
uswds.paths.dist.fonts = './assets/fonts';
uswds.paths.dist.js = './assets/js';

/**
 * Exports
 */
exports.init = uswds.init;
exports.compile = uswds.compile;
exports.watch = uswds.watch;
exports.default = uswds.watch;

