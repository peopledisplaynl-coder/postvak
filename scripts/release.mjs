#!/usr/bin/env node
/**
 * Builds the installable release zip: dist/postvak-<version>.zip
 *
 *   postvak-<version>/
 *     public/   -> upload into the web root of the (sub)domain (includes install.php)
 *     app/      -> upload next to it, outside the web root (code, vendor, database)
 *     README.md, LICENSE, CHANGELOG.md, INSTALL.md, INSTALL.nl.md
 *
 * Needs: backend/vendor installed with --no-dev, and the frontend built (this script runs the build
 * unless --skip-build is given). No npm dependencies: the zip is written with node:zlib.
 */
import { execSync } from 'node:child_process'
import { existsSync, mkdirSync, readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'
import { deflateRawSync } from 'node:zlib'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const backend = join(root, 'backend')
const args = new Set(process.argv.slice(2))
const version = JSON.parse(readFileSync(join(root, 'frontend', 'package.json'), 'utf8')).version

const apiVersion = /POSTVAK_VERSION = '([^']+)'/.exec(readFileSync(join(backend, 'bootstrap.php'), 'utf8'))?.[1]
if (apiVersion !== version) {
  console.error(`Version mismatch: frontend/package.json is ${version}, POSTVAK_VERSION is ${apiVersion}.`)
  process.exit(1)
}
if (!existsSync(join(backend, 'vendor', 'autoload.php'))) {
  console.error('backend/vendor is missing. Run: composer install --no-dev --optimize-autoloader --working-dir=backend')
  process.exit(1)
}
if (existsSync(join(backend, 'vendor', 'phpunit')) || existsSync(join(backend, 'vendor', 'bin', 'phpunit'))) {
  console.error('backend/vendor contains dev packages. Run composer install with --no-dev first.')
  process.exit(1)
}
// The zip promises PHP 8.2: refuse a vendor folder that was resolved for a newer PHP.
const platformCheck = join(backend, 'vendor', 'composer', 'platform_check.php')
const minPhp = existsSync(platformCheck) ? Number(/PHP_VERSION_ID >= (\d+)/.exec(readFileSync(platformCheck, 'utf8'))?.[1] ?? 0) : 0
if (minPhp > 80200) {
  console.error(`backend/vendor requires PHP ${Math.floor(minPhp / 10000)}.${Math.floor(minPhp / 100) % 100}; the release must run on PHP 8.2. Install with PHP 8.2 (or keep "platform": {"php": "8.2.0"} in composer.json).`)
  process.exit(1)
}
if (!args.has('--skip-build')) {
  execSync('npm run build --prefix frontend', { cwd: root, stdio: 'inherit' })
}
if (!existsSync(join(backend, 'public', 'index.html'))) {
  console.error('The frontend build is missing (backend/public/index.html).')
  process.exit(1)
}

// Same filter as deploy.mjs: library tests, docs and tooling are not needed on the server.
const VENDOR_SKIP = /(^|\/)(tests?|docs?|examples?|\.github|\.git|bin)(\/|$)|\.(md|dist|yml|yaml|neon|xml)$|(^|\/)(LICENSE|CHANGELOG|UPGRADE|CONTRIBUTING|phpunit|phpstan|psalm)[^/]*$/i

/** @type {Array<{name: string, data: Buffer}>} */
const entries = []
const base = `postvak-${version}`
const add = (name, data) => entries.push({ name: `${base}/${name}`, data: Buffer.isBuffer(data) ? data : Buffer.from(data) })

function addDir(localDir, target, skip = () => false) {
  for (const name of readdirSync(localDir).sort()) {
    const local = join(localDir, name)
    const rel = relative(localDir, local).replaceAll('\\', '/')
    if (skip(rel, local)) continue
    if (statSync(local).isDirectory()) addDir(local, `${target}/${name}`, (r, l) => skip(`${rel}/${r}`, l))
    else add(`${target}/${name}`, readFileSync(local))
  }
}

// public/: the built SPA, .htaccess, the API entry point, the installer.
addDir(join(backend, 'public'), 'public', (rel) => rel === 'api/app-path.php')
add('public/api/app-path.php', "<?php\n// Where the application folder is, relative to this file. Change it if you put \"app\" somewhere else.\nreturn __DIR__ . '/../../app';\n")
add('public/install.php', readFileSync(join(backend, 'installer', 'install.php')))

