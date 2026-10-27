<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Honorários recorrentes no card Pagamentos da pasta (desenho 02 de 27/10, `FIN_TIPOS.recorrente`):
 * o CHECK do tipo passa a aceitar 'recorrente'. Sem coluna nova e sem backfill; o recorrente é
 * valor fixo com vencimento, como contrato e custas.
 */
final class Version20261027154600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pasta: tipo recorrente em pasta_pagamento';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_pagamento DROP CONSTRAINT ck_pasta_pagamento_tipo');
        $this->addSql("ALTER TABLE pasta_pagamento ADD CONSTRAINT ck_pasta_pagamento_tipo CHECK (tipo IN ('contrato', 'exito', 'sucumbencia', 'custas', 'recorrente'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM pasta_pagamento WHERE tipo = 'recorrente'");
        $this->addSql('ALTER TABLE pasta_pagamento DROP CONSTRAINT ck_pasta_pagamento_tipo');
        $this->addSql("ALTER TABLE pasta_pagamento ADD CONSTRAINT ck_pasta_pagamento_tipo CHECK (tipo IN ('contrato', 'exito', 'sucumbencia', 'custas'))");
    }
}
