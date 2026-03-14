<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../../services/config/Config.php';
require_once __DIR__ . '/../../../database/repositories/InternalLinkRepository.php';
require_once __DIR__ . '/../../../services/internal-links/InternalLinksQueueManager.php';
require_once __DIR__ . '/internal-links/InternalLinksSettingsHandler.php';
require_once __DIR__ . '/internal-links/SettingsSection.php';
require_once __DIR__ . '/internal-links/QueueSection.php';
require_once __DIR__ . '/internal-links/LinksListSection.php';

use WPContentAI\ContaiDatabase\Repositories\ContaiInternalLinkRepository;

class ContaiInternalLinksPanel
{
    private $settingsHandler;
    private $settingsSection;
    private $queueSection;
    private $linksListSection;

    public function __construct()
    {
        $config = ContaiConfig::getInstance();
        $repository = new ContaiInternalLinkRepository();
        $queueManager = new ContaiInternalLinksQueueManager();

        $this->settingsHandler = new ContaiInternalLinksSettingsHandler();
        $this->settingsSection = new ContaiInternalLinksSettingsSection($config);
        $this->queueSection = new ContaiInternalLinksQueueSection($queueManager);
        $this->linksListSection = new ContaiInternalLinksListSection($repository);
    }

    public function render(): void
    {
        $this->settingsHandler->handleRequest();

        ?>
        <div class="contai-settings-panel contai-panel-internal-links">
            <?php $this->settingsSection->render(); ?>
            <?php $this->queueSection->render(); ?>
            <?php $this->linksListSection->render(); ?>
        </div>
        <?php
    }
}
