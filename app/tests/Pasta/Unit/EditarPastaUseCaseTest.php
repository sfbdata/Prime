<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\DTO\EditarPastaDTO;
use App\Pasta\Exception\NumeroDePastaEmUsoException;
use App\Pasta\Service\NumeracaoDePastaInterface;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Pasta\UseCase\EditarPastaUseCase;
use App\Pasta\UseCase\GerarNumeroDePasta;
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

    /** O número que outra pasta viva do escritório já tem (o que `emUsoPorOutraPasta` responde). */
    private ?string $numeroDeOutra = null;
    /** @var list<string> */
    private array $chamadasDaNumeracao = [];

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->numeroDeOutra = null;
        $this->chamadasDaNumeracao = [];
        $this->useCase = $this->useCase($this->em);
    }

    private function useCase(EntityManagerInterface&MockObject $em, ?RegistroDoSistemaNaPasta $registro = null): EditarPastaUseCase
    {
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $trabalho): mixed => $trabalho($em));
        $numeracao = $this->createStub(NumeracaoDePastaInterface::class);
        $numeracao->method('travar')->willReturnCallback(function (): void {
            $this->chamadasDaNumeracao[] = 'travar';
        });
        $numeracao->method('emUsoPorOutraPasta')->willReturnCallback(function (Tenant $t, string $nup): bool {
            $this->chamadasDaNumeracao[] = 'emUso ' . $nup;

            return $nup === $this->numeroDeOutra;
        });

        return new EditarPastaUseCase($em, new GerarNumeroDePasta($numeracao), $registro);
    }

    private static function pasta(?string $nup = null): Pasta
    {
        $pasta = (new Pasta())->setTenant(new Tenant());

        return $nup === null ? $pasta : $pasta->setNup($nup);
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

    #[TestDox('Número novo e livre: trava a numeração, confere e grava')]
    public function testNumeroNovoLivreEhGravado(): void
    {
        $pasta = self::pasta('NUP-000');
        $dto   = new EditarPastaDTO(nup: 'NUP-001', nomeCliente: 'Cliente X', situacao: Pasta::SITUACAO_ATIVA);

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($dto, $pasta);

        self::assertSame('NUP-001', $pasta->getNup());
        self::assertSame(['travar', 'emUso NUP-001'], $this->chamadasDaNumeracao, 'a trava vem antes da conferência');
    }

    #[TestDox('Número novo que é de outra pasta viva: recusa com a mensagem, e nada muda nem é gravado')]
    public function testNumeroDeOutraPastaEhRecusado(): void
    {
        $this->numeroDeOutra = '1240';
        $pasta = self::pasta('1239')->setNomeCliente('Ana');

        $this->em->expects($this->never())->method('flush');

        try {
            $this->useCase->executar(new EditarPastaDTO(nup: ' 1240 ', nomeCliente: 'Ana Maria', situacao: Pasta::SITUACAO_ARQUIVADA), $pasta);
            self::fail('Deveria recusar o número.');
        } catch (NumeroDePastaEmUsoException $e) {
            self::assertSame('O número 1240 já é de outra pasta do escritório. Escolha outro número.', $e->getMessage());
            self::assertInstanceOf(\InvalidArgumentException::class, $e, 'cai no catch que a tela já tem');
        }
        self::assertSame('1239', $pasta->getNup());
        self::assertSame('ANA', $pasta->getNomeCliente());
        self::assertSame(Pasta::SITUACAO_ATIVA, $pasta->getSituacao());
    }

    #[TestDox('Mesmo número (só muda caixa ou espaço): não confere, e a pasta do legado que divide o número segue editável')]
    public function testMesmoNumeroNaoConfere(): void
    {
        $this->numeroDeOutra = '10A';
        $pasta = self::pasta('10A');

        $this->useCase->executar(new EditarPastaDTO(nup: ' 10a ', nomeCliente: 'Bia', situacao: Pasta::SITUACAO_ATIVA), $pasta);

        self::assertSame([], $this->chamadasDaNumeracao);
        self::assertSame('BIA', $pasta->getNomeCliente());
    }

    #[TestDox('Situação inválida é ignorada')]
    public function testSituacaoInvalidaEhIgnorada(): void
    {
        $pasta = self::pasta();
        $pasta->setSituacao(Pasta::SITUACAO_ATIVA);
        $dto = new EditarPastaDTO(nup: 'NUP-002', situacao: 'xpto');

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($dto, $pasta);

        self::assertSame(Pasta::SITUACAO_ATIVA, $pasta->getSituacao());
    }

    #[TestDox('Situação válida ARQUIVADA é setada')]
    public function testSituacaoValidaEhSetada(): void
    {
        $pasta = self::pasta();
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
            $pasta = self::pasta();
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

        $this->useCase($em, new RegistroDoSistemaNaPasta($em))
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
        $uc = $this->useCase($em, new RegistroDoSistemaNaPasta($em));

        $uc->executar(new EditarPastaDTO(nup: '20', nomeCliente: 'Bia', nomeAcao: null, situacao: Pasta::SITUACAO_ATIVA), $pasta, $autor);
        self::assertCount(1, $persistidos);
        self::assertInstanceOf(PastaMensagem::class, $persistidos[0]);
        self::assertStringContainsString('dados editados: ação &quot;COBRANÇA&quot; → &quot;Não informado&quot;.', $persistidos[0]->getConteudo());

        $uc->executar(new EditarPastaDTO(nup: '20', nomeCliente: ' Bia ', nomeAcao: null, situacao: Pasta::SITUACAO_ATIVA), $pasta, $autor);
        self::assertCount(1, $persistidos, 'nada mudou: sem segundo cartão');
    }
}
