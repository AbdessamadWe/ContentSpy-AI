# ContentSpy AI - SaaS Competitive Intelligence & Automated Content Platform

A multi-tenant SaaS platform for competitive intelligence, AI-powered content generation, and multi-platform publishing with WordPress integration.

## 🚀 Quick Start

### Prerequisites
- PHP 8.3+
- Composer
- Node.js 18+
- MySQL 8.0
- Redis
- MongoDB (for spy snapshots)
- Meilisearch

### Installation

```bash
# 1. Clone the repository
git clone https://github.com/your-repo/ContentSpy-AI.git
cd ContentSpy-AI

# 2. Install PHP dependencies
cd backend
composer install

# 3. Install Node dependencies
cd ../frontend
npm install

# 4. Copy environment file
cp backend/.env.example backend/.env

# 5. Generate application key
cd backend
php artisan key:generate

# 6. Configure your .env file (see .env.example for all required API keys)

# 7. Start Docker services
cd ../docker
docker-compose up -d

# 8. Run migrations
docker exec contentspy-app php artisan migrate

# 9. Start the application
php artisan serve
```

## 📋 Features

### Multi-Site Management
- Connect unlimited WordPress sites
- Two-way sync with WordPress plugin
- Site health monitoring

### Competitor Spying Engine
- RSS Feed Monitoring
- HTML Scraping (Playwright)
- Sitemap Monitoring
- Google News & Trends
- Social Signal Tracking
- Keyword Gap Analysis (SEMrush/Ahrefs)
- SERP Position Monitoring

### AI Content Generation
- Multi-provider support: OpenAI, Anthropic, OpenRouter
- Image generation: DALL-E 3, Stable Diffusion, Midjourney
- Video generation for social media
- Full token cost tracking

### Publishing
- WordPress auto-publishing (REST API or Plugin)
- Social media distribution (Facebook, Instagram, TikTok, Pinterest)
- Scheduling and queue management

### Credit System
- Atomic credit reservation
- Per-action pricing
- Usage analytics

## 🏗️ Architecture

```
ContentSpy-AI/
├── backend/              # Laravel 11 API
│   ├── app/
│   │   ├── Http/         # Controllers & Middleware
│   │   ├── Jobs/        # Queue Jobs
│   │   ├── Models/      # Eloquent Models
│   │   ├── Services/    # Business Logic
│   │   └── Console/     # Commands
│   ├── config/          # Configuration
│   ├── database/        # Migrations & Seeders
│   └── routes/          # API Routes
├── frontend/            # React 18 + Vite
│   └── src/
│       ├── pages/       # Page Components
│       ├── components/  # Reusable Components
│       ├── hooks/       # Custom Hooks
│       ├── stores/      # Zustand Stores
│       └── lib/         # Utilities
├── docker/              # Docker Configuration
│   └── docker-compose.yml
├── services/            # Microservices
│   ├── playwright/      # Headless Browser Scraping
│   └── ffmpeg/          # Video Assembly
└── wordpress-plugin/    # WordPress Plugin
```

## 📝 API Endpoints

### Authentication
- `POST /api/auth/register` - Register new user
- `POST /api/auth/login` - Login
- `GET /api/auth/google/redirect` - Google OAuth

### Workspaces
- `GET /api/workspaces` - List workspaces
- `POST /api/workspaces` - Create workspace

### Sites
- `GET /api/sites` - List sites
- `POST /api/sites` - Create site
- `POST /api/sites/{id}/verify-connection` - Verify WordPress

### Competitors
- `GET /api/competitors` - List competitors
- `POST /api/competitors` - Add competitor
- `POST /api/competitors/{id}/scan` - Trigger scan

### Content
- `GET /api/suggestions` - List content suggestions
- `POST /api/suggestions/{id}/accept` - Accept & generate
- `GET /api/articles` - List articles

### Social
- `GET /api/social/accounts` - List connected accounts
- `POST /api/social/{platform}/connect` - OAuth connect

## 🔧 Configuration

### Required API Keys

1. **OpenAI** - For GPT models
2. **Anthropic** - For Claude models
3. **Stripe** - For payments
4. **Resend** - For emails

### Optional API Keys

1. **Google OAuth** - Social login
2. **SEMrush/Ahrefs** - Keyword gap analysis
3. **Replicate** - Stable Diffusion images
4. **ElevenLabs** - Voice generation

## 🧪 Running Tests

```bash
cd backend
php artisan test
```

## 📦 Deployment

### Production with Docker

```bash
# Build and start production containers
cd docker
docker-compose -f docker-compose.prod.yml up -d

# Run migrations
docker exec contentspy-app php artisan migrate

# Or use the deploy script
chmod +x ../deploy.sh
../deploy.sh
```

## 📄 License

Proprietary - All rights reserved
