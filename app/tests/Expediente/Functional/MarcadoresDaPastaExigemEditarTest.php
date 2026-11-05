<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\Controller\ExpedienteController;
use App\Expediente\Entity\Marcador;
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
