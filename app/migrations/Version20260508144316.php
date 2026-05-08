<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260508144316 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Atribuir ROLE_SUPER_ADMIN ao usuário jusprime.samuel@gmail.com';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE "user"
            SET roles = CASE
                WHEN roles::jsonb @> '["ROLE_SUPER_ADMIN"]'::jsonb THEN roles::jsonb
                ELSE roles::jsonb || '["ROLE_SUPER_ADMIN"]'::jsonb
            END
            WHERE email = 'jusprime.samuel@gmail.com'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE "user"
            SET roles = (roles::jsonb - 'ROLE_SUPER_ADMIN')
            WHERE email = 'jusprime.samuel@gmail.com'
        SQL);
    }
}
