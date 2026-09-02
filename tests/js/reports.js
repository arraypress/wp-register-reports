/**
 * Tests for the reports script.
 *
 * Fourteen hundred lines that nothing had ever executed. The PHP suite
 * renders the page and stops there, so everything the page does *after* it
 * loads — the auto-refresh, the date picker, the export polling, the table
 * paging — was covered by nobody looking at it in a browser and noticing.
 *
 * Two kinds of test here, and the split is deliberate:
 *
 * - Behaviour, for the parts that do not need a browser: URL reading, the
 *   escapers, the per-copy config lookup, colour conversion. These are run
 *   for real against a jQuery stub that matches nothing, which is a page with
 *   no reports on it — a legitimate state the script must survive.
 *
 * - Source, for invariants a stub cannot honestly check. Asserting that
 *   escapeHtml() escapes `<` against a stubbed document only proves the stub
 *   escapes `<`. What is worth pinning is where its output is allowed to go,
 *   since it does not escape quotes.
 *
 * Deliberately no jsdom: this library has no JavaScript dependencies and a
 * test suite is a poor reason to acquire the first one.
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const vm = require( 'vm' );

const file = path.join( __dirname, '..', '..', 'assets', 'js', 'reports.js' );
const source = fs.readFileSync( file, 'utf8' );

let failures = 0;

/**
 * Report a failure.
 *
 * @param {string} message What went wrong.
 */
function fail( message ) {
	console.error( '  ' + message );
	failures ++;
}

/**
 * Assert two values match.
 *
 * @param {*}      actual   What happened.
 * @param {*}      expected What should have.
 * @param {string} what     The name of the thing.
 */
function is( actual, expected, what ) {
	if ( actual !== expected ) {
		fail( `${ what }: expected ${ JSON.stringify( expected ) }, got ${ JSON.stringify( actual ) }` );
	}
}

/**
 * A jQuery that matches nothing.
 *
 * Every method returns the collection so chains complete, `length` is 0 and
 * `data()`/`attr()`/`val()` are undefined — which is the honest answer for an
 * empty set and the state the script hits on any admin page that is not a
 * report. A page where init() throws is a page where every later handler is
 * never bound, and the only symptom is that nothing works.
 *
 * @return {Function} The stub.
 */
function makeJQuery() {
	const calls = [];

	const collection = new Proxy( function () {}, {
		get( target, name ) {
			if ( 'length' === name ) {
				return 0;
			}

			if ( 'then' === name || Symbol.toPrimitive === name || 'constructor' === name ) {
				return undefined;
			}

			return function () {
				calls.push( name );

				return collection;
			};
		},
	} );

	const $ = function ( thing ) {
		// $(function () {…}) is the ready handler. Run it, because that is
		// what a browser does and it is the path init() is reached by.
		if ( 'function' === typeof thing ) {
			thing();
		}

		return collection;
	};

	$.ajax = () => collection;
	$.each = ( items, each ) => {
		Object.keys( items || {} ).forEach( ( key ) => each( key, items[ key ] ) );
	};
	$.extend = Object.assign;
	$.calls = calls;

	return $;
}

/**
 * Load the script with a given environment.
 *
 * @param {Object} overrides Anything to put in the context.
 *
 * @return {Object} The context after loading.
 */
function load( overrides ) {
	const context = Object.assign(
		{
			// Quiet: the script warns about Chart.js on every load, which is
			// correct — there is no Chart.js here — and six copies of it
			// would bury a real failure.
			console: Object.assign( Object.create( console ), { log() {}, warn() {}, error() {} } ),
			URL,
			URLSearchParams,
			Date,
			Math,
			JSON,
			parseInt,
			parseFloat,
			isNaN,
			setTimeout: () => 0,
			clearTimeout: () => {},
			setInterval: () => 0,
			clearInterval: () => {},
			jQuery: makeJQuery(),
			document: {
				currentScript: { id: 'arraypress-reports-js' },
				addEventListener() {},
				createElement: () => ( {} ),
				hidden: false,
			},
		},
		overrides
	);

	context.window = context.window || {};
	context.window.location = context.window.location || { href: 'https://example.test/wp-admin/admin.php?page=reports' };
	context.globalThis = context;

	vm.createContext( context );
	vm.runInContext( source, context, { filename: 'reports.js' } );

	return context;
}

/* -------------------------------------------------------------------------
 * It loads, and init() survives a page with no reports on it.
 * ---------------------------------------------------------------------- */

let ctx;

