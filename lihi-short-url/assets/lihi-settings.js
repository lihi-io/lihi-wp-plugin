document.addEventListener( 'DOMContentLoaded', () => {
	bindAuthTabs();
	bindPasswordToggles();
	bindLoginForm();
	bindRegisterForm();
	bindLogout();
	bindWorkGroupDialog();
	bindDashboardPassthrough();
} );

function bindAuthTabs() {
	const tabs = [ ...document.querySelectorAll( '[data-lihi-auth-tab]' ) ];
	if ( tabs.length !== 2 ) return;

	tabs.forEach( ( tab, index ) => {
		tab.addEventListener( 'click', ( event ) => {
			event.preventDefault();
			activateAuthTab( tab.dataset.lihiAuthTab, true );
			updateAuthTabUrl( tab.href );
		} );

		tab.addEventListener( 'keydown', ( event ) => {
			let nextIndex = null;
			if ( event.key === 'ArrowRight' || event.key === 'ArrowDown' ) {
				nextIndex = ( index + 1 ) % tabs.length;
			} else if ( event.key === 'ArrowLeft' || event.key === 'ArrowUp' ) {
				nextIndex = ( index - 1 + tabs.length ) % tabs.length;
			} else if ( event.key === 'Home' ) {
				nextIndex = 0;
			} else if ( event.key === 'End' ) {
				nextIndex = tabs.length - 1;
			}

			if ( nextIndex === null ) return;
			event.preventDefault();
			const nextTab = tabs[ nextIndex ];
			activateAuthTab( nextTab.dataset.lihiAuthTab, true );
			updateAuthTabUrl( nextTab.href );
		} );
	} );
}

function activateAuthTab( tabName, shouldFocus = false ) {
	if ( tabName !== 'login' && tabName !== 'register' ) return;

	document.querySelectorAll( '[data-lihi-auth-tab]' ).forEach( ( tab ) => {
		const selected = tab.dataset.lihiAuthTab === tabName;
		tab.classList.toggle( 'is-active', selected );
		tab.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
		tab.tabIndex = selected ? 0 : -1;
		if ( selected && shouldFocus ) tab.focus();
	} );

	[ 'login', 'register' ].forEach( ( name ) => {
		const panel = document.getElementById( `lihi-${ name }-panel` );
		if ( panel ) panel.hidden = name !== tabName;
	} );
}

function updateAuthTabUrl( href ) {
	if ( ! window.history?.replaceState || ! href ) return;

	try {
		const url = new URL( href, window.location.href );
		window.history.replaceState( null, '', url.toString() );
	} catch {
		// Tab switching still works when URL parsing is unavailable.
	}
}

function bindLoginForm() {
	bindAuthForm( {
		formId: 'lihi-login-form',
		statusId: 'lihi-login-status',
		config: lihiSettings.login,
		payload: ( form ) => ( {
			email: form.querySelector( '#lihi_login_email' )?.value ?? '',
			password: form.querySelector( '#lihi_login_password' )?.value ?? '',
		} ),
		validate: ( form ) => {
			const email = form.querySelector( '#lihi_login_email' );
			const password = form.querySelector( '#lihi_login_password' );
			if ( ! email?.value.trim() || ! email.validity.valid ) {
				return {
					field: email,
					message: lihiSettings.login?.emailRequired ||
						'Please enter a valid email address.',
				};
			}
			if ( ! password?.value.trim() ) {
				return {
					field: password,
					message: lihiSettings.login?.passwordRequired ||
						'Please enter the lihi account password.',
				};
			}
			return null;
		},
		onSuccess: ( data, form ) => {
			const password = form.querySelector( '#lihi_login_password' );
			if ( password ) password.value = '';
			if ( data.authenticated ) {
				setTimeout( () => window.location.reload(), 2000 );
				return true;
			}
			return false;
		},
	} );
}

function registerPasswordHasMinimumLength( password ) {
	return [ ...password ].length >= 6;
}

