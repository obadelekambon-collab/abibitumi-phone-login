const path = require( 'path' );
const { test, expect } = require( '@playwright/test' );

const asset = ( name ) => path.resolve( __dirname, '../../assets/js/' + name );

test( 'visitor widget opens, creates a conversation, and sends text safely', async ( { page } ) => {
	await page.setContent( '<div id="abchat-root"></div>' );
	await page.evaluate( () => {
		window.__calls = [];
		window.ABChatData = {
			restUrl: 'https://example.test/wp-json/abchat/v1', nonce: '', pwa: { enabled: false },
			config: {
				enabled: true, primaryColor: '#123456', textColor: '#ffffff', position: 'right',
				brandName: 'Test Chat', welcomeTitle: 'Hello', welcomeSubtitle: 'Welcome', isOpen: true,
				fileUploads: false, maxMessageLength: 120, prechat: { enabled: false }, departments: [],
				botEnabled: false, soundEnabled: false, streamEnabled: false, journeyTracking: true, pollInterval: 60
			},
			i18n: { send: 'Send', typeMessage: 'Type', startChat: 'Start', poweredBy: 'Powered', rateChat: 'Rate', thanks: 'Thanks', agentTyping: 'typing', attach: 'Attach', newMessages: 'New', online: 'Online', offline: 'Offline' }
		};
		window.fetch = ( url, options ) => {
			window.__calls.push( { url, options } );
			let data = { ok: true };
			if ( url.endsWith( '/session' ) ) { data = { enabled: true, token: 'visitor-token', visitor: { id: 1 }, config: { isOpen: true } }; }
			if ( url.endsWith( '/conversation' ) ) { data = { conversation: { id: 7 }, messages: [], isOpen: true }; }
			return Promise.resolve( { ok: true, json: () => Promise.resolve( data ) } );
		};
	} );
	await page.addScriptTag( { path: asset( 'widget.js' ) } );
	await expect( page.locator( '.abchat-launcher' ) ).toHaveAttribute( 'aria-label', 'Start' );
	await page.locator( '.abchat-launcher' ).click();
	await expect( page.locator( '#abchat-root' ) ).toHaveClass( /abchat-open/ );
	await expect.poll( () => page.evaluate( () => window.__calls.some( call => call.url.endsWith( '/conversation' ) ) ) ).toBe( true );
	await page.locator( '#abchat-text' ).fill( '<img src=x onerror=window.__xss=1>' );
	await page.locator( '#abchat-form' ).evaluate( form => form.requestSubmit() );
	await expect( page.locator( '.abchat-bubble' ).last() ).toContainText( '<img src=x onerror=window.__xss=1>' );
	await expect( page.locator( '.abchat-bubble img' ) ).toHaveCount( 0 );
	await expect.poll( () => page.evaluate( () => window.__calls.some( call => call.url.endsWith( '/message' ) ) ) ).toBe( true );
	await page.evaluate( () => { location.hash = 'membership-checkout'; } );
	await expect.poll( () => page.evaluate( () => window.__calls.some( call => call.url.endsWith( '/page-view' ) ) ) ).toBe( true );
} );

test( 'operator dashboard renders hostile API text without executing markup', async ( { page } ) => {
	await page.setContent( '<div id="abchat-dashboard"><input id="abchat-search"><input id="abchat-mine" type="checkbox"><button class="abchat-tab active" data-filter="open"></button><span id="abchat-count-open"></span><span id="abchat-count-pending"></span><span id="abchat-count-closed"></span><div id="abchat-list"></div></div>' );
	await page.evaluate( () => {
		window.ABChatAdmin = { restUrl: '/wp-json/abchat/v1', nonce: 'nonce', pollInterval: 60, streamEnabled: false, pushEnabled: false, openConvo: 0 };
		window.fetch = url => {
			const data = url.indexOf( '/agent/canned' ) > -1 ? { canned: [] } : {
				conversations: [ { id: 4, visitorName: '<img src=x onerror=window.__xss=1>', lastMessage: '<script>window.__xss=1</script>', department: 'support', updatedAt: '', unread: 1, online: true } ],
				counts: { open: 1, pending: 0, closed: 0 }
			};
			return Promise.resolve( { ok: true, json: () => Promise.resolve( data ) } );
		};
	} );
	await page.addScriptTag( { path: asset( 'admin.js' ) } );
	await expect( page.locator( '.abchat-li-name' ) ).toContainText( '<img src=x onerror=window.__xss=1>' );
	await expect( page.locator( '.abchat-li-msg' ) ).toContainText( '<script>window.__xss=1</script>' );
	await expect( page.locator( '#abchat-list img, #abchat-list script' ) ).toHaveCount( 0 );
	await expect.poll( () => page.evaluate( () => window.__xss || 0 ) ).toBe( 0 );
} );
