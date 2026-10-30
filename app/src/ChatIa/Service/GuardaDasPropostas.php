<?php

declare(strict_types=1);

namespace App\ChatIa\Service;

use App\ChatIa\Enum\TipoDaProposta;
use App\ChatIa\Exception\SemPermissaoParaPropostaException;
use App\Cliente\UseCase\CriarClienteAvulsoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\FerramentaDeLinguagem;
use App\Service\PermissionChecker;

/**
 * A permissão das propostas, nos DOIS lados (a permissão pode mudar entre a proposta e o clique):
 *
 * - ao perguntar, o pedido só leva as ferramentas que a pessoa PODE usar ({@see self::ferramentasPara()}); a chamada de
 *   ferramenta que o modelo faz sem ela ter sido oferecida é descartada ({@see self::podePropor()});
 * - no clique, {@see self::exigirNoClique()} confere de novo a permissão de quem clicou (403).
 *
 * Cliente avulso exige {@see CriarClienteAvulsoUseCase::CAPACIDADE}; pasta exige o módulo `pastas` (e o cliente ligado à
 * pasta, poder editá-la, o que o UseCase de Cliente confere).
 */
final class GuardaDasPropostas
{
    public const MODULO_DE_PASTAS = 'pastas';

    public function __construct(private readonly PermissionChecker $permissoes)
    {
    }

    /** @return list<FerramentaDeLinguagem> */
    public function ferramentasPara(User $eu, Tenant $tenant): array
    {
        $ferramentas = [];
        if ($this->podePropor(TipoDaProposta::Cliente, $eu, $tenant)) {
            $ferramentas[] = FerramentasDoCadastro::cliente();
        }
        if ($this->podePropor(TipoDaProposta::Pasta, $eu, $tenant)) {
            $ferramentas[] = FerramentasDoCadastro::pasta();
        }

        return $ferramentas;
    }

    public function podePropor(TipoDaProposta $tipo, User $eu, Tenant $tenant): bool
    {
        return match ($tipo) {
            TipoDaProposta::Cliente => $this->permissoes->hasPermission($eu, $tenant, CriarClienteAvulsoUseCase::CAPACIDADE),
            TipoDaProposta::Pasta => $this->permissoes->canAccessModule($eu, $tenant, self::MODULO_DE_PASTAS),
        };
    }

    /** @throws SemPermissaoParaPropostaException */
    public function exigirNoClique(TipoDaProposta $tipo, User $eu, Tenant $tenant): void
    {
        if (!$this->podePropor($tipo, $eu, $tenant)) {
            throw new SemPermissaoParaPropostaException($tipo === TipoDaProposta::Cliente
                ? 'Você não tem permissão para cadastrar clientes.'
                : 'Você não tem permissão para abrir pastas.');
        }
    }
}
