<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "Responder" no Registro dos expedientes (aba Dados da pasta, desenho 1.2.3): uma mensagem
 * do chat da pasta pode responder a outra, com UM nível de recuo.
 *
 * Escrita à mão (sem `make:migration`, que traria o ruído pré-existente do dev — índices
 * funcionais de cobrança e defaults de outra frente). Os nomes da FK e do índice são os que o
 * Doctrine gera para o mapeamento de `PastaMensagem`:
 *   - `resposta_a_id` → FK para a própria `pasta_mensagem`, `ON DELETE SET NULL`: excluir a
 *     original não apaga as respostas dos outros.
 *   - `eh_resposta` → a marca de que a mensagem nasceu como resposta. O `SET NULL` apaga o
 *     vínculo; sem a marca, a tela não teria como dizer "Resposta a uma mensagem excluída".
 *     `DEFAULT false` deixa toda mensagem existente como registro comum.
 */
final class Version20261006010231 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona resposta_a_id e eh_resposta em pasta_mensagem (responder no Registro da pasta)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_mensagem ADD resposta_a_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pasta_mensagem ADD eh_resposta BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE pasta_mensagem ADD CONSTRAINT FK_9D37B71C2EF701A0 FOREIGN KEY (resposta_a_id) REFERENCES pasta_mensagem (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_pasta_mensagem_resposta_a ON pasta_mensagem (resposta_a_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_mensagem DROP CONSTRAINT FK_9D37B71C2EF701A0');
        $this->addSql('DROP INDEX idx_pasta_mensagem_resposta_a');
        $this->addSql('ALTER TABLE pasta_mensagem DROP resposta_a_id');
        $this->addSql('ALTER TABLE pasta_mensagem DROP eh_resposta');
    }
}
