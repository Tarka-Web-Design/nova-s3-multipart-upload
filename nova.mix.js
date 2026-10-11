// Nova 5 puts Vue and its own packages on window, so a tool's bundle uses
// those rather than carrying copies (the same as laravel/nova-devtool's
// nova.mix.js, kept here so a build needs nothing from a Nova install).
class NovaExtension {
  name() {
    return 'nova-extension'
  }

  register(name) {
    this.name = name
  }

  webpackConfig(webpackConfig) {
    webpackConfig.externals = {
      vue: 'Vue',
      'laravel-nova': 'LaravelNova',
      'laravel-nova-ui': 'LaravelNovaUi',
      'laravel-nova-util': 'LaravelNovaUtil',
    }

    webpackConfig.output = {
      uniqueName: this.name,
    }
  }
}

module.exports = NovaExtension
