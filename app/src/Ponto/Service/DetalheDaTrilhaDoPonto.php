<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Ponto\Entity\AceiteDaFolhaDoPonto;
use App\Ponto\Entity\FolhaEmitidaDoPonto;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\PontoAjuste;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Enum\AcaoDaTrilhaDoPonto;
use App\Ponto\Enum\TipoDoAjuste;
use App\Ponto\Enum\TipoJustificativa;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * O `detalhe` dos eventos da trilha do Ponto (E9): monta o que cada evento grava (ids, datas em ISO, o nome do
 * colaborador no momento) e escreve o texto da coluna "Detalhe" da aba Auditoria a partir dele.
 *
 * O detalhe gravado é selado: mudar o que se monta aqui vale só para os eventos novos. O texto, não: é lido de novo a
 * cada vez e pode mudar à vontade, inclusive para eventos antigos. Nada de observação livre, anexo ou coordenada no
 * detalhe: a trilha diz quem fez o quê, quando e de quem.
 *
 * O texto segue o do desenho 16 (dc L544, L601, L768), com as partes separadas por " · ": a batida é "Entrada às
 * 08:01:12", com " · trabalho externo" (E12) quando foi fora do raio e " · sem internet" quando veio da fila; a
 * justificativa é "dd/mm/aaaa · Tipo · Saída às 18:00". Ficam,
 * como extras necessários, o nome do colaborador na frente (o autor da linha nem sempre é ele), a data da batida (a
 * da fila e a do esquecimento não são do dia do evento), o " · home office" (a marca do lugar, como o " · trabalho
 * externo" do desenho) e o período do lote ("dd/mm/aaaa a dd/mm/aaaa (N dias)", ou "dd/mm/aaaa (N justificativas)"
 * num dia só), que grava um evento só para o envio inteiro.
 */
final class DetalheDaTrilhaDoPonto
{
    private const BATIDAS = ['entrada' => 'Entrada', 'repouso' => 'Repouso', 'retorno' => 'Retorno', 'saida' => 'Saída'];

    private const SITUACOES = ['pendente' => 'em análise', 'abonado' => 'abonada', 'rejeitado' => 'recusada'];

    /** O "nome" que pede o título de uma função: o português e a chave vêm de {@see FuncoesDoPonto}, não da tabela abaixo. */
    private const TITULO_DA_FUNCAO = 'titulo_da_funcao';

    /** O prefixo das chaves do texto da trilha no catálogo `ponto_servidor`. */
    public const PREFIXO_DA_CHAVE = 'ponto_servidor.rotulo.trilha.';

    /**
     * Os pedaços do texto da coluna "Detalhe", em português, pelo nome da chave (depois de {@see self::PREFIXO_DA_CHAVE}),
     * com `{nome}` onde entra um valor. `texto()` usa estes (o português de sempre); `textoTraduzido()` usa o catálogo,
     * e o teste amarra o pt_BR do catálogo a esta lista. Ficam em português, por virem do banco ou de enum
     * compartilhado: o nome da pessoa, o código da folha, o tipo da justificativa e o texto da escala trocada.
     *
     * @var array<string, string>
     */
    public const PORTUGUES = [
        'colaborador' => 'Colaborador',
        'batida' => 'Batida',
        'justificativa' => 'Justificativa',
        'funcao' => 'Função',
        'batida_entrada' => 'Entrada',
        'batida_repouso' => 'Repouso',
        'batida_retorno' => 'Retorno',
        'batida_saida' => 'Saída',
        'momento' => '{hora} de {data}',
        'as' => '{item} às {hora}',
        'marca_home_office' => 'home office',
        'marca_trabalho_externo' => 'trabalho externo',
        'marca_sem_internet' => 'sem internet',
        'pedida_no_esquecimento' => 'pedida no esquecimento',
        'incluida' => '{batida} incluída',
        'ajustada' => '{antes} passou para {depois}',
        'desconsiderada' => '{batida} desconsiderada',
        'restaurada' => '{batida} restaurada',
        'desconsiderada_ao_voltar' => '{batida}, do esquecimento abonado, desconsiderada ao voltar a justificativa para análise',
        'sincronizacao_uma' => '{quantidade} batida enviada ao voltar a internet',
        'sincronizacao_varias' => '{quantidade} batidas enviadas ao voltar a internet',
        'data_nao_informada' => 'data não informada',
        'escala_versao_salva' => 'versão da jornada a partir de {data} salva',
        'escala_versao_excluida' => 'versão da jornada de {data} excluída',
        'escala_limpa' => 'jornada personalizada limpa (volta a do escritório)',
        'escala_alterada' => 'escala alterada',
        'competencia_nao_informada' => 'competência não informada',
        'situacao_pendente' => 'em análise',
        'situacao_abonado' => 'abonada',
        'situacao_rejeitado' => 'recusada',
        'estava' => 'estava {situacao}',
        'parcial' => 'Parcial',
        'parcial_horario' => 'Parcial: {de} às {ate}',
        'periodo' => '{de} a {ate}',
        'periodo_dias' => '{periodo} ({quantidade} dias)',
        'periodo_justificativas' => '{periodo} ({quantidade} justificativas)',
        'equipe_uma' => '1 justificativa da equipe',
        'equipe_varias' => '{quantidade} justificativas da equipe',
        'configuracao_ativada' => '{titulo} ativado',
        'configuracao_desativada' => '{titulo} desativado',
        'sistema' => 'Sistema',
    ];

