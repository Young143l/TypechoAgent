import { createClient } from '../client'
import { config } from '../config'

const blog = createClient(config)

// 博客概览
const stat = await blog.stats()
console.log(`文章 ${stat.posts} 篇，评论 ${stat.comments.total} 条`)

// 搜文章（返回 Paginated 对象）
const { data: posts, total } = await blog.searchPosts('typecho')
console.log(`找到 ${total} 篇:`)
for (const p of posts) console.log(`[${p.cid}] ${p.title}`)

// 只获取摘要列表
const { data: summaries } = await blog.listPosts(1, 10, 'summary')
for (const s of summaries) console.log(`${s.title} — ${s.excerpt}`)

// 审核评论
await blog.editComment(23, 'approved')
await blog.editComment(24, 'spam')

// 新建分类 & 标签
await blog.createCategory('技术')
await blog.createTag('TypeScript')

// 创建页面
await blog.createPage({ title: '关于', text: '# 关于我' })

// 设置分类（无 XMLRPC bug）
await blog.setPostCategories(1, [18])

// 查看用户
const users = await blog.listUsers()
for (const u of users) console.log(`${u.screenName} (${u.group})`)

// 删除无用分类
await blog.deleteCategory(99)
