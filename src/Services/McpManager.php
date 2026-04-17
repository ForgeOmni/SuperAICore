<?php

namespace SuperAICore\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class McpManager
{
    protected static ?string $projectRootOverride = null;

    /**
     * Registry of known MCP servers with metadata.
     * type: uvx | npx | python
     */
    /**
     * Category definitions for grouping servers in UI.
     */
    protected static array $categories = [
        'career'   => ['icon' => 'bi-briefcase',        'order' => 1],
        'web'      => ['icon' => 'bi-globe',             'order' => 2],
        'academic' => ['icon' => 'bi-mortarboard',       'order' => 3],
        'finance'  => ['icon' => 'bi-graph-up-arrow',    'order' => 4],
        'crypto'   => ['icon' => 'bi-currency-bitcoin',  'order' => 5],
        'seo'      => ['icon' => 'bi-search-heart',      'order' => 6],
        'logistics' => ['icon' => 'bi-truck',            'order' => 7],
        'gov'      => ['icon' => 'bi-building',          'order' => 8],
        'tools'    => ['icon' => 'bi-cpu',               'order' => 9],
        'ecommerce' => ['icon' => 'bi-bag-check',         'order' => 10],
    ];

    protected static array $registry = [
        // ── Career & Networking ──
        'linkedin' => [
            'name' => 'LinkedIn',
            'icon' => 'bi-linkedin',
            'color' => '#0a66c2',
            'category' => 'career',
            'type' => 'uvx',
            'package' => 'linkedin-scraper-mcp',
            'requires_auth' => true,
            'auth_method' => 'browser_login',
            'session_dir' => '.linkedin-mcp',
            'session_indicator' => 'cookies.json',
            'capabilities' => ['profiles', 'companies', 'jobs', 'search', 'posts'],
            'mcp_config' => [
                'command' => 'uvx',
                'args' => ['linkedin-scraper-mcp'],
                'timeout' => 30000,
            ],
        ],
        'jobspy' => [
            'name' => 'JobSpy',
            'icon' => 'bi-briefcase-fill',
            'color' => '#059669',
            'category' => 'career',
            'type' => 'python',
            'repo' => 'https://github.com/borgius/jobspy-mcp-server.git',
            'requires_auth' => false,
            'capabilities' => ['indeed', 'linkedin', 'glassdoor', 'ziprecruiter', 'google'],
            'install_dir' => 'jobspy-mcp-server',
            'entrypoint' => 'src/index.js',
            'requirements' => 'package.json',
        ],
        'himalayas' => [
            'name' => 'Himalayas Remote Jobs',
            'icon' => 'bi-globe2',
            'color' => '#6366F1',
            'category' => 'career',
            'type' => 'npx',
            'package' => 'mcp-remote',
            'requires_auth' => false,
            'capabilities' => ['remote_jobs', 'salary_data', 'company_profiles', 'application_tracking'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', 'mcp-remote', 'https://mcp.himalayas.app/sse'],
            ],
        ],
        'job-searchoor' => [
            'name' => 'Job Searchoor',
            'icon' => 'bi-search-heart-fill',
            'color' => '#F59E0B',
            'category' => 'career',
            'type' => 'npx',
            'package' => 'job-searchoor',
            'requires_auth' => false,
            'capabilities' => ['job_search', 'keyword_filter', 'remote_filter', 'time_filter'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', 'job-searchoor'],
            ],
        ],
        'web3-jobs' => [
            'name' => 'Web3 Jobs',
            'icon' => 'bi-boxes',
            'color' => '#8B5CF6',
            'category' => 'career',
            'type' => 'npx',
            'package' => '@kukapay/web3-jobs-mcp',
            'requires_auth' => false,
            'capabilities' => ['web3_jobs', 'blockchain_careers', 'remote_filter', 'tag_filter'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', '@kukapay/web3-jobs-mcp'],
            ],
        ],

        'dice' => [
            'name' => 'Dice',
            'icon' => 'bi-pc-display',
            'color' => '#E21937',
            'category' => 'career',
            'type' => 'npx',
            'package' => 'mcp-remote',
            'requires_auth' => false,
            'capabilities' => ['tech_jobs', 'visa_sponsorship', 'workplace_type', 'salary_filter', 'remote_filter'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', 'mcp-remote', 'https://mcp.dice.com/mcp'],
            ],
        ],

        // ── Web & Scraping ──
        'playwright' => [
            'name' => 'Playwright',
            'icon' => 'bi-browser-chrome',
            'color' => '#2EAD33',
            'category' => 'web',
            'type' => 'npx',
            'package' => '@playwright/mcp@latest',
            'requires_auth' => false,
            'capabilities' => ['navigate', 'screenshot', 'click', 'fill', 'evaluate'],
            'post_install' => 'npx playwright install chromium',
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['@playwright/mcp@latest'],
            ],
        ],
        'sqlite' => [
            'name' => 'SQLite',
            'icon' => 'bi-database',
            'color' => '#003B57',
            'category' => 'tools',
            'type' => 'uvx',
            'package' => 'mcp-server-sqlite',
            'requires_auth' => false,
            'capabilities' => ['sql_query', 'schema_inspect', 'data_store', 'local_db'],
            'mcp_config' => [
                'command' => 'uvx',
                'args' => ['mcp-server-sqlite'],
            ],
        ],
        'reddit' => [
            'name' => 'Reddit',
            'icon' => 'bi-reddit',
            'color' => '#FF4500',
            'category' => 'web',
            'type' => 'npx',
            'package' => 'reddit-mcp-buddy',
            'requires_auth' => false,
            'capabilities' => ['subreddit_search', 'sentiment', 'pain_points', 'trending'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', 'reddit-mcp-buddy'],
            ],
        ],
        'hackernews' => [
            'name' => 'Hacker News',
            'icon' => 'bi-newspaper',
            'color' => '#FF6600',
            'category' => 'web',
            'type' => 'npx',
            'package' => 'mcp-hacker-news',
            'requires_auth' => false,
            'capabilities' => ['top_stories', 'new_stories', 'ask_hn', 'show_hn', 'comments'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', 'mcp-hacker-news'],
            ],
        ],
        'youtube-transcript' => [
            'name' => 'YouTube Transcript',
            'icon' => 'bi-youtube',
            'color' => '#FF0000',
            'category' => 'web',
            'type' => 'npx',
            'package' => 'mcp-youtube-transcript',
            'requires_auth' => false,
            'capabilities' => ['video_transcript', 'content_extract', 'timestamp', 'multi_language'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', 'mcp-youtube-transcript'],
            ],
        ],
        'packagelens' => [
            'name' => 'PackageLens',
            'icon' => 'bi-box',
            'color' => '#2B5797',
            'category' => 'tools',
            'type' => 'npx',
            'package' => 'packagelens-mcp',
            'requires_auth' => false,
            'capabilities' => ['npm_search', 'pypi_search', 'crates_search', 'package_compare', 'multi_registry'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', 'packagelens-mcp'],
            ],
        ],
        'jina-reader' => [
            'name' => 'Jina Reader',
            'icon' => 'bi-file-earmark-text',
            'color' => '#FF6B00',
            'category' => 'web',
            'type' => 'npx',
            'package' => '@jina-ai/mcp-server',
            'requires_auth' => false,
            'capabilities' => ['url_to_markdown', 'js_rendering', 'content_extract', 'web_search'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', '@jina-ai/mcp-server'],
            ],
        ],
        'crawl4ai' => [
            'name' => 'Crawl4AI',
            'icon' => 'bi-robot',
            'color' => '#10B981',
            'category' => 'web',
            'type' => 'npx',
            'package' => 'mcp-crawl4ai',
            'requires_auth' => false,
            'capabilities' => ['site_crawl', 'markdown_extract', 'multi_page', 'structured_data'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', 'mcp-crawl4ai'],
            ],
        ],
        'fetch' => [
            'name' => 'Fetch',
            'icon' => 'bi-cloud-download',
            'color' => '#6366F1',
            'category' => 'web',
            'type' => 'uvx',
            'package' => 'mcp-server-fetch',
            'requires_auth' => false,
            'capabilities' => ['fetch_url', 'html_to_markdown', 'raw_content'],
            'mcp_config' => [
                'command' => 'uvx',
                'args' => ['mcp-server-fetch'],
            ],
        ],
        'duckduckgo' => [
            'name' => 'DuckDuckGo',
            'icon' => 'bi-search',
            'color' => '#DE5833',
            'category' => 'web',
            'type' => 'npx',
            'package' => 'duckduckgo-mcp',
            'requires_auth' => false,
            'capabilities' => ['web_search', 'news_search'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', 'duckduckgo-mcp'],
            ],
        ],

        // ── Financial Data ──
        'yahoo-finance' => [
            'name' => 'Yahoo Finance',
            'icon' => 'bi-graph-up',
            'color' => '#6001D2',
            'category' => 'finance',
            'type' => 'npx',
            'package' => '@szemeng76/yfinance-mcp-server',
            'requires_auth' => false,
            'capabilities' => ['stock_prices', 'financials', 'options_data', 'market_news', 'historical_data'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', '@szemeng76/yfinance-mcp-server'],
            ],
        ],
        'stockflow' => [
            'name' => 'Stockflow',
            'icon' => 'bi-activity',
            'color' => '#10B981',
            'category' => 'finance',
            'type' => 'npx',
            'package' => '@twolven/mcp-stockflow',
            'requires_auth' => false,
            'capabilities' => ['realtime_quotes', 'technical_analysis', 'options_analysis', 'market_data'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', '@twolven/mcp-stockflow'],
            ],
        ],
        'stockscreen' => [
            'name' => 'Stockscreen',
            'icon' => 'bi-funnel-fill',
            'color' => '#F97316',
            'category' => 'finance',
            'type' => 'npx',
            'package' => '@twolven/mcp-stockscreen',
            'requires_auth' => false,
            'capabilities' => ['stock_screening', 'fundamental_screening', 'technical_screening', 'options_strategies'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', '@twolven/mcp-stockscreen'],
            ],
        ],
        'finance-agent' => [
            'name' => 'Finance Agent',
            'icon' => 'bi-globe-europe-africa',
            'color' => '#0EA5E9',
            'category' => 'finance',
            'type' => 'npx',
            'package' => 'finance-agent-mcp-server',
            'requires_auth' => false,
            'capabilities' => ['global_markets', 'us_stocks', 'hk_stocks', 'cn_stocks', 'eu_stocks'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', 'finance-agent-mcp-server'],
            ],
        ],
        'maverick' => [
            'name' => 'MaverickMCP',
            'icon' => 'bi-rocket-takeoff',
            'color' => '#DC2626',
            'category' => 'finance',
            'type' => 'uvx',
            'package' => 'maverick-mcp',
            'requires_auth' => false,
            'capabilities' => ['stock_analysis', 'technical_indicators', 'portfolio_optimization', 'risk_metrics'],
            'mcp_config' => [
                'command' => 'uvx',
                'args' => ['maverick-mcp'],
            ],
        ],

        // ── Crypto & DeFi ──
        'coingecko' => [
            'name' => 'CoinGecko',
            'icon' => 'bi-currency-bitcoin',
            'color' => '#8DC63F',
            'category' => 'crypto',
            'type' => 'npx',
            'package' => '@coingecko/coingecko-mcp',
            'requires_auth' => false,
            'capabilities' => ['crypto_prices', 'market_cap', 'volume', 'historical', 'trending'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', '@coingecko/coingecko-mcp'],
            ],
        ],
        'dexpaprika' => [
            'name' => 'DexPaprika',
            'icon' => 'bi-diagram-3-fill',
            'color' => '#00D4AA',
            'category' => 'crypto',
            'type' => 'npx',
            'package' => 'dexpaprika-mcp',
            'requires_auth' => false,
            'capabilities' => ['dex_pools', 'token_data', 'swap_volume', 'liquidity', 'chains'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', 'dexpaprika-mcp'],
            ],
        ],

