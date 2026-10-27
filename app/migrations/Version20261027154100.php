<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gateway de pagamentos, M0b (spec `docs/specs/gateway-m0b-webhook-conciliacao.md` §5): índice para achar o pagamento
 * pelo id da cobrança no banco, que é o que o aviso e a reconsulta trazem.
 */
final class Version20261027154100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gateway M0b: índice de pagamento por provedor e id no banco';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_pagamento_provedor_id_externo ON pagamento (provedor, id_externo)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_pagamento_provedor_id_externo');
    }
}
