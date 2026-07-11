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

var ABCHAT_CACHE_PREFIX = 'abchat-shell-';

self.addEventListener( 'install', function ( event ) {
	self.skipWaiting();
} );

self.addEventListener( 'activate', function ( event ) {
	event.waitUntil(
		caches.keys().then( function ( keys ) {
			return Promise.all(
				keys.filter( function ( k ) { return k.indexOf( ABCHAT_CACHE_PREFIX ) === 0; } )
					.map( function ( k ) { return caches.delete( k ); } )
			);
		} ).then( function () { return self.clients.claim(); } )
	);
} );

/**
 * Never cache navigations: operator dashboard HTML contains private data.
 * A static, non-sensitive response provides a useful offline state.
 */
self.addEventListener( 'fetch', function ( event ) {
	var req = event.request;
	if ( req.method !== 'GET' ) { return; }
	if ( req.mode === 'navigate' ) {
		event.respondWith(
			fetch( req ).catch( function () {
				return new Response(
					'<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Chat offline</title></head><body><main><h1>Chat is offline</h1><p>Reconnect to view conversations and send messages.</p></main></body></html>',
					{ status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' } }
				);
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