    /** A chave da coluna "Detalhe" no catálogo, pelo nome em {@see self::PORTUGUES}. */
    public static function chaveDoTexto(string $nome): string
    {
        return isset(self::PORTUGUES[$nome]) ? self::PREFIXO_DA_CHAVE . $nome : throw new \LogicException(sprintf('Texto da trilha sem chave no catálogo: "%s".', $nome));
    }

    /** A chave que separa o "Abonar selecionadas" da aba Equipe ({@see daEquipe}) do "aprovar todos" da ficha. */
    private const DA_EQUIPE = 'da_equipe';

    /** @return array<string, mixed> */
    public static function daBatida(RegistroPonto $batida): array
    {
        return self::colaborador($batida->getUser()) + [
            'tipo'             => $batida->getTipo(),
            'data_hora'        => $batida->getDataHora()?->format('Y-m-d H:i:s'),
            'home_office'      => $batida->isHomeOffice(),
            'sede'             => $batida->getSedeNomeSnapshot(),
            'trabalho_externo' => $batida->isTrabalhoExterno(),   // E12
            'sem_internet'     => $batida->isEnviadaSemInternet(),
        ];
    }

    /**
     * Um ajuste de batida (E10): o colaborador, a linhagem (o ajuste, o alvo, o que ele desfaz, a justificativa) e a
     * marcação antes e depois, cada uma com tipo e data/hora, ou nula onde não há (a inclusão não tem anterior, a
     * desconsideração não tem nova). Sem o motivo, que é texto livre.
     *
     * @return array<string, mixed>
     */
    public static function daAjuste(PontoAjuste $ajuste): array
    {
        return self::colaborador($ajuste->getUser()) + [
            'ajuste_id'        => $ajuste->getId(),
            'alvo'             => $ajuste->alvo(),
            'anterior'         => self::marcacao($ajuste->getTipoMarcacaoAnterior(), $ajuste->getDataHoraAnterior()),
            'novo'             => self::marcacao($ajuste->getTipoMarcacaoNova(), $ajuste->getDataHoraNova()),
            'justificativa_id' => $ajuste->getJustificativa()?->getId(),
            'desfaz_id'        => $ajuste->getDesfaz()?->getId(),
        ];
    }

    /**
     * A inclusão que o abono de um esquecimento criou (E10): o evento continua `batida_do_esquecimento`, com o detalhe do
     * ajuste e, como a batida de antes da E10, o tipo e a data/hora no primeiro nível (o texto da trilha não muda).
     *
     * @return array<string, mixed>
     */
    public static function daInclusaoDoEsquecimento(PontoAjuste $inclusao): array
    {
        if ($inclusao->getTipo() !== TipoDoAjuste::Inclusao || $inclusao->getJustificativa() === null) {
            throw new \LogicException('O evento do esquecimento abonado é de uma inclusão ligada à justificativa.');
        }

        return self::daAjuste($inclusao) + [
            'tipo'      => $inclusao->getTipoMarcacaoNova(),
            'data_hora' => $inclusao->getDataHoraNova()?->format('Y-m-d H:i:s'),
        ];
    }

