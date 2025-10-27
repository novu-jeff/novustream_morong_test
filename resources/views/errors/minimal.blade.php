<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Error')</title>
    <style>
        html {font-family:system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica Neue,Arial,Noto Sans,sans-serif;line-height:1.5;margin:0;}
        body {margin:0; background-color:#f7fafc; color:#1a202c; display:flex; justify-content:center; align-items:center; min-height:100vh; padding:1rem;}
        .card {background-color:white; padding:2rem; border-radius:1rem; max-width:400px; width:100%; box-shadow:0 4px 12px rgba(0,0,0,0.1); text-align:center;}
        .code {font-size:4rem; font-weight:bold; color:#e53e3e; margin-bottom:1rem;}
        .message {font-size:1.125rem; color:#4a5568; margin-bottom:2rem; line-height:1.6;}
        .button {display:inline-block; padding:0.5rem 1.5rem; background-color:#fed7d7; color:#c53030; border-radius:0.5rem; text-decoration:none; font-weight:600; transition: background-color 0.3s;}
        .button:hover {background-color:#feb2b2;}
        img {max-width:150px; height:auto; margin-bottom:1.5rem;}
    </style>
</head>
<body>
    <div class="card">
        <!-- Optional friendly SVG illustration -->
        <img src="/images/error404.svg" alt="Error Illustration">

        <!-- Error Code -->
        <div class="code">@yield('code', '404')</div>

        <!-- Error Message -->
        <div class="message">
            @yield('message', 'Oops! Something went wrong.')<br>
            @yield('message', 'If this page exists, please contact the admin.')
        </div>

        <!-- Back Button -->
        <a href="{{ url()->previous() }}" class="button">← Go Back</a>
    </div>
</body>
</html>
