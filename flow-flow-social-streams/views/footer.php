<?php

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'FF_USE_WP' ) || FF_USE_WP ) {
		exit;
	}
}

// phpcs:disable
/** @var array $context */
?>
<div class="section footer">
    <div class="width-wrapper"><div class="ff-table"><div class="ff-cell">
                Flow-Flow Social Stream<br>
                Version: <?php echo \la\core\LAUtils::version($context);?><br>
                Made by <a href="http://looks-awesome.com/">Looks Awesome</a>
            </div>
            <div class="ff-cell">
                <h1>HOT TOPICS</h1>
                <a target="_blank" href="http://docs.social-streams.com/article/42-first-steps-flow-wp">First Steps With Plugin</a><br>
                <a target="_blank" href="http://docs.social-streams.com/article/46-authenticate-with-facebook">Connect Facebook</a><br>
                <a target="_blank" href="http://docs.social-streams.com/article/56-issues-using-big-number-of-feeds">Issues With Streams</a><br>
                <a target="_blank" href="http://docs.social-streams.com/collection/104-faq">Frequently Asked Questions</a>
            </div>
            <div class="ff-cell">
                <h1>USEFUL LINKS</h1>
                <a href="http://go.social-streams.com/help">Help Center</a><br>
                <a href="https://social-streams.com/">Social Stream Apps</a><br>
                <a href="<?php echo $context['plugin_url'] . $context['plugin_dir_name']; ?>/flow-flow-debug.log" target="_blank">Debug Log</a> & <a href="#" class="show-debug">Server Specs</a><br>
                <a href="https://forms.gle/G7Bq5GPCBoeBFR8z7">Feedback</a>
            </div>
        </div>
    </div>
</div>
<?php // phpcs:enable ?>
