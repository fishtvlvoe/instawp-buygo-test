// Quick Edit Methods for BuyGo Products Component
// Add these methods to the BuyGo Products Vue component

// Method 1: Quick update stock (+/- buttons)
async function quickUpdateStock( product, delta ) {
	const newStock = Math.max( 0, product.total_stock + delta );
	
	try {
		const response = await fetch(
			`${window.buygoFrontendPortalData?.apiUrl || '/wp-json/buygo/v1/portal'}/products/${product.id}`,
			{
				method: 'PATCH',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': window.buygoFrontendPortalData?.nonce || '',
				},
				body: JSON.stringify({
					stock: newStock,
				}),
			}
		);

		if ( response.ok ) {
			const result = await response.json();
			if ( result.success ) {
				// Update local data
				product.total_stock = newStock;
				product.available_stock = Math.max( 0, newStock - product.ordered_count );
				product.stock = product.available_stock;
				// Close editing mode after a short delay
				setTimeout(() => {
					this.editingStock = null;
				}, 1000);
			}
		}
	} catch ( error ) {
		console.error( 'Failed to update stock:', error );
	}
}

// Method 2: Quick update procurement status (dropdown)
async function quickUpdateProcurementStatus( product ) {
	try {
		const response = await fetch(
			`${window.buygoFrontendPortalData?.apiUrl || '/wp-json/buygo/v1/portal'}/products/${product.id}`,
			{
				method: 'PATCH',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': window.buygoFrontendPortalData?.nonce || '',
				},
				body: JSON.stringify({
					procurement_status: product.procurement_status,
				}),
			}
		);

		if ( !response.ok ) {
			console.error( 'Failed to update procurement status' );
		}
	} catch ( error ) {
		console.error( 'Failed to update procurement status:', error );
	}
}

// Data property to add:
// editingStock: null, // Track which product is being edited (product ID)
