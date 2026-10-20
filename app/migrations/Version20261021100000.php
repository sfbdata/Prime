<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BlueJus IA — drawer da pasta, fatias F1 e F2 (spec `docs/specs/inteligencia-drawer-da-pasta.md`).
 *
 * Uma coluna nova em `inteligencia_analise`:
 *   · `comando` — qual comando ou relatório do drawer gerou a análise (enum
 *     `App\Inteligencia\Enum\Comando`, gravado como string). Nula no Push e nas análises de agente
 *     anteriores aos comandos (a tela as mostra no comando `Comando::doAgenteLegado`).
 *
 * Escrita à mão (worktree sem container), sem índice novo: a idempotência por comando filtra sobre
 * o prefixo de `idx_inteligencia_analise_alvo` (tenant, alvo_tipo, alvo_id), como a por agente.
 * Timestamp provisório: a vez na fila de migrations é dada pelo coordenador.
 *
 * Aplicar também no banco de teste (`migrations:execute --up`, nunca `schema:update`).
 */
final class Version20261021100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BlueJus IA: adiciona comando em inteligencia_analise (comandos e relatórios do drawer)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inteligencia_analise ADD comando VARCHAR(30) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inteligencia_analise DROP comando');
    }
}
