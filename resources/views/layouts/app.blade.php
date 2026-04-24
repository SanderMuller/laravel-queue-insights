<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Queue Insights</title>
    <script src="https://cdn.tailwindcss.com"></script>
    @livewireStyles
</head>
<body class="bg-gray-50 text-gray-900 antialiased">
    <div class="min-h-screen">
        <header class="border-b border-gray-200 bg-white">
            <div class="mx-auto max-w-7xl px-4 py-4">
                <h1 class="text-xl font-semibold">Queue Insights</h1>
            </div>
        </header>
        <main class="mx-auto max-w-7xl px-4 py-6">
            {{ $slot ?? '' }}
            @yield('content')
        </main>
    </div>
    @livewireScripts
</body>
</html>
