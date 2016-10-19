<?php

use Vendi\WordPress\Caching\cache_exclusion;

class test_cache_exclusion extends WP_UnitTestCase
{

    /**
     * @dataProvider provider_for_static_factories
     *  
     * @covers Vendi\WordPress\Caching\cache_exclusion
     */
    public function test_static_factories( $method, string $property, string $comparison, array $tests )
    {

        foreach( $tests as $test )
        {
            $exclusion = $method( $test[ 0 ] );
            $this->assertSame( $property, $exclusion->get_property() );
            $this->assertSame( $comparison, $exclusion->get_comparison() );
            $this->assertSame( $test[ 0 ], $exclusion->get_value() );

            $this->assertSame( $test[ 2 ], $exclusion->process_rule( $test[ 1 ] ) );
        }
    }

    /**
     * @dataProvider provider_for_create_from_legacy
     *  
     * @covers Vendi\WordPress\Caching\cache_exclusion::create_from_legacy
     */
    public function test_create_from_legacy( string $pt, string $property, string $comparison )
    {
        $text = '/test/';

        $exclusion = cache_exclusion::create_from_legacy( array( 'pt' => $pt, 'p' => $text ) );
        $this->assertSame( $property, $exclusion->get_property() );
        $this->assertSame( $comparison, $exclusion->get_comparison() );
        $this->assertSame( $text, $exclusion->get_value() );
    }

    /**
     * @expectedException \Vendi\WordPress\Caching\cache_setting_exception
     *  
     * @covers Vendi\WordPress\Caching\cache_exclusion::create_from_legacy
     */
    public function test_create_from_legacy_unknown_property( )
    {
        cache_exclusion::create_from_legacy( array( 'pt' => 'invalid', 'p' => '' ) );
    }

    /**
     * @expectedException \Vendi\WordPress\Caching\cache_setting_exception
     *  
     * @covers Vendi\WordPress\Caching\cache_exclusion::set_property
     */
    public function test_set_property_invalid( )
    {
        cache_exclusion::create_url_exact( 'test' )->set_property( 'invalid' );
    }

    /**
     * @expectedException \Vendi\WordPress\Caching\cache_setting_exception
     *  
     * @covers Vendi\WordPress\Caching\cache_exclusion::set_comparison
     */
    public function test_set_comparison_invalid()
    {
        cache_exclusion::create_url_exact( 'test' )->set_comparison( 'invalid' );
    }

    /**
     * @expectedException \Vendi\WordPress\Caching\cache_setting_exception
     *  
     * @covers Vendi\WordPress\Caching\cache_exclusion::create_from_legacy
     */
    public function test_create_from_legacy_not_array()
    {
        cache_exclusion::create_from_legacy( 'string' );
    }

    /**
     * @expectedException \Vendi\WordPress\Caching\cache_setting_exception
     *  
     * @covers Vendi\WordPress\Caching\cache_exclusion::create_from_legacy
     */
    public function test_create_from_legacy_missing_key()
    {
        cache_exclusion::create_from_legacy( array( 'test' => 'invalid' ) );
    }

    /**
     * @expectedException \Vendi\WordPress\Caching\cache_setting_exception
     *  
     * @covers Vendi\WordPress\Caching\cache_exclusion::create_from_legacy
     */
    public function test_create_from_legacy_missing_key_other()
    {
        cache_exclusion::create_from_legacy( array( 'pt' => 'invalid', 'x' => 'y' ) );
    }

    public function provider_for_create_from_legacy()
    {
        return array(
                    array( 'eq',     cache_exclusion::PROPERTY_URL,          cache_exclusion::COMPARISON_EXACT ),
                    array( 'c',      cache_exclusion::PROPERTY_URL,          cache_exclusion::COMPARISON_CONTAINS ),
                    array( 'e',      cache_exclusion::PROPERTY_URL,          cache_exclusion::COMPARISON_ENDS_WITH ),
                    array( 's',      cache_exclusion::PROPERTY_URL,          cache_exclusion::COMPARISON_STARTS_WITH ),

                    array( 'uac',    cache_exclusion::PROPERTY_USER_AGENT,   cache_exclusion::COMPARISON_CONTAINS ),
                    array( 'uaeq',   cache_exclusion::PROPERTY_USER_AGENT,   cache_exclusion::COMPARISON_EXACT ),

                    array( 'cc',     cache_exclusion::PROPERTY_COOKIE_NAME,  cache_exclusion::COMPARISON_CONTAINS ),
            );
    }

    public function provider_for_static_factories()
    {
        return array(
                    array(
                        array( cache_exclusion::class, 'create_url_exact' ),
                        cache_exclusion::PROPERTY_URL,
                        cache_exclusion::COMPARISON_EXACT,
                        array(
                            array( 'abc', 'abc', true ),
                        ),
                    ),
                    array(
                        array( cache_exclusion::class, 'create_url_contains' ),
                        cache_exclusion::PROPERTY_URL,
                        cache_exclusion::COMPARISON_CONTAINS,
                        array(
                            array( 'abc', 'abc', true ),
                        ),
                    ),
                    array(
                        array( cache_exclusion::class, 'create_url_ends_with' ),
                        cache_exclusion::PROPERTY_URL,
                        cache_exclusion::COMPARISON_ENDS_WITH,
                        array(
                            array( 'abc', 'c', true ),
                        ),
                    ),
                    array(
                        array( cache_exclusion::class, 'create_url_starts_with' ),
                        cache_exclusion::PROPERTY_URL,
                        cache_exclusion::COMPARISON_STARTS_WITH,
                        array(
                            array( 'abc', 'a', true ),
                        ),
                    ),



                    array(
                        array( cache_exclusion::class, 'create_user_agent_contains' ),
                        cache_exclusion::PROPERTY_USER_AGENT,
                        cache_exclusion::COMPARISON_CONTAINS,
                        array(
                            array( 'abc', 'b', true ),
                        ),
                    ),
                    array(
                        array( cache_exclusion::class, 'create_user_agent_exact' ),
                        cache_exclusion::PROPERTY_USER_AGENT,
                        cache_exclusion::COMPARISON_EXACT,
                        array(
                            array( 'abc', 'abc', true ),
                        ),
                    ),



                    array(
                        array( cache_exclusion::class, 'create_cookie_contains' ),
                        cache_exclusion::PROPERTY_COOKIE_NAME,
                        cache_exclusion::COMPARISON_CONTAINS,
                        array(
                            array( 'abc', array( 'b' => false ), true ),
                        ),
                    ),
            );
    }
}
