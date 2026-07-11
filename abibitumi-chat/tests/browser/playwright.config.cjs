const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: '.',
	testMatch: '*.spec.cjs',
	timeout: 15000,
	workers: 1,
	use: {
		browserName: 'chromium',
		channel: 'chrome',
		headless: true
	}
} );
