<?php

namespace Threadwire\Facades;

use Illuminate\Support\Facades\Facade;
use Threadwire\ThreadwireClient;

/**
 * @method static array sendText(string $instanceId, string $to, string $text, array $options = [])
 * @method static array sendMedia(string $instanceId, string $to, array $media, ?string $caption = null, array $options = [])
 * @method static array sendLocation(string $instanceId, string $to, float $latitude, float $longitude, ?string $title = null, array $options = [])
 * @method static array sendContact(string $instanceId, string $to, string $name, string $phone, ?string $organization = null, array $options = [])
 * @method static array sendPoll(string $instanceId, string $to, string $name, array $answers, bool $multipleAnswers = false, array $options = [])
 * @method static array send(string $instanceId, string $to, array $content, array $options = [])
 * @method static array message(string $id)
 * @method static array messages(array $query = [])
 * @method static array|null cancelMessage(string $id)
 * @method static array|null deleteMessage(string $id)
 * @method static array instances(array $query = [])
 * @method static array instance(string $id)
 * @method static array chats(array $query = [])
 * @method static array createVerification(array $data, array $options = [])
 * @method static array verification(string $id)
 * @method static array checkVerification(string $id, string $code)
 * @method static array resendVerification(string $id)
 * @method static array json(string $method, string $path, array $data = [], ?string $idempotencyKey = null)
 * @method static \Illuminate\Http\Client\Response call(string $method, string $path, array $data = [], ?string $idempotencyKey = null)
 *
 * @see ThreadwireClient
 */
class Threadwire extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ThreadwireClient::class;
    }
}
