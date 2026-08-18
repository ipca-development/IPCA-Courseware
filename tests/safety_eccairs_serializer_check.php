<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/safety/SafetyEccairsService.php';

$attributes = array(
    '452' => array(
        'attribute_code' => '452',
        'attribute_name' => 'File_Number',
        'datatype_code' => '1',
        'sequence_number' => 32,
        'values' => array(array('kind' => 'scalar', 'value' => 'IPCA-TEST-1')),
    ),
    '453' => array(
        'attribute_code' => '453',
        'attribute_name' => 'Responsible_Entity',
        'datatype_code' => '2',
        'value_list_code' => 'VL453',
        'sequence_number' => 33,
        'values' => array(array('kind' => 'code', 'value' => '1')),
    ),
    '601' => array(
        'attribute_code' => '601',
        'attribute_name' => 'Headline',
        'datatype_code' => '1',
        'sequence_number' => 58,
        'values' => array(array('kind' => 'scalar', 'value' => 'Serializer contract test')),
    ),
);
$canonical = array(
    'model_version' => 'ipca-eccairs-canonical-v1',
    'taxonomy' => array(
        'name' => 'ECCAIRS Aviation',
        'version' => '8.0.0.0',
        'schema_version' => '1.0.0.0',
    ),
    'source' => array(
        'occurrence_uuid' => '00000000-0000-4000-8000-000000000001',
        'assessment_id' => 1,
    ),
    'root' => array(
        'entity_code' => '24',
        'entity_name' => 'Occurrence',
        'instance_id' => '#id_number#',
        'sequence_number' => 0,
        'attributes' => $attributes,
        'entities' => array(),
    ),
);
$envelope = array(
    'type' => 'REPORT',
    'status' => 'SENT',
    'reporting_entity_id' => 1,
    'responsible_entity_id' => 1,
    'general_version' => null,
);

$rest = (new SafetyEccairsRestSerializer())->serialize($canonical, $envelope);
assertSame('REPORT', $rest['type'] ?? null, 'REST report type');
assertSame('SENT', $rest['status'] ?? null, 'REST report status');
assertSame('#id_number#', $rest['taxonomyCodes']['24']['ID'] ?? null, 'REST server-assigned ID');
assertSame(
    'Serializer contract test',
    $rest['taxonomyCodes']['24']['ATTRIBUTES']['601'][0] ?? null,
    'REST headline'
);
echo "PASS transport-neutral canonical model serializes to E2 REST JSON\n";

$archive = trim((string)getenv('ECCAIRS_TEST_TAXONOMY_ARCHIVE'));
if ($archive === '') {
    echo "SKIP XSD 1.1 XML check: ECCAIRS_TEST_TAXONOMY_ARCHIVE is not set.\n";
    exit(0);
}
$xml = (new SafetyEccairsXmlSerializer())->serialize($canonical, $archive);
$document = new DOMDocument();
if (!$document->loadXML($xml, LIBXML_NONET)) {
    throw new RuntimeException('Generated ECCAIRS XML is not well formed.');
}
assertSame('SET', $document->documentElement?->localName, 'E5X XML root');
assertSame(
    '8.0.0.0',
    $document->documentElement?->getAttribute('TaxonomyVersion'),
    'E5X taxonomy version'
);
echo "PASS canonical model serializes to XSD 1.1-valid ECCAIRS XML\n";

$canonicalJson = SafetySupport::json($canonical);
putenv('CW_ECCAIRS_TAXONOMY_ARCHIVE_PATH=' . $archive);
putenv('CW_ECCAIRS_E5X_PACKAGER_COMMAND');
putenv('CW_ECCAIRS_E5X_PACKAGER_PROFILE');
$target = sys_get_temp_dir() . '/ipca-eccairs-unconfigured-' . bin2hex(random_bytes(4)) . '.e5x';
try {
    (new SafetyEccairsE5xExporter())->export(array(
        'canonical_json' => $canonicalJson,
        'canonical_sha256' => SafetySupport::digest($canonicalJson),
        'approved_at_utc' => SafetySupport::nowUtc(),
    ), $target);
    throw new RuntimeException('E5X export did not fail closed without a packaging profile.');
} catch (SafetyException $exception) {
    assertSame(
        'eccairs_e5x_packaging_profile_unavailable',
        $exception->errorCode,
        'E5X packaging profile gate'
    );
}
echo "PASS E5X container export fails closed without an approved packaging profile\n";

function assertSame(mixed $expected, mixed $actual, string $description): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, 'FAIL ' . $description . PHP_EOL);
        exit(1);
    }
}
