<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClientePF;
use App\Controller\PastaController;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Lote L1 da auditoria 2 da Pasta (desenho 1.2.3): cabeçalho (C4 — selo com 0 — NÃO aplicado: o zero já foi bug corrigido na revisão anterior, ver D-PASTA6;
 * C5 "PASTA" e número como itens), moldura do modal "Editar dados" (C2) e drawer do
 * histórico (D4 hora embaixo do texto, D5 título + subtítulo + fechar com moldura).
 *
 * Arranjo com combinador de FILHO DIRETO. Cor, raio, sombra e gradiente não são
 * visíveis aqui — ficam para o smoke do dono.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaCabecalhoAuditoria2TelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function criarPastaNumerada(Tenant $tenant, string $nup, ?string $identificador): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setTenant($tenant);
        if ($identificador !== null) {
            $pasta->setNomeCliente($identificador);
        }
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    #[TestDox('C5: "PASTA" e o número são itens do selo do número; o texto copiado continua "PASTA nnnn"')]
    public function testPastaENumeroComoItens(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaNumerada($tenant, '3102', null);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        $nup = $crawler->filter('.ps-cab-identidade > .ps-cab-nup');
        self::assertCount(2, $nup->filter('.ps-cab-nup > span'));
        self::assertSame('PASTA', trim($nup->filter('.ps-cab-nup > span')->first()->text()));
        self::assertSame('3102', trim($nup->filter('.ps-cab-nup > span.ps-num')->text()));
        self::assertSame('PASTA 3102', trim($nup->text()));
    }

    #[TestDox('C2: moldura do Editar dados — cabeçalho com título e cliente, rótulos e rodapé Fechar · Salvar')]
    public function testMolduraDoModalEditarDados(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaNumerada($tenant, '3103', 'TEXTO LEGADO');

        $cliente = new ClientePF();
        $cliente->setEmail('l1' . uniqid() . '@test.com');
        $cliente->setCep('80000-000');
        $cliente->setEndereco('Rua Um, 1');
        $cliente->setCidade('Curitiba');
        $cliente->setEstado('PR');
        $cliente->setTenant($tenant);
        $cliente->setNomeCompleto('Joao Batista Moreira');
        $cliente->setCpf('12345678901');
        $cliente->setRg('12.345.678-9');
        $cliente->setRgOrgaoExpedidor('SSP');
        $this->em()->persist($cliente);
        $pasta->addCliente($cliente);
        $pasta->definirClientePrincipal($cliente);
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        $conteudo = $crawler->filter('#modalEditarPasta > .modal-dialog.ps-modal-pasta > .modal-content');
        self::assertCount(1, $conteudo);
        self::assertSame('modalEditarPastaLabel', $crawler->filter('#modalEditarPasta')->attr('aria-labelledby'));

        $cab = $conteudo->filter('.modal-content > .ps-modal-pasta-cab');
        self::assertCount(1, $cab);
        self::assertCount(1, $cab->filter('.ps-modal-pasta-cab > .ps-modal-pasta-cab-icone > i.bi-folder2-open'));
        $titulo = $cab->filter('.ps-modal-pasta-cab > .ps-modal-pasta-cab-corpo > #modalEditarPastaLabel.ps-modal-pasta-titulo');
        self::assertCount(1, $titulo);
        self::assertStringContainsString('Pasta 3103', $titulo->text());
        $sub = $cab->filter('.ps-modal-pasta-cab-corpo > .ps-modal-pasta-sub')->text();
        self::assertStringContainsString($cliente->getNomeExibicao(), $sub, 'o subtítulo é o cliente do cadastro');
        self::assertMatchesRegularExpression('/CPF.*123\.?456\.?789-?01/', $sub);
        self::assertCount(1, $cab->filter('.ps-modal-pasta-cab > button.ps-modal-pasta-fechar[data-bs-dismiss="modal"]'));

        // O contrato do PastaController::edit não muda: mesma action, mesmo CSRF, mesmos names.
        $form = $conteudo->filter('.modal-content > form');
        self::assertCount(1, $form);
        self::assertSame('/pasta/' . $pasta->getId() . '/editar', parse_url((string) $form->attr('action'), PHP_URL_PATH));
        self::assertSame('post', strtolower((string) $form->attr('method')));
        self::assertCount(1, $form->filter('input[type="hidden"][name="_token"]'));
        self::assertCount(1, $form->filter('.ps-modal-pasta-corpo #edit_nup[name="nup"][required]'));
        self::assertCount(1, $form->filter('.ps-modal-pasta-corpo #edit_nome_cliente[name="nome_cliente"]'));
        self::assertCount(1, $form->filter('.ps-modal-pasta-corpo select#edit_situacao[name="situacao"]'));
        $acao = $form->filter('.ps-modal-pasta-corpo #edit_nome_acao[name="nome_acao"]');
        self::assertCount(1, $acao, 'a Ação continua no form (decisão D-META)');
        self::assertNull($acao->attr('readonly'), 'a Ação continua editável');
        self::assertNull($acao->attr('disabled'));
        self::assertCount(1, $form->filter('.ps-modal-pasta-corpo .ps-modal-pasta-campo--2 #edit_nome_cliente'), 'o nome do cliente ocupa duas colunas');
        self::assertCount(4, $form->filter('.ps-modal-pasta-corpo > .ps-modal-pasta-linha > .ps-modal-pasta-campo > label.ps-modal-pasta-rotulo'));

        $rodape = $form->filter('form > .ps-modal-pasta-rodape');
        self::assertCount(1, $rodape);
        $botoes = $rodape->filter('.ps-modal-pasta-rodape > button');
        self::assertCount(2, $botoes);
        self::assertSame('Fechar', trim($botoes->eq(0)->text()));
        self::assertSame('modal', $botoes->eq(0)->attr('data-bs-dismiss'));
        self::assertSame('button', $botoes->eq(0)->attr('type'));
        self::assertSame('Salvar', trim($botoes->eq(1)->text()));
        self::assertSame('submit', $botoes->eq(1)->attr('type'));

        // O ⋮ continua abrindo o mesmo modal.
        self::assertCount(1, $crawler->filter('#psMenuAcoes > .ps-pop-item[data-bs-target="#modalEditarPasta"]'));
    }

    #[TestDox('C2: sem cliente cadastrado o subtítulo é o identificador da pasta (o setter grava em maiúsculas)')]
    public function testSubtituloDoModalSemCadastro(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaNumerada($tenant, '3104', 'Maria das Gracas');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        self::assertSame(
            $pasta->getNomeCliente(),
            trim($crawler->filter('#modalEditarPasta .ps-modal-pasta-cab-corpo > .ps-modal-pasta-sub')->text())
        );
        self::assertSame($pasta->getNomeCliente(), $crawler->filter('#edit_nome_cliente')->attr('value'));
    }

    #[TestDox('D4/D5: drawer com título + subtítulo da contagem, fechar com moldura e hora embaixo do texto')]
    public function testDrawerDoHistorico(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaNumerada($tenant, '3105', 'HISTORICO');

        // Dois eventos do sistema na auditoria (mesmo caminho do ExclusaoLapideNaTelaTest).
        $conexao = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        foreach (['2026-08-28 16:57:06', '2026-08-28 17:02:00'] as $quando) {
            $conexao->executeStatement(
                "INSERT INTO audit_log (action, entity_class, entity_id, changes, actor_user_id, actor_email, tenant_id, route, created_at)
                 VALUES ('delete', ?, ?, ?, ?, ?, ?, 'pasta_delete', ?)",
                [
                    Pasta::class,
                    (string) $pasta->getId(),
                    json_encode(['diff' => ['before' => ['nup' => '3105']]], JSON_THROW_ON_ERROR),
                    $user->getId(),
                    $user->getEmail(),
                    $tenant->getId(),
                    $quando,
                ],
            );
        }

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        $cab = $crawler->filter('#psHistorico > .ps-drawer-cab');
        self::assertCount(1, $cab);
        self::assertCount(0, $cab->filter('.ps-drawer-cab > i'), 'sem ícone no cabeçalho');
        self::assertCount(0, $cab->filter('.ps-contagem'), 'a contagem virou subtítulo');
        self::assertSame('Histórico do sistema', trim($cab->filter('.ps-drawer-cab > div > h2#psHistoricoTitulo')->text()));
        $fechar = $cab->filter('.ps-drawer-cab > button.ps-drawer-fechar.ps-hist-fechar');
        self::assertCount(1, $fechar, 'mantém .ps-drawer-fechar, o gancho do pasta-show.js');
        self::assertSame('×', trim($fechar->text()));

        $itens = $crawler->filter('#psHistorico > .ps-drawer-corpo > .ps-hist-item');
        $n     = $itens->count();
        self::assertGreaterThanOrEqual(2, $n);
        self::assertSame(
            $n . ' alterações registradas automaticamente',
            trim($cab->filter('.ps-drawer-cab > div > .ps-hist-sub')->text()),
            'a contagem do subtítulo é a dos itens, nunca literal'
        );
        self::assertCount($n, $crawler->filter('.ps-hist-item > .ps-hist-corpo > .ps-hist-hora'), 'a hora fica embaixo do texto');
        self::assertCount(0, $crawler->filter('.ps-hist-item > .ps-hist-hora'), 'não há mais coluna de hora à direita');
        self::assertMatchesRegularExpression('/^\d{2}:\d{2}$/', trim($crawler->filter('.ps-hist-corpo > .ps-hist-hora')->first()->text()));
    }
}