// app/: everything the API needs, without tests, dev tools or local config.
for (const f of ['bootstrap.php', 'routes.php', 'cron.php', '.htaccess', 'composer.json', 'composer.lock']) {
  if (existsSync(join(backend, f))) add(`app/${f}`, readFileSync(join(backend, f)))
}
addDir(join(backend, 'src'), 'app/src')
addDir(join(backend, 'database'), 'app/database')
addDir(join(backend, 'vendor'), 'app/vendor', (rel) => VENDOR_SKIP.test(rel))
add('app/config/config.example.php', readFileSync(join(backend, 'config', 'config.example.php')))
add('app/config/.htaccess', readFileSync(join(backend, '.htaccess')))
add('app/storage/.htaccess', readFileSync(join(backend, 'storage', '.htaccess')))
add('app/storage/cache/.gitkeep', '')

for (const [from, to] of [['README.md', 'README.md'], ['LICENSE', 'LICENSE'], ['CHANGELOG.md', 'CHANGELOG.md'], ['docs/INSTALL.md', 'INSTALL.md'], ['docs/INSTALL.nl.md', 'INSTALL.nl.md']]) {
  if (existsSync(join(root, from))) add(to, readFileSync(join(root, from)))
}

// ------------------------------------------------------------------ zip writer
const CRC_TABLE = Array.from({ length: 256 }, (_, n) => {
  let c = n
  for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1
  return c >>> 0
})
function crc32(buf) {
  let c = 0xffffffff
  for (const b of buf) c = CRC_TABLE[(c ^ b) & 0xff] ^ (c >>> 8)
  return (c ^ 0xffffffff) >>> 0
}
function dosTime(d) {
  return {
    time: (d.getHours() << 11) | (d.getMinutes() << 5) | Math.floor(d.getSeconds() / 2),
    date: ((d.getFullYear() - 1980) << 9) | ((d.getMonth() + 1) << 5) | d.getDate(),
  }
}

export function writeZip(files) {
  const { time, date } = dosTime(new Date())
  const chunks = []
  const central = []
  let offset = 0
  for (const { name, data } of files) {
    const nameBuf = Buffer.from(name, 'utf8')
    const deflated = deflateRawSync(data, { level: 9 })
    const stored = deflated.length >= data.length
    const body = stored ? data : deflated
    const crc = crc32(data)

    const local = Buffer.alloc(30)
    local.writeUInt32LE(0x04034b50, 0)
    local.writeUInt16LE(20, 4)
    local.writeUInt16LE(0x0800, 6) // UTF-8 names
    local.writeUInt16LE(stored ? 0 : 8, 8)
    local.writeUInt16LE(time, 10)
    local.writeUInt16LE(date, 12)
    local.writeUInt32LE(crc, 14)
    local.writeUInt32LE(body.length, 18)
    local.writeUInt32LE(data.length, 22)
    local.writeUInt16LE(nameBuf.length, 26)
    local.writeUInt16LE(0, 28)
    chunks.push(local, nameBuf, body)

    const cen = Buffer.alloc(46)
    cen.writeUInt32LE(0x02014b50, 0)
    cen.writeUInt16LE(0x0314, 4) // made by: Unix, so permissions below are honoured
    cen.writeUInt16LE(20, 6)
    cen.writeUInt16LE(0x0800, 8)
    cen.writeUInt16LE(stored ? 0 : 8, 10)
    cen.writeUInt16LE(time, 12)
    cen.writeUInt16LE(date, 14)
    cen.writeUInt32LE(crc, 16)
    cen.writeUInt32LE(body.length, 20)
    cen.writeUInt32LE(data.length, 24)
    cen.writeUInt16LE(nameBuf.length, 28)
    cen.writeUInt32LE(((0o100644) << 16) >>> 0, 38)
    cen.writeUInt32LE(offset, 42)
    central.push(cen, nameBuf)

    offset += local.length + nameBuf.length + body.length
  }
  const centralBuf = Buffer.concat(central)
  const end = Buffer.alloc(22)
  end.writeUInt32LE(0x06054b50, 0)
  end.writeUInt16LE(files.length, 8)
  end.writeUInt16LE(files.length, 10)
  end.writeUInt32LE(centralBuf.length, 12)
  end.writeUInt32LE(offset, 16)
  return Buffer.concat([...chunks, centralBuf, end])
}

if (entries.length > 65535) {
  console.error(`Too many files for a plain zip (${entries.length}).`)
  process.exit(1)
}
const zip = writeZip(entries)
mkdirSync(join(root, 'dist'), { recursive: true })
const out = join(root, 'dist', `${base}.zip`)
writeFileSync(out, zip)
console.log(`${relative(root, out)}: ${entries.length} files, ${(zip.length / 1024 / 1024).toFixed(1)} MB`)
