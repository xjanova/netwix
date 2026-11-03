<?php

namespace App\Support;

use App\Models\Content;
use App\Models\Profile;

/**
 * Seed for the like / comment / share rail on both web players (watch + vertical-player), handed
 * to the shared `nxSocial()` Alpine mixin in partials/player-social-drawer.
 *
 * The comments are the same rows the app reads and writes (`/api/app/content/{id}/comments`), and
 * the drawer re-reads that endpoint when it opens, so a comment posted from the app shows up on the
 * web without a reload.
 */
class PlayerSocial
{
    public static function seed(Content $content, ?Profile $profile): array
    {
        $member = auth()->check() && $profile !== null;

        return [
            'member' => $member,
            // Signed in but no profile picked yet: the like/comment routes need a profile, so send
            // them to the picker instead of the login page (which would just bounce them home).
            'loginUrl' => auth()->check() ? route('profiles.index') : route('login'),
            'liked' => $member && $profile->likes()->where('content_id', $content->id)->exists(),
            'likes' => $content->likedBy()->count(),
            'likeUrl' => route('content.like', $content),
            'commentUrl' => route('content.comment', $content),
            'listUrl' => url('/api/app/content/'.$content->id.'/comments'),
            'commentCount' => $content->comments()->count(),
            'comments' => $content->comments()->with('profile')->latest()->limit(30)->get()
                ->map(fn ($c) => [
                    'author' => $c->profile?->name ?? 'สมาชิก',
                    'color' => $c->profile?->avatar_color ?? '#8b2ff0',
                    'initial' => $c->profile?->initial ?? 'N',
                    'text' => $c->body,
                    'ago' => optional($c->created_at)->diffForHumans(),
                ])->values()->all(),
            'title' => $content->title,
            'shareUrl' => route('title.show', $content),
        ];
    }
}
