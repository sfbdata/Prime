<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Planos sem limite de usuários (dono, 26/10/2026: "usuários ilimitados" do desenho 05 de 25/10 revoga a D5 de
 * 3 / 8 / 15 / sem limite; spec perfis-1.2-planos-e-limites.md). Nulo em `limite_usuarios` é "sem limite". Contas com
 * limite próprio definido pelo Master (contribua_espaco_do_escritorio.limite_usuarios) não são tocadas.
 */
final class Version20261026152000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Planos: limite de usuários vira sem limite em todos os planos (D5 revogada)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE contribua_plano SET limite_usuarios = NULL WHERE limite_usuarios IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE contribua_plano SET limite_usuarios = CASE codigo WHEN 'free' THEN 3 WHEN 'essencial' THEN 8 WHEN 'profissional' THEN 15 ELSE NULL END");
    }
}