try {
	ctx = load( {} );
} catch ( error ) {
	console.error( `  the script threw while loading: ${ error.message }` );
	process.exit( 1 );
}

const controller = ctx.window.ReportsController;

if ( ! controller ) {
	console.error( '  the script exposed no controller' );
	process.exit( 1 );
}

// The ready handler runs init(), so reaching here at all means init() did not
// throw. Assert it actually ran rather than inferring it.
if ( ! ctx.jQuery.calls.length ) {
	fail( 'init: nothing was bound, so the ready handler never ran' );
}

/* -------------------------------------------------------------------------
 * Each prefixed copy reads its own configuration.
 *
 * Strauss renames classes, not script handles or global keys, so two plugins
 * bundling this library land on one screen with two copies of reports.js and
 * one window. Resolving by the handle WordPress stamped on the executing
 * script is what stops the second copy answering with the first one's REST
 * namespace and nonce.
 * ---------------------------------------------------------------------- */

( function () {
	const shared = {
		'plugin-a-reports': { restUrl: '/a/', nonce: 'aaa' },
		'plugin-b-reports': { restUrl: '/b/', nonce: 'bbb' },
	};

	[ 'a', 'b' ].forEach( ( which ) => {
		const context = load( {
			window: { ArrayPressReports: shared },
			document: {
				currentScript: { id: `plugin-${ which }-reports-js` },
				addEventListener() {},
				createElement: () => ( {} ),
				hidden: false,
			},
		} );

		// The config is closed over rather than exposed, so it is reached
		// through something that reads it.
		is(
			context.window.ReportsController.i18n( 'noData' ),
			'noData',
			`i18n survives copy ${ which } having no strings`
		);
	} );

	// The fallback path: no registry entry matches the handle, so the config
	// is the bare {} at the end of the chain. Every i18n() call used to be a
	// TypeError there — which is the one situation the fallback exists to
	// survive.
	const bare = load( {
		window: {},
		document: {
			currentScript: { id: 'some-other-plugin-js' },
			addEventListener() {},
			createElement: () => ( {} ),
			hidden: false,
		},
	} );

	if ( ! bare.window.ReportsController ) {
		fail( 'config: the script did not load without a handle registry' );
	}

	is( bare.window.ReportsController.i18n( 'noData' ), 'noData', 'i18n with no config at all' );

	// Substitution still works, since that is the reason it is a function.
	is(
		bare.window.ReportsController.i18n( '%1$s of %2$s', 'one', 'three' ),
		'one of three',
		'i18n: positional substitution'
	);
} )();

/* -------------------------------------------------------------------------
 * Reading the request.
 * ---------------------------------------------------------------------- */

( function () {
	const context = load( {
		window: {
			location: {
				href: 'https://example.test/wp-admin/admin.php?page=reports&tab=sales'
					+ '&date_preset=last_30&date_start=2026-01-01&date_end=2026-01-31'
					+ '&filter_status=complete&filter_gateway=stripe&orderby=name',
			},
		},
	} );

	const it = context.window.ReportsController;

	is( it.getCurrentTab(), 'sales', 'getCurrentTab' );
	is( it.getCurrentDatePreset(), 'last_30', 'getCurrentDatePreset' );
	is( it.getCurrentDateStart(), '2026-01-01', 'getCurrentDateStart' );
	is( it.getCurrentDateEnd(), '2026-01-31', 'getCurrentDateEnd' );

	const filters = it.getCurrentFilters();

	is( JSON.stringify( filters ), JSON.stringify( { filter_status: 'complete', filter_gateway: 'stripe' } ), 'getCurrentFilters' );

	// A multiselect arrives as repeated bracketed keys. Read one at a time,
	// each repeat overwrote the last and the refresh carried one status
	// where the page showed two.
	const multi = load( {
		window: {
			location: {
				href: 'https://example.test/wp-admin/admin.php?page=reports'
					+ '&filter_status%5B%5D=complete&filter_status%5B%5D=refunded&filter_gateway=stripe',
			},
		},
	} );

	is(
		JSON.stringify( multi.window.ReportsController.getCurrentFilters() ),
		JSON.stringify( { filter_status: [ 'complete', 'refunded' ], filter_gateway: 'stripe' } ),
		'getCurrentFilters with a multiselect'
	);

	// A URL with none of it must not invent values: an empty tab means "the
	// first one", and a made-up preset would silently change what is shown.
	const bare = load( { window: { location: { href: 'https://example.test/wp-admin/admin.php?page=reports' } } } );

	is( bare.window.ReportsController.getCurrentTab(), '', 'getCurrentTab with no tab' );
	is( JSON.stringify( bare.window.ReportsController.getCurrentFilters() ), '{}', 'getCurrentFilters with none' );

	// A missing date_preset means the report's own default, not a hardcoded
	// one. PHP reads default_preset from the report's config; the script used
	// to answer this_month regardless, so a report configuring anything else
	// got its own default on the first render and lost it on the first
	// refresh.
	const configured = load( {
		window: {
			ArrayPressReports: { 'arraypress-reports': { defaultPreset: 'last_7' } },
			location: { href: 'https://example.test/wp-admin/admin.php?page=reports' },
		},
	} );

	is( configured.window.ReportsController.getCurrentDatePreset(), 'last_7', 'getCurrentDatePreset: the report default' );
	is( bare.window.ReportsController.getCurrentDatePreset(), 'this_month', 'getCurrentDatePreset: the fallback' );
} )();

