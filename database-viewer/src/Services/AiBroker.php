<?php

namespace GreyHarbour\DatabaseViewer\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class AiBroker
{
    public function complete(array $messages): array
    {
        $token = config('database-viewer.ai_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('AI broker is not configured.');
        }

        $origin = StudioOrigin::validate(config('database-viewer.studio_origin'));
        $response = Http::acceptJson()
            ->withToken($token)
            ->withoutRedirecting()
            ->connectTimeout(3)
            ->timeout(20)
            ->post($origin.'/internal/ai', ['messages' => $messages]);
        $response->throw();

        $data = $response->json();
        if (! is_array($data) || array_is_list($data) || array_keys($data) !== ['response']
            || ! is_string($data['response']) || strlen($data['response']) > AiLimits::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('AI broker returned an invalid response.');
        }

        return ['response' => $data['response']];
    }
}
