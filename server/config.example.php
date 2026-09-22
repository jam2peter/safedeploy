<?php
declare(strict_types=1);

/*
 * Copy this file to your private SafeDeploy directory as config.php.
 * Never place database credentials in the public repository or webroot.
 */
return [
    'api_version' => '0.1',
    'issuer' => 'https://token.actions.githubusercontent.com',
    'jwks_url' => 'https://token.actions.githubusercontent.com/.well-known/jwks',
    'audience' => 'https://deploy.example.com/safedeploy/',

    'trust' => [
        'repository' => 'owner/repository',
        // Strongly recommended. Find it with GitHub's repository API.
        'repository_id' => '',
        'owner' => 'owner',
        'refs' => ['refs/heads/main'],
        'events' => ['push', 'workflow_dispatch'],
        // Leave empty to accept any workflow from the trusted repository/ref.
        // For tighter policy use:
        // 'owner/repository/.github/workflows/deploy.yml@refs/heads/main'
        'job_workflow_refs' => [],
    ],

    'db' => [
        'dsn' => 'mysql:host=localhost;dbname=safedeploy;charset=utf8mb4',
        'user' => 'safedeploy',
        'password' => 'CHANGE_ME',
    ],

    // Must be outside the public webroot and writable by PHP.
    'state_dir' => '/home/example/safedeploy-private/state',

    // Logical root => absolute directory. A target such as apps/my-app
    // resolves to /home/example/public_html/apps/my-app.
    'deploy_roots' => [
        'apps' => '/home/example/public_html/apps',
    ],

    'limits' => [
        'max_files' => 2000,
        'max_bytes' => 67108864,
    ],

    'jwks_cache_seconds' => 3600,
];
