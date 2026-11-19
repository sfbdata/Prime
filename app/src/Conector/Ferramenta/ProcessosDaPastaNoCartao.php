<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\ChatIa\Service\ProtecaoDoTextoDoChat;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Service\PropostasDoConector;
use App\Pasta\Entity\Pasta;
use App\Processo\DTO\DadosDoProcessoInput;
use App\Processo\Entity\Processo;
use App\Processo\UseCase\SalvarDadosManuaisDoProcessoUseCase;
use App\Twig\NumeroProcessoExtension;

/**
 * `dados_do_cartao`: os dados manuais do cartão do processo ({@see SalvarDadosManuaisDoProcessoUseCase}), sem o
 * campo de segredo. O UseCase da tela refaz os dados manuais por inteiro a cada gravação; por isso o que a pessoa não
 * mandou entra com o valor manual de agora, e o segredo manual volta como está (o conector nunca o muda, 6.2.3).
 *
 * Campo de texto que já tem valor manual e recebe outro (ou vazio) é gravar por cima: a chamada inteira vira proposta,
 * com o valor de antes guardado nos dados para a confirmação recusar se mudou. Preencher vazio e as chaves sim ou não
 * são diretos.
 */
final class ProcessosDaPastaNoCartao
{
    private const ROTULOS = ['classe' => 'classe', 'assuntos' => 'assuntos', 'jurisdicao' => 'jurisdição', 'orgao' => 'órgão julgador', 'distribuicao' => 'distribuição', 'valor_causa' => 'valor da causa'];

    public function __construct(
        private readonly ProcessosDaPastaResolvedor $resolvedor,
        private readonly ProtecaoDoTextoDoChat $protecao,
        private readonly PropostasDoConector $propostas,
        private readonly NumeroProcessoExtension $numero,
        private readonly SalvarDadosManuaisDoProcessoUseCase $salvar,
    ) {
    }

