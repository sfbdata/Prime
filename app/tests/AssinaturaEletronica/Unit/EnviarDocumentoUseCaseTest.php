<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\DTO\CartaDaAssinatura;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\NivelDeAssinatura;
use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Service\Cadeia\ResultadoDaCadeia;
use App\AssinaturaEletronica\Service\Cadeia\ValidadorDeCadeia;
use App\AssinaturaEletronica\Service\Cadeia\ValidadorDeCadeiaNaoConfigurado;
use App\AssinaturaEletronica\Service\ChaveDaAssinatura;
use App\AssinaturaEletronica\Service\ChaveDoSelo;
use App\AssinaturaEletronica\Service\CofreDaSelfie;
use App\AssinaturaEletronica\Service\ConferenciaDoEnvio;
use App\AssinaturaEletronica\Service\ConteudoCanonicoDoDocumento;
use App\AssinaturaEletronica\Service\CorreioDaAssinatura;
use App\AssinaturaEletronica\UseCase\EnviarDocumentoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\AssinaturaEletronica\Support\OficinaDosUseCasesDaAssinatura;
use App\Tests\Shared\Doubles\MailerQueGuarda;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * "Gerar links e enviar" (contrato da etapa 2, seções 3, 4.1 e 6) com os repositórios do domínio dublados: a trava e a
 * releitura, a conferência dos signatários, o sha256 do token de cada um, o convite depois do COMMIT e as falhas do
 * e-mail como evento, sem desfazer o envio. Etapa 3: o código de validação do selo, gerado no envio depois do hash, com
 * a colisão (outra tentativa) e a recusa sem o segredo do selo. Etapa 5 (E5-2): gov.br e ICP só com o validador de
 * cadeia configurado.
 */
#[CoversClass(EnviarDocumentoUseCase::class)]
#[CoversClass(CorreioDaAssinatura::class)]
#[CoversClass(CartaDaAssinatura::class)]
#[CoversClass(ConferenciaDoEnvio::class)]
final class EnviarDocumentoUseCaseTest extends TestCase
{
    use OficinaDosUseCasesDaAssinatura;

    private User $advogada;
    private CofreDaSelfie $cofre;
    private ChaveDoSelo $selo;
    private ValidadorDeCadeia $cadeia;

    protected function setUp(): void
    {
        $this->montarOficina();
        $this->advogada = self::usuario('advogada@escritorio.test', 'Dra. Advogada');
        $this->cofre = new CofreDaSelfie(str_repeat('ab', 32));
        $this->selo = new ChaveDoSelo('selo-de-teste-com-32-bytes-ou-mais-0123456');
        $this->cadeia = new ValidadorDeCadeiaNaoConfigurado();
    }

    #[TestDox('envia: aguardando, sha256 do token de cada signatário guardado e um convite com o link a cada um, Reply-To de quem enviou')]
    public function testEnvia(): void
    {
        $documento = $this->rascunho();

        $detalhe = $this->enviarDocumento()->executar($this->advogada, $this->tenant, 1);

        self::assertSame(SituacaoDoDocumento::Aguardando, $documento->getSituacao());
        self::assertSame(['documento_enviado', 'convite_enviado', 'convite_enviado'], $this->tiposGravados());
        self::assertSame($this->advogada, $this->ultimoGravado(TipoDeEvento::DocumentoEnviado)->getUsuario());
        self::assertCount(2, $this->mailer->enviados);
        foreach ($documento->getSignatarios() as $i => $signatario) {
            $token = $this->chave->token($signatario);
            self::assertSame(hash('sha256', $token), $signatario->getTokenHash());
            self::assertSame('https://app.bluejus.test/assinar/' . $token, $detalhe['signatarios'][$i]['link']);
            $email = $this->mailer->enviados[$i];
            self::assertSame((string) $signatario->getEmail(), $email->getTo()[0]->getAddress());
            self::assertStringContainsString('link=https://app.bluejus.test/assinar/' . $token, (string) $email->getHtmlBody());
            self::assertSame('advogada@escritorio.test', $email->getReplyTo()[0]->getAddress());
            self::assertSame("Silva & D'Ávila Advogados via BlueJus", $email->getFrom()[0]->getName());
        }
        self::assertSame(['para' => 'a***@exemplo.com', 'chave' => 1], $this->gravados[1]->getDados());
    }

    #[TestDox('com "em ordem", o convite vai só ao primeiro')]
    public function testEmOrdem(): void
    {
        $this->rascunho(emOrdem: true);

        $this->enviarDocumento()->executar($this->advogada, $this->tenant, 1);

        self::assertSame('ana@exemplo.com', $this->unicoEmail()->getTo()[0]->getAddress());
        self::assertSame(['documento_enviado', 'convite_enviado'], $this->tiposGravados());
    }

