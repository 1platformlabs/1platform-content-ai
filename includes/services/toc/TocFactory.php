<?php

if (!defined('ABSPATH')) exit;

final class ContaiTocFactory {

    public static function create(): ContaiTocWordPressIntegration {
        $config = new ContaiTocConfiguration();

        $parser = new ContaiHeadingParser();

        $anchor_generator = new ContaiAnchorGenerator(
            $config->shouldLowercaseAnchors(),
            $config->shouldHyphenateAnchors()
        );

        $builder = new ContaiTocBuilder();
        $injector = new ContaiContentInjector();

        $generator = new ContaiTocGenerator(
            $parser,
            $anchor_generator,
            $builder,
            $injector,
            $config
        );

        return new ContaiTocWordPressIntegration($generator, $config);
    }
}
