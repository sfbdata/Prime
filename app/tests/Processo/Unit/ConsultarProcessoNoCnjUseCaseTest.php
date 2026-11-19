<?php

declare(strict_types=1);

namespace App\Tests\Processo\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Processo\Enum\DesfechoDaConsultaAoCnj;
use App\Processo\Enum\MotivoFalhaDatajud;
use App\Processo\Exception\ConsultaDatajudException;
use App\Processo\Exception\TribunalNaoIdentificadoException;
use App\Processo\Repository\ProcessoRepository;
use App\Processo\Service\ConsultasDoCnjGuardadas;
use App\Processo\Service\DatajudClient;
use App\Processo\Service\DatajudProcessoMapper;
use App\Processo\Service\TribunalCnjResolver;
use App\Processo\Service\ValidadorNumeroCnj;
use App\Processo\UseCase\ConsultarProcessoNoCnjUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(ConsultarProcessoNoCnjUseCase::class)]
final class ConsultarProcessoNoCnjUseCaseTest extends TestCase
{
    private const NUMERO = '07011345720258070007';
    private const NUMERO_DV_ERRADO = '07011345820258070007';

    private MockObject&DatajudClient $datajud;
    private ConsultasDoCnjGuardadas $guardadas;

    protected function setUp(): void
    {
        $this->datajud = $this->createMock(DatajudClient::class);
        $this->guardadas = new ConsultasDoCnjGuardadas(new ArrayAdapter());
    }

    #[TestDox('achou: devolve os dados mapeados no formato da tela, com a sigla derivada do número, e guarda a consulta quando recebe a sessão')]
    public function testAchou(): void
    {
        $this->datajud->method('searchByNumeroProcesso')->with(self::NUMERO)->willReturn(['hits' => ['hits' => [['_source' => $this->source()]]]]);
        [$tenant, $pessoa] = [$this->comId(new Tenant(), 1), $this->comId(new User(), 2)];

        $saida = $this->useCase()->executar('0701134-57.2025.8.07.0007', $tenant, $pessoa, 'sessao-x');

        self::assertSame(DesfechoDaConsultaAoCnj::Achou, $saida->desfecho);
        self::assertSame(200, $saida->statusHttp);
        self::assertSame(self::NUMERO, $saida->dados['numeroProcesso']);
        self::assertSame('TJDFT', $saida->dados['siglaTribunal']);
        self::assertSame('PROCEDIMENTO COMUM CÍVEL', $saida->dados['classeProcessual']);
        self::assertSame($this->source(), $this->guardadas->buscar($tenant, $pessoa, 'sessao-x', self::NUMERO));
    }

    #[TestDox('achou sem sessão (o conector): não guarda nada')]
    public function testAchouSemSessaoNaoGuarda(): void
    {
        $this->datajud->method('searchByNumeroProcesso')->willReturn(['hits' => ['hits' => [['_source' => $this->source()]]]]);
        [$tenant, $pessoa] = [$this->comId(new Tenant(), 1), $this->comId(new User(), 2)];

        $saida = $this->useCase()->executar(self::NUMERO, $tenant, $pessoa);

        self::assertTrue($saida->achouOProcesso());
        self::assertNull($this->guardadas->buscar($tenant, $pessoa, '', self::NUMERO));
    }

    #[TestDox('número vazio ou só sem dígitos: "número inválido" com 400, sem chamar o CNJ')]
    public function testNumeroVazio(): void
    {
        $this->datajud->expects(self::never())->method('searchByNumeroProcesso');

        $saida = $this->useCase()->executar(' abc ', null, $this->comId(new User(), 2));

        self::assertSame(DesfechoDaConsultaAoCnj::NumeroInvalido, $saida->desfecho);
        self::assertSame(400, $saida->statusHttp);
        self::assertSame('Informe o número do processo para consultar no CNJ.', $saida->mensagem);
    }

    #[TestDox('número com dígitos a menos: "número inválido" (422) com a contagem de dígitos')]
    public function testNumeroForaDoPadrao(): void
    {
        $this->datajud->method('searchByNumeroProcesso')->willThrowException(new TribunalNaoIdentificadoException('fora do padrão'));

        $saida = $this->useCase()->executar('123', null, $this->comId(new User(), 2));

        self::assertSame(DesfechoDaConsultaAoCnj::NumeroInvalido, $saida->desfecho);
        self::assertSame(422, $saida->statusHttp);
        self::assertSame('O número informado tem 3 dígito(s), mas o padrão do CNJ tem 20. Confira e tente de novo.', $saida->mensagem);
    }

    #[TestDox('sem resultado e dígito verificador errado: "não encontrado" (404) apontando erro de digitação')]
    public function testNaoEncontradoComDigitoErrado(): void
    {
        $this->datajud->method('searchByNumeroProcesso')->willReturn(['hits' => ['hits' => []]]);
        $this->datajud->expects(self::never())->method('contarPosterioresNoForo');

        $saida = $this->useCase()->executar(self::NUMERO_DV_ERRADO, null, $this->comId(new User(), 2));

        self::assertSame(DesfechoDaConsultaAoCnj::NaoEncontrado, $saida->desfecho);
        self::assertSame(404, $saida->statusHttp);
        self::assertStringContainsString('o dígito verificador não confere', (string) $saida->mensagem);
        self::assertStringContainsString('base do TJDFT', (string) $saida->mensagem);
    }

