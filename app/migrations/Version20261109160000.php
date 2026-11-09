<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BlueJus Voto, fase A2 (spec docs/specs/bluejus-voto.md, D2 item 2 e seção 7, M2): as permissões do schema `voto`
 * para o container próprio do Voto.
 *
 * Cria o papel `voto_acesso` (NOLOGIN, só agrupa permissões) e dá a ele USAGE no schema `voto` e, por privilégio
 * padrão, leitura e escrita nas tabelas e sequências que as migrations FUTURAS criarem nesse schema. A `voto.liberacao`
 * (M1b) fica de fora de propósito: é dado do BlueJus (ato do Master), não da votação, e o container do Voto não a lê
 * nem a grava. Por isso nada de `ON ALL TABLES`.
 *
 * A única tabela do `public` que o papel lê é a `recurso_estado` (só SELECT): o guarda de rota do Master
 * (BloqueioDeRecursoDesativado) a consulta em toda rota `voto_`, inclusive no container do Voto, para a chave `voto`
 * desligada cair fechada na página "em preparação" (503) em vez de 500. Nenhuma outra tabela do BlueJus.
 *
 * O usuário que entra no banco (`voto_app`, LOGIN, com CONNECTION LIMIT e membro de `voto_acesso`) NÃO nasce aqui: a
 * senha só pode existir no .env.prod da VPS, então quem o cria é o Samuel (docs/runbooks/voto-subir-na-vps.md).
 *
 * Papel é do CLUSTER inteiro, não do banco: no dev, saas, saas_ux, saas_test, saas_chat e os clones das frentes
 * dividem o mesmo cluster. Por isso o CREATE ROLE é condicional (a migration roda em cada banco) e o down não apaga o
 * papel (outro banco do cluster pode depender dele); o down só tira as permissões deste banco.
 *
 * O privilégio padrão vale para os objetos criados pelo usuário que roda as migrations (o dono do schema em produção),
 * que é quem cria as tabelas do Voto.
 */
final class Version20261109160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BlueJus Voto: papel voto_acesso com USAGE no schema voto, privilégio padrão só nas tabelas futuras e leitura de recurso_estado';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'voto_acesso') THEN CREATE ROLE voto_acesso NOLOGIN; END IF; END \$\$;");
        $this->addSql('GRANT USAGE ON SCHEMA voto TO voto_acesso');
        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA voto GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO voto_acesso');
        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA voto GRANT USAGE, SELECT ON SEQUENCES TO voto_acesso');
        $this->addSql('GRANT SELECT ON TABLE public.recurso_estado TO voto_acesso');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE SELECT ON TABLE public.recurso_estado FROM voto_acesso');
        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA voto REVOKE USAGE, SELECT ON SEQUENCES FROM voto_acesso');
        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA voto REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLES FROM voto_acesso');
        // Sem DROP ROLE: o papel é do cluster, e outro banco dele pode ainda usá-lo.
        $this->addSql('REVOKE USAGE ON SCHEMA voto FROM voto_acesso');
    }
}
