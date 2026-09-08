#!/usr/bin/env node
/**
 * Typecho 博客管理技能 CLI
 *
 * 在博客仓库根目录执行（认证沿用 config.ts / TYPECHO_URL、TYPECHO_API_KEY 环境变量）：
 *
 *   tsx <skill目录>/scripts/cli.ts status
 *   tsx <skill目录>/scripts/cli.ts regen-index
 *   tsx <skill目录>/scripts/cli.ts create-post 文章/30.新文章.md
 *   tsx <skill目录>/scripts/cli.ts update-post 40
 *   tsx <skill目录>/scripts/cli.ts delete-post 40
 *   tsx <skill目录>/scripts/cli.ts comments
 *   tsx <skill目录>/scripts/cli.ts comment 23 approved
 *   tsx <skill目录>/scripts/cli.ts update-page 2
 *   tsx <skill目录>/scripts/cli.ts search 关键词
 */
import { existsSync, readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { join, basename } from 'node:path'
import { createClient } from '../client'
import { config } from '../config'

const blog = createClient({ url: config.url, apiKey: config.apiKey })

// 项目根目录 = 本文件 (<skill目录>/scripts/cli.ts) 上溯四级
const ROOT = process.cwd()
const POSTS_DIR = join(ROOT, '文章')
const PAGES_DIR = join(ROOT, '页面')
const INDEX_FILE = join(POSTS_DIR, '_index.md')

interface FrontMatter {
  title?: string
  date?: string
  cid?: number
  category?: string
  tags?: string[]
  [k: string]: unknown
}

/** 解析 YAML front matter（支持 key: value、列表项、引号、数字；空值如 `cid:` 解析为 null） */
export function parseFrontMatter(text: string): FrontMatter | null {
  const m = text.match(/^---\r?\n([\s\S]*?)\r?\n---\r?\n?/)
  if (!m) return null
  const fm: Record<string, unknown> = {}
  const lines = m[1].split(/\r?\n/)
  let key: string | null = null
  for (const raw of lines) {
    const line = raw.trim()
    if (!line || line.startsWith('#')) continue
    if (key !== null && /^-\s+/.test(line)) {
      // 列表项（如 tags 的 "- xxx" 形式）；若前一行是空值字段，先初始化为数组
      const arr = Array.isArray(fm[key]) ? (fm[key] as string[]) : []
      arr.push(line.replace(/^-\s+/, '').replace(/^['"]|['"]$/g, ''))
      fm[key] = arr
      continue
    }
    const kv = line.match(/^([\w-]+)\s*:\s*(.*)$/)
    if (!kv) { key = null; continue }
    key = kv[1]
    let v: string = kv[2].trim().replace(/^["']|["']$/g, '')
    if (v === '') { fm[key] = null; continue } // 空值（如 cid: 留空）为 null，不算已填写
    if (/^-?\d+(\.\d+)?$/.test(v)) { fm[key] = Number(v); continue }
    if (key === 'tags' && v.includes(',')) { fm[key] = v.split(',').map(s => s.trim()); continue }
    fm[key] = v
  }
  return fm as FrontMatter
}

/** 取出 front matter 之后的正文 */
function extractBody(text: string): string {
  const m = text.match(/^---\r?\n[\s\S]*?\r?\n---\r?\n?/)
  return m ? text.slice(m[0].length).trim() : text.trim()
}

/** 把 cid 写回本地文件 front matter（已有则替换，否则插入到 date 之后） */
function writeCid(file: string, cid: number) {
  const text = readFileSync(file, 'utf8')
  const m = text.match(/^---\r?\n([\s\S]*?)\r?\n---/)
  if (!m) throw new Error(`front matter 缺失: ${file}`)
  const fm = m[1]
  let next: string
  if (/^cid\s*:/m.test(fm)) {
    next = fm.replace(/^(cid\s*:\s*).*$/m, `$1${cid}`)
  } else {
    const dateLine = fm.match(/^date\s*:.*$/m)
    if (dateLine) {
      next = fm.replace(dateLine[0], dateLine[0] + `\ncid: ${cid}`)
    } else {
      next = fm.replace(/^title\s*:.*$/m, `$&\ncid: ${cid}`)
    }
  }
  writeFileSync(file, text.replace(m[1], next), 'utf8')
}

/** 解析文件参数：优先按原路径，其次按文章目录下文件名 */
function resolveFile(p: string): string {
  if (existsSync(p)) return p
  const inPosts = join(POSTS_DIR, p)
  if (existsSync(inPosts)) return inPosts
  throw new Error(`找不到文件: ${p}`)
}

/** 在 文章/ 目录下按 cid 查找本地文件 */
function findLocalPostByCid(cid: number): { file: string; text: string } | null {
  for (const f of readdirSync(POSTS_DIR)) {
    if (!f.endsWith('.md') || f === '_index.md') continue
    const file = join(POSTS_DIR, f)
    const text = readFileSync(file, 'utf8')
    const fm = parseFrontMatter(text)
    if (fm?.cid === cid) return { file, text }
  }
  return null
}

/** 在 页面/ 目录下按 cid 查找本地文件 */
function findLocalPageByCid(cid: number): { file: string; text: string } | null {
  if (!existsSync(PAGES_DIR)) return null
  for (const f of readdirSync(PAGES_DIR)) {
    if (!f.endsWith('.md')) continue
    const file = join(PAGES_DIR, f)
    const text = readFileSync(file, 'utf8')
    const fm = parseFrontMatter(text)
    if (fm?.cid === cid) return { file, text }
  }
  return null
}

/** 分类名 → mid（实时查询线上，避免硬编码失效） */
async function categoryMid(name: string): Promise<number> {
  const cats = await blog.getCategories()
  const cat = cats.find(c => c.name === name)
  if (!cat) throw new Error(`分类「${name}」不存在，可用: ${cats.map(c => c.name).join(', ')}`)
  return cat.mid
}

// ── status ──────────────────────────────────────────────

async function cmdStatus() {
  const ping = await blog.ping()
  const stats = await blog.stats()
  const cats = await blog.getCategories()
  const comments = await blog.listComments(1, 100)
  const pages = await blog.listPages(1, 20, 'meta')
  const users = await blog.listUsers()
  const media = await blog.listMedia(1, 1)

  console.log('=== 连通性 ===')
  console.log(`ping: ${ping.message}`)
  console.log('\n=== 总体统计 ===')
  console.log(`文章 ${stats.posts} 篇 | 页面 ${stats.pages} 个 | 分类 ${stats.categories} | 标签 ${stats.tags}`)
  const c = stats.comments
  console.log(`评论 共 ${c.total} 条（通过 ${c.approved} / 待审 ${c.waiting} / 垃圾 ${c.spam}）`)
  console.log(`媒体 ${media.total} 个 | 用户: ${users.map(u => u.screenName || u.name).join(', ')}`)
  console.log(`最新文章: ${stats.latestPost ? `《${stats.latestPost.title}》` : '无'}`)

  console.log('\n=== 分类分布 ===')
  cats.forEach(x => console.log(`  ${x.name}: ${x.count} 篇`))

  console.log('\n=== 待审核评论 ===')
  const waiting = comments.data.filter(x => x.status === 'waiting')
  if (waiting.length === 0) console.log('  无')
  waiting.forEach(x => console.log(`  #${x.coid} ${x.author}: ${x.text.slice(0, 50)}`))

  console.log('\n=== 页面 ===')
  pages.data.forEach(p => console.log(`  ${p.title} (cid=${p.cid})`))
}

// ── regen-index ─────────────────────────────────────────

async function cmdRegenIndex() {
  const posts: { file: string; fm: FrontMatter }[] = []
  for (const f of readdirSync(POSTS_DIR)) {
    if (!f.endsWith('.md') || f === '_index.md') continue
    const fm = parseFrontMatter(readFileSync(join(POSTS_DIR, f), 'utf8'))
    if (fm) posts.push({ file: f, fm })
  }
  if (posts.length === 0) throw new Error(`未在 ${POSTS_DIR} 找到任何文章`)

  // 分类顺序：优先按线上 mid 升序，API 不可用时按本地首次出现顺序
  let order: string[] = []
  try {
    const cats = await blog.getCategories()
    order = [...cats].sort((a, b) => a.mid - b.mid).map(c => c.name)
  } catch {
    order = [...new Set(posts.map(p => p.fm.category ?? '未分类'))]
  }
  for (const p of posts) {
    const cat = p.fm.category ?? '未分类'
    if (!order.includes(cat)) order.push(cat)
  }

  const byCat = new Map<string, typeof posts>()
  for (const p of posts) {
    const cat = p.fm.category ?? '未分类'
    if (!byCat.has(cat)) byCat.set(cat, [])
    byCat.get(cat)!.push(p)
  }

  const sortByDate = (a: { fm: FrontMatter; file: string }, b: { fm: FrontMatter; file: string }) => {
    const da = a.fm.date ?? ''
    const db = b.fm.date ?? ''
    return da < db ? 1 : da > db ? -1 : a.file.localeCompare(b.file)
  }

  const today = new Date().toISOString().slice(0, 10)
  const lines: string[] = ['# 博客文章索引', '', `最后更新: ${today} · 共 ${posts.length} 篇`, '']
  for (const cat of order) {
    const list = (byCat.get(cat) ?? []).sort(sortByDate)
    if (list.length === 0) continue
    lines.push(`## ${cat}（${list.length}篇）`, '')
    for (const p of list) {
      const tags = Array.isArray(p.fm.tags) && p.fm.tags.length
        ? ' ' + p.fm.tags.map(t => `\`${t}\``).join(', ')
        : ''
      lines.push(`- [${p.fm.title ?? ''}](./${encodeURI(p.file)})${tags}`)
    }
    lines.push('')
  }

  const out = lines.join('\n').replace(/\n+$/, '\n')
  writeFileSync(INDEX_FILE, out, 'utf8')
  console.log(`已重建 ${INDEX_FILE}（共 ${posts.length} 篇）`)
  for (const cat of order) {
    const n = byCat.get(cat)?.length ?? 0
    if (n > 0) console.log(`  ${cat}: ${n} 篇`)
  }
}

// ── create-post ─────────────────────────────────────────

async function cmdCreatePost(args: string[]) {
  const fileArg = args[0]
  const isDraft = args.includes('--draft')
  if (!fileArg) throw new Error('用法: create-post <本地md文件> [--draft]')

  const file = resolveFile(fileArg)
  const text = readFileSync(file, 'utf8')
  const fm = parseFrontMatter(text)
  if (!fm) throw new Error(`${file} 缺少 YAML front matter`)
  if (fm.cid != null) throw new Error(`该文章已发布（cid=${fm.cid}），如需修改请用 update-post ${fm.cid}`)

  const title = String(fm.title ?? '')
  if (!title) throw new Error('front matter 缺少 title')
  const body = extractBody(text)
  const categoryName = String(fm.category ?? '')
  const mid = await categoryMid(categoryName)
  const tags = Array.isArray(fm.tags) ? fm.tags.map(String) : []

  const { cid } = await blog.createPost({
    title,
    text: body,
    format: 'markdown',
    status: isDraft ? 'draft' : 'publish',
    categoryIds: [mid],
    tags: tags.join(','),
  })
  writeCid(file, cid)
  console.log(`已${isDraft ? '存为草稿' : '发布'}《${title}》(cid=${cid})，并回写 cid 到 ${file}`)
  console.log('提示: 运行 regen-index 重建索引')
}

// ── update-post ─────────────────────────────────────────

async function cmdUpdatePost(args: string[]) {
  const cid = Number(args[0])
  if (!Number.isInteger(cid) || cid <= 0) throw new Error('用法: update-post <cid>')

  const local = findLocalPostByCid(cid)
  if (!local) throw new Error(`本地 ${POSTS_DIR} 中未找到 cid=${cid} 的文章文件`)
  const fm = parseFrontMatter(local.text)
  if (!fm) throw new Error(`${local.file} 缺少 YAML front matter`)

  await blog.updatePost(cid, {
    title: String(fm.title ?? ''),
    text: extractBody(local.text),
    format: 'markdown',
  })
  console.log(`已更新《${fm.title}》(cid=${cid})，来源 ${basename(local.file)}`)
  console.log('提示: 运行 regen-index 重建索引')
}

// ── delete-post ─────────────────────────────────────────

async function cmdDeletePost(args: string[]) {
  const cid = Number(args[0])
  if (!Number.isInteger(cid) || cid <= 0) throw new Error('用法: delete-post <cid>')

  const local = findLocalPostByCid(cid)
  await blog.deletePost(cid)
  console.log(`已删除线上文章 cid=${cid}`)
  if (local) console.log(`提示: 本地文件 ${basename(local.file)} 请手动删除，再运行 regen-index 重建索引`)
  else console.log('提示: 运行 regen-index 重建索引')
}

// ── comments ────────────────────────────────────────────

async function cmdComments(args: string[]) {
  const statusArg = args.find(a => a.startsWith('--status='))?.split('=')[1]
  const status = statusArg && ['approved', 'waiting', 'spam'].includes(statusArg) ? statusArg : undefined
  const res = await blog.listComments(1, 100, undefined, status)
  console.log(`评论共 ${res.total} 条${status ? `（过滤: ${status}）` : ''}`)
  for (const c of res.data) {
    const date = new Date(c.created * 1000).toLocaleString('zh-CN', { timeZone: 'Asia/Shanghai' })
    console.log(`#${c.coid} [${c.status}] ${c.author} ${date}`)
    console.log(`   ${c.text.replace(/\s+/g, ' ').slice(0, 60)}`)
  }
  if (!status) {
    const waiting = res.data.filter(c => c.status === 'waiting')
    if (waiting.length > 0) console.log(`\n待审核 ${waiting.length} 条，可用: comment <coid> approved|spam`)
  }
}

// ── comment ─────────────────────────────────────────────

async function cmdComment(args: string[]) {
  const coid = Number(args[0])
  const status = args[1]
  if (!Number.isInteger(coid) || coid <= 0 || !['approved', 'waiting', 'spam'].includes(status ?? '')) {
    throw new Error('用法: comment <coid> <approved|waiting|spam>')
  }
  await blog.editComment(coid, status as 'approved' | 'waiting' | 'spam')
  console.log(`评论 #${coid} 已标记为 ${status}`)
}

// ── update-page ─────────────────────────────────────────

async function cmdUpdatePage(args: string[]) {
  const cid = Number(args[0])
  if (!Number.isInteger(cid) || cid <= 0) throw new Error('用法: update-page <cid>')

  const local = findLocalPageByCid(cid)
  if (!local) throw new Error(`本地 ${PAGES_DIR} 中未找到 cid=${cid} 的页面文件`)
  const fm = parseFrontMatter(local.text)
  if (!fm) throw new Error(`${local.file} 缺少 YAML front matter`)

  await blog.updatePage(cid, {
    text: extractBody(local.text),
    format: 'markdown',
  })
  console.log(`已更新页面《${fm.title}》(cid=${cid})，来源 ${basename(local.file)}`)
}

// ── search ──────────────────────────────────────────────

async function cmdSearch(args: string[]) {
  const kw = args[0]
  if (!kw) throw new Error('用法: search <关键词>')
  const res = await blog.searchPosts(kw, 1, 20, 'summary')
  console.log(`搜索「${kw}」共 ${res.total} 条:`)
  for (const p of res.data) {
    console.log(`- cid=${p.cid}《${p.title}》 [${p.status}]`)
  }
}

// ── 入口 ────────────────────────────────────────────────

const usage = `Typecho 博客管理技能 CLI

用法:
  tsx <skill目录>/scripts/cli.ts <命令> [参数]

命令:
  status                        博客状态概览（连通性/统计/分类/待审评论/页面）
  regen-index                   重建 文章/_index.md（按分类分组、日期倒序）
  create-post <md文件> [--draft]  发布新文章（自动回写 cid 到本地 front matter）
  update-post <cid>             用本地对应文件同步更新线上文章
  delete-post <cid>             删除线上文章
  comments [--status=approved|waiting|spam]   列出评论
  comment <coid> <approved|waiting|spam>      评论审核
  update-page <cid>             用本地 页面/ 对应文件同步更新页面
  search <关键词>               搜索文章
  help                          显示本帮助
`

async function main() {
  const [cmd, ...args] = process.argv.slice(2)
  switch (cmd) {
    case 'status': await cmdStatus(); break
    case 'regen-index': await cmdRegenIndex(); break
    case 'create-post': await cmdCreatePost(args); break
    case 'update-post': await cmdUpdatePost(args); break
    case 'delete-post': await cmdDeletePost(args); break
    case 'comments': await cmdComments(args); break
    case 'comment': await cmdComment(args); break
    case 'update-page': await cmdUpdatePage(args); break
    case 'search': await cmdSearch(args); break
    case 'help': case '--help': case '-h': case undefined:
      console.log(usage); break
    default:
      console.error(`未知命令: ${cmd}\n`)
      console.log(usage)
      process.exit(1)
  }
}

main().catch(e => {
  console.error('出错:', e instanceof Error ? e.message : e)
  process.exit(1)
})
