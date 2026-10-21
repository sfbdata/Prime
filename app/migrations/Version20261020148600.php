<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Idioma do sistema escolhido pela pessoa (spec docs/specs/idioma-do-sistema.md, entrega 1): uma coluna em
 * `user_profiles`, nula = português. Sem backfill.
 */
final class Version20261020148600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Meu Perfil: idioma do sistema (user_profiles.idioma)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_profiles ADD idioma VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_profiles DROP idioma');
    }
}
