<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Valor da causa na pasta — o número que a aba Financeiro passa a mostrar e a
 * deixar editar, e a base da "Média por CPF" (a média do valor da causa de
 * todas as pastas de um mesmo cliente).
 *
 * **Puramente aditiva**: uma coluna nova, anulável, em `pasta`. Nenhuma linha
 * existente é lida ou alterada.
 *
 * `DEFAULT NULL` é decisão de domínio, não descuido: nulo significa "ninguém
 * preencheu" e fica de fora da média; R$ 0,00 significa "a causa vale zero" e
 * entra nela. Um `DEFAULT 0` faria as 1.069 pastas do acervo nascerem
 * declarando valor zero e envenenaria a média de todo cliente.
 *
 * ESCRITA À MÃO, não gerada. Antes de escrever, fotografei
 * `doctrine:schema:update --dump-sql` no checkout principal (sem esta alteração)
 * e ele já trazia um ruído pré-existente: `DROP INDEX
 * uniq_cobranca_obrigacao_ref_competencia` — índice funcional criado por SQL
 * cru, que o Doctrine não sabe representar no mapeamento e por isso propõe
 * apagar. **Ele não entra aqui**: é o índice único em que a conferência da
 * cobrança se apoia, e sem ele entra dívida duplicada em silêncio.
 */
final class Version20260814120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Valor da causa na pasta (base da média por CPF na aba Financeiro)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta ADD valor_causa NUMERIC(15, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Derruba só a coluna criada aqui. O que se perde é o que foi digitado
        // nela — não há como reconstruir, então voltar esta migration em base com
        // dado preenchido é perda real, e consciente.
        $this->addSql('ALTER TABLE pasta DROP COLUMN valor_causa');
    }
}
