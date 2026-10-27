<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gateway de pagamentos, M1 (spec `docs/specs/gateway-m1-trabalho-externo.md` §5): o PIX copia e cola da cobrança
 * dinâmica fica no pagamento, para a aba Pagamento não consultar o banco a cada abertura.
 */
final class Version20261027154000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gateway M1: coluna br_code no pagamento';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pagamento ADD br_code TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pagamento DROP br_code');
    }
}