        'bitcoin-mcp' => [
            'name' => 'Bitcoin MCP',
            'icon' => 'bi-currency-bitcoin',
            'color' => '#F7931A',
            'category' => 'crypto',
            'type' => 'npx',
            'package' => 'bitcoin-mcp',
            'requires_auth' => false,
            'capabilities' => ['btc_keys', 'btc_address', 'btc_tx', 'btc_blockchain', 'lightning'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', 'bitcoin-mcp@latest'],
            ],
        ],
        'evm-mcp' => [
            'name' => 'EVM MCP',
            'icon' => 'bi-diagram-3',
            'color' => '#627EEA',
            'category' => 'crypto',
            'type' => 'npx',
            'package' => '@mcpdotdirect/evm-mcp-server',
            'requires_auth' => false,
            'capabilities' => ['evm_60chains', 'abi_fetch', 'ens_resolve', 'contract_read', 'tx_query'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', '@mcpdotdirect/evm-mcp-server'],
            ],
        ],
        'solana-mcp' => [
            'name' => 'Solana Agent Kit',
            'icon' => 'bi-lightning-charge',
            'color' => '#9945FF',
            'category' => 'crypto',
            'type' => 'npx',
            'package' => 'solana-mcp',
            'requires_auth' => false,
            'capabilities' => ['sol_tokens', 'sol_transactions', 'sol_staking', 'sol_accounts', 'sol_protocols'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', 'solana-mcp'],
            ],
        ],
        'goat' => [
            'name' => 'GOAT',
            'icon' => 'bi-link-45deg',
            'color' => '#1DB954',
            'category' => 'crypto',
            'type' => 'npx',
            'package' => '@goat-sdk/mcp',
            'requires_auth' => false,
            'capabilities' => ['multichain_200', 'onchain_read', 'smart_contracts', 'defi_actions'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', '@goat-sdk/mcp'],
            ],
        ],
        'crypto-news' => [
            'name' => 'Crypto News',
            'icon' => 'bi-newspaper',
            'color' => '#FF6B35',
            'category' => 'crypto',
            'type' => 'npx',
            'package' => 'free-crypto-news',
            'requires_auth' => false,
            'capabilities' => ['crypto_news_feed', 'news_archive', 'market_context', 'multi_chain_news'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', 'free-crypto-news'],
            ],
        ],

        // ── SEO & Web Intelligence ──
        'lighthouse' => [
            'name' => 'Lighthouse',
            'icon' => 'bi-speedometer2',
            'color' => '#F44B21',
            'category' => 'seo',
            'type' => 'npx',
            'package' => '@danielsogl/lighthouse-mcp',
            'requires_auth' => false,
            'capabilities' => ['core_web_vitals', 'performance', 'seo_score', 'accessibility', 'security_audit'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', '@danielsogl/lighthouse-mcp@latest'],
            ],
        ],
        'serp' => [
            'name' => 'SERP',
            'icon' => 'bi-google',
            'color' => '#4285F4',
            'category' => 'seo',
            'type' => 'uvx',
            'package' => 'serp-mcp',
            'requires_auth' => false,
            'capabilities' => ['google_results', 'people_also_ask', 'related_searches', 'knowledge_graph'],
            'mcp_config' => [
                'command' => 'uvx',
                'args' => ['serp-mcp'],
            ],
        ],
        'sitemap' => [
            'name' => 'Sitemap',
            'icon' => 'bi-diagram-2',
            'color' => '#17A2B8',
            'category' => 'seo',
            'type' => 'uvx',
            'package' => 'sitemap-mcp-server',
            'requires_auth' => false,
            'capabilities' => ['sitemap_tree', 'page_list', 'sitemap_stats', 'content_parse'],
            'mcp_config' => [
                'command' => 'uvx',
                'args' => ['sitemap-mcp-server'],
            ],
        ],
        'whois' => [
            'name' => 'Whois',
            'icon' => 'bi-shield-check',
            'color' => '#6C757D',
            'category' => 'seo',
            'type' => 'npx',
            'package' => '@bharathvaj/whois-mcp',
            'requires_auth' => false,
            'capabilities' => ['domain_whois', 'tld_info', 'ip_whois', 'asn_lookup'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', '@bharathvaj/whois-mcp@latest'],
            ],
        ],

        // ── Logistics & Geospatial ──
        'osm-mcp' => [
            'name' => 'OSM MCP',
            'icon' => 'bi-map',
            'color' => '#7EBC6F',
            'category' => 'logistics',
            'type' => 'binary',
            'requires_auth' => false,
            'capabilities' => ['geocoding', 'routing', 'nearby_places', 'distance', 'emissions', 'map_image'],
            'install_dir' => 'osmmcp',
            'binary_name' => 'osmmcp',
            'release_url' => 'https://github.com/NERVsystems/osmmcp/releases/download/v0.1.1',
            'release_files' => [
                'darwin-arm64'  => 'osmmcp-darwin-arm64-v0.1.1.tar.gz',
                'darwin-amd64'  => 'osmmcp-darwin-amd64-v0.1.1.tar.gz',
                'linux-amd64'   => 'osmmcp-linux-amd64-v0.1.1.tar.gz',
                'linux-arm64'   => 'osmmcp-linux-arm64-v0.1.1.tar.gz',
                'windows-amd64' => 'osmmcp-windows-amd64-v0.1.1.zip',
            ],
        ],
        'geocoding' => [
            'name' => 'Geocoding',
            'icon' => 'bi-geo-alt-fill',
            'color' => '#E74C3C',
            'category' => 'logistics',
            'type' => 'npx',
            'package' => '@geocoding-ai/mcp',
            'requires_auth' => false,
            'capabilities' => ['geocode', 'reverse_geocode'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', '@geocoding-ai/mcp'],
            ],
        ],
        'world-bank' => [
            'name' => 'World Bank Data',
            'icon' => 'bi-globe-americas',
            'color' => '#009FDA',
            'category' => 'gov',
            'type' => 'python',
            'repo' => 'https://github.com/llnormll/world-bank-data-mcp.git',
            'requires_auth' => false,
            'capabilities' => ['country_data', 'economic_indicators', 'trade_data'],
            'install_dir' => 'world-bank-data-mcp',
            'entrypoint' => 'src/world_bank_mcp/server.py',
            'requirements' => 'pyproject.toml',
        ],
        'currency' => [
            'name' => 'Currency Conversion',
            'icon' => 'bi-currency-exchange',
            'color' => '#27AE60',
            'category' => 'gov',
            'type' => 'npx',
            'package' => 'mcp-remote',
            'requires_auth' => false,
            'capabilities' => ['convert', 'latest_rates', 'historical_rates', 'currencies'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', 'mcp-remote', 'https://currency-mcp.wesbos.com/sse'],
            ],
        ],
        'weather' => [
            'name' => 'Weather',
            'icon' => 'bi-cloud-sun',
            'color' => '#3498DB',
            'category' => 'gov',
            'type' => 'uvx',
            'package' => 'mcp-weather-free',
            'requires_auth' => false,
            'capabilities' => ['current_weather', 'forecast', 'city_search'],
            'mcp_config' => [
                'command' => 'uvx',
                'args' => ['--from', 'git+https://github.com/microagents/mcp-servers.git#subdirectory=mcp-weather-free', 'mcp-weather-free'],
            ],
        ],
        'canada-weather' => [
            'name' => 'Canada Weather',
            'icon' => 'bi-snow2',
            'color' => '#E8112D',
            'category' => 'gov',
            'type' => 'python',
            'repo' => 'https://github.com/SeanLF/weather-mcp.git',
            'requires_auth' => false,
            'capabilities' => ['ca_forecast', 'ca_5day', 'ca_by_location', 'ca_alerts'],
            'install_dir' => 'weather-mcp',
            'entrypoint' => 'weather.py',
            'requirements' => 'pyproject.toml',
        ],
        '17track' => [
            'name' => '17TRACK',
            'icon' => 'bi-box-seam',
            'color' => '#FF6600',
            'category' => 'logistics',
            'type' => 'npx',
            'package' => '@iamfiro/17track-mcp',
            'requires_auth' => false,
            'capabilities' => ['parcel_tracking', 'multi_carrier', 'global_coverage', 'delivery_status'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', '@iamfiro/17track-mcp'],
            ],
        ],
        'oil-price' => [
            'name' => 'Oil Price API',
            'icon' => 'bi-fuel-pump',
            'color' => '#1A1A2E',
            'category' => 'logistics',
            'type' => 'npx',
            'package' => '@oilpriceapi/mcp-server',
            'requires_auth' => false,
            'capabilities' => ['oil_price', 'diesel_price', 'gasoline_price', 'jet_fuel', 'price_history'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', '@oilpriceapi/mcp-server'],
            ],
        ],
        'drivebc' => [
            'name' => 'DriveBC',
            'icon' => 'bi-cone-striped',
            'color' => '#003366',
            'category' => 'logistics',
            'type' => 'npx',
            'package' => '@infil00p/drivebc-mcp',
            'requires_auth' => false,
            'capabilities' => ['road_conditions', 'closures', 'traffic_incidents', 'weather_alerts'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', '@infil00p/drivebc-mcp'],
            ],
        ],
        'open-meteo' => [
            'name' => 'Open-Meteo Weather',
            'icon' => 'bi-cloud-sun',
            'color' => '#FF6B00',
            'category' => 'logistics',
            'type' => 'npx',
            'package' => 'open-meteo-mcp-server',
            'requires_auth' => false,
            'capabilities' => ['weather_forecast', 'historical_weather', 'air_quality', 'marine_weather', 'flood_forecast', 'elevation'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', 'open-meteo-mcp-server'],
            ],
        ],
        'weather-mcp' => [
            'name' => 'Weather MCP (12 Tools)',
            'icon' => 'bi-tornado',
            'color' => '#1E40AF',
            'category' => 'logistics',
            'type' => 'npx',
            'package' => '@dangahagan/weather-mcp',
            'requires_auth' => false,
            'capabilities' => ['weather_forecast', 'weather_alerts', 'air_quality', 'lightning', 'wildfire', 'river_monitor', 'radar_imagery'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', '@dangahagan/weather-mcp@latest'],
            ],
        ],
        'realvest' => [
            'name' => 'Realvest',
            'icon' => 'bi-house-door',
            'color' => '#059669',
            'category' => 'logistics',
            'type' => 'npx',
            'package' => '@realvest/mcp-server',
            'requires_auth' => false,
            'capabilities' => ['mortgage_calc', 'investment_calc', 'brrrr', 'affordability', 'portfolio'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', '@realvest/mcp-server'],
            ],
        ],
        'timezone' => [
            'name' => 'Timezone Toolkit',
            'icon' => 'bi-clock-history',
            'color' => '#8B5CF6',
            'category' => 'logistics',
            'type' => 'npx',
            'package' => '@cicatriz/timezone-toolkit',
            'requires_auth' => false,
            'capabilities' => ['tz_convert', 'tz_current', 'tz_list', 'tz_diff'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', '@cicatriz/timezone-toolkit'],
            ],
        ],
        'gtfs' => [
            'name' => 'GTFS Transit',
            'icon' => 'bi-bus-front',
            'color' => '#0D9488',
            'category' => 'logistics',
            'type' => 'python',
            'repo' => 'https://github.com/jdamcd/gtfs-mcp.git',
            'requires_auth' => false,
            'capabilities' => ['transit_schedules', 'transit_realtime', 'transit_routes', 'transit_stops'],
            'install_dir' => 'gtfs-mcp',
            'entrypoint' => 'dist/index.js',
            'requirements' => 'package.json',
        ],
        'gis-mcp' => [
            'name' => 'GIS Server',
            'icon' => 'bi-geo',
            'color' => '#B45309',
            'category' => 'logistics',
            'type' => 'python',
            'repo' => 'https://github.com/mahdin75/gis-mcp.git',
            'requires_auth' => false,
            'capabilities' => ['elevation', 'terrain', 'spatial_analysis', 'coord_convert', 'h3_index'],
            'install_dir' => 'gis-mcp',
            'entrypoint' => 'server.py',
            'requirements' => 'requirements.txt',
        ],
        'valhalla' => [
            'name' => 'Valhalla Routing',
            'icon' => 'bi-signpost-split',
            'color' => '#DC2626',
            'category' => 'logistics',
            'type' => 'python',
            'repo' => 'https://github.com/aatakansalar/valhalla-mcp.git',
            'requires_auth' => false,
            'capabilities' => ['multimodal_routing', 'isochrone', 'matrix', 'map_matching'],
            'install_dir' => 'valhalla-mcp',
            'entrypoint' => 'dist/index.js',
            'requirements' => 'package.json',
            'config_fields' => [
                'valhalla_url' => [
                    'label' => 'valhalla_url_label',
                    'placeholder' => 'http://localhost:8002',
                    'required' => true,
                    'env_key' => 'VALHALLA_URL', // kept for backward compat; DB takes priority
                ],
            ],
        ],
        'opensky' => [
            'name' => 'OpenSky Flight Tracker',
            'icon' => 'bi-airplane',
            'color' => '#1A1A2E',
            'category' => 'logistics',
            'type' => 'uvx',
            'package' => 'flight-tracker-mcp',
            'requires_auth' => false,
            'capabilities' => ['flight_tracking', 'airport_arrivals', 'aircraft_states', 'aircraft_track'],
            'mcp_config' => [
                'command' => 'uvx',
                'args' => ['flight-tracker-mcp'],
            ],
        ],