    /** @return array<string, mixed> */
    public static function daJustificativa(JustificativaPonto $justificativa): array
    {
        return self::colaborador($justificativa->getUser()) + [
            'justificativa_id' => $justificativa->getId(),
            'tipo'             => $justificativa->getTipo(),
            'data'             => $justificativa->getData()?->format('Y-m-d'),
        ] + self::esquecimento($justificativa) + self::parcial($justificativa);
    }

    /**
     * Um lote (as justificativas de um envio, de um lançamento ou de um abono em lote), num evento só.
     *
     * @param list<JustificativaPonto> $lote
     *
     * @return array<string, mixed>
     */
    public static function doLote(array $lote): array
    {
        if ($lote === []) {
            throw new \LogicException('Evento de lote sem nenhuma justificativa.');
        }
        $datas = array_map(static fn (JustificativaPonto $j): string => (string) $j->getData()?->format('Y-m-d'), $lote);
        sort($datas);

        return self::colaborador($lote[0]->getUser()) + [
            'justificativa_ids' => array_map(static fn (JustificativaPonto $j): ?int => $j->getId(), $lote),
            'tipo'              => $lote[0]->getTipo(),
            'de'                => $datas[0],
            'ate'               => $datas[\count($datas) - 1],
            'quantidade'        => \count($lote),
        ] + self::esquecimento($lote[0]) + self::parcial($lote[0]);
    }

    /**
     * O "Abonar selecionadas" da aba Equipe (E5, desenho L818): as justificativas da equipe abonadas de uma vez, de
     * várias pessoas, num evento "Abono em lote" só. Guarda de cada uma o id, a pessoa e o dia, para a auditoria saber
     * quem foi abonado; a tela mostra só a quantidade, como o desenho. A chave `da_equipe` separa este detalhe do
     * "aprovar todos" da ficha ({@see doLote}, de uma pessoa só), que tem o texto dele.
     *
     * @param list<JustificativaPonto> $abonadas
     *
     * @return array<string, mixed>
     */
    public static function daEquipe(array $abonadas): array
    {
        if ($abonadas === []) {
            throw new \LogicException('Evento de lote sem nenhuma justificativa.');
        }

        return [
            self::DA_EQUIPE => array_map(static fn (JustificativaPonto $j): array => [
                'justificativa_id' => $j->getId(),
            ] + self::colaborador($j->getUser()) + [
                'data' => $j->getData()?->format('Y-m-d'),
            ], $abonadas),
            'quantidade' => \count($abonadas),
        ];
    }

    /**
     * A troca de escala (versão da jornada salva ou excluída, escala pessoal limpa). A troca da aba Equipe leva o
     * rótulo da escala do catálogo em `escala` (desenho L778); a ficha, sem rótulo, fica sem a chave.
     *
     * @param 'versao_salva'|'versao_excluida'|'escala_limpa' $operacao
     *
     * @return array<string, mixed>
     */
    public static function daEscala(User $pessoa, string $operacao, ?\DateTimeInterface $valeAPartirDe = null, ?string $escala = null): array
    {
        return self::colaborador($pessoa) + [
            'operacao'          => $operacao,
            'vale_a_partir_de'  => $valeAPartirDe?->format('Y-m-d'),
        ] + ($escala === null ? [] : ['escala' => $escala]);
    }

    /**
     * A chave "Escritório" de uma função do Ponto (E7), ligada ou desligada pelo administrador do ponto. O título é o da
     * função no momento, como a linha da aba Configurações o mostra.
     *
     * @return array{chave: string, titulo: string, ligada: bool}
     */
    public static function daConfiguracao(string $chave, string $titulo, bool $ligada): array
    {
        return ['chave' => $chave, 'titulo' => $titulo, 'ligada' => $ligada];
    }

    /**
     * A rodada da fila sem internet que enviou batidas (E14, dc L438): quem, quais batidas e quantas.
     *
     * @param list<int> $registroIds
     *
     * @return array<string, mixed>
     */
    public static function daSincronizacao(User $pessoa, array $registroIds): array
    {
        return self::colaborador($pessoa) + ['registro_ids' => $registroIds, 'quantidade' => \count($registroIds)];
    }

