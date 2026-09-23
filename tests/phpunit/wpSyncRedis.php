<?php
/**
 * Redis wire protocol tests, independent of a Redis installation.
 *
 * @package GutenbergSyncEngines
 * @group collaboration
 */
class Tests_Collaboration_WpSyncRedis extends WP_UnitTestCase {
	private $peer;

	private function client(): WP_Sync_Redis {
		$pair       = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
		$this->peer = $pair[1];
		$reflection = new ReflectionClass( WP_Sync_Redis::class );
		$client     = $reflection->newInstanceWithoutConstructor();
		$socket     = $reflection->getProperty( 'socket' );
		$socket->setAccessible( true );
		$socket->setValue( $client, $pair[0] );
		stream_set_timeout( $pair[0], 1 );
		return $client;
	}

	public function tear_down() {
		if ( is_resource( $this->peer ) ) {
			fclose( $this->peer ); }
		parent::tear_down();
	}

	public function test_subscribe_waits_for_ack_and_receives_a_notice() {
		$client = $this->client();
		fwrite( $this->peer, "*3\r\n$9\r\nsubscribe\r\n$4\r\nroom\r\n:1\r\n" );
		$client->subscribe( array( 'room' ) );
		$this->assertSame( "*2\r\n$9\r\nSUBSCRIBE\r\n$4\r\nroom\r\n", fread( $this->peer, 1024 ) );
		fwrite( $this->peer, "*3\r\n$7\r\nmessage\r\n$4\r\nroom\r\n$7\r\nchanged\r\n" );
		$this->assertTrue( $client->wait( 0.01 ) );
		$this->assertFalse( $client->wait( 0.01 ) );
		$client->close();
	}

	public function test_redis_disconnect_ends_the_wait() {
		$client = $this->client();
		fclose( $this->peer );
		$this->expectException( RuntimeException::class );
		$client->wait( 0.01 );
	}

	public function test_redis_error_does_not_leak_credentials() {
		$client = $this->client();
		fwrite( $this->peer, "-ERR private server detail\r\n" );
		$this->expectExceptionMessage( 'Redis rejected the command.' );
		$client->read();
	}

	public function test_rejects_unbounded_replies() {
		$client = $this->client();
		fwrite( $this->peer, "$999999999\r\n" );
		$this->expectException( RuntimeException::class );
		$client->read();
	}
	public function test_reads_notices_already_buffered_with_the_subscription_ack() {
		$client = $this->client();
		fwrite( $this->peer, "*3\r\n$9\r\nsubscribe\r\n$4\r\nroom\r\n:1\r\n*3\r\n$7\r\nmessage\r\n$4\r\nroom\r\n$7\r\nchanged\r\n" );
		$client->subscribe( array( 'room' ) );
		$this->assertTrue( $client->wait( 0.01 ) );
	}
	public function test_channel_namespace_is_stable_and_separates_rooms() {
		$room    = 'postType/post:1';
		$channel = WP_Sync_Redis_Notifications::channel( $room );
		$this->assertSame( $channel, WP_Sync_Redis_Notifications::channel( $room ) );
		$this->assertNotSame( $channel, WP_Sync_Redis_Notifications::channel( 'postType/post:2' ) );
	}

	public function test_channel_namespace_ignores_the_hostname_a_site_is_reached_through() {
		$room       = 'postType/post:1';
		$channel    = WP_Sync_Redis_Notifications::channel( $room );
		$other_host = static fn() => 'https://alias.example';
		add_filter( 'home_url', $other_host );
		try {
			$this->assertSame( $channel, WP_Sync_Redis_Notifications::channel( $room ), 'One site behind several hostnames must share a channel.' );
		} finally {
			remove_filter( 'home_url', $other_host );
		}
	}

	public function test_channel_namespace_separates_table_prefixes_and_blog_ids() {
		global $wpdb, $blog_id;
		$prefix        = $wpdb->prefix;
		$original_blog = $blog_id;
		$channel       = WP_Sync_Redis_Notifications::channel( 'postType/post:1' );
		try {
			$wpdb->prefix = 'another_installation_';
			$this->assertNotSame( $channel, WP_Sync_Redis_Notifications::channel( 'postType/post:1' ) );
			$wpdb->prefix = $prefix;
			$blog_id      = (int) $original_blog + 1;
			$this->assertNotSame( $channel, WP_Sync_Redis_Notifications::channel( 'postType/post:1' ) );
		} finally {
			$wpdb->prefix = $prefix;
			$blog_id      = $original_blog;
		}
		$this->assertSame( $channel, WP_Sync_Redis_Notifications::channel( 'postType/post:1' ) );
	}
}
