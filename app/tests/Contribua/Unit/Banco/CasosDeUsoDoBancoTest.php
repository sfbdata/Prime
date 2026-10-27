<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit\Banco;

use App\Contribua\Banco\BancosPix;
use App\Contribua\Banco\CertificadoDoBanco;
use App\Contribua\Banco\CofreDeCertificado;
use App\Contribua\Banco\Cora\BancoCora;
use App\Contribua\Banco\Cora\ClienteCora;
use App\Contribua\Entity\IntegracaoBancaria;
use App\Contribua\Repository\IntegracaoBancariaRepository;
use App\Contribua\UseCase\EnviarCertificadoDoBancoUseCase;
use App\Contribua\UseCase\SalvarIntegracaoBancariaUseCase;
use App\Contribua\UseCase\TestarConexaoDoBancoUseCase;
use App\Contribua\UseCase\VerIntegracaoBancariaUseCase;
use App\Entity\Auth\User;
use App\Tests\Contribua\Banco\CoraFalsa;
use App\Tests\Contribua\Banco\ParDeCertificado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(SalvarIntegracaoBancariaUseCase::class)]
#[CoversClass(EnviarCertificadoDoBancoUseCase::class)]
#[CoversClass(TestarConexaoDoBancoUseCase::class)]
#[CoversClass(VerIntegracaoBancariaUseCase::class)]
#[CoversClass(IntegracaoBancaria::class)]
final class CasosDeUsoDoBancoTest extends TestCase
{
    private IntegracaoBancaria $integracao;
    private IntegracaoBancariaRepository $repo;
    private CofreDeCertificado $cofre;

    protected function setUp(): void
    {
        $this->integracao = new IntegracaoBancaria();
        $this->repo = $this->createStub(IntegracaoBancariaRepository::class);
        $this->repo->method('atual')->willReturn($this->integracao);
        $this->cofre = new CofreDeCertificado(base64_encode(random_bytes(32)));
    }

    #[TestDox('Salvar exige Client ID; sem certificado fica "Não conectado"; com certificado, "Aguardando servidor"; recursos desconhecidos são ignorados')]
    public function testSalvar(): void
    {
        $sut = new SalvarIntegracaoBancariaUseCase($this->repo);
        try {
            $sut->executar('sandbox', '  ', [], new User());
            self::fail('Devia recusar.');
        } catch (\DomainException $e) {
            self::assertSame('Informe o Client ID fornecido pelo banco.', $e->getMessage());
        }

        self::assertSame('Integração salva. Ela é ativada quando o servidor validar o certificado.', $sut->executar('sandbox', 'c1', ['bol' => true, 'xyz' => true], new User()));
        self::assertSame(IntegracaoBancaria::ESTADO_DESLIGADA, $this->integracao->getEstado());
        self::assertSame(['cob' => true, 'conc' => true, 'bol' => true, 'rel' => true], $this->integracao->getRecursos());
    }

    #[TestDox('Enviar o certificado guarda cifrado e devolve só os nomes; a tela (Ver) diz presente, sem o conteúdo')]
    public function testEnviarEVer(): void
    {
        $par = ParDeCertificado::novo();
        $nomes = (new EnviarCertificadoDoBancoUseCase(new CertificadoDoBanco(), $this->cofre, $this->repo))->executar(['c.pem' => $par['certificado'], 'k.key' => $par['chave']], new User());

        self::assertSame(['c.pem', 'k.key'], $nomes);
        self::assertSame($par['chave'], $this->cofre->decifrar((string) $this->integracao->getChaveCifrada()));
        $ver = (new VerIntegracaoBancariaUseCase($this->repo))->executar();
        self::assertTrue($ver->certificadoPresente);
        self::assertSame(IntegracaoBancaria::ESTADO_AGUARDANDO, $ver->estado);
        self::assertStringNotContainsString('PRIVATE', (string) json_encode($ver));
    }

    #[TestDox('Testar conexão: conectou vira "Conectado" e passa a cobrar por conta; recusa volta a "Não conectado"')]
    public function testTestar(): void
    {
        $par = ParDeCertificado::novo();
        $this->integracao->guardarCertificado($this->cofre->cifrar($par['certificado']), $this->cofre->cifrar($par['chave']), ['c.pem'], new User());
        $this->integracao->configurar('sandbox', 'c1', [], new User());
        $cora = new CoraFalsa();
        $sut = new TestarConexaoDoBancoUseCase($this->repo, new BancosPix(new BancoCora(new ClienteCora($cora->cliente(), new ArrayAdapter(), $this->cofre), $this->repo, $this->cofre)), new ArrayAdapter());

        self::assertTrue($sut->executar()->conectou);
        self::assertTrue($this->integracao->cobraPorConta());

        $cora->recusarToken = true;
        $novo = new TestarConexaoDoBancoUseCase($this->repo, new BancosPix(new BancoCora(new ClienteCora($cora->cliente(), new ArrayAdapter(), $this->cofre), $this->repo, $this->cofre)), new ArrayAdapter());
        self::assertFalse($novo->executar()->conectou);
        self::assertSame(IntegracaoBancaria::ESTADO_DESLIGADA, $this->integracao->getEstado());
        self::assertFalse($this->integracao->cobraPorConta());
    }
}
