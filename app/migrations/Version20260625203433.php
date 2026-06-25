<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P1 multi-tenant: unicidade do NUP da pasta passa de GLOBAL para por-escritório.
 *
 * Com Pasta agora TenantAware, a checagem de duplicidade (findOneBy(['nup'])) é escopada pelo
 * filtro ao tenant atual; o unique do banco precisa acompanhar, senão dois escritórios com o
 * mesmo NUP violariam a constraint global. Troca o índice unique global por composto
 * (tenant_id, nup). Como o NUP era globalmente único, cada par (tenant_id, nup) já é único —
 * o CREATE não encontra duplicatas. Marcar as entidades de Pasta/Expediente como TenantAware
 * não gera mudança de schema (a coluna tenant_id já existe em todas).
 *
 * Atenção produção: se já houver dois escritórios com o mesmo NUP (impossível hoje, pois o
 * unique era global), o CREATE composto ainda passa; o que muda é a semântica daqui pra frente.
 * O down() recria o unique global e ABORTA se nesse meio-tempo surgir NUP repetido entre tenants.
 */
final class Version20260625203433 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Torna o NUP da pasta único por escritório (tenant_id, nup) em vez de global';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_9b3bbc81bf45c3b7');
        $this->addSql('CREATE UNIQUE INDEX uniq_pasta_tenant_nup ON pasta (tenant_id, nup)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_pasta_tenant_nup');
        $this->addSql('CREATE UNIQUE INDEX uniq_9b3bbc81bf45c3b7 ON pasta (nup)');
    }
}
