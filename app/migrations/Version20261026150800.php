<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Continuar com Facebook (spec `docs/specs/login-cupom-e-facebook.md`, PR B): `user.facebook_id`, o `id` do Facebook
 * ligado à conta, gravado só no cadastro pelo Facebook. Único (um `id` pertence a uma conta só; o Postgres aceita
 * vários nulos). Escrita à mão, espelhando a do `google_sub`: o gerador propõe também apagar o índice funcional
 * `uq_user_email_lower`, que fica. O nome do índice é o que o Doctrine calcula (crc32 de "user" e de "facebook_id").
 */
final class Version20261026150800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Continuar com Facebook: coluna user.facebook_id (única)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD facebook_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D6499BE8FD98 ON "user" (facebook_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_8D93D6499BE8FD98');
        $this->addSql('ALTER TABLE "user" DROP facebook_id');
    }
}
