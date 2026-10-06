<?php

declare(strict_types=1);

namespace App\Tests\Processo\Unit;

use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Entity\Processo;
use App\Processo\Exception\NotaTecnicaForaDoEscopoException;
use App\Processo\Repository\NotaTecnicaRepository;
use App\Processo\UseCase\CriarNotaTecnicaUseCase;
use App\Tests\Shared\CriaSanitizadorTextoRico;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CriarNotaTecnicaUseCase::class)]
final class CriarNotaTecnicaUseCaseTest extends TestCase
{
    use CriaSanitizadorTextoRico;

    private const NUMERO = '07011345720258070007';

    private NotaTecnicaRepository&MockObject $repository;
    private CriarNotaTecnicaUseCase $useCase;
    private Tenant $tenant;
    private User $autor;
    private Processo $processo;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(NotaTecnicaRepository::class);
        $this->useCase    = new CriarNotaTecnicaUseCase($this->repository, $this->criarSanitizadorTextoRico());
        $this->tenant     = new Tenant();
        $this->autor      = (new User())->setEmail('autor@test.com')->setFullName('Ana Advogada');
        $this->processo   = (new Processo())->setTenant($this->tenant)->setNumeroProcesso(self::NUMERO);
    }

    private function publicacao(Tenant $tenant, string $numero, ?Processo $processo = null): PublicacaoDjen
    {
        $pub = (new PublicacaoDjen())->setTenant($tenant)->setNumeroProcesso($numero)->setTipoComunicacao('Intimação');
        if ($processo !== null) {
            $pub->setProcesso($processo);
        }

        return $pub;
    }

    #[TestDox('cria a nota no processo, limpa, e devolve o DTO')]
    public function testCriaNotaNoProcesso(): void
    {
        $this->repository->expects($this->once())->method('salvar')
            ->with(self::callback(static fn (NotaTecnica $n): bool => $n->getConteudo() === '<p><strong>Prazo:</strong> 15 dias</p>'
                && $n->getPublicacaoDjen() === null), true);

        $saida = $this->useCase->executar($this->processo, $this->autor, $this->tenant, '  <p><strong>Prazo:</strong> 15 dias</p>  ');

        self::assertSame('<p><strong>Prazo:</strong> 15 dias</p>', $saida->conteudo);
        self::assertSame('Ana Advogada', $saida->autorNome);
        self::assertNull($saida->publicacaoId);
        self::assertNull($saida->movimentacaoRotulo);
        self::assertNull($saida->editadaEm);
    }

    #[TestDox('script e atributo de evento não chegam ao banco')]
    public function testSanitizaAntesDePersistir(): void
    {
        $this->repository->expects($this->once())->method('salvar')
            ->with(self::callback(static function (NotaTecnica $n): bool {
                $c = $n->getConteudo();

                return !str_contains(strtolower($c), '<script') && !str_contains(strtolower($c), 'onclick') && str_contains($c, 'Combinado');
            }), true);

        $this->useCase->executar($this->processo, $this->autor, $this->tenant, '<p onclick="roubar()">Combinado</p><script>alert(1)</script>');
    }

    #[TestDox('conteúdo vazio é recusado')]
    public function testConteudoVazioLancaInvalidArgument(): void
    {
        $this->repository->expects($this->never())->method('salvar');
        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->processo, $this->autor, $this->tenant, '   ');
    }

    #[TestDox('o parágrafo vazio do editor (<p><br></p>) conta como vazio')]
    public function testParagrafoVazioDoEditorLancaInvalidArgument(): void
    {
        $this->repository->expects($this->never())->method('salvar');
        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->processo, $this->autor, $this->tenant, '<p><br></p>');
    }

    #[TestDox('mais de 5000 caracteres visíveis é recusado; a marcação não conta')]
    public function testLimiteDe5000ContaSoOTextoVisivel(): void
    {
        $this->repository->expects($this->once())->method('salvar');

        // 5000 visíveis + marcação em volta: passa.
        $this->useCase->executar($this->processo, $this->autor, $this->tenant, '<p><strong>' . str_repeat('a', 5000) . '</strong></p>');

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar($this->processo, $this->autor, $this->tenant, str_repeat('a', 5001));
    }

    #[TestDox('processo de outro escritório é recusado')]
    public function testProcessoDeOutroTenantLancaForaDoEscopo(): void
    {
        $this->repository->expects($this->never())->method('salvar');
        $this->expectException(NotaTecnicaForaDoEscopoException::class);

        $this->useCase->executar($this->processo, $this->autor, new Tenant(), '<p>Nota</p>');
    }

    #[TestDox('publicação vinculada pela FK ao processo: a nota nasce pendurada nela')]
    public function testPublicacaoComFkDoProcesso(): void
    {
        $pub = $this->publicacao($this->tenant, self::NUMERO, $this->processo);
        $pub->setDataDisponibilizacao(new \DateTimeImmutable('2026-08-20'));
        $this->repository->expects($this->once())->method('salvar')
            ->with(self::callback(static fn (NotaTecnica $n): bool => $n->getPublicacaoDjen() === $pub), true);

        $saida = $this->useCase->executar($this->processo, $this->autor, $this->tenant, '<p>Nota</p>', $pub);

        self::assertSame('Intimação · 20/08/2026', $saida->movimentacaoRotulo);
    }

    #[TestDox('publicação SEM FK mas com o mesmo número CNJ também vale (a FK só nasce na sincronização)')]
    public function testPublicacaoCasadaPeloNumero(): void
    {
        $pub = $this->publicacao($this->tenant, self::NUMERO);
        $this->repository->expects($this->once())->method('salvar')
            ->with(self::callback(static fn (NotaTecnica $n): bool => $n->getPublicacaoDjen() === $pub), true);

        $this->useCase->executar($this->processo, $this->autor, $this->tenant, '<p>Nota</p>', $pub);
    }

    #[TestDox('publicação de OUTRO processo é recusada')]
    public function testPublicacaoDeOutroProcessoLancaForaDoEscopo(): void
    {
        $pub = $this->publicacao($this->tenant, '07099999999999999999');
        $this->repository->expects($this->never())->method('salvar');
        $this->expectException(NotaTecnicaForaDoEscopoException::class);

        $this->useCase->executar($this->processo, $this->autor, $this->tenant, '<p>Nota</p>', $pub);
    }

    #[TestDox('publicação de outro escritório é recusada mesmo com o mesmo número')]
    public function testPublicacaoDeOutroTenantLancaForaDoEscopo(): void
    {
        $pub = $this->publicacao(new Tenant(), self::NUMERO);
        $this->repository->expects($this->never())->method('salvar');
        $this->expectException(NotaTecnicaForaDoEscopoException::class);

        $this->useCase->executar($this->processo, $this->autor, $this->tenant, '<p>Nota</p>', $pub);
    }
}
