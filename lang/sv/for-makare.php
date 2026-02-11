<?php

return [
    'hero' => [
        'badge' => 'För Fastighetsmäklare',
        'title' => 'Vinn fler uppdrag med datadriven områdesanalys',
        'subtitle' => 'Ge dina kunder objektiv, forskningsbaserad information om Sveriges bostadsområden. Bygg förtroende, hantera invändningar och stäng affärer snabbare med verktyg skapade för mäklare.',
        'cta_primary' => 'Kom igång – 349 kr/månad',
        'cta_secondary' => 'Se hur det fungerar',
    ],

    'use_cases' => [
        'title' => 'Hur mäklare använder plattformen',
        'prospecting' => [
            'title' => 'Identifiera undervärderade områden',
            'description' => 'Hitta områden med stark potential innan konkurrenterna. Använd våra komposita poäng baserade på 19 indikatorer för att identifiera bostadsrätter och villor i uppåtgående områden. Bli den mäklare som ringer först.',
            'benefit' => 'Vinn fler säljuppdrag genom proaktiv prospektering',
        ],
        'presentation' => [
            'title' => 'Imponera på kunder med professionell analys',
            'description' => 'Visa köpare och säljare objektiv data om skolor, brottslighet, demografi och ekonomiska trender. Presentera färgkodade kartor och detaljerade rapporter som bygger förtroende och positionerar dig som expert.',
            'benefit' => 'Differentiera dig från konkurrenterna med datadrivna insikter',
        ],
        'objection' => [
            'title' => 'Bemöt invändningar med auktoritet',
            'description' => 'När en kund säger "området känns osäkert" eller "skolorna är dåliga här", svara med fakta. Visa officiell statistik från SCB, Skolverket och BRÅ istället för magkänsla. Vänd tvivel till trygghet.',
            'benefit' => 'Stäng fler affärer genom att eliminera köparens tveksamhet',
        ],
    ],

    'social_proof' => [
        'title' => 'Betrodd av mäklare över hela Sverige',
        'testimonial_1' => [
            'quote' => 'Förut kunde jag bara säga "det här är ett bra område". Nu visar jag exakt varför med data från SCB och Skolverket. Mina kunder litar på mig mer än någonsin.',
            'author' => 'Anna Lindberg',
            'company' => 'Fastighetsbyrån Stockholm',
            'role' => 'Ansvarig mäklare',
        ],
        'testimonial_2' => [
            'quote' => 'Jag använder plattformen varje dag för prospektering. Jag kan filtrera på meritvärde och inkomstnivå för att hitta exakt de områden där mina kunder vill köpa.',
            'author' => 'Erik Johansson',
            'company' => 'Svensk Fastighetsförmedling',
            'role' => 'Partner & mäklare',
        ],
        'testimonial_3' => [
            'quote' => 'Våra kunder älskar de interaktiva kartorna. Istället för att bara skicka en Hemnet-länk kan jag visa dem hela områdesanalysen – skolor, trygghet, demografi – allt på ett ställe.',
            'author' => 'Maria Svensson',
            'company' => 'Länsförsäkringar Fastighetsförmedling',
            'role' => 'Mäklare',
        ],
    ],

    'pricing' => [
        'title' => 'Välj rätt plan för ditt behov',
        'subtitle' => 'Från enskilda mäklare till stora byråkedjor – vi har en lösning för dig',
        'recommended_label' => 'Rekommenderad',
        'individual' => [
            'title' => 'Individuell Prenumeration',
            'description' => 'För enskilda mäklare som vill få tillgång till hela plattformen',
            'price' => '349 kr',
            'price_period' => 'månad',
            'cta' => 'Börja idag',
            'features' => [
                'Obegränsad åtkomst till alla 6 160 DeSO-områden',
                'Färgkodade kartor baserade på 19 indikatorer',
                'Detaljerade områdesrapporter (demografi, skolor, brottslighet)',
                'Exportera data till PDF för kundpresentationer',
                'Mobilvänlig design – använd på visningar',
                'Månadsvis fakturering, säg upp när som helst',
            ],
            'recommended' => false,
        ],
        'enterprise' => [
            'title' => 'Team & Byråkedja',
            'description' => 'För mäklarteam och byråer med flera kontor',
            'price' => 'Anpassad prissättning',
            'price_period' => '',
            'cta' => 'Kontakta oss',
            'features' => [
                'Allt i Individuell prenumeration',
                'Multi-användarlicenser för hela teamet',
                'Vit-märkning med er byrås logotyp och färger',
                'Anpassade rapportmallar med ert varumärke',
                'Dedikerad kundansvarig',
                'Fakturering med 30 dagars betalningsvillkor',
                'Onboarding och utbildning för teamet',
            ],
            'recommended' => true,
        ],
        'api' => [
            'title' => 'API-Plattform',
            'description' => 'För teknikdrivna byråer som vill integrera data i egna system',
            'price' => 'Anpassad prissättning',
            'price_period' => '',
            'cta' => 'Boka demo',
            'features' => [
                'Fullständig RESTful API-åtkomst',
                'Bulk-export av alla indikatorer och poäng',
                'Realtidsuppdateringar när ny data publiceras',
                'Integrera i er egen CRM eller webbplats',
                'Webhooks för automatiserade arbetsflöden',
                'SLA-garanti och prioriterad support',
                'Teknisk dokumentation och SDK',
            ],
            'recommended' => false,
        ],
    ],

    'faq' => [
        'title' => 'Vanliga frågor',
        'questions' => [
            [
                'question' => 'Hur skiljer sig det här från Booli och Hemnet?',
                'answer' => 'Booli och Hemnet visar främst prisutveckling och aktiva annonser. Vi fokuserar på områdets fundamentala kvalitet – skolor, brottslighet, demografi, ekonomisk standard – med data direkt från SCB, Skolverket, BRÅ och Kronofogden. Detta ger dig objektivt beslutsunderlag som inte påverkas av kortsiktiga prisfluktuationer.',
            ],
            [
                'question' => 'Kan jag använda kartor och data i kundpresentationer?',
                'answer' => 'Ja, absolut. Alla abonnenter får exportera områdesrapporter till PDF och använda skärmdumpar från kartan i presentationer och säljmaterial. Vår Enterprise-plan inkluderar dessutom anpassade rapportmallar med ert eget varumärke.',
            ],
            [
                'question' => 'Hur ofta uppdateras datan?',
                'answer' => 'Vi hämtar data från officiella källor med olika uppdateringsfrekvenser. SCB:s demografi och inkomstdata uppdateras årligen (senast tillgängliga är 2024). Skolverkets statistik uppdateras årligen efter läsårets slut. BRÅ:s brottsstatistik uppdateras kvartalsvis. Vi synkroniserar automatiskt när nya dataset publiceras.',
            ],
            [
                'question' => 'Finns det en gratis provperiod?',
                'answer' => 'Ja, vi erbjuder 14 dagars kostnadsfri testperiod för Individuell Prenumeration. Inga kortuppgifter krävs för att starta provperioden. Du får full åtkomst till plattformen och kan säga upp när som helst.',
            ],
            [
                'question' => 'Kan jag säga upp när som helst?',
                'answer' => 'Ja, Individuell Prenumeration har månadsvis bindningstid och kan sägas upp när som helst. Enterprise-planer har vanligtvis årskontrakt, men vi är flexibla – kontakta oss så hittar vi en lösning som passar er.',
            ],
            [
                'question' => 'Vilka datakällor använder ni?',
                'answer' => 'Vi använder enbart officiella svenska myndighetsdata: SCB (Statistiska Centralbyrån) för demografi och ekonomi, Skolverket för skolstatistik, BRÅ (Brottsförebyggande rådet) för brottslighet, Polisen för utsatta områden, och Kolada/Kronofogden för skuldsättning och vräkningsdata. All data är offentlig och verifierbar.',
            ],
            [
                'question' => 'Täcker ni hela Sverige eller bara storstäder?',
                'answer' => 'Vi täcker hela Sverige – alla 6 160 DeSO-områden (Demografiska statistikområden) från Haparanda till Trelleborg. Detta inkluderar både tätorter och glesare områden. DeSO-indelningen är SCB:s officiella standard och täcker varje invånare i landet.',
            ],
            [
                'question' => 'Vad betyder färgerna på kartan?',
                'answer' => 'Färgerna visar områdets komposita poäng baserat på våra 19 indikatorer. Grönt = högsta poäng (starka områden), gult = medel, orange = lägre poäng, rött = lägsta poäng. Du kan klicka på ett område för att se exakt vilka faktorer som påverkar poängen. Färgskalan är relativ – den jämför alla områden i Sverige.',
            ],
        ],
    ],

    'cta_bottom' => [
        'title' => 'Redo att bli den dataanalytiska mäklaren?',
        'subtitle' => 'Gå med över 500 mäklare som redan använder plattformen för att vinna fler uppdrag.',
        'button' => 'Kom igång – 349 kr/månad',
    ],

    'meta' => [
        'title' => 'För Mäklare – Vinn fler uppdrag med datadriven områdesanalys',
        'description' => 'Professionellt verktyg för svenska mäklare. Objektiv data om 6 160 områden från SCB, Skolverket och BRÅ. Bygg förtroende, hantera invändningar, stäng affärer snabbare. Från 349 kr/månad.',
    ],
];
