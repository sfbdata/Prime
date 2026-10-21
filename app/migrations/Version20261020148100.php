<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Entrar com o Google, entrega 1 (spec `docs/specs/entrar-com-google.md`): `user.google_sub`, o identificador estável
 * da conta Google ligada, gravado no primeiro login pelo Google. Único (um `sub` pertence a uma conta só; o Postgres
 * aceita vários nulos). Escrita à mão a partir do `--dump-sql` filtrado por `google_sub`: o gerador propõe também
 * apagar o índice funcional `uq_user_email_lower`, que fica.
 */
final class Version20261020148100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Entrar com o Google: coluna user.google_sub (única)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD google_sub VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E788A50 ON "user" (google_sub)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_8D93D649E788A50');
        $this->addSql('ALTER TABLE "user" DROP google_sub');
    }
}
