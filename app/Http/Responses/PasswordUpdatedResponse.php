<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\PasswordUpdateResponse;
use Symfony\Component\HttpFoundation\Response;

class PasswordUpdatedResponse implements PasswordUpdateResponse
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        return $request->wantsJson()
            ? new JsonResponse('', 200)
            : back()->with('success', __('Your password has been updated.'));
    }
}
