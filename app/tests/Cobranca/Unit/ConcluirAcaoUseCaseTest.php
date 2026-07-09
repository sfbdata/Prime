<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\ConcluirAcaoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ProximaAcao;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\StatusProximaAcao;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\ProximaAcaoNaoEncontradaException;
use App\Cobranca\Repository\ProximaAcaoRepository;
use App\Cobranca\UseCase\ConcluirAcaoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConcluirAcaoUseCase::class)]
final class ConcluirAcaoUseCaseTest extends TestCase
{
    private ProximaAcaoRepository&MockObject $proximaAcaoRepository;
    private ConcluirAcaoUseCase $sut;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->proximaAcaoRepository = $this->createMock(ProximaAcaoRepository::class);
        $this->sut = new ConcluirAcaoUseCase($this->proximaAcaoRepository);
        $this->tenant = new Tenant();
        $this->usuario = new User();
    }

    #[Test]
    public function concluiAcaoPendenteSemDefinirProxima(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $acao = (new ProximaAcao())->setTenant($this->tenant)->setCaso($caso)->setDescricao('Contatar devedor');

        $this->proximaAcaoRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(50, $this->tenant)
            ->willReturn($acao);

        // Sem próxima ação: a conclusão é persistida com flush numa única chamada.
        $this->proximaAcaoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::identicalTo($acao), true);

        $input = new ConcluirAcaoInput();
        $input->acaoId = 50;
        $input->resultado = 'Devedor prometeu pagar até sexta';

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame($acao, $resultado);
        self::assertSame(StatusProximaAcao::Concluida, $resultado->getStatus());
        self::assertSame('Devedor prometeu pagar até sexta', $resultado->getResultado());
        self::assertNotNull($resultado->getConcluidaEm());
    }

    #[Test]
    public function concluiEDefineProximaAcao(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $acao = (new ProximaAcao())->setTenant($this->tenant)->setCaso($caso)->setDescricao('Enviar boleto');

        $this->proximaAcaoRepository->method('findOneByIdDoTenant')->willReturn($acao);

        // Duas persistências: (1) conclusão da atual sem flush; (2) nova ação pendente com flush.
        $salvas = [];
        $this->proximaAcaoRepository
            ->expects($this->exactly(2))
            ->method('salvar')
            ->willReturnCallback(function (ProximaAcao $acaoSalva) use (&$salvas): void {
                $salvas[] = $acaoSalva;
            });

        $prazoProxima = new \DateTimeImmutable('2026-09-10');
        $input = new ConcluirAcaoInput();
        $input->acaoId = 50;
        $input->resultado = 'Boleto enviado por e-mail';
        $input->proximaDescricao = 'Verificar pagamento do boleto';
        $input->proximoPrazo = $prazoProxima;

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame($acao, $resultado);
        self::assertSame(StatusProximaAcao::Concluida, $resultado->getStatus());

        // A primeira salva é a conclusão; a segunda é a NOVA ação pendente.
        self::assertCount(2, $salvas);
        self::assertSame($acao, $salvas[0]);

        $proxima = $salvas[1];
        self::assertNotSame($acao, $proxima);
        self::assertSame(StatusProximaAcao::Pendente, $proxima->getStatus());
        self::assertSame('Verificar pagamento do boleto', $proxima->getDescricao());
        self::assertSame($prazoProxima, $proxima->getPrazo());
        self::assertSame($caso, $proxima->getCaso());
        self::assertSame($this->tenant, $proxima->getTenant());
        self::assertSame($this->usuario, $proxima->getResponsavel());
        self::assertSame($this->usuario, $proxima->getCriadoPor());
    }

    #[Test]
    public function concluiSemCriarProximaEmCasoEncerrado(): void
    {
        // Caso encerrado com ação ainda pendente: concluir é permitido (fecha o trabalho), mas
        // definir uma NOVA ação num caso encerrado é rejeitado (§17) — coerente com DefinirProximaAcao.
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $caso->setStatus(StatusCaso::Encerrado);
        $acao = (new ProximaAcao())->setTenant($this->tenant)->setCaso($caso)->setDescricao('Pendente antiga');

        $this->proximaAcaoRepository->method('findOneByIdDoTenant')->willReturn($acao);
        // Nada é persistido: a exceção é lançada antes de concluir/salvar.
        $this->proximaAcaoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoEncerradoException::class);

        $input = new ConcluirAcaoInput();
        $input->acaoId = 50;
        $input->resultado = 'Fechando';
        $input->proximaDescricao = 'Tentar criar ação nova';

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }

    #[Test]
    public function rejeitaAcaoNaoEncontrada(): void
    {
        // Ação inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->proximaAcaoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->proximaAcaoRepository->expects($this->never())->method('salvar');

        $this->expectException(ProximaAcaoNaoEncontradaException::class);

        $input = new ConcluirAcaoInput();
        $input->acaoId = 999;
        $input->resultado = 'Qualquer';

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }

    #[Test]
    public function rejeitaAcaoJaConcluida(): void
    {
        // Ação já concluída não é a ação ativa: não há o que concluir (§14).
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $acao = (new ProximaAcao())->setTenant($this->tenant)->setCaso($caso)->setDescricao('Já feita');
        $acao->concluir('Resolvido antes');

        $this->proximaAcaoRepository->method('findOneByIdDoTenant')->willReturn($acao);
        $this->proximaAcaoRepository->expects($this->never())->method('salvar');

        $this->expectException(ProximaAcaoNaoEncontradaException::class);

        $input = new ConcluirAcaoInput();
        $input->acaoId = 50;
        $input->resultado = 'Tentativa duplicada';

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }
}
