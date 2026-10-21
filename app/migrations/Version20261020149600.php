<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Comercial (spec `docs/specs/comercial-e-emails.md` §11, F3.3 a): cada destino da fila de campanha guarda a lista de
 * consentimento pela qual entrou (`comercial_campanhas`, ou `vagas_alerta` no modelo de vagas). Ela decide o link de
 * descadastro, o List-Unsubscribe e a checagem do Carteiro. As linhas que já existem ficam com `comercial_campanhas`.
 */
final class Version20261020149600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Comercial: lista de consentimento de cada destino da campanha (comercial_campanha_destino.lista)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE comercial_campanha_destino ADD lista VARCHAR(40) DEFAULT 'comercial_campanhas' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comercial_campanha_destino DROP lista');
    }
}
