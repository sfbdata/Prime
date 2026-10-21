<?php

declare(strict_types=1);

namespace App\Central\Service;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Service\NotificacaoService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * O sino de cada membro quando o despacho é "Urgente" (`docs/specs/encaminhar-via-chat-ia.md` §2.6).
 *
 * A `Notificacao` é `Auditavel`: leva só quem despachou e o link da conversa, NUNCA o texto do item nem a instrução
 * (`mensagem = null`, regra 3 da Central). O link é o mesmo do aviso de menção (`central_index?conversa=N`).
 *
 * Só persiste: o `flush` é do `EncaminharItemDaPastaUseCase`, na mesma transação da mensagem e da auditoria.
 */
final class AvisoDeDespachoUrgente
{
    public function __construct(
        private readonly NotificacaoService $notificacoes,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** @param list<User> $pessoas quem recebe o despacho (já sem quem despachou) */
    public function avisar(int $conversaId, User $autor, Tenant $tenant, array $pessoas): void
    {
        $url = $this->urls->generate('central_index', ['conversa' => $conversaId]);
        $titulo = sprintf('Despacho urgente de %s', PrimeiroNome::de($autor));

        foreach ($pessoas as $pessoa) {
            $this->notificacoes->criar($pessoa, $tenant, Notificacao::TIPO_CENTRAL_DESPACHO, $titulo)->setUrl($url);
        }
    }
}
