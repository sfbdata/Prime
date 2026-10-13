<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FPN-009 — a taxa de juros ao mês da parcela Price (`pasta_pagamento.juros_mensal`), para a linha
 * mostrar "· juros N% a.m.". Só REGISTRO: nenhuma conta, resumo ou valor lê esta coluna.
 *
 * Aditiva e anulável, SEM backfill: os lançamentos existentes ficam com NULL e continuam sem
 * sufixo — a taxa usada antes não foi guardada, e não se inventa.
 *
 * Fora daqui, de propósito: a divergência antiga do `--dump-sql` (3 `DROP INDEX` de índices
 * funcionais de cobrança e 3 `ALTER cobranca_pessoa_* DROP DEFAULT`), que não é desta mudança.
 */
final class Version20261007221859 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'FPN-009: pasta_pagamento.juros_mensal (taxa ao mês da parcela Price, só registro)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_pagamento ADD juros_mensal NUMERIC(6, 4) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_pagamento DROP juros_mensal');
    }
}