function bindRegisterForm() {
	bindAuthForm( {
		formId: 'lihi-register-form',
		statusId: 'lihi-register-status',
		config: lihiSettings.register,
		payload: ( form ) => ( {
			email: form.querySelector( '#lihi_register_email' )?.value ?? '',
			password: form.querySelector( '#lihi_register_password' )?.value ?? '',
			create_account_consent: form.querySelector( '#lihi_register_consent' )?.checked ? '1' : '0',
		} ),
		validate: ( form ) => {
			const email = form.querySelector( '#lihi_register_email' );
			const password = form.querySelector( '#lihi_register_password' );
			const consent = form.querySelector( '#lihi_register_consent' );
			if ( ! email?.value.trim() || ! email.validity.valid ) {
				return {
					field: email,
					message: lihiSettings.register?.emailRequired ||
						'Please enter a valid email address.',
				};
			}
			if ( ! password || ! registerPasswordHasMinimumLength( password.value ) ) {
				return {
					field: password,
					message: lihiSettings.register?.passwordRequired ||
						'Please enter a password with at least 6 characters.',
				};
			}
			if ( ! consent?.checked ) {
				return {
					field: consent,
					message: lihiSettings.register?.consentRequired ||
						'Please confirm that lihi may use this email and password to create an account.',
				};
			}
			return null;
		},
		onSuccess: ( data, form ) => {
			const password = form.querySelector( '#lihi_register_password' );
			const consent = form.querySelector( '#lihi_register_consent' );
			const loginEmail = document.getElementById( 'lihi_login_email' );
			if ( password ) password.value = '';
			if ( consent ) consent.checked = false;
			if ( loginEmail && data.email ) {
				loginEmail.value = data.email;
			}
			activateAuthTab( 'login', true );
			updateAuthTabUrl( document.getElementById( 'lihi-login-tab' )?.href );
			return false;
		},
	} );
}

