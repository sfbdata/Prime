<?php

declare(strict_types=1);

namespace App\Processo\Service;

use App\Pasta\Entity\Pasta;
use App\Processo\DTO\DadosDoProcessoNoCartao;
use App\Processo\DTO\DadosManuaisDoProcesso;
use App\Processo\Entity\Processo;
use App\Processo\Integracao\IntegracaoComPjeInterface;

/**
 * Monta o cartão do processo (P5b, docs/specs/processo-cartao-edicao.md) pela regra do desenho
 * (`pjeDados`): o valor manual prevalece; sem ele, o valor base; sem os dois, nulo.
 *
 * Sem integração com o PJe (P5a), o valor base é o que o Datajud gravou no processo (classe,
 * assuntos, órgão, distribuição, sigilo) e, no valor da causa, o `Pasta.valorCausa` da pasta em que
 * o cartão está. O segredo efetivo nunca é "Não" quando o tribunal informou sigilo.
 */
final class MontadorDosDadosDoProcesso
{
    /** `PJE_CAMPOS` do desenho: chave, rótulo, tipo, placeholder. */
    public const CAMPOS = [
        ['classe', 'Classe judicial', 'texto', 'Ex.: PROCEDIMENTO COMUM CÍVEL (7)'],
        ['assuntos', 'Assunto', 'area', 'Um assunto por linha'],
        ['jurisdicao', 'Jurisdição', 'texto', 'Ex.: Brasília · Fórum…'],
        ['orgao', 'Órgão julgador', 'texto', 'Ex.: 2ª Vara da Fazenda Pública do DF'],
        ['distribuicao', 'Última distribuição', 'texto', 'dd/mm/aaaa'],
        ['valorCausa', 'Valor da causa', 'texto', 'R$ 0,00'],
        ['segredo', 'Segredo de justiça?', 'flag', ''],
        ['digital', 'Juízo 100% digital?', 'flag', ''],
        ['gratuita', 'Justiça gratuita?', 'flag', ''],
        ['tutela', 'Tutela/liminar?', 'flag', ''],
        ['prioridade', 'Prioridade?', 'flag', ''],
    ];

    /** Marcas do desenho, na ordem dele: rótulo, chave da flag, ícone. */
    private const MARCAS = [
        ['Justiça gratuita', 'gratuita', 'bi-heart-pulse'],
        ['Tutela/liminar', 'tutela', 'bi-lightning-charge'],
        ['Prioridade', 'prioridade', 'bi-flag'],
        ['Segredo de justiça', 'segredo', 'bi-lock'],
        ['Juízo 100% digital', 'digital', 'bi-pc-display'],
    ];

    public function __construct(
        private readonly IntegracaoComPjeInterface $integracao,
    ) {
    }

    /** O que vale sem o manual: Datajud no processo e o valor da causa da pasta. */
    public function valoresBase(Processo $processo, ?Pasta $pasta): DadosManuaisDoProcesso
    {
        $assuntos = [];
        foreach ($processo->getAssuntos() as $assunto) {
            $nome = trim($assunto->getNome());
            if ($nome !== '' && !in_array($nome, $assuntos, true)) {
                $assuntos[] = $nome;
            }
        }
        if ($assuntos === [] && trim($processo->getAssuntoProcessual()) !== '') {
            $assuntos[] = trim($processo->getAssuntoProcessual());
        }

        $distribuicao = $processo->getDataDistribuicao();
        $sigilo = $processo->getNivelSigilo();

        return new DadosManuaisDoProcesso(
            // Classe com o código do CNJ, como o cartão já mostrava e o exemplo do desenho ("… (7)").
            classe: self::textoOuNulo($processo->getClasseProcessual() . (
                trim($processo->getClasseProcessual()) !== '' && $processo->getClasseCodigo() !== null ? ' (' . $processo->getClasseCodigo() . ')' : ''
            )),
            assuntos: $assuntos !== [] ? $assuntos : null,
            jurisdicao: null,
            orgao: self::textoOuNulo($processo->getOrgaoJulgador()),
            distribuicao: $distribuicao !== null ? \DateTimeImmutable::createFromInterface($distribuicao)->setTime(0, 0) : null,
            valorCausa: $pasta?->getValorCausa(),
            segredo: $sigilo === null ? null : $sigilo > 0,
        );
    }

