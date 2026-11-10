<?php

namespace App\Http\Resources\Rest\V1;

use App\Application\Services\InstanceSettings;
use App\Application\Services\InstanceUploadLimits;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InstanceResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var InstanceSettings $settings */
        $settings = $this->resource;
        $baseUrl = rtrim((string) config('app.url'), '/');
        $data = [
            'instanceName' => $settings->siteName(),
            'instanceDomain' => (string) config('openbook.domain'),
            'instanceUrl' => $baseUrl,
            'instanceDescription' => $settings->siteDescription(),
            'instanceUploadLimits' => app(InstanceUploadLimits::class)->forInstance($settings),
        ];
        $icon = $settings->androidIconUrl(512);
        if ($icon !== null) {
            $data['instanceIcon'] = (string) UriResolver::resolve(new Uri($baseUrl.'/'), new Uri($icon));
        }

        return $data;
    }
}
