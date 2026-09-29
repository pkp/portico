<?php

/**
 * @file plugins/generic/portico/jobs/PorticoDeliver.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PorticoDeliver
 *
 * @ingroup jobs
 *
 * @brief Build a ZIP package and deliver it to every configured Portico endpoint.
 */

namespace APP\plugins\generic\portico\jobs;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\portico\PorticoExportPlugin;
use APP\plugins\PubObjectsExportPlugin;
use APP\publication\Publication;
use APP\submission\Submission;
use PKP\job\exceptions\JobException;
use PKP\jobs\BaseJob;
use PKP\plugins\PluginRegistry;
use Throwable;

class PorticoDeliver extends BaseJob
{
    public function __construct(
        protected string $zipFilename,
        protected int $objectId,
        protected bool $isPublication,
        protected int $contextId
    ) {
        parent::__construct();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        /** @var Submission|Publication|null $object */
        $object = $this->isPublication
            ? Repo::publication()->get($this->objectId)
            : Repo::submission()->get($this->objectId);

        if (!$object) {
            throw new JobException(JobException::INVALID_PAYLOAD);
        }

        PluginRegistry::register('importexport', new PorticoExportPlugin(), 'plugins/generic/portico/PorticoExportPlugin', $this->contextId);
        /** @var PorticoExportPlugin $plugin */
        $plugin = PluginRegistry::getPlugin('importexport', 'PorticoExportPlugin');

        if ($object->getData($plugin->getDepositStatusSettingName()) === PubObjectsExportPlugin::EXPORT_STATUS_REGISTERED) {
            return;
        }

        $context = Application::getContextDAO()->getById($this->contextId);
        $package = $plugin->createZip($object, $context);
        if (isset($package['error'])) {
            $package['error'][1] = $plugin->buildMetadataFilename($object);
            $errorMessage = $plugin->convertErrorMessage($package['error']);
            $plugin->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
            throw new JobException($errorMessage);
        }

        $endpoints = $plugin->getEndpoints($this->contextId);
        try {
            $plugin->deliverToEndpoints($package['path'], $this->zipFilename, $endpoints);
            $plugin->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_REGISTERED);
        } catch (Throwable $e) {
            $plugin->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $e->getMessage());
            throw new JobException($e->getMessage());
        } finally {
            @unlink($package['path']);
        }
    }
}