function bindWorkGroupDialog() {
	const trigger = document.getElementById( 'lihi-switch-work-group' );
	const root = document.getElementById( 'lihi-work-group-modal' );
	const dialog = root?.querySelector( '[role="dialog"]' );
	const form = document.getElementById( 'lihi-work-group-form' );
	const select = document.getElementById( 'lihi-work-group-select' );
	const submit = document.getElementById( 'lihi-work-group-submit' );
	const status = document.getElementById( 'lihi-work-group-status' );
	const accountStatus = document.getElementById( 'lihi-account-status' );
	const currentLabel = document.getElementById( 'lihi-current-work-group' );
	const config = lihiSettings.workGroup;
	if (
		! trigger ||
		! root ||
		! dialog ||
		! form ||
		! select ||
		! submit ||
		! status ||
		! accountStatus ||
		! currentLabel ||
		! config
	) return;

	let currentValue = '';
	let previousFocus = trigger;
	let switching = false;

	const closeDialog = () => {
		if ( switching ) return;
		root.hidden = true;
		document.body.classList.remove( 'lihi-work-group-modal-open' );
		previousFocus?.focus();
	};

	const updateSubmitState = () => {
		submit.disabled = select.disabled || select.value === currentValue || switching;
	};

	const renderLoadingOption = () => {
		const option = document.createElement( 'option' );
		option.value = '';
		option.textContent = config.loading || 'Loading work groups…';
		option.selected = true;
		select.replaceChildren( option );
		select.disabled = true;
		updateSubmitState();
	};

	const renderOptions = ( groups, groupId ) => {
		if ( ! Array.isArray( groups ) || groups.length === 0 ) {
			throw new Error( config.noOptions || 'No work groups are available.' );
		}

		const options = groups.map( ( group ) => {
			const id = group?.id;
			if ( id !== null && ( ! Number.isInteger( id ) || id <= 0 ) ) {
				throw new Error( config.loadFailed || 'Could not load work groups.' );
			}

			const option = document.createElement( 'option' );
			option.value = id === null ? '' : String( id );
			if ( id === null ) {
				option.textContent = config.personalLabel || 'My Work Group';
			} else {
				const name = typeof group.name === 'string' ? group.name.trim() : '';
				option.textContent = name ||
					`${ config.unnamedLabel || 'Unnamed Work Group' } #${ id }`;
			}
			return option;
		} );

		currentValue = groupId === null ? '' : String( groupId );
		if ( ! options.some( ( option ) => option.value === currentValue ) ) {
			throw new Error( config.loadFailed || 'Could not load work groups.' );
		}

		select.replaceChildren( ...options );
		select.value = currentValue;
		select.disabled = false;
		updateSubmitState();
		select.focus();
	};

	const openDialog = async () => {
		previousFocus = document.activeElement || trigger;
		root.hidden = false;
		document.body.classList.add( 'lihi-work-group-modal-open' );
		clearStatus( status );
		renderLoadingOption();
		dialog.focus();

		try {
			const data = await postAjax( {
				action: config.optionsAction,
				nonce: config.optionsNonce,
			} );
			if ( ! data.success ) {
				throw new Error( errorMessage( data ) );
			}

			renderOptions( data.data?.groups, data.data?.group_id ?? null );
		} catch ( error ) {
			select.disabled = true;
			updateSubmitState();
			renderStatus( status, 'error', exceptionMessage( error ) );
		}
	};

	trigger.addEventListener( 'click', openDialog );
	select.addEventListener( 'change', updateSubmitState );

	root.querySelectorAll( '[data-lihi-work-group-close]' ).forEach( ( close ) => {
		close.addEventListener( 'click', closeDialog );
	} );

	root.addEventListener( 'keydown', ( event ) => {
		if ( event.key === 'Escape' ) {
			event.preventDefault();
			closeDialog();
			return;
		}
		if ( event.key !== 'Tab' ) return;

		const focusable = [ ...dialog.querySelectorAll(
			'button:not([disabled]), select:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'
		) ];
		if ( focusable.length === 0 ) {
			event.preventDefault();
			dialog.focus();
			return;
		}

		const first = focusable[ 0 ];
		const last = focusable[ focusable.length - 1 ];
		if ( event.shiftKey && document.activeElement === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	} );

	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		if ( select.disabled || select.value === currentValue || switching ) return;

		switching = true;
		select.disabled = true;
		submit.disabled = true;
		form.setAttribute( 'aria-busy', 'true' );
		clearStatus( status );

		const requestedValue = select.value;
		const selectedOption = select.selectedOptions[ 0 ];
		try {
			const data = await postAjax( {
				action: config.switchAction,
				nonce: config.switchNonce,
				group_id: requestedValue,
			} );
			if ( ! data.success ) {
				throw new Error( errorMessage( data ) );
			}

			if (
				! data.data ||
				! Object.prototype.hasOwnProperty.call( data.data, 'group_id' )
			) {
				throw new Error( fallbackMessage() );
			}
			const returnedValue = data.data?.group_id === null
				? ''
				: String( data.data.group_id );
			if ( returnedValue !== requestedValue ) {
				throw new Error( fallbackMessage() );
			}

			currentValue = returnedValue;
			currentLabel.textContent = selectedOption?.textContent ||
				config.personalLabel ||
				'My Work Group';
			switching = false;
			form.removeAttribute( 'aria-busy' );
			closeDialog();
			clearStatus( accountStatus );
			renderStatus(
				accountStatus,
				'success',
				data.data?.message || config.switched || 'Work group switched.'
			);
			setTimeout( () => window.location.reload(), 2000 );
		} catch ( error ) {
			switching = false;
			select.disabled = false;
			form.removeAttribute( 'aria-busy' );
			updateSubmitState();
			renderStatus( status, 'error', exceptionMessage( error ) );
		}
	} );
}

function bindLogout() {
	const button = document.getElementById( 'lihi-logout' );
	const status = document.getElementById( 'lihi-account-status' );
	if ( ! button || ! status || ! lihiSettings.logout ) return;

	button.addEventListener( 'click', async () => {
		clearStatus( status );
		button.disabled = true;
		button.setAttribute( 'aria-busy', 'true' );
		let reloadPending = false;

		try {
			const data = await postAjax( {
				action: lihiSettings.logout.action,
				nonce: lihiSettings.logout.nonce,
			} );
			if ( data.success ) {
				renderStatus( status, 'success', data.data?.message || fallbackMessage() );
				reloadPending = true;
				setTimeout( () => window.location.reload(), 2000 );
			} else {
				renderStatus( status, 'error', errorMessage( data ) );
			}
		} catch ( error ) {
			renderStatus( status, 'error', exceptionMessage( error ) );
		} finally {
			if ( ! reloadPending ) {
				button.disabled = false;
				button.removeAttribute( 'aria-busy' );
			}
		}
	} );
}

