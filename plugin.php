<?php
return array(
    'id' => 'osticket:storage-gcs',
    'version' => '1.0.1',
    'ost_version' => '1.17',
    'name' => 'Attachments in Google Cloud Storage',
    'author' => 'Anatoly Melnikov',
    'description' => 'Stores attachment files in a Google Cloud Storage bucket using a service account key (inline JSON or key file path).',
    'url' => 'https://github.com/Aeliot-Tm/osticket-storage-gcs',
    'requires' => array(
        'google/cloud-storage' => array(
            'version' => '^1.42',
        ),
    ),
    'scripts' => array(),
    'plugin' => 'storage.php:GcsStoragePlugin',
);
