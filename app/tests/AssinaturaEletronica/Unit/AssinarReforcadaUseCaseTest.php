<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\DTO\EntradaDaAssinatura;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\NivelDeAssinatura;
use App\AssinaturaEletronica\Enum\SituacaoDoSignatario;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Service\CofreDaSelfie;
use App\AssinaturaEletronica\UseCase\AssinarDocumentoUseCase;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Doctrine\Transacao\DestinoDaTransacao;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use App\Tests\AssinaturaEletronica\Support\OficinaDosUseCasesDaAssinatura;
use App\Tests\Shared\Doubles\ConsultaDeDestinoFixa;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * "Assinar" Reforçado (contrato da etapa 7, seções 4.2 e 9) com os repositórios do domínio dublados: a chave da selfie
 * exigida antes de tudo, a selfie e a localização conferidas depois do código e antes do aceite, a selfie regravada e
 * cifrada no disco só com o código certo, a localização nas colunas e as evidências na trilha.
 */
#[CoversClass(AssinarDocumentoUseCase::class)]
final class AssinarReforcadaUseCaseTest extends TestCase
{
    use OficinaDosUseCasesDaAssinatura;

    private const CODIGO = '123456';
    private const CHAVE_DA_SELFIE = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';

    private CofreDaSelfie $cofre;

    protected function setUp(): void
    {
        $this->montarOficina();
        $this->cofre = new CofreDaSelfie(self::CHAVE_DA_SELFIE);
    }

    #[TestDox('Reforçada completa: selfie cifrada no disco, localização nas colunas, método "reforcada" e as evidências na trilha')]
    public function testAssinaReforcada(): void
    {
        $ana = $this->anaReforcada();

        $publico = $this->assinar()->executar($this->chave->token($ana), self::entrada(), self::evidencia());

        self::assertSame('ja_assinado', $publico['estado']);
        self::assertSame(SituacaoDoSignatario::Assinado, $ana->getSituacao());
        self::assertSame('reforcada', $ana->getMetodo());
        self::assertSame(['-15.79388060', '-47.88277630', '25.00'], [$ana->getLatitude(), $ana->getLongitude(), $ana->getPrecisao()]);
        self::assertSame(CofreDaSelfie::VERSAO, $ana->getSelfieVersao());
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.cifrado$/', (string) $ana->getSelfieChave());

        $arquivo = $this->disco->ler(self::chaveDoDisco((string) $ana->getSelfieChave()));
        self::assertStringStartsWith('BJSELFIE', $arquivo);
        $jpeg = $this->cofre->decifrar($arquivo, $ana);
        self::assertStringStartsWith("\xFF\xD8", $jpeg);
        self::assertSame(hash('sha256', $jpeg), $ana->getSelfieSha256());

        $dados = $this->ultimoGravado(TipoDeEvento::Assinado)->getDados();
        self::assertSame('reforcada', $dados['metodo']);
        self::assertSame($ana->getSelfieSha256(), $dados['selfie_sha256']);
        self::assertSame(CofreDaSelfie::VERSAO, $dados['selfie_versao']);
        self::assertArrayNotHasKey('selfie_chave', $dados, 'a versão não se confunde com a coluna do arquivo');
        self::assertSame(['-15.79388060', '-47.88277630', '25.00'], [$dados['latitude'], $dados['longitude'], $dados['precisao']]);
        self::assertStringNotContainsString('BJSELFIE', json_encode($dados, \JSON_THROW_ON_ERROR), 'nem o arquivo nem a imagem vão para a trilha');
    }

