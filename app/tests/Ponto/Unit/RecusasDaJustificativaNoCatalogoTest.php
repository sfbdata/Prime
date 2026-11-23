<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\Exception\AnaliseDeJustificativaRecusadaException;
use App\Ponto\Service\AnaliseDaPropriaJustificativa;
use App\Ponto\Service\BatidaJaRegistrada;
use App\Ponto\Service\HorarioFuturoDaBatida;
use App\Ponto\Service\NomesDasSituacoesDaJustificativa;
use App\Ponto\Service\TextoDoPonto;
use App\Ponto\UseCase\AbonarJustificativasDaEquipeUseCase;
use App\Ponto\Validacao\RestricoesAnexoJustificativa;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * Spec idioma-do-sistema §33 (idioma-ponto-php, PR B2a): os textos de recusa da análise da justificativa, os nomes de
 * situação que o controller leva à tela, as duas mensagens do anexo e as recusas do abono em lote têm chave no catálogo
 * `ponto_servidor.recusa`; o pt_BR do catálogo é idêntico ao português da constante e o en existe e é outro texto. As
 * recusas das exceções `RecusaDoPonto` (ajuste de batida, justificativa já analisada, troca de anexo) ficam no
 * `RecusasDoPontoNoCatalogoTest`.
 */
final class RecusasDaJustificativaNoCatalogoTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string}> chave no catálogo => português */
    public static function textos(): iterable
    {
        foreach (AnaliseDeJustificativaRecusadaException::CHAVE_DO_TEXTO as $texto => $chave) {
            yield $chave => [$chave, $texto];
        }
        foreach (NomesDasSituacoesDaJustificativa::CHAVE_DA_TELA as $nome => $chave) {
            yield $chave => [$chave, NomesDasSituacoesDaJustificativa::texto($nome)];
        }
        foreach (RestricoesAnexoJustificativa::CHAVE_DO_TEXTO as $texto => $chave) {
            yield $chave => [$chave, $texto];
        }
        yield 'ponto_servidor.recusa.abono.nao_encontrada' => ['ponto_servidor.recusa.abono.nao_encontrada', AbonarJustificativasDaEquipeUseCase::NAO_ENCONTRADA];
    }

    #[DataProvider('textos')]
    #[TestDox('pt_BR de $chave é o texto do código e o en é outro texto')]
    public function testPtBrIgualAoCodigoEEnDiferente(string $chave, string $portugues): void
    {
        $tradutor = self::tradutor();

        self::assertSame($portugues, $tradutor->trans($chave, [], null, 'pt_BR'));
        $en = $tradutor->trans($chave, [], null, 'en');
        self::assertNotSame($chave, $en, $chave . ' sem en');
        self::assertNotSame($portugues, $en, $chave . ' igual ao português em en');
    }

    #[TestDox('Recusa por pessoa do abono em lote: o português é o sprintf de antes e o en traduz o motivo')]
    public function testRecusaPorPessoaDoAbono(): void
    {
        $tradutor = self::tradutor();
        $motivo = AnaliseDeJustificativaRecusadaException::textoDaMensagem(AnaliseDaPropriaJustificativa::RECUSA);
        self::assertInstanceOf(TextoDoPonto::class, $motivo);
        $texto = TextoDoPonto::de(AbonarJustificativasDaEquipeUseCase::CHAVE_DO_TEXTO[AbonarJustificativasDaEquipeUseCase::RECUSA_DA_PESSOA], AbonarJustificativasDaEquipeUseCase::RECUSA_DA_PESSOA, [
            'pessoa' => 'Ana',
            'dia' => '05/11/2026',
            'motivo' => $motivo,
        ]);

        self::assertSame('Ana (05/11/2026): ' . AnaliseDaPropriaJustificativa::RECUSA, $texto->portugues);
        self::assertSame($texto->portugues, $texto->traduzir(self::tradutorEm('pt_BR')));
        self::assertSame("Ana (05/11/2026): Only the firm's primary user or the Master can review their own justification.", $texto->traduzir(self::tradutorEm('en')));
    }

    /** @return iterable<string, array{0: string}> */
    public static function mensagensDaAnalise(): iterable
    {
        yield 'excluida' => [AnaliseDeJustificativaRecusadaException::MENSAGEM_EXCLUIDA];
        yield 'nao encontrada' => [AnaliseDeJustificativaRecusadaException::MENSAGEM_NAO_ENCONTRADA];
        yield 'propria' => [AnaliseDaPropriaJustificativa::RECUSA];
        yield 'horario futuro' => [HorarioFuturoDaBatida::MENSAGEM];
        foreach (array_keys(NomesDasSituacoesDaJustificativa::CHAVE_DA_TELA) as $nome) {
            yield 'situacao ' . $nome => [NomesDasSituacoesDaJustificativa::texto($nome)];
        }
        foreach (array_keys(BatidaJaRegistrada::CHAVE_DO_ROTULO) as $rotulo) {
            yield 'ja registrada ' . $rotulo => [sprintf(BatidaJaRegistrada::MENSAGEM, $rotulo)];
        }
    }

    #[DataProvider('mensagensDaAnalise')]
    #[TestDox('A mensagem "$mensagem" volta a ter chave (textoDaMensagem) e o português fica igual')]
    public function testTextoDaMensagemVoltaAterChave(string $mensagem): void
    {
        $texto = AnaliseDeJustificativaRecusadaException::textoDaMensagem($mensagem);

        self::assertNotNull($texto);
        self::assertSame($mensagem, $texto->portugues);
        self::assertSame($mensagem, $texto->traduzir(self::tradutorEm('pt_BR')));
        self::assertNotSame($mensagem, $texto->traduzir(self::tradutorEm('en')));
    }

    #[TestDox('Mensagem desconhecida não vira texto com chave; situação e texto fora do mapa dão LogicException ou null')]
    public function testForaDoMapa(): void
    {
        self::assertNull(AnaliseDeJustificativaRecusadaException::textoDaMensagem('Outro texto.'));
        self::assertNull(RestricoesAnexoJustificativa::chaveDe('Outro texto.'));
        $this->expectException(\LogicException::class);

        NomesDasSituacoesDaJustificativa::textoDaTela('analise.sem_motivo');
    }

    #[TestDox('A exceção da análise leva o texto com chave e a mensagem em português')]
    public function testExcecaoDaAnaliseLevaOTexto(): void
    {
        $recusa = AnaliseDeJustificativaRecusadaException::situacaoMudou('analise.so_para_recusar');

        self::assertSame('Apenas justificativas em análise podem ser recusadas.', $recusa->getMessage());
        self::assertSame('ponto_servidor.recusa.analise.so_para_recusar', $recusa->texto()->chave);
        self::assertSame(AnaliseDeJustificativaRecusadaException::SITUACAO_MUDOU, $recusa->motivo);
        self::assertSame(HorarioFuturoDaBatida::MENSAGEM, AnaliseDeJustificativaRecusadaException::horarioFuturo()->getMessage());
        $jaBatida = AnaliseDeJustificativaRecusadaException::batidaJaRegistrada(TextoDoPonto::de(BatidaJaRegistrada::chaveDe(BatidaJaRegistrada::MENSAGEM), BatidaJaRegistrada::MENSAGEM, ['tipo' => 'entrada']));
        self::assertSame('Já existe um registro de entrada neste dia.', $jaBatida->getMessage());
    }

    private static function tradutorEm(string $locale): Translator
    {
        $tradutor = self::tradutor();
        $tradutor->setLocale($locale);

        return $tradutor;
    }

    private static function tradutor(): Translator
    {
        static $tradutor = null;
        if ($tradutor === null) {
            $tradutor = new Translator('pt_BR');
            $tradutor->addLoader('yaml', new YamlFileLoader());
            foreach (['pt_BR', 'en'] as $locale) {
                $tradutor->addResource('yaml', \dirname(__DIR__, 3) . '/translations/ponto_servidor/messages+intl-icu.' . $locale . '.yaml', $locale, 'messages+intl-icu');
            }
        }

        return $tradutor;
    }
}
