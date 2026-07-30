<?php
namespace Lihi\ShortUrl;

/**
 * Request-lifetime singleton registry.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Lihi_Singletons {

    /**
     * @var array<string, object|null>
     */
    private static array $instances = [];

    private static function get( string $key ): ?object {
        return self::$instances[ $key ] ?? null;
    }

    private static function set( string $key, ?object $instance ): void {
        self::$instances[ $key ] = $instance;
    }

    public static function lihi_client(): Lihi_Client_Interface {
        $instance = self::get( Lihi_Client_Interface::class );

        if ( ! $instance instanceof Lihi_Client_Interface ) {
            $instance = new Lihi_Client( lihi_api_host() );
            self::set( Lihi_Client_Interface::class, $instance );
        }

        return $instance;
    }

    public static function lihi_client_set( ?Lihi_Client_Interface $client ): void {
        self::set( Lihi_Client_Interface::class, $client );
    }

    public static function lihi_token_store(): Lihi_Token_Store {
        $instance = self::get( Lihi_Token_Store::class );

        if ( ! $instance instanceof Lihi_Token_Store ) {
            $instance = new Lihi_Token_Store();
            self::set( Lihi_Token_Store::class, $instance );
        }

        return $instance;
    }

    public static function lihi_token_store_set( ?Lihi_Token_Store $store ): void {
        self::set( Lihi_Token_Store::class, $store );
    }

    public static function lihi_service(): Lihi_Service {
        $instance = self::get( Lihi_Service::class );

        if ( ! $instance instanceof Lihi_Service ) {
            $instance = new Lihi_Service( self::lihi_client(), self::lihi_token_store() );
            self::set( Lihi_Service::class, $instance );
        }

        return $instance;
    }

    public static function lihi_service_set( ?Lihi_Service $service ): void {
        self::set( Lihi_Service::class, $service );
    }
}
