<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\EspelhoRemoto;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\EspelhoRemoto\LayoutDeChaveRemota;
use App\Shared\EspelhoRemoto\Liberacao\RegistroDeLiberacao;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * O registro de liberação do disco (spec `leitura-pelo-r2-e-liberacao-de-disco.md` §4) contra o
 * PostgreSQL de verdade: o índice único parcial só existe no banco, um dublê não o provaria.
 */
#[CoversClass(RegistroDeLiberacao::class)]
final class RegistroDeLiberacaoTest extends KernelTestCase
{
    private Connection $conexao;
    private MockClock $relogio;
    private RegistroDeLiberacao $registro;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conexao  = static::getContainer()->get(Connection::class);
        $this->relogio  = new MockClock('2026-11-03 12:00:00', 'UTC');
        $this->registro = new RegistroDeLiberacao($this->conexao, $this->relogio);
    }

    #[TestDox('registrar abre a linha: abertaDe devolve lote, SHA, tamanho e quando saiu do disco; a linha guarda a chave inteira')]
    public function testRegistrarEConsultar(): void
    {
        $chave = $this->chave(7, 'contrato.pdf');
        $id    = $this->registro->registrar('lote-1', $chave, hash('sha256', 'x'), 1);

        $aberta = $this->registro->abertaDe(LayoutDeChaveRemota::chaveDe($chave));

        self::assertNotNull($aberta);
        self::assertSame($id, $aberta->id);
        self::assertSame('lote-1', $aberta->lote);
        self::assertSame(hash('sha256', 'x'), $aberta->sha256);
        self::assertSame(1, $aberta->tamanhoBytes);
        self::assertSame('2026-11-03 12:00:00', $aberta->liberadoEm->format('Y-m-d H:i:s'));
        self::assertSame(
            ['categoria' => 'pasta_documento', 'escopo_tenant_id' => 7, 'nome' => 'contrato.pdf', 'restaurado_em' => null],
            $this->conexao->fetchAssociative('SELECT categoria, escopo_tenant_id, nome, restaurado_em FROM armazenamento_liberacao WHERE id = ?', [$id]),
        );
    }

    #[TestDox('fechar grava restaurado_em: a chave deixa de estar liberada, e pode ser liberada de novo')]
    public function testFechar(): void
    {
        $chave       = $this->chave(7, 'volta.pdf');
        $chaveRemota = LayoutDeChaveRemota::chaveDe($chave);
        $this->registro->registrar('lote-1', $chave, hash('sha256', 'x'), 1);

        $this->relogio->modify('+1 hour');
        self::assertSame(1, $this->registro->fechar($chaveRemota));
        self::assertNull($this->registro->abertaDe($chaveRemota));
        self::assertSame('2026-11-03 13:00:00', $this->conexao->fetchOne('SELECT restaurado_em FROM armazenamento_liberacao WHERE chave_remota = ?', [$chaveRemota]));
        self::assertSame(0, $this->registro->fechar($chaveRemota), 'fechar de novo não muda nada');

        $novo = $this->registro->registrar('lote-2', $chave, hash('sha256', 'y'), 1);
        self::assertSame($novo, $this->registro->abertaDe($chaveRemota)?->id);
    }

    #[TestDox('o índice único parcial impede duas linhas ABERTAS da mesma chave')]
    public function testDuasAbertasDaMesmaChave(): void
    {
        $chave = $this->chave(7, 'duas.pdf');
        $this->registro->registrar('lote-1', $chave, hash('sha256', 'x'), 1);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->registro->registrar('lote-2', $chave, hash('sha256', 'x'), 1);
    }

    #[TestDox('a linha é da chave do escritório: a mesma chave em outro escritório não está liberada')]
    public function testOutroEscritorio(): void
    {
        $this->registro->registrar('lote-1', $this->chave(7, 'mesmo.pdf'), hash('sha256', 'x'), 1);

        self::assertNull($this->registro->abertaDe(LayoutDeChaveRemota::chaveDe($this->chave(8, 'mesmo.pdf'))));
    }

    #[TestDox('fecharPrefixo fecha só as linhas abertas do escritório na categoria')]
    public function testFecharPrefixo(): void
    {
        $alvo  = $this->chave(7, 'a.pdf');
        $outro = $this->chave(8, 'a.pdf');
        foreach ([$alvo, $outro] as $chave) {
            $this->registro->registrar('lote-1', $chave, hash('sha256', 'x'), 1);
        }

        self::assertSame(1, $this->registro->fecharPrefixo(EscopoDeArquivo::deTenant(7), CategoriaDeArquivo::PASTA_DOCUMENTO));
        self::assertNull($this->registro->abertaDe(LayoutDeChaveRemota::chaveDe($alvo)));
        self::assertNotNull($this->registro->abertaDe(LayoutDeChaveRemota::chaveDe($outro)));
        self::assertSame(0, $this->registro->fecharPrefixo(EscopoDeArquivo::deTenant(7), CategoriaDeArquivo::COBRANCA_DOCUMENTO));
    }

    #[TestDox('recusa SHA fora do formato, tamanho negativo e lote vazio, sem gravar nada')]
    public function testRecusaDadoInvalido(): void
    {
        $chave = $this->chave(7, 'invalido.pdf');
        foreach ([
            ['lote-1', 'ABC', 1],
            ['lote-1', strtoupper(hash('sha256', 'x')), 1],
            ['lote-1', hash('sha256', 'x'), -1],
            ['  ', hash('sha256', 'x'), 1],
        ] as $i => [$lote, $sha, $tamanho]) {
            try {
                $this->registro->registrar($lote, $chave, $sha, $tamanho);
                self::fail('caso ' . $i . ' devia ser recusado');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertNull($this->registro->abertaDe(LayoutDeChaveRemota::chaveDe($chave)));
    }

    private function chave(int $tenant, string $nome): ChaveDeArquivo
    {
        return new ChaveDeArquivo(EscopoDeArquivo::deTenant($tenant), CategoriaDeArquivo::PASTA_DOCUMENTO, $nome);
    }
}
