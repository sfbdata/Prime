<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ponto pelo desenho 16, etapa E4 (spec docs/specs/ponto-desenho-0811.md, P44 e P46; decisão do Coordenador de
 * 09/11/2026): o "Motivo" que quem justifica escreve nas janelas "Justificar falta" e "Registro de esquecimento".
 *
 * Coluna nula: as justificativas de antes da E4 e as que o gestor lança pela ficha não têm motivo. A obrigação mora no
 * formulário do colaborador (JustificativaPontoType), não no banco. Sem dado a migrar.
 */
final class Version20261109210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ponto E4: coluna motivo (texto, nula) em justificativa_ponto';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE justificativa_ponto ADD motivo TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE justificativa_ponto DROP motivo');
    }
}