        // ── Government & Public Data ──
        'us-gov-data' => [
            'name' => 'US Gov Open Data',
            'icon' => 'bi-building',
            'color' => '#112E51',
            'category' => 'gov',
            'type' => 'npx',
            'package' => 'us-gov-open-data-mcp',
            'requires_auth' => false,
            'capabilities' => ['fred', 'treasury', 'congress', 'bls', 'census', 'sec', 'usda', 'noaa'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => [
                    '-y', 'us-gov-open-data-mcp',
                    '--modules', 'treasury,congress,congress-members,federal-register,fec,recalls,usa-spending,patent,trademark,cdc,fda-drugs,fda-food,open-fda,nasa,noaa,nws,usgs-earthquake,usgs-water',
                ],
            ],
        ],
        'eurostat' => [
            'name' => 'Eurostat',
            'icon' => 'bi-flag',
            'color' => '#003399',
            'category' => 'gov',
            'type' => 'python',
            'repo' => 'https://github.com/ano-kuhanathan/eurostat-mcp.git',
            'requires_auth' => false,
            'capabilities' => ['eu_gdp', 'eu_unemployment', 'eu_inflation', 'eu_trade', 'eu_population'],
            'install_dir' => 'eurostat-mcp',
            'entrypoint' => 'server.py',
            'requirements' => 'pyproject.toml',
        ],
        'oecd' => [
            'name' => 'OECD',
            'icon' => 'bi-globe-central-south-asia',
            'color' => '#0074B8',
            'category' => 'gov',
            'type' => 'python',
            'repo' => 'https://github.com/isakskogstad/OECD-MCP.git',
            'requires_auth' => false,
            'capabilities' => ['oecd_economy', 'oecd_health', 'oecd_education', 'oecd_environment', 'oecd_38countries'],
            'install_dir' => 'oecd-mcp',
            'entrypoint' => 'dist/index.js',
            'requirements' => 'package.json',
        ],
        'companies-house' => [
            'name' => 'Companies House',
            'icon' => 'bi-building-check',
            'color' => '#00703C',
            'category' => 'gov',
            'type' => 'npx',
            'package' => 'companies-house-mcp-server',
            'requires_auth' => false,
            'capabilities' => ['uk_companies', 'uk_filings', 'uk_officers', 'uk_charges', 'uk_search'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', 'companies-house-mcp-server'],
            ],
        ],
        'us-census' => [
            'name' => 'US Census',
            'icon' => 'bi-people-fill',
            'color' => '#112E51',
            'category' => 'gov',
            'type' => 'python',
            'repo' => 'https://github.com/uscensusbureau/us-census-bureau-data-api-mcp.git',
            'requires_auth' => false,
            'capabilities' => ['us_demographics', 'us_population', 'us_income', 'us_housing'],
            'install_dir' => 'us-census-mcp',
            'entrypoint' => 'mcp-server/dist/index.js',
            'requirements' => 'mcp-server/package.json',
        ],
        'datagov' => [
            'name' => 'Data.gov',
            'icon' => 'bi-database',
            'color' => '#205493',
            'category' => 'gov',
            'type' => 'npx',
            'package' => 'datagov-mcp-server',
            'requires_auth' => false,
            'capabilities' => ['us_census_api', 'us_sec_api', 'us_fda_api', 'us_bls_api', 'us_epa_api'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', 'datagov-mcp-server'],
            ],
        ],
        'datagouv' => [
            'name' => 'Data.gouv.fr',
            'icon' => 'bi-flag-fill',
            'color' => '#000091',
            'category' => 'gov',
            'type' => 'python',
            'repo' => 'https://github.com/datagouv/datagouv-mcp.git',
            'requires_auth' => false,
            'capabilities' => ['fr_datasets', 'fr_organizations', 'fr_search', 'fr_metadata'],
            'install_dir' => 'datagouv-mcp',
            'entrypoint' => 'main.py',
            'requirements' => 'pyproject.toml',
        ],
        'statcan' => [
            'name' => 'Statistics Canada',
            'icon' => 'bi-maple-leaf',
            'color' => '#E8112D',
            'category' => 'gov',
            'type' => 'uvx',
            'package' => 'statcan-mcp-server',
            'requires_auth' => false,
            'capabilities' => ['ca_gdp', 'ca_unemployment', 'ca_demographics', 'ca_trade', 'ca_datasets'],
            'mcp_config' => [
                'command' => 'uvx',
                'args' => ['statcan-mcp-server'],
            ],
        ],
        'gov-ca' => [
            'name' => 'Gov CA Open Data',
            'icon' => 'bi-maple-leaf',
            'color' => '#AF3C43',
            'category' => 'gov',
            'type' => 'python',
            'repo' => 'https://github.com/krunal16-c/gov-ca-mcp.git',
            'requires_auth' => false,
            'capabilities' => ['ca_250k_datasets', 'ca_transportation', 'ca_federal', 'ca_provincial'],
            'install_dir' => 'gov-ca-mcp',
            'entrypoint' => 'gov_mcp/server.py',
            'requirements' => 'requirements.txt',
        ],

