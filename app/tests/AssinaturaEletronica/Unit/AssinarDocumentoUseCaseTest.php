<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\DTO\EntradaDaAssinatura;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Enum\SituacaoDoSignatario;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\MuitasTentativas;
use App\AssinaturaEletronica\Message\GerarPdfFinalDoDocumento;
use App\AssinaturaEletronica\Service\CofreDaSelfie;
use App\AssinaturaEletronica\UseCase\AssinarDocumentoUseCase;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Tests\AssinaturaEletronica\Support\OficinaDosUseCasesDaAssinatura;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * "Assinar" da tela pública, nível Simples (contrato da etapa 2, seções 3, 4.1 e 5.2), com os repositórios do domínio
 * dublados: as conferências do formulário antes da transação, o código (certo, errado, vencido, teto), a rubrica no
 * disco, a conclusão e os e-mails depois do COMMIT (a vez com o Reply-To de quem enviou; sem link, não sai).
 */
#[CoversClass(AssinarDocumentoUseCase::class)]
final class AssinarDocumentoUseCaseTest extends TestCase
{
    use OficinaDosUseCasesDaAssinatura;

    private const CODIGO = '123456';

    protected function setUp(): void
    {
        $this->montarOficina();
        $this->quemEnviou = self::usuario('quem.enviou@escritorio.test', 'Dra. Remetente');
    }

    #[TestDox('código certo, em ordem: rubrica no disco, assinatura e evidência gravadas e a vez avisada ao próximo com o Reply-To de quem enviou')]
    public function testAssinaEAvisaOProximo(): void
    {
        [$ana, $bia] = $this->documentoEnviado(emOrdem: true)->getSignatarios();
        $this->comCodigo($ana);

        $publico = $this->assinar()->executar($this->chave->token($ana), self::entrada(), self::evidencia());

        self::assertSame('ja_assinado', $publico['estado']);
        self::assertSame(SituacaoDoSignatario::Assinado, $ana->getSituacao());
        self::assertSame('Ana Maria Lima', $ana->getNomeDigitado());
        self::assertSame(base64_decode(self::PNG_1X1, true), $this->disco->ler(new ChaveDeArquivo(EscopoDeArquivo::deTenant(9), CategoriaDeArquivo::ASSINATURA_DOCUMENTO, (string) $ana->getRubricaChave())));
        self::assertSame(['assinado', 'convite_enviado'], $this->tiposGravados());
        $dados = $this->ultimoGravado(TipoDeEvento::Assinado)->getDados();
        self::assertSame(['simples', 'desenhada', hash('sha256', (string) base64_decode(self::PNG_1X1, true)), true], [$dados['metodo'], $dados['modo'], $dados['rubrica_sha256'], $dados['cpf_confere']]);

        $vez = $this->unicoEmail();
        self::assertSame('bia@exemplo.com', $vez->getTo()[0]->getAddress());
        self::assertStringContainsString('link=https://app.bluejus.test/assinar/' . $this->chave->token($bia), (string) $vez->getHtmlBody());
        self::assertSame('quem.enviou@escritorio.test', $vez->getReplyTo()[0]->getAddress());
        self::assertSame([], $this->despachadas, 'sem conclusão, o PDF final não é pedido');
    }

    #[TestDox('o último a assinar conclui o documento, o remetente recebe a conclusão e o PDF final é pedido ao worker')]
    public function testUltimoConclui(): void
    {
        [$ana, $bia] = $this->documentoEnviado()->getSignatarios();
        $bia->registrarAssinatura('Bia Souza', '11144477735', 'r.png', 'simples', null, null, new \DateTimeImmutable('2026-11-09 09:30:00'));
        $this->comCodigo($ana);

        $publico = $this->assinar()->executar($this->chave->token($ana), self::entrada(), self::evidencia());

        self::assertSame('concluido', $publico['estado']);
        self::assertSame(SituacaoDoDocumento::Concluido, $ana->getDocumento()->getSituacao());
        self::assertSame(['assinado', 'documento_concluido'], $this->tiposGravados());
        self::assertSame('criadora@escritorio.test', $this->unicoEmail()->getTo()[0]->getAddress());
        self::assertEquals([new GerarPdfFinalDoDocumento(1, 9)], $this->despachadas, 'a conclusão pede o PDF final ao worker');
    }

    #[TestDox('fila fora do ar na conclusão: a assinatura vale, o remetente recebe a conclusão e a falha só vai para o log')]
    public function testFilaCaidaNaoDerrubaAConclusao(): void
    {
        [$ana, $bia] = $this->documentoEnviado()->getSignatarios();
        $bia->registrarAssinatura('Bia Souza', '11144477735', 'r.png', 'simples', null, null, new \DateTimeImmutable('2026-11-09 09:30:00'));
        $this->comCodigo($ana);
        $this->filaCaida = new \RuntimeException('transporte fora do ar');

        $publico = $this->assinar()->executar($this->chave->token($ana), self::entrada(), self::evidencia());

        self::assertSame('concluido', $publico['estado']);
        self::assertSame(SituacaoDoDocumento::Concluido, $ana->getDocumento()->getSituacao());
        self::assertSame('criadora@escritorio.test', $this->unicoEmail()->getTo()[0]->getAddress());
        $erros = $this->logger->doNivel('error');
        self::assertCount(1, $erros);
        self::assertSame(['documento' => 1, 'erro' => 'transporte fora do ar'], $erros[0]['contexto']);
    }

