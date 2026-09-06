<?php
// Docker ortamında admin panelinden eklenti/tema güncellemelerine izin ver
if ( ! defined( 'FS_METHOD' ) ) {
    define( 'FS_METHOD', 'direct' );
}
