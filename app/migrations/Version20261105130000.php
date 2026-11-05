<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "Manter conectado" com token persistente (auditoria 3.29): `rememberme_token` é a tabela do
 * `DoctrineTokenProvider` do Symfony, usada pelo {@see \App\Security\TokenDoManterConectado} (que grava o HMAC do
 * valor, nunca o valor), uma linha por série de cookie. Sair apaga a série; o cookie copiado antes do logout deixa
 * de entrar. Colunas iguais às do `configureSchemaTable` do Symfony (o esquema as compara pelo
 * {@see \App\Security\EsquemaDoManterConectadoListener}).
 *
 * **Aditiva:** só cria a tabela. Os cookies assinados de antes desta versão deixam de valer (o formato muda), e
 * quem os tinha entra de novo com a senha uma vez.
 */
final class Version20261105130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Manter conectado: tabela das séries do cookie (sair revoga)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE rememberme_token (series VARCHAR(88) NOT NULL, value VARCHAR(88) NOT NULL, lastUsed TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, class VARCHAR(100) DEFAULT \'\' NOT NULL, username VARCHAR(200) NOT NULL, PRIMARY KEY (series))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE rememberme_token');
    }
}
