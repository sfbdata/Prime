<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Meu Perfil: chave PIX para recompensas (desenho 07; spec docs/specs/meu-perfil-pix-recompensas.md).
 * Só uma coluna nula em user_profiles; nenhum dado é alterado.
 */
final class Version20261027153900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Meu Perfil: coluna chave_pix_recompensa em user_profiles';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_profiles ADD chave_pix_recompensa VARCHAR(140) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_profiles DROP chave_pix_recompensa');
    }
}