    #[TestDox('sem resultado, número válido e nenhum processo posterior no foro: explica o atraso da base')]
    public function testNaoEncontradoPorAtraso(): void
    {
        $this->datajud->method('searchByNumeroProcesso')->willReturn(['hits' => ['hits' => []]]);
        $this->datajud->method('contarPosterioresNoForo')->with(self::NUMERO, 50)->willReturn(0);

        $saida = $this->useCase()->executar(self::NUMERO, null, $this->comId(new User(), 2));

        self::assertSame(404, $saida->statusHttp);
        self::assertStringContainsString('ainda não aparece na base pública do TJDFT', (string) $saida->mensagem);
    }

    #[TestDox('sem resultado, número válido e muitos posteriores: sugere segredo de justiça ou arquivado')]
    public function testNaoEncontradoComMuitosPosteriores(): void
    {
        $this->datajud->method('searchByNumeroProcesso')->willReturn(['hits' => ['hits' => []]]);
        $this->datajud->method('contarPosterioresNoForo')->willReturn(50);

        $saida = $this->useCase()->executar(self::NUMERO, null, $this->comId(new User(), 2));

        self::assertStringContainsString('embora processos mais novos do mesmo foro já apareçam', (string) $saida->mensagem);
    }

    #[TestDox('sem resultado e a contagem de posteriores falha: cai na mensagem que cobre as duas causas')]
    public function testNaoEncontradoSemContagem(): void
    {
        $this->datajud->method('searchByNumeroProcesso')->willReturn(['hits' => ['hits' => []]]);
        $this->datajud->method('contarPosterioresNoForo')->willThrowException(new ConsultaDatajudException(MotivoFalhaDatajud::Timeout));

        $saida = $this->useCase()->executar(self::NUMERO, null, $this->comId(new User(), 2));

        self::assertSame(DesfechoDaConsultaAoCnj::NaoEncontrado, $saida->desfecho);
        self::assertStringContainsString('não encontramos este processo na base pública do TJDFT', (string) $saida->mensagem);
    }

    #[TestDox('falha do CNJ: desfecho falha com o motivo, a mensagem amigável e o status do motivo')]
    public function testFalhaDoCnj(): void
    {
        $this->datajud->method('searchByNumeroProcesso')->willThrowException(new ConsultaDatajudException(MotivoFalhaDatajud::LimiteExcedido));

        $saida = $this->useCase()->executar(self::NUMERO, null, $this->comId(new User(), 2));

        self::assertSame(DesfechoDaConsultaAoCnj::Falha, $saida->desfecho);
        self::assertSame(MotivoFalhaDatajud::LimiteExcedido, $saida->motivo);
        self::assertSame(429, $saida->statusHttp);
        self::assertSame(MotivoFalhaDatajud::LimiteExcedido->mensagemUsuario('TJDFT'), $saida->mensagem);
    }

    #[TestDox('resposta sem a lista de hits: falha "resposta inválida"')]
    public function testRespostaInvalida(): void
    {
        $this->datajud->method('searchByNumeroProcesso')->willReturn(['erro' => 'x']);

        $saida = $this->useCase()->executar(self::NUMERO, null, $this->comId(new User(), 2));

        self::assertSame(DesfechoDaConsultaAoCnj::Falha, $saida->desfecho);
        self::assertSame(MotivoFalhaDatajud::RespostaInvalida, $saida->motivo);
        self::assertSame(502, $saida->statusHttp);
    }

    #[TestDox('erro inesperado: falha 500 genérica, sem motivo do CNJ')]
    public function testErroInesperado(): void
    {
        $this->datajud->method('searchByNumeroProcesso')->willThrowException(new \RuntimeException('boom'));

        $saida = $this->useCase()->executar(self::NUMERO, null, $this->comId(new User(), 2));

        self::assertSame(DesfechoDaConsultaAoCnj::Falha, $saida->desfecho);
        self::assertNull($saida->motivo);
        self::assertSame(500, $saida->statusHttp);
    }

    private function useCase(): ConsultarProcessoNoCnjUseCase
    {
        $resolver = new TribunalCnjResolver();
        $repositorio = $this->createMock(ProcessoRepository::class);
        $repositorio->method('findOneBy')->willReturn(null);

        return new ConsultarProcessoNoCnjUseCase(
            $this->datajud,
            new DatajudProcessoMapper($repositorio),
            new ValidadorNumeroCnj(),
            $this->guardadas,
            $resolver,
            new NullLogger(),
        );
    }

    /** @return array<string, mixed> */
    private function source(): array
    {
        return [
            'numeroProcesso' => self::NUMERO,
            'classe' => ['codigo' => 7, 'nome' => 'Procedimento Comum Cível'],
            'tribunal' => 'TJDFT',
            'grau' => 'G1',
            'nivelSigilo' => 0,
            'id' => 'TJDFT_G1_' . self::NUMERO,
        ];
    }

    private function comId(object $entidade, int $id): object
    {
        (new \ReflectionProperty($entidade, 'id'))->setValue($entidade, $id);

        return $entidade;
    }
}
