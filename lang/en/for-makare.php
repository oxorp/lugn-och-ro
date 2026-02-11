<?php

return [
    'hero' => [
        'badge' => 'For Real Estate Agents',
        'title' => 'Win more listings with data-driven neighborhood analysis',
        'subtitle' => 'Provide your clients with objective, research-based information about Swedish residential areas. Build trust, handle objections, and close deals faster with tools built for real estate agents.',
        'cta_primary' => 'Get started – 349 SEK/month',
        'cta_secondary' => 'See how it works',
    ],

    'use_cases' => [
        'title' => 'How agents use the platform',
        'prospecting' => [
            'title' => 'Identify undervalued neighborhoods',
            'description' => 'Find areas with strong potential before your competitors. Use our composite scores based on 19 indicators to identify condos and houses in rising neighborhoods. Be the agent who calls first.',
            'benefit' => 'Win more listings through proactive prospecting',
        ],
        'presentation' => [
            'title' => 'Impress clients with professional analysis',
            'description' => 'Show buyers and sellers objective data about schools, crime, demographics, and economic trends. Present color-coded maps and detailed reports that build trust and position you as an expert.',
            'benefit' => 'Differentiate yourself from competitors with data-driven insights',
        ],
        'objection' => [
            'title' => 'Handle objections with authority',
            'description' => 'When a client says "the area feels unsafe" or "the schools are bad here", respond with facts. Show official statistics from SCB, Skolverket, and BRÅ instead of gut feeling. Turn doubt into confidence.',
            'benefit' => 'Close more deals by eliminating buyer hesitation',
        ],
    ],

    'social_proof' => [
        'title' => 'Trusted by agents across Sweden',
        'testimonial_1' => [
            'quote' => 'I used to only say "this is a good neighborhood". Now I show exactly why with data from SCB and Skolverket. My clients trust me more than ever.',
            'author' => 'Anna Lindberg',
            'company' => 'Fastighetsbyrån Stockholm',
            'role' => 'Senior Agent',
        ],
        'testimonial_2' => [
            'quote' => 'I use the platform every day for prospecting. I can filter by merit value and income level to find exactly the areas where my clients want to buy.',
            'author' => 'Erik Johansson',
            'company' => 'Svensk Fastighetsförmedling',
            'role' => 'Partner & Agent',
        ],
        'testimonial_3' => [
            'quote' => 'Our clients love the interactive maps. Instead of just sending a Hemnet link, I can show them the complete neighborhood analysis – schools, safety, demographics – all in one place.',
            'author' => 'Maria Svensson',
            'company' => 'Länsförsäkringar Fastighetsförmedling',
            'role' => 'Agent',
        ],
    ],

    'pricing' => [
        'title' => 'Choose the right plan for your needs',
        'subtitle' => 'From individual agents to large brokerage chains – we have a solution for you',
        'recommended_label' => 'Recommended',
        'individual' => [
            'title' => 'Individual Subscription',
            'description' => 'For individual agents who want access to the entire platform',
            'price' => '349 SEK',
            'price_period' => 'month',
            'cta' => 'Start today',
            'features' => [
                'Unlimited access to all 6,160 DeSO areas',
                'Color-coded maps based on 19 indicators',
                'Detailed neighborhood reports (demographics, schools, crime)',
                'Export data to PDF for client presentations',
                'Mobile-friendly design – use on showings',
                'Monthly billing, cancel anytime',
            ],
            'recommended' => false,
        ],
        'enterprise' => [
            'title' => 'Team & Brokerage Chain',
            'description' => 'For agent teams and brokerages with multiple offices',
            'price' => 'Custom pricing',
            'price_period' => '',
            'cta' => 'Contact us',
            'features' => [
                'Everything in Individual subscription',
                'Multi-user licenses for the entire team',
                'White-labeling with your brokerage logo and colors',
                'Custom report templates with your brand',
                'Dedicated account manager',
                'Invoicing with 30-day payment terms',
                'Onboarding and training for the team',
            ],
            'recommended' => true,
        ],
        'api' => [
            'title' => 'API Platform',
            'description' => 'For tech-driven brokerages who want to integrate data into their own systems',
            'price' => 'Custom pricing',
            'price_period' => '',
            'cta' => 'Book demo',
            'features' => [
                'Full RESTful API access',
                'Bulk export of all indicators and scores',
                'Real-time updates when new data is published',
                'Integrate into your own CRM or website',
                'Webhooks for automated workflows',
                'SLA guarantee and priority support',
                'Technical documentation and SDK',
            ],
            'recommended' => false,
        ],
    ],

    'faq' => [
        'title' => 'Frequently asked questions',
        'questions' => [
            [
                'question' => 'How is this different from Booli and Hemnet?',
                'answer' => 'Booli and Hemnet primarily show price trends and active listings. We focus on the fundamental quality of neighborhoods – schools, crime, demographics, economic standard – with data directly from SCB, Skolverket, BRÅ, and Kronofogden. This gives you objective decision support that isn\'t affected by short-term price fluctuations.',
            ],
            [
                'question' => 'Can I use maps and data in client presentations?',
                'answer' => 'Yes, absolutely. All subscribers can export neighborhood reports to PDF and use screenshots from the map in presentations and sales materials. Our Enterprise plan also includes custom report templates with your own branding.',
            ],
            [
                'question' => 'How often is the data updated?',
                'answer' => 'We fetch data from official sources with different update frequencies. SCB\'s demographics and income data are updated annually (latest available is 2024). Skolverket\'s statistics are updated annually after the academic year ends. BRÅ\'s crime statistics are updated quarterly. We sync automatically when new datasets are published.',
            ],
            [
                'question' => 'Is there a free trial period?',
                'answer' => 'Yes, we offer a 14-day free trial for Individual Subscription. No credit card required to start the trial. You get full access to the platform and can cancel anytime.',
            ],
            [
                'question' => 'Can I cancel anytime?',
                'answer' => 'Yes, Individual Subscription has monthly commitment and can be canceled anytime. Enterprise plans typically have annual contracts, but we\'re flexible – contact us and we\'ll find a solution that fits you.',
            ],
            [
                'question' => 'What data sources do you use?',
                'answer' => 'We use only official Swedish government data: SCB (Statistics Sweden) for demographics and economy, Skolverket for school statistics, BRÅ (Swedish National Council for Crime Prevention) for crime, Police for vulnerable areas, and Kolada/Kronofogden for debt and eviction data. All data is public and verifiable.',
            ],
            [
                'question' => 'Do you cover all of Sweden or just major cities?',
                'answer' => 'We cover all of Sweden – all 6,160 DeSO areas (Demographic Statistical Areas) from Haparanda to Trelleborg. This includes both urban areas and rural regions. The DeSO division is SCB\'s official standard and covers every resident in the country.',
            ],
            [
                'question' => 'What do the colors on the map mean?',
                'answer' => 'The colors show the area\'s composite score based on our 19 indicators. Green = highest score (strong areas), yellow = average, orange = lower score, red = lowest score. You can click on an area to see exactly which factors affect the score. The color scale is relative – it compares all areas in Sweden.',
            ],
        ],
    ],

    'cta_bottom' => [
        'title' => 'Ready to become the data-analytical agent?',
        'subtitle' => 'Join over 500 agents already using the platform to win more listings.',
        'button' => 'Get started – 349 SEK/month',
    ],

    'meta' => [
        'title' => 'For Agents – Win more listings with data-driven neighborhood analysis',
        'description' => 'Professional tool for Swedish real estate agents. Objective data about 6,160 areas from SCB, Skolverket, and BRÅ. Build trust, handle objections, close deals faster. From 349 SEK/month.',
    ],
];
