<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\Exception\AvisoNaoEncontradoException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Service\AcessoACentral;
use App\Central\Service\AvisosDaCentral;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\NotificacaoService;

/**
 * Botão direito → "Excluir" num aviso da Caixa: apaga a notificação DA PESSOA (lida ou não), pelo mesmo serviço do
 * "Limpar notificações lidas". É limpeza da própria caixa: a meta, a pasta ou a menção de origem não mudam, e o aviso
 * de outra pessoa não existe para quem pede (404). Sem confirmação na tela, como no desenho (L5586).
 */
final class ExcluirAvisoUseCase
{
    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly AvisosDaCentral $avisos,
        private readonly NotificacaoService $notificacoes,
    ) {
    }

    /**
     * @throws SemAcessoACentralException
     * @throws AvisoNaoEncontradoException
     */
    public function executar(User $eu, ?Tenant $tenant, int $id): void
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);
        if ($this->avisos->doUsuario($id, $eu, $tenant) === null) {
            throw new AvisoNaoEncontradoException();
        }

        $this->notificacoes->excluir($eu, $tenant, [$id]);
    }
}