    public function montar(Processo $processo, ?Pasta $pasta): DadosDoProcessoNoCartao
    {
        [$campos, $efetivo] = $this->campos($this->valoresBase($processo, $pasta), $processo->getDadosManuais());

        $marcas = [];
        foreach (self::MARCAS as [$rotulo, $chave, $icone]) {
            if ($efetivo[$chave] === true) {
                $marcas[] = ['rotulo' => $rotulo, 'icone' => $icone];
            }
        }

        $manualEm = $processo->getDadosManuaisEm();
        $temIntegracao = $this->integracao->estaConfigurada();

        return new DadosDoProcessoNoCartao(
            campos: $campos,
            resumo: [
                ['rotulo' => 'Órgão julgador', 'valor' => self::exibir('orgao', $efetivo['orgao'])],
                ['rotulo' => 'Valor da causa', 'valor' => self::exibir('valorCausa', $efetivo['valorCausa'])],
                ['rotulo' => 'Última distribuição', 'valor' => self::exibir('distribuicao', $efetivo['distribuicao'])],
            ],
            marcas: $marcas,
            fonteTexto: $manualEm !== null
                ? 'Preenchido manualmente em ' . $manualEm->format('d/m/Y, H:i') . ($temIntegracao ? '' : ' · integração com o PJe não configurada')
                : 'Integração com o PJe não configurada. Preencha manualmente.',
            fonteIcone: $manualEm !== null ? 'bi-pencil-square' : 'bi-cloud-slash',
            temIntegracao: $temIntegracao,
        );
    }

    /**
     * Os 11 campos com o valor efetivo e o selo, e o mapa chave → valor efetivo (para as marcas).
     *
     * @return array{0: list<array{chave: string, rotulo: string, tipo: string, placeholder: string, manual: bool, texto: ?string, itens: list<string>, flag: ?bool, formulario: string|bool|null}>, 1: array<string, mixed>}
     */
    private function campos(DadosManuaisDoProcesso $base, DadosManuaisDoProcesso $manual): array
    {
        $efetivo = [];
        $campos = [];
        foreach (self::CAMPOS as [$chave, $rotulo, $tipo, $placeholder]) {
            $valorManual = $manual->{$chave};
            $valor = $valorManual ?? $base->{$chave};
            // O "Sim" do tribunal vence o "Não" manual (gravado antes de o tribunal informar o sigilo):
            // o valor exibido é o do tribunal, então sem o selo "manual".
            if ($chave === 'segredo' && $base->segredo === true) {
                $valor = true;
                $valorManual = $valorManual === true ? true : null;
            }
            $efetivo[$chave] = $valor;

            $campos[] = [
                'chave' => $chave,
                'rotulo' => $rotulo,
                'tipo' => $tipo,
                'placeholder' => $placeholder,
                'manual' => $valorManual !== null,
                'texto' => $tipo === 'texto' ? self::exibir($chave, $valor) : null,
                'itens' => $tipo === 'area' && is_array($valor) ? $valor : [],
                'flag' => $tipo === 'flag' && is_bool($valor) ? $valor : null,
                'formulario' => match ($tipo) {
                    'flag' => is_bool($valor) ? $valor : null,
                    'area' => is_array($valor) ? implode("\n", $valor) : '',
                    default => (string) self::exibir($chave, $valor),
                },
            ];
        }

        return [$campos, $efetivo];
    }

    /** Texto de tela de um valor de campo de texto (data dd/mm/aaaa, valor R$ 1.234,56). */
    public static function exibir(string $chave, mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('d/m/Y');
        }
        if ($chave === 'valorCausa') {
            return 'R$ ' . number_format((float) $valor, 2, ',', '.');
        }

        return is_string($valor) ? $valor : null;
    }

    private static function textoOuNulo(string $texto): ?string
    {
        $texto = trim($texto);

        return $texto !== '' ? $texto : null;
    }
}
