<?php
declare(strict_types=1);

require_once __DIR__ . '/SafetySupport.php';
require_once __DIR__ . '/SafetyAccessService.php';
require_once __DIR__ . '/SafetyAuditEventService.php';

final class SafetyEccairsConfig
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function connection(
        int $organizationId,
        string $environment,
        bool $requireTransmissionEnabled = true
    ): array
    {
        $environment = strtolower(trim($environment));
        if (!in_array($environment, array('sandbox', 'uat', 'production'), true)) {
            throw new SafetyException('validation_error', 'Invalid ECCAIRS environment.', 400);
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_safety_eccairs_connections
             WHERE organization_id = ? AND environment = ? LIMIT 1'
        );
        $stmt->execute(array($organizationId, $environment));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !(bool)$row['enabled']) {
            throw new SafetyException('eccairs_not_configured', 'ECCAIRS is not enabled for this environment.', 409);
        }
        if (
            $requireTransmissionEnabled
            && $environment === 'production'
            && !(bool)$row['production_transmission_enabled']
        ) {
            throw new SafetyException(
                'eccairs_production_disabled',
                'Production ECCAIRS transmission has not been authorized.',
                409
            );
        }
        return $row;
    }

    /** @return array{username:string,password:string,client_id:string,client_secret:string} */
    public function credentials(string $environment): array
    {
        $prefix = 'CW_ECCAIRS_' . strtoupper($environment) . '_';
        $credentials = array(
            'username' => trim((string)getenv($prefix . 'USERNAME')),
            'password' => trim((string)getenv($prefix . 'PASSWORD')),
            'client_id' => trim((string)getenv($prefix . 'CLIENT_ID')),
            'client_secret' => trim((string)getenv($prefix . 'CLIENT_SECRET')),
        );
        if (in_array('', $credentials, true)) {
            throw new SafetyException(
                'eccairs_credentials_unavailable',
                'ECCAIRS credentials are not configured.',
                503
            );
        }
        return $credentials;
    }
}

