/* Flow-Flow Visual Front-End Live Customizer Logic */
(function($) {
    "use strict";

    if (!window.FlowFlowOpts || !window.FlowFlowOpts.isAdmin) {
        return;
    }

    var liveEditor = {
        activeStreamId: null,
        originalOptions: {},
        currentOptions: {},
        $panel: null,

        init: function() {
            var self = this;
            $(document).ready(function() {
                self.setupTriggers();
                self.createPanel();
                self.bindEvents();
            });
        },

        setupTriggers: function() {
            var self = this;
            // Find all active Flow-Flow stream containers on page
            $('[data-plugin="flow_flow"]').each(function() {
                var $cont = $(this);
                var idAttr = $cont.attr('id') || '';
                var id = idAttr.replace('ff-stream-', '');
                if (!id) return;

                // Ensure relative position for container so absolute trigger renders inside
                if ($cont.css('position') === 'static') {
                    $cont.css('position', 'relative');
                }

                // Inject sleek edit button inside stream container
                if (!$cont.find('.ff-live-editor-trigger').length) {
                    var triggerHtml = 
                        '<div class="ff-live-editor-trigger" data-stream-id="' + id + '">' +
                            '<svg viewBox="0 0 24 24"><path d="M19.43 12.98c.04-.32.07-.64.07-.98s-.03-.66-.07-.98l2.11-1.65c.19-.15.24-.42.12-.64l-2-3.46c-.12-.22-.39-.3-.61-.22l-2.49 1c-.52-.4-1.08-.73-1.69-.98l-.38-2.65C14.46 2.18 14.25 2 14 2h-4c-.25 0-.46.18-.49.42l-.38 2.65c-.61.25-1.17.59-1.69.98l-2.49-1c-.23-.09-.49 0-.61.22l-2 3.46c-.13.22-.07.49.12.64l2.11 1.65c-.04.32-.07.65-.07.98s.03.66.07.98l-2.11 1.65c-.19.15-.24.42-.12.64l2 3.46c.12.22.39.3.61.22l2.49-1c.52.4 1.08.73 1.69.98l.38 2.65c.03.24.24.42.49.42h4c.25 0 .46-.18.49-.42l.38-2.65c.61-.25 1.17-.59 1.69-.98l2.49 1c.23.09.49 0 .61-.22l2-3.46c.12-.22.07-.49-.12-.64l-2.11-1.65zM12 15.5c-1.93 0-3.5-1.57-3.5-3.5s1.57-3.5 3.5-3.5 3.5 1.57 3.5 3.5-1.57 3.5-3.5 3.5z"/></svg>' +
                            'Customize Stream' +
                        '</div>';
                    $cont.append(triggerHtml);
                }
            });
        },

        createPanel: function() {
            var html = 
                '<div class="ff-live-editor-panel">' +
                    '<div class="ff-live-editor-header">' +
                        '<h3>Customize Stream</h3>' +
                        '<p id="ff-editor-stream-subtitle">Stream ID: ...</p>' +
                        '<div class="ff-live-editor-close">&times;</div>' +
                    '</div>' +
                    '<div class="ff-live-editor-tabs">' +
                        '<div class="ff-live-editor-tab-btn active" data-target="tab-general">General</div>' +
                        '<div class="ff-live-editor-tab-btn" data-target="tab-cards">Cards</div>' +
                        '<div class="ff-live-editor-tab-btn" data-target="tab-colors">Colors</div>' +
                        '<div class="ff-live-editor-tab-btn" data-target="tab-css">Custom CSS</div>' +
                    '</div>' +
                    '<div class="ff-live-editor-body">' +
                        
                        /* TAB 1: General & Layout */
                        '<div class="ff-live-editor-tab-pane active" id="tab-general">' +
                            '<div class="ff-live-editor-group">' +
                                '<label>Stream Heading</label>' +
                                '<input type="text" class="ff-live-editor-input-text" data-opt="heading" placeholder="e.g. Follow Us On Social">' +
                            '</div>' +
                            '<div class="ff-live-editor-group">' +
                                '<label>Stream Subheading</label>' +
                                '<input type="text" class="ff-live-editor-input-text" data-opt="subheading" placeholder="e.g. Real-time updates from our feed">' +
                            '</div>' +
                            '<div class="ff-live-editor-group">' +
                                '<label>Heading Alignment</label>' +
                                '<select class="ff-live-editor-input-select" data-opt="hhalign">' +
                                    '<option value="center">Centered</option>' +
                                    '<option value="left">Left</option>' +
                                    '<option value="right">Right</option>' +
                                '</select>' +
                            '</div>' +
                            '<div class="ff-live-editor-group">' +
                                '<label>Stream Layout</label>' +
                                '<select class="ff-live-editor-input-select" data-opt="layout" id="ff-opt-layout">' +
                                    '<option value="masonry">Masonry</option>' +
                                    '<option value="grid">Grid</option>' +
                                    '<option value="justified">Justified</option>' +
                                    '<option value="list">Wall</option>' +
                                    '<option value="carousel">Carousel</option>' +
                                '</select>' +
                            '</div>' +
                            '<div class="ff-live-editor-group ff-gallery-mode-container" id="ff-gallery-mode-masonry-group" style="display: none;">' +
                                '<div class="ff-live-editor-switch-row">' +
                                    '<div class="ff-live-editor-switch-info">' +
                                        '<span class="ff-live-editor-switch-label">Gallery Mode</span>' +
                                        '<span class="ff-live-editor-switch-desc">Content overlays image on hover</span>' +
                                    '</div>' +
                                    '<label class="ff-live-editor-switch">' +
                                        '<input type="checkbox" class="ff-live-editor-input-checkbox" data-opt="m-overlay">' +
                                        '<span class="ff-live-editor-slider"></span>' +
                                    '</label>' +
                                '</div>' +
                            '</div>' +
                            '<div class="ff-live-editor-group ff-gallery-mode-container" id="ff-gallery-mode-grid-group" style="display: none;">' +
                                '<div class="ff-live-editor-switch-row">' +
                                    '<div class="ff-live-editor-switch-info">' +
                                        '<span class="ff-live-editor-switch-label">Gallery Mode</span>' +
                                        '<span class="ff-live-editor-switch-desc">Content overlays image on hover</span>' +
                                    '</div>' +
                                    '<label class="ff-live-editor-switch">' +
                                        '<input type="checkbox" class="ff-live-editor-input-checkbox" data-opt="g-overlay">' +
                                        '<span class="ff-live-editor-slider"></span>' +
                                    '</label>' +
                                '</div>' +
                            '</div>' +
                            '<div class="ff-live-editor-group">' +
                                '<div class="ff-live-editor-switch-row">' +
                                    '<div class="ff-live-editor-switch-info">' +
                                        '<span class="ff-live-editor-switch-label">Show Lightbox</span>' +
                                        '<span class="ff-live-editor-switch-desc">Open lightbox on card click</span>' +
                                    '</div>' +
                                    '<label class="ff-live-editor-switch">' +
                                        '<input type="checkbox" class="ff-live-editor-input-checkbox" data-opt="gallery">' +
                                        '<span class="ff-live-editor-slider"></span>' +
                                    '</label>' +
                                '</div>' +
                            '</div>' +
                            '<div class="ff-live-editor-group" id="ff-lightbox-type-group" style="display: none;">' +
                                '<label>Lightbox Type</label>' +
                                '<select class="ff-live-editor-input-select" data-opt="gallery-type">' +
                                    '<option value="classic">Lightbox</option>' +
                                    '<option value="news">News feed style</option>' +
                                '</select>' +
                            '</div>' +
                            '<div class="ff-live-editor-group">' +
                                '<label>Color Settings</label>' +
                                '<div class="ff-live-editor-color-row">' +
                                    '<div class="ff-live-editor-color-info">' +
                                        '<span class="ff-live-editor-color-label">Heading Color</span>' +
                                        '<span class="ff-live-editor-color-desc">Title font color</span>' +
                                    '</div>' +
                                    '<div class="ff-live-editor-picker-wrap">' +
                                        '<span class="ff-live-editor-picker-val">...</span>' +
                                        '<input type="text" class="ff-live-editor-color-input" data-opt="headingcolor">' +
                                    '</div>' +
                                '</div>' +
                                '<div class="ff-live-editor-color-row">' +
                                    '<div class="ff-live-editor-color-info">' +
                                        '<span class="ff-live-editor-color-label">Subheading Color</span>' +
                                        '<span class="ff-live-editor-color-desc">Subheading font color</span>' +
                                    '</div>' +
                                    '<div class="ff-live-editor-picker-wrap">' +
                                        '<span class="ff-live-editor-picker-val">...</span>' +
                                        '<input type="text" class="ff-live-editor-color-input" data-opt="subheadingcolor">' +
                                    '</div>' +
                                '</div>' +
                                '<div class="ff-live-editor-color-row">' +
                                    '<div class="ff-live-editor-color-info">' +
                                        '<span class="ff-live-editor-color-label">Outer Background</span>' +
                                        '<span class="ff-live-editor-color-desc">Stream wrapper background</span>' +
                                    '</div>' +
                                    '<div class="ff-live-editor-picker-wrap">' +
                                        '<span class="ff-live-editor-picker-val">...</span>' +
                                        '<input type="text" class="ff-live-editor-color-input" data-opt="bgcolor">' +
                                    '</div>' +
                                '</div>' +
                            '</div>' +
                        '</div>' +

                        /* TAB 2: Card Styling & Layout */
                        '<div class="ff-live-editor-tab-pane" id="tab-cards">' +
                            '<div class="ff-live-editor-group">' +
                                '<label>Card Border Rounding</label>' +
                                '<div class="ff-live-editor-range-container">' +
                                    '<input type="range" class="ff-live-editor-range" data-opt="bradius" min="0" max="30" step="1">' +
                                    '<span class="ff-live-editor-range-val">4px</span>' +
                                '</div>' +
                            '</div>' +
                            '<div class="ff-live-editor-group">' +
                                '<label>Card Text Alignment</label>' +
                                '<select class="ff-live-editor-input-select" data-opt="talign">' +
                                    '<option value="left">Left</option>' +
                                    '<option value="center">Centered</option>' +
                                    '<option value="right">Right</option>' +
                                '</select>' +
                            '</div>' +
                            '<div class="ff-live-editor-group">' +
                                '<label>Avatar Style</label>' +
                                '<select class="ff-live-editor-input-select" data-opt="upic-pos">' +
                                    '<option value="timestamp">With timestamp</option>' +
                                    '<option value="centered">Centered</option>' +
                                    '<option value="centered-big">Big Centered & Overlaps Image</option>' +
                                    '<option value="off">Don\'t show it</option>' +
                                '</select>' +
                            '</div>' +
                            '<div class="ff-live-editor-group">' +
                                '<label>Social Icon Style</label>' +
                                '<select class="ff-live-editor-input-select" data-opt="icon-style">' +
                                    '<option value="label1">Label</option>' +
                                    '<option value="label2">Corner icon</option>' +
                                    '<option value="stamp1">Timestamp</option>' +
                                    '<option value="off">Off</option>' +
                                '</select>' +
                            '</div>' +
                            '<div class="ff-live-editor-group">' +
                                '<label>Icon Color Style</label>' +
                                '<select class="ff-live-editor-input-select" data-opt="icon-col">' +
                                    '<option value="colored">Colored</option>' +
                                    '<option value="light">Light B&W</option>' +
                                    '<option value="dark">Dark B&W</option>' +
                                '</select>' +
                            '</div>' +
                            '<div class="ff-live-editor-group">' +
                                '<label>Card Colors</label>' +
                                '<div class="ff-live-editor-color-row">' +
                                    '<div class="ff-live-editor-color-info">' +
                                        '<span class="ff-live-editor-color-label">Card Background</span>' +
                                        '<span class="ff-live-editor-color-desc">Inside card background</span>' +
                                    '</div>' +
                                    '<div class="ff-live-editor-picker-wrap">' +
                                        '<span class="ff-live-editor-picker-val">...</span>' +
                                        '<input type="text" class="ff-live-editor-color-input" data-opt="cardcolor">' +
                                    '</div>' +
                                '</div>' +
                                '<div class="ff-live-editor-color-row">' +
                                    '<div class="ff-live-editor-color-info">' +
                                        '<span class="ff-live-editor-color-label">Card Shadow</span>' +
                                        '<span class="ff-live-editor-color-desc">Card drop shadow color</span>' +
                                    '</div>' +
                                    '<div class="ff-live-editor-picker-wrap">' +
                                        '<span class="ff-live-editor-picker-val">...</span>' +
                                        '<input type="text" class="ff-live-editor-color-input" data-opt="shadow">' +
                                    '</div>' +
                                '</div>' +
                                '<div class="ff-live-editor-color-row">' +
                                    '<div class="ff-live-editor-color-info">' +
                                        '<span class="ff-live-editor-color-label">Gallery Overlay</span>' +
                                        '<span class="ff-live-editor-color-desc">Background color for gallery mode overlay</span>' +
                                    '</div>' +
                                    '<div class="ff-live-editor-picker-wrap">' +
                                        '<span class="ff-live-editor-picker-val">...</span>' +
                                        '<input type="text" class="ff-live-editor-color-input" data-opt="bcolor">' +
                                    '</div>' +
                                '</div>' +
                            '</div>' +
                        '</div>' +

                        /* TAB 3: Core Colors & Accent */
                        '<div class="ff-live-editor-tab-pane" id="tab-colors">' +
                            '<div class="ff-live-editor-group">' +
                                '<label>Typography Palette</label>' +
                                '<div class="ff-live-editor-color-row">' +
                                    '<div class="ff-live-editor-color-info">' +
                                        '<span class="ff-live-editor-color-label">Accent / Title</span>' +
                                        '<span class="ff-live-editor-color-desc">Titles and author names</span>' +
                                    '</div>' +
                                    '<div class="ff-live-editor-picker-wrap">' +
                                        '<span class="ff-live-editor-picker-val">...</span>' +
                                        '<input type="text" class="ff-live-editor-color-input" data-opt="namecolor">' +
                                    '</div>' +
                                '</div>' +
                                '<div class="ff-live-editor-color-row">' +
                                    '<div class="ff-live-editor-color-info">' +
                                        '<span class="ff-live-editor-color-label">Body Text</span>' +
                                        '<span class="ff-live-editor-color-desc">Main post content text</span>' +
                                    '</div>' +
                                    '<div class="ff-live-editor-picker-wrap">' +
                                        '<span class="ff-live-editor-picker-val">...</span>' +
                                        '<input type="text" class="ff-live-editor-color-input" data-opt="textcolor">' +
                                    '</div>' +
                                '</div>' +
                                '<div class="ff-live-editor-color-row">' +
                                    '<div class="ff-live-editor-color-info">' +
                                        '<span class="ff-live-editor-color-label">Links Color</span>' +
                                        '<span class="ff-live-editor-color-desc">URLs and hashtags</span>' +
                                    '</div>' +
                                    '<div class="ff-live-editor-picker-wrap">' +
                                        '<span class="ff-live-editor-picker-val">...</span>' +
                                        '<input type="text" class="ff-live-editor-color-input" data-opt="linkscolor">' +
                                    '</div>' +
                                '</div>' +
                                '<div class="ff-live-editor-color-row">' +
                                    '<div class="ff-live-editor-color-info">' +
                                        '<span class="ff-live-editor-color-label">Secondary / Meta</span>' +
                                        '<span class="ff-live-editor-color-desc">Timestamps and stats</span>' +
                                    '</div>' +
                                    '<div class="ff-live-editor-picker-wrap">' +
                                        '<span class="ff-live-editor-picker-val">...</span>' +
                                        '<input type="text" class="ff-live-editor-color-input" data-opt="restcolor">' +
                                    '</div>' +
                                '</div>' +
                            '</div>' +
                        '</div>' +

                        /* TAB 4: Custom CSS & AI */
                        '<div class="ff-live-editor-tab-pane" id="tab-css">' +
                            '<div class="ff-live-editor-group">' +
                                '<label>Stream Custom CSS</label>' +
                                '<p class="ff-live-editor-desc" style="font-size:11px; margin-bottom:8px; opacity:0.8; color:#9ca3af;">Prefix your selectors with <strong class="ff-live-editor-css-prefix">#ff-stream-</strong> to target this stream.</p>' +
                                '<textarea class="ff-live-editor-input-textarea" data-opt="css" id="ff-live-editor-css-textarea" rows="8" style="width:100%; font-family:monospace; padding:10px; background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.1); border-radius:8px; color:#ffffff; box-sizing:border-box; resize:vertical; font-size:12px; line-height:1.5;"></textarea>' +
                            '</div>' +
                            '<div class="ff-live-editor-group ff-ai-css-section" style="margin-top: 15px; padding: 14px; background: rgba(129, 140, 248, 0.04); border: 1px dashed rgba(129, 140, 248, 0.2); border-radius: 8px;">' +
                                '<label style="display: flex; align-items: center; gap: 6px; font-weight: 500; font-size: 13px; color: #a5b4fc; text-transform: none; letter-spacing: normal; margin-bottom: 6px;"><span class="ff-ai-sparkle">✨</span> WP AI CSS Generator</label>' +
                                '<p class="ff-live-editor-desc" style="font-size:11px; margin-bottom:10px; color: #9ca3af; line-height: 1.4;">Describe styling adjustments (e.g. "hide text under image, make title font Outfit").</p>' +
                                '<div style="display: flex; gap: 8px; margin-bottom: 8px;">' +
                                    '<input type="text" id="ff-live-editor-ai-prompt" placeholder="e.g. Hide author names" style="flex:1; padding:8px 12px; background: rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.1); border-radius:6px; font-size:12px; color: #ffffff; box-sizing:border-box;" />' +
                                    '<button type="button" id="ff-live-editor-ai-btn" style="padding:8px 14px; font-size:12px; font-weight: 500; background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); color:white; border:none; border-radius:6px; cursor:pointer; box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);">Generate</button>' +
                                '</div>' +
                                '<div id="ff-live-editor-ai-status" style="display: none; font-size: 11px; font-weight: 500; margin-top: 6px;"></div>' +
                            '</div>' +
                        '</div>' +

                    '</div>' +
                    '<div class="ff-live-editor-footer">' +
                        '<button class="ff-live-editor-save-btn">' +
                            '<span class="ff-live-editor-spinner"></span>' +
                            '<span class="ff-live-editor-save-lbl">Save Changes</span>' +
                        '</button>' +
                        '<button class="ff-live-editor-cancel-btn">Discard</button>' +
                    '</div>' +
                    '</div>' +
                
                /* Sleek toast notification */
                '<div class="ff-live-editor-toast">' +
                    '<svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>' +
                    'Design settings saved successfully!' +
                '</div>';

            $('body').append(html);
            this.$panel = $('.ff-live-editor-panel');
            
            var self = this;
            setTimeout(function() {
                self.$panel.addClass('ff-panel-ready');
            }, 50);
        },

        bindEvents: function() {
            var self = this;

            // Trigger click - open customize drawer
            $(document).on('click', '.ff-live-editor-trigger', function() {
                var streamId = $(this).attr('data-stream-id');
                self.openPanel(streamId);
            });

            // Close button
            $('.ff-live-editor-close, .ff-live-editor-cancel-btn').on('click', function() {
                self.closePanel();
            });

            // Tabs toggle
            $('.ff-live-editor-tab-btn').on('click', function() {
                var target = $(this).attr('data-target');
                $('.ff-live-editor-tab-btn').removeClass('active');
                $(this).addClass('active');
                $('.ff-live-editor-tab-pane').removeClass('active');
                $('#' + target).addClass('active');
            });

            // Value updates - Inputs (Text, Select, Textarea)
            $('.ff-live-editor-input-text, .ff-live-editor-input-select, .ff-live-editor-input-textarea').on('input change', function() {
                var opt = $(this).attr('data-opt');
                var val = $(this).val();
                self.currentOptions[opt] = val;
                
                if (opt === 'layout' || opt === 'upic-pos' || opt === 'icon-style' || opt === 'icon-col' || opt === 'talign' || opt === 'gallery-type') {
                    if (opt === 'layout') {
                        if (val === 'masonry') {
                            self.currentOptions['gallery'] = self.currentOptions['m-overlay'] || 'nope';
                        } else if (val === 'grid') {
                            self.currentOptions['gallery'] = self.currentOptions['g-overlay'] || 'nope';
                        }
                    }
                    self.rebuildStreamDOM(self.activeStreamId);
                }
                
                // Live preview updates
                self.updateLivePreview();
            });

            // Listen to layout changes to toggle conditional fields
            $(document).on('change', '#ff-opt-layout, .ff-live-editor-input-checkbox', function() {
                self.updateConditionalFields();
            });

            // Value updates - Checkboxes
            $(document).on('change', '.ff-live-editor-input-checkbox', function() {
                var opt = $(this).attr('data-opt');
                var val = $(this).is(':checked') ? 'yep' : 'nope';
                self.currentOptions[opt] = val;
                
                if (opt === 'm-overlay' || opt === 'g-overlay' || opt === 'gallery') {
                    if (opt === 'm-overlay' || opt === 'g-overlay') {
                        self.currentOptions['gallery'] = val;
                    }
                    self.rebuildStreamDOM(self.activeStreamId);
                }
                
                // Live preview updates
                self.updateLivePreview();
            });

            // Value updates - Range slider
            $('.ff-live-editor-range').on('input change', function() {
                var opt = $(this).attr('data-opt');
                var val = $(this).val();
                self.currentOptions[opt] = val;
                $(this).siblings('.ff-live-editor-range-val').text(val + 'px');
                
                // Live preview updates
                self.updateLivePreview();
            });

            // Color updates are now handled inline via Spectrum callbacks

            // AI CSS Generation click
            $(document).on('click', '#ff-live-editor-ai-btn', function() {
                self.generateCustomizerCssWithAi();
            });

            // Permanent saving via AJAX
            $('.ff-live-editor-save-btn').on('click', function() {
                self.saveChanges();
            });
        },

        openPanel: function(streamId) {
            this.activeStreamId = streamId;
            var streamConfig = window.FlowFlowOpts.streams['stream' + streamId];
            if (!streamConfig) {
                console.error("FLOW-FLOW: Stream config not found for stream " + streamId);
                return;
            }

            // Keep deep clones of original and current options
            this.originalOptions = $.extend(true, {}, streamConfig);
            this.currentOptions = $.extend(true, {}, streamConfig);

            // Repair discrepancy: if hover gallery mode is off for the layout, make sure gallery click is also off
            if (this.currentOptions.layout === 'masonry' && this.currentOptions['m-overlay'] === 'nope') {
                this.currentOptions['gallery'] = 'nope';
            } else if (this.currentOptions.layout === 'grid' && this.currentOptions['g-overlay'] === 'nope') {
                this.currentOptions['gallery'] = 'nope';
            }

            // Also apply same discrepancy repair to originalOptions to avoid treating it as a change
            if (this.originalOptions.layout === 'masonry' && this.originalOptions['m-overlay'] === 'nope') {
                this.originalOptions['gallery'] = 'nope';
            } else if (this.originalOptions.layout === 'grid' && this.originalOptions['g-overlay'] === 'nope') {
                this.originalOptions['gallery'] = 'nope';
            }

            $('#ff-editor-stream-subtitle').text('Stream: ' + (stripslashes(this.currentOptions.name) || 'Unnamed') + ' (ID: ' + streamId + ')');
            $('.ff-live-editor-css-prefix').text('#ff-stream-' + streamId);

            this.populateFields();
            this.updateConditionalFields();
            this.$panel.addClass('open');
            $('.ff-live-editor-trigger').addClass('ff-panel-open');

            // Smooth scroll to the top of the active stream container
            var $stream = $('#ff-stream-' + streamId);
            if ($stream.length) {
                $('html, body').animate({
                    scrollTop: $stream.offset().top
                }, 500);
            }
        },

        hasActualChanges: function() {
            var self = this;
            var ignoreKeys = ['items', 'feeds', 'errors', 'isOverlay', 'trueLayout', 'shop', 'plugin'];
            
            // Check all keys in currentOptions
            for (var key in self.currentOptions) {
                if (self.currentOptions.hasOwnProperty(key)) {
                    if (ignoreKeys.indexOf(key) !== -1) continue;
                    
                    var orig = self.originalOptions[key];
                    var curr = self.currentOptions[key];
                    if (orig === undefined) orig = '';
                    if (curr === undefined) curr = '';
                    
                    if (typeof orig !== 'object' && typeof curr !== 'object') {
                        if (String(orig) !== String(curr)) {
                            console.log('Difference found in currentOptions: key=' + key + ' orig=' + orig + ' curr=' + curr);
                            return true;
                        }
                    }
                }
            }
            // Check keys that might only exist in originalOptions
            for (var key in self.originalOptions) {
                if (self.originalOptions.hasOwnProperty(key)) {
                    if (ignoreKeys.indexOf(key) !== -1) continue;
                    if (self.currentOptions[key] === undefined) {
                        var orig = self.originalOptions[key];
                        if (orig !== undefined && orig !== '') {
                            console.log('Difference found in originalOptions: key=' + key + ' orig=' + orig);
                            return true;
                        }
                    }
                }
            }
            return false;
        },

        closePanel: function() {
            this.$panel.removeClass('open');
            $('.ff-live-editor-trigger').removeClass('ff-panel-open');
            
            // Hide open Spectrum pickers
            if ($.fn.spectrum) {
                $('.ff-live-editor-color-input').spectrum("hide");
            }
            
            if (this.hasActualChanges()) {
                // Revert current options to original ones
                this.currentOptions = $.extend(true, {}, this.originalOptions);
                
                // Rebuild the stream DOM to restore original layout/structure
                this.rebuildStreamDOM(this.activeStreamId);
                
                // Restore original text content in DOM if heading changed
                var origHeading = this.originalOptions.heading || '';
                var origSubheading = this.originalOptions.subheading || '';
                this.updateHeaderDOM(origHeading, origSubheading);
            }
            
            // Revert changes from preview by deleting preview style tag
            $('#ff-live-preview-style-' + this.activeStreamId).remove();
        },

        populateFields: function() {
            var self = this;
            var opts = this.currentOptions;

            // Text, Select, and Textarea inputs
            $('.ff-live-editor-input-text, .ff-live-editor-input-select, .ff-live-editor-input-textarea').each(function() {
                var opt = $(this).attr('data-opt');
                if (opts[opt] !== undefined) {
                    $(this).val(opts[opt]);
                }
            });

            // Checkbox inputs
            $('.ff-live-editor-input-checkbox').each(function() {
                var opt = $(this).attr('data-opt');
                if (opts[opt] !== undefined) {
                    $(this).prop('checked', opts[opt] === 'yep');
                }
            });

            // Range inputs
            $('.ff-live-editor-range').each(function() {
                var opt = $(this).attr('data-opt');
                if (opts[opt] !== undefined) {
                    var val = parseInt(opts[opt], 10) || 0;
                    $(this).val(val);
                    $(this).siblings('.ff-live-editor-range-val').text(val + 'px');
                }
            });

            // Color inputs
            $('.ff-live-editor-color-input').each(function() {
                var $input = $(this);
                var opt = $input.attr('data-opt');
                var defaultColor = '#ffffff';
                if (opt === 'bcolor') defaultColor = 'rgba(0, 0, 0, 0.75)';
                else if (opt === 'shadow') defaultColor = 'rgba(0, 0, 0, 0.05)';
                var colorVal = opts[opt] !== undefined ? opts[opt] : defaultColor;
                
                // Parse rgba / rgb into hex
                var hex = self.colorToHex(colorVal);
                $input.val(hex);
                $input.siblings('.ff-live-editor-picker-val').text(hex.toUpperCase());

                // Initialize Spectrum color picker inline
                if ($.fn.spectrum) {
                    if (!$input.data('spectrum.id')) {
                        $input.spectrum({
                            type: "color",
                            showInput: true,
                            showAlpha: true,
                            preferredFormat: "hex",
                            clickoutFiresChange: true,
                            showPalette: true,
                            palette: [
                                ['#c0392b', '#a3503c', '#925873', '#927758', '#589272'],
                                ['#588c92', '#2bb1c0', '#2b8ac0', '#e96701', '#c02b74'],
                                ['#000000', '#4C4C4C', '#CCCCCC', '#F0F0F0', '#FFFFFF']
                            ],
                            replacerClassName: 'ff-spectrum-replacer',
                            containerClassName: 'ff-spectrum-container',
                            move: function(color) {
                                if (!color) return;
                                var rgb = color.toRgb();
                                var finalVal;
                                if (opt === 'shadow') {
                                    var alpha = rgb.a !== 1 ? rgb.a : 0.06;
                                    finalVal = 'rgba(' + rgb.r + ', ' + rgb.g + ', ' + rgb.b + ', ' + alpha + ')';
                                } else if ((opt.indexOf('color') !== -1 && opt !== 'bcolor') || opt === 'bgcolor') {
                                    finalVal = 'rgb(' + rgb.r + ', ' + rgb.g + ', ' + rgb.b + ')';
                                } else {
                                    finalVal = color.toRgbString();
                                }
                                self.currentOptions[opt] = finalVal;
                                var displayVal = color.toHexString().toUpperCase();
                                $input.siblings('.ff-live-editor-picker-val').text(displayVal);
                                self.updateLivePreview();
                            },
                            change: function(color) {
                                if (!color) return;
                                var rgb = color.toRgb();
                                var finalVal;
                                if (opt === 'shadow') {
                                    var alpha = rgb.a !== 1 ? rgb.a : 0.06;
                                    finalVal = 'rgba(' + rgb.r + ', ' + rgb.g + ', ' + rgb.b + ', ' + alpha + ')';
                                } else if ((opt.indexOf('color') !== -1 && opt !== 'bcolor') || opt === 'bgcolor') {
                                    finalVal = 'rgb(' + rgb.r + ', ' + rgb.g + ', ' + rgb.b + ')';
                                } else {
                                    finalVal = color.toRgbString();
                                }
                                self.currentOptions[opt] = finalVal;
                                var displayVal = color.toHexString().toUpperCase();
                                $input.siblings('.ff-live-editor-picker-val').text(displayVal);
                                self.updateLivePreview();
                            }
                        });
                    }
                    // Set current color to Spectrum container
                    $input.spectrum("set", colorVal);
                }
            });
        },

        updateConditionalFields: function() {
            var layoutVal = $('#ff-opt-layout').val();
            $('.ff-gallery-mode-container').hide();
            if (layoutVal === 'masonry') {
                $('#ff-gallery-mode-masonry-group').show();
            } else if (layoutVal === 'grid') {
                $('#ff-gallery-mode-grid-group').show();
            }

            var showLightbox = $('[data-opt="gallery"]').is(':checked');
            if (showLightbox) {
                $('#ff-lightbox-type-group').show();
            } else {
                $('#ff-lightbox-type-group').hide();
            }
        },

        updateLivePreview: function() {
            var streamId = this.activeStreamId;
            var opts = this.currentOptions;

            // Generate CSS code dynamically
            var css = this.generatePreviewCss(streamId, opts);

            // Inject or update preview style tag in head
            var styleId = 'ff-live-preview-style-' + streamId;
            var $style = $('#' + styleId);
            if (!$style.length) {
                $style = $('<style id="' + styleId + '"></style>');
                $('head').append($style);
            }
            $style.html(css);

            // Live Heading text updates
            this.updateHeaderDOM(opts.heading, opts.subheading);
        },

        updateHeaderDOM: function(heading, subheading) {
            var streamId = this.activeStreamId;
            var $stream = $('#ff-stream-' + streamId);
            var $header = $stream.find('.ff-header');

            var hasHeading = heading && heading.trim() !== '';
            var hasSubheading = subheading && subheading.trim() !== '';

            if (hasHeading || hasSubheading) {
                if (!$header.length) {
                    $header = $('<div class="ff-header"><h1></h1><h2></h2></div>');
                    $stream.prepend($header);
                } else {
                    if (!$header.find('h1').length) {
                        $header.prepend('<h1></h1>');
                    }
                    if (!$header.find('h2').length) {
                        if ($header.find('h1').length) {
                            $header.find('h1').after('<h2></h2>');
                        } else {
                            $header.append('<h2></h2>');
                        }
                    }
                }
                $header.show();
                $header.find('h1').text(heading || '');
                $header.find('h2').text(subheading || '');
            } else {
                if ($header.length) {
                    $header.hide();
                }
            }
        },

        rebuildStreamDOM: function(streamId) {
            var self = this;
            var $cont = $('#ff-stream-' + streamId);
            if (!$cont.length) return;

            // Find matching config in window.flow_flow_streams
            var config = null;
            if (window.flow_flow_streams) {
                for (var i = 0; i < window.flow_flow_streams.length; i++) {
                    if (window.flow_flow_streams[i].id == streamId) {
                        config = window.flow_flow_streams[i];
                        break;
                    }
                }
            }
            if (!config) {
                console.error("FLOW-FLOW live customizer: Stream config not found in flow_flow_streams");
                return;
            }

            var response = self.currentOptions.items || (window.FlowFlowOpts.streams['stream' + streamId] && window.FlowFlowOpts.streams['stream' + streamId]['items']);
            if (!response) {
                console.error("FLOW-FLOW live customizer: Stream items response not found");
                return;
            }

            // Create a copy of currentOptions to avoid polluting original state with mutated properties
            var renderOpts = $.extend(true, {}, self.currentOptions);
            
            // Prepare options as in stream-loader.js
            renderOpts.shop = config.domain;
            renderOpts.plugin = 'flow_flow';
            renderOpts.trueLayout = renderOpts.layout;

            var isMobile = /android|blackBerry|iphone|ipad|ipod|opera mini|iemobile/i.test(navigator.userAgent);

            if (renderOpts.layout == 'carousel') {
                 renderOpts['layout'] = 'grid';
                 renderOpts['g-ratio-h'] = "1";
                 renderOpts['g-ratio-img'] = "1/2";
                 renderOpts['g-ratio-w'] = "1";
                 renderOpts['g-overlay'] = "yep";
                 renderOpts['c-overlay'] = "yep";
                 renderOpts['s-desktop'] = "0";
                 renderOpts['s-laptop'] = "0";
                 renderOpts['s-smart-l'] = "0";
                 renderOpts['s-smart-p'] = "0";
                 renderOpts['s-tablet-l'] = "0";
                 renderOpts['s-tablet-p'] = "0";
            } else if (renderOpts.layout == 'list') {
                 renderOpts['layout'] = 'masonry';
            }

            // Prepare overlay template mods if needed
            var layout_pre = renderOpts.layout.charAt(0);
            var isOverlay = layout_pre === 'j' || renderOpts[layout_pre + '-overlay'] === 'yep' && renderOpts.trueLayout !== 'list';
            var imgIndex;
            if (isOverlay) {
                 if (renderOpts.template[0] !== 'image') {
                     for (var i = 0, len = renderOpts.template.length; i < len; i++) {
                         if (renderOpts.template[i] === 'image') imgIndex = i;
                     }
                     renderOpts.template.splice(0, 0, renderOpts.template.splice(imgIndex, 1)[0]);
                 }
                 renderOpts.isOverlay = true;
            }

            // Remove existing stream DOM structures
            $cont.find('.ff-stream-wrapper, .ff-header, .ff-loadmore-wrapper, .ff-slide-overlay, .ff-settings-popup, .ff-errors').remove();

            // Rebuild using core FlowFlow engine
            var $stream = FlowFlow.buildStreamWith(response, renderOpts, config.moderation, window.FlowFlowOpts.dependencies);
            $cont.append($stream);

            var num = renderOpts.layout === 'compact' || (renderOpts.mobileslider === 'yep' && isMobile) ? (renderOpts.mobileslider === 'yep' ? 3 : renderOpts['cards-num']) : false;

            if (typeof $stream !== 'string') {
                FlowFlow.setupGrid($cont.find('.ff-stream-wrapper'), num, renderOpts.scrolltop === 'yep', renderOpts.gallery === 'yep', renderOpts, $cont);
            }

            setTimeout(function () {
                $cont.find('.ff-header').removeClass('ff-loading').end().find('.ff-loader').addClass('ff-squeezed').delay(300).hide();
            }, 0);
        },

        generatePreviewCss: function(streamId, options) {
            var css = '';
            var bradius = parseInt(options.bradius || 4, 10);
            
            // Heading color
            if (options.headingcolor) {
                css += '#ff-stream-' + streamId + ' .ff-header h1,#ff-stream-' + streamId + ' .ff-controls-wrapper > span:hover { color: ' + options.headingcolor + ' !important; }\n';
                css += '#ff-stream-' + streamId + ' .ff-controls-wrapper > span:hover { border-color: ' + options.headingcolor + ' !important; }\n';
                css += '#ff-stream-' + streamId + ' .ff-filter:hover, #ff-stream-' + streamId + ' .ff-filter.ff-filter--active, #ff-stream-' + streamId + ' .ff-moderation-button, #ff-stream-' + streamId + ' .ff-loadmore-wrapper .ff-btn, #ff-stream-' + streamId + ' .ff-square:nth-child(1) { background-color: ' + options.headingcolor + ' !important; }\n';
                css += '#ff-stream-' + streamId + ' .ff-search input:focus, #ff-stream-' + streamId + ' .ff-search input:hover { border-color: ' + options.headingcolor + ' !important; }\n';
            }
            
            // Subheading color
            if (options.subheadingcolor) {
                css += '#ff-stream-' + streamId + ' .ff-header h2 { color: ' + options.subheadingcolor + ' !important; }\n';
            }
            
            // Heading alignment
            if (options.hhalign) {
                css += '#ff-stream-' + streamId + ' .ff-header h1, #ff-stream-' + streamId + ' .ff-header h2 { text-align: ' + options.hhalign + '; }\n';
            }
            
            // Container background color
            if (options.bgcolor) {
                css += '#ff-stream-' + streamId + ', #ff-stream-' + streamId + ' .ff-popup, #ff-stream-' + streamId + ' .ff-search input { background-color: ' + options.bgcolor + '; }\n';
            }
            
            // Card background color
            if (options.cardcolor) {
                css += '#ff-stream-' + streamId + ' .picture-item__inner { background: ' + options.cardcolor + '; }\n';
                css += '#ff-stream-' + streamId + ' .ff-post-cta { background-color: ' + options.cardcolor + '; }\n';
                css += '#ff-stream-' + streamId + '-slideshow .ff-share-popup, #ff-stream-' + streamId + '-slideshow .ff-share-popup:after, #ff-stream-' + streamId + ' .ff-share-popup, #ff-stream-' + streamId + ' .ff-share-popup:after { background: ' + options.cardcolor + '; }\n';
                css += '#ff-stream-' + streamId + ' .ff-infinite > li { background: ' + options.cardcolor + '; }\n';
                css += '#ff-stream-' + streamId + ' .ff-square { background: ' + options.cardcolor + '; }\n';
                css += '#ff-stream-' + streamId + ' .ff-icon { border-color: ' + options.cardcolor + '; }\n';
            }
            
            // Accent color (namecolor)
            if (options.namecolor) {
                css += '#ff-stream-' + streamId + ' .ff-item h1, #ff-stream-' + streamId + ' .ff-stream-wrapper.ff-infinite .ff-nickname, #ff-stream-' + streamId + ' h4, #ff-stream-' + streamId + '-slideshow h4, #ff-stream-' + streamId + '-slideshow h4 a, #ff-stream-' + streamId + ' .ff-name, #ff-stream-' + streamId + '-slideshow .ff-name { color: ' + options.namecolor + ' !important; }\n';
            }
            
            // Text color
            if (options.textcolor) {
                css += '#ff-stream-' + streamId + ' .picture-item__inner, #ff-stream-' + streamId + ' .ff-post-cta { color: ' + options.textcolor + '; }\n';
                css += '#ff-stream-' + streamId + ', #ff-stream-' + streamId + '-slideshow, #ff-stream-' + streamId + ' .ff-infinite .ff-content { color: ' + options.textcolor + '; }\n';
            }
            
            // Links color
            if (options.linkscolor) {
                css += '#ff-stream-' + streamId + ' .ff-content a { color: ' + options.linkscolor + '; }\n';
            }
            
            // Secondary color (restcolor)
            if (options.restcolor) {
                css += '#ff-stream-' + streamId + ' .ff-nickname, #ff-stream-' + streamId + ' .ff-timestamp, #ff-stream-' + streamId + ' .ff-item-bar, #ff-stream-' + streamId + ' .ff-item-bar a { color: ' + options.restcolor + ' !important; }\n';
            }
            
            // Card shadow
            if (options.shadow) {
                css += '#ff-stream-' + streamId + ' .picture-item__inner { box-shadow: 0 1px 4px 0 ' + options.shadow + '; }\n';
            }
            
            // Text alignment
            if (options.talign) {
                css += '#ff-stream-' + streamId + ' .ff-item, #ff-stream-' + streamId + ' .ff-stream-wrapper.ff-infinite .ff-content { text-align: ' + options.talign + '; }\n';
            }
            
            // Corner rounding (bradius)
            css += '#ff-stream-' + streamId + ' .ff-upic-round .picture-item__inner, #ff-stream-' + streamId + ' .ff-upic-round .picture-item__inner:before { border-radius: ' + (bradius + 2) + 'px; }\n';
            css += '#ff-stream-' + streamId + ' .ff-upic-round.ff-sc-label2 .ff-icon { border-top-right-radius: ' + (bradius - 1) + 'px; }\n';
            css += '#ff-stream-' + streamId + ' .ff-upic-round.ff-infinite > li { border-radius: ' + bradius + 'px; }\n';
            css += '#ff-stream-' + streamId + ' .ff-upic-round .ff-img-holder:first-child, #ff-stream-' + streamId + ' .ff-upic-round .ff-img-holder:first-child img { border-radius: ' + bradius + 'px ' + bradius + 'px 0 0; }\n';
            css += '#ff-stream-' + streamId + ' .ff-upic-round.ff-infinite .ff-img-holder:first-child, #ff-stream-' + streamId + ' .ff-upic-round.ff-infinite .ff-img-holder:first-child img { border-radius: ' + (bradius - 2) + 'px ' + (bradius - 2) + 'px 0 0; }\n';
            css += '#ff-stream-' + streamId + ' .ff-upic-round .ff-has-overlay .ff-img-holder, #ff-stream-' + streamId + ' .ff-upic-round .ff-has-overlay .ff-overlay, #ff-stream-' + streamId + ' .ff-upic-round .ff-has-overlay .ff-img-holder img { border-radius: ' + bradius + 'px !important; }\n';

            // Gallery Overlay Color (bcolor)
            if (options.bcolor) {
                css += '#ff-stream-' + streamId + ' .ff-overlay { background-color: ' + options.bcolor + ' !important; }\n';
            }

            // Custom CSS
            if (options.css) {
                css += '\n/* Custom CSS */\n' + options.css + '\n';
            }

            return css;
        },

        saveChanges: function() {
            var self = this;
            var streamId = this.activeStreamId;
            var streamData = $.extend(true, {}, this.currentOptions);

            // Visual loading state
            $('.ff-live-editor-spinner').show();
            $('.ff-live-editor-save-lbl').text('Saving...');
            $('.ff-live-editor-save-btn, .ff-live-editor-cancel-btn').prop('disabled', true);

            // Clean data properties to match Backend format
            if (typeof streamData.feeds !== 'string') {
                streamData.feeds = JSON.stringify(streamData.feeds);
            }
            if (streamData.errors) delete streamData.errors;
            if (streamData.items) delete streamData.items;
            if (streamData.isOverlay) delete streamData.isOverlay;
            if (streamData.trueLayout) delete streamData.trueLayout;
            if (streamData.shop) delete streamData.shop;
            if (streamData.plugin) delete streamData.plugin;

            $.ajax({
                url: window.FlowFlowOpts.ajaxurl,
                type: 'POST',
                data: {
                    action: 'flow_flow_save_stream_settings',
                    stream: streamData,
                    security: window.FlowFlowOpts.flow_flow_nonce
                },
                success: function(response) {
                    var data = {};
                    try {
                        data = typeof response === 'string' ? JSON.parse(response) : response;
                    } catch (e) {
                        data = response;
                    }

                    // Reset save button loading state
                    $('.ff-live-editor-spinner').hide();
                    $('.ff-live-editor-save-lbl').text('Save Changes');
                    $('.ff-live-editor-save-btn, .ff-live-editor-cancel-btn').prop('disabled', false);

                    if (data && data.error) {
                        alert('Error: You are not allowed to save settings or security validation failed.');
                        return;
                    }

                    // Check if structural layout settings were modified
                    var structuralChange = 
                        self.currentOptions.layout !== self.originalOptions.layout || 
                        self.currentOptions['m-overlay'] !== self.originalOptions['m-overlay'] || 
                        self.currentOptions['g-overlay'] !== self.originalOptions['g-overlay'];

                    // Update memory config with the new configurations
                    window.FlowFlowOpts.streams['stream' + streamId] = self.currentOptions;
                    self.originalOptions = $.extend(true, {}, self.currentOptions);

                    // Show success visual toast!
                    var $toast = $('.ff-live-editor-toast');
                    $toast.html('<svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>' + 'Design settings saved successfully!');
                    $toast.addClass('show');
                    setTimeout(function() {
                        $toast.removeClass('show');
                    }, 3500);

                    // Close the sidebar panel smoothly
                    setTimeout(function() {
                        self.$panel.removeClass('open');
                        $('.ff-live-editor-trigger').removeClass('ff-panel-open');
                    }, 800);
                },
                error: function(xhr, status, err) {
                    console.error("FLOW-FLOW visual saving error", status, err);
                    alert("Nay! Something went wrong saving your settings. Please try again.");

                    $('.ff-live-editor-spinner').hide();
                    $('.ff-live-editor-save-lbl').text('Save Changes');
                    $('.ff-live-editor-save-btn, .ff-live-editor-cancel-btn').prop('disabled', false);
                }
            });
        },

        /* Helper methods for parsing color strings */
        colorToHex: function(color) {
            if (!color) return '#FFFFFF';
            color = color.trim();
            
            // Hex format directly
            if (color.indexOf('#') === 0) return color;
            
            // RGB / RGBA format
            if (color.indexOf('rgb') === 0) {
                var matches = color.match(/\d+/g);
                if (matches && matches.length >= 3) {
                    var r = parseInt(matches[0], 10);
                    var g = parseInt(matches[1], 10);
                    var b = parseInt(matches[2], 10);
                    return "#" + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
                }
            }
            return '#FFFFFF';
        },

        hexToRgb: function(hex) {
            var shorthandRegex = /^#?([a-f\d])([a-f\d])([a-f\d])$/i;
            hex = hex.replace(shorthandRegex, function(m, r, g, b) {
                return r + r + g + g + b + b;
            });
            var result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
            return result ? 'rgb(' + 
                parseInt(result[1], 16) + ', ' + 
                parseInt(result[2], 16) + ', ' + 
                parseInt(result[3], 16) + ')' : 'rgb(255, 255, 255)';
        },

        hexToRgba: function(hex, alpha) {
            var shorthandRegex = /^#?([a-f\d])([a-f\d])([a-f\d])$/i;
            hex = hex.replace(shorthandRegex, function(m, r, g, b) {
                return r + r + g + g + b + b;
            });
            var result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
            return result ? 'rgba(' + 
                parseInt(result[1], 16) + ', ' + 
                parseInt(result[2], 16) + ', ' + 
                parseInt(result[3], 16) + ', ' + alpha + ')' : 'rgba(0, 0, 0, ' + alpha + ')';
        },

        generateCustomizerCssWithAi: function() {
            var self = this;
            var streamId = this.activeStreamId;
            var $btn = $('#ff-live-editor-ai-btn');
            var $promptInput = $('#ff-live-editor-ai-prompt');
            var $status = $('#ff-live-editor-ai-status');
            var prompt = $promptInput.val().trim();

            if (!prompt) {
                $promptInput.css('border-color', '#dc3545');
                setTimeout(function () { $promptInput.css('border-color', ''); }, 1500);
                return;
            }

            $btn.prop('disabled', true).text('Generating...');
            $status.css({ 'color': '#a5b4fc', 'display': 'block' }).text('Generating CSS rules...');

            $.ajax({
                url: window.FlowFlowOpts.ajaxurl,
                type: 'POST',
                data: {
                    action: 'flow_flow_ai_generate_css',
                    security: window.FlowFlowOpts.flow_flow_nonce,
                    prompt: prompt,
                    stream_id: streamId
                },
                success: function(resp) {
                    $btn.prop('disabled', false).text('Generate');

                    if (resp.success && resp.data.css) {
                        $status.css('color', '#34d399').text('Successfully generated!');
                        setTimeout(function() { $status.fadeOut(500); }, 2500);
                        var $textarea = $('#ff-live-editor-css-textarea');
                        var existing = $textarea.val().trim();
                        var delimiter = existing ? '\n\n' : '';
                        var newVal = existing + delimiter + resp.data.css;
                        $textarea.val(newVal).trigger('change');
                        $promptInput.val('');
                    } else {
                        var msg = (resp.data && resp.data.message) ? resp.data.message : 'Failed to generate CSS.';
                        $status.css('color', '#f87171').text('Error: ' + msg);
                    }
                },
                error: function(xhr, status, err) {
                    $btn.prop('disabled', false).text('Generate');
                    $status.css('color', '#f87171').text('Network error. Please try again.');
                }
            });
        }
    };

    // Helper functions
    function stripslashes(str) {
        if (!str) return '';
        return (str + '').replace(/\\(.?)/g, function(s, n1) {
            switch (n1) {
                case '\\': return '\\';
                case '0': return '\u0000';
                case '': return '';
                default: return n1;
            }
        });
    }

    liveEditor.init();

})(window.jQuery);