        // ── Computational Tools ──
        'mcp-optimizer' => [
            'name' => 'Optimizer (OR-Tools)',
            'icon' => 'bi-diagram-3-fill',
            'color' => '#4338CA',
            'category' => 'tools',
            'type' => 'uvx',
            'package' => 'mcp-optimizer',
            'requires_auth' => false,
            'capabilities' => ['vrp', 'tsp', 'scheduling', 'knapsack', 'assignment'],
            'mcp_config' => [
                'command' => 'uvx',
                'args' => ['mcp-optimizer'],
            ],
        ],
        'highs-mcp' => [
            'name' => 'HiGHS Solver',
            'icon' => 'bi-calculator',
            'color' => '#0369A1',
            'category' => 'tools',
            'type' => 'uvx',
            'package' => 'highs-mcp',
            'requires_auth' => false,
            'capabilities' => ['linear_prog', 'mixed_integer', 'simplex', 'interior_point'],
            'mcp_config' => [
                'command' => 'uvx',
                'args' => ['highs-mcp'],
            ],
        ],
        'mcp-solver' => [
            'name' => 'Constraint Solver',
            'icon' => 'bi-puzzle',
            'color' => '#7C3AED',
            'category' => 'tools',
            'type' => 'uvx',
            'package' => 'mcp-solver',
            'requires_auth' => false,
            'capabilities' => ['sat_smt', 'constraint_prog', 'minizinc', 'z3'],
            'mcp_config' => [
                'command' => 'uvx',
                'args' => ['mcp-solver'],
            ],
        ],
        'fast-whisper' => [
            'name' => 'Fast Whisper STT',
            'icon' => 'bi-mic',
            'color' => '#DC2626',
            'category' => 'tools',
            'type' => 'uvx',
            'package' => 'fast-whisper-mcp-server',
            'requires_auth' => false,
            'capabilities' => ['speech_to_text', 'transcription', 'offline_stt', 'multi_language'],
            'mcp_config' => [
                'command' => 'uvx',
                'args' => ['fast-whisper-mcp-server'],
            ],
        ],
        'run-python' => [
            'name' => 'Python Sandbox',
            'icon' => 'bi-terminal',
            'color' => '#0D9488',
            'category' => 'tools',
            'type' => 'npx',
            'package' => '@pydantic/mcp-run-python',
            'requires_auth' => false,
            'capabilities' => ['python_exec', 'scipy', 'pandas', 'sklearn', 'any_algorithm'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', '@pydantic/mcp-run-python'],
            ],
        ],
        'idea-reality' => [
            'name' => 'Idea Reality Check',
            'icon' => 'bi-lightbulb',
            'color' => '#F59E0B',
            'category' => 'tools',
            'type' => 'uvx',
            'package' => 'idea-reality-mcp',
            'requires_auth' => false,
            'capabilities' => ['competitor_scan', 'market_signal', 'github_scan', 'hn_scan', 'npm_scan', 'producthunt_scan'],
            'mcp_config' => [
                'command' => 'uvx',
                'args' => ['idea-reality-mcp'],
            ],
        ],
        'sec-edgar' => [
            'name' => 'SEC EDGAR',
            'icon' => 'bi-building',
            'color' => '#002868',
            'category' => 'finance',
            'type' => 'uvx',
            'package' => 'sec-edgar-mcp',
            'requires_auth' => false,
            'capabilities' => ['sec_filings', 'financial_statements', 'insider_trading', '13f_holdings', 'company_search'],
            'mcp_config' => [
                'command' => 'uvx',
                'args' => ['sec-edgar-mcp'],
            ],
        ],
        'wikidata' => [
            'name' => 'Wikidata Knowledge Graph',
            'icon' => 'bi-diagram-2',
            'color' => '#006699',
            'category' => 'tools',
            'type' => 'uvx',
            'package' => 'mcp-wikidata',
            'requires_auth' => false,
            'capabilities' => ['entity_search', 'property_query', 'sparql', 'knowledge_graph'],
            'mcp_config' => [
                'command' => 'uvx',
                'args' => ['mcp-wikidata'],
            ],
        ],
        'markitdown' => [
            'name' => 'MarkItDown',
            'icon' => 'bi-file-earmark-richtext',
            'color' => '#E65100',
            'category' => 'tools',
            'type' => 'npx',
            'package' => 'markitdown-mcp-npx',
            'requires_auth' => false,
            'capabilities' => ['pdf_to_md', 'docx_to_md', 'xlsx_to_md', 'pptx_to_md', 'html_to_md'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', 'markitdown-mcp-npx'],
            ],
        ],
        'ocr' => [
            'name' => 'OCR (Tesseract)',
            'icon' => 'bi-file-earmark-image',
            'color' => '#0F766E',
            'category' => 'tools',
            'type' => 'python-package',
            'package' => 'mcp-ocr',
            'python_module' => 'mcp_ocr',
            'requires_auth' => false,
            'capabilities' => ['pdf_ocr', 'pdf_text_extract'],
        ],
        'pdf-extract' => [
            'name' => 'SuperOCR',
            'icon' => 'bi-file-earmark-text-fill',
            'color' => '#DC2626',
            'category' => 'tools',
            'type' => 'artisan',
            'artisan_command' => 'superocr:serve',
            'requires_auth' => false,
            'capabilities' => ['pdf_ocr', 'pdf_text_extract', 'batch_extract', 'task_attachments', 'word_extract', 'excel_extract', 'ppt_extract', 'image_ocr'],
            'mcp_config' => [
                'command' => PHP_BINARY,
                'args' => ['__ARTISAN__', 'superocr:serve', '--transport=stdio', '-q'],
                'timeout' => 300000,
            ],
        ],
        'uspto' => [
            'name' => 'USPTO Patent & Trademark',
            'icon' => 'bi-file-earmark-text',
            'color' => '#1E3A5F',
            'category' => 'tools',
            'type' => 'uvx',
            'package' => 'patent-mcp-server',
            'requires_auth' => false,
            'capabilities' => ['patent_search', 'trademark_search', 'ptab_decisions', 'patent_litigation', 'office_actions'],
            'mcp_config' => [
                'command' => 'uvx',
                'args' => ['patent-mcp-server'],
            ],
        ],
        // ── Academic & Scholarly Research ──
        'paper-search' => [
            'name' => 'Paper Search (14 Sources)',
            'icon' => 'bi-journal-text',
            'color' => '#7C3AED',
            'category' => 'academic',
            'type' => 'npx',
            'package' => 'paper-search-mcp-nodejs',
            'requires_auth' => false,
            'capabilities' => ['arxiv', 'pubmed', 'biorxiv', 'google_scholar', 'semantic_scholar', 'crossref'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', 'paper-search-mcp-nodejs'],
            ],
        ],
        'papermcp' => [
            'name' => 'PaperMCP (32 Tools)',
            'icon' => 'bi-file-earmark-text',
            'color' => '#2563EB',
            'category' => 'academic',
            'type' => 'uvx',
            'package' => 'papermcp',
            'requires_auth' => false,
            'capabilities' => ['arxiv', 'huggingface_papers', 'google_scholar', 'openreview', 'dblp', 'paperswithcode'],
            'mcp_config' => [
                'command' => 'uvx',
                'args' => ['papermcp'],
            ],
        ],
        'openalex' => [
            'name' => 'OpenAlex (240M+ Works)',
            'icon' => 'bi-book',
            'color' => '#059669',
            'category' => 'academic',
            'type' => 'npx',
            'package' => 'openalex-research-mcp',
            'requires_auth' => false,
            'capabilities' => ['scholarly_search', 'citation_analysis', 'research_trends', 'author_discovery'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', 'openalex-research-mcp'],
            ],
        ],
        'pubmed' => [
            'name' => 'PubMed (35M+ Citations)',
            'icon' => 'bi-heart-pulse',
            'color' => '#0891B2',
            'category' => 'academic',
            'type' => 'npx',
            'package' => '@cyanheads/pubmed-mcp-server',
            'requires_auth' => false,
            'capabilities' => ['pubmed_search', 'biomedical', 'clinical_research', 'ncbi_eutils'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', '@cyanheads/pubmed-mcp-server'],
            ],
        ],
        'arxiv' => [
            'name' => 'arXiv (Full-text)',
            'icon' => 'bi-file-earmark-pdf',
            'color' => '#B91C1C',
            'category' => 'academic',
            'type' => 'uvx',
            'package' => 'arxiv-mcp-server',
            'requires_auth' => false,
            'capabilities' => ['arxiv_search', 'pdf_fulltext', 'category_filter', 'date_filter'],
            'mcp_config' => [
                'command' => 'uvx',
                'args' => ['arxiv-mcp-server'],
            ],
        ],
        'scihub' => [
            'name' => 'Sci-Hub',
            'icon' => 'bi-unlock',
            'color' => '#374151',
            'category' => 'academic',
            'type' => 'npx',
            'package' => 'sci-mcp-server',
            'requires_auth' => false,
            'capabilities' => ['pdf_download', 'doi_lookup', 'mirror_select'],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', 'sci-mcp-server'],
            ],
        ],
        'unpaywall' => [
            'name' => 'Unpaywall',
            'icon' => 'bi-unlock-fill',
            'color' => '#16A34A',
            'category' => 'academic',
            'type' => 'python',
            'repo' => 'https://github.com/ElliotPadfield/unpaywall-mcp.git',
            'requires_auth' => false,
            'capabilities' => ['doi_metadata', 'oa_links', 'title_search', 'pdf_extract'],
            'install_dir' => 'unpaywall-mcp',
            'entrypoint' => 'dist/index.js',
            'requirements' => 'package.json',
        ],
        'author-profile' => [
            'name' => 'Author Profile Network',
            'icon' => 'bi-people',
            'color' => '#7C3AED',
            'category' => 'academic',
            'type' => 'python',
            'repo' => 'https://github.com/alperenkocyigit/authorprofilemcp.git',
            'requires_auth' => false,
            'capabilities' => ['author_network', 'cross_platform', 'collaboration_map'],
            'install_dir' => 'author-profile-mcp',
            'entrypoint' => 'server.py',
            'requirements' => 'requirements.txt',
        ],
        'google-patents' => [
            'name' => 'Google Patents (90M+)',
            'icon' => 'bi-file-earmark-text',
            'color' => '#4285F4',
            'category' => 'academic',
            'type' => 'python',
            'repo' => 'https://github.com/openpharma-org/patents-mcp.git',
            'requires_auth' => false,
            'capabilities' => ['google_patents', 'uspto', 'patent_search_17countries'],
            'install_dir' => 'patents-mcp',
            'entrypoint_script' => 'patent-mcp-server',
        ],
        'biotools' => [
            'name' => 'BioTools (37 Tools)',
            'icon' => 'bi-virus',
            'color' => '#059669',
            'category' => 'academic',
            'type' => 'python',
            'repo' => 'https://github.com/neuralx-dev/biotools-mcp-server.git',
            'requires_auth' => false,
            'capabilities' => ['pubmed', 'uniprot', 'genbank', 'kegg', 'pdb'],
            'install_dir' => 'biotools-mcp',
            'entrypoint' => 'build/index.js',
            'requirements' => 'package.json',
        ],
        'ncbi-datasets' => [
            'name' => 'NCBI Datasets (31 Tools)',
            'icon' => 'bi-bar-chart-steps',
            'color' => '#2563EB',
            'category' => 'academic',
            'type' => 'python',
            'repo' => 'https://github.com/Augmented-Nature/NCBI-Datasets-MCP-Server.git',
            'requires_auth' => false,
            'capabilities' => ['genomic_data', 'taxonomic', 'biological_data'],
            'install_dir' => 'ncbi-datasets-mcp',
            'entrypoint' => 'build/index.js',
            'requirements' => 'package.json',
        ],
        'paperswithcode' => [
            'name' => 'PapersWithCode',
            'icon' => 'bi-code-square',
            'color' => '#2563EB',
            'category' => 'academic',
            'type' => 'python',
            'repo' => 'https://github.com/hbg/mcp-paperswithcode.git',
            'requires_auth' => false,
            'capabilities' => ['papers', 'code_repos', 'benchmarks', 'sota'],
            'install_dir' => 'paperswithcode-mcp',
            'entrypoint' => 'src/mcp_paperswithcode/server.py',
            'requirements' => 'pyproject.toml',
        ],
        'clinicaltrials' => [
            'name' => 'ClinicalTrials.gov',
            'icon' => 'bi-clipboard2-pulse',
            'color' => '#0D9488',
            'category' => 'academic',
            'type' => 'python',
            'repo' => 'https://github.com/cyanheads/clinicaltrialsgov-mcp-server.git',
            'requires_auth' => false,
            'capabilities' => ['trial_search', 'study_details', 'study_compare', 'trend_analysis', 'patient_match'],
            'install_dir' => 'clinicaltrials-mcp',
            'entrypoint' => 'src/index.ts',
            'requirements' => 'package.json',
            'run_with_tsx' => true,
        ],

        // ── E-commerce ──
        'shopify' => [
            'name' => 'Shopify',
            'icon' => 'bi-bag-check',
            'color' => '#96BF48',
            'category' => 'ecommerce',
            'type' => 'npx',
            'package' => '@anthropic/shopify-mcp-server',
            'requires_auth' => true,
            'auth_method' => 'config',
            'capabilities' => ['products', 'orders', 'customers', 'inventory', 'fulfillment', 'discounts', 'analytics'],
            'config_fields' => [
                'store_domain' => [
                    'label' => 'shopify_store_domain_label',
                    'placeholder' => 'your-store.myshopify.com',
                    'is_secret' => false,
                    'env_key' => 'SHOPIFY_STORE_DOMAIN',
                ],
                'access_token' => [
                    'label' => 'shopify_access_token_label',
                    'placeholder' => 'shpat_xxxxxxxxxxxxxxxx',
                    'is_secret' => true,
                    'env_key' => 'SHOPIFY_ACCESS_TOKEN',
                ],
            ],
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', '@anthropic/shopify-mcp-server@latest'],
                'env' => [
                    'SHOPIFY_ACCESS_TOKEN' => '{{access_token}}',
                    'SHOPIFY_STORE_DOMAIN' => '{{store_domain}}',
                ],
            ],
        ],

    ];

    /**
     * External API services (not MCP servers) — managed via Integrations page.
     * Config values stored in integration_configs database table.
     */
    protected static array $apiServices = [
        'vroom' => [
            'name' => 'VROOM Route Optimizer',
            'icon' => 'bi-truck',
            'color' => '#1565C0',
            'category' => 'logistics',
            'type' => 'api-service',
            'capabilities' => ['vrp_solver', 'route_optimization', 'multi_vehicle', 'time_windows', 'capacity_constraints'],
            'config_fields' => [
                'url_north_america' => [
                    'label' => 'vroom_url_north_america_label',
                    'placeholder' => 'http://vroom-na.example.com:3000',
                    'is_secret' => false,
                ],
                'url_europe' => [
                    'label' => 'vroom_url_europe_label',
                    'placeholder' => 'http://vroom-eu.example.com:3000',
                    'is_secret' => false,
                ],
                'url_oceania' => [
                    'label' => 'vroom_url_oceania_label',
                    'placeholder' => 'http://vroom-oc.example.com:3000',
                    'is_secret' => false,
                ],
            ],
        ],
        'opencharge' => [
            'name' => 'OpenChargeMap',
            'icon' => 'bi-ev-station',
            'color' => '#4CAF50',
            'category' => 'logistics',
            'type' => 'api-service',
            'capabilities' => ['ev_charging_stations', 'global_coverage', 'realtime_availability', 'connector_types'],
            'config_fields' => [
                'api_key' => [
                    'label' => 'opencharge_api_key_label',
                    'placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
                    'is_secret' => false,
                ],
            ],
        ],
        'nrel' => [
            'name' => 'NREL / NRCan AFDC',
            'icon' => 'bi-lightning-charge',
            'color' => '#1B5E20',
            'category' => 'logistics',
            'type' => 'api-service',
            'capabilities' => ['ev_charging_stations', 'north_america', 'station_details', 'network_operators'],
            'config_fields' => [
                'api_key' => [
                    'label' => 'nrel_api_key_label',
                    'placeholder' => 'your_nrel_api_key (or leave empty for DEMO_KEY)',
                    'is_secret' => false,
                ],
            ],
        ],
        'superroute' => [
            'name' => 'SuperRoute',
            'icon' => 'bi-signpost-2',
            'color' => '#E65100',
            'category' => 'logistics',
            'type' => 'api-service',
            'capabilities' => ['delivery_routing', 'driver_management', 'order_tracking', 'route_optimization', 'address_geocoding', 'label_service'],
            'config_fields' => [
                'api_url' => [
                    'label' => 'superroute_api_url_label',
                    'placeholder' => 'https://api.superroute.ca',
                    'is_secret' => false,
                ],
                'api_token' => [
                    'label' => 'superroute_api_token_label',
                    'placeholder' => 'Bearer token',
                    'is_secret' => true,
                ],
            ],
        ],
    ];

    /**
     * Get category definitions.
     */
    public static function getCategories(): array
    {
        return self::$categories;
    }

    /**
     * Get the project root (where .mcp.json lives).
     */
    public static function projectRoot(): string
    {
        return self::$projectRootOverride ?: dirname(base_path()); // web/../ = project root
    }

    public static function setProjectRootOverride(?string $projectRoot): void
    {
        self::$projectRootOverride = $projectRoot;
    }

    /**
     * Get .mcp-servers directory path.
     */
    public static function serversDir(): string
    {
        return self::projectRoot() . DIRECTORY_SEPARATOR . '.mcp-servers';
    }

    /**
     * Get user home directory (cross-platform).
     */
    public static function homeDir(): string
    {
        // Windows
        if (PHP_OS_FAMILY === 'Windows') {
            return getenv('USERPROFILE') ?: getenv('HOMEDRIVE') . getenv('HOMEPATH');
        }

        // macOS / Linux
        $home = getenv('HOME');
        if ($home) {
            return $home;
        }

        // Fallback: posix
        if (function_exists('posix_getpwuid') && function_exists('posix_getuid')) {
            $info = posix_getpwuid(posix_getuid());
            if ($info && isset($info['dir'])) {
                return $info['dir'];
            }
        }

        return '/root';
    }

    /**
     * Get extra PATH directories for subprocess execution.
     * PHP web processes often lack user PATH entries that the shell has.
     */
    protected static function extraPathDirs(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return [];
        }
        $home = self::homeDir();
        return [
            $home . '/.local/bin',
            '/opt/homebrew/bin',
            '/usr/local/bin',
            $home . '/.cargo/bin',
            $home . '/.nvm/versions/node/' . (getenv('NODE_VERSION') ?: 'default') . '/bin',
        ];
    }

    /**
     * Get enriched environment variables for subprocess execution.
     * Ensures PATH includes common user binary locations.
     */
    public static function enrichedEnv(): array
    {
        $env = getenv();
        if (PHP_OS_FAMILY === 'Windows') {
            return $env;
        }
        $currentPath = $env['PATH'] ?? getenv('PATH') ?: '/usr/bin:/bin';
        $extraDirs = array_filter(self::extraPathDirs(), 'is_dir');
        $newDirs = array_diff($extraDirs, explode(':', $currentPath));
        if (!empty($newDirs)) {
            $env['PATH'] = implode(':', $newDirs) . ':' . $currentPath;
        }
        return $env;
    }

    /**
     * Create a Process with enriched PATH environment.
     */
    protected static function proc(array $command, ?string $cwd = null): Process
    {
        return new Process($command, $cwd, self::enrichedEnv());
    }

    /**
     * Get the path to a command binary (cross-platform).
     */
    public static function which(string $command): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $result = @shell_exec("where {$command} 2>NUL");
        } else {
            $result = @shell_exec("which {$command} 2>/dev/null");
        }
        $path = $result ? trim(explode("\n", $result)[0]) : null;

        if ($path && file_exists($path)) {
            return $path;
        }

        // PHP web processes often lack user PATH entries — check common locations
        if (PHP_OS_FAMILY !== 'Windows') {
            foreach (self::extraPathDirs() as $dir) {
                $candidate = $dir . '/' . $command;
                if (file_exists($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Resolved full path to uvx binary (cached after ensureUvInstalled).
     */
    protected static ?string $uvxPath = null;

    /**
     * Ensure uv/uvx is installed. If missing, try to install via pip.
     * Caches the resolved uvx path for subsequent installUvx calls.
     */
    public static function ensureUvInstalled(): bool
    {
        // Check if already in PATH
        $uvx = self::which('uvx');
        if ($uvx) {
            self::$uvxPath = $uvx;
            return true;
        }

        // Try installing uv via pip (works on all platforms)
        $python = self::which('python3') ?: self::which('python');
        if (!$python) {
            return false;
        }

        $proc = self::proc([$python, '-m', 'pip', 'install', 'uv']);
        $proc->setTimeout(120);
        $proc->run();

        if ($proc->isSuccessful()) {
            // Find uvx in Python's scripts directory (may not be in PATH)
            $uvx = self::findUvxPath($python);
            if ($uvx) {
                self::$uvxPath = $uvx;
                Log::info("uv/uvx installed via pip at: {$uvx}");
                return true;
            }
        }

        return false;
    }

    /**
     * Find the full path to the uvx binary.
     */
    protected static function findUvxPath(?string $python = null): ?string
    {
        // 1. Check PATH first
        $uvx = self::which('uvx');
        if ($uvx) return $uvx;

        $suppress = PHP_OS_FAMILY === 'Windows' ? '2>NUL' : '2>/dev/null';

        // 2. Ask Python for its scripts directory
        $python = $python ?: self::which('python3') ?: self::which('python');
        if ($python) {
            $pyArg = escapeshellarg($python);
            $result = @shell_exec("{$pyArg} -c \"import sysconfig; print(sysconfig.get_path('scripts'))\" {$suppress}");
            if ($result) {
                $scriptsDir = trim($result);
                $binary = $scriptsDir . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'uvx.exe' : 'uvx');
                if (file_exists($binary)) return $binary;
            }

            // 3. Check user scripts directory (pip install --user)
            $result = @shell_exec("{$pyArg} -m site --user-base {$suppress}");
            if ($result) {
                $userBase = trim($result);
                $binary = $userBase . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'Scripts' : 'bin') . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'uvx.exe' : 'uvx');
                if (file_exists($binary)) return $binary;
            }
        }

        return null;
    }

    /**
     * Get the resolved uvx path (full path or just 'uvx' if in PATH).
     */
    public static function getUvxPath(): ?string
    {
        if (self::$uvxPath) return self::$uvxPath;

        self::$uvxPath = self::findUvxPath();
        return self::$uvxPath;
    }

    /**
     * Find the full path to the uv binary.
     */
    public static function findUvPath(): ?string
    {
        $uv = self::which('uv');
        if ($uv) return $uv;

        // Derive from uvx path
        $uvx = self::getUvxPath();
        if ($uvx) {
            $uv = dirname($uvx) . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'uv.exe' : 'uv');
            if (file_exists($uv)) return $uv;
        }

        // Ask Python
        $suppress = PHP_OS_FAMILY === 'Windows' ? '2>NUL' : '2>/dev/null';
        $python = self::which('python3') ?: self::which('python');
        if ($python) {
            $pyArg = escapeshellarg($python);
            $result = @shell_exec("{$pyArg} -c \"import sysconfig; print(sysconfig.get_path('scripts'))\" {$suppress}");
            if ($result) {
                $binary = trim($result) . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'uv.exe' : 'uv');
                if (file_exists($binary)) return $binary;
            }
        }

        return null;
    }

    /**
     * Get Python binary path for a venv (cross-platform).
     */
    public static function venvPython(string $venvDir): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return $venvDir . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
        }

        return $venvDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'python';
    }

    /**
     * Read .mcp.json config.
     */
    public static function readConfig(): array
    {
        $path = self::projectRoot() . DIRECTORY_SEPARATOR . '.mcp.json';

        if (!file_exists($path)) {
            return ['mcpServers' => []];
        }

        $content = file_get_contents($path);
        $data = json_decode($content, true);

        return $data ?: ['mcpServers' => []];
    }

    public static function codexMcpServers(?array $config = null, bool $includeSuperfeed = true): array
    {
        $config ??= self::readConfig();
        $servers = $config['mcpServers'] ?? [];

        if ($includeSuperfeed && !isset($servers['superfeed'])) {
            $servers['superfeed'] = self::superfeedMcpConfig();
        }

        if (!isset($servers['ocr'])) {
            $ocrServer = self::codexOcrMcpConfig();
            if ($ocrServer !== null) {
                $servers['ocr'] = $ocrServer;
            }
        }

        if (!isset($servers['pdf-extract'])) {
            $pdfExtractServer = self::codexPdfExtractMcpConfig();
            if ($pdfExtractServer !== null) {
                $servers['pdf-extract'] = $pdfExtractServer;
            }
        }

        $resolved = [];
        foreach ($servers as $name => $server) {
            if (!is_string($name) || !is_array($server)) {
                continue;
            }

            $normalized = self::normalizeCodexMcpServer($server);
            if ($normalized === null) {
                continue;
            }

            $resolved[$name] = $normalized;
        }

        return $resolved;
    }

    protected static function codexOcrMcpConfig(): ?array
    {
        $python = self::which('python3') ?: self::which('python');
        if (!$python || !self::pythonModuleExists($python, 'mcp_ocr')) {
            return null;
        }

        return [
            'command' => $python,
            'args' => ['-m', 'mcp_ocr'],
            'timeout' => 30000,
        ];
    }

    protected static function codexPdfExtractMcpConfig(): ?array
    {
        return [
            'command' => PHP_BINARY,
            'args' => [base_path('artisan'), 'superocr:serve', '--transport=stdio', '-q'],
            'timeout' => 300000,
        ];
    }

    public static function codexMcpConfigArgs(?array $config = null, bool $includeSuperfeed = true): array
    {
        $args = [];

        foreach (self::codexMcpServers($config, $includeSuperfeed) as $name => $server) {
            $prefix = 'mcp_servers.' . self::tomlKeySegment($name);
            $args = array_merge($args, self::flattenCodexConfigArgs($prefix, $server));
        }

        return $args;
    }

    protected static function pythonModuleExists(string $python, string $module): bool
    {
        static $cache = [];
        $key = $python . '|' . $module;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $proc = self::proc([
            $python,
            '-c',
            'import importlib.util, sys; sys.exit(0 if importlib.util.find_spec(sys.argv[1]) else 1)',
            $module,
        ]);
        $proc->setTimeout(10);
        $proc->run();

        return $cache[$key] = $proc->isSuccessful();
    }

    protected static function superfeedMcpConfig(): array
    {
        return [
            'command' => PHP_BINARY,
            'args' => [base_path('artisan'), 'superfeed:mcp-server'],
        ];
    }

    protected static function normalizeCodexMcpServer(array $server): ?array
    {
        $normalized = [];

        foreach (['command', 'args', 'env', 'url', 'headers'] as $key) {
            if (!array_key_exists($key, $server)) {
                continue;
            }

            $value = $server[$key];
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if ($key === 'args' && !is_array($value)) {
                continue;
            }

            if (in_array($key, ['env', 'headers'], true) && !is_array($value)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return isset($normalized['command']) || isset($normalized['url']) ? $normalized : null;
    }

    protected static function flattenCodexConfigArgs(string $prefix, mixed $value): array
    {
        if (is_array($value) && !array_is_list($value)) {
            $args = [];
            foreach ($value as $key => $nested) {
                if (!is_string($key)) {
                    continue;
                }

                $args = array_merge(
                    $args,
                    self::flattenCodexConfigArgs($prefix . '.' . self::tomlKeySegment($key), $nested)
                );
            }

            return $args;
        }

        return [$prefix . '=' . json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    }

    protected static function tomlKeySegment(string $key): string
    {
        return preg_match('/^[A-Za-z0-9_-]+$/', $key)
            ? $key
            : '"' . addcslashes($key, "\\\"") . '"';
    }

    /**
     * Write .mcp.json config.
     */
    public static function writeConfig(array $config): bool
    {
        $path = self::projectRoot() . DIRECTORY_SEPARATOR . '.mcp.json';
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return file_put_contents($path, $json . "\n") !== false;
    }

    /**
     * Get the full registry.
     */
    public static function getRegistry(): array
    {
        return self::$registry;
    }

    /**
     * Get info for a specific server from registry.
     */
    public static function getServerInfo(string $key): ?array
    {
        return self::$registry[$key] ?? self::$apiServices[$key] ?? null;
    }

    /**
     * Check if a server is configured in .mcp.json.
     */
    public static function isConfigured(string $key): bool
    {
        $config = self::readConfig();

        return isset($config['mcpServers'][$key]);
    }

    /**
     * Get the status of all servers (configured + available from registry).
     */
    public static function getAllStatuses(): array
    {
        $config = self::readConfig();
        $configured = $config['mcpServers'] ?? [];
        $statuses = [];

        // Process all known servers from registry
        foreach (self::$registry as $key => $info) {
            $statuses[$key] = self::getServerStatus($key, $info, $configured[$key] ?? null);
        }

        // Process any configured servers NOT in registry (custom)
        foreach ($configured as $key => $mcpConfig) {
            if (!isset($statuses[$key])) {
                $statuses[$key] = self::getCustomServerStatus($key, $mcpConfig);
            }
        }

        // Process API services (not MCP servers)
        foreach (self::$apiServices as $key => $info) {
            $statuses[$key] = self::getApiServiceStatus($key, $info);
        }

        return $statuses;
    }

    /**
     * Get status for a known (registry) server.
     */
    /**
     * Get status for an API service (not MCP server).
     */
    protected static function getApiServiceStatus(string $key, array $info): array
    {
        $configured = \SuperAICore\Models\IntegrationConfig::isConfigured($key);
        return [
            'key' => $key,
            'name' => $info['name'],
            'icon' => $info['icon'],
            'color' => $info['color'],
            'type' => 'api-service',
            'category' => $info['category'] ?? 'other',
            'capabilities' => $info['capabilities'] ?? [],
            'requires_auth' => false,
            'config_fields' => $info['config_fields'] ?? null,
            'installed' => $configured,
            'in_registry' => true,
            'dependency_ready' => true,
            'auth_status' => null,
        ];
    }

    protected static function getServerStatus(string $key, array $info, ?array $mcpConfig): array
    {
        $installed = $mcpConfig !== null;

        // For api-service type: "installed" means config exists in database
        if (($info['type'] ?? '') === 'api-service') {
            $installed = \SuperAICore\Models\IntegrationConfig::isConfigured($key);
        }

        $status = [
            'key' => $key,
            'name' => $info['name'],
            'icon' => $info['icon'],
            'color' => $info['color'],
            'type' => $info['type'],
            'category' => $info['category'] ?? 'other',
            'capabilities' => $info['capabilities'],
            'requires_auth' => $info['requires_auth'] ?? false,
            'config_fields' => $info['config_fields'] ?? null,
            'installed' => $installed,
            'in_registry' => true,
            'dependency_ready' => false,
            'auth_status' => null,
        ];

        // Check dependency availability
        $status['dependency_ready'] = self::checkDependency($key, $info);

        // Check auth status if needed
        if ($info['requires_auth'] && $installed) {
            $status['auth_status'] = self::checkAuth($key, $info);
        }

        return $status;
    }

    /**
     * Get status for a custom (not in registry) server.
     */
    protected static function getCustomServerStatus(string $key, array $mcpConfig): array
    {
        $command = $mcpConfig['command'] ?? '';
        $commandExists = !empty($command) && (
            file_exists($command) || self::which($command) !== null
        );

        return [
            'key' => $key,
            'name' => ucfirst($key),
            'icon' => 'bi-puzzle-fill',
            'color' => '#6b7280',
            'type' => 'custom',
            'category' => 'other',
            'capabilities' => [],
            'requires_auth' => false,
            'installed' => true,
            'in_registry' => false,
            'dependency_ready' => $commandExists,
            'auth_status' => null,
            'mcp_config' => $mcpConfig,
        ];
    }

    /**
     * Check if a server's runtime dependency is available.
     * For installed servers, check actual .mcp.json config (command + args files exist).
     * For uninstalled servers, check if the installer tool is available.
     */
    protected static function checkDependency(string $key, array $info): bool
    {
        // If server is already installed, check actual .mcp.json config
        $config = self::readConfig();
        if (isset($config['mcpServers'][$key])) {
            $mcpConfig = $config['mcpServers'][$key];
            $command = $mcpConfig['command'] ?? '';

            // Check command exists (binary path or PATH command)
            if (empty($command)) return false;
            $commandExists = file_exists($command) || self::which($command) !== null;
            if (!$commandExists) return false;

            // Check first arg (entrypoint file) exists if it looks like a file path
            $args = $mcpConfig['args'] ?? [];
            if (!empty($args)) {
                $firstArg = $args[0] ?? '';
                // Only check file existence for absolute paths or --directory patterns
                if ($command === 'uv' && in_array('--directory', $args)) {
                    $dirIdx = array_search('--directory', $args);
                    $dir = $args[$dirIdx + 1] ?? '';
                    return is_dir($dir);
                }
                if (($firstArg === '-m' || $firstArg === '-Im') && !empty($args[1])) {
                    return self::pythonModuleExists($command, (string) $args[1]);
                }
                if (str_starts_with($firstArg, '/') && !str_starts_with($firstArg, '/-')) {
                    return file_exists($firstArg);
                }
            }

            return true;
        }

        // Not installed — check if installer tool is available
        switch ($info['type']) {
            case 'uvx':
                return self::which('uvx') !== null;

            case 'npx':
                return self::which('npx') !== null;

            case 'python':
                // For uninstalled python servers, check git + python/uv
                return self::which('git') !== null && (self::which('uv') !== null || self::which('python3') !== null);

            case 'python-package':
                return self::which('python3') !== null || self::which('python') !== null;

            case 'binary':
                return true;

            case 'api-service':
                return true; // No dependency — just stores config in database

            default:
                return false;
        }
    }

    /**
     * Check authentication status for servers that require it.
     */
    protected static function checkAuth(string $key, array $info): array
    {
        $sessionDir = self::homeDir() . DIRECTORY_SEPARATOR . ($info['session_dir'] ?? ".{$key}-mcp");
        $indicator = $sessionDir . DIRECTORY_SEPARATOR . ($info['session_indicator'] ?? 'session');

        $hasSession = file_exists($indicator);
        $sessionAge = null;
        $sessionAgeHuman = null;

        if ($hasSession) {
            $sessionAge = time() - filemtime($indicator);
            $sessionAgeHuman = self::humanAge($sessionAge);
        }

        return [
            'connected' => $hasSession,
            'session_dir' => $sessionDir,
            'session_age' => $sessionAge,
            'session_age_human' => $sessionAgeHuman,
        ];
    }

    /**
     * Install a server from registry.
     * Returns ['success' => bool, 'message' => string, 'output' => string].
     */
    public static function install(string $key, array $configValues = []): array
    {
        $info = self::getServerInfo($key);
        if (!$info) {
            return ['success' => false, 'message' => "Unknown server: {$key}", 'output' => ''];
        }

        if ($info['type'] !== 'api-service' && self::isConfigured($key)) {
            // Already installed — update config values if provided
            if (!empty($configValues) && !empty($info['config_fields'])) {
                return self::updateServerConfig($key, $info, $configValues);
            }
            return ['success' => false, 'message' => "Server '{$key}' is already configured.", 'output' => ''];
        }

        // Store user config values for use during install
        $info['_config_values'] = $configValues;

        $output = '';

        switch ($info['type']) {
            case 'uvx':
                return self::installUvx($key, $info);

            case 'npx':
                return self::installNpx($key, $info);

            case 'python':
                return self::installPython($key, $info);

            case 'python-package':
                return self::installPythonPackage($key, $info);

            case 'binary':
                return self::installBinary($key, $info);

            case 'artisan':
                return self::installArtisan($key, $info);

            case 'api-service':
                return self::installApiService($key, $info);

            default:
                return ['success' => false, 'message' => "Unknown server type: {$info['type']}", 'output' => ''];
        }
    }

    /**
     * Install a uvx-based server (just add to config, uvx handles packages at runtime).
     */
    protected static function installUvx(string $key, array $info): array
    {
        $uvx = self::getUvxPath() ?: self::which('uvx');
        if (!$uvx) {
            return [
                'success' => false,
                'message' => 'uvx_not_found',
                'output' => '',
            ];
        }

        // uvx packages run on-demand, just add config — use full path to uvx
        $mcpConfig = $info['mcp_config'] ?? [
            'command' => $uvx,
            'args' => [$info['package']],
            'timeout' => 30000,
        ];

        // Replace bare 'uvx' with full path in mcp_config (may not be in PATH)
        if (($mcpConfig['command'] ?? '') === 'uvx') {
            $mcpConfig['command'] = $uvx;
        }

        $config = self::readConfig();
        $config['mcpServers'][$key] = $mcpConfig;
        self::writeConfig($config);

        return ['success' => true, 'message' => 'installed', 'output' => ''];
    }

    /**
     * Install an artisan-based MCP server (built into the Laravel app).
     */
    protected static function installArtisan(string $key, array $info): array
    {
        $mcpConfig = $info['mcp_config'] ?? [
            'command' => PHP_BINARY,
            'args' => [base_path('artisan'), $info['artisan_command'] ?? 'mcp:serve', '--transport=stdio', '-q'],
            'timeout' => 300000,
        ];

        // Replace __ARTISAN__ placeholder with actual artisan path
        $mcpConfig['args'] = array_map(function ($arg) {
            return $arg === '__ARTISAN__' ? base_path('artisan') : $arg;
        }, $mcpConfig['args']);

        $config = self::readConfig();
        $config['mcpServers'][$key] = $mcpConfig;
        self::writeConfig($config);

        return ['success' => true, 'message' => 'installed', 'output' => ''];
    }

    /**
     * Install an npx-based server.
     */
    protected static function installNpx(string $key, array $info): array
    {
        $npx = self::which('npx');
        if (!$npx) {
            return [
                'success' => false,
                'message' => 'npx_not_found',
                'output' => '',
            ];
        }

        $output = '';

        // Run post-install command if defined (e.g., playwright install chromium)
        if (!empty($info['post_install'])) {
            $proc = Process::fromShellCommandline($info['post_install'], null, self::enrichedEnv());
            $proc->setTimeout(300);
            $proc->run();
            $output .= $proc->getOutput() . $proc->getErrorOutput();

            if (!$proc->isSuccessful()) {
                Log::warning("MCP post-install for {$key} had issues", ['output' => $output]);
                // Don't fail — npx packages may still work
            }
        }

        // Add to config
        $config = self::readConfig();
        $config['mcpServers'][$key] = $info['mcp_config'] ?? [
            'command' => 'npx',
            'args' => [$info['package']],
        ];
        self::writeConfig($config);

        return ['success' => true, 'message' => 'installed', 'output' => $output];
    }

    /**
     * Install a Python-based server (git clone + venv + pip install).
     */
    protected static function installPython(string $key, array $info): array
    {
        // ── Pre-flight checks ──
        if (!self::which('git')) {
            return ['success' => false, 'message' => 'git_not_found', 'output' => ''];
        }

        $hasUv = self::which('uv') !== null || self::findUvPath() !== null;
        $hasPython = self::which('python3') !== null || self::which('python') !== null;
        $hasNode = self::which('node') !== null;
        $hasNpm = self::which('npm') !== null;

        $serversDir = self::serversDir();
        $installDir = $info['install_dir'] ?? $key;
        $serverDir = $serversDir . DIRECTORY_SEPARATOR . $installDir;
        $output = '';

        // Create .mcp-servers directory
        if (!is_dir($serversDir)) {
            mkdir($serversDir, 0755, true);
        }

        // ── Clone or repair repo ──
        $requirementsFile = $info['requirements'] ?? 'requirements.txt';
        $requirementsPath = $serverDir . DIRECTORY_SEPARATOR . $requirementsFile;

        // Repair: directory exists but is empty or corrupted (missing key files)
        if (is_dir($serverDir) && !file_exists($requirementsPath)) {
            // Check if truly empty or missing requirements
            $fileCount = count(glob($serverDir . '/*'));
            if ($fileCount <= 1) {
                // Empty or near-empty dir — remove and re-clone
                self::removeDirectory($serverDir);
                $output .= "Repaired: removed corrupted directory\n";
            }
        }

        // Clone if directory doesn't exist
        if (!is_dir($serverDir)) {
            if (empty($info['repo'])) {
                return ['success' => false, 'message' => 'no_repo_url', 'output' => ''];
            }

            $git = self::which('git') ?: 'git';
            $cloneCmd = escapeshellarg($git) . ' clone --depth 1 '
                 . escapeshellarg($info['repo']) . ' '
                 . escapeshellarg($serverDir) . ' 2>&1';
            $cloneOutput = shell_exec($cloneCmd);
            $output .= $cloneOutput ?? '';

            if (!is_dir($serverDir) || count(glob($serverDir . '/*')) === 0) {
                Log::warning("MCP git clone failed for {$key}", [
                    'repo' => $info['repo'],
                    'dir' => $serverDir,
                    'output' => substr($output, 0, 500),
                ]);
                return ['success' => false, 'message' => 'clone_failed', 'output' => $output];
            }
        }

        // ── Detect project type ──
        $useNodeJs = str_ends_with($requirementsFile, 'package.json');
        $usePyproject = str_ends_with($requirementsFile, 'pyproject.toml');

        // Auto-detect: if registry says requirements.txt but package.json actually exists
        if (!$useNodeJs && !$usePyproject && file_exists($serverDir . DIRECTORY_SEPARATOR . 'package.json') && !file_exists($requirementsPath)) {
            $useNodeJs = true;
            $requirementsFile = 'package.json';
            $requirementsPath = $serverDir . DIRECTORY_SEPARATOR . 'package.json';
            $output .= "Auto-detected: Node.js project (package.json found)\n";
        }

        // ═══════════════════════════════════════════
        // ── Node.js projects (package.json) ──
        // ═══════════════════════════════════════════
        if ($useNodeJs) {
            if (!$hasNode || !$hasNpm) {
                return ['success' => false, 'message' => 'npx_not_found', 'output' => $output . 'Node.js and npm are required for this server.'];
            }

            $npm = self::which('npm');
            $npmDir = dirname($requirementsPath);

            // Repair: node_modules missing or corrupted
            $nodeModules = $npmDir . DIRECTORY_SEPARATOR . 'node_modules';
            if (!is_dir($nodeModules)) {
                $output .= "Installing npm dependencies...\n";
            }

            $proc = self::proc([$npm, 'install'], $npmDir);
            $proc->setTimeout(300);
            $proc->run();
            $output .= $proc->getOutput() . $proc->getErrorOutput();

            if (!$proc->isSuccessful()) {
                // Repair: try cleaning and reinstalling
                $output .= "npm install failed, trying clean install...\n";
                if (is_dir($nodeModules)) {
                    self::removeDirectory($nodeModules);
                }
                $lockFile = $npmDir . DIRECTORY_SEPARATOR . 'package-lock.json';
                if (file_exists($lockFile)) {
                    @unlink($lockFile);
                }
                $proc = self::proc([$npm, 'install'], $npmDir);
                $proc->setTimeout(300);
                $proc->run();
                $output .= $proc->getOutput() . $proc->getErrorOutput();

                if (!$proc->isSuccessful()) {
                    return ['success' => false, 'message' => 'npm_install_failed', 'output' => $output];
                }
            }

            // Build if package.json has a build script
            $packageJson = json_decode(file_get_contents($requirementsPath), true);
            $entrypoint = $info['entrypoint'] ?? 'dist/index.js';
            $entrypointFull = $npmDir . DIRECTORY_SEPARATOR . $entrypoint;

            if (isset($packageJson['scripts']['build'])) {
                // Repair: dist/ missing or stale — always rebuild
                $proc = self::proc([$npm, 'run', 'build'], $npmDir);
                $proc->setTimeout(300);
                $proc->run();
                $output .= $proc->getOutput() . $proc->getErrorOutput();

                if (!$proc->isSuccessful()) {
                    return ['success' => false, 'message' => 'build_failed', 'output' => $output];
                }
            }

            // Resolve entrypoint path
            if (!file_exists($entrypointFull)) {
                $entrypointFull = $serverDir . DIRECTORY_SEPARATOR . $entrypoint;
            }

            // Final check: entrypoint must exist
            if (!file_exists($entrypointFull)) {
                return ['success' => false, 'message' => 'binary_not_found', 'output' => $output . "Entrypoint not found: {$entrypointFull}"];
            }

            $node = self::which('node');
            $mcpConfig = [
                'command' => $node,
                'args' => [$entrypointFull],
                'timeout' => 30000,
            ];

            // Inject env vars from config_fields and save to database
            $configValues = $info['_config_values'] ?? [];
            $configFields = $info['config_fields'] ?? [];
            if (!empty($configFields) && !empty($configValues)) {
                $env = [];
                foreach ($configFields as $fieldKey => $fieldDef) {
                    if (isset($configValues[$fieldKey]) && !empty($configValues[$fieldKey])) {
                        $envKey = $fieldDef['env_key'] ?? strtoupper($fieldKey);
                        $env[$envKey] = $configValues[$fieldKey];
                        // Also persist to database
                        \SuperAICore\Models\IntegrationConfig::setValue($key, $fieldKey, $configValues[$fieldKey], $fieldDef['is_secret'] ?? false);
                    }
                }
                if (!empty($env)) $mcpConfig['env'] = $env;
            } else {
                // Read from database if no new values provided (re-install scenario)
                $dbValues = \SuperAICore\Models\IntegrationConfig::getAll($key);
                if (!empty($dbValues) && !empty($configFields)) {
                    $env = [];
                    foreach ($configFields as $fieldKey => $fieldDef) {
                        if (!empty($dbValues[$fieldKey])) {
                            $env[$fieldDef['env_key'] ?? strtoupper($fieldKey)] = $dbValues[$fieldKey];
                        }
                    }
                    if (!empty($env)) $mcpConfig['env'] = $env;
                }
            }

            $config = self::readConfig();
            $config['mcpServers'][$key] = $mcpConfig;
            self::writeConfig($config);

            return ['success' => true, 'message' => 'installed', 'output' => $output];
        }

        // ═══════════════════════════════════════════
        // ── Python projects ──
        // ═══════════════════════════════════════════
        if (!$hasUv && !$hasPython) {
            return ['success' => false, 'message' => 'python_not_found', 'output' => $output . 'Python 3.10+ or uv is required.'];
        }

        $venvDir = $serverDir . DIRECTORY_SEPARATOR . '.venv';

        // Repair: corrupted venv (python binary missing or broken)
        $venvPython = self::venvPython($venvDir);
        if (is_dir($venvDir) && !file_exists($venvPython)) {
            $output .= "Repaired: removing corrupted venv\n";
            self::removeDirectory($venvDir);
        }

        if ($hasUv) {
            $uvCmd = self::which('uv') ?: self::findUvPath();

            if ($usePyproject) {
                // pyproject.toml projects: use `uv sync` (creates venv automatically)
                $proc = self::proc([$uvCmd, 'sync'], $serverDir);
                $proc->setTimeout(300);
                $proc->run();
                $output .= $proc->getOutput() . $proc->getErrorOutput();

                if (!$proc->isSuccessful()) {
                    // Repair: try removing .venv and lock file, then retry
                    $output .= "uv sync failed, trying clean install...\n";
                    if (is_dir($venvDir)) {
                        self::removeDirectory($venvDir);
                    }
                    $lockFile = $serverDir . DIRECTORY_SEPARATOR . 'uv.lock';
                    if (file_exists($lockFile)) @unlink($lockFile);

                    $proc = self::proc([$uvCmd, 'sync'], $serverDir);
                    $proc->setTimeout(300);
                    $proc->run();
                    $output .= $proc->getOutput() . $proc->getErrorOutput();

                    if (!$proc->isSuccessful()) {
                        return ['success' => false, 'message' => 'pip_install_failed', 'output' => $output];
                    }
                }
            } else {
                // requirements.txt projects: create venv + pip install
                if (!is_dir($venvDir)) {
                    $proc = self::proc([$uvCmd, 'venv', $venvDir], $serverDir);
                    $proc->setTimeout(60);
                    $proc->run();
                    $output .= $proc->getOutput() . $proc->getErrorOutput();
                }

                if (file_exists($requirementsPath)) {
                    $proc = self::proc([$uvCmd, 'pip', 'install', '-r', $requirementsPath, '--python', self::venvPython($venvDir)], $serverDir);
                    $proc->setTimeout(300);
                    $proc->run();
                    $output .= $proc->getOutput() . $proc->getErrorOutput();

                    if (!$proc->isSuccessful()) {
                        return ['success' => false, 'message' => 'pip_install_failed', 'output' => $output];
                    }
                }
            }
        } else {
            // Fallback: python3/python + pip
            $pythonCmd = self::which('python3') ?: self::which('python');

            if (!is_dir($venvDir)) {
                $proc = self::proc([$pythonCmd, '-m', 'venv', $venvDir], $serverDir);
                $proc->setTimeout(60);
                $proc->run();
                $output .= $proc->getOutput() . $proc->getErrorOutput();

                // Repair: venv creation failed (missing ensurepip on some Linux distros)
                if (!$proc->isSuccessful()) {
                    $output .= "venv creation failed, trying without pip...\n";
                    $proc = self::proc([$pythonCmd, '-m', 'venv', '--without-pip', $venvDir], $serverDir);
                    $proc->setTimeout(60);
                    $proc->run();
                    $output .= $proc->getOutput() . $proc->getErrorOutput();

                    if ($proc->isSuccessful()) {
                        // Bootstrap pip manually
                        $proc = self::proc([self::venvPython($venvDir), '-m', 'ensurepip', '--default-pip'], $serverDir);
                        $proc->setTimeout(120);
                        $proc->run();
                        $output .= $proc->getOutput() . $proc->getErrorOutput();
                    }
                }
            }

            if (!$usePyproject && file_exists($requirementsPath)) {
                $pip = self::venvPython($venvDir);
                $proc = self::proc([$pip, '-m', 'pip', 'install', '-r', $requirementsPath], $serverDir);
                $proc->setTimeout(300);
                $proc->run();
                $output .= $proc->getOutput() . $proc->getErrorOutput();

                if (!$proc->isSuccessful()) {
                    return ['success' => false, 'message' => 'pip_install_failed', 'output' => $output];
                }
            } elseif ($usePyproject) {
                $pip = self::venvPython($venvDir);
                $proc = self::proc([$pip, '-m', 'pip', 'install', '-e', '.'], $serverDir);
                $proc->setTimeout(300);
                $proc->run();
                $output .= $proc->getOutput() . $proc->getErrorOutput();

                if (!$proc->isSuccessful()) {
                    return ['success' => false, 'message' => 'pip_install_failed', 'output' => $output];
                }
            }
        }

        // Add to .mcp.json — use mcp_config if provided, otherwise build from venv
        if (!empty($info['mcp_config'])) {
            $mcpConfig = $info['mcp_config'];
        } elseif (!empty($info['entrypoint_script'])) {
            // For pyproject.toml projects with a console_scripts entry (installed in .venv/bin/)
            $scriptPath = $venvDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $info['entrypoint_script'];
            $mcpConfig = [
                'command' => $scriptPath,
                'args' => [],
                'timeout' => 30000,
            ];
        } elseif (!empty($info['run_with_tsx'])) {
            // For TypeScript projects that need npx tsx to run
            $entrypoint = $serverDir . DIRECTORY_SEPARATOR . ($info['entrypoint'] ?? 'src/index.ts');
            $mcpConfig = [
                'command' => 'npx',
                'args' => ['-y', 'tsx', $entrypoint],
                'timeout' => 30000,
            ];
        } elseif ($usePyproject && $hasUv) {
            // For uv sync projects, use `uv run` with the entrypoint — use full path
            $uvCmd = self::which('uv') ?: self::findUvPath();
            $mcpConfig = [
                'command' => $uvCmd,
                'args' => ['--directory', $serverDir, 'run', $info['entrypoint'] ?? 'server.py'],
                'timeout' => 30000,
            ];
        } else {
            $python = self::venvPython($venvDir);
            $entrypoint = $serverDir . DIRECTORY_SEPARATOR . ($info['entrypoint'] ?? 'server.py');
            $mcpConfig = [
                'command' => $python,
                'args' => [$entrypoint],
                'timeout' => 30000,
            ];
        }

        // Inject env vars from config_fields if user provided values
        $configValues = $info['_config_values'] ?? [];
        $configFields = $info['config_fields'] ?? [];
        if (!empty($configFields) && !empty($configValues)) {
            $env = [];
            foreach ($configFields as $fieldKey => $fieldDef) {
                if (isset($configValues[$fieldKey]) && !empty($configValues[$fieldKey])) {
                    $envKey = $fieldDef['env_key'] ?? strtoupper($fieldKey);
                    $env[$envKey] = $configValues[$fieldKey];
                }
            }
            if (!empty($env)) {
                $mcpConfig['env'] = $env;
            }
        }

        $config = self::readConfig();
        $config['mcpServers'][$key] = $mcpConfig;
        self::writeConfig($config);

        return ['success' => true, 'message' => 'installed', 'output' => $output];
    }

    protected static function installPythonPackage(string $key, array $info): array
    {
        $python = self::which('python3') ?: self::which('python');
        if (!$python) {
            return ['success' => false, 'message' => 'python_not_found', 'output' => ''];
        }

        $package = $info['package'] ?? null;
        $module = $info['python_module'] ?? null;
        if (!$package || !$module) {
            return ['success' => false, 'message' => 'invalid_python_package_config', 'output' => ''];
        }

        $proc = self::proc([$python, '-m', 'pip', 'install', '--user', '--break-system-packages', $package]);
        $proc->setTimeout(600);
        $proc->run();
        $output = $proc->getOutput() . $proc->getErrorOutput();

        if (!$proc->isSuccessful()) {
            return ['success' => false, 'message' => 'pip_install_failed', 'output' => $output];
        }

        if (!self::pythonModuleExists($python, $module)) {
            return ['success' => false, 'message' => 'module_not_found_after_install', 'output' => $output];
        }

        $mcpConfig = $info['mcp_config'] ?? [
            'command' => $python,
            'args' => ['-m', $module],
            'timeout' => 30000,
        ];

        $config = self::readConfig();
        $config['mcpServers'][$key] = $mcpConfig;
        self::writeConfig($config);

        return ['success' => true, 'message' => 'installed', 'output' => $output];
    }

    /**
     * Install a pre-built binary server (download from GitHub releases).
     */
    /**
     * Update config values for an already-installed MCP server.
     * Saves to DB and updates MCP JSON config env.
     */
    protected static function updateServerConfig(string $key, array $info, array $configValues): array
    {
        $configFields = $info['config_fields'] ?? [];
        $config = self::readConfig();
        $mcpConfig = $config['mcpServers'][$key] ?? null;
        $saved = [];

        foreach ($configFields as $fieldKey => $fieldDef) {
            $value = $configValues[$fieldKey] ?? '';
            if (empty($value)) continue;

            // Save to database
            \SuperAICore\Models\IntegrationConfig::setValue($key, $fieldKey, $value, $fieldDef['is_secret'] ?? false);

            // Update MCP JSON config env
            if ($mcpConfig) {
                $envKey = $fieldDef['env_key'] ?? strtoupper($fieldKey);
                $config['mcpServers'][$key]['env'][$envKey] = $value;
            }
            $saved[] = $fieldKey;
        }

        if ($mcpConfig && !empty($saved)) {
            self::writeConfig($config);
        }

        return [
            'success' => true,
            'message' => 'Configuration updated',
            'output' => '',
        ];
    }

    /**
     * Save API service config values to database.
     */
    protected static function installApiService(string $key, array $info): array
    {
        $configValues = $info['_config_values'] ?? [];
        $configFields = $info['config_fields'] ?? [];
        $saved = [];

        foreach ($configFields as $fieldKey => $fieldDef) {
            $value = $configValues[$fieldKey] ?? '';
            if (empty($value)) continue;

            \SuperAICore\Models\IntegrationConfig::setValue(
                $key,
                $fieldKey,
                $value,
                $fieldDef['is_secret'] ?? false
            );
            $saved[] = $fieldKey;
        }

        return [
            'success' => true,
            'message' => !empty($saved) ? 'Configuration saved' : 'No values provided',
            'output' => '',
        ];
    }

    protected static function installBinary(string $key, array $info): array
    {
        $serversDir = self::serversDir();
        $installDir = $info['install_dir'] ?? $key;
        $serverDir = $serversDir . DIRECTORY_SEPARATOR . $installDir;
        $binaryName = $info['binary_name'] ?? $key;
        $output = '';

        if (!is_dir($serversDir)) {
            mkdir($serversDir, 0755, true);
        }

        if (!is_dir($serverDir)) {
            mkdir($serverDir, 0755, true);
        }

        // Detect platform
        $platform = self::detectPlatform();
        $releaseFiles = $info['release_files'] ?? [];

        if (!isset($releaseFiles[$platform])) {
            return ['success' => false, 'message' => 'unsupported_platform', 'output' => "Platform: {$platform}"];
        }

        $fileName = $releaseFiles[$platform];
        $baseUrl = $info['release_url'] ?? '';
        $downloadUrl = "{$baseUrl}/{$fileName}";
        $downloadPath = $serverDir . DIRECTORY_SEPARATOR . $fileName;
        $binaryPath = $serverDir . DIRECTORY_SEPARATOR . $binaryName;

        // Skip download if binary already exists
        if (!file_exists($binaryPath)) {
            // Download using curl or wget
            $curl = self::which('curl');
            $wget = self::which('wget');

            if ($curl) {
                $proc = self::proc([$curl, '-fSL', '-o', $downloadPath, $downloadUrl], $serverDir);
            } elseif ($wget) {
                $proc = self::proc([$wget, '-O', $downloadPath, $downloadUrl], $serverDir);
            } else {
                return ['success' => false, 'message' => 'curl_not_found', 'output' => ''];
            }

            $proc->setTimeout(120);
            $proc->run();
            $output .= $proc->getOutput() . $proc->getErrorOutput();

            if (!$proc->isSuccessful()) {
                @unlink($downloadPath);

                return ['success' => false, 'message' => 'download_failed', 'output' => $output];
            }

            // Extract archive
            if (str_ends_with($fileName, '.tar.gz') || str_ends_with($fileName, '.tgz')) {
                $proc = self::proc(['tar', 'xzf', $downloadPath], $serverDir);
                $proc->setTimeout(60);
                $proc->run();
                $output .= $proc->getOutput() . $proc->getErrorOutput();
            } elseif (str_ends_with($fileName, '.zip')) {
                $proc = self::proc(['unzip', '-o', $downloadPath], $serverDir);
                $proc->setTimeout(60);
                $proc->run();
                $output .= $proc->getOutput() . $proc->getErrorOutput();
            }

            // Clean up archive
            @unlink($downloadPath);

            // If target binary doesn't exist, find the extracted binary and rename it
            if (!file_exists($binaryPath)) {
                // Look for files matching binary name pattern (e.g., osmmcp-darwin-arm64)
                $candidates = glob($serverDir . DIRECTORY_SEPARATOR . $binaryName . '-*');
                // Also check for exact platform-suffixed name
                $platformBinary = $serverDir . DIRECTORY_SEPARATOR . $binaryName . '-' . $platform;
                if (file_exists($platformBinary)) {
                    rename($platformBinary, $binaryPath);
                } elseif (!empty($candidates)) {
                    // Pick the first matching candidate
                    $candidate = $candidates[0];
                    // Skip archive files
                    if (!preg_match('/\.(tar\.gz|tgz|zip|txt|md)$/i', $candidate)) {
                        rename($candidate, $binaryPath);
                    }
                }
            }

            // Make binary executable (Unix)
            if (PHP_OS_FAMILY !== 'Windows' && file_exists($binaryPath)) {
                chmod($binaryPath, 0755);
            }
        }

        if (!file_exists($binaryPath)) {
            return ['success' => false, 'message' => 'binary_not_found', 'output' => $output];
        }

        // Add to config
        $config = self::readConfig();
        $config['mcpServers'][$key] = [
            'command' => $binaryPath,
            'args' => [],
        ];
        self::writeConfig($config);

        return ['success' => true, 'message' => 'installed', 'output' => $output];
    }

    /**
     * Detect current platform for binary downloads.
     */
    protected static function detectPlatform(): string
    {
        $os = PHP_OS_FAMILY === 'Darwin' ? 'darwin' : (PHP_OS_FAMILY === 'Windows' ? 'windows' : 'linux');
        $arch = php_uname('m');

        // Normalize arch
        $archMap = [
            'x86_64' => 'amd64',
            'amd64' => 'amd64',
            'aarch64' => 'arm64',
            'arm64' => 'arm64',
        ];

        $normalizedArch = $archMap[$arch] ?? $arch;

        return "{$os}-{$normalizedArch}";
    }

    /**
     * Uninstall a server: remove from .mcp.json (optionally delete files).
     */
    public static function uninstall(string $key, bool $deleteFiles = false): array
    {
        $config = self::readConfig();

        if (!isset($config['mcpServers'][$key])) {
            return ['success' => false, 'message' => 'not_configured'];
        }

        // Remove from config
        unset($config['mcpServers'][$key]);
        self::writeConfig($config);

        // Optionally delete server files for python-type servers
        if ($deleteFiles) {
            $info = self::getServerInfo($key);
            if ($info && $info['type'] === 'python') {
                $installDir = $info['install_dir'] ?? $key;
                $serverDir = self::serversDir() . DIRECTORY_SEPARATOR . $installDir;

                if (is_dir($serverDir)) {
                    self::removeDirectory($serverDir);
                }
            }

            // Clear auth session if applicable
            if ($info && ($info['requires_auth'] ?? false)) {
                $sessionDir = self::homeDir() . DIRECTORY_SEPARATOR . ($info['session_dir'] ?? ".{$key}-mcp");
                if (is_dir($sessionDir)) {
                    self::removeDirectory($sessionDir);
                }
            }
        }

        return ['success' => true, 'message' => 'uninstalled'];
    }

    /**
     * Start auth flow for a server (e.g., LinkedIn browser login).
     */
    public static function startAuth(string $key): array
    {
        $info = self::getServerInfo($key);
        if (!$info || !($info['requires_auth'] ?? false)) {
            return ['success' => false, 'message' => 'auth_not_required'];
        }

        if ($info['type'] === 'uvx') {
            $uvx = self::which('uvx');
            if (!$uvx) {
                return ['success' => false, 'message' => 'uvx_not_found'];
            }

            $proc = self::proc([$uvx, $info['package'], '--login']);
            $proc->setTimeout(300);
            $proc->setOptions(['create_new_console' => true]);

            try {
                $proc->start();
                Log::info("MCP auth started for {$key}, PID: {$proc->getPid()}");

                return ['success' => true, 'message' => 'auth_started', 'pid' => $proc->getPid()];
            } catch (\Throwable $e) {
                Log::error("MCP auth failed for {$key}: {$e->getMessage()}");

                return ['success' => false, 'message' => 'auth_failed', 'error' => $e->getMessage()];
            }
        }

        return ['success' => false, 'message' => 'unsupported_auth_method'];
    }

    /**
     * Clear auth session for a server.
     */
    public static function clearAuth(string $key): array
    {
        $info = self::getServerInfo($key);
        if (!$info || !($info['requires_auth'] ?? false)) {
            return ['success' => false, 'message' => 'auth_not_required'];
        }

        $sessionDir = self::homeDir() . DIRECTORY_SEPARATOR . ($info['session_dir'] ?? ".{$key}-mcp");

        if (is_dir($sessionDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sessionDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            Log::info("MCP auth cleared for {$key}");
        }

        return ['success' => true, 'message' => 'auth_cleared'];
    }

    /**
     * Test if a server can respond (basic health check).
     */
    public static function testConnection(string $key): array
    {
        // API services don't have MCP connections to test
        $info = self::getServerInfo($key);
        if ($info && ($info['type'] ?? '') === 'api-service') {
            $configured = \SuperAICore\Models\IntegrationConfig::isConfigured($key);
            return ['success' => $configured, 'message' => $configured ? 'configured' : 'not_configured'];
        }

        $config = self::readConfig();
        $mcpConfig = $config['mcpServers'][$key] ?? null;

        if (!$mcpConfig) {
            return ['success' => false, 'message' => 'not_configured'];
        }

        $command = $mcpConfig['command'] ?? '';

        // Check if the command binary exists
        if (!file_exists($command) && !self::which($command)) {
            return ['success' => false, 'message' => 'command_not_found', 'command' => $command];
        }

        // For servers with file-based entrypoints, check if the file exists
        $args = $mcpConfig['args'] ?? [];
        if (!empty($args)) {
            // uv --directory /path run script.py — resolve relative entrypoint against directory
            $isUv = ($command === 'uv' || basename($command) === 'uv' || basename($command) === 'uv.exe');
            if ($isUv && in_array('--directory', $args)) {
                $dirIdx = array_search('--directory', $args);
                $dir = $args[$dirIdx + 1] ?? '';
                if (!is_dir($dir)) {
                    return ['success' => false, 'message' => 'entrypoint_not_found', 'path' => $dir];
                }
                // Check the run target (last arg) — it may be a script file or a command name
                $runTarget = end($args);
                if (str_ends_with($runTarget, '.py')) {
                    $fullPath = $dir . DIRECTORY_SEPARATOR . $runTarget;
                    if (!file_exists($fullPath)) {
                        return ['success' => false, 'message' => 'entrypoint_not_found', 'path' => $fullPath];
                    }
                }
                // If run target is a command name (like "gis-mcp"), trust uv to resolve it
                return ['success' => true, 'message' => 'ready'];
            }

            // Direct python/node entrypoints — only check absolute paths
            $lastArg = end($args);
            if (str_starts_with($lastArg, '/') && (str_ends_with($lastArg, '.py') || str_ends_with($lastArg, '.js'))) {
                if (!file_exists($lastArg)) {
                    return ['success' => false, 'message' => 'entrypoint_not_found', 'path' => $lastArg];
                }
            }
        }

        return ['success' => true, 'message' => 'ready'];
    }

    /**
     * Format seconds to human-readable age.
     */
    public static function humanAge(int $seconds): string
    {
        if ($seconds < 60) return $seconds . 's';
        if ($seconds < 3600) return round($seconds / 60) . 'min';
        if ($seconds < 86400) return round($seconds / 3600) . 'h';

        return round($seconds / 86400) . 'd';
    }

    /**
     * Recursively remove a directory.
     */
    protected static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($dir);
    }

    /**
     * Write the current MCP server set into every supported backend's
     * native config file via its BackendCapabilities adapter.
     *
     * @param  array|null  $backends   subset of ['claude','codex','gemini']; null = all that support MCP
     * @param  CapabilityRegistry|null $registry  defaults to app-bound singleton
     * @return array<int,array{backend:string,path:string,bytes:int,error:?string}>
     */
    public static function syncAllBackends(?array $backends = null, ?CapabilityRegistry $registry = null): array
    {
        $servers = self::toCanonicalServerSpec(self::codexMcpServers());

        if ($registry === null && function_exists('app')) {
            try {
                $registry = app(CapabilityRegistry::class);
            } catch (\Throwable $e) {
                $registry = new CapabilityRegistry();
            }
        }
        $registry ??= new CapabilityRegistry();

        $backends ??= ['claude', 'codex', 'gemini'];
        $home = getenv('HOME') ?: (PHP_OS_FAMILY === 'Windows' ? (getenv('USERPROFILE') ?: '') : '');
        $report = [];

        foreach ($backends as $backend) {
            $cap = $registry->for($backend);
            $relPath = $cap->mcpConfigPath();
            if (!$cap->supportsMcp() || !$relPath || !$home) {
                $report[] = ['backend' => $backend, 'path' => '', 'bytes' => 0, 'error' => 'backend does not support MCP or home unknown'];
                continue;
            }

            $full = $home . DIRECTORY_SEPARATOR . $relPath;
            $dir = dirname($full);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);

            try {
                $content = $cap->renderMcpConfig($servers);
                $written = @file_put_contents($full, $content);
                $report[] = [
                    'backend' => $backend,
                    'path' => $full,
                    'bytes' => $written === false ? 0 : $written,
                    'error' => $written === false ? 'file_put_contents failed' : null,
                ];
            } catch (\Throwable $e) {
                $report[] = ['backend' => $backend, 'path' => $full, 'bytes' => 0, 'error' => $e->getMessage()];
            }
        }

        return $report;
    }

    /**
     * Normalize `[name => [command, args, env]]` into the canonical spec
     * BackendCapabilities::renderMcpConfig() expects.
     */
    protected static function toCanonicalServerSpec(array $named): array
    {
        $out = [];
        foreach ($named as $key => $server) {
            if (!is_array($server)) continue;
            $out[] = [
                'key' => (string) $key,
                'command' => $server['command'] ?? null,
                'args' => $server['args'] ?? [],
                'env' => $server['env'] ?? [],
            ];
        }
        return $out;
    }
}
