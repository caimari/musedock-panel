<?php
namespace MuseDockPanel\Mcp;

use MuseDockPanel\Controllers\DocsController;

/**
 * Documentación del panel por MCP (solo lectura): que un asistente pueda listar, clasificar, buscar y leer TODA la doc
 * que hay en /docs (guías de ajustes, correo, errores conocidos y guías especiales), con las mismas fuentes que el
 * buscador del propio panel. Solo texto de ayuda: no hay datos del servidor ni secretos.
 */
final class McpDocsTools
{
    public const MAX_CHARS = 12000;

    public static function definitions(): array
    {
        $o = static fn(array $props, array $req = []) => array_filter([
            'type' => 'object', 'properties' => (object)$props, 'required' => $req ?: null, 'additionalProperties' => false,
        ], static fn($v) => $v !== null);

        return [
            'docs_catalog' => [
                'write' => false,
                'title' => 'Catálogo de la documentación del panel',
                'description' => 'Solo lectura. Lista TODA la documentación del panel (la de /docs), clasificada por grupo (ajustes, correo, errores conocidos, guías especiales) y categoría, con título, descripción, palabras clave, URL y tamaño. Empieza por aquí para saber qué guías existen; luego usa docs_search o docs_read.',
                'inputSchema' => $o([
                    'group' => ['type' => 'string', 'enum' => ['settings', 'mail', 'bugs', 'special'], 'description' => 'Opcional: solo un grupo'],
                ]),
            ],
            'docs_search' => [
                'write' => false,
                'title' => 'Buscar en la documentación del panel',
                'description' => 'Solo lectura. Busca en el contenido completo de toda la documentación del panel (título, descripción, palabras clave y texto) y devuelve las guías más relevantes con un fragmento. Úsalo para responder preguntas de «cómo se hace» o «qué significa» con la doc real; después lee la guía entera con docs_read.',
                'inputSchema' => $o([
                    'query' => ['type' => 'string', 'description' => 'Palabras a buscar (p. ej. «réplica correo dsync», «alias catch-all», «sendmail_path»)'],
                    'limit' => ['type' => 'integer', 'description' => 'Máximo de resultados (por defecto 8, máximo 25)'],
                ], ['query']),
            ],
            'docs_read' => [
                'write' => false,
                'title' => 'Leer una guía de la documentación del panel',
                'description' => 'Solo lectura. Devuelve el texto completo de una guía por su URL (las que da docs_catalog o docs_search, p. ej. /docs/web-mail). Si es larga, se devuelve por partes: usa `offset` para seguir.',
                'inputSchema' => $o([
                    'url' => ['type' => 'string', 'description' => 'URL de la guía, p. ej. /docs/system-mail'],
                    'offset' => ['type' => 'integer', 'description' => 'Carácter desde el que seguir leyendo (por defecto 0)'],
                ], ['url']),
            ],
        ];
    }

    public static function has(string $name): bool
    {
        return isset(self::definitions()[$name]);
    }

    public static function run(string $name, array $args): array
    {
        return match ($name) {
            'docs_catalog' => self::catalog((string)($args['group'] ?? '')),
            'docs_search'  => self::search((string)($args['query'] ?? ''), (int)($args['limit'] ?? 8)),
            'docs_read'    => self::read((string)($args['url'] ?? ''), (int)($args['offset'] ?? 0)),
            default        => throw new \InvalidArgumentException("Herramienta desconocida: {$name}"),
        };
    }

    /** Grupo de una guía según su URL. Puro. */
    public static function groupOf(string $url): string
    {
        return match (true) {
            str_starts_with($url, '/docs/settings') => 'settings',
            str_starts_with($url, '/docs/mail') => 'mail',
            str_starts_with($url, '/docs/bug') => 'bugs',
            default => 'special',
        };
    }

    /** Solo se lee lo que pertenece a la doc: «/docs/…» sin saltos de directorio. Puro. */
    public static function validUrl(string $url): bool
    {
        return (bool)preg_match('#^/docs(/[A-Za-z0-9._-]+)*$#', $url) && !str_contains($url, '..');
    }

    /** Todos los temas con su texto. @return array<int,array{topic:array,text:string}> */
    private static function all(): array
    {
        $ctl = new DocsController();
        [$topics, $guides] = $ctl->mcpCatalog();
        $out = [];
        foreach ($topics as $t) {
            $url = (string)($t['url'] ?? '');
            if (!self::validUrl($url)) {
                continue;
            }
            $out[] = ['topic' => $t, 'text' => $ctl->mcpContent($url, $guides)];
        }
        return $out;
    }

