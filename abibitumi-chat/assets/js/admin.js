/**
 * Abibitumi Chat — operator dashboard.
 * Vanilla JS. Polls the agent REST endpoints, renders the inbox and the
 * active conversation, and handles canned responses, assignment, notes,
 * status changes and Web Push registration.
 *
 * @package AbibitumiChat
 */
(function () {
	'use strict';

	var A = window.ABChatAdmin;
	if ( ! A ) { return; }

	var current = 0;       // open conversation id
	var lastId  = 0;       // last message id in current convo
	var filter  = 'open';
	var mine    = false;
	var search  = '';
	var canned  = [];
	var listTimer = null;
	var msgTimer  = null;
	var stream    = null;
	var typingTimer = null;
	var audioCtx = null;

	var $ = function ( sel, ctx ) { return ( ctx || document ).querySelector( sel ); };

	function beep() {
		try {
			var Ctx = window.AudioContext || window.webkitAudioContext;
			if ( ! Ctx ) { return; }
			audioCtx = audioCtx || new Ctx();
			var o = audioCtx.createOscillator();
			var g = audioCtx.createGain();
			o.connect( g ); g.connect( audioCtx.destination );
			o.type = 'sine'; o.frequency.value = 660;
			g.gain.setValueAtTime( 0.001, audioCtx.currentTime );
			g.gain.exponentialRampToValueAtTime( 0.12, audioCtx.currentTime + 0.02 );
			g.gain.exponentialRampToValueAtTime( 0.001, audioCtx.currentTime + 0.25 );
			o.start(); o.stop( audioCtx.currentTime + 0.26 );
		} catch ( e ) {}
	}

	/* ------------------------------------------------------------------ */
	/* API                                                                */
	/* ------------------------------------------------------------------ */
	function api( path, opts ) {
		opts = opts || {};
		var headers = { 'Content-Type': 'application/json', 'X-WP-Nonce': A.nonce };
		return fetch( A.restUrl + path, {
			method: opts.method || 'GET',
			headers: headers,
			credentials: 'same-origin',
			body: opts.body ? JSON.stringify( opts.body ) : undefined
		} ).then( function ( r ) { return r.json(); } );
	}

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s == null ? '' : String( s );
		return d.innerHTML;
	}
	function el( tag, cls, html ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( html != null ) { n.innerHTML = html; }
		return n;
	}

	/* ------------------------------------------------------------------ */
	/* Inbox list                                                         */
	/* ------------------------------------------------------------------ */
	function loadList() {
		var q = '/agent/conversations?status=' + encodeURIComponent( filter ) +
			( mine ? '&mine=1' : '' ) +
			( search ? '&search=' + encodeURIComponent( search ) : '' );
		api( q ).then( function ( res ) {
			renderList( res.conversations || [] );
			renderCounts( res.counts || {} );
		} );
	}

	function renderCounts( counts ) {
		setText( '#abchat-count-open', counts.open || 0 );
		setText( '#abchat-count-pending', counts.pending || 0 );
		setText( '#abchat-count-closed', counts.closed || 0 );
	}
	function setText( sel, v ) { var n = $( sel ); if ( n ) { n.textContent = v; } }

	function renderList( convos ) {
		var list = $( '#abchat-list' );
		if ( ! list ) { return; }
		if ( ! convos.length ) {
			list.innerHTML = '<div class="abchat-empty">No conversations here.</div>';
			return;
		}
		list.innerHTML = '';
		convos.forEach( function ( c ) {
			var item = el( 'div', 'abchat-list-item' + ( c.id === current ? ' active' : '' ) );
			item.dataset.id = c.id;
			item.innerHTML =
				'<div class="abchat-li-top">' +
					'<span class="abchat-li-name">' +
						'<span class="abchat-dot ' + ( c.online ? 'on' : 'off' ) + '"></span>' + esc( c.visitorName ) +
					'</span>' +
					( c.unread ? '<span class="abchat-li-unread">' + c.unread + '</span>' : '' ) +
				'</div>' +
				'<div class="abchat-li-msg">' + esc( ( c.lastMessage || '' ).slice( 0, 60 ) ) + '</div>' +
				'<div class="abchat-li-meta">' + esc( c.department || '' ) + ' · ' + timeAgo( c.updatedAt ) + '</div>';
			item.addEventListener( 'click', function () { openConversation( c.id ); } );
			list.appendChild( item );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Conversation view                                                  */
	/* ------------------------------------------------------------------ */
	function openConversation( id ) {
		current = id; lastId = 0;
		document.querySelectorAll( '.abchat-list-item' ).forEach( function ( n ) {
			n.classList.toggle( 'active', parseInt( n.dataset.id, 10 ) === id );
		} );
		$( '#abchat-conversation' ).classList.add( 'has-active' );
		api( '/agent/conversation/' + id ).then( function ( res ) {
			renderConversation( res );
			startUpdates();
		} );
	}

	function renderConversation( res ) {
		var c = res.conversation, v = res.visitor || {};
		var head = $( '#abchat-convo-head' );
		head.innerHTML =
			'<div class="abchat-convo-title">' +
				'<span class="abchat-dot ' + ( v.online ? 'on' : 'off' ) + '"></span>' +
				esc( v.name || 'Visitor' ) +
				'<span class="abchat-badge-status abchat-st-' + esc( c.status ) + '">' + esc( c.status ) + '</span>' +
			'</div>' +
			'<div class="abchat-convo-actions">' +
				( A.exportUrl ? '<a class="button" href="' + esc( A.exportUrl + '&conversation_id=' + c.id ) + '">Export CSV</a>' : '' ) +
				'<select id="abchat-assign" title="Assign"></select>' +
				( 'closed' === c.status
					? '<button class="button" data-status="open">Reopen</button>'
					: '<button class="button button-primary" data-status="closed">Resolve</button>' ) +
			'</div>';

		// Assignment dropdown.
		var sel = $( '#abchat-assign' );
		sel.innerHTML = '<option value="">Unassigned</option>';
		( A.operators || [] ).forEach( function ( o ) {
			var opt = document.createElement( 'option' );
			opt.value = o.id; opt.textContent = o.name;
			if ( o.id === c.operatorId ) { opt.selected = true; }
			sel.appendChild( opt );
		} );
		sel.addEventListener( 'change', function () {
			api( '/agent/assign', { method: 'POST', body: { conversation_id: current, operator_id: sel.value } } ).then( loadMessages );
		} );

		head.querySelectorAll( '[data-status]' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				api( '/agent/status', { method: 'POST', body: { conversation_id: current, status: b.dataset.status } } )
					.then( function () { openConversation( current ); loadList(); } );
			} );
		} );

		renderVisitorPanel( v, c );

		var msgBox = $( '#abchat-messages' );
		msgBox.innerHTML = '';
		( res.messages || [] ).forEach( appendMessage );
		scrollMsgs();
		api( '/agent/read', { method: 'POST', body: { conversation_id: current } } );
	}

	function renderVisitorPanel( v, c ) {
		var panel = $( '#abchat-visitor' );
		if ( ! panel ) { return; }
		var rows = [
			[ 'Email', v.email ], [ 'Phone', v.phone ], [ 'Location/IP', v.ip ],
			[ 'Current page', v.page ], [ 'Referrer', v.referrer ],
			[ 'First seen', v.firstSeen ], [ 'Last seen', v.lastSeen ],
			[ 'Rating', c.rating ? c.rating + '/5' : '—' ]
		];
		var html = '<h3>' + esc( v.name || 'Visitor' ) + '</h3>';
		if ( v.wpUserId ) { html += '<div class="abchat-vi-member">✔ Registered member (#' + v.wpUserId + ')</div>'; }
		html += '<dl class="abchat-vi">';
		rows.forEach( function ( r ) {
			if ( r[1] ) { html += '<dt>' + esc( r[0] ) + '</dt><dd>' + esc( r[1] ) + '</dd>'; }
		} );
		html += '</dl>';
		if ( v.userAgent ) { html += '<div class="abchat-vi-ua">' + esc( v.userAgent ) + '</div>'; }
		panel.innerHTML = html;
	}

	function appendMessage( m ) {
		if ( m.id ) { lastId = Math.max( lastId, m.id ); }
		var box = $( '#abchat-messages' );

		if ( 'note' === m.type ) {
			box.appendChild( el( 'div', 'abchat-note', '📝 ' + esc( m.body ) + ' <span class="abchat-note-by">' + esc( m.senderName ) + '</span>' ) );
			scrollMsgs(); return;
		}
		if ( 'system' === m.senderType ) {
			box.appendChild( el( 'div', 'abchat-sys', esc( m.body ) ) );
			scrollMsgs(); return;
		}

		var mine_ = ( 'operator' === m.senderType );
		var row = el( 'div', 'abchat-m ' + ( mine_ ? 'out' : 'in' ) + ( 'bot' === m.senderType ? ' bot' : '' ) );
		var inner = '';
		if ( 'image' === m.type && m.attachment ) {
			inner = '<a href="' + esc( m.attachment.url ) + '" target="_blank"><img src="' + esc( m.attachment.url ) + '"></a>';
		} else if ( 'file' === m.type && m.attachment ) {
			inner = '<a href="' + esc( m.attachment.url ) + '" target="_blank">📎 ' + esc( m.attachment.name ) + '</a>';
		} else {
			inner = esc( m.body ).replace( /\n/g, '<br>' );
		}
		row.innerHTML =
			'<div class="abchat-m-bubble">' + inner + '</div>' +
			'<div class="abchat-m-meta">' + esc( m.senderName || '' ) + ' · ' + esc( m.time || '' ) +
			( mine_ && m.read ? ' · <span class="abchat-read">Seen</span>' : '' ) + '</div>';
		box.appendChild( row );
		scrollMsgs();
	}

	/* ------------------------------------------------------------------ */
	/* Sending                                                            */
	/* ------------------------------------------------------------------ */
	function send( isNote ) {
		var ta = $( '#abchat-reply' );
		var text = ta.value.trim();
		if ( ! text || ! current ) { return; }
		ta.value = '';
		api( '/agent/message', { method: 'POST', body: {
			conversation_id: current, body: text, type: isNote ? 'note' : 'text'
		} } ).then( loadMessages );
	}

	function loadMessages() {
		if ( ! current ) { return; }
		api( '/agent/poll?conversation_id=' + current + '&after=' + lastId ).then( function ( res ) {
			( res.messages || [] ).forEach( function ( m ) {
				appendMessage( m );
				if ( 'visitor' === m.senderType ) { onIncoming( m ); }
			} );
			showTyping( res.visitorTyping );
			renderCounts( res.counts || {} );
		} );
	}

	function onIncoming( m ) {
		beep();
		notify( m.senderName || 'Visitor', m.body );
	}

	function showTyping( on ) {
		var t = $( '#abchat-agent-typing' );
		if ( t ) { t.hidden = ! on; if ( on ) { scrollMsgs(); } }
	}

	/* ------------------------------------------------------------------ */
	/* Canned responses                                                   */
	/* ------------------------------------------------------------------ */
	function loadCanned() {
		api( '/agent/canned' ).then( function ( res ) { canned = res.canned || []; } );
	}
	function maybeCanned( ta ) {
		var val = ta.value;
		var m = val.match( /(^|\s)(\/[a-z0-9]+)$/i );
		var pop = $( '#abchat-canned-pop' );
		if ( ! m ) { if ( pop ) { pop.hidden = true; } return; }
		var frag = m[2].toLowerCase();
		var hits = canned.filter( function ( c ) { return c.shortcut.toLowerCase().indexOf( frag ) === 0; } );
		if ( ! hits.length ) { if ( pop ) { pop.hidden = true; } return; }
		pop.innerHTML = '';
		hits.forEach( function ( c ) {
			var b = el( 'button', 'abchat-canned-item', '<strong>' + esc( c.shortcut ) + '</strong> ' + esc( c.title ) );
			b.addEventListener( 'click', function () {
				ta.value = val.replace( /(\/[a-z0-9]+)$/i, c.body );
				pop.hidden = true; ta.focus();
			} );
			pop.appendChild( b );
		} );
		pop.hidden = false;
	}

	/* ------------------------------------------------------------------ */
	/* Notifications & Web Push                                            */
	/* ------------------------------------------------------------------ */
	function notify( title, body ) {
		if ( 'Notification' in window && 'granted' === Notification.permission && document.hidden ) {
			try { new Notification( title, { body: body, tag: 'abchat-' + current } ); } catch ( e ) {}
		}
	}

	function setupPush() {
		if ( ! A.pushEnabled || ! ( 'Notification' in window ) ) { return; }
		if ( 'default' === Notification.permission ) {
			Notification.requestPermission();
		}
		if ( ! A.vapidPublic || ! ( 'serviceWorker' in navigator ) || ! ( 'PushManager' in window ) ) { return; }
		navigator.serviceWorker.register( A.swUrl, { scope: '/' } ).then( function ( reg ) {
			return reg.pushManager.getSubscription().then( function ( sub ) {
				if ( sub ) { return sub; }
				return reg.pushManager.subscribe( {
					userVisibleOnly: true,
					applicationServerKey: urlB64ToUint8( A.vapidPublic )
				} );
			} );
		} ).then( function ( sub ) {
			if ( sub ) {
				api( '/agent/push', { method: 'POST', body: { subscription: sub } } );
			}
		} ).catch( function () {} );
	}

	function urlB64ToUint8( base64 ) {
		var pad = '='.repeat( ( 4 - base64.length % 4 ) % 4 );
		var b64 = ( base64 + pad ).replace( /-/g, '+' ).replace( /_/g, '/' );
		var raw = atob( b64 );
		var arr = new Uint8Array( raw.length );
		for ( var i = 0; i < raw.length; i++ ) { arr[i] = raw.charCodeAt( i ); }
		return arr;
	}

	/* ------------------------------------------------------------------ */
	/* Polling                                                            */
	/* ------------------------------------------------------------------ */
	function startUpdates() {
		if ( stream ) { stream.close(); stream = null; }
		if ( A.streamEnabled && window.EventSource ) {
			startStream();
		} else {
			startMsgPoll();
		}
	}
	function startStream() {
		if ( msgTimer ) { clearInterval( msgTimer ); msgTimer = null; }
		var url = A.restUrl + '/agent/stream?_wpnonce=' + encodeURIComponent( A.nonce ) +
			'&conversation_id=' + encodeURIComponent( current || 0 ) + '&after=' + encodeURIComponent( lastId );
		stream = new EventSource( url, { withCredentials: true } );
		stream.addEventListener( 'update', function ( event ) {
			var res;
			try { res = JSON.parse( event.data ); } catch ( e ) { return; }
			( res.messages || [] ).forEach( function ( m ) {
				if ( m.id && m.id <= lastId ) { return; }
				appendMessage( m );
				if ( 'visitor' === m.senderType ) { onIncoming( m ); }
			} );
			showTyping( res.visitorTyping );
			renderCounts( res.counts || {} );
		} );
		stream.addEventListener( 'reconnect', function () {
			if ( stream ) { stream.close(); stream = null; }
			startStream();
		} );
		stream.onerror = function () {
			if ( stream ) { stream.close(); stream = null; }
			startMsgPoll();
		};
	}
	function startMsgPoll() {
		if ( msgTimer ) { clearInterval( msgTimer ); }
		msgTimer = setInterval( loadMessages, A.pollInterval * 1000 );
	}
	function startListPoll() {
		loadList();
		if ( listTimer ) { clearInterval( listTimer ); }
		listTimer = setInterval( loadList, Math.max( 5, A.pollInterval * 2 ) * 1000 );
	}

	/* ------------------------------------------------------------------ */
	/* Utils                                                              */
	/* ------------------------------------------------------------------ */
	function scrollMsgs() { var b = $( '#abchat-messages' ); if ( b ) { b.scrollTop = b.scrollHeight; } }
	function timeAgo( ts ) {
		if ( ! ts ) { return ''; }
		var d = new Date( ts.replace( ' ', 'T' ) );
		var s = Math.floor( ( Date.now() - d.getTime() ) / 1000 );
		if ( isNaN( s ) ) { return ''; }
		if ( s < 60 ) { return 'just now'; }
		if ( s < 3600 ) { return Math.floor( s / 60 ) + 'm'; }
		if ( s < 86400 ) { return Math.floor( s / 3600 ) + 'h'; }
		return Math.floor( s / 86400 ) + 'd';
	}

	/* ------------------------------------------------------------------ */
	/* Wire up                                                            */
	/* ------------------------------------------------------------------ */
	function init() {
		if ( ! $( '#abchat-dashboard' ) ) { return; }

		// Filter tabs.
		document.querySelectorAll( '.abchat-tab' ).forEach( function ( t ) {
			t.addEventListener( 'click', function () {
				document.querySelectorAll( '.abchat-tab' ).forEach( function ( x ) { x.classList.remove( 'active' ); } );
				t.classList.add( 'active' );
				filter = t.dataset.filter;
				loadList();
			} );
		} );

		var mineToggle = $( '#abchat-mine' );
		if ( mineToggle ) { mineToggle.addEventListener( 'change', function () { mine = mineToggle.checked; loadList(); } ); }

		var searchBox = $( '#abchat-search' );
		if ( searchBox ) {
			searchBox.addEventListener( 'input', function () {
				search = searchBox.value;
				clearTimeout( typingTimer );
				typingTimer = setTimeout( loadList, 300 );
			} );
		}

		var replyForm = $( '#abchat-reply-form' );
		if ( replyForm ) {
			replyForm.addEventListener( 'submit', function ( e ) { e.preventDefault(); send( false ); } );
		}
		var ta = $( '#abchat-reply' );
		if ( ta ) {
			ta.addEventListener( 'input', function () {
				maybeCanned( ta );
				if ( current ) { api( '/agent/typing', { method: 'POST', body: { conversation_id: current } } ); }
			} );
			ta.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key && ! e.shiftKey ) { e.preventDefault(); send( false ); }
			} );
		}
		var noteBtn = $( '#abchat-note-btn' );
		if ( noteBtn ) { noteBtn.addEventListener( 'click', function () { send( true ); } ); }

		var fileBtn = $( '#abchat-file' );
		if ( fileBtn ) {
			fileBtn.addEventListener( 'change', function ( e ) {
				var f = e.target.files[0];
				if ( ! f || ! current ) { return; }
				var fd = new FormData();
				fd.append( 'file', f ); fd.append( 'conversation_id', current );
				fetch( A.restUrl + '/upload', {
					method: 'POST', headers: { 'X-WP-Nonce': A.nonce }, credentials: 'same-origin', body: fd
				} ).then( function () { loadMessages(); } );
				e.target.value = '';
			} );
		}

		loadCanned();
		startListPoll();
		setupPush();

		// Presence heartbeat.
		setInterval( function () { api( '/agent/presence', { method: 'POST' } ); }, 30000 );

		if ( A.openConvo ) { openConversation( A.openConvo ); }
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
