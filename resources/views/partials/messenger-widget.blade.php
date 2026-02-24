{{-- Facebook Messenger Chat Plugin --}}
{{-- Note: Facebook has been transitioning their Chat Plugin. If deprecated at implementation time,
     alternatives include Send-to-Messenger button or m.me/PAGE_ID URLs.
     The backend integration remains the same regardless. --}}
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
        window.fbAsyncInit = function() {
            FB.init({
                xfbml: true,
                version: 'v19.0'
            });
        };
        (function(d, s, id) {
            var js, fjs = d.getElementsByTagName(s)[0];
            if (d.getElementById(id)) return;
            js = d.createElement(s); js.id = id;
            js.src = 'https://connect.facebook.net/en_US/sdk/xfbml.customerchat.js';
            fjs.parentNode.insertBefore(js, fjs);
        }(document, 'script', 'facebook-jssdk'));
    </script>
@endif