final class SafetyEccairsMapper
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $source
     * @return array{payload:array<string,mixed>,validation:array<string,mixed>}
     */
    public function build(
        int $organizationId,
        array $connection,
        string $mappingVersion,
        array $source
    ): array {
        $packageStmt = $this->pdo->prepare(
            "SELECT id, taxonomy_name, taxonomy_version, schema_version
             FROM ipca_safety_eccairs_taxonomy_packages
             WHERE organization_id = ? AND taxonomy_version = ? AND status = 'active'
             ORDER BY activated_at_utc DESC, id DESC LIMIT 1"
        );
        $packageStmt->execute(array(
            $organizationId,
            (string)$connection['taxonomy_version'],
        ));
        $package = $packageStmt->fetch(PDO::FETCH_ASSOC);
        $stmt = $this->pdo->prepare(
            'SELECT source_field, target_entity_code, target_entity_path,
                    target_attribute_code, transform_code,
                    value_map_json, required_state, a.attribute_name, a.datatype_code,
                    a.value_list_code, a.default_unit_code,
                    a.sequence_number AS attribute_sequence,
                    (SELECT e.entity_name FROM ipca_safety_eccairs_taxonomy_entities e
                     WHERE e.organization_id = m.organization_id
                       AND e.taxonomy_package_id = ?
                       AND e.entity_code = m.target_entity_code
                       AND (e.parent_path = m.target_entity_path
                            OR (m.target_entity_code = \'24\' AND e.entity_name = \'Occurrence\'))
                     ORDER BY e.id LIMIT 1) AS entity_name,
                    (SELECT e.sequence_number FROM ipca_safety_eccairs_taxonomy_entities e
                     WHERE e.organization_id = m.organization_id
                       AND e.taxonomy_package_id = ?
                       AND e.entity_code = m.target_entity_code
                       AND (e.parent_path = m.target_entity_path
                            OR (m.target_entity_code = \'24\' AND e.entity_name = \'Occurrence\'))
                     ORDER BY e.id LIMIT 1) AS entity_sequence
             FROM ipca_safety_eccairs_mappings m
             LEFT JOIN ipca_safety_eccairs_taxonomy_attributes a
               ON a.organization_id = m.organization_id
              AND a.taxonomy_package_id = ?
              AND a.entity_code = m.target_entity_code
              AND a.attribute_code = m.target_attribute_code
             WHERE m.organization_id = ? AND m.mapping_version = ?
               AND m.taxonomy_version = ? AND m.active = 1
             ORDER BY m.id'
        );
        $stmt->execute(array(
            (int)($package['id'] ?? 0),
            (int)($package['id'] ?? 0),
            (int)($package['id'] ?? 0),
            $organizationId,
            $mappingVersion,
            (string)$connection['taxonomy_version'],
        ));
        $mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $errors = array();
        if (!is_array($package)) {
            $errors[] = array(
                'code' => 'taxonomy_package_inactive',
                'field' => null,
                'message' => 'No active imported taxonomy package matches this version.',
            );
        }
        if ($mappings === array()) {
            $errors[] = array(
                'code' => 'mapping_missing',
                'field' => null,
                'message' => 'No approved mapping exists for this taxonomy version.',
            );
        }
        foreach (array('reporting_entity_id', 'taxonomy_version') as $field) {
            if (trim((string)($connection[$field] ?? '')) === '') {
                $errors[] = array(
                    'code' => 'connection_value_missing',
                    'field' => $field,
                    'message' => $field . ' is required.',
                );
            }
        }

        $rootCode = '24';
        $root = array(
            'entity_code' => $rootCode,
            'entity_name' => 'Occurrence',
            'instance_id' => '#id_number#',
            'sequence_number' => 0,
            'attributes' => array(),
            'entities' => array(),
        );
        $requiredStmt = $this->pdo->prepare(
            'SELECT entity_code, attribute_code, attribute_name, datatype_code,
                    value_list_code, sequence_number
             FROM ipca_safety_eccairs_taxonomy_attributes
             WHERE organization_id = ? AND taxonomy_package_id = ? AND min_instances > 0'
        );
        $requiredStmt->execute(array($organizationId, (int)($package['id'] ?? 0)));
        $requiredByEntity = array();
        foreach ($requiredStmt->fetchAll(PDO::FETCH_ASSOC) as $required) {
            $requiredByEntity[(string)$required['entity_code']][(string)$required['attribute_code']] = $required;
            if ((string)$required['entity_code'] !== $rootCode) {
                continue;
            }
            $automatic = match ((string)$required['attribute_name']) {
                'File_Number' => trim((string)$source['report_number']),
                'Responsible_Entity' => trim((string)($connection['responsible_entity_id'] ?? '')),
                default => '',
            };
            if ($automatic === '') {
                continue;
            }
            $root['attributes'][(string)$required['attribute_code']] = array(
                'attribute_code' => (string)$required['attribute_code'],
                'attribute_name' => (string)$required['attribute_name'],
                'datatype_code' => (string)$required['datatype_code'],
                'value_list_code' => trim((string)($required['value_list_code'] ?? '')) !== ''
                    ? trim((string)$required['value_list_code'])
                    : null,
                'unit_code' => null,
                'sequence_number' => (int)$required['sequence_number'],
                'values' => array(array(
                    'kind' => (string)$required['datatype_code'] === '2' ? 'code' : 'scalar',
                    'value' => $automatic,
                )),
            );
        }
        foreach ($mappings as $mapping) {
            $value = $this->sourceValue($source, (string)$mapping['source_field']);
            $required = (string)$mapping['required_state'] === 'required';
            if ($value === null || $value === '') {
                if ($required) {
                    $errors[] = array(
                        'code' => 'required_value_missing',
                        'field' => (string)$mapping['source_field'],
                        'message' => 'A required ECCAIRS value is missing.',
                    );
                }
                continue;
            }
            try {
                $value = $this->transform($value, $mapping);
            } catch (SafetyException $e) {
                $errors[] = array(
                    'code' => $e->errorCode,
                    'field' => (string)$mapping['source_field'],
                    'message' => $e->getMessage(),
                );
                continue;
            }
            $entityCode = (string)$mapping['target_entity_code'];
            $attributeCode = (string)$mapping['target_attribute_code'];
            if ($entityCode !== $rootCode && (string)$mapping['target_entity_path'] !== 'Occurrence') {
                $errors[] = array(
                    'code' => 'nested_entity_mapping_unsupported',
                    'field' => (string)$mapping['source_field'],
                    'message' => 'This mapping targets a nested entity path that requires an explicit instance strategy.',
                );
                continue;
            }
            if (
                trim((string)($mapping['datatype_code'] ?? '')) === ''
                || trim((string)($mapping['attribute_name'] ?? '')) === ''
                || trim((string)($mapping['entity_name'] ?? '')) === ''
            ) {
                $errors[] = array(
                    'code' => 'taxonomy_attribute_missing',
                    'field' => (string)$mapping['source_field'],
                    'message' => 'The mapped ECCAIRS attribute is absent from the active taxonomy package.',
                );
                continue;
            }
            if (in_array((string)$mapping['datatype_code'], array('8', '9', '10'), true)) {
                $errors[] = array(
                    'code' => 'structured_taxonomy_datatype_unsupported',
                    'field' => (string)$mapping['source_field'],
                    'message' => 'This mapped ECCAIRS datatype requires a structured canonical value.',
                );
                continue;
            }
            $attribute = array(
                'attribute_code' => $attributeCode,
                'attribute_name' => (string)$mapping['attribute_name'],
                'datatype_code' => (string)$mapping['datatype_code'],
                'value_list_code' => trim((string)($mapping['value_list_code'] ?? '')) ?: null,
                'unit_code' => trim((string)($mapping['default_unit_code'] ?? '')) ?: null,
                'sequence_number' => (int)$mapping['attribute_sequence'],
                'values' => array($value),
            );
            if ($entityCode === $rootCode) {
                $root['attributes'][$attributeCode] = $attribute;
                continue;
            }
            if (!isset($root['entities'][$entityCode])) {
                $root['entities'][$entityCode] = array(array(
                    'entity_code' => $entityCode,
                    'entity_name' => (string)$mapping['entity_name'],
                    'instance_id' => '#id_number#',
                    'sequence_number' => (int)$mapping['entity_sequence'],
                    'attributes' => array(),
                    'entities' => array(),
                ));
            }
            $root['entities'][$entityCode][0]['attributes'][$attributeCode] = $attribute;
        }
        $this->validateRequiredAttributes($root, $requiredByEntity, $errors);

        $canonical = array(
            'model_version' => 'ipca-eccairs-canonical-v1',
            'taxonomy' => array(
                'package_id' => (int)($package['id'] ?? 0),
                'name' => (string)($package['taxonomy_name'] ?? 'ECCAIRS Aviation'),
                'version' => (string)$connection['taxonomy_version'],
                'schema_version' => (string)($package['schema_version'] ?? ''),
            ),
            'source' => array(
                'occurrence_uuid' => (string)$source['occurrence_uuid'],
                'assessment_id' => (int)$source['assessment']['id'],
            ),
            'root' => $root,
        );

        return array(
            'canonical' => $canonical,
            'envelope' => array(
                'type' => 'REPORT',
                'status' => 'SENT',
                'reporting_entity_id' => $this->numericOrString($connection['reporting_entity_id'] ?? null),
                'responsible_entity_id' => trim((string)($connection['responsible_entity_id'] ?? '')) !== ''
                    ? $this->numericOrString($connection['responsible_entity_id'])
                    : null,
                'general_version' => trim((string)($connection['general_version'] ?? '')) ?: null,
            ),
            'validation' => array(
                'valid' => $errors === array(),
                'errors' => $errors,
                'mapping_count' => count($mappings),
                'mapping_version' => $mappingVersion,
                'taxonomy_version' => (string)($connection['taxonomy_version'] ?? ''),
            ),
        );
    }

    /** @param array<string,mixed> $source */
    private function sourceValue(array $source, string $path): mixed
    {
        $value = $source;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }
        return $value;
    }

    /** @param array<string,mixed> $mapping */
    private function transform(mixed $value, array $mapping): mixed
    {
        $transform = (string)$mapping['transform_code'];
        if ($transform === 'text') {
            return array('kind' => 'text', 'value' => trim((string)$value));
        }
        if ($transform === 'upper') {
            return array('kind' => 'scalar', 'value' => strtoupper(trim((string)$value)));
        }
        if ($transform === 'date') {
            $time = strtotime((string)$value);
            if ($time === false) {
                throw new SafetyException('eccairs_date_invalid', 'The value is not a valid date.', 400);
            }
            return array('kind' => 'date', 'value' => gmdate('Y-m-d', $time));
        }
        if ($transform === 'datetime_utc') {
            $time = strtotime((string)$value . ' UTC');
            if ($time === false) {
                throw new SafetyException('eccairs_datetime_invalid', 'The value is not a valid UTC date/time.', 400);
            }
            return array('kind' => 'datetime_utc', 'value' => gmdate('Y-m-d\TH:i:s\Z', $time));
        }
        if ($transform === 'value_map') {
            $map = json_decode((string)($mapping['value_map_json'] ?? ''), true);
            $key = (string)$value;
            if (!is_array($map) || !array_key_exists($key, $map)) {
                throw new SafetyException('eccairs_value_unmapped', 'The value has no approved taxonomy mapping.', 400);
            }
            return array('kind' => 'code', 'value' => $map[$key]);
        }
        return array('kind' => 'scalar', 'value' => $value);
    }

    /**
     * @param array<string,mixed> $entity
     * @param array<string,array<string,array<string,mixed>>> $requiredByEntity
     * @param array<int,array<string,mixed>> $errors
     */
    private function validateRequiredAttributes(
        array $entity,
        array $requiredByEntity,
        array &$errors
    ): void {
        $entityCode = (string)($entity['entity_code'] ?? '');
        foreach ($requiredByEntity[$entityCode] ?? array() as $attributeCode => $attribute) {
            if (!isset($entity['attributes'][$attributeCode])) {
                $errors[] = array(
                    'code' => 'required_taxonomy_attribute_missing',
                    'field' => null,
                    'message' => 'Required ECCAIRS attribute is missing: '
                        . (string)$attribute['attribute_name'] . '.',
                );
            }
        }
        foreach ((array)($entity['entities'] ?? array()) as $instances) {
            foreach ((array)$instances as $instance) {
                if (is_array($instance)) {
                    $this->validateRequiredAttributes($instance, $requiredByEntity, $errors);
                }
            }
        }
    }

    private function numericOrString(mixed $value): int|string|null
    {
        $value = trim((string)$value);
        return $value === '' ? null : (ctype_digit($value) ? (int)$value : $value);
    }
}

final class SafetyEccairsRestSerializer
{
    /**
     * @param array<string,mixed> $canonical
     * @param array<string,mixed> $envelope
     * @return array<string,mixed>
     */
    public function serialize(array $canonical, array $envelope): array
    {
        $taxonomy = is_array($canonical['taxonomy'] ?? null) ? $canonical['taxonomy'] : array();
        $root = is_array($canonical['root'] ?? null) ? $canonical['root'] : array();
        if (
            (string)($canonical['model_version'] ?? '') !== 'ipca-eccairs-canonical-v1'
            || (string)($root['entity_code'] ?? '') !== '24'
        ) {
            throw new SafetyException('eccairs_canonical_invalid', 'The canonical ECCAIRS model is invalid.', 500);
        }
        $payload = array(
            'type' => (string)($envelope['type'] ?? 'REPORT'),
            'status' => (string)($envelope['status'] ?? 'SENT'),
            'reportingEntityId' => $envelope['reporting_entity_id'] ?? null,
            'taxonomyCodes' => array('24' => $this->entity($root)),
            'taxonomy_version_id' => $this->numericOrString($taxonomy['version'] ?? null),
        );
        if (($envelope['responsible_entity_id'] ?? null) !== null) {
            $payload['responsibleEntityId'] = $envelope['responsible_entity_id'];
        }
        if (($envelope['general_version'] ?? null) !== null) {
            $payload['general_version_id'] = $this->numericOrString($envelope['general_version']);
        }
        return $payload;
    }

    /** @param array<string,mixed> $entity @return array<string,mixed> */
    private function entity(array $entity): array
    {
        $result = array('ID' => (string)($entity['instance_id'] ?? '#id_number#'));
        $attributes = array();
        foreach ((array)($entity['attributes'] ?? array()) as $code => $attribute) {
            if (!is_array($attribute)) {
                continue;
            }
            $attributes[(string)$code] = array_map(
                fn (mixed $value): mixed => $this->value($value),
                (array)($attribute['values'] ?? array())
            );
        }
        if ($attributes !== array()) {
            $result['ATTRIBUTES'] = $attributes;
        }
        $children = array();
        foreach ((array)($entity['entities'] ?? array()) as $code => $instances) {
            foreach ((array)$instances as $instance) {
                if (is_array($instance)) {
                    $children[(string)$code][] = $this->entity($instance);
                }
            }
        }
        if ($children !== array()) {
            $result['ENTITIES'] = $children;
        }
        return $result;
    }

    private function value(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $kind = (string)($value['kind'] ?? 'scalar');
        $content = $value['value'] ?? null;
        return $kind === 'text' ? array('text' => (string)$content) : $content;
    }

    private function numericOrString(mixed $value): int|string|null
    {
        $value = trim((string)$value);
        return $value === '' ? null : (ctype_digit($value) ? (int)$value : $value);
    }
}

final class SafetyEccairsApiClient
{
    /** @var null|callable(string,string,array<string,string>,?string):array{status:int,body:string} */
    private $transport;
    private ?string $accessToken = null;
    private ?string $refreshToken = null;

    /**
     * @param null|callable(string,string,array<string,string>,?string):array{status:int,body:string} $transport
     */
    public function __construct(
        private SafetyEccairsConfig $config,
        ?callable $transport = null
    ) {
        $this->transport = $transport;
    }

    /** @param array<string,mixed> $connection @param array<string,mixed> $payload */
    public function create(array $connection, array $payload): array
    {
        return $this->jsonRequest(
            $connection,
            'POST',
            (string)($connection['create_path'] ?? '/occurrences/create'),
            $payload
        );
    }

    /** @param array<string,mixed> $connection */
    public function get(array $connection, string $e2Id): array
    {
        return $this->jsonRequest(
            $connection,
            'GET',
            str_replace(
                '{e2id}',
                rawurlencode($e2Id),
                (string)($connection['get_path_template'] ?? '/occurrences/get/{e2id}')
            ),
            null
        );
    }

    /** @param array<string,mixed> $connection @param array<string,mixed>|null $payload */
    private function jsonRequest(array $connection, string $method, string $path, ?array $payload): array
    {
        $environment = (string)$connection['environment'];
        $token = $this->accessToken ?? $this->authenticate($connection);
        $body = $payload === null ? null : SafetySupport::json($payload);
        try {
            $response = $this->send(
                $method,
                rtrim((string)$connection['base_url'], '/') . $path,
                array('Accept' => 'application/json', 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $token),
                $body
            );
        } catch (SafetyException $e) {
            if ($method === 'POST') {
                throw new SafetyException(
                    'eccairs_delivery_uncertain',
                    'The ECCAIRS delivery outcome could not be determined.',
                    503
                );
            }
            throw $e;
        }
        if ($response['status'] === 401) {
            $token = $this->authenticate($connection, true);
            try {
                $response = $this->send(
                    $method,
                    rtrim((string)$connection['base_url'], '/') . $path,
                    array('Accept' => 'application/json', 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $token),
                    $body
                );
            } catch (SafetyException $e) {
                if ($method === 'POST') {
                    throw new SafetyException(
                        'eccairs_delivery_uncertain',
                        'The ECCAIRS delivery outcome could not be determined.',
                        503
                    );
                }
                throw $e;
            }
        }
        $decoded = json_decode($response['body'], true);
        return array(
            'http_status' => $response['status'],
            'body' => is_array($decoded) ? $decoded : array(),
            'body_sha256' => SafetySupport::digest($response['body']),
            'environment' => $environment,
        );
    }

    /** @param array<string,mixed> $connection */
    private function authenticate(array $connection, bool $preferRefresh = false): string
    {
        $credentials = $this->config->credentials((string)$connection['environment']);
        $useRefresh = $preferRefresh && $this->refreshToken !== null;
        $form = $useRefresh
            ? array('grant_type' => 'refresh_token', 'refresh_token' => $this->refreshToken)
            : array(
                'grant_type' => 'password',
                'username' => $credentials['username'],
                'password' => $credentials['password'],
            );
        $response = $this->send(
            'POST',
            rtrim((string)$connection['base_url'], '/')
                . (string)($connection['token_path'] ?? '/auth/api/token'),
            array(
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . base64_encode(
                    $credentials['client_id'] . ':' . $credentials['client_secret']
                ),
            ),
            http_build_query($form)
        );
        $decoded = json_decode($response['body'], true);
        $token = is_array($decoded) ? trim((string)($decoded['access_token'] ?? '')) : '';
        if ($response['status'] < 200 || $response['status'] >= 300 || $token === '') {
            throw new SafetyException('eccairs_authentication_failed', 'ECCAIRS authentication failed.', 503);
        }
        $this->accessToken = $token;
        $newRefresh = is_array($decoded) ? trim((string)($decoded['refresh_token'] ?? '')) : '';
        if ($newRefresh !== '') {
            $this->refreshToken = $newRefresh;
        }
        return $this->accessToken;
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string}
     */
    private function send(string $method, string $url, array $headers, ?string $body): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $headers, $body);
        }
        if (!function_exists('curl_init')) {
            throw new SafetyException('server_configuration_error', 'The ECCAIRS HTTP transport is unavailable.', 503);
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new SafetyException('eccairs_transport_error', 'Could not initialize ECCAIRS transport.', 503);
        }
        $headerLines = array();
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        curl_setopt_array($handle, array(
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => max(2, (int)(getenv('CW_ECCAIRS_CONNECT_TIMEOUT') ?: 10)),
            CURLOPT_TIMEOUT => max(5, (int)(getenv('CW_ECCAIRS_REQUEST_TIMEOUT') ?: 30)),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ));
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $responseBody = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($responseBody)) {
            throw new SafetyException(
                'eccairs_transport_error',
                'ECCAIRS could not be reached' . ($error !== '' ? ': ' . mb_substr($error, 0, 300) : '.'),
                503
            );
        }
        return array('status' => $status, 'body' => $responseBody);
    }
}

