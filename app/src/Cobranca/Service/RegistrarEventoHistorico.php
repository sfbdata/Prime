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
 * Persiste o evento na mesma unidade de trabalho do UseCase. Por padrão NÃO faz flush (o UseCase
 * flusha uma vez, junto da entidade principal); quando o evento é a ÚNICA escrita do UseCase
 * (ex.: registrar tentativa de cobrança), passe `flush: true` para commitar tudo aqui. O tenant do
 * evento vem SEMPRE do caso (fonte única de verdade), nunca de um parâmetro externo — evita
 * evento cross-tenant.
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
        bool $flush = false,
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

        $this->eventoRepository->salvar($evento, $flush);

        return $evento;
    }
}
