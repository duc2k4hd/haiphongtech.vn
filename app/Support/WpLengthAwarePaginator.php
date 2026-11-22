<?php

namespace App\Support;

use Illuminate\Pagination\LengthAwarePaginator;

class WpLengthAwarePaginator extends LengthAwarePaginator
{
    protected string $wpBaseSlashedPath = '/';

    public function setWpBaseSlashedPath(string $path): self
    {
        $this->wpBaseSlashedPath = '/' . trim($path, '/') . '/';
        if ($this->wpBaseSlashedPath === '//') {
            $this->wpBaseSlashedPath = '/';
        }
        return $this;
    }

    public function url($page): string
    {
        if ($page <= 0) {
            $page = 1;
        }

        $target = $page === 1
            ? $this->wpBaseSlashedPath
            : $this->wpBaseSlashedPath . 'page/' . $page . '/';

        if (count($this->query) > 0) {
            $target .= '?' . http_build_query($this->query, '', '&', PHP_QUERY_RFC3986);
        }

        return $target . $this->buildFragment();
    }
}
