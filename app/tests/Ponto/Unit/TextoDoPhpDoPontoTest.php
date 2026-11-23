<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\Controller\JornadaColaboradorController;
use App\Ponto\Controller\JornadaTenantController;
use App\Ponto\Controller\LimitesDoBancoDoPontoController;
use App\Ponto\Controller\PontoController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Yaml\Yaml;

/**
 * Spec idioma-do-sistema §30 (idioma-ponto-php, PR A): os flashes e as respostas JSON de texto fixo dos controllers do
 * Ponto passam pelo tradutor no ponto de saída e moram em `ponto_servidor.*`. O português não pode mudar: cada chave
 * tem o texto exato que estava no controller (e `sprintf` antigo, nos que têm parâmetro).
 */
final class TextoDoPhpDoPontoTest extends TestCase
{
    /**
     * Chave => [texto que estava fixo no controller, parâmetros].
     *
     * @return iterable<string, array{0: string, 1: array<string, scalar>}>
     */
    public static function literais(): iterable
    {
        yield 'comum.payload_invalido' => ['Payload inválido.', []];
        yield 'comum.nao_autenticado' => ['Usuário não autenticado.', []];
        yield 'comum.sem_permissao_registrar' => ['Sem permissão para registrar ponto.', []];
        yield 'comum.jornada_alterada' => ['Esta jornada acabou de ser alterada; recarregue e tente de novo.', []];
        yield 'comum.numero_inteiro' => ['Informe um número inteiro.', []];
        yield 'ponto.esquecido_incompleto' => ['Informe o tipo e o horário do registro esquecido.', []];
        yield 'ponto.sem_data' => ['Selecione ao menos uma data para justificar.', []];
        yield 'ponto.motivo_vazio' => ['Informe o motivo.', []];
        yield 'ponto.motivo_longo' => ['O motivo pode ter até 2000 caracteres.', ['maximo' => '2000']];
        yield 'ponto.abono_uma_data' => ['Abono parcial só pode ser aplicado a uma única data.', []];
        yield 'ponto.esquecimento_uma_data' => ['Esquecimento de Registro só pode ser aplicado a uma única data.', []];
        yield 'ponto.abono_horarios' => ['Informe os horários de saída e retorno corretamente para o abono parcial.', []];
        yield 'ponto.data_futura' => [sprintf('A data %s é futura e foi ignorada.', '05/11/2026'), ['data' => '05/11/2026']];
        yield 'ponto.falta_nao_justificada' => [sprintf('Falta registrada como não justificada para %d dia(s).', 3), ['dias' => 3]];
        yield 'ponto.enviado_ao_gestor' => ['Enviado para aprovação do gestor.', []];
        yield 'ponto.token_invalido' => ['Token de segurança inválido.', []];
        yield 'ponto.anexo_trocado_lote' => [sprintf('O anexo foi trocado nos %d dias deste abono.', 2), ['dias' => 2]];
        yield 'ponto.justificativa_atualizada' => ['Justificativa atualizada com sucesso.', []];
        yield 'ponto.sem_tenant' => ['Usuário sem tenant configurado.', []];
        yield 'ponto.payload_invalido_batida' => ['Payload invalido.', []];
        yield 'ponto.tipo_obrigatorio' => ['Tipo de registro e obrigatorio (Entrada, Repouso, Retorno ou Saida).', []];
        yield 'ponto.tipo_invalido' => ['Tipo de registro invalido. Selecione Entrada, Repouso, Retorno ou Saida.', []];
        yield 'ponto.geo_obrigatoria' => ['Geolocalização é obrigatória para registrar o ponto.', []];
        yield 'ponto.coordenadas_invalidas' => ['Coordenadas inválidas para registro do ponto.', []];
        yield 'ponto.coordenadas_fora_da_faixa' => ['Coordenadas fora da faixa válida.', []];
        yield 'ponto.precisao_invalida' => ['Precisão do GPS inválida.', []];
        yield 'ponto.registrado_home_office' => ['Ponto (home office) registrado com sucesso!', []];
        yield 'ponto.registrado' => ['Ponto registrado com sucesso!', []];
        yield 'ponto.comprovante_na_aba' => ['Comprovante na aba Comprovantes.', []];
        yield 'equipe.escala_da_lista' => ['Escolha uma das escalas da lista.', []];
        yield 'equipe.selecione_justificativa' => ['Selecione ao menos uma justificativa.', []];
        yield 'equipe.abonadas' => ['2 justificativas abonadas.', ['quantidade' => 2, 'numero' => '2']];
        yield 'feriado.cadastrado' => ['Feriado cadastrado com sucesso.', []];
        yield 'feriado.atualizado' => ['Feriado atualizado com sucesso.', []];
        yield 'feriado.excluido' => ['Feriado excluído.', []];
        yield 'configuracoes.feriado_cadastrado' => ['Feriado cadastrado.', []];
        yield 'configuracoes.feriado_removido' => ['Feriado removido.', []];
        yield 'configuracoes.funcao_ligada_ou_desligada' => ['Informe se a função fica ligada ou desligada.', []];
        yield 'configuracoes.externo_permitido' => ['Informe se o trabalho externo fica permitido.', []];
        yield 'home_office.dias_invalidos' => ['Dias da semana inválidos (use inteiros de 1 a 7).', []];
        yield 'home_office.datas_invalidas' => ['Datas avulsas inválidas (use o formato AAAA-MM-DD).', []];
        yield 'home_office.vigencia_invalida' => ['Vigência inválida (use o formato AAAA-MM-DD).', []];
        yield 'home_office.vigencia_invertida' => ['A data inicial da vigência não pode ser maior que a final.', []];
        yield 'jornada.carga_semanal' => ['Carga horária semanal deve estar entre 1h e 44h.', []];
        yield 'jornada.repouso_minimo' => ['Intervalo mínimo de repouso deve estar entre 1 e 480 minutos.', []];
        yield 'jornada.interjornada_minima' => ['Interjornada mínima deve estar entre 1 e 24 horas.', []];
        yield 'jornada.max_blocos' => ['Máximo de 7 blocos de jornada.', []];
        yield 'jornada.data_vigencia' => ['Informe a data em que a jornada passa a valer (dd/mm/aaaa).', []];
        yield 'jornada.bloco_sem_dias' => [sprintf('Bloco %d não tem dias da semana definidos.', 2), ['n' => 2]];
        yield 'jornada.dia_invalido' => [sprintf('Dia inválido no bloco %d: %d.', 2, 9), ['n' => 2, 'dia' => 9]];
        yield 'jornada.dia_repetido' => [sprintf('Dia %d aparece em mais de um bloco.', 3), ['dia' => 3]];
        yield 'jornada.bloco_carga_invalida' => [sprintf('Bloco %d tem carga horária inválida (deve ser positiva).', 1), ['n' => 1]];
        yield 'jornada.soma_blocos' => [
            sprintf('Soma dos blocos (%dh%02dm) não bate com a carga semanal (%dh%02dm).', 8, 5, 44, 0),
            ['soma' => sprintf('%dh%02dm', 8, 5), 'carga' => sprintf('%dh%02dm', 44, 0)],
        ];
        yield 'ajuste.incluida' => ['Batida incluída na folha.', []];
        yield 'ajuste.ajustada' => ['Batida ajustada. O registro original fica guardado.', []];
        yield 'ajuste.desconsiderada' => ['Batida desconsiderada. O registro original fica guardado.', []];
        yield 'ajuste.restaurada' => ['Batida restaurada.', []];
        yield 'ajuste.erro_dos_campos' => ['Erro ao registrar batida. Verifique os campos.', []];
        yield 'horas_pagas.registrado' => ['Lançamento de horas pagas registrado.', []];
        yield 'horas_pagas.atualizado' => ['Lançamento de horas pagas atualizado.', []];
        yield 'horas_pagas.excluido' => ['Lançamento de horas pagas excluído.', []];
        yield 'horas_pagas.erro_dos_campos' => ['Erro ao registrar horas pagas. Verifique os campos.', []];
        yield 'horas_pagas.banco_negativo' => [
            sprintf('Atenção: este desconto deixou o banco de horas negativo em -%dh%02d.', intdiv(95, 60), 95 % 60),
            ['saldo' => sprintf('%dh%02d', intdiv(95, 60), 95 % 60)],
        ];
    }

