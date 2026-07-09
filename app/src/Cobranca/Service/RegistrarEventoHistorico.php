<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Entity\Auth\User;

/**
 * Escreve na linha do tempo OPERACIONAL do Caso de Cobrança (SPEC §13). É o histórico de domínio,
 * gravado explicitamente pelos UseCases — não é auditoria técnica (invariável 26).
 *
 * NÃO faz flush: persiste o evento na mesma unidade de trabalho do UseCase, que faz um único
 * flush ao final (uma transação por caso de uso). O tenant do evento vem SEMPRE do caso (fonte
 * única de verdade), nunca de um parâmetro externo — evita evento cross-tenant.
 */
final class RegistrarEventoHistorico
{
    public function __construct(
        private readonly EventoHistoricoRepository $eventoRepository,
    ) {
    }

    /**
     * @param array<string, mixed>|null $dados
     */
    public function registrar(
        CasoCobranca $caso,
        TipoEventoHistorico $tipo,
        ?User $usuario,
        string $descricao,
        ?array $dados = null,
    ): EventoHistorico {
        $tenant = $caso->getTenant();

        if ($tenant === null) {
            throw new \LogicException('Caso sem tenant não pode registrar evento de histórico.');
        }

        $evento = new EventoHistorico();
        $evento->setTenant($tenant);
        $evento->setCaso($caso);
        $evento->setTipo($tipo);
        $evento->setUsuario($usuario);
        $evento->setDescricao($descricao);
        $evento->setDados($dados);

        $this->eventoRepository->salvar($evento);

        return $evento;
    }
}
