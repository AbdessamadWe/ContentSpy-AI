const express = require('express')
const { chromium } = require('playwright')
const app = express()

app.use(express.json())

app.post('/scrape', async (req, res) => {
  const { url } = req.body
  const browser = await chromium.launch()
  const page = await browser.newPage()
  await page.goto(url, { waitUntil: 'networkidle' })
  const html = await page.content()
  await browser.close()
  res.json({ html })
})

app.listen(3001, () => console.log('Playwright service on :3001'))
