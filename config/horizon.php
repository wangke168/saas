<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => null,

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => 'horizon',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env('HORIZON_PREFIX', 'horizon:'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is dispatched.
    |
    */

    'waits' => [
        'redis:default' => 60,
        'redis:ota-push' => 60,
        'redis:resource-push' => 30,  // 资源方推送需要快速响应
        'redis:ota-sync' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored until you manually
    | delete them. You may adjust these values based on your needs.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots you want Horizon to store
    | in order to compute metrics. Horizon will store 3 snapshots per
    | minute and you can adjust this value based on your needs.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to finish executing their current jobs
    | before terminating. This may be useful if you have long-running
    | jobs that you don't want to be terminated.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum memory amount that may be consumed
    | by Horizon before it is stopped and restarted. For preventing these
    | restarts from causing problems with your queue workers, Horizon will
    | stop working when it is approaching this limit. Then, it will be
    | the responsibility of the process monitor to restart Horizon.
    |
    */

    'memory_limit' => 512,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    'environments' => [
        'production' => [
            'supervisor-ota-push' => [
                'connection' => 'redis',
                'queue' => ['ota-push'],
                'balance' => 'simple',
                'processes' => 3,
                'tries' => 3,
                'timeout' => 600,  // 10分钟超时（推送大量数据）
                'nice' => 0,
                'maxTime' => 0,
                'maxJobs' => 0,
                'maxMemory' => 512,
                'force' => false,
                'rest' => 0,
            ],
            'supervisor-resource-push' => [
                'connection' => 'redis',
                'queue' => ['resource-push'],
                'balance' => 'simple',
                'processes' => 5,  // 资源方推送需要更多worker（高并发）
                'tries' => 3,
                'timeout' => 300,  // 5分钟超时
                'nice' => 0,
                'maxTime' => 0,
                'maxJobs' => 0,
                'maxMemory' => 512,
                'force' => false,
                'rest' => 0,
            ],
            'supervisor-ota-sync' => [
                'connection' => 'redis',
                'queue' => ['ota-sync'],
                'balance' => 'simple',
                'processes' => 2,  // 同步任务不需要太多worker
                'tries' => 3,
                'timeout' => 300,
                'nice' => 0,
                'maxTime' => 0,
                'maxJobs' => 0,
                'maxMemory' => 512,
                'force' => false,
                'rest' => 0,
            ],
            'supervisor-default' => [
                'connection' => 'redis',
                'queue' => ['default'],
                'balance' => 'simple',
                'processes' => 3,  // 默认队列处理各种任务
                'tries' => 3,
                'timeout' => 300,
                'nice' => 0,
                'maxTime' => 0,
                'maxJobs' => 0,
                'maxMemory' => 512,
                'force' => false,
                'rest' => 0,
            ],
        ],

        'local' => [
            'supervisor-default' => [
                'connection' => 'redis',
                'queue' => ['default', 'ota-push', 'resource-push', 'ota-sync'],
                'balance' => 'simple',
                'processes' => 2,
                'tries' => 3,
                'timeout' => 300,
                'nice' => 0,
                'maxTime' => 0,
                'maxJobs' => 0,
                'maxMemory' => 512,
                'force' => false,
                'rest' => 0,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | The following array of class names will be ignored during Horizon's
    | job trimming process. These jobs will never be pruned even if they
    | are older than the configured "trim" times.
    |
    */

    'silenced' => [
        // \App\Jobs\ExampleJob::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Balancing Strategy
    |--------------------------------------------------------------------------
    |
    | This option determines how Horizon balances the workload across
    | different queues. You may choose from "simple", "auto", or "false".
    | When "auto" is used, Horizon will automatically balance based on
    | the workload of each queue. When "false" is used, no balancing
    | will occur.
    |
    */

    'balance' => 'simple',

    /*
    |--------------------------------------------------------------------------
    | Dark Mode
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon will render its dashboard in
    | dark mode. Otherwise, Horizon will render its dashboard in light
    | mode. This option has no effect if Horizon's middleware group
    | does not include the "web" middleware group.
    |
    */

    'dark_mode' => env('HORIZON_DARK_MODE', false),

];


