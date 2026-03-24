{{-- Facebook Messenger Chat Plugin — deferred until user interaction --}}
@php
    $messengerSettings = \App\Models\MessengerSetting::getInstance();
@endphp

@if($messengerSettings->website_widget_enabled && $messengerSettings->page_id)
    <div id="fb-root"></div>
    <div id="fb-customer-chat"
        class="fb-customerchat"
        attribution="biz_inbox"
        page_id="{{ $messengerSettings->page_id }}"
        theme_color="{{ $messengerSettings->widget_theme_color ?? '#0084ff' }}"
        logged_in_greeting="{{ $messengerSettings->widget_greeting_logged_in ?? 'Hi! Need a painting quote?' }}"
        logged_out_greeting="{{ $messengerSettings->widget_greeting_logged_out ?? 'Hi! Need a painting quote?' }}">
    </div>
    <script>
    (function() {
        var loaded = false;
        function loadMessenger() {
            if (loaded) return;
            loaded = true;
            window.fbAsyncInit = function() {
                FB.init({ xfbml: true, version: 'v19.0' });
            };
            var d = document, s = 'script', id = 'facebook-jssdk';
            if (d.getElementById(id)) return;
            var js = d.createElement(s); js.id = id;
            js.src = 'https://connect.facebook.net/en_US/sdk/xfbml.customerchat.js';
            d.getElementsByTagName(s)[0].parentNode.insertBefore(js, d.getElementsByTagName(s)[0]);
        }
        ['scroll','mousemove','touchstart','keydown'].forEach(function(e) {
            window.addEventListener(e, loadMessenger, {once: true, passive: true});
        });
        setTimeout(loadMessenger, 5000);
    })();
    </script>
@endif
