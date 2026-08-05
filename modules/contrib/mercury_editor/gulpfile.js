const gulp = require('gulp');
const postcss = require('gulp-postcss');
const path = require('path');

const isWatching = process.argv.some((arg) => arg.trim() === '--gulp-watch');
const inputs = ['source/**/*.css'];
const componentInputs = ['components/**/src/*.css'];

const cssTask = () => {
  return gulp
    .src(inputs)
    .pipe(postcss())
    .pipe(
      gulp.dest((fileVinyl) => {
        fileVinyl.base = fileVinyl.path.replace(/[^/]*js$/, '');
        return path.relative(
          process.cwd(),
          fileVinyl.path.replace('/source/', '/build/').replace(/[^/]*js$/, ''),
        );
      }),
    );
};

const componentCssTask = () => {
  return gulp
    .src(componentInputs)
    .pipe(postcss())
    .pipe(
      gulp.dest((fileVinyl) => {
        fileVinyl.base = fileVinyl.path.replace(/[^/]*js$/, '');
        return path.relative(
          process.cwd(),
          fileVinyl.path.replace('/src', ''),
        );
      })
    );
};

const cssWatch = () => gulp.watch(inputs, cssTask);
const componentCssWatch = () => gulp.watch(componentInputs, componentCssTask);

gulp.task('css', isWatching ? cssWatch : cssTask);
gulp.task('component-css', isWatching ? componentCssWatch : componentCssTask);