    /** @return iterable<string, array{0: string, 1: string, 2: array<string, scalar>}> */
    public static function casos(): iterable
    {
        foreach (self::literais() as $chave => [$literal, $parametros]) {
            yield $chave => [$chave, $literal, $parametros];
        }
    }

    /**
     * @param array<string, scalar> $parametros
     */
    #[DataProvider('casos')]
    #[TestDox('pt_BR de $chave é o literal antigo e o en é outro texto')]
    public function testPtBrIgualAoLiteralAntigoEEnDiferente(string $chave, string $literal, array $parametros): void
    {
        $completa = 'ponto_servidor.' . $chave;

        self::assertSame($literal, self::tradutor()->trans($completa, $parametros, null, 'pt_BR'), $chave . ' em pt_BR');
        $en = self::tradutor()->trans($completa, $parametros, null, 'en');
        self::assertNotSame($completa, $en, $chave . ' sem en');
        self::assertNotSame($literal, $en, $chave . ' igual ao português em en');
    }

    #[TestDox('A lista acima cobre todas as chaves do bloco, e o en tem as mesmas chaves do pt_BR')]
    public function testListaCobreOBlocoInteiro(): void
    {
        $pt = self::chavesDoBloco('pt_BR');
        $en = self::chavesDoBloco('en');
        $cobertas = array_keys(iterator_to_array(self::literais()));

        sort($pt);
        sort($en);
        sort($cobertas);
        self::assertSame($pt, $cobertas, 'chave do catálogo fora da lista (ou o inverso)');
        self::assertSame($pt, $en, 'pt_BR e en com chaves diferentes');
    }

