<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Auth\Entity\CadastroPendente;
use App\Auth\Service\CadastroMailer;
use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\TipoDeEnvio;
use App\Comercial\EventListener\RegistroDeEmailSincronoListener;
use App\Comercial\Message\EnviarEmail;
use App\Comercial\MessageHandler\EnviarEmailHandler;
use App\Comercial\Service\RegistroDeEnvioExterno;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * A cota conta TODOS os e-mails (spec comercial-e-emails.md §3.6): o que sai direto pelo Mailer vira linha `sistema`
 * em `comercial_envio`; o do Carteiro e a confirmação do cadastro (`automacao:cad`) não duplicam, e o cabeçalho
 * interno não sai no e-mail. Transporte null do ambiente de teste (o SentMessageEvent dispara igual).
 */
#[CoversClass(RegistroDeEmailSincronoListener::class)]
#[CoversClass(RegistroDeEnvioExterno::class)]
final class RegistroDeEmailSincronoListenerTest extends KernelTestCase
{
    private Connection $conexao;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conexao = static::getContainer()->get(Connection::class);
    }

    #[TestDox('E-mail síncrono vira uma linha sistema, transacional, enviado, com o primeiro "Para" e o assunto')]
    public function testSincronoViraLinhaSistema(): void
    {
        $email = (new Email())->from('nao-responda@bluejus.test')->to('Pessoa.Um@Exemplo.com', 'segunda@exemplo.com')->subject('Redefinir senha')->html('<p>x</p>');

        static::getContainer()->get(MailerInterface::class)->send($email);

        self::assertEmailCount(1);
        self::assertSame(
            [['origem' => 'sistema', 'tipo' => 'transacional', 'situacao' => 'enviado', 'assunto' => 'Redefinir senha', 'com_data' => true]],
            $this->conexao->fetchAllAssociative(
                "SELECT origem, tipo, situacao, assunto, enviado_em IS NOT NULL AS com_data FROM comercial_envio WHERE destinatario = 'pessoa.um@exemplo.com'",
            ),
        );
        self::assertSame(0, $this->linhasPara('segunda@exemplo.com'), 'só o primeiro "Para"');
    }

    #[TestDox('Já registrado (com o cabeçalho interno): nenhuma linha a mais, e o cabeçalho não sai no e-mail')]
    public function testJaRegistradoNaoDuplicaENaoLevaOCabecalho(): void
    {
        $email = (new Email())->from('nao-responda@bluejus.test')->to('marcado@exemplo.com')->subject('x')->html('<p>x</p>');
        $email->getHeaders()->addTextHeader(RegistroDeEmailSincronoListener::CABECALHO, '1');

        static::getContainer()->get(MailerInterface::class)->send($email);

        self::assertEmailCount(1);
        self::assertSame(0, $this->linhasPara('marcado@exemplo.com'));
        self::assertEmailNotHasHeader(self::getMailerMessage() ?? self::fail('sem e-mail'), RegistroDeEmailSincronoListener::CABECALHO);
    }

    #[TestDox('O do Carteiro (EnviarEmailHandler) fica com a linha própria, sem a sistema')]
    public function testCarteiroNaoDuplica(): void
    {
        $c = static::getContainer();
        $id = $c->get(CarteiroInterface::class)->enviar(new MensagemDeEmail('carteiro@exemplo.com', 'Do carteiro', '<p>x</p>', TipoDeEnvio::Transacional, 'automacao:val'))->envioId;

        $c->get(EnviarEmailHandler::class)(new EnviarEmail($id));

        self::assertEmailCount(1);
        self::assertSame(1, $this->linhasPara('carteiro@exemplo.com'));
        self::assertSame('enviado', $this->conexao->fetchOne('SELECT situacao FROM comercial_envio WHERE id = ?', [$id]));
        self::assertEmailNotHasHeader(self::getMailerMessage() ?? self::fail('sem e-mail'), RegistroDeEmailSincronoListener::CABECALHO);
    }

    #[TestDox('A confirmação do cadastro fica só com a linha automacao:cad, sem a sistema')]
    public function testCadNaoDuplica(): void
    {
        $cadastro = new CadastroPendente(
            email: 'cadastro.cota@exemplo.com',
            token: str_repeat('b', 64),
            nomeCompleto: 'Pessoa Cadastro',
            nomeEscritorio: 'Escritório Cota',
            oabNumero: '12345',
            oabUf: 'SP',
            senhaHash: 'hash',
            ip: '127.0.0.1',
            expiresAt: new \DateTimeImmutable('+1 hour'),
        );

        static::getContainer()->get(CadastroMailer::class)->enviarConfirmacao($cadastro);

        self::assertEmailCount(1);
        self::assertSame(['automacao:cad'], $this->conexao->fetchFirstColumn("SELECT origem FROM comercial_envio WHERE destinatario = 'cadastro.cota@exemplo.com'"));
    }

    private function linhasPara(string $email): int
    {
        return (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM comercial_envio WHERE destinatario = ?', [$email]);
    }
}
