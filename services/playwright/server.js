'use strict'

const express = require('express')
const { chromium } = require('playwright')

const app = express()
app.use(express.json({ limit: '10mb' }))

// ── Circuit Breaker ──────────────────────────────────────────────────────────
const circuit = {
  failures: 0,
  threshold: 5,
  resetAfterMs: 60_000,
  openUntil: null,

  isOpen() {
    if (!this.openUntil) return false
    if (Date.now() > this.openUntil) {
      this.failures = 0
      this.openUntil = null
      return false
    }
    return true
  },

  recordFailure() {
    this.failures++
    if (this.failures >= this.threshold) {
      this.openUntil = Date.now() + this.resetAfterMs
      console.error(`[circuit] OPEN — too many failures, blocking for ${this.resetAfterMs / 1000}s`)
    }
  },

  recordSuccess() {
    this.failures = 0
    this.openUntil = null
  },
}

// ── User-Agent Pool (20+ agents) ─────────────────────────────────────────────
const USER_AGENTS = [
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
  'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:125.0) Gecko/20100101 Firefox/125.0',
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 14.4; rv:125.0) Gecko/20100101 Firefox/125.0',
  'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0',
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_4_1) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4.1 Safari/605.1.15',
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.0.0',
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
  'Mozilla/5.0 (X11; Linux x86_64; rv:124.0) Gecko/20100101 Firefox/124.0',
  'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4.1 Mobile/15E148 Safari/604.1',
  'Mozilla/5.0 (iPad; CPU OS 17_4_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4.1 Mobile/15E148 Safari/604.1',
  'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.6367.82 Mobile Safari/537.36',
  'Mozilla/5.0 (Linux; Android 14; SM-S928B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.6367.82 Mobile Safari/537.36',
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 OPR/110.0.0.0',
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 OPR/110.0.0.0',
  'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0.0.0 Safari/537.36',
  'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
]

function randomUserAgent() {
  return USER_AGENTS[Math.floor(Math.random() * USER_AGENTS.length)]
}

// ── Proxy list from env ──────────────────────────────────────────────────────
const PROXY_LIST = process.env.PROXY_LIST
  ? process.env.PROXY_LIST.split(',').map(p => p.trim()).filter(Boolean)
  : []

function randomProxy() {
  if (!PROXY_LIST.length) return null
  const raw = PROXY_LIST[Math.floor(Math.random() * PROXY_LIST.length)]
  return { server: raw }
}

// ── Browser launch helper ────────────────────────────────────────────────────
async function launchBrowser() {
  const proxy = randomProxy()
  return chromium.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
    ...(proxy ? { proxy } : {}),
  })
}

// ── Timeout wrapper ───────────────────────────────────────────────────────────
async function withTimeout(promise, ms) {
  let timer
  const timeout = new Promise((_, reject) => {
    timer = setTimeout(() => reject(new Error(`Timeout after ${ms}ms`)), ms)
  })
  try {
    return await Promise.race([promise, timeout])
  } finally {
    clearTimeout(timer)
  }
}

// ── GET /health ───────────────────────────────────────────────────────────────
app.get('/health', (_req, res) => {
  res.json({
    status: circuit.isOpen() ? 'degraded' : 'ok',
    circuit_open: circuit.isOpen(),
    circuit_failures: circuit.failures,
    proxy_count: PROXY_LIST.length,
    timestamp: new Date().toISOString(),
  })
})

// ── POST /scrape ──────────────────────────────────────────────────────────────
app.post('/scrape', async (req, res) => {
  if (circuit.isOpen()) {
    return res.status(503).json({ error: 'Circuit open — service temporarily unavailable', retry_after: 60 })
  }

  const { url, wait_for = 'networkidle', timeout_ms = 30_000, screenshot = false } = req.body
  if (!url) return res.status(400).json({ error: 'url is required' })

  let browser
  try {
    browser = await launchBrowser()
    const page = await browser.newPage()
    await page.setUserAgent(randomUserAgent())
    await page.setExtraHTTPHeaders({ 'Accept-Language': 'en-US,en;q=0.9' })

    await withTimeout(
      page.goto(url, { waitUntil: wait_for, timeout: timeout_ms }),
      timeout_ms + 5_000,
    )

    const html = await page.content()
    const title = await page.title().catch(() => null)
    let screenshotBase64 = null

    if (screenshot) {
      const buf = await page.screenshot({ type: 'jpeg', quality: 70, fullPage: false })
      screenshotBase64 = buf.toString('base64')
    }

    circuit.recordSuccess()
    return res.json({ html, title, url, screenshot: screenshotBase64 })
  } catch (err) {
    circuit.recordFailure()
    console.error(`[scrape] error for ${url}:`, err.message)
    return res.status(500).json({ error: err.message, url })
  } finally {
    if (browser) await browser.close().catch(() => {})
  }
})

