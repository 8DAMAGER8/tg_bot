<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;

class Telegram
{
    private Api $apiClient;

    /**
     * @throws TelegramSDKException
     */
    public function __construct()
    {
        $this->apiClient = new Api(env('TG_BOT_TOKEN'));
    }

    /**
     * @return void
     * @throws TelegramSDKException
     */
    public function handleWebhook(): void
    {
        $updates = $this->apiClient->getWebhookUpdate();
//        $callbackQuery = $updates->callbackQuery;
//
//        if (!empty($callbackQuery)) {
//            $this->apiClient->answerCallbackQuery([
//                'callback_query_id' => $callbackQuery->id,
//                'text' => ''
//            ]);
//
//            return;
//        }

        $chatId = $updates->getMessage()->chat->id;
        $tgUserId = $updates->getMessage()->from->id;
        $firstName = $updates->getMessage()->from->first_name;

        if ($updates->getMessage()->text === '/start' || $updates->getMessage()->text === 'Главное меню') {
            $this->setStage($chatId, 'start');
            User::updateOrCreate([
                'tg_id' => $tgUserId,
                'first_name' => $firstName
            ]);
            $this->sendMainButtons($chatId);

            return;
        } elseif (!empty($location = $updates->getMessage()->location) && $this->getStage($chatId) === 'start') {
            $latitude = $location->latitude;
            $longitude = $location->longitude;
            User::updateOrCreate([
                'tg_id' => $tgUserId
            ], [
                'tg_id' => $tgUserId,
                'first_name' => $firstName,
                'latitude' => $latitude,
                'longitude' => $longitude
            ]);
            $this->sendTextMessage($chatId, "Ваши координаты широота = $latitude, долгота = $longitude.");
            Log::info($updates);

        } elseif ($updates->getMessage()->text === 'Начать поиск') {
            if (User::all()->where('tg_id', $tgUserId)->value('latitude') != '') {
                $this->setStage($chatId, 'search');
                $this->sendStagesButton($chatId);

                return;
            }
        }

        switch ($this->getStage($chatId)) {
            case 'start':
                if ($updates->getMessage()->text != '') {
                    $this->setStageMessage($chatId, 'start', $updates->getMessage()->text);
                    if (User::all()->where('tg_id', $tgUserId)->value('latitude') != '') {
                        $this->sendTextMessage($chatId, 'Нажмите \'Начать поиск\' для старта');
                    } else {
                        $this->sendTextMessage($chatId, 'Ваши координаты не определены');
                    }
                }

                break;

            case 'search':
                if ($updates->getMessage()->text != '') {
                    $this->setStageMessage($chatId, 'search', $updates->getMessage()->text);
                    $this->setStage($chatId, 'radius');
                    $this->sendTextMessage($chatId, 'Введите радиус поиска в метрах');
                } else {
                    $this->sendTextMessage($chatId, 'Введите текст поиска');
                }

                break;

            case 'radius':
                if ($updates->getMessage()->text != '') {
                    $this->setStageMessage($chatId, 'radius', $updates->getMessage()->text);
                    $this->setStage($chatId, 'result');

                    if (!empty($resultArray = $this->collectionSearch($tgUserId, $chatId))) {
                        $this->sendResultMessage($chatId, $resultArray);
                    }
                } else {
                    $this->sendTextMessage($chatId, 'Введите радиус в метрах');
                }

                break;
        }

        Log::info($updates);
        Log::debug(json_encode(Cache::get("$chatId-stage")));
    }

    /**
     * @return void
     * @throws TelegramSDKException
     */
    public function setWebhook(): void
    {
        $tgBotToken = $this->apiClient->getAccessToken();
        $appUrl = env('APP_URL');

        $this->apiClient->setWebhook([
            'url' => "$appUrl/api/webhook/$tgBotToken",
            'drop_pending_updates' => true
        ]);
    }

    /**
     * @param int $chatId
     * @param string $message
     * @return void
     */
    private function sendTextMessage(int $chatId, string $message): void
    {
        try {
            $this->apiClient->sendMessage([
                'chat_id' => $chatId,
                'text' => $message
            ]);

        } catch (TelegramSDKException $exception) {
            Log::error($exception->getMessage());
        }
    }

    /**
     * @param int $chatId
     * @param array $resultArray
     * @return void
     */
    private function sendResultMessage(int $chatId, array $resultArray): void
    {
        try {
            if ($resultArray['summary']['totalResults'] === 0) {

                $this->apiClient->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "Результат поиска не найден, вернитесь в главное меню для изменения данных поиска"
                ]);

            } else {
                foreach ($resultArray['results'] as $result) {
                    $phone = $result['poi']['phone'] ?? 'отсутствует';

                    $name = $result['poi']['name'];
                    $distance = intval($result['dist']);
                    $this->apiClient->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "Название: $name \n Расстояние до места: $distance метров \n Номер телефона: $phone",
                        'parse_mode' => 'html',
                    ]);
                }
            }
        } catch (TelegramSDKException $exception) {
            Log::error($exception->getMessage());
        }
    }

    /**
     * @param int $chatId
     * @return void
     */
    private function sendMainButtons(int $chatId): void
    {
        try {
            $this->apiClient->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Здравствуйте , Я бот который поможет вам найти любое место в нужном вам радиусе',
                'reply_markup' => json_encode([
                    'keyboard' => [
                        [
                            [
                                'text' => 'Начать поиск',
                            ],
                        ],
                        [
                            [
                                'text' => 'Отправить гео',
                                'request_location' => true,
                            ],
                        ]
                    ],
                    'one_time_keyboard' => false,
                    'resize_keyboard' => true,
                ]),
            ]);
        } catch (TelegramSDKException $exception) {
            Log::error($exception->getMessage());
        }
    }

    private function sendStagesButton($chatId): void
    {
        try {
            $this->apiClient->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Введите запрос для поиска (На английском языке 😅)',
                'reply_markup' => json_encode([
                    'keyboard' => [
                        [
                            [
                                'text' => 'Главное меню',
                            ],
                        ]
                    ],
                    'one_time_keyboard' => false,
                    'resize_keyboard' => TRUE,
                ]),
            ]);
        } catch (TelegramSDKException $exception) {
            Log::error($exception->getMessage());
        }
    }

    private function setStage(int $chatId, string $stage): void
    {
        Cache::set("$chatId-stage", $stage);
    }

    private function getStage($chatId): string
    {
        return Cache::get("$chatId-stage");
    }

    private function setStageMessage(int $chatId, string $stage, string $message): void
    {
        Cache::set("$chatId-$stage", $message);
    }

    private function getStageMessage(int $chatId, string $stage): string
    {
        return Cache::get("$chatId-$stage");
    }

    private function collectionSearch($tgUserId, $chatId): array
    {
        $apiKey = env('Search_Map_Key');

        $search = $this->getStageMessage($chatId, 'search');
        $radius = $this->getStageMessage($chatId, 'radius');
        $latitude = User::all()->where('tg_id', "$tgUserId")->value('latitude');
        $longitude = User::all()->where('tg_id', "$tgUserId")->value('longitude');

        //TODO почитать как написать свой апи клиент для том том, посмотреть, что такое DTO (передача данных о поиске в функцию при помощи объекта)
        return Http::get("https://api.tomtom.com/search/2/poiSearch/$search.json?key=$apiKey&relatedpois=all&radius=$radius&limit=15&openingHours=nextSevenDays&language=ru-RU&lat=$latitude&lon=$longitude&timezone=iana")->json();
    }
}
