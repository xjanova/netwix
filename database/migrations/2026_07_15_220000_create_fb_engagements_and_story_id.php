<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facebook comment→DM invite funnel (Phase A). When someone comments on one of our clip
 * posts we privately reply inviting them to watch that exact title on the web / in the app.
 *
 * - `fb_engagements` records each comment we saw + whether we DM'd them (audit + cooldown).
 * - `marketing_clips.remote_story_id` is the feed STORY id (`{page}_{story}`), resolved once
 *   at post time from the video id, so a webhook comment's post_id maps straight to the title
 *   without a Graph lookup per comment. (remote_post_id stays the video/reel id we already had.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fb_engagements', function (Blueprint $table) {
            $table->id();
            $table->string('fb_user_id', 40)->index();     // the commenter's PSID/user id
            $table->string('fb_user_name')->nullable();
            $table->string('fb_post_id', 80)->index();      // the story post_id from the webhook
            $table->string('comment_id', 80)->nullable();   // target of the private reply
            $table->foreignId('content_id')->nullable()->constrained('contents')->nullOnDelete();
            $table->string('kind', 12)->default('comment'); // comment | reaction
            $table->string('dm_status', 12)->default('pending'); // pending | sent | skipped | failed
            $table->string('dm_error')->nullable();
            $table->timestamps();

            $table->index(['fb_user_id', 'content_id']);    // cooldown lookups
            $table->index('created_at');
        });

        Schema::table('marketing_clips', function (Blueprint $table) {
            $table->string('remote_story_id', 80)->nullable()->index()->after('remote_post_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fb_engagements');
        Schema::table('marketing_clips', function (Blueprint $table) {
            $table->dropColumn('remote_story_id');
        });
    }
};
