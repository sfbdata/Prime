<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compartilhar pasta com outra conta (spec docs/specs/acesso-restrito-e-compartilhar.md, §4.5, fatia 8): o código
 * pessoal de cada usuário (`BJ-XXXX-XXXX-XXXX`), com que ele recebe pastas de outras contas.
 *
 * Só acrescenta a coluna, vazia: o código nasce na primeira vez que a pessoa o vê (janela "Recebidas" ou "Copiar meu
 * código"). Nenhum usuário, pasta ou permissão muda. O índice único parcial guarda um dono por código.
 */
final class Version20261118120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Usuários: código pessoal para receber pastas de outras contas (user.codigo_pessoal).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD codigo_pessoal VARCHAR(20) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_codigo_pessoal ON "user" (codigo_pessoal) WHERE (codigo_pessoal IS NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_user_codigo_pessoal');
        $this->addSql('ALTER TABLE "user" DROP codigo_pessoal');
    }
}