/* -------------------------------------------------------------------------
 * escapeJs, which writes into a JavaScript string inside an onclick.
 *
 * PHP does the same job with esc_js(), which runs addslashes() — all three of
 * quote, double quote and backslash. This escaped two of them, so a value
 * ending in a backslash escaped the closing quote instead of itself and
 * everything after it was code.
 * ---------------------------------------------------------------------- */

( function () {
	const escape = controller.escapeJs.bind( controller );

	is( escape( "it's" ), "it\\'s", 'escapeJs: single quote' );
	is( escape( 'say "hi"' ), 'say \\"hi\\"', 'escapeJs: double quote' );
	is( escape( 'a\\b' ), 'a\\\\b', 'escapeJs: backslash' );

	// The attack the missing backslash allowed: the trailing backslash used
	// to escape the quote the script closes with.
	const payload = 'x\\';
	const inside = "return confirm('" + escape( payload ) + "')";

	if ( ! inside.endsWith( "\\\\')" ) ) {
		fail( `escapeJs: a trailing backslash still breaks out — ${ inside }` );
	}

	// Not a string: handed back untouched rather than crashing on .replace.
	is( escape( 42 ), 42, 'escapeJs: a number is left alone' );
} )();

/* -------------------------------------------------------------------------
 * hexToRgba, which paints the fill under a chart line.
 * ---------------------------------------------------------------------- */

( function () {
	is( controller.hexToRgba( '#2271b1', 0.2 ), 'rgba(34, 113, 177, 0.2)', 'hexToRgba' );
	is( controller.hexToRgba( '#000000', 1 ), 'rgba(0, 0, 0, 1)', 'hexToRgba: black' );
} )();

/* -------------------------------------------------------------------------
 * Source invariants.
 * ---------------------------------------------------------------------- */

( function () {
	// escapeHtml goes through the DOM — textContent in, innerHTML out — which
	// escapes `<`, `>` and `&` and does NOT escape quotes. That is fine in
	// text position and an injection inside an attribute, so the one place it
	// is used has to stay a text position.
	const uses = source.match( /^.*escapeHtml\(.*$/gm ) || [];

	uses.forEach( ( line ) => {
		if ( /=\s*['"]/.test( line ) && ! /^\s*escapeHtml/.test( line ) ) {
			fail( `escapeHtml is used inside an attribute, where it escapes no quotes: ${ line.trim() }` );
		}
	} );

	if ( 1 !== ( source.match( /this\.escapeHtml\(/g ) || [] ).length ) {
		fail( 'escapeHtml has more call sites than the one this test checked' );
	}

	// The script must not format table cells. The server formats them with
	// the same code that renders the page; a second formatter here is how the
	// refresh came to print dollars on a site configured for euros and the
	// browser's date format on a site configured for another.
	[ 'formatCellValue', 'formatCurrency', 'formatNumber:', 'formatDateTime' ].forEach( ( name ) => {
		if ( source.includes( name ) ) {
			fail( `${ name } is back in the script; the server formats cells now` );
		}
	} );

	// And it must use the cells as given.
	if ( ! /\.html\(row\[columnKey\]/.test( source ) ) {
		fail( 'updateTable no longer writes the cell the server sent' );
	}

	// Row action URLs substitute from the unformatted row. An id rendered for
	// display is 1,204 and links to nothing.
	if ( ! /Object\.keys\(raw\)\.forEach/.test( source ) ) {
		fail( 'row action URLs are no longer built from the raw row' );
	}
} )();

if ( failures ) {
	console.error( `\n${ failures } failure(s)` );
	process.exit( 1 );
}

console.log( '  controller loads, init survives an empty page, URL reading and escaping hold' );
