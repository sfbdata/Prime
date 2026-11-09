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
 * Rota de excluir a própria justificativa em análise (P41, desenho do Ponto E3): POST com o token da página.
 * 403 para quem não pode (outra pessoa, sem token), 422 para a já analisada, 404 para a de outro escritório.
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

    #[TestDox('O dono exclui a que está em análise: 200, some do banco e a aba abre com a mensagem')]
    public function testDonoExcluiAEmAnalise(): void
    {
        $j  = $this->justificativa($this->dono, $this->escritorio, new \DateTimeImmutable('first day of this month'), 'pendente');
        $id = (int) $j->getId();

        $this->excluir($this->dono, $this->escritorio, $id);

        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertNull($this->statusNoBanco($id), 'a justificativa saiu');

        $tela = $this->client->request('GET', '/ponto/?aba=justificativas');
        self::assertStringContainsString(
            sprintf('Justificativa de %s excluída.', (new \DateTimeImmutable('first day of this month'))->format('d/m/Y')),
            $tela->text(),
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

    private function excluir(User $quem, Tenant $sessao, int $id, ?string $token = 'TOKEN_ajax'): void
    {
        $this->logarComTenant($this->client, $quem, $sessao);
        $this->em()->clear();
        $cabecalhos = ['HTTP_ACCEPT' => 'application/json'] + ($token !== null ? ['HTTP_X_CSRF_TOKEN' => $token] : []);

        $this->client->request('POST', '/ponto/justificativa/' . $id . '/excluir', [], [], $cabecalhos);
    }
}
