<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/industrial_attachment_lib.php';

function expectSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

expectSame('2026-11-15', industrialAttachmentDate('2026-11-15'), 'Valid ISO dates should be accepted.');
expectSame(null, industrialAttachmentDate('2026-02-30'), 'Impossible calendar dates should be rejected.');
expectSame(null, industrialAttachmentDate('15/11/2026'), 'Non-ISO dates should be rejected.');
expectSame(null, industrialAttachmentDate(20261115), 'Non-string dates should be rejected.');
expectSame('Placement', industrialAttachmentRequiredText(' Placement ', 20), 'Text should be trimmed.');
expectSame(null, industrialAttachmentRequiredText('   ', 20), 'Blank text should be rejected.');
expectSame(null, industrialAttachmentRequiredText(str_repeat('x', 21), 20), 'Overlong text should be rejected.');

echo "Industrial attachment validation tests passed." . PHP_EOL;