    /**
     * A folha emitida com código BJF (E16, decisão 18): a pessoa da folha, a competência e o código. Quem emitiu (a
     * própria pessoa ou o gestor) é o autor do evento.
     *
     * @return array<string, mixed>
     */
    public static function daFolhaEmitida(FolhaEmitidaDoPonto $folha): array
    {
        return self::colaborador($folha->getUser()) + [
            'folha_emitida_id' => $folha->getId(),
            'ano'              => $folha->getAno(),
            'mes'              => $folha->getMes(),
            'codigo'           => $folha->getCodigo(),
        ];
    }

    /**
     * O aceite da folha (E16, decisões 1 e 14): a pessoa, a competência, o número do aceite e o código da folha vista.
     * Sem o texto da ressalva, que fica só no aceite selado.
     *
     * @return array<string, mixed>
     */
    public static function doAceiteDaFolha(AceiteDaFolhaDoPonto $aceite): array
    {
        return self::colaborador($aceite->getUser()) + [
            'aceite_id' => $aceite->getId(),
            'ano'       => $aceite->getAno(),
            'mes'       => $aceite->getMes(),
            'numero'    => $aceite->getNumero(),
            'codigo'    => $aceite->getFolhaVista()->getCodigo(),
        ];
    }

    /** @param array<string, mixed> $detalhe */
    public static function texto(string $acao, array $detalhe): string
    {
        return self::redigir($acao, $detalhe, self::emPortugues(...));
    }

    /**
     * O mesmo texto de {@see self::texto()}, com cada pedaço traduzido pelo catálogo (o pt_BR é idêntico). O que vem do
     * banco (nome, código, tipo da justificativa, escala trocada) segue como foi gravado.
     *
     * @param array<string, mixed> $detalhe
     */
    public static function textoTraduzido(string $acao, array $detalhe, TranslatorInterface $tradutor): string
    {
        return self::redigir($acao, $detalhe, static fn (string $nome, array $parametros = []): string => $nome === self::TITULO_DA_FUNCAO
            ? $tradutor->trans(FuncoesDoPonto::chaveDoTitulo($parametros['chave']))
            : $tradutor->trans(self::chaveDoTexto($nome), $parametros));
    }

    /** @param array<string, string> $parametros */
    private static function emPortugues(string $nome, array $parametros = []): string
    {
        if ($nome === self::TITULO_DA_FUNCAO) {
            return FuncoesDoPonto::COM_CAMADA_ESCRITORIO[$parametros['chave']]['titulo'];
        }
        $chaves = array_map(static fn (string $chave): string => '{' . $chave . '}', array_keys($parametros));

        return strtr(self::PORTUGUES[$nome] ?? throw new \LogicException(sprintf('Texto da trilha sem chave no catálogo: "%s".', $nome)), array_combine($chaves, array_values($parametros)));
    }

