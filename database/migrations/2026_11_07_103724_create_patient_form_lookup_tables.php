<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The fixed rows of each patient-module lookup table. The IDs are
     * referenced by the App\Enums classes, so they must never change.
     *
     * @var array<string, array<int, string>>
     */
    private array $lookups = [
        'patient_form_field_types' => [
            1 => 'TEXT',
            2 => 'TEXTAREA',
            3 => 'NUMBER',
            4 => 'DATE',
            5 => 'SELECT',
            6 => 'MULTISELECT',
            7 => 'CHECKBOX',
            8 => 'RADIO',
            9 => 'EMAIL',
            10 => 'PHONE',
        ],
        'progress_ratings' => [
            1 => 'BASELINE',
            2 => 'IMPROVED',
            3 => 'UNCHANGED',
            4 => 'DECLINED',
            5 => 'RESOLVED',
        ],
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
