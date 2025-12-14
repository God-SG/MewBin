<?php
// Cloudflare R2 Configuration
return [
    'r2' => [
        'key' => '8ec515f579696bed492310adcf7dc84d',
        'secret' => '79544fb76b79560aed6934e1071358319ad35a8acdfdf00da4872c5b11472cf1',
        'region' => 'auto',
        'bucket' => 'user-media',
        'account_id' => 'ca1cc0e193daad95b594ed6a6f91ad0d',
        'public_url' => 'https://pub-e2a0d5fdb6b643c1bd302919fc06bd3a.r2.dev',
        'cdn_url' => 'https://pub-e2a0d5fdb6b643c1bd302919fc06bd3a.r2.dev' // Using the same as public_url since we want to use R2's public URL
    ]
];