    /**
     * @param array<string, mixed>                                       $detalhe
     * @param \Closure(string, array<string, string>=): string $d      o pedaço de texto pelo nome (português ou catálogo)
     */
    private static function redigir(string $acao, array $detalhe, \Closure $d): string
    {
        $evento = AcaoDaTrilhaDoPonto::tryFrom($acao);
        if ($evento === AcaoDaTrilhaDoPonto::ConfiguracaoDoEscritorio) {
            return self::textoDaConfiguracao($detalhe, $d);
        }
        if ($evento === AcaoDaTrilhaDoPonto::AbonoEmLote && \is_array($detalhe[self::DA_EQUIPE] ?? null)) {
            return self::textoDaEquipe($detalhe, $d);   // várias pessoas: sem o "Nome: " na frente (desenho L818)
        }
        $quem = self::textoDe($detalhe, 'colaborador') ?? $d('colaborador');
        $escala = $evento === AcaoDaTrilhaDoPonto::EscalaAlterada ? self::textoDe($detalhe, 'escala') : null;
        if ($escala !== null) {
            return $quem . ' · ' . $escala;   // a troca da aba Equipe, como o desenho (L778): "Ana · 44h · seg a sáb (7h20)"
        }

        return $quem . ': ' . match ($evento) {
            AcaoDaTrilhaDoPonto::BatidaRegistrada     => self::textoDaBatida($detalhe, $d),
            AcaoDaTrilhaDoPonto::BatidaDoEsquecimento => self::textoDaBatida($detalhe, $d) . ' · ' . $d('pedida_no_esquecimento'),
            AcaoDaTrilhaDoPonto::JustificativaEnviada,
            AcaoDaTrilhaDoPonto::JustificativaLancada,
            AcaoDaTrilhaDoPonto::AbonoEmLote          => self::textoDoLote($detalhe, $d),
            AcaoDaTrilhaDoPonto::JustificativaReaberta => self::textoDaJustificativa($detalhe, $d) . self::textoDaSituacaoAnterior($detalhe, $d),
            AcaoDaTrilhaDoPonto::EscalaAlterada       => self::textoDaEscala($detalhe, $d),
            AcaoDaTrilhaDoPonto::Sincronizacao        => self::textoDaSincronizacao($detalhe, $d),
            AcaoDaTrilhaDoPonto::BatidaIncluida       => $d('incluida', ['batida' => self::textoDaMarcacao($detalhe, 'novo', $d)]),
            AcaoDaTrilhaDoPonto::BatidaAjustada       => $d('ajustada', ['antes' => self::textoDaMarcacao($detalhe, 'anterior', $d), 'depois' => self::textoDaMarcacao($detalhe, 'novo', $d)]),
            AcaoDaTrilhaDoPonto::BatidaDesconsiderada => $d('desconsiderada', ['batida' => self::textoDaMarcacao($detalhe, 'anterior', $d)]),
            AcaoDaTrilhaDoPonto::AjusteDesfeito       => self::textoDoAjusteDesfeito($detalhe, $d),
            AcaoDaTrilhaDoPonto::FolhaEmitida         => self::textoDaFolhaEmitida($detalhe, $d),
            AcaoDaTrilhaDoPonto::FolhaAssinada,
            AcaoDaTrilhaDoPonto::FolhaAssinadaComRessalva => self::textoDaCompetencia($detalhe, $d),
            default                                   => self::textoDaJustificativa($detalhe, $d),
        };
    }

    /** @return array{colaborador_id: ?int, colaborador: ?string} */
    private static function colaborador(?User $pessoa): array
    {
        $nome = $pessoa === null ? null : trim((string) $pessoa->getFullName());

        return [
            'colaborador_id' => $pessoa?->getId(),
            'colaborador'    => $nome !== null && $nome !== '' ? $nome : $pessoa?->getEmail(),
        ];
    }

    /** @return array<string, string> */
    private static function esquecimento(JustificativaPonto $justificativa): array
    {
        $tipo = $justificativa->getTipoRegistroEsquecido();
        $hora = $justificativa->getHoraRegistroEsquecido();
        if ($tipo === null || $tipo === '' || $hora === null) {
            return [];
        }

        return ['batida_esquecida' => $tipo, 'hora_esquecida' => $hora->format('H:i')];
    }

    /**
     * O abono parcial (só o horário, como o subtítulo da lista): `parcial` e, quando há, `parcial_de` e `parcial_ate`.
     *
     * @return array<string, bool|string>
     */
    private static function parcial(JustificativaPonto $justificativa): array
    {
        if (!$justificativa->isAbonoParcial()) {
            return [];
        }
        $de  = $justificativa->getHoraInicioAbono();
        $ate = $justificativa->getHoraFimAbono();

        return ['parcial' => true] + ($de !== null && $ate !== null ? ['parcial_de' => $de->format('H:i'), 'parcial_ate' => $ate->format('H:i')] : []);
    }

