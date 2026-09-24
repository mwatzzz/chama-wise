<?php

declare(strict_types=1);

interface AIProviderInterface
{
    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    public function generate(array $messages): string;
}
