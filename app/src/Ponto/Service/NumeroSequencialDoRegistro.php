<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * O NSR (número sequencial do registro) da batida, por escritório (spec ponto-desenho-0811, D2): sai do servidor,
 * sobe de um em um, sem buraco e sem repetir, mesmo com o comprovante desligado.
 *
 * Uma instrução só (`INSERT ... ON CONFLICT ... DO UPDATE ... RETURNING`): a linha do contador do escritório fica
 * travada até o fim da transação, então duas batidas ao mesmo tempo no mesmo escritório saem uma depois da outra, e o
 * rollback da batida desfaz o número junto (a próxima recebe o mesmo). Por isso quem chama tem de estar dentro da
 * transação que grava a batida. Escritórios diferentes não se travam: cada um tem a sua linha.
 *
 * SQL cru não passa pelo TenantFilter: o `tenant_id` explícito é o isolamento. Reaproveitável pela fila sem internet
 * (E13 e E14), que grava a batida do mesmo jeito.
 */
final class NumeroSequencialDoRegistro
{
    private const SQL_PROXIMO = <<<'SQL'
        INSERT INTO ponto_contador_nsr (tenant_id, ultimo) VALUES (:escritorio, 1)
        ON CONFLICT (tenant_id) DO UPDATE SET ultimo = ponto_contador_nsr.ultimo + 1
        RETURNING ultimo
        SQL;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Reserva e devolve o próximo NSR do escritório.
     *
     * @throws \LogicException sem transação aberta (fora dela, uma batida que falhasse depois deixaria buraco)
     */
    public function proximo(Tenant $escritorio): int
    {
        $conexao = $this->em->getConnection();
        if (!$conexao->isTransactionActive()) {
            throw new \LogicException('O NSR exige a transação da batida: fora dela, a batida que falhar deixa buraco na sequência.');
        }

        return (int) $conexao->fetchOne(self::SQL_PROXIMO, ['escritorio' => $escritorio->getId()]);
    }
}