    /**
     * @param array<string, mixed> $detalhe
     * @param \Closure(string, array<string, string>=): string $d o pedaço de texto pelo nome (português ou catálogo)
     */
    private static function textoDaBatida(array $detalhe, \Closure $d): string
    {
        $codigo  = self::textoDe($detalhe, 'tipo');
        $tipo    = isset(self::BATIDAS[$codigo ?? '']) ? $d('batida_' . $codigo) : ($codigo ?? $d('batida'));
        $momento = self::momentoDaBatida(self::textoDe($detalhe, 'data_hora'), $d);
        $marcas  = (($detalhe['home_office'] ?? false) === true ? ' · ' . $d('marca_home_office') : '')
            . (($detalhe['trabalho_externo'] ?? false) === true ? ' · ' . $d('marca_trabalho_externo') : '')
            . (($detalhe['sem_internet'] ?? false) === true ? ' · ' . $d('marca_sem_internet') : '');

        return ($momento !== null ? $d('as', ['item' => $tipo, 'hora' => $momento]) : $tipo) . $marcas;
    }

    /**
     * Um lado do ajuste ('anterior' ou 'novo') no texto da batida: "Saída às 18:00:00 de 21/10/2026".
     *
     * @param array<string, mixed> $detalhe
     * @param \Closure(string, array<string, string>=): string $d o pedaço de texto pelo nome (português ou catálogo)
     */
    private static function textoDaMarcacao(array $detalhe, string $lado, \Closure $d): string
    {
        $marcacao = $detalhe[$lado] ?? null;

        /** @var array<string, mixed> $marcacao */
        $marcacao = is_array($marcacao) ? $marcacao : [];

        return self::textoDaBatida($marcacao, $d);
    }

    /**
     * A restauração traz a batida de volta (tem a nova); sem a nova, é a inclusão do esquecimento desconsiderada quando a
     * justificativa voltou para análise.
     *
     * @param array<string, mixed> $detalhe
     * @param \Closure(string, array<string, string>=): string $d o pedaço de texto pelo nome (português ou catálogo)
     */
    private static function textoDoAjusteDesfeito(array $detalhe, \Closure $d): string
    {
        if (is_array($detalhe['novo'] ?? null)) {
            return $d('restaurada', ['batida' => self::textoDaMarcacao($detalhe, 'novo', $d)]);
        }

        return $d('desconsiderada_ao_voltar', ['batida' => self::textoDaMarcacao($detalhe, 'anterior', $d)]);
    }

    /** @return array{tipo: string, data_hora: string}|null */
    private static function marcacao(?string $tipo, ?\DateTimeImmutable $dataHora): ?array
    {
        if ($tipo === null || $dataHora === null) {
            return null;
        }

        return ['tipo' => $tipo, 'data_hora' => $dataHora->format('Y-m-d H:i:s')];
    }

    /**
     * @param array<string, mixed> $detalhe
     * @param \Closure(string, array<string, string>=): string $d o pedaço de texto pelo nome (português ou catálogo)
     */
    private static function textoDaJustificativa(array $detalhe, \Closure $d): string
    {
        $data = self::momento(self::textoDe($detalhe, 'data'), 'd/m/Y');

        return self::partes([$data, self::tipoDaJustificativa($detalhe, $d), self::subtitulo($detalhe, $d)]);
    }

    /**
     * @param array<string, mixed> $detalhe
     * @param \Closure(string, array<string, string>=): string $d o pedaço de texto pelo nome (português ou catálogo)
     */
    private static function textoDoLote(array $detalhe, \Closure $d): string
    {
        $de         = self::momento(self::textoDe($detalhe, 'de'), 'd/m/Y');
        $ate        = self::momento(self::textoDe($detalhe, 'ate'), 'd/m/Y');
        $quantidade = (int) ($detalhe['quantidade'] ?? 0);
        $periodo    = match (true) {
            $de === null || $ate === null => $de,
            $de !== $ate                  => self::comQuantidade($d('periodo', ['de' => $de, 'ate' => $ate]), 'periodo_dias', $quantidade, $d),
            default                       => self::comQuantidade($de, 'periodo_justificativas', $quantidade, $d),
        };

        return self::partes([$periodo, self::tipoDaJustificativa($detalhe, $d), self::subtitulo($detalhe, $d)]);
    }

    /** O período do lote com o "(N dias)" ou "(N justificativas)" quando são várias; uma só fica sem. */
    private static function comQuantidade(string $periodo, string $nome, int $quantidade, \Closure $d): string
    {
        return $quantidade > 1 ? $d($nome, ['periodo' => $periodo, 'quantidade' => (string) $quantidade]) : $periodo;
    }

