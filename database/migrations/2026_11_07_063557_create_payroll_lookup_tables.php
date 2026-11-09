<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The fixed rows of each payroll lookup table. The IDs are referenced by
     * the App\Enums classes, so they must never change.
     *
     * @var array<string, array<int, string>>
     */
    private array $lookups = [
        'attendance_statuses' => [1 => 'PRESENT', 2 => 'ABSENT', 3 => 'LATE', 4 => 'EXCUSED'],
        'discount_types' => [1 => 'PERCENTAGE', 2 => 'FIXED'],
        'discount_targets' => [1 => 'CLINIC', 2 => 'WORKER'],
        'payout_statuses' => [1 => 'DRAFT', 2 => 'PAID', 3 => 'CANCELLED'],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->lookups as $tableName => $rows) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->unsignedTinyInteger('id')->primary();
                $table->string('code')->unique();
            });

            DB::table($tableName)->insert(
                collect($rows)->map(fn (string $code, int $id) => ['id' => $id, 'code' => $code])->values()->all(),
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_keys($this->lookups) as $tableName) {
            Schema::dropIfExists($tableName);
        }
    }
};
