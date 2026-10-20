<?php

declare(strict_types=1);

namespace App\Agenda\UseCase;

use App\Agenda\Infra\GoogleAgendaApi;
use App\Agenda\Repository\ConexaoGoogleAgendaRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * O estado do painel GOOGLE AGENDA (desenho 09): conectado; servidor liberado e pessoa não conectada (ou
 * com a autorização revogada na conta Google); ou servidor sem cliente OAuth.
 */
final class EstadoDoGoogleAgendaUseCase
{
    public const CONECTADO = 'conectado';
    public const NAO_CONECTADO = 'nao_conectado';
    public const INDISPONIVEL = 'indisponivel';

    public function __construct(
        private readonly GoogleAgendaApi $google,
        private readonly ConexaoGoogleAgendaRepository $conexoes,
    ) {
    }

    public function executar(Tenant $tenant, User $eu): string
    {
        if (!$this->google->ligado()) {
            return self::INDISPONIVEL;
        }
        $conexao = $this->conexoes->daPessoa($eu, $tenant);

        return null !== $conexao && !$conexao->precisaReconectar() ? self::CONECTADO : self::NAO_CONECTADO;
    }
}