    /**
     * "1 justificativa da equipe" ou "N justificativas da equipe" (desenho L818, no plural certo).
     *
     * @param array<string, mixed> $detalhe
     * @param \Closure(string, array<string, string>=): string $d o pedaço de texto pelo nome (português ou catálogo)
     */
    private static function textoDaEquipe(array $detalhe, \Closure $d): string
    {
        $quantidade = \is_int($detalhe['quantidade'] ?? null) ? $detalhe['quantidade'] : \count((array) $detalhe[self::DA_EQUIPE]);

        return $quantidade === 1 ? $d('equipe_uma') : $d('equipe_varias', ['quantidade' => (string) $quantidade]);
    }

    /**
     * Como o desenho (dc L761): "Geolocalização desativado", sem o nome de um colaborador na frente (a chave é do
     * escritório; quem mudou está na coluna "Autor").
     *
     * @param array<string, mixed> $detalhe
     * @param \Closure(string, array<string, string>=): string $d o pedaço de texto pelo nome (português ou catálogo)
     */
    private static function textoDaConfiguracao(array $detalhe, \Closure $d): string
    {
        $titulo = self::tituloDaFuncao($detalhe, $d);

        return $d(($detalhe['ligada'] ?? null) === true ? 'configuracao_ativada' : 'configuracao_desativada', ['titulo' => $titulo]);
    }

    /**
     * O título gravado da função. Se é o de hoje de uma função conhecida, sai pelo catálogo (traduzido); um título
     * gravado diferente (de uma versão antiga do texto) fica como foi gravado, e sem título cai na chave ou em "Função".
     *
     * @param array<string, mixed> $detalhe
     * @param \Closure(string, array<string, string>=): string $d o pedaço de texto pelo nome (português ou catálogo)
     */
    private static function tituloDaFuncao(array $detalhe, \Closure $d): string
    {
        $titulo = self::textoDe($detalhe, 'titulo');
        $chave  = self::textoDe($detalhe, 'chave');
        if ($titulo !== null && $chave !== null && ($titulo === (FuncoesDoPonto::COM_CAMADA_ESCRITORIO[$chave]['titulo'] ?? null))) {
            return $d(self::TITULO_DA_FUNCAO, ['chave' => $chave]);
        }

        return $titulo ?? $chave ?? $d('funcao');
    }

    /**
     * Como o desenho (dc L438), "N batida(s) enviada(s) ao voltar a internet", com o plural escrito por inteiro, como as
     * mensagens do abono em lote da E5: "1 batida enviada" e "3 batidas enviadas".
     *
     * @param array<string, mixed> $detalhe
     * @param \Closure(string, array<string, string>=): string $d o pedaço de texto pelo nome (português ou catálogo)
     */
    private static function textoDaSincronizacao(array $detalhe, \Closure $d): string
    {
        $quantidade = (int) ($detalhe['quantidade'] ?? 0);

        return $d($quantidade === 1 ? 'sincronizacao_uma' : 'sincronizacao_varias', ['quantidade' => (string) $quantidade]);
    }

    /**
     * @param array<string, mixed> $detalhe
     * @param \Closure(string, array<string, string>=): string $d o pedaço de texto pelo nome (português ou catálogo)
     */
    private static function textoDaEscala(array $detalhe, \Closure $d): string
    {
        $data = self::momento(self::textoDe($detalhe, 'vale_a_partir_de'), 'd/m/Y') ?? $d('data_nao_informada');

        return match (self::textoDe($detalhe, 'operacao')) {
            'versao_salva'    => $d('escala_versao_salva', ['data' => $data]),
            'versao_excluida' => $d('escala_versao_excluida', ['data' => $data]),
            'escala_limpa'    => $d('escala_limpa'),
            default           => $d('escala_alterada'),
        };
    }

    /**
     * "11/2026 · BJF-ABCD-EFGH-JK23", como o desenho (L727: a folha e o código).
     *
     * @param array<string, mixed> $detalhe
     * @param \Closure(string, array<string, string>=): string $d o pedaço de texto pelo nome (português ou catálogo)
     */
    private static function textoDaFolhaEmitida(array $detalhe, \Closure $d): string
    {
        $codigo = self::textoDe($detalhe, 'codigo');

        return self::textoDaCompetencia($detalhe, $d) . ($codigo !== null ? ' · ' . $codigo : '');
    }

