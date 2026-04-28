<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260428124222 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona campo codigo_funcionario ao usuário e popula sequencialmente por tenant';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD codigo_funcionario VARCHAR(20) DEFAULT NULL');

        // Popula todos os usuários existentes com código sequencial por tenant,
        // ordenado por data de criação (mais antigo recebe o menor número)
        $this->addSql(<<<'SQL'
            UPDATE "user" u
            SET codigo_funcionario = LPAD(CAST(r.rn AS TEXT), 4, '0')
            FROM (
                SELECT id,
                       ROW_NUMBER() OVER (PARTITION BY tenant_id ORDER BY created_at, id) AS rn
                FROM "user"
                WHERE tenant_id IS NOT NULL
            ) r
            WHERE u.id = r.id
              AND u.codigo_funcionario IS NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE "user" DROP codigo_funcionario');
    }
}
