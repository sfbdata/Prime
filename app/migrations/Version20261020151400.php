<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Êxito e sucumbência no card Pagamentos da pasta (spec pasta-exito-sucumbencia, desenho 02
 * `FIN_TIPOS`): `pasta_pagamento` ganha o `tipo` (enum `TipoDePagamento`, padrão 'contrato') e
 * o `percentual` do previsto; o `vencimento` passa a aceitar nulo (o previsto não vence).
 *
 * Os CHECKs guardam a coerência que o mapeamento não representa: percentual só em êxito ou
 * sucumbência ainda não pagos, entre 0 e 100; vencimento nulo só em êxito ou sucumbência.
 *
 * Sem backfill: as linhas antigas ficam 'contrato' (as custas de antes inclusive; o tipo só muda
 * o comportamento de êxito e sucumbência, que não existiam). Escrita à mão: o diff do
 * `make:migration` traria ruído de outras frentes (foto em spec §3).
 */
final class Version20261020151400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pasta: tipo e percentual em pasta_pagamento; vencimento nulo para êxito e sucumbência';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE pasta_pagamento ADD tipo VARCHAR(20) DEFAULT 'contrato' NOT NULL");
        $this->addSql('ALTER TABLE pasta_pagamento ADD percentual NUMERIC(5, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE pasta_pagamento ALTER vencimento DROP NOT NULL');
        $this->addSql("ALTER TABLE pasta_pagamento ADD CONSTRAINT ck_pasta_pagamento_tipo CHECK (tipo IN ('contrato', 'exito', 'sucumbencia', 'custas'))");
        $this->addSql("ALTER TABLE pasta_pagamento ADD CONSTRAINT ck_pasta_pagamento_percentual CHECK (percentual IS NULL OR (tipo IN ('exito', 'sucumbencia') AND pago_em IS NULL AND percentual > 0 AND percentual <= 100))");
        $this->addSql("ALTER TABLE pasta_pagamento ADD CONSTRAINT ck_pasta_pagamento_vencimento CHECK (vencimento IS NOT NULL OR tipo IN ('exito', 'sucumbencia'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_pagamento DROP CONSTRAINT ck_pasta_pagamento_vencimento');
        $this->addSql('ALTER TABLE pasta_pagamento DROP CONSTRAINT ck_pasta_pagamento_percentual');
        $this->addSql('ALTER TABLE pasta_pagamento DROP CONSTRAINT ck_pasta_pagamento_tipo');
        // O previsto não tem data: volta com a do lançamento para a coluna poder ser NOT NULL.
        $this->addSql('UPDATE pasta_pagamento SET vencimento = CAST(criado_em AS DATE) WHERE vencimento IS NULL');
        $this->addSql('ALTER TABLE pasta_pagamento ALTER vencimento SET NOT NULL');
        $this->addSql('ALTER TABLE pasta_pagamento DROP percentual');
        $this->addSql('ALTER TABLE pasta_pagamento DROP tipo');
    }
}
