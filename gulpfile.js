const {dest, task, watch} = require('gulp'),
  browserify = require('browserify'),
  buffer = require('vinyl-buffer'),
  source = require('vinyl-source-stream'),
  uglify = require('gulp-uglify');

function js() {
  return browserify({entries: ['assets/scripts/modal-controls.js']})
    .transform('babelify', {presets: ['@babel/preset-env']})
    .bundle()
    .pipe(source('modal-controls.min.js'))
    .pipe(buffer())
    .pipe(uglify())
    .pipe(dest('assets'));
}

function watcher() {
  js();
  watch(['assets/scripts/**/*.js'], js);
}

task('default', js);
task('watch', watcher);