// ── POST /scrape-links ────────────────────────────────────────────────────────
app.post('/scrape-links', async (req, res) => {
  if (circuit.isOpen()) {
    return res.status(503).json({ error: 'Circuit open — service temporarily unavailable', retry_after: 60 })
  }

  const { url, link_selector = 'a', max_pages = 3, timeout_ms = 30_000 } = req.body
  if (!url) return res.status(400).json({ error: 'url is required' })

  let browser
  try {
    browser = await launchBrowser()
    const allLinks = new Set()
    let currentUrl = url

    for (let page_num = 0; page_num < max_pages; page_num++) {
      const page = await browser.newPage()
      await page.setUserAgent(randomUserAgent())

      try {
        await withTimeout(
          page.goto(currentUrl, { waitUntil: 'domcontentloaded', timeout: timeout_ms }),
          timeout_ms + 5_000,
        )

        const links = await page.$$eval(link_selector, els =>
          els.map(el => el.href).filter(h => h && h.startsWith('http')),
        )
        links.forEach(l => allLinks.add(l))

        const nextHref = await page.$eval('a[rel="next"], .next a, .nav-next a', el => el.href).catch(() => null)
        await page.close()

        if (!nextHref || nextHref === currentUrl) break
        currentUrl = nextHref
      } catch (e) {
        await page.close().catch(() => {})
        break
      }
    }

    circuit.recordSuccess()
    return res.json({ links: [...allLinks], count: allLinks.size, url })
  } catch (err) {
    circuit.recordFailure()
    return res.status(500).json({ error: err.message, url })
  } finally {
    if (browser) await browser.close().catch(() => {})
  }
})

// ── POST /parse-rss ────────────────────────────────────────────────────────────
app.post('/parse-rss', async (req, res) => {
  const { url, timeout_ms = 15_000 } = req.body
  if (!url) return res.status(400).json({ error: 'url is required' })

  try {
    const controller = new AbortController()
    const timer = setTimeout(() => controller.abort(), timeout_ms)

    const response = await fetch(url, {
      signal: controller.signal,
      headers: {
        'User-Agent': randomUserAgent(),
        'Accept': 'application/rss+xml, application/atom+xml, application/xml, text/xml',
      },
    })
    clearTimeout(timer)

    if (!response.ok) throw new Error(`HTTP ${response.status}`)
    const xml = await response.text()
    return res.json({ xml, url, content_type: response.headers.get('content-type') })
  } catch (err) {
    return res.status(500).json({ error: err.message, url })
  }
})

// ── POST /parse-sitemap ────────────────────────────────────────────────────────
app.post('/parse-sitemap', async (req, res) => {
  const { url, timeout_ms = 15_000 } = req.body
  if (!url) return res.status(400).json({ error: 'url is required' })

  try {
    const controller = new AbortController()
    const timer = setTimeout(() => controller.abort(), timeout_ms)

    const response = await fetch(url, {
      signal: controller.signal,
      headers: { 'User-Agent': randomUserAgent() },
    })
    clearTimeout(timer)

    if (!response.ok) throw new Error(`HTTP ${response.status}`)
    const xml = await response.text()
    return res.json({ xml, url })
  } catch (err) {
    return res.status(500).json({ error: err.message, url })
  }
})

const PORT = process.env.PORT || 3001
app.listen(PORT, () => {
  console.log(`[ContentSpy] Playwright service listening on :${PORT}`)
  console.log(`[ContentSpy] Proxy pool: ${PROXY_LIST.length} proxies loaded`)
})
