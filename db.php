<?php

global $wpdb;
$charset_collate = $wpdb->get_charset_collate();
$table_name = $wpdb->prefix . 'third_party_integration';
$query = $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) );

if ( ! $wpdb->get_var( $query ) == $table_name ) {
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        tier mediumint(9) NOT NULL,
        uuid VARCHAR(250) NOT NULL,
        username VARCHAR(50) NOT NULL,
        substatus VARCHAR(50) NOT NULL,
        expirydate DATE NOT NULL,
        calltype VARCHAR(50) NOT NULL,
        UNIQUE KEY id (id)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}