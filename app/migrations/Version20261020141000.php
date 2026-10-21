<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gênero e atuação declarados no Meu Perfil (desenho 05 de 21/10, F1; spec docs/specs/meu-perfil-genero-atuacao.md).
 * Nascem nulos para quem já existe: sem backfill, o filtro só conta o que a pessoa declarou.
 */
final class Version20261020141000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user_profiles.genero e user_profiles.atuacao (declarados pela pessoa, sem backfill)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_profiles ADD genero VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE user_profiles ADD atuacao VARCHAR(20) DEFAULT NULL');
        $this->addSql("ALTER TABLE user_profiles ADD CONSTRAINT chk_user_profiles_genero CHECK (genero IS NULL OR genero IN ('feminino', 'masculino', 'nao_informado'))");
        $this->addSql("ALTER TABLE user_profiles ADD CONSTRAINT chk_user_profiles_atuacao CHECK (atuacao IS NULL OR atuacao IN ('advogado', 'estagiario', 'controladoria', 'financeiro', 'administrativo', 'outra'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_profiles DROP CONSTRAINT chk_user_profiles_atuacao');
        $this->addSql('ALTER TABLE user_profiles DROP CONSTRAINT chk_user_profiles_genero');
        $this->addSql('ALTER TABLE user_profiles DROP genero');
        $this->addSql('ALTER TABLE user_profiles DROP atuacao');
    }
}
