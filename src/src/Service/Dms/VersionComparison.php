<?php

namespace App\Service\Dms;

/**
 * Side-by-side comparison of the text extracted from two versions. Text is split into sentences (extraction
 * flattens line breaks). The unchanged beginning and end are matched directly and only the differing middle is
 * aligned with a longest-common-subsequence diff, refused beyond MAX_UNITS sentences per side to bound memory.
 */
final class VersionComparison
{
    public const MAX_UNITS = 1000;

    /**
     * @return array{comparable: bool, reason: ?string, segments: list<array{op: string, text: string}>, added: int, removed: int}
     */
    public function compare(?string $from, ?string $to): array
    {
        if ($from === null || $to === null) {
            return ['comparable' => false, 'reason' => 'Text comparison needs text from both versions (PDF and image files have none yet).', 'segments' => [], 'added' => 0, 'removed' => 0];
        }
        $a = $this->units($from);
        $b = $this->units($to);
        $prefix = 0;
        while ($prefix < count($a) && $prefix < count($b) && $a[$prefix] === $b[$prefix]) {
            ++$prefix;
        }
        $suffix = 0;
        while ($suffix < count($a) - $prefix && $suffix < count($b) - $prefix && $a[count($a) - 1 - $suffix] === $b[count($b) - 1 - $suffix]) {
            ++$suffix;
        }
        $middleA = array_slice($a, $prefix, count($a) - $prefix - $suffix);
        $middleB = array_slice($b, $prefix, count($b) - $prefix - $suffix);
        if (count($middleA) > self::MAX_UNITS || count($middleB) > self::MAX_UNITS) {
            return ['comparable' => false, 'reason' => 'The changes are too extensive to compare here; download both versions instead.', 'segments' => [], 'added' => 0, 'removed' => 0];
        }
        $equal = static fn (array $units): array => array_map(static fn (string $text): array => ['op' => 'equal', 'text' => $text], $units);
        $segments = [...$equal(array_slice($a, 0, $prefix)), ...$this->diff($middleA, $middleB), ...$equal(array_slice($a, count($a) - $suffix))];
        $count = static fn (string $op): int => count(array_filter($segments, static fn (array $segment): bool => $segment['op'] === $op));

        return ['comparable' => true, 'reason' => null, 'segments' => $this->merge($segments), 'added' => $count('added'), 'removed' => $count('removed')];
    }

    /** @return list<string> */
    private function units(string $text): array
    {
        return preg_split('/(?<=[.!?;:])\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{op: string, text: string}>
     */
    private function diff(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        // lengths[i][j] = LCS length of a[i..] and b[j..], kept as rows of integers.
        $lengths = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; --$i) {
            for ($j = $m - 1; $j >= 0; --$j) {
                $lengths[$i][$j] = $a[$i] === $b[$j] ? $lengths[$i + 1][$j + 1] + 1 : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }
        $segments = [];
        for ($i = 0, $j = 0; $i < $n || $j < $m;) {
            if ($i < $n && $j < $m && $a[$i] === $b[$j]) {
                $segments[] = ['op' => 'equal', 'text' => $a[$i++]];
                ++$j;
            } elseif ($j < $m && ($i >= $n || $lengths[$i][$j + 1] >= $lengths[$i + 1][$j])) {
                $segments[] = ['op' => 'added', 'text' => $b[$j++]];
            } else {
                $segments[] = ['op' => 'removed', 'text' => $a[$i++]];
            }
        }

        return $segments;
    }

    /**
     * Joins neighbouring sentences with the same operation so the view shows passages, not fragments.
     *
     * @param list<array{op: string, text: string}> $segments
     * @return list<array{op: string, text: string}>
     */
    private function merge(array $segments): array
    {
        $merged = [];
        foreach ($segments as $segment) {
            $last = array_key_last($merged);
            if ($last !== null && $merged[$last]['op'] === $segment['op']) {
                $merged[$last]['text'] .= ' '.$segment['text'];
            } else {
                $merged[] = $segment;
            }
        }

        return $merged;
    }
}
