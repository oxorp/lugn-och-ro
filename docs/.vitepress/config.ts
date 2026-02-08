import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'PlatsIndex',
  description: 'Swedish Real Estate Prediction Platform — Internal Documentation',

  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/logo.svg' }],
  ],

  themeConfig: {
    logo: '/logo.svg',

    search: {
      provider: 'local',
    },

    nav: [
      { text: 'Architecture', link: '/architecture/' },
      { text: 'Data Sources', link: '/data-sources/' },
      { text: 'Pipeline', link: '/data-pipeline/' },
      { text: 'Indicators', link: '/indicators/' },
      { text: 'Methodology', link: '/methodology/' },
      { text: 'API', link: '/api/' },
      { text: 'Operations', link: '/operations/' },
    ],

    sidebar: {
      '/architecture/': [
        {
          text: 'Architecture',
          items: [
            { text: 'Overview', link: '/architecture/' },
            { text: 'Tech Stack', link: '/architecture/stack' },
            { text: 'Database Schema', link: '/architecture/database-schema' },
            { text: 'Spatial Framework', link: '/architecture/spatial-framework' },
            { text: 'Indicator Pattern', link: '/architecture/indicator-pattern' },
            { text: 'Scoring Engine', link: '/architecture/scoring-engine' },
          ],
        },
      ],
      '/data-sources/': [
        {
          text: 'Data Sources',
          items: [
            { text: 'Overview', link: '/data-sources/' },
            { text: 'SCB Demographics', link: '/data-sources/scb-demographics' },
            { text: 'Skolverket Schools', link: '/data-sources/skolverket-schools' },
            { text: 'BRÅ Crime', link: '/data-sources/bra-crime' },
            { text: 'Kronofogden Debt', link: '/data-sources/kronofogden-debt' },
            { text: 'GTFS Transit', link: '/data-sources/gtfs-transit' },
            { text: 'POI (OpenStreetMap)', link: '/data-sources/poi-openstreetmap' },
            { text: 'POI (Google Places)', link: '/data-sources/poi-google-places' },
          ],
        },
      ],
      '/data-pipeline/': [
        {
          text: 'Data Pipeline',
          items: [
            { text: 'Overview', link: '/data-pipeline/' },
            { text: 'Ingestion', link: '/data-pipeline/ingestion' },
            { text: 'Normalization', link: '/data-pipeline/normalization' },
            { text: 'Scoring', link: '/data-pipeline/scoring' },
            { text: 'Aggregation', link: '/data-pipeline/aggregation' },
            { text: 'Data Quality', link: '/data-pipeline/data-quality' },
          ],
        },
      ],
      '/indicators/': [
        {
          text: 'Indicators',
          items: [
            { text: 'Master Reference', link: '/indicators/' },
            { text: 'Income', link: '/indicators/income' },
            { text: 'Employment', link: '/indicators/employment' },
            { text: 'Education', link: '/indicators/education' },
            { text: 'School Quality', link: '/indicators/school-quality' },
            { text: 'Crime', link: '/indicators/crime' },
            { text: 'Financial Distress', link: '/indicators/financial-distress' },
            { text: 'Transit', link: '/indicators/transit' },
            { text: 'POI', link: '/indicators/poi' },
            { text: 'Proximity', link: '/indicators/proximity' },
          ],
        },
      ],
      '/methodology/': [
        {
          text: 'Methodology',
          items: [
            { text: 'Overview', link: '/methodology/' },
            { text: 'Scoring Model', link: '/methodology/scoring-model' },
            { text: 'Normalization', link: '/methodology/normalization' },
            { text: 'Merit Value (Meritvärde)', link: '/methodology/meritvalue' },
            { text: 'DeSO Explained', link: '/methodology/deso-explained' },
            { text: 'Disaggregation', link: '/methodology/disaggregation' },
            { text: 'Urbanity Classification', link: '/methodology/urbanity' },
            { text: 'Legal Constraints', link: '/methodology/legal-constraints' },
          ],
        },
      ],
      '/frontend/': [
        {
          text: 'Frontend',
          items: [
            { text: 'Overview', link: '/frontend/' },
            { text: 'Map Rendering', link: '/frontend/map-rendering' },
            { text: 'Sidebar', link: '/frontend/sidebar' },
            { text: 'School Markers', link: '/frontend/school-markers' },
            { text: 'POI Display', link: '/frontend/poi-display' },
            { text: 'Admin Dashboard', link: '/frontend/admin-dashboard' },
            { text: 'Components', link: '/frontend/components' },
          ],
        },
      ],
      '/api/': [
        {
          text: 'API',
          items: [
            { text: 'Overview', link: '/api/' },
            { text: 'Location Lookup', link: '/api/location-lookup' },
            { text: 'DeSO GeoJSON', link: '/api/deso-geojson' },
            { text: 'DeSO Scores', link: '/api/deso-scores' },
            { text: 'DeSO Schools', link: '/api/deso-schools' },
            { text: 'DeSO Indicators', link: '/api/deso-indicators' },
            { text: 'POIs', link: '/api/pois' },
            { text: 'H3 Endpoints', link: '/api/h3-endpoints' },
            { text: 'Heatmap Tiles', link: '/api/heatmap-tiles' },
            { text: 'Admin Endpoints', link: '/api/admin-endpoints' },
          ],
        },
      ],
      '/operations/': [
        {
          text: 'Operations',
          items: [
            { text: 'Overview', link: '/operations/' },
            { text: 'Docker Setup', link: '/operations/docker-setup' },
            { text: 'Running Locally', link: '/operations/running-locally' },
            { text: 'Artisan Commands', link: '/operations/artisan-commands' },
            { text: 'Data Refresh', link: '/operations/data-refresh' },
            { text: 'Troubleshooting', link: '/operations/troubleshooting' },
          ],
        },
      ],
      '/business/': [
        {
          text: 'Business',
          items: [
            { text: 'Overview', link: '/business/' },
            { text: 'Target Customers', link: '/business/target-customers' },
            { text: 'Competitor Landscape', link: '/business/competitor-landscape' },
            { text: 'Tiering Model', link: '/business/tiering-model' },
            { text: 'Go-to-Market', link: '/business/go-to-market' },
          ],
        },
      ],
      '/changelog/': [
        {
          text: 'Changelog',
          items: [
            { text: 'Overview', link: '/changelog/' },
          ],
        },
      ],
    },

    footer: {
      message: 'Internal documentation — not for public distribution',
    },

    editLink: {
      pattern: 'https://github.com/oxorp/lugn-och-ro/edit/docs/docs/:path',
      text: 'Edit this page',
    },
  },

  markdown: {
    lineNumbers: true,
  },
})