    #[TestDox('e-mail fora do ar: o envio fica, cada convite vira "email_nao_enviado" e o log não leva o link')]
    public function testEmailForaDoAr(): void
    {
        $documento = $this->rascunho();
        $this->mailer = new MailerQueGuarda(falhar: true);

        $this->enviarDocumento()->executar($this->advogada, $this->tenant, 1);

        self::assertSame(SituacaoDoDocumento::Aguardando, $documento->getSituacao());
        self::assertSame(['documento_enviado', 'email_nao_enviado', 'email_nao_enviado'], $this->tiposGravados());
        self::assertSame('falha_no_envio', $this->ultimoGravado(TipoDeEvento::EmailNaoEnviado)->getDados()['motivo']);
        self::assertCount(2, $this->logger->doNivel('error'));
        $this->assertLogSemToken(...array_map($this->chave->token(...), $documento->getSignatarios()));
    }

    #[TestDox('sem o segredo configurado: recusa antes de abrir a transação')]
    public function testSemSegredo(): void
    {
        $this->rascunho();
        $this->chave = new ChaveDaAssinatura('');
        $this->documentos->expects(self::never())->method('emTransacao');

        $this->expectExceptionObject(new AcaoRecusada('assinatura_eletronica.erro.sem_chave'));
        $this->enviarDocumento()->executar($this->advogada, $this->tenant, 1);
    }