function bindPasswordToggles() {
	document.querySelectorAll( '[data-lihi-password-toggle]' ).forEach( ( button ) => {
		const inputId = button.getAttribute( 'aria-controls' );
		const input = inputId ? document.getElementById( inputId ) : null;
		const icon = button.querySelector( '.dashicons' );
		if ( ! input || ! icon ) return;

		button.addEventListener( 'click', () => {
			const shouldShow = input.type === 'password';
			input.type = shouldShow ? 'text' : 'password';
			button.setAttribute( 'aria-pressed', shouldShow ? 'true' : 'false' );

			const label = shouldShow
				? ( lihiSettings.hidePassword || 'Hide password' )
				: ( lihiSettings.showPassword || 'Show password' );
			button.setAttribute( 'aria-label', label );
			button.title = label;
			icon.classList.toggle( 'dashicons-visibility', ! shouldShow );
			icon.classList.toggle( 'dashicons-hidden', shouldShow );
		} );
	} );
}

function fallbackMessage() {
	return lihiSettings.requestFailed || 'Request failed. Please try again later.';
}

function errorMessage( data ) {
	if ( typeof data?.data === 'string' ) return data.data;
	if ( typeof data?.data?.message === 'string' ) return data.data.message;
	return fallbackMessage();
}

function errorAction( data ) {
	if ( data?.data?.code !== 'email_or_password_invalid' ) return null;

	const url = data.data.password_reset_url || lihiSettings.passwordResetUrl || '';
	if ( ! url ) return null;

	return {
		label: lihiSettings.forgotPassword || 'Forgot password?',
		url,
	};
}

function exceptionMessage( error ) {
	return error?.message || fallbackMessage();
}

function clearStatus( status ) {
	status.classList.remove( 'notice', 'notice-error', 'notice-success', 'notice-warning', 'inline' );
	status.replaceChildren();
}

function renderStatus( status, type, message, action = null ) {
	status.classList.add( 'notice', 'notice-' + type, 'inline' );
	const p = document.createElement( 'p' );
	p.textContent = message;
	if ( action?.url && action?.label ) {
		const link = document.createElement( 'a' );
		link.className = 'lihi-settings-notice-link';
		link.href = action.url;
		link.target = '_blank';
		link.rel = 'noopener noreferrer';
		link.textContent = action.label;
		p.append( ' ', link );
	}
	status.replaceChildren( p );
}

function clearFieldValidation( form, status ) {
	form.querySelectorAll( '[aria-invalid="true"]' ).forEach( ( field ) => {
		field.removeAttribute( 'aria-invalid' );
		const describedBy = ( field.getAttribute( 'aria-describedby' ) || '' )
			.split( /\s+/ )
			.filter( ( id ) => id && id !== status.id );
		if ( describedBy.length ) {
			field.setAttribute( 'aria-describedby', describedBy.join( ' ' ) );
		} else {
			field.removeAttribute( 'aria-describedby' );
		}
	} );
}

function renderFieldError( status, error ) {
	renderStatus( status, 'error', error.message );
	if ( ! error.field ) return;

	error.field.setAttribute( 'aria-invalid', 'true' );
	const describedBy = new Set(
		( error.field.getAttribute( 'aria-describedby' ) || '' )
			.split( /\s+/ )
			.filter( Boolean )
	);
	describedBy.add( status.id );
	error.field.setAttribute( 'aria-describedby', [ ...describedBy ].join( ' ' ) );
	error.field.focus();
}

async function postAjax( params ) {
	const res = await fetch( lihiSettings.ajaxUrl, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: new URLSearchParams( params ),
	} );
	const responseText = await res.text();

	try {
		const data = JSON.parse( responseText );
		if ( data && typeof data === 'object' && ! Array.isArray( data ) ) {
			return data;
		}
	} catch {
		// WordPress may return "0" or HTML for a broken AJAX request.
	}

	throw new Error( fallbackMessage() );
}

