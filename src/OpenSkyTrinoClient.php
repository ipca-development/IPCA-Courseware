<?php
declare(strict_types=1);

final class OpenSkyTrinoClient
{
    public function configured(): bool
    {
        return $this->username() !== '' && $this->password() !== '' && $this->host() !== '';
    }

    /**
     * @return array<string,mixed>
     */
    public function configurationSummary(): array
    {
        return array(
            'configured' => $this->configured(),
            'host' => $this->host(),
            'port' => $this->port(),
            'catalog' => $this->catalog(),
            'schema' => $this->schema(),
            'table' => $this->table(),
            'username' => $this->username() !== '' ? $this->username() : null,
            'ssl' => $this->sslEnabled(),
            'password_configured' => $this->password() !== '',
        );
    }

    public function qualifiedTable(): string
    {
        return $this->quoteIdent($this->catalog()) . '.' . $this->quoteIdent($this->schema()) . '.' . $this->quoteIdent($this->table());
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function queryRows(string $sql, int $timeoutSeconds = 120): array
    {
        if (!$this->configured()) {
            throw new RuntimeException('OpenSky Trino is not configured. Set CW_OPENSKY_TRINO_USERNAME and CW_OPENSKY_TRINO_PASSWORD.');
        }
        $response = $this->request('POST', $this->baseUrl() . '/v1/statement', $sql, $timeoutSeconds);
        return $this->collectRows($response, $timeoutSeconds);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function collectRows(array $response, int $timeoutSeconds): array
    {
        $rows = array();
        $columns = $this->columnNames($response);
        $this->appendDataRows($rows, $columns, $response);
        $nextUri = isset($response['nextUri']) ? (string)$response['nextUri'] : '';
        $started = time();
        while ($nextUri !== '') {
            if (time() - $started > $timeoutSeconds) {
                throw new RuntimeException('OpenSky Trino query timed out while fetching result pages.');
            }
            $response = $this->request('GET', $nextUri, null, max(10, $timeoutSeconds - (time() - $started)));
            if (isset($response['error']) && is_array($response['error'])) {
                $message = (string)($response['error']['message'] ?? 'OpenSky Trino query failed.');
                throw new RuntimeException($message);
            }
            $columns = $columns !== array() ? $columns : $this->columnNames($response);
            $this->appendDataRows($rows, $columns, $response);
            $nextUri = isset($response['nextUri']) ? (string)$response['nextUri'] : '';
        }
        return $rows;
    }

    /**
     * @return list<string>
     */
    private function columnNames(array $response): array
    {
        $columns = array();
        foreach (($response['columns'] ?? array()) as $column) {
            if (is_array($column) && isset($column['name'])) {
                $columns[] = (string)$column['name'];
            }
        }
        return $columns;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $columns
     */
    private function appendDataRows(array &$rows, array $columns, array $response): void
    {
        foreach (($response['data'] ?? array()) as $dataRow) {
            if (!is_array($dataRow)) {
                continue;
            }
            $row = array();
            foreach ($columns as $index => $name) {
                $row[$name] = $dataRow[$index] ?? null;
            }
            $rows[] = $row;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function request(string $method, string $url, ?string $body, int $timeoutSeconds): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Could not initialize OpenSky Trino request.');
        }
        $headers = array(
            'Accept: application/json',
            'X-Trino-User: ' . $this->username(),
            'X-Trino-Source: ' . $this->source(),
            'X-Trino-Catalog: ' . $this->catalog(),
            'X-Trino-Schema: ' . $this->schema(),
        );
        $options = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(10, $timeoutSeconds),
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERPWD => $this->username() . ':' . $this->password(),
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_SSL_VERIFYPEER => $this->sslEnabled(),
        );
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body ?? '';
            $headers[] = 'Content-Type: text/plain';
            $options[CURLOPT_HTTPHEADER] = $headers;
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('OpenSky Trino request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            $detail = trim(substr((string)$raw, 0, 500));
            throw new RuntimeException('OpenSky Trino returned HTTP ' . $status . ($detail !== '' ? ': ' . $detail : ''));
        }
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OpenSky Trino returned invalid JSON.');
        }
        if (isset($decoded['error']) && is_array($decoded['error'])) {
            throw new RuntimeException((string)($decoded['error']['message'] ?? 'OpenSky Trino query failed.'));
        }
        return $decoded;
    }

    private function baseUrl(): string
    {
        return ($this->sslEnabled() ? 'https://' : 'http://') . $this->host() . ':' . $this->port();
    }

    private function quoteIdent(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }

    private function username(): string
    {
        return trim((string)getenv('CW_OPENSKY_TRINO_USERNAME'));
    }

    private function password(): string
    {
        return trim((string)getenv('CW_OPENSKY_TRINO_PASSWORD'));
    }

    private function host(): string
    {
        return trim((string)(getenv('CW_OPENSKY_TRINO_HOST') ?: 'trino.opensky-network.org'));
    }

    private function port(): int
    {
        return (int)(getenv('CW_OPENSKY_TRINO_PORT') ?: 443);
    }

    private function catalog(): string
    {
        return trim((string)(getenv('CW_OPENSKY_TRINO_CATALOG') ?: 'minio'));
    }

    private function schema(): string
    {
        return trim((string)(getenv('CW_OPENSKY_TRINO_SCHEMA') ?: 'osky'));
    }

    private function table(): string
    {
        return trim((string)(getenv('CW_OPENSKY_TRINO_TABLE') ?: 'state_vectors_data4'));
    }

    private function source(): string
    {
        return trim((string)(getenv('CW_OPENSKY_TRINO_SOURCE') ?: 'ipca-courseware'));
    }

    private function sslEnabled(): bool
    {
        return trim((string)(getenv('CW_OPENSKY_TRINO_SSL') ?: '1')) !== '0';
    }
}
