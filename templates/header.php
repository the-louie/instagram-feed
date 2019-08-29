<?php
/**
 * Custom Feeds for Instagram Header Template
 * Adds account information and an avatar to the top of the feed
 *
 * @version 2.0 Custom Feeds for Instagram Free by Smash Balloon
 *
 */

// Don't load directly
if ( ! defined( 'ABSPATH' ) ) {
	die( '-1' );
}
$header_padding = (int)$settings['imagepadding'] > 0 ? 'padding: '.(int)$settings['imagepadding'] . $settings['imagepaddingunit'] . ';' : '';

$username = SB_Instagram_Parse::get_username( $header_data );
$avatar = SB_Instagram_Parse::get_avatar( $header_data, $settings );
$name = SB_Instagram_Parse::get_name( $header_data );
$header_text_color_style = SB_Instagram_Display_Elements::get_header_text_color_styles( $settings ); // style="color: #517fa4;" already escaped

?>
<div class="sb_instagram_header" style="<?php echo $header_padding; ?>padding-bottom: 0;">
    <a href="https://www.instagram.com/<?php echo urlencode( $username ); ?>" target="_blank" rel="noopener" title="@<?php echo esc_attr( $username ); ?>" class="sbi_header_link">
        <div class="sbi_header_text">
            <h3 class="sbi_no_bio" <?php echo $header_text_color_style; ?>><?php echo esc_html( $username ); ?></h3>
        </div>
        <div class="sbi_header_img" data-avatar-url="<?php echo esc_attr( SB_Instagram_Parse::get_avatar( $header_data ) ); ?>">
            <div class="sbi_header_img_hover"><?php echo SB_Instagram_Display_Elements::get_icon( 'newlogo', $icon_type ); ?></div>
            <img src="<?php echo esc_url( $avatar ); ?>" alt="<?php echo esc_attr( $name ); ?>" width="50" height="50">
        </div>
    </a>
</div>