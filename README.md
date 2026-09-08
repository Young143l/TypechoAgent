# TypechoCli

![License](https://img.shields.io/badge/License-GPL%202.0-blue)
![Typecho](https://img.shields.io/badge/Typecho-1.2%2B-brightgreen)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)

命令行管理 Typecho 博客：**TypechoCli 插件**（JSON API 服务端）+ **CLI**（命令行工具）。

## 工作原理

插件在博客上注册一个 `POST /action/tc` 的 JSON API 端点，CLI 直连它完成发文、改稿、评论审核等全部操作——**不 SSH、不进后台**，配合本地 Markdown 文章目录即可管理整站内容。

## 特性

- **安全认证** — API Key + 管理员密码双重鉴权
- **28 个 API 操作** — 覆盖文章、页面、评论、分类、标签、用户、媒体全功能
- **CLI** — 状态总览、发文自动回写 cid、评论一键审核、全文搜索
- **兼容 Typecho 1.2+** — PHP 7.4+，支持 MySQL / SQLite / PostgreSQL
- **零依赖** — CLI 无任何运行时依赖，Node 18+ 即可
- **事务可靠** — 事务内读写统一走写连接，SQLite 下无死锁（[见兼容性说明](#数据库兼容性说明)）

## 结构

```
TypechoCli/
├── plugin/              # Typecho 插件（API 服务端）
│   ├── Plugin.php       # 激活，注册 /action/tc 路由
│   └── Action.php       # JSON API 端点（28 个操作）
├── cli/                 # CLI（npm 包根）
│   ├── scripts/
│   │   └── cli.ts       # CLI 源码
│   ├── client.ts        # API 客户端（可选 TS SDK）
│   ├── config.ts        # 环境变量注入
│   └── package.json
├── skill/
│   └── SKILL.md         # 完整操作参考（agent 说明书）
├── LICENSE
└── README.md
```

## 前提要求

- Typecho 1.2+，PHP 7.4+
- MySQL / SQLite / PostgreSQL
- SSL 证书（[见安全说明](#安全说明)）
- 使用 CLI：Node 18+

## 快速开始

### 1. 安装插件

```bash
cp -r plugin/* /path/to/usr/plugins/TypechoCli/
```

后台 **控制台 → 插件** → 激活 TypechoCli。

### 2. 设置 API Key

```bash
curl -X POST https://your-blog.com/action/tc \
  -H "Content-Type: application/json" \
  -d '{"action":"setApiKey","params":{"password":"管理员密码","api_key":"你的key"}}'
```

> `password` 是 **Typecho 后台登录密码**（users 表 administrator 用户的密码，多管理员时仅验证 uid 最小的那个），用于验证操作者身份；密码错误次数过多会触发 1 小时限制。`api_key` 填你要设置的新 Key（用于替换旧 Key）。

### 3. 使用 CLI

**方式 A：npm 安装（推荐）**

```bash
npm i -g typecho-cli

export TYPECHO_URL="https://your-blog.com"
export TYPECHO_API_KEY="你的key"

typecho-cli status
```

> API Key 只通过环境变量注入，**不要提交到任何公开仓库**。

**方式 B：从源码运行**

```bash
git clone https://github.com/Young143l/TypechoCli.git
cd TypechoCli && npm i && npm run build -w cli

export TYPECHO_URL="https://your-blog.com"
export TYPECHO_API_KEY="你的key"

tsx cli/scripts/cli.ts status
```

## CLI 命令

在文章仓库根目录执行：

```bash
typecho-cli <命令> [参数]          # npm 全局安装
tsx cli/scripts/cli.ts <命令> [参数]  # 源码方式
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
| `regen-index` | 扫描本地 Markdown 文章目录重建索引 |
| `help` | 显示帮助 |

### 示例

```bash
# 查看博客状况
typecho-cli status

# 发布新文章（front matter 含 title/date/category/tags，发布后 cid 自动回写）
typecho-cli create-post 新文章.md

# 更新文章（先改本地文件，再同步）
typecho-cli update-post 40

# 评论审核
typecho-cli comment 23 approved

# 搜索
typecho-cli search typecho
```

## 本地 Markdown 工作流（可选）

CLI 的 `create-post` / `update-post` / `regen-index` 支持一种「本地为源」的写作流：

```
你的仓库/
├── 文章/          # {编号}.{标题}.md，front matter 含 cid（发布后自动回写）
│   └── _index.md  # regen-index 自动生成的索引
└── 页面/          # about.md、links.md 等
```

- `create-post` 发布后把线上 cid 写回本地文件，此后 `update-post <cid>` 以本地文件为准同步
- 目录名可在 `cli/scripts/cli.ts` 顶部的 `POSTS_DIR` / `PAGES_DIR` 中修改

## API 操作参考

CLI 底层的全部 API 操作（也可通过 `client.ts` 直接调用）：

| 类别 | 操作 |
|------|------|
| 系统 | `ping` `setApiKey` `stats` |
| 文章 | `listPosts` `getPost` `createPost` `updatePost` `deletePost` `searchPosts` |
| 评论 | `listComments` `getComment` `editComment` `updateComment` `deleteComment` |
| 分类 | `getCategories` `setPostCategories` `createCategory` `deleteCategory` |
| 标签 | `getTags` `createTag` `deleteTag` |
| 页面 | `listPages` `getPage` `createPage` `updatePage` `deletePage` |
| 用户/媒体 | `listUsers` `listMedia` |

请求/响应格式与参数详见 [`skill/SKILL.md`](skill/SKILL.md)。

## 数据库兼容性说明

- **MySQL / PostgreSQL**：无特殊处理，按 Typecho 标准配置即可。
- **SQLite**：完全支持。两点注意：
  1. **Typecho 1.2/1.3 的连接池机制**：即使只配置单个数据库，`READ` 和 `WRITE` 连接也是两个独立的连接。所有事务类操作（createPost/updatePost/deletePost/createPage/updatePage/deletePage）内部已统一在写连接上执行，避免 SQLite 下"读连接持锁 ↔ 写连接等锁"的自死锁（2026-09 修复）。事务内读写同连接也保证了 metas 计数等数据的一致性。
  2. **文件权限**：SQLite 需要对 `.db` 文件**及其所在目录**的写权限（写入时会在同目录创建 journal 临时文件）；同时在 nginx/openresty 中建议拒绝 `.db` 文件的 HTTP 直读（`location ~* \.db$ { deny all; }`）。

## 安全说明

> ⚠ **必须使用 HTTPS**
>
> API Key 在每次请求中以明文传输，未使用 HTTPS 时可能被中间人窃取。
> 请确保博客已配置有效的 SSL 证书，并使用 `https://` 访问端点。

## 开发

```bash
# 类型检查
cd cli && npm i && npm run typecheck

# 构建（发布前 prepack 会自动执行）
npm run build

# 运行 CLI（开发）
tsx cli/scripts/cli.ts status
```

## 相关链接

- [作者博客](https://www.young143.top)
- [GitHub Issues](https://github.com/Young143l/TypechoCli/issues)
- [npm 包](https://www.npmjs.com/package/typecho-cli)
- [Typecho 官网](https://typecho.org)

## 证书

TypechoCli 基于 [GNU General Public License v2.0](LICENSE) 发布。
