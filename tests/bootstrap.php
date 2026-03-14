<?php

define('ABSPATH', '/tmp/wordpress/');
define('OBJECT', 'OBJECT');
define('ARRAY_A', 'ARRAY_A');

require_once __DIR__ . '/../vendor/autoload.php';

WP_Mock::bootstrap();

require_once __DIR__ . '/../includes/database/models/JobStatus.php';
require_once __DIR__ . '/../includes/database/models/Job.php';
require_once __DIR__ . '/../includes/database/models/Keyword.php';
require_once __DIR__ . '/../includes/database/models/InternalLink.php';
require_once __DIR__ . '/../includes/database/Database.php';
require_once __DIR__ . '/../includes/database/repositories/JobRepository.php';
require_once __DIR__ . '/../includes/database/repositories/KeywordRepository.php';
require_once __DIR__ . '/../includes/helpers/crypto.php';
require_once __DIR__ . '/../includes/helpers/security.php';
require_once __DIR__ . '/../includes/helpers/TimestampHelper.php';
require_once __DIR__ . '/../includes/helpers/JobDetailsFormatter.php';
require_once __DIR__ . '/../includes/services/config/EnvironmentDetector.php';
require_once __DIR__ . '/../includes/services/toc/HeadingParser.php';
require_once __DIR__ . '/../includes/services/toc/AnchorGenerator.php';
require_once __DIR__ . '/../includes/services/toc/TocBuilder.php';
require_once __DIR__ . '/../includes/services/toc/TocConfiguration.php';
require_once __DIR__ . '/../includes/services/internal-links/KeywordMatcher.php';
require_once __DIR__ . '/../includes/services/internal-links/ContentLinkInjector.php';
require_once __DIR__ . '/../includes/services/http/RateLimiter.php';
require_once __DIR__ . '/../includes/providers/UserProvider.php';
