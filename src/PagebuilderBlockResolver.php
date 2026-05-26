<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation;

use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;

class PagebuilderBlockResolver
{
    public function __construct(
        private readonly EntryReferenceExtractor $references,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function resolve(Entry $entry, bool $expandReusable = true): array
    {
        $blocks = $entry->get('pagebuilder');

        if (! is_array($blocks)) {
            return [];
        }

        return $this->resolveBlocks($blocks, $expandReusable);
    }

    /**
     * @param  array<int, mixed>  $blocks
     * @param  array<int, string>  $visitedReusableBlockIds
     * @return array<int, array<string, mixed>>
     */
    private function resolveBlocks(array $blocks, bool $expandReusable, array $visitedReusableBlockIds = []): array
    {
        $resolved = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $resolved[] = $block;

            if (! $this->shouldExpandReusableBlock($block, $expandReusable)) {
                continue;
            }

            $resolved = [
                ...$resolved,
                ...$this->resolveReusableBlocks($block, $expandReusable, $visitedReusableBlockIds),
            ];
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<int, string>  $visitedReusableBlockIds
     * @return array<int, array<string, mixed>>
     */
    private function resolveReusableBlocks(array $block, bool $expandReusable, array $visitedReusableBlockIds): array
    {
        $resolved = [];

        foreach ($this->references->extract($block['entry'] ?? null) as $reusableBlockId) {
            if (in_array($reusableBlockId, $visitedReusableBlockIds, true)) {
                continue;
            }

            $reusableBlock = EntryFacade::find($reusableBlockId);

            if (! $reusableBlock instanceof Entry) {
                continue;
            }

            $resolved = [
                ...$resolved,
                ...$this->resolveBlocks(
                    $reusableBlock->get('pagebuilder') ?? [],
                    $expandReusable,
                    [...$visitedReusableBlockIds, $reusableBlockId],
                ),
            ];
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function shouldExpandReusableBlock(array $block, bool $expandReusable): bool
    {
        return $expandReusable
            && PagebuilderBlockType::ReusableBlock->matches($block);
    }
}
