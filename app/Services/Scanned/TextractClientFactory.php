<?php

namespace App\Services\Scanned;

use Aws\Textract\TextractClient;

class TextractClientFactory
{
    public function make(array $overrides = []): TextractClient
    {
        $config = [
            'version' => 'latest',
            'region' => (string) config('textract.region', 'us-east-1'),
        ];

        $key = trim((string) config('textract.key', ''));
        $secret = trim((string) config('textract.secret', ''));
        $sessionToken = trim((string) config('textract.session_token', ''));

        if ($key !== '' && $secret !== '') {
            $config['credentials'] = [
                'key' => $key,
                'secret' => $secret,
            ];

            if ($sessionToken !== '') {
                $config['credentials']['token'] = $sessionToken;
            }
        }

        $endpoint = trim((string) config('textract.endpoint', ''));
        if ($endpoint !== '') {
            $config['endpoint'] = $endpoint;
        }

        return new TextractClient(array_replace($config, $overrides));
    }
}
