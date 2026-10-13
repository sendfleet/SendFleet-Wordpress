/**
 * Generates the WordPress.org directory assets (.wordpress-org/) from the
 * brand vectors in bin/brand/ — the same sources brand.sendfleet.net uses —
 * so the plugin icon and banner cannot drift from the design system.
 *
 *   NODE_PATH=../SendFleet-Docs/node_modules node bin/generate-assets.mjs
 *
 * Requires `sharp`, and the Syne and JetBrains Mono fonts installed locally
 * (the banner's tagline is typeset; the wordmark itself is vector).
 *
 * Writes: icon-128x128.png, icon-256x256.png, icon.svg,
 *         banner-772x250.png, banner-1544x500.png
 */

import { readFile, writeFile, mkdir } from "node:fs/promises"
import path from "node:path"
import { fileURLToPath } from "node:url"
import { createRequire } from "node:module"

const require = createRequire(import.meta.url)
const sharp = require(require.resolve("sharp", { paths: [process.env.NODE_PATH || "", process.cwd()] }))

const HERE = path.dirname(fileURLToPath(import.meta.url))
const ROOT = path.resolve(HERE, "..")
const OUT = path.join(ROOT, ".wordpress-org")

const AMBER = "#f5a623"
const INK = "#0a0a0a"
const CHALK = "#f0ede8"
const SMOKE = "#8a8480"
const SUPERSAMPLE = 4

const read = (p) => readFile(path.join(HERE, "brand", p), "utf8")
const bodyOf = (svg) => svg.replace(/<svg[\s\S]*?>/, "").replace(/<\/\s*svg\s*>\s*$/, "")

async function mark(id) {
	return bodyOf(await read("sendfleet-mark.svg")).replace(/id="mk"/g, `id="${id}"`).replace(/url\(#mk\)/g, `url(#${id})`)
}

async function wordmark(fill) {
	// "Send" inherits the group fill; "Fleet" carries its own amber.
	return bodyOf(await read("sendfleet-wordmark.svg")).replace('<g\n    aria-label="SendFleet"', `<g fill="${fill}"\n    aria-label="SendFleet"`)
}

async function raster(svg, width, height) {
	return sharp(Buffer.from(svg), { density: 72 * SUPERSAMPLE })
		.resize(width * SUPERSAMPLE, height * SUPERSAMPLE, { fit: "fill" })
		.resize(width, height, { fit: "fill", kernel: "lanczos3" })
		.png({ compressionLevel: 9 })
		.toBuffer()
}

async function icon(size) {
	const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" width="${size}" height="${size}">${await mark("mk-icon")}</svg>`
	return raster(svg, size, size)
}

/** Banner at 1544x500; the 772x250 version is the same artwork at half size. */
async function bannerSvg() {
	return `<svg xmlns="http://www.w3.org/2000/svg" width="1544" height="500" viewBox="0 0 1544 500">
  <defs>
    <linearGradient id="glow" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0" stop-color="${AMBER}" stop-opacity="0.10"/>
      <stop offset="1" stop-color="${AMBER}" stop-opacity="0"/>
    </linearGradient>
    <pattern id="grid" width="80" height="80" patternUnits="userSpaceOnUse">
      <path d="M80 0H0V80" fill="none" stroke="rgba(255,255,255,0.035)" stroke-width="1"/>
    </pattern>
  </defs>
  <rect width="1544" height="500" fill="${INK}"/>
  <rect width="1544" height="500" fill="url(#grid)"/>
  <rect width="1544" height="500" fill="url(#glow)"/>

  <g transform="translate(120 112)"><g transform="scale(0.66)">${await mark("mk-banner")}</g></g>
  <svg x="209" y="51" viewBox="0 0 200 70" width="700" height="245">${await wordmark(CHALK)}</svg>

  <g font-family="JetBrains Mono" fill="${AMBER}">
    <text x="124" y="336" font-size="18" font-weight="500" letter-spacing="7">WORDPRESS PLUGIN</text>
  </g>
  <g font-family="Syne" font-weight="800">
    <text x="122" y="400" font-size="36" letter-spacing="-0.8" fill="${CHALK}">Every WordPress email. Your SES or ours.</text>
  </g>

  <g font-family="JetBrains Mono" font-size="19" fill="${SMOKE}" text-anchor="end">
    <text x="1424" y="150">wp_mail()</text>
    <text x="1424" y="186" fill="${AMBER}">→ POST /v1/send</text>
    <text x="1424" y="222">200 · queued</text>
  </g>
</svg>`
}

async function main() {
	await mkdir(OUT, { recursive: true })

	await writeFile(path.join(OUT, "icon-128x128.png"), await icon(128))
	await writeFile(path.join(OUT, "icon-256x256.png"), await icon(256))
	await writeFile(path.join(OUT, "icon.svg"), await read("sendfleet-mark.svg"))

	const banner = await bannerSvg()
	await writeFile(path.join(OUT, "banner-1544x500.png"), await raster(banner, 1544, 500))
	await writeFile(path.join(OUT, "banner-772x250.png"), await raster(banner, 772, 250))

	console.log("Wrote icons and banners to .wordpress-org/")
}

main().catch((error) => {
	console.error(error)
	process.exit(1)
})
