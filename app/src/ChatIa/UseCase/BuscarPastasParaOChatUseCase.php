<?php

declare(strict_types=1);

namespace App\ChatIa\UseCase;

use App\ChatIa\DTO\PastaCitavelOutput;
use App\ChatIa\Service\AcessoAoChat;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\AcessoAPasta;

/**
 * "Citar pasta": busca pastas por NUP (e pelo que a Lista já busca) entre as do escritório que a PESSOA vê (cadeado,
 * permissão), sem as da lixeira, no máximo {@see MAXIMO}. Só `{id, nup, cliente}`; o contexto da pasta só é lido no
 * envio da pergunta, com as travas do aceite e do segredo de justiça.
 *
 * @throws \App\ChatIa\Exception\SemAcessoAoChatException
 */
final class BuscarPastasParaOChatUseCase
{
    public const MAXIMO = 8;

    public function __construct(
        private readonly AcessoAoChat $acesso,
        private readonly PastaRepository $pastas,
        private readonly AcessoAPasta $acessoAPasta,
    ) {
    }

    /** @return list<PastaCitavelOutput> */
    public function executar(User $eu, ?Tenant $tenant, string $busca): array
    {
        $tenant = $this->acesso->exigir($eu, $tenant);
        $busca = trim($busca);
        if ($busca === '') {
            return [];
        }

        $visiveis = array_filter(
            $this->pastas->findByFilters(['busca' => $busca], $tenant, 1, self::MAXIMO, 'nup', 'asc', null, $eu),
            fn (Pasta $p): bool => !$p->estaExcluida() && $this->acessoAPasta->podeVer($eu, $p),
        );

        return array_values(array_map(
            static function (Pasta $p): PastaCitavelOutput {
                $cliente = trim((string) $p->getClientePrincipal()?->getNomeExibicao());

                return new PastaCitavelOutput((int) $p->getId(), (string) $p->getNup(), $cliente !== '' ? $cliente : null);
            },
            $visiveis,
        ));
    }
}
