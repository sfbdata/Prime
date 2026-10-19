<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Agenda: a entidade Evento saiu da pasta legada (App\Entity\Agenda) para o domínio (App\Agenda\Entity).
 * O audit_log guarda o nome completo da classe; sem esta troca, o histórico antigo do Evento some do
 * filtro da auditoria (que lista as classes da metadata) e o "Desfazer" não acha a classe. Só o nome
 * da classe muda; ação, autor, data e alterações ficam como estão. O down faz a troca inversa.
 * Spec: docs/specs/agenda-vs-18-10-26.md, entrega 3.
 */
final class Version20261019125154 extends AbstractMigration
{
    private const ANTIGA = 'App\Entity\Agenda\Evento';
    private const NOVA = 'App\Agenda\Entity\Evento';

    public function getDescription(): string
    {
        return 'Agenda: audit_log.entity_class do Evento para App\Agenda\Entity\Evento';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE audit_log SET entity_class = :nova WHERE entity_class = :antiga', ['nova' => self::NOVA, 'antiga' => self::ANTIGA]);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE audit_log SET entity_class = :antiga WHERE entity_class = :nova', ['nova' => self::NOVA, 'antiga' => self::ANTIGA]);
    }
}
