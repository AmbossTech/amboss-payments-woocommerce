( function () {
	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { getSetting } = window.wc.wcSettings;
	const { createElement } = window.wp.element;
	const { decodeEntities } = window.wp.htmlEntities;

	const settings = getSetting( 'amboss_data', {} );
	const label = decodeEntities( settings.title || 'Pay with Bitcoin' );
	const Description = () => decodeEntities( settings.description || '' );

	registerPaymentMethod( {
		name: 'amboss',
		label,
		ariaLabel: label,
		content: createElement( Description ),
		edit: createElement( Description ),
		canMakePayment: () => true,
		supports: { features: [ 'products' ] },
	} );
} )();
