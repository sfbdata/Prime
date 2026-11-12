<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Gerada pelo diff do Doctrine; mantidas só as colunas desta entrega (o resto da divergência do banco DEV é de outras frentes). */
final class Version20261112180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Voto: regras por pauta (critério, base, dígitos, vagas e quórum por convocação) e número da opção em INT';
    }

    public function up(Schema $schema): void
    {
        // Os padrões são os de sempre: mais votos, válidos, 1 dígito, 1 vaga, quórum 50% na 1ª e 0% na 2ª.
        $this->addSql("ALTER TABLE voto.assembleia_pauta ADD criterio VARCHAR(12) DEFAULT 'mais' NOT NULL, ADD base VARCHAR(12) DEFAULT 'validos' NOT NULL, ADD digitos SMALLINT DEFAULT 1 NOT NULL, ADD vagas SMALLINT DEFAULT 1 NOT NULL, ADD quorum_primeira NUMERIC(5, 2) DEFAULT 50 NOT NULL, ADD quorum_segunda NUMERIC(5, 2) DEFAULT 0");
        // Número de até 5 dígitos (99999) não cabe em SMALLINT.
        $this->addSql('ALTER TABLE voto.assembleia_opcao ALTER numero TYPE INT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voto.assembleia_opcao ALTER numero TYPE SMALLINT');
        $this->addSql('ALTER TABLE voto.assembleia_pauta DROP criterio, DROP base, DROP digitos, DROP vagas, DROP quorum_primeira, DROP quorum_segunda');
    }
}
