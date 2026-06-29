<?php

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'FF_USE_WP' ) || FF_USE_WP ) {
		exit;
	}
}

// phpcs:disable
 if ( ! defined( 'WPINC' ) ) die;
/**
 * @var array $context
 */
$plugins_url = plugins_url() . '/' . $context['plugin_dir_name'];
$options = $context['options'];
?>
<div class="section-content" data-tab="addons-tab">
    <div class="section" id="boosts">
        <h1 class="desc-following">Boosts<span class="boosts-beta">beta</span> <span class="desc hint-block">
                <span class="hint-link"><img src="<?php echo $plugins_url ?>/assets/info_icon.svg"></span>
                <span class="hint hint-pro">
                    <div class="ff-negative-margins"><img src="<?php echo $plugins_url ?>/assets/ezgif-boost.gif" alt=""/></div>
                    <h1>How to use</h1>
                    <p>When you have available BOOSTS drag and drop BOOST element on feed in the list or enable BOOST in feed settings.
                        <a href="http://social-streams.com/boosts" target="_blank">What is BOOST cloud service?</a></p></span>
            </span></h1>
        <div class="desc">Power up your social feeds with extra features of Boost cloud service for Flow-Flow. <a href="#" class="ff-pseudo-link boosts-link">What features?</a><br><span id="ff-cloud-status">Testing connection...</span>

        </div>
        <ul class="pricing-table">

        </ul>
        <div class="boosts_custom">
            <span>If you have coupon promo code enter it here:</span><span><input class="clearcache" type="text" id="boosts_coupon" name="boosts_coupon" placeholder="Coupon" value=""/><a class="block-controls"><a href="#" class="ff-pseudo-link coupon-apply">Apply to next checkout</a> or <a href="#" class="ff-pseudo-link coupon-clear">clear</a></span>
        </div>
        <div class="boosts_manual">
            Purchased but activation hasn't been completed? Or changed the plan? Run <a href="#" id="boosts_manual" class="ff-pseudo-link">manual activation</a>
        </div>
        <div class="desc" style="text-align: center">* Please notice that VAT is not included in displayed prices, it will be added on checkout depending on your location.<br>Payments are processed by <a target="_blank" href="https://paddle.com/legal-buyers/">Paddle</a> — online reseller with main office based in UK.<br> Also, we have 30 days guaranteed money back policy.</div>
        <div class="popup boosts-popup">

            <div class="section boost-explained">
                <h1><span>Boosts explained</span></h1>

                <div class="popup-content-wrapper">

                    <h2 style="text-align: center"><strong>BOOSTS</strong> — cloud service for Flow-Flow and it works as simple as pictured below.</h2>
                    <img src="<?php echo $plugins_url ?>/assets/boosts-explained.png">
                    <h2>Why host feeds in the cloud?</h2>
                    <p>If you want to have all basic Flow-Flow features plus extra cloud features AND offload your website we offer you to delegate all data manipulation to the cloud plus directly embed feeds into your website pages not bothering your server. The less plugins the better for website loading speed, which is the crucial for conversion rates. Also, now it's possible to add some extra features for cloud feeds because Flow-Flow is not limited by resources of single server anymore. Already available: feature to pin posts on top and feature to add any call-to-action buttons eg. to make posts shoppable. For agencies/freelancers we have special offer, please <a href="https://social-streams.com/instagram-feed-agency-boosts/" target="_blank">read more</a>. And more features coming in future.</p>
                    <h2>I don't like subscription much, can I just use Flow-Flow with own website?</h2>
                    <p>According to our support statistics 97% of clients do not experience any issues with running plugin on their own website server. In rare cases server configuration is preventing plugin from functioning (CRON and issues with server jobs), sometimes hosting has some security settings or hosting IP network can be banned by social media API servers. Boosts service is the way to guarantee everything is fine and dandy with feeds. Besides that, there was always subscription for your hosting and domain name.</p>
                    <h2>So, will it load faster on pages?</h2>
                    <p>Visually on page it's possible you won't notice big difference. It CAN make difference though if you have a lot of plugins installed, a lot of feeds created so there a lot of database queries happens to render single page. Cloud allows to reduce load on server from Flow-Flow side, content is added on page dynamically from the cloud not querying your website server.</p>
                    <h2>What extra features you plan to add for cloud streams?</h2>
                    <p>Because now we have access to more powerful cloud computing that scales as much as needed we can implement richer features. In addition to some neat extra features like pinning posts, adding CTA buttons, overall more reliable updating of feeds in the cloud, we will implement more features in future. This will include various e-commerce integrations, advanced editing of grid and posts, usage analytics etc</p>
                    <h2>Can I mix regular and cloud feeds in one stream?</h2>
                    <p>No, you can't. Because data is located and prepared on your server and in the cloud accordingly, it will require a lot of additional operations to synchronize these chunks of data. At the same time as all the point of cloud service is to reduce amount of computing on your server. Maybe we'll come up with some graceful solution in the future.</p>
                    <h2>Do I still need to add tokens or authenticate networks?</h2>
                    <p>Yes, we are getting data on your behalf, it's just difference that either your server or cloud server requests network API endpoints to get posts data.</p>
                    <h2>What about GDPR compliance?</h2>
                    <p>As plugin we operate under your website rules which most likely asks consent for EU visitors to use cookies. So far we don't gather or store any data of your website visitors. In future maybe we will add optional analytics service.</p>

                    <i class="popupclose flaticon-close-4"></i>
                </div>
            </div>
            <div class="section boost-feature-2">
                <h1><span>Pin Posts to Top</span></h1>

                <div class="popup-content-wrapper">
                    <img src="<?php echo $plugins_url ?>/assets/ezgif-flow-flow-pin-to-top.gif">
                    <p>This feature stands for the purpose described in its name: you can <Strong>pin any post</Strong> of your social stream to be at the top of your feed. The main purpose of this feature is to promote <strong>products</strong>, <strong>blog posts</strong>, or <strong>links</strong> you wish your potential customers see first of all. You will have nice synergy if you also add call-to-action elements (buttons, new likes animation) to pinned posts. Available after you enable <strong>moderation mode</strong> for feed.</p>
                    <p><a href="https://social-streams.com/add-engaging-call-to-action-elements-to-any-post-of-your-social-stream" target="_blank">Learn more</a> →</p>
                    <i class="popupclose flaticon-close-4"></i>
                </div>
            </div>
            <div class="section boost-feature-3">
                <h1><span>Call-to-action Elements</span></h1>

                <div class="popup-content-wrapper">
                    <img src="<?php echo $plugins_url ?>/assets/ezgif-flow-flow-promo-2.gif">
                    <p><strong>Call-to-action buttons</strong> are the main tools for the promotion of your products or services. You can place these <strong>animated buttons</strong> right on the post of the social stream or within a lightbox that opens up after clicking on the post. Additionally, <strong>likes indication</strong> is a perfect tool for creating social proof for your products and services. Moreover, the animated likes indications attract more attention of potential customers to certain posts that promote dedicated products or services. Available after you enable <strong>moderation mode</strong> for feed.</p>
                    <p><a href="https://social-streams.com/add-engaging-call-to-action-elements-to-any-post-of-your-social-stream" target="_blank">Learn more</a> →</p>
                    <i class="popupclose flaticon-close-4"></i>
                </div>
            </div>
            <div class="section boost-feature-4">
                <h1><span>Multi domain support</span></h1>

                <div class="popup-content-wrapper">
                    <p>Want to use single Boosts subscription on multiple domains?<br><strong>Agency/Freelancer plans</strong> are exactly what you need.</p>
                        <ul>
                            <li><strong>Ease of maintaining</strong>. Because if there are any changes in networks API access (the most volatile thing in Flow-Flow and similar apps) we update code on our side in the cloud and delivery of feed on website is not interrupted. We have control over connection to API unlike self-hosted solution. It means less updates of plugin on client’s website due to API changes.</li>
                            <li><strong>No need to ask client to get access tokens</strong>. You can use own tokens, it’s possible to pull any public Business/Creator account, even if you are not admin of pages of your clients. Exclusive for Boosts, we use own extended developer access to Instagram pages and it’s possible to pull even non-Business accounts and posts comments. In short, you use our access as proxy.</li>
                            <li><strong>Additional features</strong>. Cloud service allowed us to implement features that are only possible for cloud platform such as storing post images on own CDN servers to prevent expiration for images from Facebook/Instagram APIs aka 'broken' images.</li>
                        </ul>
                    <i class="popupclose flaticon-close-4"></i>
                </div>
            </div>
        </div>

    </div>
    <div class="section" id="extensions">
        <h1 class="desc-following">Available extensions</h1>
        <p class="desc">Enhance Flow-Flow functionality with these great add-ons.</p>

        <div class="extension">
            <div class="extension__item" id="extension-ads">
                <div class="extension__image"></div>
                <div class="extension__content">
                    <a class="extension__cta" target="_blank" href="https://codecanyon.net/item/advertisement-branding-for-flowflow-social-stream/14935987?utm_source=Flow+Admin+Pro&utm_medium=getbutton&utm_campaign=Admin+Flow+Get+Advert+Extension">Get</a>
                    <h1 class="extension__title">Advertising & Branding extension</h1>
                    <p class="extension__text">Personalize your Flow-Flow stream with custom cards. Make sticky and always show custom content: your brand advertisement with links to social profiles, custom advertisements (like AdSense), any announcements, event promotion and whatever you think of.<br>
                        <strong>Supported products:</strong> Flow-Flow PRO v 2.5+, Flow-Flow Lite v 3.0.5+</p>

                 </div>

            </div>
            <div class="extension__item" id="extension-tv">
                <div class="extension__image"></div>
                <div class="extension__content">
                    <a class="extension__cta" target="_blank" href="https://codecanyon.net/item/big-screens-extension-for-flowflow-social-stream/16042896?utm_source=Flow+Admin+Pro&utm_medium=getbutton&utm_campaign=Admin+Flow+Get+TV+Extension">Get</a>
                    <h1 class="extension__title">Big Screens extension</h1>
                    <p class="extension__text">Cast your social hub directly to a live TV, projector, or HDMI broadcast device with just one click! This extension comes with real-time updating and posts automatic rotation for full-screen mode. You just need to output stream page to desired screen.<br>
                        <strong>Supported products:</strong> Flow-Flow PRO v 2.8+, Flow-Flow Lite v 3.0.5+</p>
                 </div>

            </div>
        </div>
    </div>
    <div class="section" id="other_products">
        <h1 class="desc-following">Social Stream Apps</h1>
        <p class="desc">Other products built on Flow-Flow's core.</p>

        <div class="extension">
            <div class="extension__item" id="plugin-grace">
                <div class="extension__image"></div>
                <div class="extension__content">
                    <a class="extension__cta" target="_blank" href="http://go.social-streams.com/get-grace">Get</a>
                    <h1 class="extension__title">Grace — Instagram Feed Gallery for WordPress</h1>
                    <p class="extension__text">The most advanced plugin for creating graceful Instagram feed media walls of Instagram public posts. This feature-rich plugin lets you aggregate and showcase posts of Instagram accounts, hashtags and locations. And the great thing is that you can mix any of Instagram feeds in the same social media wall or carousel. Add eye-catching Instagram gallery to your website in fast and easy way!</p>
                </div>
            </div>
            <div class="extension__item" id="plugin-php">
                <div class="extension__image"></div>
                <div class="extension__content">
                    <a class="extension__cta" target="_blank" href="https://codecanyon.net/item/flowflow-social-streams-php-script/11096958">Get</a>
                    <h1 class="extension__title">Flow-Flow — Social Streams PHP Script</h1>
                    <p class="extension__text">Standalone version of Flow-Flow app for PHP servers without WordPress CMS installed. Can be used on any PHP server but requires more coding knowledge. Provides same features as WordPress version</p>
                </div>
            </div>
        </div>
    </div>
    <?php
    	/** @noinspection PhpIncludeInspection */
		include(\la\core\LAUtils::root($context)  . 'views/footer.php');
	?>
</div>

<?php // phpcs:enable ?>
