<?php

namespace App\Http\Requests;

use App\Http\Controllers\RfidController;
use App\Http\Requests\Concerns\NormalisesRfid;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class RelayRfidScanRequest extends FormRequest
{
    use NormalisesRfid;

    public const CHANNEL_CLOSED_MESSAGE = 'RFID request cannot be sent. Admin channel is closed.';

    public const ERROR_STATUS = 'error';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // authorize() runs before the rules, so a closed window answers 403 before any 422
        return Cache::has(RfidController::CHANNEL_CACHE_KEY);
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'status' => self::ERROR_STATUS,
            'message' => self::CHANNEL_CLOSED_MESSAGE,
        ], Response::HTTP_FORBIDDEN));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['rfid' => $this->buildRfidRules()];
    }
}
