<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260413130329 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Renomear tabela pasta_organizadora para marcador';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_organizadora DROP CONSTRAINT fk_9eea0cc4c19c5634');
        $this->addSql('ALTER TABLE pasta_organizadora DROP CONSTRAINT fk_9eea0cc49033212a');
        $this->addSql('ALTER TABLE pasta_organizadora DROP CONSTRAINT fk_9eea0cc4f42f4a03');
        $this->addSql('DROP INDEX idx_9eea0cc4c19c5634');
        $this->addSql('DROP INDEX idx_9eea0cc49033212a');
        $this->addSql('DROP INDEX idx_9eea0cc4f42f4a03');

        $this->addSql('ALTER TABLE pasta_organizadora RENAME TO marcador');

        $this->addSql('CREATE INDEX IDX_B5F18E7C19C5634 ON marcador (pai_id)');
        $this->addSql('CREATE INDEX IDX_B5F18E79033212A ON marcador (tenant_id)');
        $this->addSql('CREATE INDEX IDX_B5F18E7F42F4A03 ON marcador (criado_por_id)');
        $this->addSql('ALTER TABLE marcador ADD CONSTRAINT FK_B5F18E7C19C5634 FOREIGN KEY (pai_id) REFERENCES marcador (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE marcador ADD CONSTRAINT FK_B5F18E79033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE marcador ADD CONSTRAINT FK_B5F18E7F42F4A03 FOREIGN KEY (criado_por_id) REFERENCES "user" (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marcador DROP CONSTRAINT FK_B5F18E7C19C5634');
        $this->addSql('ALTER TABLE marcador DROP CONSTRAINT FK_B5F18E79033212A');
        $this->addSql('ALTER TABLE marcador DROP CONSTRAINT FK_B5F18E7F42F4A03');
        $this->addSql('DROP INDEX IDX_B5F18E7C19C5634');
        $this->addSql('DROP INDEX IDX_B5F18E79033212A');
        $this->addSql('DROP INDEX IDX_B5F18E7F42F4A03');

        $this->addSql('ALTER TABLE marcador RENAME TO pasta_organizadora');

        $this->addSql('CREATE INDEX idx_9eea0cc4c19c5634 ON pasta_organizadora (pai_id)');
        $this->addSql('CREATE INDEX idx_9eea0cc49033212a ON pasta_organizadora (tenant_id)');
        $this->addSql('CREATE INDEX idx_9eea0cc4f42f4a03 ON pasta_organizadora (criado_por_id)');
        $this->addSql('ALTER TABLE pasta_organizadora ADD CONSTRAINT fk_9eea0cc4c19c5634 FOREIGN KEY (pai_id) REFERENCES pasta_organizadora (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE pasta_organizadora ADD CONSTRAINT fk_9eea0cc49033212a FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE pasta_organizadora ADD CONSTRAINT fk_9eea0cc4f42f4a03 FOREIGN KEY (criado_por_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