final class SafetyEccairsXmlSerializer
{
    private const DATA_NS = 'https://e2.aviationreporting.eu/data.xsd';
    private const DATATYPE_NS = 'https://e2.aviationreporting.eu/dataTypes.xsd';
    private int $nextId = 1;

    /** @param array<string,mixed> $canonical */
    public function serialize(array $canonical, string $taxonomyArchivePath): string
    {
        if (!class_exists('DOMDocument') || !class_exists('ZipArchive')) {
            throw new SafetyException('server_configuration_error', 'ECCAIRS XML extensions are unavailable.', 503);
        }
        $taxonomy = is_array($canonical['taxonomy'] ?? null) ? $canonical['taxonomy'] : array();
        $rootEntity = is_array($canonical['root'] ?? null) ? $canonical['root'] : array();
        if (
            (string)($canonical['model_version'] ?? '') !== 'ipca-eccairs-canonical-v1'
            || (string)($rootEntity['entity_code'] ?? '') !== '24'
        ) {
            throw new SafetyException('eccairs_canonical_invalid', 'The canonical ECCAIRS model is invalid.', 500);
        }
        $this->nextId = 1;
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $set = $document->createElementNS(self::DATA_NS, 'SET');
        $set->setAttribute('TaxonomyName', (string)$taxonomy['name']);
        $set->setAttribute('TaxonomyVersion', (string)$taxonomy['version']);
        $set->setAttribute('Domain', '');
        $set->setAttribute('Version', (string)$taxonomy['schema_version']);
        $document->appendChild($set);
        $set->appendChild($this->entity($document, $rootEntity));

        $xml = $document->saveXML();
        if (!is_string($xml) || $xml === '') {
            throw new SafetyException('eccairs_e5x_generation_failed', 'Could not serialize ECCAIRS XML.', 500);
        }
        $this->validateXsd11($xml, $taxonomyArchivePath);
        return $xml;
    }

    /** @param array<string,mixed> $entity */
    private function entity(DOMDocument $document, array $entity): DOMElement
    {
        $name = $this->xmlName((string)($entity['entity_name'] ?? ''));
        $element = $document->createElementNS(self::DATA_NS, $name);
        $element->setAttribute('entityId', (string)$entity['entity_code']);
        $element->setAttribute('ID', 'IPCA_' . $this->nextId++);

        $attributes = array_values(array_filter(
            (array)($entity['attributes'] ?? array()),
            'is_array'
        ));
        usort($attributes, static fn (array $a, array $b): int =>
            (int)($a['sequence_number'] ?? 0) <=> (int)($b['sequence_number'] ?? 0)
        );
        if ($attributes !== array()) {
            $container = $document->createElementNS(self::DATA_NS, 'ATTRIBUTES');
            foreach ($attributes as $attribute) {
                foreach ((array)($attribute['values'] ?? array()) as $value) {
                    $container->appendChild($this->attribute($document, $attribute, $value));
                }
            }
            $element->appendChild($container);
        }

        $groups = array();
        foreach ((array)($entity['entities'] ?? array()) as $instances) {
            foreach ((array)$instances as $instance) {
                if (is_array($instance)) {
                    $groups[] = $instance;
                }
            }
        }
        usort($groups, static fn (array $a, array $b): int =>
            (int)($a['sequence_number'] ?? 0) <=> (int)($b['sequence_number'] ?? 0)
        );
        if ($groups !== array()) {
            $container = $document->createElementNS(self::DATA_NS, 'ENTITIES');
            foreach ($groups as $child) {
                $container->appendChild($this->entity($document, $child));
            }
            $element->appendChild($container);
        }
        return $element;
    }

