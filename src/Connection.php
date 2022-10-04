<?php

namespace BooneStudios\Surreal;

use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Database\Connection as BaseConnection;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Connection extends BaseConnection
{
    /**
     * The Surreal database connection handler.
     *
     * @param array $config
     * @param array $options
     *
     * @var \GuzzleHttp\Client
     */
    protected $connection;

    /**
     * Create a new SurrealDB connection.
     *
     * @param array $config
     * @param array $options
     *
     * @var \GuzzleHttp\Client
     */
    protected function createConnection(array $config, array $options)
    {
        $base_uri = (! parse_url($config['host'], PHP_URL_HOST)) ? $config['host'] . ':' . $config['port'] : $config['host'];

        $clientConfig = [
            'base_uri' => $base_uri,
            'headers'  => [
                'Content-Type' => 'application/json',
                'NS'           => $config['namespace'],
                'DB'           => $config['database'],
            ],
        ];

        // Both username and password are required for Basic Auth
        if (isset($config['username'])) {
            $clientConfig['auth'] = [
                $config['username'],
                $config['password'] ?? '',
            ];
        }

        return new GuzzleClient($clientConfig);
    }

    protected function getDefaultDatabaseName()
    {
        if (empty($this->config['database'])) {
            throw new InvalidArgumentException('Database is not properly configured.');
        }

        return $this->config['database'];
    }

    /**
     * Create a new database connection instance.
     *
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;

        $options = Arr::get($config, 'options', []);

        $this->connection = $this->createConnection($config, $options);

        $this->useDefaultQueryGrammar();
        $this->useDefaultPostProcessor();
        $this->useDefaultSchemaGrammar();
    }

    /**
     * Begin a fluent query against a database collection.
     *
     * @param string $collection
     *
     * @return Query\Builder
     */
    public function collection($collection)
    {
        $query = new Query\Builder($this, $this->getPostProcessor());

        return $query->from($collection);
    }

    /**
     * Decode the response from the SurrealDB server.
     *
     * @param mixed $response
     *
     * @return mixed
     * @throws \JsonException
     */
    public function decode($response)
    {
        if (is_array($response)) {
            return $response;
        }

        return json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @inheritdoc
     */
    public function getDriverName()
    {
        return 'surrealdb';
    }

    /**
     * @inheritdoc
     */
    protected function getDefaultPostProcessor()
    {
        return new Query\Processor;
    }

    /**
     * @inheritdoc
     */
    public function getDefaultQueryGrammar()
    {
        return new Query\Grammar;
    }

    /**
     * Run a select statement against the database.
     *
     * @param string $query
     * @param array  $bindings
     * @param bool   $useReadPdo
     *
     * @return array|mixed
     */
    public function select($query, $bindings = [], $useReadPdo = false)
    {
        return $this->run($query, $bindings, function ($query, $bindings) use ($useReadPdo) {
            if ($this->pretending()) {
                return [];
            }

            foreach ($this->prepareBindings($bindings) as $key => $value) {
                $value = is_string($value) ? "'$value'" : $value;
                $query = Str::replaceFirst('?', $value, $query);
            }

            $response = $this->connection->request('POST', '/sql', [
                'body' => $query,
            ]);

            return $this->decode($response);
        });
    }

    /**
     * Execute an SQL statement and return the boolean result.
     *
     * @param string $query
     * @param array  $bindings
     * @return bool
     */
    public function statement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return true;
            }

            foreach ($this->prepareBindings($bindings) as $key => $value) {
                $value = is_string($value) ? "'$value'" : $value;
                $query = Str::replaceFirst('?', $value, $query);
            }

            $response = $this->connection->request('POST', '/sql', [
                'body' => $query,
            ]);

            $this->recordsHaveBeenModified();

            $decoded = $this->decode($response);

            return Arr::get($decoded, 'status', 'OK') === 'OK';
        });
    }

    /**
     * Begin a fluent query against a database collection.
     *
     * @param string  $table
     * @param ?string $as
     *
     * @return Query\Builder
     */
    public function table($table, $as = null)
    {
        return $this->collection($table);
    }
}