<?php

/**
 * @file classes/migration/upgrade/updatePorticoPluginNameToGeneric.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class updatePorticoPluginNameToGeneric
 *
 * @brief Migrates settings/version data from the old plugin's name to the
 *   new one, and removes the old plugin's files.
 */

namespace APP\plugins\generic\portico\classes\migration\upgrade;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use PKP\core\Core;
use PKP\db\DAORegistry;
use PKP\file\FileManager;
use PKP\install\DowngradeNotSupportedException;
use PKP\site\VersionDAO;

class updatePorticoPluginNameToGeneric extends Migration
{
    private const OLD_PLUGIN_NAMES = [
        'porticoexportplugin',
        'app\plugins\importexport\portico\porticoexportplugin',
    ];

    private const OLD_PLUGIN_NAME = 'porticoexportplugin';

    private const NEW_PLUGIN_NAME = 'porticoplugin';

    private const OLD_PRODUCT_TYPE = 'plugins.importexport';

    private const PRODUCT = 'portico';

    public function up(): void
    {
        $duplicatesExist = DB::table('plugin_settings')
            ->whereIn(DB::raw('LOWER(plugin_name)'), self::OLD_PLUGIN_NAMES)
            ->count();

        if ($duplicatesExist) {
            // Prefer the unqualified name over the namespaced one; keyBy()
            // keeps the last item per key, so it must sort last (ASC).
            $records = DB::table('plugin_settings')
                ->whereIn(DB::raw('LOWER(plugin_name)'), self::OLD_PLUGIN_NAMES)
                ->orderByRaw('LOWER(plugin_name) asc')
                ->get()
                ->keyBy(fn ($record) => $record->context_id . '-' . $record->setting_name);

            DB::table('plugin_settings')
                ->whereIn(DB::raw('LOWER(plugin_name)'), self::OLD_PLUGIN_NAMES)
                ->delete();

            foreach ($records as $record) {
                DB::table('plugin_settings')->insert([
                    'plugin_name' => self::OLD_PLUGIN_NAME,
                    'context_id' => $record->context_id,
                    'setting_name' => $record->setting_name,
                    'setting_value' => $record->setting_value,
                    'setting_type' => $record->setting_type,
                ]);
            }
        }

        // Only "enabled" moves: it's read from the new plugin name now,
        // everything else is still read from the old one.
        DB::table('plugin_settings')
            ->where(DB::raw('LOWER(plugin_name)'), self::OLD_PLUGIN_NAME)
            ->where('setting_name', 'enabled')
            ->update(['plugin_name' => self::NEW_PLUGIN_NAME]);

        // Installing under the new product_type doesn't disable the old
        // version row automatically.
        /** @var VersionDAO $versionDao */
        $versionDao = DAORegistry::getDAO('VersionDAO');
        $versionDao->disableVersion(self::OLD_PRODUCT_TYPE, self::PRODUCT);

        $fileManager = new FileManager();
        $fileManager->rmtree(Core::getBaseDir() . '/plugins/importexport/portico');
    }

    /**
     * Rollback the migration.
     */
    public function down(): void
    {
        throw new DowngradeNotSupportedException();
    }
}
