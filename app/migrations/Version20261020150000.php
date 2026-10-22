<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prazo do Push, pergunta 4 do dono (docs/specs/prazo-do-push.md): `pasta_prazo.pode_ser_em_dobro`, o sinal de que a
 * parte parece Fazenda Pública, MP ou Defensoria. O prazo não é dobrado; a tela só avisa. Linha existente nasce false
 * e a próxima sincronização da pasta acerta.
 *
 * Escrita à mão (o diff do make:migration traria ruído de outras frentes).
 */
final class Version20261020150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prazo do Push: pasta_prazo.pode_ser_em_dobro (sinal de possível prazo em dobro)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_prazo ADD pode_ser_em_dobro BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_prazo DROP pode_ser_em_dobro');
    }
}
