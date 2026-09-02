( function () {
	'use strict';

	if ( typeof updateZombiePluginStatuses !== 'object' || ! updateZombiePluginStatuses ) {
		return;
	}

	function addStatus( target, pluginFile ) {
		var status = updateZombiePluginStatuses[ pluginFile ];

		if ( ! status || ! target ) {
			return;
		}

		target = target.querySelector( '.plugin-title strong' );

		if ( ! target || target.parentNode.querySelector( '.uz-plugin-title-status' ) ) {
			return;
		}

		var badge = document.createElement( status.url ? 'a' : 'span' );
		badge.className = 'uz-badge uz-badge-' + status.class;

		if ( status.url ) {
			badge.href = status.url;
		}

		badge.appendChild( document.createTextNode( status.label ) );
		badge.setAttribute( 'aria-label', 'Update Zombie: ' + status.label );

		badge.className += ' uz-plugin-title-status';
		target.insertAdjacentElement( 'afterend', badge );
	}

	document.querySelectorAll( '#update-plugins-table input[name="checked[]"]' ).forEach( function ( checkbox ) {
		addStatus( checkbox.closest( 'tr' ), checkbox.value );
	} );

	document.querySelectorAll( '#the-list tr[data-plugin]' ).forEach( function ( row ) {
		addStatus( row, row.getAttribute( 'data-plugin' ) );
	} );
}() );