    private static function catalog(string $group): array
    {
        $rows = [];
        $groups = [];
        foreach (self::all() as $e) {
            $t = $e['topic'];
            $url = (string)$t['url'];
            $g = self::groupOf($url);
            if ($group !== '' && $g !== $group) {
                continue;
            }
            $groups[$g] = ($groups[$g] ?? 0) + 1;
            $rows[] = [
                'url' => $url,
                'title' => (string)($t['title'] ?? ''),
                'description' => (string)($t['description'] ?? ''),
                'category' => (string)($t['category'] ?? ''),
                'group' => $g,
                'keywords' => (string)($t['keywords'] ?? ''),
                'characters' => mb_strlen($e['text']),
            ];
        }
        usort($rows, static fn($a, $b) => [$a['group'], $a['category'], $a['title']] <=> [$b['group'], $b['category'], $b['title']]);
        return ['total' => count($rows), 'por_grupo' => $groups, 'guias' => $rows,
            'next' => 'Busca con docs_search o lee una guía entera con docs_read (url).'];
    }

    /** Puntuación de una guía para una búsqueda. Pura. @param list<string> $terms */
    public static function score(array $terms, string $title, string $description, string $keywords, string $text): int
    {
        $score = 0;
        $t = mb_strtolower($title);
        $d = mb_strtolower($description);
        $k = mb_strtolower($keywords);
        $x = mb_strtolower($text);
        foreach ($terms as $w) {
            $score += 8 * substr_count($t, $w) + 4 * substr_count($k, $w) + 3 * substr_count($d, $w) + min(5, substr_count($x, $w));
        }
        return $score;
    }

    /** Fragmento del texto alrededor de la primera coincidencia. Puro. */
    public static function excerpt(string $text, array $terms, int $len = 260): string
    {
        $low = mb_strtolower($text);
        $pos = null;
        foreach ($terms as $w) {
            $p = mb_strpos($low, $w);
            if ($p !== false && ($pos === null || $p < $pos)) {
                $pos = $p;
            }
        }
        $start = max(0, ($pos ?? 0) - 80);
        $e = trim(mb_substr($text, $start, $len));
        return ($start > 0 ? '… ' : '') . $e . (mb_strlen($text) > $start + $len ? ' …' : '');
    }

    private static function search(string $query, int $limit): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new \InvalidArgumentException('Falta la búsqueda (query).');
        }
        $terms = array_values(array_filter(preg_split('/\s+/u', mb_strtolower($query)) ?: [], static fn($w) => mb_strlen($w) >= 2));
        $limit = max(1, min(25, $limit ?: 8));
        $hits = [];
        foreach (self::all() as $e) {
            $t = $e['topic'];
            $s = self::score($terms, (string)($t['title'] ?? ''), (string)($t['description'] ?? ''), (string)($t['keywords'] ?? ''), $e['text']);
            if ($s > 0) {
                $hits[] = ['score' => $s, 'url' => (string)$t['url'], 'title' => (string)($t['title'] ?? ''), 'group' => self::groupOf((string)$t['url']),
                    'category' => (string)($t['category'] ?? ''), 'excerpt' => self::excerpt($e['text'], $terms)];
            }
        }
        usort($hits, static fn($a, $b) => $b['score'] <=> $a['score']);
        return ['query' => $query, 'resultados' => array_slice($hits, 0, $limit), 'total_coincidencias' => count($hits),
            'next' => 'Lee la guía entera con docs_read (url).'];
    }

    private static function read(string $url, int $offset): array
    {
        $url = rtrim(trim($url), '/');
        if (!self::validUrl($url)) {
            throw new \InvalidArgumentException('URL no válida: usa una de docs_catalog (empieza por /docs/).');
        }
        foreach (self::all() as $e) {
            if ((string)$e['topic']['url'] === $url) {
                $text = $e['text'];
                $offset = max(0, $offset);
                $chunk = mb_substr($text, $offset, self::MAX_CHARS);
                $end = $offset + mb_strlen($chunk);
                return [
                    'url' => $url, 'title' => (string)($e['topic']['title'] ?? ''), 'group' => self::groupOf($url),
                    'category' => (string)($e['topic']['category'] ?? ''), 'characters_total' => mb_strlen($text),
                    'offset' => $offset, 'text' => $chunk,
                    'next_offset' => $end < mb_strlen($text) ? $end : null,
                ];
            }
        }
        throw new \InvalidArgumentException("No hay ninguna guía con la URL {$url}. Mira docs_catalog.");
    }
}
