'use strict'

const express = require('express')
const { execFile } = require('child_process')
const { promisify } = require('util')
const fsp = require('fs/promises')
const path = require('path')
const os = require('os')
const crypto = require('crypto')

const execFileAsync = promisify(execFile)

const app = express()
app.use(express.json({ limit: '10mb' }))

// ── Concurrency limiter ──────────────────────────────────────────────────────
const MAX_CONCURRENT = parseInt(process.env.MAX_CONCURRENT_ASSEMBLIES || '2', 10)
let activeJobs = 0

// ── Download a file from URL ──────────────────────────────────────────────────
async function downloadFile(url, dest) {
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), 30_000)
  try {
    const res = await fetch(url, { signal: controller.signal })
    if (!res.ok) throw new Error(`Download failed: HTTP ${res.status} for ${url}`)
    const buf = Buffer.from(await res.arrayBuffer())
    await fsp.writeFile(dest, buf)
  } finally {
    clearTimeout(timer)
  }
}

// ── Build FFmpeg slideshow filter_complex ─────────────────────────────────────
function buildSlideshowFilter(imageCount, imageDuration, width, height) {
  const scaleFilter = `scale=${width}:${height}:force_original_aspect_ratio=decrease,pad=${width}:${height}:(ow-iw)/2:(oh-ih)/2,setsar=1`
  const parts = Array.from({ length: imageCount }, (_, i) =>
    `[${i}:v]${scaleFilter},setpts=PTS-STARTPTS,trim=duration=${imageDuration}[v${i}]`,
  )
  const inputs = Array.from({ length: imageCount }, (_, i) => `[v${i}]`).join('')
  return [...parts, `${inputs}concat=n=${imageCount}:v=1:a=0[vout]`].join(';')
}

// ── Upload to R2 (optional) ───────────────────────────────────────────────────
async function uploadToR2(filePath, key) {
  const { S3Client, PutObjectCommand } = require('@aws-sdk/client-s3')
  const client = new S3Client({
    region: 'auto',
    endpoint: process.env.R2_ENDPOINT,
    credentials: {
      accessKeyId: process.env.R2_ACCESS_KEY_ID,
      secretAccessKey: process.env.R2_SECRET_ACCESS_KEY,
    },
  })
  const fileBuffer = await fsp.readFile(filePath)
  await client.send(new PutObjectCommand({
    Bucket: process.env.R2_BUCKET,
    Key: key,
    Body: fileBuffer,
    ContentType: 'video/mp4',
  }))
  return `${process.env.R2_PUBLIC_URL}/${key}`
}

// ── GET /health ───────────────────────────────────────────────────────────────
app.get('/health', async (_req, res) => {
  try {
    await new Promise((resolve, reject) =>
      execFile('ffmpeg', ['-version'], (err) => err ? reject(err) : resolve()),
    )
    res.json({ status: 'ok', active_jobs: activeJobs, max_concurrent: MAX_CONCURRENT })
  } catch (_) {
    res.status(500).json({ status: 'error', error: 'FFmpeg not found' })
  }
})

