<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Primary AI Model (OpenRouter Strictly)
    |--------------------------------------------------------------------------
    | The #1 highest-priority, cost-effective model for redaction and intelligence.
    */
    'default' => 'deepseek/deepseek-v4-flash',

    /*
    |--------------------------------------------------------------------------
    | AI Models Pool / Failover Priority Chain (OpenRouter Only)
    |--------------------------------------------------------------------------
    | Ordered strictly by priority and cost-efficiency:
    | 1. deepseek/deepseek-v4-flash    (Top #1: Best analytical depth & bilingual quality)
    | 2. qwen/qwen3.7-flash            (Top #2: Ultra fast, flawless JSON formatting)
    | 3. deepseek/deepseek-v3.2        (Alternative high-reasoning DeepSeek)
    | 4. qwen/qwen3.8-flash            (Next-gen Qwen speed & context)
    | 5. meta-llama/llama-4-scout      (Meta Llama 4 high-speed editorial)
    | 6. meta-llama/llama-4-maverick   (Meta Llama 4 deep context)
    | 7. bytedance-seed/seed-1.6-flash (ByteDance robust flash failover)
    */
    'pool' => [
        'deepseek/deepseek-v4-flash',
        'qwen/qwen3.7-flash',
        'deepseek/deepseek-v3.2',
        'qwen/qwen3.8-flash',
        'meta-llama/llama-4-scout',
        'meta-llama/llama-4-maverick',
        'bytedance-seed/seed-1.6-flash',
    ],

    /*
    |--------------------------------------------------------------------------
    | Centralized UI Metadata (Badges & Colors in Filament)
    |--------------------------------------------------------------------------
    */
    'models' => [
        'deepseek/deepseek-v4-flash' => [
            'name'  => 'DeepSeek V4 Flash',
            'color' => 'success',
        ],
        'qwen/qwen3.7-flash' => [
            'name'  => 'Qwen 3.7 Flash',
            'color' => 'warning',
        ],
        'deepseek/deepseek-v3.2' => [
            'name'  => 'DeepSeek V3.2',
            'color' => 'success',
        ],
        'qwen/qwen3.8-flash' => [
            'name'  => 'Qwen 3.8 Flash',
            'color' => 'warning',
        ],
        'meta-llama/llama-4-scout' => [
            'name'  => 'Llama 4 Scout',
            'color' => 'info',
        ],
        'meta-llama/llama-4-maverick' => [
            'name'  => 'Llama 4 Maverick',
            'color' => 'info',
        ],
        'bytedance-seed/seed-1.6-flash' => [
            'name'  => 'Seed 1.6 Flash',
            'color' => 'primary',
        ],
        // Legacy / Compatibility aliases
        'deepseek/deepseek-v4-flash-0731' => [
            'name'  => 'DeepSeek V4 Flash (0731)',
            'color' => 'success',
        ],
        'deepseek/deepseek-chat' => [
            'name'  => 'DeepSeek V3',
            'color' => 'success',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Limits & Performance Tuners
    |--------------------------------------------------------------------------
    */
    'max_tokens'                => 14000,
    'classification_max_tokens' => 3500,
    'tag_max_tokens'            => 1000,
    'temperature'               => 0.7,
    'timeout'                   => 300,
];
