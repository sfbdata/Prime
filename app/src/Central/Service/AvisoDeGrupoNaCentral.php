<?php

declare(strict_types=1);

namespace App\Central\Service;

use App\Central\Entity\Conversa;
use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Service\NotificacaoService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Ana adicionou você ao grupo X" no sino de quem entrou (`docs/specs/central-comunicacao-b.md` §4; desenho L6185).
 * Como o aviso de menção, sem conteúdo de mensagem: a `Notificacao` é auditada, e leva só quem fez, o nome do grupo e o
 * link. Só persiste — o flush é do UseCase.
 */
final class AvisoDeGrupoNaCentral
{
    public function __construct(
        private readonly NotificacaoService $notificacoes,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function avisarAdicionado(Conversa $grupo, User $quemAdicionou, Tenant $tenant, User $adicionado): void
    {
        $titulo = sprintf('%s adicionou você ao grupo %s', PrimeiroNome::de($quemAdicionou), (string) $grupo->getNome());

        $this->notificacoes
            ->criar($adicionado, $tenant, Notificacao::TIPO_CENTRAL_GRUPO_ADICIONADO, mb_substr($titulo, 0, 255))
            ->setUrl($this->urls->generate('central_index', ['conversa' => $grupo->getId()]));
    }
}