// ── POST /assemble ────────────────────────────────────────────────────────────
/*
  Body: { images: [url,...], audio_url?, duration?, format?, r2_key?, job_id? }
  format: "tiktok" | "instagram_reels"
*/
app.post('/assemble', async (req, res) => {
  if (activeJobs >= MAX_CONCURRENT) {
    return res.status(429).json({ error: 'Too many concurrent jobs — retry later', active_jobs: activeJobs })
  }

  const {
    images = [],
    audio_url = null,
    duration = null,
    format = 'tiktok',
    r2_key = null,
    job_id = crypto.randomUUID(),
  } = req.body

  if (!images.length) return res.status(400).json({ error: 'images array is required' })
  if (images.length > 20) return res.status(400).json({ error: 'max 20 images per video' })

  const specs = {
    tiktok:          { width: 1080, height: 1920, fps: 30 },
    instagram_reels: { width: 1080, height: 1920, fps: 30 },
  }
  const spec = specs[format] || specs.tiktok
  const totalDuration = duration || images.length * 5
  const imageDuration = totalDuration / images.length

  activeJobs++
  const tmpDir = await fsp.mkdtemp(path.join(os.tmpdir(), `cspy-${job_id}-`))

  try {
    // Download images
    const imagePaths = []
    for (let i = 0; i < images.length; i++) {
      const ext = (images[i].match(/\.(jpg|jpeg|png|webp)/i) || ['', 'jpg'])[1]
      const dest = path.join(tmpDir, `img${i}.${ext}`)
      await downloadFile(images[i], dest)
      imagePaths.push(dest)
    }

    // Download audio (optional, silent fallback)
    let audioPath = null
    if (audio_url) {
      const ext = (audio_url.match(/\.(mp3|wav|ogg|m4a)/i) || ['', 'mp3'])[1]
      audioPath = path.join(tmpDir, `audio.${ext}`)
      await downloadFile(audio_url, audioPath).catch(() => { audioPath = null })
    }

    const outputPath = path.join(tmpDir, 'output.mp4')
    const filterComplex = buildSlideshowFilter(imagePaths.length, imageDuration, spec.width, spec.height)
    const inputArgs = imagePaths.flatMap(p => ['-i', p])
    const audioArgs = audioPath ? ['-i', audioPath] : []
    const audioMapArgs = audioPath
      ? ['-map', '[vout]', '-map', `${imagePaths.length}:a`, '-c:a', 'aac', '-shortest']
      : ['-map', '[vout]', '-an']

    await execFileAsync('ffmpeg', [
      '-y',
      ...inputArgs,
      ...audioArgs,
      '-filter_complex', filterComplex,
      ...audioMapArgs,
      '-c:v', 'libx264', '-preset', 'fast', '-crf', '23',
      '-r', String(spec.fps),
      '-pix_fmt', 'yuv420p',
      '-movflags', '+faststart',
      outputPath,
    ], { timeout: 300_000 })

    let videoUrl = null
    if (r2_key && process.env.R2_ENDPOINT) {
      videoUrl = await uploadToR2(outputPath, r2_key)
    }

    const stat = await fsp.stat(outputPath)
    return res.json({
      status: 'ok',
      job_id,
      format,
      duration: totalDuration,
      images_count: images.length,
      has_audio: !!audioPath,
      file_size_bytes: stat.size,
      video_url: videoUrl,
    })
  } catch (err) {
    console.error(`[ffmpeg] assembly error job=${job_id}:`, err.message)
    return res.status(500).json({ error: err.message, job_id })
  } finally {
    activeJobs--
    fsp.rm(tmpDir, { recursive: true, force: true }).catch(() => {})
  }
})

// ── POST /tts-to-video ────────────────────────────────────────────────────────
app.post('/tts-to-video', async (req, res) => {
  if (activeJobs >= MAX_CONCURRENT) {
    return res.status(429).json({ error: 'Too many concurrent jobs', active_jobs: activeJobs })
  }

  const { image_url, audio_url, r2_key, job_id = crypto.randomUUID(), format = 'tiktok' } = req.body
  if (!image_url || !audio_url) return res.status(400).json({ error: 'image_url and audio_url required' })

  const spec = { width: 1080, height: 1920 }
  activeJobs++
  const tmpDir = await fsp.mkdtemp(path.join(os.tmpdir(), `cspy-tts-${job_id}-`))

  try {
    const imgPath = path.join(tmpDir, 'image.jpg')
    const audioPath = path.join(tmpDir, 'audio.mp3')
    const outputPath = path.join(tmpDir, 'output.mp4')

    await Promise.all([downloadFile(image_url, imgPath), downloadFile(audio_url, audioPath)])

    await execFileAsync('ffmpeg', [
      '-y',
      '-loop', '1', '-i', imgPath,
      '-i', audioPath,
      '-vf', `scale=${spec.width}:${spec.height}:force_original_aspect_ratio=decrease,pad=${spec.width}:${spec.height}:(ow-iw)/2:(oh-ih)/2`,
      '-c:v', 'libx264', '-preset', 'fast', '-crf', '23',
      '-c:a', 'aac',
      '-shortest',
      '-pix_fmt', 'yuv420p',
      '-movflags', '+faststart',
      outputPath,
    ], { timeout: 120_000 })

    let videoUrl = null
    if (r2_key && process.env.R2_ENDPOINT) {
      videoUrl = await uploadToR2(outputPath, r2_key)
    }

    const stat = await fsp.stat(outputPath)
    return res.json({ status: 'ok', job_id, video_url: videoUrl, file_size_bytes: stat.size })
  } catch (err) {
    return res.status(500).json({ error: err.message, job_id })
  } finally {
    activeJobs--
    fsp.rm(tmpDir, { recursive: true, force: true }).catch(() => {})
  }
})

const PORT = process.env.PORT || 3002
app.listen(PORT, () => {
  console.log(`[ContentSpy] FFmpeg service listening on :${PORT}`)
  console.log(`[ContentSpy] Max concurrent assemblies: ${MAX_CONCURRENT}`)
})
