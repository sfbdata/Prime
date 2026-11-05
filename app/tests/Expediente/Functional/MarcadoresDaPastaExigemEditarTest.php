<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Entity\Auth\User;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Expediente\Controller\ExpedienteController;
use App\Expediente\Entity\Marcador;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Controller\DemandasLinhaController;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Auditoria de segurança 3.10/3.11: trocar os marcadores da pasta é EDITAR a pasta. As duas rotas
 * (POST /expediente/pasta/{id}/marcadores e POST /demandas/pasta/{id}/marcadores) recusam com 403 quem só vê,
 * sem gravar; quem edita (permissão do papel) e o primário seguem gravando; pasta de outro escritório é 404.
 * A Lista e o cabeçalho da pasta só entregam o botão e o token a quem edita.
 */
#[CoversClass(ExpedienteController::class)]
#[CoversClass(DemandasLinhaController::class)]
final class MarcadoresDaPastaExigemEditarTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const SO_VE = ['modules.expediente.view', 'modules.pastas.view', 'resources.pasta.view'];
    private const EDITA = ['modules.expediente.view', 'modules.pastas.view', 'resources.pasta.view', 'resources.pasta.edit'];

    /** @return iterable<string, array{string}> */
    public static function rotas(): iterable
    {
        yield 'Expediente' => ['/expediente/pasta/%d/marcadores'];
        yield 'Demandas' => ['/demandas/pasta/%d/marcadores'];
    }

    #[DataProvider('rotas')]
    #[TestDox('quem só vê a pasta recebe 403 em $rota e os marcadores ficam iguais')]
    public function testQuemSoVeRecebe403(string $rota): void
    {
        $this->prepararCliente();
        [$primario, $tenant] = $this->criarEscritorio();
        $leitor = $this->criarColaborador($tenant, 'Lia Leitora', self::SO_VE);
        $pasta = $this->criarPasta($tenant);
        $antigo = $this->marcador($tenant, $primario, 'Antigo');
        $novo = $this->marcador($tenant, $primario, 'Novo');
        $pasta->addMarcador($antigo);
        $this->em()->flush();

        $this->entrar($leitor, $tenant);
        $this->enviar($rota, $pasta, [$novo]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([$antigo->getId()], $this->idsGravados($pasta));
    }

    #[DataProvider('rotas')]
    #[TestDox('quem edita a pasta e o primário gravam em $rota')]
    public function testQuemEditaGrava(string $rota): void
    {
        $this->prepararCliente();
        [$primario, $tenant] = $this->criarEscritorio();
        $editor = $this->criarColaborador($tenant, 'Edu Editor', self::EDITA);
        $pasta = $this->criarPasta($tenant);
        $um = $this->marcador($tenant, $primario, 'Um');
        $dois = $this->marcador($tenant, $primario, 'Dois');

        $this->entrar($editor, $tenant);
        $this->enviar($rota, $pasta, [$um]);
        self::assertResponseIsSuccessful();
        self::assertSame([$um->getId()], $this->idsGravados($pasta));

        $this->entrar($primario, $tenant);
        $this->enviar($rota, $pasta, [$dois]);
        self::assertResponseIsSuccessful();
        self::assertSame([$dois->getId()], $this->idsGravados($pasta));
    }

    #[DataProvider('rotas')]
    #[TestDox('acesso item a item com edição (ResourceAccess can_edit) grava em $rota e vê o botão na Lista')]
    public function testAcessoItemAItemComEdicaoGrava(string $rota): void
    {
        $this->prepararCliente();
        [$primario, $tenant] = $this->criarEscritorio();
        $leitor = $this->criarColaborador($tenant, 'Lia Leitora', self::SO_VE);
        $pasta = $this->criarPasta($tenant);
        $marcador = $this->marcador($tenant, $primario, 'Item');
        $acesso = new ResourceAccess();
        $acesso->setUser($leitor);
        $acesso->setTenant($tenant);
        $acesso->setResourceType(ResourceAccess::RESOURCE_PASTA);
        $acesso->setResourceId((int) $pasta->getId());
        $acesso->setCanView(true);
        $acesso->setCanEdit(true);
        $this->em()->persist($acesso);
        $this->em()->flush();

        $this->entrar($leitor, $tenant);
        $this->enviar($rota, $pasta, [$marcador]);
        self::assertResponseIsSuccessful();
        self::assertSame([$marcador->getId()], $this->idsGravados($pasta));

        $crawler = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral');
        self::assertCount(1, $crawler->filter(sprintf('td.pasta-marcadores-col[data-pasta-id="%d"] [data-csrf-token]', $pasta->getId())));
    }

    #[DataProvider('rotas')]
    #[TestDox('o cadeado em Visualizar é teto: quem edita pelo papel recebe 403 em $rota e não vê o botão')]
    public function testCadeadoEmVisualizarETeto(string $rota): void
    {
        $this->prepararCliente();
        [$primario, $tenant] = $this->criarEscritorio();
        $editor = $this->criarColaborador($tenant, 'Edu Editor', self::EDITA);
        $pasta = $this->criarPasta($tenant);
        $marcador = $this->marcador($tenant, $primario, 'Teto');
        $this->trancar($pasta, $primario, [[$editor, NivelDeAcesso::Visualizar]]);

        $this->entrar($editor, $tenant);
        $crawler = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral');
        self::assertCount(0, $crawler->filter(sprintf('td.pasta-marcadores-col[data-pasta-id="%d"] [data-csrf-token]', $pasta->getId())));

        $this->enviar($rota, $pasta, [$marcador]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->idsGravados($pasta));
    }

    #[DataProvider('rotas')]
    #[TestDox('pasta de outro escritório continua 404 em $rota, sem gravar')]
    public function testOutroEscritorio404(string $rota): void
    {
        $this->prepararCliente();
        [$primario, $tenant] = $this->criarEscritorio();
        [, $outro] = $this->criarEscritorio();
        $alheia = $this->criarPasta($outro);
        $marcador = $this->marcador($tenant, $primario, 'Meu');

        $this->entrar($primario, $tenant);
        $this->enviar($rota, $alheia, [$marcador]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->idsGravados($alheia));
    }

    #[TestDox('a Lista só entrega o botão e o token dos marcadores a quem edita')]
    public function testListaSoMostraOBotaoAQuemEdita(): void
    {
        $this->prepararCliente();
        [$primario, $tenant] = $this->criarEscritorio();
        $leitor = $this->criarColaborador($tenant, 'Lia Leitora', self::SO_VE);
        $editor = $this->criarColaborador($tenant, 'Edu Editor', self::EDITA);
        $pasta = $this->criarPasta($tenant);
        $pasta->addMarcador($this->marcador($tenant, $primario, 'Na pasta'));
        $this->em()->flush();
        $botao = sprintf('td.pasta-marcadores-col[data-pasta-id="%d"] [data-csrf-token]', $pasta->getId());
        $chip = sprintf('td.pasta-marcadores-col[data-pasta-id="%d"] .js-marc-pop', $pasta->getId());

        $this->entrar($leitor, $tenant);
        $crawler = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(sprintf('td.pasta-marcadores-col[data-pasta-id="%d"]', $pasta->getId())), 'a pasta aparece na Lista');
        self::assertCount(0, $crawler->filter($botao), 'quem só vê não recebe o token dos marcadores');
        self::assertCount(0, $crawler->filter($chip), 'nem o chip que abre o seletor');

        $this->entrar($editor, $tenant);
        $crawler = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter($botao));
        self::assertGreaterThan(0, $crawler->filter($chip)->count());
    }

    #[TestDox('a Lista das Demandas também só entrega o botão e o token a quem edita')]
    public function testListaDasDemandasSoMostraOBotaoAQuemEdita(): void
    {
        $this->prepararCliente();
        [, $tenant] = $this->criarEscritorio();
        $leitor = $this->criarColaborador($tenant, 'Lia Leitora', self::SO_VE);
        $editor = $this->criarColaborador($tenant, 'Edu Editor', self::EDITA);
        // As Demandas são as pastas de que a pessoa é responsável: uma para cada.
        $daLeitora = $this->criarPasta($tenant);
        $daLeitora->setResponsavel($leitor);
        $doEditor = $this->criarPasta($tenant);
        $doEditor->setResponsavel($editor);
        $this->em()->flush();
        $celula = 'td.pasta-marcadores-col[data-pasta-id="%d"]';

        $this->entrar($leitor, $tenant);
        $crawler = $this->client->request('GET', '/demandas');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(sprintf($celula, $daLeitora->getId())), 'a pasta aparece nas Demandas');
        self::assertCount(0, $crawler->filter(sprintf($celula, $daLeitora->getId()) . ' [data-csrf-token]'));

        $this->entrar($editor, $tenant);
        $crawler = $this->client->request('GET', '/demandas');
        self::assertCount(1, $crawler->filter(sprintf($celula, $doEditor->getId()) . ' [data-csrf-token]'));
    }

    #[TestDox('o "+" de marcadores do cabeçalho da pasta só aparece para quem edita')]
    public function testCabecalhoSoMostraOMaisAQuemEdita(): void
    {
        $this->prepararCliente();
        [, $tenant] = $this->criarEscritorio();
        $leitor = $this->criarColaborador($tenant, 'Lia Leitora', self::SO_VE);
        $editor = $this->criarColaborador($tenant, 'Edu Editor', self::EDITA);
        $pasta = $this->criarPasta($tenant);

        $this->entrar($leitor, $tenant);
        $crawler = $this->client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.ps-etiqueta-add.js-mover-para'));

        $this->entrar($editor, $tenant);
        $crawler = $this->client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.ps-etiqueta-add.js-mover-para'));
    }

    // ----------------------------------------------------------------- apoio

    private function marcador(Tenant $tenant, User $por, string $nome): Marcador
    {
        $marcador = new Marcador($nome . ' ' . uniqid(), $tenant, $por);
        $this->em()->persist($marcador);
        $this->em()->flush();

        return $marcador;
    }

    /** @param list<Marcador> $marcadores */
    private function enviar(string $rota, Pasta $pasta, array $marcadores): void
    {
        $this->client->xmlHttpRequest('POST', sprintf($rota, $pasta->getId()), [
            '_token'     => 'TOKEN_pasta_marcadores_' . $pasta->getId(),
            'marcadores' => array_map(static fn (Marcador $m): string => (string) $m->getId(), $marcadores),
        ]);
    }

    /** @return list<int> */
    private function idsGravados(Pasta $pasta): array
    {
        $ids = $this->em()->getConnection()->fetchFirstColumn(
            'SELECT marcador_id FROM pasta_marcador WHERE pasta_id = ? ORDER BY marcador_id',
            [$pasta->getId()],
        );

        return array_map('intval', $ids);
    }
}
