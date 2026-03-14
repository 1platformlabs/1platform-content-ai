<?php

if (!defined('ABSPATH')) exit;

class ContaiPaginationService {

    private const DEFAULT_ITEMS_PER_PAGE = 50;
    private const MAX_VISIBLE_PAGES = 7;

    private int $currentPage;
    private int $totalItems;
    private int $itemsPerPage;

    public function __construct(int $currentPage, int $totalItems, int $itemsPerPage = self::DEFAULT_ITEMS_PER_PAGE) {
        $this->currentPage = max(1, $currentPage);
        $this->totalItems = max(0, $totalItems);
        $this->itemsPerPage = max(1, $itemsPerPage);
    }

    public function getTotalPages(): int {
        return (int) ceil($this->totalItems / $this->itemsPerPage);
    }

    public function getOffset(): int {
        return ($this->currentPage - 1) * $this->itemsPerPage;
    }

    public function getCurrentPage(): int {
        return min($this->currentPage, $this->getTotalPages() ?: 1);
    }

    public function hasPages(): bool {
        return $this->getTotalPages() > 1;
    }

    public function hasPreviousPage(): bool {
        return $this->currentPage > 1;
    }

    public function hasNextPage(): bool {
        return $this->currentPage < $this->getTotalPages();
    }

    public function getPreviousPage(): int {
        return max(1, $this->currentPage - 1);
    }

    public function getNextPage(): int {
        return min($this->getTotalPages(), $this->currentPage + 1);
    }

    public function getVisiblePages(): array {
        $totalPages = $this->getTotalPages();

        if ($totalPages <= self::MAX_VISIBLE_PAGES) {
            return range(1, $totalPages);
        }

        $currentPage = $this->getCurrentPage();
        $halfVisible = (int) floor(self::MAX_VISIBLE_PAGES / 2);

        $startPage = max(1, $currentPage - $halfVisible);
        $endPage = min($totalPages, $currentPage + $halfVisible);

        if ($currentPage <= $halfVisible) {
            $endPage = self::MAX_VISIBLE_PAGES;
        }

        if ($currentPage > $totalPages - $halfVisible) {
            $startPage = $totalPages - self::MAX_VISIBLE_PAGES + 1;
        }

        return range($startPage, $endPage);
    }

    public function getStartItemNumber(): int {
        if ($this->totalItems === 0) {
            return 0;
        }
        return $this->getOffset() + 1;
    }

    public function getEndItemNumber(): int {
        return min($this->totalItems, $this->getOffset() + $this->itemsPerPage);
    }

    public function getItemsPerPage(): int {
        return $this->itemsPerPage;
    }
}
