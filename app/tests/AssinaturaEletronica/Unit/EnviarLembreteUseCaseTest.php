<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Service\ChaveDaAssinatura;
use App\AssinaturaEletronica\UseCase\EnviarLembreteUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\AssinaturaEletronica\Support\OficinaDosUseCasesDaAssinatura;
use App\Tests\Shared\Doubles\MailerQueGuarda;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O lembrete da lista (contrato da etapa 2, seção 5.1) com os repositórios do domínio dublados: ao primeiro pendente,
 * no máximo um por hora (contado pelos `lembrete_enviado`), e o `lembrete_enviado` só depois de o e-mail sair. Falhou
 * ou ficou sem link: só `email_nao_enviado`, 422, e a regra de 1 por hora não é gasta.
 */
#[CoversClass(EnviarLembreteUseCase::class)]
final class EnviarLembreteUseCaseTest extends TestCase
{
    use OficinaDosUseCasesDaAssinatura;

    private User $advogada;

    protected function setUp(): void
    {
        $this->montarOficina();
        $this->advogada = self::usuario('advogada@escritorio.test', 'Dra. Advogada');
    }

    #[TestDox('lembra o primeiro pendente com o link e o Reply-To de quem clicou; "lembrete_enviado" depois do envio')]
    public function testLembra(): void
    {
        [$ana, $bia] = $this->documentoEnviado()->getSignatarios();
        $ana->registrarAssinatura('Ana Maria Lima', self::CPF_ANA, 'r.png', 'simples', null, null, new \DateTimeImmutable('2026-11-09 09:30:00'));

        self::assertSame(['ok' => true, 'para' => 'Bia Souza'], $this->lembrar()->executar($this->advogada, $this->tenant, 1));

        $email = $this->unicoEmail();
        self::assertSame('bia@exemplo.com', $email->getTo()[0]->getAddress());
        self::assertStringContainsString('link=https://app.bluejus.test/assinar/' . $this->chave->token($bia), (string) $email->getHtmlBody());
        self::assertSame('advogada@escritorio.test', $email->getReplyTo()[0]->getAddress());
        self::assertSame(['lembrete_enviado'], $this->tiposGravados());
        $evento = $this->gravados[0];
        self::assertSame([$this->advogada, $bia], [$evento->getUsuario(), $evento->getSignatario()]);
        self::assertSame(['para' => 'b***@exemplo.com', 'chave' => 1], $evento->getDados());
    }

    #[TestDox('um lembrete na última hora: recusa sem mandar nada')]
    public function testRecente(): void
    {
        $this->documentoEnviado();
        $this->ultimoLembrete = new \DateTimeImmutable('2026-11-09 09:30:00');

        $this->expectExceptionObject(new AcaoRecusada('assinatura_eletronica.erro.lembrete_recente'));
        $this->lembrar()->executar($this->advogada, $this->tenant, 1);
    }

    #[TestDox('o último lembrete há mais de uma hora: lembra de novo')]
    public function testDepoisDeUmaHora(): void
    {
        $this->documentoEnviado();
        $this->ultimoLembrete = new \DateTimeImmutable('2026-11-09 08:59:00');

        $this->lembrar()->executar($this->advogada, $this->tenant, 1);

        self::assertCount(1, $this->mailer->enviados);
    }

    #[TestDox('e-mail fora do ar: 422, só "email_nao_enviado" (nenhum "lembrete_enviado", a regra de 1 por hora fica livre)')]
    public function testEmailForaDoAr(): void
    {
        $this->documentoEnviado();
        $this->mailer = new MailerQueGuarda(falhar: true);

        try {
            $this->lembrar()->executar($this->advogada, $this->tenant, 1);
            self::fail('deveria recusar');
        } catch (AcaoRecusada $e) {
            self::assertSame('assinatura_eletronica.erro.email_nao_enviado', $e->getMessage());
            self::assertSame(['nome' => 'Ana Lima'], $e->parametros);
        }
        self::assertSame(['email_nao_enviado'], $this->tiposGravados());
        self::assertSame($this->advogada, $this->ultimoGravado(TipoDeEvento::EmailNaoEnviado)->getUsuario());
    }

    #[TestDox('segredo trocado depois do envio: o lembrete não sai (sem link), recusa com o aviso de sem chave e grava "email_nao_enviado"')]
    public function testSegredoTrocado(): void
    {
        $ana = $this->documentoEnviado()->getSignatarios()[0];
        $tokenEnviado = $this->chave->token($ana);
        $this->trocarOSegredo();

        try {
            $this->lembrar()->executar($this->advogada, $this->tenant, 1);
            self::fail('deveria recusar');
        } catch (AcaoRecusada $e) {
            self::assertSame('assinatura_eletronica.erro.sem_chave', $e->getMessage());
        }
        self::assertSame([], $this->mailer->enviados);
        self::assertSame(['email_nao_enviado'], $this->tiposGravados());
        $this->assertLogSemToken($tokenEnviado, $this->chave->token($ana));
    }

    #[TestDox('fora do envio (rascunho): recusa')]
    public function testForaDoEnvio(): void
    {
        $this->rascunho();

        $this->expectExceptionObject(new AcaoRecusada('assinatura_eletronica.erro.lembrete_fora_do_envio'));
        $this->lembrar()->executar($this->advogada, $this->tenant, 1);
    }

    #[TestDox('prazo vencido: recusa')]
    public function testPrazoVencido(): void
    {
        $this->documentoEnviado();
        $this->relogio->modify('+30 days');

        $this->expectExceptionObject(new AcaoRecusada('assinatura_eletronica.erro.lembrete_fora_do_envio'));
        $this->lembrar()->executar($this->advogada, $this->tenant, 1);
    }

    #[TestDox('sem o segredo configurado: recusa antes de tudo; documento de outro escritório: 404')]
    public function testSemSegredoEOutroEscritorio(): void
    {
        $this->documentoEnviado();
        $semSegredo = new EnviarLembreteUseCase($this->documentos, $this->eventosDaTrilha, new ChaveDaAssinatura(''), $this->correio(), $this->apresentacao(), $this->relogio);

        try {
            $semSegredo->executar($this->advogada, $this->tenant, 1);
            self::fail('deveria recusar');
        } catch (AcaoRecusada $e) {
            self::assertSame('assinatura_eletronica.erro.sem_chave', $e->getMessage());
        }

        $this->expectException(DocumentoNaoEncontrado::class);
        $this->lembrar()->executar($this->advogada, new Tenant(), 1);
    }

    private function lembrar(): EnviarLembreteUseCase
    {
        return new EnviarLembreteUseCase($this->documentos, $this->eventosDaTrilha, $this->chave, $this->correio(), $this->apresentacao(), $this->relogio);
    }
}
