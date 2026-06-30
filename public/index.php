<?php
header('Content-Type: application/json; charset=utf-8');

$repo = 'geensnor/geensnor.nl';
$branch = 'b5f00bfa7236e101662df9ac164dfb929e0b36df';
$blogDir = 'src/content/blog';

function fetchUrl(string $url): ?string {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: blog-excerpt-api\r\nAccept: application/vnd.github+json\r\n",
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);

    $content = @file_get_contents($url, false, $context);
    if ($content === false) {
        return null;
    }

    return $content;
}

function parseMarkdownBody(string $content): string {
    $content = preg_replace('/^---\s*[\r\n].*?[\r\n]---\s*/s', '', $content, 1);
    $content = preg_replace('/```[\s\S]*?```/m', ' ', $content);
    $content = preg_replace('/!\[(.*?)\]\((.*?)\)/', ' ', $content);
    $content = preg_replace('/\[(.*?)\]\((.*?)\)/', '$1', $content);
    $content = strip_tags($content);
    $content = preg_replace('/[#>*_~`]/', ' ', $content);
    $content = preg_replace('/\s+/', ' ', $content);

    return trim($content);
}

function splitSentences(string $text): array {
    $text = trim($text);
    if ($text === '') {
        return [];
    }

    $sentences = preg_split('/(?<=[.!?])\s+/', $text);
    $sentences = array_values(array_filter(array_map('trim', $sentences), static fn($sentence) => $sentence !== ''));

    return $sentences;
}

function buildExcerpt(array $posts): array {
    $eligible = array_values(array_filter($posts, static function ($post): bool {
        return count($post['sentences']) >= 3;
    }));

    if ($eligible === []) {
        return [
            'error' => 'No posts with enough sentences were found.',
        ];
    }

    $post = $eligible[array_rand($eligible)];
    $sentences = $post['sentences'];
    $start = random_int(0, max(0, count($sentences) - 3));
    $excerpt = implode(' ', array_slice($sentences, $start, 3));

    return [
        'excerpt' => $excerpt,
        'source' => $post['source'],
        'title' => $post['title'],
        'sentence_count' => count($sentences),
        'start_index' => $start,
    ];
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/health') {
    echo json_encode(['status' => 'ok']);
    exit;
}

$apiUrl = sprintf('https://api.github.com/repos/%s/contents/%s?ref=%s', $repo, $blogDir, $branch);
$listingJson = fetchUrl($apiUrl);

if ($listingJson === null) {
    http_response_code(502);
    echo json_encode(['error' => 'Unable to load blog index from GitHub.']);
    exit;
}

$listing = json_decode($listingJson, true);
if (!is_array($listing)) {
    http_response_code(502);
    echo json_encode(['error' => 'The blog index from GitHub was not valid JSON.']);
    exit;
}

$posts = [];
foreach ($listing as $entry) {
    if (!is_array($entry) || ($entry['type'] ?? '') !== 'file' || substr($entry['name'] ?? '', -3) !== '.md') {
        continue;
    }

    $rawContent = fetchUrl($entry['download_url'] ?? '');
    if ($rawContent === null) {
        continue;
    }

    $body = parseMarkdownBody($rawContent);
    $sentences = splitSentences($body);
    if (count($sentences) < 3) {
        continue;
    }

    $title = pathinfo($entry['name'], PATHINFO_FILENAME);
    $posts[] = [
        'title' => $title,
        'source' => $entry['name'],
        'sentences' => $sentences,
    ];
}

if ($posts === []) {
    http_response_code(502);
    echo json_encode(['error' => 'No blog posts with enough content were found.']);
    exit;
}

$result = buildExcerpt($posts);
if (isset($result['error'])) {
    http_response_code(500);
    echo json_encode($result);
    exit;
}

echo json_encode([
    'ok' => true,
    'repo' => $repo,
    'branch' => $branch,
    'blog_directory' => $blogDir,
    'result' => $result,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
