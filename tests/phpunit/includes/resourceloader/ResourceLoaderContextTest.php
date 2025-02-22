<?php

/**
 * See also:
 * - ResourceLoaderImageModuleTest::testContext
 *
 * @group ResourceLoader
 * @covers ResourceLoaderContext
 */
class ResourceLoaderContextTest extends PHPUnit\Framework\TestCase {

	use MediaWikiCoversValidator;

	protected static function getResourceLoader() {
		return new EmptyResourceLoader( new HashConfig( [
			'ResourceLoaderDebug' => false,
			'LoadScript' => '/w/load.php',
			// For ResourceLoader::register()
			'ResourceModuleSkinStyles' => [],
		] ) );
	}

	public function testEmpty() {
		$ctx = new ResourceLoaderContext( $this->getResourceLoader(), new FauxRequest( [] ) );

		// Request parameters
		$this->assertEquals( [], $ctx->getModules() );
		$this->assertEquals( 'qqx', $ctx->getLanguage() );
		$this->assertEquals( false, $ctx->getDebug() );
		$this->assertEquals( null, $ctx->getOnly() );
		$this->assertEquals( 'fallback', $ctx->getSkin() );
		$this->assertEquals( null, $ctx->getUser() );
		$this->assertNull( $ctx->getContentOverrideCallback() );

		// Misc
		$this->assertEquals( 'ltr', $ctx->getDirection() );
		$this->assertEquals( 'qqx|fallback||||||||', $ctx->getHash() );
		$this->assertSame( [], $ctx->getReqBase() );
		$this->assertInstanceOf( User::class, $ctx->getUserObj() );
	}

	public function testDummy() {
		$this->assertInstanceOf(
			ResourceLoaderContext::class,
			ResourceLoaderContext::newDummyContext()
		);
	}

	public function testAccessors() {
		$ctx = new ResourceLoaderContext( $this->getResourceLoader(), new FauxRequest( [] ) );
		$this->assertInstanceOf( ResourceLoader::class, $ctx->getResourceLoader() );
		$this->assertInstanceOf( WebRequest::class, $ctx->getRequest() );
		$this->assertInstanceOf( Psr\Log\LoggerInterface::class, $ctx->getLogger() );
	}

	public function testTypicalRequest() {
		$ctx = new ResourceLoaderContext( $this->getResourceLoader(), new FauxRequest( [
			'debug' => 'false',
			'lang' => 'zh',
			'modules' => 'foo|foo.quux,baz,bar|baz.quux',
			'only' => 'styles',
			'skin' => 'fallback',
		] ) );

		// Request parameters
		$this->assertEquals(
			$ctx->getModules(),
			[ 'foo', 'foo.quux', 'foo.baz', 'foo.bar', 'baz.quux' ]
		);
		$this->assertEquals( false, $ctx->getDebug() );
		$this->assertEquals( 'zh', $ctx->getLanguage() );
		$this->assertEquals( 'styles', $ctx->getOnly() );
		$this->assertEquals( 'fallback', $ctx->getSkin() );
		$this->assertEquals( null, $ctx->getUser() );

		// Misc
		$this->assertEquals( 'ltr', $ctx->getDirection() );
		$this->assertEquals( 'zh|fallback|||styles|||||', $ctx->getHash() );
		$this->assertSame( [ 'lang' => 'zh' ], $ctx->getReqBase() );
	}

	public static function provideDirection() {
		yield 'LTR language' => [
			[ 'lang' => 'en' ],
			'ltr',
		];
		yield 'RTL language' => [
			[ 'lang' => 'he' ],
			'rtl',
		];
		yield 'explicit LTR' => [
			[ 'lang' => 'he', 'dir' => 'ltr' ],
			'ltr',
		];
		yield 'explicit RTL' => [
			[ 'lang' => 'en', 'dir' => 'rtl' ],
			'rtl',
		];
		// Not supported, but tested to cover the case and detect change
		yield 'invalid dir' => [
			[ 'lang' => 'he', 'dir' => 'xyz' ],
			'rtl',
		];
	}

	/**
	 * @dataProvider provideDirection
	 */
	public function testDirection( array $params, $expected ) {
		$ctx = new ResourceLoaderContext( $this->getResourceLoader(), new FauxRequest( $params ) );
		$this->assertEquals( $expected, $ctx->getDirection() );
	}

	public function testShouldInclude() {
		$ctx = new ResourceLoaderContext( $this->getResourceLoader(), new FauxRequest( [] ) );
		$this->assertTrue( $ctx->shouldIncludeScripts(), 'Scripts in combined' );
		$this->assertTrue( $ctx->shouldIncludeStyles(), 'Styles in combined' );
		$this->assertTrue( $ctx->shouldIncludeMessages(), 'Messages in combined' );

		$ctx = new ResourceLoaderContext( $this->getResourceLoader(), new FauxRequest( [
			'only' => 'styles'
		] ) );
		$this->assertFalse( $ctx->shouldIncludeScripts(), 'Scripts not in styles-only' );
		$this->assertTrue( $ctx->shouldIncludeStyles(), 'Styles in styles-only' );
		$this->assertFalse( $ctx->shouldIncludeMessages(), 'Messages not in styles-only' );

		$ctx = new ResourceLoaderContext( $this->getResourceLoader(), new FauxRequest( [
			'only' => 'scripts'
		] ) );
		$this->assertTrue( $ctx->shouldIncludeScripts(), 'Scripts in scripts-only' );
		$this->assertFalse( $ctx->shouldIncludeStyles(), 'Styles not in scripts-only' );
		$this->assertFalse( $ctx->shouldIncludeMessages(), 'Messages not in scripts-only' );
	}

	public function testGetUser() {
		$ctx = new ResourceLoaderContext( $this->getResourceLoader(), new FauxRequest( [] ) );
		$this->assertSame( null, $ctx->getUser() );
		$this->assertTrue( $ctx->getUserObj()->isAnon() );

		$ctx = new ResourceLoaderContext( $this->getResourceLoader(), new FauxRequest( [
			'user' => 'Example'
		] ) );
		$this->assertSame( 'Example', $ctx->getUser() );
		$this->assertEquals( 'Example', $ctx->getUserObj()->getName() );
	}

	public function testMsg() {
		$ctx = new ResourceLoaderContext( $this->getResourceLoader(), new FauxRequest( [
			'lang' => 'en'
		] ) );
		$msg = $ctx->msg( 'mainpage' );
		$this->assertInstanceOf( Message::class, $msg );
		$this->assertSame( 'Main Page', $msg->useDatabase( false )->plain() );
	}
}
