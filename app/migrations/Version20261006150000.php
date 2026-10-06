<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BlueJus IA — agentes da pasta (spec `docs/specs/inteligencia-agentes-da-pasta.md`, fatia 2).
 *
 * Duas colunas novas em `inteligencia_analise`, ambas nulas para as linhas do Push (fatia 1):
 *   · `agente` — qual dos sete agentes gerou a análise (`tipo = 'analise_pasta'`); enum
 *     `App\Inteligencia\Enum\Agente`, gravado como string;
 *   · `texto_da_analise` — a análise integral do agente no formato do Designer (CONCLUSÃO,
 *     EVIDÊNCIAS, …), separada de `resumo`/`pontos`, que continuam alimentando o cartão.
 *
 * Escrita à mão (worktree sem container), sem índice novo: as consultas por agente filtram sobre
 * o prefixo de `idx_inteligencia_analise_alvo` (tenant, alvo_tipo, alvo_id) — volume por pasta é
 * de dezenas, não de milhares.
 *
 * Aplicar também no banco de teste (`migrations:execute --up`, nunca `schema:update`).
 */
final class Version20261006150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BlueJus IA: adiciona agente e texto_da_analise em inteligencia_analise (agentes da pasta)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inteligencia_analise ADD agente VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE inteligencia_analise ADD texto_da_analise TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inteligencia_analise DROP texto_da_analise');
        $this->addSql('ALTER TABLE inteligencia_analise DROP agente');
    }
}
