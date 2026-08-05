(($, Drupal, once) => {
  function initColorSpectrum(context, settings) {
    once('option-plugin-color-spectrum', '[data-color-spectrum-id]', context).forEach((e) => {
        const $e = $(e);
        const id = $(e).attr('data-color-spectrum-id');
        const options = $.extend({}, settings[id] || {}, {
          preferredFormat: 'rgb',
        });
        if (options.palette) {
          options.showPalette = true;
        }
        $e.spectrum(options);
      });
  }
  Drupal.behaviors.optionPluginColorSpectrum = {
    attach: function attach(context, settings) {
      setTimeout(() => {
        initColorSpectrum(context, settings);
      }, 500);
    },
  };
})(jQuery, Drupal, once);
