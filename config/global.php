<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Glodaxia Branding & Identity
    |--------------------------------------------------------------------------
    | Global settings for the platform branding, meta titles and taglines.
    */
    'site' => [
        'name' => env('APP_NAME', 'Glodaxia'),
        'tagline' => [
            'es' => 'Periodismo Tecnológico de Vanguardia',
            'en' => 'Next-Gen Technology Journalism',
        ],
        'description' => [
            'es' => 'Tu fuente diaria de noticias, análisis y tendencias sobre Inteligencia Artificial, desarrollo de software, ciberseguridad y tecnología global.',
            'en' => 'Your daily source for tech news, in-depth analysis, and emerging trends in AI, software development, cybersecurity, and global innovation.',
        ],
        'url' => env('APP_URL', 'https://glodaxia.com'),
        'contact_email' => env('CONTACT_EMAIL', 'hi@glodaxia.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security & Super Administrators
    |--------------------------------------------------------------------------
    | Centralized list of emails with implicit root SuperAdmin access.
    | Protected against accidental deletion, full gate bypass in Filament.
    */
    'security' => [
        'super_admins' => array_values(array_filter(array_map('trim', explode(',', env(
            'SUPER_ADMIN_EMAILS',
            'sifuncion@gmail.com,admin@glodaxia.com,luis.figuera@glodaxia.com'
        ))))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Media Storage & CDN
    |--------------------------------------------------------------------------
    | Cloudflare R2 public endpoint / CDN domain for assets and images.
    */
    'media' => [
        'public_url' => rtrim(env('R2_PUBLIC_URL', 'https://media.glodaxia.com'), '/'),
        'image_conversions' => [
            'thumb'  => ['width' => 480,  'height' => 270],
            'medium' => ['width' => 800,  'height' => 450],
            'large'  => ['width' => 1200, 'height' => 675],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Editorial & AI Directives (Google E-E-A-T & Quality Standards)
    |--------------------------------------------------------------------------
    | Rules for AI content generation, word counts, personas, and focus guidelines.
    */
    'editorial' => [
        // Persona for the AI journalist
        'persona' => 'world-class Senior Technology Journalist and elite SEO copywriter (15+ years experience) working for Glodaxia, a premium tech publication.',

        // Strict grounding rules against hallucinations
        'focus_rules' => 'STRICTLY ADHERE TO THE FACTS PROVIDED. NEVER invent names, dates, statistics, or events not present in the SOURCE FACTS.',

        // Default author fallback if no active author is found
        'default_author' => [
            'name' => [
                'es' => 'Luis Figuera',
                'en' => 'Luis Figuera',
            ],
            'email' => 'luis@glodaxia.com',
            'slug'  => 'luis-figuera',
            'bio'   => [
                'es' => 'Especialista en redacción y análisis tecnológico en Glodaxia.',
                'en' => 'Technology analysis and digital journalism specialist at Glodaxia.',
            ],
        ],

        // -------------------------------------------------------------------
        // Standard Character & Length Limits (Single Source of Truth)
        // -------------------------------------------------------------------
        'limits' => [
            'title' => [
                'min'       => 50,
                'max'       => 130,
                'min_words' => 7,
            ],
            'excerpt' => [
                'min' => 160,
                'max' => 250,
            ],
            'meta_title' => [
                'min' => 50,
                'max' => 70,
            ],
            'meta_description' => [
                'min' => 120,
                'max' => 160,
            ],
            'image_alt' => [
                'max' => 125,
            ],
            'image_title' => [
                'max' => 70,
            ],
            'card' => [
                'title_max_words' => 15,
                'title_max'       => 130,
                'excerpt_max'     => 160,
            ],
            'reading_time_wpm' => 200,
            'raw_preview_chars' => 2000,
            'min_words' => [
                'news'   => 500,
                'blog'   => 650,
                'guide'  => 900,
                'review' => 700,
                'pillar' => 1200,
            ],
        ],

        // Word count targets per content type (for prompt context)
        'word_targets' => [
            'news'   => '600-1000 words EN | 600-1000 palabras ES (Mínimo: 500 palabras)',
            'blog'   => '700-1200 words EN | 700-1200 palabras ES (Mínimo: 650 palabras)',
            'guide'  => '900-1600 words EN | 900-1600 palabras ES (Mínimo: 900 palabras)',
            'review' => '700-1200 words EN | 700-1200 palabras ES (Mínimo: 700 palabras)',
            'pillar' => '1200-2200 words EN | 1200-2200 palabras ES (Mínimo: 1200 palabras)',
        ],

        // -------------------------------------------------------------------
        // Anti-AI Fingerprint Filter (Banned Clichés & Metaphors)
        // -------------------------------------------------------------------
                // -------------------------------------------------------------------
        // Strict Legal, Defamation & Journalistic Ethics Safeguards
        // -------------------------------------------------------------------
        'legal_ethics' => [
            'zero_defamation'       => true,  // Prohibit unverified accusations against people/companies
            'mandatory_attribution' => true,  // All non-obvious claims must cite source
            'presumption_innocence' => true,  // Use conditional language in disputes/allegations
            'prohibited_language'   => [
                'insults', 'mockery', 'humiliation', 'slander', 'libel', 'malicious speculation'
            ],
            'standard'              => 'AP Stylebook / Reuters Trust Principles / SPJ Code of Ethics',
        ],

        'blocked_phrases' => [
            'en' => [
                'paradigm shift', 'game-changer', 'revolutionary', 'democratization of',
                'inflection point', 'trajectory points toward', 'unprecedented scale',
                'seamlessly integrate', 'robust ecosystem', 'the digital landscape',
                'it remains to be seen', 'only time will tell', "it's worth noting",
                "in today's rapidly evolving", 'at the end of the day',
                'raises important questions', 'a bold step forward', 'double-edged sword',
                'the implications are profound', 'a testament to',
                "let's dive in", 'let me break this down', 'in my experience',
                'low-hanging fruit', 'home run', 'slam dunk', 'picture this',
                'beacon of hope', 'tapestry', 'delve', 'orchestrate', 'in conclusion',
            ],
            'es' => [
                'cambio de paradigma', 'en conclusión', 'sin lugar a dudas',
                'cabe destacar', 'queda por ver', 'un arma de doble filo',
                'marca un antes y un después', 'las implicaciones son profundas',
                'en el mundo de', 'sin ir más lejos',
                'como ya hemos mencionado', 'en última instancia',
                'es importante destacar',
                'sin duda alguna', 'no cabe duda', 'vale la pena mencionar',
                'el panorama digital', 'faro de esperanza', 'espada de doble filo',
                'tapiz', 'adentrarse', 'testimonio de', 'orquestar', 'al final del día',
                'solo el tiempo dirá', 'en el mundo actual',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Anti-Duplicate Detection Engine
    |--------------------------------------------------------------------------
    | Thresholds and parameters for the multi-tier duplicate prevention system.
    */
    'duplicate_detection' => [
        'event_slug_window_hours' => 36,
        'fuzzy_similarity_threshold' => 75,
        'semantic_exact_distance' => 0.18,
        'semantic_judge_distance' => 0.35,
        'search_window_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Transparency & Feature Toggles
    |--------------------------------------------------------------------------
    | Feature flags for frontend components and disclosure notices.
    */
    'features' => [
        'show_source_links'   => true,  // Show canonical source link on articles
        'show_editorial_note' => true,  // Show "Written with technological support" disclosure
    ],
    /*
    |--------------------------------------------------------------------------
    | Master Taxonomy & Bilingual Tag Seeds
    |--------------------------------------------------------------------------
    | Predefined bilingual names and slugs for automatic high-quality tag creation.
    */
    'taxonomy_map' => [
        'openai' => ['en' => 'OpenAI', 'es' => 'OpenAI', 'slug_en' => 'openai', 'slug_es' => 'openai'],
        'google' => ['en' => 'Google', 'es' => 'Google', 'slug_en' => 'google', 'slug_es' => 'google'],
        'meta' => ['en' => 'Meta', 'es' => 'Meta', 'slug_en' => 'meta', 'slug_es' => 'meta'],
        'microsoft' => ['en' => 'Microsoft', 'es' => 'Microsoft', 'slug_en' => 'microsoft', 'slug_es' => 'microsoft'],
        'nvidia' => ['en' => 'NVIDIA', 'es' => 'NVIDIA', 'slug_en' => 'nvidia', 'slug_es' => 'nvidia'],
        'apple' => ['en' => 'Apple', 'es' => 'Apple', 'slug_en' => 'apple', 'slug_es' => 'apple'],
        'anthropic' => ['en' => 'Anthropic', 'es' => 'Anthropic', 'slug_en' => 'anthropic', 'slug_es' => 'anthropic'],
        'amazon' => ['en' => 'Amazon', 'es' => 'Amazon', 'slug_en' => 'amazon', 'slug_es' => 'amazon'],
        'tesla' => ['en' => 'Tesla', 'es' => 'Tesla', 'slug_en' => 'tesla', 'slug_es' => 'tesla'],
        'artificial-intelligence' => ['en' => 'Artificial Intelligence', 'es' => 'Inteligencia Artificial', 'slug_en' => 'artificial-intelligence', 'slug_es' => 'inteligencia-artificial'],
        'large-language-models' => ['en' => 'Large Language Models', 'es' => 'Modelos de Lenguaje', 'slug_en' => 'large-language-models', 'slug_es' => 'modelos-de-lenguaje'],
        'ai-agents' => ['en' => 'AI Agents', 'es' => 'Agentes de IA', 'slug_en' => 'ai-agents', 'slug_es' => 'agentes-de-ia'],
        'computer-vision' => ['en' => 'Computer Vision', 'es' => 'Visión Computacional', 'slug_en' => 'computer-vision', 'slug_es' => 'vision-computacional'],
        'robotics-automation' => ['en' => 'Robotics & Automation', 'es' => 'Robótica & Automatización', 'slug_en' => 'robotics-automation', 'slug_es' => 'robotica-automatizacion'],
        'reinforcement-learning' => ['en' => 'Reinforcement Learning', 'es' => 'Aprendizaje por Refuerzo', 'slug_en' => 'reinforcement-learning', 'slug_es' => 'aprendizaje-por-refuerzo'],
        'ai-ethics-safety' => ['en' => 'AI Ethics & Safety', 'es' => 'Ética & Seguridad en IA', 'slug_en' => 'ai-ethics-safety', 'slug_es' => 'etica-seguridad-ia'],
        'ai-regulation-policy' => ['en' => 'AI Regulation & Policy', 'es' => 'Regulación & Políticas de IA', 'slug_en' => 'ai-regulation-policy', 'slug_es' => 'regulacion-politicas-ia'],
        'cybersecurity' => ['en' => 'Cybersecurity', 'es' => 'Ciberseguridad', 'slug_en' => 'cybersecurity', 'slug_es' => 'ciberseguridad'],
        'vulnerabilities-exploits' => ['en' => 'Vulnerabilities & Exploits', 'es' => 'Vulnerabilidades & Exploits', 'slug_en' => 'vulnerabilities-exploits', 'slug_es' => 'vulnerabilidades-exploits'],
        'ransomware-malware' => ['en' => 'Ransomware & Malware', 'es' => 'Ransomware & Malware', 'slug_en' => 'ransomware-malware', 'slug_es' => 'ransomware-malware'],
        'data-privacy-protection' => ['en' => 'Data Privacy & Protection', 'es' => 'Privacidad & Protección de Datos', 'slug_en' => 'data-privacy-protection', 'slug_es' => 'privacidad-proteccion-datos'],
        'cloud-computing' => ['en' => 'Cloud Computing', 'es' => 'Computación en la Nube', 'slug_en' => 'cloud-computing', 'slug_es' => 'computacion-en-la-nube'],
        'digital-infrastructure' => ['en' => 'Digital Infrastructure', 'es' => 'Infraestructura Digital', 'slug_en' => 'digital-infrastructure', 'slug_es' => 'infraestructura-digital'],
        'hardware-semiconductors' => ['en' => 'Hardware & Semiconductors', 'es' => 'Hardware & Semiconductores', 'slug_en' => 'hardware-semiconductors', 'slug_es' => 'hardware-semiconductores'],
        'software-engineering' => ['en' => 'Software Engineering', 'es' => 'Ingeniería de Software', 'slug_en' => 'software-engineering', 'slug_es' => 'ingenieria-de-software'],
        'devops-cicd' => ['en' => 'DevOps & CI/CD', 'es' => 'DevOps & CI/CD', 'slug_en' => 'devops-cicd', 'slug_es' => 'devops-cicd'],
        'open-source' => ['en' => 'Open Source', 'es' => 'Código Abierto', 'slug_en' => 'open-source', 'slug_es' => 'codigo-abierto'],
        'databases-storage' => ['en' => 'Databases & Storage', 'es' => 'Bases de Datos & Almacenamiento', 'slug_en' => 'databases-storage', 'slug_es' => 'bases-de-datos-almacenamiento'],
        'web-development' => ['en' => 'Web Development', 'es' => 'Desarrollo Web', 'slug_en' => 'web-development', 'slug_es' => 'desarrollo-web'],
        'apis-microservices' => ['en' => 'APIs & Microservices', 'es' => 'APIs & Microservicios', 'slug_en' => 'apis-microservices', 'slug_es' => 'apis-microservicios'],
        'science-innovation' => ['en' => 'Science & Innovation', 'es' => 'Ciencia & Innovación', 'slug_en' => 'science-innovation', 'slug_es' => 'ciencia-innovacion'],
        'startups-venture-capital' => ['en' => 'Startups & Venture Capital', 'es' => 'Startups & Capital de Riesgo', 'slug_en' => 'startups-venture-capital', 'slug_es' => 'startups-capital-riesgo'],
        'e-commerce-digital-economy' => ['en' => 'E-Commerce & Digital Economy', 'es' => 'Comercio Electrónico & Economía Digital', 'slug_en' => 'e-commerce-digital-economy', 'slug_es' => 'comercio-electronico-economia-digital'],
    ],
];