    #[TestDox('etapa 3: o código de validação sai do id e do hash enviado, no formato BJA, e vai para o detalhe')]
    public function testGeraOCodigo(): void
    {
        $documento = $this->rascunho();

        $detalhe = $this->enviarDocumento()->executar($this->advogada, $this->tenant, 1);

        $esperado = $this->selo->codigo(1, (string) $documento->getHashEnviado());
        self::assertMatchesRegularExpression('/^BJA-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $esperado);
        self::assertSame($esperado, $documento->getCodigo());
        self::assertSame($esperado, $detalhe['codigo']);
    }

    #[TestDox('etapa 3: código já usado por outro documento, vai a tentativa seguinte')]
    public function testCodigoEmUsoTentaOutro(): void
    {
        $documento = $this->rascunho();
        $consultados = [];
        $this->documentos->method('codigoEmUso')->willReturnCallback(static function (string $codigo) use (&$consultados): bool {
            $consultados[] = $codigo;

            return \count($consultados) === 1;
        });

        $this->enviarDocumento()->executar($this->advogada, $this->tenant, 1);

        $hash = (string) $documento->getHashEnviado();
        self::assertSame([$this->selo->codigo(1, $hash, 0), $this->selo->codigo(1, $hash, 1)], $consultados);
        self::assertSame($this->selo->codigo(1, $hash, 1), $documento->getCodigo());
    }

    #[TestDox('etapa 3: nenhuma tentativa livre, o envio falha sem código')]
    public function testSemCodigoLivre(): void
    {
        $documento = $this->rascunho();
        $this->documentos->expects(self::exactly(EnviarDocumentoUseCase::TENTATIVAS_DO_CODIGO))->method('codigoEmUso')->willReturn(true);

        try {
            $this->enviarDocumento()->executar($this->advogada, $this->tenant, 1);
            self::fail('Esperava a falha do código.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Nenhum código de validação livre', $e->getMessage());
        }
        self::assertNull($documento->getCodigo());
        self::assertSame([], $this->mailer->enviados);
    }

    #[TestDox('etapa 3: sem o segredo do selo, recusa antes de abrir a transação')]
    public function testSemSegredoDoSelo(): void
    {
        $documento = $this->rascunho();
        $this->selo = new ChaveDoSelo('curto');
        $this->documentos->expects(self::never())->method('emTransacao');

        try {
            $this->enviarDocumento()->executar($this->advogada, $this->tenant, 1);
            self::fail('Esperava a recusa.');
        } catch (AcaoRecusada $e) {
            self::assertSame('assinatura_eletronica.erro.selo_sem_chave', $e->getMessage());
        }
        self::assertSame(SituacaoDoDocumento::Rascunho, $documento->getSituacao());
    }

    #[TestDox('documento de outro escritório: 404 e nada gravado')]
    public function testOutroEscritorio(): void
    {
        $this->rascunho();
        $this->documentos->expects(self::never())->method('emTransacao');

        $this->expectException(DocumentoNaoEncontrado::class);
        $this->enviarDocumento()->executar($this->advogada, new Tenant(), 1);
    }

    #[TestDox('documento que sumiu entre a leitura e a trava: 404, sem e-mail')]
    public function testSumiuNaTrava(): void
    {
        $this->rascunho();
        $this->documentoSumiu = true;

        try {
            $this->enviarDocumento()->executar($this->advogada, $this->tenant, 1);
            self::fail('deveria ser 404');
        } catch (DocumentoNaoEncontrado) {
            self::assertSame([], $this->mailer->enviados);
            self::assertSame([], $this->gravados);
        }
    }

    #[TestDox('decide com a linha relida: o que outra aba já enviou não é enviado de novo')]
    public function testDecideComALinhaRelida(): void
    {
        $this->rascunho();
        $this->aoTravar = static function (DocumentoParaAssinar $d): void {
            (new \ReflectionProperty($d, 'situacao'))->setValue($d, SituacaoDoDocumento::Aguardando);
        };

        $this->expectExceptionObject(new AcaoRecusada('assinatura_eletronica.erro.envio_ja_feito'));
        $this->enviarDocumento()->executar($this->advogada, $this->tenant, 1);
    }

    #[TestDox('signatário Simples sem e-mail: recusa com o nome dele e o documento continua rascunho')]
    public function testSemEmail(): void
    {
        $documento = $this->rascunho();
        (new \ReflectionProperty($documento->getSignatarios()[1], 'email'))->setValue($documento->getSignatarios()[1], null);

        try {
            $this->enviarDocumento()->executar($this->advogada, $this->tenant, 1);
            self::fail('deveria recusar');
        } catch (AcaoRecusada $e) {
            self::assertSame('assinatura_eletronica.erro.envio_email', $e->getMessage());
            self::assertSame(['nome' => 'Bia Souza'], $e->parametros);
        }
        self::assertSame(SituacaoDoDocumento::Rascunho, $documento->getSituacao());
        self::assertSame([], $this->mailer->enviados);
    }

    #[TestDox('Reforçada (etapa 7): o envio aceita, com a chave da selfie configurada')]
    public function testReforcadaAceita(): void
    {
        $documento = $this->rascunho();
        self::reforcada($documento->getSignatarios()[0]);

        $this->enviarDocumento()->executar($this->advogada, $this->tenant, 1);

        self::assertSame(SituacaoDoDocumento::Aguardando, $documento->getSituacao());
        self::assertCount(2, $this->mailer->enviados);
    }

    /** @return iterable<string, array{string}> */
    public static function papeisDeMenor(): iterable
    {
        yield 'Menor assistido(a)' => ['Menor assistido(a)'];
        yield 'Menor Representado ou Assistido' => ['Menor Representado ou Assistido'];
        yield 'MENOR em caixa alta' => ['MENOR'];
        yield 'Responsável pelo menor (falso positivo assumido, I8)' => ['Responsável pelo menor'];
        yield 'plural: Menores representados' => ['Menores representados'];
        yield 'Criança' => ['Criança'];
        yield 'plural: Crianças' => ['Crianças'];
        yield 'sem acento: crianca' => ['crianca'];
        yield 'CRIANÇA em caixa alta' => ['CRIANÇA'];
        yield 'Adolescente assistido' => ['Adolescente assistido'];
        yield 'plural: Adolescentes' => ['Adolescentes'];
    }

    #[DataProvider('papeisDeMenor')]
    #[TestDox('Reforçada com papel de menor ($_dataName): recusa com o nome e o documento continua rascunho')]
    public function testReforcadaDeMenor(string $papel): void
    {
        $documento = $this->rascunho();
        $bia = $documento->getSignatarios()[1];
        self::reforcada($bia);
        (new \ReflectionProperty(Signatario::class, 'papel'))->setValue($bia, $papel);

        try {
            $this->enviarDocumento()->executar($this->advogada, $this->tenant, 1);
            self::fail('deveria recusar');
        } catch (AcaoRecusada $e) {
            self::assertSame('assinatura_eletronica.erro.envio_reforcada_menor', $e->getMessage());
            self::assertSame(['nome' => 'Bia Souza'], $e->parametros);
        }
        self::assertSame(SituacaoDoDocumento::Rascunho, $documento->getSituacao());
        self::assertSame([], $this->mailer->enviados);
    }

    #[TestDox('papel de menor com assinatura Simples e palavra parecida ("Menorá"): o envio aceita')]
    public function testMenorSimples(): void
    {
        $documento = $this->rascunho();
        (new \ReflectionProperty(Signatario::class, 'papel'))->setValue($documento->getSignatarios()[0], 'Menor assistido(a)');
        self::reforcada($documento->getSignatarios()[1]);
        (new \ReflectionProperty(Signatario::class, 'papel'))->setValue($documento->getSignatarios()[1], 'Menorá');

        $this->enviarDocumento()->executar($this->advogada, $this->tenant, 1);

        self::assertSame(SituacaoDoDocumento::Aguardando, $documento->getSituacao());
    }

    #[TestDox('Reforçada sem a chave da selfie: recusa com o aviso de sem chave; só Simples, envia sem ela')]
    public function testReforcadaSemChave(): void
    {
        $this->cofre = new CofreDaSelfie('');
        $documento = $this->rascunho();
        self::reforcada($documento->getSignatarios()[1]);

        try {
            $this->enviarDocumento()->executar($this->advogada, $this->tenant, 1);
            self::fail('deveria recusar');
        } catch (AcaoRecusada $e) {
            self::assertSame('assinatura_eletronica.erro.reforcada_sem_chave', $e->getMessage());
        }
        self::assertSame(SituacaoDoDocumento::Rascunho, $documento->getSituacao());

        (new \ReflectionProperty(Signatario::class, 'nivel'))->setValue($documento->getSignatarios()[1], NivelDeAssinatura::Simples);
        $this->enviarDocumento()->executar($this->advogada, $this->tenant, 1);
        self::assertSame(SituacaoDoDocumento::Aguardando, $documento->getSituacao());
    }

    #[TestDox('Reforçada sem e-mail: recusa pelo e-mail (o código vai por e-mail, I6)')]
    public function testReforcadaSemEmail(): void
    {
        $documento = $this->rascunho();
        $bia = $documento->getSignatarios()[1];
        self::reforcada($bia);
        (new \ReflectionProperty(Signatario::class, 'email'))->setValue($bia, null);

        $this->expectExceptionObject(new AcaoRecusada('assinatura_eletronica.erro.envio_email', ['nome' => 'Bia Souza']));
        $this->enviarDocumento()->executar($this->advogada, $this->tenant, 1);
    }

    /** @return iterable<string, array{NivelDeAssinatura}> */
    public static function niveisComCertificado(): iterable
    {
        yield 'gov.br' => [NivelDeAssinatura::Govbr];
        yield 'ICP-Brasil' => [NivelDeAssinatura::Icp];
    }

    #[DataProvider('niveisComCertificado')]
    #[TestDox('$_dataName sem o validador de cadeia configurado (E5-2): recusa com o nome e o documento continua rascunho')]
    public function testCertificadoSemValidador(NivelDeAssinatura $nivel): void
    {
        $documento = $this->rascunho();
        (new \ReflectionProperty(Signatario::class, 'nivel'))->setValue($documento->getSignatarios()[1], $nivel);

        try {
            $this->enviarDocumento()->executar($this->advogada, $this->tenant, 1);
            self::fail('deveria recusar');
        } catch (AcaoRecusada $e) {
            self::assertSame('assinatura_eletronica.erro.certificado_sem_validador', $e->getMessage());
            self::assertSame(['nome' => 'Bia Souza'], $e->parametros);
        }
        self::assertSame(SituacaoDoDocumento::Rascunho, $documento->getSituacao());
        self::assertSame([], $this->mailer->enviados);
    }

    #[DataProvider('niveisComCertificado')]
    #[TestDox('$_dataName com o validador de cadeia configurado: o envio aceita')]
    public function testCertificadoComValidador(NivelDeAssinatura $nivel): void
    {
        $this->cadeia = new class () implements ValidadorDeCadeia {
            public function configurado(): bool
            {
                return true;
            }

            public function conferir(string $pdf, string $ancora): ResultadoDaCadeia
            {
                throw new \LogicException('O envio só pergunta se o validador está configurado.');
            }
        };
        $documento = $this->rascunho();
        (new \ReflectionProperty(Signatario::class, 'nivel'))->setValue($documento->getSignatarios()[1], $nivel);

        $this->enviarDocumento()->executar($this->advogada, $this->tenant, 1);

        self::assertSame(SituacaoDoDocumento::Aguardando, $documento->getSituacao());
        self::assertCount(2, $this->mailer->enviados);
    }

    private static function reforcada(Signatario $signatario): void
    {
        (new \ReflectionProperty(Signatario::class, 'nivel'))->setValue($signatario, NivelDeAssinatura::Reforcada);
    }

    private function enviarDocumento(): EnviarDocumentoUseCase
    {
        return new EnviarDocumentoUseCase(
            $this->documentos,
            $this->eventosDaTrilha,
            new ConteudoCanonicoDoDocumento($this->disco),
            $this->chave,
            $this->selo,
            $this->cofre,
            $this->trilha(),
            $this->correio(),
            $this->apresentacao(),
            $this->relogio,
            $this->cadeia,
        );
    }
}
