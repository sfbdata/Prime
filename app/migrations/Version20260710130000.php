<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cobranças Etapa 7 — deduplicação de Pessoa por DÍGITOS (invariável 24; follow-up #3).
 *
 * Índices FUNCIONAIS que espelham a busca advisory de duplicadas
 * (PessoaRepository::buscarPossiveisDuplicadas): comparação de CPF/CNPJ por dígitos, ignorando a
 * formatação armazenada. `regexp_replace(coalesce(campo,''), '\D', '', 'g')` é IMMUTABLE, logo
 * indexável. Escopados por tenant_id — a busca é sempre intra-tenant (invariável 24). Não há
 * migration de coluna: o caminho de escrita e o schema das entidades do núcleo ficam intactos.
 *
 * Índices funcionais não são expressáveis via atributos Doctrine (#[ORM\Index] só cobre colunas),
 * por isso vivem apenas aqui.
 *
 * ATENÇÃO (drift de schema): como estes índices não têm mapeamento na entidade, o
 * `doctrine:migrations:diff`/`schema:validate` vai considerá-los "fora de sincronia" e um diff
 * gerado automaticamente emitirá `DROP INDEX idx_cobranca_pessoa_tenant_cpf_digitos` (e o de cnpj).
 * Ao gerar a PRÓXIMA migration por diff, REMOVA esses DROPs à mão — senão os índices somem
 * silenciosamente (perda de performance da dedup, sem corromper dado). Princípio: não confiar no
 * diff automático do Doctrine.
 */
final class Version20260710130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cobranças Etapa 7 — índices funcionais para dedup de Pessoa por dígitos de CPF/CNPJ '
            . '(intra-tenant, invariável 24).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "CREATE INDEX idx_cobranca_pessoa_tenant_cpf_digitos ON cobranca_pessoa "
            . "(tenant_id, (regexp_replace(coalesce(cpf, ''), '\\D', '', 'g')))"
        );
        $this->addSql(
            "CREATE INDEX idx_cobranca_pessoa_tenant_cnpj_digitos ON cobranca_pessoa "
            . "(tenant_id, (regexp_replace(coalesce(cnpj, ''), '\\D', '', 'g')))"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_cobranca_pessoa_tenant_cpf_digitos');
        $this->addSql('DROP INDEX idx_cobranca_pessoa_tenant_cnpj_digitos');
    }
}
