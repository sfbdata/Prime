<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Contribua\Repository\UsuariosDoEscritorioRepository;
use App\Contribua\Service\SituacaoDoEspaco;

/**
 * Limite de usuários do escritório no convite: no envio e no aceite (spec perfis-1.2-planos-e-limites P8 e
 * §6, acerto com a f6). A regra é da Contribua (`RetratoDoEspaco::aceitaMaisUmUsuario`): só recusa com o
 * bloqueio ligado, e "sem limite" sempre libera. Conta quem já está no escritório (vínculo ativo de pessoa
 * ativa), a mesma regra do "Definir limite de usuários" do Master.
 *
 * `retratar($tenantId, 0)` e não `doEscritorio()`: o limite de usuários e a chave do bloqueio não dependem do
 * uso, e assim não se mede o espaço (soma sobre sete tabelas) a cada convite.
 */
final class VagaNoEscritorio
{
    public const RECUSA = 'Este escritório chegou ao limite de %d usuários. Para convidar mais pessoas, amplie o plano ou fale com o suporte do BlueJus.';

    public function __construct(
        private readonly SituacaoDoEspaco $situacaoDoEspaco,
        private readonly UsuariosDoEscritorioRepository $usuariosDoEscritorio,
    ) {
    }

    /** @throws \DomainException quando o escritório já chegou ao limite e o bloqueio está ligado */
    public function exigir(int $tenantId): void
    {
        $retrato = $this->situacaoDoEspaco->retratar($tenantId, 0);
        if (!$retrato->aceitaMaisUmUsuario($this->usuariosDoEscritorio->quantos($tenantId))) {
            throw new \DomainException(sprintf(self::RECUSA, (int) $retrato->limiteUsuarios));
        }
    }
}
