<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "Sair de todos os aparelhos" no Meu Perfil: `user.sessoes_encerradas_em` guarda o instante do botão. Toda sessão
 * aberta antes dele é encerrada no pedido seguinte (as sessões ficam em arquivo, sem lista por usuário; o corte no
 * banco sobrevive ao deploy, o que um cache não faria).
 *
 * **Aditiva:** coluna nula; quem nunca usou o botão segue como antes.
 */
final class Version20261105140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sair de todos os aparelhos: instante do corte das sessões do usuário';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD sessoes_encerradas_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP sessoes_encerradas_em');
    }
}
