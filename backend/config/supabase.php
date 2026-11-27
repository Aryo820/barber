<?php

return [
    'url' => rtrim((string) env('SUPABASE_URL', ''), '/'),
    'anon_key' => (string) env('SUPABASE_ANON_KEY', ''),
    'admin_emails' => array_values(array_filter(
        array_map(
            static fn (string $email): string => strtolower(trim($email)),
            explode(',', (string) env('SUPABASE_ADMIN_EMAILS', '')),
        ),
        static fn (string $email): bool => $email !== '',
    )),
];
