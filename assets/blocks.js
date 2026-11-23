( function () {
	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { getSetting } = window.wc.wcSettings;
	const { createElement } = window.wp.element;
	const { decodeEntities } = window.wp.htmlEntities;
	const data = window.wp.data;

	const methods = [ 'amboss', 'amboss_cashapp' ]
		.map( ( name ) => ( { name, settings: getSetting( name + '_data', null ) } ) )
		.filter( ( method ) => method.settings );

	methods.forEach( ( { name, settings } ) => {
		const label = decodeEntities( settings.title );
		const Description = () => decodeEntities( settings.description || '' );

		registerPaymentMethod( {
			name,
			label,
			ariaLabel: label,
			content: createElement( Description ),
			edit: createElement( Description ),
			canMakePayment: () => true,
			supports: { features: [ 'products' ] },
		} );
	} );

	// The block checkout does not recalculate totals when the payment method
	// changes, so tell the server, which adds or removes the Amboss discount.
	if ( ! methods.some( ( method ) => method.settings.has_discount ) ) {
		return;
	}
	const { extensionCartUpdate } = window.wc.blocksCheckout;
	let lastPaymentMethod = null;
	data.subscribe( () => {
		const paymentMethod = data.select( 'wc/store/payment' ).getActivePaymentMethod();
		if ( ! paymentMethod || paymentMethod === lastPaymentMethod ) {
			return;
		}
		lastPaymentMethod = paymentMethod;
		extensionCartUpdate( { namespace: 'amboss-payments', data: { payment_method: paymentMethod } } );
	}, 'wc/store/payment' );
} )();
