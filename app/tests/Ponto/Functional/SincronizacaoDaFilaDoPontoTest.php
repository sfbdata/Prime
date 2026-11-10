<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\FilaDoPontoController;
use App\Ponto\DTO\SincronizacaoDaFilaInput;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Service\DetalheDaTrilhaDoPonto;
use App\Ponto\UseCase\RegistrarSincronizacaoDaFilaUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * O evento "Sincronização" da trilha do Ponto (spec ponto-desenho-0811.md, E14 e E9; dc L438): a tela avisa os
 * `idEnvio` que a rodada da fila mandou, e o servidor grava um evento com as batidas da pessoa, neste escritório, que
 * vieram da fila, sem contar duas vezes a mesma.
 */
#[CoversClass(FilaDoPontoController::class)]
#[CoversClass(RegistrarSincronizacaoDaFilaUseCase::class)]
#[CoversClass(SincronizacaoDaFilaInput::class)]
#[Group('ponto')]
final class SincronizacaoDaFilaDoPontoTest extends JusPrimeWebTestCase
{
    use CenarioDeJustificativaTrait;

    private const A = '0b6f3a52-1d7e-4c89-a2f4-5e6d7c8b9a01';
    private const B = '1c7a4b63-2e8f-4d9a-b3a5-6f7e8d9cab02';
    private const C = '2d8b5c74-3f9a-4eab-84b6-7a8f9eadbc03';

