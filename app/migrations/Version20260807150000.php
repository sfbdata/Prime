<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A carteira passa a guardar até quando os dados estão em dia — a emissão do último relatório
 * importado, POR TIPO, para a tela poder responder "posso confiar neste saldo?".
 *
 * Escrita à mão de propósito: `doctrine:schema:update --dump-sql` propõe junto três `DROP INDEX` em
 * índices FUNCIONAIS (criados por SQL cru, que o mapeamento não sabe representar) e alguns
 * `DROP DEFAULT` de outra frente. Aceitar aquele diff apagaria, entre outros, o índice único que
 * impede obrigação duplicada. Aqui vai só a coluna nova.
 */
final class Version20260807150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cobranca_carteira: guarda a emissão do último relatório importado, por tipo';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cobranca_carteira ADD emissao_por_tipo_de_relatorio JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cobranca_carteira DROP emissao_por_tipo_de_relatorio');
    }
}
