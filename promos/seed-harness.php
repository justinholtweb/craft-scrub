<?php
require __DIR__ . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;

$section = Craft::$app->getEntries()->getSectionByHandle('pages');
$type = Craft::$app->getEntries()->getEntryTypeByHandle('sitePage');
if (!$section || !$type) { exit("missing section/type\n"); }

$rows = [
    ['Homepage — Acme', 'Trusted by Acme Ltd since 1994, and still the only platform that lets you form a quote without a phone call.'],
    ['About Acme Ltd', 'Acme Ltd was founded in 1994. Today Acme Ltd employs 120 people across three sites, and every order still passes through the same workshop in Sheffield.'],
    ['Widget Pro', 'The widget that started it all. Every Widget we ship is tested by hand, and the WIDGET name has meant the same thing for thirty years.'],
    ['Contact Acme', 'Fill in the form below and we will be in touch. For more information about Acme Ltd, call 0800 555 0199.'],
    ['Why we rebuilt the platform', 'Published 2026-03-02. The old platform could not transform a form submission into anything useful, and Acme Ltd needed more than it could give.'],
    ['Terms of sale', 'This agreement is between you and Acme Ltd (company number 02914773) and takes effect on 2026-08-18.'],
    ['Careers at Acme', 'Acme Ltd is hiring. Send your details using the form on this page and we will read every one.'],
    ['Legal notice', 'Copyright Acme Ltd 1994 to 2026. All rights reserved.'],
    ['Delivery and returns', 'Acme Ltd delivers to the UK and Ireland. Returns are free within 30 days of dispatch.'],
    ['Press — Manufacturer of the Year', 'Acme Ltd wins Manufacturer of the Year. Acme Ltd has been making widgets in Sheffield since 1994.'],
];

$ids = [];
foreach ($rows as [$title, $body]) {
    $existing = Entry::find()->section('pages')->title($title)->status(null)->one();
    $entry = $existing ?: new Entry();
    $entry->sectionId = $section->id;
    $entry->typeId = $type->id;
    $entry->title = $title;
    $entry->enabled = true;
    $entry->setFieldValue('newsSummary', $body);
    $entry->setFieldValue('newsBody', '<p>' . $body . '</p>');

    if (!Craft::$app->getElements()->saveElement($entry)) {
        echo "FAILED {$title}: " . json_encode($entry->getErrors()) . "\n";
        continue;
    }
    $ids[] = $entry->id;
    echo ($existing ? 'updated ' : 'created ') . "#{$entry->id}  {$title}\n";
}

file_put_contents(__DIR__ . '/scrub-demo-ids.json', json_encode($ids));
echo "\nids: " . implode(',', $ids) . "\n";
