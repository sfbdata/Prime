<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\DTO\DadosDoRascunho;
use App\AssinaturaEletronica\DTO\LocalizacaoDoSignatario;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\EventoDaTrilha;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Pdf\CartaoDoManifesto;
use App\AssinaturaEletronica\Pdf\SituacaoNoManifesto;
use App\AssinaturaEletronica\Repository\VersaoDoPdfRepository;
use App\AssinaturaEletronica\Service\ApresentacaoDaConferencia;
use App\AssinaturaEletronica\Service\ApresentacaoDoDocumento;
use App\AssinaturaEletronica\Service\ManifestoDoDocumento;
use App\AssinaturaEletronica\Service\TextoDoEvento;
use App\Tests\AssinaturaEletronica\Support\OficinaDosUseCasesDaAssinatura;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Package;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;

/**
 * Os textos do manifesto (A2, dc:417-420) com o catálogo de verdade: nome digitado, CPF e e-mail mascarados, "Tipo:"
 * com o método (a localização da Reforçada no texto, E3-13), a data da recusa tirada da trilha, "não informado" no
 * lugar do traço do desenho (E3-16) e a trilha do mais antigo ao mais novo, cada texto cortado em 96 caracteres.
 */
#[CoversClass(ManifestoDoDocumento::class)]
#[CoversClass(TextoDoEvento::class)]
final class ManifestoDoDocumentoTest extends TestCase
{
    use OficinaDosUseCasesDaAssinatura;

    private const MOTIVO = 'Os dados do contrato estão errados e a cláusula de foro precisa ser revista antes de qualquer assinatura.';

    protected function setUp(): void
    {
        $this->montarOficina();
    }

    #[TestDox('cabeçalho, trilha e nota legal do catálogo; um cartão por signatário, na ordem do documento')]
    public function testCabecalho(): void
    {
        $manifesto = $this->manifesto()->de($this->quatroSignatarios());

        self::assertSame('MANIFESTO DE ASSINATURAS', $manifesto->titulo);
        self::assertSame('Procuração', $manifesto->documento);
        self::assertSame('TRILHA DE EVENTOS', $manifesto->tituloDaTrilha);
        self::assertSame('Classificação das assinaturas conforme a Lei nº 14.063/2020. Este manifesto é parte integrante do documento.', $manifesto->notaLegal);
        self::assertSame(['Ana Maria Lima', 'Bia Souza', 'Caio Reis', 'Duda Melo'], array_map(static fn (CartaoDoManifesto $c): string => $c->nome, $manifesto->cartoes));
        self::assertSame([0, 1, 2, 3], array_map(static fn (CartaoDoManifesto $c): int => $c->indice, $manifesto->cartoes));
    }

    #[TestDox('quem assinou Simples: nome e CPF digitados, CPF ***.982.247-**, e-mail an***@, método e data da assinatura')]
    public function testAssinouSimples(): void
    {
        $ana = $this->manifesto()->de($this->quatroSignatarios())->cartoes[0];

        self::assertSame('Outorgante · CPF ***.982.247-** · an***@exemplo.com', $ana->dados);
        self::assertSame('Tipo: Eletrônica simples · E-mail e código', $ana->tipo);
        self::assertSame(SituacaoNoManifesto::Assinado, $ana->situacao);
        self::assertSame('Assinado em 09/11/2026, 09:30:00', $ana->textoDaSituacao);
        self::assertSame('IP e dispositivo registrados na trilha do servidor.', $ana->nota);
    }

    #[TestDox('Reforçada: a localização vai no texto do método (sem selfie); sem localização, o 3º texto do desenho')]
    public function testReforcada(): void
    {
        $cartoes = $this->manifesto()->de($this->quatroSignatarios())->cartoes;

        self::assertSame('Tipo: Eletrônica com múltiplos fatores · E-mail e código, selfie, localização -23.55052, -46.63331', $cartoes[1]->tipo);
        self::assertSame('Tipo: Eletrônica com múltiplos fatores · E-mail e código, selfie', $cartoes[2]->tipo);
    }

    #[TestDox('recusa: a data vem do evento "recusado" do próprio signatário na trilha, com o motivo')]
    public function testRecusa(): void
    {
        $duda = $this->manifesto()->de($this->quatroSignatarios())->cartoes[3];

        self::assertSame(SituacaoNoManifesto::Recusado, $duda->situacao);
        self::assertSame('Recusou em 09/11/2026, 09:50:00: ' . self::MOTIVO, $duda->textoDaSituacao);
        self::assertSame('Tipo: Eletrônica simples', $duda->tipo, 'quem recusou não tem método');
    }

    #[TestDox('recusa sem o evento na trilha: a data vira "não informado"')]
    public function testRecusaSemEvento(): void
    {
        $documento = $this->quatroSignatarios();
        $this->gravados = [];

        self::assertSame('Recusou em não informado: ' . self::MOTIVO, $this->manifesto()->de($documento)->cartoes[3]->textoDaSituacao);
    }

    #[TestDox('dado ausente (E3-16): sem CPF e sem e-mail, "não informado"; pendente sem método')]
    public function testNaoInformado(): void
    {
        $documento = $this->documento([['nome' => 'Eva Rocha', 'papel' => 'Testemunha']]);

        $eva = $this->manifesto()->de($documento)->cartoes[0];

        self::assertSame('Testemunha · CPF não informado · não informado', $eva->dados);
        self::assertSame('Tipo: Eletrônica simples', $eva->tipo);
        self::assertSame(SituacaoNoManifesto::Pendente, $eva->situacao);
        self::assertSame('Assinatura pendente', $eva->textoDaSituacao);
    }

