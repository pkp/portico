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
 * @brief Update the plugin name to "PorticoExportPlugin"
 */

namespace APP\plugins\importexport\portico\classes\migration\upgrade;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class updatePorticoPluginName extends Migration
{
    /**
     * Run the migration to update the plugin name, which may have been set incorrectly in 3.4.
     */
    public function up(): void
    {
        DB::table('plugin_settings')
            ->where('plugin_name', '=', 'app\plugins\importexport\portico\PorticoExportPlugin')
            ->update(['plugin_name' => 'porticoexportplugin']);
    }

    /**
     * Rollback the migration.
     */
    public function down(): void
    {
        DB::table('plugin_settings')
            ->where('plugin_name', '=', 'porticoexportplugin')
            ->update(['plugin_name' => 'app\plugins\importexport\portico\PorticoExportPlugin']);
    }
}
