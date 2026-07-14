<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajuste 7 dos Cobranças: acordo inteligente. Adiciona a cobranca_acordo o snapshot da negociação —
 * valor_total_negociado (nullable) e valor_entrada (NOT NULL default 0), ambos em CENTAVOS. São
 * DESCRITIVOS e NÃO-autoritativos para o saldo (que segue derivado das obrigações — invariável 20);
 * alimentam o detalhe/edição do acordo. Aditiva e não-breaking: acordos antigos ficam total NULL /
 * entrada 0. O down() remove as colunas.
 */
final class Version20260714130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajuste 7: snapshot valor_total_negociado + valor_entrada em cobranca_acordo';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cobranca_acordo ADD valor_total_negociado INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cobranca_acordo ADD valor_entrada INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cobranca_acordo DROP valor_total_negociado');
        $this->addSql('ALTER TABLE cobranca_acordo DROP valor_entrada');
    }
}