    /** @param array<string,mixed> $attribute */
    private function attribute(DOMDocument $document, array $attribute, mixed $value): DOMElement
    {
        $element = $document->createElementNS(
            self::DATA_NS,
            $this->xmlName((string)($attribute['attribute_name'] ?? ''))
        );
        $element->setAttribute('attributeId', (string)$attribute['attribute_code']);
        $kind = is_array($value) ? (string)($value['kind'] ?? 'scalar') : 'scalar';
        $content = is_array($value) ? ($value['value'] ?? null) : $value;
        if ((string)$attribute['datatype_code'] === '7') {
            $unit = trim((string)($attribute['unit_code'] ?? ''));
            if ($unit !== '') {
                $element->setAttribute('Unit', $unit);
            }
        }
        if ($kind === 'datetime_utc') {
            $content = rtrim((string)$content, 'Z');
        }
        if ((string)$attribute['datatype_code'] === '14' || $kind === 'text') {
            $plain = $document->createElementNS(self::DATATYPE_NS, 'PlainText');
            $plain->appendChild($document->createTextNode((string)$content));
            $element->appendChild($plain);
        } else {
            $element->appendChild($document->createTextNode((string)$content));
        }
        return $element;
    }

    private function xmlName(string $name): string
    {
        if ($name === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $name) !== 1) {
            throw new SafetyException('eccairs_taxonomy_name_invalid', 'The taxonomy contains an invalid XML name.', 500);
        }
        return $name;
    }

    private function validateXsd11(string $xml, string $archivePath): void
    {
        if ($archivePath === '' || !is_file($archivePath) || !is_readable($archivePath)) {
            throw new SafetyException('eccairs_taxonomy_archive_unavailable', 'The taxonomy archive is unavailable.', 503);
        }
        $validator = dirname(__DIR__, 2) . '/scripts/safety/eccairs_xsd11_validate.py';
        $python = trim((string)getenv('CW_ECCAIRS_XSD11_PYTHON_PATH'));
        if ($python === '') {
            $python = '/usr/bin/python3';
        }
        if (
            !is_file($validator)
            || !str_starts_with($python, '/')
            || !is_file($python)
            || !is_executable($python)
        ) {
            throw new SafetyException(
                'eccairs_xsd11_validator_unavailable',
                'The required ECCAIRS XSD 1.1 validator is not configured.',
                503
            );
        }
        $xmlPath = tempnam(sys_get_temp_dir(), 'ipca_eccairs_xml_');
        if (!is_string($xmlPath)) {
            throw new SafetyException('eccairs_e5x_generation_failed', 'Could not allocate XML validation storage.', 500);
        }
        chmod($xmlPath, 0600);
        try {
            if (file_put_contents($xmlPath, $xml, LOCK_EX) === false) {
                throw new SafetyException('eccairs_e5x_generation_failed', 'Could not stage XML validation.', 500);
            }
            $process = proc_open(
                array($python, $validator, '--archive', $archivePath, '--xml', $xmlPath),
                array(
                    0 => array('pipe', 'r'),
                    1 => array('pipe', 'w'),
                    2 => array('pipe', 'w'),
                ),
                $pipes
            );
            if (!is_resource($process)) {
                throw new SafetyException('eccairs_xsd11_validator_unavailable', 'Could not start XSD 1.1 validation.', 503);
            }
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            $result = json_decode(is_string($stdout) && $stdout !== '' ? $stdout : (string)$stderr, true);
            if ($exitCode !== 0 || !is_array($result) || empty($result['ok'])) {
                $code = is_array($result) ? (string)($result['code'] ?? '') : '';
                if ($code === 'xsd11_validator_dependency_missing' || $exitCode === 3) {
                    throw new SafetyException(
                        'eccairs_xsd11_validator_unavailable',
                        'The XSD 1.1 validator dependency is unavailable.',
                        503
                    );
                }
                throw new SafetyException(
                    'eccairs_e5x_validation_failed',
                    'The generated ECCAIRS XML did not pass XSD 1.1 taxonomy validation.',
                    409
                );
            }
        } finally {
            @unlink($xmlPath);
        }
    }
}

final class SafetyEccairsE5xExporter
{
    public function __construct(private ?SafetyEccairsXmlSerializer $serializer = null)
    {
        $this->serializer ??= new SafetyEccairsXmlSerializer();
    }

    /** @param array<string,mixed> $submission */
    public function export(array $submission, string $targetPath): array
    {
        $canonicalJson = (string)($submission['canonical_json'] ?? '');
        $canonical = json_decode($canonicalJson, true);
        if (!is_array($canonical) || trim((string)($submission['approved_at_utc'] ?? '')) === '') {
            throw new SafetyException(
                'workflow_gate_failed',
                'Only a human-approved canonical occurrence can be exported.',
                409
            );
        }
        if (!hash_equals((string)$submission['canonical_sha256'], SafetySupport::digest($canonicalJson))) {
            throw new SafetyException('eccairs_canonical_integrity_error', 'The canonical digest does not match.', 500);
        }
        $taxonomyArchive = trim((string)getenv('CW_ECCAIRS_TAXONOMY_ARCHIVE_PATH'));
        $xml = $this->serializer->serialize($canonical, $taxonomyArchive);
        $directory = dirname($targetPath);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new SafetyException('validation_error', 'The E5X target directory is not writable.', 400);
        }
        if (file_exists($targetPath)) {
            throw new SafetyException('validation_error', 'The E5X target already exists.', 409);
        }
        $packager = trim((string)getenv('CW_ECCAIRS_E5X_PACKAGER_COMMAND'));
        $profile = trim((string)getenv('CW_ECCAIRS_E5X_PACKAGER_PROFILE'));
        if (
            $packager === ''
            || $profile === ''
            || !str_starts_with($packager, '/')
            || !is_file($packager)
            || !is_executable($packager)
        ) {
            throw new SafetyException(
                'eccairs_e5x_packaging_profile_unavailable',
                'An authority-confirmed E5X packaging profile is not configured.',
                503
            );
        }
        $xmlPath = tempnam(sys_get_temp_dir(), 'ipca_eccairs_packaging_');
        if (!is_string($xmlPath)) {
            throw new SafetyException('eccairs_e5x_generation_failed', 'Could not stage validated XML.', 500);
        }
        chmod($xmlPath, 0600);
        try {
            if (file_put_contents($xmlPath, $xml, LOCK_EX) === false) {
                throw new SafetyException('eccairs_e5x_generation_failed', 'Could not stage validated XML.', 500);
            }
            $process = proc_open(
                array($packager, '--xml', $xmlPath, '--output', $targetPath),
                array(
                    0 => array('pipe', 'r'),
                    1 => array('pipe', 'w'),
                    2 => array('pipe', 'w'),
                ),
                $pipes
            );
            if (!is_resource($process)) {
                throw new SafetyException('eccairs_e5x_generation_failed', 'Could not start E5X packaging.', 500);
            }
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            if ($exitCode !== 0 || !is_file($targetPath) || filesize($targetPath) < 1) {
                @unlink($targetPath);
                throw new SafetyException(
                    'eccairs_e5x_generation_failed',
                    'The configured E5X packaging profile rejected the export.',
                    409
                );
            }
        } finally {
            @unlink($xmlPath);
        }
        $archiveHash = hash_file('sha256', $targetPath);
        return array(
            'path' => $targetPath,
            'sha256' => is_string($archiveHash) ? $archiveHash : '',
            'canonical_sha256' => (string)$submission['canonical_sha256'],
            'xml_sha256' => SafetySupport::digest($xml),
            'schema_version' => (string)($canonical['taxonomy']['schema_version'] ?? ''),
            'packaging_profile' => $profile,
        );
    }
}

final class SafetyEccairsService
{
    public function __construct(
        private PDO $pdo,
        private SafetyAccessService $access,
        private SafetyAuditEventService $events,
        private SafetyEccairsConfig $config,
        private SafetyEccairsMapper $mapper,
        private SafetyEccairsRestSerializer $restSerializer,
        private SafetyEccairsApiClient $client
    ) {
    }

