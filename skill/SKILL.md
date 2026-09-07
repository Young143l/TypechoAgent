---
name: typecho-agent
description: Use when managing a Typecho blog via the TypechoAgent plugin (JSON API). Create/edit/delete posts, pages, manage comments, categories, tags, media, view stats and users.
---

# TypechoAgent

AI 管理 Typecho 博客的 JSON API 插件。

## 安装

```
usr/plugins/TypechoAgent/
├── Plugin.php      # 激活
└── Action.php      # API 处理器
```

1. 上传 `usr/plugins/TypechoAgent/` 到服务器
2. 后台 → 控制台 → 插件 → 激活 TypechoAgent
3. 设置 API Key

> ⚠ **安全警告：必须使用 HTTPS**  
> API Key 在每次请求中以明文传输，未使用 HTTPS 时可能被中间人窃取。  
> 请确保博客已配置有效的 SSL 证书，并使用 `https://` 访问端点。

## 端点

```
POST /action/ta
Content-Type: application/json
```

## 认证

除 `ping`、`setApiKey` 外，所有请求在 `params` 中传 `api_key`。

初始设置：
```json
{ "action": "setApiKey", "params": { "password": "管理员密码", "api_key": "你的key" } }
```

## 配置（技能侧）

技能通过 `config.ts` 或环境变量读取博客地址与 API Key：

```bash
export TYPECHO_URL="https://your-blog.com"
export TYPECHO_API_KEY="你的key"
```

> ⚠ **安全警告：不要把真实 API Key 提交到任何公开仓库**。
> config.ts 中的 fallback 应留空，真实 Key 通过环境变量注入。

## fields 参数

`listPosts`、`searchPosts`、`listPages` 支持 `fields` 参数控制返回字段：

| 值 | 说明 |
|------|------|
| `full` (默认) | 完整内容（含 text、categories、tags） |
| `summary` | 不含 text，含 excerpt（前 200 字摘要）|
| `meta` | 仅基本信息（cid/title/slug/status/created/author），不含正文和分类标签 |

## 响应格式

```json
{ "success": true, "data": [...], "total": 26, "page": 1, "pageSize": 10 }
```

## 错误响应

```json
{ "error": true, "message": "错误描述" }
```

HTTP 状态码：400（参数错误）、401（鉴权失败）、404（资源/操作不存在）、405（方法不允许）、500（服务器错误）。

## 操作参考

### 系统

| 操作 | 参数 | 说明 |
|------|------|------|
| `ping` | — | 连通性测试 |
| `setApiKey` | password, api_key | 设置 API Key |
| `stats` | — | 博客统计概览 |

### 文章

| 操作 | 参数 | 说明 |
|------|------|------|
| `listPosts` | page?, pageSize?, fields?, status?, categoryId?, tagId?, authorId? | 分页列表（支持过滤） |
| `getPost` | id | 详情（含分类/标签/作者） |
| `createPost` | title, text?, format?, status?, categoryIds?, tags?, slug?, created? | 新建 |
| `updatePost` | id, title?, text?, format?, status?, categoryIds?, tags?, created? | 更新 |
| `deletePost` | id | 删除 |
| `searchPosts` | keyword, page?, pageSize?, fields? | 按标题/内容搜索 |

### 评论

| 操作 | 参数 | 说明 |
|------|------|------|
| `listComments` | page?, pageSize?, postId?, status? | 分页列表 |
| `getComment` | id | 详情 |
| `editComment` | id, status | 审核：`approved` / `waiting` / `spam` |
| `updateComment` | id, text?, author?, mail?, url? | 编辑正文/作者信息 |
| `deleteComment` | id | 删除 |

### 分类

| 操作 | 参数 | 说明 |
|------|------|------|
| `getCategories` | — | 所有分类 |
| `setPostCategories` | postId, categoryIds[] | 设置文章分类 |
| `createCategory` | name, slug? | 新建分类 |
| `deleteCategory` | mid | 删除分类（需无子分类） |

### 标签

| 操作 | 参数 | 说明 |
|------|------|------|
| `getTags` | — | 所有标签 |
| `createTag` | name, slug? | 新建标签 |
| `deleteTag` | mid | 删除标签 |

### 页面

| 操作 | 参数 | 说明 |
|------|------|------|
| `listPages` | page?, pageSize?, fields? | 分页列表 |
| `getPage` | id | 详情 |
| `createPage` | title, text?, format?, status?, slug?, created? | 新建 |
| `updatePage` | id, title?, text?, format?, status?, created? | 更新 |
| `deletePage` | id | 删除 |

### 用户 & 媒体

| 操作 | 参数 | 说明 |
|------|------|------|
| `listUsers` | — | 所有用户/作者 |
| `listMedia` | page?, pageSize? | 附件/媒体列表 |

## SDK

`client.ts` 除供 CLI 内部使用外，也可独立用作 TypeScript SDK（23 个方法，类型安全），直接调用 `/action/ta` 的全部操作。方法签名见文件内注释。

## CLI 用法

skill 自带命令行工具（`scripts/cli.ts`，需 Node 18+ 与全局 `tsx`），覆盖日常操作的常用命令：

```bash
tsx skill/scripts/cli.ts <命令> [参数]
```

| 命令 | 说明 |
|------|------|
| `status` | 博客状态概览：连通性、文章/页面/评论统计、分类分布、待审核评论、页面列表 |
| `create-post <md文件> [--draft]` | 发布新文章。读取本地 Markdown 的 front matter（title/date/category/tags）→ 创建 → **自动回写 cid** |
| `update-post <cid>` | 用本地 md 文件同步更新线上文章 |
| `delete-post <cid>` | 删除线上文章 |
| `comments [--status=approved\|waiting\|spam]` | 列出评论（默认全部，可过滤） |
| `comment <coid> <approved\|waiting\|spam>` | 评论审核 |
| `update-page <cid>` | 用本地 md 文件同步更新页面 |
| `search <关键词>` | 按标题/内容搜索文章 |
| `regen-index` | 扫描本地 Markdown 文章目录重建索引（可选：配合"本地 md 文章目录 + cid 回写"的工作流使用） |
| `help` | 显示帮助 |

### 示例

```bash
# 查看博客状况
tsx skill/scripts/cli.ts status

# 发布新文章（front matter 含 title/date/category/tags，发布后 cid 自动回写）
tsx skill/scripts/cli.ts create-post 新文章.md

# 更新文章（先改本地文件，再同步）
tsx skill/scripts/cli.ts update-post 40

# 评论审核
tsx skill/scripts/cli.ts comment 23 approved

# 搜索
tsx skill/scripts/cli.ts search typecho
```

> `create-post` / `update-post` / `regen-index` 依赖"本地 Markdown 文章目录"约定（目录名可在 `scripts/cli.ts` 顶部的 `POSTS_DIR` / `PAGES_DIR` 中修改），按 cid 与本地文件双向同步。
