<?php

declare(strict_types=1);

namespace App\Tests\Central\Unit;

use App\Central\Enum\TipoDeItemEncaminhado;
use App\Central\Exception\ItemEncaminhadoNaoEncontradoException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\ParticipanteRepository;
use App\Central\UseCase\ObterItemDoEncaminhamentoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Repository\PastaMensagemRepository;
use App\Tests\Central\Support\MontaEncaminhamentoTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** O cartão da tela de despacho (BC:6224): as guardas do envio e o mesmo leitor de itens; nada é gravado. */
#[CoversClass(ObterItemDoEncaminhamentoUseCase::class)]
final class ObterItemDoEncaminhamentoUseCaseTest extends TestCase
{
    use MontaEncaminhamentoTrait;

    private Tenant $tenant;
    private Pasta $pasta;
    private User $eu;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->pasta = $this->pastaDe($this->tenant);
        $this->eu = $this->pessoa(1, 'Ana Lima');
    }

    #[TestDox('Tipo pasta: o título é "Pasta NUP", sem resumo, e o id do item é ignorado')]
    public function testPasta(): void
    {
        $item = $this->useCase()->executar($this->eu, $this->tenant, 50, TipoDeItemEncaminhado::Pasta, 999);

        self::assertSame('Pasta PASTA-50', $item->titulo);
        self::assertSame('', $item->resumo);
    }

    #[TestDox('Registro de Dados: o leitor recebe o id, a pasta e o escritório, e o cartão sai com menção desarmada')]
    public function testRegistro(): void
    {
        $registro = (new PastaMensagem())->setTenant($this->tenant)->setPasta($this->pasta)
            ->setAutor($this->pessoa(9, 'Carla Souza'))
            ->setConteudo('<p>Ligar para @[Zé](user:3)</p>');
        $mensagens = $this->createMock(PastaMensagemRepository::class);
        $mensagens->expects(self::once())->method('findByIdAndPastaAndTenant')->with(31, $this->pasta, $this->tenant)->willReturn($registro);

        $item = $this->useCase(mensagens: $mensagens)->executar($this->eu, $this->tenant, 50, TipoDeItemEncaminhado::RegistroDados, 31);

        self::assertStringStartsWith('Registro de Carla Souza · ', $item->titulo);
        self::assertSame('Ligar para @Zé', $item->resumo);
    }

    #[TestDox('Sem vínculo ativo no escritório: SemAcessoACentral (403), antes de olhar a pasta')]
    public function testSemVinculo(): void
    {
        $this->expectException(SemAcessoACentralException::class);
        $this->useCase(ativos: [])->executar($this->eu, $this->tenant, 50, TipoDeItemEncaminhado::Pasta, null);
    }

    #[TestDox('Sem escritório na sessão: SemAcessoACentral (403)')]
    public function testSemTenant(): void
    {
        $this->expectException(SemAcessoACentralException::class);
        $this->useCase()->executar($this->eu, null, 50, TipoDeItemEncaminhado::Pasta, null);
    }

    #[TestDox('Pasta que eu não vejo: 404, como no envio')]
    public function testPastaQueNaoVejo(): void
    {
        $this->expectException(ItemEncaminhadoNaoEncontradoException::class);
        $this->useCase(quemVe: [2])->executar($this->eu, $this->tenant, 50, TipoDeItemEncaminhado::Pasta, null);
    }

    #[TestDox('Pasta de outro escritório (a consulta com o tenant não acha): 404')]
    public function testPastaDeOutroEscritorio(): void
    {
        $this->expectException(ItemEncaminhadoNaoEncontradoException::class);
        $this->useCase(pastaAchada: false)->executar($this->eu, $this->tenant, 50, TipoDeItemEncaminhado::Pasta, null);
    }

    #[TestDox('Pasta excluída (lápide): 404')]
    public function testPastaExcluida(): void
    {
        $this->pasta->marcarExcluida($this->eu, new \DateTimeImmutable());

        $this->expectException(ItemEncaminhadoNaoEncontradoException::class);
        $this->useCase()->executar($this->eu, $this->tenant, 50, TipoDeItemEncaminhado::Pasta, null);
    }

    #[TestDox('Item que não é desta pasta: 404; item sem id fora do tipo pasta: 404')]
    public function testItemDeFora(): void
    {
        $mensagens = $this->createStub(PastaMensagemRepository::class);
        $mensagens->method('findByIdAndPastaAndTenant')->willReturn(null);
        $useCase = $this->useCase(mensagens: $mensagens);

        try {
            $useCase->executar($this->eu, $this->tenant, 50, TipoDeItemEncaminhado::RegistroDados, 31);
            self::fail('registro de fora devia dar 404');
        } catch (ItemEncaminhadoNaoEncontradoException) {
        }

        $this->expectException(ItemEncaminhadoNaoEncontradoException::class);
        $useCase->executar($this->eu, $this->tenant, 50, TipoDeItemEncaminhado::Meta, null);
    }

    /**
     * @param list<User>|null $ativos
     * @param list<int>       $quemVe
     */
    private function useCase(?array $ativos = null, array $quemVe = [1], bool $pastaAchada = true, ?PastaMensagemRepository $mensagens = null): ObterItemDoEncaminhamentoUseCase
    {
        $participantes = $this->createStub(ParticipanteRepository::class);

        return new ObterItemDoEncaminhamentoUseCase(
            $this->acessoACentral($ativos ?? [$this->eu], $participantes),
            $this->guarda($pastaAchada ? $this->pasta : null, $this->acessoAPasta($quemVe), $participantes),
            $this->itens($mensagens),
        );
    }
}
