let mix = require('laravel-mix')
let NovaExtension = require('./nova.mix')

mix.extend('nova', new NovaExtension())

mix
  .setPublicPath('dist')
  .js('resources/js/tool.js', 'js')
  .vue({ version: 3 })
    .sass('resources/sass/tool.scss', 'css', {}, [
        require('autoprefixer'),
        require('postcss-logical'),
        require('postcss-dir-pseudo-class'),
    ])
  .nova('ahmedkandel/nova-s3-multipart-upload')
  .disableNotifications();
