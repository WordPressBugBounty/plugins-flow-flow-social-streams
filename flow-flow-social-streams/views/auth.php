<?php

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'FF_USE_WP' ) || FF_USE_WP ) {
		exit;
	}
}

// phpcs:disable
 if ( ! defined( 'WPINC' ) )  die;
/**
 * FlowFlow.
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>
 *
 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 * @var array $context
 */
$options = (isset($context['options']) && is_array($context['options'])) ? $context['options'] : array();
$auth = (isset($context['auth_options']) && is_array($context['auth_options'])) ? $context['auth_options'] : array();
$auth['facebook_access_token'] = isset($auth['facebook_access_token']) ? $auth['facebook_access_token'] : '';

if (defined('FF_USE_WP') && FF_USE_WP && function_exists('set_transient') && function_exists('get_current_user_id')) {
    set_transient('flow_flow_auth_pending_' . get_current_user_id(), true, 15 * MINUTE_IN_SECONDS);
}

$fb_own_app = la\core\settings\LASettingsUtils::YepNope2ClassicStyleSafe($auth, 'facebook_use_own_app', false);
//$facebook_long_life_token = $context['facebook_long_life_token'];
?>
<div class="section-content" data-tab="auth-tab">
    <div class="section" id="auth-settings">
        <h1 class="desc-following" style="min-height: 38px"><span style="vertical-align: middle;line-height: 38px;">Facebook and Instagram integration</span> <span id="facebook-auth" class='admin-button auth-button blue-button'>Connect</span></h1>
        <p class="desc">Single login to access Facebook and Instagram. <a target="_blank" href="http://docs.social-streams.com/article/46-authenticate-with-facebook">More info</a></p>
        <dl class="section-settings ff-auth-tiktok-settings ff-auth-tiktok-settings--meta">
            <dt class="ff-toggler ff-fb-own-app" <?php echo $fb_own_app ? '' : 'style="display:none"' ?>>Use own app <p class="desc">Deprecated, please get token via our app</p></dt>
            <dd class="ff-toggler ff-fb-own-app" <?php echo $fb_own_app ? '' : 'style="display:none"' ?>>
                <label><input class="clearcache switcher" <?php echo $fb_own_app ? 'checked' : ''?> type="checkbox" id="facebook_use_own_app" name="flow_flow_fb_auth_options[facebook_use_own_app]" value="yep"/><div><div></div></div></label>
            </dd>
            <dt class="vert-aligned">Access Token</dt>
            <dd>
                <input class="clearcache" type="text" id="facebook_access_token" name="flow_flow_fb_auth_options[facebook_access_token]" placeholder="Acquired from Facebook" value="<?php echo esc_attr($auth['facebook_access_token'])?>"/><a <?php echo $fb_own_app ? 'style="display:none"' : '' ?> class="ff-pseudo-link" href="#" id="fb-refresh-token">Refresh token</a>
			    <?php
			    $extended = $context['extended_facebook_access_token'];
			    if(!empty($auth['facebook_access_token']) && !empty($extended) ) {
				    //if ($auth['facebook_access_token'] != $extended)
					    echo '<p class="desc ff-hide" style="margin: 30px 0 5px">Generated long-life token</p><textarea class="ff-hide" disabled rows=3>' . $extended . '</textarea>';
			    } else {
				    if (empty($extended)) {
					    echo '<p class="desc fb-token-notice" style="margin: 10px 0 5px; color: red !important">! Extended token is not generated, Facebook feeds might not work</p>';
				    }
			    }
			    ?>
            </dd>
            <dt class="vert-aligned">Token Status</dt>
            <dd id="fb-token-status">
                <?php
                // Facebook token expiry is managed by LAFacebookCacheManager
                // Check if we have extended token info
                $fb_token_status = '';
                if (!empty($context['extended_facebook_access_token'])) {
                    $fb_token_status = '<span style="color:#28a745;font-weight:500;">✓ Active</span>';
                } else if (!empty($auth['facebook_access_token'])) {
                    $fb_token_status = '<span style="color:#ffc107;font-weight:500;">⚠ Needs Extension</span>';
                } else {
                    $fb_token_status = '<span style="color:#999;">— Not connected</span>';
                }
                echo $fb_token_status;
                ?>
            </dd>
            <dt class="vert-aligned">Connected Account</dt>
            <dd id="fb-connected-user-dd">
                <?php
                $fb_name = isset($options['facebook_user_name']) ? trim($options['facebook_user_name']) : '';
                $fb_pic  = isset($options['facebook_userpic']) ? esc_url($options['facebook_userpic']) : '';
                if ($fb_name !== '' || $fb_pic !== '') {
                    echo '<div class="fb-user-display" style="display:flex;align-items:center;gap:10px;margin:5px 0;">';
                    if ($fb_pic !== '') {
                        echo '<img src="' . $fb_pic . '" alt="" style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid #e0e0e0;box-shadow:0 1px 3px rgba(0,0,0,0.1)">';
                    }
                    echo '<div class="fb-user-info" style="line-height:1.3">';
                    if ($fb_name !== '') {
                        echo '<div><strong id="facebook_user_name">' . esc_html($fb_name) . '</strong></div>';
                    }
                    echo '</div>';
                    echo '</div>';
                } else {
                    echo '<span class="desc">— Not connected</span>';
                }
                ?>
            </dd>
            <dt class="vert-aligned own-app-input">APP ID</dt>
            <dd class="own-app-input">
                <input class="clearcache" type="text" name="flow_flow_fb_auth_options[facebook_app_id]" placeholder="Copy and paste from Facebook" value="<?php echo isset($auth['facebook_app_id']) ? esc_attr($auth['facebook_app_id']) : ''?>"/>
            </dd>
            <dt class="vert-aligned own-app-input">APP Secret</dt>
            <dd class="own-app-input">
                <input class="clearcache" type="text" name="flow_flow_fb_auth_options[facebook_app_secret]" placeholder="Copy and paste from Facebook" value="<?php echo isset($auth['facebook_app_secret']) ? esc_attr($auth['facebook_app_secret']) : ''?>"/>
            </dd>
        </dl>
        <p class="button-wrapper"><span id="fb-auth-settings-sbmt" class='admin-button green-button submit-button'>Save Changes</span></p>
        <?php
        $slug = \la\core\LAUtils::slug($context);
        $is_lite = ($slug === 'flow-flow-lite' || $slug === 'flow-flow-social-streams');
        if (!$is_lite):
        ?>
        <div class="ff-auth-tiktok-section">
        <h1 class="desc-following ff-auth-tiktok-heading">TikTok integration <span id="tiktok-auth" class='admin-button auth-button blue-button'>Connect</span></h1>
        <p class="desc ff-auth-tiktok-desc"><a target="_blank" href="https://developers.tiktok.com/doc/">Setup guide</a></p>
        <?php if (!empty($options['tiktok_access_token'])): ?>
        <dl class="section-settings ff-auth-tiktok-settings ff-auth-tiktok-settings--refresh">
            <dt class="vert-aligned">Refresh Token</dt>
            <dd>
                <input type="hidden" id="tiktok_refresh_token" name="flow_flow_options[tiktok_refresh_token]" value="<?php echo isset($options['tiktok_refresh_token']) ? esc_attr($options['tiktok_refresh_token']) : ''?>"/>
                <a href="#" id="tk-refresh-token" class="ff-pseudo-link">Refresh token</a>
            </dd>
        </dl>
        <?php endif; ?>
        <dl class="section-settings ff-auth-tiktok-settings ff-auth-tiktok-settings--token">
            <dt class="vert-aligned">Access Token</dt>
            <dd>
                <input class="clearcache" type="text" id="tiktok_access_token" name="flow_flow_options[tiktok_access_token]" placeholder="Copy and paste from TikTok" value="<?php echo isset($options['tiktok_access_token']) ? esc_attr($options['tiktok_access_token']) : ''?>"/>
                <input type="hidden" id="tiktok_username" value="<?php echo isset($options['tiktok_username']) ? esc_attr($options['tiktok_username']) : ''?>"/>
            </dd>
        </dl>
        <dl class="section-settings">
            <dt class="vert-aligned" style="display:none">Granted Scopes</dt>
            <dd style="display:none">
                <?php
                $tk_scopes = isset($options['tiktok_scopes']) ? $options['tiktok_scopes'] : '';
                echo $tk_scopes !== '' ? '<code>' . esc_html($tk_scopes) . '</code>' : '<span class="desc">— Not available yet. Connect TikTok to see granted scopes.</span>';
                ?>
            </dd>
            <dt class="vert-aligned">Connected Account</dt>
            <dd>
                <?php
                $tk_user = isset($options['tiktok_username']) ? trim($options['tiktok_username']) : '';
                $tk_name = isset($options['tiktok_display_name']) ? trim($options['tiktok_display_name']) : '';
                $tk_oid  = isset($options['tiktok_open_id']) ? trim($options['tiktok_open_id']) : '';
                $avatar = isset($options['tiktok_userpic']) ? esc_url($options['tiktok_userpic']) : '';
                if ($tk_user !== '' || $tk_name !== '' || $tk_oid !== '') {
                    echo '<div class="tiktok-user-display" style="display:flex;align-items:center;gap:10px;margin:5px 0;">';
                    if ($avatar !== '') {
                        echo '<img src="' . $avatar . '" alt="" style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid #e0e0e0;box-shadow:0 1px 3px rgba(0,0,0,0.1)">';
                    }
                    echo '<div class="tiktok-user-info" style="line-height:1.3">';
                    if ($tk_user !== '') {
                        echo '<div><strong id="tiktok_username">@' . esc_html(ltrim($tk_user, '@')) . '</strong></div>';
                        if ($tk_name !== '') {
                            echo '<div style="font-size:0.9em;color:#666">' . esc_html($tk_name) . '</div>';
                        }
                    } elseif ($tk_name !== '') {
                        echo '<div><strong>' . esc_html($tk_name) . '</strong></div>';
                    } 
                    echo '</div>'; // .tiktok-user-info
                    echo '</div>'; // .tiktok-user-display
                } else {
                    echo '<span class="desc">— Not connected</span>';
                }
                ?>
            </dd>
            <dt class="vert-aligned">Token Status</dt>
            <dd id="tk-token-status">
                <?php
                $tk_expires = isset($options['tiktok_expires_in']) ? (int)$options['tiktok_expires_in'] : 0;
                $tk_token = isset($options['tiktok_access_token']) ? trim($options['tiktok_access_token']) : '';
                $tk_refresh_token = isset($options['tiktok_refresh_token']) ? trim($options['tiktok_refresh_token']) : '';
                $now = time();
                $wp_timezone = function_exists( 'wp_timezone' ) ? call_user_func( 'wp_timezone' ) : null;
                if ( ! $wp_timezone ) {
                    $tz_string = get_option( 'timezone_string' );
                    if ( ! $tz_string ) {
                        $gmt_offset = (float) get_option( 'gmt_offset' );
                        $tz_string = timezone_name_from_abbr( '', $gmt_offset * 3600, false );
                    }
                    $wp_timezone = new DateTimeZone( $tz_string ?: 'UTC' );
                }
                $auto_refresh_enabled = true; // Auto-refresh is always enabled in the code
                
                if ($tk_token !== '') {
                    $expires_date = new DateTime('@' . $tk_expires);
                    $expires_date->setTimezone($wp_timezone);
                    $expires_formatted = $tk_expires > 0 ? $expires_date->format('M j, Y g:i A T') : 'Never';
                    $time_until_expiry = $tk_expires - $now;
                    
                    // Calculate time components
                    $days_left = floor($time_until_expiry / DAY_IN_SECONDS);
                    $hours_left = floor(($time_until_expiry % DAY_IN_SECONDS) / HOUR_IN_SECONDS);
                    $minutes_left = floor(($time_until_expiry % HOUR_IN_SECONDS) / 60);
                    $seconds_left = $time_until_expiry % 60;
                    
                    // Status message
                    $status_message = '';
                    $status_class = '';
                    $expiry_details = '';
                    
                    if ($tk_expires === 0) {
                        // No expiration date (unlikely for TikTok, but handle it)
                        $status_message = '✓ Active';
                        $status_class = 'success';
                        $expiry_details = 'No expiration date';
                    } elseif ($time_until_expiry > 0) {
                        // Token is still valid
                        if ($days_left > 7) {
                            $status_message = '✓ Active';
                            $status_class = 'success';
                            $expiry_details = sprintf('Expires in %d days on %s', $days_left, $expires_date->format('M j, Y'));
                        } elseif ($days_left > 1) {
                            $status_message = '✓ Active';
                            $status_class = 'success';
                            $expiry_details = sprintf('Expires in %d days, %d hours', $days_left, $hours_left);
                        } elseif ($days_left > 0) {
                            $status_message = '⚠ Expires Soon';
                            $status_class = 'warning';
                            $expiry_details = sprintf('Expires in %d hours, %d minutes', ($days_left * 24) + $hours_left, $minutes_left);
                        } elseif ($hours_left > 0) {
                            $status_message = '⚠ Expires Soon';
                            $status_class = 'warning';
                            $expiry_details = sprintf('Expires in %d hours, %d minutes', $hours_left, $minutes_left);
                        } elseif ($minutes_left > 5) {
                            $status_message = '⚠ Expires Very Soon';
                            $status_class = 'warning';
                            $expiry_details = sprintf('Expires in %d minutes', $minutes_left);
                        } else {
                            $status_message = '⏳ Expiring Imminently';
                            $status_class = 'error';
                            $expiry_details = sprintf('Expires in %d minutes, %d seconds', $minutes_left, $seconds_left);
                        }
                    } else {
                        // Token has expired
                        $status_message = '❌ Expired';
                        $status_class = 'expired';
                        $expiry_details = 'Expired ' . human_time_diff($tk_expires, $now) . ' ago';
                    }
                    
                    // Add auto-refresh notice if enabled
                    if ($auto_refresh_enabled && $time_until_expiry > 0) {
                        $expiry_details .= ' (auto-refresh enabled)';
                    }
                    
                    // Output the status with appropriate styling
                    echo sprintf(
                        '<div class="tk-token-status">' .
                        '  <span class="tk-status-badge tk-status-%s">%s</span>' .
                        '  <div class="tk-status-details">%s</div>' .
                        '  <div class="tk-status-expiry">%s</div>' .
                        '%s' . // Additional notices
                        '</div>',
                        esc_attr($status_class),
                        esc_html($status_message),
                        $tk_expires > 0 ? 'Expires: ' . esc_html($expires_formatted) : 'No expiration date set',
                        esc_html($expiry_details),
                        $time_until_expiry <= 0 && !empty($tk_refresh_token) ? 
                            '<div class="tk-status-notice">Auto-refresh will attempt to renew the token automatically.</div>' : 
                            (empty($tk_refresh_token) ? 
                                '<div class="tk-status-notice error">No refresh token available. Please re-authenticate with TikTok.</div>' : '')
                    );
                } else {
                    // No token set
                    echo '<div class="tk-token-status">';
                    echo '<span class="tk-status-badge">Not Connected</span>';
                    echo '<div class="tk-status-details">Connect your TikTok account to get started</div>';
                    echo '</div>';
                }
                ?>
            </dd>
        </dl>
        <p class="button-wrapper ff-auth-tiktok-actions"><span id="tk-auth-settings-sbmt" class='admin-button green-button submit-button'>Save Changes</span></p>
        </div>
        <?php endif; ?>

        <div class="ff-auth-linkedin-section">
        <h1 class="desc-following ff-auth-linkedin-heading">LinkedIn integration <span id="linkedin-auth" class='admin-button auth-button blue-button'>Connect</span></h1>
        <p class="desc ff-auth-linkedin-desc"><a target="_blank" href="http://docs.social-streams.com/article/53-authenticate-with-linkedin">Setup guide</a></p>
        <?php if (!empty($options['linkedin_access_token'])): ?>
        <dl class="section-settings ff-auth-linkedin-settings ff-auth-linkedin-settings--refresh">
            <dt class="vert-aligned">Refresh Token</dt>
            <dd>
                <input type="hidden" id="linkedin_refresh_token" name="flow_flow_options[linkedin_refresh_token]" value="<?php echo isset($options['linkedin_refresh_token']) ? esc_attr($options['linkedin_refresh_token']) : ''?>"/>
                <a href="#" id="li-refresh-token" class="ff-pseudo-link">Refresh token</a>
            </dd>
        </dl>
        <?php endif; ?>
        <dl class="section-settings ff-auth-linkedin-settings ff-auth-linkedin-settings--token">
            <dt class="vert-aligned">Access Token</dt>
            <dd>
                <input class="clearcache" type="text" id="linkedin_access_token" name="flow_flow_options[linkedin_access_token]" placeholder="Acquired from LinkedIn" value="<?php echo isset($options['linkedin_access_token']) ? esc_attr($options['linkedin_access_token']) : ''?>"/>
                <input type="hidden" id="linkedin_username" value="<?php echo isset($options['linkedin_username']) ? esc_attr($options['linkedin_username']) : ''?>"/>
                <input type="hidden" id="linkedin_org_id" name="flow_flow_options[linkedin_org_id]" value="<?php echo isset($options['linkedin_org_id']) ? esc_attr($options['linkedin_org_id']) : ''?>"/>
                <input type="hidden" id="linkedin_org_name" name="flow_flow_options[linkedin_org_name]" value="<?php echo isset($options['linkedin_org_name']) ? esc_attr($options['linkedin_org_name']) : ''?>"/>
                <?php
                $li_orgs_val = '';
                if (isset($options['linkedin_organizations'])) {
                    $li_orgs = $options['linkedin_organizations'];
                    if (is_string($li_orgs)) {
                        $li_orgs_val = $li_orgs;
                    } else if (is_array($li_orgs)) {
                        $li_orgs_val = json_encode($li_orgs);
                    }
                }
                ?>
                <input type="hidden" id="linkedin_organizations" name="flow_flow_options[linkedin_organizations]" value="<?php echo esc_attr($li_orgs_val); ?>"/>
            </dd>
        </dl>
        <dl class="section-settings">
            <dt class="vert-aligned">Connected Account</dt>
            <dd id="linkedin-connected-user-dd">
                <?php
                $li_user = isset($options['linkedin_username']) ? trim($options['linkedin_username']) : '';
                $li_name = isset($options['linkedin_display_name']) ? trim($options['linkedin_display_name']) : '';
                $li_mid  = isset($options['linkedin_member_id']) ? trim($options['linkedin_member_id']) : '';
                $li_avatar = isset($options['linkedin_userpic']) ? esc_url($options['linkedin_userpic']) : '';
                if ($li_user !== '' || $li_name !== '' || $li_mid !== '') {
                    echo '<div class="linkedin-user-display" style="display:flex;align-items:center;gap:10px;margin:5px 0;">';
                    if ($li_avatar !== '') {
                        echo '<img src="' . $li_avatar . '" alt="" style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid #e0e0e0;box-shadow:0 1px 3px rgba(0,0,0,0.1)">';
                    }
                    echo '<div class="linkedin-user-info" style="line-height:1.3">';
                    if ($li_name !== '') {
                        echo '<div><strong id="linkedin_display_name">' . esc_html($li_name) . '</strong></div>';
                        if ($li_user !== '' && $li_user !== $li_name) {
                            echo '<div style="font-size:0.9em;color:#666">' . esc_html($li_user) . '</div>';
                        }
                    } elseif ($li_user !== '') {
                        echo '<div><strong>' . esc_html($li_user) . '</strong></div>';
                    }
                    echo '</div>'; // .linkedin-user-info
                    echo '</div>'; // .linkedin-user-display
                } else {
                    echo '<span class="desc">— Not connected</span>';
                }
                ?>
            </dd>
            <dt class="vert-aligned">Token Status</dt>
            <dd id="li-token-status">
                <?php
                $li_expires = isset($options['linkedin_expires_in']) ? (int)$options['linkedin_expires_in'] : 0;
                $li_token = isset($options['linkedin_access_token']) ? trim($options['linkedin_access_token']) : '';
                $li_refresh_token = isset($options['linkedin_refresh_token']) ? trim($options['linkedin_refresh_token']) : '';
                $now = time();
                $wp_timezone = function_exists( 'wp_timezone' ) ? call_user_func( 'wp_timezone' ) : null;
                if ( ! $wp_timezone ) {
                    $tz_string = get_option( 'timezone_string' );
                    if ( ! $tz_string ) {
                        $gmt_offset = (float) get_option( 'gmt_offset' );
                        $tz_string = timezone_name_from_abbr( '', $gmt_offset * 3600, false );
                    }
                    $wp_timezone = new DateTimeZone( $tz_string ?: 'UTC' );
                }
                
                if ($li_token !== '') {
                    if ($li_expires > 0) {
                        $expires_date = new DateTime('@' . $li_expires);
                        $expires_date->setTimezone($wp_timezone);
                        $expires_formatted = $expires_date->format('M j, Y g:i A T');
                        $time_until_expiry = $li_expires - $now;
                        $days_left = floor($time_until_expiry / DAY_IN_SECONDS);
                        
                        if ($time_until_expiry > 0) {
                            if ($days_left > 7) {
                                echo '<span style="color:#28a745;font-weight:500;">✓ Active</span>';
                                echo '<div style="font-size:0.9em;color:#666">Expires in ' . $days_left . ' days on ' . $expires_date->format('M j, Y') . '</div>';
                            } elseif ($days_left > 1) {
                                echo '<span style="color:#ffc107;font-weight:500;">⚠ Expires Soon</span>';
                                echo '<div style="font-size:0.9em;color:#666">Expires in ' . $days_left . ' days</div>';
                            } else {
                                echo '<span style="color:#ff9800;font-weight:500;">⚠ Expires Today</span>';
                                $hours_left = floor($time_until_expiry / HOUR_IN_SECONDS);
                                echo '<div style="font-size:0.9em;color:#666">Expires in ' . $hours_left . ' hours</div>';
                            }
                        } else {
                            echo '<span style="color:#dc3545;font-weight:500;">❌ Expired</span>';
                            echo '<div style="font-size:0.9em;color:#666">Expired ' . human_time_diff($li_expires, $now) . ' ago</div>';
                        }
                    } else {
                        echo '<span style="color:#28a745;font-weight:500;">✓ Active</span>';
                        echo '<div style="font-size:0.9em;color:#666">No expiration info available</div>';
                    }
                } else {
                    echo '<div class="li-token-status">';
                    echo '<span class="li-status-badge">Not Connected</span>';
                    echo '<div class="li-status-details">Connect your LinkedIn account to get started</div>';
                    echo '</div>';
                }
                ?>
            </dd>
        </dl>
        <p class="button-wrapper ff-auth-linkedin-actions"><span id="linkedin-auth-settings-sbmt" class='admin-button green-button submit-button'>Save Changes</span></p>
        </div>

        <h1 class="desc-following">YouTube integration</h1>
        <p class="desc"><a target="_blank" href="http://docs.social-streams.com/article/49-authenticate-with-google-and-youtube">Setup guide</a></p>
        <dl class="section-settings">
            <dt class="vert-aligned">API key</dt>
            <dd>
                <input class="clearcache" type="text" id="google_api_key" name="flow_flow_options[google_api_key]" placeholder="Copy and paste from YouTube" value="<?php echo isset($options['google_api_key']) ? esc_attr($options['google_api_key']) : ''?>"/>
            </dd>
        </dl>
        <p class="button-wrapper"><span id="gp-auth-settings-sbmt" class='admin-button green-button submit-button'>Save Changes</span></p>


        <h1 class="desc-following">Foursquare integration  <span id="foursquare-auth" class='admin-button auth-button blue-button'>Authorize</span></h1>
        <p class="desc"><a target="_blank" href="http://docs.social-streams.com/article/54-authenticate-with-foursquare">Setup guide</a></p>
        <dl class="section-settings">
            <dt class="vert-aligned">Access Token</dt>
            <dd>
                <input class="clearcache" type="text" id="foursquare_access_token" name="flow_flow_options[foursquare_access_token]" placeholder="Copy and paste from Foursquare" value="<?php echo isset($options['foursquare_access_token']) ? esc_attr($options['foursquare_access_token']) : '';?>"/>
            </dd>
            <dt class="vert-aligned">Client ID</dt>
            <dd>
                <input class="clearcache" id="foursquare_client_id" type="text" name="flow_flow_options[foursquare_client_id]" placeholder="Copy and paste from Foursquare" value="<?php echo isset($options['foursquare_client_id']) ? esc_attr($options['foursquare_client_id']) : ''?>"/>
            </dd>
            <dt class="vert-aligned">Client Secret</dt>
            <dd>
                <input class="clearcache" id="foursquare_client_secret" type="text" name="flow_flow_options[foursquare_client_secret]" placeholder="Copy and paste from Foursquare" value="<?php echo isset($options['foursquare_client_secret']) ? esc_attr($options['foursquare_client_secret']) : ''?>"/>
            </dd>
        </dl>
        <p class="button-wrapper"><span id="fq-auth-settings-sbmt" class='admin-button green-button submit-button'>Save Changes</span></p>

        <h1 class="desc-following">SoundCloud integration</h1>
        <p class="desc"><a target="_blank" href="http://soundcloud.com/you/apps/new">Create SoundCloud app</a> and paste its ID below.</p>


        <dl class="section-settings">
            <dt class="vert-aligned">Your app Client ID</dt>
            <dd>
                <input class="clearcache" type="text" name="flow_flow_options[soundcloud_api_key]" placeholder="Copy and paste from SoundCloud" value="<?php echo isset($options['soundcloud_api_key']) ? esc_attr($options['soundcloud_api_key']) : ''?>"/>
            </dd>
        </dl>

        <p class="button-wrapper"><span id="sc-auth-settings-sbmt" class='admin-button green-button submit-button'>Save Changes</span></p>
        <h1 class="desc-following">Twitter integration</h1>
        <p class="desc"><a target="_blank" href="http://docs.social-streams.com/article/48-authenticate-with-twitter">Setup guide</a></p>
        <dl class="section-settings">
            <dt class="vert-aligned">Consumer Key (API Key)</dt>
            <dd>
                <input class="clearcache" type="text" name="flow_flow_options[consumer_key]" placeholder="Copy and paste from Twitter" value="<?php echo isset($options['consumer_key']) ? esc_attr($options['consumer_key']) : ''?>"/>
            </dd>
            <dt class="vert-aligned">Consumer Secret (API Secret)</dt>
            <dd>
                <input class="clearcache" type="text" name="flow_flow_options[consumer_secret]" placeholder="Copy and paste from Twitter" value="<?php echo isset($options['consumer_secret']) ? esc_attr($options['consumer_secret']) : ''?>"/>
            </dd>
            <dt class="vert-aligned">Access Token</dt>
            <dd>
                <input class="clearcache" id="oauth_access_token" type="text" name="flow_flow_options[oauth_access_token]" placeholder="Copy and paste from Twitter" value="<?php echo isset($options['oauth_access_token']) ? esc_attr($options['oauth_access_token']) : ''?>"/>
            </dd>
            <dt class="vert-aligned">Access Token Secret</dt>
            <dd>
                <input class="clearcache" type="text" name="flow_flow_options[oauth_access_token_secret]" placeholder="Copy and paste from Twitter" value="<?php echo isset($options['oauth_access_token_secret']) ? esc_attr($options['oauth_access_token_secret']) : ''?>"/>						</dd>

        </dl>
        <p class="button-wrapper"><span id="tw-auth-settings-sbmt" class='admin-button green-button submit-button'>Save Changes</span></p>
        <h1 class="desc-following">Dribbble integration</h1>
        <p class="desc"><a target="_blank" href="http://developer.dribbble.com">Create Dribbble app</a> and paste its access token below.</p>
        <dl class="section-settings">
            <dt class="vert-aligned">Client Access Token</dt>
            <dd>
                <input class="clearcache" type="text" name="flow_flow_options[dribbble_access_token]" placeholder="Copy and paste from Dribbble" value="<?php echo isset($options['dribbble_access_token']) ? esc_attr($options['dribbble_access_token']) : ''?>"/>
            </dd>
        </dl>
        <p class="button-wrapper"><span id="dribbble-auth-settings-sbmt" class='admin-button green-button submit-button'>Save Changes</span></p>
    </div>
	<?php
		/** @noinspection PhpIncludeInspection */
		include(\la\core\LAUtils::root($context)  . 'views/footer.php');
	?>
</div>
<?php // phpcs:enable ?>
