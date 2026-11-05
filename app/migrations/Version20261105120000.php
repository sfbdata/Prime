<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * O texto do modelo sai do que já foi gravado na auditoria da `AnaliseDeInteligencia` (item 1.18 da auditoria
 * interna, lote L-2a-baixas).
 *
 * A expiração diária do conteúdo da IA (app:inteligencia:expirar-conteudo) limpa os campos de texto só na linha da
 * análise, por DQL, sem passar pelo `AuditLogSubscriber`. A cópia gravada no `audit_log` (resumo, pontos, quem age,
 * texto da análise, texto bruto e a versão editada) sobrevivia ao prazo. Daqui para a frente o subscriber ignora
 * esses campos (`IGNORED_FIELDS_POR_CLASSE`); esta migration apaga as mesmas chaves das linhas antigas, nos caminhos
 * que o subscriber grava: `diff.after` (create), `diff.before` (delete) e `diff.changes` (update). A entidade nasceu
 * depois do formato com `diff`, então não há caminho antigo a cobrir. Status, provedor, modelo, tokens, datas, quem
 * pediu e quem editou ficam.
 *
 * Só sai a chave de um pai que é objeto: o `#-` com chave de texto num array abortaria a migration. A linha sem
 * nenhuma das chaves não é reescrita.
 */
final class Version20261105120000 extends AbstractMigration
{
    private const CLASSE = 'App\Inteligencia\Entity\AnaliseDeInteligencia';

    private const PAIS = ['{diff,after}', '{diff,before}', '{diff,changes}'];

    private const CAMPOS = ['resumo', 'pontos', 'quemAge', 'textoDaAnalise', 'textoBruto', 'resumoEditado', 'pontosEditados'];

    public function getDescription(): string
    {
        return 'Tira o texto da IA das linhas antigas do audit_log da AnaliseDeInteligencia';
    }

    public function up(Schema $schema): void
    {
        // Uma remoção por caminho, como na Version20261020150500.
        foreach (self::PAIS as $pai) {
            foreach (self::CAMPOS as $campo) {
                $caminho = substr($pai, 0, -1) . ',' . $campo . '}';
                $this->addSql(sprintf(
                    "UPDATE audit_log SET changes = (changes::jsonb #- '%s')::json WHERE entity_class = '%s' AND changes IS NOT NULL AND jsonb_typeof(changes::jsonb #> '%s') = 'object' AND changes::jsonb #> '%s' IS NOT NULL",
                    $caminho,
                    self::CLASSE,
                    $pai,
                    $caminho,
                ));
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('O texto da IA apagado do audit_log não volta.');
    }
}
