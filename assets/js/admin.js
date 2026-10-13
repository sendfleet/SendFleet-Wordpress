/* SendFleet admin — vanilla JS, no dependencies. */
( function () {
	'use strict';

	var i18n = window.sendfleetAdmin || { copied: 'Copied', show: 'Show', hide: 'Hide', confirm: 'Are you sure?' };

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;

		if ( ! ( target instanceof Element ) ) {
			return;
		}

		// Show / hide secret inputs.
		var reveal = target.closest( '.sf-reveal' );
		if ( reveal ) {
			var input = document.getElementById( reveal.getAttribute( 'data-target' ) );
			if ( input ) {
				var hidden = 'password' === input.type;
				input.type = hidden ? 'text' : 'password';
				reveal.textContent = hidden ? i18n.hide : i18n.show;
			}
			return;
		}

		// Copy a field's value.
		var copy = target.closest( '.sf-copy' );
		if ( copy ) {
			var field = document.getElementById( copy.getAttribute( 'data-target' ) );
			if ( field ) {
				var original = copy.textContent;
				var done = function () {
					copy.textContent = i18n.copied;
					window.setTimeout( function () {
						copy.textContent = original;
					}, 1500 );
				};
				if ( navigator.clipboard && window.isSecureContext ) {
					navigator.clipboard.writeText( field.value ).then( done );
				} else {
					field.select();
					document.execCommand( 'copy' );
					done();
				}
			}
			return;
		}

		// Confirm destructive actions.
		var confirmable = target.closest( '[data-sf-confirm]' );
		if ( confirmable && ! window.confirm( confirmable.getAttribute( 'data-sf-confirm' ) || i18n.confirm ) ) {
			event.preventDefault();
		}
	} );
}() );
