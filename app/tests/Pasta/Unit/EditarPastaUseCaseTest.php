<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\DTO\EditarPastaDTO;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Pasta\UseCase\EditarPastaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(EditarPastaUseCase::class)]
final class EditarPastaUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private EditarPastaUseCase $useCase;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new EditarPastaUseCase($this->em);
    }

    #[TestDox('NUP vazio lança InvalidArgumentException e não persiste')]
    public function testNupVazioLancaExcecao(): void
    {
        $pasta = new Pasta();
        $dto   = new EditarPastaDTO(nup: '   ', situacao: Pasta::SITUACAO_ATIVA);

        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('O NUP é obrigatório.');

        $this->useCase->executar($dto, $pasta);
    }

    #[TestDox('NUP repetido agora é permitido: persiste sem lançar')]
    public function testNupRepetidoEhPermitido(): void
    {
        $pasta = new Pasta();
        $dto   = new EditarPastaDTO(nup: 'NUP-001', nomeCliente: 'Cliente X', situacao: Pasta::SITUACAO_ATIVA);

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($dto, $pasta);

        self::assertSame('NUP-001', $pasta->getNup());
    }

    #[TestDox('Situação inválida é ignorada')]
    public function testSituacaoInvalidaEhIgnorada(): void
    {
        $pasta = new Pasta();
        $pasta->setSituacao(Pasta::SITUACAO_ATIVA);
        $dto = new EditarPastaDTO(nup: 'NUP-002', situacao: 'xpto');

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($dto, $pasta);

        self::assertSame(Pasta::SITUACAO_ATIVA, $pasta->getSituacao());
    }

    #[TestDox('Situação válida ARQUIVADA é setada')]
    public function testSituacaoValidaEhSetada(): void
    {
        $pasta = new Pasta();
        $pasta->setSituacao(Pasta::SITUACAO_ATIVA);
        $dto = new EditarPastaDTO(nup: 'NUP-003', situacao: Pasta::SITUACAO_ARQUIVADA);

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($dto, $pasta);

        self::assertSame(Pasta::SITUACAO_ARQUIVADA, $pasta->getSituacao());
    }

    #[TestDox('Suspenso e Cancelado do modal "Editar dados" são gravados (antes viravam ativo sem ninguém pedir)')]
    public function testSuspensoECanceladoSaoAceitos(): void
    {
        foreach ([Pasta::SITUACAO_SUSPENSA, Pasta::SITUACAO_CANCELADA] as $situacao) {
            $pasta = new Pasta();
            $pasta->setSituacao(Pasta::SITUACAO_ATIVA);

            $this->useCase->executar(new EditarPastaDTO(nup: 'NUP-004', situacao: $situacao), $pasta);

            self::assertSame($situacao, $pasta->getSituacao());
        }
    }

    #[TestDox('com o autor, o que mudou vira "Dados da pasta: dados editados: número 10 → 11; cliente "ANA" → "ANA MARIA"." no tenant da pasta')]
    public function testCartaoDosDadosEditados(): void
    {
        $tenant = new Tenant();
        $pasta = (new Pasta())->setNup('10')->setNomeCliente('Ana')->setNomeAcao('Revisional');
        $pasta->setTenant($tenant);
        $autor = (new User())->setFullName('Autora');
        $persistidos = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $o) use (&$persistidos): void {
            $persistidos[] = $o;
        });
        $em->expects($this->once())->method('flush');

        (new EditarPastaUseCase($em, new RegistroDoSistemaNaPasta($em)))
            ->executar(new EditarPastaDTO(nup: ' 11 ', nomeCliente: 'Ana Maria', nomeAcao: 'Revisional', situacao: Pasta::SITUACAO_ATIVA), $pasta, $autor);

        self::assertCount(1, $persistidos);
        $cartao = $persistidos[0];
        self::assertInstanceOf(PastaMensagem::class, $cartao);
        self::assertSame('pasta', $cartao->getSistema());
        self::assertSame($tenant, $cartao->getTenant());
        self::assertSame($autor, $cartao->getAutor());
        self::assertSame('<p><strong>Dados da pasta:</strong> dados editados: número 10 → 11; cliente &quot;ANA&quot; → &quot;ANA MARIA&quot;.</p>', $cartao->getConteudo());
    }

    #[TestDox('ação limpa aparece como "Não informado"; nenhum campo mudado não escreve cartão')]
    public function testAcaoLimpaESemMudanca(): void
    {
        $pasta = (new Pasta())->setNup('20')->setNomeCliente('Bia')->setNomeAcao('Cobrança');
        $pasta->setTenant(new Tenant());
        $autor = (new User())->setFullName('Autora');
        $persistidos = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $o) use (&$persistidos): void {
            $persistidos[] = $o;
        });
        $uc = new EditarPastaUseCase($em, new RegistroDoSistemaNaPasta($em));

        $uc->executar(new EditarPastaDTO(nup: '20', nomeCliente: 'Bia', nomeAcao: null, situacao: Pasta::SITUACAO_ATIVA), $pasta, $autor);
        self::assertCount(1, $persistidos);
        self::assertInstanceOf(PastaMensagem::class, $persistidos[0]);
        self::assertStringContainsString('dados editados: ação &quot;COBRANÇA&quot; → &quot;Não informado&quot;.', $persistidos[0]->getConteudo());

        $uc->executar(new EditarPastaDTO(nup: '20', nomeCliente: ' Bia ', nomeAcao: null, situacao: Pasta::SITUACAO_ATIVA), $pasta, $autor);
        self::assertCount(1, $persistidos, 'nada mudou: sem segundo cartão');
    }
}
