<?php

namespace App\Integrations\Telegram;

use App\Contracts\TelegramBotContract;
use App\Integrations\Telegram\Commands\Irazasyed\AddTokenCommand;
use App\Integrations\Telegram\Commands\Irazasyed\DeleteTokenCommand;
use App\Integrations\Telegram\Commands\Irazasyed\DonateCommand;
use App\Integrations\Telegram\Commands\Irazasyed\HelpCommand;
use App\Integrations\Telegram\Commands\Irazasyed\MenuCommand;
use App\Integrations\Telegram\Commands\Irazasyed\ShowChatIdCommand;
use App\Integrations\Telegram\Commands\Irazasyed\StartCommand;
use App\Integrations\Telegram\Commands\Irazasyed\WebhookCommand;
use App\Integrations\Telegram\Entities\BotCommand;
use App\Integrations\Telegram\Entities\CallbackQueryAnswer;
use App\Integrations\Telegram\Entities\ChatAction;
use App\Integrations\Telegram\Entities\OutboundMessage;
use App\Integrations\Telegram\Entities\WebhookInfo;
use App\Integrations\Telegram\Exceptions\TelegramBotException;
use App\Integrations\Telegram\Menu\MenuManager;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Objects\MessageEntity;
use Telegram\Bot\Objects\Update;
use Telegram\Bot\TelegramClient;

class IrazasyedTelegramBot implements TelegramBotContract
{
    /**
     * @var Api
     */
    private Api $telegram;

