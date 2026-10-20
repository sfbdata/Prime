<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Contribua C4b/C4c (Inter, Banco do Brasil e "Outro" na integração bancária; spec
 * docs/specs/contribua-c4b-bancos.md): os campos extras da configuração (D4). Client Secret e app key
 * CIFRADOS (a chave de cifra fica no ambiente); chave PIX, URLs do PSP e a conta corrente do Inter (opcional,
 * cabeçalho `x-conta-corrente`) em claro (não são segredo). Vai na
 * fila depois da C4 (Version20261019230000, que cria a tabela); renomear o horário na vez.
 */
final class Version20261021160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contribua C4b: Client Secret, chave PIX, app key, URLs do PSP e conta corrente na integração bancária';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contribua_integracao_bancaria ADD client_secret_cifrado TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE contribua_integracao_bancaria ADD chave_pix VARCHAR(77) DEFAULT NULL');
        $this->addSql('ALTER TABLE contribua_integracao_bancaria ADD app_key_cifrada TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE contribua_integracao_bancaria ADD url_base VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE contribua_integracao_bancaria ADD url_token VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE contribua_integracao_bancaria ADD conta_corrente VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contribua_integracao_bancaria DROP client_secret_cifrado');
        $this->addSql('ALTER TABLE contribua_integracao_bancaria DROP chave_pix');
        $this->addSql('ALTER TABLE contribua_integracao_bancaria DROP app_key_cifrada');
        $this->addSql('ALTER TABLE contribua_integracao_bancaria DROP url_base');
        $this->addSql('ALTER TABLE contribua_integracao_bancaria DROP url_token');
        $this->addSql('ALTER TABLE contribua_integracao_bancaria DROP conta_corrente');
    }
}
