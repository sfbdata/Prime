<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Convite só com o hash do token (achado de auditoria interna; spec `docs/specs/seguranca-a-convites.md`,
 * raízes B e C).
 *
 * - `invitation.token_hash` recebe o SHA-256 (hex) do token de cada convite que já existe. O link já enviado
 *   traz o token puro e a busca passa a comparar o hash dele, então nenhum link enviado deixa de valer.
 * - O UNIQUE passa para `token_hash` e a coluna `token` sai: o banco deixa de ter o token.
 * - O token também sai do que já foi gravado na auditoria da `Invitation`. O formato atual do
 *   `AuditLogSubscriber` grava em `diff.after` (create), `diff.before` (delete) e `diff.changes` (update); os
 *   caminhos sem o `diff` são do formato antigo e entram por garantia. A chave é apagada, igual ao que o
 *   subscriber faz daqui para a frente com os campos ignorados.
 */
final class Version20261020150500 extends AbstractMigration
{
    private const CAMINHOS_NA_AUDITORIA = [
        '{diff,after,token}',
        '{diff,before,token}',
        '{diff,changes,token}',
        '{after,token}',
        '{before,token}',
        '{changes,token}',
    ];

    public function getDescription(): string
    {
        return 'Convite guarda só o hash do token; o token sai da auditoria do convite';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invitation ADD token_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql("UPDATE invitation SET token_hash = encode(sha256(convert_to(token, 'UTF8')), 'hex')");
        $this->addSql('ALTER TABLE invitation ALTER token_hash SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uq_invitation_token_hash ON invitation (token_hash)');
        // Apagar a coluna leva junto o UNIQUE antigo (uq_invitation_token), seja constraint ou índice.
        $this->addSql('ALTER TABLE invitation DROP COLUMN token');

        $semToken = 'changes::jsonb';
        $temToken = [];
        foreach (self::CAMINHOS_NA_AUDITORIA as $caminho) {
            $semToken = sprintf("(%s #- '%s')", $semToken, $caminho);
            $temToken[] = sprintf("changes::jsonb #> '%s' IS NOT NULL", $caminho);
        }
        $this->addSql(sprintf(
            "UPDATE audit_log SET changes = %s::json WHERE entity_class = 'App\\Entity\\Auth\\Invitation' AND changes IS NOT NULL AND (%s)",
            $semToken,
            implode(' OR ', $temToken),
        ));
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('O token do convite não volta: o banco só tem o hash dele.');
    }
}
