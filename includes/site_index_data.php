<?php
/**
 * 轩宇-导航 · 公开站点索引数据（v2.18.3）
 *
 * 供两个 SEO 入口复用，保证"对外可见的站点集合"与首页完全一致：
 *   - sites.php          全部站点索引（HTML，给人看 + 给爬虫铺内链）
 *   - sitemap-sites.php  站点详情页的 XML sitemap（给搜索引擎看）
 *
 * 可见性规则与首页一致：只看默认访客视图用户、启用中、未删除、分类非私密；
 * 若默认视图用户没有数据，回退到公开数据量最多的用户（空站自愈的同一套逻辑）。
 */

if (!function_exists('navPublicIndexLinks')) {
    /**
     * @return array{links: array<int, array<string, mixed>>, categories: array<int, array<string, mixed>>}
     */
    function navPublicIndexLinks(Functions $functions)
    {
        $db = Database::getInstance()->getConnection();
        $users = new UserService($functions);

        $viewUserId = (int) $users->defaultViewUserId();

        $sql = "SELECT nl.id, nl.name, nl.url, nl.description, nl.clicks, nl.category_id, nl.updated_at,
                       c.name AS category_name, c.sort_order AS category_sort
                FROM nav_links nl
                LEFT JOIN categories c ON c.id = nl.category_id
                WHERE nl.user_id = ? AND nl.is_active = 1 AND nl.deleted_at IS NULL
                  AND COALESCE(c.is_private, 0) = 0
                ORDER BY COALESCE(nl.clicks, 0) DESC, nl.id ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute([$viewUserId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            // 默认视图用户没有公开数据：切到公开数据量最多的用户，避免输出空 sitemap
            $fallback = $db->query(
                "SELECT nl.user_id, COUNT(*) AS total
                 FROM nav_links nl
                 LEFT JOIN categories c ON c.id = nl.category_id
                 WHERE nl.is_active = 1 AND nl.deleted_at IS NULL AND COALESCE(c.is_private, 0) = 0
                 GROUP BY nl.user_id
                 ORDER BY total DESC
                 LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);

            if ($fallback && (int) $fallback['total'] > 0) {
                $stmt = $db->prepare($sql);
                $stmt->execute([(int) $fallback['user_id']]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        return $rows ?: [];
    }
}

if (!function_exists('navIndexCategories')) {
    /**
     * 把链接按分类归档，分类按 sort_order 排，未分类放到最后。
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{name: string, links: array<int, array<string, mixed>>}>
     */
    function navIndexCategories(array $rows)
    {
        $grouped = [];

        foreach ($rows as $row) {
            $key = (int) ($row['category_id'] ?? 0);
            $name = trim((string) ($row['category_name'] ?? ''));

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'id' => $key,
                    'name' => $name !== '' ? $name : '未分类',
                    'sort' => (int) ($row['category_sort'] ?? 9999),
                    'links' => [],
                ];
            }

            $grouped[$key]['links'][] = $row;
        }

        uasort($grouped, function ($a, $b) {
            if ($a['sort'] === $b['sort']) {
                return $a['id'] <=> $b['id'];
            }

            return $a['sort'] <=> $b['sort'];
        });

        return array_values($grouped);
    }
}