    #[TestDox('sem a chave da selfie: recusa antes de qualquer conferência, sem gravar nada')]
    public function testSemChave(): void
    {
        $ana = $this->anaReforcada();
        $this->cofre = new CofreDaSelfie('');
        $this->documentos->expects(self::never())->method('emTransacaoComArquivoNovo');

        $this->expectExceptionObject(new AcaoRecusada('assinatura_eletronica.erro.reforcada_sem_chave'));
        $this->assinar()->executar($this->chave->token($ana), EntradaDaAssinatura::deJson([]), self::evidencia());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function formulariosRecusados(): iterable
    {
        yield 'sem selfie' => [['selfie' => ''], 'assinatura_eletronica.erro.assinar_selfie'];
        yield 'sem selfie e sem aceite: a selfie vem antes do aceite' => [['selfie' => '', 'aceite' => false], 'assinatura_eletronica.erro.assinar_selfie'];
        yield 'sem código pedido e sem selfie: o código vem antes' => [['selfie' => '', 'codigo' => ''], 'assinatura_eletronica.erro.assinar_pedir_codigo'];
        yield 'imagem que não é JPEG nem PNG' => [['selfie' => 'data:image/jpeg;base64,' . base64_encode('texto')], 'assinatura_eletronica.erro.selfie_invalida'];
        yield 'sem localização' => [['localizacao' => null], 'assinatura_eletronica.erro.assinar_localizacao'];
        yield 'sem localização e sem aceite: a localização vem antes do aceite' => [['localizacao' => null, 'aceite' => false], 'assinatura_eletronica.erro.assinar_localizacao'];
        yield 'localização fora da faixa' => [['localizacao' => ['latitude' => 120, 'longitude' => 0, 'precisao' => 1]], 'assinatura_eletronica.erro.localizacao_invalida'];
        yield 'sem aceite' => [['aceite' => false], 'assinatura_eletronica.erro.assinar_aceite'];
    }

    /** @param array<string, mixed> $troca */
    #[DataProvider('formulariosRecusados')]
    #[TestDox('recusa antes da transação: $_dataName')]
    public function testFormularioRecusado(array $troca, string $erro): void
    {
        $ana = $this->anaReforcada();
        $this->documentos->expects(self::never())->method('emTransacaoComArquivoNovo');

        $this->expectExceptionObject(new AcaoRecusada($erro));
        $this->assinar()->executar($this->chave->token($ana), self::entrada($troca), self::evidencia());
    }

    #[TestDox('código errado: conta a tentativa e não grava selfie nem localização')]
    public function testCodigoErradoNaoGravaSelfie(): void
    {
        $ana = $this->anaReforcada();

        try {
            $this->assinar()->executar($this->chave->token($ana), self::entrada(['codigo' => '654321']), self::evidencia());
            self::fail('deveria recusar');
        } catch (AcaoRecusada $e) {
            self::assertSame('assinatura_eletronica.erro.codigo_errado', $e->getMessage());
        }

        self::assertSame([], $this->disco->gravadas);
        self::assertSame(['codigo_errado'], $this->tiposGravados());
        self::assertSame(SituacaoDoSignatario::Pendente, $ana->getSituacao());
        self::assertNull($ana->getSelfieChave());
        self::assertNull($ana->getLatitude());
    }

    #[TestDox('flush que falha depois de gravar: a transação REAL tira do disco a selfie cifrada e a rubrica, e a falha sobe')]
    public function testFalhaDepoisDeGravarTiraASelfieDoDisco(): void
    {
        $ana = $this->anaReforcada();
        $recusa = new \RuntimeException('flush recusado');
        $consulta = new ConsultaDeDestinoFixa(DestinoDaTransacao::NaoConfirmada);
        $this->transacaoReal = new TransacaoComArquivoNovo($this->emQueFalhaNoFlush($recusa), $consulta, new RemocaoAposTransacao($this->disco, $this->logger), $this->logger);

        try {
            $this->assinar()->executar($this->chave->token($ana), self::entrada(), self::evidencia());
            self::fail('a falha do flush deveria subir');
        } catch (\RuntimeException $e) {
            self::assertSame($recusa, $e);
        }

        $selfie = array_values(array_filter($this->disco->gravadas, static fn (ChaveDeArquivo $c): bool => str_ends_with($c->nome, '.cifrado')));
        self::assertCount(1, $selfie, 'a selfie cifrada chegou ao disco antes do flush');
        self::assertCount(2, $this->disco->gravadas, 'rubrica e selfie');
        foreach ($this->disco->gravadas as $chave) {
            self::assertFalse($this->disco->existe($chave), 'não fica órfão no disco: ' . $chave->nome);
        }
    }

    #[TestDox('quem é Simples e manda selfie e localização: nada disso é gravado e a trilha fica a do Simples')]
    public function testSimplesIgnoraSelfie(): void
    {
        $ana = $this->documentoEnviado()->getSignatarios()[0];
        $ana->registrarCodigo($this->chave->hashDoCodigo($ana, self::CODIGO), new \DateTimeImmutable('2026-11-09 09:55:00'));

        $this->assinar()->executar($this->chave->token($ana), self::entrada(), self::evidencia());

        self::assertSame('simples', $ana->getMetodo());
        self::assertNull($ana->getSelfieChave());
        self::assertNull($ana->getLatitude());
        self::assertCount(1, $this->disco->gravadas, 'só a rubrica');
        $chaves = array_keys($this->ultimoGravado(TipoDeEvento::Assinado)->getDados());
        sort($chaves);
        self::assertSame(['aceite', 'chave', 'cpf_confere', 'metodo', 'modo', 'porta', 'rubrica_sha256'], $chaves);
    }

    /** Ana Reforçada, documento enviado, com o código de teste pedido há 5 minutos. */
    private function anaReforcada(): Signatario
    {
        $ana = $this->documentoEnviado()->getSignatarios()[0];
        (new \ReflectionProperty(Signatario::class, 'nivel'))->setValue($ana, NivelDeAssinatura::Reforcada);
        $ana->registrarCodigo($this->chave->hashDoCodigo($ana, self::CODIGO), new \DateTimeImmutable('2026-11-09 09:55:00'));

        return $ana;
    }

    /** @param array<string, mixed> $troca */
    private static function entrada(array $troca = []): EntradaDaAssinatura
    {
        return EntradaDaAssinatura::deJson(array_merge([
            'nome' => 'Ana Maria Lima',
            'cpf' => self::CPF_ANA,
            'codigo' => self::CODIGO,
            'modo' => 'desenhada',
            'rubrica' => 'data:image/png;base64,' . self::PNG_1X1,
            'selfie' => 'data:image/jpeg;base64,' . base64_encode(self::jpeg()),
            'localizacao' => ['latitude' => -15.7938806, 'longitude' => -47.8827763, 'precisao' => 25],
            'aceite' => true,
        ], $troca));
    }

    private static function jpeg(): string
    {
        $imagem = imagecreatetruecolor(320, 240);
        imagefilledrectangle($imagem, 0, 0, 319, 239, (int) imagecolorallocate($imagem, 200, 120, 40));
        ob_start();
        imagejpeg($imagem, null, 90);

        return (string) ob_get_clean();
    }

    /** EntityManager e conexão como a transação real os usa (nível, BEGIN, ROLLBACK); o `flush` falha. */
    private function emQueFalhaNoFlush(\Throwable $falha): EntityManagerInterface
    {
        $nivel = 0;
        $conexao = $this->createStub(Connection::class);
        $conexao->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$nivel): int {
            return $nivel;
        });
        $conexao->method('beginTransaction')->willReturnCallback(static function () use (&$nivel): void {
            ++$nivel;
        });
        $conexao->method('rollBack')->willReturnCallback(static function () use (&$nivel): void {
            --$nivel;
        });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conexao);
        $em->method('flush')->willThrowException($falha);

        return $em;
    }

    private static function chaveDoDisco(string $nome): ChaveDeArquivo
    {
        return new ChaveDeArquivo(EscopoDeArquivo::deTenant(9), CategoriaDeArquivo::ASSINATURA_DOCUMENTO, $nome);
    }

    private function assinar(): AssinarDocumentoUseCase
    {
        return new AssinarDocumentoUseCase(
            $this->link(),
            $this->documentos,
            $this->eventosDaTrilha,
            $this->chave,
            $this->cofre,
            $this->trilha(),
            $this->disco,
            $this->correio(),
            $this->apresentacao(),
            $this->pagina(),
            new IdentityTranslator(),
            $this->relogio,
            $this->barramento(),
            $this->logger,
        );
    }
}
