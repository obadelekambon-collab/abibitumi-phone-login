/**
 * Abibitumi Chat — service worker.
 * Provides the offline app shell for the PWA operator app and receives
 * Web Push notifications, routing clicks back to the dashboard.
 *
 * Served from the site root at /abchat-sw.js so its scope is the whole
 * origin (see ABChat_PWA).
 *
 * @package AbibitumiChat
 */
/* global self, clients */

var ABCHAT_CACHE = 'abchat-shell-v1';

self.addEventListener( 'install', function ( event ) {
	self.skipWaiting();
} );

self.addEventListener( 'activate', function ( event ) {
	event.waitUntil(
		caches.keys().then( function ( keys ) {
			return Promise.all(
				keys.filter( function ( k ) { return k !== ABCHAT_CACHE; } )
					.map( function ( k ) { return caches.delete( k ); } )
			);
		} ).then( function () { return self.clients.claim(); } )
	);
} );

/**
 * Network-first for navigations so the app is always fresh, falling back
 * to any cached shell when offline. Non-navigation requests pass through.
 */
self.addEventListener( 'fetch', function ( event ) {
	var req = event.request;
	if ( req.method !== 'GET' ) { return; }
	if ( req.mode === 'navigate' ) {
		event.respondWith(
			fetch( req ).then( function ( res ) {
				var copy = res.clone();
				caches.open( ABCHAT_CACHE ).then( function ( c ) { c.put( req, copy ); } );
				return res;
			} ).catch( function () {
				return caches.match( req ).then( function ( hit ) {
					return hit || caches.match( '/' );
				} );
			} )
		);
	}
} );

/**
 * Incoming Web Push → show a notification.
 */
self.addEventListener( 'push', function ( event ) {
	var data = { title: 'New message', body: '', url: '/wp-admin/admin.php?page=abchat', tag: 'abchat' };
	if ( event.data ) {
		try { data = Object.assign( data, event.data.json() ); }
		catch ( e ) { data.body = event.data.text(); }
	}
	event.waitUntil(
		self.registration.showNotification( data.title, {
			body: data.body,
			tag: data.tag,
			icon: '/wp-content/plugins/abibitumi-chat/assets/img/icon-192.png',
			badge: '/wp-content/plugins/abibitumi-chat/assets/img/icon-192.png',
			data: { url: data.url },
			vibrate: [ 80, 40, 80 ]
		} )
	);
} );

/**
 * Notification click → focus an open tab or open the dashboard.
 */
self.addEventListener( 'notificationclick', function ( event ) {
	event.notification.close();
	var url = ( event.notification.data && event.notification.data.url ) || '/wp-admin/admin.php?page=abchat';
	event.waitUntil(
		clients.matchAll( { type: 'window', includeUncontrolled: true } ).then( function ( list ) {
			for ( var i = 0; i < list.length; i++ ) {
				if ( list[i].url.indexOf( 'page=abchat' ) > -1 && 'focus' in list[i] ) {
					return list[i].focus();
				}
			}
			if ( clients.openWindow ) { return clients.openWindow( url ); }
		} )
	);
} );
