<?php

declare(strict_types=1);

namespace App\Agenda\Infra;

use App\Agenda\Contract\EnviadorDePush;
use App\Agenda\Entity\InscricaoDePush;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Web push com minishlink/web-push (VAPID, cifra aes128gcm, a única que o push da Apple aceita; Safari 16.4+ exige `urgency` e TTL
 * explícitos).
 * As chaves vêm do ambiente do servidor (VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY, VAPID_SUBJECT); sem elas,
 * `ligado()` é falso e nada é enviado.
 */
#[AsAlias(EnviadorDePush::class)]
final class EnviadorDePushMinishlink implements EnviadorDePush
{
    private const TTL_SEGUNDOS = 3600;
    private const TEMPO_SEGUNDOS = 5;
    /** 401/403: a inscrição foi feita com outra chave VAPID (troca de chave); não volta a servir. */
    private const SEM_SERVENTIA = [401, 403];

    private ?bool $vapidValida = null;

    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default::VAPID_PUBLIC_KEY)%')]
        private readonly ?string $chavePublica = null,
        #[Autowire('%env(default::VAPID_PRIVATE_KEY)%')]
        private readonly ?string $chavePrivada = null,
        #[Autowire('%env(default::VAPID_SUBJECT)%')]
        private readonly ?string $assunto = null,
    ) {
    }

    /** Ligado só com as três variáveis preenchidas e válidas (assunto mailto:/https, chaves do tamanho certo). */
    public function ligado(): bool
    {
        if ('' === (string) $this->chavePublica || '' === (string) $this->chavePrivada || '' === (string) $this->assunto) {
            return false;
        }

        return $this->vapidValida ??= $this->validarVapid();
    }

    public function chavePublica(): ?string
    {
        return $this->ligado() ? $this->chavePublica : null;
    }

    public function enviar(array $envios): array
    {
        if (!$this->ligado() || [] === $envios) {
            return [];
        }
        $webPush = $this->webPush();
        $vencidas = [];
        foreach ($envios as $e) {
            $i = $e['inscricao'];
            if (!in_array($i, $vencidas, true) && !$this->entregar($webPush, $i, $e['conteudo'])) {
                $vencidas[] = $i;
            }
        }

        return $vencidas;
    }

    private function validarVapid(): bool
    {
        try {
            if (!str_starts_with((string) $this->assunto, 'mailto:') && !str_starts_with((string) $this->assunto, 'https://')) {
                throw new \InvalidArgumentException('VAPID_SUBJECT precisa ser mailto: ou https://');
            }
            VAPID::validate($this->vapid());

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Push da Agenda desligado: chaves VAPID inválidas', ['motivo' => $e->getMessage()]);

            return false;
        }
    }

    /** @return array{subject: string, publicKey: string, privateKey: string} */
    private function vapid(): array
    {
        return ['subject' => (string) $this->assunto, 'publicKey' => (string) $this->chavePublica, 'privateKey' => (string) $this->chavePrivada];
    }

    /** Cliente com tempo curto: um serviço de push lento não segura o worker da fila. */
    private function webPush(): WebPush
    {
        $webPush = new WebPush(['VAPID' => $this->vapid()], [], new Psr18Client(HttpClient::create(['timeout' => self::TEMPO_SEGUNDOS, 'max_duration' => self::TEMPO_SEGUNDOS * 2])));
        $webPush->setReuseVAPIDHeaders(true);

        return $webPush;
    }

    /**
     * Um envio por vez: a cifra de uma inscrição torta lança exceção, e num lote ela derrubaria os avisos
     * de todo mundo. Falso quando a inscrição não serve mais (vencida, chave torta ou de outra chave VAPID).
     *
     * @param array<string, string> $conteudo
     */
    private function entregar(WebPush $webPush, InscricaoDePush $i, array $conteudo): bool
    {
        try {
            $assinatura = Subscription::create(['endpoint' => $i->getEndpoint(), 'contentEncoding' => 'aes128gcm', 'keys' => ['p256dh' => $i->getP256dh(), 'auth' => $i->getAuth()]]);
            $relatorio = $webPush->sendOneNotification($assinatura, (string) json_encode($conteudo), ['TTL' => self::TTL_SEGUNDOS, 'urgency' => 'high']);
        } catch (\Throwable $e) {
            // Falha de rede não chega aqui (vira relatório de recusa) e a VAPID foi validada em ligado(): o
            // que lança é a cifra desta inscrição (chave torta dá TypeError, ErrorException ou aviso do PHP).
            $this->logger->warning('Push da Agenda: inscrição com chave inválida, apagada', ['motivo' => $e->getMessage()]);

            return false;
        }
        $status = $relatorio->getResponse()?->getStatusCode();
        if ($relatorio->isSubscriptionExpired() || in_array($status, self::SEM_SERVENTIA, true)) {
            return false;
        }
        if (!$relatorio->isSuccess()) {
            $this->logger->warning('Push da Agenda não entregue', ['motivo' => $relatorio->getReason()]);
        }

        return true;
    }
}
