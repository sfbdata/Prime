<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Central de Comunicação A2 (`docs/specs/central-comunicacao-a2.md`): responder uma mensagem
 * (`central_mensagem.resposta_a_id`, apagar a original só tira a citação) e "marcar como não lida"
 * (`central_participante.marcada_nao_lida`, sinal à parte do cursor, que continua sem recuar).
 *
 * Só colunas novas, nula ou com padrão — sem dado a migrar. A DDL veio do `--dump-sql` filtrado por `central_` no
 * banco da frente (que já tem a A1); o resto do dump é divergência de outras frentes e dos índices funcionais.
 */
final class Version20261015090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Central A2: resposta_a_id em central_mensagem e marcada_nao_lida em central_participante';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE central_mensagem ADD resposta_a_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE central_mensagem ADD CONSTRAINT FK_74FC94E02EF701A0 FOREIGN KEY (resposta_a_id) REFERENCES central_mensagem (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_74FC94E02EF701A0 ON central_mensagem (resposta_a_id)');
        $this->addSql('ALTER TABLE central_participante ADD marcada_nao_lida BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE central_participante DROP marcada_nao_lida');
        $this->addSql('ALTER TABLE central_mensagem DROP CONSTRAINT FK_74FC94E02EF701A0');
        $this->addSql('DROP INDEX IDX_74FC94E02EF701A0');
        $this->addSql('ALTER TABLE central_mensagem DROP resposta_a_id');
    }
}
