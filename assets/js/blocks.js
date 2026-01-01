const maib_checkout_settings = window.wc.wcSettings.getSetting('maib_checkout_data', {});
const maib_checkout_title = window.wp.htmlEntities.decodeEntities(maib_checkout_settings.title);

const maib_checkout_content = () => {
    return window.wp.htmlEntities.decodeEntities(maib_checkout_settings.description || '');
};

const maib_checkout_label = () => {
    let icon = maib_checkout_settings.icon
        ? window.wp.element.createElement(
            'img',
            {
                alt: maib_checkout_title,
                title: maib_checkout_title,
                src: maib_checkout_settings.icon,
                style: { float: 'right', paddingRight: '1em' }
            }
        )
        : null;

    let label = window.wp.element.createElement(
        'span',
        icon ? { style: { width: '100%' } } : null,
        maib_checkout_title,
        icon
    );

    return label;
};

const maib_checkout_blockGateway = {
    name: maib_checkout_settings.id,
    label: window.wp.element.createElement(maib_checkout_label, null),
    icons: [{id: 'maib_checkout', alt: maib_checkout_settings.title, src: maib_checkout_settings.icon}],
    content: window.wp.element.createElement(maib_checkout_content, null),
    edit: window.wp.element.createElement(maib_checkout_content, null),
    canMakePayment: () => true,
    ariaLabel: maib_checkout_title,
    supports: {
        features: maib_checkout_settings.supports,
    },
};

window.wc.wcBlocksRegistry.registerPaymentMethod(maib_checkout_blockGateway);
