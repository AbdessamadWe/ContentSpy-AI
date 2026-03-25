# ContentSpy AI

Multi-tenant SaaS for competitive intelligence, AI content generation, and WordPress publishing.

## Stack
- **Backend**: Laravel 11 (PHP 8.3)
- **Frontend**: React 18 + Vite + TailwindCSS
- **Queue**: Redis + Laravel Horizon
- **DB**: MySQL 8 + MongoDB + Meilisearch
- **Microservices**: Playwright (scraping) + FFmpeg (video)

## Quick Start (GitHub Codespaces)

1. Open repo → Code → Codespaces → Create codespace
2. Wait ~2min for build
3. Copy `.env.example` → `.env` and fill API keys
4. Replace `data/full_kson_tracker.json` and `data/full_prompt.txt`
5. Run: `php artisan serve`

## Ports
| Service | Port |
|---|---|
| Laravel API | 8000 |
| React (Vite) | 5173 |
| Playwright | 3001 |
| FFmpeg | 3002 |
| MySQL | 3306 |
| Redis | 6379 |
| MongoDB | 27017 |
| Meilisearch | 7700 |
