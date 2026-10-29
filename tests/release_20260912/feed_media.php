<?php
/** Real production normalization/presentation, without Moodle bootstrap or network. */
define('MOODLE_INTERNAL', true);
require __DIR__ . '/../../moodle/local/ustar/classes/feed_rss.php';
require __DIR__ . '/../../moodle/local/ustar/classes/feed_files.php';
function expect_same($want, $actual): void {
    if ($want !== $actual) {
        throw new RuntimeException(json_encode(['want' => $want, 'actual' => $actual]));
    }
}
$case = $argv[1];
$asset = 'ffc2def2-f8d0-574d-83c7-825ff5fa829e';
$original = 'https://leonardo.osnova.io/' . $asset . '/';
$resized = $original . '-/resize/1300/';
$photo = static fn(int $id, string $hash, string $source = ''): array =>
    ['id' => $id, 'name' => 'rss-' . $id . '.jpg', 'image' => true,
     'contenthash' => $hash, 'source' => $source, 'url' => '/private/' . $id];
switch ($case) {
    case 'provider_variants':
        expect_same([$original], \local_ustar\feed_rss::unique_media_urls([$original, $resized, $original]));
        break;
    case 'distinct_urls':
        $urls = ['https://cdn.example/a.jpg?image=1', 'https://cdn.example/a.jpg?image=2',
            'https://leonardo.osnova.io.evil.test/' . $asset . '/',
            'https://leonardo.osnova.io/12345678-1234-1234-1234-123456789abc/'];
        expect_same($urls, \local_ustar\feed_rss::unique_media_urls($urls));
        break;
    case 'old_byte_duplicates':
        $out = \local_ustar\feed_files::for_display([$photo(1, 'same'), $photo(2, 'same'), $photo(3, 'other')], true);
        expect_same([1, 3], array_column($out, 'id'));
        expect_same(false, array_key_exists('contenthash', $out[0]));
        expect_same(false, array_key_exists('source', $out[0]));
        break;
    case 'stored_provider_variants':
        $out = \local_ustar\feed_files::for_display([$photo(1, 'first', $original), $photo(2, 'resize', $resized)], true);
        expect_same([1], array_column($out, 'id'));
        break;
    case 'employee_and_documents':
        $files = [$photo(1, 'same'), $photo(2, 'same'),
            ['id' => 3, 'name' => 'guide.pdf', 'image' => false, 'contenthash' => 'same']];
        expect_same([1, 2, 3], array_column(\local_ustar\feed_files::for_display($files, false), 'id'));
        expect_same([1, 3], array_column(\local_ustar\feed_files::for_display($files, true), 'id'));
        break;
    case 'compact_cover':
        $files = [$photo(1, 'a'), ['id' => 2, 'image' => false], $photo(3, 'b'), $photo(4, 'c')];
        $out = \local_ustar\feed_files::card_media($files, true);
        expect_same([1, 2], array_column($out['summary'], 'id'));
        expect_same([3, 4], array_column($out['additional'], 'id'));
        $out = \local_ustar\feed_files::card_media($files, false);
        expect_same([1, 2, 3, 4], array_column($out['summary'], 'id'));
        expect_same([], $out['additional']);
        expect_same(['summary' => [], 'additional' => []], \local_ustar\feed_files::card_media([], true));
        break;
    default: throw new RuntimeException('Unknown case');
}
echo json_encode(['case' => $case, 'status' => 'passed']);