function base64UrlEncode( bytes ) {
	let binary = '';
	bytes.forEach( ( byte ) => {
		binary += String.fromCharCode( byte );
	} );

	return window.btoa( binary )
		.replace( /\+/g, '-' )
		.replace( /\//g, '_' )
		.replace( /=+$/g, '' );
}

async function createPassthroughProof() {
	if ( ! window.crypto?.getRandomValues || ! window.crypto?.subtle || ! window.TextEncoder ) {
		throw new Error(
			lihiSettings.dashboard?.proofUnavailable ||
			'Your browser does not support secure lihi dashboard login.'
		);
	}

	const bytes = new Uint8Array( 32 );
	window.crypto.getRandomValues( bytes );

	const verifier = base64UrlEncode( bytes );
	const digest = await window.crypto.subtle.digest(
		'SHA-256',
		new window.TextEncoder().encode( verifier )
	);

	return {
		challenge: base64UrlEncode( new Uint8Array( digest ) ),
		verifier,
	};
}

function buildPassthroughRedirectUrl( redirectUrl, nonce, verifier ) {
	const separator = redirectUrl.includes( '?' ) ? '&' : '?';
	return redirectUrl + separator +
		'nonce=' + encodeURIComponent( nonce ) +
		'&verifier=' + encodeURIComponent( verifier );
}

function bindDashboardPassthrough() {
	const button = document.getElementById( 'lihi-open-dashboard' );
	const status = document.getElementById( 'lihi-dashboard-status' );
	if ( ! button || ! status ) return;

	button.addEventListener( 'click', async () => {
		const homeUrl = lihiSettings.homeUrl || '';
		clearStatus( status );

		if ( ! homeUrl && ! lihiSettings.passthroughRedirectUrl ) {
			renderStatus( status, 'error', fallbackMessage() );
			return;
		}

		button.disabled = true;
		button.setAttribute( 'aria-busy', 'true' );

		try {
			let proof = null;
			let proofError = null;
			try {
				proof = await createPassthroughProof();
			} catch ( error ) {
				proofError = error;
			}

			const params = {
				action: lihiSettings.dashboardAction,
				nonce: lihiSettings.dashboardNonce,
			};
			if ( proof ) {
				params.challenge = proof.challenge;
			}

			const data = await postAjax( params );

			if ( ! data.success ) {
				throw proofError || new Error( errorMessage( data ) );
			}

			if ( data.data?.passthrough === false ) {
				const fallbackUrl = data.data?.home_url || homeUrl;
				if ( ! fallbackUrl ) {
					throw new Error( errorMessage( data ) );
				}

				openExternalUrl( fallbackUrl );
				return;
			}

			if ( ! proof ) {
				throw proofError || new Error( fallbackMessage() );
			}

			const passthroughNonce = data.data?.nonce;
			const redirectUrl = data.data?.redirect_url || lihiSettings.passthroughRedirectUrl;
			if ( ! passthroughNonce || ! redirectUrl ) {
				throw new Error( errorMessage( data ) );
			}

			openExternalUrl(
				buildPassthroughRedirectUrl( redirectUrl, passthroughNonce, proof.verifier )
			);
		} catch ( error ) {
			renderStatus( status, 'error', exceptionMessage( error ) );
		} finally {
			button.disabled = false;
			button.removeAttribute( 'aria-busy' );
		}
	} );
}

function openExternalUrl( url ) {
	const link = document.createElement( 'a' );
	link.href = url;
	link.target = '_blank';
	link.rel = 'noopener noreferrer';
	link.click();
}

function bindAuthForm( { formId, statusId, config, payload, validate, onSuccess } ) {
	const form = document.getElementById( formId );
	const status = document.getElementById( statusId );
	const button = form?.querySelector( 'button[type="submit"]' );
	if ( ! form || ! status || ! button || ! config ) return;

	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		clearStatus( status );
		clearFieldValidation( form, status );

		const validationError = validate( form );
		if ( validationError ) {
			renderFieldError( status, validationError );
			return;
		}

		button.disabled = true;
		form.setAttribute( 'aria-busy', 'true' );
		let reloadPending = false;
		try {
			const data = await postAjax( {
				action: config.action,
				nonce: config.nonce,
				...payload( form ),
			} );

			if ( data.success ) {
				renderStatus( status, 'success', data.data?.message || fallbackMessage() );
				reloadPending = onSuccess?.( data.data ?? {}, form ) === true;
			} else {
				renderStatus( status, 'error', errorMessage( data ), errorAction( data ) );
			}
		} catch ( error ) {
			renderStatus( status, 'error', exceptionMessage( error ) );
		} finally {
			form.removeAttribute( 'aria-busy' );
			if ( ! reloadPending ) {
				button.disabled = false;
			}
		}
	} );
}
