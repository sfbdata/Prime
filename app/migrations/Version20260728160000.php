<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tipo do telefone da pessoa cobrada: WhatsApp ou telefone comum (2026-07-28).
 *
 * NULLABLE e SEM backfill, por decisão do dono: os telefones já cadastrados não têm tipo declarado, e
 * inferir por contagem de dígitos gravaria um palpite ("11 dígitos logo é WhatsApp") indistinguível de
 * informação real. Nulo se comporta como `fixo` na tela, sem afirmar nada no banco.
 *
 * Escrita à mão, não gerada: `make:migration` faz o diff contra o banco do dev, que carrega alterações
 * de outras frentes ainda não aplicadas, e tenta dropar os índices funcionais criados por SQL cru
 * (`idx_cobranca_pessoa_tenant_cpf_digitos` e companhia). Aqui só entra a coluna desta frente.
 */
final class Version20260728160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona cobranca_pessoa_telefone.tipo (whatsapp|fixo), nullable e sem backfill';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cobranca_pessoa_telefone ADD tipo VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cobranca_pessoa_telefone DROP tipo');
    }
}
