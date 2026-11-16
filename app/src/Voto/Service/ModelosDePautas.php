<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Voto\DTO\PautaInput;
use App\Voto\DTO\RegrasDaPautaInput;
use App\Voto\Enum\BaseDaDecisao;
use App\Voto\Enum\RegraDeDecisao;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;

/**
 * Catálogo das pautas prontas do desenho 18 (bj-voto-cenarios.js: `CG`, `PES`, `MOD` e `cargoPara`): "Cargos e pautas
 * frequentes" e "Modelos prontos". Cada nome vira a pauta com as regras do cenário: pessoa (eleição de cargo, 2 dígitos,
 * sem nomes fictícios) ou pauta Sim/Não; "convenção" e "contrato social" pedem dois terços sobre o total de aptos.
 *
 * Os textos dos modelos entram como no desenho; o aval jurídico fica registrado à parte (item C10 do inventário).
 */
final class ModelosDePautas
{
    /** @var array<string, array{pauta: bool, criterio: string, base: string, quorum: int, pessoas: list<string>}> */
    private const CENARIOS = [
        'condominio' => ['pauta' => true, 'criterio' => 'mais', 'base' => 'validos', 'quorum' => 50, 'pessoas' => ['Síndico(a)', 'Subsíndico(a)', 'Conselho fiscal']],
        'cooperativa' => ['pauta' => true, 'criterio' => 'mais', 'base' => 'validos', 'quorum' => 0, 'pessoas' => ['Conselho de administração', 'Conselho fiscal']],
        'sociedade' => ['pauta' => true, 'criterio' => 'absoluta', 'base' => 'aptos', 'quorum' => 0, 'pessoas' => ['Eleição de administradores']],
        'associacao' => ['pauta' => false, 'criterio' => 'mais', 'base' => 'validos', 'quorum' => 0, 'pessoas' => []],
        'classe' => ['pauta' => false, 'criterio' => 'mais', 'base' => 'validos', 'quorum' => 0, 'pessoas' => []],
        'sindicato' => ['pauta' => false, 'criterio' => 'mais', 'base' => 'validos', 'quorum' => 0, 'pessoas' => []],
        'ensino' => ['pauta' => false, 'criterio' => 'mais', 'base' => 'validos', 'quorum' => 0, 'pessoas' => []],
        'consulta' => ['pauta' => true, 'criterio' => 'mais', 'base' => 'validos', 'quorum' => 0, 'pessoas' => []],
        'livre' => ['pauta' => false, 'criterio' => 'mais', 'base' => 'validos', 'quorum' => 0, 'pessoas' => []],
    ];

    /** "Cargos e pautas frequentes" por cenário (desenho: `CG`). @var array<string, list<string>> */
    private const ATALHOS = [
        'condominio' => ['Síndico(a)', 'Subsíndico(a)', 'Conselho fiscal', 'Aprovação de contas', 'Previsão orçamentária', 'Obra ou benfeitoria', 'Alteração da convenção'],
        'classe' => ['Diretoria', 'Conselho', 'Delegados', 'Caixa de assistência'],
        'associacao' => ['Presidência', 'Vice-presidência', 'Diretoria', 'Conselho fiscal', 'Conselho deliberativo'],
        'sindicato' => ['Diretoria executiva', 'Conselho fiscal', 'Delegados sindicais'],
        'cooperativa' => ['Conselho de administração', 'Conselho fiscal', 'Destinação das sobras', 'Prestação de contas'],
        'sociedade' => ['Aprovação das contas', 'Destinação dos lucros', 'Eleição de administradores', 'Alteração do contrato social'],
        'ensino' => ['Reitoria ou direção', 'Vice-direção', 'Coordenação de curso', 'Representação estudantil'],
        'consulta' => ['Pergunta da consulta'],
        'livre' => [],
    ];