    /**
     * IrazasyedTelegramBot constructor.
     *
     * @param string $botApiKey
     *
     * @return void
     */
    public function __construct(string $botApiKey)
    {
        try {
            $this->telegram = new Api($botApiKey);

            $this->telegram->addCommands([
                StartCommand::class,
                HelpCommand::class,
                ShowChatIdCommand::class,
                AddTokenCommand::class,
                DeleteTokenCommand::class,
                MenuCommand::class,
                WebhookCommand::class,
            ]);

            if (config('services.telegram.bot.donate_command')) {
                $this->telegram->addCommand(DonateCommand::class);
            }
        } catch (TelegramSDKException $e) {
            throw new TelegramBotException($e->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setWebhook(string $hookUrl): bool
    {
        try {
            $result = $this->telegram->setWebhook([
                'url' => $hookUrl,
            ]);
        } catch (TelegramSDKException $e) {
            throw new TelegramBotException($e->getMessage());
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getWebhookInfo(): WebhookInfo
    {
        try {
            $response = $this->telegram->getWebhookInfo();
        } catch (TelegramSDKException $e) {
            throw new TelegramBotException($e->getMessage());
        }

        return new WebhookInfo($response->toArray());
    }

    /**
     * {@inheritdoc}
     */
    public function removeWebhook(): bool
    {
        try {
            $result = $this->telegram->removeWebhook();
        } catch (TelegramSDKException $e) {
            throw new TelegramBotException($e->getMessage());
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function authenticate(Request $request): void
    {
        $chat = (new Update($request->all()))->getChat();

        switch ($chat->getType()) {
            case 'private':
                $name = trim("{$chat->getFirstName()} {$chat->getLastName()}");
                break;
            case 'group':
            case 'supergroup':
            case 'channel':
                $name = $chat->getTitle();
                break;
            default:
                $name = 'Unknown name';
        }

        $user = User::findOrCreateByTelegramChatId($chat->getId(), [
            'name' => $name,
        ]);

        Auth::login($user);
    }

    /**
     * Finds current dialog and processes it.
     *
     * @param Update $update
     *
     * @return void
     */
    private function processDialog(Update $update): void
    {
        if (! $update->isType('message')) {
            return;
        }

        $currentDialog = Auth::user()->dialogs()->current()->first();

        if ($currentDialog !== null) {
            $currentDialog->nextStep($update->getMessage()->getText());
        }
    }

    /**
     * Gets callback data and sends it to menu manager.
     *
     * @param Update $update
     *
     * @return void
     */
    private function processCallbackQuery(Update $update): void
    {
        $messageId = $update->getCallbackQuery()->getMessage()->getMessageId();

        if (Auth::user()->menus()->whereMessageId($messageId)->exists()) {
            MenuManager::forMessageId($messageId)
                ->handleCallback($update->getCallbackQuery()->getId(), $update->getCallbackQuery()->getData());
        }
    }

    /**
     * Runs a command if it finds one.
     *
     * @param Update $update
     *
     * @return bool
     */
    private function processCommand(Update $update): bool
    {
        if (! $this->updateIsCommand($update)) {
            return false;
        }

        // Every command will finish all current user's dialogs and menu dialogs.
        Auth::user()->finishAllCurrentDialogs();

        $this->telegram->processCommand($update);

        return true;
    }

    /**
     * Determines if received update is a command.
     *
     * @param Update $update
     *
     * @return bool
     */
    private function updateIsCommand(Update $update): bool
    {
        if (! $update->isType('message') && ! $update->isType('channel_post')) {
            return false;
        }

        $entities = ($update->getMessage()->getEntities() ?? collect())->map(function ($entity) {
            return new MessageEntity($entity);
        });

        foreach ($entities as $entity) {
            if ($entity->getType() === 'bot_command' && $entity->getOffset() === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Removes the user from database and logout him if he was kicked from a chat.
     * Return true if user was kicked, otherwise false.
     *
     * @param Update $update
     *
     * @return bool
     */
    private function botWasKickedFromChat(Update $update): bool
    {
        $leftUser = $update->getMessage()->getLeftChatMember();

        if ($leftUser === null) {
            return false;
        }

        if ($leftUser->getIsBot() && $leftUser->getUsername() === config('services.telegram.bot.username')) {
            Auth::user()->delete();
            Auth::logout();

            return true;
        }

        return false;
    }

    /**
     * Determines if a message was sent for a dialog in some menu.
     *
     * @param Update $update
     *
     * @return bool
     */
    private function messageForMenu(Update $update): bool
    {
        $menu = Auth::user()->menus()->waitingMessage()->first();

        if ($menu === null) {
            return false;
        }

        MenuManager::forMessageId($menu->message_id)->handleMessage($update->getMessage()->getText());

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Request $request): void
    {
        $update = new Update($request->all());

        if ($update->isType('callback_query')) {
            $this->processCallbackQuery($update);

            return;
        }

        if ($update->isType('message') || $update->isType('channel_post')) {
            if (
                $this->botWasKickedFromChat($update) ||
                $this->processCommand($update) ||
                $this->messageForMenu($update)
            ) {
                return;
            }

            $this->processDialog($update);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function sendMessage(OutboundMessage $message): void
    {
        try {
            $this->telegram->sendMessage($message->toArray());
        } catch (TelegramSDKException $e) {
            throw new TelegramBotException($e->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function sendChatAction(ChatAction $chatAction): void
    {
        try {
            $this->telegram->sendChatAction($chatAction->toArray());
        } catch (TelegramSDKException $e) {
            throw new TelegramBotException($e->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function editMessage(OutboundMessage $message): void
    {
        try {
            $this->telegram->editMessageText($message->toArray());
        } catch (TelegramSDKException $e) {
            throw new TelegramBotException($e->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function answerCallbackQuery(CallbackQueryAnswer $answer): void
    {
        try {
            $this->telegram->answerCallbackQuery($answer->toArray());
        } catch (TelegramSDKException $e) {
            throw new TelegramBotException($e->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function listOfCommands(): Collection
    {
        return collect($this->telegram->getCommands())->map(function ($handler, $name) {
            return new BotCommand($name, $handler->getDescription());
        })->values();
    }

    /**
     * {@inheritdoc}
     */
    public function setMyCommands(): bool
    {
        // TODO: Make a PR to Irazasyed lib.

        $response = Http::post(TelegramClient::BASE_BOT_URL."{$this->telegram->getAccessToken()}/setMyCommands", [
            'commands' => $this->listOfCommands()->toJson(),
        ]);

        return $response->ok() && Arr::get($response->json(), 'result');
    }
}
