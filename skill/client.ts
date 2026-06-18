export interface ClientOptions {
  url: string
  apiKey: string
}

export interface Paginated<T> {
  data: T[]
  total: number
  page: number
  pageSize: number
}

const FETCH_TIMEOUT = 30000

export function createClient(options: ClientOptions) {
  const { url, apiKey } = options
  const baseUrl = url.replace(/\/+$/, '') + '/action/ta'

  async function call<T = unknown>(action: string, params: Record<string, unknown> = {}): Promise<T> {
    const noAuth = action === 'ping' || action === 'setApiKey'
    const body = noAuth
      ? JSON.stringify({ action, params })
      : JSON.stringify({ action, params: { ...params, api_key: apiKey } })

    const controller = new AbortController()
    const timer = setTimeout(() => controller.abort(), FETCH_TIMEOUT)

    let res: Response
    try {
      res = await fetch(baseUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body,
        signal: controller.signal,
      })
    } finally {
      clearTimeout(timer)
    }

    if (!res.ok) {
      let msg = `HTTP ${res.status}`
      try {
        const j = await res.json()
        msg = j.message || msg
      } catch { /* ignore */ }
      throw new Error(msg)
    }

    const text = await res.text()
    let json: any
    try {
      json = JSON.parse(text)
    } catch {
      throw new Error(`非 JSON 响应: ${text.slice(0, 120)}`)
    }

    if (json.error || !json.success) {
      throw new Error(json.message || 'API Error')
    }

    if ('total' in json) {
      return {
        data: json.data ?? [],
        total: json.total,
        page: json.page,
        pageSize: json.pageSize,
      } as T
    }

    return json.data ?? json as T
  }

  return {

    // ── System ────────────────────────────────────────

    ping(): Promise<{ message: string }> {
      return call('ping')
    },

    setApiKey(password: string, newKey: string): Promise<void> {
      return call('setApiKey', { password, api_key: newKey })
    },

    stats(): Promise<StatsData> {
      return call<StatsData>('stats')
    },

    // ── Posts ─────────────────────────────────────────

    listPosts(page = 1, pageSize = 10, fields?: 'full' | 'summary' | 'meta', filters?: {
      status?: string; categoryId?: number; tagId?: number; authorId?: number
    }): Promise<Paginated<PostData>> {
      return call<Paginated<PostData>>('listPosts', {
        page, pageSize, fields, ...filters,
      })
    },

    getPost(id: number): Promise<PostData> {
      return call<PostData>('getPost', { id })
    },

    createPost(data: {
      title: string; text?: string; format?: 'html' | 'markdown'; status?: string;
      categoryIds?: number[]; tags?: string; slug?: string; created?: number
    }): Promise<{ cid: number }> {
      return call<{ cid: number }>('createPost', data)
    },

    updatePost(id: number, data: Record<string, unknown>): Promise<void> {
      return call('updatePost', { id, ...data })
    },

    deletePost(id: number): Promise<void> {
      return call('deletePost', { id })
    },

    searchPosts(keyword: string, page = 1, pageSize = 10, fields?: 'full' | 'summary' | 'meta'): Promise<Paginated<PostData>> {
      return call<Paginated<PostData>>('searchPosts', { keyword, page, pageSize, fields })
    },

    // ── Comments ──────────────────────────────────────

    listComments(page = 1, pageSize = 10, postId?: number, status?: string): Promise<Paginated<CommentData>> {
      return call<Paginated<CommentData>>('listComments', { page, pageSize, postId, status })
    },

    getComment(id: number): Promise<CommentData> {
      return call<CommentData>('getComment', { id })
    },

    editComment(id: number, status: 'approved' | 'waiting' | 'spam'): Promise<void> {
      return call('editComment', { id, status })
    },

    deleteComment(id: number): Promise<void> {
      return call('deleteComment', { id })
    },

    updateComment(id: number, data: { text?: string; author?: string; mail?: string; url?: string }): Promise<void> {
      return call('updateComment', { id, ...data })
    },

    // ── Categories ────────────────────────────────────

    getCategories(): Promise<MetaData[]> {
      return call<MetaData[]>('getCategories')
    },

    setPostCategories(postId: number, categoryIds: number[]): Promise<void> {
      return call('setPostCategories', { postId, categoryIds })
    },

    createCategory(name: string, slug?: string): Promise<{ mid: number }> {
      return call<{ mid: number }>('createCategory', { name, slug })
    },

    deleteCategory(mid: number): Promise<void> {
      return call('deleteCategory', { mid })
    },

    // ── Tags ──────────────────────────────────────────

    getTags(): Promise<MetaData[]> {
      return call<MetaData[]>('getTags')
    },

    createTag(name: string, slug?: string): Promise<{ mid: number }> {
      return call<{ mid: number }>('createTag', { name, slug })
    },

    deleteTag(mid: number): Promise<void> {
      return call('deleteTag', { mid })
    },

    // ── Pages ─────────────────────────────────────────

    listPages(page = 1, pageSize = 10, fields?: 'full' | 'summary' | 'meta'): Promise<Paginated<PostData>> {
      return call<Paginated<PostData>>('listPages', { page, pageSize, fields })
    },

    getPage(id: number): Promise<PostData> {
      return call<PostData>('getPage', { id })
    },

    createPage(data: {
      title: string; text?: string; format?: 'html' | 'markdown'; status?: string;
      slug?: string; created?: number; password?: string;
      allowComment?: number; allowPing?: number; allowFeed?: number;
    }): Promise<{ cid: number }> {
      return call<{ cid: number }>('createPage', data)
    },

    updatePage(id: number, data: Record<string, unknown>): Promise<void> {
      return call('updatePage', { id, ...data })
    },

    deletePage(id: number): Promise<void> {
      return call('deletePage', { id })
    },

    // ── Users & Media ─────────────────────────────────

    listUsers(): Promise<UserData[]> {
      return call<UserData[]>('listUsers')
    },

    listMedia(page = 1, pageSize = 10): Promise<Paginated<MediaData>> {
      return call<Paginated<MediaData>>('listMedia', { page, pageSize })
    },
  }
}

export interface PostData {
  cid: number; title: string; slug: string; text?: string
  status: string; created: number; modified: number
  commentsNum: number; allowComment: number; allowPing: number; allowFeed: number
  author: { uid: number; name: string; screenName: string } | null
  categories: { mid: number; name: string; slug: string }[]
  tags: { mid: number; name: string; slug: string }[]
  excerpt?: string
}

export interface CommentData {
  coid: number; cid: number; created: number
  author: string; authorId: number; mail: string; url: string
  text: string; status: string; parent: number
}

export interface MetaData {
  mid: number; name: string; slug: string; count: number; order: number
}

export interface UserData {
  uid: number; name: string; screenName: string; mail: string
  group: string; url: string; activated: number; logged: number
}

export interface MediaData {
  cid: number; title: string; slug: string
  created: number; modified: number
  authorId: number; parent: number
  file: unknown
}

export interface StatsData {
  posts: number; pages: number
  comments: { total: number; approved: number; waiting: number; spam: number }
  categories: number; tags: number
  latestPost: { cid: number; title: string; created: number } | null
}
