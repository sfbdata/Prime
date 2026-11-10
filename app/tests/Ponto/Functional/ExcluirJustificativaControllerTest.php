<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\PontoController;
use App\Ponto\UseCase\AnalisarJustificativaUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Rota de excluir a própria justificativa em análise (P41, desenho do Ponto E3): POST com o token da página, JSON sem
 * flash (a tela tira a linha sem recarregar). 403 para quem não pode (outra pessoa, sem token), 422 para a já
 * analisada, 404 para a de outro escritório.
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

    #[TestDox('O dono exclui a que está em análise: 200 com JSON, some do banco e nenhum flash fica para depois')]
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

    #[TestDox('E10: em análise, mas já mexeu na folha (abonada e devolvida para análise): 422 com a frase, e nada sai')]
    public function testQueJaMexeuNaFolhaRecusaCom422(): void
    {
        $gestor = $this->pessoa('gestor');
        $this->vincularGestor($gestor, $this->escritorio);
        $dia = new \DateTimeImmutable('-3 days');
        $j   = $this->justificativa($this->dono, $this->escritorio, $dia, 'pendente', ['tipo' => 'esquecimento_registro', 'tipoRegistroEsquecido' => 'entrada', 'horaRegistroEsquecido' => '08:00']);
        $analisar = static::getContainer()->get(AnalisarJustificativaUseCase::class);
        $analisar->abonar($j, $this->escritorio, $gestor, '');
        $analisar->reverter($j, $this->escritorio, $gestor);

        $this->excluir($this->dono, $this->escritorio, (int) $j->getId());

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            'Esta justificativa já foi abonada uma vez e mexeu na folha do ponto, por isso não pode ser excluída. Ela continua em análise com o gestor.',
            json_decode((string) $this->client->getResponse()->getContent(), true)['mensagem'] ?? null,
        );
        self::assertSame('pendente', $this->statusNoBanco((int) $j->getId()));
    }

    #[TestDox('E10, tela: na aba "Minhas Justificativas", a em análise sem ajuste mostra o "Excluir"; a que já mexeu na folha (abonada e devolvida para análise) não mostra, e o lápis fica')]
    public function testExcluirSomeDaQueJaMexeuNaFolha(): void
    {
        $gestor = $this->pessoa('gestor');
        $this->vincularGestor($gestor, $this->escritorio);
        $dia       = new \DateTimeImmutable('first day of this month');
        $semAjuste = $this->justificativa($this->dono, $this->escritorio, $dia, 'pendente');
        $comAjuste = $this->justificativa($this->dono, $this->escritorio, $dia, 'pendente', ['tipo' => 'esquecimento_registro', 'tipoRegistroEsquecido' => 'entrada', 'horaRegistroEsquecido' => '00:00']);
        $analisar  = static::getContainer()->get(AnalisarJustificativaUseCase::class);
        $analisar->abonar($comAjuste, $this->escritorio, $gestor, '');
        $analisar->reverter($comAjuste, $this->escritorio, $gestor);
        self::assertSame('pendente', $this->statusNoBanco($comAjuste));

        $this->logarComTenant($this->client, $this->dono, $this->escritorio);
        $this->em()->clear();
        $tela = $this->client->request('GET', '/ponto/?aba=justificativas');

        self::assertResponseIsSuccessful();
        $linha = static fn (int $id): string => sprintf('tr.pe-just-lin[data-justificativa="%d"]', $id);
        self::assertCount(1, $tela->filter($linha((int) $semAjuste->getId()) . ' [data-excluir-url]'), 'a sem ajuste pode ser excluída');
        self::assertCount(1, $tela->filter($linha((int) $comAjuste->getId())), 'a que já mexeu na folha continua na lista');
        self::assertCount(1, $tela->filter($linha((int) $comAjuste->getId()) . ' [data-justificativa-editar]'), 'o lápis continua');
        self::assertCount(0, $tela->filter($linha((int) $comAjuste->getId()) . ' [data-excluir-url]'), 'sem o "Excluir": o servidor recusaria');
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
}
