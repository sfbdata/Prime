<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Trabalho Externo VS 18.10.26, F4 (spec `docs/specs/trabalho-externo-vs-18-10-26.md` §4.7): a candidatura
 * guarda o escritório de quem se candidatou (o tenant do contexto no momento), onde ele recebe o aviso de
 * andamento. Some com o escritório (SET NULL): a candidatura fica, sem aviso.
 *
 * Só acrescenta. As linhas vieram do `--dump-sql` no banco de teste da frente, filtradas por
 * `escritorio_do_candidato` (o resto do dump já divergia antes e não é desta fatia). As candidaturas que
 * já existem ficam sem escritório.
 */
final class Version20261020147800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Trabalho Externo: escritório do candidato na candidatura';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trabalho_externo_candidatura ADD escritorio_do_candidato INT DEFAULT NULL');
        $this->addSql('ALTER TABLE trabalho_externo_candidatura ADD CONSTRAINT FK_9139807C159D4D71 FOREIGN KEY (escritorio_do_candidato) REFERENCES tenant (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_9139807C159D4D71 ON trabalho_externo_candidatura (escritorio_do_candidato)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trabalho_externo_candidatura DROP CONSTRAINT FK_9139807C159D4D71');
        $this->addSql('DROP INDEX IDX_9139807C159D4D71');
        $this->addSql('ALTER TABLE trabalho_externo_candidatura DROP escritorio_do_candidato');
    }
}
