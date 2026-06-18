# TypechoAgent

![License](https://img.shields.io/badge/License-GPL%202.0-blue)
![Typecho](https://img.shields.io/badge/Typecho-1.2%2B-brightgreen)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)

AI 管理 Typecho 博客的完整方案。包含 Typecho 插件 + Agent Skill（TypeScript SDK）。

## 特性

- **安全认证** — API Key + 管理员密码双重鉴权
- **20+ API 操作** — 覆盖文章、页面、评论、分类、标签、用户、媒体全功能
- **TypeScript SDK** — 开箱即用的客户端，类型安全
- **兼容 Typecho 1.2+** — PHP 7.4+，支持 MySQL / SQLite

## 结构

```
TypechoAgent/
├── plugin/              # Typecho 插件
│   ├── Plugin.php       # 激活，注册 /action/ta 路由
│   └── Action.php       # JSON API 端点（20 个操作）
├── skill/               # Agent Skill + TypeScript SDK
│   ├── client.ts        # TypeScript 客户端
│   ├── config.ts        # 博客地址 + API Key
│   ├── SKILL.md         # 完整操作参考
│   └── examples/
│       └── manage-blog.ts
├── LICENSE
└── README.md
```

## 前提要求

- Typecho 1.2+
- PHP 7.4+
- MySQL 或 SQLite
- SSL 证书（[见安全说明](#安全说明)）

## 快速开始

### 1. 安装插件

```bash
cp -r plugin/* /path/to/usr/plugins/TypechoAgent/
```

后台 **控制台 → 插件** → 激活 TypechoAgent。

### 2. 设置 API Key

```bash
curl -X POST https://your-blog.com/action/ta \
  -H "Content-Type: application/json" \
  -d '{"action":"setApiKey","params":{"password":"管理员密码","api_key":"你的key"}}'
```

> 首次安装时 `password` 可留空（`""`）以跳过旧密码验证。

### 3. TypeScript SDK 用法

```typescript
import { createClient } from './skill/client'
import { config } from './skill/config'

const blog = createClient(config)

// 博客概览
const stat = await blog.stats()

// 文章管理
await blog.listPosts(1, 10, 'summary')
await blog.createPost({ title: '新文章', text: '内容', categoryIds: [19] })

// 评论审核
await blog.editComment(23, 'approved')

// 分类 & 标签
await blog.setPostCategories(1, [18])
await blog.createTag('TypeScript')
```

完整示例见 [`skill/examples/manage-blog.ts`](skill/examples/manage-blog.ts)。

## API 操作

| 类别 | 操作 | 说明 |
|------|------|------|
| 系统 | `ping` | 连通性测试 |
| | `setApiKey` | 设置 API Key |
| | `stats` | 博客统计概览 |
| 文章 | `listPosts` | 分页列表（支持 status/categoryId/tagId/authorId 过滤及 fields 参数） |
| | `getPost` | 详情（含分类/标签/作者） |
| | `createPost` | 新建 |
| | `updatePost` | 更新 |
| | `deletePost` | 删除 |
| | `searchPosts` | 按关键词搜索 |
| 评论 | `listComments` | 分页列表（支持按 status 和 postId 过滤） |
| | `getComment` | 详情 |
| | `editComment` | 审核（approved / waiting / spam） |
| | `updateComment` | 编辑正文/作者/邮箱/网址 |
| | `deleteComment` | 删除 |
| 分类 | `getCategories` | 所有分类 |
| | `setPostCategories` | 设置文章分类 |
| | `createCategory` | 新建分类 |
| | `deleteCategory` | 删除分类（需无子分类） |
| 标签 | `getTags` | 所有标签 |
| | `createTag` | 新建标签 |
| | `deleteTag` | 删除标签 |
| 页面 | `listPages` | 分页列表 |
| | `getPage` | 详情 |
| | `createPage` | 新建 |
| | `updatePage` | 更新 |
| | `deletePage` | 删除 |
| 用户 | `listUsers` | 所有用户/作者 |
| 媒体 | `listMedia` | 附件/媒体列表 |

完整的请求/响应格式及字段说明见 [`skill/SKILL.md`](skill/SKILL.md)。

## 安全说明

> ⚠ **必须使用 HTTPS**
>
> API Key 在每次请求中以明文传输，未使用 HTTPS 时可能被中间人窃取。
> 请确保博客已配置有效的 SSL 证书，并使用 `https://` 访问端点。

## 开发

```bash
# skill 目录为 TypeScript 项目
cd skill
bun install

# 类型检查
bun run tsc --noEmit
```

## 相关链接

- [作者博客](https://www.young143.top)
- [GitHub Issues](https://github.com/Young143/TypechoAgent/issues)
- [Typecho 官网](https://typecho.org)

## 证书

TypechoAgent 基于 [GNU General Public License v2.0](LICENSE) 发布。
