<?php

class GcsStoragePluginConfig extends PluginConfig {

    function getOptions() {
        return array(
            'bucket' => new TextboxField(array(
                'required' => true,
                'label' => __('GCS bucket name'),
                'hint' => __('Target bucket for attachment objects'),
                'configuration' => array('size' => 64, 'length' => 256),
            )),
            'folder' => new TextboxField(array(
                'required' => false,
                'label' => __('Object key prefix'),
                'hint' => __('Optional path prefix (no leading slash; e.g. osticket/prod)'),
                'configuration' => array('size' => 64, 'length' => 256),
            )),
            'service-account-json' => new TextareaField(array(
                'required' => true,
                'label' => __('Service account credentials'),
                'hint' => __(
                    'Paste the full service account key JSON, or an absolute path to a JSON key file readable by PHP (e.g. a mounted secret).'),
                'configuration' => array(
                    'html' => false,
                    'cols' => 80,
                    'rows' => 12,
                    'length' => 0,
                ),
            )),
            'signed-url-ttl' => new TextboxField(array(
                'required' => false,
                'label' => __('Default signed URL lifetime (seconds)'),
                'hint' => __('Leave empty to expire signed download URLs at midnight UTC (same idea as the S3 plugin). If set, used when osTicket does not pass a shorter TTL.'),
                'configuration' => array('size' => 8, 'length' => 10),
            )),
            'access-info' => new SectionBreakField(array(
                'label' => __('Credentials'),
            )),
        );
    }

    function pre_save(&$config, &$errors) {
        $ttlRaw = trim($config['signed-url-ttl'] ?? '');
        if ($ttlRaw !== '' && (!ctype_digit($ttlRaw) || (int) $ttlRaw <= 0)) {
            $this->getForm()->getField('signed-url-ttl')->addError(
                __('Enter a positive number of seconds or leave empty'));
            return false;
        }

        if ($ttlRaw === '') {
            $config['signed-url-ttl'] = '';
        }

        [
            $clientOpts,
            $credErr,
        ] = GcsStorageBackend::credentialsToClientOptions($config['service-account-json'] ?? '');
        
        if ($credErr !== null) {
            $this->getForm()->getField('service-account-json')->addError($credErr);
            return false;
        }

        if (!is_file(__DIR__ . '/vendor/autoload.php')) {
            $errors['err'] = __('Run Composer in the plugin directory to install dependencies (vendor/autoload.php missing).');
            return false;
        }

        require_once __DIR__ . '/vendor/autoload.php';
        try {
            $client = new Google\Cloud\Storage\StorageClient($clientOpts);
            $bucket = $client->bucket($config['bucket']);
            $bucket->info();
        }
        catch (Throwable $e) {
            $errors['err'] = sprintf(
                __('Unable to access bucket (check IAM and bucket name): %s'),
                $e->getMessage());
            return false;
        }
        
        return true;
    }
}
