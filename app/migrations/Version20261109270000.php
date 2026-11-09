<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Assinatura de documentos, etapa 7, M5 (contrato docs/specs/assinatura-documentos-etapa-7.md, seção 4.1): as colunas
 * da selfie da assinatura Reforçada no signatário.
 *
 * - `selfie_chave`: o nome da chave do arquivo cifrado (categoria ASSINATURA_DOCUMENTO, pasta do escritório);
 * - `selfie_sha256`: o sha256 do JPEG regravado, antes de cifrar;
 * - `selfie_versao`: a versão da chave da selfie (`ASSINATURA_SELFIE_CHAVE`) usada no arquivo.
 *
 * As três ficam nulas de quem não é Reforçada e de quem ainda não assinou. Latitude, longitude e precisão já existem
 * desde a M1 (Version20261109150000) e passam a ser gravadas na Reforçada: nada a mudar nelas.
 */
final class Version20261109270000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Assinatura de documentos (etapa 7): colunas da selfie cifrada da assinatura Reforçada no signatário';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assinatura_eletronica_signatario ADD selfie_chave VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE assinatura_eletronica_signatario ADD selfie_sha256 CHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE assinatura_eletronica_signatario ADD selfie_versao SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assinatura_eletronica_signatario DROP selfie_chave');
        $this->addSql('ALTER TABLE assinatura_eletronica_signatario DROP selfie_sha256');
        $this->addSql('ALTER TABLE assinatura_eletronica_signatario DROP selfie_versao');
    }
}
