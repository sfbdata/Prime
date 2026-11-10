<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\PontoController;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Rota de excluir a própria justificativa em análise (P41, desenho do Ponto E3). Dois caminhos:
 *  - o pedido da tela (fetch com o token da página): JSON sem flash, porque a tela tira a linha sem recarregar.
 *    403 para quem não pode (outra pessoa, sem token), 422 para a já analisada, 404 para a de outro escritório;
 *  - o formulário comum, sem JavaScript (`_token` por id): flash e volta para a aba das justificativas. A já analisada
 *    vira o aviso; outra pessoa, outro escritório e o token errado continuam 403, 404 e 403.
 */
#[Group('ponto')]
#[CoversClass(PontoController::class)]
final class ExcluirJustificativaControllerTest extends JusPrimeWebTestCase
{
    use CenarioDeJustificativaTrait;

    private KernelBrowser $client;
    private Tenant $escritorio;
    private User $dono;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfPrevisivel();
        $this->escritorio = $this->escritorio('Escritório Excluir');
        $this->dono       = $this->pessoa('dono');
        $this->vincularColaborador($this->dono, $this->escritorio);
    }

    #[TestDox('Pela tela: o dono exclui a que está em análise, 200 com JSON, some do banco e nenhum flash fica para depois')]
    public function testDonoExcluiAEmAnalise(): void
    {
        $j  = $this->justificativa($this->dono, $this->escritorio, new \DateTimeImmutable('first day of this month'), 'pendente');
        $id = (int) $j->getId();

        $this->excluir($this->dono, $this->escritorio, $id);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(['ok' => true], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertNull($this->statusNoBanco($id), 'a justificativa saiu');

        $tela = $this->client->request('GET', '/ponto/?aba=justificativas');
        self::assertStringNotContainsString(
            sprintf('Justificativa de %s excluída.', (new \DateTimeImmutable('first day of this month'))->format('d/m/Y')),
            $tela->text(),
            'a tela tirou a linha: o flash sobraria na próxima página',
        );
    }

    #[TestDox('Pela tela, só com o X-Requested-With e o X-CSRF-Token (sem Accept de JSON): continua JSON, sem redirecionar')]
    public function testPedidoDaTelaSemAccept(): void
    {
        $j = $this->justificativa($this->dono, $this->escritorio, new \DateTimeImmutable('first day of this month'), 'pendente');
        $this->logarComTenant($this->client, $this->dono, $this->escritorio);

        $this->client->request('POST', '/ponto/justificativa/' . $j->getId() . '/excluir', [], [], [
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'HTTP_X_CSRF_TOKEN'     => 'TOKEN_ajax',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true], json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    #[TestDox('Sem JavaScript: o formulário comum exclui, volta para a aba das justificativas no mês do dia e mostra o flash')]
    public function testFormularioComumExcluiComFlash(): void
    {
        $dia = new \DateTimeImmutable('first day of this month');
        $j   = $this->justificativa($this->dono, $this->escritorio, $dia, 'pendente');
        $id  = (int) $j->getId();

        $this->excluirPeloFormulario($this->dono, $this->escritorio, $id);

        self::assertResponseRedirects('/ponto/?competencia=' . $dia->format('Y-m') . '&aba=justificativas');
        self::assertNull($this->statusNoBanco($id), 'a justificativa saiu');
        $tela = $this->client->followRedirect();
        self::assertStringContainsString(sprintf('Justificativa de %s excluída.', $dia->format('d/m/Y')), $tela->text());
    }

    #[TestDox('Sem JavaScript, já analisada: volta para a aba com a frase no flash, e nada sai')]
    public function testFormularioComumRecusaAAnalisada(): void
    {
        $dia = new \DateTimeImmutable('first day of this month');
        $j   = $this->justificativa($this->dono, $this->escritorio, $dia, 'abonado');

        $this->excluirPeloFormulario($this->dono, $this->escritorio, (int) $j->getId(), competencia: $dia->format('Y-m'));

        self::assertResponseRedirects('/ponto/?competencia=' . $dia->format('Y-m') . '&aba=justificativas');
        self::assertSame('abonado', $this->statusNoBanco($j));
        $tela = $this->client->followRedirect();
        self::assertStringContainsString('Esta justificativa não está mais em análise e não pode ser excluída.', $tela->text());
    }

    #[TestDox('Sem JavaScript, de outra pessoa (403), de outro escritório (404) e sem o token do formulário (403): nada sai')]
    public function testFormularioComumRecusaOResto(): void
    {
        $colega = $this->pessoa('colega');
        $this->vincularColaborador($colega, $this->escritorio);
        $outro = $this->escritorio('Outro escritório');
        $this->vincularColaborador($this->dono, $outro);
        $dia       = new \DateTimeImmutable('first day of this month');
        $doDono    = $this->justificativa($this->dono, $this->escritorio, $dia, 'pendente');
        $deOutroEs = $this->justificativa($this->dono, $outro, $dia, 'pendente');

        $this->excluirPeloFormulario($colega, $this->escritorio, (int) $doDono->getId());
        self::assertResponseStatusCodeSame(403);
        $this->excluirPeloFormulario($this->dono, $this->escritorio, (int) $deOutroEs->getId());
        self::assertResponseStatusCodeSame(404);
        $this->excluirPeloFormulario($this->dono, $this->escritorio, (int) $doDono->getId(), token: 'TOKEN_ajax');
        self::assertResponseStatusCodeSame(403, 'o formulário pede o token da exclusão daquela justificativa');
        $this->excluirPeloFormulario($this->dono, $this->escritorio, (int) $doDono->getId(), token: null);
        self::assertResponseStatusCodeSame(403);

        self::assertSame('pendente', $this->statusNoBanco($doDono));
        self::assertSame('pendente', $this->statusNoBanco($deOutroEs));
    }

    #[TestDox('Sem o token da página: 403 e nada sai')]
    public function testSemTokenRecusa(): void
    {
        $j = $this->justificativa($this->dono, $this->escritorio, new \DateTimeImmutable('first day of this month'), 'pendente');

        $this->excluir($this->dono, $this->escritorio, (int) $j->getId(), token: null);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('pendente', $this->statusNoBanco($j));
    }

    #[TestDox('Justificativa de outra pessoa do mesmo escritório: 403 e nada sai')]
    public function testDeOutraPessoaRecusa(): void
    {
        $colega = $this->pessoa('colega');
        $this->vincularColaborador($colega, $this->escritorio);
        $j = $this->justificativa($this->dono, $this->escritorio, new \DateTimeImmutable('first day of this month'), 'pendente');

        $this->excluir($colega, $this->escritorio, (int) $j->getId());

        self::assertResponseStatusCodeSame(403);
        self::assertSame('pendente', $this->statusNoBanco($j));
    }

    #[TestDox('Já analisada (abonada ou recusada): 422 com a frase, e nada sai')]
    public function testAnalisadaRecusaCom422(): void
    {
        $analisadas = [];
        foreach (['abonado', 'rejeitado'] as $status) {
            $analisadas[$status] = (int) $this->justificativa($this->dono, $this->escritorio, new \DateTimeImmutable('first day of this month'), $status)->getId();
        }

        foreach ($analisadas as $status => $id) {
            $this->excluir($this->dono, $this->escritorio, $id);

            self::assertResponseStatusCodeSame(422);
            self::assertSame(
                'Esta justificativa não está mais em análise e não pode ser excluída.',
                json_decode((string) $this->client->getResponse()->getContent(), true)['mensagem'] ?? null,
            );
            self::assertSame($status, $this->statusNoBanco($id));
        }
    }

    #[TestDox('Do próprio dono, mas de outro escritório: 404 pelo escritório da sessão, e nada sai')]
    public function testDeOutroEscritorioNaoExiste(): void
    {
        $outro = $this->escritorio('Outro escritório');
        $this->vincularColaborador($this->dono, $outro);
        $j = $this->justificativa($this->dono, $outro, new \DateTimeImmutable('first day of this month'), 'pendente');

        $this->excluir($this->dono, $this->escritorio, (int) $j->getId());

        self::assertResponseStatusCodeSame(404);
        self::assertSame('pendente', $this->statusNoBanco($j));
    }

    #[TestDox('Só POST: GET na rota de excluir não apaga')]
    public function testSoPost(): void
    {
        $j = $this->justificativa($this->dono, $this->escritorio, new \DateTimeImmutable('first day of this month'), 'pendente');
        $this->logarComTenant($this->client, $this->dono, $this->escritorio);

        $this->client->request('GET', '/ponto/justificativa/' . $j->getId() . '/excluir');

        self::assertResponseStatusCodeSame(405);
        self::assertSame('pendente', $this->statusNoBanco($j));
    }

    /** O pedido da tela, com os cabeçalhos que o js/ponto-abas.js e o base.html.twig mandam. */
    private function excluir(User $quem, Tenant $sessao, int $id, ?string $token = 'TOKEN_ajax'): void
    {
        $this->logarComTenant($this->client, $quem, $sessao);
        $this->em()->clear();
        $cabecalhos = ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']
            + ($token !== null ? ['HTTP_X_CSRF_TOKEN' => $token] : []);

        $this->client->request('POST', '/ponto/justificativa/' . $id . '/excluir', [], [], $cabecalhos);
    }

    /** O formulário comum: sem os cabeçalhos do fetch, com o `_token` da exclusão daquela justificativa. */
    private function excluirPeloFormulario(User $quem, Tenant $sessao, int $id, ?string $token = 'TOKEN_excluir_justificativa_%d', string $competencia = ''): void
    {
        $this->logarComTenant($this->client, $quem, $sessao);
        $this->em()->clear();
        $campos = ['competencia' => $competencia] + ($token !== null ? ['_token' => sprintf($token, $id)] : []);

        $this->client->request('POST', '/ponto/justificativa/' . $id . '/excluir', $campos, [], ['HTTP_ACCEPT' => 'text/html']);
    }
}
