<?php

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
require_once __DIR__ . '/config.php';

use Google\Auth\HttpHandler\HttpHandlerFactory;
use Google\Cloud\Core\Exception\NotFoundException;
use Google\Cloud\Storage\StorageClient;
use GuzzleHttp\Client;

class GcsStorageBackend extends FileStorageBackend {

    const BACKEND_CHAR = 'G';

    static $desc = 'Google Cloud Storage';
    static $blocksize = 131072;

    /** @var PluginConfig|null */
    static $__config;

    /** @var StorageClient|null */
    protected $_client;

    /** @var resource|object|null */
    protected $body;

    /** @var bool */
    protected $eof = false;

    /** @var int */
    protected $written = 0;

    /** @var resource|\HashContext|null */
    protected $upload_hash;

    /** @var string|null */
    protected $upload_md5_final;

    static function setConfig(PluginConfig $config) {
        static::$__config = $config;
    }

    /** @return PluginConfig */
    static function getPluginConfig() {
        return static::$__config;
    }

    /**
     * True when this plugin file is loaded from a PHAR (osTicket supports plugins as .phar).
     * Phar::running() is often empty when the archive is only included, not executed via the stub.
     */
    protected static function isPluginCodeRunningFromPhar() {
        if (strncmp(__DIR__, 'phar://', 7) === 0)
            return true;
        if (class_exists('Phar', false) && Phar::running(false) !== '')
            return true;
        return false;
    }

    /**
     * Use a host CA bundle path so HTTPS from Guzzle/cURL does not depend on code living inside a PHAR.
     */
    protected static function preferredSslCaBundlePath() {
        $paths = array();
        if (function_exists('openssl_get_cert_locations')) {
            $loc = @openssl_get_cert_locations();
            if (is_array($loc)) {
                if (!empty($loc['default_cert_file']))
                    $paths[] = $loc['default_cert_file'];
                if (!empty($loc['default_cert_dir']))
                    $paths[] = $loc['default_cert_dir'];
            }
        }
        $paths = array_merge($paths, array(
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/usr/local/share/certs/ca-root-nss.crt',
            '/var/lib/ca-certificates/ca-bundle.pem',
        ));
        foreach ($paths as $p) {
            if ($p && is_string($p) && is_readable($p))
                return $p;
        }
        return null;
    }

    /**
     * Configure Guzzle used by google/cloud-storage for PHAR installs (fixes broken TLS/CA in some environments).
     */
    protected static function applyPharFriendlyHttpOptions(array &$opts) {
        if (!static::isPluginCodeRunningFromPhar())
            return;
        $ca = static::preferredSslCaBundlePath();
        if ($ca === null)
            return;
        if (!empty($opts['httpHandler']) || !empty($opts['authHttpHandler']))
            return;
        $guzzle = array('verify' => $ca);
        $opts['httpHandler'] = HttpHandlerFactory::build(new Client($guzzle));
        $opts['authHttpHandler'] = HttpHandlerFactory::build(new Client($guzzle));
    }

