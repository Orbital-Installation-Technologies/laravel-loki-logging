<?php

namespace AlexMacArthur\LaravelLokiLogging;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Monolog\Handler\HandlerInterface;
use Monolog\Level;
use Monolog\LogRecord;

class L3Logger implements HandlerInterface
{
    /**
     * Loki rejects oversized label values, so cap every tag well below
     * the server limit. Longer context still ships, truncated.
     */
    private const MAX_TAG_VALUE_LENGTH = 1000;

    /** Grafana Cloud's limit on labels per stream. */
    private const MAX_LABELS = 15;

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /** @var resource|\fopen */
    private mixed $file;

    private bool $hasError = false;

    private array $context;

    private string $format;

    private ConfigRepository $config;

    private Application $app;

    public function __construct()
    {
        $this->config = \app('config');
        $this->app = \app();

        $this->format = $this->config->get('l3.format');
        $this->context = $this->config->get('l3.context');

        $file = $this->app->storagePath().'/'.L3ServiceProvider::LOG_LOCATION;
        if (! file_exists($file)) {
            $dir = dirname($file);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            touch($file);
        }
        $this->file = fopen($file, 'a');
        register_shutdown_function([$this, 'flush']);
    }

    /**
     * This handler is capable of handling every record
     */
    public function isHandling(LogRecord $record): bool
    {
        return true;
    }

    public function handle(LogRecord $record): bool
    {
        $this->hasError = $this->hasError || $record->level->value >= Level::Error->value;
        $recordArray = $record->toArray();
        $message = $this->formatString($this->format, $recordArray);
        // Simple values (strings, ids, flags) become labels. Anything Loki
        // would reject as a label (arrays, objects, exceptions, bad names,
        // too many labels) goes on the end of the log line instead of being
        // dropped, so the context is still searchable.
        $tags = [];
        $extra = [];
        foreach (array_merge($this->context, $recordArray['context']) as $tag => $value) {
            if (! is_scalar($value) && $value !== null) {
                $extra[$tag] = self::describe($value);

                continue;
            }

            $value = $this->formatString($value === null ? 'null' : (string) $value, $recordArray);

            // Only labels need truncating. Values that go in the line keep their full text.
            if (self::isValidLabelName($tag) && (array_key_exists($tag, $tags) || count($tags) < self::MAX_LABELS)) {
                $tags[$tag] = self::truncateTagValue($value);
            } else {
                $extra[$tag] = $value;
            }
        }

        if ($extra !== []) {
            $message .= ' '.self::encode($extra);
        }

        $line = json_encode([
            'time' => (int) (microtime(true) * 1000000),
            'tags' => $tags,
            'message' => $message,
        ], self::JSON_FLAGS)."\n";

        if (! flock($this->file, LOCK_EX)) {
            return false;
        }

        fseek($this->file, SEEK_END);
        $result = fwrite($this->file, $line);
        fflush($this->file);
        flock($this->file, LOCK_UN);

        return (bool) $result;
    }

    public function handleBatch(array $records): void
    {
        foreach ($records as $record) {
            if ($record instanceof LogRecord) {
                $this->handle($record);
            }
        }
    }

    public function flush(bool $force = false): void
    {
        if ($this->hasError || $force) {
            try {
                $persister = new L3Persister;
                $persister->handle();
            } catch (\Throwable $e) {
                error_log('L3Logger: failed to persist logs to Loki. '.$e->getMessage());
            }
        }
    }

    public function close(): void
    {
        if (is_resource($this->file)) {
            fclose($this->file);
        }
    }

    private function formatString(string $format, array $context): string
    {
        $message = $format;
        foreach ($context as $key => $value) {
            if (! is_string($value)) {
                continue;
            }
            $message = str_replace(
                sprintf('{%s}', $key),
                $value,
                $message
            );
        }

        return $message;
    }

    /**
     * Loki label names must look like identifiers and can't start with "__".
     */
    private static function isValidLabelName(int|string $name): bool
    {
        return is_string($name)
            && strlen($name) <= 1024
            && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\z/', $name) === 1
            && ! str_starts_with($name, '__');
    }

    /**
     * Readable text for context that can't be a label. Never throws, so a
     * broken __toString() or jsonSerialize() doesn't cost us the line.
     */
    private static function describe(mixed $value): mixed
    {
        try {
            return match (true) {
                $value instanceof \Throwable => sprintf(
                    '%s: %s at %s:%d',
                    $value::class,
                    $value->getMessage(),
                    $value->getFile(),
                    $value->getLine()
                ),
                $value instanceof \Stringable => (string) $value,
                // Round-trip to plain data now, so nothing can throw later
                // when the whole line is encoded.
                is_object($value) && ! $value instanceof \JsonSerializable => json_decode(self::encode((array) $value), true),
                default => json_decode(self::encode($value), true) ?? self::encode($value),
            };
        } catch (\Throwable $e) {
            return sprintf('[could not format %s: %s thrown]', get_debug_type($value), $e::class);
        }
    }

    private static function encode(mixed $value): string
    {
        $json = json_encode($value, self::JSON_FLAGS | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $json === false ? '[could not encode '.get_debug_type($value).']' : $json;
    }

    /**
     * Cap a label value so Loki never rejects the push for oversized
     * labels. Cut on a UTF-8 boundary and mark the value truncated.
     */
    private static function truncateTagValue(string $value): string
    {
        if (mb_strlen($value, 'UTF-8') <= self::MAX_TAG_VALUE_LENGTH) {
            return $value;
        }

        return mb_strcut($value, 0, self::MAX_TAG_VALUE_LENGTH, 'UTF-8').'... [truncated]';
    }
}
