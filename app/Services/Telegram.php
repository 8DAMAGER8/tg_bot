<?php

namespace App\Services;

use App\DTO\TomTomDTO;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;

class Telegram
{
    private Api $tgApiClient;
    private TomTomApiClient $tomTomApiClient;

    /**
     * @throws TelegramSDKException
     */
    public function __construct()
    {
        $this->tgApiClient = new Api(env('TG_BOT_TOKEN'));
        $this->tomTomApiClient = new TomTomApiClient(env('TOM_TOM_API_KEY'));
    }

    /**
     * @return void
     * @throws TelegramSDKException
     */
    public function handleWebhook(): void
    {
        $updates = $this->tgApiClient->getWebhookUpdate();
        $chatId = $updates->getMessage()->chat->id;
        $tgUserId = $updates->getMessage()->from->id;
        $firstName = $updates->getMessage()->from->first_name;

        if ($updates->getMessage()->text === '/start') {
            User::updateOrCreate([
                'tg_id' => $tgUserId,
                'first_name' => $firstName
            ]);
            $this->sendTextMessage($chatId, 'Главное меню');

            return;
        } elseif ($updates->getMessage()->text === 'Главное меню') {
            $this->setStage($chatId, 'start');
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
                if (empty($updates->getMessage()->text) && !is_numeric($updates->getMessage()->text)) {
                    $this->setStageMessage($chatId, 'search', $updates->getMessage()->text);
                    $this->setStage($chatId, 'radius');
                    $this->sendTextMessage($chatId, 'Введите радиус поиска в метрах');
                } else {
                    $this->sendTextMessage($chatId, 'Введите текст поиска (на английском языке)');
                }

                break;

            case 'radius':
                if (is_numeric($updates->getMessage()->text)) {
                    $this->setStageMessage($chatId, 'radius', $updates->getMessage()->text);
                    $this->setStage($chatId, 'result');

                    if (!empty($resultArray = $this->collectionSearch($tgUserId, $chatId))) {
                        $this->sendResultMessage($chatId, $resultArray);
                    }

                } else {
                    $this->sendTextMessage($chatId, 'Введите радиус поиска в метрах');
                }

                break;
        }
    }

    /**
     * @return void
     * @throws TelegramSDKException
     */
    public function setWebhook(): void
    {
        $tgBotToken = $this->tgApiClient->getAccessToken();
        $appUrl = env('APP_URL');

        $this->tgApiClient->setWebhook([
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
            $this->tgApiClient->sendMessage([
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

                $this->tgApiClient->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "Результат поиска не найден, вернитесь в главное меню для изменения данных поиска"
                ]);

            } else {
                foreach ($resultArray['results'] as $result) {
                    $phone = $result['poi']['phone'] ?? 'отсутствует';
                    $municipality = $result['address']['municipality'] ?? 'отсутствует';
                    $streetName = $result['address']['streetName'] ?? 'ул. отсутствует';
                    $streetNumber = $result['address']['streetNumber'] ?? 'отсутствует';

                    $name = $result['poi']['name'];
                    $distance = intval($result['dist']);
                    if (isset($result['poi']['openingHours']['timeRanges'][0])) {
                        $openingTime = $result['poi']['openingHours']['timeRanges'][0]['startTime'];
                        $closingTime = $result['poi']['openingHours']['timeRanges'][0]['endTime'];
                        $openingHour = $openingTime['hour'];
                        if ($openingTime['minute'] === 0) {
                            $openingMinute = '00';
                        } else {
                            $openingMinute = $openingTime['minute'];
                        }

                        $closingHour = $closingTime['hour'];
                        if ($closingTime['minute'] === 0) {
                            $closingMinute = '00';
                        } else {
                            $closingMinute = $closingTime['minute'];
                        }

                        $this->tgApiClient->sendMessage([
                            'chat_id' => $chatId,
                            'text' => "Название: $name\nНомер телефона: $phone\nАдрес: г.$municipality, $streetName, дом $streetNumber \nРасстояние до места: $distance метров \nВремя работы: $openingHour:$openingMinute - $closingHour:$closingMinute ",
                            'parse_mode' => 'html',
                        ]);
                    } else {
                        $this->tgApiClient->sendMessage([
                            'chat_id' => $chatId,
                            'text' => "Название: $name\nНомер телефона: $phone\nАдрес: г.$municipality, $streetName, дом $streetNumber \nРасстояние до места: $distance метров \nВремя работы отсутствует",
                            'parse_mode' => 'html',
                        ]);
                    }
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
            $this->tgApiClient->sendMessage([
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


    /**
     * @param int $chatId
     * @return void
     */
    private function sendStagesButton(int $chatId): void
    {
        try {
            $this->tgApiClient->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Введите запрос для поиска (На английском языке)',
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

    /**
     * @param int $chatId
     * @param string $stage
     * @return void
     */
    private function setStage(int $chatId, string $stage): void
    {
        Cache::set("$chatId-stage", $stage);
    }

    /**
     * @param $chatId
     * @return string
     */
    private function getStage($chatId): string
    {
        return Cache::get("$chatId-stage");
    }

    /**
     * @param int $chatId
     * @param string $stage
     * @param string $message
     * @return void
     */
    private function setStageMessage(int $chatId, string $stage, string $message): void
    {
        Cache::set("$chatId-$stage", $message);
    }

    /**
     * @param int $chatId
     * @param string $stage
     * @return string
     */
    private function getStageMessage(int $chatId, string $stage): string
    {
        return Cache::get("$chatId-$stage");
    }


    /**
     * @param int $tgUserId
     * @param int $chatId
     * @return array
     */
    private function collectionSearch(int $tgUserId, int $chatId): array
    {
        $tgUser = User::where('tg_id', "$tgUserId")->first();

        $tomTomDto = new TomTomDTO();
        $tomTomDto->setSearch($this->getStageMessage($chatId, 'search'));
        $tomTomDto->setRadius($this->getStageMessage($chatId, 'radius'));
        $tomTomDto->setLatitude($tgUser->latitude);
        $tomTomDto->setLongitude($tgUser->longitude);

        return $this->tomTomApiClient->poiSearch($tomTomDto);
    }
}
