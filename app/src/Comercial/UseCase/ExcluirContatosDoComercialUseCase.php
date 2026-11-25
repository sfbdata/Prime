<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Exception\ContatoRecusado;
use App\Comercial\Repository\ContatoComercialRepository;
use App\Comercial\Service\AuditoriaDaBaseDeContatos;
use App\Entity\Auth\User;

/**
 * Exclui um ou vários contatos da Base. Só sai da base: o descadastro (opt-out) NÃO é tocado, então quem pediu para
 * não receber continua sem receber mesmo se o endereço entrar de novo.
 */
final class ExcluirContatosDoComercialUseCase
{
    public const MAXIMO_POR_PEDIDO = 200;

    public function __construct(
        private readonly ContatoComercialRepository $contatos,
        private readonly AuditoriaDaBaseDeContatos $auditoria,
    ) {
    }

    /**
     * @param list<int> $ids
     *
     * @return int quantos foram excluídos
     *
     * @throws ContatoRecusado lista vazia ou grande demais
     */
    public function executar(array $ids, User $master): int
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            throw new ContatoRecusado('Escolha ao menos um contato.');
        }
        if (\count($ids) > self::MAXIMO_POR_PEDIDO) {
            throw new ContatoRecusado('Exclua até ' . self::MAXIMO_POR_PEDIDO . ' contatos por vez.');
        }
        $excluidos = $this->contatos->apagarPorIds($ids);
        $this->auditoria->registrar(AuditoriaDaBaseDeContatos::EXCLUIDO, null, ['ids' => $ids, 'excluidos' => $excluidos], $master);

        return $excluidos;
    }
}
