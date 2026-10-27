<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Enum\TipoItemMarcado;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Repository\CandidaturaRepository;
use App\TrabalhoExterno\Repository\MarcacaoDoUsuarioRepository;
use App\TrabalhoExterno\Repository\TermoDeVoluntariadoRepository;
use App\TrabalhoExterno\Service\ApresentadorDeTermo;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\FiltroDeCurriculos;
use App\TrabalhoExterno\Service\LocalizadorDeCurriculo;
use App\TrabalhoExterno\Service\PlanilhaCsv;
use App\TrabalhoExterno\Service\PoliticaDoCurriculo;

/**
 * "Exportar {N} currículo(s)" (`cvCsv`, desenho 06 l.1150): a lista filtrada em planilha, com as
 * colunas do desenho, no formato da {@see PlanilhaCsv} (`;`, aspas, BOM e proteção contra fórmula). Exige
 * `talentos`.
 */
final class ExportarCurriculosCsvUseCase
{
    public const NOME_DO_ARQUIVO = 'curriculos-bluejus.csv';
    public const CABECALHO = ['Nome', 'E-mail', 'Instagram', 'Telefone', 'Situação', 'OAB', 'UF OAB', 'Instituição', 'Cidade', 'UF', 'Cargo', 'Áreas', 'Modalidade', 'Anos exp.', 'Pretensão', 'Voluntário', 'Termo', 'Situação no banco', 'Recebido'];
    private const VOLUNTARIO = ['sim' => 'Sim', 'talvez' => 'Talvez', 'nao' => 'Não'];
    /** A coluna Termo (`cvCsv`, l.1150): só o estado do termo mais recente, nunca CPF nem RG. */
    private const TERMO = ['sem' => 'Sem termo', 'enviado' => 'Aguardando aceite', 'aceito' => 'Aceito'];

    public function __construct(
        private readonly LocalizadorDeCurriculo $localizador,
        private readonly PoliticaDoCurriculo $politica,
        private readonly MarcacaoDoUsuarioRepository $marcacoes,
        private readonly FiltroDeCurriculos $filtro,
        private readonly CandidaturaRepository $candidaturas,
        private readonly TermoDeVoluntariadoRepository $termos,
    ) {
    }

    /** @param array<string, string> $filtros filtro, busca, area, situacao, uf, termo */
    public function executar(Contexto $ctx, array $filtros): string
    {
        if (!$this->politica->podeGerir($ctx)) {
            throw new AcaoNaoPermitida('Só a Central com acesso ao banco de talentos exporta os currículos.');
        }
        $todos = $this->localizador->visiveis($ctx);
        $ids = array_map(static fn (Curriculo $c): int => (int) $c->getId(), $todos);
        $marcas = $this->marcacoes->doUsuario($ctx->usuario, TipoItemMarcado::Curriculo, $ids);
        $ligado = $ctx->configuracao->termoLigado();
        $termos = $ligado ? array_map(static fn ($t): string => ApresentadorDeTermo::estado($t), $this->termos->maisRecentesDe($ids)) : [];
        if (!$ligado) {
            unset($filtros['termo']);
        }
        $linhas = [self::CABECALHO];
        foreach ($this->filtro->aplicar($todos, $marcas, $filtros, new \DateTimeImmutable(), $this->candidaturas->contarPorCurriculo($ids), $termos) as $cv) {
            $linhas[] = self::colunas($cv, $termos[(int) $cv->getId()] ?? 'sem');
        }

        return PlanilhaCsv::documento($ligado ? $linhas : array_map(self::semATermo(...), $linhas));
    }

    /**
     * Com o termo desligado a coluna Termo sai do arquivo.
     *
     * @param list<string> $linha
     *
     * @return list<string>
     */
    private static function semATermo(array $linha): array
    {
        $posicao = array_search('Termo', self::CABECALHO, true);
        unset($linha[$posicao]);

        return array_values($linha);
    }

    /** @return list<string> */
    private static function colunas(Curriculo $cv, string $termo): array
    {
        return [
            $cv->texto('nome'), $cv->texto('email'), $cv->texto('insta') !== '' ? '@' . $cv->texto('insta') : '',
            $cv->texto('tel'), $cv->getSituacao()->rotuloCurto(), $cv->texto('oabNum'), $cv->texto('oabUf'),
            $cv->texto('inst'), $cv->texto('cidade'), $cv->texto('uf'), $cv->texto('cargo'), implode('; ', $cv->getAreas()),
            $cv->texto('modalidade'), $cv->getAnos() !== null ? (string) $cv->getAnos() : '', $cv->texto('pretensao'),
            self::VOLUNTARIO[$cv->texto('vol')] ?? '', self::TERMO[$termo] ?? self::TERMO['sem'], $cv->getStatus()->rotulo(), $cv->getRecebidoEm()->format('d/m/Y'),
        ];
    }
}
