(function () {
    const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
    const { getSetting } = window.wc.wcSettings;
    const { createElement: el, useState, useEffect } = window.wp.element;
    const { decodeEntities } = window.wp.htmlEntities;
    const { __, _n, sprintf } = window.wp.i18n;

    const data = getSetting('toloka_chast_data', {});
    const title = decodeEntities(data.title || __('monobank installments', 'toloka-monobank'));
    const parts = data.parts || [];

    const label = (n, total, currency) => {
        if (!total) {
            return sprintf(_n('%d payment', '%d payments', n, 'toloka-monobank'), n);
        }
        const [int, dec] = (total / n / 10 ** currency.minorUnit).toFixed(currency.minorUnit).split('.');
        const amount = int.replace(/\B(?=(\d{3})+(?!\d))/g, currency.thousandSeparator) + (dec ? currency.decimalSeparator + dec : '');
        const one = currency.prefix + amount + currency.suffix;
        return sprintf(_n('%1$d payment of ~%2$s', '%1$d payments of ~%2$s', n, 'toloka-monobank'), n, one);
    };

    const Content = ({ eventRegistration, emitResponse, billing }) => {
        const [count, setCount] = useState(parts[0]);
        const { onPaymentSetup } = eventRegistration;

        useEffect(() => onPaymentSetup(() => ({
            type: emitResponse.responseTypes.SUCCESS,
            meta: { paymentMethodData: { toloka_chast_parts: String(count) } },
        })), [onPaymentSetup, count]);

        const total = parseInt(billing.cartTotal.value, 10);
        return el('div', null,
            data.description && el('p', null, decodeEntities(data.description)),
            el('label', { htmlFor: 'toloka_chast_parts' }, __('Number of payments', 'toloka-monobank')),
            el('select', {
                id: 'toloka_chast_parts',
                value: count,
                onChange: (e) => setCount(parseInt(e.target.value, 10)),
                style: { display: 'block', width: '100%', marginTop: '4px' },
            }, parts.map((n) => el('option', { key: n, value: n }, label(n, total, billing.currency))))
        );
    };

    registerPaymentMethod({
        name: 'toloka_chast',
        label: el('span', null, title),
        ariaLabel: title,
        content: el(Content),
        edit: el(Content),
        canMakePayment: ({ cartTotals }) => {
            const total = parseInt(cartTotals.total_price, 10) / 10 ** cartTotals.currency_minor_unit;
            return parts.length > 0 && cartTotals.currency_code === 'UAH'
                && !(data.min && total < data.min) && !(data.max && total > data.max);
        },
        supports: { features: data.supports || ['products'] },
    });
})();
