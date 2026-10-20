<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\InteressadoEmVagas;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Repository\InteressadoEmVagasRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\EngajamentoDoTrabalhoExterno;
use App\TrabalhoExterno\Service\PlanilhaCsv;
use App\TrabalhoExterno\Service\PoliticaDoCurriculo;

/**
 * O clique no KPI "ALERTAS POR E-MAIL" do painel (desenho 06 l.1146; spec VS 18.10.26 §4.9): a lista de
 * quem pediu os alertas de vagas na página pública, em planilha (`alertas-vagas-bluejus.csv`), com as
 * colunas do desenho. Só o Master, nunca na prévia. Sem inscritos, "não encontrado" com o texto do toast
 * do desenho ("Ainda sem inscritos").
 */
final class ExportarAlertasCsvUseCase
{
    public const NOME_DO_ARQUIVO = 'alertas-vagas-bluejus.csv';
    public const CABECALHO = ['E-mail', 'UF', 'Áreas', 'Inscrito em', 'Origem'];
    public const TITULO_SEM_INSCRITOS = 'Ainda sem inscritos';
    public const TEXTO_SEM_INSCRITOS = 'Compartilhe vagas para começar a captar e-mails.';

    public function __construct(
        private readonly PoliticaDoCurriculo $politica,
        private readonly InteressadoEmVagasRepository $interessados,
    ) {
    }

    public function executar(Contexto $ctx): string
    {
        if (!$this->politica->ehMaster($ctx) || !$this->politica->veAba($ctx)) {
            throw new AcaoNaoPermitida('Só o Master exporta a lista de alertas por e-mail.');
        }
        $inscritos = $this->interessados->paraExportar();
        if ($inscritos === []) {
            throw new DemandaNaoEncontrada(self::TEXTO_SEM_INSCRITOS);
        }

        return PlanilhaCsv::documento(array_merge([self::CABECALHO], array_map(self::colunas(...), $inscritos)));
    }

    /**
     * As colunas do `L.map` do desenho: sem UF é "Brasil", as áreas separadas por "; " e a data da
     * inscrição no horário de Brasília. A origem é o link compartilhado, quando veio de um.
     *
     * @return list<string>
     */
    private static function colunas(InteressadoEmVagas $i): array
    {
        return [
            $i->getEmail(),
            $i->getUf() !== '' ? $i->getUf() : 'Brasil',
            implode('; ', $i->getAreas()),
            $i->getCriadoEm()->setTimezone(new \DateTimeZone(EngajamentoDoTrabalhoExterno::FUSO))->format('d/m/Y'),
            $i->getOrigemLink() !== null ? 'link de vagas' : 'página de vagas',
        ];
    }
}
