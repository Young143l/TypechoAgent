const rawUrl = process.env.TYPECHO_URL || ''
const rawKey = process.env.TYPECHO_API_KEY || ''

if (!rawUrl || !rawKey) {
  console.warn('[TypechoCli] 请设置 TYPECHO_URL 和 TYPECHO_API_KEY 环境变量')
}

export const config = {
  url: rawUrl,
  apiKey: rawKey,
}