    #[TestDox('Toda chave do bloco é usada por um controller do Ponto')]
    public function testTodaChaveTemUso(): void
    {
        $fontes = '';
        foreach (glob(\dirname(__DIR__, 3) . '/src/Ponto/Controller/*.php') ?: [] as $arquivo) {
            // Só código: comentário citando a chave não conta como uso.
            foreach (token_get_all((string) file_get_contents($arquivo)) as $token) {
                if (!\is_array($token) || !\in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $fontes .= \is_array($token) ? $token[1] : $token;
                }
            }
        }
        foreach (self::chavesDoBloco('pt_BR') as $chave) {
            self::assertStringContainsString("trans('ponto_servidor." . $chave . "'", $fontes, $chave . ' sem uso nos controllers');
        }
    }

    #[TestDox('As constantes mantidas nos controllers são o pt_BR da chave correspondente')]
    public function testConstantesMantidasIguaisAoCatalogo(): void
    {
        $tradutor = self::tradutor();

        self::assertSame($tradutor->trans('ponto_servidor.comum.jornada_alterada', [], null, 'pt_BR'), JornadaTenantController::JORNADA_ALTERADA_AO_MESMO_TEMPO);
        self::assertSame($tradutor->trans('ponto_servidor.comum.jornada_alterada', [], null, 'pt_BR'), JornadaColaboradorController::JORNADA_ALTERADA_AO_MESMO_TEMPO);
        self::assertSame($tradutor->trans('ponto_servidor.ponto.enviado_ao_gestor', [], null, 'pt_BR'), PontoController::AVISO_ENVIADO_AO_GESTOR);
        self::assertSame($tradutor->trans('ponto_servidor.comum.numero_inteiro', [], null, 'pt_BR'), LimitesDoBancoDoPontoController::SEM_NUMERO_INTEIRO);
    }

    /** @return list<string> */
    private static function chavesDoBloco(string $locale): array
    {
        $bloco = Yaml::parseFile(self::arquivo($locale))['ponto_servidor'];
        $chaves = [];
        foreach ($bloco as $grupo => $itens) {
            // `recusa` guarda o texto das exceções de domínio (chave citada em CHAVE_DA_RECUSA); quem confere é o
            // RecusasDoPontoNoCatalogoTest; `batida` guarda o texto dos Services da batida (CHAVE_DO_TEXTO de cada um),
            // conferido pelo TextosDaBatidaNoCatalogoTest.
            if ($grupo === 'recusa' || $grupo === 'batida') {
                continue;
            }
            foreach (array_keys($itens) as $item) {
                $chaves[] = $grupo . '.' . $item;
            }
        }

        return $chaves;
    }

    private static function arquivo(string $locale): string
    {
        return \dirname(__DIR__, 3) . '/translations/ponto_servidor/messages+intl-icu.' . $locale . '.yaml';
    }

    private static function tradutor(): Translator
    {
        static $tradutor = null;
        if ($tradutor === null) {
            $tradutor = new Translator('pt_BR');
            $tradutor->addLoader('yaml', new YamlFileLoader());
            foreach (['pt_BR', 'en'] as $locale) {
                $tradutor->addResource('yaml', self::arquivo($locale), $locale, 'messages+intl-icu');
            }
        }

        return $tradutor;
    }
}
