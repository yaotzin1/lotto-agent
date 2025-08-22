const Encore = require('@symfony/webpack-encore');

Encore
  .setOutputPath('public/build/')
  .setPublicPath('/build')
  .addEntry('app', './assets/app.tsx')
  .enableSingleRuntimeChunk()
  .enableTypeScriptLoader()
  .enableReactPreset()
  .enableSourceMaps(!Encore.isProduction())
  .cleanupOutputBeforeBuild()
  .enableVersioning(Encore.isProduction());

module.exports = Encore.getWebpackConfig();
