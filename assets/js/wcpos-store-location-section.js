(function () {
  function registerSection() {
    if (!window.wcpos || !window.wcpos.storeEdit || typeof window.wcpos.storeEdit.registerSection !== 'function') {
      setTimeout(registerSection, 40);
      return;
    }

    if (window.wcpos.storeEdit.getSections && window.wcpos.storeEdit.getSections().has('mulopimfwc-location-inventory')) {
      return;
    }

    var el = window.wp && window.wp.element ? window.wp.element.createElement : null;
    if (!el) {
      return;
    }

    var config = window.mulopimfwcWcposStoreEdit || {};
    var locations = Array.isArray(config.locations) ? config.locations : [];
    var strings = config.strings || {};
    var selectClass = 'wcpos:block wcpos:w-full wcpos:rounded-md wcpos:border wcpos:border-gray-300 wcpos:px-2.5 wcpos:py-1.5 wcpos:text-sm wcpos:shadow-xs wcpos:focus:outline-none wcpos:focus:ring-2 wcpos:focus:ring-wp-admin-theme-color wcpos:focus:border-wp-admin-theme-color';

    function StoreLocationSection(props) {
      var locationValue = props.store.mulopimfwc_location_id || '';
      var pricingValue = props.store.mulopimfwc_pricing_source || 'mulopimfwc';
      var hasLocation = locationValue && locationValue !== '0';

      return el('div', { className: 'wcpos:border-b wcpos:border-gray-200 wcpos:pb-6 wcpos:space-y-6' },
        el('div', { className: 'wcpos:mb-4' },
          el('h3', { className: 'wcpos:text-base wcpos:font-semibold wcpos:text-gray-900 wcpos:m-0' },
            strings.sectionLabel || 'Multi Location Inventory'
          )
        ),

        el('div', null,
          el('label', { className: 'wcpos:block wcpos:text-sm wcpos:font-medium wcpos:text-gray-700 wcpos:mb-1' },
            strings.locationTitle || 'Inventory location'
          ),
          el('p', { className: 'wcpos:text-xs wcpos:text-gray-500 wcpos:mb-2' },
            strings.locationDescription || ''
          ),
          locations.length
            ? el('select', {
                className: selectClass,
                value: locationValue,
                onChange: function (event) {
                  props.onChange('mulopimfwc_location_id', event.target.value);
                }
              },
              el('option', { value: '' }, strings.locationDefault || 'No location'),
              locations.map(function (location) {
                return el('option', { key: location.value, value: location.value }, location.label);
              })
            )
            : el('p', { className: 'wcpos:text-sm wcpos:text-gray-500' }, strings.noLocations || 'No locations found.')
        ),

        hasLocation ? el('div', null,
          el('label', { className: 'wcpos:block wcpos:text-sm wcpos:font-medium wcpos:text-gray-700 wcpos:mb-1' },
            strings.pricingTitle || 'Pricing source'
          ),
          el('p', { className: 'wcpos:text-xs wcpos:text-gray-500 wcpos:mb-2' },
            strings.pricingDescription || ''
          ),
          el('select', {
              className: selectClass,
              value: pricingValue,
              onChange: function (event) {
                props.onChange('mulopimfwc_pricing_source', event.target.value);
              }
            },
            el('option', { value: 'mulopimfwc' }, strings.pricingLocation || 'Multi Location price'),
            el('option', { value: 'default' }, strings.pricingDefault || 'WCPOS/WooCommerce default')
          )
        ) : null
      );
    }

    window.wcpos.storeEdit.registerSection('mulopimfwc-location-inventory', {
      component: StoreLocationSection,
      label: strings.sectionLabel || 'Multi Location Inventory',
      column: 'sidebar',
      priority: 35
    });
  }

  registerSection();
})();
