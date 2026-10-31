<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Controladoria da pasta, fatia B (spec central-documentos-controladoria): contrato, unidade, titular do extrato e
 * data-limite de prescrição na pasta; qualificação, e-mail e endereço no réu. Tudo opcional; só ADD COLUMN.
 */
final class Version20261031120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pasta: contrato, unidade, titular do extrato e prescrição; réu: qualificação, e-mail e endereço';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta ADD contrato VARCHAR(120) DEFAULT NULL, ADD unidade VARCHAR(120) DEFAULT NULL, ADD titular_extrato VARCHAR(255) DEFAULT NULL, ADD prescricao_em DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE pasta_reu ADD qualificacao VARCHAR(500) DEFAULT NULL, ADD email VARCHAR(255) DEFAULT NULL, ADD endereco VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta DROP contrato, DROP unidade, DROP titular_extrato, DROP prescricao_em');
        $this->addSql('ALTER TABLE pasta_reu DROP qualificacao, DROP email, DROP endereco');
    }
}
