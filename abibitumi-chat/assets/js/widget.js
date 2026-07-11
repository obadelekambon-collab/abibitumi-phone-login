/**
 * Abibitumi Chat — visitor widget.
 * Vanilla JS, no framework. Talks to the WP REST API and polls for updates.
 *
 * @package AbibitumiChat
 */
(function () {
	'use strict';

	if ( ! window.ABChatData || ! window.ABChatData.config || ! window.ABChatData.config.enabled ) {
		return;
	}

	var D        = window.ABChatData;
	var CFG      = D.config;
	var I18N     = D.i18n;
	var STORE    = 'abchat_state_v1';
	var token    = null;
	var visitor  = null;
	var convo    = null;      // conversation id
	var lastId   = 0;         // last message id seen
	var pollTimer = null;
	var stream   = null;
	var typingTimer = null;
	var isOpen   = false;
	var seenIntro = false;
	var humanJoined = false;
	var audioCtx = null;

	/* ------------------------------------------------------------------ */
	/* Sound — short Web Audio beep (no binary asset required)            */
	/* ------------------------------------------------------------------ */
	function beep() {
		if ( ! CFG.soundEnabled ) { return; }
		try {
			var Ctx = window.AudioContext || window.webkitAudioContext;
			if ( ! Ctx ) { return; }
			audioCtx = audioCtx || new Ctx();
			var o = audioCtx.createOscillator();
			var g = audioCtx.createGain();
			o.connect( g ); g.connect( audioCtx.destination );
			o.type = 'sine'; o.frequency.value = 620;
			g.gain.setValueAtTime( 0.001, audioCtx.currentTime );
			g.gain.exponentialRampToValueAtTime( 0.15, audioCtx.currentTime + 0.02 );
			g.gain.exponentialRampToValueAtTime( 0.001, audioCtx.currentTime + 0.25 );
			o.start(); o.stop( audioCtx.currentTime + 0.26 );
		} catch ( e ) {}
	}

	/* ------------------------------------------------------------------ */
	/* Storage                                                            */
	/* ------------------------------------------------------------------ */
	function load() {
		try {
			var s = JSON.parse( localStorage.getItem( STORE ) || '{}' );
			token = s.token || null;
			convo = s.convo || null;
			lastId = s.lastId || 0;
			seenIntro = !! s.seenIntro;
		} catch ( e ) {}
	}
	function save() {
		try {
			localStorage.setItem( STORE, JSON.stringify( {
				token: token, convo: convo, lastId: lastId, seenIntro: seenIntro
			} ) );
		} catch ( e ) {}
	}

	/* ------------------------------------------------------------------ */
	/* API                                                                */
	/* ------------------------------------------------------------------ */
	function api( path, opts ) {
		opts = opts || {};
		var headers = { 'Content-Type': 'application/json' };
		if ( D.nonce ) { headers['X-WP-Nonce'] = D.nonce; }
		if ( token ) { headers['X-ABChat-Token'] = token; }
		return fetch( D.restUrl + path, {
			method: opts.method || 'GET',
			headers: headers,
			credentials: 'same-origin',
			body: opts.body ? JSON.stringify( opts.body ) : undefined
		} ).then( function ( r ) {
			return r.json().then( function ( j ) {
				if ( ! r.ok ) { throw j; }
				return j;
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* DOM helpers                                                        */
	/* ------------------------------------------------------------------ */
	function el( tag, cls, html ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( html != null ) { n.innerHTML = html; }
		return n;
	}
	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s == null ? '' : String( s );
		return d.innerHTML;
	}

	var root, launcher, panel, body, form, input, badge, typingEl, headerStatus;

	function buildUI() {
		root = document.getElementById( 'abchat-root' );
		if ( ! root ) { return; }
		root.style.setProperty( '--abchat-primary', CFG.primaryColor );
		root.style.setProperty( '--abchat-text', CFG.textColor );
		root.setAttribute( 'data-position', CFG.position );

		// Launcher bubble.
		launcher = el( 'button', 'abchat-launcher' );
		launcher.setAttribute( 'aria-label', I18N.startChat );
		launcher.innerHTML = launcherIcon();
		badge = el( 'span', 'abchat-badge' );
		badge.style.display = 'none';
		launcher.appendChild( badge );
		launcher.addEventListener( 'click', togglePanel );
		root.appendChild( launcher );

		// Panel.
		panel = el( 'div', 'abchat-panel' );
		panel.setAttribute( 'role', 'dialog' );
		panel.setAttribute( 'aria-label', CFG.brandName );
		panel.innerHTML =
			'<div class="abchat-header">' +
				'<div class="abchat-header-info">' +
					( CFG.avatarUrl ? '<img class="abchat-avatar" src="' + esc( CFG.avatarUrl ) + '" alt="">' : '<span class="abchat-avatar abchat-avatar-fallback">' + esc( ( CFG.brandName || 'C' ).charAt( 0 ) ) + '</span>' ) +
					'<div><div class="abchat-title">' + esc( CFG.welcomeTitle ) + '</div>' +
					'<div class="abchat-status">' + ( CFG.isOpen ? esc( I18N.online ) : esc( I18N.offline ) ) + '</div></div>' +
				'</div>' +
				'<button class="abchat-close" aria-label="Close">&times;</button>' +
			'</div>' +
			'<div class="abchat-body" id="abchat-body"></div>' +
			'<div class="abchat-typing" id="abchat-typing" hidden><span></span><span></span><span></span></div>' +
			'<form class="abchat-input" id="abchat-form">' +
				( CFG.fileUploads ? '<label class="abchat-attach" title="' + esc( I18N.attach ) + '"><input type="file" hidden><svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M16.5 6v11.5a4 4 0 01-8 0V5a2.5 2.5 0 015 0v10.5a1 1 0 01-2 0V6H10v9.5a2.5 2.5 0 005 0V5a4 4 0 00-8 0v12.5a5.5 5.5 0 0011 0V6z"/></svg></label>' : '' ) +
				'<textarea id="abchat-text" rows="1" maxlength="' + Number( CFG.maxMessageLength || 5000 ) + '" placeholder="' + esc( I18N.typeMessage ) + '"></textarea>' +
				'<button type="submit" class="abchat-send" aria-label="' + esc( I18N.send ) + '"><svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M2 21l21-9L2 3v7l15 2-15 2z"/></svg></button>' +
			'</form>' +
			'<div class="abchat-footer">' + esc( I18N.poweredBy ) + '</div>';
		root.appendChild( panel );

		body         = panel.querySelector( '#abchat-body' );
		form         = panel.querySelector( '#abchat-form' );
		input        = panel.querySelector( '#abchat-text' );
		typingEl     = panel.querySelector( '#abchat-typing' );
		headerStatus = panel.querySelector( '.abchat-status' );

		panel.querySelector( '.abchat-close' ).addEventListener( 'click', togglePanel );
		form.addEventListener( 'submit', onSubmit );
		input.addEventListener( 'input', onInput );
		input.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key && ! e.shiftKey ) { e.preventDefault(); onSubmit( e ); }
		} );
		var fileInput = panel.querySelector( '.abchat-attach input' );
		if ( fileInput ) { fileInput.addEventListener( 'change', onFile ); }

	}

	function launcherIcon() {
		return '<svg viewBox="0 0 24 24" width="28" height="28"><path fill="currentColor" d="M12 3C6.5 3 2 6.8 2 11.5c0 2.3 1.1 4.4 2.9 5.9L4 21l4.2-1.7c1.2.4 2.5.7 3.8.7 5.5 0 10-3.8 10-8.5S17.5 3 12 3z"/></svg>';
	}

	/* ------------------------------------------------------------------ */
	/* Panel open/close                                                   */
	/* ------------------------------------------------------------------ */
	function togglePanel() {
		isOpen = ! isOpen;
		root.classList.toggle( 'abchat-open', isOpen );
		if ( isOpen ) {
			badge.style.display = 'none';
			badge.textContent = '';
			ensureConversation();
			startUpdates();
			setTimeout( function () { input && input.focus(); }, 150 );
			markRead();
		} else {
			stopUpdates();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Session + conversation                                             */
	/* ------------------------------------------------------------------ */
	function startSession() {
		return api( '/session', {
			method: 'POST',
			body: { token: token, page_url: location.href, referrer: document.referrer }
		} ).then( function ( res ) {
			if ( ! res.enabled ) { return; }
			token   = res.token;
			visitor = res.visitor;
			if ( res.config ) { CFG.isOpen = res.config.isOpen; updateStatus(); }
			save();
		} ).catch( function () {} );
	}

	function ensureConversation() {
		if ( convo ) { loadHistory(); return; }
		if ( CFG.prechat && CFG.prechat.enabled && ! ( visitor && visitor.email ) ) {
			renderPrechat();
		} else {
			createConversation( {} );
		}
	}

	function createConversation( data ) {
		data.department = data.department || 'general';
		return api( '/conversation', { method: 'POST', body: data } ).then( function ( res ) {
			convo  = res.conversation.id;
			lastId = 0;
			save();
			renderMessages( res.messages, true );
			CFG.isOpen = res.isOpen;
			updateStatus();
		} ).catch( showError );
	}

	function loadHistory() {
		body.innerHTML = '';
		lastId = 0;
		api( '/poll?conversation_id=' + convo + '&after=0' ).then( function ( res ) {
			renderMessages( res.messages, true );
		} ).catch( function ( err ) {
			// Conversation may be gone; reset.
			if ( err && err.data && 403 === err.data.status ) {
				convo = null; save(); ensureConversation();
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Pre-chat form                                                      */
	/* ------------------------------------------------------------------ */
	function renderPrechat() {
		body.innerHTML = '';
		var wrap = el( 'div', 'abchat-prechat' );
		wrap.appendChild( el( 'p', 'abchat-prechat-msg', esc( CFG.prechat.message ) ) );
		var f = el( 'form', 'abchat-prechat-form' );
		var html = '';
		if ( CFG.prechat.name )  { html += '<input name="name" required placeholder="' + esc( I18N.name ) + '">'; }
		if ( CFG.prechat.email ) { html += '<input name="email" type="email" required placeholder="' + esc( I18N.email ) + '">'; }
		if ( CFG.prechat.phone ) { html += '<input name="phone" placeholder="' + esc( I18N.phone ) + '">'; }
		if ( CFG.departments && CFG.departments.length > 1 ) {
			html += '<select name="department">';
			CFG.departments.forEach( function ( d ) { html += '<option value="' + esc( d.id ) + '">' + esc( d.name ) + '</option>'; } );
			html += '</select>';
		}
		html += '<button type="submit">' + esc( I18N.startChat ) + '</button>';
		f.innerHTML = html;
		f.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var fd = {};
			Array.prototype.forEach.call( f.elements, function ( inp ) {
				if ( inp.name ) { fd[ inp.name ] = inp.value; }
			} );
			createConversation( fd );
		} );
		wrap.appendChild( f );
		body.appendChild( wrap );
	}

	/* ------------------------------------------------------------------ */
	/* Messaging                                                          */
	/* ------------------------------------------------------------------ */
	function onSubmit( e ) {
		if ( e && e.preventDefault ) { e.preventDefault(); }
		var text = input.value.trim();
		if ( ! text || ! convo ) { return; }
		input.value = '';
		autoGrow();
		appendMessage( { senderType: 'visitor', body: text, type: 'text', createdAt: 'now' }, true );

		api( '/message', { method: 'POST', body: { conversation_id: convo, body: text } } )
			.then( function () { maybeBot( text ); } )
			.catch( showError );
	}

	function maybeBot( text ) {
		// Never let the bot talk over a human agent, or after hand-off.
		if ( ! CFG.botEnabled || humanJoined ) { return; }
		api( '/bot', { method: 'POST', body: { conversation_id: convo, text: text } } )
			.then( function ( res ) {
				if ( res && res.handoff ) { humanJoined = true; }
				poll();
			} )
			.catch( function () {} );
	}

	function onInput() {
		autoGrow();
		if ( ! convo ) { return; }
		if ( typingTimer ) { return; }
		api( '/typing', { method: 'POST', body: { conversation_id: convo } } ).catch( function () {} );
		typingTimer = setTimeout( function () { typingTimer = null; }, 3000 );
	}

	function autoGrow() {
		input.style.height = 'auto';
		input.style.height = Math.min( 120, input.scrollHeight ) + 'px';
	}

	function onFile( e ) {
		var file = e.target.files[0];
		if ( ! file || ! convo ) { return; }
		var fd = new FormData();
		fd.append( 'file', file );
		fd.append( 'conversation_id', convo );
		var headers = {};
		if ( D.nonce ) { headers['X-WP-Nonce'] = D.nonce; }
		if ( token ) { headers['X-ABChat-Token'] = token; }
		fetch( D.restUrl + '/upload', { method: 'POST', headers: headers, credentials: 'same-origin', body: fd } )
			.then( function ( r ) { return r.json(); } )
			.then( function () { poll(); } )
			.catch( showError );
		e.target.value = '';
	}

	function renderMessages( messages, replace ) {
		if ( replace ) { body.innerHTML = ''; }
		( messages || [] ).forEach( function ( m ) { appendMessage( m ); } );
		scrollBottom();
	}

	function appendMessage( m, local ) {
		if ( m.id ) { lastId = Math.max( lastId, m.id ); save(); }

		if ( 'system' === m.senderType || 'note' === m.type ) {
			if ( 'note' === m.type ) { return; } // never show notes to visitors
			body.appendChild( el( 'div', 'abchat-system', esc( m.body ) ) );
			scrollBottom();
			return;
		}

		var mine = 'visitor' === m.senderType;
		var row  = el( 'div', 'abchat-msg ' + ( mine ? 'abchat-mine' : 'abchat-theirs' ) );
		var bubble = el( 'div', 'abchat-bubble' );

		if ( 'image' === m.type && m.attachment ) {
			bubble.innerHTML = '<a href="' + esc( m.attachment.url ) + '" target="_blank" rel="noopener"><img src="' + esc( m.attachment.url ) + '" alt=""></a>';
		} else if ( 'file' === m.type && m.attachment ) {
			bubble.innerHTML = '<a class="abchat-file" href="' + esc( m.attachment.url ) + '" target="_blank" rel="noopener">📎 ' + esc( m.attachment.name ) + '</a>';
		} else {
			bubble.innerHTML = linkify( esc( m.body ) );
		}

		if ( ! mine && m.senderName ) {
			row.appendChild( el( 'div', 'abchat-sender', esc( m.senderName ) ) );
		}
		row.appendChild( bubble );
		if ( m.time ) { row.appendChild( el( 'div', 'abchat-time', esc( m.time ) ) ); }
		body.appendChild( row );

		// Quick replies from bot meta.
		if ( m.meta && m.meta.quickReplies && m.meta.quickReplies.length ) {
			renderQuickReplies( m.meta.quickReplies );
		}
		scrollBottom();
	}

	function renderQuickReplies( replies ) {
		var wrap = el( 'div', 'abchat-quick' );
		replies.forEach( function ( q ) {
			var b = el( 'button', 'abchat-quick-btn', esc( q.label ) );
			b.addEventListener( 'click', function () {
				wrap.remove();
				appendMessage( { senderType: 'visitor', body: q.label, type: 'text' }, true );
				api( '/bot', { method: 'POST', body: { conversation_id: convo, flow_id: q.id, text: q.label } } )
					.then( function () { poll(); } );
			} );
			wrap.appendChild( b );
		} );
		body.appendChild( wrap );
		scrollBottom();
	}

	function linkify( html ) {
		return html.replace( /(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>' );
	}

	/* ------------------------------------------------------------------ */
	/* Polling                                                            */
	/* ------------------------------------------------------------------ */
	function startUpdates() {
		stopUpdates();
		poll();
		if ( CFG.streamEnabled && window.EventSource && token && convo ) {
			startStream();
		} else {
			startPolling();
		}
	}
	function stopUpdates() {
		stopPolling();
		if ( stream ) { stream.close(); stream = null; }
	}
	function startStream() {
		var url = D.restUrl + '/stream?conversation_id=' + encodeURIComponent( convo ) +
			'&after=' + encodeURIComponent( lastId );
		stream = new EventSource( url, { withCredentials: true } );
		stream.addEventListener( 'update', function ( event ) {
			try { handleUpdate( JSON.parse( event.data ) ); } catch ( e ) {}
		} );
		stream.addEventListener( 'reconnect', function () {
			if ( stream ) { stream.close(); stream = null; }
			if ( isOpen ) { startStream(); }
		} );
		stream.onerror = function () {
			if ( stream ) { stream.close(); stream = null; }
			if ( isOpen ) { startPolling(); }
		};
	}
	function startPolling() {
		stopPolling();
		pollTimer = setInterval( poll, ( CFG.pollInterval || 4 ) * 1000 );
	}
	function stopPolling() {
		if ( pollTimer ) { clearInterval( pollTimer ); pollTimer = null; }
	}
	function poll() {
		if ( ! convo ) { return; }
		api( '/poll?conversation_id=' + convo + '&after=' + lastId ).then( function ( res ) {
			handleUpdate( res );
		} ).catch( function () {} );
	}
	function handleUpdate( res ) {
		if ( res.messages && res.messages.length ) {
			res.messages.forEach( function ( m ) {
				if ( m.id && m.id <= lastId ) { return; }
				appendMessage( m );
				if ( 'operator' === m.senderType ) { humanJoined = true; }
				if ( 'visitor' !== m.senderType && 'system' !== m.senderType ) {
					notifyIncoming( m );
				}
			} );
		}
		showTyping( res.agentTyping );
		if ( res.operatorName ) { headerStatus.textContent = res.operatorName; }
		if ( 'closed' === res.status ) { offerRating(); }
		if ( isOpen ) { markRead(); }
	}

	function notifyIncoming( m ) {
		if ( ! isOpen ) {
			var n = parseInt( badge.textContent || '0', 10 ) + 1;
			badge.textContent = n;
			badge.style.display = 'flex';
		}
		beep();
	}

	function showTyping( on ) {
		if ( ! typingEl ) { return; }
		typingEl.hidden = ! on;
		if ( on ) { scrollBottom(); }
	}

	function markRead() {
		if ( ! convo ) { return; }
		api( '/read', { method: 'POST', body: { conversation_id: convo } } ).catch( function () {} );
	}

	/* ------------------------------------------------------------------ */
	/* Rating                                                             */
	/* ------------------------------------------------------------------ */
	var rated = false;
	function offerRating() {
		if ( rated || document.querySelector( '.abchat-rating' ) ) { return; }
		var wrap = el( 'div', 'abchat-rating' );
		wrap.appendChild( el( 'div', 'abchat-rating-q', esc( I18N.rateChat ) ) );
		var stars = el( 'div', 'abchat-stars' );
		for ( var i = 1; i <= 5; i++ ) {
			( function ( n ) {
				var s = el( 'button', 'abchat-star', '★' );
				s.addEventListener( 'click', function () { sendRating( n, wrap ); } );
				stars.appendChild( s );
			} )( i );
		}
		wrap.appendChild( stars );
		body.appendChild( wrap );
		scrollBottom();
	}
	function sendRating( n, wrap ) {
		rated = true;
		api( '/rate', { method: 'POST', body: { conversation_id: convo, rating: n } } );
		wrap.innerHTML = '<div class="abchat-rating-q">' + esc( I18N.thanks ) + '</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Misc                                                               */
	/* ------------------------------------------------------------------ */
	function updateStatus() {
		if ( ! headerStatus ) { return; }
		headerStatus.textContent = CFG.isOpen ? I18N.online : I18N.offline;
	}
	function scrollBottom() {
		if ( body ) { body.scrollTop = body.scrollHeight; }
	}
	function showError( err ) {
		var msg = ( err && err.message ) ? err.message : 'Something went wrong. Please try again.';
		body.appendChild( el( 'div', 'abchat-system abchat-error', esc( msg ) ) );
		scrollBottom();
	}

	/* ------------------------------------------------------------------ */
	/* Proactive greeting                                                 */
	/* ------------------------------------------------------------------ */
	function maybeGreet() {
		if ( seenIntro || isOpen ) { return; }
		setTimeout( function () {
			if ( seenIntro || isOpen ) { return; }
			seenIntro = true; save();
			var bubble = el( 'div', 'abchat-greeting' );
			bubble.innerHTML = '<div class="abchat-greeting-text">' + esc( CFG.welcomeSubtitle ) + '</div>';
			bubble.addEventListener( 'click', function () { bubble.remove(); togglePanel(); } );
			root.appendChild( bubble );
			beep();
			setTimeout( function () { bubble.classList.add( 'abchat-fade' ); }, 8000 );
		}, ( CFG.greetingDelay || 3 ) * 1000 );
	}

	/* ------------------------------------------------------------------ */
	/* PWA service worker (visitor side is optional)                      */
	/* ------------------------------------------------------------------ */
	function registerSW() {
		if ( D.pwa && D.pwa.enabled && 'serviceWorker' in navigator ) {
			navigator.serviceWorker.register( D.pwa.swUrl, { scope: '/' } ).catch( function () {} );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Init                                                               */
	/* ------------------------------------------------------------------ */
	function init() {
		load();
		buildUI();
		if ( ! root ) { return; }
		startSession().then( function () {
			maybeGreet();
			// Background poll for unread even when closed, at a slower cadence.
			if ( convo ) {
				setInterval( function () { if ( ! isOpen ) { poll(); } }, ( CFG.pollInterval || 4 ) * 3000 );
			}
		} );
		registerSW();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
