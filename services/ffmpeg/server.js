const express = require('express')
const { execFile } = require('child_process')
const app = express()

app.use(express.json())

app.post('/assemble', async (req, res) => {
  // FFmpeg video assembly logic here
  res.json({ status: 'ok' })
})

app.listen(3002, () => console.log('FFmpeg service on :3002'))
