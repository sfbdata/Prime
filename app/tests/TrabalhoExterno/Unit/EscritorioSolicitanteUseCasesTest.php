<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\TrabalhoExterno\DTO\DadosDoEscritorioSolicitante;
use App\TrabalhoExterno\Entity\EscritorioSolicitante;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Exception\TransicaoInvalida;
use App\TrabalhoExterno\Repository\EscritorioSolicitanteRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\UseCase\AtualizarEscritorioSolicitanteUseCase;
use App\TrabalhoExterno\UseCase\CriarEscritorioSolicitanteUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** "Novo escritório" e "Salvar edição" da Nova solicitação (desenho 06 de 25/10). */
#[CoversClass(CriarEscritorioSolicitanteUseCase::class)]
#[CoversClass(AtualizarEscritorioSolicitanteUseCase::class)]
#[CoversClass(DadosDoEscritorioSolicitante::class)]
#[CoversClass(EscritorioSolicitante::class)]
final class EscritorioSolicitanteUseCasesTest extends TestCase
{
    private function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    /** @param array<string, mixed> $extra */
    private static function dados(array $extra = []): DadosDoEscritorioSolicitante
    {
        return DadosDoEscritorioSolicitante::deArray($extra + ['nome' => '  Silva & Souza  ', 'email' => 'contato@silva.test', 'telefone' => '(61) 3000-0000', 'uf' => 'df', 'cidade' => 'Brasília']);
    }

    private function criar(EscritorioSolicitanteRepository $repo): CriarEscritorioSolicitanteUseCase
    {
        return new CriarEscritorioSolicitanteUseCase($repo, $this->validator());
    }

    #[TestDox('cria para o usuário logado, aparando o texto e pondo a UF em maiúsculas')]
    public function testCria(): void
    {
        $repo = $this->createMock(EscritorioSolicitanteRepository::class);
        $repo->method('contarDoUsuario')->willReturn(0);
        $repo->expects(self::once())->method('salvar')->with(self::isInstanceOf(EscritorioSolicitante::class), true);
        $ctx = Apoio::contexto(Perfil::Solicitante);

        $e = $this->criar($repo)->executar($ctx, self::dados());

        self::assertSame($ctx->usuario, $e->getUsuario());
        self::assertSame('Silva & Souza', $e->getNome());
        self::assertSame('DF', $e->getUf());
        self::assertSame('Brasília', $e->getCidade());
    }

    #[TestDox('campo opcional vazio vira null e o painel recebe texto vazio')]
    public function testOpcionaisVazios(): void
    {
        $e = new EscritorioSolicitante(Apoio::usuario(1), self::dados(['email' => ' ', 'telefone' => '', 'uf' => '', 'cidade' => '']));

        self::assertNull($e->getEmail());
        self::assertNull($e->getUf());
        $linha = $e->paraPainel();
        self::assertTrue($linha['salvo']);
        self::assertSame('', $linha['email']);
        self::assertSame('', $linha['uf']);
    }

