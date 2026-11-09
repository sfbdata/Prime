<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\DTO\CartaDaAssinatura;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Service\ChaveDaAssinatura;
use App\AssinaturaEletronica\Service\ConteudoCanonicoDoDocumento;
use App\AssinaturaEletronica\Service\CorreioDaAssinatura;
use App\AssinaturaEletronica\UseCase\EnviarDocumentoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\AssinaturaEletronica\Support\OficinaDosUseCasesDaAssinatura;
use App\Tests\Shared\Doubles\MailerQueGuarda;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * "Gerar links e enviar" (contrato da etapa 2, seções 3, 4.1 e 6) com os repositórios do domínio dublados: a trava e a
 * releitura, a conferência dos signatários, o sha256 do token de cada um, o convite depois do COMMIT e as falhas do
 * e-mail como evento, sem desfazer o envio.
 */
#[CoversClass(EnviarDocumentoUseCase::class)]
#[CoversClass(CorreioDaAssinatura::class)]
#[CoversClass(CartaDaAssinatura::class)]
final class EnviarDocumentoUseCaseTest extends TestCase
{
    use OficinaDosUseCasesDaAssinatura;

    private User $advogada;

    protected function setUp(): void
    {
        $this->montarOficina();
        $this->advogada = self::usuario('advogada@escritorio.test', 'Dra. Advogada');
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

    private function enviarDocumento(): EnviarDocumentoUseCase
    {
        return new EnviarDocumentoUseCase(
            $this->documentos,
            $this->eventosDaTrilha,
            new ConteudoCanonicoDoDocumento($this->disco),
            $this->chave,
            $this->trilha(),
            $this->correio(),
            $this->apresentacao(),
            $this->relogio,
        );
    }
}
