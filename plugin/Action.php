<?php

namespace TypechoPlugin\TypechoCli;

use Typecho\Common;
use Typecho\Db;
use Widget\ActionInterface;
use Typecho\Widget;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Action extends Widget implements ActionInterface
{
    private const CONFIG_KEY = 'plugin:TypechoCli';
    private const VALID_STATUSES = ['publish', 'draft', 'private', 'hidden', 'waiting'];

    public function execute()
    {
    }

    public function action()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->jsonExit(['error' => true, 'message' => '仅支持 POST'], 405);
        }

        header('Content-Type: application/json; charset=UTF-8');

        try {
            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input) || !isset($input['action'])) {
                $this->jsonExit(['error' => true, 'message' => '缺少 action'], 400);
            }

            $action = $input['action'];
            $params = $input['params'] ?? [];

            if ($action === 'ping') {
                $this->jsonExit(['success' => true, 'message' => 'pong']);
            }

            if ($action === 'setApiKey') {
                $this->jsonExit($this->handleSetApiKey($params));
            }

            $db = Db::get();
            if (!$this->checkApiKey($db, $params['api_key'] ?? '')) {
                $this->jsonExit(['error' => true, 'message' => 'API Key 无效'], 401);
            }

            switch ($action) {
                case 'listPosts':         $r = $this->listPosts($db, $params); break;
                case 'getPost':           $r = $this->getPost($db, $params); break;
                case 'createPost':        $r = $this->createPost($db, $params); break;
                case 'updatePost':        $r = $this->updatePost($db, $params); break;
                case 'deletePost':        $r = $this->deletePost($db, $params); break;
                case 'searchPosts':       $r = $this->searchPosts($db, $params); break;
                case 'listComments':      $r = $this->listComments($db, $params); break;
                case 'getComment':        $r = $this->getComment($db, $params); break;
                case 'editComment':       $r = $this->editComment($db, $params); break;
                case 'deleteComment':     $r = $this->deleteComment($db, $params); break;
                case 'updateComment':     $r = $this->updateComment($db, $params); break;
                case 'getCategories':     $r = $this->getCategories($db); break;
                case 'setPostCategories': $r = $this->setPostCategories($db, $params); break;
                case 'createCategory':    $r = $this->createCategory($db, $params); break;
                case 'deleteCategory':    $r = $this->deleteMeta($db, $params); break;
                case 'getTags':           $r = $this->getTags($db); break;
                case 'createTag':         $r = $this->createTag($db, $params); break;
                case 'deleteTag':         $r = $this->deleteMeta($db, $params); break;
                case 'listPages':         $r = $this->listPages($db, $params); break;
                case 'getPage':           $r = $this->getPage($db, $params); break;
                case 'createPage':        $r = $this->createPage($db, $params); break;
                case 'updatePage':        $r = $this->updatePage($db, $params); break;
                case 'deletePage':        $r = $this->deletePage($db, $params); break;
                case 'listUsers':         $r = $this->listUsers($db); break;
                case 'listMedia':         $r = $this->listMedia($db, $params); break;
                case 'stats':             $r = $this->stats($db); break;
                default:                  $r = ['error' => true, 'message' => '未知操作: ' . $action, '_code' => 404];
            }

            $code = $r['_code'] ?? (($r['error'] ?? false) ? 400 : 200);
            unset($r['_code']);
            $this->jsonExit($r, $code);
        } catch (\Exception $e) {
            $this->jsonExit(['error' => true, 'message' => $e->getMessage()], 500);
        }
    }

    private function jsonExit(array $data, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function handleSetApiKey(array $p): array
    {
        $password = $p['password'] ?? '';
        $newKey   = $p['api_key'] ?? '';
        if (!$password || !$newKey) return ['error' => true, 'message' => '需要 password 和 api_key'];
        if (strlen($newKey) < 8) return ['error' => true, 'message' => 'API Key 至少 8 位'];

        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        $ip = trim(explode(',', $xff)[0]) ?: ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if ($rl = $this->checkRateLimit($ip)) {
            return $rl;
        }

        $db = Db::get();
        $admin = $db->fetchRow(
            $db->select()->from('table.users')
                ->where('group = ?', 'administrator')->limit(1)
        );
        if (!$admin) return ['error' => true, 'message' => '未找到管理员'];

        $valid = false;
        if (class_exists('\PasswordHash')) {
            $hasher = new \PasswordHash(8, true);
            $valid = $hasher->CheckPassword($password, $admin['password']);
        }
        if (!$valid && function_exists('password_verify')) {
            $valid = password_verify($password, $admin['password']);
        }
        if (!$valid) {
            $this->recordRateLimit($ip);
            return ['error' => true, 'message' => '管理员密码错误'];
        }

        $config = serialize(['api_key' => $newKey]);
        $exists = $db->fetchRow(
            $db->select()->from('table.options')
                ->where('name = ?', self::CONFIG_KEY)->limit(1)
        );

        if ($exists) {
            $db->query($db->update('table.options')->rows(['value' => $config])
                ->where('name = ?', self::CONFIG_KEY));
        } else {
            $db->query($db->insert('table.options')
                ->rows(['name' => self::CONFIG_KEY, 'user' => 0, 'value' => $config]));
        }

        $this->clearRateLimit($ip);
        return ['success' => true, 'message' => 'API Key 设置成功'];
    }

    private function checkRateLimit(string $ip): ?array
    {
        $file = sys_get_temp_dir() . '/ta_rl_' . md5($ip);
        if (!file_exists($file)) return null;
        $data = @unserialize(file_get_contents($file));
        if (!is_array($data)) return null;
        if (time() - $data['first'] > 3600) {
            @unlink($file);
            return null;
        }
        if ($data['count'] >= 5) {
            return ['error' => true, 'message' => '尝试次数过多，请 1 小时后再试'];
        }
        return null;
    }

    private function recordRateLimit(string $ip): void
    {
        $file = sys_get_temp_dir() . '/ta_rl_' . md5($ip);
        $data = ['count' => 0, 'first' => time()];
        if (file_exists($file)) {
            $old = @unserialize(file_get_contents($file));
            if (is_array($old)) $data = $old;
        }
        $data['count']++;
        $data['first'] = $data['first'] ?: time();
        file_put_contents($file, serialize($data), LOCK_EX);
    }

    private function clearRateLimit(string $ip): void
    {
        $file = sys_get_temp_dir() . '/ta_rl_' . md5($ip);
        @unlink($file);
    }

    /**
     * 事务内统一走写连接（WRITE 池）：SQLite 下 READ/WRITE 是两个独立连接，
     * READ 连接读不到 WRITE 连接未提交的事务数据，也会与写锁互等造成死锁
     */
    private function fetchAllW(Db $db, $query): array
    {
        return $db->fetchAll($db->query($query->prepare($query), Db::WRITE));
    }

    private function fetchRowW(Db $db, $query): ?array
    {
        return $db->fetchRow($db->query($query->prepare($query), Db::WRITE));
    }

    private function fetchObjectW(Db $db, $query): ?\stdClass
    {
        return $db->fetchObject($db->query($query->prepare($query), Db::WRITE));
    }

    private function checkApiKey(Db $db, string $key): bool
    {
        if (!$key) return false;
        $row = $db->fetchRow(
            $db->select()->from('table.options')
                ->where('name = ?', self::CONFIG_KEY)->limit(1)
        );
        if (!$row || empty($row['value'])) return false;
        $config = @unserialize($row['value']);
        if (!is_array($config)) $config = json_decode($row['value'], true) ?: [];
        if (empty($config['api_key'])) return false;
        return hash_equals($config['api_key'], $key);
    }

    private function buildPostListQuery(Db $db, array $p, bool $count = false)
    {
        if ($count) {
            $sel = $db->select(['COUNT(cid)' => 'num'])->from('table.contents');
        } else {
            $sel = $db->select()->from('table.contents');
        }
        $sel->where('type = ?', 'post');

        if (!empty($p['status'])) {
            $sel->where('status = ?', $p['status']);
        }
        if (!empty($p['authorId'])) {
            $sel->where('authorId = ?', (int)$p['authorId']);
        }

        if (!empty($p['categoryId'])) {
            $catCids = $db->fetchAll(
                $db->select('cid')->from('table.relationships')->where('mid = ?', (int)$p['categoryId'])
            );
            $catCids = array_map(fn($r) => (int)$r['cid'], $catCids);
            if (!empty($catCids)) {
                $phs = implode(',', array_fill(0, count($catCids), '?'));
                $sel->where("cid IN ({$phs})", ...$catCids);
            } else {
                $sel->where('cid = ?', 0);
            }
        }

        if (!empty($p['tagId'])) {
            $tagRows = $db->fetchAll(
                $db->select('cid')->from('table.relationships')->where('mid = ?', (int)$p['tagId'])
            );
            $tagCids = array_map(fn($r) => (int)$r['cid'], $tagRows);
            if (!empty($tagCids)) {
                $phs = implode(',', array_fill(0, count($tagCids), '?'));
                $sel->where("cid IN ({$phs})", ...$tagCids);
            } else {
                $sel->where('cid = ?', 0);
            }
        }

        return $sel;
    }

    private function listPosts(Db $db, array $p): array
    {
        $page = max(1, (int)($p['page'] ?? 1));
        $size = max(1, min(50, (int)($p['pageSize'] ?? 10)));
        $fields = $p['fields'] ?? 'full';
        if (!in_array($fields, ['full', 'summary', 'meta'])) $fields = 'full';

        $sel = $this->buildPostListQuery($db, $p);
        $sel->order('created', Db::SORT_DESC)->limit($size)->offset(($page - 1) * $size);

        $countSel = $this->buildPostListQuery($db, $p, true);

        return [
            'success'  => true,
            'data'     => $this->fmtPostsBatch($db, $db->fetchAll($sel), $fields),
            'total'    => (int)$db->fetchObject($countSel)->num,
            'page' => $page, 'pageSize' => $size,
        ];
    }

    private function getPost(Db $db, array $p): array
    {
        $id = (int)($p['id'] ?? 0);
        if (!$id) return ['error' => true, 'message' => '缺少 id'];
        $row = $db->fetchRow($db->select()->from('table.contents')->where('cid = ? AND type = ?', $id, 'post')->limit(1));
        return $row ? ['success' => true, 'data' => $this->fmtPost($db, $row)] : ['error' => true, 'message' => '文章不存在', '_code' => 404];
    }

    private function createPost(Db $db, array $p): array
    {
        $title = trim($p['title'] ?? '');
        if (!$title) return ['error' => true, 'message' => '标题不能为空'];

        $text = $this->ensureMarkdown($p['text'] ?? '', $p['format'] ?? null);
        $status = $p['status'] ?? 'publish';
        if (!in_array($status, self::VALID_STATUSES)) {
            return ['error' => true, 'message' => 'status 无效'];
        }

        $db->query('BEGIN', Db::WRITE);
        try {
            $cid = $db->query($db->insert('table.contents')->rows([
                'title' => $title, 'slug' => $p['slug'] ?? Common::slugName($title),
                'created' => isset($p['created']) ? (int)$p['created'] : time(), 'modified' => time(), 'text' => $text,
                'authorId' => (int)($p['authorId'] ?? $this->getAdminUid($db)), 'type' => 'post', 'status' => $status,
                'password' => $p['password'] ?? '',
                'allowComment' => (int)($p['allowComment'] ?? 1),
                'allowPing' => (int)($p['allowPing'] ?? 1),
                'allowFeed' => (int)($p['allowFeed'] ?? 1),
            ]));

            $this->syncCats($db, $cid, $p['categoryIds'] ?? []);
            $this->syncTags($db, $cid, $p['tags'] ?? '');
            $db->query('COMMIT', Db::WRITE);
            return ['success' => true, 'data' => ['cid' => $cid]];
        } catch (\Exception $e) {
            $db->query('ROLLBACK', Db::WRITE);
            return ['error' => true, 'message' => $e->getMessage()];
        }
    }

    private function updatePost(Db $db, array $p): array
    {
        $id = (int)($p['id'] ?? 0);
        if (!$id) return ['error' => true, 'message' => '缺少 id'];

        $existing = $db->fetchRow($db->select('cid')->from('table.contents')->where('cid = ? AND type = ?', $id, 'post')->limit(1));
        if (!$existing) return ['error' => true, 'message' => '文章不存在', '_code' => 404];

        $up = [];
        foreach (['title', 'slug', 'text', 'status', 'password', 'created'] as $f) {
            if (isset($p[$f])) {
                $val = $p[$f];
                if ($f === 'text') {
                    $val = $this->ensureMarkdown($val, $p['format'] ?? null);
                }
                if ($f === 'status' && !in_array($val, self::VALID_STATUSES)) {
                    return ['error' => true, 'message' => 'status 无效'];
                }
                $up[$f] = $val;
            }
        }
        foreach (['allowComment', 'allowPing', 'allowFeed'] as $f) {
            if (isset($p[$f])) $up[$f] = (int)$p[$f];
        }
        $db->query('BEGIN', Db::WRITE);
        try {
            if ($up) { $up['modified'] = time(); $db->query($db->update('table.contents')->rows($up)->where('cid = ?', $id)); }
            if (isset($p['categoryIds'])) $this->syncCats($db, $id, $p['categoryIds']);
            if (isset($p['tags'])) $this->syncTags($db, $id, $p['tags']);
            $db->query('COMMIT', Db::WRITE);
            return ['success' => true];
        } catch (\Exception $e) {
            $db->query('ROLLBACK', Db::WRITE);
            return ['error' => true, 'message' => $e->getMessage()];
        }
    }

    private function deletePost(Db $db, array $p): array
    {
        $id = (int)($p['id'] ?? 0);
        if (!$id) return ['error' => true, 'message' => '缺少 id'];

        $db->query('BEGIN', Db::WRITE);
        try {
            $mids = $this->fetchAllW($db,
                $db->select('mid')->from('table.relationships')->where('cid = ?', $id)
            );
            $affected = array_map(fn($r) => (int)$r['mid'], $mids);

            foreach (['table.relationships', 'table.comments', 'table.fields', 'table.contents'] as $t) {
                $db->query($db->delete($t)->where('cid = ?', $id));
            }

            $this->recountMetas($db, $affected);
            $db->query('COMMIT', Db::WRITE);
            return ['success' => true];
        } catch (\Exception $e) {
            $db->query('ROLLBACK', Db::WRITE);
            return ['error' => true, 'message' => $e->getMessage()];
        }
    }

    private function searchPosts(Db $db, array $p): array
    {
        $keyword = trim($p['keyword'] ?? '');
        if (!$keyword) return ['error' => true, 'message' => '缺少 keyword'];

        $page = max(1, (int)($p['page'] ?? 1));
        $size = max(1, min(50, (int)($p['pageSize'] ?? 10)));
        $fields = $p['fields'] ?? 'full';
        if (!in_array($fields, ['full', 'summary', 'meta'])) $fields = 'full';

        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $keyword);
        $like = '%' . $escaped . '%';

        $rows = $db->fetchAll(
            $db->select()->from('table.contents')
                ->where('type = ? AND (title LIKE ? OR text LIKE ?)', 'post', $like, $like)
                ->order('created', Db::SORT_DESC)
                ->limit($size)->offset(($page - 1) * $size)
        );

        $count = (int)$db->fetchObject(
            $db->select(['COUNT(cid)' => 'num'])->from('table.contents')
                ->where('type = ? AND (title LIKE ? OR text LIKE ?)', 'post', $like, $like)
        )->num;

        return [
            'success' => true,
            'data'    => $this->fmtPostsBatch($db, $rows, $fields),
            'total'   => $count, 'page' => $page, 'pageSize' => $size,
        ];
    }

    private function listComments(Db $db, array $p): array
    {
        $page = max(1, (int)($p['page'] ?? 1));
        $size = max(1, min(50, (int)($p['pageSize'] ?? 10)));
        $sel = $db->select()->from('table.comments')->order('created', Db::SORT_DESC)
            ->limit($size)->offset(($page - 1) * $size);
        $countSel = $db->select(['COUNT(coid)' => 'num'])->from('table.comments');

        if ($pid = (int)($p['postId'] ?? 0)) {
            $sel->where('cid = ?', $pid);
            $countSel->where('cid = ?', $pid);
        }
        if (!empty($p['status'])) {
            $sel->where('status = ?', $p['status']);
            $countSel->where('status = ?', $p['status']);
        }

        return [
            'success' => true,
            'data'    => array_map(fn($r) => $this->fmtComment($r), $db->fetchAll($sel)),
            'total'   => (int)$db->fetchObject($countSel)->num,
            'page' => $page, 'pageSize' => $size,
        ];
    }

    private function getComment(Db $db, array $p): array
    {
        $id = (int)($p['id'] ?? 0);
        if (!$id) return ['error' => true, 'message' => '缺少 id'];
        $row = $db->fetchRow($db->select()->from('table.comments')->where('coid = ?', $id)->limit(1));
        return $row ? ['success' => true, 'data' => $this->fmtComment($row)] : ['error' => true, 'message' => '评论不存在', '_code' => 404];
    }

    private function deleteComment(Db $db, array $p): array
    {
        $id = (int)($p['id'] ?? 0);
        if (!$id) return ['error' => true, 'message' => '缺少 id'];
        $c = $db->fetchRow($db->select('cid', 'status')->from('table.comments')->where('coid = ?', $id)->limit(1));
        $db->query($db->delete('table.comments')->where('coid = ?', $id));
        if ($c && $c['status'] === 'approved') {
            $db->query($db->update('table.contents')->expression('commentsNum', 'commentsNum - 1')->where('cid = ?', $c['cid']));
        }
        return ['success' => true];
    }

    private function editComment(Db $db, array $p): array
    {
        $coid = (int)($p['coid'] ?? $p['id'] ?? 0);
        $status = $p['status'] ?? '';
        if (!$coid || !$status) return ['error' => true, 'message' => '需要 coid 和 status'];
        if (!in_array($status, ['approved', 'waiting', 'spam'])) {
            return ['error' => true, 'message' => 'status 必须为 approved/waiting/spam'];
        }

        $old = $db->fetchRow($db->select('cid', 'status')->from('table.comments')->where('coid = ?', $coid)->limit(1));
        if (!$old) return ['error' => true, 'message' => '评论不存在', '_code' => 404];

        $db->query($db->update('table.comments')->rows(['status' => $status])->where('coid = ?', $coid));

        if ($old['status'] === 'approved' && $status !== 'approved') {
            $db->query($db->update('table.contents')->expression('commentsNum', 'commentsNum - 1')->where('cid = ?', $old['cid']));
        } elseif ($old['status'] !== 'approved' && $status === 'approved') {
            $db->query($db->update('table.contents')->expression('commentsNum', 'commentsNum + 1')->where('cid = ?', $old['cid']));
        }

        return ['success' => true];
    }

    private function updateComment(Db $db, array $p): array
    {
        $coid = (int)($p['coid'] ?? $p['id'] ?? 0);
        if (!$coid) return ['error' => true, 'message' => '缺少 coid'];

        $row = $db->fetchRow($db->select('coid')->from('table.comments')->where('coid = ?', $coid)->limit(1));
        if (!$row) return ['error' => true, 'message' => '评论不存在', '_code' => 404];

        $up = [];
        foreach (['text', 'author', 'mail', 'url'] as $f) {
            if (isset($p[$f])) $up[$f] = $p[$f];
        }

        if (empty($up)) return ['error' => true, 'message' => '没有需要更新的字段'];

        $db->query($db->update('table.comments')->rows($up)->where('coid = ?', $coid));
        return ['success' => true];
    }

    private function getCategories(Db $db): array
    {
        return ['success' => true, 'data' => array_map(fn($r) => $this->fmtMeta($r),
            $db->fetchAll($db->select()->from('table.metas')->where('type = ?', 'category')->order('order', Db::SORT_ASC))
        )];
    }

    private function setPostCategories(Db $db, array $p): array
    {
        $pid = (int)($p['postId'] ?? 0);
        if (!$pid) return ['error' => true, 'message' => '缺少 postId'];
        $this->syncCats($db, $pid, $p['categoryIds'] ?? []);
        return ['success' => true];
    }

    private function getTags(Db $db): array
    {
        return ['success' => true, 'data' => array_map(fn($r) => $this->fmtMeta($r),
            $db->fetchAll($db->select()->from('table.metas')->where('type = ?', 'tag')->order('count', Db::SORT_DESC))
        )];
    }

    private function createCategory(Db $db, array $p): array
    {
        return $this->createMeta($db, 'category', $p);
    }

    private function createTag(Db $db, array $p): array
    {
        return $this->createMeta($db, 'tag', $p);
    }

    private function deleteMeta(Db $db, array $p): array
    {
        $mid = (int)($p['mid'] ?? 0);
        if (!$mid) return ['error' => true, 'message' => '缺少 mid'];

        $row = $db->fetchRow($db->select('mid', 'type')->from('table.metas')->where('mid = ?', $mid)->limit(1));
        if (!$row) return ['error' => true, 'message' => '分类/标签不存在', '_code' => 404];

        if ($row['type'] === 'category') {
            $children = $db->fetchAll($db->select('mid')->from('table.metas')->where('parent = ?', $mid));
            if (!empty($children)) return ['error' => true, 'message' => '该分类下有子分类，无法删除'];
        }

        $db->query($db->delete('table.relationships')->where('mid = ?', $mid));
        $db->query($db->delete('table.metas')->where('mid = ?', $mid));
        $this->recountMetas($db, [$mid]);

        return ['success' => true];
    }

    private function listPages(Db $db, array $p): array
    {
        $page = max(1, (int)($p['page'] ?? 1));
        $size = max(1, min(50, (int)($p['pageSize'] ?? 10)));
        $fields = $p['fields'] ?? 'full';
        if (!in_array($fields, ['full', 'summary', 'meta'])) $fields = 'full';

        $rows = $db->fetchAll(
            $db->select()->from('table.contents')->where('type = ?', 'page')
                ->order('order', Db::SORT_ASC)->limit($size)->offset(($page - 1) * $size)
        );

        return [
            'success'  => true,
            'data'     => $this->fmtPostsBatch($db, $rows, $fields),
            'total'    => (int)$db->fetchObject(
                $db->select(['COUNT(cid)' => 'num'])->from('table.contents')->where('type = ?', 'page')
            )->num,
            'page' => $page, 'pageSize' => $size,
        ];
    }

    private function getPage(Db $db, array $p): array
    {
        $id = (int)($p['id'] ?? 0);
        if (!$id) return ['error' => true, 'message' => '缺少 id'];
        $row = $db->fetchRow($db->select()->from('table.contents')->where('cid = ? AND type = ?', $id, 'page')->limit(1));
        return $row ? ['success' => true, 'data' => $this->fmtPost($db, $row)] : ['error' => true, 'message' => '页面不存在', '_code' => 404];
    }

    private function createPage(Db $db, array $p): array
    {
        $title = trim($p['title'] ?? '');
        if (!$title) return ['error' => true, 'message' => '标题不能为空'];

        $text = $this->ensureMarkdown($p['text'] ?? '', $p['format'] ?? null);
        $status = $p['status'] ?? 'publish';
        if (!in_array($status, self::VALID_STATUSES)) {
            return ['error' => true, 'message' => 'status 无效'];
        }

        $db->query('BEGIN', Db::WRITE);
        try {
            $now = isset($p['created']) ? (int)$p['created'] : time();
            $cid = $db->query($db->insert('table.contents')->rows([
                'title' => $title, 'slug' => $p['slug'] ?? Common::slugName($title),
                'created' => $now, 'modified' => $now, 'text' => $text,
                'authorId' => (int)($p['authorId'] ?? $this->getAdminUid($db)), 'type' => 'page', 'status' => $status,
                'password' => $p['password'] ?? '',
                'allowComment' => (int)($p['allowComment'] ?? 0),
                'allowPing' => (int)($p['allowPing'] ?? 0),
                'allowFeed' => (int)($p['allowFeed'] ?? 0),
            ]));
            $db->query('COMMIT', Db::WRITE);
            return ['success' => true, 'data' => ['cid' => $cid]];
        } catch (\Exception $e) {
            $db->query('ROLLBACK', Db::WRITE);
            return ['error' => true, 'message' => $e->getMessage()];
        }
    }

    private function updatePage(Db $db, array $p): array
    {
        $id = (int)($p['id'] ?? 0);
        if (!$id) return ['error' => true, 'message' => '缺少 id'];

        $existing = $db->fetchRow($db->select('cid')->from('table.contents')->where('cid = ? AND type = ?', $id, 'page')->limit(1));
        if (!$existing) return ['error' => true, 'message' => '页面不存在', '_code' => 404];

        $up = [];
        foreach (['title', 'slug', 'text', 'status', 'password', 'created'] as $f) {
            if (isset($p[$f])) {
                $val = $p[$f];
                if ($f === 'text') {
                    $val = $this->ensureMarkdown($val, $p['format'] ?? null);
                }
                if ($f === 'status' && !in_array($val, self::VALID_STATUSES)) {
                    return ['error' => true, 'message' => 'status 无效'];
                }
                $up[$f] = $val;
            }
        }
        foreach (['allowComment', 'allowPing', 'allowFeed'] as $f) {
            if (isset($p[$f])) $up[$f] = (int)$p[$f];
        }

        $db->query('BEGIN', Db::WRITE);
        try {
            if ($up) {
                $up['modified'] = time();
                $db->query($db->update('table.contents')->rows($up)->where('cid = ?', $id));
            }
            $db->query('COMMIT', Db::WRITE);
            return ['success' => true];
        } catch (\Exception $e) {
            $db->query('ROLLBACK', Db::WRITE);
            return ['error' => true, 'message' => $e->getMessage()];
        }
    }

    private function deletePage(Db $db, array $p): array
    {
        $id = (int)($p['id'] ?? 0);
        if (!$id) return ['error' => true, 'message' => '缺少 id'];

        $db->query('BEGIN', Db::WRITE);
        try {
            $mids = $this->fetchAllW($db,
                $db->select('mid')->from('table.relationships')->where('cid = ?', $id)
            );
            $affected = array_map(fn($r) => (int)$r['mid'], $mids);

            foreach (['table.relationships', 'table.comments', 'table.fields', 'table.contents'] as $t) {
                $db->query($db->delete($t)->where('cid = ?', $id));
            }

            $this->recountMetas($db, $affected);
            $db->query('COMMIT', Db::WRITE);
            return ['success' => true];
        } catch (\Exception $e) {
            $db->query('ROLLBACK', Db::WRITE);
            return ['error' => true, 'message' => $e->getMessage()];
        }
    }

    private function listUsers(Db $db): array
    {
        $rows = $db->fetchAll(
            $db->select('uid', 'name', 'screenName', 'mail', 'url', 'group', 'activated', 'logged')
                ->from('table.users')->order('uid', Db::SORT_ASC)
        );
        return ['success' => true, 'data' => array_map(fn($r) => [
            'uid' => (int)$r['uid'], 'name' => $r['name'],
            'screenName' => $r['screenName'], 'mail' => $r['mail'],
            'url' => $r['url'], 'group' => $r['group'],
            'activated' => (int)$r['activated'], 'logged' => (int)$r['logged'],
        ], $rows)];
    }

    private function listMedia(Db $db, array $p): array
    {
        $page = max(1, (int)($p['page'] ?? 1));
        $size = max(1, min(50, (int)($p['pageSize'] ?? 10)));
        $rows = $db->fetchAll(
            $db->select('cid', 'title', 'slug', 'text', 'created', 'modified', 'authorId', 'parent')
                ->from('table.contents')
                ->where('type = ?', 'attachment')
                ->order('created', Db::SORT_DESC)
                ->limit($size)->offset(($page - 1) * $size)
        );
        $total = (int)$db->fetchObject(
            $db->select(['COUNT(cid)' => 'num'])->from('table.contents')->where('type = ?', 'attachment')
        )->num;

        return [
            'success' => true,
            'data' => array_map(fn($r) => [
                'cid' => (int)$r['cid'],
                'title' => $r['title'],
                'slug' => $r['slug'],
                'created' => (int)$r['created'],
                'modified' => (int)$r['modified'],
                'authorId' => (int)$r['authorId'],
                'parent' => (int)$r['parent'],
                'file' => @unserialize($r['text']),
            ], $rows),
            'total' => $total, 'page' => $page, 'pageSize' => $size,
        ];
    }

    private function stats(Db $db): array
    {
        $posts = (int)$db->fetchObject(
            $db->select(['COUNT(cid)' => 'num'])->from('table.contents')->where('type = ?', 'post')
        )->num;
        $pages = (int)$db->fetchObject(
            $db->select(['COUNT(cid)' => 'num'])->from('table.contents')->where('type = ?', 'page')
        )->num;
        $comments = (int)$db->fetchObject(
            $db->select(['COUNT(coid)' => 'num'])->from('table.comments')
        )->num;
        $approved = (int)$db->fetchObject(
            $db->select(['COUNT(coid)' => 'num'])->from('table.comments')->where('status = ?', 'approved')
        )->num;
        $waiting = (int)$db->fetchObject(
            $db->select(['COUNT(coid)' => 'num'])->from('table.comments')->where('status = ?', 'waiting')
        )->num;
        $spam = (int)$db->fetchObject(
            $db->select(['COUNT(coid)' => 'num'])->from('table.comments')->where('status = ?', 'spam')
        )->num;
        $categories = (int)$db->fetchObject(
            $db->select(['COUNT(mid)' => 'num'])->from('table.metas')->where('type = ?', 'category')
        )->num;
        $tags = (int)$db->fetchObject(
            $db->select(['COUNT(mid)' => 'num'])->from('table.metas')->where('type = ?', 'tag')
        )->num;

        $latest = $db->fetchRow(
            $db->select('cid', 'title', 'created')->from('table.contents')
                ->where('type = ?', 'post')->order('created', Db::SORT_DESC)->limit(1)
        );

        return ['success' => true, 'data' => [
            'posts' => $posts, 'pages' => $pages,
            'comments' => ['total' => $comments, 'approved' => $approved, 'waiting' => $waiting, 'spam' => $spam],
            'categories' => $categories, 'tags' => $tags,
            'latestPost' => $latest ? ['cid' => (int)$latest['cid'], 'title' => $latest['title'], 'created' => (int)$latest['created']] : null,
        ]];
    }

    private function fmtPost(Db $db, array $r): array
    {
        $cid = (int)$r['cid'];
        return [
            'cid' => $cid, 'title' => $r['title'], 'slug' => $r['slug'], 'text' => $r['text'],
            'status' => $r['status'], 'created' => (int)$r['created'], 'modified' => (int)$r['modified'],
            'commentsNum' => (int)$r['commentsNum'],
            'allowComment' => (int)$r['allowComment'], 'allowPing' => (int)$r['allowPing'], 'allowFeed' => (int)$r['allowFeed'],
            'author' => ($a = $db->fetchRow($db->select('uid', 'name', 'screenName')->from('table.users')
                ->where('uid = ?', $r['authorId'])->limit(1))) ? ['uid' => (int)$a['uid'], 'name' => $a['name'], 'screenName' => $a['screenName']] : null,
            'categories' => array_map(fn($c) => ['mid' => (int)$c['mid'], 'name' => $c['name'], 'slug' => $c['slug']],
                $db->fetchAll($db->select('m.mid', 'm.name', 'm.slug')->from('table.metas m')
                    ->join('table.relationships r', 'r.mid = m.mid')->where('r.cid = ? AND m.type = ?', $cid, 'category'))),
            'tags' => array_map(fn($t) => ['mid' => (int)$t['mid'], 'name' => $t['name'], 'slug' => $t['slug']],
                $db->fetchAll($db->select('m.mid', 'm.name', 'm.slug')->from('table.metas m')
                    ->join('table.relationships r', 'r.mid = m.mid')->where('r.cid = ? AND m.type = ?', $cid, 'tag'))),
        ];
    }

    private function fmtPostsBatch(Db $db, array $rows, string $fields): array
    {
        if (empty($rows)) return [];

        $cids = array_map(fn($r) => (int)$r['cid'], $rows);

        $uids = array_filter(array_unique(array_map(fn($r) => (int)$r['authorId'], $rows)));
        $authorRows = [];
        if (!empty($uids)) {
            $phs = implode(',', array_fill(0, count($uids), '?'));
            $authorRows = $db->fetchAll(
                $db->select('uid', 'name', 'screenName')->from('table.users')
                    ->where("uid IN ({$phs})", ...$uids)
            );
        }
        $authorMap = [];
        foreach ($authorRows as $a) {
            $authorMap[(int)$a['uid']] = ['uid' => (int)$a['uid'], 'name' => $a['name'], 'screenName' => $a['screenName']];
        }

        $cidsPhs = implode(',', array_fill(0, count($cids), '?'));
        $relRows = $db->fetchAll(
            $db->select('r.cid', 'm.mid', 'm.name', 'm.slug', 'm.type')
                ->from('table.metas m')
                ->join('table.relationships r', 'r.mid = m.mid')
                ->where("r.cid IN ({$cidsPhs})", ...$cids)
                ->where("m.type IN (?, ?)", 'category', 'tag')
        );
        $catsByCid = [];
        $tagsByCid = [];
        foreach ($relRows as $rel) {
            $cid = (int)$rel['cid'];
            $item = ['mid' => (int)$rel['mid'], 'name' => $rel['name'], 'slug' => $rel['slug']];
            if ($rel['type'] === 'category') {
                $catsByCid[$cid][] = $item;
            } else {
                $tagsByCid[$cid][] = $item;
            }
        }

        $result = [];
        foreach ($rows as $r) {
            $cid = (int)$r['cid'];
            $post = [
                'cid' => $cid, 'title' => $r['title'], 'slug' => $r['slug'],
                'status' => $r['status'], 'created' => (int)$r['created'], 'modified' => (int)$r['modified'],
                'commentsNum' => (int)$r['commentsNum'],
                'allowComment' => (int)$r['allowComment'], 'allowPing' => (int)$r['allowPing'], 'allowFeed' => (int)$r['allowFeed'],
                'author' => $authorMap[(int)$r['authorId']] ?? null,
            ];

            if ($fields === 'meta') {
                $result[] = $post;
                continue;
            }

            $post['text'] = $r['text'];
            $post['categories'] = $catsByCid[$cid] ?? [];
            $post['tags'] = $tagsByCid[$cid] ?? [];

            if ($fields === 'summary') {
                $excerptText = preg_replace('/^<!--markdown-->/', '', $r['text']);
                $post['excerpt'] = mb_substr(str_replace("\n", ' ', strip_tags($excerptText)), 0, 200);
                unset($post['text']);
            }

            $result[] = $post;
        }

        return $result;
    }

    private function fmtComment(array $r): array
    {
        return ['coid' => (int)$r['coid'], 'cid' => (int)$r['cid'], 'created' => (int)$r['created'],
            'author' => $r['author'], 'authorId' => (int)$r['authorId'], 'mail' => $r['mail'],
            'url' => $r['url'], 'text' => $r['text'], 'status' => $r['status'], 'parent' => (int)$r['parent']];
    }

    private function fmtMeta(array $r): array
    {
        return ['mid' => (int)$r['mid'], 'name' => $r['name'], 'slug' => $r['slug'],
            'count' => (int)$r['count'], 'order' => (int)$r['order']];
    }

    private function getAdminUid(Db $db): int
    {
        $admin = $this->fetchRowW($db,
            $db->select('uid')->from('table.users')
                ->where('group = ?', 'administrator')->limit(1)
        );
        return $admin ? (int)$admin['uid'] : 1;
    }

    private function ensureMarkdown(string $text, ?string $format): string
    {
        if ($format === 'markdown' && strpos($text, '<!--markdown-->') !== 0) {
            return '<!--markdown-->' . $text;
        }
        return $text;
    }

    private function createMeta(Db $db, string $type, array $p): array
    {
        $name = trim($p['name'] ?? '');
        if (!$name) return ['error' => true, 'message' => '名称不能为空'];
        $slug = $p['slug'] ?? Common::slugName($name);
        $row = $db->fetchRow($db->select('mid')->from('table.metas')
            ->where('type = ? AND (name = ? OR slug = ?)', $type, $name, $slug)->limit(1));
        if ($row) return ['error' => true, 'message' => '已存在同名 ' . $type];
        $mid = $db->query($db->insert('table.metas')->rows([
            'name' => $name, 'slug' => $slug, 'type' => $type, 'count' => 0, 'order' => 0,
        ]));
        return ['success' => true, 'data' => ['mid' => $mid]];
    }

    private function syncCats(Db $db, int $cid, array $ids): void
    {
        // 只处理 category 类型的关系，避免误删该文章的标签关系
        $oldRows = $this->fetchAllW($db,
            $db->select('r.mid')->from('table.relationships r')
                ->join('table.metas m', 'm.mid = r.mid')
                ->where('r.cid = ?', $cid)
                ->where('m.type = ?', 'category')
        );
        $oldMids = array_map(fn($r) => (int)$r['mid'], $oldRows);

        if (!empty($oldMids)) {
            $phs = implode(',', array_fill(0, count($oldMids), '?'));
            $db->query($db->delete('table.relationships')
                ->where('cid = ?', $cid)
                ->where("mid IN ({$phs})", ...$oldMids));
        }
        $newMids = [];
        foreach ($ids as $mid) {
            $mid = (int)$mid;
            if ($mid > 0) {
                $db->query($db->insert('table.relationships')->rows(['mid' => $mid, 'cid' => $cid]));
                $newMids[] = $mid;
            }
        }

        $this->recountMetas($db, array_unique([...$oldMids, ...$newMids]));
    }

    private function syncTags(Db $db, int $cid, string $tags): void
    {
        $oldRows = $this->fetchAllW($db,
            $db->select('r.mid')->from('table.relationships r')
                ->join('table.metas m', 'm.mid = r.mid')
                ->where('r.cid = ?', $cid)
                ->where('m.type = ?', 'tag')
        );
        $oldMids = array_map(fn($r) => (int)$r['mid'], $oldRows);

        if (!empty($oldMids)) {
            $phs = implode(',', array_fill(0, count($oldMids), '?'));
            $db->query($db->delete('table.relationships')
                ->where('cid = ?', $cid)
                ->where("mid IN ({$phs})", ...$oldMids));
        }

        $newMids = [];
        foreach (array_filter(array_unique(array_map('trim', explode(',', str_replace('，', ',', $tags))))) as $name) {
            $row = $this->fetchRowW($db, $db->select('mid')->from('table.metas')->where('type = ? AND name = ?', 'tag', $name)->limit(1));
            $mid = $row ? (int)$row['mid'] : $db->query($db->insert('table.metas')->rows([
                'name' => $name, 'slug' => Common::slugName($name), 'type' => 'tag', 'count' => 0,
            ]));
            $db->query($db->insert('table.relationships')->rows(['cid' => $cid, 'mid' => $mid]));
            $newMids[] = $mid;
        }

        $this->recountMetas($db, array_unique([...$oldMids, ...$newMids]));
    }

    private function recountMetas(Db $db, array $mids): void
    {
        if (empty($mids)) return;
        $mids = array_unique(array_map('intval', $mids));
        foreach ($mids as $mid) {
            $count = (int)$this->fetchObjectW($db,
                $db->select(['COUNT(*)' => 'num'])->from('table.relationships')->where('mid = ?', $mid)
            )->num;
            $db->query($db->update('table.metas')->rows(['count' => $count])->where('mid = ?', $mid));
        }
    }
}
