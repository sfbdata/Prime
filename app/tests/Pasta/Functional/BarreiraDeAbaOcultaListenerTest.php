<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\EventListener\BarreiraDeAbaOcultaListener;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Barreira de servidor do "ocultar aba" (spec pasta-ocultar-aba D3 e §4): uma rota de cada aba do
 * mapa responde 403 para quem a aba está oculta, e não para quem a vê (colega sem regra, primário).
 */
#[CoversClass(BarreiraDeAbaOcultaListener::class)]
#[Group('pasta')]
final class BarreiraDeAbaOcultaListenerTest extends JusPrimeWebTestCase
{
    use CriaCenarioDeAbaOcultaTrait;

    private const RECUSA = 'está oculta para você nesta pasta.';

    /** @return iterable<string, array{AbaDaPasta, string, string, array<string, mixed>}> */
    public static function rotaDeCadaAba(): iterable
    {
        yield 'push: gerar link externo' => [AbaDaPasta::Push, 'POST', '/pasta/{id}/push/links', ['exigir_codigo' => '1']];
        yield 'push: lista do link' => [AbaDaPasta::Push, 'GET', '/pasta/{id}/push/links', []];
        yield 'financeiro: pró-bono' => [AbaDaPasta::Financeiro, 'POST', '/pasta/{id}/pro-bono', ['pro_bono' => '1']];
        yield 'documentos: lixeira' => [AbaDaPasta::Documentos, 'GET', '/pasta/{id}/documentos/lixeira', []];
        yield 'detalhes: nova observação' => [AbaDaPasta::Detalhes, 'POST', '/pasta/{id}/detalhes/observacao', ['conteudo' => 'x']];
        yield 'documentos: checklist' => [AbaDaPasta::Documentos, 'POST', '/pasta/{id}/checklist', ['texto' => 'x']];
        yield 'processo: buscar processos' => [AbaDaPasta::Processo, 'GET', '/pasta/{id}/processo/buscar?q=1', []];
        yield 'processo: vincular' => [AbaDaPasta::Processo, 'POST', '/pasta/{id}/processo/vincular', []];
        yield 'tarefas: novo prazo' => [AbaDaPasta::Tarefas, 'POST', '/pasta/{id}/prazos', []];
    }

    /** @param array<string, mixed> $corpo */
    private function pedir(KernelBrowser $client, string $metodo, string $rota, Pasta $pasta, array $corpo): string
    {
        $client->request($metodo, str_replace('{id}', (string) $pasta->getId(), $rota), $corpo, [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        return (string) $client->getResponse()->getContent();
    }

    /** @return array{KernelBrowser, Tenant, User, User, User, Pasta} */
    private function cenario(): array
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $primario = $this->criarPrimario($tenant);
        $ana = $this->criarColaborador($tenant, 'Ana Oculta');
        $beto = $this->criarColaborador($tenant, 'Beto Vê');
        [$pasta] = $this->criarPastaComPush($tenant);

        return [$client, $tenant, $primario, $ana, $beto, $pasta];
    }

    /** @param array<string, mixed> $corpo */
    #[DataProvider('rotaDeCadaAba')]
    #[TestDox('$aba oculta: $metodo $rota responde 403 com o aviso; o colega e o primário passam pela barreira')]
    public function testBarreira(AbaDaPasta $aba, string $metodo, string $rota, array $corpo): void
    {
        [$client, $tenant, $primario, $ana, $beto, $pasta] = $this->cenario();
        $this->ocultarAba($pasta, $aba, $primario, [$ana, $primario]);

        $this->logarComTenant($client, $ana, $tenant);
        $resposta = $this->pedir($client, $metodo, $rota, $pasta, $corpo);
        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('A aba ' . $aba->rotulo() . ' ' . self::RECUSA, json_decode($resposta, true, 512, JSON_THROW_ON_ERROR)['erro']);

        foreach ([$beto, $primario] as $quemVe) {
            $this->logarComTenant($client, $quemVe, $tenant);
            self::assertStringNotContainsString(self::RECUSA, $this->pedir($client, $metodo, $rota, $pasta, $corpo));
        }
    }

    #[TestDox('Push oculto: gerar o link externo é recusado e nada é gravado')]
    public function testPushOcultoNaoGeraLink(): void
    {
        [$client, $tenant, $primario, $ana, , $pasta] = $this->cenario();
        $this->ocultarAba($pasta, AbaDaPasta::Push, $primario);
        $this->instalarCsrfPrevisivel();

        $this->logarComTenant($client, $ana, $tenant);
        $client->request('POST', "/pasta/{$pasta->getId()}/push/links", ['_token' => 'TOKEN_pasta_push_link_' . $pasta->getId(), 'exigir_codigo' => '1']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM push_link_externo WHERE pasta_id = :p', ['p' => $pasta->getId()]));
    }

    #[TestDox('Rota de filha (documento pelo id dele): a barreira acha a pasta pelo documento')]
    public function testRotaDeFilha(): void
    {
        [$client, $tenant, $primario, $ana, , $pasta] = $this->cenario();
        $this->ocultarAba($pasta, AbaDaPasta::Documentos, $primario);
        $doc = new \App\Pasta\Entity\PastaDocumento();
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $doc->setTitulo('Petição');
        $doc->setCategoria(\App\Pasta\Entity\PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo('teste/' . uniqid() . '.pdf');
        $doc->setNomeOriginal('peticao.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(1234);
        $this->em()->persist($doc);
        $this->em()->flush();

        $this->logarComTenant($client, $ana, $tenant);
        $client->request('GET', "/pasta/documento/{$doc->getId()}/download");

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Navegação (GET sem AJAX): página 403, sem JSON')]
    public function testNavegacao403(): void
    {
        [$client, $tenant, $primario, $ana, , $pasta] = $this->cenario();
        $this->ocultarAba($pasta, AbaDaPasta::Documentos, $primario);

        $this->logarComTenant($client, $ana, $tenant);
        $client->request('GET', "/pasta/{$pasta->getId()}/peticionar");

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Pasta de outro escritório: a barreira não se manifesta (a action responde o próprio 404)')]
    public function testOutroEscritorioPassaReto(): void
    {
        [$client, $tenant, $primario, $ana, , $pasta] = $this->cenario();
        $this->ocultarAba($pasta, AbaDaPasta::Push, $primario);
        $outro = $this->criarTenant();
        $estranho = $this->criarColaborador($outro);

        $this->logarComTenant($client, $estranho, $outro);
        $resposta = $this->pedir($client, 'GET', '/pasta/{id}/push/links', $pasta, []);

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(self::RECUSA, $resposta);
    }
}
