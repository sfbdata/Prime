<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\DTO\FiltroDaCampanha;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\DestinatariosDaCampanha;
use App\TrabalhoExterno\Service\PlanilhaCsv;
use App\TrabalhoExterno\Service\PoliticaDoCurriculo;
use App\TrabalhoExterno\Service\RegistroDaCampanha;

/**
 * "Lista de destinatários (CSV)" do modal da campanha (desenho 06 l.1097; spec VS 18.10.26 §4.10): a lista
 * do público e dos filtros, com as colunas do desenho, para a ferramenta de envio em massa. Só o Master,
 * nunca na prévia. Sem ninguém, "não encontrado" com o texto do toast do desenho ("Sem destinatários").
 * Cada download vai para o log (quem, quando, público, filtros e quantidade), nunca a lista.
 */
final class ExportarDestinatariosDaCampanhaUseCase
{
    public const NOME_DO_ARQUIVO = 'destinatarios-campanha.csv';
    public const CABECALHO = ['E-mail', 'Nome', 'Origem', 'UF'];
    public const TITULO_SEM_DESTINATARIOS = 'Sem destinatários';
    public const TEXTO_SEM_DESTINATARIOS = 'Ainda não há e-mails autorizados para este público.';

    public function __construct(
        private readonly PoliticaDoCurriculo $politica,
        private readonly DestinatariosDaCampanha $destinatarios,
        private readonly RegistroDaCampanha $registro,
    ) {
    }

    public function executar(Contexto $ctx, FiltroDaCampanha $filtro): string
    {
        MontarCampanhaUseCase::exigirMaster($this->politica, $ctx);
        $lista = $this->destinatarios->para($filtro)['lista'];
        if ($lista === []) {
            throw new DemandaNaoEncontrada(self::TEXTO_SEM_DESTINATARIOS);
        }

        $linhas = array_map(static fn (array $d): array => [$d['email'], $d['nome'], $d['origem'], $d['uf']], $lista);
        $csv = PlanilhaCsv::documento(array_merge([self::CABECALHO], $linhas));
        $this->registro->registrar($ctx, $filtro, RegistroDaCampanha::CSV, count($lista));

        return $csv;
    }
}