    private KernelBrowser $client;
    private Tenant $escritorio;
    private User $colaborador;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfPrevisivel();
        $this->escritorio = $this->escritorio('Escritório Fila');
        $this->colaborador = $this->pessoa('fila');
        $this->vincularColaborador($this->colaborador, $this->escritorio);
    }

    #[TestDox('Duas batidas da fila: um evento "Sincronização" com a pessoa como autora e o texto "2 batidas enviadas ao voltar a internet"')]
    public function testRodadaGravaUmEvento(): void
    {
        $a = $this->batida($this->colaborador, $this->escritorio, self::A, true, '-3 hours');
        $b = $this->batida($this->colaborador, $this->escritorio, self::B, true, '-2 hours');

        self::assertSame(2, $this->avisar($this->colaborador, [self::A, self::B, self::A]));

        $eventos = $this->eventos();
        self::assertCount(1, $eventos);
        self::assertSame(['sincronizacao', (int) $this->colaborador->getId()], [$eventos[0]['acao'], $eventos[0]['autor_id']]);
        self::assertSame([$a, $b], $eventos[0]['detalhe']['registro_ids']);
        self::assertSame('Pessoa fila: 2 batidas enviadas ao voltar a internet', DetalheDaTrilhaDoPonto::texto('sincronizacao', $eventos[0]['detalhe']));
    }

    #[TestDox('O mesmo aviso repetido não grava de novo; uma batida nova da fila entra sozinha no evento seguinte')]
    public function testRepetidoNaoContaDuasVezes(): void
    {
        $this->batida($this->colaborador, $this->escritorio, self::A, true, '-3 hours');
        self::assertSame(1, $this->avisar($this->colaborador, [self::A]));
        self::assertSame(0, $this->avisar($this->colaborador, [self::A]));

        $c = $this->batida($this->colaborador, $this->escritorio, self::C, true, '-1 hour');
        self::assertSame(1, $this->avisar($this->colaborador, [self::A, self::C]));

        $eventos = $this->eventos();
        self::assertCount(2, $eventos);
        self::assertSame([$c], $eventos[1]['detalhe']['registro_ids']);
        self::assertSame('Pessoa fila: 1 batida enviada ao voltar a internet', DetalheDaTrilhaDoPonto::texto('sincronizacao', $eventos[1]['detalhe']));
    }

    #[TestDox('Só contam as batidas dela, neste escritório, que vieram da fila: a comum, a de outra pessoa, a de outro escritório e a que não existe não gravam nada')]
    public function testSoAsBatidasDelaDaFila(): void
    {
        $outraPessoa = $this->pessoa('outra');
        $this->vincularColaborador($outraPessoa, $this->escritorio);
        $outroEscritorio = $this->escritorio('Outro Escritório');
        $this->vincularColaborador($this->colaborador, $outroEscritorio);
        $this->batida($this->colaborador, $this->escritorio, self::A, false, '-3 hours');
        $this->batida($outraPessoa, $this->escritorio, self::B, true, '-3 hours');
        $this->batida($this->colaborador, $outroEscritorio, self::C, true, '-3 hours');

        self::assertSame(0, $this->avisar($this->colaborador, [self::A, self::B, self::C, '3e9c6d85-4a0b-4fbc-95c7-8b9a0fbecd04']));
        self::assertSame([], $this->eventos());
    }

    #[TestDox('Recusas: pedido sem lista, lista vazia ou com algo que não é identificador (422); CSRF inválido (403); sem acesso ao Ponto (403); nada grava')]
    public function testRecusas(): void
    {
        $semAcesso = $this->pessoa('sem-acesso');
        $this->vincular($semAcesso, $this->escritorio, [], false);
        $this->batida($this->colaborador, $this->escritorio, self::A, true, '-3 hours');
        foreach ([[], ['idsEnvio' => []], ['idsEnvio' => [12]], ['idsEnvio' => [str_repeat('a', 37)]], ['idsEnvio' => ['x' => self::A]]] as $corpo) {
            $this->pedir($this->colaborador, $corpo);
            self::assertResponseStatusCodeSame(422);
        }

        $this->pedir($this->colaborador, ['idsEnvio' => [self::A]], 'TOKEN_errado');
        self::assertResponseStatusCodeSame(403);

        $this->pedir($semAcesso, ['idsEnvio' => [self::A]]);
        self::assertResponseStatusCodeSame(403);

        self::assertSame([], $this->eventos());
    }

    /** @param list<mixed> $ids */
    private function avisar(User $quem, array $ids): int
    {
        $this->pedir($quem, ['idsEnvio' => $ids]);
        self::assertResponseIsSuccessful();
        $corpo = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return (int) $corpo['registradas'];
    }

    /** @param array<mixed> $corpo */
    private function pedir(User $quem, array $corpo, string $token = 'TOKEN_ajax'): void
    {
        $this->logarComTenant($this->client, $quem, $this->escritorio);
        $this->client->request('POST', '/ponto/fila/sincronizada', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], (string) json_encode($corpo));
    }

    private function batida(User $quem, Tenant $onde, string $idEnvio, bool $semInternet, string $quando): int
    {
        $registro = new RegistroPonto();
        // Referências: o pedido anterior pode ter limpado a unidade de trabalho do teste.
        $registro->setUser($this->em()->getReference(User::class, $quem->getId()));
        $registro->setTenant($this->em()->getReference(Tenant::class, $onde->getId()));
        $registro->setTipo('entrada');
        $registro->setDataHora(new \DateTime($quando));
        $registro->setIdEnvio($idEnvio);
        $registro->setEnviadaSemInternet($semInternet);
        $this->em()->persist($registro);
        $this->em()->flush();

        return (int) $registro->getId();
    }

    /** @return list<array{acao: string, autor_id: ?int, detalhe: array<string, mixed>}> */
    private function eventos(): array
    {
        $linhas = $this->emSemFiltro()->getConnection()->fetchAllAssociative(
            'SELECT acao, autor_id, detalhe FROM ponto_auditoria WHERE tenant_id = ? ORDER BY sequencia',
            [$this->escritorio->getId()],
        );

        return array_map(static fn (array $l): array => [
            'acao'     => (string) $l['acao'],
            'autor_id' => $l['autor_id'] === null ? null : (int) $l['autor_id'],
            'detalhe'  => json_decode((string) $l['detalhe'], true),
        ], $linhas);
    }
}
