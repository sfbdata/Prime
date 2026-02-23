<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260222235456 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER INDEX idx_88f9f1ef3b4da9c0 RENAME TO IDX_DC58E2BD70AE7BF1');
        $this->addSql('ALTER TABLE movimentacao_processo DROP CONSTRAINT fk_e5fcb8a7d3f49b91');
        $this->addSql('ALTER TABLE movimentacao_processo ADD CONSTRAINT FK_BE435ED5AAA822D2 FOREIGN KEY (processo_id) REFERENCES processo (id) NOT DEFERRABLE');
        $this->addSql('ALTER INDEX idx_e5fcb8a7d3f49b91 RENAME TO IDX_BE435ED5AAA822D2');
        $this->addSql('ALTER TABLE parte_processo DROP CONSTRAINT fk_4f9726c5d3f49b91');
        $this->addSql('ALTER TABLE parte_processo ADD CONSTRAINT FK_87D9D194AAA822D2 FOREIGN KEY (processo_id) REFERENCES processo (id) NOT DEFERRABLE');
        $this->addSql('ALTER INDEX idx_4f9726c5d3f49b91 RENAME TO IDX_87D9D194AAA822D2');
        $this->addSql('ALTER TABLE processo DROP CONSTRAINT fk_1fdcc2b29c7fdd7c');
        $this->addSql('ALTER TABLE processo ALTER contrato_id DROP NOT NULL');
        $this->addSql('ALTER TABLE processo ADD CONSTRAINT FK_16E5B82D70AE7BF1 FOREIGN KEY (contrato_id) REFERENCES contrato (id) NOT DEFERRABLE');
        $this->addSql('ALTER INDEX uniq_1fdcc2b251c716a9 RENAME TO UNIQ_16E5B82DB7F27F4D');
        $this->addSql('ALTER INDEX idx_1fdcc2b29c7fdd7c RENAME TO IDX_16E5B82D70AE7BF1');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER INDEX idx_dc58e2bd70ae7bf1 RENAME TO idx_88f9f1ef3b4da9c0');
        $this->addSql('ALTER TABLE movimentacao_processo DROP CONSTRAINT FK_BE435ED5AAA822D2');
        $this->addSql('ALTER TABLE movimentacao_processo ADD CONSTRAINT fk_e5fcb8a7d3f49b91 FOREIGN KEY (processo_id) REFERENCES processo (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER INDEX idx_be435ed5aaa822d2 RENAME TO idx_e5fcb8a7d3f49b91');
        $this->addSql('ALTER TABLE parte_processo DROP CONSTRAINT FK_87D9D194AAA822D2');
        $this->addSql('ALTER TABLE parte_processo ADD CONSTRAINT fk_4f9726c5d3f49b91 FOREIGN KEY (processo_id) REFERENCES processo (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER INDEX idx_87d9d194aaa822d2 RENAME TO idx_4f9726c5d3f49b91');
        $this->addSql('ALTER TABLE processo DROP CONSTRAINT FK_16E5B82D70AE7BF1');
        $this->addSql('ALTER TABLE processo ALTER contrato_id SET NOT NULL');
        $this->addSql('ALTER TABLE processo ADD CONSTRAINT fk_1fdcc2b29c7fdd7c FOREIGN KEY (contrato_id) REFERENCES contrato (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER INDEX idx_16e5b82d70ae7bf1 RENAME TO idx_1fdcc2b29c7fdd7c');
        $this->addSql('ALTER INDEX uniq_16e5b82db7f27f4d RENAME TO uniq_1fdcc2b251c716a9');
    }
}
