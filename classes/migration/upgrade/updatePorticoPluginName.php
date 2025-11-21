<?php

/**
 * @file classes/migration/upgrade/updatePorticoPluginName.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class updatePorticoPluginName
 *
 * @brief Fix the plugin name in plugin settings for the Portico export plugin.
 */

namespace APP\plugins\importexport\portico\classes\migration\upgrade;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use PKP\install\DowngradeNotSupportedException;

class updatePorticoPluginName extends Migration
{
    /**
     * Run the migration to update the plugin name, which may have been set incorrectly in 3.4.
     */
    public function up(): void
    {
        $upgradeRequired = DB::table('plugin_settings')
            ->where(DB::raw('LOWER(plugin_name)'), '=', 'app\plugins\importexport\portico\porticoexportplugin')
            ->count();

        if ($upgradeRequired > 0) {
            // Get all portico plugin settings, order to prioritize the 3.4 settings, and clear duplicates
            $records = DB::table('plugin_settings')
                ->whereLike('plugin_name', '%porticoexportplugin')
                ->orderBy('plugin_name', 'desc')
                ->get()
                ->keyBy('context_id');

            // Delete the old settings
            DB::table('plugin_settings')
                ->whereLike('plugin_name', '%porticoexportplugin')
                ->delete();

            // Insert the settings with the correct plugin name
            foreach ($records as $record) {
                DB::table('plugin_settings')->insert(
                    [
                        'plugin_name' => 'porticoexportplugin',
                        'context_id' => $record->context_id,
                        'setting_name' => $record->setting_name,
                        'setting_value' => $record->setting_value,
                        'setting_type' => 'object'
                    ]
                );
            }
        }
    }

    /**
     * Rollback the migration.
     */
    public function down(): void
    {
        throw new DowngradeNotSupportedException();
    }
}