    /** @param array<string,mixed> $session */
    public function prepare(
        array $session,
        int $occurrenceId,
        string $environment,
        string $mappingVersion
    ): array {
        $this->access->requirePermission($session, 'eccairs.prepare');
        $org = SafetySupport::organizationId($session);
        $environment = strtolower(trim($environment));
        $mappingVersion = SafetySupport::cleanText($mappingVersion, 64, 'mapping_version');
        $source = $this->source($org, $occurrenceId);
        if ((string)$source['assessment']['decision'] !== 'reportable') {
            throw new SafetyException(
                'workflow_gate_failed',
                'Only a human-assessed reportable occurrence can be prepared for ECCAIRS.',
                409
            );
        }
        $connection = $this->config->connection($org, $environment, false);
        $built = $this->mapper->build($org, $connection, $mappingVersion, $source);
        $canonicalJson = SafetySupport::json($built['canonical']);
        $envelopeJson = SafetySupport::json($built['envelope']);
        $validationJson = SafetySupport::json($built['validation']);
        $canonicalHash = SafetySupport::digest($canonicalJson);
        $envelopeHash = SafetySupport::digest($envelopeJson);
        $status = !empty($built['validation']['valid']) ? 'awaiting_approval' : 'validation_failed';
        $versionStmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(payload_version), 0) + 1
             FROM ipca_safety_eccairs_submissions
             WHERE organization_id = ? AND occurrence_id = ? AND environment = ?'
        );
        $versionStmt->execute(array($org, $occurrenceId, $environment));
        $payloadVersion = (int)$versionStmt->fetchColumn();
        $uuid = SafetySupport::uuid();
        try {
            $this->pdo->prepare(
                'INSERT INTO ipca_safety_eccairs_submissions
                 (organization_id, submission_uuid, occurrence_id, assessment_id, environment,
                  payload_version, mapping_version, taxonomy_version, general_version, canonical_json,
                  canonical_sha256, envelope_json, envelope_sha256, validation_json,
                  status, created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute(array(
                $org, $uuid, $occurrenceId, (int)$source['assessment']['id'], $environment,
                $payloadVersion, $mappingVersion, (string)$connection['taxonomy_version'],
                trim((string)($connection['general_version'] ?? '')) ?: null, $canonicalJson,
                $canonicalHash, $envelopeJson, $envelopeHash, $validationJson,
                $status, (int)$session['user']['id'],
            ));
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                throw new SafetyException(
                    'eccairs_duplicate_canonical',
                    'This exact canonical occurrence has already been prepared.',
                    409
                );
            }
            throw $e;
        }
        $id = (int)$this->pdo->lastInsertId();
        $this->events->append(
            $org,
            'occurrence',
            $occurrenceId,
            'eccairs.payload_prepared',
            'user',
            (int)$session['user']['id'],
            null,
            array(
                'submission_uuid' => $uuid,
                'status' => $status,
                'canonical_sha256' => $canonicalHash,
                'envelope_sha256' => $envelopeHash,
            )
        );
        return array(
            'submission_id' => $id,
            'submission_uuid' => $uuid,
            'payload_version' => $payloadVersion,
            'canonical_sha256' => $canonicalHash,
            'envelope_sha256' => $envelopeHash,
            'status' => $status,
            'validation' => $built['validation'],
        );
    }

    /** @param array<string,mixed> $session */
    public function approve(array $session, string $submissionUuid, string $rationale): array
    {
        $this->access->requirePermission($session, 'eccairs.approve');
        $org = SafetySupport::organizationId($session);
        $rationale = SafetySupport::cleanText($rationale, 12000, 'approval_rationale');
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, occurrence_id, canonical_json, canonical_sha256,
                        envelope_json, envelope_sha256,
                        validation_json, environment
                 FROM ipca_safety_eccairs_submissions
                 WHERE organization_id = ? AND submission_uuid = ? AND status = ?
                 FOR UPDATE'
            );
            $stmt->execute(array($org, strtolower(trim($submissionUuid)), 'awaiting_approval'));
            $submission = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($submission)) {
                throw new SafetyException(
                    'workflow_gate_failed',
                    'Only a valid payload awaiting approval can be approved.',
                    409
                );
            }
            $validation = json_decode((string)$submission['validation_json'], true);
            if (!is_array($validation) || empty($validation['valid'])) {
                throw new SafetyException('workflow_gate_failed', 'The canonical ECCAIRS occurrence is not valid.', 409);
            }
            if (!hash_equals(
                (string)$submission['canonical_sha256'],
                SafetySupport::digest((string)$submission['canonical_json'])
            )) {
                throw new SafetyException(
                    'eccairs_canonical_integrity_error',
                    'The canonical occurrence changed before approval.',
                    500
                );
            }
            if (!hash_equals(
                (string)$submission['envelope_sha256'],
                SafetySupport::digest((string)$submission['envelope_json'])
            )) {
                throw new SafetyException(
                    'eccairs_envelope_integrity_error',
                    'The frozen REST envelope changed before approval.',
                    500
                );
            }
            $connection = $this->config->connection($org, (string)$submission['environment']);
            if ((string)$connection['environment'] === 'production'
                && !(bool)$connection['production_transmission_enabled']) {
                throw new SafetyException('eccairs_production_disabled', 'Production transmission is disabled.', 409);
            }
            $now = SafetySupport::nowUtc();
            $this->pdo->prepare(
                'INSERT INTO ipca_safety_eccairs_approvals
                 (organization_id, submission_id, decision, canonical_sha256, rationale, decided_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute(array(
                $org, (int)$submission['id'], 'approved', (string)$submission['canonical_sha256'],
                $rationale, (int)$session['user']['id'],
            ));
            $this->pdo->prepare(
                "UPDATE ipca_safety_eccairs_submissions
                 SET status = 'queued', approved_by_user_id = ?, approved_at_utc = ?,
                     approval_rationale = ?, queued_at_utc = ?, next_attempt_at_utc = ?
                 WHERE id = ?"
            )->execute(array(
                (int)$session['user']['id'], $now, $rationale, $now, $now, (int)$submission['id'],
            ));
            $this->events->append(
                $org,
                'occurrence',
                (int)$submission['occurrence_id'],
                'eccairs.payload_approved',
                'user',
                (int)$session['user']['id'],
                null,
                array(
                    'submission_uuid' => strtolower(trim($submissionUuid)),
                    'canonical_sha256' => (string)$submission['canonical_sha256'],
                )
            );
            $this->pdo->commit();
            return array('status' => 'queued', 'queued_at_utc' => $now);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $session @param array<string,mixed> $input */
    public function proposeHistoricalCorrelation(array $session, array $input): array
    {
        $this->access->requirePermission($session, 'eccairs.historical_correlate');
        $org = SafetySupport::organizationId($session);
        $packageUuid = SafetySupport::cleanText((string)($input['package_uuid'] ?? ''), 36, 'package_uuid');
        $packageStmt = $this->pdo->prepare(
            "SELECT id, taxonomy_version FROM ipca_safety_eccairs_taxonomy_packages
             WHERE organization_id = ? AND package_uuid = ? AND status IN ('imported','active') LIMIT 1"
        );
        $packageStmt->execute(array($org, strtolower($packageUuid)));
        $package = $packageStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($package)) {
            throw new SafetyException('validation_error', 'The taxonomy package is unavailable.', 422);
        }
        $sourceSystem = SafetySupport::cleanText(
            (string)($input['source_system'] ?? 'legacy_sms'),
            96,
            'source_system'
        );
        $sourceType = SafetySupport::cleanText(
            (string)($input['source_entity_type'] ?? 'occurrence'),
            64,
            'source_entity_type'
        );
        $sourceKey = SafetySupport::cleanText((string)($input['source_key'] ?? ''), 255, 'source_key');
        $entityCode = SafetySupport::cleanText((string)($input['entity_code'] ?? ''), 64, 'entity_code');
        $attributeCode = trim((string)($input['attribute_code'] ?? '')) ?: null;
        $valueCode = trim((string)($input['value_code'] ?? '')) ?: null;
        $method = (string)($input['mapping_method'] ?? 'manual');
        if (!in_array($method, array('manual', 'rules', 'ai_assisted', 'imported'), true)) {
            throw new SafetyException('validation_error', 'The mapping method is invalid.', 422);
        }
        $confidence = filter_var($input['confidence'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($confidence === false || $confidence < 0 || $confidence > 1) {
            throw new SafetyException('validation_error', 'Confidence must be between zero and one.', 422);
        }
        $taxonomyStmt = $this->pdo->prepare(
            'SELECT a.value_list_code,
                    EXISTS(
                      SELECT 1 FROM ipca_safety_eccairs_taxonomy_entities e
                      WHERE e.organization_id = a.organization_id
                        AND e.taxonomy_package_id = a.taxonomy_package_id
                        AND e.entity_code = a.entity_code
                    ) AS entity_exists
             FROM ipca_safety_eccairs_taxonomy_attributes a
             WHERE a.organization_id = ? AND a.taxonomy_package_id = ?
               AND a.entity_code = ? AND a.attribute_code = ? LIMIT 1'
        );
        if ($attributeCode !== null) {
            $taxonomyStmt->execute(array($org, (int)$package['id'], $entityCode, $attributeCode));
            $taxonomy = $taxonomyStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($taxonomy)) {
                throw new SafetyException('validation_error', 'The taxonomy attribute does not exist.', 422);
            }
            $valueList = trim((string)($taxonomy['value_list_code'] ?? '')) ?: null;
            if ($valueCode !== null) {
                if ($valueList === null) {
                    throw new SafetyException('validation_error', 'This attribute has no coded value list.', 422);
                }
                $valueStmt = $this->pdo->prepare(
                    'SELECT 1 FROM ipca_safety_eccairs_taxonomy_values
                     WHERE organization_id = ? AND taxonomy_package_id = ?
                       AND value_list_code = ? AND value_code = ? AND active = 1 LIMIT 1'
                );
                $valueStmt->execute(array($org, (int)$package['id'], $valueList, $valueCode));
                if (!$valueStmt->fetchColumn()) {
                    throw new SafetyException('validation_error', 'The taxonomy value does not exist.', 422);
                }
            }
        } else {
            $entityStmt = $this->pdo->prepare(
                'SELECT 1 FROM ipca_safety_eccairs_taxonomy_entities
                 WHERE organization_id = ? AND taxonomy_package_id = ? AND entity_code = ? LIMIT 1'
            );
            $entityStmt->execute(array($org, (int)$package['id'], $entityCode));
            if (!$entityStmt->fetchColumn() || $valueCode !== null) {
                throw new SafetyException('validation_error', 'The taxonomy entity correlation is invalid.', 422);
            }
            $valueList = null;
        }
        $uuid = SafetySupport::uuid();
        $correlationHash = SafetySupport::digest(SafetySupport::json(array(
            'source_system' => $sourceSystem,
            'source_entity_type' => $sourceType,
            'source_key' => $sourceKey,
            'entity_code' => $entityCode,
            'attribute_code' => $attributeCode,
            'value_code' => $valueCode,
        )));
        $this->pdo->prepare(
            'INSERT INTO ipca_safety_eccairs_historical_correlations
             (organization_id, correlation_uuid, taxonomy_package_id, source_system,
              source_entity_type, source_key, target_type, target_id, entity_code,
              attribute_code, value_list_code, value_code, mapping_method, confidence,
              rationale, correlation_sha256, proposed_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(array(
            $org,
            $uuid,
            (int)$package['id'],
            $sourceSystem,
            $sourceType,
            $sourceKey,
            trim((string)($input['target_type'] ?? '')) ?: null,
            isset($input['target_id']) && (int)$input['target_id'] > 0 ? (int)$input['target_id'] : null,
            $entityCode,
            $attributeCode,
            $valueList,
            $valueCode,
            $method,
            $confidence,
            SafetySupport::cleanText((string)($input['rationale'] ?? ''), 12000, 'rationale'),
            $correlationHash,
            (int)$session['user']['id'],
        ));
        $this->events->append(
            $org,
            'historical_taxonomy_correlation',
            (int)$this->pdo->lastInsertId(),
            'eccairs.historical_correlation_proposed',
            'user',
            (int)$session['user']['id'],
            null,
            array('correlation_uuid' => $uuid, 'taxonomy_version' => $package['taxonomy_version'])
        );
        return array('correlation_uuid' => $uuid, 'status' => 'pending');
    }

    /** @param array<string,mixed> $session */
    public function reviewHistoricalCorrelation(
        array $session,
        string $correlationUuid,
        string $decision,
        string $rationale
    ): void {
        $this->access->requirePermission($session, 'eccairs.historical_review');
        $org = SafetySupport::organizationId($session);
        if (!in_array($decision, array('approved', 'rejected'), true)) {
            throw new SafetyException('validation_error', 'The review decision is invalid.', 422);
        }
        $rationale = SafetySupport::cleanText($rationale, 12000, 'rationale');
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT id, proposed_by_user_id FROM ipca_safety_eccairs_historical_correlations
                 WHERE organization_id = ? AND correlation_uuid = ? AND status = 'pending'
                 FOR UPDATE"
            );
            $stmt->execute(array($org, strtolower(trim($correlationUuid))));
            $correlation = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($correlation)) {
                throw new SafetyException('workflow_gate_failed', 'The correlation is not pending review.', 409);
            }
            if ((int)$correlation['proposed_by_user_id'] === (int)$session['user']['id']) {
                throw new SafetyException(
                    'separation_of_duties_required',
                    'A different Safety Manager must review this correlation.',
                    409
                );
            }
            $this->pdo->prepare(
                'UPDATE ipca_safety_eccairs_historical_correlations
                 SET status = ?, rationale = CONCAT(rationale, CHAR(10), CHAR(10), ?),
                     reviewed_by_user_id = ?, reviewed_at_utc = CURRENT_TIMESTAMP(3)
                 WHERE id = ?'
            )->execute(array(
                $decision,
                'Review: ' . $rationale,
                (int)$session['user']['id'],
                (int)$correlation['id'],
            ));
            $this->events->append(
                $org,
                'historical_taxonomy_correlation',
                (int)$correlation['id'],
                'eccairs.historical_correlation_' . $decision,
                'user',
                (int)$session['user']['id'],
                null,
                array('correlation_uuid' => strtolower(trim($correlationUuid)))
            );
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $session @return array<int,array<string,mixed>> */
    public function historicalCorrelations(array $session, string $status = 'pending'): array
    {
        $this->access->requirePermission($session, 'eccairs.historical_review');
        $org = SafetySupport::organizationId($session);
        if (!in_array($status, array('pending', 'approved', 'rejected'), true)) {
            throw new SafetyException('validation_error', 'The correlation status is invalid.', 422);
        }
        $stmt = $this->pdo->prepare(
            'SELECT c.correlation_uuid, c.source_system, c.source_entity_type, c.source_key,
                    c.target_type, c.target_id, c.entity_code, c.attribute_code,
                    c.value_list_code, c.value_code, c.mapping_method, c.confidence,
                    c.rationale, c.status, c.proposed_at_utc, c.reviewed_at_utc,
                    p.taxonomy_name, p.taxonomy_version
             FROM ipca_safety_eccairs_historical_correlations c
             JOIN ipca_safety_eccairs_taxonomy_packages p
               ON p.id = c.taxonomy_package_id AND p.organization_id = c.organization_id
             WHERE c.organization_id = ? AND c.status = ?
             ORDER BY c.confidence DESC, c.proposed_at_utc, c.id'
        );
        $stmt->execute(array($org, $status));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $session */
    public function retry(array $session, string $submissionUuid, string $verificationReference = ''): void
    {
        $this->access->requirePermission($session, 'eccairs.transmit');
        $org = SafetySupport::organizationId($session);
        $lookup = $this->pdo->prepare(
            "SELECT id, occurrence_id, status FROM ipca_safety_eccairs_submissions
             WHERE organization_id = ? AND submission_uuid = ? AND approved_at_utc IS NOT NULL
               AND status IN ('retry_pending','rejected','delivery_uncertain') LIMIT 1"
        );
        $lookup->execute(array($org, strtolower(trim($submissionUuid))));
        $submission = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!is_array($submission)) {
            throw new SafetyException('workflow_gate_failed', 'This submission cannot be retried.', 409);
        }
        $verificationReference = SafetySupport::cleanText(
            $verificationReference,
            1000,
            'verification_reference',
            (string)$submission['status'] === 'delivery_uncertain'
        );
        $stmt = $this->pdo->prepare(
            "UPDATE ipca_safety_eccairs_submissions
             SET status = 'queued', next_attempt_at_utc = CURRENT_TIMESTAMP(3),
                 last_error_code = NULL, last_error_summary = NULL
             WHERE id = ?"
        );
        $stmt->execute(array((int)$submission['id']));
        if ($stmt->rowCount() !== 1) {
            throw new SafetyException('workflow_gate_failed', 'This submission cannot be retried.', 409);
        }
        $this->events->append(
            $org,
            'occurrence',
            (int)$submission['occurrence_id'],
            'eccairs.retry_authorized',
            'user',
            (int)$session['user']['id'],
            null,
            array(
                'submission_uuid' => strtolower(trim($submissionUuid)),
                'previous_status' => (string)$submission['status'],
                'verification_reference' => $verificationReference,
            )
        );
    }

    public function recoverStaleSending(int $minutes = 10): int
    {
        $minutes = max(2, min(1440, $minutes));
        $stmt = $this->pdo->prepare(
            "SELECT id, organization_id, occurrence_id, submission_uuid
             FROM ipca_safety_eccairs_submissions
             WHERE status = 'sending'
               AND updated_at_utc < DATE_SUB(CURRENT_TIMESTAMP(3), INTERVAL ? MINUTE)"
        );
        $stmt->execute(array($minutes));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $recovered = 0;
        foreach ($rows as $submission) {
            $update = $this->pdo->prepare(
                "UPDATE ipca_safety_eccairs_submissions
                 SET status = 'delivery_uncertain', next_attempt_at_utc = NULL,
                     last_error_code = 'eccairs_worker_interrupted',
                     last_error_summary = 'Worker stopped during transmission; verify ECCAIRS before retrying.'
                 WHERE id = ? AND status = 'sending'"
            );
            $update->execute(array((int)$submission['id']));
            if ($update->rowCount() !== 1) {
                continue;
            }
            $recovered++;
            $this->events->append(
                (int)$submission['organization_id'],
                'occurrence',
                (int)$submission['occurrence_id'],
                'eccairs.delivery_uncertain',
                'system',
                null,
                null,
                array(
                    'submission_uuid' => $submission['submission_uuid'],
                    'reason' => 'worker_interrupted',
                )
            );
        }
        return $recovered;
    }

    /** @return array<string,mixed>|null */
    public function processNext(?string $environment = null): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $where = "status IN ('queued','retry_pending') AND approved_at_utc IS NOT NULL
                      AND next_attempt_at_utc <= CURRENT_TIMESTAMP(3)";
            $args = array();
            if ($environment !== null && trim($environment) !== '') {
                $where .= ' AND environment = ?';
                $args[] = strtolower(trim($environment));
            }
            $stmt = $this->pdo->prepare(
                'SELECT * FROM ipca_safety_eccairs_submissions WHERE ' . $where
                . ' ORDER BY queued_at_utc, id LIMIT 1 FOR UPDATE SKIP LOCKED'
            );
            $stmt->execute($args);
            $submission = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($submission)) {
                $this->pdo->commit();
                return null;
            }
            $this->pdo->prepare(
                "UPDATE ipca_safety_eccairs_submissions SET status = 'sending' WHERE id = ?"
            )->execute(array((int)$submission['id']));
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        try {
            $canonical = json_decode(
                (string)$submission['canonical_json'],
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $envelope = json_decode(
                (string)$submission['envelope_json'],
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            if (!hash_equals(
                (string)$submission['canonical_sha256'],
                SafetySupport::digest((string)$submission['canonical_json'])
            )) {
                throw new SafetyException(
                    'eccairs_canonical_integrity_error',
                    'The approved canonical digest does not match.',
                    500
                );
            }
            if (!is_array($envelope) || !hash_equals(
                (string)$submission['envelope_sha256'],
                SafetySupport::digest((string)$submission['envelope_json'])
            )) {
                throw new SafetyException(
                    'eccairs_envelope_integrity_error',
                    'The frozen REST envelope digest does not match.',
                    500
                );
            }
            $payload = $this->restSerializer->serialize($canonical, $envelope);
            $artifactJson = SafetySupport::json($payload);
            $artifactHash = SafetySupport::digest($artifactJson);
            $this->recordArtifact(
                $submission,
                'rest',
                'application/json',
                $artifactJson,
                null,
                $artifactHash,
                (string)($canonical['taxonomy']['schema_version'] ?? '')
            );
        } catch (Throwable $e) {
            $this->pdo->prepare(
                "UPDATE ipca_safety_eccairs_submissions
                 SET status = 'rejected', next_attempt_at_utc = NULL,
                     last_error_code = 'eccairs_artifact_generation_failed',
                     last_error_summary = 'Approved canonical occurrence could not be serialized.'
                 WHERE id = ? AND status = 'sending'"
            )->execute(array((int)$submission['id']));
            return array(
                'submission_uuid' => $submission['submission_uuid'],
                'status' => 'rejected',
            );
        }

        $attemptNumber = $this->nextAttemptNumber((int)$submission['id'], 'create');
        $attemptUuid = SafetySupport::uuid();
        $started = SafetySupport::nowUtc();
        $this->pdo->prepare(
            "INSERT INTO ipca_safety_eccairs_attempts
             (organization_id, submission_id, attempt_uuid, operation, attempt_number,
              request_sha256, outcome, started_at_utc)
             VALUES (?, ?, ?, 'create', ?, ?, 'started', ?)"
        )->execute(array(
            (int)$submission['organization_id'], (int)$submission['id'], $attemptUuid,
            $attemptNumber, $artifactHash, $started,
        ));
        $attemptId = (int)$this->pdo->lastInsertId();

        try {
            $connection = $this->config->connection(
                (int)$submission['organization_id'],
                (string)$submission['environment']
            );
            $response = $this->client->create($connection, $payload);
            $data = $response['body']['data'] ?? null;
            $success = $response['http_status'] >= 200 && $response['http_status'] < 300
                && (int)($response['body']['returnCode'] ?? 0) === 1
                && is_array($data)
                && trim((string)($data['e2Id'] ?? '')) !== '';
            if (!$success) {
                $summary = $this->responseSummary($response);
                $retryable = (int)$response['http_status'] === 429;
                if ((int)$response['http_status'] >= 500) {
                    $this->uncertainAttempt($submission, $attemptId, $attemptNumber, $response, $summary);
                    return array(
                        'submission_uuid' => $submission['submission_uuid'],
                        'status' => 'delivery_uncertain',
                    );
                }
                $this->failAttempt($submission, $attemptId, $attemptNumber, $response, $summary, $retryable);
                return array('submission_uuid' => $submission['submission_uuid'], 'status' => $retryable ? 'retry_pending' : 'rejected');
            }
            $now = SafetySupport::nowUtc();
            $this->pdo->beginTransaction();
            $this->pdo->prepare(
                "UPDATE ipca_safety_eccairs_attempts
                 SET response_sha256 = ?, http_status = ?, outcome = 'accepted', completed_at_utc = ?
                 WHERE id = ?"
            )->execute(array($response['body_sha256'], $response['http_status'], $now, $attemptId));
            $this->pdo->prepare(
                "UPDATE ipca_safety_eccairs_submissions
                 SET status = 'accepted', remote_e2_id = ?, remote_version = ?, remote_status = ?,
                     accepted_at_utc = ?, next_attempt_at_utc = NULL,
                     last_error_code = NULL, last_error_summary = NULL
                 WHERE id = ? AND status = 'sending'"
            )->execute(array(
                (string)$data['e2Id'], (string)($data['version'] ?? ''),
                (string)($data['status'] ?? 'SENT'), $now, (int)$submission['id'],
            ));
            $this->recordStatus(
                $submission,
                (string)$data['e2Id'],
                (string)($data['version'] ?? ''),
                (string)($data['status'] ?? 'SENT'),
                (string)$response['body_sha256']
            );
            $this->events->append(
                (int)$submission['organization_id'],
                'occurrence',
                (int)$submission['occurrence_id'],
                'eccairs.submission_accepted',
                'system',
                null,
                null,
                array('submission_uuid' => $submission['submission_uuid'], 'remote_e2_id' => (string)$data['e2Id'])
            );
            $this->pdo->commit();
            return array(
                'submission_uuid' => $submission['submission_uuid'],
                'status' => 'accepted',
                'remote_e2_id' => (string)$data['e2Id'],
            );
        } catch (Throwable $e) {
            $response = array('http_status' => 0, 'body_sha256' => null, 'body' => array());
            if ($e instanceof SafetyException && $e->errorCode === 'eccairs_delivery_uncertain') {
                $this->uncertainAttempt(
                    $submission,
                    $attemptId,
                    $attemptNumber,
                    $response,
                    $e->errorCode
                );
                return array('submission_uuid' => $submission['submission_uuid'], 'status' => 'delivery_uncertain');
            }
            $retryable = $e instanceof SafetyException
                && in_array($e->errorCode, array(
                    'eccairs_credentials_unavailable',
                    'eccairs_authentication_failed',
                    'eccairs_transport_error',
                    'eccairs_not_configured',
                ), true);
            $this->failAttempt(
                $submission,
                $attemptId,
                $attemptNumber,
                $response,
                $e instanceof SafetyException ? $e->errorCode : 'payload_error',
                $retryable
            );
            return array(
                'submission_uuid' => $submission['submission_uuid'],
                'status' => $retryable ? 'retry_pending' : 'rejected',
            );
        }
    }

    public function reconcileAccepted(int $limit = 50): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM ipca_safety_eccairs_submissions
             WHERE status = 'accepted' AND remote_e2_id IS NOT NULL
             ORDER BY updated_at_utc LIMIT " . max(1, min(200, $limit))
        );
        $stmt->execute();
        $count = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $submission) {
            $connection = $this->config->connection(
                (int)$submission['organization_id'],
                (string)$submission['environment']
            );
            $response = $this->client->get($connection, (string)$submission['remote_e2_id']);
            $data = $response['body']['data'] ?? null;
            if ($response['http_status'] < 200 || $response['http_status'] >= 300 || !is_array($data)) {
                continue;
            }
            $status = trim((string)($data['status'] ?? ''));
            if ($status === '') {
                continue;
            }
            $this->pdo->prepare(
                'UPDATE ipca_safety_eccairs_submissions
                 SET remote_version = ?, remote_status = ? WHERE id = ?'
            )->execute(array((string)($data['version'] ?? ''), $status, (int)$submission['id']));
            $this->recordStatus(
                $submission,
                (string)$submission['remote_e2_id'],
                (string)($data['version'] ?? ''),
                $status,
                (string)$response['body_sha256']
            );
            $count++;
        }
        return $count;
    }

    /** @return array<int,array<string,mixed>> */
    public function submissionsForOccurrence(int $organizationId, int $occurrenceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT submission_uuid, environment, payload_version, mapping_version, taxonomy_version,
                    canonical_sha256, envelope_sha256, validation_json, status,
                    approved_by_user_id, approved_at_utc,
                    queued_at_utc, remote_e2_id, remote_version, remote_status, accepted_at_utc,
                    last_error_code, last_error_summary, created_at_utc, updated_at_utc
             FROM ipca_safety_eccairs_submissions
             WHERE organization_id = ? AND occurrence_id = ?
             ORDER BY payload_version DESC'
        );
        $stmt->execute(array($organizationId, $occurrenceId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['validation'] = json_decode((string)$row['validation_json'], true) ?: array();
            unset($row['validation_json']);
        }
        unset($row);
        return $rows;
    }

    /** @return array<string,mixed> */
    private function source(int $organizationId, int $occurrenceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.id AS occurrence_id, o.occurrence_uuid, o.occurrence_type, o.occurred_at_utc,
                    r.id AS report_id, r.report_uuid, r.report_number, r.category_code, r.title,
                    r.narrative, r.event_at_utc, r.location_text, r.aircraft_registration,
                    r.immediate_action, r.confidentiality,
                    a.id AS assessment_id, a.framework_code, a.decision, a.rationale,
                    a.deadline_at_utc, a.assessed_at_utc
             FROM ipca_safety_occurrences o
             INNER JOIN ipca_safety_reports r
               ON r.organization_id = o.organization_id AND r.id = o.report_id
             INNER JOIN ipca_safety_reportability_assessments a ON a.id = (
               SELECT a2.id FROM ipca_safety_reportability_assessments a2
               WHERE a2.organization_id = o.organization_id AND a2.occurrence_id = o.id
               ORDER BY a2.assessed_at_utc DESC, a2.id DESC LIMIT 1
             )
             WHERE o.organization_id = ? AND o.id = ? LIMIT 1'
        );
        $stmt->execute(array($organizationId, $occurrenceId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new SafetyException('not_found', 'Assessed safety occurrence not found.', 404);
        }
        return array(
            'occurrence_uuid' => $row['occurrence_uuid'],
            'occurrence' => array(
                'type' => $row['occurrence_type'],
                'occurred_at_utc' => $row['occurred_at_utc'] ?: $row['event_at_utc'],
            ),
            'report' => array(
                'uuid' => $row['report_uuid'],
                'number' => $row['report_number'],
                'category' => $row['category_code'],
                'title' => $row['title'],
                'narrative' => $row['narrative'],
                'event_at_utc' => $row['event_at_utc'],
                'location' => $row['location_text'],
                'aircraft_registration' => $row['aircraft_registration'],
                'immediate_action' => $row['immediate_action'],
                'confidentiality' => $row['confidentiality'],
            ),
            'assessment' => array(
                'id' => (int)$row['assessment_id'],
                'framework' => $row['framework_code'],
                'decision' => $row['decision'],
                'rationale' => $row['rationale'],
                'deadline_at_utc' => $row['deadline_at_utc'],
                'assessed_at_utc' => $row['assessed_at_utc'],
            ),
        );
    }

    private function nextAttemptNumber(int $submissionId, string $operation): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(attempt_number), 0) + 1
             FROM ipca_safety_eccairs_attempts WHERE submission_id = ? AND operation = ?'
        );
        $stmt->execute(array($submissionId, $operation));
        return (int)$stmt->fetchColumn();
    }

    /** @param array<string,mixed> $submission */
    private function recordArtifact(
        array $submission,
        string $transport,
        string $contentType,
        ?string $artifactJson,
        ?string $storageReference,
        string $artifactHash,
        string $schemaVersion
    ): void {
        $versionStmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(artifact_version), 0) + 1
             FROM ipca_safety_eccairs_artifacts
             WHERE submission_id = ? AND transport = ?'
        );
        $versionStmt->execute(array((int)$submission['id'], $transport));
        $version = (int)$versionStmt->fetchColumn();
        try {
            $this->pdo->prepare(
                'INSERT INTO ipca_safety_eccairs_artifacts
                 (organization_id, submission_id, artifact_uuid, transport, artifact_version,
                  content_type, artifact_json, storage_reference, artifact_sha256, schema_version)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute(array(
                (int)$submission['organization_id'],
                (int)$submission['id'],
                SafetySupport::uuid(),
                $transport,
                $version,
                $contentType,
                $artifactJson,
                $storageReference,
                $artifactHash,
                $schemaVersion,
            ));
        } catch (PDOException $e) {
            if ((string)$e->getCode() !== '23000') {
                throw $e;
            }
        }
    }

    /** @param array<string,mixed> $submission @param array<string,mixed> $response */
    private function failAttempt(
        array $submission,
        int $attemptId,
        int $attemptNumber,
        array $response,
        string $summary,
        bool $retryable
    ): void {
        $maxAttempts = max(1, (int)(getenv('CW_ECCAIRS_MAX_ATTEMPTS') ?: 5));
        $retryable = $retryable && $attemptNumber < $maxAttempts;
        $status = $retryable ? 'retry_pending' : 'rejected';
        $delay = min(3600, 30 * (2 ** max(0, $attemptNumber - 1)));
        $next = $retryable ? gmdate('Y-m-d H:i:s', time() + $delay) . '.000' : null;
        $summary = mb_substr(trim($summary) ?: 'ECCAIRS rejected the request.', 0, 1000);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                "UPDATE ipca_safety_eccairs_attempts
                 SET response_sha256 = ?, http_status = ?, outcome = ?, error_code = ?,
                     error_summary = ?, completed_at_utc = CURRENT_TIMESTAMP(3)
                 WHERE id = ?"
            )->execute(array(
                $response['body_sha256'] ?? null, (int)($response['http_status'] ?? 0) ?: null,
                $status, 'eccairs_submission_failed', $summary, $attemptId,
            ));
            $this->pdo->prepare(
                'UPDATE ipca_safety_eccairs_submissions
                 SET status = ?, next_attempt_at_utc = ?, last_error_code = ?, last_error_summary = ?
                 WHERE id = ?'
            )->execute(array($status, $next, 'eccairs_submission_failed', $summary, (int)$submission['id']));
            $this->events->append(
                (int)$submission['organization_id'],
                'occurrence',
                (int)$submission['occurrence_id'],
                'eccairs.submission_' . $status,
                'system',
                null,
                null,
                array('submission_uuid' => $submission['submission_uuid'], 'attempt' => $attemptNumber)
            );
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $submission @param array<string,mixed> $response */
    private function uncertainAttempt(
        array $submission,
        int $attemptId,
        int $attemptNumber,
        array $response,
        string $summary
    ): void {
        $summary = mb_substr(
            'Delivery outcome is uncertain; verify ECCAIRS before manually retrying. ' . trim($summary),
            0,
            1000
        );
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                "UPDATE ipca_safety_eccairs_attempts
                 SET response_sha256 = ?, http_status = ?, outcome = 'delivery_uncertain',
                     error_code = 'eccairs_delivery_uncertain', error_summary = ?,
                     completed_at_utc = CURRENT_TIMESTAMP(3)
                 WHERE id = ?"
            )->execute(array(
                $response['body_sha256'] ?? null,
                (int)($response['http_status'] ?? 0) ?: null,
                $summary,
                $attemptId,
            ));
            $this->pdo->prepare(
                "UPDATE ipca_safety_eccairs_submissions
                 SET status = 'delivery_uncertain', next_attempt_at_utc = NULL,
                     last_error_code = 'eccairs_delivery_uncertain', last_error_summary = ?
                 WHERE id = ?"
            )->execute(array($summary, (int)$submission['id']));
            $this->events->append(
                (int)$submission['organization_id'],
                'occurrence',
                (int)$submission['occurrence_id'],
                'eccairs.delivery_uncertain',
                'system',
                null,
                null,
                array('submission_uuid' => $submission['submission_uuid'], 'attempt' => $attemptNumber)
            );
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $response */
    private function responseSummary(array $response): string
    {
        $body = $response['body'] ?? array();
        if (!is_array($body)) {
            return 'Unexpected ECCAIRS response.';
        }
        return trim((string)($body['errorDetails'] ?? $body['message'] ?? 'ECCAIRS rejected the request.'));
    }

    /** @param array<string,mixed> $submission */
    private function recordStatus(
        array $submission,
        string $e2Id,
        string $version,
        string $status,
        string $responseHash
    ): void {
        $this->pdo->prepare(
            'INSERT IGNORE INTO ipca_safety_eccairs_status_history
             (organization_id, submission_id, remote_e2_id, remote_version, remote_status, response_sha256)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute(array(
            (int)$submission['organization_id'], (int)$submission['id'],
            $e2Id, $version !== '' ? $version : null, $status, $responseHash,
        ));
    }
}