    #[TestDox('trilha: do mais antigo ao mais novo, "data  texto", o texto cortado em 96 caracteres')]
    public function testTrilha(): void
    {
        $eventos = $this->manifesto()->de($this->quatroSignatarios())->eventos;

        self::assertSame('09/11/2026, 09:00:00  Enviado para assinatura. Hash 0123456789abcdef...', $eventos[0]);
        self::assertSame('09/11/2026, 09:30:00  Assinado por Ana Maria Lima · E-mail e código', $eventos[1], 'o nome digitado ao assinar');
        $recusa = mb_substr('Recusado por Duda Melo: ' . self::MOTIVO, 0, 96);
        self::assertSame('09/11/2026, 09:50:00  ' . $recusa, $eventos[2]);
        self::assertSame(96, mb_strlen($recusa));
        self::assertCount(3, $eventos);
    }

    #[TestDox('o texto de cada evento é o mesmo do detalhe da tela (uma regra só, TextoDoEvento), cortado em 96 no PDF')]
    public function testMesmoTextoDaTela(): void
    {
        $documento = $this->quatroSignatarios();
        $tela = new ApresentacaoDoDocumento(self::urls(), self::tradutorDaAssinatura(), new Packages(new Package(new EmptyVersionStrategy())), $this->chave, $this->createStub(VersaoDoPdfRepository::class), new ApresentacaoDaConferencia(self::tradutorDaAssinatura()));

        $daTela = array_column(array_reverse($tela->detalhe($documento, $this->eventosDaTrilha->doDocumento($documento))['eventos']), 'texto');
        $doPdf = array_map(static fn (string $linha): string => substr($linha, \strlen('09/11/2026, 09:00:00  ')), $this->manifesto()->de($documento)->eventos);

        self::assertSame(array_map(static fn (string $texto): string => mb_substr($texto, 0, 96), $daTela), $doPdf);
        self::assertStringNotContainsString('{', implode(' ', $doPdf), 'nenhum parâmetro cru');
    }

    private function manifesto(): ManifestoDoDocumento
    {
        return new ManifestoDoDocumento(self::tradutorDaAssinatura(), $this->eventosDaTrilha);
    }

    /**
     * Ana assinou Simples; Bia, Reforçada com localização; Caio, Reforçada sem localização; Duda recusou. Na trilha: o
     * envio, a assinatura da Ana e a recusa da Duda.
     */
    private function quatroSignatarios(): DocumentoParaAssinar
    {
        $documento = $this->documento([
            ['nome' => 'Ana Lima', 'email' => 'ana@exemplo.com', 'cpf' => self::CPF_ANA, 'papel' => 'Outorgante'],
            ['nome' => 'Bia Souza', 'email' => 'bia@exemplo.com', 'papel' => 'Outorgada', 'nivel' => 'reforcada'],
            ['nome' => 'Caio Reis', 'email' => 'caio@exemplo.com', 'papel' => 'Testemunha', 'nivel' => 'reforcada'],
            ['nome' => 'Duda Melo', 'email' => 'duda@exemplo.com', 'papel' => 'Testemunha'],
        ]);
        $documento->enviar(str_repeat('f', 64), new \DateTimeImmutable('2026-11-09 09:00:00'));
        [$ana, $bia, $caio, $duda] = $documento->getSignatarios();

        $this->assinou($documento, $ana, 'Ana Maria Lima', 'simples', '09:30');
        $bia->registrarReforco('selfie.cifrado', str_repeat('c', 64), 1, LocalizacaoDoSignatario::deJson(['latitude' => -23.550520, 'longitude' => -46.633308, 'precisao' => 12]));
        $this->assinou($documento, $bia, 'Bia Souza', 'reforcada', '09:35');
        $this->assinou($documento, $caio, 'Caio Reis', 'reforcada', '09:40');
        $documento->recusar($duda, self::MOTIVO, null, null, new \DateTimeImmutable('2026-11-09 09:50:00'));

        $this->gravados = [
            new EventoDaTrilha($documento, TipoDeEvento::DocumentoEnviado, ['hash' => str_repeat('0123456789abcdef', 4)], new \DateTimeImmutable('2026-11-09 09:00:00'), null),
            new EventoDaTrilha($documento, TipoDeEvento::Assinado, ['metodo' => 'simples'], new \DateTimeImmutable('2026-11-09 09:30:00'), null, null, $ana),
            new EventoDaTrilha($documento, TipoDeEvento::Recusado, ['motivo' => self::MOTIVO], new \DateTimeImmutable('2026-11-09 09:50:00'), null, null, $duda),
        ];

        return $documento;
    }

    private function assinou(DocumentoParaAssinar $documento, Signatario $signatario, string $nome, string $metodo, string $hora): void
    {
        $documento->assinar($signatario, $nome, $signatario->getCpf() ?? '11144477735', 'rubrica.png', $metodo, null, null, new \DateTimeImmutable('2026-11-09 ' . $hora . ':00'));
    }

    /** @param list<array<string, string>> $signatarios */
    private function documento(array $signatarios): DocumentoParaAssinar
    {
        $momento = new \DateTimeImmutable('2026-11-09 08:00:00');
        $documento = new DocumentoParaAssinar($this->tenant, self::usuario('criadora@escritorio.test', 'Carla Criadora'), $momento);
        $documento->aplicarRascunho(DadosDoRascunho::deJson(['nome' => 'Procuração', 'signatarios' => $signatarios]), [], $momento);
        (new \ReflectionProperty(DocumentoParaAssinar::class, 'id'))->setValue($documento, 1);
        foreach ($documento->getSignatarios() as $i => $signatario) {
            (new \ReflectionProperty(Signatario::class, 'id'))->setValue($signatario, 21 + $i);
        }

        return $documento;
    }
}
