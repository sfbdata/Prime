<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Processos apensados (spec docs/specs/processo-apensados.md §1): `pasta_processo.tipo_apenso` guarda o tipo
 * escolhido no "Vincular apensado" (embargos, agravo_de_instrumento...; enum App\Pasta\Entity\TipoDeApenso).
 *
 * Nullable e sem preenchimento: o vínculo que já existe fica sem tipo e a tela o lê como "Outro". O principal
 * continua sendo o `principal = true`; os demais vínculos são os apensados.
 */
final class Version20261020103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Processos apensados: tipo_apenso em pasta_processo';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_processo ADD tipo_apenso VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_processo DROP tipo_apenso');
    }
}
