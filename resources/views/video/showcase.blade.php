<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Property Showcase - {{ config('app.name') }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background-color: #111;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            width: 100%;
        }
        .video-wrapper {
            position: relative;
            width: 100%;
            aspect-ratio: 16/9;
            background-color: #000;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        #video-player-root {
            width: 100%;
            height: 100%;
        }
        .branding {
            margin-top: 16px;
            text-align: center;
            color: #666;
            font-size: 13px;
        }
        .branding a {
            color: #888;
            text-decoration: none;
        }
        .branding a:hover {
            color: #fff;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="video-wrapper">
            <div id="video-player-root"></div>
        </div>
        <div class="branding">
            Powered by <a href="{{ url('/') }}">{{ config('app.name') }}</a>
        </div>
    </div>

    @vite('resources/js/video-player.tsx')
</body>
</html>