    #[TestDox('nome vazio: 422 com a mensagem do desenho, sem gravar')]
    public function testNomeVazio(): void
    {
        $repo = $this->createMock(EscritorioSolicitanteRepository::class);
        $repo->method('contarDoUsuario')->willReturn(0);
        $repo->expects(self::never())->method('salvar');

        $this->expectException(DadosInvalidos::class);
        $this->expectExceptionMessage('Informe o nome do escritório para salvar.');
        $this->criar($repo)->executar(Apoio::contexto(Perfil::Solicitante), self::dados(['nome' => '   ']));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function entradasInvalidas(): iterable
    {
        yield 'e-mail fora do formato' => [['email' => 'nao-e-email']];
        yield 'UF inexistente' => [['uf' => 'XX']];
        yield 'nome acima de 120' => [['nome' => str_repeat('a', 121)]];
        yield 'telefone acima de 30' => [['telefone' => str_repeat('1', 31)]];
        yield 'município acima de 80' => [['cidade' => str_repeat('a', 81)]];
    }

    /** @param array<string, mixed> $extra */
    #[\PHPUnit\Framework\Attributes\DataProvider('entradasInvalidas')]
    #[TestDox('entrada fora do limite ou do formato: 422')]
    public function testEntradaInvalida(array $extra): void
    {
        $repo = $this->createMock(EscritorioSolicitanteRepository::class);
        $repo->method('contarDoUsuario')->willReturn(0);
        $repo->expects(self::never())->method('salvar');

        $this->expectException(DadosInvalidos::class);
        $this->criar($repo)->executar(Apoio::contexto(Perfil::Solicitante), self::dados($extra));
    }

    #[TestDox('no teto de 20 guardados: 409; com 19 ainda cria')]
    public function testTeto(): void
    {
        $repo = $this->createMock(EscritorioSolicitanteRepository::class);
        $repo->method('contarDoUsuario')->willReturnOnConsecutiveCalls(19, 20);
        $repo->expects(self::once())->method('salvar');
        $uc = $this->criar($repo);
        $ctx = Apoio::contexto(Perfil::Solicitante);

        $uc->executar($ctx, self::dados());
        $this->expectException(TransicaoInvalida::class);
        $uc->executar($ctx, self::dados());
    }

    #[TestDox('prévia do Master e perfil que não solicita (Equipe, Convidado): negado, sem gravar')]
    public function testQuemNaoPode(): void
    {
        $repo = $this->createMock(EscritorioSolicitanteRepository::class);
        $repo->expects(self::never())->method('salvar');
        $uc = $this->criar($repo);

        foreach ([Perfil::Equipe, Perfil::Convidado] as $perfil) {
            try {
                $uc->executar(Apoio::contexto($perfil), self::dados());
                self::fail('devia negar ' . $perfil->value);
            } catch (AcaoNaoPermitida) {
                self::addToAssertionCount(1);
            }
        }
        $previa = new Contexto(Apoio::usuario(1), Apoio::tenant(1), Perfil::Solicitante, new \App\TrabalhoExterno\Entity\ConfiguracaoCentral(), previa: true);
        $this->expectException(AcaoNaoPermitida::class);
        $uc->executar($previa, self::dados());
    }

    #[TestDox('atualiza só o do dono, procurando sempre pelo usuário logado')]
    public function testAtualiza(): void
    {
        $ctx = Apoio::contexto(Perfil::Solicitante);
        $existente = new EscritorioSolicitante($ctx->usuario, self::dados());
        $repo = $this->createMock(EscritorioSolicitanteRepository::class);
        $repo->expects(self::once())->method('doUsuarioPorId')->with($ctx->usuario, 7)->willReturn($existente);
        $repo->expects(self::once())->method('salvar')->with($existente, true);

        $e = (new AtualizarEscritorioSolicitanteUseCase($repo, $this->validator()))->executar($ctx, 7, self::dados(['nome' => 'Novo nome', 'cidade' => '']));

        self::assertSame('Novo nome', $e->getNome());
        self::assertNull($e->getCidade());
    }

    #[TestDox('id de outro usuário (ou inexistente): 404, sem gravar')]
    public function testOutroUsuario(): void
    {
        $repo = $this->createMock(EscritorioSolicitanteRepository::class);
        $repo->method('doUsuarioPorId')->willReturn(null);
        $repo->expects(self::never())->method('salvar');

        $this->expectException(DemandaNaoEncontrada::class);
        (new AtualizarEscritorioSolicitanteUseCase($repo, $this->validator()))->executar(Apoio::contexto(Perfil::Solicitante), 7, self::dados());
    }

    #[TestDox('atualizar com nome vazio: 422 e o cadastro fica como estava')]
    public function testAtualizaNomeVazio(): void
    {
        $ctx = Apoio::contexto(Perfil::Solicitante);
        $existente = new EscritorioSolicitante($ctx->usuario, self::dados());
        $repo = $this->createMock(EscritorioSolicitanteRepository::class);
        $repo->method('doUsuarioPorId')->willReturn($existente);
        $repo->expects(self::never())->method('salvar');

        try {
            (new AtualizarEscritorioSolicitanteUseCase($repo, $this->validator()))->executar($ctx, 7, self::dados(['nome' => '']));
            self::fail('devia recusar');
        } catch (DadosInvalidos $e) {
            self::assertSame('Informe o nome do escritório para salvar.', $e->getMessage());
        }
        self::assertSame('Silva & Souza', $existente->getNome());
    }
}