    /**
     * "11/2026", a competência da folha (desenho L727 e L809).
     *
     * @param array<string, mixed> $detalhe
     * @param \Closure(string, array<string, string>=): string $d o pedaço de texto pelo nome (português ou catálogo)
     */
    private static function textoDaCompetencia(array $detalhe, \Closure $d): string
    {
        $ano = $detalhe['ano'] ?? null;
        $mes = $detalhe['mes'] ?? null;

        return is_int($ano) && is_int($mes) ? sprintf('%02d/%04d', $mes, $ano) : $d('competencia_nao_informada');
    }

    /**
     * @param array<string, mixed> $detalhe
     * @param \Closure(string, array<string, string>=): string $d o pedaço de texto pelo nome (português ou catálogo)
     */
    private static function textoDaSituacaoAnterior(array $detalhe, \Closure $d): string
    {
        $codigo = self::textoDe($detalhe, 'situacao_anterior') ?? '';

        return isset(self::SITUACOES[$codigo]) ? ' · ' . $d('estava', ['situacao' => $d('situacao_' . $codigo)]) : '';
    }

    /**
     * O subtítulo, como o da lista (`_justificativa_macros.html.twig`): o do esquecimento, "Saída às 18:00", ou o do
     * abono parcial, "Parcial: 14:00 às 16:00" (só "Parcial" sem o horário). Eventos gravados antes do parcial no
     * detalhe saem sem ele.
     *
     * @param array<string, mixed> $detalhe
     * @param \Closure(string, array<string, string>=): string $d o pedaço de texto pelo nome (português ou catálogo)
     */
    private static function subtitulo(array $detalhe, \Closure $d): ?string
    {
        $codigo = self::textoDe($detalhe, 'batida_esquecida') ?? '';
        $hora   = self::textoDe($detalhe, 'hora_esquecida');
        if (isset(self::BATIDAS[$codigo]) && $hora !== null) {
            return $d('as', ['item' => $d('batida_' . $codigo), 'hora' => $hora]);
        }
        if (($detalhe['parcial'] ?? false) !== true) {
            return null;
        }
        $de  = self::textoDe($detalhe, 'parcial_de');
        $ate = self::textoDe($detalhe, 'parcial_ate');

        return $de === null || $ate === null ? $d('parcial') : $d('parcial_horario', ['de' => $de, 'ate' => $ate]);
    }

    /**
     * Junta as partes que vieram com " · ", como o desenho (dc L601, L768).
     *
     * @param list<?string> $partes
     */
    private static function partes(array $partes): string
    {
        return implode(' · ', array_filter($partes, static fn (?string $parte): bool => $parte !== null && $parte !== ''));
    }

    /**
     * O tipo vem do enum compartilhado (sem catálogo no Ponto ainda): fica em português nos dois idiomas.
     *
     * @param array<string, mixed> $detalhe
     * @param \Closure(string, array<string, string>=): string $d o pedaço de texto pelo nome (português ou catálogo)
     */
    private static function tipoDaJustificativa(array $detalhe, \Closure $d): string
    {
        $tipo = self::textoDe($detalhe, 'tipo') ?? '';

        return TipoJustificativa::tryFrom($tipo)?->label() ?? ($tipo !== '' ? $tipo : $d('justificativa'));
    }

    /** "08:01:12 de 21/10/2026"; a data ilegível volta como veio. */
    private static function momentoDaBatida(?string $iso, \Closure $d): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        try {
            $data = new \DateTimeImmutable($iso);
        } catch (\Exception) {
            return $iso;
        }

        return $d('momento', ['hora' => $data->format('H:i:s'), 'data' => $data->format('d/m/Y')]);
    }

    private static function momento(?string $iso, string $formato): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($iso))->format($formato);
        } catch (\Exception) {
            return $iso;
        }
    }

    /** @param array<string, mixed> $detalhe */
    private static function textoDe(array $detalhe, string $campo): ?string
    {
        $valor = $detalhe[$campo] ?? null;

        return is_scalar($valor) && (string) $valor !== '' ? (string) $valor : null;
    }
}
