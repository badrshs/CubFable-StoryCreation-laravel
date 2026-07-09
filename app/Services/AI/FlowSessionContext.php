<?php

namespace App\Services\AI;

/**
 * Conversation continuity key for the flow gateway's session-capable engines
 * (browser Grok): every image request carrying the same key reuses one live
 * Grok conversation, so the reference photo uploads once and later images
 * inherit it. Scoped per queue job like UsageCollector; StoryGenerator sets
 * the key per book + art style.
 */
class FlowSessionContext
{
    public ?string $key = null;
}
