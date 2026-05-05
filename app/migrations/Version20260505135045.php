<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260505135045 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalizar campos de texto para maiúsculo nos módulos Clientes, Processos, Demandas e Expediente';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE cliente SET
            endereco   = UPPER(TRIM(endereco)),
            cidade     = UPPER(TRIM(cidade)),
            estado     = UPPER(TRIM(estado)),
            complemento = CASE WHEN complemento IS NOT NULL THEN UPPER(TRIM(complemento)) ELSE NULL END");

        $this->addSql("UPDATE cliente_pf SET
            nome_completo      = UPPER(TRIM(nome_completo)),
            rg                 = UPPER(TRIM(rg)),
            rg_orgao_expedidor = UPPER(TRIM(rg_orgao_expedidor)),
            estado_civil = CASE WHEN estado_civil IS NOT NULL THEN UPPER(TRIM(estado_civil)) ELSE NULL END,
            profissao    = CASE WHEN profissao    IS NOT NULL THEN UPPER(TRIM(profissao))    ELSE NULL END");

        $this->addSql("UPDATE cliente_pj SET
            razao_social       = UPPER(TRIM(razao_social)),
            enderec_sede       = UPPER(TRIM(enderec_sede)),
            representante_legal = UPPER(TRIM(representante_legal)),
            representante_rg   = UPPER(TRIM(representante_rg)),
            representante_cargo = UPPER(TRIM(representante_cargo)),
            nome_fantasia      = CASE WHEN nome_fantasia      IS NOT NULL THEN UPPER(TRIM(nome_fantasia))      ELSE NULL END,
            inscricao_estadual = CASE WHEN inscricao_estadual IS NOT NULL THEN UPPER(TRIM(inscricao_estadual)) ELSE NULL END,
            inscricao_municipal = CASE WHEN inscricao_municipal IS NOT NULL THEN UPPER(TRIM(inscricao_municipal)) ELSE NULL END");

        $this->addSql("UPDATE processo SET
            orgao_julgador     = UPPER(TRIM(orgao_julgador)),
            sigla_tribunal     = UPPER(TRIM(sigla_tribunal)),
            classe_processual  = UPPER(TRIM(classe_processual)),
            assunto_processual = UPPER(TRIM(assunto_processual)),
            situacao_processo  = UPPER(TRIM(situacao_processo)),
            instancia          = UPPER(TRIM(instancia))");

        $this->addSql("UPDATE parte_processo SET
            tipo = UPPER(TRIM(tipo)),
            nome = UPPER(TRIM(nome)),
            papel = CASE WHEN papel IS NOT NULL THEN UPPER(TRIM(papel)) ELSE NULL END");

        $this->addSql("UPDATE movimentacao_processo SET
            descricao = UPPER(TRIM(descricao)),
            tipo  = CASE WHEN tipo  IS NOT NULL THEN UPPER(TRIM(tipo))  ELSE NULL END,
            orgao = CASE WHEN orgao IS NOT NULL THEN UPPER(TRIM(orgao)) ELSE NULL END");

        $this->addSql("UPDATE pasta SET
            nup          = UPPER(TRIM(nup)),
            nome_cliente = CASE WHEN nome_cliente IS NOT NULL THEN UPPER(TRIM(nome_cliente)) ELSE NULL END,
            nome_acao    = CASE WHEN nome_acao    IS NOT NULL THEN UPPER(TRIM(nome_acao))    ELSE NULL END");

        $this->addSql("UPDATE pasta_documento SET
            titulo = UPPER(TRIM(titulo)),
            numero = CASE WHEN numero IS NOT NULL THEN UPPER(TRIM(numero)) ELSE NULL END");

        $this->addSql("UPDATE pasta_secao SET nome = UPPER(TRIM(nome))");

        $this->addSql("UPDATE pasta_checklist_item SET titulo = UPPER(TRIM(titulo))");
    }

    public function down(Schema $schema): void
    {
        // Não é possível reverter maiúsculo para capitalização original sem backup
    }
}