    /** @var array<string, array{cenario: string, rotulo: string, pautas: list<string>}> */
    private const MODELOS = [
        'condominio_ago' => ['cenario' => 'condominio', 'rotulo' => 'AGO completa', 'pautas' => ['Aprovação de contas', 'Previsão orçamentária', 'Síndico(a)', 'Conselho fiscal']],
        'condominio_eleicao' => ['cenario' => 'condominio', 'rotulo' => 'Eleição de síndico e conselho', 'pautas' => ['Síndico(a)', 'Subsíndico(a)', 'Conselho fiscal']],
        'condominio_age_convencao' => ['cenario' => 'condominio', 'rotulo' => 'AGE: alteração da convenção', 'pautas' => ['Alteração da convenção']],
        'condominio_age_obra' => ['cenario' => 'condominio', 'rotulo' => 'AGE: obra ou benfeitoria', 'pautas' => ['Obra ou benfeitoria']],
        'cooperativa_ago' => ['cenario' => 'cooperativa', 'rotulo' => 'AGO completa', 'pautas' => ['Prestação de contas', 'Destinação das sobras', 'Conselho de administração', 'Conselho fiscal']],
        'sociedade_ago' => ['cenario' => 'sociedade', 'rotulo' => 'AGO de sócios', 'pautas' => ['Aprovação das contas', 'Destinação dos lucros', 'Eleição de administradores']],
        'associacao_eleicao' => ['cenario' => 'associacao', 'rotulo' => 'Eleição da diretoria', 'pautas' => ['Presidência', 'Vice-presidência', 'Conselho fiscal']],
    ];

    /**
     * @param string|null $cenario só os modelos desse cenário (desenho: `modelos(id)`); null traz todos
     *
     * @return array<string, array{cenario: string, rotulo: string, pautas: list<string>}> pela chave do modelo
     */
    public static function modelos(?string $cenario = null): array
    {
        return null === $cenario ? self::MODELOS : array_filter(self::MODELOS, static fn (array $m): bool => $m['cenario'] === $cenario);
    }

    /** @return list<string> os "Cargos e pautas frequentes" do cenário (desenho: `CG`) */
    public static function atalhos(string $cenario): array
    {
        return self::ATALHOS[$cenario] ?? [];
    }

    /** @return list<PautaInput> as pautas do modelo, na ordem do desenho */
    public static function pautasDoModelo(string $chave): array
    {
        $modelo = self::MODELOS[$chave] ?? throw new OperacaoInvalidaNaAssembleiaException('Modelo de pautas desconhecido.');

        return array_map(static fn (string $nome): PautaInput => self::pauta($modelo['cenario'], $nome), $modelo['pautas']);
    }

    /** A pauta de um nome do cenário (desenho: `cargoPara`). */
    public static function pauta(string $cenario, string $nome): PautaInput
    {
        $c = self::CENARIOS[$cenario] ?? self::CENARIOS['condominio'];
        $pessoa = !$c['pauta'] || \in_array($nome, $c['pessoas'], true);
        $criterio = RegraDeDecisao::from($c['criterio']);
        $base = BaseDaDecisao::from($c['base']);
        $quorum = $c['quorum'];
        $segunda = 0;
        if (1 === preg_match('/convenção|contrato social/iu', $nome)) {
            [$criterio, $base, $quorum, $segunda] = [RegraDeDecisao::DoisTercos, BaseDaDecisao::Aptos, 0, null];
        }
        if ($pessoa) {
            // Eleição de pessoas: 2 dígitos e as candidaturas ficam para o gestor preencher (sem nomes fictícios).
            if ($c['pauta']) {
                $criterio = RegraDeDecisao::Mais;
            }

            return PautaInput::eleicao($nome, new RegrasDaPautaInput($criterio, $base, 2, 1, $quorum, $segunda));
        }

        return new PautaInput($nome, null, PautaInput::OPCOES_PADRAO, new RegrasDaPautaInput($criterio, $base, 1, 1, $quorum, $segunda));
    }
}
