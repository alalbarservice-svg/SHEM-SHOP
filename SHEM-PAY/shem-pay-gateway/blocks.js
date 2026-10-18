(function () {
  var reg = window.wc && window.wc.wcBlocksRegistry, settings = window.wc && window.wc.wcSettings;
  if (!reg || !settings) return;
  var d = settings.getSetting('shem_via_shimi_data', {}), el = window.wp.element.createElement;
  var label = function () { return el('span', null, d.title || 'PayPal'); };
  reg.registerPaymentMethod({
    name: 'shem_via_shimi',
    label: el(label),
    content: el('div', null, d.description || ''),
    edit: el('div', null, d.title || 'PayPal'),
    canMakePayment: function () { return true; },
    ariaLabel: d.title || 'PayPal',
    supports: { features: d.supports || ['products'] }
  });
})();
