<?php

declare(strict_types=1);

namespace App\Agenda\UseCase;

use App\Agenda\DTO\InscricaoDePushInput;
use App\Agenda\Entity\InscricaoDePush;
use App\Agenda\Repository\InscricaoDePushRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * História: depois de a pessoa permitir as notificações (clique em "Testar lembretes" ou "Salvar" na
 * Agenda), o navegador se inscreve no push e manda o endereço e as chaves. Guardamos uma linha por
 * navegador: se o mesmo navegador passa a outra pessoa ou outro escritório, a linha muda de dono. A varredura
 * do push roda a cada minuto pelo schedule `cadeias`, com ou sem inscrição nova.
 *
 * O servidor vai fazer POST nesse endereço e cifrar com essas chaves: só aceitamos endereço https de um
 * serviço de push conhecido (o servidor não sai chamando endereço qualquer) e chaves com o tamanho certo
 * (uma chave torta derrubaria a cifra do envio).
 */
final class InscreverPushUseCase
{
    private const FUSO_PADRAO = 'America/Sao_Paulo';

    /** Serviços de push dos navegadores: Chrome/Edge/Android, Firefox, Safari/iOS e Windows. */
    private const SERVICOS = ['fcm.googleapis.com', 'push.services.mozilla.com', 'push.apple.com', 'notify.windows.com'];

    private const BYTES_P256DH = 65;
    private const BYTES_AUTH = 16;

    public function __construct(
        private readonly InscricaoDePushRepository $inscricoes,
    ) {
    }

    public function executar(Tenant $tenant, User $eu, InscricaoDePushInput $input): void
    {
        $this->exigirServicoDePush($input->endpoint);
        $this->exigirChaves($input->p256dh, $input->auth);
        $fuso = in_array($input->fuso, \DateTimeZone::listIdentifiers(), true) ? $input->fuso : self::FUSO_PADRAO;
        $this->inscricoes->gravar(new InscricaoDePush($tenant, $eu, $input->endpoint, $input->p256dh, $input->auth, $fuso));
    }

    private function exigirServicoDePush(string $endpoint): void
    {
        $host = strtolower((string) parse_url($endpoint, \PHP_URL_HOST));
        $conhecido = array_filter(self::SERVICOS, static fn (string $s): bool => $host === $s || str_ends_with($host, '.' . $s));
        if (!str_starts_with($endpoint, 'https://') || strlen($endpoint) > 2000 || false === filter_var($endpoint, \FILTER_VALIDATE_URL) || [] === $conhecido) {
            throw new \DomainException('Inscrição de push inválida.');
        }
    }

    /** p256dh é um ponto P-256 sem compressão (65 bytes, começa em 0x04); auth tem 16 bytes. */
    private function exigirChaves(string $p256dh, string $auth): void
    {
        $chave = $this->base64url($p256dh);
        if (null === $chave || self::BYTES_P256DH !== strlen($chave) || "\x04" !== $chave[0] || self::BYTES_AUTH !== strlen((string) $this->base64url($auth))) {
            throw new \DomainException('Inscrição de push inválida.');
        }
    }

    private function base64url(string $texto): ?string
    {
        if (1 !== preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $texto)) {
            return null;
        }
        $bytes = base64_decode(strtr(rtrim($texto, '='), '-_', '+/'), true);

        return false === $bytes ? null : $bytes;
    }
}