    /**
     * Build StorageClient options from a single config value: inline JSON or a filesystem path to a JSON key file.
     *
     * @return array{0: array, 1: ?string, 2: ?string} [constructor options, error message, form field name for error].
     */
    static function credentialsToClientOptions($credentialsRaw) {
        $raw = trim((string) $credentialsRaw);
        if ($raw === '') {
            return array(
                array(),
                __('Provide service account JSON or a path to a JSON key file'),
            );
        }
        if (is_file($raw) && is_readable($raw)) {
            $json = @file_get_contents($raw);
            if ($json === false) {
                return array(
                    array(),
                    __('Unable to read the key file'),
                );
            }
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                return array(
                    array(),
                    __('Key file must contain valid JSON'),
                );
            }
            if (empty($decoded['private_key']) || empty($decoded['client_email'])) {
                return array(
                    array(),
                    __('Key file must contain a service account JSON with private_key and client_email'),
                );
            }
            return array(array('keyFilePath' => $raw), null, null);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return array(
                array(),
                __('Value is not valid JSON and not a readable path to a key file'),
            );
        }
        if (empty($decoded['private_key']) || empty($decoded['client_email'])) {
            return array(
                array(),
                __('Service account JSON must include private_key and client_email'),
            );
        }
        return array(array('keyFile' => $decoded), null, null);
    }

    protected function getClient() {
        if (!isset($this->_client)) {
            $cfg = static::getPluginConfig();
            list($opts, $err) = static::credentialsToClientOptions(
                $cfg->get('service-account-json', ''));
            if ($err)
                throw new RuntimeException($err);
            static::applyPharFriendlyHttpOptions($opts);
            $this->_client = new StorageClient($opts);
        }
        return $this->_client;
    }

    protected function getBucketName() {
        return static::getPluginConfig()->get('bucket');
    }

    /**
     * Normalize folder: trim slashes so keys do not start with "/".
     */
    static function normalizeFolder($folder) {
        $folder = trim((string) $folder);
        return trim($folder, '/');
    }

    function __construct($meta) {
        parent::__construct($meta);
    }

    /**
     * Unix timestamp when a signed URL should expire.
     *
     * @param int|false $relativeTtl Seconds from now (osTicket hint) or false to use defaults.
     */
    protected function resolveSignedUrlExpiry($relativeTtl) {
        $now = time();
        if ($relativeTtl)
            return $now + (int) $relativeTtl;
        $cfgTtl = (int) static::getPluginConfig()->get('signed-url-ttl', 0);
        if ($cfgTtl > 0)
            return $now + $cfgTtl;
        $day = GcsStoragePluginConfig::SIGNED_URL_END_OF_UTC_DAY_PERIOD_SECONDS;
        return $now + $day - ($now % $day);
    }

    protected function getObject() {
        $bucket = $this->getClient()->bucket($this->getBucketName());
        return $bucket->object($this->getKey());
    }

    function getKey($create=false) {
        $attrsRaw = $create ? $this->getAttrs() : $this->meta->getAttrs();
        $attrs = is_string($attrsRaw) ? JsonDataParser::parse($attrsRaw) : $attrsRaw;
        $folder = '';
        if (is_array($attrs) && isset($attrs['folder']))
            $folder = self::normalizeFolder($attrs['folder']);

        if ($folder !== '')
            return sprintf('%s/%s', $folder, $this->meta->getKey());

        return $this->meta->getKey();
    }

    function read($bytes=false, $offset=0) {
        try {
            if ($this->eof)
                return false;
            if (!$this->body)
                $this->openReadStream();
            $chunk = '';
            $bytes = $bytes ?: self::getBlockSize();
            while (strlen($chunk) < $bytes) {
                $buf = fread($this->body, $bytes - strlen($chunk));
                if ($buf === false || $buf === '')
                    break;
                $chunk .= $buf;
            }
            if ($chunk === '') {
                $this->eof = true;
                return false;
            }
            return $chunk;
        }
        catch (NotFoundException $e) {
            throw new IOException(sprintf(
                '%s: Unable to locate file: %s',
                $this->getKey(),
                $e->getMessage()));
        }
        catch (Throwable $e) {
            throw new IOException(sprintf(
                '%s: Read error: %s',
                $this->getKey(),
                $e->getMessage()));
        }
    }

    function passthru() {
        try {
            while ($block = $this->read())
                echo $block;
        }
        catch (NotFoundException $e) {
            throw new IOException(sprintf(
                '%s: Unable to locate file: %s',
                $this->getKey(),
                $e->getMessage()));
        }
    }

    function write($block) {
        if (!$this->body)
            $this->openWriteStream();
        hash_update($this->upload_hash, $block);
        $n = fwrite($this->body, $block);
        if ($n !== false)
            $this->written += strlen($block);
        return $n !== false;
    }

    function flush() {
        if (!$this->body)
            $this->openWriteStream();
        return $this->upload($this->body);
    }

    function upload($filepath) {
        if (is_resource($filepath)) {
            rewind($filepath);
        }
        elseif (is_string($filepath)) {
            $this->upload_hash = hash_init('md5');
            hash_update_file($this->upload_hash, $filepath);
            $filepath = fopen($filepath, 'rb');
            if (!$filepath)
                return false;
        }
        else {
            return false;
        }

        try {
            $bucket = $this->getClient()->bucket($this->getBucketName());
            $object = $bucket->upload($filepath, array(
                'name' => $this->getKey(true),
                'metadata' => array(
                    'contentType' => $this->meta->getType() ?: 'application/octet-stream',
                    'cacheControl' => 'private, max-age=86400',
                ),
            ));
            $this->body = null;
            $this->upload_md5_final = hash_final($this->upload_hash);
            $info = $object->info();
            if (isset($info['md5Hash'])) {
                $hex = bin2hex(base64_decode($info['md5Hash'], true));
                if ($hex !== '')
                    $this->upload_md5_final = $hex;
            }
            $this->written = (int) ($info['size'] ?? $this->written);
            return true;
        }
        catch (Throwable $e) {
            throw new IOException(sprintf(
                'Unable to upload to Google Cloud Storage: %s',
                $e->getMessage()));
        }
        finally {
            if (is_resource($filepath))
                fclose($filepath);
        }
    }

    function getNativeHashAlgos() {
        return array('md5');
    }

    function getHashDigest($algo) {
        if (strtolower((string) $algo) !== 'md5')
            return false;
        if (isset($this->upload_md5_final))
            return $this->upload_md5_final;
        try {
            $info = $this->getObject()->info();
            if (!isset($info['md5Hash']))
                return false;
            $hex = bin2hex(base64_decode($info['md5Hash'], true));
            return $hex !== '' ? $hex : false;
        }
        catch (Throwable $e) {
            return false;
        }
    }

    function sendRedirectUrl($disposition='inline', $ttl=false) {
        $expiresAt = $this->resolveSignedUrlExpiry($ttl);
        $expiry = new DateTimeImmutable('@' . $expiresAt);
        $filenamePart = Http::getDispositionFilename($this->meta->getName());
        $disp = sprintf('%s; %s', $disposition, $filenamePart);
        try {
            $url = $this->getObject()->signedUrl($expiry, array(
                'version' => 'v4',
                'method' => 'GET',
                'responseDisposition' => $disp,
            ));
            Http::redirect((string) $url);
            return true;
        }
        catch (Throwable $e) {
            return false;
        }
    }

    function unlink() {
        try {
            $this->getObject()->delete();
            return true;
        }
        catch (NotFoundException $e) {
            return true;
        }
        catch (Throwable $e) {
            throw new IOException(sprintf(
                'Unable to remove object: %s',
                $e->getMessage()));
        }
    }

    function getAttrs() {
        $cfg = static::getPluginConfig();
        $bucket = $cfg->get('bucket');
        $folder = self::normalizeFolder($cfg->get('folder'));
        return JsonDataEncoder::encode(array(
            'bucket' => $bucket,
            'folder' => $folder,
        ));
    }

    function getSize() {
        if ($this->written > 0 && !$this->body)
            return $this->written;
        try {
            $info = $this->getObject()->info();
            return (int) ($info['size'] ?? 0);
        }
        catch (Throwable $e) {
            return false;
        }
    }

    protected function openReadStream() {
        try {
            $stream = $this->getObject()->downloadAsStream();
            $this->body = $stream->detach();
            if (!$this->body)
                throw new RuntimeException('Detached stream was empty');
            $this->eof = false;
            return true;
        }
        catch (NotFoundException $e) {
            throw new IOException(sprintf(
                '%s: Unable to locate file: %s',
                $this->getKey(),
                $e->getMessage()));
        }
    }

    protected function openWriteStream() {
        $this->body = fopen('php://temp', 'r+');
        $this->written = 0;
        $this->upload_hash = hash_init('md5');
        $this->upload_md5_final = null;
    }
}

class GcsStoragePlugin extends Plugin {

    var $config_class = 'GcsStoragePluginConfig';

    function isMultiInstance() {
        return false;
    }

    function bootstrap() {
        if (!is_file(__DIR__ . '/vendor/autoload.php'))
            return;
        $cfg = $this->getConfig();
        if (!$cfg)
            return;
        GcsStorageBackend::setConfig($cfg);
        $bucket = $cfg->get('bucket');
        $folder = GcsStorageBackend::normalizeFolder($cfg->get('folder'));
        $path = $bucket . ($folder !== '' ? '/' . $folder : '');
        GcsStorageBackend::$desc = sprintf('Google Cloud Storage (%s)', $path);
        FileStorageBackend::register(GcsStorageBackend::BACKEND_CHAR, 'GcsStorageBackend');
    }
}