    #[TestDox('segredo trocado depois do envio: a assinatura vale, mas a vez do próximo não sai (sem link) e vira "email_nao_enviado"')]
    public function testVezSemLink(): void
    {
        [$ana, $bia] = $this->documentoEnviado(emOrdem: true)->getSignatarios();
        $token = $this->chave->token($ana);
        $tokenDaBia = $this->chave->token($bia);
        $this->trocarOSegredo();
        $this->comCodigo($ana);

        $this->assinar()->executar($token, self::entrada(), self::evidencia());

        self::assertSame(SituacaoDoSignatario::Assinado, $ana->getSituacao());
        self::assertSame([], $this->mailer->enviados);
        self::assertSame(['assinado', 'email_nao_enviado'], $this->tiposGravados());
        self::assertSame($bia, $this->ultimoGravado(TipoDeEvento::EmailNaoEnviado)->getSignatario());
        self::assertSame('link_indisponivel', $this->logger->doNivel('error')[0]['contexto']['motivo']);
        $this->assertLogSemToken($token, $tokenDaBia);
    }

    #[TestDox('código errado: conta a tentativa, grava "codigo_errado" e recusa depois, sem rubrica no disco')]
    public function testCodigoErrado(): void
    {
        $ana = $this->documentoEnviado()->getSignatarios()[0];
        $this->comCodigo($ana);

        try {
            $this->assinar()->executar($this->chave->token($ana), self::entrada(['codigo' => '654321']), self::evidencia());
            self::fail('deveria recusar');
        } catch (AcaoRecusada $e) {
            self::assertSame('assinatura_eletronica.erro.codigo_errado', $e->getMessage());
        }

        self::assertSame(1, $ana->getTentativas());
        self::assertSame(['codigo_errado'], $this->tiposGravados());
        self::assertSame(['tentativa' => 1, 'chave' => 1, 'porta' => 51234], $this->gravados[0]->getDados());
        self::assertSame(SituacaoDoSignatario::Pendente, $ana->getSituacao());
        self::assertSame([], $this->mailer->enviados);
    }

    #[TestDox('o 15º código errado em 24 h bloqueia até o fim da janela')]
    public function testTetoDeErros(): void
    {
        $ana = $this->documentoEnviado()->getSignatarios()[0];
        $this->comCodigo($ana);
        $this->contagens[TipoDeEvento::CodigoErrado->value] = [14, new \DateTimeImmutable('2026-11-09 01:00:00')];

        $this->expectException(MuitasTentativas::class);
        try {
            $this->assinar()->executar($this->chave->token($ana), self::entrada(['codigo' => '654321']), self::evidencia());
        } finally {
            self::assertSame('2026-11-10 01:00:00', $ana->getBloqueadoAte()?->format('Y-m-d H:i:s'));
        }
    }

    #[TestDox('código vencido (mais de 15 min): pede outro, nada gravado')]
    public function testCodigoVencido(): void
    {
        $ana = $this->documentoEnviado()->getSignatarios()[0];
        $this->comCodigo($ana, new \DateTimeImmutable('2026-11-09 09:44:00'));

        $this->expectExceptionObject(new AcaoRecusada('assinatura_eletronica.erro.codigo_vencido'));
        $this->assinar()->executar($this->chave->token($ana), self::entrada(), self::evidencia());
    }

    #[TestDox('CPF diferente do cadastrado: recusa antes da transação, sem gastar tentativa')]
    public function testCpfDiferente(): void
    {
        $ana = $this->documentoEnviado()->getSignatarios()[0];
        $this->comCodigo($ana);
        $this->documentos->expects(self::never())->method('emTransacaoComArquivoNovo');

        try {
            $this->assinar()->executar($this->chave->token($ana), self::entrada(['cpf' => '11144477735']), self::evidencia());
            self::fail('deveria recusar');
        } catch (AcaoRecusada $e) {
            self::assertSame('assinatura_eletronica.erro.assinar_cpf_cadastro', $e->getMessage());
        }
        self::assertSame(0, $ana->getTentativas());
    }

    #[TestDox('sem o aceite: recusa antes da transação')]
    public function testSemAceite(): void
    {
        $ana = $this->documentoEnviado()->getSignatarios()[0];
        $this->documentos->expects(self::never())->method('emTransacaoComArquivoNovo');

        $this->expectExceptionObject(new AcaoRecusada('assinatura_eletronica.erro.assinar_aceite'));
        $this->assinar()->executar($this->chave->token($ana), self::entrada(['aceite' => false]), self::evidencia());
    }

    /** O código de teste pedido na hora dada (padrão: há 5 minutos). */
    private function comCodigo(Signatario $signatario, ?\DateTimeImmutable $em = null): void
    {
        $signatario->registrarCodigo($this->chave->hashDoCodigo($signatario, self::CODIGO), $em ?? new \DateTimeImmutable('2026-11-09 09:55:00'));
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
            'aceite' => true,
        ], $troca));
    }

    private function assinar(): AssinarDocumentoUseCase
    {
        return new AssinarDocumentoUseCase(
            $this->link(),
            $this->documentos,
            $this->eventosDaTrilha,
            $this->chave,
            new CofreDaSelfie(''), // o Simples não precisa da chave da selfie
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
