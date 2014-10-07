<?php
namespace TenUp\SwiftStream\Filters;

function setup() {
	$n = function( $function ) {
		return __NAMESPACE__ . "\\$function";
	};

	add_action( 'after_setup_theme',               $n( 'add_placeholder_sizes' ), 999, 0 );
	add_filter( 'image_resize_dimensions',         $n( 'upscale_dimensions' ),    1,   6 );
	add_filter( 'wp_generate_attachment_metadata', $n( 'filter_images' ),         10,  1 );
}

/**
 * Loop through registered image sizes and add placeholders
 *
 * @global array $_wp_additional_image_sizes
 */
function add_placeholder_sizes() {
	global $_wp_additional_image_sizes;

	$new_sizes = array();

	foreach( $_wp_additional_image_sizes as $name => $args ) {
		$new_sizes[ $name . '-ph' ] = $args;
	}

	$_wp_additional_image_sizes = array_merge( $_wp_additional_image_sizes, $new_sizes );
}

/**
 * Automatically resample and save the downsized versions of each graphic.
 *
 * @param array $meta
 *
 * @return array
 */
function filter_images( $meta ) {
	foreach( $meta['sizes'] as $name => $data ) {
		if ( false !== strpos( $name, '-ph' ) ) {
			$new_file = create_placeholder( $data['file'] );
			$meta['sizes'][ $name ]['file'] = $new_file;
		}
	}

	return $meta;
}

/**
 * Create a placeholder image given a regular image.
 *
 * @param string $image_filename
 *
 * @return string
 */
function create_placeholder( $image_filename ) {
	$uploads = wp_upload_dir();
	$file = trailingslashit( $uploads['path'] ) . $image_filename;
	$image = wp_get_image_editor( $file );

	if ( is_wp_error( $image ) ) {
		return $image_filename;
	}

	// Get proportions
	$size = $image->get_size();
	$old_width = $size['width'];
	$old_height = $size['height'];
	$width = $old_width / 10;
	$height = $old_height / 10;

	// Placeholder filename
	$new_name = preg_replace( '/(\.gif|\.jpg|\.jpeg|\.png)/', '-ph$1', $image_filename );

	$image->resize( $width, $height, false );
	$image->save( trailingslashit( $uploads['path'] ) . $new_name );
	$image->resize( $old_width, $old_height, false );
	$image->set_quality( 25 );
	$image->save( trailingslashit( $uploads['path'] ) . $new_name );

	return $new_name;
}

/**
 * Allow WordPress to upscale images.
 *
 * @see https://wordpress.org/support/topic/wp-351-wp-image-editor-scaling-up-images-does-not-work?replies=6
 *
 * @param null $null
 * @param int  $orig_w
 * @param int  $orig_h
 * @param int  $dest_w
 * @param int  $dest_h
 * @param bool $crop
 *
 * @return array|bool
 */
function upscale_dimensions( $null, $orig_w, $orig_h, $dest_w, $dest_h, $crop = false ) {
	if ( $crop ) {
		// crop the largest possible portion of the original image that we can size to $dest_w x $dest_h
		$aspect_ratio = $orig_w / $orig_h;
		$new_w        = min( $dest_w, $orig_w );
		$new_h        = min( $dest_h, $orig_h );

		if ( ! $new_w ) {
			$new_w = intval( $new_h * $aspect_ratio );
		}

		if ( ! $new_h ) {
			$new_h = intval( $new_w / $aspect_ratio );
		}

		$size_ratio = max( $new_w / $orig_w, $new_h / $orig_h );

		$crop_w = round( $new_w / $size_ratio );
		$crop_h = round( $new_h / $size_ratio );

		$s_x = floor( ( $orig_w - $crop_w ) / 2 );
		$s_y = floor( ( $orig_h - $crop_h ) / 2 );
	} else {
		// don't crop, just resize using $dest_w x $dest_h as a maximum bounding box
		$crop_w = $orig_w;
		$crop_h = $orig_h;

		$s_x = 0;
		$s_y = 0;

		/* wp_constrain_dimensions() doesn't consider higher values for $dest :( */
		/* So just use that function only for scaling down ... */
		if ( $orig_w >= $dest_w && $orig_h >= $dest_h ) {
			list( $new_w, $new_h ) = wp_constrain_dimensions( $orig_w, $orig_h, $dest_w, $dest_h );
		} else {
			$ratio = $dest_w / $orig_w;
			$w     = intval( $orig_w * $ratio );
			$h     = intval( $orig_h * $ratio );
			list( $new_w, $new_h ) = array( $w, $h );
		}
	}

	// Now WE need larger images ...
	if ( $new_w == $orig_w && $new_h == $orig_h ) {
		return false;
	}

	// the return array matches the parameters to imagecopyresampled()
	// int dst_x, int dst_y, int src_x, int src_y, int dst_w, int dst_h, int src_w, int src_h
	return array( 0, 0, (int) $s_x, (int) $s_y, (int) $new_w, (int) $new_h, (int) $crop_w, (int) $crop_h );
}