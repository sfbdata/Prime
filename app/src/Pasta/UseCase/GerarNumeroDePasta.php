<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Devolve o próximo número livre de pasta do escritório (spec fase2 §12.5/D12.1).
 *
 * Existe porque a escolha manual do número colidia: até 2026-08 o número vinha digitado
 * pelo usuário e o `CriarPastaUseCase` não checava duplicidade (a trava UNIQUE de nup foi
 * removida de propósito na Version20260701144054, para o sync aceitar 10A/10B). Duas pessoas
 * criando a mesma pasta ao mesmo tempo geravam duas pastas — e o sync refletia as duas no
 * Drive. Em produção isso aconteceu 3 vezes (nups 1214, 1221 e 1227).
 *
 * Regra: MAX(prefixo numérico) + 1, POR TENANT. Buracos NÃO são preenchidos — reaproveitar o
 * número de uma pasta apagada confunde o Drive e o histórico. No tenant 1, com 1047 prefixos
 * distintos e maior = 1231, o próximo é 1232 (e não um dos ~184 buracos).
 *
 * O sufixo de letra do legado (10A/10B) é tolerado na leitura — conta como prefixo 10 — mas
 * nunca é gerado.
 */
final class GerarNumeroDePasta
{
    /**
     * Espaço de nomes da trava consultiva. Advisory lock é global ao banco inteiro, então a
     * chave precisa de duas partes: esta constante isola "numeração de pasta" de qualquer
     * outro uso futuro, e o tenant_id isola um escritório do outro (dois escritórios geram
     * número ao mesmo tempo sem esperar um pelo outro).
     */
    private const CLASSE_TRAVA = 4713;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(Tenant $tenant): string
    {
        $conn     = $this->em->getConnection();
        $tenantId = $tenant->getId();

        if ($tenantId === null) {
            throw new \InvalidArgumentException('O escritório precisa estar persistido para gerar número de pasta.');
        }

        // `pg_advisory_xact_lock` só é liberada no fim da TRANSAÇÃO. Sem uma transação aberta
        // ela cairia no mesmo instante e a trava viraria enfeite: o MAX+1 voltaria a ter
        // corrida, e o defeito seria silencioso — duas pastas com o mesmo número, sem erro
        // nenhum. Por isso o chamador é obrigado a envolver geração+persistência (é o que o
        // `CriarPastaUseCase` faz com `wrapInTransaction`).
        if (!$conn->isTransactionActive()) {
            throw new \LogicException(
                'GerarNumeroDePasta exige uma transação aberta: a trava por escritório só '
                . 'segura até o commit. Envolva a criação em wrapInTransaction().'
            );
        }

        // Serializa a geração dentro do escritório. Quem chegar depois espera aqui e só lê o
        // MAX quando o anterior já gravou — que é o ponto inteiro desta classe.
        $conn->executeStatement(
            'SELECT pg_advisory_xact_lock(?, ?)',
            [self::CLASSE_TRAVA, $tenantId]
        );

        // SQL cru NÃO passa pelo TenantFilter do Doctrine (o filtro age no ORM). O
        // `tenant_id = ?` abaixo é o isolamento — não é redundante, é o único que existe aqui.
        // A expressão do prefixo é a mesma do CAST_INT_PREFIXO (Shared/Doctrine/DQL), para
        // que "maior número" signifique o mesmo na geração e na ordenação da lista.
        $maior = $conn->fetchOne(
            "SELECT MAX(CASE WHEN nup ~ '^[0-9]'
                             THEN CAST(substring(nup FROM '^[0-9]{1,18}') AS BIGINT) END)
             FROM pasta WHERE tenant_id = ?",
            [$tenantId]
        );

        return (string) ((int) $maior + 1);
    }
}
