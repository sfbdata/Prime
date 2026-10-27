<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PR F da spec `docs/specs/ia-anthropic-ligar.md` ("Ler N documentos"): a versão do texto do aceite da
 * BlueJus IA (`App\Inteligencia\Enum\VersaoDoAceiteDaIa`) em `inteligencia_configuracao.consentimento_versao`.
 *
 * As linhas que já existem ficam com a versão 1 (o texto que não cita os documentos): o aceite delas segue
 * valendo para o resto da IA, e a leitura de documentos espera o administrador renovar (gravar a versão 2).
 * Aditiva: só uma coluna com padrão, sem reescrever dado.
 */
final class Version20261024150400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BlueJus IA: versão do texto do aceite (consentimento_versao), as linhas atuais na versão 1';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inteligencia_configuracao ADD consentimento_versao INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inteligencia_configuracao DROP consentimento_versao');
    }
}
