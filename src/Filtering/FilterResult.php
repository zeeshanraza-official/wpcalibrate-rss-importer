<?php

declare(strict_types=1);

namespace WPCalibrate\RSSImporter\Filtering;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Value object representing the outcome of a filter decision.
 */
final class FilterResult {

    private bool $allowed;
    private string $reason;

    public function __construct(bool $allowed, string $reason = '') {
        $this->allowed = $allowed;
        $this->reason  = $reason;
    }

    public static function allow(): self {
        return new self(true, '');
    }

    public static function reject(string $reason): self {
        return new self(false, $reason);
    }

    public function isAllowed(): bool {
        return $this->allowed;
    }

    public function getReason(): string {
        return $this->reason;
    }
}
