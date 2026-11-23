<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

use App\Ponto\Service\AnaliseDaPropriaJustificativa;
use App\Ponto\Service\BatidaJaRegistrada;
use App\Ponto\Service\HorarioFuturoDaBatida;
use App\Ponto\Service\NomesDasSituacoesDaJustificativa;
use App\Ponto\Service\TextoDoPonto;

/**
 * A análise do gestor não foi gravada (docs/specs/ponto-desenho-0811.md, E3 e corrida da E3). Nada mudou no banco; a
 * mensagem é escrita para o usuário final e vai direto para a tela. O `motivo` deixa quem chama escolher a resposta
 * (a ficha volta com aviso; a aba Equipe da E5 decide a dela). A mensagem da exceção é o português de sempre; o
 * controller traduz pelo {@see self::texto()} (chave e parâmetros no catálogo `ponto_servidor`, spec idioma-do-sistema §33).
 */
final class AnaliseDeJustificativaRecusadaException extends \DomainException
{
    /** O colaborador excluiu a justificativa (P41) entre a leitura e a gravação. */
    public const EXCLUIDA = 'excluida';

    /** Outra análise comitou antes, ou a justificativa já não está na situação que a ação pede. */
    public const SITUACAO_MUDOU = 'situacao_mudou';

    /** Não existe neste escritório (id de outro escritório incluído): para quem pede, não existe. */
    public const NAO_ENCONTRADA = 'nao_encontrada';

    /** A própria justificativa, de quem não é primário nem Master (P43 a, AnaliseDaPropriaJustificativa). */
    public const PROPRIA = 'propria';

    /** Esquecimento de uma batida que já existe no dia (R1 de ponto-esquecimento-nao-trava). */
    public const BATIDA_JA_REGISTRADA = 'batida_ja_registrada';

    /** Esquecimento com data e hora acima de agora. */
    public const HORARIO_FUTURO = 'horario_futuro';

    public const MENSAGEM_EXCLUIDA = 'Esta justificativa foi excluída pelo colaborador.';

    public const MENSAGEM_NAO_ENCONTRADA = 'Justificativa não encontrada.';

    /** @var array<string, string> texto da constante => chave no catálogo `ponto_servidor` */
    public const CHAVE_DO_TEXTO = [
        self::MENSAGEM_EXCLUIDA => 'ponto_servidor.recusa.analise.excluida',
        self::MENSAGEM_NAO_ENCONTRADA => 'ponto_servidor.recusa.analise.nao_encontrada',
        AnaliseDaPropriaJustificativa::RECUSA => 'ponto_servidor.recusa.analise.propria',
    ];

    private function __construct(public readonly string $motivo, private readonly TextoDoPonto $texto)
    {
        parent::__construct($texto->portugues);
    }

    /** O texto da recusa com a chave e os parâmetros, para o controller traduzir ({@see TextoDoPonto::traduzir()}). */
    public function texto(): TextoDoPonto
    {
        return $this->texto;
    }

    /**
     * O texto com chave de uma recusa que viajou como português puro (a lista de recusas do "Abonar selecionadas"
     * guarda só a mensagem); null quando a mensagem não é de nenhuma recusa conhecida.
     */
    public static function textoDaMensagem(string $mensagem): ?TextoDoPonto
    {
        if (isset(self::CHAVE_DO_TEXTO[$mensagem])) {
            return TextoDoPonto::de(self::CHAVE_DO_TEXTO[$mensagem], $mensagem);
        }
        if ($mensagem === HorarioFuturoDaBatida::MENSAGEM) {
            return HorarioFuturoDaBatida::texto($mensagem);
        }
        foreach (array_keys(NomesDasSituacoesDaJustificativa::CHAVE_DA_TELA) as $chave) {
            if (NomesDasSituacoesDaJustificativa::texto($chave) === $mensagem) {
                return NomesDasSituacoesDaJustificativa::textoDaTela($chave);
            }
        }
        // "Já existe um registro de {tipo} neste dia.": o tipo é um dos rótulos de BatidaJaRegistrada.
        foreach (array_keys(BatidaJaRegistrada::CHAVE_DO_ROTULO) as $rotulo) {
            if ($mensagem === sprintf(BatidaJaRegistrada::MENSAGEM, $rotulo)) {
                return TextoDoPonto::de(BatidaJaRegistrada::chaveDe(BatidaJaRegistrada::MENSAGEM), BatidaJaRegistrada::MENSAGEM, [
                    'tipo' => TextoDoPonto::de(BatidaJaRegistrada::CHAVE_DO_ROTULO[$rotulo], $rotulo),
                ]);
            }
        }

        return null;
    }

    public static function excluida(): self
    {
        return new self(self::EXCLUIDA, TextoDoPonto::de(self::CHAVE_DO_TEXTO[self::MENSAGEM_EXCLUIDA], self::MENSAGEM_EXCLUIDA));
    }

    /** @param string $chaveDaSituacao uma de NomesDasSituacoesDaJustificativa::CHAVE_DA_TELA (`analise.so_para_abonar`...) */
    public static function situacaoMudou(string $chaveDaSituacao): self
    {
        return new self(self::SITUACAO_MUDOU, NomesDasSituacoesDaJustificativa::textoDaTela($chaveDaSituacao));
    }

    public static function naoEncontrada(): self
    {
        return new self(self::NAO_ENCONTRADA, TextoDoPonto::de(self::CHAVE_DO_TEXTO[self::MENSAGEM_NAO_ENCONTRADA], self::MENSAGEM_NAO_ENCONTRADA));
    }

    public static function propria(): self
    {
        return new self(self::PROPRIA, TextoDoPonto::de(self::CHAVE_DO_TEXTO[AnaliseDaPropriaJustificativa::RECUSA], AnaliseDaPropriaJustificativa::RECUSA));
    }

    /** @param TextoDoPonto $texto a recusa de BatidaJaRegistrada, com o tipo da batida */
    public static function batidaJaRegistrada(TextoDoPonto $texto): self
    {
        return new self(self::BATIDA_JA_REGISTRADA, $texto);
    }

    public static function horarioFuturo(): self
    {
        return new self(self::HORARIO_FUTURO, HorarioFuturoDaBatida::texto(HorarioFuturoDaBatida::MENSAGEM));
    }
}