    public function executar(ProcessosDaPastaEntrada $entrada, Pasta $pasta, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $processo = $this->processoEditavel($entrada, $pasta, $chamada);
        $anterior = $this->anterior($entrada, $processo);
        if ($this->temTroca($entrada, $anterior)) {
            $resumo = $this->resumo($entrada, $pasta, $processo, $anterior, $chamada);

            return $this->propostas->propor($chamada, ProcessosDaPastaFerramenta::NOME, $entrada->acao, $entrada->dados($anterior), $resumo);
        }
        $this->gravar($entrada, $pasta, $processo, $chamada);

        return ProcessosDaPastaResposta::direta($pasta, $entrada, ['processo_id' => (int) $processo->getId(), 'campos' => $this->camposMexidos($entrada)], ['processo' => [(int) $processo->getId()]]);
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @throws \App\Conector\Exception\ErroDaFerramenta
     */
    public function reconferir(ProcessosDaPastaEntrada $entrada, array $dados, Pasta $pasta, ChamadaDoConector $chamada): void
    {
        $processo = $this->processoEditavel($entrada, $pasta, $chamada);
        $anterior = \is_array($dados['anterior'] ?? null) ? $dados['anterior'] : [];
        if ($anterior !== $this->anterior($entrada, $processo)) {
            throw ProcessosDaPastaRecusa::mudou();
        }
    }

    public function confirmar(ProcessosDaPastaEntrada $entrada, Pasta $pasta, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        $processo = $this->processoEditavel($entrada, $pasta, $chamada);
        $this->gravar($entrada, $pasta, $processo, $chamada);

        return ProcessosDaPastaResposta::confirmada($pasta, $entrada, ['processo_id' => (int) $processo->getId(), 'campos' => $this->camposMexidos($entrada)], ['processo' => [(int) $processo->getId()]]);
    }

    private function processoEditavel(ProcessosDaPastaEntrada $entrada, Pasta $pasta, ChamadaDoConector $chamada): Processo
    {
        $processo = $this->resolvedor->processo($entrada->id('processo_id'), $pasta, $chamada->contexto->escritorio, true);
        $this->resolvedor->exigirEdicaoDoProcesso($processo, $chamada);

        return $processo;
    }

    /** @return list<string> */
    private function camposMexidos(ProcessosDaPastaEntrada $entrada): array
    {
        return array_values(array_diff(array_keys($entrada->campos), ['processo_id']));
    }

    private function gravar(ProcessosDaPastaEntrada $entrada, Pasta $pasta, Processo $processo, ChamadaDoConector $chamada): void
    {
        $contexto = $chamada->contexto;
        $input = $this->entradaDoUseCase($entrada, $processo);
        ProcessosDaPastaRecusa::traduzindo(fn () => $this->salvar->executar($processo, $pasta, $input, $contexto->pessoa, $contexto->escritorio));
    }

    /**
     * O valor manual de agora de cada campo que a chamada manda: o texto dos campos de texto e a marca (sim, não ou
     * vazia) das marcas.
     *
     * @return array<string, string|bool|null>
     */
    private function anterior(ProcessosDaPastaEntrada $entrada, Processo $processo): array
    {
        $manual = $processo->getDadosManuais();
        $marcas = ['digital' => $manual->digital, 'gratuita' => $manual->gratuita, 'tutela' => $manual->tutela, 'prioridade' => $manual->prioridade];

        return array_intersect_key($this->textosManuais($processo) + $marcas, $entrada->campos);
    }

    /**
     * Gravar por cima: texto que já tem valor manual e recebe outro (ou vazio), ou marca ligada que se desliga. Ligar
     * marca e preencher campo vazio são diretos.
     *
     * @param array<string, string|bool|null> $anterior
     */
    private function temTroca(ProcessosDaPastaEntrada $entrada, array $anterior): bool
    {
        foreach ($anterior as $campo => $atual) {
            $trocaTexto = \is_string($atual) && $atual !== '' && self::normal($campo, $entrada->texto($campo)) !== $atual;
            $desligaMarca = $atual === true && ($entrada->campos[$campo] ?? null) === false;
            if ($trocaTexto || $desligaMarca) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string|bool|null> $anterior */
    private function resumo(ProcessosDaPastaEntrada $entrada, Pasta $pasta, Processo $processo, array $anterior, ChamadaDoConector $chamada): string
    {
        $escritorio = $chamada->contexto->escritorio;
        $partes = [];
        foreach (array_intersect_key($anterior, self::ROTULOS) as $campo => $atual) {
            $atual = (string) $atual;
            $novo = $this->protecao->rotulo($escritorio, self::normal($campo, $entrada->texto($campo)), 'vazio');
            $partes[] = $atual === ''
                ? sprintf('%s: "%s" (hoje sem valor manual)', self::ROTULOS[$campo], $novo)
                : sprintf('%s: de "%s" para "%s"', self::ROTULOS[$campo], $this->protecao->rotulo($escritorio, $atual, 'vazio'), $novo);
        }
        foreach (ProcessosDaPastaEntrada::CARTAO_FLAGS as $flag) {
            if (isset($entrada->campos[$flag])) {
                $antes = $anterior[$flag] ?? null;
                $partes[] = $antes === true && $entrada->campos[$flag] === false
                    ? sprintf('%s: de sim para não', $flag)
                    : sprintf('%s: %s', $flag, $entrada->campos[$flag] ? 'sim' : 'não');
            }
        }

        return sprintf(
            'Trocar dados do cartão do processo %s (pasta %s), por cima do que está preenchido à mão. %s. Os dados valem para todas as pastas com este processo.',
            $this->numero->numeroCnj($processo->getNumeroProcesso()),
            $this->protecao->rotulo($escritorio, $pasta->getNup() ?? '', 'sem número'),
            implode('; ', $partes),
        );
    }

    /** Os valores manuais de agora no texto que a ferramenta recebe (data AAAA-MM-DD, valor com vírgula). */
    private function textosManuais(Processo $processo): array
    {
        $manual = $processo->getDadosManuais();

        return [
            'classe' => (string) $manual->classe,
            'assuntos' => implode("\n", $manual->assuntos ?? []),
            'jurisdicao' => (string) $manual->jurisdicao,
            'orgao' => (string) $manual->orgao,
            'distribuicao' => $manual->distribuicao?->format('Y-m-d') ?? '',
            'valor_causa' => $manual->valorCausa === null ? '' : str_replace('.', ',', $manual->valorCausa),
        ];
    }

    private function entradaDoUseCase(ProcessosDaPastaEntrada $entrada, Processo $processo): DadosDoProcessoInput
    {
        $textos = array_replace($this->textosManuais($processo), array_intersect_key($entrada->campos, array_flip(ProcessosDaPastaEntrada::CARTAO_TEXTOS)));
        $manual = $processo->getDadosManuais();
        $flags = ['segredo' => $manual->segredo, 'digital' => $manual->digital, 'gratuita' => $manual->gratuita, 'tutela' => $manual->tutela, 'prioridade' => $manual->prioridade];
        $flags = array_replace($flags, array_intersect_key($entrada->campos, array_flip(ProcessosDaPastaEntrada::CARTAO_FLAGS)));
        $distribuicao = $textos['distribuicao'] === '' ? '' : (new \DateTimeImmutable((string) $textos['distribuicao']))->format('d/m/Y');

        return new DadosDoProcessoInput([
            'classe' => (string) $textos['classe'],
            'assuntos' => (string) $textos['assuntos'],
            'jurisdicao' => (string) $textos['jurisdicao'],
            'orgao' => (string) $textos['orgao'],
            'distribuicao' => $distribuicao,
            'valorCausa' => (string) $textos['valor_causa'],
        ], $flags);
    }

    /** O texto na forma em que o valor manual de agora é lido, para comparar o que veio com o que está. */
    private static function normal(string $campo, string $valor): string
    {
        $valor = trim(str_replace("\r\n", "\n", $valor));
        if ($campo !== 'valor_causa') {
            return $valor;
        }

        return str_contains($valor, ',') ? str_replace(['R$', ' ', '.'], '', $valor) : str_replace(['R$', ' '], '', $valor);
    }
}
