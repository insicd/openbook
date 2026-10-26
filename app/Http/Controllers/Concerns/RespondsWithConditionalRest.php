<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

trait RespondsWithConditionalRest
{
    protected function conditionalResponse(Request $request, Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-cache');
        $response->setVary('Authorization');
        $response->setEtag(hash('sha256', $response->getContent()."\n".$response->headers->get('Link', '')));
        $response->isNotModified($request);

        return $response;
    }
}
