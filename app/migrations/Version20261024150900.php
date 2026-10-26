<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Trabalho Externo: o Master ajusta o valor do pagamento (spec `docs/specs/trabalho-externo-valor-editavel.md`).
 * `trabalho_externo_demanda.valor_pagamento_ajustado` guarda só o ajuste; a proposta aceita não muda. Escrita à mão:
 * o gerador propõe também apagar o índice funcional `uq_user_email_lower`, que fica.
 */
final class Version20261024150900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Trabalho Externo: coluna trabalho_externo_demanda.valor_pagamento_ajustado (valor ajustado pelo Master)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trabalho_externo_demanda ADD valor_pagamento_ajustado NUMERIC(12, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trabalho_externo_demanda DROP valor_pagamento_ajustado');
    }
}
