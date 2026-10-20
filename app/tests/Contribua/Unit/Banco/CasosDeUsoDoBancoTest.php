<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit\Banco;

use App\Contribua\Banco\Bacen\BancoDoBrasil;
use App\Contribua\Banco\Bacen\BancoInter;
use App\Contribua\Banco\Bacen\BancoPixPadrao;
use App\Contribua\Banco\Bacen\ClientePixBacen;
use App\Contribua\Banco\BancosPix;
use App\Contribua\Banco\CertificadoDoBanco;
use App\Contribua\Banco\CofreDeCertificado;
use App\Contribua\Banco\Cora\BancoCora;
use App\Contribua\Banco\Cora\ClienteCora;
use App\Contribua\DTO\DadosDoBancoInput;
use App\Contribua\Entity\IntegracaoBancaria;
use App\Contribua\Repository\IntegracaoBancariaRepository;
use App\Contribua\UseCase\EnviarCertificadoDoBancoUseCase;
use App\Contribua\UseCase\SalvarIntegracaoBancariaUseCase;
use App\Contribua\UseCase\TestarConexaoDoBancoUseCase;
use App\Contribua\UseCase\VerIntegracaoBancariaUseCase;
use App\Entity\Auth\User;
use App\Tests\Contribua\Banco\CoraFalsa;
use App\Tests\Contribua\Banco\ParDeCertificado;
use App\Tests\Contribua\Banco\PspBacenFalso;
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
        $sut = $this->salvar();
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
        $sut = new TestarConexaoDoBancoUseCase($this->repo, new BancosPix([new BancoCora(new ClienteCora($cora->cliente(), new ArrayAdapter(), $this->cofre), $this->repo, $this->cofre)]), new ArrayAdapter());

        self::assertTrue($sut->executar()->conectou);
        self::assertTrue($this->integracao->cobraPorConta());

        $cora->recusarToken = true;
        $novo = new TestarConexaoDoBancoUseCase($this->repo, new BancosPix([new BancoCora(new ClienteCora($cora->cliente(), new ArrayAdapter(), $this->cofre), $this->repo, $this->cofre)]), new ArrayAdapter());
        self::assertFalse($novo->executar()->conectou);
        self::assertSame(IntegracaoBancaria::ESTADO_DESLIGADA, $this->integracao->getEstado());
        self::assertFalse($this->integracao->cobraPorConta());
    }

    #[TestDox('Salvar sem o banco (pedido antigo) mantém a Cora e os campos extras como estão')]
    public function testSalvarSemBanco(): void
    {
        $this->salvar()->executar('sandbox', 'c1', [], new User());

        self::assertSame('cora', $this->integracao->getBanco());
        self::assertNull($this->integracao->getChavePix());
    }

    #[TestDox('Salvar o Inter: grava o banco, a chave PIX e o Client Secret cifrado; em branco depois, o segredo fica; a tela só sabe "definido"')]
    public function testSalvarInter(): void
    {
        $sut = $this->salvar();

        $sut->executar('producao', 'c1', [], new User(), new DadosDoBancoInput('inter', ' segredo-1 ', 'pix@bluejus.com.br'));

        self::assertSame('inter', $this->integracao->getBanco());
        self::assertSame('pix@bluejus.com.br', $this->integracao->getChavePix());
        self::assertSame('segredo-1', $this->cofre->decifrar((string) $this->integracao->getClientSecretCifrado()));
        self::assertStringNotContainsString('segredo-1', (string) base64_decode((string) $this->integracao->getClientSecretCifrado()));

        $sut->executar('producao', 'c1', [], new User(), new DadosDoBancoInput('inter', '', 'pix@bluejus.com.br'));
        self::assertSame('segredo-1', $this->cofre->decifrar((string) $this->integracao->getClientSecretCifrado()), 'em branco mantém');

        $ver = (new VerIntegracaoBancariaUseCase($this->repo))->executar();
        self::assertTrue($ver->clientSecretDefinido);
        self::assertFalse($ver->appKeyDefinida);
        self::assertSame('inter', $ver->banco);
        self::assertStringNotContainsString('segredo-1', (string) json_encode($ver));
        self::assertStringNotContainsString((string) $this->integracao->getClientSecretCifrado(), (string) json_encode($ver));
    }

    #[TestDox('Salvar recusa: banco que não existe, Inter sem segredo ou sem chave PIX, BB sem app key, Outro sem URLs ou com URL que não é https; nada muda')]
    public function testSalvarRecusas(): void
    {
        $sut = $this->salvar();
        $casos = [
            'Banco não disponível.' => new DadosDoBancoInput('itau', 's', 'x'),
            'Informe o Client Secret fornecido pelo banco.' => new DadosDoBancoInput('inter', '', 'x'),
            'Informe a chave PIX da conta que recebe.' => new DadosDoBancoInput('inter', 's', ''),
            'Informe a app key do Banco do Brasil.' => new DadosDoBancoInput('bb', 's', 'x'),
            'Informe a URL base da API Pix e a URL do token do banco.' => new DadosDoBancoInput('outro', 's', 'x', '', 'https://psp/pix'),
            'A URL base da API Pix precisa ser um endereço https:// completo.' => new DadosDoBancoInput('outro', 's', 'x', '', 'http://psp.com/pix', 'https://psp.com/token'),
            'A URL do token precisa ser um endereço https:// completo.' => new DadosDoBancoInput('outro', 's', 'x', '', 'https://psp.com/pix', 'file:///etc/passwd'),
        ];
        foreach ($casos as $mensagem => $dados) {
            try {
                $sut->executar('sandbox', 'c1', [], new User(), $dados);
                self::fail('Devia recusar: ' . $mensagem);
            } catch (\DomainException $e) {
                self::assertSame($mensagem, $e->getMessage());
            }
        }
        self::assertSame('cora', $this->integracao->getBanco());
        self::assertFalse($this->integracao->temClientSecret());
        self::assertNull($this->integracao->getUrlBase());
    }

    #[TestDox('Salvar o BB e o Outro: app key cifrada; URLs do PSP gravadas sem a barra final')]
    public function testSalvarBbEOutro(): void
    {
        $sut = $this->salvar();
        $sut->executar('sandbox', 'c1', [], new User(), new DadosDoBancoInput('bb', 's', 'x', 'chave-do-app'));
        self::assertSame('chave-do-app', $this->cofre->decifrar((string) $this->integracao->getAppKeyCifrada()));

        $sut->executar('sandbox', 'c1', [], new User(), new DadosDoBancoInput('outro', 's2', 'x', '', 'https://psp.com.br/pix/v2/', 'https://psp.com.br/oauth/token'));
        self::assertSame('outro', $this->integracao->getBanco());
        self::assertFalse($this->integracao->temAppKey(), 'a app key do BB foi esquecida na troca');
        self::assertSame('https://psp.com.br/pix/v2', $this->integracao->getUrlBase());
        self::assertSame('https://psp.com.br/oauth/token', $this->integracao->getUrlToken());
    }

    #[TestDox('Testar conexão com o Inter: usa o banco escolhido; conectou, "Conectado"; trocar de banco desfaz a conexão')]
    public function testTestarInter(): void
    {
        $par = ParDeCertificado::novo();
        $this->integracao->guardarCertificado($this->cofre->cifrar($par['certificado']), $this->cofre->cifrar($par['chave']), ['c.crt', 'c.key'], new User());
        $this->salvar()->executar('sandbox', 'c1', [], new User(), new DadosDoBancoInput('inter', 's', 'pix@bluejus.com.br'));
        $psp = new PspBacenFalso();
        $cliente = new ClientePixBacen($psp->cliente(), new ArrayAdapter(), $this->cofre);
        $sut = new TestarConexaoDoBancoUseCase($this->repo, new BancosPix([new BancoInter($cliente, $this->repo, $this->cofre)]), new ArrayAdapter());

        $r = $sut->executar();

        self::assertTrue($r->conectou, $r->mensagem);
        self::assertSame('Conexão com o Banco Inter confirmada.', $this->integracao->getResultadoDoTeste());
        self::assertTrue($this->integracao->cobraPorConta());
        $this->integracao->escolherBanco('bb');
        self::assertFalse($this->integracao->cobraPorConta(), 'o teste valia para o Inter');
    }

    #[TestDox('Trocar de banco ou as URLs do Outro esquece o segredo guardado: em branco, recusa pedindo de novo; nada muda')]
    public function testTrocaExigeSegredoDeNovo(): void
    {
        $sut = $this->salvar();
        $sut->executar('sandbox', 'c1', [], new User(), new DadosDoBancoInput('inter', 'segredo-inter', 'x'));
        $antes = $this->integracao->getClientSecretCifrado();

        $recusas = [
            'Ao trocar de banco ou de endereço, informe de novo o Client Secret.' => new DadosDoBancoInput('bb', '', 'x', 'k'),
            'Informe a app key do Banco do Brasil.' => new DadosDoBancoInput('bb', 'novo', 'x'),
        ];
        foreach ($recusas as $mensagem => $dados) {
            try {
                $sut->executar('sandbox', 'c1', [], new User(), $dados);
                self::fail('Devia recusar: ' . $mensagem);
            } catch (\DomainException $e) {
                self::assertSame($mensagem, $e->getMessage());
            }
        }
        self::assertSame('inter', $this->integracao->getBanco());
        self::assertSame($antes, $this->integracao->getClientSecretCifrado());

        $sut->executar('sandbox', 'c1', [], new User(), new DadosDoBancoInput('outro', 'segredo-outro', 'x', '', 'https://a.psp.com.br/pix', 'https://a.psp.com.br/token'));
        try {
            $sut->executar('sandbox', 'c1', [], new User(), new DadosDoBancoInput('outro', '', 'x', '', 'https://b.atacante.com/pix', 'https://a.psp.com.br/token'));
            self::fail('Devia recusar a URL nova sem o segredo.');
        } catch (\DomainException $e) {
            self::assertSame('Ao trocar de banco ou de endereço, informe de novo o Client Secret.', $e->getMessage());
        }
        self::assertSame('https://a.psp.com.br/pix', $this->integracao->getUrlBase());
        self::assertSame('segredo-outro', $this->cofre->decifrar((string) $this->integracao->getClientSecretCifrado()));
    }

    #[TestDox('URLs do Outro: recusa localhost e IP privado, de loopback ou link-local (v4 e v6); aceita host público')]
    public function testUrlsDaRedeInterna(): void
    {
        $sut = $this->salvar();
        foreach (['https://localhost/pix', 'https://api.localhost/pix', 'https://127.0.0.1/pix', 'https://10.0.0.5/pix', 'https://192.168.1.1/pix', 'https://172.16.0.1/pix', 'https://169.254.169.254/latest', 'https://[::1]/pix', 'https://[fe80::1]/pix', 'https://[fd00::1]/pix', 'https://0.0.0.0/pix'] as $url) {
            try {
                $sut->executar('sandbox', 'c1', [], new User(), new DadosDoBancoInput('outro', 's', 'x', '', $url, 'https://psp.com.br/token'));
                self::fail('Devia recusar ' . $url);
            } catch (\DomainException $e) {
                self::assertSame('A URL base da API Pix não pode apontar para a rede interna (localhost, IP privado, de loopback ou link-local).', $e->getMessage(), $url);
            }
        }
        self::assertNull($this->integracao->getUrlBase());

        $sut->executar('sandbox', 'c1', [], new User(), new DadosDoBancoInput('outro', 's', 'x', '', 'https://8.8.8.8/pix', 'https://psp.com.br/token'));
        self::assertSame('https://8.8.8.8/pix', $this->integracao->getUrlBase());
    }

    private function salvar(): SalvarIntegracaoBancariaUseCase
    {
        $cliente = new ClientePixBacen((new PspBacenFalso())->cliente(), new ArrayAdapter(), $this->cofre);
        $bancos = new BancosPix([
            new BancoCora(new ClienteCora((new CoraFalsa())->cliente(), new ArrayAdapter(), $this->cofre), $this->repo, $this->cofre),
            new BancoInter($cliente, $this->repo, $this->cofre),
            new BancoDoBrasil($cliente, $this->repo, $this->cofre),
            new BancoPixPadrao($cliente, $this->repo, $this->cofre),
        ]);

        return new SalvarIntegracaoBancariaUseCase($this->repo, $bancos, $this->cofre);
    }
}
