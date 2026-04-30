<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260429160936 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE jornada_tenant ALTER validacao_repouso_habilitada DROP DEFAULT');
        $this->addSql('ALTER TABLE jornada_tenant ALTER minimo_minutos_repouso DROP DEFAULT');
        $this->addSql('ALTER TABLE jornada_tenant ALTER validacao_interjornada_habilitada DROP DEFAULT');
        $this->addSql('ALTER TABLE jornada_tenant ALTER minimo_minutos_interjornada DROP DEFAULT');
        $this->addSql('ALTER TABLE tenant ADD responsavel_assinatura VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE user_profiles ADD data_admissao DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE jornada_tenant ALTER validacao_repouso_habilitada SET DEFAULT true');
        $this->addSql('ALTER TABLE jornada_tenant ALTER minimo_minutos_repouso SET DEFAULT 60');
        $this->addSql('ALTER TABLE jornada_tenant ALTER validacao_interjornada_habilitada SET DEFAULT true');
        $this->addSql('ALTER TABLE jornada_tenant ALTER minimo_minutos_interjornada SET DEFAULT 660');
        $this->addSql('ALTER TABLE tenant DROP responsavel_assinatura');
        $this->addSql('ALTER TABLE user_profiles DROP data_admissao');
    }
}
