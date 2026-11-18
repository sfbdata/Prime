<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Integration;

use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\PublicacaoDaPastaOutput;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaProcesso;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\PublicacaoDaPastaNaoEncontradaException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\UseCase\LerPublicacaoDaPastaUseCase;
use App\Processo\Entity\Processo;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * As publicações da pasta só para leitura (`docs/specs/mcp-remoto-oauth.md`, 6.4, `ler_pasta` parte `publicacoes`),
 * com o banco: a lista e o texto inteiro sem gravar `lida`, a publicação casada pelo número do processo DESTA pasta, e
 * as recusas (outro escritório, sem permissão, Push oculto, publicação de fora).
 */
#[CoversClass(LerPublicacaoDaPastaUseCase::class)]
#[CoversClass(PublicacaoDaPastaOutput::class)]
final class LerPublicacaoDaPastaUseCaseTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const CNJ = '0701134-57.2025.8.07.0007';
    private const OUTRO_CNJ = '0000001-02.2025.8.07.0001';
    private const LEITOR = ['modules.pastas.view', 'resources.pasta.view'];

    private Tenant $escritorio;
    private User $pessoa;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->escritorio = $this->escritorio('Escritório das Publicações');
        $this->pessoa = $this->pessoa();
        $this->vinculo($this->pessoa, $this->escritorio, permissoes: self::LEITOR);
    }

    #[TestDox('lista: só as publicações dos processos da pasta, a mais recente primeiro; nada gravado (nem lida, nem alteração agendada)')]
    public function testListaSemGravar(): void
    {
        $pasta = $this->pasta(self::CNJ);
        $antiga = $this->publicacao(self::CNJ, 'Antiga', '2026-11-01');
        $recente = $this->publicacao(self::CNJ, '<p>Recente</p>', '2026-11-09');
        $this->publicacao(self::OUTRO_CNJ, 'De outra pasta');

        $lista = $this->useCase()->listar($pasta, $this->pessoa, $this->escritorio, 1, 25);

        self::assertSame([$recente->getId(), $antiga->getId()], array_map(static fn (PublicacaoDaPastaOutput $p): int => $p->id, $lista['itens']));
        self::assertSame('Recente', $lista['itens'][0]->texto);
        self::assertFalse($lista['haMais']);
        $this->assertNadaGravado();
    }

    #[TestDox('lista: a página corta no tamanho pedido e diz se há mais')]
    public function testListaPaginada(): void
    {
        $pasta = $this->pasta(self::CNJ);
        for ($i = 1; $i <= 5; ++$i) {
            $this->publicacao(self::CNJ, 'P' . $i, '2026-11-0' . $i);
        }

        $primeira = $this->useCase()->listar($pasta, $this->pessoa, $this->escritorio, 1, 2);
        $ultima = $this->useCase()->listar($pasta, $this->pessoa, $this->escritorio, 3, 2);

        self::assertCount(2, $primeira['itens']);
        self::assertTrue($primeira['haMais']);
        self::assertCount(1, $ultima['itens']);
        self::assertFalse($ultima['haMais']);
    }

    #[TestDox('texto inteiro: texto puro, sem tags nem entidades, e a publicação continua não lida')]
    public function testTextoInteiro(): void
    {
        $pasta = $this->pasta(self::CNJ);
        $publicacao = $this->publicacao(self::CNJ, '<p>Um &amp; dois.</p><p>Três.</p>');

        $saida = $this->useCase()->executar($pasta, $this->pessoa, $this->escritorio, (int) $publicacao->getId());

        self::assertSame('Um & dois. Três.', $saida->texto);
        self::assertSame('TJDFT', $saida->tribunal);
        self::assertSame('Intimação', $saida->tipo);
        self::assertSame(self::CNJ, $saida->numeroDoProcesso);
        self::assertSame('2026-11-10', $saida->data);
        self::assertFalse($saida->lida);
        $this->assertNadaGravado();
    }

    #[TestDox('texto inteiro: publicação de outra pasta, de outro escritório ou inexistente: não encontrada')]
    public function testPublicacaoDeFora(): void
    {
        $pasta = $this->pasta(self::CNJ);
        $deOutraPasta = $this->publicacao(self::OUTRO_CNJ, 'Outra pasta');
        $deOutroEscritorio = $this->publicacao(self::CNJ, 'Outro escritório', escritorio: $this->escritorio('Outro'));

        foreach ([(int) $deOutraPasta->getId(), (int) $deOutroEscritorio->getId(), 999_999_999] as $id) {
            try {
                $this->useCase()->executar($pasta, $this->pessoa, $this->escritorio, $id);
                self::fail('devia recusar o id ' . $id);
            } catch (PublicacaoDaPastaNaoEncontradaException) {
                self::assertTrue(true);
            }
        }
    }

    #[TestDox('pasta de outro escritório: recusa, na lista e no texto')]
    public function testOutroEscritorio(): void
    {
        $outro = $this->escritorio('Outro');
        $pasta = $this->pasta(self::CNJ, $outro);

        $this->expectException(PastaDeOutroEscritorioException::class);
        $this->useCase()->listar($pasta, $this->pessoa, $this->escritorio, 1, 25);
    }

    #[TestDox('quem não pode ver a pasta: recusa sem permissão')]
    public function testSemPermissao(): void
    {
        $semRecurso = $this->pessoa();
        $this->vinculo($semRecurso, $this->escritorio, permissoes: ['modules.pastas.view']);
        $pasta = $this->pasta(self::CNJ);
        $publicacao = $this->publicacao(self::CNJ, 'Teor');

        $this->expectException(SemPermissaoParaVerPastaException::class);
        $this->useCase()->executar($pasta, $semRecurso, $this->escritorio, (int) $publicacao->getId());
    }

    #[TestDox('aba Push oculta para quem lê: a lista e o texto respondem não encontrada')]
    public function testPushOculto(): void
    {
        $pasta = $this->pasta(self::CNJ);
        $publicacao = $this->publicacao(self::CNJ, 'Teor');
        $colega = $this->pessoa();
        $this->vinculo($colega, $this->escritorio, permissoes: self::LEITOR);
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->escritorio, $pasta, AbaDaPasta::Push, $colega, new \DateTimeImmutable()));
        $this->em()->flush();

        foreach ([
            fn () => $this->useCase()->listar($pasta, $this->pessoa, $this->escritorio, 1, 25),
            fn () => $this->useCase()->executar($pasta, $this->pessoa, $this->escritorio, (int) $publicacao->getId()),
        ] as $leitura) {
            try {
                $leitura();
                self::fail('devia recusar');
            } catch (PublicacaoDaPastaNaoEncontradaException) {
                self::assertTrue(true);
            }
        }
    }

    private function useCase(): LerPublicacaoDaPastaUseCase
    {
        return static::getContainer()->get(LerPublicacaoDaPastaUseCase::class);
    }

    /** Nada agendado no ORM e nenhuma publicação lida no banco. */
    private function assertNadaGravado(): void
    {
        $unidade = $this->em()->getUnitOfWork();
        $unidade->computeChangeSets();
        self::assertSame([], $unidade->getScheduledEntityInsertions());
        self::assertSame([], $unidade->getScheduledEntityUpdates());
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM publicacao_djen WHERE lida = true'));
    }

    private function pasta(string $cnj, ?Tenant $escritorio = null): Pasta
    {
        $escritorio ??= $this->escritorio;
        $pasta = (new Pasta())->setNup('PUB-' . uniqid());
        $pasta->setTenant($escritorio);
        $this->em()->persist($pasta);
        $processo = new Processo();
        $processo->setNumeroProcesso($cnj);
        $processo->setSiglaTribunal('TJDFT');
        $processo->setClasseProcessual('Procedimento Comum');
        $processo->setAssuntoProcessual('Assunto');
        $processo->setTenant($escritorio);
        $this->em()->persist($processo);
        $vinculo = new PastaProcesso($pasta, $processo);
        $this->em()->persist($vinculo);
        $pasta->getPastaProcessos()->add($vinculo);
        $this->em()->flush();

        return $pasta;
    }

    private function publicacao(string $cnj, string $texto, string $data = '2026-11-10', ?Tenant $escritorio = null): PublicacaoDjen
    {
        $publicacao = (new PublicacaoDjen())
            ->setTenant($escritorio ?? $this->escritorio)
            ->setDjenId((string) random_int(100_000_000, 999_999_999_999))
            ->setNumeroProcesso((string) preg_replace('/\D/', '', $cnj))
            ->setNumeroProcessoComMascara($cnj)
            ->setSiglaTribunal('TJDFT')
            ->setTipoComunicacao('Intimação')
            ->setDataDisponibilizacao(new \DateTimeImmutable($data))
            ->setTexto($texto);
        $this->em()->persist($publicacao);
        $this->em()->flush();

        return $publicacao;
    }
}
