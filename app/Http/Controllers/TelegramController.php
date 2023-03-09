<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Telegram;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;
use Telegram\Bot\Exceptions\TelegramSDKException;

class TelegramController extends Controller
{
    /**
     * @param Telegram $telegramService
     */
    public function __construct(private Telegram $telegramService)
    {
    }

    /**
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        try {
            $this->telegramService->handleWebhook();
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());

            return Response::json($exception->getMessage(), ResponseAlias::HTTP_BAD_REQUEST);
        }

        return Response::json();
    }

    /**
     * Регестрирует вебхук с обнулением очереди
     *
     * @return JsonResponse
     */
    public function registerBot(): JsonResponse
    {
        try {
            $this->telegramService->setWebhook();
        } catch (TelegramSDKException $exception) {
            Log::error($exception->getMessage());

            return Response::json($exception->getMessage(), ResponseAlias::HTTP_BAD_REQUEST);
        }

        return Response::json();
    }
}
