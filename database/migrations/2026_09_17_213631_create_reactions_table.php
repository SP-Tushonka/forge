<?php

declare(strict_types=1);

use App\Models\Comment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('hub_id')->nullable();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reactable_type');
            $table->unsignedBigInteger('reactable_id');
            $table->foreignId('emoji_id')->constrained('emojis')->restrictOnDelete();
            $table->timestamps();

            // One reaction per user per item: picking a different emoji replaces the previous choice rather than
            // adding to it. The emoji is deliberately not part of the key.
            $table->unique(['user_id', 'reactable_type', 'reactable_id'], 'reactions_unique_per_user');
            $table->unique(['hub_id'], 'reactions_hub_id_unique');
            $table->index(['reactable_type', 'reactable_id', 'emoji_id'], 'reactions_count_index');
            // No separate index for the "which did I pick" lookup: reactions_unique_per_user covers those exact
            // columns and serves it.
        });

        $this->backfillLegacyLikes();
    }

    public function down(): void
    {
        Schema::dropIfExists('reactions');
    }

    /**
     * Copies every row of comment_reactions in as a heart. comment_reactions is intentionally left in place and
     * untouched: dropping it is a separate migration with its own approval, which keeps this one's down() harmless.
     */
    private function backfillLegacyLikes(): void
    {
        if (! Schema::hasTable('comment_reactions')) {
            return;
        }

        $heartId = DB::table('emojis')->where('shortcode', 'heart')->value('id');

        if ($heartId === null) {
            throw new RuntimeException('The heart emoji is missing; the emojis migration must run first.');
        }

        DB::table('comment_reactions')
            ->orderBy('id')
            ->chunk(2000, function (Collection $rows) use ($heartId): void {
                DB::table('reactions')->insert($rows->map(fn (object $row): array => [
                    'hub_id' => $row->hub_id,
                    'user_id' => $row->user_id,
                    'reactable_type' => Comment::class,
                    'reactable_id' => $row->comment_id,
                    'emoji_id' => $heartId,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ])->all());
            });
    }
};
