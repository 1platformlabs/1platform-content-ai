<?php
/**
 * Link Distribution Strategy
 *
 * Implements anti-clustering algorithm to distribute links naturally throughout content.
 * Prevents multiple links from being added too close together.
 *
 * @package WPContentAI
 * @subpackage Services\InternalLinks
 */

namespace WPContentAI\Services\InternalLinks;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class ContaiLinkDistributionStrategy
 *
 * Responsible for selecting optimal match positions to avoid link clustering
 */
class ContaiLinkDistributionStrategy {
    /**
     * @var int Minimum distance between links (in characters)
     */
    private $min_distance;

    /**
     * @var int Content length for calculating distribution
     */
    private $content_length;

    /**
     * Constructor
     *
     * @param int $min_distance Minimum distance between links
     */
    public function __construct(int $min_distance = 200) {
        $this->min_distance = $min_distance;
    }

    /**
     * Select optimal matches from all available matches
     * Distributes links evenly throughout content to avoid clustering
     *
     * @param array $matches All available match positions
     * @param int $max_links Maximum number of links to select
     * @param string $content Full content (for length calculation)
     * @return array Selected matches
     */
    public function selectDistributedMatches(array $matches, int $max_links, string $content): array {
        if (empty($matches)) {
            return [];
        }

        $this->content_length = strlen($content);

        if (count($matches) <= $max_links && !$this->hasClusteredMatches($matches)) {
            return $matches;
        }

        return $this->distributeMatches($matches, $max_links);
    }

    /**
     * Distribute matches evenly throughout content
     *
     * @param array $matches
     * @param int $max_links
     * @return array
     */
    private function distributeMatches(array $matches, int $max_links): array {
        if (count($matches) <= $max_links) {
            return $this->removeClusteredMatches($matches);
        }

        $segments = $this->divideContentIntoSegments($max_links);
        $selected = [];

        foreach ($segments as $segment) {
            $match_in_segment = $this->findMatchInSegment($matches, $segment, $selected);
            if ($match_in_segment !== null) {
                $selected[] = $match_in_segment;
            }
        }

        usort($selected, function($a, $b) {
            return $a['offset'] <=> $b['offset'];
        });

        return $selected;
    }

    /**
     * Divide content into segments for distribution
     *
     * @param int $num_segments
     * @return array
     */
    private function divideContentIntoSegments(int $num_segments): array {
        $segment_size = (int) ceil($this->content_length / $num_segments);
        $segments = [];

        for ($i = 0; $i < $num_segments; $i++) {
            $segments[] = [
                'start' => $i * $segment_size,
                'end' => min(($i + 1) * $segment_size, $this->content_length),
            ];
        }

        return $segments;
    }

    /**
     * Find best match within a content segment
     *
     * @param array $matches
     * @param array $segment
     * @param array $already_selected
     * @return array|null
     */
    private function findMatchInSegment(array $matches, array $segment, array $already_selected): ?array {
        $candidates = [];

        foreach ($matches as $match) {
            $offset = $match['offset'];

            if ($offset >= $segment['start'] && $offset < $segment['end']) {
                if ($this->isFarEnoughFromSelected($match, $already_selected)) {
                    $candidates[] = $match;
                }
            }
        }

        if (empty($candidates)) {
            return $this->findNearestMatch($matches, $segment, $already_selected);
        }

        return $this->selectBestCandidate($candidates, $segment);
    }

    /**
     * Find nearest match to segment if no match within segment
     *
     * @param array $matches
     * @param array $segment
     * @param array $already_selected
     * @return array|null
     */
    private function findNearestMatch(array $matches, array $segment, array $already_selected): ?array {
        $segment_center = ($segment['start'] + $segment['end']) / 2;
        $nearest = null;
        $min_distance = PHP_INT_MAX;

        foreach ($matches as $match) {
            if (!$this->isFarEnoughFromSelected($match, $already_selected)) {
                continue;
            }

            $distance = abs($match['offset'] - $segment_center);
            if ($distance < $min_distance) {
                $min_distance = $distance;
                $nearest = $match;
            }
        }

        return $nearest;
    }

    /**
     * Select best candidate from multiple matches in same segment
     * Prefers matches closer to segment center
     *
     * @param array $candidates
     * @param array $segment
     * @return array
     */
    private function selectBestCandidate(array $candidates, array $segment): array {
        $segment_center = ($segment['start'] + $segment['end']) / 2;

        usort($candidates, function($a, $b) use ($segment_center) {
            $dist_a = abs($a['offset'] - $segment_center);
            $dist_b = abs($b['offset'] - $segment_center);
            return $dist_a <=> $dist_b;
        });

        return $candidates[0];
    }

    /**
     * Check if match is far enough from already selected matches
     *
     * @param array $match
     * @param array $selected
     * @return bool
     */
    private function isFarEnoughFromSelected(array $match, array $selected): bool {
        foreach ($selected as $selected_match) {
            $distance = abs($match['offset'] - $selected_match['offset']);
            if ($distance < $this->min_distance) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if matches are clustered
     *
     * @param array $matches
     * @return bool
     */
    private function hasClusteredMatches(array $matches): bool {
        if (count($matches) < 2) {
            return false;
        }

        usort($matches, function($a, $b) {
            return $a['offset'] <=> $b['offset'];
        });

        for ($i = 1; $i < count($matches); $i++) {
            $distance = $matches[$i]['offset'] - $matches[$i - 1]['offset'];
            if ($distance < $this->min_distance) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove clustered matches, keeping only well-distributed ones
     *
     * @param array $matches
     * @return array
     */
    private function removeClusteredMatches(array $matches): array {
        if (count($matches) < 2) {
            return $matches;
        }

        usort($matches, function($a, $b) {
            return $a['offset'] <=> $b['offset'];
        });

        $filtered = [$matches[0]];

        for ($i = 1; $i < count($matches); $i++) {
            $last_selected = $filtered[count($filtered) - 1];
            $distance = $matches[$i]['offset'] - $last_selected['offset'];

            if ($distance >= $this->min_distance) {
                $filtered[] = $matches[$i];
            }
        }

        return $filtered;
    }

    /**
     * Set minimum distance between links
     *
     * @param int $distance
     * @return self
     */
    public function setMinDistance(int $distance): self {
        $this->min_distance = $distance;
        return $this;
    }

    /**
     * Get minimum distance
     *
     * @return int
     */
    public function getMinDistance(): int {
        return $this->min_distance;
    }
}
