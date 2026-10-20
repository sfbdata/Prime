<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PastaResumoController;
use App\Pasta\Controller\PastaTimelineController;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Ocultar aba além da tela (spec pasta-ocultar-aba §4-§5): as folhas avulsas de impressão das abas
 * ocultas, a Timeline inteligente, o Histórico do sistema e os atalhos `data-ps-ir-aba`.
 */
#[CoversClass(PastaResumoController::class)]
#[CoversClass(PastaTimelineController::class)]
#[Group('pasta')]
final class PastaAbaOcultaFontesTest extends JusPrimeWebTestCase
{
    use CriaCenarioDeAbaOcultaTrait;

    private KernelBrowser $client;
    private Tenant $tenant;
    private User $primario;
    private User $ana;
    private Pasta $pasta;
    private Processo $processo;

    private function cenario(): void
    {
        $this->client = $this->cliente();
        $this->tenant = $this->criarTenant();
        $this->primario = $this->criarPrimario($this->tenant);
        $this->ana = $this->criarColaborador($this->tenant);
        [$this->pasta, $this->processo] = $this->criarPastaComPush($this->tenant);
    }

    private function get(User $quem, string $caminho): int
    {
        $this->logarComTenant($this->client, $quem, $this->tenant);
        $this->client->request('GET', "/pasta/{$this->pasta->getId()}" . $caminho);

        return $this->client->getResponse()->getStatusCode();
    }

    #[TestDox('Folhas avulsas: processo (Processo oculto), extrato (Financeiro oculto) e registro (Detalhes oculto) dão 403; quem vê segue')]
    public function testFolhasDasAbasOcultas(): void
    {
        $this->cenario();
        foreach ([AbaDaPasta::Processo, AbaDaPasta::Financeiro, AbaDaPasta::Detalhes] as $aba) {
            $this->ocultarAba($this->pasta, $aba, $this->primario, [$this->ana]);
        }
        $pid = (int) $this->processo->getId();

        self::assertSame(403, $this->get($this->ana, "/resumo/pdf?secao=processo&processo={$pid}"));
        self::assertSame(403, $this->get($this->ana, '/resumo/imprimir?secao=extrato'));
        self::assertSame(403, $this->get($this->ana, '/resumo/pdf?secao=extrato'));
        self::assertSame(403, $this->get($this->ana, '/resumo/pdf?secao=observacao&obs=1'));
        self::assertSame(200, $this->get($this->ana, '/resumo/imprimir?secao=resumo'), 'o resumo geral sai, sem as seções ocultas');

        self::assertSame(200, $this->get($this->primario, "/resumo/pdf?secao=processo&processo={$pid}"));
        self::assertSame(200, $this->get($this->primario, '/resumo/imprimir?secao=extrato'));
        self::assertSame(404, $this->get($this->primario, '/resumo/pdf?secao=observacao&obs=999999'), 'quem vê cai na conferência de sempre');
    }

    /** @return list<string> */
    private function tiposDaTimeline(User $quem): array
    {
        self::assertSame(200, $this->get($quem, '/timeline'));
        $dados = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return array_map(static fn (array $e): string => $e['tipo'], $dados['eventos']);
    }

    #[TestDox('Timeline inteligente: com Push e Detalhes ocultos, sem publicação nem observação de Detalhes; o primário vê as duas')]
    public function testTimelineSemEventosDasAbasOcultas(): void
    {
        $this->cenario();
        $obs = (new PastaObservacaoDetalhes())->setPasta($this->pasta)->setAutor($this->primario)->setTenant($this->tenant)->setConteudo('Relato do cliente');
        $this->em()->persist($obs);
        $this->em()->flush();
        $this->ocultarAba($this->pasta, AbaDaPasta::Push, $this->primario, [$this->ana]);
        $this->ocultarAba($this->pasta, AbaDaPasta::Detalhes, $this->primario, [$this->ana]);

        $doPrimario = $this->tiposDaTimeline($this->primario);
        self::assertContains('publicacao', $doPrimario);
        self::assertContains('observacao_detalhes', $doPrimario);

        $daAna = $this->tiposDaTimeline($this->ana);
        self::assertNotContains('publicacao', $daAna);
        self::assertNotContains('observacao_detalhes', $daAna);
    }

    #[TestDox('Histórico do sistema (drawer): com Documentos oculto, sem "Documento enviado"; o primário o vê')]
    public function testHistoricoSemEventosDaAbaOculta(): void
    {
        $this->cenario();
        $doc = new \App\Pasta\Entity\PastaDocumento();
        $doc->setTenant($this->tenant);
        $doc->setPasta($this->pasta);
        $doc->setTitulo('Procuração');
        $doc->setCategoria(\App\Pasta\Entity\PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo('teste/' . uniqid() . '.pdf');
        $doc->setNomeOriginal('procuracao.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(1234);
        $this->em()->persist($doc);
        $this->em()->flush();
        // O audit_log não é gravado pelo subscriber neste teste: a linha do envio vai à mão.
        $this->em()->getConnection()->insert('audit_log', [
            'action' => 'create', 'entity_class' => 'App\\Pasta\\Entity\\PastaDocumento', 'entity_id' => (string) $doc->getId(),
            'changes' => json_encode(['diff' => ['after' => ['nomeOriginal' => 'procuracao.pdf']]]), 'tenant_id' => $this->tenant->getId(),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $this->ocultarAba($this->pasta, AbaDaPasta::Documentos, $this->primario, [$this->ana]);

        $this->logarComTenant($this->client, $this->primario, $this->tenant);
        $doPrimario = $this->client->request('GET', "/pasta/{$this->pasta->getId()}")->filter('#psHistorico')->text('');
        $this->logarComTenant($this->client, $this->ana, $this->tenant);
        $daAna = $this->client->request('GET', "/pasta/{$this->pasta->getId()}")->filter('#psHistorico')->text('');

        self::assertStringContainsString('Documento enviado', $doPrimario);
        self::assertStringNotContainsString('Documento enviado', $daAna);
    }

    #[TestDox('Atalhos para aba oculta não são renderizados: sem data-ps-ir-aba para Processo e Documentos')]
    public function testAtalhosParaAbaOculta(): void
    {
        $this->cenario();
        $this->logarComTenant($this->client, $this->ana, $this->tenant);
        $antes = $this->client->request('GET', "/pasta/{$this->pasta->getId()}");
        self::assertGreaterThan(0, $antes->filter('[data-ps-ir-aba="processo-tab"]')->count(), 'com a aba visível o atalho existe');

        $this->ocultarAba($this->pasta, AbaDaPasta::Processo, $this->primario, [$this->ana]);
        $this->ocultarAba($this->pasta, AbaDaPasta::Documentos, $this->primario, [$this->ana]);
        $this->ocultarAba($this->pasta, AbaDaPasta::Tarefas, $this->primario, [$this->ana]);
        $depois = $this->client->request('GET', "/pasta/{$this->pasta->getId()}");

        foreach (['processo-tab', 'documentos-tab', 'tarefas-tab'] as $alvo) {
            self::assertCount(0, $depois->filter('[data-ps-ir-aba="' . $alvo . '"]'), $alvo);
        }
    }
}
